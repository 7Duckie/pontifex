<?php
/**
 * Pontifex database rewriter — the serialised-safe search-replace pass over the live database.
 *
 * @package Pontifex\Migrate
 */

declare(strict_types=1);

namespace Pontifex\Migrate;

use InvalidArgumentException;
use RuntimeException;

/**
 * Runs a serialised-safe search-replace over the live WordPress database.
 *
 * This is the cross-URL migration pass (ADR 0006): after a same-URL
 * restore, it walks every table's rows and rewrites each value through
 * {@see SerialisedReplacer}, so a site moved from old.example to
 * new.example has its URLs rewritten without corrupting PHP-serialised
 * data. It is the `wp search-replace` model — structured values, not a
 * text rewrite of the archive's SQL.
 *
 * Two modes share one walk:
 *
 *  - {@see scan()} previews what would change and writes nothing — the
 *    pre-migrate dry run an operator runs before trusting the pass.
 *  - {@see rewrite()} applies the change, issuing one UPDATE per changed
 *    row, keyed on the row's primary key so exactly one row is touched.
 *
 * Both return a {@see RewriteReport}. The safety comes from the replacer
 * (gadget chains never instantiate; values that will not round-trip are
 * kept unchanged) and from the database seam (every query failure throws
 * rather than silently skipping a row). A table without a usable
 * single-column primary key is skipped and named in the report, never
 * rewritten on a guessed key.
 *
 * The pre-import safety archive (ADR 0005) is the undo for the whole
 * operation: a migration that goes wrong is reversed with
 * `wp pontifex rollback`.
 */
final class DatabaseRewriter {

	/**
	 * Rows read per batch, to bound memory on large tables.
	 *
	 * @var int
	 */
	public const DEFAULT_BATCH_SIZE = 1000;

	/**
	 * Columns the rewrite never touches, by name, on every table.
	 *
	 * WordPress treats a post's guid as a PERMANENT globally-unique identity —
	 * feed readers use it to decide whether a post is new — so it must survive
	 * a URL migration unchanged even though it usually contains the old URL.
	 * Skipping by column name across all tables matches the wider ecosystem's
	 * documented convention for search-replace tooling. A skipped column that
	 * does hold the search term is tallied in the report's skipped-value
	 * count, so the operator sees it was deliberately left alone.
	 *
	 * @var string[]
	 */
	private const SKIPPED_COLUMNS = array( 'guid' );

	/**
	 * Target byte budget for one row batch (4 MiB), before the overhead multiplier.
	 *
	 * A batch is held in memory whole while its rows rewrite, so its size must
	 * be bounded by bytes, not by row count — a fixed thousand rows of
	 * megabyte-wide page-builder content is gigabytes. Mirrors the export
	 * chunker's per-table sizing.
	 *
	 * @var int
	 */
	private const BATCH_BYTE_BUDGET = 4194304;

	/**
	 * Assumed bytes per row when the storage engine's estimate is unknown or smaller.
	 *
	 * @var int
	 */
	private const AVG_ROW_FLOOR = 1024;

	/**
	 * Multiplier on the storage engine's average row width, for PHP-side overhead.
	 *
	 * A row in PHP (associative array of strings, plus the rewrite's working
	 * copies) costs more than its stored bytes; erring large means fewer rows
	 * per batch, the safe direction.
	 *
	 * @var int
	 */
	private const ROW_OVERHEAD_MULTIPLIER = 2;

	/**
	 * The live-database seam the pass reads and writes through.
	 *
	 * @var MigrationDatabase
	 */
	private MigrationDatabase $db;

	/**
	 * The serialised-safe replacer applied to every value.
	 *
	 * @var SerialisedReplacer
	 */
	private SerialisedReplacer $replacer;

	/**
	 * Rows read per batch.
	 *
	 * @var int
	 */
	private int $batch_size;

