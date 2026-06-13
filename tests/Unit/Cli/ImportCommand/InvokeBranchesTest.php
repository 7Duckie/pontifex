<?php
/**
 * Surgical __invoke branch tests for ImportCommand.
 *
 * @package Pontifex\Tests\Unit\Cli\ImportCommand
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Cli\ImportCommand;

use Mockery;
use Pontifex\Cli\ImportCommand;
use Pontifex\Cli\NullProgressBar;
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
 * As with ExportCommand, the bulk of orchestration is not worth a
 * behavioural __invoke test — mocking every collaborator only to assert
 * "the code calls the methods I made it call" is coverage theatre. The
 * branches that genuinely escape the helper/integration layers and earn
 * a surgical unit test are:
 *
 *  1. The --yes short-circuit: confirm() is never called when --yes is
 *     set, because __invoke reads the flag before delegating.
 *  2. The try-finally exception path: the source archive is opened
 *     before the restore runs; if restore() throws, the finally must
 *     close the handle and the exception must propagate unswallowed.
 *  3. The --dry-run branch: it calls verify() (not restore()) and writes
 *     no counters — the "touch nothing" contract.
 *
 * The logging assertions (info on success, error on failure) live here
 * for the same reason: they are __invoke control-flow facts.
 *
 * The restore runner is injected as a RestoreRunnerInterface mock — the
 * interface that exists precisely so this final-class engine can be
 * faked here. With a runner injected, the default wiring
 * (build_default_restore_runner) is never reached, so FileWriter,
 * DatabaseWriter, WpdbAdapter and the Environment seam are not exercised
 * by these tests; Phase 6 integration tests cover that wiring for free.
 */
final class InvokeBranchesTest extends TestCase {


	/**
	 * A real temporary archive file used as the import source.
	 *
	 * Created in setUp (empty is fine — the runner is mocked, so the
	 * bytes are never parsed) and removed in tearDown. Real path, not
	 * mocked, because ImportCommand calls fopen() against it directly
	 * and Mockery cannot intercept stream resources.
	 *
	 * @var string|null
	 */
	private ?string $temp_archive_path = null;

	/**
	 * Create a real, readable temp archive file for the import source.
	 *
	 * Empty file: ImportCommand fopen()s it for reading, but the injected
	 * runner mock never parses its contents.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->temp_archive_path = sys_get_temp_dir() . '/pontifex-import-invoke-test-' . uniqid( '', true ) . '.wpmig';
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
	 * Passing --yes must short-circuit WP_CLI::confirm so it is never invoked.
	 *
	 * The check sits in __invoke before the confirm call. A regression
	 * that dropped the if-statement would still pass every helper test.
	 *
	 * @return void
	 */
	public function test_invoke_with_yes_flag_short_circuits_confirmation(): void {
		$environment       = $this->build_environment_mock();
		$wordpress_context = $this->build_wordpress_context_mock();
		$restore_runner    = $this->build_restore_runner_mock_succeeding();

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldNotReceive( 'confirm' );

		$command = new ImportCommand( $environment, $wordpress_context, $restore_runner, new NullLogger(), new NullProgressBar() );

		$command(
			array( $this->temp_archive_path ),
			array( 'yes' => true )
		);

		$this->assertFileExists(
			$this->temp_archive_path,
			'ImportCommand should have run to completion with --yes set.'
		);
	}

	/**
	 * An exception thrown by RestoreRunner::restore must propagate out of __invoke.
	 *
	 * The source archive is opened before the restore runs. A try-finally
	 * closes the handle on failure as well as success; a regression that
	 * swallowed the exception would hide the failure from the user.
	 *
	 * @return void
	 */
	public function test_invoke_propagates_restore_exception(): void {
		$environment       = $this->build_environment_mock();
		$wordpress_context = $this->build_wordpress_context_mock();

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner
			->shouldReceive( 'restore' )
			->once()
			->andThrow( new RuntimeException( 'simulated restore failure' ) );

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();

		$command = new ImportCommand( $environment, $wordpress_context, $restore_runner, new NullLogger(), new NullProgressBar() );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'simulated restore failure' );

