<?php
/**
 * Surgical __invoke branch tests for RollbackCommand.
 *
 * @package Pontifex\Tests\Unit\Cli\RollbackCommand
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Cli\RollbackCommand;

use Brain\Monkey\Functions;
use Mockery;
use Pontifex\Cli\NullProgressBar;
use Pontifex\Cli\RollbackCommand;
use Pontifex\Environment\Environment;
use Pontifex\Job\JobStore;
use Pontifex\Lock\OperationLock;
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
	 * A successful rollback flushes the cache and records its own counters.
	 *
	 * The rollback replays the database with raw SQL, so the cache is flushed before
	 * the counter write (or it is lost), and the counters land in the separate
	 * rollback option so the admin Overview's Rollbacks row reflects a CLI rollback.
	 *
	 * @return void
	 */
	public function test_invoke_records_a_successful_rollback_in_the_counters(): void {
		$store = Mockery::mock( RollbackStoreInterface::class );
		$store->shouldReceive( 'most_recent' )->once()->andReturn( $this->temp_archive_path );

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'restore' )->once();

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();

		$flushed = false;
		$stats   = null;
		$context = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'option_value' )->andReturnUsing(
			static function ( string $name, $fallback = false ) {
				unset( $name );
				return $fallback;
			}
		);
		$context->shouldReceive( 'flush_cache' )->once()->andReturnUsing(
			static function () use ( &$flushed ): void {
				$flushed = true;
			}
		);
		$context->shouldReceive( 'save_option' )->andReturnUsing(
			static function ( string $name, $value ) use ( &$stats ): void {
				if ( 'pontifex_rollback_stats' === $name ) {
					$stats = $value;
				}
			}
		);

		$command = $this->build_command( $store, $restore_runner, new NullLogger(), $context );
		$command( array(), array( 'yes' => true ) );

		$this->assertTrue( $flushed, 'A rollback must flush the stale option cache before recording.' );
		$this->assertIsArray( $stats );
		$this->assertSame( 1, $stats['attempted'] );
		$this->assertSame( 1, $stats['succeeded'] );
		$this->assertSame( 0, $stats['failed'] );
		$this->assertArrayHasKey( 'bytes_rolled_back', $stats );
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
	 * With no safety archive, the lock is never acquired — the reorder regression test.
	 *
	 * Before the fix, acquire() ran ahead of require_most_recent(), so a missing
	 * safety archive left the holder transient set (WP_CLI::error exits the real
	 * process, skipping the finally that would have released it). The command now
	 * finds and validates the archive first, so a real OperationLock's
	 * acquire_named_lock() — the first thing acquire() itself calls — must never be
	 * reached; the command still errors exactly as before.
	 *
	 * @return void
	 */
	public function test_invoke_with_no_safety_archive_never_acquires_the_lock(): void {
		$store = Mockery::mock( RollbackStoreInterface::class );
		$store->shouldReceive( 'most_recent' )->once()->andReturnNull();

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldNotReceive( 'restore' );
		$restore_runner->shouldNotReceive( 'verify' );

		$lock_context = Mockery::mock( WordPressContext::class );
		$lock_context->shouldNotReceive( 'acquire_named_lock' );
		$lock_context->shouldNotReceive( 'release_named_lock' );
		$lock = new OperationLock( $lock_context, new JobStore( sys_get_temp_dir() . '/pontifex-rollback-lock-test-' . uniqid( '', true ) ) );

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'error' )->once()->andThrow( new RuntimeException( 'wp-cli halt: no safety archive' ) );

		$command = $this->build_command( $store, $restore_runner, new NullLogger(), null, $lock );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'wp-cli halt: no safety archive' );

		$command( array(), array() );
	}

	/**
	 * The shutdown backstop releases a lock this command still holds.
	 *
	 * Simulates a mid-work fatal: the lock was genuinely acquired (as if by a real
	 * run) but nothing else called release(), so is_held() is still true when
	 * release_lock_on_shutdown() runs — mirroring register_shutdown_function()
	 * firing after PHP dies mid-restore. A second call afterwards must be a no-op,
	 * proving the idempotent release() guard: the transient is cleared exactly once.
	 *
	 * @return void
	 */
	public function test_release_lock_on_shutdown_releases_a_held_lock(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		$delete_transient_calls = 0;
		Functions\when( 'delete_transient' )->alias(
			static function () use ( &$delete_transient_calls ): bool {
				++$delete_transient_calls;
				return true;
			}
		);

		$lock_context = Mockery::mock( WordPressContext::class );
		$lock_context->shouldReceive( 'acquire_named_lock' )->once()->andReturn( true );
		$lock_context->shouldReceive( 'release_named_lock' )->once();

		$lock = new OperationLock( $lock_context, new JobStore( sys_get_temp_dir() . '/pontifex-rollback-lock-test-' . uniqid( '', true ) ) );
		$this->assertTrue( $lock->acquire( OperationLock::OP_ROLLBACK ), 'The lock must be genuinely held before the shutdown handler runs.' );

		$command = new RollbackCommand(
			logger: new NullLogger(),
			progress: new NullProgressBar(),
			lock: $lock
		);

		$command->release_lock_on_shutdown();
		$this->assertSame( 1, $delete_transient_calls, 'A held lock must be released at shutdown, clearing the holder transient.' );
		$this->assertFalse( $lock->is_held(), 'The lock must no longer be held once the shutdown handler has released it.' );

		// A second shutdown call (e.g. two register_shutdown_function() registrations
		// firing) must not clear another operation's transient a second time.
		$command->release_lock_on_shutdown();
		$this->assertSame( 1, $delete_transient_calls, 'A second shutdown call after a clean release must be a no-op.' );
	}

	/**
	 * The shutdown backstop is a no-op when the lock was never acquired (or was
	 * already released cleanly through the normal finally).
	 *
	 * @return void
	 */
	public function test_release_lock_on_shutdown_is_a_no_op_when_the_lock_is_not_held(): void {
		$lock_context = Mockery::mock( WordPressContext::class );
		$lock_context->shouldNotReceive( 'acquire_named_lock' );
		$lock_context->shouldNotReceive( 'release_named_lock' );

		$lock = new OperationLock( $lock_context, new JobStore( sys_get_temp_dir() . '/pontifex-rollback-lock-test-' . uniqid( '', true ) ) );

		$command = new RollbackCommand(
			logger: new NullLogger(),
			progress: new NullProgressBar(),
			lock: $lock
		);

		$command->release_lock_on_shutdown();

		$this->assertFalse( $lock->is_held(), 'A lock that was never held must still report unheld after the no-op shutdown call.' );
	}

	/**
	 * Build a RollbackCommand with injected store, runner, and logger.
	 *
	 * The Environment is a bare mock (its default-wiring path is never reached). The
	 * WordPressContext is stubbed to tolerate the post-rollback counter write, or a
	 * caller may inject one to assert on it.
	 *
	 * @param RollbackStoreInterface $store          The injected store.
	 * @param RestoreRunnerInterface $restore_runner The injected restore engine.
	 * @param LoggerInterface        $logger         The injected logger.
	 * @param WordPressContext|null  $context        Optional. A custom context to assert on; a tolerant stub by default.
	 * @param OperationLock|null     $lock           Optional. A custom lock to assert on; the default lazy wiring by default.
	 * @return RollbackCommand
	 */
	private function build_command( $store, $restore_runner, $logger, ?WordPressContext $context = null, ?OperationLock $lock = null ): RollbackCommand {
		if ( null === $context ) {
			$context = Mockery::mock( WordPressContext::class );
			$context->shouldReceive( 'option_value' )->andReturnUsing(
				static function ( string $name, $fallback = false ) {
					unset( $name );
					return $fallback;
				}
			);
			$context->shouldReceive( 'save_option' );
			$context->shouldReceive( 'flush_cache' );
		}
		// The shared single-runner lock: free by default so __invoke's new lock
		// acquisition does not need a dedicated stub in every test. The named
		// lock is granted through the context mock above; the holder transient
		// OperationLock reads/writes directly via the global WordPress transient
		// functions, stubbed here to a plain "nothing is running" default.
		$context->shouldReceive( 'acquire_named_lock' )->andReturn( true );
		$context->shouldReceive( 'release_named_lock' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );

		$environment = Mockery::mock( Environment::class );
		// Bare in every other respect (the default-wiring path is never reached
		// when the restore engine is injected, as it always is here); these two
		// are what the shared lock's default JobStore needs to resolve its
		// content root.
		$environment->shouldReceive( 'is_constant_defined' )->with( 'WP_CONTENT_DIR' )->andReturn( true );
		$environment->shouldReceive( 'constant_value' )->with( 'WP_CONTENT_DIR' )->andReturn( '/var/www/html/wp-content' );

		return new RollbackCommand(
			$environment,
			$context,
			$store,
			$restore_runner,
			$logger,
			new NullProgressBar(),
			$lock
		);
	}
}
