<?php
/**
 * Pontifex manifest wpdb adapter — wraps WordPress's $wpdb to satisfy DatabaseAdapter.
 *
 * @package Pontifex\Manifest
 */

declare(strict_types=1);

namespace Pontifex\Manifest;

use RuntimeException;
use wpdb;

/**
 * Concrete {@see DatabaseAdapter} that wraps WordPress's $wpdb object.
 *
 * Single point of contact between Pontifex's database-scanning logic
 * and WordPress's database layer. All wpdb knowledge lives here; the
 * rest of the manifest layer depends only on the DatabaseAdapter
 * interface, making it unit-testable without any WP mocking.
 *
 * Production code constructs this adapter with the global $wpdb
 * instance:
 *
 *     $adapter = new WpdbAdapter( $wpdb );
 *
 * Tests for this adapter (and only this adapter) mock $wpdb's
 * methods. Tests for DatabaseScanner inject an in-memory fake
 * adapter and do not require WP mocking.
 *
 * SQL output conventions:
 *
 *  - Identifiers (table names) are passed through $wpdb->prepare()
 *    using the %i placeholder, which became available in WordPress
 *    6.2 (Feb 2023). Pontifex targets WP 6.2+; older WordPress is
 *    not supported.
 *  - Values use $wpdb->prepare() to escape with %s, %d, or %f
 *    placeholders as appropriate.
 *  - Schema dumps use SHOW CREATE TABLE and include a leading
 *    DROP TABLE IF EXISTS so the import side replaces an existing
 *    table cleanly.
 *  - Row dumps batch many rows into a single multi-VALUE
 *    INSERT INTO ... VALUES (...), (...), ... statement to minimise
 *    statement count without losing parseability.
 *  - All output is UTF-8; WordPress's default charset is already
 *    utf8mb4, and Pontifex's archive layer carries the charset in
 *    the Provenance block.
 */
final class WpdbAdapter implements DatabaseAdapter {

	/**
	 * The wpdb instance this adapter wraps.
	 *
	 * @var wpdb
	 */
	private wpdb $wpdb;

	/**
	 * Construct a WpdbAdapter around an existing wpdb instance.
	 *
	 * @param wpdb $wpdb The WordPress database object, typically the global $wpdb.
	 */
	public function __construct( wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * List every WordPress-prefixed table in the database.
	 *
	 * Queries SHOW TABLES LIKE '{prefix}%' so unrelated tables
	 * sharing the same database (e.g. analytics, mailer queues) are
	 * not pulled in.
	 *
	 * @return string[] Alphabetically sorted table names.
	 * @throws RuntimeException If $wpdb signals a query error.
	 */
	public function list_tables(): array {
		$pattern = $this->wpdb->esc_like( $this->wpdb->prefix ) . '%';
		$sql     = $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $pattern );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is the direct return value of $wpdb->prepare() on the line above; this satisfies the prepared-query contract.
		$rows = $this->wpdb->get_col( $sql );

		if ( '' !== $this->wpdb->last_error ) {
			$last_error = (string) $this->wpdb->last_error;
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $wpdb->last_error reported verbatim for diagnostic context; exception path, not HTML output.
			throw new RuntimeException( sprintf( 'WpdbAdapter: list_tables query failed: %s', $last_error ) );
		}

		$tables = array_values( array_map( 'strval', $rows ) );
		sort( $tables, SORT_STRING );
		return $tables;
	}

	/**
	 * Return the row count of the given table.
	 *
	 * @param string $table_name Fully-prefixed table name.
	 * @return int Non-negative row count.
	 * @throws RuntimeException If the COUNT query fails.
	 */
	public function row_count( string $table_name ): int {
		$this->assert_prefixed_table( $table_name );
		$sql = $this->wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table_name );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is the direct return value of $wpdb->prepare() on the line above.
		$count = $this->wpdb->get_var( $sql );

