<?php
// phpcs:ignoreFile -- standalone developer CLI script, not plugin runtime code.

/**
 * Pre-flight release check for Pontifex.
 *
 * Confirms a release is internally consistent BEFORE you tag it — the
 * same agreement the ADR 0003 CI guard enforces, plus the changelog —
 * so a mistake shows up on your machine in a second, instead of as a
 * red CI run after the tag is already public.
 *
 * It checks:
 *   1. the plugin-header "Version:" line is present
 *   2. the PONTIFEX_VERSION constant is present
 *   3. those two agree with each other
 *   4. CHANGELOG.md has a matching "## [x.y.z]" section and link line
 *   5. readme.txt's "Stable tag:" agrees with the version
 *   6. (optionally) that they match a version you pass in
 *
 * Read-only — it changes nothing, so run it any time, anywhere. It is
 * not a commit, so a terminal or PhpStorm is fine.
 *
 * Usage:
 *     php scripts/check-release.php            # internal consistency
 *     php scripts/check-release.php 0.0.6      # also: does it equal 0.0.6?
 */

$root           = __DIR__ . '/..';
$plugin_file    = $root . '/pontifex.php';
$changelog_file = $root . '/CHANGELOG.md';
$readme_file    = $root . '/readme.txt';

$expected = $argv[1] ?? null;
$failures = 0;

$check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    if (!$ok) {
        $failures++;
    }
    echo '  ' . ($ok ? 'OK  ' : 'BAD ') . $label;
    if ($detail !== '') {
        echo "  ({$detail})";
    }
    echo "\n";
};

if (!is_file($plugin_file)) {
    fwrite(STDERR, "Error: pontifex.php not found next to scripts/.\n");
    exit(1);
}

$php = file_get_contents($plugin_file);

$header = null;
if (preg_match('/^\s*\*\s*Version:\s*(\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?)/m', $php, $m)) {
    $header = $m[1];
}

$constant = null;
if (preg_match("/define\(\s*'PONTIFEX_VERSION',\s*'(\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?)'/", $php, $m)) {
    $constant = $m[1];
}

echo "Release check\n";
$check('plugin header Version present', $header !== null, $header ?? 'not found');
$check('PONTIFEX_VERSION constant present', $constant !== null, $constant ?? 'not found');
$check('header and constant agree', $header !== null && $header === $constant, "header={$header}, const={$constant}");

$version = $constant ?? $header;

if (!is_file($changelog_file)) {
    $check('CHANGELOG.md present', false, 'not found');
} elseif ($version !== null) {
    $cl          = file_get_contents($changelog_file);
    $has_section = strpos($cl, "## [{$version}]") !== false;
    $has_link    = (bool) preg_match('/^\[' . preg_quote($version, '/') . '\]:\s+https?:\/\//m', $cl);
    $check("CHANGELOG has a [{$version}] section", $has_section);
    $check("CHANGELOG has a [{$version}] link", $has_link);
}

