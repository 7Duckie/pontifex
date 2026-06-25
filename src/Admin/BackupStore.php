<?php
/**
 * Pontifex backup store — the on-disk directory of operator-created backups.
 *
 * @package Pontifex\Admin
 */

declare(strict_types=1);

namespace Pontifex\Admin;

use DateTimeImmutable;
use DateTimeZone;
use Pontifex\Filesystem\ProtectedDirectory;
use RuntimeException;

/**
 * Manages `wp-content/pontifex/backups/` and the backups within it.
 *
 * The admin Backup screen writes full-site archives here. The directory is the
 * sibling of the rollback store's (see {@see \Pontifex\Rollback\RollbackStore})
 * and shares its policy, because a backup is the same sensitive artefact — a full
 * copy of the site and its database:
 *
 *  - **Location:** a `pontifex/backups` subdirectory of the content directory,
 *    created not world-readable (mode 0700) and locked against direct web access
 *    by {@see ProtectedDirectory}.
 *  - **Naming:** `pontifex-backup-<UTC>.wpmig`, the time formatted in UTC so the
 *    newest backup is the lexicographically last.
 *  - **Retrieval:** {@see self::resolve()} is the single gate through which the
 *    download and delete handlers turn an operator-supplied filename into an
 *    absolute path. It accepts only a bare filename that matches the exact naming
 *    pattern and really exists in this directory, so a crafted value
 *    (`../wp-config.php`, an absolute path, a planted symlink) can never escape
 *    the backups directory.
 *
 * Filesystem work uses PHP's built-ins directly, mirroring RollbackStore; the
 * class has no WordPress coupling and is exercised against a real temporary
 * directory in its tests.
 */
final class BackupStore {

	/**
	 * Subdirectory, under the content directory, where backups live.
	 *
	 * @var string
	 */
	private const SUBDIRECTORY = 'pontifex/backups';

	/**
	 * Filename prefix shared by every backup.
	 *
	 * @var string
	 */
	private const NAME_PREFIX = 'pontifex-backup-';

	/**
	 * Filename extension shared by every backup.
	 *
	 * @var string
	 */
	private const NAME_EXTENSION = '.wpmig';

	/**
	 * The exact filename pattern a backup name must match to be retrievable.
	 *
	 * Anchored at both ends and admitting only the prefix, a `Ymd\THis\Z` UTC
	 * stamp, and the extension — nothing that could carry a path separator.
	 *
	 * @var string
	 */
	private const NAME_PATTERN = '/^pontifex-backup-\d{8}T\d{6}Z\.wpmig$/';

	/**
	 * The format a backup's UTC timestamp is encoded with in its name.
	 *
	 * @var string
	 */
	private const STAMP_FORMAT = 'Ymd\THis\Z';

	/**
	 * Mode the backups directory is created with: owner-only (rwx------).
	 *
	 * @var int
	 */
	private const DIRECTORY_MODE = 0700;

	/**
	 * Sentinel filename whose presence asks a running backup to stop.
	 *
	 * A dot-file, so it never matches the backup glob or the strict retrieval
	 * pattern; it lives in this owner-only, web-protected directory. The cancel
	 * request creates it and the running export polls for it — the one signal that
	 * crosses the two requests reliably (a transient cannot be re-read mid-request
	 * without a persistent object cache).
	 *
	 * @var string
	 */
	private const CANCEL_SENTINEL = '.pontifex-cancel';

	/**
	 * Absolute path of the backups directory.
	 *
	 * @var string
	 */
	private string $directory;

	/**
	 * Construct a store rooted at the given content directory.
	 *
	 * @param string $content_dir Absolute path of the WordPress content directory (WP_CONTENT_DIR).
	 */
	public function __construct( string $content_dir ) {
		$this->directory = rtrim( $content_dir, '/' ) . '/' . self::SUBDIRECTORY;
	}

	/**
	 * Return the absolute path of the backups directory.
	 *
	 * @return string The absolute directory path.
	 */
	public function directory(): string {
		return $this->directory;
	}

