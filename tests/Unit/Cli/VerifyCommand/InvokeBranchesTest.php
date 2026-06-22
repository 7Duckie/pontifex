<?php
/**
 * Surgical __invoke branch tests for VerifyCommand.
 *
 * @package Pontifex\Tests\Unit\Cli\VerifyCommand
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Cli\VerifyCommand;

use Mockery;
use Pontifex\Cli\NullProgressBar;
use Pontifex\Cli\VerifyCommand;
use Pontifex\Environment\Environment;
use Pontifex\Restore\RestoreRunnerInterface;
use Pontifex\Tests\TestCase;
use Pontifex\WordPress\WordPressContext;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Behavioural coverage of the genuine __invoke branches.
 *
 * As with ImportCommand, most orchestration is not worth a behavioural
 * __invoke test. The branches that genuinely earn a surgical unit test are
 * verify's defining control-flow facts, all distinct from import:
 *
 *  1. A sound archive logs success, prints its verdict, and does NOT halt —
 *     the command exits 0 by returning normally.
 *  2. A broken archive does NOT propagate the engine's exception (unlike
 *     import, which re-throws); it swallows it, logs an error, and halts
 *     non-zero so a script sees a failing exit code.
 *  3. Verify drives the engine's verify() walk, never restore() — the
 *     "writes nothing" contract.
 *
 * The restore engine is injected as a RestoreRunnerInterface mock — the
 * interface that exists precisely so this final-class engine can be faked.
 * With a runner injected, the default wiring (build_default_restore_runner)
 * is never reached, so FileWriter, DatabaseWriter, WpdbAdapter and the
 * Environment/WordPressContext seams are not exercised here. The --list path
 * and its WP-CLI formatter are exercised by the wp-env smoke, since they
 * need the WP-CLI runtime; the pure row-building logic is unit-tested in
 * HelperMethodsTest.
 */
final class InvokeBranchesTest extends TestCase {


	/**
	 * A real temporary archive file used as the verify source.
	 *
	 * Created in setUp (empty is fine — the runner is mocked, so the bytes
	 * are never parsed) and removed in tearDown. Real path, not mocked,
	 * because VerifyCommand calls fopen() against it directly and Mockery
	 * cannot intercept stream resources.
	 *
	 * @var string|null
	 */
	private ?string $temp_archive_path = null;

	/**
	 * Create a real, readable temp archive file for the verify source.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->temp_archive_path = sys_get_temp_dir() . '/pontifex-verify-invoke-test-' . uniqid( '', true ) . '.wpmig';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Creating an empty, readable source file in sys_get_temp_dir() for the command to fopen; WP_Filesystem is not bootstrapped in unit tests.
		touch( $this->temp_archive_path );
	}

	/**
	 * Remove the temp archive file the test created.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ( null !== $this->temp_archive_path && file_exists( $this->temp_archive_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test-only cleanup of a file the test itself created in sys_get_temp_dir().
			unlink( $this->temp_archive_path );
		}
		$this->temp_archive_path = null;
		parent::tearDown();
	}

	/**
	 * A sound archive logs success, never logs an error, and never halts.
	 *
	 * The verify() call returns cleanly, so the command must report the sound
	 * verdict and exit 0 by returning — a regression that halted on success,
	 * or logged an error, would fail here.
	 *
	 * @return void
	 */
	public function test_invoke_sound_archive_logs_info_and_does_not_halt(): void {
		$environment       = Mockery::mock( Environment::class );
		$wordpress_context = Mockery::mock( WordPressContext::class );

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'verify' )->once();
		$restore_runner->shouldNotReceive( 'restore' );

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldNotReceive( 'halt' );

		$logger = Mockery::mock( LoggerInterface::class );
		$logger->shouldReceive( 'info' )->atLeast()->once();
		$logger->shouldReceive( 'error' )->never();

		$command = new VerifyCommand( $environment, $wordpress_context, $restore_runner, $logger, new NullProgressBar() );

		$command(
			array( $this->temp_archive_path ),
			array()
		);

		$this->assertFileExists(
			$this->temp_archive_path,
			'VerifyCommand should have run to completion on a sound archive.'
		);
	}

	/**
	 * A broken archive is not re-thrown: the command swallows it and halts non-zero.
	 *
	 * Unlike import, verify converts a failure into a verdict and an exit
	 * code rather than letting the exception reach WP-CLI. It must log the
	 * error, print the broken verdict, and call WP_CLI::halt(1).
	 *
	 * @return void
	 */
	public function test_invoke_broken_archive_halts_nonzero_and_logs_error(): void {
		$environment       = Mockery::mock( Environment::class );
		$wordpress_context = Mockery::mock( WordPressContext::class );

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner
			->shouldReceive( 'verify' )
			->once()
			->andThrow( new RuntimeException( 'entry 3: stored hash does not match computed hash' ) );

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'halt' )->once()->with( 1 );

		$logger = Mockery::mock( LoggerInterface::class );
		$logger->shouldReceive( 'info' )->zeroOrMoreTimes();
		$logger->shouldReceive( 'error' )->once();

		$command = new VerifyCommand( $environment, $wordpress_context, $restore_runner, $logger, new NullProgressBar() );

		// No expectException: the command must NOT re-throw. It returns after
		// halting, and Mockery verifies halt(1) and error() were called.
		$command(
			array( $this->temp_archive_path ),
			array()
		);

		$this->assertFileExists(
			$this->temp_archive_path,
			'VerifyCommand should swallow the failure and run to completion (halt is mocked).'
		);
	}

	/**
	 * Verify drives the engine's verify() walk and never its restore() walk.
	 *
	 * The "writes nothing" contract: a regression that called restore()
	 * would write to the destination. This guards that verify() — and only
	 * verify() — is invoked.
	 *
	 * @return void
	 */
	public function test_invoke_calls_verify_never_restore(): void {
		$environment       = Mockery::mock( Environment::class );
		$wordpress_context = Mockery::mock( WordPressContext::class );

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'verify' )->once();
		$restore_runner->shouldNotReceive( 'restore' );

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'halt' )->zeroOrMoreTimes();

		$command = new VerifyCommand( $environment, $wordpress_context, $restore_runner, new NullLogger(), new NullProgressBar() );

		$command(
			array( $this->temp_archive_path ),
			array()
		);

		$this->assertFileExists(
			$this->temp_archive_path,
			'A verify must read the archive without removing or altering it.'
		);
	}
}
