<?php
/**
 * Unit tests for DestinationStore.
 *
 * @package Pontifex\Tests\Unit\Destination
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Destination;

use Mockery;
use Pontifex\Destination\DestinationSpec;
use Pontifex\Destination\DestinationStore;
use Pontifex\Tests\TestCase;
use Pontifex\WordPress\WordPressContext;

/**
 * Behavioural coverage of {@see DestinationStore}: the read-modify-write
 * save/delete pair, the all()/get()/names() views over the stored option,
 * and that a record this version cannot parse is skipped rather than
 * fataling the whole read (the same posture as {@see \Pontifex\Schedule\ScheduleStore}).
 */
final class DestinationStoreTest extends TestCase {

	/**
	 * An absent option degrades to an empty destination list.
	 *
	 * @return void
	 */
	public function test_all_on_an_absent_option_is_empty(): void {
		$context = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'option_value' )->with( DestinationStore::OPTION, array() )->andReturn( array() );

		$store = new DestinationStore( $context );

		$this->assertSame( array(), $store->all() );
		$this->assertSame( array(), $store->names() );
	}

	/**
	 * A non-array option value degrades to an empty destination list.
	 *
	 * @return void
	 */
	public function test_all_tolerates_a_non_array_option(): void {
		$context = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'option_value' )->andReturn( 'corrupt' );

		$store = new DestinationStore( $context );

		$this->assertSame( array(), $store->all() );
	}

	/**
	 * A stored record that cannot form a valid spec is skipped, not fatal.
	 *
	 * @return void
	 */
	public function test_all_skips_an_unreadable_record(): void {
		$context = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'option_value' )->andReturn(
			array(
				'good'    => array(
					'type'      => DestinationSpec::TYPE_SFTP,
					'settings'  => array( 'host' => 'example.test' ),
					'retention' => 3,
				),
				'garbage' => array( 'type' => 'not-a-real-type' ),
				'bogus'   => 'not even an array',
				5         => array( 'type' => DestinationSpec::TYPE_SFTP ),
			)
		);

		$store = new DestinationStore( $context );
		$all   = $store->all();

		$this->assertSame( array( 'good' ), array_keys( $all ), 'Only the parseable, string-keyed record survives.' );
		$this->assertSame( DestinationSpec::TYPE_SFTP, $all['good']->type() );
	}

	/**
	 * Getting by name returns the named spec when present, or null when absent.
	 *
	 * @return void
	 */
	public function test_get_returns_the_named_spec_or_null(): void {
		$context = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'option_value' )->andReturn(
			array(
				'backup-server' => array(
					'type'      => DestinationSpec::TYPE_SFTP,
					'settings'  => array( 'host' => 'example.test' ),
					'retention' => 3,
				),
			)
		);

		$store = new DestinationStore( $context );

		$this->assertSame( 'backup-server', $store->get( 'backup-server' )->name() );
		$this->assertNull( $store->get( 'missing' ) );
	}

	/**
	 * Listing names returns the keys of every readable stored destination.
	 *
	 * @return void
	 */
	public function test_names_lists_the_stored_keys(): void {
		$context = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'option_value' )->andReturn(
			array(
				'first'  => array(
					'type'      => DestinationSpec::TYPE_SFTP,
					'settings'  => array(),
					'retention' => 1,
				),
				'second' => array(
					'type'      => DestinationSpec::TYPE_S3,
					'settings'  => array(),
					'retention' => 2,
				),
			)
		);

		$store = new DestinationStore( $context );

		$this->assertSame( array( 'first', 'second' ), $store->names() );
	}

	/**
	 * Saving writes the spec into the option under its name, preserving any
	 * existing record the read-modify-write cannot parse.
	 *
	 * @return void
	 */
	public function test_save_writes_the_spec_under_its_name(): void {
		$saved   = null;
		$context = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'option_value' )->andReturn( array( 'existing' => array( 'type' => 'unparseable-by-this-version' ) ) );
		$context->shouldReceive( 'save_option' )->once()->with(
			DestinationStore::OPTION,
			Mockery::on(
				static function ( $value ) use ( &$saved ): bool {
					$saved = $value;
					return true;
				}
			)
		);

		$spec = new DestinationSpec( 'backup-server', DestinationSpec::TYPE_SFTP, array( 'host' => 'example.test' ), 3 );
		( new DestinationStore( $context ) )->save( $spec );

		$this->assertIsArray( $saved );
		$this->assertArrayHasKey( 'existing', $saved, 'A record this version cannot parse is preserved, not dropped.' );
		$this->assertSame( $spec->to_array(), $saved['backup-server'] );
	}

	/**
	 * Saving replaces an existing destination of the same name.
	 *
	 * @return void
	 */
	public function test_save_replaces_an_existing_destination_of_the_same_name(): void {
		$saved   = null;
		$context = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'option_value' )->andReturn(
			array(
				'backup-server' => array(
					'type'      => DestinationSpec::TYPE_SFTP,
					'settings'  => array( 'host' => 'old.example.test' ),
					'retention' => 1,
				),
			)
		);
		$context->shouldReceive( 'save_option' )->once()->with(
			DestinationStore::OPTION,
			Mockery::on(
				static function ( $value ) use ( &$saved ): bool {
					$saved = $value;
					return true;
				}
			)
		);

		$spec = new DestinationSpec( 'backup-server', DestinationSpec::TYPE_SFTP, array( 'host' => 'new.example.test' ), 5 );
		( new DestinationStore( $context ) )->save( $spec );

		$this->assertCount( 1, $saved );
		$this->assertSame( 'new.example.test', $saved['backup-server']['settings']['host'] );
	}

	/**
	 * Deleting removes the named destination and leaves the rest untouched.
	 *
	 * @return void
	 */
	public function test_delete_removes_the_named_destination(): void {
		$saved   = null;
		$context = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'option_value' )->andReturn(
			array(
				'keep'   => array(
					'type'      => DestinationSpec::TYPE_SFTP,
					'settings'  => array(),
					'retention' => 1,
				),
				'remove' => array(
					'type'      => DestinationSpec::TYPE_S3,
					'settings'  => array(),
					'retention' => 2,
				),
			)
		);
		$context->shouldReceive( 'save_option' )->once()->with(
			DestinationStore::OPTION,
			Mockery::on(
				static function ( $value ) use ( &$saved ): bool {
					$saved = $value;
					return true;
				}
			)
		);

		( new DestinationStore( $context ) )->delete( 'remove' );

		$this->assertSame( array( 'keep' ), array_keys( $saved ) );
	}

	/**
	 * Deleting a name that is not stored is a harmless no-op write.
	 *
	 * @return void
	 */
	public function test_delete_of_an_absent_name_is_harmless(): void {
		$context = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'option_value' )->andReturn( array() );
		$context->shouldReceive( 'save_option' )->once()->with( DestinationStore::OPTION, array() );

		( new DestinationStore( $context ) )->delete( 'missing' );

		$this->assertTrue( true );
	}
}
