<?php
/**
 * Single source of truth for Pontifex's version string.
 *
 * @package Pontifex
 */

declare(strict_types=1);

namespace Pontifex;

/**
 * Resolves the plugin's version from the plugin header.
 *
 * WordPress reads the * Version: line from the top of pontifex.php to
 * populate the wp-admin Plugins screen, so the header must contain the
 * canonical version string regardless of anything else. This class reads
 * the same string at runtime so PHP code does not need to duplicate it.
 *
 * The result is cached on first access; subsequent calls are free.
 */
final class Version {

	/**
	 * Cached version string.
	 *
	 * @var string|null
	 */
	private static ?string $cached = null;

	/**
	 * Returns the current plugin version as declared in the plugin header.
	 *
	 * Falls back to "unknown" only if the plugin header cannot be parsed,
	 * which should never happen in a correctly-installed plugin.
	 *
	 * @return string
	 */
	public static function current(): string {
		if ( null !== self::$cached ) {
			return self::$cached;
		}

		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$data         = get_plugin_data( PONTIFEX_PLUGIN_FILE, false, false );
		self::$cached = $data['Version'];

		return self::$cached;
	}

	/**
	 * Resets the cache. Test-only.
	 *
	 * @internal
	 * @return void
	 */
	public static function reset_cache_for_testing(): void {
		self::$cached = null;
	}
}
