#!/usr/bin/env bash
#
# Run WordPress.org's Plugin Check tool against the BUILT Pontifex package,
# in a dedicated wp-env environment, and fail on any error or warning.
#
# Why this exists: CI runs Plugin Check against the built package at the
# staging and main gates, with strict:true, so a single warning fails the
# build. `composer lint` cannot see everything that gate sees — ten of
# Plugin Check's sniffs live in a package that is not on Packagist, and
# nineteen further checks (readme validation, plugin headers, file types,
# trademarks, direct file access, obfuscation, runtime checks) are not
# PHPCS sniffs at all. Running Plugin Check itself, locally, against the
# same built artefact CI checks, is the only way to see a staging-gate
# failure before the staging gate.
#
# Usage:
#   scripts/plugin-check.sh          # run the check, stop the environment afterwards
#   scripts/plugin-check.sh --keep   # run the check, leave the environment running
#
# Needs Docker running and takes a few minutes — most of it is composer
# and wp-env doing real installs, not this script being slow.

set -euo pipefail

# --- argument parsing --------------------------------------------------

keep_running=0

for arg in "$@"; do
    case "$arg" in
        --keep)
            keep_running=1
            ;;
        *)
            echo "Usage: $(basename "$0") [--keep]" >&2
            echo "  --keep   leave the wp-env environment running after the check" >&2
            exit 1
            ;;
    esac
done

# --- resolve paths -------------------------------------------------------

# Resolve everything relative to this script's own location, not the
# caller's working directory, so it behaves the same whether invoked as
# `scripts/plugin-check.sh`, `composer check:plugin` (composer runs
# scripts from the repository root, but nothing here should depend on
# that), or from inside scripts/ itself.
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
root="$(cd "$script_dir/.." && pwd)"

wp_env_config=".wp-env.plugin-check.json"

phase() {
    printf '\n==> %s\n' "$1"
}

# --- preconditions -------------------------------------------------------
#
# Each of these fails with a plain sentence explaining what to do, not a
# stack trace three layers into wp-env or Docker's own error handling.

phase "Checking preconditions"

if ! docker info >/dev/null 2>&1; then
    echo "Error: Docker does not appear to be running. Start Docker Desktop and try again." >&2
    exit 1
fi

if [ ! -d "$root/node_modules/@wordpress/env" ]; then
    echo "Error: @wordpress/env is not installed. Run 'npm ci' first." >&2
    exit 1
fi

if ! command -v composer >/dev/null 2>&1; then
    echo "Error: composer is not on PATH. Install Composer and try again." >&2
    exit 1
fi

# The bare `wp-env` binary is never on PATH in this project — every
# invocation below goes through `npx @wordpress/env` deliberately.

# --- prepare build/ ------------------------------------------------------

phase "Preparing build/"

# build/ is already in .gitignore. build/src and build/pkg must exist
# before wp-env starts, because .wp-env.plugin-check.json bind-mounts them
# in — wp-env cannot mount a path that does not yet exist on the host.
#
# This empties the two directories IN PLACE rather than `rm -rf build` +
# `mkdir -p`, and that is deliberate, not fussiness — resist "simplifying"
# it back to `rm -rf`. build/src and build/pkg are bind-mount targets, and
# `wp-env start` against an environment that is ALREADY running (as it is
# with --keep, or after an interrupted or concurrent run left it up) reuses
# the existing containers rather than recreating them. Their bind mounts
# stay pinned to the directories' current inodes. `rm -rf` followed by
# `mkdir -p` deletes those inodes and hands back new ones with the same
# path but a different identity — the live container's mount then points
# at nothing, and the next `wp-env run` inside it fails with Docker's
# "outside of container mount namespace root" error. Deleting only the
# CONTENTS leaves the directories — and therefore their inodes, and
# therefore any live bind mount — untouched.
mkdir -p "$root/build/src" "$root/build/pkg"
find "$root/build/src" -mindepth 1 -delete
find "$root/build/pkg" -mindepth 1 -delete
rm -f "$root/build/pontifex.tar.gz" "$root/build/plugin-check.json"

# --- copy the working tree ------------------------------------------------

phase "Copying the working tree into build/src"

# Copying the working tree — not `git archive HEAD` — is deliberate. The
# point of this script is to check what you are about to push, INCLUDING
# whatever is currently staged or unstaged. A commit-time snapshot would
# miss exactly the change you are trying to verify before you commit it.
rsync -a \
    --exclude='.git' \
    --exclude='vendor' \
    --exclude='node_modules' \
    --exclude='build' \
    --exclude='.phpunit.cache' \
    --exclude='.idea' \
    --exclude='.vscode' \
    "$root/" "$root/build/src/"

