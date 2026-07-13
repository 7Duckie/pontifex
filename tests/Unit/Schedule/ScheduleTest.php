<?php
/**
 * Unit tests for the Schedule, ScheduleStore, and JobTicker classes.
 *
 * @package Pontifex\Tests\Unit\Schedule
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Schedule;

use Brain\Monkey\Functions;
use InvalidArgumentException;
use Mockery;
use Pontifex\Admin\BackupStore;
use Pontifex\Environment\Environment;
use Pontifex\Job\Job;
use Pontifex\Job\JobStore;
use Pontifex\Schedule\JobTicker;
use Pontifex\Schedule\Schedule;
use Pontifex\Schedule\ScheduleStore;
use Pontifex\Tests\TestCase;
use Pontifex\WordPress\WordPressContext;
use Psr\Log\NullLogger;

/**
 * Behavioural tests for the schedule layer.
 *
 * The Schedule value object and the store's cron-sync contract are pinned
 * directly; the ticker's stand-down behaviours (no job, lock held) are
 * pinned against a real JobStore on a temp filesystem with the WordPress
 * boundary stubbed by brain/monkey — the same layering every admin
 * controller test uses. The tick-to-completion path itself is exercised
 * end-to-end by the runner suite and the real-cron Docker evidence.
 */
final class ScheduleTest extends TestCase {

	/**
	 * Fixture directory standing in for wp-content.
	 *
	 * @var string
	 */
	private string $content_dir;

	/**
	 * Create the fixture tree.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->content_dir = sys_get_temp_dir() . '/pontifex-schedule-test-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating the test fixture directory.
		mkdir( $this->content_dir, 0o755, true );
	}

	/**
	 * Remove the fixture tree.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		self::rmtree( $this->content_dir );
		parent::tearDown();
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $path Absolute path to remove.
	 * @return void
	 */
	private static function rmtree( string $path ): void {
		if ( ! is_dir( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Test fixture teardown; best-effort.
			@unlink( $path );
			return;
		}
		foreach ( scandir( $path ) as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				self::rmtree( $path . '/' . $entry );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.PHP.NoSilencedErrors.Discouraged -- Test fixture teardown; best-effort.
		@rmdir( $path );
	}

	/**
	 * Retention clamps up to the floor: pruning may never delete the last backup.
	 *
	 * @return void
	 */
	public function test_retention_clamps_to_the_floor(): void {
		$schedule = new Schedule( true, Schedule::FREQUENCY_DAILY, 3, 0 );

		$this->assertSame( Schedule::MIN_RETENTION, $schedule->retention() );
	}

	/**
	 * An unknown frequency is refused.
	 *
	 * @return void
	 */
	public function test_an_unknown_frequency_is_refused(): void {
		$this->expectException( InvalidArgumentException::class );

		new Schedule( true, 'hourly', 3, 3 );
	}

	/**
	 * Garbage stored option data degrades to the disabled default, never a fatal.
	 *
	 * @return void
	 */
	public function test_garbage_stored_data_degrades_to_disabled(): void {
		$this->assertFalse( Schedule::from_stored( 'not an array' )->is_enabled() );
		$this->assertFalse(
			Schedule::from_stored(
				array(
					'frequency' => 'never',
					'enabled'   => true,
				)
			)->is_enabled()
		);
	}

	/**
	 * Saving an enabled schedule clears and re-registers the recurring event.
	 *
	 * @return void
	 */
	public function test_save_syncs_the_cron_event_when_enabled(): void {
		$context = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'save_option' )->once()->with( ScheduleStore::OPTION, Mockery::type( 'array' ) );

		Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( ScheduleStore::CRON_HOOK );
		Functions\expect( 'wp_schedule_event' )->once()->with( Mockery::type( 'int' ), Schedule::FREQUENCY_DAILY, ScheduleStore::CRON_HOOK );

		( new ScheduleStore( $context ) )->save( new Schedule( true, Schedule::FREQUENCY_DAILY, 3, 3 ), 1700000000 );

		// Brain Monkey verifies the expectations in tearDown; this keeps the
		// test PHPUnit-visible rather than flagged risky.
		$this->assertTrue( true );
	}

	/**
	 * Saving a disabled schedule clears the event and registers nothing.
	 *
	 * @return void
	 */
	public function test_save_only_clears_when_disabled(): void {
		$context = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'save_option' )->once();

		Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( ScheduleStore::CRON_HOOK );
		Functions\expect( 'wp_schedule_event' )->never();

		( new ScheduleStore( $context ) )->save( Schedule::disabled(), 1700000000 );

		// Brain Monkey verifies the expectations in tearDown; this keeps the
		// test PHPUnit-visible rather than flagged risky.
		$this->assertTrue( true );
	}

	/**
	 * The next occurrence is today at the hour when still ahead, else tomorrow.
	 *
	 * @return void
	 */
	public function test_next_occurrence_rolls_to_tomorrow_when_past(): void {
		$schedule = new Schedule( true, Schedule::FREQUENCY_DAILY, 3, 3 );

		// 2023-11-14 22:13:20 UTC — 03:00 already passed, so tomorrow 03:00.
		$next = ScheduleStore::next_occurrence( $schedule, 1700000000 );
		$this->assertSame( '2023-11-15 03:00', gmdate( 'Y-m-d H:i', $next ) );

		// 2023-11-14 01:00 UTC — 03:00 still ahead today.
		$early = ScheduleStore::next_occurrence( $schedule, 1699923600 );
		$this->assertSame( '2023-11-14 03:00', gmdate( 'Y-m-d H:i', $early ) );
	}

	/**
	 * The ticker stands down silently when no job is active.
	 *
	 * @return void
	 */
	public function test_ticker_stands_down_with_no_active_job(): void {
		$context = Mockery::mock( WordPressContext::class );
		$context->shouldNotReceive( 'acquire_named_lock' );

		$ticker = new JobTicker( Mockery::mock( Environment::class ), $context, new JobStore( $this->content_dir ), new BackupStore( $this->content_dir ), new NullLogger() );

		$ticker->run();

		// Reaching here without a Mockery unexpected-call exception IS the pass;
		// assert explicitly so the test is not flagged as risky.
		$this->assertTrue( true );
	}

	/**
	 * The ticker reschedules and leaves when a live request holds the lock.
	 *
	 * @return void
	 */
	public function test_ticker_defers_to_a_live_request_holding_the_lock(): void {
		$job_store = new JobStore( $this->content_dir );
		$job_store->create( Job::KIND_EXPORT, array( 'output' => '/tmp/x.wpmig' ), 1700000000 );

		$context = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'acquire_named_lock' )->once()->andReturn( false );
		$context->shouldNotReceive( 'release_named_lock' );

		Functions\expect( 'wp_next_scheduled' )->once()->andReturn( false );
		Functions\expect( 'wp_schedule_single_event' )->once()->with( Mockery::type( 'int' ), JobTicker::CRON_HOOK );

		$ticker = new JobTicker( Mockery::mock( Environment::class ), $context, $job_store, new BackupStore( $this->content_dir ), new NullLogger() );

		$ticker->run();

		$this->assertTrue( true );
	}
}
