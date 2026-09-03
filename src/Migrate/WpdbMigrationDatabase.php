<?php
/**
 * Pontifex wpdb migration adapter — wraps $wpdb to satisfy MigrationDatabase.
 *
 * @package Pontifex\Migrate
 */

declare(strict_types=1);

namespace Pontifex\Migrate;

use InvalidArgumentException;
use Pontifex\Database\HardenedTableListing;
use RuntimeException;
use wpdb;

/**
 * Concrete {@see MigrationDatabase} that wraps WordPress's $wpdb object.
 *
 * The single point of contact between the cross-URL rewrite pass and
 * WordPress's database layer, mirroring {@see \Pontifex\Manifest\WpdbAdapter}
 * on the migration side. All wpdb knowledge lives here; {@see DatabaseRewriter}
 * depends only on the MigrationDatabase interface, so it is unit-tested with
 * an in-memory fake and never touches $wpdb.
 *
 * Conventions, matching WpdbAdapter:
 *
 *  - Identifiers (table names) go through `$wpdb->prepare()` with the `%i`
 *    placeholder (WordPress 6.2+); Pontifex targets WP 6.2+.
 *  - Reads use `get_results()`/`get_col()` on a prepared statement.
 *  - Writes go through `$wpdb->update()`, which parameterises the data and
 *    WHERE values itself.
 *
 * Failure handling honours the interface contract: the real `$wpdb` returns
 * `false` (it does not throw) on a failed query, so every method checks the
 * outcome and throws a {@see RuntimeException} rather than letting a failure
 * pass silently — the difference between a loud abort and a half-migrated
 * database.
 */
final class WpdbMigrationDatabase implements MigrationDatabase {

	use HardenedTableListing;

	/**
	 * The wpdb instance this adapter wraps.
	 *
	 * @var wpdb
	 */
	private wpdb $wpdb;

	/**
	 * Explicit table scope, or null to walk every prefixed table.
	 *
	 * Production passes null: {@see list_tables()} then returns every
	 * WordPress-prefixed table, the `wp search-replace` default. An explicit
	 * list narrows the walk to exactly those tables — the seam an integration
	 * test uses to operate on a single scratch table without touching the
	 * rest of the database.
	 *
	 * @var string[]|null
	 */
	private ?array $tables;

	/**
	 * Construct a WpdbMigrationDatabase around an existing wpdb instance.
	 *
	 * @param wpdb          $wpdb   The WordPress database object, typically the global $wpdb.
	 * @param string[]|null $tables Optional explicit table scope; null walks every prefixed table.
	 * @throws InvalidArgumentException If $tables contains an empty name.
	 */
	public function __construct( wpdb $wpdb, ?array $tables = null ) {
		if ( null !== $tables ) {
			foreach ( $tables as $table ) {
				if ( '' === $table ) {
					throw new InvalidArgumentException( 'Table scope must not contain an empty name.' );
				}
			}
			$tables = array_values( $tables );
		}
		$this->wpdb   = $wpdb;
		$this->tables = $tables;
	}

	/**
	 * List the tables the rewrite pass should walk.
	 *
	 * Returns the explicit scope when one was supplied, otherwise every
	 * WordPress-prefixed table (SHOW TABLES LIKE '{prefix}%'), so unrelated
	 * tables sharing the database are not pulled in.
	 *
	 * @return string[] Fully-prefixed table names, alphabetically sorted.
	 * @throws RuntimeException If $wpdb signals a query error.
	 */
	public function list_tables(): array {
		if ( null !== $this->tables ) {
			return $this->tables;
		}

		return $this->list_prefixed_tables( $this->wpdb, 'WpdbMigrationDatabase' );
	}

