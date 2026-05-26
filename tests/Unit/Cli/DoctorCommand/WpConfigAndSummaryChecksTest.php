<?php
/**
 * Behavioural tests for DoctorCommand's WordPress config checks and status summary.
 *
 * @package Pontifex\Tests\Unit\Cli\DoctorCommand
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Cli\DoctorCommand;

use Mockery;
use Pontifex\Cli\DoctorCommand;
use Pontifex\Environment\Environment;
use Pontifex\Tests\TestCase;
use Pontifex\WordPress\WordPressContext;
use ReflectionMethod;

/**
 * Tests for the third batch of DoctorCommand check methods plus the summary aggregation.
 *
 * Covers check_wp_cron_status (DISABLE_WP_CRON probe),
 * check_action_scheduler_presence (bundled-dependency detection for the
 * future Phase 1 Action Scheduler integration), and compute_status_counts
 * (the pure aggregation logic that print_summary delegates to).
 *
 * The check methods exercised here only touch Environment (constant
 * probes, class/function existence checks); none of them call into
 * WordPressContext. The mock is still injected for consistency with the
 * other behavioural test files and to defend against future drift —
 * if a check ever starts touching WordPressContext, the mock catches
 * it as an unexpected call instead of silently delegating to the real
 * implementation.
 *
 * __invoke is deliberately not covered with behavioural tests here. It is a
 * thin orchestrator that delegates to collect_all_checks() (already tested
 * via the per-check methods), WP-CLI's Formatter (not our code to test),
 * and print_summary (whose pure counting logic is tested via
 * compute_status_counts below). There is no logic in __invoke itself worth
 * testing in isolation; the four structural tests in DoctorCommandTest
 * already cover the layer that matters (method exists, signature, void
 * return). Mocking around all 18 check methods plus WP-CLI internals to
 * exercise __invoke directly would be invasive ceremony for no real
 * coverage gain.
 *
 * Status string values are hardcoded ('OK', 'WARN', 'FAIL', 'INFO') because
 * they are part of DoctorCommand's visible output contract.
 */
final class WpConfigAndSummaryChecksTest extends TestCase {

	// -------------------------------------------------------------------------
	// Helpers.
	// -------------------------------------------------------------------------

	/**
	 * Construct a DoctorCommand instance with the given mock dependencies.
	 *
	 * @param Environment      $environment       The mock environment to inject.
	 * @param WordPressContext $wordpress_context The mock WordPress context to inject.
	 * @return DoctorCommand
	 */
	private function build_command( Environment $environment, WordPressContext $wordpress_context ): DoctorCommand {
		return new DoctorCommand( $environment, $wordpress_context );
	}

	/**
	 * Invoke a private method on DoctorCommand via reflection.
	 *
	 * @param DoctorCommand $command     The command instance under test.
	 * @param string        $method_name The private method to invoke.
	 * @param mixed         ...$args     Any arguments the method takes.
	 * @return mixed The method's return value, cast to the caller's expected type.
	 */
	private function invoke_private( DoctorCommand $command, string $method_name, ...$args ) {
		$reflection = new ReflectionMethod( $command, $method_name );
		return $reflection->invoke( $command, ...$args );
	}

	// -------------------------------------------------------------------------
	// check_wp_cron_status
	// -------------------------------------------------------------------------

	/**
	 * When DISABLE_WP_CRON is not defined, WP-Cron is reported as enabled (OK).
	 *
	 * @return void
	 */
	public function test_wp_cron_enabled_when_constant_not_defined(): void {
		$environment = Mockery::mock( Environment::class );
		$environment->shouldReceive( 'is_constant_defined' )
					->with( 'DISABLE_WP_CRON' )
					->andReturn( false );

		$wordpress_context = Mockery::mock( WordPressContext::class );

		$row = (array) $this->invoke_private(
			$this->build_command( $environment, $wordpress_context ),
			'check_wp_cron_status'
		);

		$this->assertSame( 'WordPress config', $row['category'] );
		$this->assertSame( 'WP-Cron', $row['name'] );
		$this->assertSame( 'enabled', $row['value'] );
		$this->assertSame( 'OK', $row['status'] );
	}

	/**
	 * When DISABLE_WP_CRON is defined and truthy, WP-Cron is reported as disabled (WARN).
	 *
	 * @return void
	 */
	public function test_wp_cron_disabled_when_constant_is_truthy(): void {
		$environment = Mockery::mock( Environment::class );
		$environment->shouldReceive( 'is_constant_defined' )
					->with( 'DISABLE_WP_CRON' )
					->andReturn( true );
		$environment->shouldReceive( 'constant_value' )
					->with( 'DISABLE_WP_CRON' )
					->andReturn( true );

		$wordpress_context = Mockery::mock( WordPressContext::class );

		$row = (array) $this->invoke_private(
			$this->build_command( $environment, $wordpress_context ),
			'check_wp_cron_status'
		);

		$this->assertStringContainsString( 'disabled', $row['value'] );
		$this->assertSame( 'WARN', $row['status'] );
		$this->assertStringContainsString( 'system cron', $row['note'] );
	}