# --- production-only dependencies -----------------------------------------

phase "Installing production Composer dependencies into the copy"

# This installs into the COPY at build/src, never into the repository
# root. CI does this same --no-dev install inside a throwaway checkout;
# doing it in place against the developer's own vendor/ would strip it
# down to production-only packages and break their ability to run tests
# or PHPStan afterwards. That has bitten this project before.
composer install --no-dev --no-interaction --prefer-dist --no-progress --working-dir="$root/build/src"

# --- start the environment ------------------------------------------------

phase "Starting the plugin-check wp-env environment (port 8912)"

stopped=0

stop_environment() {
    if [ "$stopped" -eq 1 ]; then
        return
    fi
    stopped=1

    if [ "$keep_running" -eq 1 ]; then
        echo "Leaving the plugin-check environment running (--keep passed)."
        echo "Stop it later with: npx @wordpress/env stop --config $wp_env_config"
        return
    fi

    phase "Stopping the plugin-check wp-env environment"
    if ! npx @wordpress/env stop --config "$wp_env_config"; then
        echo "Warning: failed to stop the plugin-check wp-env environment." >&2
        echo "Stop it manually with: npx @wordpress/env stop --config $wp_env_config" >&2
        return
    fi
    echo "Restart it with: npx @wordpress/env start --config $wp_env_config"
}

# The exit code this script returns must be the exit code of the CHECK,
# never the exit code of stopping the environment afterwards — so the trap
# saves $? before doing anything else, ignores whatever stop_environment
# itself returns, and re-asserts the saved code as the very last thing it
# does.
trap 'exit_code=$?; stop_environment || true; exit $exit_code' EXIT

# A statement of general fact, true on every run, not a claim about THIS
# run — this script has no way to know whether wp-env's clone and images
# are already cached, and predicting that would risk announcing a
# multi-minute wait immediately before a several-second start. Printed
# unconditionally so the silence during an actual first-time clone (roughly
# a gigabyte of WordPress) and image build doesn't read as a hang.
echo "The first run for a new environment clones WordPress and builds its Docker images, which takes several minutes; later runs reuse both and start in seconds."

npx @wordpress/env start --config "$wp_env_config"

# --- the dist-archive package ---------------------------------------------

phase "Checking for the WP-CLI dist-archive package"

if npx @wordpress/env run cli --config "$wp_env_config" wp help dist-archive >/dev/null 2>&1; then
    echo "wp-cli/dist-archive-command is already installed."
else
    echo "Installing wp-cli/dist-archive-command..."
    if ! npx @wordpress/env run cli --config "$wp_env_config" wp package install wp-cli/dist-archive-command:^2.0; then
        cat >&2 <<'EOF'

Error: could not install wp-cli/dist-archive-command inside the wp-env
container.

This script deliberately does not fall back to building the distributable
package some other way (for example, zipping the source tree by hand).
Doing so would skip .distignore entirely, and exercising the real
dist-archive tool against .distignore is half the reason this script
exists — a previously broken .distignore silently excluded nothing and
would have shipped the entire development tree to wordpress.org.

WP-CLI installs this package anonymously from its public GitHub
repository, which can hit GitHub's unauthenticated rate limit. If that is
the cause, waiting a while and retrying is the remedy.
EOF
        exit 1
    fi
fi

# --- build the distributable inside the container --------------------------

phase "Building the distributable package inside the container"

# The tarball has to land somewhere the host can see, and build/pkg (mapped
# to wp-content/plugins/pontifex) is the only other directory this
# environment maps in. The host moves it back out again immediately in the
# next step, before anything checks that directory as the plugin itself.
dist_target="/var/www/html/wp-content/plugins/pontifex/pontifex.tar.gz"

if dist_output=$(npx @wordpress/env run cli --env-cwd=wp-content/pontifex-src --config "$wp_env_config" \
    wp dist-archive . "$dist_target" --format=targz 2>&1); then
    dist_status=0
else
    dist_status=$?
fi