	/**
	 * Return the single-column primary key of a table, or null when there is none usable.
	 *
	 * Inspects SHOW KEYS … WHERE Key_name = 'PRIMARY'. Exactly one row means a
	 * single-column primary key (its column name is returned); zero rows means
	 * no primary key and more than one means a composite key — both return null
	 * so the caller skips the table rather than UPDATE on a guessed key.
	 *
	 * @param string $table Fully-prefixed table name.
	 * @return string|null The primary-key column, or null if absent or composite.
	 * @throws RuntimeException If the keys cannot be inspected.
	 */
	public function primary_key( string $table ): ?string {
		$sql = $this->wpdb->prepare( 'SHOW KEYS FROM %i WHERE Key_name = %s', $table, 'PRIMARY' );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is the direct return value of $wpdb->prepare() on the line above.
		$rows = $this->wpdb->get_results( $sql, ARRAY_A );

		if ( '' !== $this->wpdb->last_error ) {
			$last_error = (string) $this->wpdb->last_error;
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $table and $wpdb->last_error reported verbatim for diagnostic context; exception path, not HTML output.
			throw new RuntimeException( sprintf( 'Primary-key lookup failed for "%s": %s', $table, $last_error ) );
		}

		if ( ! is_array( $rows ) || 1 !== count( $rows ) ) {
			// No primary key, or a composite one — neither is a single-column key.
			return null;
		}

		$column = $rows[0]['Column_name'] ?? null;
		return is_string( $column ) && '' !== $column ? $column : null;
	}

	/**
	 * Read a batch of rows from a table as associative arrays.
	 *
	 * A table with a usable single-column primary key ({@see self::primary_key()})
	 * is windowed by keyset — the same pure-index-seek approach
	 * {@see \Pontifex\Manifest\WpdbAdapter::dump_table_rows()} uses for exports —
	 * so $offset stops costing a server-side scan of every row before it once the
	 * walk is past the first batch. A table with a composite or absent primary
	 * key keeps today's LIMIT/OFFSET windowing; in practice
	 * {@see \Pontifex\Migrate\DatabaseRewriter::walk()} already skips such a
	 * table entirely rather than call this method on it, but the fallback stays
	 * correct for a direct caller (a test, a future consumer of this interface).
	 *
	 * @param string $table  Fully-prefixed table name.
	 * @param int    $offset 0-based starting row.
	 * @param int    $limit  Maximum rows to read.
	 * @return array<int, array<string, mixed>> The rows, each a column => value map.
	 * @throws RuntimeException If the SELECT fails.
	 */
	public function read_rows( string $table, int $offset, int $limit ): array {
		$primary_key = $this->cached_primary_key( $table );

		if ( null !== $primary_key ) {
			return $this->read_rows_by_keyset( $table, $primary_key, $offset, $limit );
		}

		return $this->read_rows_by_offset( $table, $offset, $limit );
	}

	/**
	 * Per-table keyset cursor: the last-read row's primary-key value.
	 *
	 * Reset to unset whenever $offset is 0 — the start of a fresh walk for
	 * that table, which {@see DatabaseRewriter::walk()} always begins at
	 * offset 0 — so a table walked more than once on the same adapter
	 * instance (a dry-run scan() followed by a real rewrite(), or a test)
	 * starts its cursor over rather than inheriting a stale value from an
	 * earlier walk.
	 *
	 * @var array<string, int|string>
	 */
	private array $row_cursors = array();

