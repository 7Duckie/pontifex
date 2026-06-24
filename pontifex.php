<?php
/**
 * Plugin Name:       Pontifex
 * Plugin URI:        https://github.com/7Duckie/pontifex
 * Description:       A free, open-source WordPress migration and backup plugin with a documented archive format.
 * Version:           0.4.3
 * Requires at least: 6.5
 * Requires PHP:      8.2
 * Author:            7Duckie
 * Author URI:        https://github.com/7Duckie
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pontifex
 *
 * @package Pontifex
 */

// -----------------------------------------------------------------------------
// IMPORTANT: This file MUST remain parseable by older PHP versions.
//
// If a user activates Pontifex on PHP 7.4, we want to show them a friendly
// admin notice — not a fatal parse error. That means no modern syntax above
// the version gate: no `readonly`, no enums, no union types, no `never`
// return type, no first-class callable syntax, no constructor property
// promotion. Plain PHP 7-era syntax only, in this file, above the gate.
//
// All modern code lives in src/, behind the autoloader, where it is only
// ever parsed if the version check passes.
// -----------------------------------------------------------------------------

// Refuse to be loaded outside WordPress. ABSPATH is defined by wp-load.php;
// if someone hits this file directly via a URL, exit silently.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// -----------------------------------------------------------------------------
// Version gate
// -----------------------------------------------------------------------------

/**
 * The minimum PHP version Pontifex supports.
 *
 * Kept as a string for `version_compare()`, which is lexically aware of
 * version semantics (so '8.10.0' > '8.2.0' compares correctly).
 */
$pontifex_minimum_php_version = '8.2.0';

/**
 * The minimum WordPress version Pontifex supports.
 *
 * Enforced soft (admin notice) rather than hard (deactivate), because
 * a user with an older WordPress probably wants to know *both* facts
 * — that their WordPress needs updating, and that Pontifex is the
 * tool they'll reach for once it is.
 */
$pontifex_minimum_wp_version = '6.5';

if ( version_compare( PHP_VERSION, $pontifex_minimum_php_version, '<' ) ) {
	/*
	 * Older PHP detected. Register an admin notice and stop loading.
	 *
	 * We use a static closure (anonymous function) here rather than a
	 * named function because: (a) we want to capture variables via `use`,
	 * and (b) a named function declared at file scope would persist
	 * even if the plugin were deactivated, polluting the global scope.
	 */
	add_action(
		'admin_notices',
		static function () use ( $pontifex_minimum_php_version ) {
			$message = sprintf(
				/* translators: 1: minimum PHP version, 2: current PHP version */
				esc_html__(
					'Pontifex requires PHP %1$s or newer. You are running %2$s. The plugin has not been loaded.',
					'pontifex'
				),
				esc_html( $pontifex_minimum_php_version ),
				esc_html( PHP_VERSION )
			);
			printf( '<div class="notice notice-error"><p>%s</p></div>', $message ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	);

	return; // Stop loading the rest of the plugin. Crucial.
}

// -----------------------------------------------------------------------------
// Constants
//
// We attach version, file path, and directory constants to the plugin so
// other parts of the code can locate themselves without hard-coding paths.
// `PONTIFEX_*` prefix avoids collision with anything else in global scope.
// -----------------------------------------------------------------------------

// Define plugin constants.
define( 'PONTIFEX_PLUGIN_FILE', __FILE__ );
define( 'PONTIFEX_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PONTIFEX_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PONTIFEX_MINIMUM_PHP_VERSION', '8.2' );
define( 'PONTIFEX_MINIMUM_WP_VERSION', '6.5' );

/**
 * The Pontifex plugin version, exposed to runtime code.
 *
 * The plugin header above also carries a Version line, but that line
 * is documentation/metadata read by WordPress and the Plugins screen.
 * PONTIFEX_VERSION is the runtime source of truth: it is what
 * ExporterInfo records inside every Pontifex archive's Provenance
 * block, so the value at this point in the file IS the value that
 * gets stamped onto archives.
 *
 * Bumping the version means updating both this define and the header
 * Version line at the top of the file. They must agree. ADR 0003
 * formalises this with a CI guard that fails the workflow on tag
 * push if the values disagree with the tag.
 */
define( 'PONTIFEX_VERSION', '0.4.3' );

// -----------------------------------------------------------------------------
// Autoloader
//
// Composer generates vendor/autoload.php from the PSR-4 mapping in
// composer.json. Without this require, no `Pontifex\…` class can be
// found by PHP. We check for existence first so a developer who forgot
// to run `composer install` gets a clear error rather than a fatal.
// -----------------------------------------------------------------------------

$pontifex_autoloader = PONTIFEX_PLUGIN_DIR . 'vendor/autoload.php';

if ( ! file_exists( $pontifex_autoloader ) ) {
	add_action(
		'admin_notices',
		static function () {
			$message = esc_html__(
				'Pontifex is missing its Composer dependencies. Run `composer install` from the plugin directory.',
				'pontifex'
			);
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $message ) );
		}
	);
	return;
}

require_once $pontifex_autoloader;

// -----------------------------------------------------------------------------
// WP-CLI integration
//
// `WP_CLI` is only defined when WordPress is running under the WP-CLI binary.
// On normal web requests this constant does not exist, so we guard the
// registration. The class name is fully qualified — the leading backslash
// is the global namespace, equivalent to a root-level import.
//
// `add_command()` accepts either an instance or a class name string; we
// pass the class name and let WP-CLI instantiate it lazily (only when the
// command actually runs).
// -----------------------------------------------------------------------------

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	\WP_CLI::add_command( 'pontifex doctor', \Pontifex\Cli\DoctorCommand::class );
	\WP_CLI::add_command( 'pontifex export', \Pontifex\Cli\ExportCommand::class );
	\WP_CLI::add_command( 'pontifex import', \Pontifex\Cli\ImportCommand::class );
	\WP_CLI::add_command( 'pontifex verify', \Pontifex\Cli\VerifyCommand::class );
	\WP_CLI::add_command( 'pontifex rollback', \Pontifex\Cli\RollbackCommand::class );
	\WP_CLI::add_command( 'pontifex keygen', \Pontifex\Cli\KeygenCommand::class );
	\WP_CLI::add_command( 'pontifex stats', \Pontifex\Cli\StatsCommand::class );
	\WP_CLI::add_command( 'pontifex diagnostics', \Pontifex\Cli\DiagnosticsCommand::class );
}
