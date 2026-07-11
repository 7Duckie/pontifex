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
}