if (!is_file($readme_file)) {
    $check('readme.txt present', false, 'not found');
} else {
    $readme = file_get_contents($readme_file);

    if ($version !== null) {
        $stable = null;
        if (preg_match('/^Stable tag:\s*(\S+)/mi', $readme, $m)) {
            $stable = $m[1];
        }
        $check('readme.txt Stable tag agrees', $stable === $version, 'stable=' . ($stable ?? 'not found') . ", version={$version}");
    }

    // --- readme.txt size limits (wordpress.org rejects a plugin whose
    // readme breaks these at submission time; nothing else here catches
    // that before the tag is already public). Checked in both characters
    // and bytes on purpose: the validator has historically measured with
    // strlen(), and a multi-byte character (an em dash is 3 bytes in
    // UTF-8) can push a string over the byte limit while it is still under
    // the character limit. Failing on the stricter of the two costs
    // nothing and cannot surprise us later.

    $readme_lines = preg_split('/\r\n|\r|\n/', $readme);

    // 1. Short description: the single non-empty line between the header
    // block and "== Description ==".
    $description_heading_index = null;
    foreach ($readme_lines as $i => $line) {
        if (preg_match('/^==\s*Description\s*==$/i', trim($line))) {
            $description_heading_index = $i;
            break;
        }
    }

    $short_description = null;
    if ($description_heading_index !== null) {
        for ($i = $description_heading_index - 1; $i >= 0; $i--) {
            if (trim($readme_lines[$i]) !== '') {
                $short_description = trim($readme_lines[$i]);
                break;
            }
        }
    }

    if ($short_description === null) {
        $check('readme.txt short description found', false, 'no non-empty line before == Description ==');
    } else {
        $short_chars = mb_strlen($short_description, 'UTF-8');
        $short_bytes = strlen($short_description);
        $check(
            'readme.txt short description within 150 chars/bytes',
            $short_chars <= 150 && $short_bytes <= 150,
            "{$short_chars} chars, {$short_bytes} bytes"
        );
    }

    // 2. Every "== Upgrade Notice ==" entry body.
    $in_upgrade_notice   = false;
    $upgrade_notice_body = array();
    $notice_version      = null;

    foreach ($readme_lines as $line) {
        $trimmed = trim($line);

        if (preg_match('/^==\s*Upgrade Notice\s*==$/i', $trimmed)) {
            $in_upgrade_notice = true;
            continue;
        }

        if (!$in_upgrade_notice) {
            continue;
        }

        if (substr($trimmed, 0, 2) === '==') {
            break; // the next top-level section closes Upgrade Notice.
        }

        if (preg_match('/^=(.+)=$/', $trimmed, $m)) {
            $notice_version                        = trim($m[1]);
            $upgrade_notice_body[$notice_version]  = array();
            continue;
        }

        if ($notice_version !== null) {
            $upgrade_notice_body[$notice_version][] = $line;
        }
    }

    if (empty($upgrade_notice_body)) {
        $check('readme.txt Upgrade Notice has entries', false, 'no "= x.y.z =" entries found');
    } else {
        foreach ($upgrade_notice_body as $entry_version => $body_lines) {
            $body  = trim(implode("\n", $body_lines));
            $chars = mb_strlen($body, 'UTF-8');
            $bytes = strlen($body);
            $check(
                "readme.txt Upgrade Notice ({$entry_version}) within 300 chars/bytes",
                $chars <= 300 && $bytes <= 300,
                "{$chars} chars, {$bytes} bytes"
            );
        }
    }

    // 3. Tag count from the "Tags:" header.
    $tag_count = null;
    if (preg_match('/^Tags:\s*(.+)$/mi', $readme, $m)) {
        $tags      = array_filter(array_map('trim', explode(',', $m[1])));
        $tag_count = count($tags);
    }

    if ($tag_count === null) {
        $check('readme.txt Tags header present', false, 'not found');
    } else {
        $check('readme.txt Tags count within 5', $tag_count <= 5, "{$tag_count} tags");
    }
}

if ($expected !== null) {
    $check("matches the version you asked for ({$expected})", $version === $expected, "found {$version}");
}

echo "\n";

if ($failures === 0) {
    // Below v1.0.0 every release is published as a GitHub pre-release. This
    // reminder used to be unconditional, written when that was true of every
    // version there had ever been — so on the v1.0.0 assembly it cheerfully
    // advised publishing the first stable release as a pre-release, on the
    // stated grounds that 1.0.0 is below 1.0.0.
    $is_pre_release = version_compare($version, '1.0.0', '<');
    $reminder = $is_pre_release
        ? ' (remember: pre-release, since it is below v1.0.0)'
        : ' (a full release, NOT a pre-release — this is v1.0.0 or later)';

    echo "All good — safe to tag v{$version}{$reminder}.\n";
    exit(0);
}

echo "{$failures} problem(s) above — fix before tagging.\n";
exit(1);
