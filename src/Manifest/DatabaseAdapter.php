<?php
/**
 * Pontifex manifest database adapter — the abstraction DatabaseScanner depends on.
 *
 * @package Pontifex\Manifest
 */

declare(strict_types=1);

namespace Pontifex\Manifest;

use RuntimeException;

/**
 * Database operations that DatabaseScanner needs.
 *
 * This interface exists so DatabaseScanner can be unit-tested without
 * any WordPress / wpdb mocking machinery. Tests inject a small
 * in-memory fake; production code injects {@see WpdbAdapter}.
 * Concentrates all knowledge of WordPress's `$wpdb` into a single
 * adapter class whose own tests use brain/monkey.
 *
 * All return values are byte-sized strings ready to be written to an
 * archive entry. SQL statements end with semicolons and newlines so
 * that concatenating multiple {@see DatabaseAdapter::dump_table_rows()}
 * results produces a valid SQL script.
 */
interface DatabaseAdapter {

	/**
	 * List every table the WordPress installation owns.
	 *
	 * Includes prefixed core tables (wp_posts, wp_options, etc.) and
	 * any prefixed tables that plugins / themes have added. Does NOT
	 * include unrelated tables in the same database that lack the
	 * WordPress prefix.
	 *
	 * @return string[] Table names in alphabetical order.
	 * @throws RuntimeException If the database cannot be queried.
	 */
	public function list_tables(): array;

	/**
	 * Return the number of rows in the given table.
	 *
	 * Used by DatabaseScanner to decide chunking — large tables are
	 * split into multiple chunks, small tables are dumped in one.
	 *
	 * @param string $table_name Fully prefixed table name as returned by list_tables().
	 * @return int The non-negative row count.
	 * @throws RuntimeException If the table cannot be queried.
	 */
	public function row_count( string $table_name ): int;

	/**
	 * Dump the schema (DROP TABLE IF EXISTS + CREATE TABLE) for the given table.
	 *
	 * Returned as ready-to-execute SQL, ending with a trailing
	 * semicolon and newline so that concatenating it with subsequent
	 * INSERT statements produces a valid script.
	 *
	 * @param string $table_name Fully prefixed table name.
	 * @return string SQL bytes encoding the schema.
	 * @throws RuntimeException If the schema cannot be retrieved.
	 */
	public function dump_table_schema( string $table_name ): string;

	/**
	 * Dump a slice of rows from the given table as INSERT statements.
	 *
	 * Returns the SQL for rows in the range [$offset, $offset + $limit).
	 * If $offset is past the end of the table, returns an empty string.
	 * The returned SQL ends with a trailing newline; concatenation
	 * with subsequent dumps produces a valid script.
	 *
	 * @param string $table_name Fully prefixed table name.
	 * @param int    $offset     0-based starting row index; must be non-negative.
	 * @param int    $limit      Maximum number of rows to dump; must be positive.
	 * @return string SQL bytes encoding the rows; empty if no rows match.
	 * @throws RuntimeException If the rows cannot be retrieved.
	 */
	public function dump_table_rows( string $table_name, int $offset, int $limit ): string;
}
