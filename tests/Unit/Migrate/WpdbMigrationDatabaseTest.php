<?php
/**
 * Unit tests for the WpdbMigrationDatabase adapter.
 *
 * @package Pontifex\Tests\Unit\Migrate
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Migrate;

require_once __DIR__ . '/../Manifest/Fakes/WpdbStub.php';

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Pontifex\Migrate\WpdbMigrationDatabase;
use wpdb;

/**
 * Tests for {@see WpdbMigrationDatabase}.
 *
 * Mocks $wpdb against the minimal stub in Manifest/Fakes/WpdbStub.php — the
 * one place migration code touches $wpdb directly. The critical case is the
 * $wpdb->update() === false path: the real $wpdb returns false (it does not
 * throw) on a failed write, and the adapter must turn that into a loud throw
 * rather than report a silent success.
 */
final class WpdbMigrationDatabaseTest extends TestCase {

	/**
	 * Build a mock wpdb suitable for injecting into WpdbMigrationDatabase.
	 *
	 * @return wpdb&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function mock_wpdb() {
		$mock             = $this->createMock( wpdb::class );
		$mock->prefix     = 'wp_';
		$mock->last_error = '';
		return $mock;
	}

	/**
	 * Without an explicit scope, list_tables returns the prefixed tables alphabetically.
	 *
	 * @return void
	 */
	public function test_list_tables_returns_alphabetised_prefixed_tables(): void {
		$wpdb = $this->mock_wpdb();
		$wpdb->method( 'esc_like' )->willReturnArgument( 0 );
		$wpdb->method( 'prepare' )->willReturn( "SHOW TABLES LIKE 'wp_%'" );
		$wpdb->method( 'get_col' )->willReturn( array( 'wp_postmeta', 'wp_options', 'wp_posts' ) );

		$tables = ( new WpdbMigrationDatabase( $wpdb ) )->list_tables();

		$this->assertSame( array( 'wp_options', 'wp_postmeta', 'wp_posts' ), $tables );
	}

	/**
	 * An explicit table scope is returned verbatim, without querying the database.
	 *
	 * @return void
	 */
	public function test_list_tables_returns_the_explicit_scope_without_querying(): void {
		$wpdb = $this->mock_wpdb();
		$wpdb->expects( $this->never() )->method( 'get_col' );

		$tables = ( new WpdbMigrationDatabase( $wpdb, array( 'wp_scratch' ) ) )->list_tables();

		$this->assertSame( array( 'wp_scratch' ), $tables );
	}

	/**
	 * The list_tables method throws when $wpdb signals an error.
	 *
	 * @return void
	 */
	public function test_list_tables_throws_on_error(): void {
		$wpdb             = $this->mock_wpdb();
		$wpdb->last_error = 'connection lost';
		$wpdb->method( 'esc_like' )->willReturnArgument( 0 );
		$wpdb->method( 'prepare' )->willReturn( '' );
		$wpdb->method( 'get_col' )->willReturn( array() );

		$this->expectException( RuntimeException::class );

		( new WpdbMigrationDatabase( $wpdb ) )->list_tables();
	}

	/**
	 * The list_tables method refuses an empty result even with no error signalled.
	 *
	 * A silently-failed SHOW TABLES returns [] with an empty last_error under
	 * suppress_errors; a real install always has {prefix}options, so the migration must
	 * refuse rather than rewrite zero tables and report a hollow success.
	 *
	 * @return void
	 */
	public function test_list_tables_refuses_an_empty_result_without_error(): void {
		$wpdb = $this->mock_wpdb();
		$wpdb->method( 'esc_like' )->willReturnArgument( 0 );
		$wpdb->method( 'prepare' )->willReturn( "SHOW TABLES LIKE 'wp_%'" );
		$wpdb->method( 'get_col' )->willReturn( array() );
		$wpdb->method( 'get_var' )->willReturn( null );

		$this->expectException( RuntimeException::class );

		( new WpdbMigrationDatabase( $wpdb ) )->list_tables();
	}

