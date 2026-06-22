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
use Pontifex\Rollback\SafetyArchiverInterface;
use Pontifex\Tests\TestCase;
use Pontifex\WordPress\WordPressContext;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Behavioural coverage of the genuine __invoke branches.
 *
 * As with ExportCommand, the bulk of orchestration is not worth a behavioural
 * __invoke test. The branches that genuinely earn a surgical unit test are:
 *
 *  1. The --yes short-circuit: confirm() is never called when --yes is set.
 *  2. The try-finally exception path: a restore failure closes the handle and
 *     propagates unswallowed.
 *  3. The --dry-run branch: it calls verify() (not restore()), writes no
 *     counters, and takes no safety archive.
 *  4. The safety archive (v0.2.0): a real import takes one before restoring;
 *     --no-rollback-archive skips it; and a safety-archive failure aborts the
 *     import before the destructive restore runs.
 *
 * The restore engine and the safety archiver are injected as their interfaces —
 * the seams that exist precisely so these final-class collaborators can be faked
 * here. With them injected, the default wiring is never reached.
 */
final class InvokeBranchesTest extends TestCase {


	/**
	 * A real temporary archive file used as the import source.
	 *
	 * @var string|null
	 */
	private ?string $temp_archive_path = null;

	/**
	 * Create a real, readable temp archive file for the import source.
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
	 * @return void
	 */
	public function test_invoke_with_yes_flag_short_circuits_confirmation(): void {
		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldNotReceive( 'confirm' );

		$command = new ImportCommand(
			$this->build_environment_mock(),
			$this->build_wordpress_context_mock(),
			$this->build_restore_runner_mock_succeeding(),
			new NullLogger(),
			new NullProgressBar(),
			$this->build_safety_archiver_succeeding()
		);

		$command( array( $this->temp_archive_path ), array( 'yes' => true ) );

		$this->assertFileExists(
			$this->temp_archive_path,
			'ImportCommand should have run to completion with --yes set.'
		);
	}

	/**
	 * An exception thrown by RestoreRunner::restore must propagate out of __invoke.
	 *
	 * @return void
	 */
	public function test_invoke_propagates_restore_exception(): void {
		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'restore' )->once()->andThrow( new RuntimeException( 'simulated restore failure' ) );

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();

