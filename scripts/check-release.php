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
 *   5. (optionally) that they match a version you pass in
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

if ($expected !== null) {
    $check("matches the version you asked for ({$expected})", $version === $expected, "found {$version}");
}

echo "\n";

if ($failures === 0) {
    echo "All good — safe to tag v{$version} (remember: pre-release, since it is below v1.0.0).\n";
    exit(0);
}

echo "{$failures} problem(s) above — fix before tagging.\n";
exit(1);
