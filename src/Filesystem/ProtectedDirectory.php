<?php
/**
 * Creates a plugin-owned directory and locks it against direct web access.
 *
 * @package Pontifex\Filesystem
 */

declare(strict_types=1);

namespace Pontifex\Filesystem;

/**
 * Ensures a directory exists and is protected from being browsed or downloaded
 * over HTTP.
 *
 * Pontifex writes logs, rollback safety archives, and diagnostic bundles under
 * `wp-content/pontifex/`. On a typical Apache host those paths would otherwise
 * be directly fetchable by URL — and a safety archive is a full site-plus-database
 * backup, so that would be a serious leak. This helper drops the two guards
 * WordPress core and major plugins use for their own private upload directories:
 * an `.htaccess` denying all access (Apache 2.2 and 2.4), and an empty
 * `index.php` so a server with directory listing enabled cannot enumerate the
 * contents. It cannot help on nginx, where access must be denied in the server
 * configuration — that limitation is documented for operators.
 *
 * Like {@see \Pontifex\Log\FileLogger}, this MUST NEVER throw or surface a PHP
 * warning: failing to write a guard must not break the backup or restore the
 * directory was created for. Every I/O failure is swallowed; the worst case is
 * an unguarded directory, which is no worse than before this helper existed.
 *
 * It has no WordPress coupling, so it unit-tests against a temporary directory
 * with no WordPress bootstrap. All static; not instantiable.
 */
final class ProtectedDirectory {

	/**
	 * Contents of the `.htaccess` guard: deny all direct web access.
	 *
	 * Covers both Apache 2.4 (`Require all denied`) and the older 2.2
	 * (`Deny from all`), so the directory is protected regardless of the
	 * host's Apache version.
	 *
	 * @var string
	 */
	private const HTACCESS = "# Pontifex: deny direct web access to this directory.\n"
		. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
		. "<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n";

	/**
	 * Contents of the `index.php` guard: silence directory listing.
	 *
	 * @var string
	 */
	private const INDEX_PHP = "<?php\n// Silence is golden.\n";

	/**
	 * Prevent instantiation; this class exposes only static helpers.
	 */
	private function __construct() {
	}

	/**
	 * Ensure $dir exists with the given mode and carries the web-access guards.
	 *
	 * Best-effort and never-throwing. The guards are also written into the parent
	 * directory when that parent is the shared `pontifex` directory, so the whole
	 * `wp-content/pontifex/` tree is covered without ever touching `wp-content`
	 * itself (dropping a deny-all guard there would break the site's uploads).
	 *
	 * @param string $dir  Absolute directory path to create and protect.
	 * @param int    $mode Directory mode to create with (e.g. 0700).
	 * @return bool True if the directory exists (created or already present) after the call.
	 */
	public static function ensure( string $dir, int $mode ): bool {
		$dir = rtrim( $dir, '/\\' );

		self::make_directory( $dir, $mode );
		if ( ! is_dir( $dir ) ) {
			return false;
		}

		self::write_guards( $dir );

		$parent = dirname( $dir );
		if ( 'pontifex' === basename( $parent ) && is_dir( $parent ) ) {
			self::write_guards( $parent );
		}

		return true;
	}

	/**
	 * Create the directory if it does not already exist (silently on failure).
	 *
	 * @param string $dir  The directory to create.
	 * @param int    $mode The mode to create it with.
	 * @return void
	 */
	private static function make_directory( string $dir, int $mode ): void {
		if ( is_dir( $dir ) ) {
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir, WordPress.PHP.NoSilencedErrors.Discouraged -- Plugin-owned directory; WP_Filesystem is unavailable in CLI/test contexts and a guard failure must never surface.
		@mkdir( $dir, $mode, true );
	}

	/**
	 * Write both guard files into a directory if they are absent.
	 *
	 * @param string $dir The directory to guard.
	 * @return void
	 */
	private static function write_guards( string $dir ): void {
		self::write_if_absent( $dir . '/.htaccess', self::HTACCESS );
		self::write_if_absent( $dir . '/index.php', self::INDEX_PHP );
	}

	/**
	 * Write a guard file unless it already exists (silently on failure).
	 *
	 * @param string $path     Absolute path to write.
	 * @param string $contents File contents.
	 * @return void
	 */
	private static function write_if_absent( string $path, string $contents ): void {
		if ( file_exists( $path ) ) {
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- Plugin-owned guard file; WP_Filesystem is unavailable in CLI/test contexts and a guard failure must never surface.
		@file_put_contents( $path, $contents );
	}
}