	/**
	 * Read a row window keyed off the table's single-column primary key.
	 *
	 * Builds `WHERE pk > cursor ORDER BY pk LIMIT $limit` once a cursor is on
	 * record for this table, and no WHERE at all for the first window
	 * ($offset 0, which also resets the cursor). Every identifier is
	 * backtick-escaped; the cursor value is bound through $wpdb->prepare(),
	 * never string-concatenated.
	 *
	 * @param string $table       Fully-prefixed table name.
	 * @param string $primary_key The table's single-column primary key.
	 * @param int    $offset      0-based starting row; only used to detect the first window (0) and reset the cursor.
	 * @param int    $limit       Maximum rows to read.
	 * @return array<int, array<string, mixed>> The rows, each a column => value map.
	 * @throws RuntimeException If the SELECT fails.
	 */
	private function read_rows_by_keyset( string $table, string $primary_key, int $offset, int $limit ): array {
		if ( 0 === $offset ) {
			unset( $this->row_cursors[ $table ] );
		}

		$order_clause     = $this->order_by_clause( $table );
		$escaped_key      = str_replace( '`', '``', $primary_key );
		$where_clause     = '';
		$args             = array( $table );
		$has_prior_cursor = array_key_exists( $table, $this->row_cursors );

		if ( $has_prior_cursor ) {
			$where_clause = ' WHERE `' . $escaped_key . '` > %s';
			$args[]       = (string) $this->row_cursors[ $table ];
		}

		$args[] = $limit;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where_clause and $order_clause are built from cached_primary_key()'s SHOW KEYS result, with the identifier backtick-escaped; every value placeholder is still resolved by $wpdb->prepare() below. The sniff cannot count placeholders against a runtime-sized ...$args spread, so it always reports one fewer than the query actually needs.
		$sql = $this->wpdb->prepare( 'SELECT * FROM %i' . $where_clause . $order_clause . ' LIMIT %d', ...$args );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is the direct return value of $wpdb->prepare() on the line above; the taint analysis cannot see the preparation (or the backtick-escaped identifier) across the assignment.
		$raw = $this->wpdb->get_results( $sql, ARRAY_A );

		if ( '' !== $this->wpdb->last_error ) {
			$last_error = (string) $this->wpdb->last_error;
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $table and $wpdb->last_error reported verbatim for diagnostic context; exception path, not HTML output.
			throw new RuntimeException( sprintf( 'Row read failed for "%s" offset=%d limit=%d: %s', $table, $offset, $limit, $last_error ) );
		}

		$rows = is_array( $raw ) ? array_values( $raw ) : array();

		if ( array() !== $rows ) {
			$last_row                    = $rows[ count( $rows ) - 1 ];
			$cursor_value                = $last_row[ $primary_key ] ?? null;
			$this->row_cursors[ $table ] = is_int( $cursor_value ) || is_string( $cursor_value ) ? $cursor_value : (string) $cursor_value;
		}

		return $rows;
	}

