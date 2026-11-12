<?php
/**
 * Keyset row-dump pagination integration test.
 *
 * @package Pontifex\Tests\Integration
 */

declare(strict_types=1);

namespace Pontifex\Tests\Integration;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use Pontifex\Manifest\WpdbAdapter;

/**
 * Proves WpdbAdapter::dump_table_rows()'s keyset pagination against a real
 * MySQL server (job 11): a single-column key chains by plain comparison, a
 * composite key chains by row-constructor comparison, a table with no
 * primary key is untouched by any of it, and — the correctness case this
 * job exists for — a keyset cursor survives the per-call row limit changing
 * between calls without duplicating or skipping rows, unlike the
 * LIMIT/OFFSET windowing it replaces.
 *
 * Every table here is this test's own scratch table, created in set_up()
 * and dropped in tear_down(); nothing pre-existing is touched.
 */
final class KeysetRowDumpTest extends TestCase {

	/**
	 * Single-column primary-key scratch table.
	 *
	 * @var string
	 */
	private const SINGLE_KEY_TABLE = 'wp_pontifexks_single';

	/**
	 * Composite primary-key scratch table.
	 *
	 * @var string
	 */
	private const COMPOSITE_KEY_TABLE = 'wp_pontifexks_composite';

	/**
	 * No-primary-key scratch table.
	 *
	 * @var string
	 */
	private const NO_KEY_TABLE = 'wp_pontifexks_none';

	/**
	 * Drop every scratch table before the test, in case a prior run aborted.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();
		$this->drop_scratch_tables();
	}

	/**
	 * Drop every scratch table after the test.
	 *
	 * @return void
	 */
	protected function tear_down(): void {
		$this->drop_scratch_tables();
		parent::tear_down();
	}

	/**
	 * A single-column-keyed table's windows must chain to the full row set,
	 * exactly once, even when the per-call limit changes between calls.
	 *
	 * This is the correctness bug job 11 exists to close: an OFFSET-based
	 * window silently shifts when rows_per_chunk is recomputed differently
	 * between a resumed export's ticks (measured at 166,608 rows written for
	 * a 150,000-row table); a keyset cursor is immune, because it is tied to
	 * the data itself, never to a row count.
	 *
	 * @return void
	 */
	public function test_single_column_key_windows_chain_across_a_changed_limit(): void {
		global $wpdb;

		$this->seed_single_key_table( 23 );
		$adapter = new WpdbAdapter( $wpdb );

		$seen      = array();
		$after_key = null;

		// Three calls, three DIFFERENT limits — the shape a changed
		// average_row_bytes() reading between separate export ticks produces.
		foreach ( array( 7, 4, 100 ) as $limit ) {
			$result    = $adapter->dump_table_rows( self::SINGLE_KEY_TABLE, 0, $limit, $after_key );
			$seen      = array_merge( $seen, self::extract_single_ids( $result->sql() ) );
			$after_key = $result->end_key();
		}

		$this->assertSame( range( 1, 23 ), $seen, 'The full row set must come back exactly once, in ascending order, despite the limit changing between calls.' );
	}

	/**
	 * A composite-keyed table's windows must chain via the row-constructor
	 * comparison to the full row set, exactly once.
	 *
	 * @return void
	 */
	public function test_composite_key_windows_chain_to_the_full_row_set(): void {
		global $wpdb;

		$total = 18; // (a in 1..3) x (b in 1..6).
		$this->seed_composite_key_table( 3, 6 );
		$adapter = new WpdbAdapter( $wpdb );

		$seen      = array();
		$after_key = null;

		// Bound each call's limit by what actually remains — exactly what
		// DatabaseScanner's own chunk planning does (row_count - offset) — so
		// this never asks for a window beyond the table's real end; a $limit
		// > 0 window that legitimately comes back empty is refused as a
		// failed read by job 10's guard, not read as "the table is finished".
		$seen_count = 0;
		while ( $seen_count < $total ) {
			$limit      = min( 5, $total - $seen_count );
			$result     = $adapter->dump_table_rows( self::COMPOSITE_KEY_TABLE, 0, $limit, $after_key );
			$seen       = array_merge( $seen, self::extract_composite_pairs( $result->sql() ) );
			$seen_count = count( $seen );
			$after_key  = $result->end_key();
		}

		$expected = array();
		for ( $a = 1; $a <= 3; ++$a ) {
			for ( $b = 1; $b <= 6; ++$b ) {
				$expected[] = array( $a, $b );
			}
		}

		$this->assertSame( $expected, $seen, 'The composite-key row-constructor comparison must chain to the full row set, in key order, exactly once.' );
	}

