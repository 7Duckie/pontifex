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
		$this->maybe_fail( __FUNCTION__ );
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
		$this->maybe_fail( __FUNCTION__ );
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
		$this->maybe_fail( __FUNCTION__ );
		$this->require_table( $table );
		$this->reads[] = array(
			'table'  => $table,
			'offset' => $offset,
			'limit'  => $limit,
		);
		return array_values( array_slice( $this->tables[ $table ]['rows'], $offset, $limit ) );
	}

	/**
	 * Every read_rows call, in order, so a test can assert the batch windows.
	 *
	 * @var array<int, array{table: string, offset: int, limit: int}>
	 */
	private array $reads = array();

	/**
	 * Return the recorded read_rows calls, in order.
	 *
	 * @return array<int, array{table: string, offset: int, limit: int}> The recorded windows.
	 */
	public function reads(): array {
		return $this->reads;
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
	 * Failure messages queued per method name; consumed on the next call.
	 *
	 * @var array<string, string>
	 */
	private array $queued_failures = array();

	/**
	 * Queue the next call to the named method to throw a RuntimeException.
	 *
	 * Mirrors the real adapter's contract — every read throws on a $wpdb
	 * failure — so orchestration can be unit-tested against a failing
	 * database without WordPress mocking.
	 *
	 * @param string $method  The method name, e.g. "row_count".
	 * @param string $message The error message the simulated failure carries.
	 * @return void
	 */
	public function fail_next( string $method, string $message ): void {
		$this->queued_failures[ $method ] = $message;
	}

	/**
	 * Throw the queued failure for the method, if one is armed.
	 *
	 * @param string $method The method name being invoked.
	 * @return void
	 * @throws RuntimeException When a failure was queued for the method.
	 */
	private function maybe_fail( string $method ): void {
		if ( isset( $this->queued_failures[ $method ] ) ) {
			$message = $this->queued_failures[ $method ];
			unset( $this->queued_failures[ $method ] );
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $message is test-controlled simulated-failure text; exception path, not HTML output.
			throw new RuntimeException( $message );
		}
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

	/**
	 * Canned average row widths, keyed by table name.
	 *
	 * @var array<string, int>
	 */
	private array $average_row_bytes = array();

	/**
	 * Register a canned average row width for a table.
	 *
	 * @param string $table Table name.
	 * @param int    $bytes Average bytes per row to report.
	 * @return void
	 */
	public function set_average_row_bytes( string $table, int $bytes ): void {
		$this->average_row_bytes[ $table ] = $bytes;
	}

	/**
	 * Return the canned average row width, or 0 when none was registered.
	 *
	 * Mirrors the real adapter's unknown-answer contract: 0 sends the rewriter
	 * to its fixed estimate.
	 *
	 * @param string $table Table name.
	 * @return int Average bytes per row; 0 when unknown.
	 */
	public function average_row_bytes( string $table ): int {
		return $this->average_row_bytes[ $table ] ?? 0;
	}
}
