<?php
// phpcs:ignoreFile -- standalone developer CLI script, not plugin runtime code.

/**
 * Bump the Pontifex version in pontifex.php.
 *
 * The version lives in two places that MUST stay identical, or the
 * ADR 0003 CI guard rejects the release tag:
 *
 *   1. the plugin-header line      *  Version:           0.0.5
 *   2. the runtime constant        define( 'PONTIFEX_VERSION', '0.0.5' );
 *
 * This script updates both at once, changing ONLY the version digits.
 * It never touches the surrounding whitespace, so it cannot break the
 * file's coding-standard formatting. If it can't find exactly one of
 * each, it writes nothing and exits loudly.
 *
 * Usage (this is not a commit, so PhpStorm's Composer panel or a
 * terminal is fine — the commit still happens in GitHub Desktop):
 *
 *     php scripts/bump-version.php 0.0.7
 *
 * Then write the CHANGELOG entry and commit pontifex.php + CHANGELOG.md.
 */

$new_version = $argv[1] ?? '';

if ($new_version === '') {
    fwrite(STDERR, "Error: no version given.\n");
    fwrite(STDERR, "Usage: php scripts/bump-version.php 0.0.7\n");
    exit(1);
}

// Accept X.Y.Z, optionally with a pre-release suffix like -rc1.
if (!preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/', $new_version)) {
    fwrite(STDERR, "Error: '{$new_version}' is not a valid version (expected something like 0.0.7).\n");
    exit(1);
}

$plugin_file = __DIR__ . '/../pontifex.php';

if (!is_file($plugin_file)) {
    fwrite(STDERR, "Error: could not find pontifex.php at {$plugin_file}\n");
    exit(1);
}

$original = file_get_contents($plugin_file);
$contents = $original;

// 1. Plugin header:  * Version:           0.0.5
$contents = preg_replace_callback(
    '/^(\s*\*\s*Version:\s*)\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?/m',
    static fn (array $m): string => $m[1] . $new_version,
    $contents,
    1,
    $header_count
);

// 2. Runtime constant:  define( 'PONTIFEX_VERSION', '0.0.5' );
//    The exact-quote match means PONTIFEX_MINIMUM_PHP_VERSION is never touched.
$contents = preg_replace_callback(
    "/(define\(\s*'PONTIFEX_VERSION',\s*')\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?(')/",
    static fn (array $m): string => $m[1] . $new_version . $m[2],
    $contents,
    1,
    $constant_count
);

if ($header_count !== 1) {
    fwrite(STDERR, "Error: expected exactly one plugin-header 'Version:' line, found {$header_count}. Nothing written.\n");
    exit(1);
}

if ($constant_count !== 1) {
    fwrite(STDERR, "Error: expected exactly one PONTIFEX_VERSION define, found {$constant_count}. Nothing written.\n");
    exit(1);
}

if ($contents === $original) {
    echo "Already at {$new_version} — nothing to change.\n";
    exit(0);
}

file_put_contents($plugin_file, $contents);

echo "Bumped pontifex.php to {$new_version}:\n";
echo "  - plugin header   Version: {$new_version}\n";
echo "  - runtime const   PONTIFEX_VERSION = '{$new_version}'\n";
echo "\nNext: write the CHANGELOG entry, then commit pontifex.php + CHANGELOG.md in GitHub Desktop.\n";
