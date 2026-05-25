<?php
/**
 * Pontifex manifest scanned database chunk — one slice of one table queued for archival.
 *
 * @package Pontifex\Manifest
 */

declare(strict_types=1);

namespace Pontifex\Manifest;

use InvalidArgumentException;
use RuntimeException;

/**
 * Immutable value object describing one database chunk found during a scan.
 *
 * Produced by {@see DatabaseScanner::scan()}, consumed by
 * ManifestBuilder (commit 12) to construct EntryHeaders and
 * EntryPlans for db_chunk entries. Mirrors {@see ScannedEntry}'s role
 * on the database side.
 *
 * Fields mirror the shape EntryHeader::for_db_chunk() needs, so
 * ManifestBuilder can construct a db_chunk EntryHeader trivially:
 *
 *  - table_name      — predominant table this chunk covers.
 *  - chunk_index     — 0-based ordinal of this chunk within the table.
 *                      Chunk 0 of each table carries the schema
 *                      (DROP+CREATE) in addition to its rows.
 *  - statement_count — number of SQL statements this chunk would
 *                      execute when imported. Used for progress
 *                      reporting and EntryHeader metadata.
 *  - byte_count      — predicted byte size of the encoded SQL on
 *                      disk before any codec runs. Predicted at scan
 *                      time and may differ slightly from the actual
 *                      bytes produced at write time.
 *  - sql_provider    — callable that, when invoked, returns a
 *                      readable stream resource containing the
 *                      chunk's SQL bytes. Stored as a closure rather
 *                      than as raw bytes so the scanner can return
 *                      many gigabytes worth of chunks without
 *                      holding all the SQL in memory.
 *
 * The callable contract: invoked once per call to
 * {@see ScannedDbChunk::open_sql_stream()}, must return a freshly
 * opened readable stream resource positioned at offset 0. Caller is
 * responsible for closing the returned resource.
 */
final class ScannedDbChunk {

	/**
	 * Predominant table this chunk covers.
	 *
	 * @var string
	 */
	private string $table_name;

	/**
	 * 0-based ordinal of this chunk within its table.
	 *
	 * @var int
	 */
	private int $chunk_index;

	/**
	 * Number of SQL statements the chunk would execute on import.
	 *
	 * @var int
	 */
	private int $statement_count;

	/**
	 * Predicted byte count of the chunk's SQL on disk before encoding.
	 *
	 * @var int
	 */
	private int $byte_count;

	/**
	 * Callable producing a fresh readable stream resource on demand.
	 *
	 * @var callable
	 */
	private $sql_provider;

	/**
	 * Construct a ScannedDbChunk with explicit field values.
	 *
	 * @param string   $table_name      Predominant table; must be non-empty.
	 * @param int      $chunk_index     0-based chunk ordinal; must be non-negative.
	 * @param int      $statement_count SQL statement count; must be non-negative.
	 * @param int      $byte_count      Predicted SQL byte count; must be non-negative.
	 * @param callable $sql_provider    Producer of a readable stream resource on demand.
	 * @throws InvalidArgumentException If any argument is out of range or empty.
	 */
	public function __construct(
		string $table_name,
		int $chunk_index,
		int $statement_count,
		int $byte_count,
		callable $sql_provider
	) {
		if ( '' === $table_name ) {
			throw new InvalidArgumentException( 'ScannedDbChunk: table_name must be non-empty.' );
		}
		if ( $chunk_index < 0 ) {
			throw new InvalidArgumentException(
				sprintf( 'ScannedDbChunk: chunk_index %d must be non-negative.', (int) $chunk_index )
			);
		}
		if ( $statement_count < 0 ) {
			throw new InvalidArgumentException(
				sprintf( 'ScannedDbChunk: statement_count %d must be non-negative.', (int) $statement_count )
			);
		}
		if ( $byte_count < 0 ) {
			throw new InvalidArgumentException(
				sprintf( 'ScannedDbChunk: byte_count %d must be non-negative.', (int) $byte_count )
			);
		}

		$this->table_name      = $table_name;
		$this->chunk_index     = $chunk_index;
		$this->statement_count = $statement_count;
		$this->byte_count      = $byte_count;
		$this->sql_provider    = $sql_provider;
	}

	/**
	 * Return the table name.
	 *
	 * @return string The predominant table for this chunk.
	 */
	public function table_name(): string {
		return $this->table_name;
	}

	/**
	 * Return the chunk index.
	 *
	 * @return int The 0-based ordinal within the table.
	 */
	public function chunk_index(): int {
		return $this->chunk_index;
	}

	/**
	 * Return the statement count.
	 *
	 * @return int The number of SQL statements this chunk would execute.
	 */
	public function statement_count(): int {
		return $this->statement_count;
	}

	/**
	 * Return the predicted byte count.
	 *
	 * @return int The predicted SQL byte count before encoding.
	 */
	public function byte_count(): int {
		return $this->byte_count;
	}

	/**
	 * Materialise the SQL bytes for this chunk as a fresh stream resource.
	 *
	 * Each call invokes the underlying sql_provider, which produces a
	 * new readable stream positioned at offset 0. The caller owns the
	 * returned resource and is responsible for closing it. Producing
	 * the stream lazily lets the scanner enumerate thousands of
	 * chunks without holding the SQL itself in memory.
	 *
	 * @return resource A readable stream resource containing this chunk's SQL.
	 * @throws RuntimeException If the underlying provider does not return a stream resource.
	 */
	public function open_sql_stream() {
		$stream = ( $this->sql_provider )();
		if ( ! is_resource( $stream ) ) {
			throw new RuntimeException(
				sprintf(
					'ScannedDbChunk: sql_provider for table "%s" chunk %d did not return a stream resource.',
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $this->table_name reported verbatim in exception message for diagnostic context; not HTML output.
					$this->table_name,
					(int) $this->chunk_index
				)
			);
		}
		return $stream;
	}
}