		$command = new ImportCommand(
			$this->build_environment_mock(),
			$this->build_wordpress_context_mock(),
			$restore_runner,
			new NullLogger(),
			new NullProgressBar(),
			$this->build_safety_archiver_succeeding()
		);

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'simulated restore failure' );

		$command( array( $this->temp_archive_path ), array( 'yes' => true ) );
	}

	/**
	 * A successful import records informational log lines and no error.
	 *
	 * @return void
	 */
	public function test_invoke_logs_info_on_success(): void {
		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();

		$logger = Mockery::mock( LoggerInterface::class );
		$logger->shouldReceive( 'info' )->atLeast()->once();
		$logger->shouldReceive( 'error' )->never();

		$command = new ImportCommand(
			$this->build_environment_mock(),
			$this->build_wordpress_context_mock(),
			$this->build_restore_runner_mock_succeeding(),
			$logger,
			new NullProgressBar(),
			$this->build_safety_archiver_succeeding()
		);

		$command( array( $this->temp_archive_path ), array( 'yes' => true ) );

		$this->assertFileExists(
			$this->temp_archive_path,
			'ImportCommand should have run to completion on the happy path.'
		);
	}

	/**
	 * A failing import records an error log line and re-throws unchanged.
	 *
	 * @return void
	 */
	public function test_invoke_logs_error_when_restore_fails(): void {
		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'restore' )->once()->andThrow( new RuntimeException( 'simulated restore failure' ) );

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();

		$logger = Mockery::mock( LoggerInterface::class );
		$logger->shouldReceive( 'info' )->zeroOrMoreTimes();
		$logger->shouldReceive( 'error' )->once();

		$command = new ImportCommand(
			$this->build_environment_mock(),
			$this->build_wordpress_context_mock(),
			$restore_runner,
			$logger,
			new NullProgressBar(),
			$this->build_safety_archiver_succeeding()
		);

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'simulated restore failure' );

		$command( array( $this->temp_archive_path ), array( 'yes' => true ) );
	}

	/**
	 * A --dry-run calls verify(), never restore(), writes no counters, and takes no safety archive.
	 *
	 * @return void
	 */
	public function test_invoke_dry_run_verifies_without_restoring_or_counting(): void {
		$wordpress_context = Mockery::mock( WordPressContext::class );
		$wordpress_context->shouldNotReceive( 'save_option' );

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'verify' )->once();
		$restore_runner->shouldNotReceive( 'restore' );

		$safety_archiver = Mockery::mock( SafetyArchiverInterface::class );
		$safety_archiver->shouldNotReceive( 'create' );

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldNotReceive( 'confirm' );

		$command = new ImportCommand(
			$this->build_environment_mock(),
			$wordpress_context,
			$restore_runner,
			new NullLogger(),
			new NullProgressBar(),
			$safety_archiver
		);

		$command( array( $this->temp_archive_path ), array( 'dry-run' => true ) );

		$this->assertFileExists(
			$this->temp_archive_path,
			'A dry-run should read the archive without removing or altering it.'
		);
	}

	/**
	 * A real import takes a safety archive before it restores.
	 *
	 * Ordering is asserted: create() must be called before restore(), so the
	 * undo exists before the destructive write begins.
	 *
	 * @return void
	 */
	public function test_invoke_takes_a_safety_archive_before_restoring(): void {
		$safety_archiver = Mockery::mock( SafetyArchiverInterface::class );
		$safety_archiver->shouldReceive( 'create' )->once()->ordered()->andReturn( '/var/www/html/wp-content/pontifex/rollback/safety.wpmig' );

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'restore' )->once()->ordered();

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();

		$command = new ImportCommand(
			$this->build_environment_mock(),
			$this->build_wordpress_context_mock(),
			$restore_runner,
			new NullLogger(),
			new NullProgressBar(),
			$safety_archiver
		);

		$command( array( $this->temp_archive_path ), array( 'yes' => true ) );

		$this->assertFileExists( $this->temp_archive_path );
	}

	/**
	 * --no-rollback-archive skips the safety archive but still restores.
	 *
	 * @return void
	 */
	public function test_invoke_no_rollback_archive_flag_skips_the_safety_archive(): void {
		$safety_archiver = Mockery::mock( SafetyArchiverInterface::class );
		$safety_archiver->shouldNotReceive( 'create' );

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'restore' )->once();

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();

		$command = new ImportCommand(
			$this->build_environment_mock(),
			$this->build_wordpress_context_mock(),
			$restore_runner,
			new NullLogger(),
			new NullProgressBar(),
			$safety_archiver
		);

		$command(
			array( $this->temp_archive_path ),
			array(
				'yes'                 => true,
				'no-rollback-archive' => true,
			)
		);

		$this->assertFileExists( $this->temp_archive_path );
	}

	/**
	 * A safety-archive failure aborts the import before the restore runs.
	 *
	 * The safety archive is written before the destructive restore, so if it
	 * throws (e.g. the disk preflight refuses), restore() must never be reached;
	 * the failure is logged and re-thrown.
	 *
	 * @return void
	 */
	public function test_invoke_safety_archive_failure_aborts_before_restore(): void {
		$safety_archiver = Mockery::mock( SafetyArchiverInterface::class );
		$safety_archiver->shouldReceive( 'create' )->once()->andThrow( new RuntimeException( 'not enough free disk space' ) );

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldNotReceive( 'restore' );

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();

		$logger = Mockery::mock( LoggerInterface::class );
		$logger->shouldReceive( 'info' )->zeroOrMoreTimes();
		$logger->shouldReceive( 'error' )->once();

		$command = new ImportCommand(
			$this->build_environment_mock(),
			$this->build_wordpress_context_mock(),
			$restore_runner,
			$logger,
			new NullProgressBar(),
			$safety_archiver
		);

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'not enough free disk space' );

		$command( array( $this->temp_archive_path ), array( 'yes' => true ) );
	}

	/**
	 * Build an Environment mock that answers the ABSPATH lookup.
	 *
	 * The take_safety_archive step resolves the WordPress root through ABSPATH to
	 * feed the archiver; the restore path never reaches the Environment because a
	 * runner is injected.
	 *
	 * @return Environment&\Mockery\MockInterface
	 */
	private function build_environment_mock() {
		$mock = Mockery::mock( Environment::class );
		$mock->shouldReceive( 'is_constant_defined' )->with( 'ABSPATH' )->andReturn( true );
		$mock->shouldReceive( 'constant_value' )->with( 'ABSPATH' )->andReturn( '/var/www/html' );
		return $mock;
	}

	/**
	 * Build a WordPressContext mock for the real-run path.
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
	 * @return RestoreRunnerInterface&\Mockery\MockInterface
	 */
	private function build_restore_runner_mock_succeeding() {
		$mock = Mockery::mock( RestoreRunnerInterface::class );
		$mock->shouldReceive( 'restore' )->once();
		return $mock;
	}

	/**
	 * Build a SafetyArchiverInterface mock whose create() succeeds, returning a path.
	 *
	 * @return SafetyArchiverInterface&\Mockery\MockInterface
	 */
	private function build_safety_archiver_succeeding() {
		$mock = Mockery::mock( SafetyArchiverInterface::class );
		$mock->shouldReceive( 'create' )->once()->andReturn( '/var/www/html/wp-content/pontifex/rollback/safety.wpmig' );
		return $mock;
	}
}