		if ( null === $count ) {
			$last_error = (string) $this->wpdb->last_error;
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $table_name and $wpdb->last_error reported verbatim for diagnostic context; exception path, not HTML output.
			throw new RuntimeException( sprintf( 'WpdbAdapter: row_count query failed for "%s": %s', $table_name, $last_error ) );
		}

		return (int) $count;
	}

	/**
	 * Dump the schema for the given table as DROP IF EXISTS + CREATE.
	 *
	 * Uses SHOW CREATE TABLE to obtain the CREATE statement exactly
	 * as MySQL would issue it, then prepends a matching DROP for
	 * idempotent imports.
	 *
	 * @param string $table_name Fully-prefixed table name.
	 * @return string SQL bytes encoding the schema, ending with a newline.
	 * @throws RuntimeException If SHOW CREATE TABLE fails.
	 */
	public function dump_table_schema( string $table_name ): string {
		$this->assert_prefixed_table( $table_name );
		$sql = $this->wpdb->prepare( 'SHOW CREATE TABLE %i', $table_name );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is the direct return value of $wpdb->prepare() on the line above.
		$row = $this->wpdb->get_row( $sql, ARRAY_N );

		if ( null === $row || ! isset( $row[1] ) || '' === $row[1] ) {
			$last_error = (string) $this->wpdb->last_error;
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $table_name and $wpdb->last_error reported verbatim for diagnostic context; exception path, not HTML output.
			throw new RuntimeException( sprintf( 'WpdbAdapter: SHOW CREATE TABLE failed for "%s": %s', $table_name, $last_error ) );
		}

		$create_sql         = (string) $row[1];
		$escaped_identifier = self::escape_identifier( $table_name );

		return "DROP TABLE IF EXISTS `{$escaped_identifier}`;\n{$create_sql};\n";
	}

	/**
	 * Dump a row range from the given table as a multi-VALUE INSERT statement.
	 *
	 * Issues SELECT * FROM `table` LIMIT $limit OFFSET $offset and
	 * emits one INSERT INTO ... VALUES (...), (...), ... per call.
	 * Returns an empty string if the range yields no rows.
	 *
	 * @param string $table_name Fully-prefixed table name.
	 * @param int    $offset     0-based starting row.
	 * @param int    $limit      Maximum number of rows.
	 * @return string SQL bytes (possibly empty) ending with a newline if any rows were emitted.
	 * @throws RuntimeException If the SELECT fails.
	 */
	public function dump_table_rows( string $table_name, int $offset, int $limit ): string {
		$this->assert_prefixed_table( $table_name );
		$sql = $this->wpdb->prepare( 'SELECT * FROM %i LIMIT %d OFFSET %d', $table_name, $limit, $offset );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is the direct return value of $wpdb->prepare() on the line above.
		$rows = $this->wpdb->get_results( $sql, ARRAY_A );

		if ( null === $rows ) {
			$last_error = (string) $this->wpdb->last_error;
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $table_name and $wpdb->last_error reported verbatim for diagnostic context; exception path, not HTML output.
			throw new RuntimeException( sprintf( 'WpdbAdapter: row dump failed for "%s" offset=%d limit=%d: %s', $table_name, (int) $offset, (int) $limit, $last_error ) );
		}

		if ( empty( $rows ) ) {
			return '';
		}

		// Use the first row's keys for the column list.
		// All rows from the same table have identical column order.
		$columns            = array_keys( $rows[0] );
		$escaped_columns    = array_map( array( self::class, 'escape_identifier' ), $columns );
		$columns_sql        = '`' . implode( '`, `', $escaped_columns ) . '`';
		$escaped_identifier = self::escape_identifier( $table_name );

		$value_tuples = array();
		foreach ( $rows as $row ) {
			$encoded_values = array();
			foreach ( $columns as $column ) {
				$encoded_values[] = $this->encode_value( $row[ $column ] );
			}
			$value_tuples[] = '(' . implode( ', ', $encoded_values ) . ')';
		}

		return "INSERT INTO `{$escaped_identifier}` ({$columns_sql}) VALUES " . implode( ', ', $value_tuples ) . ";\n";
	}

	/**
	 * Execute one SQL statement against the database via $wpdb->query().
	 *
	 * The statement is sent verbatim; no preparation, escaping, or
	 * placeholder substitution is applied because the SQL came from a
	 * Pontifex-produced archive and is already in its final form.
	 *
	 * Failure is detected by $wpdb->query() returning false, NOT only by a
	 * non-empty $wpdb->last_error: a real $wpdb returns false on a failed query
	 * and, if errors have been suppressed (suppress_errors), leaves last_error
	 * empty. Relying on last_error alone would let a failed restore statement
	 * pass as success and silently drop or skip a table. The migration writer
	 * checks the same way.
	 *
	 * @param string $sql The SQL statement to execute. Must not be empty.
	 * @throws RuntimeException If the statement fails to execute.
	 */
	public function execute_sql( string $sql ): void {
		if ( '' === $sql ) {
			throw new RuntimeException( 'WpdbAdapter::execute_sql: sql must not be empty.' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- $sql came from a Pontifex-produced archive; preparation/caching does not apply to schema-modifying restore statements.
		$result = $this->wpdb->query( $sql );
		if ( false === $result || '' !== $this->wpdb->last_error ) {
			$last_error = '' !== $this->wpdb->last_error ? (string) $this->wpdb->last_error : 'query returned false';
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $last_error is the database driver's error message, reported verbatim for diagnostic context; exception path, not HTML output.
				sprintf( 'WpdbAdapter::execute_sql: query failed: %s', $last_error )
			);
		}
	}

	/**
	 * Encode a single row value into its SQL literal form.
	 *
	 * Null becomes NULL. Numeric scalar values are emitted unquoted
	 * so their numeric form is preserved. Everything else is
	 * single-quote-escaped via $wpdb->_real_escape().
	 *
	 * @param mixed $value The value to encode.
	 * @return string SQL-literal form of the value.
	 */
	private function encode_value( $value ): string {
		if ( null === $value ) {
			return 'NULL';
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}
		$as_string = (string) $value;
		$escaped   = $this->wpdb->_real_escape( $as_string );
		return "'" . $escaped . "'";
	}

	/**
	 * Refuse to operate on a table outside the WordPress prefix.
	 *
	 * Per-table methods use $wpdb->prepare( '%i' ), so this is not an injection
	 * defence; it is a scope guard. Tables are sourced from list_tables() (SHOW
	 * TABLES LIKE prefix%), so a name outside the prefix indicates a future caller
	 * passing an externally-influenced name, which must not be dumped into an
	 * export. Skipped when the prefix is empty (an unconfigured $wpdb).
	 *
	 * @param string $table_name The table name to check.
	 * @return void
	 * @throws RuntimeException If the table is outside the WordPress prefix.
	 */
	private function assert_prefixed_table( string $table_name ): void {
		$prefix = (string) $this->wpdb->prefix;
		if ( '' !== $prefix && ! str_starts_with( $table_name, $prefix ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message naming the table and prefix for diagnostics; surfaced on the CLI, not HTML output.
			throw new RuntimeException( sprintf( 'WpdbAdapter: refusing to operate on table "%s" outside the WordPress prefix "%s".', $table_name, $prefix ) );
		}
	}

	/**
	 * Escape an SQL identifier by doubling backticks.
	 *
	 * Used for identifiers that are interpolated into pre-formatted
	 * SQL output strings (the SQL we emit into the archive, not the
	 * SQL we send to the database). Identifiers we send to the
	 * database go through $wpdb->prepare() with %i instead.
	 *
	 * @param string $identifier Raw identifier.
	 * @return string The identifier with embedded backticks doubled.
	 */
	private static function escape_identifier( string $identifier ): string {
		return str_replace( '`', '``', $identifier );
	}
}
