<?php
/**
 * Pontifex file writer — restores one decoded archive entry to the filesystem.
 *
 * @package Pontifex\Restore
 */

declare(strict_types=1);

namespace Pontifex\Restore;

use Pontifex\Exception\ArchiveNotTrustworthy;
use Pontifex\Exception\HostCannotComply;

use Closure;
use InvalidArgumentException;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Format\ManifestEntry;
use Pontifex\Archive\Reader\EntryReadResult;
use Pontifex\Filesystem\TempArtefact;

/**
 * Writes one decoded archive entry back to the filesystem.
 *
 * The mirror of {@see \Pontifex\Manifest\FileScanner}. Where the
 * scanner walked the filesystem and turned files, directories, and
 * symlinks into ScannedEntry value objects, FileWriter takes the
 * decoded form (an EntryReadResult from EntryReader) and writes
 * each entry back to its filesystem location with the recorded
 * contents, mode, mtime, and symlink target.
 *
 * Public API (locked for v0.1.0):
 *
 *  - {@see FileWriter::__construct()} — takes the destination root
 *    (an absolute path) under which all entries are restored. The
 *    root is created if it does not exist.
 *  - {@see FileWriter::write_entry()} — restore one entry. Refuses
 *    db_chunk entries (those go through DatabaseWriter in a later
 *    commit); refuses paths that would escape the destination root.
 *  - {@see FileWriter::assert_symlink_targets_confined()} — the
 *    whole-archive symlink preflight, run once by the caller before
 *    any entry is written: every symlink the archive declares is
 *    resolved the way the kernel would resolve it, and one that lands
 *    outside the site (or on wp-config.php, or in Pontifex's own
 *    working directory) refuses the restore before it starts.
 *
 * Path-traversal defense:
 *
 * Entry paths come from the archive's header field, which is
 * untrusted input. FileWriter rejects:
 *
 *  - Absolute paths (any path beginning with "/" or matching a
 *    drive letter on Windows-style paths).
 *  - Paths containing ".." segments. A correctness archive never
 *    needs them; their presence indicates either a crafted
 *    malicious archive or a bug in the writer.
 *  - Null bytes in any path component, which can confuse PHP's
 *    filesystem layer.
 *
 * The defense applies to entry.path and (for symlinks) to where
 * the link itself is placed. A symlink's TARGET is governed
 * separately, by {@see FileWriter::assert_symlink_targets_confined()}
 * — a preflight over the archive's whole declared set of links, run
 * before the first byte is written, because "where will this link
 * actually point" is a question about the finished tree and cannot be
 * answered one entry at a time.
 *
 * Internal choices (implementation details; may change without
 * breaking the public API):
 *
 *  - Parent directories are created automatically (with mode 0755)
 *    when an entry's path includes a directory that does not yet
 *    exist. This keeps the writer usable when entries arrive in
 *    any order. A later commit may add an explicit
 *    "directory-entries-first" ordering convention.
 *  - On conflict (file/directory/symlink already exists), the
 *    writer overwrites. Conflict policy is a Phase 4 (CLI)
 *    concern, not a format-layer concern.
 *  - mtime is set after writing. mode is set after writing. Order
 *    matters because writing modifies mtime, and some filesystems
 *    clear mode bits on write under certain configurations.
 *  - Symlinks: the target string is taken verbatim from the
 *    archive. Whether the target exists, is absolute, or escapes
 *    the destination root is not the writer's concern.
 *  - Stateless after construction; safe to reuse across many
 *    entries.
 */
final class FileWriter {

	/**
	 * Default mode for parent directories created on demand.
	 *
	 * @var int
	 */
	private const PARENT_DIR_MODE = 0o755;

	/**
	 * How many symlinks one target may be resolved through before the preflight gives up.
	 *
	 * Every time {@see self::assert_symlink_target_confined()} finds that a
	 * component of a target is itself a symlink, it substitutes that link's own
	 * target and carries on — exactly what the kernel does. Two links pointing at
	 * each other would make that substitution run forever, so it is capped.
	 *
	 * Forty is the operating system's own ceiling on the most permissive host
	 * Pontifex is likely to run on (Linux's MAXSYMLINKS; macOS stops at 32), so
	 * no chain a real kernel would follow is refused for being too long, while a
	 * loop is stopped after a fixed, tiny amount of work rather than hanging the
	 * request. A hang on a hostile archive would be a denial of service on a live
	 * site, so this counter is a safety measure, not a tidiness one.
	 *
	 * @var int
	 */
	private const MAX_SYMLINK_HOPS = 40;

	/**
	 * How many distinct directories {@see self::assert_symlinks_creatable()} will probe.
	 *
	 * Every declared link's parent directory collapses, via
	 * {@see self::symlink_creation_probe_directories()}, to at most one probe —
	 * so a real archive, which clusters its links into a handful of
	 * directories at most (see the Composer-layout example in
	 * {@see self::assert_symlink_targets_confined()}'s docblock), never comes
	 * close to this. It exists only for a hostile or malformed archive built
	 * to declare links across as many distinct directories as it can, which
	 * would otherwise turn this preflight itself into thousands of real
	 * filesystem operations before the restore even starts. Sixty-four is
	 * comfortably above any legitimate layout while still cheap to probe in
	 * full, so a count above it is refused outright rather than silently
	 * probing only the first sixty-four and guessing at the rest.
	 *
	 * @var int
	 */
	private const MAX_SYMLINK_PROBE_DIRECTORIES = 64;

	/**
	 * Bytes in one megabyte, used only to render a disk-space shortfall in human terms.
	 *
	 * Matches the literal {@see \Pontifex\Archive\Reader\ArchiveReader} already
	 * uses for its own memory-shortfall message, so the two "not enough X on
	 * this server" messages a restore can surface read consistently.
	 *
	 * @var int
	 */
	private const BYTES_PER_MEGABYTE = 1048576;

	/**
	 * Absolute path of the directory under which all entries are restored.
	 *
	 * Always stored without a trailing slash.
	 *
	 * @var string
	 */
	private string $destination_root;

	/**
	 * Whether to allow symlink entries whose target escapes the restore root.
	 *
	 * False by default: a symlink whose target resolves outside the destination
	 * root (or is absolute) is refused, because a hostile archive can otherwise
	 * plant a link such as `uploads/x -> /etc` that later code follows. The
	 * operator can opt back into the old verbatim behaviour for a trusted archive.
	 *
	 * True bypasses BOTH target checks: the whole-archive preflight
	 * ({@see self::assert_symlink_targets_confined()}) and the per-entry check
	 * inside {@see self::write_symlink()}. It is an operator's statement that
	 * they have inspected this archive themselves, and it has no browser route
	 * — the admin screens hard-code it to false.
	 *
	 * @var bool
	 */
	private bool $allow_unsafe_symlinks;

	/**
	 * Path prefix every restored entry must sit under, or null to allow any path.
	 *
	 * Null for an unrestricted (whole-site) restore. Set to "wp-content" for a
	 * content-only restore, where {@see self::write_entry()} refuses any
	 * file/directory/symlink whose path is not the prefix itself or beneath it — so
	 * even a mislabelled content-only archive can never write WordPress core or
	 * wp-config.php. This is the write-boundary backstop behind the import command's
	 * up-front scope preflight (ADR 0008). Database chunks are unaffected: they go
	 * through DatabaseWriter, and the whole database is restored in both modes.
	 *
	 * @var string|null
	 */
	private ?string $required_prefix;

	/**
	 * Cached result of the destination filesystem case-sensitivity probe.
	 *
	 * Null until {@see self::destination_is_case_sensitive()} has run once;
	 * true or false thereafter for the lifetime of this writer. Every entry
	 * in one restore is written under the same destination_root, so the
	 * answer cannot change mid-restore — probing once and caching avoids a
	 * filesystem round trip per entry.
	 *
	 * @var bool|null
	 */
	private ?bool $case_sensitive_destination = null;

	/**
	 * Guard reading free disk space at a given path.
	 *
	 * Wraps PHP's disk_free_space() — the reading
	 * {@see self::assert_free_space_for()} weighs against what a restore is
	 * about to need. Held behind a seam (defaulting to the real function,
	 * silenced with `@` the same way
	 * {@see \Pontifex\Admin\UploadController::refuse_if_no_room()} silences its
	 * own upload-time disk check, since a host can disable or restrict the
	 * function) only so unit tests — which cannot make the real disk report an
	 * arbitrary free-space figure — can substitute a controlled reading;
	 * production always reads the real filesystem.
	 *
	 * @var Closure(string): (float|false)
	 */
	private Closure $disk_free_space;

	/**
	 * Guard probing whether this host can create a symlink in a given directory.
	 *
	 * The reading {@see self::assert_symlinks_creatable()} refuses the restore
	 * over. It runs first, ahead of the entry walk, so the only filesystem
	 * change it can itself have made by the time it refuses is its own probe
	 * symlink — created and removed again before the throw. Held behind a
	 * seam (defaulting to {@see self::probe_symlink_creation()}, the real
	 * create-then-remove attempt), the same way {@see self::$disk_free_space}
	 * is, only so unit tests — which cannot reliably make a real host refuse
	 * to create a symlink — can substitute a controlled outcome; production
	 * always probes the real filesystem. Called once per distinct directory
	 * {@see self::symlink_creation_probe_directories()} resolves the
	 * archive's declared links down to — not once per link, and not always
	 * $this->destination_root; see that method's docblock for why.
	 *
	 * @var Closure(string): bool
	 */
	private Closure $symlink_probe;

	/**
	 * Construct a FileWriter rooted at the given destination directory.
	 *
	 * The destination is created (with mode 0755) if it does not yet
	 * exist. Once created, the absolute, real path is stored so
	 * subsequent path-traversal checks can use string comparison.
	 *
	 * @param string        $destination_root      Absolute filesystem path of the restore root.
	 * @param bool          $allow_unsafe_symlinks Optional. Allow symlink targets that escape the root (default false).
	 * @param string|null   $required_prefix       Optional. When set (e.g. "wp-content"), refuse any entry whose path is not the prefix itself or beneath it; null (default) allows any path. Any trailing slash is trimmed.
	 * @param callable|null $disk_free_space       Optional free-space reader used by {@see self::assert_free_space_for()}, called as `( string $path ): float|false`; defaults to disk_free_space().
	 * @param callable|null $symlink_probe         Optional symlink-capability probe used by {@see self::assert_symlinks_creatable()}, called as `( string $directory ): bool` once per distinct directory a declared link would actually be created in; defaults to {@see self::probe_symlink_creation()}, a real create-then-remove attempt against that directory.
	 * @throws InvalidArgumentException If $destination_root is empty or not absolute.
	 * @throws RuntimeException         If the destination cannot be created or its real path cannot be resolved.
	 */
	public function __construct( string $destination_root, bool $allow_unsafe_symlinks = false, ?string $required_prefix = null, ?callable $disk_free_space = null, ?callable $symlink_probe = null ) {
		$this->allow_unsafe_symlinks = $allow_unsafe_symlinks;
		$this->required_prefix       = null === $required_prefix ? null : rtrim( $required_prefix, '/' );
		$this->disk_free_space       = null !== $disk_free_space
			? Closure::fromCallable( $disk_free_space )
			: static function ( string $path ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- disk_free_space can be disabled or restricted by the host (e.g. open_basedir); the guard is best-effort, matching UploadController::refuse_if_no_room(), and its failure must not block a restore that could otherwise succeed.
				return @disk_free_space( $path );
			};
		$this->symlink_probe         = null !== $symlink_probe
			? Closure::fromCallable( $symlink_probe )
			: static function ( string $probe_directory ): bool {
				return self::probe_symlink_creation( $probe_directory );
			};

		if ( '' === $destination_root ) {
			throw new InvalidArgumentException( 'destination_root must be non-empty.' );
		}
		if ( ! self::is_absolute_path( $destination_root ) ) {
			throw new InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $destination_root is reported verbatim for diagnostic context; exception path, not HTML output.
				sprintf( 'destination_root "%s" must be an absolute path.', $destination_root )
			);
		}

