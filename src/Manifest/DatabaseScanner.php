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
 *    per table, dividing the configured byte budget by the table's
 *    real average row width (read from the storage engine, doubled
 *    for SQL-literal escaping overhead) or, when that is unknown or
 *    smaller, by an assumed average INSERT statement size; the
 *    actual byte count is verified lazily at write time. Sizing by
 *    the real row width keeps a wide-row table's chunks near the
 *    byte budget, so the archive stays restorable under a
 *    memory-budgeted web request. Empty tables produce one
 *    schema-only chunk so that the archive's import path always
 *    recreates the table even if it had no rows.
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
	 * options-heavy tables. Both are acceptable — and the floor: when
	 * the table's real average row width is larger, the real width
	 * takes over (see {@see self::compute_rows_per_chunk()}).
	 *
	 * @var int
	 */
	private const AVG_BYTES_PER_STATEMENT_ESTIMATE = 1024;

	/**
	 * Multiplier applied to the storage engine's average row width.
	 *
	 * A row's SQL-literal form is larger than its stored form — string
	 * escaping, quoting, numeric-to-text conversion — so the on-disk
	 * average is doubled before it divides the chunk budget. Erring
	 * large is the safe direction: it means fewer rows per chunk, so a
	 * chunk's real bytes land under the budget rather than over it.
	 *
	 * @var int
	 */
	private const SQL_OVERHEAD_MULTIPLIER = 2;

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
				sprintf( 'chunk_size_bytes %d must be positive.', (int) $chunk_size_bytes )
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
	 * @param array<string, array<string, int|string|float|bool>> $resume_cursors Optional per-table keyset seed, table_name => the end key to resume after (see {@see self::build_table_chunks()}); a table absent here starts its first window fresh. Ignored for a table with no primary key.
	 * @return ScannedDbChunk[] All chunks the scanner produced.
	 * @throws RuntimeException If the database adapter signals a failure.
	 */
	public function scan( array $resume_cursors = array() ): array {
		$tables = $this->db->list_tables();
		sort( $tables, SORT_STRING );

		$chunks = array();

		foreach ( $tables as $table_name ) {
			if ( $this->exclusions->matches( $table_name, EntryHeader::KIND_DB_CHUNK ) ) {
				continue;
			}

			$seed   = $resume_cursors[ $table_name ] ?? null;
			$chunks = array_merge( $chunks, $this->build_table_chunks( $table_name, $seed ) );
		}

		return $chunks;
	}

	/**
	 * Plan every chunk for one table, chaining the keyset cursor between them.
	 *
	 * Chunk count and each chunk's row limit still come from row_count() and
	 * rows_per_chunk exactly as before; only the window predicate a chunk's
	 * provider asks the adapter for has changed. $cursor lives in THIS
	 * method's own call frame — a fresh local variable on every call, so one
	 * table's chunk closures (which chain it by reference, see
	 * {@see self::build_chunk()}) can never alias another table's — and is
	 * seeded from $seed for the first chunk this call builds. That matters
	 * across a resumed export: {@see \Pontifex\Export\ResumableExportRunner}
	 * re-scans on every tick and SKIPS chunks it already completed rather
	 * than re-reading them, so a fresh scan's chunk 0 closure for a
	 * partially-completed table is never invoked and cannot hand chunk 1's
	 * closure a chained cursor from memory — $seed (the last completed
	 * chunk's persisted end key) is what lets the first chunk this call
	 * actually realises pick up where the archive already left off, without
	 * re-reading or duplicating the rows the earlier ticks already captured.
	 *
	 * @param string                                    $table_name The table being planned.
	 * @param array<string, int|string|float|bool>|null $seed       The end key to resume this table's cursor from, or null to start its first window fresh.
	 * @return ScannedDbChunk[] This table's chunks, in chunk_index order.
	 */
	private function build_table_chunks( string $table_name, ?array $seed ): array {
		$row_count      = $this->db->row_count( $table_name );
		$row_bytes      = $this->estimated_statement_bytes( $table_name );
		$rows_per_chunk = $this->compute_rows_per_chunk( $row_bytes );
		$is_empty_table = 0 === $row_count;

		// Empty tables get a single schema-only chunk.
		// Non-empty tables get one chunk per rows_per_chunk batch, with the schema in chunk 0.
		$chunk_count = $is_empty_table ? 1 : (int) ceil( $row_count / $rows_per_chunk );

		$cursor = $seed;
		$chunks = array();
		for ( $i = 0; $i < $chunk_count; $i++ ) {
			$offset = $i * $rows_per_chunk;
			$limit  = min( $rows_per_chunk, max( 0, $row_count - $offset ) );

			$chunks[] = $this->build_chunk( $table_name, $i, $offset, $limit, $row_bytes, $cursor );
		}

		return $chunks;
	}

	/**
	 * Estimate the SQL bytes one of this table's rows contributes to a chunk.
	 *
	 * The table's real average row width (from the adapter, doubled for
	 * SQL-literal escaping overhead) when it is known and wider than the fixed
	 * fallback; otherwise the fixed ~1 KiB estimate. Taking the larger of the
	 * two means a wide-row table gets proportionally fewer rows per chunk while
	 * narrow tables keep today's chunking exactly.
	 *
	 * @param string $table_name The table being sized.
	 * @return int A positive per-row byte estimate.
	 */
	private function estimated_statement_bytes( string $table_name ): int {
		$average = $this->db->average_row_bytes( $table_name );
		return max( self::AVG_BYTES_PER_STATEMENT_ESTIMATE, $average * self::SQL_OVERHEAD_MULTIPLIER );
	}

	/**
	 * Compute how many rows fit in a single chunk given the configured byte budget.
	 *
	 * The result is always at least 1 to guarantee progress on tables whose
	 * single row exceeds the whole budget.
	 *
	 * @param int $row_bytes The per-row byte estimate for the table being chunked.
	 * @return int A positive integer count of rows per chunk.
	 */
	private function compute_rows_per_chunk( int $row_bytes ): int {
		$estimated = (int) floor( $this->chunk_size_bytes / $row_bytes );
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
	 * $cursor is taken by reference and shared, via the closure's own
	 * `use ( &$cursor )`, by every chunk this table's
	 * {@see self::build_table_chunks()} call builds: when this chunk's
	 * provider runs (at archive-write time, once ArchiveWriter realises the
	 * chunk), it reads whichever end key the PREVIOUS chunk's provider left
	 * there — the seed, if no earlier provider in this call has run yet — and
	 * overwrites it with its own result's end key, so the NEXT chunk's
	 * provider chains off it in turn. A table with no primary key never
	 * populates $cursor (its dumps return a null end key), so its providers
	 * always pass null, matching today's plain offset/limit windowing.
	 *
	 * A chunk beyond the first whose provider runs while $cursor is still
	 * null is refused rather than silently dumped: the only way that can
	 * legitimately happen for a keyset table is a caller invoking chunks out
	 * of order within one process, which nothing in this codebase does today
	 * — the archive writer always realises chunks in index order — so in
	 * practice it means this table's earlier chunk(s) were captured in a
	 * PRIOR process (an interrupted resumable export resuming mid-table) and
	 * this scan has no persisted cursor to resume from. Continuing would
	 * silently re-read the table's first window under a later chunk's
	 * identity, duplicating rows and dropping the rows actually meant for
	 * this position. There is currently no producer of a non-empty
	 * $resume_cursors entry for {@see self::scan()} to seed this from (see
	 * that method's own docblock), so today every such resume hits this
	 * refusal rather than a silent corruption — the fail-closed half of a fix
	 * this class alone cannot complete.
	 *
	 * @param string                                    $table_name  The table being chunked.
	 * @param int                                       $chunk_index The 0-based ordinal of this chunk.
	 * @param int                                       $offset      The first row offset this chunk covers.
	 * @param int                                       $limit       The maximum row count this chunk covers.
	 * @param int                                       $row_bytes   The per-row byte estimate used to size this table's chunks.
	 * @param array<string, int|string|float|bool>|null &$cursor      The table's shared keyset cursor cell, chained across this table's chunks.
	 * @return ScannedDbChunk A fully-populated chunk metadata object.
	 */
	private function build_chunk( string $table_name, int $chunk_index, int $offset, int $limit, int $row_bytes, ?array &$cursor ): ScannedDbChunk {
		$db       = $this->db;
		$is_first = 0 === $chunk_index;

		$sql_provider = static function () use ( $db, $table_name, $chunk_index, $offset, $limit, $is_first, &$cursor ) {
			$rows_sql = '';
			if ( $limit > 0 ) {
				$cursor_before_call = $cursor;
				$result             = $db->dump_table_rows( $table_name, $offset, $limit, $cursor );

				if ( ! $is_first && null === $cursor_before_call && null !== $result->end_key() ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $table_name and $chunk_index reported verbatim for diagnostic context; exception path, not HTML output.
					throw new RuntimeException( sprintf( 'Cannot continue "%s" at chunk %d: its keyset cursor did not carry over from an earlier session, so continuing would silently re-read this table\'s first rows instead of its true continuation. This export cannot be resumed as configured — delete the partial archive and start again.', $table_name, (int) $chunk_index ) );
				}

				$rows_sql = $result->sql();
				$cursor   = $result->end_key();
			}
			$schema_sql = $is_first ? $db->dump_table_schema( $table_name ) : '';

			return self::open_memory_stream_with_sql( $schema_sql . $rows_sql );
		};

		// Predict statement_count and byte_count cheaply for metadata.
		// The first chunk's schema emits 2 statements (DROP + CREATE); the rows are
		// emitted as a single batched multi-row INSERT, so any chunk carrying rows
		// contributes exactly 1 INSERT — not one per row. This count must match the
		// statements the emitter actually writes, because DatabaseWriter refuses to
		// replay a chunk whose parsed statement count disagrees with this header value.
		// Byte count is the rows-per-chunk estimate plus an allowance for the schema if applicable.
		$statement_count = ( $is_first ? 2 : 0 ) + ( $limit > 0 ? 1 : 0 );
		$byte_count      = ( $limit * $row_bytes ) + ( $is_first ? 2048 : 0 );

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
			throw new RuntimeException( 'Could not open php://memory for chunk SQL.' );
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
