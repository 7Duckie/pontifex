<?php
/**
 * Pontifex manifest scanned entry — one item found during a filesystem scan.
 *
 * @package Pontifex\Manifest
 */

declare(strict_types=1);

namespace Pontifex\Manifest;

use InvalidArgumentException;
use Pontifex\Archive\Format\EntryHeader;

/**
 * Immutable value object describing one item found during a filesystem scan.
 *
 * Produced by {@see FileScanner::scan()}, consumed by ManifestBuilder
 * (commit 12) to construct EntryHeaders and EntryPlans. ScannedEntry
 * sits between filesystem enumeration and archive construction: it
 * carries enough information to (a) decide whether to include the
 * entry in the archive and (b) read its contents at archive-write
 * time, without ever entering the archive itself — only relative_path
 * does.
 *
 * Fields mirror the shape EntryHeader's factories need, using the
 * EntryHeader::KIND_* constants directly so there is no translation
 * layer between scanner output and EntryHeader input.
 *
 *  - kind          — one of EntryHeader::KIND_FILE, KIND_DIRECTORY,
 *                    KIND_SYMLINK. (KIND_DB_CHUNK is produced by
 *                    DatabaseScanner, not by FileScanner.)
 *  - relative_path — path within the scan root, with forward slashes,
 *                    no leading slash. This is what ends up in the
 *                    archive.
 *  - absolute_path — full filesystem path on the host. Used at write
 *                    time to open the file; never written to the
 *                    archive.
 *  - size          — byte size for files; 0 for directories and
 *                    symlinks (symlinks store their target as the
 *                    payload, sized separately by the writer).
 *  - mode          — POSIX mode bits in the low 12 bits (0..4095).
 *  - mtime         — Unix modification timestamp in seconds.
 *  - target        — for symlinks: the path the symlink points to,
 *                    stored verbatim as returned by the filesystem.
 *                    Null for files and directories.
 */
final class ScannedEntry {

	/**
	 * Entry kind; one of EntryHeader::KIND_FILE, KIND_DIRECTORY, KIND_SYMLINK.
	 *
	 * @var string
	 */
	private string $kind;

	/**
	 * Path relative to the scan root, forward-slashed, no leading slash.
	 *
	 * @var string
	 */
	private string $relative_path;

	/**
	 * Absolute filesystem path on the host.
	 *
	 * @var string
	 */
	private string $absolute_path;

	/**
	 * Byte size; 0 for non-file kinds.
	 *
	 * @var int
	 */
	private int $size;

	/**
	 * POSIX mode bits, in the range 0..MAX_POSIX_MODE inclusive.
	 *
	 * @var int
	 */
	private int $mode;

	/**
	 * Unix modification timestamp in seconds; non-negative.
	 *
	 * @var int
	 */
	private int $mtime;

	/**
	 * Symlink target; non-null for symlinks only.
	 *
	 * @var string|null
	 */
	private ?string $target;

	/**
	 * MIME type sniffed at scan time; non-null for files only.
	 *
	 * Captured by FileScanner via finfo_file() and passed through
	 * ManifestBuilder to EntryHeader::for_file(). For directory and
	 * symlink entries this is always null.
	 *
	 * @var string|null
	 */
	private ?string $media_type;