	/**
	 * When DISABLE_WP_CRON is defined but falsy, WP-Cron is still reported as enabled.
	 *
	 * Some hosts define the constant to false explicitly to make the intent
	 * unambiguous in wp-config.php. The check should treat a falsy value the
	 * same as the constant not being defined at all.
	 *
	 * @return void
	 */
	public function test_wp_cron_enabled_when_constant_is_falsy(): void {
		$environment = Mockery::mock( Environment::class );
		$environment->shouldReceive( 'is_constant_defined' )
					->with( 'DISABLE_WP_CRON' )
					->andReturn( true );
		$environment->shouldReceive( 'constant_value' )
					->with( 'DISABLE_WP_CRON' )
					->andReturn( false );

		$wordpress_context = Mockery::mock( WordPressContext::class );

		$row = (array) $this->invoke_private(
			$this->build_command( $environment, $wordpress_context ),
			'check_wp_cron_status'
		);

		$this->assertSame( 'enabled', $row['value'] );
		$this->assertSame( 'OK', $row['status'] );
	}

	// -------------------------------------------------------------------------
	// check_action_scheduler_presence
	// -------------------------------------------------------------------------

	/**
	 * When neither the class nor the function is present, Action Scheduler is reported as not loaded.
	 *
	 * @return void
	 */
	public function test_action_scheduler_not_loaded_when_absent(): void {
		$environment = Mockery::mock( Environment::class );
		$environment->shouldReceive( 'class_exists' )
					->with( 'ActionScheduler', false )
					->andReturn( false );
		$environment->shouldReceive( 'function_exists' )
					->with( 'as_schedule_single_action' )
					->andReturn( false );

		$wordpress_context = Mockery::mock( WordPressContext::class );

		$row = (array) $this->invoke_private(
			$this->build_command( $environment, $wordpress_context ),
			'check_action_scheduler_presence'
		);

		$this->assertSame( 'WordPress config', $row['category'] );
		$this->assertSame( 'Action Scheduler', $row['name'] );
		$this->assertSame( 'not loaded yet', $row['value'] );
		$this->assertSame( 'INFO', $row['status'] );
	}

	/**
	 * Action Scheduler detected via its class is reported as loaded by another plugin.
	 *
	 * @return void
	 */
	public function test_action_scheduler_loaded_via_class(): void {
		$environment = Mockery::mock( Environment::class );
		$environment->shouldReceive( 'class_exists' )
					->with( 'ActionScheduler', false )
					->andReturn( true );
		// Function check may or may not run because of short-circuit OR; allow either.
		$environment->shouldReceive( 'function_exists' )->andReturn( false );

		$wordpress_context = Mockery::mock( WordPressContext::class );

		$row = (array) $this->invoke_private(
			$this->build_command( $environment, $wordpress_context ),
			'check_action_scheduler_presence'
		);

		$this->assertSame( 'loaded by another plugin', $row['value'] );
		$this->assertSame( 'INFO', $row['status'] );
	}

	/**
	 * Action Scheduler detected via its function is reported as loaded by another plugin.
	 *
	 * Some bundlings of Action Scheduler expose the helper function but not the
	 * class under its canonical name. The check accepts either signal.
	 *
	 * @return void
	 */
	public function test_action_scheduler_loaded_via_function(): void {
		$environment = Mockery::mock( Environment::class );
		$environment->shouldReceive( 'class_exists' )
					->with( 'ActionScheduler', false )
					->andReturn( false );
		$environment->shouldReceive( 'function_exists' )
					->with( 'as_schedule_single_action' )
					->andReturn( true );

		$wordpress_context = Mockery::mock( WordPressContext::class );

		$row = (array) $this->invoke_private(
			$this->build_command( $environment, $wordpress_context ),
			'check_action_scheduler_presence'
		);

		$this->assertSame( 'loaded by another plugin', $row['value'] );
		$this->assertSame( 'INFO', $row['status'] );
	}

	// -------------------------------------------------------------------------
	// compute_status_counts
	// -------------------------------------------------------------------------

	/**
	 * Empty input yields a zero count for every status.
	 *
	 * @return void
	 */
	public function test_status_counts_empty_input_returns_zero_for_all(): void {
		$environment       = Mockery::mock( Environment::class );
		$wordpress_context = Mockery::mock( WordPressContext::class );

		$counts = (array) $this->invoke_private(
			$this->build_command( $environment, $wordpress_context ),
			'compute_status_counts',
			array()
		);

		$this->assertSame( 0, $counts['OK'] );
		$this->assertSame( 0, $counts['WARN'] );
		$this->assertSame( 0, $counts['FAIL'] );
		$this->assertSame( 0, $counts['INFO'] );
	}

