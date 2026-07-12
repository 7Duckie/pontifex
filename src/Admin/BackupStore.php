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
	 * Subdirectory, under the content directory, where in-progress uploads assemble.
	 *
	 * A sibling of the backups directory: a foreign backup uploaded from the browser
	 * lands here as a `.part` file, chunk by chunk, and is moved into the backups
	 * directory only once complete and proven to be a real archive.
	 *
	 * @var string
	 */
	private const UPLOADS_SUBDIRECTORY = 'pontifex/uploads';

	/**
	 * Extension of an in-progress upload's part file.
	 *
	 * @var string
	 */
	private const PART_EXTENSION = '.part';

	/**
	 * The pattern an upload id must match to name a part file.
	 *
	 * Only letters and digits, 8 to 64 of them — long enough to be unguessable,
	 * and admitting nothing (no dot, slash, or separator) that could carry the part
	 * file out of the uploads directory. The browser mints a 32-character hex token;
	 * anything failing this pattern is refused before any filesystem path is built.
	 *
	 * @var string
	 */
	private const UPLOAD_ID_PATTERN = '/^[A-Za-z0-9]{8,64}$/';

	/**
	 * Absolute path of the backups directory.
	 *
	 * @var string
	 */
	private string $directory;

	/**
	 * Absolute path of the in-progress uploads directory.
	 *
	 * @var string
	 */
	private string $uploads;

	/**
	 * Construct a store rooted at the given content directory.
	 *
	 * @param string $content_dir Absolute path of the WordPress content directory (WP_CONTENT_DIR).
	 */
	public function __construct( string $content_dir ) {
		$root            = rtrim( $content_dir, '/' );
		$this->directory = $root . '/' . self::SUBDIRECTORY;
		$this->uploads   = $root . '/' . self::UPLOADS_SUBDIRECTORY;
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

	// -------------------------------------------------------------------------
	// Uploads — assembling a foreign backup posted in chunks from the browser.
	// -------------------------------------------------------------------------

	/**
	 * Return the absolute path of the in-progress uploads directory.
	 *
	 * @return string The absolute uploads directory path.
	 */
	public function uploads_directory(): string {
		return $this->uploads;
	}

	/**
	 * Create the uploads directory (mode 0700, web-blocked) if it does not exist.
	 *
	 * The same policy as the backups directory: an uploaded backup is a full copy of
	 * another site, so its assembly area is owner-only and locked against direct web
	 * access. Asserted here, so the caller gets an exception when the directory
	 * cannot be made.
	 *
	 * @return void
	 * @throws RuntimeException If the directory cannot be created.
	 */
	public function ensure_uploads_directory(): void {
		if ( ! ProtectedDirectory::ensure( $this->uploads, self::DIRECTORY_MODE ) ) {
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message only; the path is plugin-derived, not web output.
				sprintf( 'BackupStore: could not create the uploads directory: %s', $this->uploads )
			);
		}
	}

	/**
	 * Append a chunk to an upload's part file, returning the assembled size so far.
	 *
	 * The first chunk (`$first`) opens the part file fresh, truncating any earlier
	 * part left under the same id, so a re-used id starts clean rather than appending
	 * to stale bytes; later chunks append. The chunk's bytes are streamed from the
	 * temporary upload file into the part file, never held whole in memory. Returns
	 * the total number of bytes now in the part file.
	 *
	 * @param string $id       The upload id (validated; see {@see self::UPLOAD_ID_PATTERN}).
	 * @param string $chunk_path Absolute path of the temporary file holding this chunk.
	 * @param bool   $first    Whether this is the first chunk of the upload.
	 * @return int The assembled size, in bytes, after appending this chunk.
	 * @throws RuntimeException If the id is malformed or the part file cannot be written.
	 */
	public function append_chunk( string $id, string $chunk_path, bool $first ): int {
		$part = $this->upload_part_path( $id );
		if ( null === $part ) {
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message only; not web output.
				'BackupStore: refusing a malformed upload id.'
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming an upload chunk into the plugin-owned part file; WP_Filesystem is unavailable in CLI/ajax contexts.
		$source = fopen( $chunk_path, 'rb' );
		if ( false === $source ) {
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message only; not web output.
				'BackupStore: could not read the uploaded chunk.'
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Opening the plugin-owned part file to assemble the upload; WP_Filesystem is unavailable in CLI/ajax contexts.
		$destination = fopen( $part, $first ? 'wb' : 'ab' );
		if ( false === $destination ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the chunk stream after a failed part-file open.
			fclose( $source );
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message only; not web output.
				'BackupStore: could not open the upload part file.'
			);
		}

		stream_copy_to_stream( $source, $destination );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the chunk stream once copied.
		fclose( $source );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the part-file stream once the chunk is appended.
		fclose( $destination );

		return $this->upload_size( $id );
	}

	/**
	 * Return the assembled size, in bytes, of an upload's part file.
	 *
	 * Zero when the id is malformed or no part file exists, so a bad id is a
	 * dead-end rather than an error.
	 *
	 * @param string $id The upload id.
	 * @return int The bytes assembled so far, or 0.
	 */
	public function upload_size( string $id ): int {
		$part = $this->upload_part_path( $id );
		if ( null === $part || ! is_file( $part ) ) {
			return 0;
		}
		clearstatcache( true, $part );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filesize -- Reading the plugin-owned part file's assembled size; WP_Filesystem is unavailable in CLI/ajax contexts.
		$size = filesize( $part );
		return false === $size ? 0 : (int) $size;
	}

	/**
	 * Open an upload's assembled part file for reading, or null when there is none.
	 *
	 * The caller (the upload controller) reads the completed file to prove it parses
	 * as an archive before it is finalised, and closes the stream itself.
	 *
	 * @param string $id The upload id.
	 * @return resource|null A read stream on the part file, or null when the id is bad or no part exists.
	 */
	public function open_upload( string $id ) {
		$part = $this->upload_part_path( $id );
		if ( null === $part || ! is_file( $part ) ) {
			return null;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Opening the plugin-owned part file to validate the assembled upload; WP_Filesystem is unavailable in CLI/ajax contexts.
		$stream = fopen( $part, 'rb' );
		return false === $stream ? null : $stream;
	}

	/**
	 * Discard an in-progress upload by removing its part file.
	 *
	 * Best-effort and safe on a bad id or a missing part: an abandoned or refused
	 * upload must be able to clean up without raising.
	 *
	 * @param string $id The upload id.
	 * @return void
	 */
	public function discard_upload( string $id ): void {
		$part = $this->upload_part_path( $id );
		if ( null === $part || ! is_file( $part ) ) {
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort removal of a plugin-owned upload part file; its failure must not raise.
		@unlink( $part );
	}

	/**
	 * Finalise a completed upload: move its part file into the backups directory.
	 *
	 * The assembled part file is renamed into the backups directory under the normal
	 * `pontifex-backup-<UTC>.wpmig` name for the given moment. Nothing is ever
	 * overwritten: if a backup already holds that exact second, the stamp is advanced
	 * a second at a time until a free name is found. Returns the absolute path of the
	 * stored backup.
	 *
	 * @param string            $id  The upload id whose part file to store.
	 * @param DateTimeImmutable $now The moment to stamp the stored backup with.
	 * @return string The absolute path of the stored backup.
	 * @throws RuntimeException If the id is bad, no part file exists, or the move fails.
	 */
	public function finalise_upload( string $id, DateTimeImmutable $now ): string {
		$part = $this->upload_part_path( $id );
		if ( null === $part || ! is_file( $part ) ) {
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message only; not web output.
				'BackupStore: there is no completed upload to finalise.'
			);
		}

		$this->ensure_directory();

		$moment = $now;
		$target = $this->next_backup_path( $moment );
		while ( file_exists( $target ) ) {
			$moment = $moment->modify( '+1 second' );
			$target = $this->next_backup_path( $moment );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Moving the plugin-owned, validated upload into the backups directory; WP_Filesystem is unavailable in CLI/ajax contexts.
		if ( ! rename( $part, $target ) ) {
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message only; the path is plugin-derived, not web output.
				sprintf( 'BackupStore: could not store the uploaded backup at %s', $target )
			);
		}

		return $target;
	}

	/**
	 * Remove abandoned upload part files older than the given age.
	 *
	 * An interrupted upload leaves a part file behind. Swept on the next upload's
	 * first chunk so stale attempts do not accumulate, comparing each part's
	 * modification time against the cutoff; fresh parts (including other uploads in
	 * flight) are kept.
	 *
	 * @param int $max_age_seconds Age, in seconds, past which a part file is removed.
	 * @return void
	 */
	public function sweep_stale_uploads( int $max_age_seconds ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_glob -- Listing the plugin-owned uploads directory; WP_Filesystem is unavailable in CLI/ajax contexts.
		$parts = glob( $this->uploads . '/*' . self::PART_EXTENSION );
		if ( false === $parts ) {
			return;
		}

		$cutoff = time() - $max_age_seconds;
		foreach ( $parts as $part ) {
			clearstatcache( true, $part );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filemtime -- Reading a plugin-owned part file's age to sweep stale uploads; WP_Filesystem is unavailable in CLI/ajax contexts.
			$mtime = filemtime( $part );
			if ( false !== $mtime && $mtime < $cutoff ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort removal of a stale plugin-owned upload part file.
				@unlink( $part );
			}
		}
	}

	/**
	 * Build the absolute part-file path for an upload id, or null when the id is bad.
	 *
	 * The single gate every upload method passes an id through: it admits only the
	 * strict {@see self::UPLOAD_ID_PATTERN} (letters and digits), so a crafted id
	 * carrying a separator can never turn into a path outside the uploads directory.
	 *
	 * @param string $id The upload id supplied by the request.
	 * @return string|null The absolute part-file path, or null when the id is malformed.
	 */
	private function upload_part_path( string $id ): ?string {
		if ( 1 !== preg_match( self::UPLOAD_ID_PATTERN, $id ) ) {
			return null;
		}
		return $this->uploads . '/' . $id . self::PART_EXTENSION;
	}
}
