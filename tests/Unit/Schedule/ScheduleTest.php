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
use RuntimeException;
use Pontifex\Admin\BackupStore;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Writer\EntryPlan;
use Pontifex\Archive\Writer\EntryWriter;
use Pontifex\Environment\Environment;
use Pontifex\Job\Job;
use Pontifex\Job\JobStore;
use Pontifex\Manifest\ManifestBuilderInterface;
use Pontifex\Manifest\ManifestStream;
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
	 * Exclusion patterns round-trip through to_array and from_stored, filtered.
	 *
	 * @return void
	 */
	public function test_exclusions_round_trip_and_are_filtered(): void {
		$schedule = new Schedule( true, Schedule::FREQUENCY_DAILY, 3, 3, array( 'wp-content/cache/**', '', 'wp_actionscheduler_*' ) );

		$this->assertSame( array( 'wp-content/cache/**', 'wp_actionscheduler_*' ), $schedule->exclusions(), 'Blank patterns are dropped.' );

		$restored = Schedule::from_stored( $schedule->to_array() );
		$this->assertSame( $schedule->exclusions(), $restored->exclusions(), 'Exclusions survive the option round trip.' );
	}

	/**
	 * A stored schedule with no exclusions key degrades to an empty list, not a fatal.
	 *
	 * @return void
	 */
	public function test_absent_exclusions_default_to_empty(): void {
		$restored = Schedule::from_stored(
			array(
				'enabled'   => true,
				'frequency' => 'daily',
				'hour'      => 3,
				'retention' => 3,
			)
		);

		$this->assertSame( array(), $restored->exclusions() );
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
	 * The successor event is on the calendar before the tick runs, and a
	 * failed tick clears it while the attempt is recorded on the job.
	 *
	 * The dead-man's switch: a fatal mid-tick runs neither catch nor finally,
	 * so the only thing that can keep the chain alive is an event scheduled
	 * BEFORE the work. Observable here: even though the tick throws
	 * immediately, the successor was already scheduled — and because a
	 * failed job is decided, the catch path then clears it.
	 *
	 * @return void
	 */
	public function test_ticker_schedules_the_successor_before_working(): void {
		$job_store = new JobStore( $this->content_dir );
		$job       = $job_store->create(
			Job::KIND_EXPORT,
			array(
				'output'      => $this->content_dir . '/x.wpmig',
				'temp'        => $this->content_dir . '/x.part',
				'scan_root'   => $this->content_dir,
				'path_prefix' => 'wp-content',
				'exclusions'  => array(),
				'signed'      => false,
				'phase'       => 'files',
			),
			1700000000
		);

		$context = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'acquire_named_lock' )->once()->andReturn( true );
		$context->shouldReceive( 'release_named_lock' )->once();
		$context->shouldReceive( 'option_value' )->andReturn( array() );
		$context->shouldReceive( 'save_option' );

		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_single_event' )->once()->with( Mockery::type( 'int' ), JobTicker::CRON_HOOK );
		Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( JobTicker::CRON_HOOK );

		$ticker = new JobTicker(
			Mockery::mock( Environment::class ),
			$context,
			$job_store,
			new BackupStore( $this->content_dir ),
			new NullLogger(),
			static function (): ManifestBuilderInterface {
				throw new RuntimeException( 'simulated mid-tick death' );
			}
		);

		$ticker->run();

		$failed = $job_store->get( $job->id() );
		$this->assertNotNull( $failed );
		$this->assertSame( Job::STATUS_FAILED, $failed->status(), 'A throwing tick leaves the job failed, not wedged running.' );
		$this->assertSame( 1, (int) ( $failed->payload()['ticker_attempts'] ?? 0 ), 'The attempt was recorded before the work began.' );
	}

	/**
	 * A job whose every continuation attempt died mid-tick is failed loudly.
	 *
	 * Past the ceiling the ticker must not touch the runner at all: the job
	 * is marked failed, the failure is counted, and the chain ends — never
	 * an unbounded crash loop, never a forever-"running" record.
	 *
	 * @return void
	 */
	public function test_ticker_fails_a_job_past_the_unclean_attempt_ceiling(): void {
		$job_store = new JobStore( $this->content_dir );
		$job       = $job_store->create(
			Job::KIND_EXPORT,
			array(
				'output'          => $this->content_dir . '/x.wpmig',
				'ticker_attempts' => 8,
			),
			1700000000
		);

		$context = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'acquire_named_lock' )->once()->andReturn( true );
		$context->shouldReceive( 'release_named_lock' )->once();
		$context->shouldReceive( 'option_value' )->andReturn( array() );
		$context->shouldReceive( 'save_option' );

		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_single_event' )->once()->with( Mockery::type( 'int' ), JobTicker::CRON_HOOK );
		Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( JobTicker::CRON_HOOK );

		$ticker = new JobTicker(
			Mockery::mock( Environment::class ),
			$context,
			$job_store,
			new BackupStore( $this->content_dir ),
			new NullLogger(),
			function (): ManifestBuilderInterface {
				$this->fail( 'Past the attempt ceiling the runner must never be invoked.' );
			}
		);

		$ticker->run();

		$failed = $job_store->get( $job->id() );
		$this->assertNotNull( $failed );
		$this->assertSame( Job::STATUS_FAILED, $failed->status() );
	}

	/**
	 * A completed job clears the pre-scheduled successor: the chain ends only
	 * when the work is decided.
	 *
	 * @return void
	 */
	public function test_ticker_clears_the_successor_on_completion(): void {
		$job_store = new JobStore( $this->content_dir );
		$job       = $job_store->create(
			Job::KIND_EXPORT,
			array(
				'output'        => $this->content_dir . '/done.wpmig',
				'temp'          => $this->content_dir . '/done.part',
				'scan_root'     => $this->content_dir,
				'path_prefix'   => 'wp-content',
				'exclusions'    => array(),
				'signed'        => false,
				'reason'        => null,
				'scope'         => null,
				'phase'         => 'files',
				'bytes_written' => 0,
				'files_changed' => 0,
			),
			1700000000
		);

		$environment = Mockery::mock( Environment::class );
		$environment->shouldReceive( 'is_constant_defined' )->with( 'PONTIFEX_VERSION' )->andReturn( false );
		$environment->shouldReceive( 'php_version' )->andReturn( '8.3.0' );

		$context = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'acquire_named_lock' )->once()->andReturn( true );
		$context->shouldReceive( 'release_named_lock' )->once();
		$context->shouldReceive( 'wp_version' )->andReturn( '6.6.0' );
		$context->shouldReceive( 'site_url' )->andReturn( 'https://example.test' );
		$context->shouldReceive( 'wpdb_charset' )->andReturn( 'utf8mb4' );
		$context->shouldReceive( 'wpdb_collation' )->andReturn( 'utf8mb4_unicode_520_ci' );
		$context->shouldReceive( 'option_value' )->andReturn( array() );
		$context->shouldReceive( 'save_option' );

		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_single_event' )->once()->with( Mockery::type( 'int' ), JobTicker::CRON_HOOK );
		Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( JobTicker::CRON_HOOK );

		$ticker = new JobTicker(
			$environment,
			$context,
			$job_store,
			new BackupStore( $this->content_dir ),
			new NullLogger(),
			static function (): ManifestBuilderInterface {
				$builder = Mockery::mock( ManifestBuilderInterface::class );
				$builder->shouldReceive( 'build' )->andReturnUsing(
					static function (): ManifestStream {
						$contents = 'alpha';
						$plan     = new EntryPlan(
							EntryHeader::for_file( 'wp-content/a.txt', strlen( $contents ), 0o644, 1690000000, 'application/octet-stream', 0 ),
							0,
							str_repeat( "\0", EntryWriter::NONCE_SIZE ),
							static function () use ( $contents ) {
								// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://memory is an in-process buffer, not a file.
								$stream = fopen( 'php://memory', 'r+b' );
								// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Operating on a test stream resource.
								fwrite( $stream, $contents );
								// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource.
								rewind( $stream );
								return $stream;
							}
						);
						return ManifestStream::from_plans( array( $plan ) );
					}
				);
				return $builder;
			}
		);

		$ticker->run();

		$this->assertFileExists( $this->content_dir . '/done.wpmig', 'The completed archive must be renamed into place.' );
		$this->assertNull( $job_store->get( $job->id() ), 'A finished job is deleted by finalise().' );
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
