<?php
/**
 * Pontifex manifest database scanner — enumerates database tables into chunks for archival.
 *
 * @package Pontifex\Manifest
 */

declare(strict_types=1);

namespace Pontifex\Manifest;

use InvalidArgumentException;
use RuntimeException;
use Pontifex\Archive\Format\EntryHeader;

/**
 * Walks a WordPress database and enumerates its contents as archive chunks.
 *
 * Returns a list of {@see ScannedDbChunk} value objects, one per
 * chunk of SQL that ManifestBuilder (commit 12) will turn into a
 * KIND_DB_CHUNK EntryPlan. Does NOT generate SQL eagerly: each chunk
 * carries a closure that produces SQL on demand at write time, so
 * scanning a multi-gigabyte database does not require multiple
 * gigabytes of memory.
 *
 * The scanner is deterministic: two scans of the same database
 * return identical chunk lists in the same order (alphabetical by
 * table name, then by chunk_index). Deterministic output matters
 * for archive-integrity testing.
 *
 * Chunking strategy:
 *
 *  - Each table is queried for its row count.
 *  - The first chunk for every table carries the schema (DROP TABLE
 *    IF EXISTS + CREATE TABLE) plus the first batch of rows.
 *  - Subsequent chunks carry only rows.
 *  - Chunk size is approximate. The scanner estimates rows-per-chunk
 *    by dividing the configured byte budget by an assumed average
 *    INSERT statement size; the actual byte count is verified
 *    lazily at write time. Empty tables produce one schema-only
 *    chunk so that the archive's import path always recreates the
 *    table even if it had no rows.
 *
 * Exclusions:
 *
 *  - ExclusionRules is consulted at the table level using the table
 *    name as the "relative_path" and KIND_DB_CHUNK as the kind.
 *  - Excluded tables are skipped entirely (no schema, no rows).
 *  - Sub-chunks of an included table are never separately excluded.
 *
 * Threading and reuse: DatabaseScanner is stateless after
 * construction. Safe to call scan() any number of times. Each call
 * re-queries the adapter, so result reflects the database's current
 * state at scan time.
 */
final class DatabaseScanner {

	/**
	 * Default target byte budget per chunk (4 MiB).
	 *
	 * Tuned so that even moderate tables (~10MB) split into two or
	 * three chunks, while typical small tables stay as one chunk.
	 *
	 * @var int
	 */
	public const DEFAULT_CHUNK_SIZE_BYTES = 4194304;

	/**
	 * Assumed average bytes per INSERT statement, used for row-per-chunk estimation.
	 *
	 * A conservative estimate: real INSERTs vary widely (a wp_options
	 * autoload row is ~100 bytes, a wp_posts row can be tens of
	 * kilobytes). Using ~1 KiB as the average means the scanner
	 * tends to produce slightly larger chunks than the byte budget
	 * for posts-heavy tables and slightly smaller chunks for
	 * options-heavy tables. Both are acceptable.
	 *
	 * @var int
	 */
	private const AVG_BYTES_PER_STATEMENT_ESTIMATE = 1024;

	/**
	 * Database adapter used to query tables.
	 *
	 * @var DatabaseAdapter
	 */
	private DatabaseAdapter $db;

	/**
	 * Exclusion rules applied at the table level.
	 *
	 * @var ExclusionRules
	 */
	private ExclusionRules $exclusions;

	/**
	 * Target byte budget per chunk.
	 *
	 * @var int
	 */
	private int $chunk_size_bytes;

	/**
	 * Construct a DatabaseScanner with explicit dependencies.
	 *
	 * @param DatabaseAdapter $db               Provides table listing and SQL dumping.
	 * @param ExclusionRules  $exclusions       Rules controlling which tables to omit.
	 * @param int             $chunk_size_bytes Target byte budget per chunk; must be positive.
	 *                                          Defaults to {@see DatabaseScanner::DEFAULT_CHUNK_SIZE_BYTES}.
	 * @throws InvalidArgumentException If $chunk_size_bytes is not positive.
	 */
	public function __construct(
		DatabaseAdapter $db,
		ExclusionRules $exclusions,
		int $chunk_size_bytes = self::DEFAULT_CHUNK_SIZE_BYTES
	) {
		if ( $chunk_size_bytes <= 0 ) {
			throw new InvalidArgumentException(
				sprintf( 'DatabaseScanner: chunk_size_bytes %d must be positive.', (int) $chunk_size_bytes )
			);
		}
		$this->db               = $db;
		$this->exclusions       = $exclusions;
		$this->chunk_size_bytes = $chunk_size_bytes;
	}

