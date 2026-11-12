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
use Pontifex\Archive\ArchiveName;

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
	 * Deferred to {@see ArchiveName::PATTERN} so this and remote retention
	 * cannot drift, which is how the SFTP adapter once accepted any `.wpmig` at
	 * all. An offsite destination's own retention still sorts by this name
	 * shape, because its remote listing carries no modification time to sort by
	 * instead (see {@see \Pontifex\Destination\DestinationRetention}). This
	 * store's own listing does NOT: see {@see self::compare_by_age()} for why a
	 * name-based sort was retired here.
	 *
	 * @var string
	 */
	private const NAME_PATTERN = ArchiveName::PATTERN;

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
				sprintf( 'Could not create the backups directory: %s', $this->directory )
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

		// List only files matching the strict retrieval pattern that resolve()
		// gates on, so anything shown here can always be verified, downloaded, or
		// deleted. A foreign or malformed file that the loose glob catches but
		// resolve() would refuse is left out rather than shown as a row that no
		// action can touch.
		$backups = array();
		foreach ( $matches as $path ) {
			if ( 1 === preg_match( self::NAME_PATTERN, basename( $path ) ) ) {
				$backups[] = $path;
			}
		}

		// "Now" is read ONCE for the whole sort, not once per comparison: a
		// comparator usort() relies on must be consistent across every pair it
		// is asked about, and re-reading a live clock inside the comparator
		// could hand back a different "now" to two different pairwise calls
		// within the very same sort, which is not a well-defined order at all.
		$now = time();
		usort(
			$backups,
			static fn ( string $a, string $b ): int => self::compare_by_age( $a, $b, $now )
		);
		return $backups;
	}

	/**
	 * Compare two backups by age, oldest first — the ordering {@see self::backups()} sorts with.
	 *
	 * Sorting by NAME was the original defect. A backup's name is untrusted,
	 * self-reported data: every field of a stamp like `20991231T235959Z` is
	 * calendar-valid, so no stricter naming pattern can refuse it, and parsing
	 * the date out of the name instead of comparing the string only moves the
	 * same trust-the-string problem behind a parser. Measured on a live site: a
	 * single future-dated file sorted last for ever under a name sort, so every
	 * genuinely current backup looked "oldest" and was pruned in its place —
	 * four consecutive scheduled runs each deleted the PREVIOUS run's own
	 * backup within the same second it was created, while the future-dated file
	 * survived every prune and permanently occupied a retention slot. That
	 * file's real modification time predated every backup it went on to
	 * destroy: a modification-time sort would have pruned it first, every time.
	 *
	 * A first attempt keyed the tie-break on "clamped" (future OR unreadable)
	 * rather than on "future" alone, and that mixes together two untrustworthy
	 * cases that are not the same and must not be treated the same:
	 *
	 *  - A FUTURE modification time is evidence that THIS PARTICULAR file's
	 *    timestamp is wrong. It is a positive signal about one file, so that
	 *    one file can safely be treated as the oldest thing in the set.
	 *  - An UNREADABLE modification time is a property of the HOST, affecting
	 *    every file equally. Treating an unreadable read as "oldest" could
	 *    make everything on a host that cannot read mtimes look prunable at
	 *    once, which is a far worse failure than the one being guarded
	 *    against.
	 *
	 * Clamping a future timestamp to "now" (so it cannot claim to be newer
	 * than everything else by an arbitrary margin) is not enough on its own:
	 * clamping only ties a future entry with a genuinely fresh one taken at
	 * the same instant, and an ordinary tie-break by name lets the
	 * future-dated file win that tie and survive at the fresh backup's
	 * expense — the original bug, reintroduced one level down, because an
	 * exact same-second tie is the only case a clamp-then-sort rule actually
	 * catches. Two keys, in order:
	 *
	 *  1. PRIMARY — whether the modification time is in the future. If
	 *     exactly one of the two entries is future-dated, that entry sorts
	 *     FIRST — before every trusted entry, regardless of the times
	 *     involved — because it is untrustworthy and untrustworthy is treated
	 *     as oldest. A backup written under a clock skewed into the future is
	 *     consequently now the first thing retention removes; that is a
	 *     deliberate trade-off, because the measured alternative is that one
	 *     such file evicts a genuine backup on every cycle, for ever. One
	 *     file lost once beats a real backup destroyed every night.
	 *  2. SECONDARY — otherwise (neither is future-dated, or both are),
	 *     `min( filemtime(), now )` ascending, so among trustworthy entries
	 *     the genuinely older one still sorts first.
	 *  3. TERTIARY — name, ascending, so a tie that reaches this point still
	 *     resolves deterministically.
	 *
	 * A modification time that cannot be read — `filemtime()` unavailable
	 * (commonly `disable_functions`, which raises a fatal `Error` on PHP 8
	 * rather than a suppressible warning) or the read itself failing — is
	 * treated as CURRENT time and TRUSTED (not future-dated): an entry whose
	 * age cannot be established must never sort as the oldest, because
	 * "oldest" is what gets deleted.
	 *
	 * $now is supplied by the caller, read once for the whole sort — see
	 * {@see self::backups()} — rather than read again here, so every
	 * comparison within one sort is measured against the identical instant.
	 *
	 * @param string $a   One absolute backup path.
	 * @param string $b   Another absolute backup path.
	 * @param int    $now The current Unix timestamp, shared by every comparison in this sort.
	 * @return int Negative when $a is older, positive when $b is older, zero when equal by every key.
	 */
	private static function compare_by_age( string $a, string $b, int $now ): int {
		$age_a = self::age( $a, $now );
		$age_b = self::age( $b, $now );

		if ( $age_a['future'] !== $age_b['future'] ) {
			// A future-dated (untrustworthy) entry sorts FIRST — as the oldest.
			return $age_a['future'] ? -1 : 1;
		}
		if ( $age_a['time'] !== $age_b['time'] ) {
			return $age_a['time'] <=> $age_b['time'];
		}
		return strcmp( $a, $b );
	}

	/**
	 * Compute one path's sort key: its modification time, clamped to now, and
	 * whether it was genuinely future-dated.
	 *
	 * @param string $path The absolute backup path.
	 * @param int    $now  The current Unix timestamp, read once per sort so every
	 *                     entry compared in that sort is measured against the same "now".
	 * @return array{time: int, future: bool} The clamped time, and whether the raw modification time was in the future.
	 */
	private static function age( string $path, int $now ): array {
		$mtime = self::modification_time( $path );
		if ( false === $mtime ) {
			// Unreadable: a host-wide condition, not evidence against this one
			// file, so it is trusted as current rather than treated as oldest.
			return array(
				'time'   => $now,
				'future' => false,
			);
		}
		return array(
			'time'   => min( $mtime, $now ),
			'future' => $mtime > $now,
		);
	}

	/**
	 * Read a path's modification time, tolerating a host that has removed filemtime().
	 *
	 * Guarded with function_exists() because a disabled function raises a fatal
	 * Error on PHP 8 rather than a suppressible warning — the same defect fixed
	 * across five call sites in this codebase already. A raw read failure (a
	 * TOCTOU race after glob(), or a host restriction such as open_basedir)
	 * degrades to false the same way, so the caller treats both identically.
	 *
	 * @param string $path The absolute backup path.
	 * @return int|false The modification time, or false when it cannot be read.
	 */
	private static function modification_time( string $path ): int|false {
		if ( ! function_exists( 'filemtime' ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filemtime,WordPress.PHP.NoSilencedErrors.Discouraged -- Reading a plugin-owned backup's age for retention ordering; best-effort, guarded by function_exists(), and a read failure degrades to "current time, clamped" rather than aborting the listing.
		return @filemtime( $path );
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
		if ( false === $real_path || false === $real_dir ) {
			return null;
		}

		// Compare on forward slashes regardless of platform. $this->directory is
		// built with forward slashes, but realpath() answers in the platform's
		// own separator -- a backslash on Windows -- so a hard-coded '/' here
		// could never prefix a real Windows path. The guard fired on every
		// legitimate file, and because this method is the single gate behind
		// Download, Delete, Verify, Restore and Preview, all five answered "that
		// backup could not be found" for backups plainly listed on the screen.
		// Creating one still worked, since that path never calls resolve(), so
		// the site produced backups it then refused to touch.
		$separator_free_path = str_replace( '\\', '/', $real_path );
		$separator_free_dir  = str_replace( '\\', '/', $real_dir );
		if ( 0 !== strpos( $separator_free_path, $separator_free_dir . '/' ) ) {
			return null;
		}

		// The real path is returned unnormalised: callers hand it to the
		// filesystem, which wants the platform's own separator back.
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
				sprintf( 'Could not create the uploads directory: %s', $this->uploads )
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
				'Refusing a malformed upload id.'
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming an upload chunk into the plugin-owned part file; WP_Filesystem is unavailable in CLI/ajax contexts.
		$source = fopen( $chunk_path, 'rb' );
		if ( false === $source ) {
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message only; not web output.
				'Could not read the uploaded chunk.'
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Opening the plugin-owned part file to assemble the upload; WP_Filesystem is unavailable in CLI/ajax contexts.
		$destination = fopen( $part, $first ? 'wb' : 'ab' );
		if ( false === $destination ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the chunk stream after a failed part-file open.
			fclose( $source );
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message only; not web output.
				'Could not open the upload part file.'
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
				'There is no completed upload to finalise.'
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
				sprintf( 'Could not store the uploaded backup at %s', $target )
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
