<?php
/**
 * Unit tests for the WpdbAdapter class.
 *
 * @package Pontifex\Tests\Unit\Manifest
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Manifest;

require_once __DIR__ . '/Fakes/WpdbStub.php';

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Pontifex\Manifest\WpdbAdapter;
use wpdb;

/**
 * Tests for {@see WpdbAdapter}.
 *
 * Uses PHPUnit's createMock against a minimal wpdb stub loaded from
 * Fakes/WpdbStub.php (which only defines wpdb when WordPress is not
 * loaded). This is the one place in the Pontifex codebase that mocks
 * $wpdb directly; everywhere else, the DatabaseAdapter interface
 * allows testing without WP.
 */
final class WpdbAdapterTest extends TestCase {

	/**
	 * Build a mock wpdb suitable for injecting into WpdbAdapter.
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
	 * The list_tables method must return an alphabetised list of strings from $wpdb->get_col().
	 *
	 * @return void
	 */
	public function test_list_tables_returns_alphabetised_strings(): void {
		$wpdb = $this->mock_wpdb();
		$wpdb->method( 'esc_like' )->willReturnArgument( 0 );
		$wpdb->method( 'prepare' )->willReturn( "SHOW TABLES LIKE 'wp_%'" );
		$wpdb->method( 'get_col' )->willReturn( array( 'wp_postmeta', 'wp_options', 'wp_posts' ) );

		$tables = ( new WpdbAdapter( $wpdb ) )->list_tables();

		$this->assertSame( array( 'wp_options', 'wp_postmeta', 'wp_posts' ), $tables );
	}

	/**
	 * The list_tables method must throw when $wpdb signals an error.
	 *
	 * @return void
	 */
	public function test_list_tables_throws_on_error(): void {
		$wpdb             = $this->mock_wpdb();
		$wpdb->last_error = 'Connection lost';
		$wpdb->method( 'esc_like' )->willReturnArgument( 0 );
		$wpdb->method( 'prepare' )->willReturn( '' );
		$wpdb->method( 'get_col' )->willReturn( array() );

		$this->expectException( RuntimeException::class );

		( new WpdbAdapter( $wpdb ) )->list_tables();
	}

	/**
	 * The list_tables method must refuse an empty result even with no error signalled.
	 *
	 * A silently-failed query returns [] from get_col() (not false), and last_error is empty
	 * under suppress_errors — so both failure signals can be silent at once. A real install
	 * always has {prefix}options, so an empty result is a failure, and a backup must not be
	 * produced with no database tables in it.
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

		( new WpdbAdapter( $wpdb ) )->list_tables();
	}

	/**
	 * The row_count method must return the integer value from $wpdb->get_var().
	 *
	 * @return void
	 */
	public function test_row_count_returns_integer(): void {
		$wpdb = $this->mock_wpdb();
		$wpdb->method( 'prepare' )->willReturn( '' );
		$wpdb->method( 'get_var' )->willReturn( '12345' );

		$count = ( new WpdbAdapter( $wpdb ) )->row_count( 'wp_posts' );

		$this->assertSame( 12345, $count );
	}

	/**
	 * The row_count method must throw when $wpdb->get_var() returns null.
	 *
	 * @return void
	 */
	public function test_row_count_throws_when_get_var_returns_null(): void {
		$wpdb             = $this->mock_wpdb();
		$wpdb->last_error = 'syntax error near table_name';
		$wpdb->method( 'prepare' )->willReturn( '' );
		$wpdb->method( 'get_var' )->willReturn( null );

		$this->expectException( RuntimeException::class );

		( new WpdbAdapter( $wpdb ) )->row_count( 'wp_posts' );
	}

	/**
	 * The dump_table_schema method must prepend DROP TABLE IF EXISTS and append a newline.
	 *
	 * @return void
	 */
	public function test_dump_table_schema_includes_drop_and_create(): void {
		$wpdb = $this->mock_wpdb();
		$wpdb->method( 'prepare' )->willReturn( '' );
		$wpdb->method( 'get_row' )->willReturn( array( 'wp_posts', 'CREATE TABLE `wp_posts` (id INT)' ) );

		$schema = ( new WpdbAdapter( $wpdb ) )->dump_table_schema( 'wp_posts' );

		$this->assertStringContainsString( 'DROP TABLE IF EXISTS `wp_posts`', $schema );
		$this->assertStringContainsString( 'CREATE TABLE `wp_posts`', $schema );
		$this->assertStringEndsWith( "\n", $schema );
	}