	/**
	 * A table with no primary key must ignore $after_key entirely and keep
	 * paginating by LIMIT/OFFSET, unchanged.
	 *
	 * @return void
	 */
	public function test_no_primary_key_table_ignores_after_key_and_uses_offset(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test fixture: a deliberately key-less scratch table.
		$wpdb->query( 'CREATE TABLE `' . self::NO_KEY_TABLE . '` (val INT NOT NULL) DEFAULT CHARSET=utf8mb4' );
		for ( $i = 1; $i <= 5; ++$i ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test fixture: seed a row via $wpdb->prepare().
			$wpdb->query( $wpdb->prepare( 'INSERT INTO %i VALUES ( %d )', self::NO_KEY_TABLE, $i ) );
		}

		$adapter = new WpdbAdapter( $wpdb );

		// A bogus $after_key that would corrupt the query if it were used —
		// proves it is genuinely ignored, not merely unset in this test.
		$result = $adapter->dump_table_rows( self::NO_KEY_TABLE, 0, 3, array( 'val' => 999 ) );
		$this->assertNull( $result->end_key(), 'A table with no primary key must never report an end key.' );
		$this->assertStringContainsString( 'INSERT INTO', $result->sql(), 'The read must still succeed and return rows, proving $after_key was not used as a real predicate.' );

		$next = $adapter->dump_table_rows( self::NO_KEY_TABLE, 3, 3, array( 'val' => 999 ) );
		$this->assertStringContainsString( 'INSERT INTO', $next->sql(), 'The second OFFSET window must still find the remaining rows, proving pagination is unaffected by $after_key.' );
	}

	/**
	 * Drop every scratch table this test may have created.
	 *
	 * @return void
	 */
	private function drop_scratch_tables(): void {
		global $wpdb;
		foreach ( array( self::SINGLE_KEY_TABLE, self::COMPOSITE_KEY_TABLE, self::NO_KEY_TABLE ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test cleanup: drop this test's own scratch tables.
			$wpdb->query( 'DROP TABLE IF EXISTS `' . $table . '`' );
		}
	}

	/**
	 * Create and seed the single-column-keyed scratch table with $count rows.
	 *
	 * @param int $count Row count to seed, ids 1..$count.
	 * @return void
	 */
	private function seed_single_key_table( int $count ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test fixture: create the scratch table.
		$wpdb->query( 'CREATE TABLE `' . self::SINGLE_KEY_TABLE . '` (id INT NOT NULL PRIMARY KEY) DEFAULT CHARSET=utf8mb4' );
		for ( $id = 1; $id <= $count; ++$id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test fixture: seed a row via $wpdb->prepare().
			$wpdb->query( $wpdb->prepare( 'INSERT INTO %i VALUES ( %d )', self::SINGLE_KEY_TABLE, $id ) );
		}
	}

	/**
	 * Create and seed the composite-keyed scratch table with $a_max * $b_max rows.
	 *
	 * @param int $a_max Highest value of column a (1-based).
	 * @param int $b_max Highest value of column b (1-based).
	 * @return void
	 */
	private function seed_composite_key_table( int $a_max, int $b_max ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test fixture: create the scratch table.
		$wpdb->query( 'CREATE TABLE `' . self::COMPOSITE_KEY_TABLE . '` (a INT NOT NULL, b INT NOT NULL, PRIMARY KEY (a, b)) DEFAULT CHARSET=utf8mb4' );
		for ( $a = 1; $a <= $a_max; ++$a ) {
			for ( $b = 1; $b <= $b_max; ++$b ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test fixture: seed a row via $wpdb->prepare().
				$wpdb->query( $wpdb->prepare( 'INSERT INTO %i VALUES ( %d, %d )', self::COMPOSITE_KEY_TABLE, $a, $b ) );
			}
		}
	}

	/**
	 * Extract every id from a single-column-key INSERT statement's VALUES tuples.
	 *
	 * The captured digits may or may not be single-quoted: wpdb's own
	 * ARRAY_A rows come back as PHP strings even for an INT column (a real
	 * MySQLi/wpdb behaviour, not a test artefact), so
	 * {@see \Pontifex\Manifest\WpdbAdapter::encode_value()} correctly quotes
	 * them — only a genuinely PHP-int/float value is ever emitted bare.
	 *
	 * @param string $sql The realised INSERT SQL, or ''.
	 * @return int[] The ids, in the order they appear.
	 */
	private static function extract_single_ids( string $sql ): array {
		if ( '' === $sql ) {
			return array();
		}
		preg_match_all( "/\('?(\d+)'?\)/", $sql, $matches );
		return array_map( 'intval', $matches[1] );
	}

	/**
	 * Extract every (a, b) pair from a composite-key INSERT statement's VALUES tuples.
	 *
	 * See {@see self::extract_single_ids()} for why the digits may be quoted.
	 *
	 * @param string $sql The realised INSERT SQL, or ''.
	 * @return array<int, array{0: int, 1: int}> The pairs, in the order they appear.
	 */
	private static function extract_composite_pairs( string $sql ): array {
		if ( '' === $sql ) {
			return array();
		}
		preg_match_all( "/\('?(\d+)'?, '?(\d+)'?\)/", $sql, $matches, PREG_SET_ORDER );
		$pairs = array();
		foreach ( $matches as $match ) {
			$pairs[] = array( (int) $match[1], (int) $match[2] );
		}
		return $pairs;
	}
}
