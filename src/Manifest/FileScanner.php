<?php
/**
 * Pontifex manifest file scanner — walks a directory tree and enumerates its contents.
 *
 * @package Pontifex\Manifest
 */

declare(strict_types=1);

namespace Pontifex\Manifest;

use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Pontifex\Archive\Format\EntryHeader;

/**
 * Walks a directory tree and enumerates everything within it.
 *
 * Returns a list of {@see ScannedEntry} value objects, one per file,
 * directory, or symlink found, after applying {@see ExclusionRules}.
 * Does NOT read file contents; only stats them. Reading happens later
 * at archive-write time inside ArchiveWriter / EntryWriter.
 *
 * The scanner is deterministic: two scans of the same tree return
 * identical ScannedEntry lists in the same order. Sort order is
 * lexicographic by relative path. Deterministic output matters for
 * archive-integrity testing — two archives of the same source tree
 * must produce identical bytes.
 *
 * Symlinks are enumerated but NOT followed. The symlink itself is
 * recorded as a KIND_SYMLINK entry with its target stored verbatim;
 * the scanner does not descend into the target. This prevents
 * infinite loops on circular symlinks and matches the behaviour of
 * standard archive tools (zip, tar).
 *
 * Excluded directories are not recursed into. If ExclusionRules
 * matches a directory's relative path, the directory itself is
 * omitted from the output AND the scanner does not enter it. This is
 * a performance optimisation for common cases like wp-content/cache,
 * where the directory and its contents would all be excluded anyway.
 *
 * Unreadable paths cause a RuntimeException. Silent skipping would
 * produce an incomplete archive without the user knowing. The
 * exception names the path so the user can fix permissions or adjust
 * exclusions.
 *
 * Implementation notes (internal; not part of the stable API):
 *
 *  - Uses PHP's RecursiveDirectoryIterator + RecursiveIteratorIterator.
 *    These are part of PHP since 5.x, mature, and handle the edge
 *    cases (long paths, special filenames, UTF-8) reliably.
 *  - WP_Filesystem is intentionally NOT used. It's designed for
 *    plugin/theme writes during WordPress core operations and has
 *    poor read/walk support, no symlink awareness, and is awkward
 *    in CLI contexts.
 *  - All ScannedEntry instances are accumulated into a list and
 *    sorted in one pass at the end. For a typical WordPress site
 *    (~30k files), this is a few megabytes of in-memory object
 *    overhead, which is fine.
 */
final class FileScanner {

	/**
	 * Exclusion rules applied during scanning.
	 *
	 * @var ExclusionRules
	 */
	private ExclusionRules $exclusions;

	/**
	 * Construct a FileScanner with exclusion rules.
	 *
	 * @param ExclusionRules $exclusions Rules controlling which paths to omit. Use
	 *                                   ExclusionRules::none() to archive everything.
	 */
	public function __construct( ExclusionRules $exclusions ) {
		$this->exclusions = $exclusions;
	}