	/**
	 * A single OK row counts as one OK and zero of everything else.
	 *
	 * @return void
	 */
	public function test_status_counts_single_ok_row(): void {
		$environment       = Mockery::mock( Environment::class );
		$wordpress_context = Mockery::mock( WordPressContext::class );

		$rows = array(
			array(
				'category' => 'Runtime',
				'name'     => 'PHP version',
				'value'    => '8.4.0',
				'status'   => 'OK',
				'note'     => '',
			),
		);

		$counts = (array) $this->invoke_private(
			$this->build_command( $environment, $wordpress_context ),
			'compute_status_counts',
			$rows
		);

		$this->assertSame( 1, $counts['OK'] );
		$this->assertSame( 0, $counts['WARN'] );
		$this->assertSame( 0, $counts['FAIL'] );
		$this->assertSame( 0, $counts['INFO'] );
	}

	/**
	 * A mix of statuses produces a count for each one.
	 *
	 * @return void
	 */
	public function test_status_counts_mixed_rows(): void {
		$environment       = Mockery::mock( Environment::class );
		$wordpress_context = Mockery::mock( WordPressContext::class );

		$rows = array(
			array( 'status' => 'OK' ),
			array( 'status' => 'OK' ),
			array( 'status' => 'OK' ),
			array( 'status' => 'WARN' ),
			array( 'status' => 'WARN' ),
			array( 'status' => 'FAIL' ),
			array( 'status' => 'INFO' ),
			array( 'status' => 'INFO' ),
		);

		$counts = (array) $this->invoke_private(
			$this->build_command( $environment, $wordpress_context ),
			'compute_status_counts',
			$rows
		);

		$this->assertSame( 3, $counts['OK'] );
		$this->assertSame( 2, $counts['WARN'] );
		$this->assertSame( 1, $counts['FAIL'] );
		$this->assertSame( 2, $counts['INFO'] );
	}

	/**
	 * A row with no status key falls back to the INFO bucket.
	 *
	 * The check methods themselves always supply a status, so this path is
	 * unreachable from the production caller. But the defensive null-coalesce
	 * in compute_status_counts is part of its contract, so it's tested here
	 * to lock the behaviour in.
	 *
	 * @return void
	 */
	public function test_status_counts_row_without_status_falls_back_to_info(): void {
		$environment       = Mockery::mock( Environment::class );
		$wordpress_context = Mockery::mock( WordPressContext::class );

		$rows = array(
			array(
				'category' => 'Runtime',
				'name'     => 'Mystery row',
			),
		);

		$counts = (array) $this->invoke_private(
			$this->build_command( $environment, $wordpress_context ),
			'compute_status_counts',
			$rows
		);

		$this->assertSame( 1, $counts['INFO'] );
		$this->assertSame( 0, $counts['OK'] );
	}

	/**
	 * An unrecognised status string is ignored rather than counted.
	 *
	 * The compute_status_counts method only increments buckets it knows
	 * about. A row claiming a status like 'PENDING' or 'UNKNOWN' produces
	 * no increment in any bucket; the totals reflect only valid statuses.
	 *
	 * @return void
	 */
	public function test_status_counts_unrecognised_status_is_ignored(): void {
		$environment       = Mockery::mock( Environment::class );
		$wordpress_context = Mockery::mock( WordPressContext::class );

		$rows = array(
			array( 'status' => 'OK' ),
			array( 'status' => 'PENDING' ),
			array( 'status' => 'UNKNOWN' ),
		);

		$counts = (array) $this->invoke_private(
			$this->build_command( $environment, $wordpress_context ),
			'compute_status_counts',
			$rows
		);

		$this->assertSame( 1, $counts['OK'] );
		$this->assertSame( 0, $counts['WARN'] );
		$this->assertSame( 0, $counts['FAIL'] );
		$this->assertSame( 0, $counts['INFO'] );
	}

	/**
	 * The returned array always contains exactly the four known status keys.
	 *
	 * Downstream code (print_summary's sprintf format) reads each of the
	 * four keys unconditionally, so the contract is "all four keys are
	 * always present, even when the count is zero".
	 *
	 * @return void
	 */
	public function test_status_counts_always_contains_four_known_keys(): void {
		$environment       = Mockery::mock( Environment::class );
		$wordpress_context = Mockery::mock( WordPressContext::class );

		$counts = (array) $this->invoke_private(
			$this->build_command( $environment, $wordpress_context ),
			'compute_status_counts',
			array()
		);

		$this->assertArrayHasKey( 'OK', $counts );
		$this->assertArrayHasKey( 'WARN', $counts );
		$this->assertArrayHasKey( 'FAIL', $counts );
		$this->assertArrayHasKey( 'INFO', $counts );
		$this->assertCount( 4, $counts );
	}
}
