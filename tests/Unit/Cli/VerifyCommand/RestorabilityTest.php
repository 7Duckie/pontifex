<?php
/**
 * Tests for VerifyCommand's print_restorability() and its inconclusive-check branch.
 *
 * @package Pontifex\Tests\Unit\Cli\VerifyCommand
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Cli\VerifyCommand;

use Mockery;
use Pontifex\Cli\NullProgressBar;
use Pontifex\Cli\VerifyCommand;
use Pontifex\Environment\Environment;
use Pontifex\Restore\PreflightReport;
use Pontifex\Tests\TestCase;
use Pontifex\WordPress\WordPressContext;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * Pins print_restorability() to report an inconclusive preflight check
 * alongside (or independently of) a host finding, without ever halting.
 *
 * Job 12 taught RestorePreflight and the admin Verify screen a third
 * outcome — a check that was attempted but never reached a decision, kept
 * apart from both "the archive is bad" and "this host cannot restore it
 * right now" because it asserts neither. `Cli\VerifyCommand::print_restorability()`
 * was left behind: it already printed nothing for a clean report and
 * nothing for an inconclusive one (it never claimed a host HAD the room, so
 * it never made the admin screen's false claim), but that also meant the
 * command line said nothing at all about a check the browser now reports.
 * This closes that gap generically, for whatever check turns out
 * inconclusive, matching the shape print_restorability() already uses for
 * host findings.
 *
 * print_restorability() is private, so a real VerifyCommand instance is
 * built with throwaway collaborators (nothing here reaches the restore
 * runner, the environment, or the logger) and the method is invoked
 * directly via reflection — the same pattern HelperMethodsTest.php uses for
 * VerifyCommand's other private helpers.
 */
final class RestorabilityTest extends TestCase {

	/**
	 * Invoke the private print_restorability() via reflection.
	 *
	 * @param PreflightReport $report The report to print.
	 * @return void
	 */
	private function print_restorability( PreflightReport $report ): void {
		$command = new VerifyCommand(
			Mockery::mock( Environment::class ),
			Mockery::mock( WordPressContext::class ),
			null,
			new NullLogger(),
			new NullProgressBar()
		);

		$reflection = new ReflectionMethod( VerifyCommand::class, 'print_restorability' );
		$reflection->invoke( $command, $report );
	}

	/**
	 * An inconclusive check prints a warning naming it, and the exit code is
	 * untouched — WP_CLI::halt() is never called.
	 *
	 * Break-verified: reverting print_restorability() to its pre-job-12-follow-up
	 * shape (`if ( ! $report->host_cannot_restore() ) { return; }`) makes this
	 * test fail, because the method would return immediately without WP_CLI
	 * ever being touched.
	 *
	 * @return void
	 */
	public function test_an_inconclusive_check_prints_a_warning_and_does_not_change_the_exit_code(): void {
		$report = new PreflightReport(
			array( 'free_space' ),
			array(),
			array(),
			array( 'free_space' => 'Whether the destination has enough free disk space could not be established on this host.' )
		);

		$logged = array();
		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'warning' )->once()->with(
			'Some checks about whether this host could restore it could not be answered:'
		);
		$wp_cli->shouldReceive( 'log' )->twice()->andReturnUsing(
			function ( string $message ) use ( &$logged ): void {
				$logged[] = $message;
			}
		);
		$wp_cli->shouldNotReceive( 'halt' );

		$this->print_restorability( $report );

		$this->assertSame(
			array(
				'  - Whether the destination has enough free disk space could not be established on this host.',
				'This is about this server, not about the backup.',
			),
			$logged
		);
	}

	/**
	 * A report carrying both a host finding and an inconclusive check prints
	 * both blocks, independently, in the same run.
	 *
	 * @return void
	 */
	public function test_a_host_finding_and_an_inconclusive_check_both_print(): void {
		$report = new PreflightReport(
			array( 'symlinks_creatable', 'free_space' ),
			array(),
			array( 'symlinks_creatable' => 'This host cannot create symbolic links.' ),
			array( 'free_space' => 'Whether the destination has enough free disk space could not be established on this host.' )
		);

		$logged = array();
		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'warning' )->once()->with(
			'The backup is fine, but this host could not restore it right now:'
		);
		$wp_cli->shouldReceive( 'warning' )->once()->with(
			'Some checks about whether this host could restore it could not be answered:'
		);
		$wp_cli->shouldReceive( 'log' )->times( 4 )->andReturnUsing(
			function ( string $message ) use ( &$logged ): void {
				$logged[] = $message;
			}
		);
		$wp_cli->shouldNotReceive( 'halt' );

		$this->print_restorability( $report );

		$this->assertSame(
			array(
				'  - This host cannot create symbolic links.',
				'This is about this server, not about the backup. Run wp pontifex import --dry-run to rehearse the whole restore.',
				'  - Whether the destination has enough free disk space could not be established on this host.',
				'This is about this server, not about the backup.',
			),
			$logged
		);
	}

	/**
	 * A fully clean report — no host finding, no inconclusive check — prints
	 * nothing at all.
	 *
	 * @return void
	 */
	public function test_a_fully_clean_report_prints_nothing(): void {
		$report = new PreflightReport( array( 'free_space', 'symlinks_creatable' ) );

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldNotReceive( 'warning' );
		$wp_cli->shouldNotReceive( 'log' );
		$wp_cli->shouldNotReceive( 'halt' );

		$this->print_restorability( $report );

		$this->addToAssertionCount( 1 );
	}
}
