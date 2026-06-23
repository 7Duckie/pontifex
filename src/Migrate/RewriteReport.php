<?php
/**
 * Pontifex rewrite report — the privacy-safe tally a scan or rewrite pass produces.
 *
 * @package Pontifex\Migrate
 */

declare(strict_types=1);

namespace Pontifex\Migrate;

use InvalidArgumentException;

/**
 * Immutable summary of what a {@see DatabaseRewriter} pass saw and changed.
 *
 * Produced by both {@see DatabaseRewriter::scan()} (a dry run that writes
 * nothing) and {@see DatabaseRewriter::rewrite()} (which applies the
 * updates). In a scan the change counts are what *would* change; in a
 * rewrite they are what *did*.
 *
 * The report is deliberately **counts only** — no row contents and no
 * column values. A migration walks the entire database, including user
 * accounts and secret keys; surfacing sampled values in a report or a
 * log would leak exactly the data §8.4 (C-ARCHIVE-SENSITIVE) says must
 * never appear there. Table names are schema, not data, so the names of
 * skipped tables are safe to carry and useful to the operator.
 */
final class RewriteReport {

	/**
	 * Tables walked — those with a usable single-column primary key.
	 *
	 * @var int
	 */
	private int $tables_scanned;

	/**
	 * Names of tables skipped for having no single-column primary key.
	 *
	 * @var string[]
	 */
	private array $skipped_tables;

	/**
	 * Rows read across all walked tables.
	 *
	 * @var int
	 */
	private int $rows_scanned;

	/**
	 * Rows with at least one rewritten value (updated, or would-be in a scan).
	 *
	 * @var int
	 */
	private int $rows_changed;

	/**
	 * Individual column values rewritten across all rows.
	 *
	 * @var int
	 */
	private int $values_changed;

	/**
	 * Values that contained the search term but were left unchanged for safety.
	 *
	 * These are values whose raw bytes hold the search string yet the
	 * replacer returned them untouched — serialised data carrying a
	 * non-allowlisted object, a value that would not round-trip, or
	 * corrupt serialised bytes. A non-zero count is the operator's cue to
	 * review them, or to widen the allowlist via the
	 * `pontifex_serialized_classes` filter if the classes are trusted.
	 *
	 * @var int
	 */
	private int $skipped_values;

	/**
	 * Construct a report from a completed walk's tallies.
	 *
	 * @param int      $tables_scanned Tables walked (those with a usable primary key); must be non-negative.
	 * @param string[] $skipped_tables Names of tables skipped for having no single-column primary key.
	 * @param int      $rows_scanned   Rows read across all walked tables; must be non-negative.
	 * @param int      $rows_changed   Rows with at least one rewritten value; must be non-negative.
	 * @param int      $values_changed Individual column values rewritten; must be non-negative.
	 * @param int      $skipped_values Values that held the search term but were left unchanged for safety; must be non-negative.
	 * @throws InvalidArgumentException If any count is negative or a skipped-table name is empty.
	 */
	public function __construct(
		int $tables_scanned,
		array $skipped_tables,
		int $rows_scanned,
		int $rows_changed,
		int $values_changed,
		int $skipped_values
	) {
		$counts = array(
			'tables_scanned' => $tables_scanned,
			'rows_scanned'   => $rows_scanned,
			'rows_changed'   => $rows_changed,
			'values_changed' => $values_changed,
			'skipped_values' => $skipped_values,
		);
		foreach ( $counts as $name => $value ) {
			if ( $value < 0 ) {
				throw new InvalidArgumentException(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal validation message; $name is a known counter key and $value an integer, reported verbatim for diagnostic context; exception path, not HTML output.
					sprintf( 'RewriteReport: %s %d must be non-negative.', $name, $value )
				);
			}
		}
		foreach ( $skipped_tables as $table ) {
			if ( '' === $table ) {
				throw new InvalidArgumentException( 'RewriteReport: skipped_tables must not contain an empty name.' );
			}
		}

		$this->tables_scanned = $tables_scanned;
		$this->skipped_tables = array_values( $skipped_tables );
		$this->rows_scanned   = $rows_scanned;
		$this->rows_changed   = $rows_changed;
		$this->values_changed = $values_changed;
		$this->skipped_values = $skipped_values;
	}

	/**
	 * Return the number of tables walked.
	 *
	 * @return int Tables with a usable single-column primary key.
	 */
	public function tables_scanned(): int {
		return $this->tables_scanned;
	}

	/**
	 * Return the names of tables skipped for lacking a single-column primary key.
	 *
	 * @return string[] Skipped table names.
	 */
	public function skipped_tables(): array {
		return $this->skipped_tables;
	}

	/**
	 * Return the number of rows read.
	 *
	 * @return int Rows scanned across all walked tables.
	 */
	public function rows_scanned(): int {
		return $this->rows_scanned;
	}

	/**
	 * Return the number of rows with at least one rewritten value.
	 *
	 * @return int Rows changed (or would-be changed in a scan).
	 */
	public function rows_changed(): int {
		return $this->rows_changed;
	}

	/**
	 * Return the number of individual column values rewritten.
	 *
	 * @return int Values changed across all rows.
	 */
	public function values_changed(): int {
		return $this->values_changed;
	}

	/**
	 * Return the number of values that held the search term but were left unchanged.
	 *
	 * @return int Values kept unchanged for safety despite containing the search term.
	 */
	public function skipped_values(): int {
		return $this->skipped_values;
	}
}
