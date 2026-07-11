<?php
/**
 * Pontifex manifest wpdb adapter — wraps WordPress's $wpdb to satisfy DatabaseAdapter.
 *
 * @package Pontifex\Manifest
 */

declare(strict_types=1);

namespace Pontifex\Manifest;

use Pontifex\Database\HardenedTableListing;
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

	use HardenedTableListing;

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
		return $this->list_prefixed_tables( $this->wpdb, 'WpdbAdapter' );
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is the by-design replay of a Pontifex archive's database dump (the documented import trust boundary, Gap E); preparation/caching/escaping do not apply to schema-modifying restore statements.
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
	 * Rewrite the WordPress table prefix embedded in the options and usermeta key columns.
	 *
	 * Runs after a cross-prefix restore has replayed every table (already renamed to
	 * the destination prefix). Two plain key columns still carry the source prefix and
	 * are rewritten column-aware here: the single `{prefix}user_roles` option, and
	 * every usermeta `meta_key` beginning with the source prefix. Both statements go
	 * through `$wpdb->prepare()` — `%i` for the table identifier, `%s`/`%d` for values,
	 * with `esc_like()` on the LIKE pattern — because the source prefix comes from the
	 * archive and is untrusted. A no-op when the prefixes are equal.
	 *
	 * @param string $source_prefix The prefix recorded in the archive.
	 * @param string $dest_prefix   The destination site's prefix.
	 * @return void
	 * @throws RuntimeException If a rewrite statement fails to execute.
	 */
	public function rewrite_prefix_keys( string $source_prefix, string $dest_prefix ): void {
		if ( $source_prefix === $dest_prefix ) {
			return;
		}

		// The single prefix-embedded option_name in the options table.
		$options_sql = $this->wpdb->prepare(
			'UPDATE %i SET option_name = %s WHERE option_name = %s',
			$dest_prefix . 'options',
			$dest_prefix . 'user_roles',
			$source_prefix . 'user_roles'
		);
		$this->run_rewrite( (string) $options_sql, 'options.option_name' );

		// Every usermeta meta_key beginning with the source prefix: swap the leading
		// prefix for the destination one. esc_like() escapes the prefix's own "_" so
		// it is matched literally, not as a LIKE wildcard.
		$like         = $this->wpdb->esc_like( $source_prefix ) . '%';
		$usermeta_sql = $this->wpdb->prepare(
			'UPDATE %i SET meta_key = CONCAT(%s, SUBSTRING(meta_key, %d)) WHERE meta_key LIKE %s',
			$dest_prefix . 'usermeta',
			$dest_prefix,
			strlen( $source_prefix ) + 1,
			$like
		);
		$this->run_rewrite( (string) $usermeta_sql, 'usermeta.meta_key' );
	}

	/**
	 * Run one prepared prefix-rewrite statement, failing loudly on a query error.
	 *
	 * Mirrors {@see self::execute_sql()}'s failure detection (a real `$wpdb` returns
	 * `false` on a failed query without necessarily setting `last_error`), but is kept
	 * separate so the verbatim-replay contract of execute_sql() is not muddied — the
	 * SQL here is prepared by this class, not replayed from an archive.
	 *
	 * @param string $sql     A prepared SQL statement.
	 * @param string $context Short label naming the column being rewritten, for diagnostics.
	 * @return void
	 * @throws RuntimeException If the statement fails to execute.
	 */
	private function run_rewrite( string $sql, string $context ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is prepared by rewrite_prefix_keys() via $wpdb->prepare(); a schema/data-modifying restore statement has no caching benefit.
		$result = $this->wpdb->query( $sql );
		if ( false === $result || '' !== $this->wpdb->last_error ) {
			$last_error = '' !== $this->wpdb->last_error ? (string) $this->wpdb->last_error : 'query returned false';
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $context is a hardcoded label and $last_error is the driver's message, reported verbatim for diagnostics; exception path, not HTML output.
				sprintf( 'WpdbAdapter: prefix-key rewrite of %s failed: %s', $context, $last_error )
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
