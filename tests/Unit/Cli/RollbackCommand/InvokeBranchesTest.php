<?php
/**
 * Surgical __invoke branch tests for RollbackCommand.
 *
 * @package Pontifex\Tests\Unit\Cli\RollbackCommand
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Cli\RollbackCommand;

use Mockery;
use Pontifex\Cli\NullProgressBar;
use Pontifex\Cli\RollbackCommand;
use Pontifex\Environment\Environment;
use Pontifex\Restore\RestoreRunnerInterface;
use Pontifex\Rollback\RollbackStoreInterface;
use Pontifex\Tests\TestCase;
use Pontifex\WordPress\WordPressContext;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Behavioural coverage of the genuine __invoke branches.
 *
 * The branches worth a surgical test are rollback's control-flow facts:
 *
 *  1. With no safety archive, the command stops at a clear error and never
 *     opens a stream or restores.
 *  2. With one present, --yes restores it (skipping the confirmation prompt).
 *  3. --dry-run verifies the archive and never restores — the "touch nothing"
 *     contract.
 *  4. A restore failure is logged and re-thrown unswallowed, as with import.
 *
 * The store and restore engine are injected as their interfaces — the seams
 * that exist precisely so these final classes can be faked here — so neither
 * the default store wiring nor the default RestoreRunner wiring is exercised.
 */
final class InvokeBranchesTest extends TestCase {


	/**
	 * A real temporary safety-archive file used as the rollback source.
	 *
	 * @var string|null
	 */
	private ?string $temp_archive_path = null;

	/**
	 * Create a real, readable temp archive file (the runner is mocked, so its
	 * bytes are never parsed).
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->temp_archive_path = sys_get_temp_dir() . '/pontifex-rollback-invoke-test-' . uniqid( '', true ) . '.wpmig';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Creating an empty, readable source file for the command to fopen; WP_Filesystem is not bootstrapped in unit tests.
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
	 * With no safety archive, the command errors and never restores.
	 *
	 * Because most_recent() returns null, require_most_recent reaches WP_CLI::error.
	 * The mock makes error() throw (standing in for its real halting), and the
	 * test asserts that throw and that the runner was never touched.
	 *
	 * @return void
	 */
	public function test_invoke_with_no_safety_archive_errors_and_does_not_restore(): void {
		$store = Mockery::mock( RollbackStoreInterface::class );
		$store->shouldReceive( 'most_recent' )->once()->andReturnNull();

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldNotReceive( 'restore' );
		$restore_runner->shouldNotReceive( 'verify' );

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'error' )->once()->andThrow( new RuntimeException( 'wp-cli halt: no safety archive' ) );

		$command = $this->build_command( $store, $restore_runner, new NullLogger() );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'wp-cli halt: no safety archive' );

		$command( array(), array() );
	}

	/**
	 * --yes restores the most recent archive and skips the confirmation prompt.
	 *
	 * @return void
	 */
	public function test_invoke_with_yes_restores_most_recent_without_confirming(): void {
		$store = Mockery::mock( RollbackStoreInterface::class );
		$store->shouldReceive( 'most_recent' )->once()->andReturn( $this->temp_archive_path );

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'restore' )->once();
		$restore_runner->shouldNotReceive( 'verify' );

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldNotReceive( 'confirm' );

		$logger = Mockery::mock( LoggerInterface::class );
		$logger->shouldReceive( 'info' )->atLeast()->once();
		$logger->shouldReceive( 'error' )->never();

		$command = $this->build_command( $store, $restore_runner, $logger );

		$command( array(), array( 'yes' => true ) );

		$this->assertFileExists(
			$this->temp_archive_path,
			'Rollback should read the safety archive without removing it.'
		);
	}

	/**
	 * --dry-run verifies the archive and never restores.
	 *
	 * @return void
	 */
	public function test_invoke_dry_run_verifies_without_restoring(): void {
		$store = Mockery::mock( RollbackStoreInterface::class );
		$store->shouldReceive( 'most_recent' )->once()->andReturn( $this->temp_archive_path );

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'verify' )->once();
		$restore_runner->shouldNotReceive( 'restore' );

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldNotReceive( 'confirm' );

		$command = $this->build_command( $store, $restore_runner, new NullLogger() );

		$command( array(), array( 'dry-run' => true ) );

		$this->assertFileExists(
			$this->temp_archive_path,
			'A dry-run rollback must change nothing.'
		);
	}

	/**
	 * A restore failure is logged at error level and re-thrown unchanged.
	 *
	 * @return void
	 */
	public function test_invoke_propagates_and_logs_a_restore_failure(): void {
		$store = Mockery::mock( RollbackStoreInterface::class );
		$store->shouldReceive( 'most_recent' )->once()->andReturn( $this->temp_archive_path );

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'restore' )->once()->andThrow( new RuntimeException( 'simulated rollback failure' ) );

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();

		$logger = Mockery::mock( LoggerInterface::class );
		$logger->shouldReceive( 'info' )->zeroOrMoreTimes();
		$logger->shouldReceive( 'error' )->once();

		$command = $this->build_command( $store, $restore_runner, $logger );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'simulated rollback failure' );

		$command( array(), array( 'yes' => true ) );
	}

	/**
	 * Build a RollbackCommand with injected store, runner, and logger.
	 *
	 * The Environment and WordPressContext are bare mocks: with a store and a
	 * runner injected, neither default-wiring path is reached.
	 *
	 * @param RollbackStoreInterface $store          The injected store.
	 * @param RestoreRunnerInterface $restore_runner The injected restore engine.
	 * @param LoggerInterface        $logger         The injected logger.
	 * @return RollbackCommand
	 */
	private function build_command( $store, $restore_runner, $logger ): RollbackCommand {
		return new RollbackCommand(
			Mockery::mock( Environment::class ),
			Mockery::mock( WordPressContext::class ),
			$store,
			$restore_runner,
			$logger,
			new NullProgressBar()
		);
	}
}
