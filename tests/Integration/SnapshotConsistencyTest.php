<?php
/**
 * Integration test: the export's consistent snapshot isolates concurrent writes.
 *
 * @package Pontifex\Tests\Integration
 */

declare(strict_types=1);

namespace Pontifex\Tests\Integration;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use Pontifex\Manifest\WpdbAdapter;
use Pontifex\WordPress\RealWordPressContext;

/**
 * Proves ADR 0011's snapshot semantics against a real MySQL server.
 *
 * Opens the dedicated dump connection through the real seam, begins the
 * consistent snapshot, then mutates the table through the GLOBAL connection —
 * the concurrent write a live site makes mid-export. The snapshot connection's
 * dump must keep seeing the database as it stood when the snapshot opened,
 * while a fresh adapter on the global connection sees the mutation; and the
 * global connection's own writes stay immediately visible to itself, which is
 * the property that keeps the admin progress bar live.
 */
final class SnapshotConsistencyTest extends TestCase {

	/**
	 * The scratch table, created and dropped by this test.
	 *
	 * @var string
	 */
	private const TABLE = 'wp_pontifexsnap';

	/**
	 * The adapter holding the snapshot, ended in tear_down before the DROP.
	 *
	 * An open snapshot holds shared metadata locks on every table it has read,
	 * so the teardown DROP would block forever if the snapshot outlived the
	 * test — the exact hazard the production release paths exist for.
	 *
	 * @var WpdbAdapter|null
	 */
	private ?WpdbAdapter $snapshot_adapter = null;

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
	 * End the snapshot, then drop the scratch table.
	 *
	 * @return void
	 */
	protected function tear_down(): void {
		if ( null !== $this->snapshot_adapter ) {
			$this->snapshot_adapter->end_consistent_snapshot();
			$this->snapshot_adapter = null;
		}
		$this->drop_scratch_table();
		parent::tear_down();
	}

	/**
	 * A write made after the snapshot opened is invisible to the dump, visible elsewhere.
	 *
	 * @return void
	 */
	public function test_snapshot_dump_does_not_see_concurrent_writes(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test fixture: create the scratch table.
		$wpdb->query( 'CREATE TABLE `' . self::TABLE . '` (id INT NOT NULL PRIMARY KEY, val VARCHAR(32)) DEFAULT CHARSET=utf8mb4' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test fixture: seed the pre-snapshot row.
		$wpdb->query( 'INSERT INTO `' . self::TABLE . "` VALUES (1, 'before-snapshot')" );

		$context   = new RealWordPressContext();
		$dedicated = $context->dedicated_wpdb_connection();
		$this->assertNotNull( $dedicated, 'The tests environment should permit a dedicated second connection.' );

		$snapshot_adapter       = new WpdbAdapter( $dedicated );
		$this->snapshot_adapter = $snapshot_adapter;
		$this->assertTrue( $snapshot_adapter->begin_consistent_snapshot(), 'The snapshot should open on the dedicated connection.' );

		// The concurrent write: a live site mutating mid-export, on the global connection.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test: the concurrent write under test.
		$wpdb->query( 'INSERT INTO `' . self::TABLE . "` VALUES (2, 'after-snapshot')" );

		// The snapshot dump sees the database as it stood when the snapshot opened.
		$this->assertSame( 1, $snapshot_adapter->row_count( self::TABLE ), 'The snapshot must not see the concurrent insert.' );
		$snapshot_sql = $snapshot_adapter->dump_table_rows( self::TABLE, 0, 10 );
		$this->assertStringContainsString( 'before-snapshot', $snapshot_sql );
		$this->assertStringNotContainsString( 'after-snapshot', $snapshot_sql, 'A row inserted mid-export must not leak into the snapshot dump.' );

		// A fresh adapter on the global connection sees the current state — the
		// property that keeps progress writes visible to polling requests.
		$live_adapter = new WpdbAdapter( $wpdb );
		$this->assertSame( 2, $live_adapter->row_count( self::TABLE ), 'The global connection must see the current state.' );
	}

	/**
	 * Releasing the export's adapter must free the snapshot's metadata locks for DDL.
	 *
	 * The production shape that matters: a pre-import safety archive dumps the
	 * live tables through a snapshot, and the restore's atomic cut-over then
	 * RENAMEs those same tables in the same request. The snapshot's shared
	 * metadata locks would block that RENAME forever — unless the adapter
	 * going out of scope (as happens when SafetyArchiver::create() returns)
	 * ends the snapshot. The release deliberately rides the adapter's own
	 * destructor: WordPress retains hidden references to every wpdb instance,
	 * so a destructor on the connection never fires mid-request. A short lock
	 * timeout makes a regression fail fast instead of hanging the suite.
	 *
	 * @return void
	 */
	public function test_released_snapshot_frees_metadata_locks_for_ddl(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test fixture: create the scratch table.
		$wpdb->query( 'CREATE TABLE `' . self::TABLE . '` (id INT NOT NULL PRIMARY KEY) DEFAULT CHARSET=utf8mb4' );

		$context   = new RealWordPressContext();
		$dedicated = $context->dedicated_wpdb_connection();
		$this->assertNotNull( $dedicated );

		// Dump through the snapshot so its transaction holds the table's MDL,
		// then release the adapter — the moment SafetyArchiver::create() returns.
		$adapter = new WpdbAdapter( $dedicated );
		$this->assertTrue( $adapter->begin_consistent_snapshot() );
		$adapter->row_count( self::TABLE );
		unset( $adapter );

		// The restore's cut-over shape: DDL on the dumped table must not block.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test: a short lock timeout so a regression fails fast instead of hanging the suite.
		$wpdb->query( 'SET SESSION lock_wait_timeout = 3' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test: the DDL under test.
		$renamed = $wpdb->query( 'RENAME TABLE `' . self::TABLE . '` TO `' . self::TABLE . '_renamed`' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test cleanup: restore the session default.
		$wpdb->query( 'SET SESSION lock_wait_timeout = 31536000' );
		$this->assertNotFalse( $renamed, 'DDL on a dumped table must not block once the export adapter is released.' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test cleanup: drop the renamed scratch table.
		$wpdb->query( 'DROP TABLE IF EXISTS `' . self::TABLE . '_renamed`' );
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
