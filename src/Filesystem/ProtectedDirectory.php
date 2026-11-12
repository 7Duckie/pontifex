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
	 * The return value reports the directory only: it is true whenever the
	 * directory exists on disk, whether or not either guard could be written or
	 * repaired. A guard failure never turns this false, because refusing to
	 * proceed with the backup or restore the directory exists for — over a
	 * directory that is merely unguarded rather than actually broken — would be
	 * a worse outcome than the unguarded directory itself.
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
	 * Write both guard files into a directory, repairing one this class
	 * previously failed to write in full.
	 *
	 * @param string $dir The directory to guard.
	 * @return void
	 */
	private static function write_guards( string $dir ): void {
		try {
			self::write_or_repair( $dir . '/.htaccess', self::HTACCESS );
			self::write_or_repair( $dir . '/index.php', self::INDEX_PHP );
		} catch ( \Throwable $ignored ) {
			// A host can remove file_put_contents(), file_get_contents(), filesize(),
			// is_link() or is_file() entirely via disable_functions. On PHP 8, calling
			// a disabled function raises \Error rather than the suppressible warning
			// it raised on PHP 7, so the @ operators below cannot stop it reaching
			// here. An unguarded directory must never break the backup or restore the
			// directory was created for, so any such failure is swallowed.
			unset( $ignored );
		}
	}

	/**
	 * Write a guard file, or repair it if what is on disk is a truncated or
	 * empty write this class made itself.
	 *
	 * `file_put_contents()` writes from the first byte, so a write cut short —
	 * a full disk being the measured cause — always leaves behind a PREFIX of
	 * the intended content; an empty file is just the prefix of zero length.
	 * An operator's own file placed at this path will not, by chance, happen
	 * to be a prefix of Pontifex's guard, so "is what's on disk a prefix of
	 * what we would write" repairs every partial write this class could have
	 * produced without ever touching a genuine customisation. This closes a
	 * failure measured on a real site: the `wp-content/pontifex/` tree was
	 * first created at zero free disk space, so `file_put_contents()` created
	 * each guard file and then wrote nothing into it — and because the file
	 * then existed, the previous file_exists()-then-write logic never
	 * revisited it. All four guard files stayed empty permanently, and the
	 * whole-site backups the directory holds were reachable by a plain web
	 * request on that Apache host.
	 *
	 * A symbolic link at this path is never written through, even though it
	 * would otherwise read as a prefix match: Pontifex never creates one
	 * here, so one present is not ours to repair, and writing through it
	 * would follow the link and place guard bytes wherever the link points —
	 * possibly outside this directory entirely.
	 *
	 * @param string $path     Absolute path to write or repair.
	 * @param string $contents The full intended guard contents.
	 * @return void
	 */
	private static function write_or_repair( string $path, string $contents ): void {
		if ( is_link( $path ) ) {
			// This must be checked before file_exists() below, not after: a
			// symlink whose target does not exist is exactly the case this
			// guards against, and file_exists() follows the link and reports
			// false for one — so a check placed after it is unreachable for the
			// dangling case, and the branch below would then write the guard
			// straight through the link, creating its target instead of a guard
			// file. This closes a hole that predates the repair work in this
			// method: the original file_exists()-then-write code had the same
			// gap, it was simply never exercised.
			return;
		}

		if ( ! file_exists( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- Plugin-owned guard file; WP_Filesystem is unavailable in CLI/test contexts and a guard failure must never surface.
			@file_put_contents( $path, $contents );
			return;
		}

		if ( ! is_file( $path ) ) {
			// A directory, or anything else at this path that is not a plain
			// file: not ours.
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filesize -- Sizing a plugin-owned guard file, before reading it, to rule out a large file that cannot possibly be a prefix of ours; WP_Filesystem is unavailable in CLI/test contexts.
		$size = filesize( $path );
		if ( false === $size || $size > strlen( $contents ) ) {
			// Bigger than the guard we would write, or unreadable: it cannot be a
			// prefix of our content, so it is not a partial write of ours.
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- Reading a plugin-owned guard file, already size-bounded above, to test whether it is a prefix of our own content; WP_Filesystem is unavailable in CLI/test contexts.
		$existing = @file_get_contents( $path );
		if ( false === $existing || $contents === $existing ) {
			// Unreadable, or already the full guard: nothing to repair.
			return;
		}

		if ( str_starts_with( $contents, $existing ) ) {
			// What is on disk is the leading bytes of what we intend to write —
			// exactly what a write cut short by a full disk leaves behind.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- Plugin-owned guard file; WP_Filesystem is unavailable in CLI/test contexts and a guard failure must never surface.
			@file_put_contents( $path, $contents );
			return;
		}

		// Some other content entirely, not a prefix of ours: an operator's own
		// file. Leave it untouched.
	}
}