		if ( ! is_dir( $destination_root ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir,WordPress.PHP.NoSilencedErrors.Discouraged -- Restore-time filesystem write; WP_Filesystem is unavailable in CLI/non-WP contexts where this code may run.
			if ( ! @mkdir( $destination_root, self::PARENT_DIR_MODE, true ) && ! is_dir( $destination_root ) ) {
				throw new RuntimeException(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $destination_root is reported verbatim for diagnostic context; exception path, not HTML output.
					sprintf( 'Could not create destination_root "%s".', $destination_root )
				);
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_realpath -- Used to normalise paths for the path-traversal check.
		$real = realpath( $destination_root );
		if ( false === $real ) {
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $destination_root is reported verbatim for diagnostic context; exception path, not HTML output.
				sprintf( 'Could not resolve real path of destination_root "%s".', $destination_root )
			);
		}
		$this->destination_root = rtrim( $real, '/\\' );
	}

	/**
	 * Restore one decoded entry to its filesystem location.
	 *
	 * Dispatches on the entry's kind: files, directories, and
	 * symlinks are each handled by a kind-specific helper. The
	 * db_chunk kind is explicitly rejected — those entries go
	 * through DatabaseWriter (a later commit), not FileWriter.
	 *
	 * The entry's path is normalised first, before anything else looks
	 * at it — see {@see self::normalise_entry_path()}. Every guard that
	 * follows (required-prefix, Pontifex-working-path, the traversal
	 * checks inside {@see self::resolve_safe_path()}, the symlinked-ancestor
	 * walk) is a text comparison against that same normalised path, and
	 * that same normalised path is what gets joined onto the destination
	 * root to build the write target — so what the guards evaluate is
	 * guaranteed to be what actually gets written to. (The
	 * Pontifex-working-path guard also depends on one filesystem fact —
	 * whether the destination folds case — which is probed once per writer
	 * and cached; see {@see self::destination_is_case_sensitive()}.)
	 *
	 * @param EntryReadResult $result A decoded entry to restore.
	 * @throws InvalidArgumentException If the entry's kind is db_chunk or the path is unsafe.
	 * @throws RuntimeException         If the filesystem operation fails.
	 */
	public function write_entry( EntryReadResult $result ): void {
		$header = $result->header();

		if ( $header->is_db_chunk() ) {
			throw new InvalidArgumentException( 'db_chunk entries must be written through DatabaseWriter, not FileWriter.' );
		}

		$relative_path = (string) $header->path();
		$relative_path = $this->normalise_entry_path( $relative_path );
		$this->assert_within_required_prefix( $relative_path );
		$this->assert_not_pontifex_working_path( $relative_path );
		$target_path = $this->resolve_safe_path( $relative_path );
		$this->assert_no_symlinked_ancestor( $relative_path );
		$this->ensure_parent_directory( $target_path );

		if ( $header->is_file() ) {
			if ( $result->is_streamed() ) {
				$this->write_file_from_stream( $target_path, $result->payload_stream(), self::clamp_mode( (int) $header->mode() ), (int) $header->mtime() );
			} else {
				$this->write_file( $target_path, $result->payload(), self::clamp_mode( (int) $header->mode() ), (int) $header->mtime() );
			}
			return;
		}
		if ( $header->is_directory() ) {
			$this->write_directory( $target_path, self::clamp_mode( (int) $header->mode() ) );
			return;
		}
		if ( $header->is_symlink() ) {
			$this->write_symlink( $target_path, (string) $header->target() );
			return;
		}

		throw new RuntimeException(
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $header->kind() is a validated KIND_* constant; reported verbatim for diagnostic context; exception path, not HTML output.
			sprintf( 'Unsupported entry kind "%s".', $header->kind() )
		);
	}

	/**
	 * Refuse to begin a restore the destination does not have room for.
	 *
	 * Called once by the caller (RestoreRunner::restore()) before any entry is
	 * written — never from a verify-only walk, which writes nothing and so has
	 * nothing to preflight. FileWriter owns the destination directory, so it
	 * owns the question "will this fit": {@see self::write_file()} always lands
	 * a file in a sibling temp file before renaming it into place, so even
	 * replacing an unchanged file needs its full size free momentarily.
	 *
	 * The amount needed is the LARGER of two figures, both read only from the
	 * manifest's file entries (a directory or symlink entry costs nothing worth
	 * measuring):
	 *
	 *  1. The single largest file entry. Because of the temp-then-rename
	 *     write, the restore cannot proceed unless there is room for its
	 *     biggest file, whatever else is true of the rest of the archive.
	 *  2. The sum, over every file entry, of how much bigger it is than
	 *     whatever already occupies its destination path —
	 *     `max( 0, $entry_size - $existing_size )`, with a missing
	 *     destination file counted as size 0. This is what catches
	 *     restoring a much larger site onto a small disk, while costing
	 *     almost nothing for a same-site rollback, where nearly every file
	 *     already exists at the same size.
	 *
	 * A {@see ManifestEntry::length()} is the entry's STORED size on disk —
	 * the compressed payload plus its own record overhead — not the unpacked
	 * file size. For compressible content that makes this estimate SMALLER
	 * than the file once restored, so the whole method leans low by
	 * construction. That is the correct direction to lean: an under-estimate
	 * merely leaves today's behaviour in place (the write itself remains the
	 * hard backstop against actually running out of room), whereas an
	 * over-estimate would refuse a restore that would in fact have succeeded —
	 * for a backup tool, wrongly locking someone out of their own recovery.
	 *
	 * An entry whose path {@see self::normalise_entry_path()} cannot make
	 * sense of (a hostile or malformed path) is skipped here rather than
	 * refused: this method is a disk-space estimate, not the path-safety
	 * guard, and {@see self::write_entry()} refuses that same entry properly,
	 * with its own message, once the walk actually reaches it.
	 *
	 * A free-space reading that cannot be taken (the injected reader returns
	 * false, e.g. under open_basedir) is treated exactly as
	 * {@see \Pontifex\Rollback\SafetyArchiver::preflight_disk_space()} treats
	 * it: proceed rather than refuse. An unknown must never become a refusal.
	 *
	 * @param array<int, ManifestEntry> $manifest_entries Every entry the restore is about to write.
	 * @return void
	 * @throws HostCannotComply If the destination does not have room for this restore.
	 */
	public function assert_free_space_for( array $manifest_entries ): void {
		$largest_entry_length = 0;
		$total_growth         = 0;

		foreach ( $manifest_entries as $manifest_entry ) {
			if ( ! $manifest_entry->is_file() ) {
				continue;
			}

			$path = $manifest_entry->path();
			if ( null === $path ) {
				continue;
			}

			try {
				$relative_path = $this->normalise_entry_path( $path );
			} catch ( InvalidArgumentException $error ) {
				// A hostile or malformed path: not this method's guard to enforce, and
				// not this entry's problem to contribute to either figure below —
				// write_entry() refuses it properly, with its own message, when the
				// walk actually reaches it. Skipped BEFORE the entry's length is
				// weighed against $largest_entry_length, not just before it is
				// weighed against $total_growth: a hostile entry must not inflate
				// either figure.
				continue;
			}

			$entry_length = $manifest_entry->length();
			if ( $entry_length > $largest_entry_length ) {
				$largest_entry_length = $entry_length;
			}

			$target_path   = $this->destination_root . '/' . $relative_path;
			$existing_size = is_file( $target_path ) ? (int) filesize( $target_path ) : 0;
			$total_growth += max( 0, $entry_length - $existing_size );
		}

		$needed = max( $largest_entry_length, $total_growth );
		if ( 0 === $needed ) {
			return;
		}

		$free = ( $this->disk_free_space )( $this->destination_root );
		if ( false === $free ) {
			return;
		}

		if ( $free < $needed ) {
			throw new HostCannotComply(
				sprintf(
					'The restore was stopped before changing anything, because there is not enough free disk space at "%s". It needs about %d MB free, and only %d MB is available. Free up some space and try again.',
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $this->destination_root is plugin-derived, not web output; reported verbatim for diagnostic context.
					$this->destination_root,
					(int) ceil( $needed / self::BYTES_PER_MEGABYTE ),
					(int) floor( $free / self::BYTES_PER_MEGABYTE )
				)
			);
		}
	}

	/**
	 * Refuse a restore this host cannot finish because it cannot create symlinks where they are needed.
	 *
	 * Called once by the caller (RestoreRunner::restore()), immediately BEFORE
	 * {@see self::assert_symlink_targets_confined()} — never from a verify-only
	 * walk, which writes nothing and so has nothing to preflight. Also never
	 * probes at all when $declared_links is empty: an archive with no symlinks
	 * restores perfectly well on a host that cannot make them, and a false
	 * refusal of an ordinary, link-free restore would be worse than the defect
	 * this preflight closes. The early return states that intent where a reader
	 * will look for it; it is not what enforces it, since an empty set yields no
	 * probe directories and the loop below would not run anyway. Deleting it
	 * changes no behaviour — do not mistake it for the guard.
	 *
	 * The only filesystem change this method can
	 * itself make, on either outcome, is its own probe symlink — created and
	 * removed again before it ever returns or throws; see
	 * {@see self::probe_symlink_creation()}.
	 *
	 * WHY THIS EXISTS, in plain terms. {@see self::write_symlink()} calls PHP's
	 * symlink() and throws when it fails. Without this preflight, that throw is
	 * the FIRST moment a host with "symlink" listed in disable_functions —
	 * common on shared hosting — discovers it cannot finish the job: by then
	 * the walk has already overwritten every file entry ahead of the archive's
	 * first symlink entry, and it stops there, leaving a site that is neither
	 * the old one nor the archive's. Every other refusal restore() can make is
	 * already decided this way, up front, for exactly this reason — a symlink
	 * target escaping the site ({@see self::assert_symlink_targets_confined()})
	 * and the destination lacking room ({@see self::assert_free_space_for()})
	 * are both settled before the walk starts, because the walk itself has no
	 * per-entry recovery: a refusal part-way through is strictly worse than a
	 * refusal up front that changes nothing. This one runs FIRST of the two
	 * symlink checks, because there is no point judging whether a target is
	 * SAFE on a host that could never create the link at all.
	 *
	 * WHERE THIS PROBES, and why it is not simply $this->destination_root. A
	 * content-only restore's destination root is the WHOLE WordPress
	 * installation (ABSPATH) — required_prefix, not destination_root, is what
	 * confines a content-only restore to wp-content — so every entry this
	 * restore actually writes lands under wp-content, several directories
	 * below the root, never in the root itself. A host can perfectly well
	 * refuse a symlink at its installation root while permitting one inside
	 * wp-content/uploads (the standard hardened posture: root read-only,
	 * wp-content writable) — or the reverse, permit one at the root while
	 * uploads itself is the directory that is locked down. Probing the root
	 * answers neither question correctly, in either direction: it can refuse a
	 * restore that would have worked, or pass one that will fail part-way
	 * through the very walk this preflight exists to protect. So this probes
	 * each declared link's OWN eventual parent directory instead — see
	 * {@see self::symlink_creation_probe_directories()} for how that set is
	 * derived, deduplicated, and resolved to directories that already exist
	 * (the walk itself creates the rest as it goes; this preflight never
	 * creates a directory merely to probe it).
	 *
	 * WHY A PROBE, NOT MERELY function_exists(). Checking function_exists('symlink')
	 * alone would miss a filesystem that cannot hold symbolic links at all,
	 * an open_basedir restriction scoped to one particular directory, and an
	 * ordinary permissions failure — all of which fail exactly the way
	 * write_symlink() would fail, mid-restore, if this preflight stopped at
	 * introspection. So {@see self::probe_symlink_creation()}, the default
	 * behind {@see self::$symlink_probe}, attempts the real thing: it creates
	 * a genuine, uniquely-named symlink inside the directory being judged and
	 * removes it again, judging that outcome instead of a guess about it.
	 * Contrast {@see \Pontifex\Cli\DoctorCommand::check_symlink_support()},
	 * which deliberately introspects instead of probing — it has no
	 * destination to write a test link into, being a read-only diagnostic
	 * that can run before any restore is even chosen.
	 *
	 * @param array<array-key, string> $declared_links Every symlink the archive declares, as entry path => raw target — see {@see self::assert_symlink_targets_confined()} for what is done with the targets themselves.
	 * @return void
	 * @throws HostCannotComply If this host cannot create the symbolic links the archive declares.
	 */
	public function assert_symlinks_creatable( array $declared_links ): void {
		if ( array() === $declared_links ) {
			return;
		}

		foreach ( $this->symlink_creation_probe_directories( $declared_links ) as $directory ) {
			if ( ( $this->symlink_probe )( $directory ) ) {
				continue;
			}

			throw new HostCannotComply(
				sprintf(
					'This backup contains %d symbolic link(s), but this host could not create a test link in "%s", so restoring it would overwrite files and then fail partway through, leaving neither the old site nor the archive\'s. Nothing has been changed beyond a test symlink this preflight itself created and removed again in that same directory. This is commonly caused by "symlink" being listed in disable_functions, a filesystem that cannot hold symbolic links, or an open_basedir/permissions restriction on that directory. Restore this archive on a host that can create symbolic links there.',
					count( $declared_links ),
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $directory is plugin-derived (built from the destination root and the archive's own already-normalised declared paths), not web output; reported verbatim for diagnostic context.
					$directory
				)
			);
		}
	}

	/**
	 * The distinct, real directories a declared link would actually be created in.
	 *
	 * Each declared path's PARENT directory is what matters here — a symlink
	 * is created IN a directory, so that is where the capability actually
	 * needs to exist — derived via {@see self::parent_of()} (never PHP's own
	 * dirname(), for the same "link" -> "." reason its docblock explains) on
	 * the same normalised path {@see self::assert_symlink_targets_confined()}
	 * computes, so a malformed or hostile PATH (as opposed to target) is
	 * skipped here exactly as it is skipped there: harmlessly, because
	 * write_entry() refuses that same entry, with its own message, when the
	 * walk actually reaches it, so no symlink is ever created there for this
	 * preflight to have judged.
	 *
	 * The result is deduplicated twice over. First, naturally: every parent
	 * directory is folded into a set (an associative array keyed by the
	 * directory itself), so ten links sharing one directory contribute one
	 * entry, not ten. Second, after resolution: a parent directory that does
	 * not exist yet — the walk creates directories as entries are written,
	 * so at preflight time a link's own directory may not exist on disk at
	 * all — is walked up, via {@see self::deepest_existing_ancestor()}, to
	 * the nearest directory that already does, and that resolved directory is
	 * what actually gets deduplicated on. A real archive's links overwhelmingly
	 * collapse this way to one or two directories (its own wp-content root, or
	 * uploads), which is what keeps this preflight cheap.
	 *
	 * Entries the walk would refuse anyway are skipped, not probed: a path
	 * outside {@see self::$required_prefix}, or one reaching into Pontifex's own
	 * working directory, never reaches {@see self::write_symlink()}, so probing
	 * its directory can only produce a misdiagnosis — telling an operator their
	 * HOST cannot create symbolic links when the real fault is one mis-scoped
	 * entry in the archive.
	 *
	 * An archive whose links are spread across a great many distinct directories
	 * falls back to probing their deepest common ancestor rather than probing
	 * each one, so the work stays bounded without ever refusing. Refusing was
	 * tried first and was wrong twice over. It broke recovery: a pnpm-shaped
	 * tree puts a link in a directory per package, and because directories that
	 * do not exist yet collapse to a single probe, the SAME archive restored
	 * onto a fresh server (nothing exists, one probe) but was refused onto the
	 * site it came from (everything exists, nothing collapses) — the rollback
	 * path, and the one moment a user cannot be told no. And it bought nothing:
	 * the count can only be known after every path has been resolved and walked,
	 * so the filesystem work a ceiling claims to prevent has already happened by
	 * the time it could refuse, while 1,000 real probes cost about a tenth of a
	 * second.
	 *
	 * @param array<array-key, string> $declared_links Every symlink the archive declares, as entry path => raw target.
	 * @return array<int, string> The distinct absolute directories to probe.
	 */
	private function symlink_creation_probe_directories( array $declared_links ): array {
		$directories = array();

		foreach ( array_keys( $declared_links ) as $raw_path ) {
			try {
				$relative_path = $this->normalise_entry_path( (string) $raw_path );
				$this->assert_within_required_prefix( $relative_path );
				$this->assert_not_pontifex_working_path( $relative_path );
			} catch ( InvalidArgumentException | RuntimeException $entry_the_walk_would_refuse ) {
				unset( $entry_the_walk_would_refuse );
				continue;
			}

			$relative_parent = self::parent_of( $relative_path );
			$absolute_parent = '' === $relative_parent
				? $this->destination_root
				: $this->destination_root . '/' . $relative_parent;

			$directories[ $this->deepest_existing_ancestor( $absolute_parent ) ] = true;
		}

		if ( count( $directories ) > self::MAX_SYMLINK_PROBE_DIRECTORIES ) {
			return array( self::deepest_common_ancestor( array_keys( $directories ) ) );
		}

		return array_keys( $directories );
	}

	/**
	 * The deepest directory every one of $directories lies within.
	 *
	 * Used only as the bounded fallback above
	 * {@see self::MAX_SYMLINK_PROBE_DIRECTORIES}: probing one shared ancestor
	 * answers the same question conservatively — if links cannot be created
	 * there, they almost certainly cannot be created in the tree beneath it —
	 * without the work growing with the archive. It is a weaker answer than
	 * probing each directory, which is precisely why it is the fallback and not
	 * the rule.
	 *
	 * Every input is already an existing absolute directory at or beneath
	 * $this->destination_root (each came from
	 * {@see self::deepest_existing_ancestor()}), so the common prefix can never
	 * climb above the destination root.
	 *
	 * @param array<int, string> $directories Absolute directories, each at or beneath the destination root.
	 * @return string The deepest shared absolute directory.
	 */
	private static function deepest_common_ancestor( array $directories ): string {
		$shared = explode( '/', (string) array_shift( $directories ) );

		foreach ( $directories as $directory ) {
			$segments = explode( '/', $directory );
			$common   = array();

			foreach ( $shared as $index => $segment ) {
				if ( ! isset( $segments[ $index ] ) || $segments[ $index ] !== $segment ) {
					break;
				}

				$common[] = $segment;
			}

			$shared = $common;
		}

		return implode( '/', $shared );
	}

	/**
	 * Walk up from $absolute_directory to the nearest ancestor (itself included) that already exists.
	 *
	 * The restore walk creates directories on demand as entries are written
	 * ({@see self::ensure_parent_directory()}), so a declared link's own
	 * parent directory can easily not exist yet at preflight time — only
	 * $this->destination_root, created by the constructor, is guaranteed to
	 * be there. Probing a directory that does not exist would have to create
	 * it first, which would test a question the real restore never asks (can
	 * THIS PREFLIGHT create a directory) instead of the one that matters (can
	 * this HOST create a symlink where the walk will actually put one), and
	 * would leave a stray empty directory behind on refusal. So this method
	 * only ever climbs; it never creates anything.
	 *
	 * The climb is guaranteed to terminate at or before
	 * $this->destination_root: every path handed in is built from a relative
	 * path that has already passed {@see self::normalise_entry_path()}, which
	 * refuses ".." segments outright, so it can only ever descend from the
	 * root, never climb above it — and the constructor already guarantees the
	 * root itself exists.
	 *
	 * @param string $absolute_directory An absolute directory at or under $this->destination_root that may not exist yet.
	 * @return string The nearest existing ancestor, at or below $absolute_directory.
	 */
	private function deepest_existing_ancestor( string $absolute_directory ): string {
		$candidate = rtrim( $absolute_directory, '/' );

		while ( $candidate !== $this->destination_root && ! is_dir( $candidate ) ) {
			$parent = dirname( $candidate );

			// dirname() is its own fixed point at the filesystem root, and
			// rtrim( '/', '/' ) is the empty string, whose dirname is also
			// itself — so a candidate that ever reached either would spin here
			// forever rather than fail. No caller can produce one today (every
			// path is built from the destination root and normalise_entry_path()
			// bars ".."), which is exactly why this belongs here: the climb's
			// termination should be guaranteed by the loop itself, not by an
			// invariant maintained in another method. Under WP-CLI, which has no
			// execution time limit, the alternative failure is a silent hang.
			if ( '' === $candidate || $parent === $candidate ) {
				return $this->destination_root;
			}

			$candidate = $parent;
		}

		return $candidate;
	}

	/**
	 * The real symlink-capability probe: create a uniquely-named symlink in $directory, then remove it.
	 *
	 * The default behind {@see self::$symlink_probe}; injectable only for
	 * tests, which cannot otherwise make a real host reliably refuse to create
	 * a symlink. Called once per distinct directory
	 * {@see self::symlink_creation_probe_directories()} resolves the archive's
	 * declared links down to, which need not be $this->destination_root — see
	 * that method's docblock for why.
	 *
	 * function_exists('symlink') is checked FIRST, and the real attempt is
	 * made only when it passes. Calling a function removed by disable_functions
	 * does not merely warn — PHP raises an Error for a call to an undefined
	 * function, and the `@` operator does not suppress an Error — so skipping
	 * straight to the real attempt would crash the very restore this preflight
	 * exists to protect. The real attempt still runs alongside that check, not
	 * instead of it, because function_exists() alone cannot see the failure
	 * modes that matter just as much: a filesystem that cannot hold symbolic
	 * links at all, an open_basedir restriction scoped to this directory
	 * specifically, or an ordinary permissions failure.
	 *
	 * Cleanup runs in a finally block so the probe artefact never survives
	 * either outcome — including when creation itself failed and there is
	 * nothing to remove — because orphaned temp artefacts are already a known,
	 * open complaint on this project, and this probe must not add to it.
	 * Unlink is tried first, then rmdir as a defence-in-depth fallback: the
	 * probe's target is a sibling name chosen to never exist (see below),
	 * which on Windows is unambiguously the FILE-symlink case, so unlink()
	 * ought always to be the right call — but if that assumption is ever
	 * wrong on some platform, the fallback still leaves nothing behind rather
	 * than an orphaned artefact this project already has a standing complaint
	 * about.
	 *
	 * The link's target names a sibling file that is never created — never
	 * ".", the probe's own parent directory, as an earlier version of this
	 * probe used. That is deliberate, not incidental, for two reasons: this
	 * probe asks only whether symlink() itself succeeds, never whether the
	 * target exists, so a target that resolves to nothing is exactly as valid
	 * a thing to test as one that resolves to something; and on Windows,
	 * where symlink() must be told at creation time whether it is making a
	 * FILE or DIRECTORY link and decides by checking whether the target
	 * currently resolves to a directory, a target guaranteed not to exist is
	 * unambiguously the file case — so the artefact this probe leaves behind,
	 * however briefly, is always removable with unlink(), never requiring
	 * rmdir() instead.
	 *
	 * @param string $directory Absolute path of the directory to probe.
	 * @return bool True if a symlink could be created (and was then removed) in $directory.
	 */
	private static function probe_symlink_creation( string $directory ): bool {
		if ( ! function_exists( 'symlink' ) ) {
			return false;
		}

		// Built through TempArtefact::suffix() — the same helper
		// self::temp_sibling_path() uses — rather than formatting its own
		// uniqid() call, so this probe's shape and a real write's temp shape can
		// never drift apart; see that class's docblock. The finally below
		// removes it on every normal outcome, but a SIGKILL between the
		// symlink() and the finally cannot be caught by anything — and an
		// orphan left this way is exactly what self::sweep_orphaned_temp_files()
		// recognises and removes at the start of the next restore.
		$probe_name   = '.symlink-probe' . TempArtefact::suffix();
		$probe_path   = $directory . '/' . $probe_name;
		$probe_target = $probe_name . '.target';

		try {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- One-off capability probe run before a restore; a filesystem/open_basedir/permissions failure is exactly what this probe exists to detect, not to surface as a PHP warning.
			return @symlink( $probe_target, $probe_path );
		} finally {
			if ( is_link( $probe_path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort removal of the probe artefact; its own failure must not mask the probe result already computed.
				if ( ! @unlink( $probe_path ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.PHP.NoSilencedErrors.Discouraged -- Defence in depth: Windows PHP requires rmdir(), not unlink(), to remove a DIRECTORY symlink; the probe's target is chosen to never resolve as one, so this should be unreachable, but it keeps the probe artefact from surviving even if that assumption is ever wrong.
					@rmdir( $probe_path );
				}
			}
		}
	}

	/**
	 * Refuse, before a single byte is written, any declared symlink the kernel would follow out of the site.
	 *
	 * Called once by the caller (RestoreRunner::restore()) with EVERY symlink
	 * the archive declares — never from a verify-only walk, which writes nothing
	 * and so has nothing to preflight.
	 *
	 * WHY THIS EXISTS, in plain terms. A symlink is a file whose contents are
	 * the name of another path. When something later opens the link, the kernel
	 * silently follows it. So a hostile archive can hand a site a link that
	 * looks harmless and, once written, hands out the site's database password.
	 * The check that shipped before this method compared the target as TEXT: it
	 * collapsed the "." and ".." pieces of the target string and asked whether
	 * the result was still inside the site. That was defeated by execution, with
	 * two entries:
	 *
	 *     wp-content/uploads/hop      -> ".."
	 *     wp-content/uploads/leak.txt -> "hop/../wp-config.php"
	 *
	 * Read as text, the second target's "hop/.." cancels out and the whole thing
	 * looks like "wp-content/uploads/wp-config.php" — inside the site, so it was
	 * permitted. The kernel does not cancel text. It follows "hop" FIRST, which
	 * lands on wp-content; the ".." after it then climbs to the site root; and
	 * "wp-config.php" resolves to the real one. Because the link sits under
	 * uploads, which webservers hand out as ordinary static files, and because
	 * its name ends ".txt" rather than ".php", anyone on the internet could then
	 * read the database credentials and the authentication salts.
	 *
	 * WHAT THIS DOES INSTEAD. It resolves the target the way the kernel does —
	 * one path component at a time, substituting a component's own target the
	 * moment that component turns out to be a symlink — and judges the place the
	 * target ACTUALLY lands.
	 *
	 * Three properties of that walk are load-bearing, and each closes a hole
	 * that adversarial testing found in an earlier draft of this guard:
	 *
	 *  1. It resolves against the tree the archive DECLARES, not the tree as it
	 *     happens to be at that instant, falling back to the live filesystem
	 *     (is_link()/readlink()) only for components the archive says nothing
	 *     about. Checking each link as it is written instead is defeated by
	 *     simply swapping the two entries above: written in the other order, the
	 *     consumer is judged while "hop" does not exist yet, so it looks
	 *     harmless, and the kernel joins them up afterwards regardless.
	 *  2. The declared-link lookup is tried byte-exactly first and then with the
	 *     case folded away, because macOS and Windows treat "HOP" and "hop" as
	 *     one file. Spelling the intermediate link "HOP" defeats a byte-exact
	 *     lookup — and nothing is on disk yet, so the is_link() fallback misses
	 *     it too — while the kernel still joins the two links at write time.
	 *     This is exactly the bug node-tar took three CVEs to close
	 *     (CVE-2021-37701), and the fold is the same fix its maintainers landed.
	 *  3. Every refusal happens here, before the walk starts, because the restore
	 *     loop has no per-entry recovery: a refusal on entry 12,000 of 47,000
	 *     would leave a site that is neither the old one nor the archive's.
	 *
	 * FALSE REFUSALS MATTER AS MUCH AS THE ATTACK. A backup tool that cannot
	 * restore its own output is not a backup tool, and an earlier attempt at
	 * this guard was reverted for exactly that. The boundary is therefore the
	 * SITE root, not wp-content, so a Composer-managed WordPress — which keeps
	 * its dependencies beside wp-content and reaches them by link, e.g.
	 * "wp-content/mu-plugins/autoload.php -> ../../vendor/acme/lib/autoload.php"
	 * and "wp-content/languages -> ../languages" — restores untouched, on its own
	 * server and on a new one. A target that does not exist yet is permitted too:
	 * the question asked is WHERE the target resolves, never whether it is there,
	 * so a link satisfied later by a `composer install` (or by a later entry, or
	 * never) is fine.
	 *
	 * @param array<array-key, string> $declared_links Every symlink the archive declares, as entry path => raw target.
	 * @return void
	 * @throws ArchiveNotTrustworthy If any declared link's target resolves somewhere this restore will not allow.
	 */
	public function assert_symlink_targets_confined( array $declared_links ): void {
		if ( $this->allow_unsafe_symlinks ) {
			return;
		}

		// is_link() and lstat() results are cached by PHP for the rest of the
		// request. This guard reads the live filesystem for every component the
		// archive does not declare, and a stale "not a link" reading for a path
		// something has since replaced would put it straight back to trusting
		// state it cannot see — the shape of every bug in this family. Clearing
		// is cheap; the cost of forgetting it is a bypass.
		clearstatcache( true );

		// Two views of the same set. $exact answers "did the archive declare a
		// link at precisely this path"; $folded answers the same question for a
		// destination filesystem that does not distinguish "HOP" from "hop". A
		// folded key whose spellings disagree about the target is recorded as
		// null — genuinely ambiguous — and refuses if resolution ever reaches it,
		// rather than picking one and hoping.
		$exact  = array();
		$folded = array();
		foreach ( $declared_links as $raw_path => $raw_target ) {
			try {
				$link_path = $this->normalise_entry_path( (string) $raw_path );
			} catch ( InvalidArgumentException $unusable_path ) {
				// A hostile or malformed link PATH: not this guard's business, and
				// harmless to omit from the index, because write_entry() refuses that
				// same entry with its own message when the walk reaches it — so the
				// link is never created and can never redirect anything.
				unset( $unusable_path );
				continue;
			}

			$exact[ $link_path ] = $raw_target;

			$folded_key = strtolower( $link_path );
			if ( array_key_exists( $folded_key, $folded ) && $folded[ $folded_key ] !== $raw_target ) {
				$folded[ $folded_key ] = null;
				continue;
			}
			$folded[ $folded_key ] = $raw_target;
		}

		foreach ( $exact as $link_path => $raw_target ) {
			$this->assert_symlink_target_confined( (string) $link_path, $raw_target, $exact, $folded );
		}
	}

	/**
	 * Resolve one declared link's target the way the kernel would, and judge where it lands.
	 *
	 * The walk keeps two lists: $resolved, the components decided so far
	 * (starting at the link's OWN directory, since that is what a relative
	 * target is measured from), and $remaining, the components still to be
	 * processed. Taking one component at a time:
	 *
	 *  - ".." drops the last decided component. Popping an already-empty list is
	 *    a deliberate no-op: "/.." is "/" on a real filesystem too. Escapes are
	 *    detected by the final comparison, never by clamping here — clamping
	 *    would quietly turn "../../../../etc/passwd" into something that looks
	 *    in-bounds.
	 *  - Any other component is tested for being a symlink. If it is, its own
	 *    target is pushed onto the FRONT of $remaining and the component itself
	 *    is discarded — that substitution is the whole of what the kernel does,
	 *    and it is what a textual collapse cannot model. If it is not, it is
	 *    simply appended.
	 *
	 * Components are held as absolute lists — the site root's own components
	 * followed by everything below — so that a target which climbs ABOVE the
	 * root is still describable, and so that the on-disk is_link() fallback can
	 * be asked about a path outside the site. POSIX path spelling is assumed
	 * throughout, as it is everywhere else in this class.
	 *
	 * @param string                        $link_path   The link's own path, relative to the destination root, already normalised.
	 * @param string                        $raw_target  The target string recorded in the archive, verbatim.
	 * @param array<array-key, string>      $exact       Every declared link, keyed by its exact normalised path.
	 * @param array<array-key, string|null> $folded      The same, keyed by lower-cased path; null marks a key whose spellings disagree.
	 * @return void
	 * @throws ArchiveNotTrustworthy If the target is absolute, loops, cannot be resolved, or lands somewhere refused.
	 */
	private function assert_symlink_target_confined( string $link_path, string $raw_target, array $exact, array $folded ): void {
		if ( self::is_absolute_path( $raw_target ) ) {
			$message = sprintf(
				'Refusing the symbolic link "%s": its target "%s" is an absolute path, so it is not confined to this site at all. Re-run with --allow-unsafe-symlinks only if you trust this archive.',
				$link_path,
				$raw_target
			);
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $message quotes the archive's own link path and target for diagnostic context; exception path, not HTML output.
			throw new ArchiveNotTrustworthy( $message );
		}

		$root_components = self::path_components( $this->destination_root );
		$resolved        = array_merge( $root_components, self::path_components( self::parent_of( $link_path ) ) );
		$remaining       = explode( '/', str_replace( '\\', '/', $raw_target ) );
		$hops            = 0;

		while ( array() !== $remaining ) {
			$component = (string) array_shift( $remaining );

			if ( '' === $component || '.' === $component ) {
				continue;
			}
			if ( '..' === $component ) {
				array_pop( $resolved );
				continue;
			}

			$candidate   = $resolved;
			$candidate[] = $component;

			$hop_target = $this->declared_or_on_disk_target( $candidate, $root_components, $exact, $folded, $link_path, $raw_target );
			if ( null === $hop_target ) {
				$resolved = $candidate;
				continue;
			}

			++$hops;
			if ( $hops > self::MAX_SYMLINK_HOPS ) {
				$message = sprintf(
					'Refusing the symbolic link "%s": resolving its target "%s" passed through more than %d links, so this backup contains a loop or a chain no filesystem would follow.',
					$link_path,
					$raw_target,
					self::MAX_SYMLINK_HOPS
				);
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $message quotes the archive's own link path and target for diagnostic context; exception path, not HTML output.
				throw new ArchiveNotTrustworthy( $message );
			}

			// An absolute target restarts resolution at the filesystem root, which
			// is what the kernel does; the leading empty component the split leaves
			// behind is skipped on the next pass like any other empty one.
			if ( self::is_absolute_path( $hop_target ) ) {
				$resolved = array();
			}
			$remaining = array_merge( explode( '/', str_replace( '\\', '/', $hop_target ) ), $remaining );
		}

		$this->assert_resolved_target_confined( $link_path, $raw_target, $resolved, $root_components );
	}

	/**
	 * The target of the symlink at $candidate, or null when $candidate is not a symlink.
	 *
	 * The archive's own declarations are consulted FIRST, and the live
	 * filesystem only for what the archive does not mention. That order is what
	 * makes the whole preflight independent of the order entries are written in:
	 * the archive's links do not exist on disk yet, so a disk-first check would
	 * see nothing and wave the target through, and the kernel would join the
	 * links up afterwards anyway.
	 *
	 * The exact spelling is tried first, then the lower-cased one. Folding is
	 * applied on every host rather than only where the destination folds case,
	 * deliberately: it keeps this guard's verdict identical everywhere, so a
	 * green run on a case-sensitive CI machine is evidence about the case-folding
	 * laptop and shared host too. (The cost is the one node-tar's maintainers
	 * accepted for the same fix — an occasional needless match on a
	 * case-sensitive filesystem. The shape that could produce one is an archive
	 * carrying two links whose paths differ only in case, which cannot exist on
	 * a case-folding destination at all; when their targets disagree the
	 * ambiguity is refused outright rather than guessed at.)
	 *
	 * @param array<int, string>            $candidate       The absolute path being tested, as components.
	 * @param array<int, string>            $root_components The destination root, as components.
	 * @param array<array-key, string>      $exact           Every declared link, keyed by its exact normalised path.
	 * @param array<array-key, string|null> $folded          The same, keyed by lower-cased path; null marks a key whose spellings disagree.
	 * @param string                        $link_path       The link being judged, for the diagnostic message only.
	 * @param string                        $raw_target      Its raw target, for the diagnostic message only.
	 * @return string|null The symlink's target, or null if $candidate is not a symlink.
	 * @throws ArchiveNotTrustworthy If two declared spellings of one path disagree, or an on-disk link cannot be read.
	 */
	private function declared_or_on_disk_target( array $candidate, array $root_components, array $exact, array $folded, string $link_path, string $raw_target ): ?string {
		$root_depth = count( $root_components );

		if ( count( $candidate ) > $root_depth && array_slice( $candidate, 0, $root_depth ) === $root_components ) {
			$relative = implode( '/', array_slice( $candidate, $root_depth ) );

			if ( array_key_exists( $relative, $exact ) ) {
				return $exact[ $relative ];
			}

			$folded_key = strtolower( $relative );
			if ( array_key_exists( $folded_key, $folded ) ) {
				if ( null === $folded[ $folded_key ] ) {
					$message = sprintf(
						'Refusing the symbolic link "%s": resolving its target "%s" reaches "%s", which this backup declares more than once with different targets, differing only in letter case. Which one a filesystem would keep cannot be decided, so the backup is refused.',
						$link_path,
						$raw_target,
						$relative
					);
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $message quotes the archive's own link paths and targets for diagnostic context; exception path, not HTML output.
					throw new ArchiveNotTrustworthy( $message );
				}
				return $folded[ $folded_key ];
			}
		}

		$absolute = self::absolute_from_components( $candidate );
		if ( ! is_link( $absolute ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Reading an existing link's target during the restore preflight; WP_Filesystem has no symlink primitive, and a failure is turned into a refusal immediately below rather than silenced.
		$on_disk_target = @readlink( $absolute );
		if ( false === $on_disk_target ) {
			// Fail closed. A component that is a symlink whose target cannot be read
			// is a component whose destination is unknown, and an unknown here is
			// indistinguishable from an escape.
			$message = sprintf(
				'Refusing the symbolic link "%s": resolving its target "%s" reaches the existing link "%s", whose own target could not be read, so where this link would point cannot be established.',
				$link_path,
				$raw_target,
				$absolute
			);
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $message quotes the archive's own link path and target plus a plugin-derived path, for diagnostic context; exception path, not HTML output.
			throw new ArchiveNotTrustworthy( $message );
		}

		return $on_disk_target;
	}

	/**
	 * Judge the place a target finally resolved to, and refuse the four shapes a restore must not create.
	 *
	 * The containment rule is STRICT descent: a target equal to the site root is
	 * refused alongside anything above it. A link that IS the root would redirect
	 * everything beneath it — Python's extraction filter refuses the same shape
	 * for the same stated reason — and anything above it is the whole
	 * /etc/passwd, sibling-site, home-directory class.
	 *
	 * Two named locations inside that boundary are refused as well. They are a
	 * deny-list, which this project otherwise avoids, and three things make this
	 * one defensible: it is applied to the FULLY RESOLVED path, after every
	 * substitution, so there is no attacker-supplied spelling left to normalise
	 * and outwit; it names paths the plugin derives itself rather than any string
	 * from the archive; and it is a backstop behind the containment rule, closing
	 * the two places inside the site whose exposure is a total compromise:
	 *
	 *  - wp-config.php, holding the database credentials and the authentication
	 *    salts, located the same two-step way WordPress core locates it in
	 *    wp-load.php, so the rule tracks core's own definition rather than a
	 *    guess. (The second of core's two locations sits above the site root and
	 *    is therefore already refused by the containment rule; it is still
	 *    computed, because the containment rule and this one are meant to be
	 *    independently correct, and a future change to either must not silently
	 *    open a hole in the other.)
	 *  - wp-content/pontifex, which holds this site's stored backups and safety
	 *    archives — each one a copy of the WHOLE database. A link from uploads
	 *    into it would publish every backup the site has. It carries no
	 *    false-refusal risk, because FileScanner excludes that directory from
	 *    every archive Pontifex writes, so no legitimate archive links there.
	 *
	 * @param string             $link_path       The link's own path, relative to the destination root.
	 * @param string             $raw_target      The target string recorded in the archive, verbatim.
	 * @param array<int, string> $resolved        Where the target finally landed, as absolute components.
	 * @param array<int, string> $root_components The destination root, as components.
	 * @return void
	 * @throws ArchiveNotTrustworthy If the resolved target is not a strict descendant of the root, or is one of the named locations.
	 */
	private function assert_resolved_target_confined( string $link_path, string $raw_target, array $resolved, array $root_components ): void {
		$root_depth = count( $root_components );
		$absolute   = self::absolute_from_components( $resolved );

		if ( count( $resolved ) <= $root_depth || array_slice( $resolved, 0, $root_depth ) !== $root_components ) {
			$message = sprintf(
				'Refusing the symbolic link "%s": its target "%s" resolves to "%s", which is not inside the site at "%s". Re-run with --allow-unsafe-symlinks only if you trust this archive.',
				$link_path,
				$raw_target,
				$absolute,
				$this->destination_root
			);
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $message quotes the archive's own link path and target plus plugin-derived paths, for diagnostic context; exception path, not HTML output.
			throw new ArchiveNotTrustworthy( $message );
		}

		foreach ( $this->wp_config_paths() as $wp_config_path ) {
			if ( $absolute !== $wp_config_path ) {
				continue;
			}
			$message = sprintf(
				'Refusing the symbolic link "%s": its target "%s" resolves to "%s", this site\'s own wp-config.php, which holds the database password and the authentication salts. Re-run with --allow-unsafe-symlinks only if you trust this archive.',
				$link_path,
				$raw_target,
				$absolute
			);
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $message quotes the archive's own link path and target plus a plugin-derived path, for diagnostic context; exception path, not HTML output.
			throw new ArchiveNotTrustworthy( $message );
		}

		$relative = implode( '/', array_slice( $resolved, $root_depth ) );
		if ( self::is_pontifex_working_path( $relative, $this->destination_is_case_sensitive() ) ) {
			$message = sprintf(
				'Refusing the symbolic link "%s": its target "%s" resolves to "%s", inside Pontifex\'s own working directory, where this site\'s stored backups live. Re-run with --allow-unsafe-symlinks only if you trust this archive.',
				$link_path,
				$raw_target,
				$absolute
			);
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $message quotes the archive's own link path and target plus a plugin-derived path, for diagnostic context; exception path, not HTML output.
			throw new ArchiveNotTrustworthy( $message );
		}
	}

	/**
	 * The absolute path(s) at which this site's own wp-config.php would be loaded.
	 *
	 * Mirrors the two-step search WordPress core performs in wp-load.php: the
	 * file directly inside the site root, or — only when that one is absent —
	 * the one a level above, and then only if wp-settings.php is NOT beside it
	 * (that second condition is how core tells "my config was moved up one" from
	 * "there is a different WordPress up there").
	 *
	 * The first location is listed unconditionally, without asking whether it
	 * exists. Core has to ask, because it is deciding what to load; this guard is
	 * deciding what to refuse, and a link pointing at wp-config.php is never
	 * legitimate whether or not the file happens to be there right now — a
	 * migration onto a destination whose config has not been written yet must
	 * still refuse it.
	 *
	 * @return array<int, string> One or two absolute paths.
	 */
	private function wp_config_paths(): array {
		$paths  = array( $this->destination_root . '/wp-config.php' );
		$parent = dirname( $this->destination_root );

		if ( $parent !== $this->destination_root
			&& ! file_exists( $paths[0] )
			&& file_exists( $parent . '/wp-config.php' )
			&& ! file_exists( $parent . '/wp-settings.php' ) ) {
			$paths[] = $parent . '/wp-config.php';
		}

		return $paths;
	}

	/**
	 * Split a path into its non-empty components, ignoring leading and repeated separators.
	 *
	 * Backslashes are folded to forward slashes first, the same way
	 * {@see self::normalise_path()} does, so a Windows-shaped string cannot
	 * smuggle a separator past the split.
	 *
	 * @param string $path The path to split.
	 * @return array<int, string> Its components, in order.
	 */
	private static function path_components( string $path ): array {
		$components = array();
		foreach ( explode( '/', str_replace( '\\', '/', $path ) ) as $component ) {
			if ( '' === $component ) {
				continue;
			}
			$components[] = $component;
		}
		return $components;
	}

	/**
	 * Rebuild an absolute POSIX path from a component list.
	 *
	 * The inverse of {@see self::path_components()} for an absolute path: an
	 * empty list is the filesystem root itself.
	 *
	 * @param array<int, string> $components The components, in order.
	 * @return string The absolute path.
	 */
	private static function absolute_from_components( array $components ): string {
		if ( array() === $components ) {
			return '/';
		}
		return '/' . implode( '/', $components );
	}

	/**
	 * The directory part of a relative path, or the empty string when it has none.
	 *
	 * PHP's dirname() is deliberately not used: given "link" it answers ".", a
	 * value that would then have to be special-cased by every caller. The empty
	 * string says "the root of the tree" directly, and
	 * {@see self::path_components()} turns it into no components at all.
	 *
	 * @param string $relative_path A normalised path relative to the destination root.
	 * @return string Its directory part, relative to the destination root.
	 */
	private static function parent_of( string $relative_path ): string {
		$components = self::path_components( $relative_path );
		array_pop( $components );
		return implode( '/', $components );
	}

	/**
	 * Normalise a raw entry path before any guard compares it as text.
	 *
	 * Every guard downstream of this method — assert_within_required_prefix(),
	 * assert_not_pontifex_working_path(), and the checks inside
	 * resolve_safe_path() — decides by literal string comparison. But a raw
	 * archive path can carry filesystem-equivalent noise those comparisons
	 * don't see through: a "." segment, or a doubled "/", both resolve to
	 * exactly the same place on disk yet neither matches "wp-content/pontifex"
	 * as text. Left unnormalised, an entry path such as
	 * "wp-content/./pontifex/.htaccess" sails straight past a literal
	 * strncmp() against "wp-content/pontifex/" — while still landing inside
	 * that very directory once the filesystem joins and opens the path,
	 * silently defeating the Pontifex-working-path guard (and the
	 * required-prefix guard alongside it). Normalising once, here, before
	 * any comparison runs, and feeding every later step the same cleaned
	 * value that is then joined onto destination_root to build the write
	 * target, closes that gap for good rather than patching one guard at a
	 * time.
	 *
	 * The steps run in this order, and the order is load-bearing:
	 *
	 *  1. The unsafe shapes are refused outright — empty, containing a null
	 *     byte, or absolute — the same checks resolve_safe_path() makes,
	 *     run early so nothing downstream ever has to see them.
	 *  2. A ".." segment is refused BEFORE anything is collapsed. Collapsing
	 *     "." is always safe: it never changes which directory a path
	 *     refers to. Collapsing ".." is not — doing so could turn a genuine
	 *     escape attempt into something that looks in-bounds afterwards.
	 *     "wp-content/uploads/../../../etc/passwd" must stay refused
	 *     exactly as it is today, never be silently resolved down to
	 *     something that looks safe.
	 *  3. Only once no ".." segment is present are runs of "/" collapsed to
	 *     one and "." segments dropped (which, applied to a segment split,
	 *     also removes a trailing slash). Backslashes are deliberately left
	 *     untouched here — resolve_safe_path() already normalises them for
	 *     its own ".." check and is kept in place as a second, redundant
	 *     guard, so a backslash-disguised traversal attempt is still caught
	 *     exactly as before.
	 *  4. The COLLAPSED result is checked for emptiness too — a separate
	 *     check from step 1's, which only looked at the raw INPUT. A raw
	 *     path of "." or "./" is non-empty going in, so step 1 lets it
	 *     through, but it collapses to "" once its "." segments are dropped
	 *     in step 3. An empty relative path, joined onto destination_root,
	 *     resolves to destination_root ITSELF — so a directory entry at
	 *     that path would apply the archive's mode to the WordPress root
	 *     (0000 has been demonstrated). Refusing it here, on the collapsed
	 *     value, closes that gap. resolve_safe_path() makes the same
	 *     "empty" check independently, on whatever value IT is given; see
	 *     its docblock for why that is a deliberate second guard, not a
	 *     duplicate of this one.
	 *
	 * @param string $relative_path The raw path field from the entry header.
	 * @return string The normalised path; every later guard and the eventual write target are built from this value.
	 * @throws InvalidArgumentException If the path is empty, contains a null byte, is absolute, contains a parent-directory segment, or normalises to an empty path.
	 */
	private function normalise_entry_path( string $relative_path ): string {
		if ( '' === $relative_path ) {
			throw new InvalidArgumentException( 'Entry path must be non-empty.' );
		}
		if ( false !== strpos( $relative_path, "\0" ) ) {
			throw new InvalidArgumentException( 'Entry path contains a null byte.' );
		}
		if ( self::is_absolute_path( $relative_path ) ) {
			throw new InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $relative_path is reported verbatim for diagnostic context; exception path, not HTML output.
				sprintf( 'Entry path "%s" must be relative, not absolute.', $relative_path )
			);
		}

		$segments = explode( '/', $relative_path );
		foreach ( $segments as $segment ) {
			if ( '..' === $segment ) {
				throw new InvalidArgumentException(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $relative_path is reported verbatim for diagnostic context; exception path, not HTML output.
					sprintf( 'Refusing the entry path "%s": it contains a parent-directory segment.', $relative_path )
				);
			}
		}

		$cleaned_segments = array();
		foreach ( $segments as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			$cleaned_segments[] = $segment;
		}

		$normalised = implode( '/', $cleaned_segments );
		if ( '' === $normalised ) {
			throw new InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $relative_path is reported verbatim for diagnostic context; exception path, not HTML output.
				sprintf( 'Entry path "%s" normalises to an empty path (e.g. ".", "./", or a run of such segments) and is refused.', $relative_path )
			);
		}

		return $normalised;
	}

	/**
	 * Refuse an entry whose path sits outside the required prefix, on a restricted restore.
	 *
	 * A no-op when no prefix is required (a whole-site restore). On a content-only
	 * restore (prefix "wp-content") the entry path must be the prefix itself or sit
	 * beneath it; anything else — a WordPress core file, wp-config.php, a root file —
	 * is refused. This is the write-boundary backstop behind the import command's
	 * up-front scope preflight: the preflight rejects a whole-site or legacy archive
	 * before any write, and this guard ensures even a mislabelled content-only
	 * archive cannot slip a core path through.
	 *
	 * @param string $relative_path The entry path, relative to the restore root.
	 * @throws InvalidArgumentException If the path is outside the required prefix.
	 */
	private function assert_within_required_prefix( string $relative_path ): void {
		if ( null === $this->required_prefix ) {
			return;
		}
		if ( $relative_path === $this->required_prefix ) {
			return;
		}
		$prefix = $this->required_prefix . '/';
		if ( 0 === strncmp( $relative_path, $prefix, strlen( $prefix ) ) ) {
			return;
		}
		throw new InvalidArgumentException(
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $relative_path and the prefix are reported verbatim for diagnostic context; exception path, not HTML output.
			sprintf( 'Entry path "%s" is outside the permitted "%s" tree and is refused by this content-only restore.', $relative_path, $this->required_prefix )
		);
	}

	/**
	 * Refuse an entry whose own path targets Pontifex's own working directory.
	 *
	 * Applies in every restore mode, including a whole-site restore with no
	 * required prefix — this is a structural guard, not a scope guard, so it
	 * runs regardless of what {@see self::assert_within_required_prefix()}
	 * decided. See {@see self::is_pontifex_working_path()} for why the only
	 * way an entry can ever land here is a forged archive, and what
	 * restoring it would let an attacker overwrite.
	 *
	 * The check looks only at where the entry itself is written TO. It says
	 * nothing about a symlink's TARGET: a symlink written elsewhere in the
	 * tree that merely points into wp-content/pontifex/ (for example
	 * wp-content/uploads/leak.wpmig -> ../pontifex/rollback/safety.wpmig) is
	 * a different class of problem that this guard does not, and is not
	 * meant to, address.
	 *
	 * Nor does it cover every Windows-specific path spelling, or a site
	 * whose WP_CONTENT_DIR is not the literal "wp-content" — see
	 * {@see self::is_pontifex_working_path()}'s "Known limits" for the
	 * current, honest list of what this guard does not catch.
	 *
	 * @param string $relative_path The entry path, relative to the restore root.
	 * @throws InvalidArgumentException If the path is wp-content/pontifex itself or beneath it.
	 */
	private function assert_not_pontifex_working_path( string $relative_path ): void {
		if ( ! self::is_pontifex_working_path( $relative_path, $this->destination_is_case_sensitive() ) ) {
			return;
		}
		throw new InvalidArgumentException(
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $relative_path is reported verbatim for diagnostic context; exception path, not HTML output.
			sprintf( 'Entry path "%s" targets Pontifex\'s own working directory and is refused; no legitimate archive ever contains one.', $relative_path )
		);
	}

	/**
	 * Whether the given relative path is inside Pontifex's own working directory.
	 *
	 * A relative of {@see \Pontifex\Manifest\FileScanner::is_pontifex_working_path()},
	 * which always compares byte-exact. Byte-exact is correct for the
	 * scanner: it walks a filesystem it controls, so the case it observes is
	 * whatever it actually wrote. This method instead compares a path taken
	 * from an UNTRUSTED archive against a destination filesystem whose case
	 * sensitivity is not a given — it varies by host (APFS on macOS and
	 * NTFS on Windows fold case by default; ext4, the common Linux/hosting
	 * case, does not) — so a single, unconditional comparison rule cannot be
	 * correct for every destination.
	 *
	 * $case_sensitive_filesystem is therefore taken as a PARAMETER rather
	 * than read from instance state, deliberately. It keeps this method
	 * static so BOTH branches can be driven directly by reflection in tests
	 * on any host, rather than the suite's coverage of one branch depending
	 * on whichever filesystem happens to run it. The caller,
	 * {@see self::assert_not_pontifex_working_path()}, determines the real
	 * answer via {@see self::destination_is_case_sensitive()} (a probe
	 * against the actual destination) and passes it in.
	 *
	 * Case-SENSITIVE branch: byte-exact, identical to FileScanner's own
	 * comparison. This is the false-refusal fix: previously this method
	 * folded case UNCONDITIONALLY, so on a case-sensitive destination a
	 * genuine "wp-content/Pontifex/" directory — scanned faithfully into a
	 * legitimate archive by the byte-exact FileScanner, which never prunes
	 * it — was refused on restore, making that backup unrestorable with no
	 * attacker involved. In this branch "wp-content/Pontifex/…" and
	 * "wp-content/PONTIFEX/…" are ordinary, different directories and are
	 * correctly PERMITTED.
	 *
	 * Case-INSENSITIVE branch: unchanged from before this fix — ASCII
	 * case-insensitive (strcasecmp()/strncasecmp()), deliberately not
	 * mb_*() or a locale-dependent fold, either of which could behave
	 * differently depending on server configuration and make a security
	 * guard's behaviour non-deterministic. Here "wp-content/PONTIFEX/…" and
	 * "wp-content/pontifex/…" name the very same on-disk directory even
	 * though they are different byte strings, so a forged archive spelling
	 * its way past a byte-exact check would still land inside the real
	 * working directory; refusing it is correct.
	 *
	 * Because of the case-insensitive branch, FileWriter's guard remains a
	 * strict superset of FileScanner's on a case-insensitive destination —
	 * every path FileScanner prunes, FileWriter also refuses, but not the
	 * reverse. On a case-sensitive destination the two are byte-identical.
	 * See FileWriterTest::test_writer_refuses_everything_the_scanner_prunes()
	 * for the asserted relationship in both branches.
	 *
	 * Known limits (deliberately not addressed by this method or its case
	 * probe, and not in scope here):
	 *
	 *  - Windows-specific path spellings: a trailing dot or trailing space
	 *    ("wp-content/pontifex." / "wp-content/pontifex "), backslash path
	 *    separators ("wp-content\pontifex\.htaccess"), and 8.3 short names.
	 *    Win32 silently strips trailing dots/spaces from a path and treats
	 *    backslash as a separator; none of that is reproduced here.
	 *  - The comparison is against the literal string "wp-content/pontifex".
	 *    A site with a customised WP_CONTENT_DIR (for example Bedrock's
	 *    "app/") has its actual Pontifex working directory somewhere else
	 *    entirely; this method has no way to know that and does not guard
	 *    it.
	 *
	 * @param string $relative_path            Path relative to the restore root.
	 * @param bool   $case_sensitive_filesystem Whether the destination filesystem treats path case as significant.
	 * @return bool True if the path is wp-content/pontifex itself or beneath it.
	 */
	private static function is_pontifex_working_path( string $relative_path, bool $case_sensitive_filesystem ): bool {
		$root = 'wp-content/pontifex';

		if ( $case_sensitive_filesystem ) {
			if ( $relative_path === $root ) {
				return true;
			}
			$prefix = $root . '/';
			return 0 === strncmp( $relative_path, $prefix, strlen( $prefix ) );
		}

		if ( 0 === strcasecmp( $relative_path, $root ) ) {
			return true;
		}
		$prefix = $root . '/';
		return 0 === strncasecmp( $relative_path, $prefix, strlen( $prefix ) );
	}

	/**
	 * Determine, once, whether the destination filesystem folds path case.
	 *
	 * A text comparison alone cannot know whether "wp-content/pontifex" and
	 * "wp-content/PONTIFEX" name the same on-disk directory — that depends
	 * on the destination filesystem, not on the bytes being compared. This
	 * probes the real answer once, against $this->destination_root (the
	 * actual directory the restore is about to write hundreds of entries
	 * into), and caches it: every entry in one restore shares the same
	 * destination_root, so the answer cannot change mid-restore, and
	 * probing per entry would cost a filesystem round trip for nothing.
	 *
	 * The probe creates a uniquely-named, mixed-case file inside the
	 * destination root, then asks file_exists() whether the CASE-FLIPPED
	 * spelling of that same name resolves to it. On a case-folding
	 * filesystem it does; on a case-sensitive one it does not, because the
	 * flipped spelling names a different, non-existent file. Where
	 * file_exists() alone could in principle be fooled by an unrelated file
	 * that happens to collide with the flipped name, fileinode() confirms
	 * the flipped spelling really does resolve to the very file just
	 * created, not a coincidence. The probe file is always removed,
	 * including when the probe itself fails partway through — the
	 * try/finally covers every exit path, so a restore never leaves probe
	 * litter behind in a site's wp-content.
	 *
	 * If the probe cannot run at all (the write itself fails), the
	 * filesystem is treated as case-INSENSITIVE — the stricter branch of
	 * {@see self::is_pontifex_working_path()}, so a guard that cannot
	 * determine the truth fails closed rather than open. In practice this
	 * fallback is unreachable at the point a real restore would hit it:
	 * destination_root is the very directory hundreds of subsequent entries
	 * are about to be written into, so a root Pontifex cannot write one
	 * probe file into is a root the restore is about to fail on anyway, for
	 * the identical reason, on its very first real entry.
	 *
	 * @return bool True if the destination filesystem is case-sensitive.
	 */
	private function destination_is_case_sensitive(): bool {
		if ( null !== $this->case_sensitive_destination ) {
			return $this->case_sensitive_destination;
		}

		$name         = 'PontifexCaseProbe' . bin2hex( random_bytes( 8 ) );
		$probe_path   = $this->destination_root . '/.' . $name;
		$flipped_path = $this->destination_root . '/.' . self::flip_case( $name );

		$this->case_sensitive_destination = false;

		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents,WordPress.PHP.NoSilencedErrors.Discouraged -- One-off case-sensitivity probe at the start of a restore; WP_Filesystem is unavailable in CLI/non-WP contexts where this code may run.
			if ( false === @file_put_contents( $probe_path, '' ) ) {
				return $this->case_sensitive_destination;
			}

			if ( ! file_exists( $flipped_path ) ) {
				$this->case_sensitive_destination = true;
				return $this->case_sensitive_destination;
			}

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort identity check; either side reading false falls through to the safer case-insensitive default below.
			$probe_inode = @fileinode( $probe_path );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort identity check; either side reading false falls through to the safer case-insensitive default below.
			$flipped_inode = @fileinode( $flipped_path );

			$this->case_sensitive_destination = ! ( false !== $probe_inode && $probe_inode === $flipped_inode );

			return $this->case_sensitive_destination;
		} finally {
			if ( is_file( $probe_path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort removal of the probe file; its own failure must not mask the probe result already computed.
				@unlink( $probe_path );
			}
		}
	}

	/**
	 * Flip the case of every ASCII letter in a string; non-letters pass through unchanged.
	 *
	 * Used only to build the case-sensitivity probe's second spelling: a
	 * predictable, deterministic transform (never mb_*() or a locale fold,
	 * for the same reason {@see self::is_pontifex_working_path()} avoids
	 * them) guaranteed to differ, byte-for-byte, from its input wherever the
	 * input contains a letter.
	 *
	 * @param string $value The string to flip.
	 * @return string The case-flipped string.
	 */
	private static function flip_case( string $value ): string {
		$flipped = '';
		for ( $i = 0, $length = strlen( $value ); $i < $length; $i++ ) {
			$character = $value[ $i ];
			if ( ctype_upper( $character ) ) {
				$flipped .= strtolower( $character );
			} elseif ( ctype_lower( $character ) ) {
				$flipped .= strtoupper( $character );
			} else {
				$flipped .= $character;
			}
		}
		return $flipped;
	}

	/**
	 * Convert a relative archive path into a safe absolute path under the destination root.
	 *
	 * Rejects an empty path, absolute paths, paths with ".." segments, and
	 * paths containing null bytes. Returns the joined absolute path; the
	 * path is not required to exist yet (it will be created).
	 *
	 * These checks are NOT a byte-for-byte duplicate of
	 * normalise_entry_path()'s, even though both methods make the same four
	 * checks — the two guard different VALUES. normalise_entry_path()
	 * checks its INPUT, the raw path field straight off the entry header,
	 * before any collapsing happens. This method checks whatever value it
	 * is actually given — which, in the normal write_entry() call sequence,
	 * is normalise_entry_path()'s COLLAPSED OUTPUT, not the raw input.
	 *
	 * That distinction is not academic: it is exactly what "empty" means
	 * for each method. A raw path of "." or "./" is non-empty on input, so
	 * normalise_entry_path()'s own pre-collapse check lets it through — but
	 * it collapses to the empty string once its "." segments are dropped,
	 * and an empty relative path, joined onto destination_root, resolves to
	 * destination_root ITSELF. A directory entry at that path would apply
	 * the archive's mode to the WordPress root (0000 has been
	 * demonstrated). Since normalise_entry_path() now also checks its own
	 * collapsed output before returning (see its docblock), the two checks
	 * overlap for paths that reach this method via write_entry() — but
	 * keeping both is deliberate defence in depth, not duplication: this
	 * method remains correct on its own terms for any other caller,
	 * present or future, that might reach it with a path that was never
	 * routed through normalise_entry_path() first, and a second,
	 * independent check on the exact value about to be joined into a
	 * filesystem path costs nothing.
	 *
	 * @param string $relative_path The path to resolve. In the normal write_entry() sequence this is normalise_entry_path()'s collapsed OUTPUT, not the raw header field.
	 * @return string An absolute path under the destination root.
	 * @throws InvalidArgumentException If the path is unsafe.
	 */
	private function resolve_safe_path( string $relative_path ): string {
		if ( '' === $relative_path ) {
			throw new InvalidArgumentException( 'Entry path must be non-empty.' );
		}
		if ( false !== strpos( $relative_path, "\0" ) ) {
			throw new InvalidArgumentException( 'Entry path contains a null byte.' );
		}
		if ( self::is_absolute_path( $relative_path ) ) {
			throw new InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $relative_path is reported verbatim for diagnostic context; exception path, not HTML output.
				sprintf( 'Entry path "%s" must be relative, not absolute.', $relative_path )
			);
		}

		$segments = explode( '/', str_replace( '\\', '/', $relative_path ) );
		foreach ( $segments as $segment ) {
			if ( '..' === $segment ) {
				throw new InvalidArgumentException(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $relative_path is reported verbatim for diagnostic context; exception path, not HTML output.
					sprintf( 'Entry path "%s" contains a parent-directory segment.', $relative_path )
				);
			}
		}

		return $this->destination_root . '/' . $relative_path;
	}

	/**
	 * Refuse an entry whose path descends through a symlinked directory.
	 *
	 * Although resolve_safe_path() blocks ".." and absolute paths textually,
	 * a hostile archive can still escape the root by placing a symlink as an
	 * earlier entry and then writing a file *through* it — neither path
	 * contains ".." nor is absolute (the Zip-Slip-via-symlink class). Walk
	 * every ancestor component of the entry and refuse if any is a symlink.
	 * is_link() is true for a symlink whether or not its target exists, so
	 * both live and dangling escapes are caught. The scanner never descends
	 * into symlinks (it records them as KIND_SYMLINK entries and does not
	 * follow them), so a legitimate archive never has a symlinked ancestor —
	 * only a crafted one does.
	 *
	 * @param string $relative_path The entry path, relative to the root.
	 * @throws InvalidArgumentException If any ancestor component is a symlink.
	 */
	private function assert_no_symlinked_ancestor( string $relative_path ): void {
		$segments = explode( '/', $relative_path );
		array_pop( $segments );

		$current = $this->destination_root;
		foreach ( $segments as $segment ) {
			$current .= '/' . $segment;
			if ( is_link( $current ) ) {
				throw new InvalidArgumentException(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $relative_path is reported verbatim for diagnostic context; exception path, not HTML output.
					sprintf( 'Entry path "%s" descends through a symlink and is refused.', $relative_path )
				);
			}
		}
	}

	/**
	 * Remove a symlink sitting at the target path so a write lands in place.
	 *
	 * A hostile archive may place a symlink and then write a file or directory
	 * at the same path; without this, the file/dir operation would follow the
	 * link and act outside the root. Unlinking the link (never its target)
	 * makes the subsequent write land inside the destination tree. A
	 * legitimate archive never has two entries at one path.
	 *
	 * @param string $target_path The absolute path about to be written.
	 * @return void
	 */
	private function remove_conflicting_symlink( string $target_path ): void {
		if ( is_link( $target_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Restore-time removal of a conflicting symlink; WP_Filesystem cannot remove symlinks reliably.
			@unlink( $target_path );
		}
	}

	/**
	 * Ensure the parent directory of $target_path exists, creating it if necessary.
	 *
	 * Created directories get PARENT_DIR_MODE (0755). If the parent
	 * already exists, no change is made — including no mode update.
	 *
	 * @param string $target_path Absolute path whose parent should exist.
	 * @throws RuntimeException If the parent cannot be created.
	 */
	private function ensure_parent_directory( string $target_path ): void {
		$parent = dirname( $target_path );
		if ( '' === $parent || $parent === $target_path || is_dir( $parent ) ) {
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir,WordPress.PHP.NoSilencedErrors.Discouraged -- Restore-time filesystem write; WP_Filesystem is unavailable in CLI/non-WP contexts where this code may run.
		if ( ! @mkdir( $parent, self::PARENT_DIR_MODE, true ) && ! is_dir( $parent ) ) {
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $parent is reported verbatim for diagnostic context; exception path, not HTML output.
				sprintf( 'Could not create parent directory "%s".', $parent )
			);
		}
	}

	/**
	 * Clamp a restored POSIX mode to a safe set of bits.
	 *
	 * The mode is taken verbatim from the archive, which on the import trust
	 * boundary is attacker-controlled. Two classes of bit are stripped before it
	 * is applied: the special bits (setuid, setgid, sticky — `07000`), so a
	 * malicious archive cannot restore a setuid binary; and the world-write bit
	 * (`0002`), so it cannot leave wp-config.php or any file writable by everyone.
	 * Owner and group bits, and read/execute for others, are preserved, so a
	 * normal same-site self-restore keeps its permissions intact.
	 *
	 * @param int $mode The mode recorded in the archive entry.
	 * @return int The clamped mode.
	 */
	private static function clamp_mode( int $mode ): int {
		return $mode & 0o0775;
	}

	/**
	 * Write file contents and set mode and mtime.
	 *
	 * The bytes land in a sibling temp file which is renamed over the target
	 * once complete — see {@see self::finalise_temp()} for the two properties
	 * that buys (per-file crash atomicity, and replacing read-only targets).
	 *
	 * @param string $target_path Absolute path of the file to write.
	 * @param string $payload     Decoded file contents.
	 * @param int    $mode        POSIX mode bits to set after writing.
	 * @param int    $mtime       Unix modification timestamp to set after writing.
	 * @throws RuntimeException If writing, chmod, touch, or the final rename fails.
	 */
	private function write_file( string $target_path, string $payload, int $mode, int $mtime ): void {
		$this->remove_conflicting_symlink( $target_path );
		$temp_path = self::temp_sibling_path( $target_path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents,WordPress.PHP.NoSilencedErrors.Discouraged -- Restore-time filesystem write; WP_Filesystem is unavailable in CLI/non-WP contexts where this code may run.
		$written = @file_put_contents( $temp_path, $payload );
		if ( false === $written ) {
			$this->discard_temp( $temp_path );
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $target_path is reported verbatim for diagnostic context; exception path, not HTML output.
				sprintf( 'Could not write file "%s".', $target_path )
			);
		}
		$this->finalise_temp( $temp_path, $target_path, $mode, $mtime );
	}

	/**
	 * Build the sibling temp path a file is written to before its atomic rename.
	 *
	 * A sibling of the target (same directory), so the final rename is a
	 * same-filesystem move; the unique suffix — built by
	 * {@see \Pontifex\Filesystem\TempArtefact::suffix()}, the same helper
	 * {@see self::probe_symlink_creation()} uses, so the two producers can
	 * never drift apart — keeps concurrent writers apart and lets
	 * {@see self::sweep_orphaned_temp_files()} recognise one left behind by an
	 * interrupted restore.
	 *
	 * @param string $target_path The final file path.
	 * @return string The temp path to write to first.
	 */
	private static function temp_sibling_path( string $target_path ): string {
		return $target_path . TempArtefact::suffix();
	}

	/**
	 * Delete a temp file left by a failed write, best-effort.
	 *
	 * Its own failure must not mask the write failure being reported.
	 *
	 * @param string $temp_path The temp path to remove.
	 * @return void
	 */
	private function discard_temp( string $temp_path ): void {
		if ( is_file( $temp_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort cleanup of the temp file after a failed restore write; its failure must not mask the original error.
			@unlink( $temp_path );
		}
	}

	/**
	 * Apply mode and mtime to a completed temp file, then rename it into place.
	 *
	 * The rename buys two properties at once. First, per-file crash atomicity:
	 * a hard kill mid-write leaves the target untouched (only an orphaned temp),
	 * never a half-written live file. Second, read-only targets are replaceable:
	 * POSIX rename() needs write permission on the DIRECTORY, not the target
	 * file, so a read-only file at the destination — git object and pack files
	 * being the everyday case — no longer aborts the restore and its recovery
	 * the way an fopen-for-write did. This is rsync's write-to-temp-then-rename
	 * behaviour. Mode and mtime are set on the temp BEFORE the rename, so the
	 * file appears at its final path fully formed; rename preserves both.
	 *
	 * @param string $temp_path   The completed temp file.
	 * @param string $target_path The final path to move it onto.
	 * @param int    $mode        POSIX mode bits to set.
	 * @param int    $mtime       Unix modification timestamp to set.
	 * @return void
	 * @throws RuntimeException If chmod, touch, or the rename fails; the temp is discarded.
	 */
	private function finalise_temp( string $temp_path, string $target_path, int $mode, int $mtime ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod,WordPress.PHP.NoSilencedErrors.Discouraged -- Restore-time filesystem write; WP_Filesystem cannot preserve POSIX mode bits.
		if ( ! @chmod( $temp_path, $mode ) ) {
			$this->discard_temp( $temp_path );
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $target_path is reported verbatim for diagnostic context; exception path, not HTML output.
				sprintf( 'Could not chmod file "%s".', $target_path )
			);
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch,WordPress.PHP.NoSilencedErrors.Discouraged -- Restore-time filesystem write; WP_Filesystem cannot preserve mtime.
		if ( ! @touch( $temp_path, $mtime ) ) {
			$this->discard_temp( $temp_path );
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $target_path is reported verbatim for diagnostic context; exception path, not HTML output.
				sprintf( 'Could not set mtime on file "%s".', $target_path )
			);
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename,WordPress.PHP.NoSilencedErrors.Discouraged -- Atomically moving the completed file into place (a same-directory move); WP_Filesystem is unavailable in CLI/non-WP contexts where this code may run.
		if ( ! @rename( $temp_path, $target_path ) ) {
			$this->discard_temp( $temp_path );
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $target_path is reported verbatim for diagnostic context; exception path, not HTML output.
				sprintf( 'Could not move file into place at "%s".', $target_path )
			);
		}
	}

	/**
	 * Write file contents from a stream and set mode and mtime.
	 *
	 * The streamed twin of {@see self::write_file()} (ADR 0010): the payload is
	 * copied to disk directly from the reader's spool, so a large file never
	 * occupies payload-sized memory. The bytes were hash-verified before the
	 * reader handed the stream over. The source stream is closed here — the
	 * result's consumer owns it, and this is where it is consumed.
	 *
	 * @param string   $target_path Absolute path of the file to write.
	 * @param resource $payload     Decoded file contents, positioned at the start.
	 * @param int      $mode        POSIX mode bits to set after writing.
	 * @param int      $mtime       Unix modification timestamp to set after writing.
	 * @throws RuntimeException If writing, chmod, touch, or the final rename fails.
	 */
	private function write_file_from_stream( string $target_path, $payload, int $mode, int $mtime ): void {
		$this->remove_conflicting_symlink( $target_path );
		$temp_path = self::temp_sibling_path( $target_path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- Restore-time filesystem write; WP_Filesystem is unavailable in CLI/non-WP contexts where this code may run.
		$destination = @fopen( $temp_path, 'wb' );
		if ( false === $destination ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Cleanup of the reader's spool stream; not a filesystem path.
			fclose( $payload );
			$this->discard_temp( $temp_path );
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $target_path is reported verbatim for diagnostic context; exception path, not HTML output.
				sprintf( 'Could not write file "%s".', $target_path )
			);
		}
		$copied = stream_copy_to_stream( $payload, $destination );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the restore-time write handle opened above; not a WP_Filesystem operation.
		$closed = fclose( $destination );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Cleanup of the reader's spool stream; not a filesystem path.
		fclose( $payload );
		if ( false === $copied || ! $closed ) {
			$this->discard_temp( $temp_path );
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $target_path is reported verbatim for diagnostic context; exception path, not HTML output.
				sprintf( 'Could not write file "%s".', $target_path )
			);
		}
		$this->finalise_temp( $temp_path, $target_path, $mode, $mtime );
	}

	/**
	 * Directory modes held back until the walk finishes, as absolute path => mode.
	 *
	 * Only directories whose recorded mode lacks owner-write appear here; every
	 * other directory gets its mode immediately and needs no second pass.
	 *
	 * @var array<string, int>
	 */
	private array $deferred_directory_modes = array();

	/**
	 * Create a directory at $target_path with the given mode.
	 *
	 * Idempotent: if the directory already exists, its mode is
	 * updated to match.
	 *
	 * @param string $target_path Absolute path of the directory to create.
	 * @param int    $mode        POSIX mode bits to set.
	 * @throws RuntimeException If the directory cannot be created or its mode cannot be set.
	 */
	private function write_directory( string $target_path, int $mode ): void {
		$this->remove_conflicting_symlink( $target_path );

		// Keep the directory owner-writable for the duration of the walk, and
		// apply its recorded mode only once every entry is in place.
		//
		// Directory entries sort ahead of the files inside them (FileScanner
		// orders by path, and a directory's path is a strict prefix of its
		// contents), so applying a restrictive mode here made the directory
		// unwritable BEFORE the files that live in it were written. A source
		// site with a hardened `wp-content/uploads/private` at 0555 — a
		// documented WordPress lockdown step, and what several security plugins
		// apply — exported perfectly happily (0555 is readable) and then failed
		// its own restore on the very next entry. Nothing preflights directory
		// modes, so the refusal landed mid-walk: the one place with no recovery,
		// because the file half is a merge with no per-entry undo. Everything
		// sorting before that directory was already the archive's content and
		// everything after was still the old site.
		$working_mode = $mode | 0o700;

		if ( ! is_dir( $target_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir,WordPress.PHP.NoSilencedErrors.Discouraged -- Restore-time filesystem write; WP_Filesystem is unavailable in CLI/non-WP contexts where this code may run.
			if ( ! @mkdir( $target_path, $working_mode, true ) && ! is_dir( $target_path ) ) {
				throw new RuntimeException(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $target_path is reported verbatim for diagnostic context; exception path, not HTML output.
					sprintf( 'Could not create directory "%s".', $target_path )
				);
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod,WordPress.PHP.NoSilencedErrors.Discouraged -- Restore-time filesystem write; WP_Filesystem cannot preserve POSIX mode bits.
		if ( ! @chmod( $target_path, $working_mode ) ) {
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $target_path is reported verbatim for diagnostic context; exception path, not HTML output.
				sprintf( 'Could not chmod directory "%s".', $target_path )
			);
		}

		if ( $working_mode !== $mode ) {
			$this->deferred_directory_modes[ $target_path ] = $mode;
		}
	}

	/**
	 * Apply the directory modes held back during the walk.
	 *
	 * Called once every entry is written. Deepest path first, so a parent is
	 * never made unwritable before its own children have been adjusted.
	 *
	 * A failure here is reported, not thrown. By this point every byte is
	 * already on disk and the restore has succeeded; throwing would report a
	 * complete restore as a failed one, and send the operator to a rollback
	 * they do not need. A directory left more permissive than the archive
	 * recorded is worth telling them about, and worth no more than that.
	 *
	 * @return array<int, string> The directories whose recorded mode could not be applied.
	 */
	public function finalise_directory_modes(): array {
		$paths = array_keys( $this->deferred_directory_modes );
		usort( $paths, static fn ( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );

		$failed = array();
		foreach ( $paths as $path ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod,WordPress.PHP.NoSilencedErrors.Discouraged -- Restore-time filesystem write; WP_Filesystem cannot preserve POSIX mode bits.
			if ( ! @chmod( $path, $this->deferred_directory_modes[ $path ] ) ) {
				$failed[] = $path;
			}
		}

		$this->deferred_directory_modes = array();

		return $failed;
	}

	/**
	 * Remove every leftover temp artefact an earlier, interrupted restore abandoned.
	 *
	 * The file-side twin of the sweep {@see \Pontifex\Restore\DatabaseWriter::begin_staging()}
	 * already performs for a crashed earlier run's leftover `pontifexstg_*` /
	 * `pontifexold_*` tables (ADR 0009): same policy, same moment in the
	 * restore, same failure posture. {@see self::write_file()} and
	 * {@see self::write_file_from_stream()} both land their bytes in a sibling
	 * temp file — {@see self::temp_sibling_path()} — before renaming it over
	 * the target, and {@see self::probe_symlink_creation()} briefly creates a
	 * temp-shaped symlink of its own. A restore killed between either write
	 * and its rename (SIGKILL, a host timeout, a fatal error) leaves that temp
	 * artefact sitting next to the real file, inside the live site. Nothing
	 * else ever removes it: it is not the manifest's problem (the archive that
	 * caused it is long finished), and it is not
	 * {@see self::discard_temp()}'s problem either — that only ever runs for a
	 * failure THIS call caught, never for one a previous, separate PHP process
	 * never got the chance to catch at all. Left alone, these accumulate under
	 * wp-content, can approach the size of the file they shadow, and —
	 * because {@see \Pontifex\Manifest\FileScanner} only prunes
	 * wp-content/pontifex, never a "*.tmp" shape — are scanned into the NEXT
	 * backup as ordinary content and faithfully restored onto whatever site
	 * that backup is later used to recover.
	 *
	 * CALLED ONCE, by {@see \Pontifex\Restore\RestoreRunner::restore()},
	 * immediately before the database-side sweep — after every preflight has
	 * already had its chance to refuse, so no archive this restore rejects has
	 * had any of its content applied. That is not quite the same as saying
	 * this call itself changes nothing: {@see self::destination_is_case_sensitive()}
	 * runs inside it and writes a one-off probe file at $this->destination_root,
	 * removed again in its own `finally` before this method ever reasons about
	 * an orphan. Never called from verify(), which writes nothing and so has
	 * nothing to sweep.
	 *
	 * REACH. The recursive walk below is rooted at $this->destination_root,
	 * narrowed to `$this->destination_root . '/' . $this->required_prefix`
	 * when a required prefix is set (a content-only restore is confined to
	 * "wp-content"; a rollback has none, so the whole destination root is
	 * walked). An earlier version of this docblock called that "exactly
	 * complete, no more and no less" — an adversarial audit demonstrated both
	 * halves of that claim false against a real filesystem.
	 *
	 * Too little: {@see self::assert_within_required_prefix()} does confine
	 * every entry {@see self::write_entry()} performs to that same boundary,
	 * but the CAPABILITY PREFLIGHT that runs ahead of it does not. See
	 * {@see self::symlink_creation_probe_directories()}'s own docblock for why
	 * {@see self::assert_symlinks_creatable()}'s probe directories fall back
	 * to $this->destination_root itself once a declared link's own parent has
	 * no existing ancestor closer than the root — which a symlink declared at
	 * exactly the required-prefix path ("wp-content" itself), or a restore
	 * onto a fresh destination where nothing has been created yet, both
	 * produce. A probe interrupted there ({@see self::probe_symlink_creation()})
	 * leaves its dangling-symlink orphan AT the installation root, outside a
	 * required-prefix-narrowed recursive walk, where a content-only restore
	 * would otherwise never look again. So, when a required prefix is set,
	 * this method ALSO lists $this->destination_root's own immediate
	 * children — never descending, because the recursive walk already covers
	 * the whole prefixed subtree — applying the same pattern match and the
	 * same isLink()-before-isFile() removal described under REMOVAL below,
	 * and counting those removals the same way. See the second loop in this
	 * method's body.
	 *
	 * Too much: $sweep_root itself can be a symlink — see TRAVERSAL below for
	 * why that is caught separately, before the recursive walk is even built.
	 *
	 * The honest claim REACH can make, then, is: everywhere this writer can
	 * actually have created an artefact, which is the prefixed subtree PLUS
	 * the installation root the capability probe can fall back to — never
	 * more, and, since the two fixes above, never less either. If the sweep
	 * root does not exist yet (a first-ever restore onto a fresh destination),
	 * there is nothing to sweep and this returns 0 without creating anything.
	 *
	 * NO AGE THRESHOLD. {@see \Pontifex\Lock\OperationLock} guarantees only one
	 * site-mutating operation — backup, restore, or rollback — runs against
	 * this site at a time, so by the time this method runs (inside a restore
	 * that has just acquired that lock) no LIVE temp file of this writer's own
	 * can possibly exist to be swept out from under a concurrent write; every
	 * match found here is necessarily a leftover from a run that is no longer
	 * running. An age threshold would actively break the commonest real
	 * case — kill a restore, retry immediately — where the orphans left
	 * behind are still only seconds old. The database-side twin this mirrors
	 * has no threshold either.
	 *
	 * WHAT COUNTS AS AN ORPHAN. Only a basename matching
	 * {@see \Pontifex\Filesystem\TempArtefact::is_orphan_name()} — the exact
	 * shape {@see \Pontifex\Filesystem\TempArtefact::suffix()} produces,
	 * anchored at the end of the basename — never a loose "*.tmp" glob; see
	 * that class's own docblocks for the two shapes it deliberately does not
	 * match (a resumable export's "*.part" file, and an ordinary user file
	 * that merely contains "pontifex" or ".tmp" somewhere in its name).
	 *
	 * TRAVERSAL, the one way this could be catastrophic if it were wrong.
	 * Built from {@see RecursiveDirectoryIterator} with
	 * `SKIP_DOTS | UNIX_PATHS` — the same flags
	 * {@see \Pontifex\Manifest\FileScanner::scan()} uses — and, critically,
	 * NEVER `FOLLOW_SYMLINKS`. A live site may legitimately contain a symlink
	 * (a Composer vendor/ layout, an aliased uploads directory), and one
	 * shaped like `wp-content/uploads/x -> /` would otherwise turn a sweep
	 * that followed it into a walk of the ENTIRE filesystem looking for
	 * something to delete. {@see RecursiveIteratorIterator} calls
	 * `hasChildren()` with its own default `$allowLinks = false`, so a
	 * symlinked directory encountered DURING the walk is never descended into
	 * — this method must not defeat that default by passing anything else.
	 *
	 * That default protects every INTERIOR symlink the walk encounters, but
	 * it says nothing about $sweep_root ITSELF, and an adversarial audit
	 * demonstrated the gap: `is_dir( $sweep_root )` follows a symlink to ask
	 * whether its TARGET is a directory, and {@see RecursiveDirectoryIterator}'s
	 * own constructor then opens whatever a symlinked root resolves to just
	 * as readily as it opens a real one — `$allowLinks` governs only whether
	 * the walk descends INTO a child it finds while walking, never what it
	 * was handed as its starting point. A required prefix of "wp-content"
	 * whose "wp-content" happens to be a symlink to a foreign tree was
	 * therefore walked, and swept, in full — while
	 * {@see self::assert_no_symlinked_ancestor()} refuses every entry
	 * {@see self::write_entry()} is asked to write through that identical
	 * symlinked directory, so the writer's own reach there is zero and the
	 * sweep's was a whole foreign tree. So $sweep_root is checked separately,
	 * before a single {@see RecursiveDirectoryIterator} is even constructed,
	 * with BOTH of two guards — this project does not take the lighter
	 * single-guard option: `is_link( $sweep_root )` refuses outright,
	 * mirroring {@see self::assert_no_symlinked_ancestor()}'s posture exactly
	 * (a tree the writer refuses to write THROUGH is a tree the sweep must
	 * refuse to walk); and, independently, `realpath( $sweep_root )` must
	 * resolve to $this->destination_root itself or somewhere beneath it,
	 * with a false or unreadable realpath() result refused rather than
	 * trusted. Either guard refusing returns 0 without touching anything.
	 *
	 * The directory iterator is
	 * wrapped in a {@see RecursiveCallbackFilterIterator} — INSIDE the
	 * {@see RecursiveIteratorIterator}, so a pruned directory is never opened
	 * at all, mirroring {@see \Pontifex\Manifest\FileScanner::scan()} — whose
	 * callback prunes Pontifex's own working directory via the existing
	 * {@see self::is_pontifex_working_path()}, fed the candidate's path made
	 * relative to $this->destination_root, DELIBERATELY NOT to the (possibly
	 * narrower) sweep root: that method expects the shape
	 * "wp-content/pontifex/…", and slicing against a sweep root already
	 * inside wp-content would cut the string in the wrong place and never
	 * recognise it. The callback's `: bool` return type is declared for the
	 * same reason FileScanner's is: an edit that fell off the end without
	 * returning would prune EVERYTHING silently, sweeping nothing and
	 * reporting success regardless.
	 *
	 * TWO deliberate differences from {@see \Pontifex\Manifest\FileScanner::scan()},
	 * each the opposite choice for the opposite reason:
	 *
	 *  1. This walk passes `RecursiveIteratorIterator::CATCH_GET_CHILD`, which
	 *     FileScanner's deliberately omits. For an export, a silently skipped
	 *     unreadable directory would be a silent hole in someone's backup —
	 *     unacceptable. Here the opposite is true: a directory this process
	 *     cannot even read cannot have been written into by THIS writer
	 *     either, so it can hold no orphan of ours to sweep; and this is
	 *     best-effort housekeeping run at the very start of a restore, so its
	 *     own failure must never be capable of stopping that restore from
	 *     proceeding.
	 *  2. The whole walk is wrapped in `try { … } catch ( Throwable $error )`.
	 *     This method must NEVER throw — not a permissions error, not an
	 *     iterator failure, nothing. It returns however many artefacts were
	 *     actually removed before whatever happened, happened.
	 *
	 * clearstatcache() runs once at the start: isLink()/isFile() results can
	 * be cached from earlier in the same request (an earlier restore's own
	 * probes, in a long-running CLI process), and a stale reading here would
	 * misclassify what is actually on disk right now.
	 *
	 * REMOVAL. For each visited entry whose basename matches the pattern,
	 * isLink() is checked BEFORE isFile() — an ordering that is load-bearing,
	 * not stylistic. {@see self::probe_symlink_creation()}'s own orphan is a
	 * DANGLING symlink (its target, a sibling name chosen to never exist, is
	 * exactly what makes that probe work at all — see its own docblock), and
	 * PHP's isFile() reports FALSE for a dangling symlink, because it follows
	 * the link to ask what the TARGET is, and there is no target. A sweep
	 * that checked isFile() first would therefore silently pass over every
	 * probe orphan while still reporting success. unlink() on a symlink
	 * removes the link itself, never anything at the far end of a (here,
	 * nonexistent) target — the same guarantee
	 * {@see self::remove_conflicting_symlink()} relies on elsewhere in this
	 * class. A directory that happens to be NAMED like a temp artefact — never
	 * produced by this class, but not this method's business to assume
	 * impossible — is left alone entirely and does not contribute to the
	 * count. The same ordering, and the same directory exemption, govern the
	 * installation-root scan described under REACH above; it is not a
	 * separate policy, only a second place the same one is applied.
	 *
	 * The returned count is the number of unlink() calls that actually
	 * returned true — never the number of matching names FOUND. A reported
	 * figure in this project describes what was actually done, never what was
	 * merely predicted; a name that matched but failed to unlink (a
	 * permissions problem, a race) is not counted as removed, because it was
	 * not.
	 *
	 * @return int How many leftover temp artefacts were actually removed.
	 */
	public function sweep_orphaned_temp_files(): int {
		clearstatcache();

		$sweep_root = null === $this->required_prefix
			? $this->destination_root
			: $this->destination_root . '/' . $this->required_prefix;

		// Both guards run before a single RecursiveDirectoryIterator is built,
		// and both refuse the whole sweep (returning 0) rather than merely
		// skipping the offending part — see TRAVERSAL in this method's
		// docblock for why $sweep_root itself needs checks the interior
		// walk's own hasChildren( $allowLinks = false ) default cannot provide.
		if ( is_link( $sweep_root ) ) {
			return 0;
		}

		if ( ! is_dir( $sweep_root ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_realpath -- Confirming the sweep root resolves inside this writer's own, already-resolved destination_root before any recursive walk begins; see TRAVERSAL above.
		$resolved_sweep_root = realpath( $sweep_root );
		if ( false === $resolved_sweep_root
			|| ( $resolved_sweep_root !== $this->destination_root
				&& ! str_starts_with( $resolved_sweep_root, $this->destination_root . '/' ) )
		) {
			return 0;
		}

		$removed = 0;

		try {
			$destination_root           = $this->destination_root;
			$case_sensitive_destination = $this->destination_is_case_sensitive();
			$root_prefix_len            = strlen( $destination_root ) + 1;

			$flags = RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::UNIX_PATHS;
			$inner = new RecursiveDirectoryIterator( $sweep_root, $flags );

			// Pruning happens HERE, inside the recursive walk, exactly as it does in
			// FileScanner::scan() — a callback that returns false for a directory
			// means PHP never opens that directory at all, so Pontifex's own working
			// directory is genuinely never entered by this sweep.
			$filtered = new RecursiveCallbackFilterIterator(
				$inner,
				static function ( SplFileInfo $current ) use ( $root_prefix_len, $case_sensitive_destination ): bool {
					$relative_path = str_replace( '\\', '/', substr( $current->getPathname(), $root_prefix_len ) );

					return ! self::is_pontifex_working_path( $relative_path, $case_sensitive_destination );
				}
			);

			// SELF_FIRST, so a directory whose own name happens to match the
			// orphan pattern is visited (and correctly left alone; see the
			// removal loop below) rather than only its contents being seen.
			// CATCH_GET_CHILD is the deliberate difference from FileScanner's own
			// walk — see this method's docblock for why the two must disagree.
			$walker = new RecursiveIteratorIterator(
				$filtered,
				RecursiveIteratorIterator::SELF_FIRST,
				RecursiveIteratorIterator::CATCH_GET_CHILD
			);

			foreach ( $walker as $info ) {
				// RecursiveDirectoryIterator yields SplFileInfo (in practice, itself);
				// the instanceof narrows the iterator's mixed current() for static
				// analysis rather than trusting that in silence.
				if ( ! $info instanceof SplFileInfo ) {
					continue;
				}
				if ( ! TempArtefact::is_orphan_name( $info->getFilename() ) ) {
					continue;
				}

				$path = $info->getPathname();

				// isLink() FIRST: a probe orphan is a dangling symlink, and isFile()
				// reports false for one — see this method's docblock.
				if ( $info->isLink() ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort removal of a leftover artefact from an earlier, interrupted restore; this method must never throw, so a failure here is simply not counted rather than surfaced.
					if ( @unlink( $path ) ) {
						++$removed;
					}
					continue;
				}

				if ( $info->isFile() ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort removal of a leftover artefact from an earlier, interrupted restore; this method must never throw, so a failure here is simply not counted rather than surfaced.
					if ( @unlink( $path ) ) {
						++$removed;
					}
				}

				// Anything else (a directory named like a temp artefact) is left
				// alone and does not contribute to the count.
			}

			// REACH's second half: when a required prefix narrows the recursive
			// walk above to a subtree, this writer can still have left a probe
			// orphan directly at the installation root — see REACH in this
			// method's docblock for the two real cases. Never recursive: the
			// walk above already covers the whole prefixed subtree, so only
			// $destination_root's own immediate children are listed here.
			if ( null !== $this->required_prefix ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Restore-time housekeeping listing of this writer's own, already-resolved destination_root; a read failure here simply means there is nothing further this step can find to sweep.
				$installation_root_children = @scandir( $destination_root );
				if ( false !== $installation_root_children ) {
					foreach ( $installation_root_children as $child_name ) {
						if ( '.' === $child_name || '..' === $child_name ) {
							continue;
						}
						if ( ! TempArtefact::is_orphan_name( $child_name ) ) {
							continue;
						}

						$child_path = $destination_root . '/' . $child_name;

						// Same isLink()-before-isFile() ordering as the loop above, and
						// for the same reason: a dangling probe orphan reports false
						// from isFile() — see REMOVAL in this method's docblock.
						if ( is_link( $child_path ) ) {
							// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort removal of a leftover artefact from an earlier, interrupted restore; this method must never throw, so a failure here is simply not counted rather than surfaced.
							if ( @unlink( $child_path ) ) {
								++$removed;
							}
							continue;
						}

						if ( is_file( $child_path ) ) {
							// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort removal of a leftover artefact from an earlier, interrupted restore; this method must never throw, so a failure here is simply not counted rather than surfaced.
							if ( @unlink( $child_path ) ) {
								++$removed;
							}
						}

						// A directory named like a temp artefact, at the installation
						// root, is left alone — the same posture as the loop above.
					}
				}
			}
		} catch ( Throwable $error ) {
			// Best-effort housekeeping must never abort the restore it runs ahead
			// of; whatever was removed before the failure is still reported.
			unset( $error );
		}

		return $removed;
	}

	/**
	 * Create a symlink at $target_path pointing at $link_target.
	 *
	 * Overwrites an existing symlink, file, or directory at the link path. Unless
	 * unsafe symlinks are explicitly allowed, a target that resolves outside the
	 * restore root (or is absolute) is refused — a hostile archive could otherwise
	 * plant an escaping link that later code follows.
	 *
	 * function_exists('symlink') is checked before the real call, for the same
	 * reason {@see self::probe_symlink_creation()} checks it: a disable_functions
	 * entry makes the bare call an undefined-function Error, which the `@`
	 * operator cannot silence, so an unguarded call here would crash the
	 * restore instead of raising the documented RuntimeException below. In the
	 * normal sequence this is unreachable — {@see self::assert_symlinks_creatable()}
	 * already refused any restore on a symlink-disabled host before the walk
	 * began — but that preflight is built from
	 * {@see \Pontifex\Restore\RestoreRunner::declared_symlink_targets()}, which
	 * SKIPS any symlink entry whose header records a null path or target, so a
	 * malformed entry of that exact shape can still reach this method with the
	 * preflight none the wiser. This guard is what keeps that shape a clean,
	 * documented refusal rather than a raw PHP Error.
	 *
	 * @param string $target_path Absolute path where the link should be created.
	 * @param string $link_target The string the link should point at.
	 * @throws RuntimeException If the target escapes the root (and is not allowed), symlink() is unavailable on this host, or the link cannot be created.
	 */
	private function write_symlink( string $target_path, string $link_target ): void {
		if ( ! $this->allow_unsafe_symlinks && $this->symlink_target_escapes_root( $target_path, $link_target ) ) {
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $target_path and $link_target are reported verbatim for diagnostic context; exception path, not HTML output.
				sprintf( 'Refusing the symbolic link "%s" whose target "%s" escapes the site. Re-run with --allow-unsafe-symlinks only if you trust this archive.', $target_path, $link_target )
			);
		}

		if ( ! function_exists( 'symlink' ) ) {
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $target_path and $link_target are reported verbatim for diagnostic context; exception path, not HTML output.
				sprintf( 'Could not create symlink "%s" -> "%s": symlink() is not available on this host, commonly because it is listed in disable_functions.', $target_path, $link_target )
			);
		}

		// Remove anything pre-existing at the link path so symlink() will succeed.
		if ( is_link( $target_path ) || file_exists( $target_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Restore-time cleanup of conflicting filesystem entry; WP_Filesystem cannot remove symlinks reliably.
			@unlink( $target_path );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_symlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Restore-time filesystem write; WP_Filesystem has no symlink primitive.
		if ( ! @symlink( $link_target, $target_path ) ) {
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $target_path and $link_target are reported verbatim for diagnostic context; exception path, not HTML output.
				sprintf( 'Could not create symlink "%s" -> "%s".', $target_path, $link_target )
			);
		}
	}

	/**
	 * Whether a symlink's target would resolve outside the restore root.
	 *
	 * An absolute target is always treated as escaping (it is not constrained to
	 * the root). A relative target is resolved against the link's own directory
	 * and its `.`/`..` segments collapsed textually (the target need not exist
	 * yet, so realpath cannot be used); the result must be the root itself or a
	 * path beneath it.
	 *
	 * @param string $link_path   Absolute path where the link will be created (inside the root).
	 * @param string $link_target The target string recorded in the archive.
	 * @return bool True if the target escapes the restore root.
	 */
	private function symlink_target_escapes_root( string $link_path, string $link_target ): bool {
		if ( self::is_absolute_path( $link_target ) ) {
			return true;
		}

		$resolved = self::normalise_path( dirname( $link_path ) . '/' . $link_target );

		return $resolved !== $this->destination_root
			&& ! str_starts_with( $resolved, $this->destination_root . '/' );
	}

	/**
	 * Collapse `.` and `..` segments in a path textually (no filesystem access).
	 *
	 * Backslashes are normalised to forward slashes first, so a Windows-shaped
	 * target is handled on a POSIX host. A leading slash is preserved. A `..`
	 * that would rise above the first segment is simply dropped.
	 *
	 * @param string $path The path to normalise.
	 * @return string The normalised path.
	 */
	private static function normalise_path( string $path ): string {
		$is_absolute = '' !== $path && '/' === $path[0];
		$segments    = explode( '/', str_replace( '\\', '/', $path ) );
		$stack       = array();

		foreach ( $segments as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				array_pop( $stack );
				continue;
			}
			$stack[] = $segment;
		}

		return ( $is_absolute ? '/' : '' ) . implode( '/', $stack );
	}

	/**
	 * Whether the given path is absolute by POSIX or Windows conventions.
	 *
	 * Accepts Windows-style absolute paths ("C:\\..." or "\\\\...")
	 * as well as POSIX ("/...") so the same check works on every
	 * host the plugin is likely to run on.
	 *
	 * @param string $path The path to inspect.
	 * @return bool True if $path is absolute.
	 */
	private static function is_absolute_path( string $path ): bool {
		if ( '' === $path ) {
			return false;
		}
		if ( '/' === $path[0] || '\\' === $path[0] ) {
			return true;
		}
		// Windows drive-letter form: C:\, D:\, etc.
		if ( strlen( $path ) >= 3 && ctype_alpha( $path[0] ) && ':' === $path[1] && ( '\\' === $path[2] || '/' === $path[2] ) ) {
			return true;
		}
		return false;
	}
}
