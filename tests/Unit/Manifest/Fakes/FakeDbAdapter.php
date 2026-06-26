<?php
/**
 * In-memory DatabaseAdapter used by DatabaseScanner unit tests.
 *
 * @package Pontifex\Tests\Unit\Manifest\Fakes
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Manifest\Fakes;

use RuntimeException;
use Pontifex\Manifest\DatabaseAdapter;

/**
 * In-memory implementation of {@see DatabaseAdapter} for tests.
 *
 * Lets DatabaseScanner be exercised without any WordPress / wpdb
 * mocking machinery. Tests register tables via add_table() and the
 * scanner queries them through the standard DatabaseAdapter
 * interface.
 */
final class FakeDbAdapter implements DatabaseAdapter {

	/**
	 * Canned table data: name => [row_count, schema].
	 *
	 * @var array<string, array{row_count: int, schema: string}>
	 */
	private array $tables = array();

	/**
	 * Register a fake table with row count and schema.
	 *
	 * @param string $name      Table name.
	 * @param int    $row_count Row count to return from row_count().
	 * @param string $schema    SQL to return from dump_table_schema().
	 * @return void
	 */
	public function add_table( string $name, int $row_count, string $schema ): void {
		$this->tables[ $name ] = array(
			'row_count' => $row_count,
			'schema'    => $schema,
		);
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
	 * Return the registered row count for the table.
	 *
	 * @param string $table_name Registered table name.
	 * @return int
	 */
	public function row_count( string $table_name ): int {
		return $this->tables[ $table_name ]['row_count'] ?? 0;
	}

	/**
	 * Return the registered schema string for the table.
	 *
	 * @param string $table_name Registered table name.
	 * @return string
	 */
	public function dump_table_schema( string $table_name ): string {
		return $this->tables[ $table_name ]['schema'] ?? '';
	}

	/**
	 * Return one batched multi-row INSERT for the requested range.
	 *
	 * Mirrors {@see \Pontifex\Manifest\WpdbAdapter::dump_table_rows()}, which
	 * packs every row of a chunk into a single INSERT INTO ... VALUES (...),
	 * (...), ...; statement — NOT one INSERT per row. Tests rely on this
	 * fidelity so the scanner's predicted statement_count is checked against
	 * the shape the real emitter produces.
	 *
	 * @param string $table_name Registered table name.
	 * @param int    $offset     Starting row offset.
	 * @param int    $limit      Maximum number of rows.
	 * @return string SQL bytes (empty when the range yields no rows).
	 */
	public function dump_table_rows( string $table_name, int $offset, int $limit ): string {
		$row_count = $this->row_count( $table_name );
		$end       = min( $offset + $limit, $row_count );
		if ( $offset >= $end ) {
			return '';
		}
		$tuples = array();
		for ( $i = $offset; $i < $end; ++$i ) {
			$tuples[] = "({$i})";
		}
		return "INSERT INTO `{$table_name}` VALUES " . implode( ', ', $tuples ) . ";\n";
	}

	/**
	 * Statements passed to execute_sql, in order.
	 *
	 * Tests inspect this array to verify which SQL the writer ran.
	 *
	 * @var string[]
	 */
	private array $executed_statements = array();

	/**
	 * If non-null, the next execute_sql call throws this message.
	 *
	 * Set via {@see FakeDbAdapter::fail_next_execute()} to simulate
	 * a database error in tests.
	 *
	 * @var string|null
	 */
	private ?string $next_failure = null;

	/**
	 * Configure the next execute_sql call to throw a RuntimeException.
	 *
	 * @param string $message The error message the simulated failure carries.
	 * @return void
	 */
	public function fail_next_execute( string $message ): void {
		$this->next_failure = $message;
	}

	/**
	 * Return the SQL statements passed to execute_sql, in order.
	 *
	 * @return string[] The recorded statements.
	 */
	public function executed_statements(): array {
		return $this->executed_statements;
	}

	/**
	 * Record the SQL statement, or simulate a configured failure.
	 *
	 * @param string $sql The SQL to execute.
	 * @throws RuntimeException If $sql is empty or fail_next_execute() was called.
	 */
	public function execute_sql( string $sql ): void {
		if ( '' === $sql ) {
			throw new RuntimeException( 'FakeDbAdapter::execute_sql: sql must not be empty.' );
		}
		if ( null !== $this->next_failure ) {
			$message            = $this->next_failure;
			$this->next_failure = null;
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $message is test-controlled simulated-failure text; exception path, not HTML output.
			throw new RuntimeException( $message );
		}
		$this->executed_statements[] = $sql;
	}

	/**
	 * Prefix-key rewrite calls, in order, as [source_prefix, dest_prefix] pairs.
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $rewrite_calls = array();

	/**
	 * Record a prefix-key rewrite call so a test can assert it happened.
	 *
	 * @param string $source_prefix The prefix recorded in the archive.
	 * @param string $dest_prefix   The destination site's prefix.
	 * @return void
	 */
	public function rewrite_prefix_keys( string $source_prefix, string $dest_prefix ): void {
		$this->rewrite_calls[] = array( $source_prefix, $dest_prefix );
	}

	/**
	 * Return the recorded prefix-key rewrite calls, in order.
	 *
	 * @return array<int, array{0: string, 1: string}> Each entry is [source_prefix, dest_prefix].
	 */
	public function rewrite_calls(): array {
		return $this->rewrite_calls;
	}
}
