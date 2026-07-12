<?php
/**
 * Integration test: multi-chunk row dumps paginate a real table without loss or duplication.
 *
 * @package Pontifex\Tests\Integration
 */

declare(strict_types=1);

namespace Pontifex\Tests\Integration;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Reader\EntryReadResult;
use Pontifex\Manifest\DatabaseScanner;
use Pontifex\Manifest\ExclusionRules;
use Pontifex\Manifest\WpdbAdapter;
use Pontifex\Restore\DatabaseWriter;

/**
 * Proves the ordered pagination contract against real MySQL.
 *
 * The row dump paginates with LIMIT/OFFSET; without an ORDER BY, MySQL
 * guarantees no row order, so consecutive windows can overlap or leave gaps —
 * a silently corrupt backup, and the root of a real live-site incident. This
 * test builds a table whose physical row order is deliberately fragmented
 * (shuffled inserts, deletes, reinserts), forces one-row chunks so the dump
 * crosses many OFFSET windows, and round-trips it through the real
 * scan → replay path: every row must come back exactly once.
 */
final class OrderedPaginationTest extends TestCase {

	/**
	 * Scratch tables, dropped in set_up and tear_down.
	 *
	 * @var string[]
	 */
	private const SCRATCH_TABLES = array(
		'wp_pontifexordered',
		'pontifexstg_wp_pontifexordered',
		'pontifexold_wp_pontifexordered',
	);

	/**
	 * Drop scratch tables before the test.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();
		$this->drop_scratch_tables();
	}

	/**
	 * Drop scratch tables after the test.
	 *
	 * @return void
	 */
	protected function tear_down(): void {
		$this->drop_scratch_tables();
		parent::tear_down();
	}

	/**
	 * A fragmented table round-trips through many one-row chunks with every row exactly once.
	 *
	 * @return void
	 */
	public function test_fragmented_table_round_trips_exactly_once_per_row(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test fixture: create the scratch table.
		$wpdb->query( 'CREATE TABLE `wp_pontifexordered` (id INT NOT NULL PRIMARY KEY, val VARCHAR(32)) DEFAULT CHARSET=utf8mb4' );

		// Fragment the physical order: shuffled inserts, then delete and reinsert
		// a handful so the storage layout differs from the key order.
		$ids = range( 1, 30 );
		shuffle( $ids );
		foreach ( $ids as $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test fixture: seed a shuffled row.
			$wpdb->query( $wpdb->prepare( 'INSERT INTO `wp_pontifexordered` VALUES (%d, %s)', $id, 'val-' . $id ) );
		}
		foreach ( array( 7, 19, 3 ) as $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test fixture: fragment the physical layout.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM `wp_pontifexordered` WHERE id = %d', $id ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test fixture: fragment the physical layout.
			$wpdb->query( $wpdb->prepare( 'INSERT INTO `wp_pontifexordered` VALUES (%d, %s)', $id, 'val-' . $id ) );
		}

		$source_rows = $this->table_rows();
		$this->assertCount( 30, $source_rows );

		// One-row chunks: a 1-byte budget floors at one row per chunk, so the dump
		// crosses 30 separate OFFSET windows — the surface the ordering stabilises.
		$adapter = new WpdbAdapter( $wpdb );
		$scanner = new DatabaseScanner( $adapter, ExclusionRules::none(), 1 );
		$chunks  = array_values(
			array_filter(
				$scanner->scan(),
				static function ( $chunk ): bool {
					return 'wp_pontifexordered' === $chunk->table_name();
				}
			)
		);
		$this->assertCount( 30, $chunks, 'The fixture must dump as one chunk per row.' );

		// Replay through the real staging restore, replacing the live table.
		$writer = new DatabaseWriter( $adapter );
		$writer->begin_staging();
		foreach ( $chunks as $chunk ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_stream_get_contents -- Realising a scanner-produced memory stream, not a filesystem path.
			$sql    = (string) stream_get_contents( $chunk->open_sql_stream() );
			$header = EntryHeader::for_db_chunk( $chunk->chunk_index(), $chunk->table_name(), $chunk->statement_count(), strlen( $sql ), 0 );
			$writer->write_entry( new EntryReadResult( $header, $sql ) );
		}
		$writer->commit_staged_tables();

		$this->assertSame( $source_rows, $this->table_rows(), 'Every row must round-trip exactly once — no duplicates, no gaps.' );
	}

	/**
	 * Read the scratch table's rows as an id-keyed, id-sorted map.
	 *
	 * @return array<int, string> Values keyed by id.
	 */
	private function table_rows(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test assertion: read back the scratch rows.
		$rows = $wpdb->get_results( 'SELECT id, val FROM `wp_pontifexordered` ORDER BY id', ARRAY_A );
		$map  = array();
		foreach ( $rows as $row ) {
			$map[ (int) $row['id'] ] = (string) $row['val'];
		}
		return $map;
	}

	/**
	 * Drop every scratch table this test may have created.
	 *
	 * @return void
	 */
	private function drop_scratch_tables(): void {
		global $wpdb;
		foreach ( self::SCRATCH_TABLES as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test cleanup: drop a scratch table.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}
	}
}
