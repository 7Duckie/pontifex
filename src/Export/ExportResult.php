<?php
/**
 * Pontifex export result — the outcome of a completed export.
 *
 * @package Pontifex\Export
 */

declare(strict_types=1);

namespace Pontifex\Export;

use InvalidArgumentException;

/**
 * Immutable value object describing what {@see ExportRunner::export()} produced.
 *
 * Carries the facts a caller needs after a successful write: how many bytes
 * were written to disk, how many entries the archive contains, which files
 * (if any) changed while they were being read, and how many file entries'
 * media_type could not be genuinely determined at write time (see
 * {@see \Pontifex\Archive\Writer\EntryWriter::sniff_media_type()}). The CLI
 * export uses these for its summary line, its stored counters, and its
 * warnings; the safety archiver ignores them. Returning a small typed object
 * rather than a bare integer leaves room to report more later without
 * changing the method signature.
 */
final class ExportResult {

	/**
	 * Total bytes written to the destination.
	 *
	 * @var int
	 */
	private int $bytes_written;

	/**
	 * Number of entries written into the archive.
	 *
	 * @var int
	 */
	private int $entry_count;

	/**
	 * Files whose content changed between the scan and the write.
	 *
	 * Each element records the path, the size the scan had declared, and the
	 * byte count the archive actually captured. The archive itself records the
	 * captured size (the truth), so these are a report for the user — the files
	 * were moving while the backup ran, and a re-run may be wanted — not a
	 * defect in the archive.
	 *
	 * @var array<int, array{path: string, declared_size: int, actual_size: int}>
	 */
	private array $changed_files;

	/**
	 * Number of file entries whose media_type could not be genuinely determined.
	 *
	 * See {@see \Pontifex\Archive\Writer\EntryWriter::sniff_media_type()} for the
	 * four ways this can happen. The archive itself is unaffected — each such
	 * entry still recorded the generic fallback media_type and restores
	 * normally — but a systemic failure (fileinfo missing on this host, say)
	 * would otherwise be silently indistinguishable from ordinary
	 * unidentifiable files.
	 *
	 * @var int
	 */
	private int $media_type_unresolved_count;

	/**
	 * Construct an ExportResult.
	 *
	 * @param int                                                                   $bytes_written               Total bytes written to the destination; must not be negative.
	 * @param int                                                                   $entry_count                 Number of entries written; must not be negative.
	 * @param array<int, array{path: string, declared_size: int, actual_size: int}> $changed_files               Files whose content changed between scan and write; empty when none did.
	 * @param int                                                                   $media_type_unresolved_count Number of file entries whose media_type could not be genuinely determined; defaults to zero.
	 * @throws InvalidArgumentException If any count is negative.
	 */
	public function __construct( int $bytes_written, int $entry_count, array $changed_files = array(), int $media_type_unresolved_count = 0 ) {
		if ( $bytes_written < 0 ) {
			throw new InvalidArgumentException( 'bytes_written must not be negative.' );
		}
		if ( $entry_count < 0 ) {
			throw new InvalidArgumentException( 'entry_count must not be negative.' );
		}
		if ( $media_type_unresolved_count < 0 ) {
			throw new InvalidArgumentException( 'media_type_unresolved_count must not be negative.' );
		}

		$this->bytes_written               = $bytes_written;
		$this->entry_count                 = $entry_count;
		$this->changed_files               = array_values( $changed_files );
		$this->media_type_unresolved_count = $media_type_unresolved_count;
	}

	/**
	 * Return the total bytes written to the destination.
	 *
	 * @return int The byte count.
	 */
	public function bytes_written(): int {
		return $this->bytes_written;
	}

	/**
	 * Return the number of entries written into the archive.
	 *
	 * @return int The entry count.
	 */
	public function entry_count(): int {
		return $this->entry_count;
	}

	/**
	 * Return the files whose content changed between the scan and the write.
	 *
	 * @return array<int, array{path: string, declared_size: int, actual_size: int}> The changed files; empty when none did.
	 */
	public function changed_files(): array {
		return $this->changed_files;
	}

	/**
	 * Return how many file entries' media_type could not be genuinely determined.
	 *
	 * @return int The non-negative count; zero when every sniffed entry resolved, or none needed sniffing.
	 */
	public function media_type_unresolved_count(): int {
		return $this->media_type_unresolved_count;
	}
}