	/**
	 * A single-column primary key returns that column's name.
	 *
	 * @return void
	 */
	public function test_primary_key_returns_the_single_column(): void {
		$wpdb = $this->mock_wpdb();
		$wpdb->method( 'prepare' )->willReturn( '' );
		$wpdb->method( 'get_results' )->willReturn( array( array( 'Column_name' => 'option_id' ) ) );

		$this->assertSame( 'option_id', ( new WpdbMigrationDatabase( $wpdb ) )->primary_key( 'wp_options' ) );
	}

	/**
	 * A composite (multi-column) primary key returns null so the table is skipped.
	 *
	 * @return void
	 */
	public function test_primary_key_is_null_for_a_composite_key(): void {
		$wpdb = $this->mock_wpdb();
		$wpdb->method( 'prepare' )->willReturn( '' );
		$wpdb->method( 'get_results' )->willReturn(
			array(
				array( 'Column_name' => 'object_id' ),
				array( 'Column_name' => 'term_taxonomy_id' ),
			)
		);

		$this->assertNull( ( new WpdbMigrationDatabase( $wpdb ) )->primary_key( 'wp_term_relationships' ) );
	}

	/**
	 * A table with no primary key returns null.
	 *
	 * @return void
	 */
	public function test_primary_key_is_null_when_there_is_no_key(): void {
		$wpdb = $this->mock_wpdb();
		$wpdb->method( 'prepare' )->willReturn( '' );
		$wpdb->method( 'get_results' )->willReturn( array() );

		$this->assertNull( ( new WpdbMigrationDatabase( $wpdb ) )->primary_key( 'wp_keyless' ) );
	}

	/**
	 * The primary_key method throws when the key lookup errors.
	 *
	 * @return void
	 */
	public function test_primary_key_throws_on_error(): void {
		$wpdb             = $this->mock_wpdb();
		$wpdb->last_error = 'no such table';
		$wpdb->method( 'prepare' )->willReturn( '' );
		$wpdb->method( 'get_results' )->willReturn( null );

		$this->expectException( RuntimeException::class );

		( new WpdbMigrationDatabase( $wpdb ) )->primary_key( 'wp_missing' );
	}

	/**
	 * Row windows must be ordered by the primary key, resolved once per table.
	 *
	 * Without an ORDER BY, consecutive windows can overlap or leave gaps — a
	 * row that silently never gets its URLs rewritten.
	 *
	 * @return void
	 */
	public function test_read_rows_orders_by_the_primary_key(): void {
		$wpdb     = $this->mock_wpdb();
		$prepared = array();
		$wpdb->method( 'prepare' )->willReturnCallback(
			static function ( string $query ) use ( &$prepared ): string {
				$prepared[] = $query;
				return $query;
			}
		);
		$wpdb->method( 'get_results' )->willReturnCallback(
			static function ( string $sql ): array {
				if ( str_contains( $sql, 'SHOW KEYS' ) ) {
					return array(
						array(
							'Column_name'  => 'ID',
							'Seq_in_index' => '1',
						),
					);
				}
				// The first window's row read (this test is about the ORDER
				// BY/keyset clause, so returning one row is enough to seed a
				// cursor for the second call to chain off).
				return array( array( 'ID' => 5 ) );
			}
		);

		$db = new WpdbMigrationDatabase( $wpdb );
		$db->read_rows( 'wp_posts', 0, 10 );
		$db->read_rows( 'wp_posts', 10, 10 );

		$this->assertContains( 'SELECT * FROM %i ORDER BY `ID` LIMIT %d', $prepared, 'The first window must be ordered by the primary key, with no WHERE.' );
		$this->assertContains( 'SELECT * FROM %i WHERE `ID` > %s ORDER BY `ID` LIMIT %d', $prepared, 'A later window must carry the keyset cursor.' );
		$this->assertSame( 1, count( array_filter( $prepared, static fn ( string $q ): bool => str_contains( $q, 'SHOW KEYS' ) ) ), 'The ordering key must be resolved once per table, not once per window.' );
	}