	/**
	 * Walk the given root directory and return everything found within it.
	 *
	 * The root itself is NOT included in the result; only its
	 * contents. Returned entries' relative_path is relative to the
	 * scan root (e.g. scanning "/var/www/html" yields entries with
	 * paths like "wp-config.php", not "/var/www/html/wp-config.php").
	 *
	 * May propagate a RuntimeException from internal helpers when an
	 * encountered path is unreadable, a symlink target cannot be
	 * resolved, or a filesystem item is none of file/directory/symlink.
	 *
	 * @param string $root Absolute filesystem path of the directory to scan.
	 * @return ScannedEntry[] All entries found, in stable lexicographic order by relative_path.
	 * @throws InvalidArgumentException If $root is empty or is not an existing directory.
	 */
	public function scan( string $root ): array {
		if ( '' === $root ) {
			throw new InvalidArgumentException( 'FileScanner: scan root must be non-empty.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_dir -- Filesystem read for archive enumeration; WP_Filesystem has no equivalent abstraction.
		if ( ! is_dir( $root ) ) {
			throw new InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $root is reported verbatim for diagnostic context; exception path, not HTML output.
				sprintf( 'FileScanner: scan root "%s" is not an existing directory.', $root )
			);
		}

		// Normalise root: strip trailing slashes so the slice arithmetic below is consistent.
		$normalised_root = rtrim( $root, '/\\' );
		$root_prefix_len = strlen( $normalised_root ) + 1;

		$entries = array();

		$flags = RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::UNIX_PATHS;
		$inner = new RecursiveDirectoryIterator( $normalised_root, $flags );

		// SELF_FIRST: visit a directory BEFORE its children.
		// This lets us record the directory entry and then prune the iterator if it is excluded.
		$walker = new RecursiveIteratorIterator( $inner, RecursiveIteratorIterator::SELF_FIRST );

		foreach ( $walker as $info ) {
			$absolute_path = $info->getPathname();
			$relative_path = substr( $absolute_path, $root_prefix_len );

			// Normalise relative path to forward slashes regardless of host OS.
			$relative_path = str_replace( '\\', '/', $relative_path );

			$kind = self::classify( $info, $absolute_path );

			// Structural recursion-prevention invariant: Pontifex's own working directory.
			// Always excluded regardless of the ExclusionRules configuration.
			// Prevents an existing Pontifex export from being recursively re-included in a new archive, which would produce an archive-of-archives.
			if ( self::is_pontifex_working_path( $relative_path ) ) {
				if ( EntryHeader::KIND_DIRECTORY === $kind ) {
					$walker->next();
				}
				continue;
			}

			if ( $this->exclusions->matches( $relative_path, $kind ) ) {
				// If the excluded entry is a directory, do not descend into it.
				if ( EntryHeader::KIND_DIRECTORY === $kind ) {
					$walker->next();
				}
				continue;
			}

			$entries[] = self::build_scanned_entry( $kind, $relative_path, $absolute_path, $info );
		}

		usort(
			$entries,
			static function ( ScannedEntry $a, ScannedEntry $b ): int {
				return strcmp( $a->relative_path(), $b->relative_path() );
			}
		);

		return $entries;
	}

	/**
	 * Whether the given relative path is inside Pontifex's working directory.
	 *
	 * This is a structural invariant enforced independently of
	 * {@see ExclusionRules}: regardless of which rules the caller
	 * configures, FileScanner never emits entries for Pontifex's own
	 * working directory. The point is to prevent a previous Pontifex
	 * export (which may have left files in wp-content/pontifex/) from
	 * being recursively re-included in a new archive — that would
	 * produce an archive-of-archives whose size and meaning is
	 * surprising.
	 *
	 * The match covers both the directory itself and anything
	 * beneath it:
	 *
	 *  - "wp-content/pontifex"            → excluded
	 *  - "wp-content/pontifex/logs"       → excluded
	 *  - "wp-content/pontifex/exports/x"  → excluded
	 *  - "wp-content/pontifex-foo"        → NOT excluded (different directory)
	 *
	 * @param string $relative_path Path relative to the scan root.
	 * @return bool True if the path is inside wp-content/pontifex/.
	 */
	private static function is_pontifex_working_path( string $relative_path ): bool {
		$root = 'wp-content/pontifex';
		if ( $relative_path === $root ) {
			return true;
		}
		$prefix = $root . '/';
		return 0 === strncmp( $relative_path, $prefix, strlen( $prefix ) );
	}

	/**
	 * Determine the EntryHeader kind for a filesystem item.
	 *
	 * Order matters: a symlink whose target is a directory must still
	 * be reported as a symlink (not a directory), so the symlink
	 * check comes first.
	 *
	 * @param SplFileInfo $info          The iterator's view of the item.
	 * @param string      $absolute_path The item's absolute path; reported in exceptions.
	 * @return string One of EntryHeader::KIND_FILE, KIND_DIRECTORY, KIND_SYMLINK.
	 * @throws RuntimeException If the item is none of the three recognised kinds.
	 */
	private static function classify( SplFileInfo $info, string $absolute_path ): string {
		if ( $info->isLink() ) {
			return EntryHeader::KIND_SYMLINK;
		}
		if ( $info->isDir() ) {
			return EntryHeader::KIND_DIRECTORY;
		}
		if ( $info->isFile() ) {
			return EntryHeader::KIND_FILE;
		}
		throw new RuntimeException(
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $absolute_path is reported verbatim for diagnostic context; exception path, not HTML output.
			sprintf( 'FileScanner: path "%s" is none of file, directory, or symlink; aborting scan.', $absolute_path )
		);
	}

	/**
	 * Build a ScannedEntry from a classified SplFileInfo.
	 *
	 * Reads size, mode, mtime, and (for symlinks) the link target.
	 * Throws RuntimeException if the item is not readable, since a
	 * silently-skipped file would produce an incomplete archive.
	 *
	 * @param string      $kind          The classified entry kind.
	 * @param string      $relative_path The scan-root-relative path.
	 * @param string      $absolute_path The host-absolute path.
	 * @param SplFileInfo $info          The iterator's view of the item.
	 * @return ScannedEntry A fully-populated value object.
	 * @throws RuntimeException If the item cannot be stat()ed or, for symlinks, the link target cannot be read.
	 */
	private static function build_scanned_entry(
		string $kind,
		string $relative_path,
		string $absolute_path,
		SplFileInfo $info
	): ScannedEntry {
		// Symlinks: we stat the link itself, not the target.
		// SplFileInfo's getSize/getMTime/getPerms can follow links in some PHP configurations.
		// We explicitly call lstat() to be sure we measure the link itself.
		if ( EntryHeader::KIND_SYMLINK === $kind ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readlink -- Filesystem read for archive enumeration; WP_Filesystem has no equivalent.
			$target = readlink( $absolute_path );
			if ( false === $target ) {
				throw new RuntimeException(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $absolute_path is reported verbatim for diagnostic context; exception path, not HTML output.
					sprintf( 'FileScanner: could not read symlink target for "%s".', $absolute_path )
				);
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_lstat -- Filesystem read for archive enumeration; WP_Filesystem has no equivalent.
			$lstat = lstat( $absolute_path );
			if ( false === $lstat ) {
				throw new RuntimeException(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $absolute_path is reported verbatim for diagnostic context; exception path, not HTML output.
					sprintf( 'FileScanner: could not lstat symlink "%s".', $absolute_path )
				);
			}
			$mode  = (int) ( $lstat['mode'] & 0o7777 );
			$mtime = (int) $lstat['mtime'];
			return new ScannedEntry( $kind, $relative_path, $absolute_path, 0, $mode, $mtime, $target );
		}

		// Files and directories: stat normally.
		if ( ! $info->isReadable() ) {
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $absolute_path is reported verbatim for diagnostic context; exception path, not HTML output.
				sprintf( 'FileScanner: path "%s" is not readable; check filesystem permissions or add it to ExclusionRules.', $absolute_path )
			);
		}

		$size  = EntryHeader::KIND_FILE === $kind ? (int) $info->getSize() : 0;
		$mode  = (int) ( $info->getPerms() & 0o7777 );
		$mtime = (int) $info->getMTime();

		return new ScannedEntry( $kind, $relative_path, $absolute_path, $size, $mode, $mtime, null );
	}
}
