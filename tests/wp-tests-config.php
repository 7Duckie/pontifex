<?php
/**
 * WordPress test-suite configuration for Pontifex integration tests.
 *
 * Read by the WordPress test bootstrap. Values come from the environment
 * variables wp-env exposes inside its tests container (WORDPRESS_DB_*),
 * with sensible fallbacks for other setups. Reading from the environment
 * means the database host and name can never drift out of sync with what
 * wp-env actually provisions.
 *
 * IF THE TEST FAILS TO CONNECT, THIS IS THE FILE TO ADJUST. The most
 * likely culprits are the host and name; wp-env's defaults are the
 * fallbacks here. CONTRIBUTING explains how to read the live values.
 *
 * @package Pontifex\Tests
 */

/**
 * Read a database setting from the environment, falling back when unset.
 *
 * @param string $name     Environment variable name.
 * @param string $fallback Value to use when the variable is not set.
 * @return string
 */
function pontifex_test_db_setting( string $name, string $fallback ): string {
	$value = getenv( $name );

	return false === $value ? $fallback : $value;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core test-config constant names.

define( 'DB_NAME', pontifex_test_db_setting( 'WORDPRESS_DB_NAME', 'tests-wordpress' ) );
define( 'DB_USER', pontifex_test_db_setting( 'WORDPRESS_DB_USER', 'root' ) );
define( 'DB_PASSWORD', pontifex_test_db_setting( 'WORDPRESS_DB_PASSWORD', 'password' ) );
define( 'DB_HOST', pontifex_test_db_setting( 'WORDPRESS_DB_HOST', 'tests-mysql' ) );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Required name for the WordPress test suite.
$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'localhost' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Pontifex Tests' );
define( 'WP_PHP_BINARY', 'php' );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/var/www/html/' );
}

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
