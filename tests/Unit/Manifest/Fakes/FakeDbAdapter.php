<?php
/**
 * In-memory DatabaseAdapter used by DatabaseScanner unit tests.
 *
 * @package Pontifex\Tests\Unit\Manifest\Fakes
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Manifest\Fakes;

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
	 * Return a single canned INSERT line per row in the requested range.
	 *
	 * @param string $table_name Registered table name.
	 * @param int    $offset     Starting row offset.
	 * @param int    $limit      Maximum number of rows.
	 * @return string SQL bytes.
	 */
	public function dump_table_rows( string $table_name, int $offset, int $limit ): string {
		$row_count = $this->row_count( $table_name );
		$end       = min( $offset + $limit, $row_count );
		if ( $offset >= $end ) {
			return '';
		}
		$sql = '';
		for ( $i = $offset; $i < $end; ++$i ) {
			$sql .= "INSERT INTO `{$table_name}` VALUES ({$i});\n";
		}
		return $sql;
	}
}