		$command(
			array( $this->temp_archive_path ),
			array( 'yes' => true )
		);
	}

	/**
	 * A successful import records informational log lines and no error.
	 *
	 * The command logs an "Import started" line and an "Import complete"
	 * line on the happy path. A regression that dropped the logging, or
	 * logged an error on success, would fail here.
	 *
	 * @return void
	 */
	public function test_invoke_logs_info_on_success(): void {
		$environment       = $this->build_environment_mock();
		$wordpress_context = $this->build_wordpress_context_mock();
		$restore_runner    = $this->build_restore_runner_mock_succeeding();

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();

		$logger = Mockery::mock( LoggerInterface::class );
		$logger->shouldReceive( 'info' )->atLeast()->once();
		$logger->shouldReceive( 'error' )->never();

		$command = new ImportCommand( $environment, $wordpress_context, $restore_runner, $logger, new NullProgressBar() );

		$command(
			array( $this->temp_archive_path ),
			array( 'yes' => true )
		);

		$this->assertFileExists(
			$this->temp_archive_path,
			'ImportCommand should have run to completion on the happy path.'
		);
	}

	/**
	 * A failing import records an error log line and re-throws unchanged.
	 *
	 * When restore() throws, the command logs an "Import failed" line at
	 * error level and re-throws the original exception so it reaches
	 * WP-CLI. Guards both halves: the error is logged, and not swallowed.
	 *
	 * @return void
	 */
	public function test_invoke_logs_error_when_restore_fails(): void {
		$environment       = $this->build_environment_mock();
		$wordpress_context = $this->build_wordpress_context_mock();

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner
			->shouldReceive( 'restore' )
			->once()
			->andThrow( new RuntimeException( 'simulated restore failure' ) );

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();

		$logger = Mockery::mock( LoggerInterface::class );
		$logger->shouldReceive( 'info' )->zeroOrMoreTimes();
		$logger->shouldReceive( 'error' )->once();

		$command = new ImportCommand( $environment, $wordpress_context, $restore_runner, $logger, new NullProgressBar() );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'simulated restore failure' );

		$command(
			array( $this->temp_archive_path ),
			array( 'yes' => true )
		);
	}

	/**
	 * A --dry-run calls verify(), never restore(), and writes no counters.
	 *
	 * This is the "touch nothing" contract: dry-run reads and verifies
	 * the archive but performs no write — not to the site, and not to the
	 * counters option. It also skips the confirmation prompt, since there
	 * is nothing to confirm.
	 *
	 * @return void
	 */
	public function test_invoke_dry_run_verifies_without_restoring_or_counting(): void {
		$environment = $this->build_environment_mock();

		// No counters must be written, so save_option must never be called.
		$wordpress_context = Mockery::mock( WordPressContext::class );
		$wordpress_context->shouldNotReceive( 'save_option' );

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'verify' )->once();
		$restore_runner->shouldNotReceive( 'restore' );

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldNotReceive( 'confirm' );

		$command = new ImportCommand( $environment, $wordpress_context, $restore_runner, new NullLogger(), new NullProgressBar() );

		$command(
			array( $this->temp_archive_path ),
			array( 'dry-run' => true )
		);

		$this->assertFileExists(
			$this->temp_archive_path,
			'A dry-run should read the archive without removing or altering it.'
		);
	}

	/**
	 * Build a bare Environment mock.
	 *
	 * With a RestoreRunner injected, __invoke never reaches the default
	 * wiring, so it makes no Environment calls; the mock needs no
	 * expectations.
	 *
	 * @return Environment&\Mockery\MockInterface
	 */
	private function build_environment_mock() {
		return Mockery::mock( Environment::class );
	}

	/**
	 * Build a WordPressContext mock for the happy (real-run) path.
	 *
	 * The bump_counters path reads option_value and writes save_option;
	 * print_summary calls format_size. All permissive — the tests assert
	 * control flow, not stored values.
	 *
	 * @return WordPressContext&\Mockery\MockInterface
	 */
	private function build_wordpress_context_mock() {
		$mock = Mockery::mock( WordPressContext::class );
		$mock->shouldReceive( 'option_value' )->andReturn( array() );
		$mock->shouldReceive( 'save_option' )->zeroOrMoreTimes();
		$mock->shouldReceive( 'format_size' )->andReturn( '0 B' );
		return $mock;
	}

	/**
	 * Build a RestoreRunnerInterface mock whose restore() succeeds silently.
	 *
	 * The mock does not invoke the progress callback; the per-entry
	 * callback contract is proven in RestoreRunnerTest, not here.
	 *
	 * @return RestoreRunnerInterface&\Mockery\MockInterface
	 */
	private function build_restore_runner_mock_succeeding() {
		$mock = Mockery::mock( RestoreRunnerInterface::class );
		$mock->shouldReceive( 'restore' )->once();
		return $mock;
	}
}