	/**
	 * Read a row window by today's LIMIT/OFFSET pagination.
	 *
	 * Used for a table with no usable single-column primary key.
	 *
	 * @param string $table  Fully-prefixed table name.
	 * @param int    $offset 0-based starting row.
	 * @param int    $limit  Maximum rows to read.
	 * @return array<int, array<string, mixed>> The rows, each a column => value map.
	 * @throws RuntimeException If the SELECT fails.
	 */
	private function read_rows_by_offset( string $table, int $offset, int $limit ): array {
		// Without an ORDER BY, MySQL guarantees no row order, so consecutive
		// OFFSET windows can overlap or leave gaps — a row that silently never
		// gets its URLs rewritten. Mirrors the export dump's ordering fix; the
		// rewrite never touches primary keys, so the order stays stable while
		// rows are being updated between windows.
		$order_clause = $this->order_by_clause( $table );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $order_clause is built by order_by_clause() from SHOW KEYS results, with the identifier backtick-escaped; the table and value placeholders still go through prepare().
		$sql = $this->wpdb->prepare( 'SELECT * FROM %i' . $order_clause . ' LIMIT %d OFFSET %d', $table, $limit, $offset );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is the direct return value of $wpdb->prepare() on the line above; the taint analysis cannot see the preparation (or the backtick-escaped ORDER BY identifier) across the assignment.
		$rows = $this->wpdb->get_results( $sql, ARRAY_A );

		if ( '' !== $this->wpdb->last_error ) {
			$last_error = (string) $this->wpdb->last_error;
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $table and $wpdb->last_error reported verbatim for diagnostic context; exception path, not HTML output.
			throw new RuntimeException( sprintf( 'Row read failed for "%s" offset=%d limit=%d: %s', $table, $offset, $limit, $last_error ) );
		}

		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	/**
	 * Ordering clauses per table, resolved once and cached for the adapter's life.
	 *
	 * @var array<string, string>
	 */
	private array $order_clauses = array();

	/**
	 * Build the ORDER BY clause that makes a table's row windows deterministic.
	 *
	 * The rewrite pass only ever reads tables whose {@see self::primary_key()}
	 * is a usable single column (the walk skips the rest), so ordering by that
	 * column is a stable total order. Resolved once per table and cached; a
	 * table without a usable key yields no ORDER BY, degrading to the old
	 * behaviour for a read the walk would not perform anyway.
	 *
	 * @param string $table Fully-prefixed table name.
	 * @return string A leading-space ' ORDER BY `col`' clause, or '' when no key resolved.
	 */
	private function order_by_clause( string $table ): string {
		if ( ! isset( $this->order_clauses[ $table ] ) ) {
			$key                           = $this->cached_primary_key( $table );
			$this->order_clauses[ $table ] = null === $key
				? ''
				: ' ORDER BY `' . str_replace( '`', '``', $key ) . '`';
		}
		return $this->order_clauses[ $table ];
	}

	/**
	 * The table's single-column primary key, resolved once and cached for the adapter's life.
	 *
	 * @var array<string, string|null>
	 */
	private array $primary_keys = array();

	/**
	 * Resolve {@see self::primary_key()} once per table, sharing the answer
	 * between {@see self::order_by_clause()} and {@see self::read_rows()} so
	 * SHOW KEYS runs at most once per table on this adapter instance
	 * regardless of how many batches a table's walk takes.
	 *
	 * @param string $table Fully-prefixed table name.
	 * @return string|null The primary-key column, or null if absent or composite.
	 * @throws RuntimeException If the keys cannot be inspected.
	 */
	private function cached_primary_key( string $table ): ?string {
		if ( ! array_key_exists( $table, $this->primary_keys ) ) {
			$this->primary_keys[ $table ] = $this->primary_key( $table );
		}
		return $this->primary_keys[ $table ];
	}

	/**
	 * The table's average stored row width from SHOW TABLE STATUS, or 0 when unknown.
	 *
	 * Mirrors the export adapter's sizing read: the storage engine's own
	 * `Avg_row_length` estimate — the right order of magnitude, which is all
	 * batch sizing needs. Any failure reports 0 and the rewriter falls back to
	 * its fixed estimate; the figure is a sizing hint, never a correctness
	 * input.
	 *
	 * @param string $table Fully-prefixed table name.
	 * @return int Average bytes per row; 0 when unknown.
	 */
	public function average_row_bytes( string $table ): int {
		$sql = $this->wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $this->wpdb->esc_like( $table ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- $sql is the direct return value of $wpdb->prepare() on the line above; a live sizing read has no caching benefit.
		$status = $this->wpdb->get_row( $sql, ARRAY_A );
		if ( ! is_array( $status ) || ! isset( $status['Avg_row_length'] ) || ! is_numeric( $status['Avg_row_length'] ) ) {
			return 0;
		}
		return max( 0, (int) $status['Avg_row_length'] );
	}

	/**
	 * Update the given columns of one row, matched by its primary key.
	 *
	 * Delegates to `$wpdb->update()`, which parameterises both the data and the
	 * WHERE values. A `false` return is a query error (the path the real $wpdb
	 * takes instead of throwing) and is turned into a thrown exception so the
	 * pass stops loudly.
	 *
	 * @param string                $table             Fully-prefixed table name.
	 * @param string                $primary_key       Primary-key column name.
	 * @param int|string            $primary_key_value The row's primary-key value.
	 * @param array<string, string> $columns           Changed columns as column => new value; must be non-empty.
	 * @return void
	 * @throws InvalidArgumentException If $columns is empty.
	 * @throws RuntimeException         If the update fails (including the `$wpdb`-returns-false path).
	 */
	public function update_row( string $table, string $primary_key, int|string $primary_key_value, array $columns ): void {
		if ( array() === $columns ) {
			throw new InvalidArgumentException( 'Columns must not be empty.' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A targeted single-row migration UPDATE keyed on the primary key; $wpdb->update() parameterises the values, and a write must not be cached.
		$result = $this->wpdb->update( $table, $columns, array( $primary_key => $primary_key_value ) );

		if ( false === $result ) {
			$last_error = (string) $this->wpdb->last_error;
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $table and $wpdb->last_error reported verbatim for diagnostic context; exception path, not HTML output.
			throw new RuntimeException( sprintf( 'Update failed for "%s": %s', $table, $last_error ) );
		}
	}
}
