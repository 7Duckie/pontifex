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
	 * Ordering columns per table, resolved once and cached for the adapter's life.
	 *
	 * The primary-key columns in key order, or every column for a table without
	 * a primary key. Feeds the ORDER BY that makes row-dump pagination stable.
	 *
	 * @var array<string, string[]>
	 */
	private array $order_columns = array();

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
		// Without an ORDER BY, MySQL guarantees no row order at all, so consecutive
		// OFFSET windows can overlap or leave gaps — a silently corrupt backup, and
		// the root of a real live-site incident. Ordering by the primary key (or
		// every column when a table has none) makes the pagination a stable total
		// order over the table.
		$order_clause = $this->order_by_clause( $table_name );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $order_clause is built by order_by_clause() from SHOW KEYS/SHOW COLUMNS results, with every identifier backtick-escaped; the table and value placeholders still go through prepare().
		$sql = $this->wpdb->prepare( 'SELECT * FROM %i' . $order_clause . ' LIMIT %d OFFSET %d', $table_name, $limit, $offset );
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
	 * When $staging_prefix is non-empty the UPDATEs target the physically-staged
	 * copies (`{staging_prefix}{dest_prefix}options` / `…usermeta`) a
	 * staging-table restore builds before its atomic cut-over (ADR 0009), so the
	 * still-live tables are never written.
	 *
	 * @param string $source_prefix  The prefix recorded in the archive.
	 * @param string $dest_prefix    The destination site's prefix.
	 * @param string $staging_prefix Optional. A physical prefix currently prepended to the tables being rewritten; default '' (rewrite the live tables).
	 * @return void
	 * @throws RuntimeException If a rewrite statement fails to execute.
	 */
	public function rewrite_prefix_keys( string $source_prefix, string $dest_prefix, string $staging_prefix = '' ): void {
		if ( $source_prefix === $dest_prefix ) {
			return;
		}

		// The single prefix-embedded option_name in the options table.
		$options_sql = $this->wpdb->prepare(
			'UPDATE %i SET option_name = %s WHERE option_name = %s',
			$staging_prefix . $dest_prefix . 'options',
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
			$staging_prefix . $dest_prefix . 'usermeta',
			$dest_prefix,
			strlen( $source_prefix ) + 1,
			$like
		);
		$this->run_rewrite( (string) $usermeta_sql, 'usermeta.meta_key' );
	}

	/**
	 * Whether a table with exactly this name exists in the database.
	 *
	 * SHOW TABLES LIKE with the name esc_like()-escaped, so underscores in the
	 * name match literally rather than as LIKE wildcards. A query error reports
	 * "does not exist" — the safe direction: the cut-over RENAME is the atomic
	 * arbiter, and a missed move-aside makes the RENAME fail whole rather than
	 * touch the live table (see {@see DatabaseAdapter::table_exists()}).
	 *
	 * @param string $table_name The exact table name to look for.
	 * @return bool True when the table exists.
	 */
	public function table_exists( string $table_name ): bool {
		$sql = $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $this->wpdb->esc_like( $table_name ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- $sql is the direct return value of $wpdb->prepare() on the line above; a liveness probe for the restore cut-over has no caching benefit.
		$found = $this->wpdb->get_var( $sql );
		return null !== $found;
	}

	/**
	 * List every table whose name begins with the given prefix.
	 *
	 * SHOW TABLES LIKE with the prefix esc_like()-escaped, so its underscores
	 * match literally. Returns an empty list on a query error: this feeds the
	 * best-effort sweep of leftover staging tables, where an empty answer means
	 * "nothing to sweep", never a gate (a real `$wpdb` returns `[]` from
	 * get_col() on failure, which is exactly the contract here).
	 *
	 * @param string $prefix The literal name prefix to match; must not be empty.
	 * @return string[] Matching table names in alphabetical order; empty when none match.
	 * @throws RuntimeException If $prefix is empty (a full-database listing is never intended).
	 */
	public function list_tables_by_prefix( string $prefix ): array {
		if ( '' === $prefix ) {
			throw new RuntimeException( 'WpdbAdapter::list_tables_by_prefix: prefix must not be empty.' );
		}
		$sql = $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $this->wpdb->esc_like( $prefix ) . '%' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- $sql is the direct return value of $wpdb->prepare() on the line above; the leftover-table sweep reads live schema state, so caching does not apply.
		$tables = $this->wpdb->get_col( $sql );
		$names  = array();
		foreach ( $tables as $table ) {
			if ( is_string( $table ) && '' !== $table ) {
				$names[] = $table;
			}
		}
		sort( $names, SORT_STRING );
		return $names;
	}

	/**
	 * The table's average stored row width from SHOW TABLE STATUS, or 0 when unknown.
	 *
	 * Reads the storage engine's own `Avg_row_length` figure — for InnoDB an
	 * estimate, but the right order of magnitude, which is all chunk sizing
	 * needs. Any failure (query error, missing row, absent column) reports 0,
	 * and the scanner falls back to its fixed estimate: the figure is a sizing
	 * hint, never a correctness input.
	 *
	 * @param string $table_name Fully prefixed table name.
	 * @return int Average bytes per row; 0 when unknown.
	 */
	public function average_row_bytes( string $table_name ): int {
		$this->assert_prefixed_table( $table_name );
		$sql = $this->wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $this->wpdb->esc_like( $table_name ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- $sql is the direct return value of $wpdb->prepare() on the line above; a live sizing read has no caching benefit.
		$status = $this->wpdb->get_row( $sql, ARRAY_A );
		if ( ! is_array( $status ) || ! isset( $status['Avg_row_length'] ) || ! is_numeric( $status['Avg_row_length'] ) ) {
			return 0;
		}
		return max( 0, (int) $status['Avg_row_length'] );
	}

	/**
	 * Open a consistent snapshot on this adapter's connection.
	 *
	 * The mysqldump --single-transaction pattern (ADR 0011): REPEATABLE READ
	 * isolation, then a transaction opened WITH CONSISTENT SNAPSHOT, so every
	 * later read on this connection — the table list, row counts, schemas, and
	 * each chunk's row window — sees the database as it stood at this instant,
	 * without blocking any writes. Call it only on a connection dedicated to
	 * the dump: on the global connection, mid-export writes would join the
	 * transaction and stay invisible to other requests until commit.
	 *
	 * Never throws: a false return means the snapshot could not be opened and
	 * the caller should dump without one (today's behaviour) rather than not
	 * back up at all. Only InnoDB tables are snapshot-consistent — MyISAM
	 * tables keep their fuzziness, mysqldump's own documented limitation.
	 *
	 * @return bool True when the snapshot is open on this connection.
	 */
	public function begin_consistent_snapshot(): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- A static session-scoped statement with no inputs; transaction control has no caching or preparation dimension.
		$isolation = $this->wpdb->query( 'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ' );
		if ( false === $isolation ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- A static transaction-control statement with no inputs; no caching or preparation dimension.
		$begin = $this->wpdb->query( 'START TRANSACTION WITH CONSISTENT SNAPSHOT' );
		if ( false === $begin ) {
			return false;
		}
		$this->snapshot_open = true;
		return true;
	}

	/**
	 * Whether {@see self::begin_consistent_snapshot()} opened a snapshot on this connection.
	 *
	 * @var bool
	 */
	private bool $snapshot_open = false;

	/**
	 * End a snapshot this adapter opened; a no-op otherwise.
	 *
	 * An open snapshot holds shared metadata locks on every table it has read,
	 * which block DDL — including Pontifex's own restore cut-over when the
	 * pre-import safety archive dumped the same tables moments earlier in the
	 * same request. Best-effort: the snapshot is read-only, so there is
	 * nothing a failed COMMIT could lose.
	 *
	 * @return void
	 */
	public function end_consistent_snapshot(): void {
		if ( ! $this->snapshot_open ) {
			return;
		}
		$this->snapshot_open = false;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- A static transaction-control statement with no inputs; no caching or preparation dimension.
		$this->wpdb->query( 'COMMIT' );
	}

	/**
	 * End any open snapshot the moment the adapter goes out of scope.
	 *
	 * This is the deterministic release. The export orchestrators hold the
	 * adapter only through locals (the builder and the chunk providers), so
	 * when an export returns, the adapter is freed and this COMMIT releases
	 * the snapshot's metadata locks — before, say, a restore's cut-over RENAME
	 * runs in the same request. The release deliberately rides THIS object,
	 * not the connection: WordPress retains hidden references to every wpdb
	 * instance, so a destructor on the connection never fires mid-request
	 * (found empirically); a plain adapter has no such hidden owners.
	 */
	public function __destruct() {
		$this->end_consistent_snapshot();
	}

	/**
	 * The identifier shape a charset name must match before it may reach SQL.
	 *
	 * @var string
	 */
	private const CHARSET_PATTERN = '/^[A-Za-z0-9_]+$/';

	/**
	 * Set the connection's character set for a database replay.
	 *
	 * Re-validates the archive-supplied charset (defence in depth — the writer
	 * validated it too) before interpolating it, then issues SET NAMES through
	 * {@see self::execute_sql()}, which throws on failure — proceeding after a
	 * failed charset change risks the mojibake this call exists to prevent.
	 *
	 * @param string $charset The archive's character set, e.g. "utf8mb4".
	 * @return void
	 * @throws RuntimeException If the charset is malformed or the server refuses it.
	 */
	public function set_session_charset( string $charset ): void {
		if ( 1 !== preg_match( self::CHARSET_PATTERN, $charset ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $charset is reported verbatim for diagnostic context; exception path, not HTML output.
			throw new RuntimeException( sprintf( 'WpdbAdapter: refusing malformed character set "%s".', $charset ) );
		}
		$this->execute_sql( "SET NAMES '" . $charset . "'" );
	}

	/**
	 * Restore the connection's own configured character set after a replay.
	 *
	 * Best-effort by contract: the replayed data is already committed, so a
	 * failure here is swallowed — the connection then keeps the archive's
	 * charset until the request ends, which cannot corrupt committed rows.
	 *
	 * @return void
	 */
	public function restore_session_charset(): void {
		$charset = (string) $this->wpdb->charset;
		if ( '' === $charset || 1 !== preg_match( self::CHARSET_PATTERN, $charset ) ) {
			return;
		}
		try {
			$this->execute_sql( "SET NAMES '" . $charset . "'" );
		} catch ( RuntimeException $ignored ) {
			unset( $ignored ); // Best-effort: the restored data is already committed.
		}
	}

	/**
	 * Build the ORDER BY clause that makes a table's row dumps deterministic.
	 *
	 * Resolved once per table and cached: the primary-key columns in key order,
	 * or — for the rare table without a primary key — every column, the only
	 * deterministic option left. An empty resolution (a query error) yields no
	 * ORDER BY, degrading to the old behaviour; the dump query itself surfaces
	 * any real database problem.
	 *
	 * @param string $table_name Fully-prefixed table name.
	 * @return string A leading-space ' ORDER BY `a`, `b`' clause, or '' when no columns resolved.
	 */
	private function order_by_clause( string $table_name ): string {
		if ( ! isset( $this->order_columns[ $table_name ] ) ) {
			$this->order_columns[ $table_name ] = $this->resolve_order_columns( $table_name );
		}
		$columns = $this->order_columns[ $table_name ];
		if ( array() === $columns ) {
			return '';
		}
		$escaped = array_map( array( self::class, 'escape_identifier' ), $columns );
		return ' ORDER BY `' . implode( '`, `', $escaped ) . '`';
	}

	/**
	 * Resolve the columns a table's row dumps are ordered by.
	 *
	 * SHOW KEYS gives the primary key's columns with their position in the key
	 * (Seq_in_index), so composite keys order correctly. A table with no
	 * primary key falls back to SHOW COLUMNS — ordering by every column.
	 *
	 * @param string $table_name Fully-prefixed table name.
	 * @return string[] Ordering column names; empty when none could be resolved.
	 */
	private function resolve_order_columns( string $table_name ): array {
		$sql = $this->wpdb->prepare( 'SHOW KEYS FROM %i WHERE Key_name = %s', $table_name, 'PRIMARY' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- $sql is the direct return value of $wpdb->prepare() on the line above; a schema read for deterministic dump ordering has no caching benefit.
		$rows = $this->wpdb->get_results( $sql, ARRAY_A );

		$columns = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( isset( $row['Column_name'], $row['Seq_in_index'] ) && is_string( $row['Column_name'] ) ) {
					$columns[ (int) $row['Seq_in_index'] ] = $row['Column_name'];
				}
			}
		}
		if ( array() !== $columns ) {
			ksort( $columns );
			return array_values( $columns );
		}

		// No primary key: every column, in table order, is the only deterministic sort left.
		$fields_sql = $this->wpdb->prepare( 'SHOW COLUMNS FROM %i', $table_name );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- $fields_sql is the direct return value of $wpdb->prepare() on the line above; a schema read for deterministic dump ordering has no caching benefit.
		$fields = $this->wpdb->get_col( $fields_sql );
		$names  = array();
		foreach ( $fields as $field ) {
			if ( is_string( $field ) && '' !== $field ) {
				$names[] = $field;
			}
		}
		return $names;
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