	/**
	 * Construct a ScannedEntry with explicit field values.
	 *
	 * @param string      $kind          One of EntryHeader::KIND_FILE, KIND_DIRECTORY, KIND_SYMLINK.
	 * @param string      $relative_path Path relative to the scan root; must be non-empty.
	 * @param string      $absolute_path Absolute filesystem path; must be non-empty.
	 * @param int         $size          Byte size; must be non-negative. Typically 0 for non-file kinds.
	 * @param int         $mode          POSIX mode bits; must be in 0..EntryHeader::MAX_POSIX_MODE inclusive.
	 * @param int         $mtime         Unix modification timestamp; must be non-negative.
	 * @param string|null $target        Symlink target; must be non-null and non-empty for KIND_SYMLINK; must be null for other kinds.
	 * @param string|null $media_type    MIME type; must be non-null and non-empty for KIND_FILE; must be null for other kinds.
	 * @throws InvalidArgumentException If any argument is out of range, empty, or inconsistent with the kind.
	 */
	public function __construct(
		string $kind,
		string $relative_path,
		string $absolute_path,
		int $size,
		int $mode,
		int $mtime,
		?string $target = null,
		?string $media_type = null
	) {
		$allowed_kinds = array( EntryHeader::KIND_FILE, EntryHeader::KIND_DIRECTORY, EntryHeader::KIND_SYMLINK );
		if ( ! in_array( $kind, $allowed_kinds, true ) ) {
			throw new InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $kind is a validated constant from a closed set; exception path, not HTML output.
				sprintf( 'ScannedEntry: kind "%s" is not one of file, directory, symlink.', $kind )
			);
		}
		if ( '' === $relative_path ) {
			throw new InvalidArgumentException( 'ScannedEntry: relative_path must be non-empty.' );
		}
		if ( '' === $absolute_path ) {
			throw new InvalidArgumentException( 'ScannedEntry: absolute_path must be non-empty.' );
		}
		if ( $size < 0 ) {
			throw new InvalidArgumentException(
				sprintf( 'ScannedEntry: size %d must be non-negative.', (int) $size )
			);
		}
		if ( $mode < 0 || $mode > EntryHeader::MAX_POSIX_MODE ) {
			throw new InvalidArgumentException(
				sprintf( 'ScannedEntry: mode %d is outside the valid POSIX range (0 to 4095).', (int) $mode )
			);
		}
		if ( $mtime < 0 ) {
			throw new InvalidArgumentException(
				sprintf( 'ScannedEntry: mtime %d must be non-negative.', (int) $mtime )
			);
		}
		if ( EntryHeader::KIND_SYMLINK === $kind ) {
			if ( null === $target || '' === $target ) {
				throw new InvalidArgumentException( 'ScannedEntry: symlink entries must have a non-empty target.' );
			}
		} elseif ( null !== $target ) {
			throw new InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $kind is a validated constant from a closed set; exception path, not HTML output.
				sprintf( 'ScannedEntry: target may only be set for symlink entries; got kind "%s".', $kind )
			);
		}
		if ( EntryHeader::KIND_FILE === $kind ) {
			if ( null === $media_type || '' === $media_type ) {
				throw new InvalidArgumentException( 'ScannedEntry: file entries must have a non-empty media_type.' );
			}
		} elseif ( null !== $media_type ) {
			throw new InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $kind is a validated constant from a closed set; exception path, not HTML output.
				sprintf( 'ScannedEntry: media_type may only be set for file entries; got kind "%s".', $kind )
			);
		}

		$this->kind          = $kind;
		$this->relative_path = $relative_path;
		$this->absolute_path = $absolute_path;
		$this->size          = $size;
		$this->mode          = $mode;
		$this->mtime         = $mtime;
		$this->target        = $target;
		$this->media_type    = $media_type;
	}

	/**
	 * Return the entry kind.
	 *
	 * @return string One of EntryHeader::KIND_FILE, KIND_DIRECTORY, KIND_SYMLINK.
	 */
	public function kind(): string {
		return $this->kind;
	}

	/**
	 * Return the path relative to the scan root.
	 *
	 * @return string Forward-slashed, no leading slash.
	 */
	public function relative_path(): string {
		return $this->relative_path;
	}

	/**
	 * Return the absolute filesystem path.
	 *
	 * @return string The host-OS absolute path.
	 */
	public function absolute_path(): string {
		return $this->absolute_path;
	}

	/**
	 * Return the byte size.
	 *
	 * @return int The non-negative byte size.
	 */
	public function size(): int {
		return $this->size;
	}

	/**
	 * Return the POSIX mode bits.
	 *
	 * @return int The mode in the range 0..MAX_POSIX_MODE.
	 */
	public function mode(): int {
		return $this->mode;
	}

	/**
	 * Return the Unix modification timestamp.
	 *
	 * @return int Seconds since the epoch, non-negative.
	 */
	public function mtime(): int {
		return $this->mtime;
	}

	/**
	 * Return the symlink target, or null for non-symlink entries.
	 *
	 * @return string|null The target path for symlinks; null otherwise.
	 */
	public function target(): ?string {
		return $this->target;
	}

	/**
	 * Return the MIME type, or null for non-file entries.
	 *
	 * Captured at scan time by FileScanner; non-null for file
	 * entries and null for directory and symlink entries.
	 *
	 * @return string|null The MIME type for file entries; null otherwise.
	 */
	public function media_type(): ?string {
		return $this->media_type;
	}

	/**
	 * Whether this entry is a regular file.
	 *
	 * @return bool True if the kind is KIND_FILE.
	 */
	public function is_file(): bool {
		return EntryHeader::KIND_FILE === $this->kind;
	}

	/**
	 * Whether this entry is a directory.
	 *
	 * @return bool True if the kind is KIND_DIRECTORY.
	 */
	public function is_directory(): bool {
		return EntryHeader::KIND_DIRECTORY === $this->kind;
	}

	/**
	 * Whether this entry is a symbolic link.
	 *
	 * @return bool True if the kind is KIND_SYMLINK.
	 */
	public function is_symlink(): bool {
		return EntryHeader::KIND_SYMLINK === $this->kind;
	}
}