	/**
	 * Walk the database and return chunks ready to be archived.
	 *
	 * Returned chunks are sorted alphabetically by table name, then
	 * by chunk_index within each table.
	 *
	 * @return ScannedDbChunk[] All chunks the scanner produced.
	 * @throws RuntimeException If the database adapter signals a failure.
	 */
	public function scan(): array {
		$tables = $this->db->list_tables();
		sort( $tables, SORT_STRING );

		$chunks = array();

		foreach ( $tables as $table_name ) {
			if ( $this->exclusions->matches( $table_name, EntryHeader::KIND_DB_CHUNK ) ) {
				continue;
			}

			$row_count      = $this->db->row_count( $table_name );
			$rows_per_chunk = $this->compute_rows_per_chunk();
			$is_empty_table = 0 === $row_count;

			// Empty tables get a single schema-only chunk.
			// Non-empty tables get one chunk per rows_per_chunk batch, with the schema in chunk 0.
			$chunk_count = $is_empty_table ? 1 : (int) ceil( $row_count / $rows_per_chunk );

			for ( $i = 0; $i < $chunk_count; $i++ ) {
				$offset = $i * $rows_per_chunk;
				$limit  = min( $rows_per_chunk, max( 0, $row_count - $offset ) );

				$chunks[] = $this->build_chunk( $table_name, $i, $offset, $limit );
			}
		}

		return $chunks;
	}

	/**
	 * Compute how many rows fit in a single chunk given the configured byte budget.
	 *
	 * Uses a conservative average bytes-per-statement estimate. The
	 * result is always at least 1 to guarantee progress on tables
	 * with wide rows.
	 *
	 * @return int A positive integer count of rows per chunk.
	 */
	private function compute_rows_per_chunk(): int {
		$estimated = (int) floor( $this->chunk_size_bytes / self::AVG_BYTES_PER_STATEMENT_ESTIMATE );
		return max( 1, $estimated );
	}

	/**
	 * Build one ScannedDbChunk for the given table slice.
	 *
	 * Chunk 0 of each table includes the schema (DROP+CREATE) in
	 * addition to its rows. The actual SQL is generated lazily by
	 * the closure stored in the returned ScannedDbChunk; this method
	 * only constructs the metadata.
	 *
	 * @param string $table_name  The table being chunked.
	 * @param int    $chunk_index The 0-based ordinal of this chunk.
	 * @param int    $offset      The first row offset this chunk covers.
	 * @param int    $limit       The maximum row count this chunk covers.
	 * @return ScannedDbChunk A fully-populated chunk metadata object.
	 */
	private function build_chunk( string $table_name, int $chunk_index, int $offset, int $limit ): ScannedDbChunk {
		$db       = $this->db;
		$is_first = 0 === $chunk_index;

		$sql_provider = static function () use ( $db, $table_name, $offset, $limit, $is_first ) {
			$rows_sql   = $limit > 0 ? $db->dump_table_rows( $table_name, $offset, $limit ) : '';
			$schema_sql = $is_first ? $db->dump_table_schema( $table_name ) : '';

			return self::open_memory_stream_with_sql( $schema_sql . $rows_sql );
		};

		// Predict statement_count and byte_count cheaply for metadata.
		// Schema contributes 2 statements (DROP + CREATE). Each row contributes 1 INSERT.
		// Byte count is the rows-per-chunk estimate plus an allowance for the schema if applicable.
		$statement_count = $limit + ( $is_first ? 2 : 0 );
		$byte_count      = ( $limit * self::AVG_BYTES_PER_STATEMENT_ESTIMATE ) + ( $is_first ? 2048 : 0 );

		return new ScannedDbChunk( $table_name, $chunk_index, $statement_count, $byte_count, $sql_provider );
	}

	/**
	 * Open a fresh php://memory stream pre-populated with the given SQL bytes.
	 *
	 * Used by the sql_provider closure inside build_chunk() to defer
	 * actual stream allocation until ArchiveWriter needs the chunk's
	 * bytes. Returning a rewound, readable stream lets EntryWriter
	 * read from offset 0 to EOF.
	 *
	 * @param string $sql SQL bytes to write into the stream; may be empty.
	 * @return resource A readable php://memory stream positioned at offset 0.
	 * @throws RuntimeException If php://memory cannot be opened.
	 */
	private static function open_memory_stream_with_sql( string $sql ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://memory is an in-process buffer, not a file; WP_Filesystem cannot open it.
		$stream = fopen( 'php://memory', 'r+b' );
		if ( false === $stream ) {
			throw new RuntimeException( 'DatabaseScanner: could not open php://memory for chunk SQL.' );
		}
		if ( '' !== $sql ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Writing to in-process php://memory stream, not a filesystem path.
			fwrite( $stream, $sql );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Rewinding an in-process php://memory stream, not a filesystem path.
			rewind( $stream );
		}
		return $stream;
	}
}