	/**
	 * The dump_table_schema method must throw if SHOW CREATE TABLE returns no usable row.
	 *
	 * @return void
	 */
	public function test_dump_table_schema_throws_when_no_row(): void {
		$wpdb             = $this->mock_wpdb();
		$wpdb->last_error = "table doesn't exist";
		$wpdb->method( 'prepare' )->willReturn( '' );
		$wpdb->method( 'get_row' )->willReturn( null );

		$this->expectException( RuntimeException::class );

		( new WpdbAdapter( $wpdb ) )->dump_table_schema( 'wp_missing' );
	}

	/**
	 * The dump_table_rows method must produce a multi-VALUE INSERT statement when rows exist.
	 *
	 * @return void
	 */
	public function test_dump_table_rows_produces_multi_value_insert(): void {
		$wpdb = $this->mock_wpdb();
		$wpdb->method( 'prepare' )->willReturn( 'SELECT * FROM `wp_posts` LIMIT 2 OFFSET 0' );
		$wpdb->method( '_real_escape' )->willReturnCallback(
			static fn( string $value ): string => str_replace( "'", "''", $value )
		);
		$wpdb->method( 'get_results' )->willReturn(
			array(
				array(
					'id'    => 1,
					'title' => "First's post",
				),
				array(
					'id'    => 2,
					'title' => 'Second post',
				),
			)
		);

		$sql = ( new WpdbAdapter( $wpdb ) )->dump_table_rows( 'wp_posts', 0, 2 );

		$this->assertStringContainsString( 'INSERT INTO `wp_posts`', $sql );
		$this->assertStringContainsString( '`id`, `title`', $sql );
		$this->assertStringContainsString( 'First', $sql );
		$this->assertStringContainsString( 'Second post', $sql );
		$this->assertStringEndsWith( "\n", $sql );
	}

	/**
	 * The dump_table_rows method must return an empty string when no rows match.
	 *
	 * @return void
	 */
	public function test_dump_table_rows_returns_empty_string_for_no_rows(): void {
		$wpdb = $this->mock_wpdb();
		$wpdb->method( 'prepare' )->willReturn( '' );
		$wpdb->method( 'get_results' )->willReturn( array() );

		$sql = ( new WpdbAdapter( $wpdb ) )->dump_table_rows( 'wp_posts', 100000, 10 );

		$this->assertSame( '', $sql );
	}

	/**
	 * The dump_table_rows method must throw when $wpdb signals an error.
	 *
	 * @return void
	 */
	public function test_dump_table_rows_throws_on_error(): void {
		$wpdb             = $this->mock_wpdb();
		$wpdb->last_error = 'Lost connection mid-query';
		$wpdb->method( 'prepare' )->willReturn( '' );
		$wpdb->method( 'get_results' )->willReturn( null );

		$this->expectException( RuntimeException::class );

		( new WpdbAdapter( $wpdb ) )->dump_table_rows( 'wp_posts', 0, 10 );
	}

	/**
	 * NULL values in row data must be encoded as the SQL literal NULL.
	 *
	 * @return void
	 */
	public function test_dump_table_rows_encodes_null_values(): void {
		$wpdb = $this->mock_wpdb();
		$wpdb->method( 'prepare' )->willReturn( '' );
		$wpdb->method( 'get_results' )->willReturn(
			array(
				array(
					'id'   => 1,
					'note' => null,
				),
			)
		);

		$sql = ( new WpdbAdapter( $wpdb ) )->dump_table_rows( 'wp_posts', 0, 1 );

		$this->assertStringContainsString( 'NULL', $sql );
	}

	/**
	 * Integer values in row data must be encoded without quotes.
	 *
	 * @return void
	 */
	public function test_dump_table_rows_encodes_integers_without_quotes(): void {
		$wpdb = $this->mock_wpdb();
		$wpdb->method( 'prepare' )->willReturn( '' );
		$wpdb->method( 'get_results' )->willReturn(
			array(
				array( 'id' => 42 ),
			)
		);

		$sql = ( new WpdbAdapter( $wpdb ) )->dump_table_rows( 'wp_posts', 0, 1 );

		$this->assertStringContainsString( 'VALUES (42)', $sql );
	}

