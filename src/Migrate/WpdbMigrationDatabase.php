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
					throw new InvalidArgumentException( 'WpdbMigrationDatabase: table scope must not contain an empty name.' );
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
			throw new RuntimeException( sprintf( 'WpdbMigrationDatabase: primary-key lookup failed for "%s": %s', $table, $last_error ) );
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
	 * @param string $table  Fully-prefixed table name.
	 * @param int    $offset 0-based starting row.
	 * @param int    $limit  Maximum rows to read.
	 * @return array<int, array<string, mixed>> The rows, each a column => value map.
	 * @throws RuntimeException If the SELECT fails.
	 */
	public function read_rows( string $table, int $offset, int $limit ): array {
		$sql = $this->wpdb->prepare( 'SELECT * FROM %i LIMIT %d OFFSET %d', $table, $limit, $offset );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is the direct return value of $wpdb->prepare() on the line above.
		$rows = $this->wpdb->get_results( $sql, ARRAY_A );

		if ( '' !== $this->wpdb->last_error ) {
			$last_error = (string) $this->wpdb->last_error;
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $table and $wpdb->last_error reported verbatim for diagnostic context; exception path, not HTML output.
			throw new RuntimeException( sprintf( 'WpdbMigrationDatabase: row read failed for "%s" offset=%d limit=%d: %s', $table, $offset, $limit, $last_error ) );
		}

		return is_array( $rows ) ? array_values( $rows ) : array();
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
			throw new InvalidArgumentException( 'WpdbMigrationDatabase::update_row: columns must not be empty.' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A targeted single-row migration UPDATE keyed on the primary key; $wpdb->update() parameterises the values, and a write must not be cached.
		$result = $this->wpdb->update( $table, $columns, array( $primary_key => $primary_key_value ) );

		if ( false === $result ) {
			$last_error = (string) $this->wpdb->last_error;
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $table and $wpdb->last_error reported verbatim for diagnostic context; exception path, not HTML output.
			throw new RuntimeException( sprintf( 'WpdbMigrationDatabase: update failed for "%s": %s', $table, $last_error ) );
		}
	}
}
