<?php
/**
 * Pontifex file writer — restores one decoded archive entry to the filesystem.
 *
 * @package Pontifex\Restore
 */

declare(strict_types=1);

namespace Pontifex\Restore;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Format\ManifestEntry;
use Pontifex\Archive\Reader\EntryReadResult;

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
	 * @throws InvalidArgumentException If $destination_root is empty or not absolute.
	 * @throws RuntimeException         If the destination cannot be created or its real path cannot be resolved.
	 */
	public function __construct( string $destination_root, bool $allow_unsafe_symlinks = false, ?string $required_prefix = null, ?callable $disk_free_space = null ) {
		$this->allow_unsafe_symlinks = $allow_unsafe_symlinks;
		$this->required_prefix       = null === $required_prefix ? null : rtrim( $required_prefix, '/' );
		$this->disk_free_space       = null !== $disk_free_space
			? Closure::fromCallable( $disk_free_space )
			: static function ( string $path ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- disk_free_space can be disabled or restricted by the host (e.g. open_basedir); the guard is best-effort, matching UploadController::refuse_if_no_room(), and its failure must not block a restore that could otherwise succeed.
				return @disk_free_space( $path );
			};

		if ( '' === $destination_root ) {
			throw new InvalidArgumentException( 'FileWriter: destination_root must be non-empty.' );
		}
		if ( ! self::is_absolute_path( $destination_root ) ) {
			throw new InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $destination_root is reported verbatim for diagnostic context; exception path, not HTML output.
				sprintf( 'FileWriter: destination_root "%s" must be an absolute path.', $destination_root )
			);
		}

		if ( ! is_dir( $destination_root ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir,WordPress.PHP.NoSilencedErrors.Discouraged -- Restore-time filesystem write; WP_Filesystem is unavailable in CLI/non-WP contexts where this code may run.
			if ( ! @mkdir( $destination_root, self::PARENT_DIR_MODE, true ) && ! is_dir( $destination_root ) ) {
				throw new RuntimeException(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $destination_root is reported verbatim for diagnostic context; exception path, not HTML output.
					sprintf( 'FileWriter: could not create destination_root "%s".', $destination_root )
				);
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_realpath -- Used to normalise paths for the path-traversal check.
		$real = realpath( $destination_root );
		if ( false === $real ) {
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $destination_root is reported verbatim for diagnostic context; exception path, not HTML output.
				sprintf( 'FileWriter: could not resolve real path of destination_root "%s".', $destination_root )
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
			throw new InvalidArgumentException( 'FileWriter: db_chunk entries must be written through DatabaseWriter, not FileWriter.' );
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
			sprintf( 'FileWriter: unsupported entry kind "%s".', $header->kind() )
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
	 * @throws RuntimeException If free space is known and smaller than what this restore needs.
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
			throw new RuntimeException(
				sprintf(
					'FileWriter: the restore was stopped before changing anything, because there is not enough free disk space at "%s". It needs about %d MB free, and only %d MB is available. Free up some space and try again.',
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $this->destination_root is plugin-derived, not web output; reported verbatim for diagnostic context.
					$this->destination_root,
					(int) ceil( $needed / self::BYTES_PER_MEGABYTE ),
					(int) floor( $free / self::BYTES_PER_MEGABYTE )
				)
			);
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
	 * @throws RuntimeException If any declared link's target resolves somewhere this restore will not allow.
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
	 * @throws RuntimeException If the target is absolute, loops, cannot be resolved, or lands somewhere refused.
	 */
	private function assert_symlink_target_confined( string $link_path, string $raw_target, array $exact, array $folded ): void {
		if ( self::is_absolute_path( $raw_target ) ) {
			$message = sprintf(
				'FileWriter: refusing symlink "%s": its target "%s" is an absolute path, so it is not confined to this site at all. Re-run with --allow-unsafe-symlinks only if you trust this archive.',
				$link_path,
				$raw_target
			);
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $message quotes the archive's own link path and target for diagnostic context; exception path, not HTML output.
			throw new RuntimeException( $message );
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
					'FileWriter: refusing symlink "%s": resolving its target "%s" passed through more than %d symlinks, so the archive contains a symlink loop or a chain no filesystem would follow.',
					$link_path,
					$raw_target,
					self::MAX_SYMLINK_HOPS
				);
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $message quotes the archive's own link path and target for diagnostic context; exception path, not HTML output.
				throw new RuntimeException( $message );
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
	 * @throws RuntimeException If two declared spellings of one path disagree, or an on-disk link cannot be read.
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
						'FileWriter: refusing symlink "%s": resolving its target "%s" reaches "%s", which the archive declares more than once with different targets, differing only in letter case. Which one a destination filesystem would keep cannot be decided, so this archive is refused.',
						$link_path,
						$raw_target,
						$relative
					);
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $message quotes the archive's own link paths and targets for diagnostic context; exception path, not HTML output.
					throw new RuntimeException( $message );
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
				'FileWriter: refusing symlink "%s": resolving its target "%s" reaches the existing symlink "%s", whose own target could not be read, so where this link would point cannot be established.',
				$link_path,
				$raw_target,
				$absolute
			);
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $message quotes the archive's own link path and target plus a plugin-derived path, for diagnostic context; exception path, not HTML output.
			throw new RuntimeException( $message );
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
	 * @throws RuntimeException If the resolved target is not a strict descendant of the root, or is one of the named locations.
	 */
	private function assert_resolved_target_confined( string $link_path, string $raw_target, array $resolved, array $root_components ): void {
		$root_depth = count( $root_components );
		$absolute   = self::absolute_from_components( $resolved );

		if ( count( $resolved ) <= $root_depth || array_slice( $resolved, 0, $root_depth ) !== $root_components ) {
			$message = sprintf(
				'FileWriter: refusing symlink "%s": its target "%s" resolves to "%s", which is not inside the restore root "%s". Re-run with --allow-unsafe-symlinks only if you trust this archive.',
				$link_path,
				$raw_target,
				$absolute,
				$this->destination_root
			);
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $message quotes the archive's own link path and target plus plugin-derived paths, for diagnostic context; exception path, not HTML output.
			throw new RuntimeException( $message );
		}

		foreach ( $this->wp_config_paths() as $wp_config_path ) {
			if ( $absolute !== $wp_config_path ) {
				continue;
			}
			$message = sprintf(
				'FileWriter: refusing symlink "%s": its target "%s" resolves to "%s", this site\'s own wp-config.php, which holds the database password and the authentication salts. Re-run with --allow-unsafe-symlinks only if you trust this archive.',
				$link_path,
				$raw_target,
				$absolute
			);
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $message quotes the archive's own link path and target plus a plugin-derived path, for diagnostic context; exception path, not HTML output.
			throw new RuntimeException( $message );
		}

		$relative = implode( '/', array_slice( $resolved, $root_depth ) );
		if ( self::is_pontifex_working_path( $relative, $this->destination_is_case_sensitive() ) ) {
			$message = sprintf(
				'FileWriter: refusing symlink "%s": its target "%s" resolves to "%s", inside Pontifex\'s own working directory, where this site\'s stored backups live. Re-run with --allow-unsafe-symlinks only if you trust this archive.',
				$link_path,
				$raw_target,
				$absolute
			);
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $message quotes the archive's own link path and target plus a plugin-derived path, for diagnostic context; exception path, not HTML output.
			throw new RuntimeException( $message );
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
			throw new InvalidArgumentException( 'FileWriter: entry path must be non-empty.' );
		}
		if ( false !== strpos( $relative_path, "\0" ) ) {
			throw new InvalidArgumentException( 'FileWriter: entry path contains a null byte.' );
		}
		if ( self::is_absolute_path( $relative_path ) ) {
			throw new InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $relative_path is reported verbatim for diagnostic context; exception path, not HTML output.
				sprintf( 'FileWriter: entry path "%s" must be relative, not absolute.', $relative_path )
			);
		}

		$segments = explode( '/', $relative_path );
		foreach ( $segments as $segment ) {
			if ( '..' === $segment ) {
				throw new InvalidArgumentException(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $relative_path is reported verbatim for diagnostic context; exception path, not HTML output.
					sprintf( 'FileWriter: normalise_entry_path refuses entry path "%s": it contains a parent-directory segment.', $relative_path )
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
				sprintf( 'FileWriter: entry path "%s" normalises to an empty path (e.g. ".", "./", or a run of such segments) and is refused.', $relative_path )
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
			sprintf( 'FileWriter: entry path "%s" is outside the permitted "%s" tree and is refused by this content-only restore.', $relative_path, $this->required_prefix )
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
			sprintf( 'FileWriter: entry path "%s" targets Pontifex\'s own working directory and is refused; no legitimate archive ever contains one.', $relative_path )
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
			throw new InvalidArgumentException( 'FileWriter: entry path must be non-empty.' );
		}
		if ( false !== strpos( $relative_path, "\0" ) ) {
			throw new InvalidArgumentException( 'FileWriter: entry path contains a null byte.' );
		}
		if ( self::is_absolute_path( $relative_path ) ) {
			throw new InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $relative_path is reported verbatim for diagnostic context; exception path, not HTML output.
				sprintf( 'FileWriter: entry path "%s" must be relative, not absolute.', $relative_path )
			);
		}

		$segments = explode( '/', str_replace( '\\', '/', $relative_path ) );
		foreach ( $segments as $segment ) {
			if ( '..' === $segment ) {
				throw new InvalidArgumentException(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $relative_path is reported verbatim for diagnostic context; exception path, not HTML output.
					sprintf( 'FileWriter: entry path "%s" contains a parent-directory segment.', $relative_path )
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
					sprintf( 'FileWriter: entry path "%s" descends through a symlink and is refused.', $relative_path )
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
				sprintf( 'FileWriter: could not create parent directory "%s".', $parent )
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
				sprintf( 'FileWriter: could not write file "%s".', $target_path )
			);
		}
		$this->finalise_temp( $temp_path, $target_path, $mode, $mtime );
	}

	/**
	 * Build the sibling temp path a file is written to before its atomic rename.
	 *
	 * A sibling of the target (same directory), so the final rename is a
	 * same-filesystem move; a unique suffix keeps concurrent writers apart.
	 *
	 * @param string $target_path The final file path.
	 * @return string The temp path to write to first.
	 */
	private static function temp_sibling_path( string $target_path ): string {
		return $target_path . '.' . uniqid( 'pontifex-', true ) . '.tmp';
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
				sprintf( 'FileWriter: could not chmod file "%s".', $target_path )
			);
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch,WordPress.PHP.NoSilencedErrors.Discouraged -- Restore-time filesystem write; WP_Filesystem cannot preserve mtime.
		if ( ! @touch( $temp_path, $mtime ) ) {
			$this->discard_temp( $temp_path );
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $target_path is reported verbatim for diagnostic context; exception path, not HTML output.
				sprintf( 'FileWriter: could not set mtime on file "%s".', $target_path )
			);
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename,WordPress.PHP.NoSilencedErrors.Discouraged -- Atomically moving the completed file into place (a same-directory move); WP_Filesystem is unavailable in CLI/non-WP contexts where this code may run.
		if ( ! @rename( $temp_path, $target_path ) ) {
			$this->discard_temp( $temp_path );
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $target_path is reported verbatim for diagnostic context; exception path, not HTML output.
				sprintf( 'FileWriter: could not move file into place at "%s".', $target_path )
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
				sprintf( 'FileWriter: could not write file "%s".', $target_path )
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
				sprintf( 'FileWriter: could not write file "%s".', $target_path )
			);
		}
		$this->finalise_temp( $temp_path, $target_path, $mode, $mtime );
	}

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
		if ( ! is_dir( $target_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir,WordPress.PHP.NoSilencedErrors.Discouraged -- Restore-time filesystem write; WP_Filesystem is unavailable in CLI/non-WP contexts where this code may run.
			if ( ! @mkdir( $target_path, $mode, true ) && ! is_dir( $target_path ) ) {
				throw new RuntimeException(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $target_path is reported verbatim for diagnostic context; exception path, not HTML output.
					sprintf( 'FileWriter: could not create directory "%s".', $target_path )
				);
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod,WordPress.PHP.NoSilencedErrors.Discouraged -- Restore-time filesystem write; WP_Filesystem cannot preserve POSIX mode bits.
		if ( ! @chmod( $target_path, $mode ) ) {
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $target_path is reported verbatim for diagnostic context; exception path, not HTML output.
				sprintf( 'FileWriter: could not chmod directory "%s".', $target_path )
			);
		}
	}

	/**
	 * Create a symlink at $target_path pointing at $link_target.
	 *
	 * Overwrites an existing symlink, file, or directory at the link path. Unless
	 * unsafe symlinks are explicitly allowed, a target that resolves outside the
	 * restore root (or is absolute) is refused — a hostile archive could otherwise
	 * plant an escaping link that later code follows.
	 *
	 * @param string $target_path Absolute path where the link should be created.
	 * @param string $link_target The string the link should point at.
	 * @throws RuntimeException If the target escapes the root (and is not allowed), or the link cannot be created.
	 */
	private function write_symlink( string $target_path, string $link_target ): void {
		if ( ! $this->allow_unsafe_symlinks && $this->symlink_target_escapes_root( $target_path, $link_target ) ) {
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $target_path and $link_target are reported verbatim for diagnostic context; exception path, not HTML output.
				sprintf( 'FileWriter: refusing symlink "%s" whose target "%s" escapes the restore root. Re-run with --allow-unsafe-symlinks only if you trust this archive.', $target_path, $link_target )
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
				sprintf( 'FileWriter: could not create symlink "%s" -> "%s".', $target_path, $link_target )
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
