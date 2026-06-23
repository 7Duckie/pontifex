<?php
/**
 * In-memory MigrationDatabase used by DatabaseRewriter unit tests.
 *
 * @package Pontifex\Tests\Unit\Migrate\Fakes
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Migrate\Fakes;

use RuntimeException;
use Pontifex\Migrate\MigrationDatabase;

/**
 * In-memory implementation of {@see MigrationDatabase} for tests.
 *
 * Lets DatabaseRewriter be exercised without any WordPress / wpdb
 * mocking. Tests register tables via add_table() and the rewriter walks
 * them through the standard MigrationDatabase interface. update_row()
 * mutates the stored rows, so a second pass sees the rewritten data —
 * which is how the idempotency property is proven.
 */
final class FakeMigrationDatabase implements MigrationDatabase {

	/**
	 * Registered tables: name => primary key + rows.
	 *
	 * @var array<string, array{primary_key: string|null, rows: array<int, array<string, mixed>>}>
	 */
	private array $tables = array();

	/**
	 * Updates applied, in order, for test assertions.
	 *
	 * @var array<int, array{table: string, primary_key: string, primary_key_value: int|string, columns: array<string, string>}>
	 */
	private array $updates = array();

	/**
	 * If non-null, the next update_row call throws this message.
	 *
	 * @var string|null
	 */
	private ?string $next_failure = null;

	/**
	 * Register a fake table with its primary key and rows.
	 *
	 * @param string                           $name        Fully-prefixed table name.
	 * @param string|null                      $primary_key Single-column primary key, or null for none/composite.
	 * @param array<int, array<string, mixed>> $rows        The table's rows as column => value maps.
	 * @return void
	 */
	public function add_table( string $name, ?string $primary_key, array $rows ): void {
		$this->tables[ $name ] = array(
			'primary_key' => $primary_key,
			'rows'        => array_values( $rows ),
		);
	}

	/**
	 * Configure the next update_row call to throw a RuntimeException.
	 *
	 * Simulates the real `$wpdb->update()` failure path in unit tests.
	 *
	 * @param string $message The error message the simulated failure carries.
	 * @return void
	 */
	public function fail_next_update( string $message ): void {
		$this->next_failure = $message;
	}

	/**
	 * Return the updates applied, in order.
	 *
	 * @return array<int, array{table: string, primary_key: string, primary_key_value: int|string, columns: array<string, string>}>
	 */
	public function updates(): array {
		return $this->updates;
	}

	/**
	 * Return the registered table names in alphabetical order.
	 *
	 * @return string[]
	 */
	public function list_tables(): array {
		$names = array_keys( $this->tables );
		sort( $names, SORT_STRING );
		return $names;
	}

	/**
	 * Return the registered primary key for a table.
	 *
	 * @param string $table Registered table name.
	 * @return string|null The primary-key column, or null if the table has none.
	 * @throws RuntimeException If the table was not registered.
	 */
	public function primary_key( string $table ): ?string {
		$this->require_table( $table );
		return $this->tables[ $table ]['primary_key'];
	}

	/**
	 * Return a slice of the registered rows for a table.
	 *
	 * @param string $table  Registered table name.
	 * @param int    $offset 0-based starting row.
	 * @param int    $limit  Maximum number of rows.
	 * @return array<int, array<string, mixed>>
	 * @throws RuntimeException If the table was not registered.
	 */
	public function read_rows( string $table, int $offset, int $limit ): array {
		$this->require_table( $table );
		return array_values( array_slice( $this->tables[ $table ]['rows'], $offset, $limit ) );
	}

	/**
	 * Merge the changed columns into the matching stored row and record the update.
	 *
	 * @param string                $table             Registered table name.
	 * @param string                $primary_key       Primary-key column name.
	 * @param int|string            $primary_key_value The row's primary-key value.
	 * @param array<string, string> $columns           Changed columns as column => new value.
	 * @return void
	 * @throws RuntimeException If a failure was queued, the table is unknown, or no row matches.
	 */
	public function update_row( string $table, string $primary_key, int|string $primary_key_value, array $columns ): void {
		if ( null !== $this->next_failure ) {
			$message            = $this->next_failure;
			$this->next_failure = null;
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $message is test-controlled simulated-failure text; exception path, not HTML output.
			throw new RuntimeException( $message );
		}

		$this->require_table( $table );

		$this->updates[] = array(
			'table'             => $table,
			'primary_key'       => $primary_key,
			'primary_key_value' => $primary_key_value,
			'columns'           => $columns,
		);

		foreach ( $this->tables[ $table ]['rows'] as $index => $row ) {
			// Loose comparison: a real database matches "1" against 1.
			if ( isset( $row[ $primary_key ] ) && $row[ $primary_key ] == $primary_key_value ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- Mirrors the database's type-juggling match of a string primary-key value against a numeric column.
				$this->tables[ $table ]['rows'][ $index ] = array_merge( $row, $columns );
				return;
			}
		}

		throw new RuntimeException(
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test-double diagnostic; $table and $primary_key are test-controlled identifiers; exception path, not HTML output.
			sprintf( 'FakeMigrationDatabase: no row in "%s" has %s = %s.', $table, $primary_key, (string) $primary_key_value )
		);
	}

	/**
	 * Assert a table was registered before it is queried.
	 *
	 * @param string $table The table name to check.
	 * @return void
	 * @throws RuntimeException If the table was not registered.
	 */
	private function require_table( string $table ): void {
		if ( ! isset( $this->tables[ $table ] ) ) {
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test-double diagnostic; $table is a test-controlled identifier; exception path, not HTML output.
				sprintf( 'FakeMigrationDatabase: table "%s" was not registered with add_table().', $table )
			);
		}
	}
}