	/**
	 * A single-column-keyed table's windows chain: the full row set comes
	 * back exactly once, and a changed batch size between calls does not
	 * duplicate or skip rows — the correctness case keyset pagination exists
	 * for.
	 *
	 * @return void
	 */
	public function test_read_rows_keyset_windows_chain_across_a_changed_batch_size(): void {
		$wpdb  = $this->mock_wpdb();
		$table = array();
		for ( $id = 1; $id <= 7; ++$id ) {
			$table[] = array(
				'id'   => $id,
				'note' => "row-{$id}",
			);
		}

		// prepare() must return a string (it really does), so the query/args
		// pair is recorded here under a unique marker and looked back up by
		// get_results() below — the only way to carry the bound args through
		// a real prepare()-shaped seam into a fake filtering implementation.
		$calls = array();
		$wpdb->method( 'prepare' )->willReturnCallback(
			static function ( string $query, ...$args ) use ( &$calls ): string {
				$marker           = 'q' . count( $calls );
				$calls[ $marker ] = array(
					'query' => $query,
					'args'  => $args,
				);
				return $marker;
			}
		);
		$wpdb->method( 'get_results' )->willReturnCallback(
			static function ( string $marker ) use ( &$calls, $table ) {
				$call  = $calls[ $marker ];
				$query = $call['query'];
				$args  = $call['args'];

				if ( str_contains( $query, 'SHOW KEYS' ) ) {
					return array(
						array(
							'Column_name'  => 'id',
							'Seq_in_index' => '1',
						),
					);
				}

				$limit = (int) end( $args );

				if ( str_contains( $query, 'WHERE' ) ) {
					$after = (int) $args[1];
					$rows  = array_values( array_filter( $table, static fn ( array $r ): bool => $r['id'] > $after ) );
				} else {
					$rows = $table;
				}

				return array_slice( $rows, 0, $limit );
			}
		);

		$db = new WpdbMigrationDatabase( $wpdb );

		// First call at a batch size of 3; a resumed second call at a
		// DIFFERENT batch size of 2 — the shape a changed
		// average_row_bytes() reading between separate walks would produce.
		$first  = $db->read_rows( 'wp_posts', 0, 3 );
		$second = $db->read_rows( 'wp_posts', 3, 2 );
		$third  = $db->read_rows( 'wp_posts', 5, 10 );

		$seen = array_merge(
			array_column( $first, 'id' ),
			array_column( $second, 'id' ),
			array_column( $third, 'id' )
		);

		$this->assertSame( array( 1, 2, 3, 4, 5, 6, 7 ), $seen, 'The full row set must come back exactly once, in order, despite the batch size changing between calls.' );
	}

	/**
	 * A table with no usable primary key (absent or composite) must keep
	 * today's LIMIT/OFFSET windowing unchanged.
	 *
	 * @return void
	 */
	public function test_read_rows_without_a_usable_key_keeps_offset_windowing(): void {
		$wpdb     = $this->mock_wpdb();
		$prepared = array();
		$wpdb->method( 'prepare' )->willReturnCallback(
			static function ( string $query ) use ( &$prepared ): string {
				$prepared[] = $query;
				return $query;
			}
		);
		$wpdb->method( 'get_results' )->willReturnCallback(
			static function ( string $sql ): array {
				if ( str_contains( $sql, 'SHOW KEYS' ) ) {
					// A composite key: two rows, neither alone usable.
					return array(
						array( 'Column_name' => 'object_id' ),
						array( 'Column_name' => 'term_taxonomy_id' ),
					);
				}
				return array( array( 'object_id' => 1 ) );
			}
		);

		$db = new WpdbMigrationDatabase( $wpdb );
		$db->read_rows( 'wp_term_relationships', 0, 10 );
		$db->read_rows( 'wp_term_relationships', 10, 10 );

		$this->assertContains( 'SELECT * FROM %i LIMIT %d OFFSET %d', $prepared, 'A table with no usable key must fall back to plain LIMIT/OFFSET, unchanged from before keyset pagination.' );
		$row_reads       = array_filter( $prepared, static fn ( string $q ): bool => str_starts_with( $q, 'SELECT' ) );
		$row_reads_where = array_filter( $row_reads, static fn ( string $q ): bool => str_contains( $q, 'WHERE' ) );
		$this->assertSame( array(), $row_reads_where, 'A table with no usable key must never build a keyset WHERE clause on its row reads.' );
	}

