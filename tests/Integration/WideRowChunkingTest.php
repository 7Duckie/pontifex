<?php
/**
 * Integration test: wide-row tables are chunked by their real row width.
 *
 * @package Pontifex\Tests\Integration
 */

declare(strict_types=1);

namespace Pontifex\Tests\Integration;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use Pontifex\Manifest\DatabaseScanner;
use Pontifex\Manifest\ExclusionRules;
use Pontifex\Manifest\WpdbAdapter;

/**
 * Proves the per-table chunk sizing against a real MySQL server.
 *
 * The old scanner divided the chunk byte budget by a fixed ~1 KiB row guess,
 * so a table of 1 MiB rows produced one chunk thousands of times over budget —
 * which a memory-budgeted web restore then rightly refuses, leaving the backup
 * unrestorable in the browser. This test builds a real table of megabyte rows
 * and asserts the scanner now splits it so every realised chunk's actual SQL
 * stays near the budget.
 */
final class WideRowChunkingTest extends TestCase {

	/**
	 * The scratch table, created and dropped by this test.
	 *
	 * @var string
	 */
	private const TABLE = 'wp_pontifexwide';

	/**
	 * Bytes per row payload: one mebibyte.
	 *
	 * @var int
	 */
	private const ROW_BYTES = 1048576;

	/**
	 * Rows seeded into the scratch table.
	 *
	 * @var int
	 */
	private const ROW_COUNT = 8;

	/**
	 * Drop the scratch table before the test.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();
		$this->drop_scratch_table();
	}

	/**
	 * Drop the scratch table after the test.
	 *
	 * @return void
	 */
	protected function tear_down(): void {
		$this->drop_scratch_table();
		parent::tear_down();
	}

	/**
	 * Megabyte rows must split into many chunks whose real SQL is near the budget.
	 *
	 * @return void
	 */
	public function test_wide_rows_chunk_near_the_byte_budget(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test fixture: create the scratch table.
		$wpdb->query( 'CREATE TABLE `' . self::TABLE . '` (id INT NOT NULL PRIMARY KEY, val LONGTEXT) DEFAULT CHARSET=utf8mb4' );
		$payload = str_repeat( 'x', self::ROW_BYTES );
		for ( $i = 1; $i <= self::ROW_COUNT; $i++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test fixture: seed a wide row.
			$wpdb->query( $wpdb->prepare( 'INSERT INTO `' . self::TABLE . '` VALUES (%d, %s)', $i, $payload ) );
		}
		// Freshen the storage engine's statistics so Avg_row_length reflects the
		// rows just inserted; on a live site the stats are maintained continuously.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test fixture: refresh table statistics.
		$wpdb->query( 'ANALYZE TABLE `' . self::TABLE . '`' );

		$adapter = new WpdbAdapter( $wpdb );
		$this->assertGreaterThan(
			self::ROW_BYTES / 4,
			$adapter->average_row_bytes( self::TABLE ),
			'The adapter must report a row width in the megabyte order for megabyte rows.'
		);

		$scanner = new DatabaseScanner( $adapter, ExclusionRules::none() );
		$chunks  = array_values(
			array_filter(
				$scanner->scan(),
				static function ( $chunk ): bool {
					return self::TABLE === $chunk->table_name();
				}
			)
		);

		$this->assertGreaterThan(
			1,
			count( $chunks ),
			'Eight megabyte rows must split into multiple chunks; the old fixed guess packed them into one.'
		);

		$budget = DatabaseScanner::DEFAULT_CHUNK_SIZE_BYTES;
		foreach ( $chunks as $chunk ) {
			$sql = stream_get_contents( $chunk->open_sql_stream() );
			$this->assertLessThanOrEqual(
				2 * $budget,
				strlen( $sql ),
				sprintf( 'Chunk %d of %s must stay within twice the byte budget; got %d bytes.', (int) $chunk->chunk_index(), self::TABLE, strlen( (string) $sql ) )
			);
		}
	}

	/**
	 * Drop the scratch table.
	 *
	 * @return void
	 */
	private function drop_scratch_table(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test cleanup: drop the scratch table.
		$wpdb->query( 'DROP TABLE IF EXISTS `' . self::TABLE . '`' );
	}
}
