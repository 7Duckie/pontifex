<?php
/**
 * Tests for the Schedule command — the CLI surface over the periodic backup.
 *
 * @package Pontifex\Tests\Unit\Cli
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Cli;

use Brain\Monkey\Functions;
use Mockery;
use RuntimeException;
use Pontifex\Cli\ScheduleCommand;
use Pontifex\Schedule\Schedule;
use Pontifex\Schedule\ScheduleStore;
use Pontifex\Tests\TestCase;
use Pontifex\WordPress\WordPressContext;

/**
 * Behavioural coverage of `wp pontifex schedule set/show/off`.
 *
 * The store's own save/sync contract is pinned by ScheduleTest; these tests
 * pin the command's wiring around it — the flag validation that refuses bad
 * input before anything is written, the set/off round trip through the
 * context seam, and show's independent check of the WP-Cron event. The
 * WP_CLI facade is alias-mocked with error() throwing, the same halt
 * pattern ResumableInvokeTest uses.
 */
final class ScheduleCommandTest extends TestCase {

	/**
	 * An unknown action is refused with the usage line.
	 *
	 * @return void
	 */
	public function test_an_unknown_action_is_refused(): void {
		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'error' )
			->once()
			->with( Mockery::pattern( '/Unknown action/' ) )
			->andThrow( new RuntimeException( 'halt' ) );

		$command = new ScheduleCommand( $this->context_mock() );

		$this->expectExceptionMessage( 'halt' );