	/**
	 * Construct a rewriter over a database seam and a replacer.
	 *
	 * @param MigrationDatabase  $db         The live-database seam to walk.
	 * @param SerialisedReplacer $replacer   The serialised-safe replacer (already carrying its class allowlist).
	 * @param int                $batch_size Rows read per batch; must be positive. Defaults to {@see DEFAULT_BATCH_SIZE}.
	 * @throws InvalidArgumentException If $batch_size is not positive.
	 */
	public function __construct( MigrationDatabase $db, SerialisedReplacer $replacer, int $batch_size = self::DEFAULT_BATCH_SIZE ) {
		if ( $batch_size <= 0 ) {
			throw new InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $batch_size is an integer reported verbatim for diagnostic context; exception path, not HTML output.
				sprintf( 'DatabaseRewriter: batch_size %d must be positive.', $batch_size )
			);
		}
		$this->db         = $db;
		$this->replacer   = $replacer;
		$this->batch_size = $batch_size;
	}

	/**
	 * Preview what a rewrite would change, writing nothing.
	 *
	 * Walks every table and counts the rows and values that would change,
	 * without issuing a single UPDATE. The returned report is the
	 * pre-migrate scan an operator reviews before committing.
	 *
	 * @param string $search  The substring to find (e.g. the old site URL); must be non-empty.
	 * @param string $replace The substring to substitute (e.g. the new site URL).
	 * @return RewriteReport The counts that would result.
	 * @throws InvalidArgumentException If $search is empty.
	 * @throws RuntimeException         If the database seam signals a failure.
	 */
	public function scan( string $search, string $replace ): RewriteReport {
		return $this->walk( $search, $replace, false );
	}

	/**
	 * Apply the search-replace across the live database.
	 *
	 * Walks every table and issues one UPDATE per changed row, keyed on
	 * the primary key. Returns the counts of what actually changed.
	 *
	 * @param string $search  The substring to find; must be non-empty.
	 * @param string $replace The substring to substitute.
	 * @return RewriteReport The counts of what changed.
	 * @throws InvalidArgumentException If $search is empty.
	 * @throws RuntimeException         If the database seam signals a failure (the pass stops at the first failure).
	 */
	public function rewrite( string $search, string $replace ): RewriteReport {
		return $this->walk( $search, $replace, true );
	}

	/**
	 * Walk every table once, counting and (optionally) applying changes.
	 *
	 * @param string $search  The substring to find.
	 * @param string $replace The substring to substitute.
	 * @param bool   $apply   When true, issue UPDATEs; when false, only count.
	 * @return RewriteReport
	 * @throws InvalidArgumentException If $search is empty.
	 */
	private function walk( string $search, string $replace, bool $apply ): RewriteReport {
		if ( '' === $search ) {
			throw new InvalidArgumentException( 'DatabaseRewriter: search must not be empty.' );
		}

		$tables_scanned = 0;
		$skipped_tables = array();
		$rows_scanned   = 0;
		$rows_changed   = 0;
		$values_changed = 0;
		$skipped_values = 0;

		foreach ( $this->db->list_tables() as $table ) {
			$primary_key = $this->db->primary_key( $table );
			if ( null === $primary_key ) {
				$skipped_tables[] = $table;
				continue;
			}

			++$tables_scanned;

			$batch_rows = $this->batch_rows_for( $table );

			$offset = 0;
			do {
				$rows  = $this->db->read_rows( $table, $offset, $batch_rows );
				$count = count( $rows );

				foreach ( $rows as $row ) {
					++$rows_scanned;

					$result          = $this->rewrite_row( $search, $replace, $primary_key, $row );
					$changed         = $result['changed'];
					$values_changed += count( $changed );
					$skipped_values += $result['skipped'];

					if ( array() !== $changed ) {
						++$rows_changed;
						if ( $apply ) {
							$this->apply_row_update( $table, $primary_key, $row, $changed );
						}
					}
				}

				$offset += $count;
			} while ( $count === $batch_rows );
		}

		return new RewriteReport( $tables_scanned, $skipped_tables, $rows_scanned, $rows_changed, $values_changed, $skipped_values );
	}

	/**
	 * Size one table's row batches from its real average row width.
	 *
	 * The batch byte budget divided by the larger of the fixed floor and the
	 * storage engine's average row width (doubled for PHP-side overhead),
	 * clamped between one row — so a table of budget-dwarfing rows still makes
	 * progress — and the configured batch size, which stays the ceiling for
	 * narrow tables. Mirrors the export chunker's per-table sizing.
	 *
	 * @param string $table The table about to be walked.
	 * @return int A positive row count for this table's batches.
	 */
	private function batch_rows_for( string $table ): int {
		$per_row = max( self::AVG_ROW_FLOOR, $this->db->average_row_bytes( $table ) * self::ROW_OVERHEAD_MULTIPLIER );
		$rows    = (int) floor( self::BATCH_BYTE_BUDGET / $per_row );
		return max( 1, min( $this->batch_size, $rows ) );
	}

	/**
	 * Rewrite one row's non-key columns, returning the changed ones and a skip tally.
	 *
	 * Every column except the primary key is run through the replacer. A
	 * value the replacer changes is collected; a value that holds the
	 * search term yet is returned unchanged (a blocked object, a
	 * non-round-tripping or corrupt serialisation) is counted as skipped,
	 * so the operator can see it was deliberately left alone. Non-string
	 * values (SQL NULL) are left untouched.
	 *
	 * @param string               $search      The substring to find.
	 * @param string               $replace     The substring to substitute.
	 * @param string               $primary_key The primary-key column to leave untouched.
	 * @param array<string, mixed> $row         The row as a column => value map.
	 * @return array{changed: array<string, string>, skipped: int} The changed columns and the skipped-value count.
	 */
	private function rewrite_row( string $search, string $replace, string $primary_key, array $row ): array {
		$changed = array();
		$skipped = 0;

		foreach ( $row as $column => $value ) {
			if ( $column === $primary_key || ! is_string( $value ) ) {
				continue;
			}

			// A guid is permanent identity, never a link: leave it unchanged, and
			// count it as skipped when it does hold the term so the report is honest.
			if ( in_array( $column, self::SKIPPED_COLUMNS, true ) ) {
				if ( str_contains( $value, $search ) ) {
					++$skipped;
				}
				continue;
			}

			$new_value = $this->replacer->replace( $search, $replace, $value );

			if ( $new_value !== $value ) {
				$changed[ $column ] = $new_value;
			} elseif ( str_contains( $value, $search ) ) {
				// Held the search term but was kept unchanged for safety.
				++$skipped;
			}
		}

		return array(
			'changed' => $changed,
			'skipped' => $skipped,
		);
	}

	/**
	 * Issue the UPDATE for one changed row, keyed on its primary key.
	 *
	 * Fails closed if the row lacks a usable primary-key value: without it
	 * there is no safe WHERE clause, so the pass throws rather than risk a
	 * broad UPDATE.
	 *
	 * @param string                $table       The table being rewritten.
	 * @param string                $primary_key The primary-key column.
	 * @param array<string, mixed>  $row         The original row.
	 * @param array<string, string> $changed     The changed columns to write.
	 * @return void
	 * @throws RuntimeException If the row has no usable primary-key value, or the update fails.
	 */
	private function apply_row_update( string $table, string $primary_key, array $row, array $changed ): void {
		$primary_key_value = $row[ $primary_key ] ?? null;

		if ( ! is_int( $primary_key_value ) && ! is_string( $primary_key_value ) ) {
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $table and $primary_key are database-origin identifiers reported verbatim for diagnostic context; exception path, not HTML output.
				sprintf( 'DatabaseRewriter: row in "%s" has no scalar value for primary key "%s"; refusing to update without a key.', $table, $primary_key )
			);
		}

		$this->db->update_row( $table, $primary_key, $primary_key_value, $changed );
	}
}
