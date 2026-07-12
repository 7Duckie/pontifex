<?php
/**
 * Integration test: the database rewrite pass over a real WordPress database.
 *
 * @package Pontifex\Tests\Integration
 */

declare(strict_types=1);

namespace Pontifex\Tests\Integration;

use RuntimeException;
use stdClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use Pontifex\Migrate\DatabaseRewriter;
use Pontifex\Migrate\SerialisedReplacer;
use Pontifex\Migrate\WpdbMigrationDatabase;

// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize,WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- This suite seeds and verifies serialised fixtures in a real database to prove the pass rewrites them safely.

/**
 * Proves the rewrite pass works against a real MySQL database, not just fakes.
 *
 * Seeds a scratch table (prefixed like a real WordPress table, but the test's
 * own and dropped in tear_down) with the value shapes a migration meets — a
 * plain URL, a serialised array, a utf8mb4 string, a serialised object, and a
 * row with no match — then runs the pass **scoped to that one table** so no
 * real wp_* row is ever touched. It asserts the serialised byte length is
 * recomputed correctly through real MySQL, the object row is left untouched,
 * and a genuine `$wpdb` error surfaces as a throw rather than a silent skip.
 */
final class DatabaseRewriteTest extends TestCase {

	/**
	 * Fully-prefixed name of the scratch table the test rewrites.
	 *
	 * @var string
	 */
	private string $scratch_table = '';

	/**
	 * The serialised object stored in the table — expected to survive unchanged.
	 *
	 * @var string
	 */
	private string $blocked_blob = '';