	/**
	 * The sizing read mirrors the export adapter: Avg_row_length, or 0 when unknown.
	 *
	 * @return void
	 */
	public function test_average_row_bytes_reads_avg_row_length_or_zero(): void {
		$known = $this->mock_wpdb();
		$known->method( 'esc_like' )->willReturnArgument( 0 );
		$known->method( 'prepare' )->willReturn( 'SHOW TABLE STATUS prepared' );
		$known->method( 'get_row' )->willReturn( array( 'Avg_row_length' => '2048' ) );
		$this->assertSame( 2048, ( new WpdbMigrationDatabase( $known ) )->average_row_bytes( 'wp_posts' ) );

		$unknown = $this->mock_wpdb();
		$unknown->method( 'esc_like' )->willReturnArgument( 0 );
		$unknown->method( 'prepare' )->willReturn( 'SHOW TABLE STATUS prepared' );
		$unknown->method( 'get_row' )->willReturn( null );
		$this->assertSame( 0, ( new WpdbMigrationDatabase( $unknown ) )->average_row_bytes( 'wp_posts' ), 'An unreadable status must report the unknown answer, 0.' );
	}

	/**
	 * The read_rows method returns the rows as associative arrays.
	 *
	 * @return void
	 */
	public function test_read_rows_returns_associative_rows(): void {
		$wpdb = $this->mock_wpdb();
		$wpdb->method( 'prepare' )->willReturn( '' );
		$wpdb->method( 'get_results' )->willReturn(
			array(
				array(
					'option_id'    => '1',
					'option_value' => 'https://old.test',
				),
			)
		);

		$rows = ( new WpdbMigrationDatabase( $wpdb ) )->read_rows( 'wp_options', 0, 10 );

		$this->assertSame(
			array(
				array(
					'option_id'    => '1',
					'option_value' => 'https://old.test',
				),
			),
			$rows
		);
	}

	/**
	 * The read_rows method throws when the SELECT errors.
	 *
	 * @return void
	 */
	public function test_read_rows_throws_on_error(): void {
		$wpdb             = $this->mock_wpdb();
		$wpdb->last_error = 'lost connection mid-query';
		$wpdb->method( 'prepare' )->willReturn( '' );
		$wpdb->method( 'get_results' )->willReturn( null );

		$this->expectException( RuntimeException::class );

		( new WpdbMigrationDatabase( $wpdb ) )->read_rows( 'wp_options', 0, 10 );
	}

	/**
	 * The update_row method writes only the changed columns, keyed on the primary key.
	 *
	 * @return void
	 */
	public function test_update_row_writes_changed_columns_keyed_on_the_primary_key(): void {
		$wpdb = $this->mock_wpdb();
		$wpdb->expects( $this->once() )
			->method( 'update' )
			->with(
				'wp_options',
				array( 'option_value' => 'https://new.example' ),
				array( 'option_id' => 1 )
			)
			->willReturn( 1 );

		( new WpdbMigrationDatabase( $wpdb ) )->update_row(
			'wp_options',
			'option_id',
			1,
			array( 'option_value' => 'https://new.example' )
		);
	}

	/**
	 * A false return from $wpdb->update() becomes a thrown exception.
	 *
	 * The headline failure path: the real $wpdb returns false (it does not
	 * throw) when a write fails, so the adapter must not treat that as success.
	 *
	 * @return void
	 */
	public function test_update_row_throws_when_wpdb_update_returns_false(): void {
		$wpdb             = $this->mock_wpdb();
		$wpdb->last_error = 'duplicate entry';
		$wpdb->method( 'update' )->willReturn( false );

		$this->expectException( RuntimeException::class );

		( new WpdbMigrationDatabase( $wpdb ) )->update_row(
			'wp_options',
			'option_id',
			1,
			array( 'option_value' => 'x' )
		);
	}

	/**
	 * The update_row method refuses an empty column set rather than issue a no-op write.
	 *
	 * @return void
	 */
	public function test_update_row_rejects_empty_columns(): void {
		$wpdb = $this->mock_wpdb();
		$wpdb->expects( $this->never() )->method( 'update' );

		$this->expectException( InvalidArgumentException::class );

		( new WpdbMigrationDatabase( $wpdb ) )->update_row( 'wp_options', 'option_id', 1, array() );
	}
}
