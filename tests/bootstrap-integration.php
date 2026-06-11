<?php
/**
 * PHPUnit bootstrap for Pontifex integration tests.
 *
 * Unlike tests/bootstrap.php — which loads no WordPress and relies on
 * brain/monkey fakes — this bootstrap boots a *real* WordPress, so tests
 * in tests/Integration/ run against the actual platform: a real $wpdb,
 * real hooks, real option storage.
 *
 * It prefers the WordPress test suite that wp-env mounts (exposed via the
 * WP_TESTS_DIR environment variable). When that is absent — for example
 * in CI, where wp-env does not exist — it falls back to the wp-phpunit
 * Composer package. Database settings come from tests/wp-tests-config.php.
 *
 * @package Pontifex\Tests
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WordPress's own test suite dictates these names.

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Prefer wp-env's bundled test suite; fall back to the wp-phpunit package.
$pontifex_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( false === $pontifex_tests_dir || '' === $pontifex_tests_dir ) {
	$pontifex_tests_dir = getenv( 'WP_PHPUNIT__DIR' );
}

if ( false === $pontifex_tests_dir || '' === $pontifex_tests_dir ) {
	$pontifex_tests_dir = dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';
}

if ( ! file_exists( $pontifex_tests_dir . '/includes/functions.php' ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- writing a diagnostic to STDERR, not a WP filesystem operation.
	fwrite( STDERR, 'Could not find the WordPress test suite at ' . $pontifex_tests_dir . '.' . PHP_EOL );
	exit( 1 );
}

// Tell the test suite where our database configuration lives.
if ( false === getenv( 'WP_TESTS_CONFIG_FILE_PATH' ) ) {
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- points the WP test bootstrap at our test-only DB config.
	putenv( 'WP_TESTS_CONFIG_FILE_PATH=' . __DIR__ . '/wp-tests-config.php' );
}

require_once $pontifex_tests_dir . '/includes/functions.php';

// Load Pontifex into the test WordPress just before it finishes booting.
tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__ ) . '/pontifex.php';
	}
);

require $pontifex_tests_dir . '/includes/bootstrap.php';

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