	/**
	 * Create and seed the scratch table with the value shapes a migration meets.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();
		global $wpdb;

		$this->scratch_table = $wpdb->prefix . 'pontifex_migrate_test';

		$blocked            = new stdClass();
		$blocked->url       = 'https://old.test';
		$this->blocked_blob = serialize( $blocked );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test: drop any leftover scratch table.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $this->scratch_table ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test: create the isolated scratch table.
		$wpdb->query( $wpdb->prepare( 'CREATE TABLE %i ( id INT PRIMARY KEY, body LONGTEXT ) DEFAULT CHARSET=utf8mb4', $this->scratch_table ) );

		$rows = array(
			1 => 'https://old.test/page',
			2 => serialize(
				array(
					'home'  => 'https://old.test',
					'count' => 5,
				)
			),
			3 => 'visiting café ☕ at https://old.test',
			4 => $this->blocked_blob,
			5 => 'no url here',
		);
		foreach ( $rows as $id => $body ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test: seed the scratch table.
			$wpdb->query( $wpdb->prepare( 'INSERT INTO %i ( id, body ) VALUES ( %d, %s )', $this->scratch_table, $id, $body ) );
		}
	}

	/**
	 * Drop the scratch table.
	 *
	 * @return void
	 */
	protected function tear_down(): void {
		global $wpdb;
		if ( '' !== $this->scratch_table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test cleanup: drop the scratch table.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $this->scratch_table ) );
		}
		parent::tear_down();
	}

	/**
	 * Build a rewriter scoped to the scratch table only.
	 *
	 * @return DatabaseRewriter
	 */
	private function rewriter(): DatabaseRewriter {
		global $wpdb;
		return new DatabaseRewriter(
			new WpdbMigrationDatabase( $wpdb, array( $this->scratch_table ) ),
			new SerialisedReplacer()
		);
	}

	/**
	 * Read one row's body straight from the database.
	 *
	 * @param int $id The row id.
	 * @return string The stored body.
	 */
	private function body( int $id ): string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test: read a row back for assertion.
		return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT body FROM %i WHERE id = %d', $this->scratch_table, $id ) );
	}

	/**
	 * Plain, serialised and utf8mb4 values are rewritten; an object row is left alone.
	 *
	 * @return void
	 */
	public function test_rewrites_plain_serialised_and_utf8_values(): void {
		$report = $this->rewriter()->rewrite( 'old.test', 'new.example' );

		$this->assertSame( 1, $report->tables_scanned() );
		$this->assertSame( 3, $report->rows_changed() );
		$this->assertSame( 3, $report->values_changed() );
		$this->assertSame( 1, $report->skipped_values(), 'The serialised object row holds the term but must be skipped.' );

		$this->assertSame( 'https://new.example/page', $this->body( 1 ) );

		$expected_serialised = serialize(
			array(
				'home'  => 'https://new.example',
				'count' => 5,
			)
		);
		$this->assertSame( $expected_serialised, $this->body( 2 ), 'The serialised value must re-serialise with a correct byte length.' );
		$this->assertSame(
			array(
				'home'  => 'https://new.example',
				'count' => 5,
			),
			unserialize( $this->body( 2 ), array( 'allowed_classes' => false ) )
		);

		$this->assertSame( 'visiting café ☕ at https://new.example', $this->body( 3 ), 'utf8mb4 content must survive the rewrite.' );
		$this->assertSame( $this->blocked_blob, $this->body( 4 ), 'A serialised object must be left unchanged.' );
		$this->assertSame( 'no url here', $this->body( 5 ) );
	}

	/**
	 * A guid column survives a rewrite verbatim while its siblings change.
	 *
	 * WordPress treats a post's guid as permanent identity, so a URL migration
	 * must leave it holding the OLD URL — the ecosystem's documented
	 * search-replace convention — while ordinary content rewrites.
	 *
	 * @return void
	 */
	public function test_guid_column_survives_a_rewrite_verbatim(): void {
		global $wpdb;
		$posts_table = $this->scratch_table . '_posts';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test fixture: create a posts-shaped scratch table.
		$wpdb->query( $wpdb->prepare( 'CREATE TABLE %i (ID INT NOT NULL PRIMARY KEY, guid TEXT, post_content TEXT) DEFAULT CHARSET=utf8mb4', $posts_table ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test fixture: seed the post row.
		$wpdb->query( $wpdb->prepare( 'INSERT INTO %i VALUES (1, %s, %s)', $posts_table, 'https://old.test/?p=1', 'see https://old.test/page' ) );

		try {
			$rewriter = new DatabaseRewriter(
				new WpdbMigrationDatabase( $wpdb, array( $posts_table ) ),
				new SerialisedReplacer()
			);
			$report   = $rewriter->rewrite( 'old.test', 'new.example' );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test assertion: read the row back.
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT guid, post_content FROM %i WHERE ID = 1', $posts_table ), ARRAY_A );
			$this->assertSame( 'https://old.test/?p=1', $row['guid'], 'The guid must keep the OLD URL — it is permanent identity.' );
			$this->assertSame( 'see https://new.example/page', $row['post_content'], 'Ordinary content must still rewrite.' );
			$this->assertSame( 1, $report->skipped_values(), 'The untouched guid must be tallied as deliberately skipped.' );
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test cleanup: drop the scratch table.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $posts_table ) );
		}
	}

	/**
	 * A scan reports what would change but writes nothing to the database.
	 *
	 * @return void
	 */
	public function test_scan_previews_without_writing(): void {
		$report = $this->rewriter()->scan( 'old.test', 'new.example' );

		$this->assertSame( 3, $report->rows_changed() );
		$this->assertSame( 'https://old.test/page', $this->body( 1 ), 'A scan must not modify the database.' );
	}

	/**
	 * A second pass over already-rewritten data changes nothing.
	 *
	 * @return void
	 */
	public function test_is_idempotent(): void {
		$rewriter = $this->rewriter();
		$rewriter->rewrite( 'old.test', 'new.example' );
		$second = $rewriter->rewrite( 'old.test', 'new.example' );

		$this->assertSame( 0, $second->rows_changed(), 'A second pass must change nothing.' );
	}

	/**
	 * A genuine $wpdb update error (unknown column) surfaces as a throw.
	 *
	 * The real $wpdb returns false on a failed write; the adapter must turn
	 * that into a loud exception rather than report a silent success.
	 *
	 * @return void
	 */
	public function test_a_real_update_error_is_surfaced_as_a_throw(): void {
		global $wpdb;
		$db = new WpdbMigrationDatabase( $wpdb, array( $this->scratch_table ) );

		$this->expectException( RuntimeException::class );

		// 'missing_column' does not exist, so MySQL errors and $wpdb->update() returns false.
		$db->update_row( $this->scratch_table, 'id', 1, array( 'missing_column' => 'x' ) );
	}
}
