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
 * Carries the two facts a caller needs after a successful write: how many bytes
 * were written to disk, and how many entries the archive contains. The CLI
 * export uses both for its summary line and its stored counters; the safety
 * archiver ignores them. Returning a small typed object rather than a bare
 * integer leaves room to report more later without changing the method signature.
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
	 * Construct an ExportResult.
	 *
	 * @param int $bytes_written Total bytes written to the destination; must not be negative.
	 * @param int $entry_count   Number of entries written; must not be negative.
	 * @throws InvalidArgumentException If either count is negative.
	 */
	public function __construct( int $bytes_written, int $entry_count ) {
		if ( $bytes_written < 0 ) {
			throw new InvalidArgumentException( 'ExportResult: bytes_written must not be negative.' );
		}
		if ( $entry_count < 0 ) {
			throw new InvalidArgumentException( 'ExportResult: entry_count must not be negative.' );
		}

		$this->bytes_written = $bytes_written;
		$this->entry_count   = $entry_count;
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
}