		$command( array( 'sideways' ), array() );
	}

	/**
	 * `set` without --frequency is refused before anything is written.
	 *
	 * @return void
	 */
	public function test_set_without_frequency_is_refused(): void {
		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'error' )
			->once()
			->with( Mockery::pattern( '/--frequency/' ) )
			->andThrow( new RuntimeException( 'halt' ) );

		$context = $this->context_mock();
		$context->shouldNotReceive( 'save_option' );

		$command = new ScheduleCommand( $context );

		$this->expectExceptionMessage( 'halt' );

		$command( array( 'set' ), array( 'hour' => '3' ) );
	}

	/**
	 * `set` without --hour is refused before anything is written.
	 *
	 * @return void
	 */
	public function test_set_without_hour_is_refused(): void {
		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'error' )
			->once()
			->with( Mockery::pattern( '/--hour/' ) )
			->andThrow( new RuntimeException( 'halt' ) );

		$context = $this->context_mock();
		$context->shouldNotReceive( 'save_option' );

		$command = new ScheduleCommand( $context );

		$this->expectExceptionMessage( 'halt' );

		$command( array( 'set' ), array( 'frequency' => 'daily' ) );
	}

	/**
	 * An out-of-range hour is refused with the value object's own message.
	 *
	 * @return void
	 */
	public function test_set_with_an_out_of_range_hour_is_refused(): void {
		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'error' )
			->once()
			->with( Mockery::pattern( '/between 0 and 23/' ) )
			->andThrow( new RuntimeException( 'halt' ) );

		$context = $this->context_mock();
		$context->shouldNotReceive( 'save_option' );

		$command = new ScheduleCommand( $context );

		$this->expectExceptionMessage( 'halt' );

		$command(
			array( 'set' ),
			array(
				'frequency' => 'daily',
				'hour'      => '24',
			)
		);
	}

	/**
	 * A retention below the floor is refused, never silently clamped, on the CLI.
	 *
	 * @return void
	 */
	public function test_set_with_a_retention_below_the_floor_is_refused(): void {
		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'error' )
			->once()
			->with( Mockery::pattern( '/at least 1/' ) )
			->andThrow( new RuntimeException( 'halt' ) );

		$context = $this->context_mock();
		$context->shouldNotReceive( 'save_option' );

		$command = new ScheduleCommand( $context );

		$this->expectExceptionMessage( 'halt' );

		$command(
			array( 'set' ),
			array(
				'frequency' => 'daily',
				'hour'      => '3',
				'retention' => '0',
			)
		);
	}

	/**
	 * `set` saves the schedule, keeps the stored retention when the flag is
	 * omitted, and re-registers the cron event.
	 *
	 * @return void
	 */
	public function test_set_saves_and_syncs_the_cron_event(): void {
		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'error' )->never();

		$context = $this->context_mock(
			array(
				'enabled'   => false,
				'frequency' => 'daily',
				'hour'      => 3,
				'retention' => 5,
			)
		);
		$context->shouldReceive( 'save_option' )
			->once()
			->with(
				ScheduleStore::OPTION,
				array(
					'enabled'   => true,
					'frequency' => Schedule::FREQUENCY_WEEKLY,
					'hour'      => 4,
					'retention' => 5,
				)
			);

		Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( ScheduleStore::CRON_HOOK );
		Functions\expect( 'wp_schedule_event' )->once()->with( Mockery::type( 'int' ), Schedule::FREQUENCY_WEEKLY, ScheduleStore::CRON_HOOK );

		$command = new ScheduleCommand( $context );

		$command(
			array( 'set' ),
			array(
				'frequency' => 'weekly',
				'hour'      => '4',
			)
		);

		// Brain Monkey and Mockery verify the expectations in tearDown; this
		// keeps the test PHPUnit-visible rather than flagged risky.
		$this->assertTrue( true );
	}

	/**
	 * `show` reports a disabled schedule plainly and checks no event lingers.
	 *
	 * @return void
	 */
	public function test_show_reports_a_disabled_schedule(): void {
		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->once()->with( Mockery::pattern( '/off/' ) );
		$wp_cli->shouldReceive( 'warning' )->never();
		$wp_cli->shouldReceive( 'error' )->never();

		Functions\expect( 'wp_next_scheduled' )->once()->with( ScheduleStore::CRON_HOOK )->andReturn( false );

		$command = new ScheduleCommand( $this->context_mock() );

		$command( array( 'show' ), array() );

		$this->assertTrue( true );
	}

	/**
	 * `show` warns when the schedule is on but WP-Cron has lost the event.
	 *
	 * The store keeps the two in step on every save, but the event lives in
	 * WP-Cron's own storage and can be cleared behind our back; the readout
	 * must surface that rather than assume.
	 *
	 * @return void
	 */
	public function test_show_warns_when_the_cron_event_is_missing(): void {
		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'warning' )->once()->with( Mockery::pattern( '/re-register/' ) );
		$wp_cli->shouldReceive( 'error' )->never();

		Functions\expect( 'wp_next_scheduled' )->once()->with( ScheduleStore::CRON_HOOK )->andReturn( false );

		$command = new ScheduleCommand(
			$this->context_mock(
				array(
					'enabled'   => true,
					'frequency' => 'daily',
					'hour'      => 3,
					'retention' => 3,
				)
			)
		);

		$command( array( 'show' ), array() );

		$this->assertTrue( true );
	}

	/**
	 * `off` disables the schedule but keeps its settings for a later re-enable.
	 *
	 * @return void
	 */
	public function test_off_preserves_the_settings(): void {
		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'error' )->never();

		$context = $this->context_mock(
			array(
				'enabled'   => true,
				'frequency' => 'weekly',
				'hour'      => 5,
				'retention' => 4,
			)
		);
		$context->shouldReceive( 'save_option' )
			->once()
			->with(
				ScheduleStore::OPTION,
				array(
					'enabled'   => false,
					'frequency' => Schedule::FREQUENCY_WEEKLY,
					'hour'      => 5,
					'retention' => 4,
				)
			);

		Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( ScheduleStore::CRON_HOOK );
		Functions\expect( 'wp_schedule_event' )->never();

		$command = new ScheduleCommand( $context );

		$command( array( 'off' ), array() );

		$this->assertTrue( true );
	}

	/**
	 * A WordPressContext mock whose stored schedule option holds the given data.
	 *
	 * @param array<string, mixed> $stored The stored option value the mock serves.
	 * @return WordPressContext&\Mockery\MockInterface The mock.
	 */
	private function context_mock( array $stored = array() ) {
		$context = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'option_value' )
			->with( ScheduleStore::OPTION, Mockery::any() )
			->andReturn( $stored );
		return $context;
	}
}
