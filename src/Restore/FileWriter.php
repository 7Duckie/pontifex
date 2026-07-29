<?php
/**
 * Pontifex file writer — restores one decoded archive entry to the filesystem.
 *
 * @package Pontifex\Restore
 */

declare(strict_types=1);

namespace Pontifex\Restore;

use InvalidArgumentException;
use RuntimeException;
use Pontifex\Archive\Format\EntryHeader;
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
 * the link itself is placed; the symlink's TARGET is stored as-is
 * because choosing to follow the link is up to whoever later
 * opens the restored tree.
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
	 * Construct a FileWriter rooted at the given destination directory.
	 *
	 * The destination is created (with mode 0755) if it does not yet
	 * exist. Once created, the absolute, real path is stored so
	 * subsequent path-traversal checks can use string comparison.
	 *
	 * @param string      $destination_root      Absolute filesystem path of the restore root.
	 * @param bool        $allow_unsafe_symlinks  Optional. Allow symlink targets that escape the root (default false).
	 * @param string|null $required_prefix        Optional. When set (e.g. "wp-content"), refuse any entry whose path is not the prefix itself or beneath it; null (default) allows any path. Any trailing slash is trimmed.
	 * @throws InvalidArgumentException If $destination_root is empty or not absolute.
	 * @throws RuntimeException         If the destination cannot be created or its real path cannot be resolved.
	 */
	public function __construct( string $destination_root, bool $allow_unsafe_symlinks = false, ?string $required_prefix = null ) {
		$this->allow_unsafe_symlinks = $allow_unsafe_symlinks;
		$this->required_prefix       = null === $required_prefix ? null : rtrim( $required_prefix, '/' );

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
