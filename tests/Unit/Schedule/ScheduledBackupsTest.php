<?php
/**
 * Unit tests for ScheduledBackups — the ledger of the scheduler's own backups.
 *
 * @package Pontifex\Tests\Unit\Schedule
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Schedule;

use Mockery;
use Pontifex\Schedule\ScheduledBackups;
use Pontifex\Tests\TestCase;
use Pontifex\WordPress\WordPressContext;

/**
 * Exercises the ledger against a mocked WordPressContext option.
 *
 * The class itself never touches disk (see its own docblock); "on disk"
 * is always supplied by the caller, so these tests pass it in directly
 * rather than exercising a real filesystem.
 */
final class ScheduledBackupsTest extends TestCase {

	/**
	 * Recording a new filename appends it and persists the option.
	 *
	 * @return void
	 */
	public function test_record_appends_a_new_filename(): void {
		$context = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'option_value' )->once()->with( ScheduledBackups::OPTION, array() )->andReturn( array() );
		$context->shouldReceive( 'save_option' )->once()->with( ScheduledBackups::OPTION, array( 'pontifex-backup-20260101T000000Z.wpmig' ) );

		( new ScheduledBackups( $context ) )->record( 'pontifex-backup-20260101T000000Z.wpmig' );

		// Mockery verifies the expectations above in tearDown(); this keeps the
		// test PHPUnit-visible rather than flagged risky.
		$this->assertTrue( true );
	}

	/**
	 * Recording a filename already on the ledger is a no-op: no second entry,
	 * and no unnecessary write.
	 *
	 * @return void
	 */
	public function test_record_is_a_no_op_when_already_recorded(): void {
		$context = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'option_value' )->once()->andReturn( array( 'pontifex-backup-20260101T000000Z.wpmig' ) );
		$context->shouldReceive( 'save_option' )->never();

		( new ScheduledBackups( $context ) )->record( 'pontifex-backup-20260101T000000Z.wpmig' );

		// Mockery verifies the expectations above in tearDown(); this keeps the
		// test PHPUnit-visible rather than flagged risky.
		$this->assertTrue( true );
	}

	/**
	 * Recorded() returns only the names confirmed present on disk.
	 *
	 * @return void
	 */
	public function test_recorded_returns_only_names_still_on_disk(): void {
		$context = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'option_value' )->once()->andReturn(
			array( 'a.wpmig', 'b.wpmig', 'c.wpmig' )
		);
		$context->shouldReceive( 'save_option' )->once()->with(
			ScheduledBackups::OPTION,
			array( 'a.wpmig', 'c.wpmig' )
		);

		$recorded = ( new ScheduledBackups( $context ) )->recorded( array( 'a.wpmig', 'c.wpmig' ) );

		$this->assertSame( array( 'a.wpmig', 'c.wpmig' ), $recorded );
	}

	/**
	 * The self-heal drops a recorded name whose file is gone AND persists the
	 * reduced list back to the option, so the ledger cannot grow for ever as
	 * backups are pruned or removed by hand.
	 *
	 * @return void
	 */
	public function test_recorded_self_heals_a_deleted_backup(): void {
		$context = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'option_value' )->once()->andReturn( array( 'gone.wpmig' ) );
		$context->shouldReceive( 'save_option' )->once()->with( ScheduledBackups::OPTION, array() );

		$recorded = ( new ScheduledBackups( $context ) )->recorded( array() );

		$this->assertSame( array(), $recorded, 'A recorded name with no matching on-disk file must not be returned.' );
	}

	/**
	 * When nothing needs dropping, recorded() does not write the option back.
	 *
	 * @return void
	 */
	public function test_recorded_does_not_write_when_nothing_changed(): void {
		$context = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'option_value' )->once()->andReturn( array( 'a.wpmig' ) );
		$context->shouldReceive( 'save_option' )->never();

		$recorded = ( new ScheduledBackups( $context ) )->recorded( array( 'a.wpmig' ) );

		$this->assertSame( array( 'a.wpmig' ), $recorded );
	}

	/**
	 * Garbage stored data degrades to an empty ledger rather than fataling.
	 *
	 * A stored option can predate this ledger's existence or be hand-edited;
	 * neither may crash a cron run.
	 *
	 * @return void
	 */
	public function test_garbage_stored_data_degrades_to_an_empty_ledger(): void {
		$context = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'option_value' )->once()->andReturn( 'not an array' );
		$context->shouldReceive( 'save_option' )->never();

		$recorded = ( new ScheduledBackups( $context ) )->recorded( array( 'a.wpmig' ) );

		$this->assertSame( array(), $recorded );
	}
}