# Matching the literal flag name, not the bare word "root": Docker's own
# error text for an unrelated failure (for example a stale bind mount) can
# easily contain the word "root" too — "mount namespace root", "container
# breakout" — and grepping for that word alone asserts a diagnosis this
# script has not actually established. WP-CLI's own refusal message tells
# the user to pass --allow-root, so the flag name is a precise signal that
# this specific cause, and no other, was hit.
if [ "$dist_status" -ne 0 ] && printf '%s\n' "$dist_output" | grep -qF -- '--allow-root'; then
    echo "wp dist-archive refused to run as root inside the container; retrying with --allow-root."
    if dist_output=$(npx @wordpress/env run cli --env-cwd=wp-content/pontifex-src --config "$wp_env_config" \
        wp dist-archive . "$dist_target" --format=targz --allow-root 2>&1); then
        dist_status=0
    else
        dist_status=$?
    fi
fi

echo "$dist_output"

if [ "$dist_status" -ne 0 ]; then
    echo "Error: 'wp dist-archive' failed inside the plugin-check container (see output above)." >&2
    exit 1
fi

# --- move the built package onto the host -----------------------------------

phase "Moving the built package onto the host"

mv "$root/build/pkg/pontifex.tar.gz" "$root/build/pontifex.tar.gz"

# dist-archive wraps the tree in a single top-level folder whose name
# varies by plugin version (e.g. pontifex-1.1.0/); strip it so build/pkg
# directly holds the shipped tree, matching what WordPress itself would
# unpack into wp-content/plugins/pontifex.
tar -xzf "$root/build/pontifex.tar.gz" -C "$root/build/pkg" --strip-components=1

echo "This is what would ship:"
ls -la "$root/build/pkg"

# --- Plugin Check itself -----------------------------------------------

phase "Installing and activating Plugin Check, and activating Pontifex"

if npx @wordpress/env run cli --config "$wp_env_config" wp plugin is-installed plugin-check >/dev/null 2>&1; then
    npx @wordpress/env run cli --config "$wp_env_config" wp plugin activate plugin-check
else
    npx @wordpress/env run cli --config "$wp_env_config" wp plugin install plugin-check --activate
fi

npx @wordpress/env run cli --config "$wp_env_config" wp plugin activate pontifex

phase "Running Plugin Check against the built package"

raw_output_file="$root/build/plugin-check.json"

# `wp plugin check` itself may exit non-zero when it finds errors — that is
# not this script's failure signal. The PHP step below parses the captured
# JSON and is the sole authority on pass/fail, so a non-zero exit here is
# deliberately swallowed rather than tripping set -e.
#
# --format=strict-json, not plain json: plain `json` prints a `FILE: <path>`
# line followed by a SEPARATE JSON array once per file with findings, so
# splicing "first [ to last ]" out of that would interleave several arrays
# with FILE: lines between them and produce invalid JSON. strict-json calls
# the formatter once with the flat, whole-run findings list instead, so the
# captured output holds exactly one JSON array. --fields is required
# alongside it because the default output fields (line, column, type, code,
# message, docs) do not include "file", and the summariser below groups by
# file.
npx @wordpress/env run cli --config "$wp_env_config" \
    wp plugin check pontifex \
    --format=strict-json \
    --fields=file,line,column,type,code,message \
    --slug=pontifex \
    --require=./wp-content/plugins/plugin-check/cli.php \
    >"$raw_output_file" 2>&1 || true

# --- summarise and decide the exit code ------------------------------------

phase "Summarising findings"

# `npx @wordpress/env run` wraps the underlying command's own output with
# its own progress lines (a leading "Starting 'wp ...'" and a trailing
# "Ran ... in Ns"), so $raw_output_file is not pure JSON even when the
# check itself ran cleanly. PHP is guaranteed present, since this is a PHP
# project, so the extraction and the pass/fail decision both happen here
# rather than pulling in another dependency just for this script.
PLUGIN_CHECK_RAW_FILE="$raw_output_file" php <<'PHP'
<?php

$rawFile = getenv('PLUGIN_CHECK_RAW_FILE');
$raw     = $rawFile !== false ? @file_get_contents($rawFile) : false;

if ($raw === false) {
    fwrite(STDERR, "Error: could not read the captured Plugin Check output ({$rawFile}).\n");
    exit(1);
}

