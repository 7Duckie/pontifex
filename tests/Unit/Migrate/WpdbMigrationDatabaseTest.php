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