	/**
	 * Create the backups directory (mode 0700) if it does not already exist.
	 *
	 * @return void
	 * @throws RuntimeException If the directory cannot be created.
	 */
	public function ensure_directory(): void {
		// Create the not-world-readable directory and lock it against direct web
		// access (a backup is a full site backup). ProtectedDirectory is
		// best-effort, so the hard guarantee — the directory exists — is asserted
		// here, where the caller expects an exception on failure.
		if ( ! ProtectedDirectory::ensure( $this->directory, self::DIRECTORY_MODE ) ) {
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message only; the path is plugin-derived, not web output.
				sprintf( 'BackupStore: could not create the backups directory: %s', $this->directory )
			);
		}
	}

	/**
	 * Return the absolute path a new backup should be written to.
	 *
	 * @param DateTimeImmutable $now The moment the backup is being taken.
	 * @return string The absolute backup path.
	 */
	public function next_backup_path( DateTimeImmutable $now ): string {
		$utc = $now->setTimezone( new DateTimeZone( 'UTC' ) );
		return $this->directory . '/' . self::NAME_PREFIX . $utc->format( self::STAMP_FORMAT ) . self::NAME_EXTENSION;
	}

	/**
	 * Return every backup in the directory, oldest first.
	 *
	 * @return array<int, string> Absolute backup paths, oldest to newest.
	 */
	public function backups(): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_glob -- Listing the plugin-owned backups directory; WP_Filesystem is unavailable in CLI/test contexts.
		$matches = glob( $this->directory . '/' . self::NAME_PREFIX . '*' . self::NAME_EXTENSION );
		if ( false === $matches ) {
			return array();
		}
		sort( $matches );
		return $matches;
	}

	/**
	 * Resolve an operator-supplied filename to a real backup path, or null.
	 *
	 * The single gate the download and delete handlers pass a filename through.
	 * It is deliberately strict and fails closed: the value must be a bare
	 * filename (no directory component), must match the exact backup naming
	 * pattern, and must name a regular file that — after symlink resolution —
	 * really sits inside the backups directory. Anything else returns null, so a
	 * traversal payload, an absolute path, or a planted symlink cannot turn into a
	 * read or delete outside this directory.
	 *
	 * @param string $filename The filename supplied by the request.
	 * @return string|null The absolute, canonical path, or null when the name is not a real backup here.
	 */
	public function resolve( string $filename ): ?string {
		if ( basename( $filename ) !== $filename ) {
			return null;
		}
		if ( 1 !== preg_match( self::NAME_PATTERN, $filename ) ) {
			return null;
		}

		$path = $this->directory . '/' . $filename;
		if ( ! is_file( $path ) ) {
			return null;
		}

		$real_path = realpath( $path );
		$real_dir  = realpath( $this->directory );
		if ( false === $real_path || false === $real_dir || 0 !== strpos( $real_path, $real_dir . '/' ) ) {
			return null;
		}

		return $real_path;
	}

	/**
	 * Delete a backup by filename, returning whether a backup was removed.
	 *
	 * Routes the filename through {@see self::resolve()} first, so only a real
	 * backup in this directory can be deleted; a name that does not resolve
	 * returns false and touches nothing.
	 *
	 * @param string $filename The filename supplied by the request.
	 * @return bool True if a backup was resolved and unlinked, false otherwise.
	 */
	public function delete( string $filename ): bool {
		$path = $this->resolve( $filename );
		if ( null === $path ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removing a plugin-owned backup the operator chose to delete; WP_Filesystem is unavailable in CLI/test contexts.
		return unlink( $path );
	}

	/**
	 * Ask the running backup to stop by creating the cancel sentinel.
	 *
	 * Called by the cancel endpoint, which runs in a separate request from the
	 * export. The export polls {@see self::is_cancel_requested()} and unwinds when
	 * it sees the file. The caller ensures the directory exists first.
	 *
	 * @return void
	 */
	public function request_cancel(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing a plugin-owned cancel sentinel in the protected backups directory; WP_Filesystem is unavailable in CLI/test contexts.
		file_put_contents( $this->directory . '/' . self::CANCEL_SENTINEL, '' );
	}

	/**
	 * Whether a cancel has been requested for the running backup.
	 *
	 * The export calls this repeatedly within one long request, so PHP's stat
	 * cache would otherwise hide a sentinel another request has just created; the
	 * cache is cleared for the path before each check.
	 *
	 * @return bool True if the cancel sentinel is present.
	 */
	public function is_cancel_requested(): bool {
		$path = $this->directory . '/' . self::CANCEL_SENTINEL;
		clearstatcache( true, $path );
		return is_file( $path );
	}

	/**
	 * Remove the cancel sentinel, if present.
	 *
	 * Best-effort: a stale sentinel is cleared at the start of a backup and the
	 * sentinel is removed on every exit path, so a failure to unlink must not
	 * abort the backup lifecycle.
	 *
	 * @return void
	 */
	public function clear_cancel(): void {
		$path = $this->directory . '/' . self::CANCEL_SENTINEL;
		if ( is_file( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort removal of the plugin-owned cancel sentinel; its failure must not abort the backup lifecycle.
			@unlink( $path );
		}
	}
}