	/**
	 * A per-table method refuses a table name outside the WordPress prefix.
	 *
	 * A scope guard: tables come from list_tables() (SHOW TABLES LIKE prefix%), so
	 * a name outside the prefix indicates a caller passing an externally-influenced
	 * name, which must not be dumped into an export.
	 *
	 * @return void
	 */
	public function test_per_table_method_refuses_table_outside_prefix(): void {
		$wpdb = $this->mock_wpdb();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'outside the WordPress prefix' );

		( new WpdbAdapter( $wpdb ) )->dump_table_rows( 'mysql.user', 0, 1 );
	}

	/**
	 * A false query() return throws even with an empty last_error.
	 *
	 * A real $wpdb returns false on a failed query and, under suppress_errors,
	 * leaves last_error empty — so checking last_error alone would let a failed
	 * restore statement pass as success. This is the data-loss path.
	 *
	 * @return void
	 */
	public function test_execute_sql_throws_when_query_returns_false(): void {
		$wpdb             = $this->mock_wpdb();
		$wpdb->last_error = '';
		$wpdb->method( 'query' )->willReturn( false );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'query returned false' );

		( new WpdbAdapter( $wpdb ) )->execute_sql( 'DROP TABLE wp_posts;' );
	}

	/**
	 * A successful query (affected-row count) does not throw.
	 *
	 * @return void
	 */
	public function test_execute_sql_succeeds_on_a_successful_query(): void {
		$this->expectNotToPerformAssertions();

		$wpdb             = $this->mock_wpdb();
		$wpdb->last_error = '';
		$wpdb->method( 'query' )->willReturn( 1 );

		( new WpdbAdapter( $wpdb ) )->execute_sql( 'CREATE TABLE wp_x (id INT);' );
	}

	/**
	 * The prefix-key rewrite must run one prepared UPDATE for options and one for usermeta.
	 *
	 * @return void
	 */
	public function test_rewrite_prefix_keys_runs_two_updates(): void {
		$wpdb = $this->mock_wpdb();
		$wpdb->method( 'esc_like' )->willReturnArgument( 0 );
		$wpdb->method( 'prepare' )->willReturn( 'UPDATE prepared' );
		$wpdb->expects( $this->exactly( 2 ) )->method( 'query' )->willReturn( 1 );

		( new WpdbAdapter( $wpdb ) )->rewrite_prefix_keys( 'wp_', 'xyz_' );
	}

	/**
	 * The prefix-key rewrite must do nothing when the prefixes are equal.
	 *
	 * @return void
	 */
	public function test_rewrite_prefix_keys_same_prefix_does_nothing(): void {
		$wpdb = $this->mock_wpdb();
		$wpdb->expects( $this->never() )->method( 'query' );

		( new WpdbAdapter( $wpdb ) )->rewrite_prefix_keys( 'wp_', 'wp_' );
	}

	/**
	 * The prefix-key rewrite must throw when a rewrite UPDATE fails.
	 *
	 * @return void
	 */
	public function test_rewrite_prefix_keys_throws_on_query_failure(): void {
		$wpdb             = $this->mock_wpdb();
		$wpdb->last_error = '';
		$wpdb->method( 'esc_like' )->willReturnArgument( 0 );
		$wpdb->method( 'prepare' )->willReturn( 'UPDATE prepared' );
		$wpdb->method( 'query' )->willReturn( false );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'prefix-key rewrite' );

		( new WpdbAdapter( $wpdb ) )->rewrite_prefix_keys( 'wp_', 'xyz_' );
	}

	/**
	 * A staging prefix must be prepended to the table names the rewrite targets.
	 *
	 * During a staging-table restore the options/usermeta copies carry the
	 * staging prefix until the cut-over; the UPDATEs must land on those staged
	 * names, never on the still-live tables.
	 *
	 * @return void
	 */
	public function test_rewrite_prefix_keys_targets_staged_tables(): void {
		$wpdb = $this->mock_wpdb();
		$wpdb->method( 'esc_like' )->willReturnArgument( 0 );
		$prepared_tables = array();
		$wpdb->method( 'prepare' )->willReturnCallback(
			static function ( string $query, ...$args ) use ( &$prepared_tables ): string {
				$prepared_tables[] = (string) $args[0];
				return 'UPDATE prepared';
			}
		);
		$wpdb->method( 'query' )->willReturn( 1 );

		( new WpdbAdapter( $wpdb ) )->rewrite_prefix_keys( 'wp_', 'xyz_', 'pontifexstg_' );

		$this->assertSame(
			array( 'pontifexstg_xyz_options', 'pontifexstg_xyz_usermeta' ),
			$prepared_tables,
			'The UPDATEs must target the staged copies, not the live tables.'
		);
	}

	/**
	 * The table_exists() probe must report true when SHOW TABLES finds the name.
	 *
	 * @return void
	 */
	public function test_table_exists_reports_a_found_table(): void {
		$wpdb = $this->mock_wpdb();
		$wpdb->method( 'esc_like' )->willReturnArgument( 0 );
		$wpdb->method( 'prepare' )->willReturn( 'SHOW TABLES prepared' );
		$wpdb->method( 'get_var' )->willReturn( 'wp_posts' );

		$this->assertTrue( ( new WpdbAdapter( $wpdb ) )->table_exists( 'wp_posts' ) );
	}

	/**
	 * The table_exists() probe must report false on no result — including a query error.
	 *
	 * A wrong "does not exist" is the safe direction: the cut-over RENAME stays
	 * the atomic arbiter and fails whole if the answer mattered.
	 *
	 * @return void
	 */
	public function test_table_exists_reports_false_when_absent(): void {
		$wpdb = $this->mock_wpdb();
		$wpdb->method( 'esc_like' )->willReturnArgument( 0 );
		$wpdb->method( 'prepare' )->willReturn( 'SHOW TABLES prepared' );
		$wpdb->method( 'get_var' )->willReturn( null );

		$this->assertFalse( ( new WpdbAdapter( $wpdb ) )->table_exists( 'wp_missing' ) );
	}

	/**
	 * The prefix listing must return the matching names, alphabetised.
	 *
	 * @return void
	 */
	public function test_list_tables_by_prefix_returns_sorted_matches(): void {
		$wpdb = $this->mock_wpdb();
		$wpdb->method( 'esc_like' )->willReturnArgument( 0 );
		$wpdb->method( 'prepare' )->willReturn( 'SHOW TABLES prepared' );
		$wpdb->method( 'get_col' )->willReturn( array( 'pontifexstg_wp_posts', 'pontifexstg_wp_options' ) );

		$this->assertSame(
			array( 'pontifexstg_wp_options', 'pontifexstg_wp_posts' ),
			( new WpdbAdapter( $wpdb ) )->list_tables_by_prefix( 'pontifexstg_' )
		);
	}

	/**
	 * Opening a snapshot must set REPEATABLE READ, then begin the transaction —
	 * and releasing the adapter must commit it.
	 *
	 * The destructor commit is the deterministic release of the snapshot's
	 * metadata locks (ADR 0011): when the export's adapter goes out of scope,
	 * the locks must go with it, or a restore's cut-over RENAME in the same
	 * request would block against our own dump.
	 *
	 * @return void
	 */
	public function test_begin_consistent_snapshot_issues_isolation_then_begin(): void {
		$wpdb    = $this->mock_wpdb();
		$queries = array();
		$wpdb->method( 'query' )->willReturnCallback(
			static function ( string $sql ) use ( &$queries ) {
				$queries[] = $sql;
				return 1;
			}
		);

		$adapter = new WpdbAdapter( $wpdb );
		$this->assertTrue( $adapter->begin_consistent_snapshot() );
		$this->assertSame(
			array(
				'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ',
				'START TRANSACTION WITH CONSISTENT SNAPSHOT',
			),
			$queries,
			'The isolation level must be set before the snapshot transaction opens.'
		);

		unset( $adapter );
		$this->assertSame( 'COMMIT', end( $queries ), 'Releasing the adapter must commit the snapshot it opened.' );
	}

	/**
	 * A snapshot that cannot open must report false, never throw.
	 *
	 * The caller falls back to dumping without a snapshot — today's behaviour —
	 * because a possibly-fuzzy backup beats no backup.
	 *
	 * @return void
	 */
	public function test_begin_consistent_snapshot_reports_failure_quietly(): void {
		$first = $this->mock_wpdb();
		$first->method( 'query' )->willReturn( false );
		$this->assertFalse( ( new WpdbAdapter( $first ) )->begin_consistent_snapshot() );

		$second  = $this->mock_wpdb();
		$replies = array( 1, false );
		$second->method( 'query' )->willReturnCallback(
			static function () use ( &$replies ) {
				return array_shift( $replies );
			}
		);
		$this->assertFalse( ( new WpdbAdapter( $second ) )->begin_consistent_snapshot(), 'A failed START TRANSACTION must report false too.' );
	}

	/**
	 * Row dumps must be ordered by the primary key so pagination is stable.
	 *
	 * Without an ORDER BY, MySQL guarantees no row order, so consecutive OFFSET
	 * windows can overlap or leave gaps — the root of a real live-site incident.
	 *
	 * @return void
	 */
	public function test_dump_table_rows_orders_by_the_primary_key(): void {
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
				return array();
			}
		);

		( new WpdbAdapter( $wpdb ) )->dump_table_rows( 'wp_posts', 0, 10 );

		$this->assertContains( 'SELECT * FROM %i ORDER BY `ID` LIMIT %d OFFSET %d', $prepared, 'The row dump must carry an ORDER BY over the primary key.' );
	}

	/**
	 * A composite primary key must order by every key column, in key order.
	 *
	 * SHOW KEYS reports one row per key column with its Seq_in_index; the clause
	 * must follow that sequence (e.g. term_relationships orders by object_id,
	 * then term_taxonomy_id), not the arrival order of the rows.
	 *
	 * @return void
	 */
	public function test_dump_table_rows_orders_composite_keys_in_key_order(): void {
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
					// Deliberately out of key order, as arrival order is not guaranteed.
					return array(
						array(
							'Column_name'  => 'term_taxonomy_id',
							'Seq_in_index' => '2',
						),
						array(
							'Column_name'  => 'object_id',
							'Seq_in_index' => '1',
						),
					);
				}
				return array();
			}
		);

		( new WpdbAdapter( $wpdb ) )->dump_table_rows( 'wp_term_relationships', 0, 10 );

		$this->assertContains( 'SELECT * FROM %i ORDER BY `object_id`, `term_taxonomy_id` LIMIT %d OFFSET %d', $prepared );
	}

	/**
	 * A table without a primary key must order by every column.
	 *
	 * The only deterministic sort left; such tables are rare and usually small.
	 *
	 * @return void
	 */
	public function test_dump_table_rows_without_a_primary_key_orders_by_all_columns(): void {
		$wpdb     = $this->mock_wpdb();
		$prepared = array();
		$wpdb->method( 'prepare' )->willReturnCallback(
			static function ( string $query ) use ( &$prepared ): string {
				$prepared[] = $query;
				return $query;
			}
		);
		$wpdb->method( 'get_results' )->willReturn( array() );
		$wpdb->method( 'get_col' )->willReturn( array( 'colour', 'shape' ) );

		( new WpdbAdapter( $wpdb ) )->dump_table_rows( 'wp_keyless', 0, 10 );

		$this->assertContains( 'SELECT * FROM %i ORDER BY `colour`, `shape` LIMIT %d OFFSET %d', $prepared );
	}

	/**
	 * The ordering columns must be resolved once per table, not once per chunk.
	 *
	 * A large table dumps as many chunks; the SHOW KEYS schema read is cached so
	 * every chunk after the first pays nothing for the stable order.
	 *
	 * @return void
	 */
	public function test_dump_table_rows_caches_the_ordering_columns_per_table(): void {
		$wpdb      = $this->mock_wpdb();
		$key_reads = 0;
		$wpdb->method( 'prepare' )->willReturnCallback(
			static function ( string $query ): string {
				return $query;
			}
		);
		$wpdb->method( 'get_results' )->willReturnCallback(
			static function ( string $sql ) use ( &$key_reads ): array {
				if ( str_contains( $sql, 'SHOW KEYS' ) ) {
					++$key_reads;
					return array(
						array(
							'Column_name'  => 'ID',
							'Seq_in_index' => '1',
						),
					);
				}
				return array();
			}
		);

		$adapter = new WpdbAdapter( $wpdb );
		$adapter->dump_table_rows( 'wp_posts', 0, 10 );
		$adapter->dump_table_rows( 'wp_posts', 10, 10 );
		$adapter->dump_table_rows( 'wp_posts', 20, 10 );

		$this->assertSame( 1, $key_reads, 'SHOW KEYS must run once per table, not once per chunk.' );
	}

	/**
	 * Switching the session charset must issue SET NAMES with the given charset.
	 *
	 * @return void
	 */
	public function test_set_session_charset_issues_set_names(): void {
		$wpdb    = $this->mock_wpdb();
		$queries = array();
		$wpdb->method( 'query' )->willReturnCallback(
			static function ( string $sql ) use ( &$queries ) {
				$queries[] = $sql;
				return 1;
			}
		);

		( new WpdbAdapter( $wpdb ) )->set_session_charset( 'utf8mb4' );

		$this->assertSame( array( "SET NAMES 'utf8mb4'" ), $queries );
	}

	/**
	 * A malformed charset must be refused before it can reach SQL.
	 *
	 * Defence in depth: the writer validated it too, but this adapter is the
	 * last gate before interpolation.
	 *
	 * @return void
	 */
	public function test_set_session_charset_refuses_a_malformed_charset(): void {
		$wpdb = $this->mock_wpdb();
		$wpdb->expects( $this->never() )->method( 'query' );

		$this->expectException( RuntimeException::class );

		( new WpdbAdapter( $wpdb ) )->set_session_charset( "utf8'; --" );
	}

	/**
	 * Restoring the session charset must hand back the connection's own charset.
	 *
	 * And it is best-effort: a failed SET NAMES is swallowed, because the
	 * restored data is already committed and must not be masked by cleanup.
	 *
	 * @return void
	 */
	public function test_restore_session_charset_hands_back_wpdb_charset_best_effort(): void {
		$wpdb          = $this->mock_wpdb();
		$wpdb->charset = 'utf8mb4';
		$queries       = array();
		$wpdb->method( 'query' )->willReturnCallback(
			static function ( string $sql ) use ( &$queries ) {
				$queries[] = $sql;
				return false;
			}
		);

		( new WpdbAdapter( $wpdb ) )->restore_session_charset();

		$this->assertSame( array( "SET NAMES 'utf8mb4'" ), $queries, 'The failed hand-back must be attempted once and swallowed.' );
	}

	/**
	 * The average row width must come from SHOW TABLE STATUS's Avg_row_length.
	 *
	 * @return void
	 */
	public function test_average_row_bytes_reads_avg_row_length(): void {
		$wpdb = $this->mock_wpdb();
		$wpdb->method( 'esc_like' )->willReturnArgument( 0 );
		$wpdb->method( 'prepare' )->willReturn( 'SHOW TABLE STATUS prepared' );
		$wpdb->method( 'get_row' )->willReturn(
			array(
				'Name'           => 'wp_posts',
				'Avg_row_length' => '3172',
			)
		);

		$this->assertSame( 3172, ( new WpdbAdapter( $wpdb ) )->average_row_bytes( 'wp_posts' ) );
	}

	/**
	 * The average row width must report 0 when the status row is unusable.
	 *
	 * A missing row (query error), an absent column, or a non-numeric value all
	 * report 0 — the "unknown" answer that sends the scanner to its fixed
	 * estimate; sizing is a hint, never a gate.
	 *
	 * @return void
	 */
	public function test_average_row_bytes_reports_zero_when_unknown(): void {
		$missing = $this->mock_wpdb();
		$missing->method( 'esc_like' )->willReturnArgument( 0 );
		$missing->method( 'prepare' )->willReturn( 'SHOW TABLE STATUS prepared' );
		$missing->method( 'get_row' )->willReturn( null );
		$this->assertSame( 0, ( new WpdbAdapter( $missing ) )->average_row_bytes( 'wp_posts' ) );

		$junk = $this->mock_wpdb();
		$junk->method( 'esc_like' )->willReturnArgument( 0 );
		$junk->method( 'prepare' )->willReturn( 'SHOW TABLE STATUS prepared' );
		$junk->method( 'get_row' )->willReturn( array( 'Avg_row_length' => 'not-a-number' ) );
		$this->assertSame( 0, ( new WpdbAdapter( $junk ) )->average_row_bytes( 'wp_posts' ) );
	}

	/**
	 * The prefix listing must refuse an empty prefix.
	 *
	 * An empty prefix would list the entire database — never what the leftover
	 * sweep intends.
	 *
	 * @return void
	 */
	public function test_list_tables_by_prefix_refuses_an_empty_prefix(): void {
		$this->expectException( RuntimeException::class );

		( new WpdbAdapter( $this->mock_wpdb() ) )->list_tables_by_prefix( '' );
	}
}
