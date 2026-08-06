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
use Pontifex\Archive\Crypto\CipherException;
use Pontifex\Cli\NullProgressBar;
use Pontifex\Cli\RollbackCommand;
use Pontifex\Environment\Environment;
use Pontifex\Exception\ArchiveNotTrustworthy;
use Pontifex\Exception\HostCannotComply;
use Pontifex\Exception\InvalidRequest;
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
 *  4. A restore failure is logged, reported as a verdict naming which kind of
 *     refusal it was (ADR 0022), and halted on rather than re-thrown — and,
 *     when the replay had already begun, said plainly to have left the site
 *     part rolled back.
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
	 * A restore failure is logged at error level, reported, and halted on.
	 *
	 * It used to be re-thrown out of __invoke, where WordPress's fatal handler caught
	 * it and printed a raw stack trace followed by "There has been a critical error on
	 * this website". The exit code was already non-zero and stays so; what changes is
	 * that a human can read why it stopped. The log entry is what a browser-side
	 * operator falls back on, so the verdict is in addition to it, never instead.
	 *
	 * @return void
	 */
	public function test_invoke_logs_and_reports_a_restore_failure_instead_of_propagating(): void {
		$store = Mockery::mock( RollbackStoreInterface::class );
		$store->shouldReceive( 'most_recent' )->once()->andReturn( $this->temp_archive_path );

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'restore' )->once()->andThrow( new RuntimeException( 'simulated rollback failure' ) );

		$printed = array();
		$wp_cli  = $this->mock_wp_cli_capturing_output( $printed );
		$wp_cli->shouldReceive( 'halt' )->once()->with( 1 );

		$logger = Mockery::mock( LoggerInterface::class );
		$logger->shouldReceive( 'info' )->zeroOrMoreTimes();
		$logger->shouldReceive( 'error' )->once();

		$command = $this->build_command( $store, $restore_runner, $logger );

		$command( array(), array( 'yes' => true ) );

		$output = implode( "\n", $printed );
		$this->assertStringContainsString( 'Rollback failed.', $output );
		$this->assertStringContainsString( 'The failure was: simulated rollback failure', $output );
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

	/**
	 * A safety archive that cannot be trusted is named as such, with advice that fits it.
	 *
	 * Import's advice — fetch a fresh copy of the backup — is wrong here. A safety
	 * archive is written automatically, in one copy, at the moment of the import it
	 * undoes; there is no second copy anywhere to fetch.
	 *
	 * @return void
	 */
	public function test_invoke_reports_an_untrustworthy_safety_archive_and_halts(): void {
		$printed = array();
		$wp_cli  = $this->mock_wp_cli_capturing_output( $printed );
		$wp_cli->shouldReceive( 'halt' )->once()->with( 1 );

		$this->run_failing_dry_run( new ArchiveNotTrustworthy( 'the entry hash does not match' ) );

		$output = implode( "\n", $printed );
		$this->assertStringContainsString( 'Dry run: this rollback would be refused. Your site was not changed.', $output );
		$this->assertStringContainsString( 'This safety archive cannot be trusted: the entry hash does not match', $output );
		$this->assertStringContainsString( 'there is no second copy of it', $output );
		$this->assertStringNotContainsString( 'fetch a fresh copy', $output, 'There is no fresh copy of a safety archive to fetch.' );
	}

	/**
	 * A host that cannot comply is distinguished from a bad safety archive.
	 *
	 * The archive may be perfectly good, so telling the operator it cannot be trusted
	 * would condemn the only undo they have over a problem with the server.
	 *
	 * @return void
	 */
	public function test_invoke_reports_a_host_that_cannot_comply_and_halts(): void {
		$printed = array();
		$wp_cli  = $this->mock_wp_cli_capturing_output( $printed );
		$wp_cli->shouldReceive( 'halt' )->once()->with( 1 );

		$this->run_failing_dry_run( new HostCannotComply( 'there is not enough free disk space' ) );

		$output = implode( "\n", $printed );
		$this->assertStringContainsString( 'This host cannot complete the rollback: there is not enough free disk space', $output );
		$this->assertStringContainsString( 'The safety archive may be perfectly good', $output );
		$this->assertStringNotContainsString( 'cannot be trusted', $output );
	}

	/**
	 * A wrong request is reported without blaming the archive or the host.
	 *
	 * @return void
	 */
	public function test_invoke_reports_an_invalid_request_and_halts(): void {
		$printed = array();
		$wp_cli  = $this->mock_wp_cli_capturing_output( $printed );
		$wp_cli->shouldReceive( 'halt' )->once()->with( 1 );

		$this->run_failing_dry_run( new InvalidRequest( 'the restore root must be an absolute path' ) );

		$output = implode( "\n", $printed );
		$this->assertStringContainsString( 'The request needs correcting: the restore root must be an absolute path', $output );
		$this->assertStringNotContainsString( 'cannot be trusted', $output );
		$this->assertStringNotContainsString( 'Full details are in the Pontifex log', $output );
	}

	/**
	 * A refusal Pontifex has not yet classified is still reported as a refusal.
	 *
	 * Five typed exceptions predate the taxonomy and carry the marker interface
	 * without being one of the three kinds. They are decisions Pontifex made
	 * deliberately, so calling them a failure would misreport a refusal as a fault.
	 *
	 * @return void
	 */
	public function test_invoke_reports_an_unclassified_refusal_as_a_refusal(): void {
		$printed = array();
		$wp_cli  = $this->mock_wp_cli_capturing_output( $printed );
		$wp_cli->shouldReceive( 'halt' )->once()->with( 1 );

		$this->run_failing_dry_run( new CipherException( 'failed to decrypt; the passphrase is wrong' ) );

		$output = implode( "\n", $printed );
		$this->assertStringContainsString( 'Dry run: this rollback would be refused.', $output );
		$this->assertStringContainsString( 'The failure was: failed to decrypt; the passphrase is wrong', $output );
	}

	/**
	 * A failure carrying no Pontifex type is reported as a failure, not a refusal.
	 *
	 * @return void
	 */
	public function test_invoke_reports_an_unrecognised_failure_as_a_failure_not_a_refusal(): void {
		$printed = array();
		$wp_cli  = $this->mock_wp_cli_capturing_output( $printed );
		$wp_cli->shouldReceive( 'halt' )->once()->with( 1 );

		$this->run_failing_dry_run( new RuntimeException( 'ArchiveReader: manifest does not fit between header and footer' ) );

		$output = implode( "\n", $printed );
		$this->assertStringContainsString( 'Dry run: this rollback failed. Your site was not changed.', $output );
		$this->assertStringNotContainsString( 'would be refused', $output );
		$this->assertStringContainsString( 'Full details are in the Pontifex log', $output );
	}

	/**
	 * The verdict carries no absolute server paths.
	 *
	 * This output is exactly what an operator pastes into a support thread, and the
	 * engine's own messages routinely name absolute paths — so the message is redacted
	 * as well as the archive path.
	 *
	 * @return void
	 */
	public function test_invoke_redacts_absolute_paths_from_the_verdict(): void {
		$temp_dir = rtrim( sys_get_temp_dir(), '/' );

		$printed = array();
		$wp_cli  = $this->mock_wp_cli_capturing_output( $printed );
		$wp_cli->shouldReceive( 'halt' )->once()->with( 1 );

		$this->run_failing_dry_run( new ArchiveNotTrustworthy( sprintf( 'refusing symlink target %s/escape', $temp_dir ) ) );

		$output = implode( "\n", $printed );
		$this->assertStringNotContainsString( $temp_dir, $output, 'Neither the message nor the archive path may leak an absolute path.' );
		$this->assertStringContainsString( '{TMP}/escape', $output, 'The redacted message still names the file, just not where it lives.' );
	}

	/**
	 * A real rollback that stops partway says so, and says how far it got.
	 *
	 * This is the failure with nothing behind it: some of the site is now the safety
	 * archive's copy and the rest is whatever the import left, and nothing will
	 * reconcile the two. It is also the only path that exercises a real rollback's
	 * verdict — every other verdict test is a dry run, so without this one a mutation
	 * of the real-run headline would survive.
	 *
	 * @return void
	 */
	public function test_invoke_warns_that_a_real_rollback_stopped_partway(): void {
		$printed = array();
		$wp_cli  = $this->mock_wp_cli_capturing_output( $printed );
		$wp_cli->shouldReceive( 'halt' )->once()->with( 1 );

		$this->run_failing_rollback_after_entries( new HostCannotComply( 'the database refused the write' ), 2 );

		$output = implode( "\n", $printed );
		$this->assertStringContainsString( 'Rollback refused.', $output, 'A real rollback states plainly that it refused, without the dry-run framing.' );
		$this->assertStringNotContainsString( 'Your site was not changed.', $output, 'A real rollback must never claim nothing happened — the site was written to.' );
		$this->assertStringContainsString( 'Your site is now part rolled back: 2 entries were restored', $output );

		$verdict_at = self::index_of_line_containing( $printed, 'the database refused the write' );
		$warning_at = self::index_of_line_containing( $printed, 'part rolled back' );
		$this->assertNotNull( $verdict_at, 'The failure verdict names the engine\'s own message.' );
		$this->assertNotNull( $warning_at, 'The site-state warning is printed.' );
		$this->assertLessThan(
			$warning_at,
			$verdict_at,
			'Why it stopped must be printed before what state that leaves the site in.'
		);
	}

	/**
	 * A real rollback that never began must not claim the site is half restored.
	 *
	 * The restore engine refuses ahead of any write when it can, and a false alarm
	 * about the most alarming thing this tool can report is worse than none: it would
	 * send an operator hunting through a site that was never touched.
	 *
	 * @return void
	 */
	public function test_invoke_does_not_warn_when_no_entry_was_ever_restored(): void {
		$printed = array();
		$wp_cli  = $this->mock_wp_cli_capturing_output( $printed );
		$wp_cli->shouldReceive( 'halt' )->once()->with( 1 );

		$this->run_failing_rollback_after_entries( new ArchiveNotTrustworthy( 'the manifest disagrees with its own records' ), 0 );

		$output = implode( "\n", $printed );
		$this->assertStringContainsString( 'Rollback refused.', $output );
		$this->assertStringNotContainsString( 'part rolled back', $output );
	}

	/**
	 * The operation lock is released before the command halts.
	 *
	 * WP_CLI::halt() calls exit(), and PHP does not run a finally block when exit() is
	 * called — so halting inside the catch would skip the release in the finally and
	 * leave the site's lock to the shutdown backstop. That backstop exists for a
	 * mid-work fatal, not as the normal path off a handled failure.
	 *
	 * @return void
	 */
	public function test_invoke_releases_the_lock_before_halting(): void {
		$lock_context = Mockery::mock( WordPressContext::class );
		$lock_context->shouldReceive( 'acquire_named_lock' )->once()->andReturn( true );
		$lock_context->shouldReceive( 'release_named_lock' )->once();
		$lock_context->shouldReceive( 'option_value' )->andReturn( array() );
		$lock_context->shouldReceive( 'save_option' );
		$lock_context->shouldReceive( 'flush_cache' );
		$lock = new OperationLock( $lock_context, new JobStore( sys_get_temp_dir() . '/pontifex-rollback-lock-test-' . uniqid( '', true ) ) );

		$store = Mockery::mock( RollbackStoreInterface::class );
		$store->shouldReceive( 'most_recent' )->once()->andReturn( $this->temp_archive_path );

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'restore' )->once()->andThrow( new RuntimeException( 'simulated rollback failure' ) );

		$held_at_halt = null;
		$wp_cli       = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'warning' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'halt' )->once()->with( 1 )->andReturnUsing(
			static function () use ( $lock, &$held_at_halt ): void {
				$held_at_halt = $lock->is_held();
			}
		);

		$command = $this->build_command( $store, $restore_runner, new NullLogger(), $lock_context, $lock );

		$command( array(), array( 'yes' => true ) );

		$this->assertFalse( $held_at_halt, 'The lock must already be released by the time the command halts.' );
	}

	/**
	 * Run a --dry-run whose verify walk raises the given failure.
	 *
	 * A dry run is the shortest path to the failure handler: it takes no lock and
	 * writes no counters, so the verdict is the only thing under test.
	 *
	 * @param \Throwable $failure The failure the verify walk raises.
	 * @return void
	 */
	private function run_failing_dry_run( \Throwable $failure ): void {
		$store = Mockery::mock( RollbackStoreInterface::class );
		$store->shouldReceive( 'most_recent' )->once()->andReturn( $this->temp_archive_path );

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'verify' )->once()->andThrow( $failure );
		$restore_runner->shouldNotReceive( 'restore' );

		$command = $this->build_command( $store, $restore_runner, new NullLogger() );

		$command( array(), array( 'dry-run' => true ) );
	}

	/**
	 * Run a real rollback that restores the given number of entries, then fails.
	 *
	 * The engine reports each entry through the progress callback only after it has
	 * written it, so driving that callback $entries times before raising is a faithful
	 * stand-in for a replay that got that far and then stopped.
	 *
	 * @param \Throwable $failure The failure the replay raises.
	 * @param int        $entries How many entries to report as restored first.
	 * @return void
	 */
	private function run_failing_rollback_after_entries( \Throwable $failure, int $entries ): void {
		$store = Mockery::mock( RollbackStoreInterface::class );
		$store->shouldReceive( 'most_recent' )->once()->andReturn( $this->temp_archive_path );

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'restore' )->once()->andReturnUsing(
			static function ( $source, ?callable $on_entry = null ) use ( $failure, $entries ): void {
				unset( $source );
				for ( $done = 1; $done <= $entries; $done++ ) {
					if ( null !== $on_entry ) {
						$on_entry( $done, $entries );
					}
				}
				throw $failure;
			}
		);

		$command = $this->build_command( $store, $restore_runner, new NullLogger() );

		$command( array(), array( 'yes' => true ) );
	}

	/**
	 * Build the WP_CLI alias mock, collecting everything it prints in order.
	 *
	 * Both streams land in one list so a test can assert not just that a line was
	 * printed but where it came in the sequence.
	 *
	 * @param array<int, string> $printed Receives each printed line, by reference.
	 * @return \Mockery\MockInterface The WP_CLI alias mock, for adding further expectations.
	 */
	private function mock_wp_cli_capturing_output( array &$printed ) {
		$collect = static function ( string $message ) use ( &$printed ): void {
			$printed[] = $message;
		};

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes()->andReturnUsing( $collect );
		$wp_cli->shouldReceive( 'warning' )->zeroOrMoreTimes()->andReturnUsing( $collect );
		return $wp_cli;
	}

	/**
	 * Find the position of the first captured line containing the given text.
	 *
	 * @param array<int, string> $printed The captured output, in order.
	 * @param string             $needle  The text to look for.
	 * @return int|null The line's position, or null when no line contains it.
	 */
	private static function index_of_line_containing( array $printed, string $needle ): ?int {
		foreach ( array_values( $printed ) as $index => $line ) {
			if ( str_contains( $line, $needle ) ) {
				return $index;
			}
		}
		return null;
	}
}