// On a clean run — both $errors and $warnings empty — Plugin Check's CLI
// command returns before it ever reaches the format handling and prints
// only this success line, in NO format, strict or otherwise. So a clean
// run never contains a JSON array at all, and the extraction below would
// wrongly treat that as unparseable output. Checked first, and matched as
// the exact literal string from Plugin Check's own source (this project
// has been bitten before by guessing at a tool's wording rather than
// copying it verbatim). Warnings are collected unless --ignore-warnings is
// passed, which this script never does, so seeing this line is a sound
// zero-findings signal, not a loosened gate.
if (str_contains($raw, 'Checks complete. No errors found.')) {
    echo "Plugin Check: no findings.\n";
    echo "\n0 error(s), 0 warning(s).\n";
    exit(0);
}

$fail_unparseable = static function (string $reason) use ($raw): void {
    fwrite(STDERR, "Error: could not parse Plugin Check's output as JSON ({$reason}).\n");
    fwrite(STDERR, "Never treated as a pass — raw output follows:\n\n");
    fwrite(STDERR, $raw . "\n");
    exit(1);
};

// The captured text has npx's own progress lines wrapped around the real
// JSON. Tolerant extraction, not a strict parser: take everything from
// the first '[' or '{' to the matching last ']' or '}'. Whichever bracket
// character appears first in the file determines which closing character
// we hunt for at the end.
$posBracket = strpos($raw, '[');
$posBrace   = strpos($raw, '{');

if ($posBracket === false && $posBrace === false) {
    $fail_unparseable('no JSON array or object found in the captured output');
}

if ($posBracket !== false && ($posBrace === false || $posBracket < $posBrace)) {
    $start = $posBracket;
    $close = ']';
} else {
    $start = $posBrace;
    $close = '}';
}

$end = strrpos($raw, $close);

if ($end === false || $end < $start) {
    $fail_unparseable("no matching closing '{$close}' found");
}

$json = substr($raw, $start, $end - $start + 1);
$data = json_decode($json, true);

if ($data === null && trim($json) !== 'null') {
    $fail_unparseable('json_decode failed: ' . json_last_error_msg());
}

if (!is_array($data)) {
    $fail_unparseable('decoded value is not a JSON array or object');
}

// Plugin Check's --format=json can report either a flat list of finding
// objects (each carrying its own "file" key) or an object keyed by file
// path, mapping to a list of findings for that file. Normalise both
// shapes into one flat list so the rest of this script does not need to
// care which one the installed Plugin Check version produced.
$findings = array();
$isList   = array_keys($data) === range(0, count($data) - 1);

if ($isList) {
    foreach ($data as $item) {
        if (is_array($item)) {
            $findings[] = $item;
        }
    }
} else {
    foreach ($data as $file => $items) {
        if (!is_array($items)) {
            continue;
        }
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (!isset($item['file'])) {
                $item['file'] = $file;
            }
            $findings[] = $item;
        }
    }
}

if ($findings === array()) {
    echo "Plugin Check: no findings.\n";
    echo "\n0 error(s), 0 warning(s).\n";
    exit(0);
}

$byFile = array();
foreach ($findings as $item) {
    $file            = (string) ($item['file'] ?? '(unknown file)');
    $byFile[$file][] = $item;
}

ksort($byFile);

$errorCount   = 0;
$warningCount = 0;

foreach ($byFile as $file => $items) {
    echo "\n{$file}\n";
    foreach ($items as $item) {
        $line    = $item['line'] ?? 0;
        $column  = $item['column'] ?? 0;
        $code    = $item['code'] ?? '';
        $type    = strtoupper((string) ($item['type'] ?? 'UNKNOWN'));
        $message = (string) ($item['message'] ?? '');
        $message = trim(preg_replace('/\s+/', ' ', $message));

        if ($type === 'ERROR') {
            $errorCount++;
        } else {
            // Anything that is not literally ERROR is counted as a
            // warning, deliberately — an unrecognised type string from a
            // future Plugin Check version must not silently go uncounted.
            // Fail closed, the same policy as everywhere else in this
            // project.
            $warningCount++;
        }

        echo "  {$file}:{$line}:{$column}  {$code}  {$type}  {$message}\n";
    }
}

echo "\n{$errorCount} error(s), {$warningCount} warning(s).\n";

// Mirrors the strict:true setting on the CI Plugin Check gate, and this
// project's standing policy that every tool runs clean: zero errors AND
// zero warnings, or it fails.
exit(($errorCount + $warningCount) > 0 ? 1 : 0);
PHP

# This heredoc is deliberately the last command in the script: under
# set -e, its exit status becomes this script's own exit status with
# nothing further needed. The EXIT trap above still runs after it (traps
# fire regardless of how the script ends) and preserves that same code
# across stopping the environment, so the caller sees the check's result,
# not the stop command's.
