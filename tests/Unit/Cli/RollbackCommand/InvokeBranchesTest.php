<?php
/**
 * Surgical __invoke branch tests for RollbackCommand.
 *
 * @package Pontifex\Tests\Unit\Cli\RollbackCommand
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Cli\RollbackCommand;

require_once __DIR__ . '/../../Manifest/Fakes/WpdbStub.php';

use DateTimeImmutable;
use DateTimeZone;
use Brain\Monkey\Functions;
use Mockery;
use Pontifex\Archive\Codec\CodecRegistry;
use Pontifex\Archive\Codec\RawCodec;
use Pontifex\Archive\Crypto\CipherException;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Format\ExporterInfo;
use Pontifex\Archive\Format\Provenance;
use Pontifex\Archive\Reader\EntryReader;
use Pontifex\Archive\Writer\ArchiveWriter;
use Pontifex\Archive\Writer\EntryPlan;
use Pontifex\Archive\Writer\EntryWriter;
use Pontifex\Archive\Writer\FooterWriter;
use Pontifex\Cli\NullProgressBar;
use Pontifex\Cli\RollbackCommand;
use Pontifex\Cli\TransferHistory;
use Pontifex\Environment\Environment;
use Pontifex\Exception\ArchiveNotTrustworthy;
use Pontifex\Exception\HostCannotComply;
use Pontifex\Exception\InvalidRequest;
use Pontifex\Job\JobStore;
use Pontifex\Lock\OperationLock;
use Pontifex\Restore\DatabaseWriter;
use Pontifex\Restore\FileWriter;
use Pontifex\Restore\RestoreRunner;
use Pontifex\Restore\RestoreRunnerInterface;
use Pontifex\Rollback\RollbackStoreInterface;
use Pontifex\Tests\TestCase;
use Pontifex\Tests\Unit\Manifest\Fakes\FakeDbAdapter;
use Pontifex\WordPress\WordPressContext;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use wpdb;

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
	 * A rollback that skips an absolute-target symlink still succeeds, and warns the operator in the terminal AND the log.
	 *
	 * Unlike every other test in this file, the restore engine here is a REAL
	 * {@see RestoreRunner} over a real {@see FileWriter} (built with
	 * $replaying_safety_archive true, matching build_default_restore_runner()'s
	 * own wiring) and a {@see FakeDbAdapter} for the database half — because
	 * the fact under test is FileWriter's own skipped-symlink ledger reaching
	 * the terminal, which a RestoreRunnerInterface mock has no ledger to
	 * produce. $this->temp_archive_path is overwritten with a real archive
	 * declaring one absolute-target symlink entry, in place of setUp()'s empty
	 * placeholder (never parsed by the mocked-engine tests elsewhere in this
	 * file). Proves the terminal output — not only the log — carries the link
	 * and its target, since an operator running this from a terminal has no
	 * other way to see it.
	 *
	 * @return void
	 */
	public function test_invoke_warns_in_the_terminal_and_the_log_when_a_symlink_is_skipped(): void {
		self::write_archive_with_plans(
			$this->temp_archive_path,
			array( self::symlink_plan( 'wp-content/uploads/media', '/mnt/shared/media' ) )
		);

		$store = Mockery::mock( RollbackStoreInterface::class );
		$store->shouldReceive( 'most_recent' )->once()->andReturn( $this->temp_archive_path );

		$restore_root   = sys_get_temp_dir() . '/pontifex-rollback-invoke-replay-' . bin2hex( random_bytes( 8 ) );
		$restore_runner = new RestoreRunner(
			new EntryReader( CodecRegistry::with_defaults() ),
			new FileWriter( $restore_root, false, null, null, null, null, true ),
			new DatabaseWriter( new FakeDbAdapter() )
		);

		$printed = array();
		$this->mock_wp_cli_capturing_output( $printed );

		$logged_warning = false;
		$logger         = Mockery::mock( LoggerInterface::class );
		$logger->shouldReceive( 'info' )->zeroOrMoreTimes();
		$logger->shouldReceive( 'warning' )->once()->andReturnUsing(
			static function ( string $message, array $context = array() ) use ( &$logged_warning ): void {
				unset( $message, $context );
				$logged_warning = true;
			}
		);

		try {
			$command = $this->build_command( $store, $restore_runner, $logger );
			$command( array(), array( 'yes' => true ) );

			$output = implode( "\n", $printed );
			$this->assertStringContainsString( 'wp-content/uploads/media', $output, 'The skipped link must be named on the terminal, not only in the log.' );
			$this->assertStringContainsString( '/mnt/shared/media', $output, 'The link\'s recorded target must be named on the terminal.' );
			$this->assertTrue( $logged_warning, 'The skip must also be logged.' );
		} finally {
			self::rmtree( $restore_root );
		}
	}

	/**
	 * A rollback that repaired frozen placeholder markers says so, with the count, in the terminal.
	 *
	 * A rollback replays a safety archive, and one written by an affected version
	 * carries a frozen marker in place of every percent sign in its row values. The
	 * engine here is a REAL {@see RestoreRunner} over a repairing
	 * {@see DatabaseWriter}, because the fact under test is the writer's own tally
	 * reaching the terminal through the runner, which a {@see RestoreRunnerInterface}
	 * mock has no writer to produce. The exact wording is asserted, and so is the
	 * database side: the statement that reached the adapter has the percent signs back.
	 *
	 * @return void
	 */
	public function test_invoke_reports_how_many_markers_a_rollback_repaired(): void {
		$m = self::frozen_marker();
		self::write_archive_with_plans(
			$this->temp_archive_path,
			array( self::db_chunk_plan( 'wp_options', 1, "INSERT INTO `wp_options` VALUES ('/{$m}postname{$m}/');\n" ) )
		);

		$store = Mockery::mock( RollbackStoreInterface::class );
		$store->shouldReceive( 'most_recent' )->once()->andReturn( $this->temp_archive_path );

		$db           = new FakeDbAdapter();
		$restore_root = sys_get_temp_dir() . '/pontifex-rollback-invoke-repair-' . bin2hex( random_bytes( 8 ) );
		$printed      = array();
		$this->mock_wp_cli_capturing_output( $printed );

		try {
			$command = $this->build_command( $store, self::build_repairing_restore_runner( $db, $restore_root ), new NullLogger() );
			$command( array(), array( 'yes' => true ) );
		} finally {
			self::rmtree( $restore_root );
		}

		$this->assertContains( "INSERT INTO `pontifexstg_wp_options` VALUES ('/%postname%/')", $db->executed_statements(), 'The repaired statement is what reached the database.' );
		$this->assertContains(
			'Repaired 2 percent signs that an older version of Pontifex had written as a long marker when this backup was taken.',
			$printed,
			'The operator must be told, with the count, that the rollback repaired the safety archive.'
		);
	}

	/**
	 * A clean safety archive rolls back without a word about repairs — the message must not appear on every ordinary rollback.
	 *
	 * The engine is the same real runner with the repair switched on, so the only
	 * thing that can keep the line off the terminal is the zero count.
	 *
	 * @return void
	 */
	public function test_invoke_prints_nothing_about_repairs_for_a_clean_safety_archive(): void {
		self::write_archive_with_plans(
			$this->temp_archive_path,
			array( self::db_chunk_plan( 'wp_options', 1, "INSERT INTO `wp_options` VALUES ('/%postname%/');\n" ) )
		);

		$store = Mockery::mock( RollbackStoreInterface::class );
		$store->shouldReceive( 'most_recent' )->once()->andReturn( $this->temp_archive_path );

		$db           = new FakeDbAdapter();
		$restore_root = sys_get_temp_dir() . '/pontifex-rollback-invoke-clean-' . bin2hex( random_bytes( 8 ) );
		$printed      = array();
		$this->mock_wp_cli_capturing_output( $printed );

		try {
			$command = $this->build_command( $store, self::build_repairing_restore_runner( $db, $restore_root ), new NullLogger() );
			$command( array(), array( 'yes' => true ) );
		} finally {
			self::rmtree( $restore_root );
		}

		$this->assertContains( "INSERT INTO `pontifexstg_wp_options` VALUES ('/%postname%/')", $db->executed_statements(), 'The rollback really ran.' );
		$this->assertNull( self::index_of_line_containing( $printed, 'Repaired' ), 'A rollback that repaired nothing must print nothing about repairs.' );
		$this->assertNull( self::index_of_line_containing( $printed, 'marker' ), 'No mention of markers either.' );
	}

	/**
	 * The command's own default wiring repairs a safety archive written by an affected version.
	 *
	 * Every test above injects a runner, which bypasses build_default_restore_runner()
	 * and so proves nothing about the repair flag that method derives from the
	 * archive's own provenance. This one injects none: a safety archive recording
	 * Pontifex 1.0.3 as its writer, carrying a marker-bearing row, is rolled back
	 * through the real wiring, and the statement the database received has the
	 * percent signs back, with the operator told so.
	 *
	 * @return void
	 */
	public function test_default_wiring_repairs_an_archive_written_by_an_affected_version(): void {
		$queries = array();
		$printed = array();

		$this->run_through_default_wiring( '1.0.3', $queries, $printed );

		$this->assertContains( "INSERT INTO `pontifexstg_wp_options` VALUES ('/%postname%/')", $queries, 'An affected writer\'s archive must be repaired by the default wiring.' );
		$this->assertContains(
			'Repaired 2 percent signs that an older version of Pontifex had written as a long marker when this backup was taken.',
			$printed
		);
	}

	/**
	 * The command's own default wiring leaves an archive written after the fix exactly as it came.
	 *
	 * The same archive, recording a version after the boundary: the marker-shaped
	 * text is real data there and must reach the database untouched, with nothing
	 * said about repairs.
	 *
	 * @return void
	 */
	public function test_default_wiring_leaves_an_archive_written_after_the_fix_untouched(): void {
		$queries = array();
		$printed = array();
		$m       = self::frozen_marker();

		$this->run_through_default_wiring( '1.1.1', $queries, $printed );

		$this->assertContains( "INSERT INTO `pontifexstg_wp_options` VALUES ('/{$m}postname{$m}/')", $queries, 'A current archive must reach the database exactly as written.' );
		$this->assertNull( self::index_of_line_containing( $printed, 'Repaired' ) );
	}

	/**
	 * A runner that is not the real engine reports nothing — it has no writer to ask.
	 *
	 * @return void
	 */
	public function test_invoke_prints_nothing_about_repairs_when_the_engine_is_a_fake(): void {
		$store = Mockery::mock( RollbackStoreInterface::class );
		$store->shouldReceive( 'most_recent' )->once()->andReturn( $this->temp_archive_path );

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'restore' )->once();

		$printed = array();
		$this->mock_wp_cli_capturing_output( $printed );

		$command = $this->build_command( $store, $restore_runner, new NullLogger() );
		$command( array(), array( 'yes' => true ) );

		$this->assertNull( self::index_of_line_containing( $printed, 'Repaired' ) );
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

		$this->run_failing_dry_run( new RuntimeException( 'The manifest does not fit between header and footer.' ) );

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

	/**
	 * Build a symlink EntryPlan.
	 *
	 * Used only by {@see self::test_invoke_warns_in_the_terminal_and_the_log_when_a_symlink_is_skipped()},
	 * the one test in this file that replays a real archive through a real
	 * engine rather than a {@see RestoreRunnerInterface} mock.
	 *
	 * @param string $path   Relative path of the link inside the archive.
	 * @param string $target The target string recorded verbatim.
	 * @return EntryPlan
	 */
	private static function symlink_plan( string $path, string $target ): EntryPlan {
		$header = EntryHeader::for_symlink( $path, $target, 0 );
		return new EntryPlan( $header, RawCodec::ID, str_repeat( "\0", EntryWriter::NONCE_SIZE ), self::memory_stream() );
	}

	/**
	 * A frozen placeholder marker, as an affected export wrote it into an archive.
	 *
	 * @return string A brace, 64 lowercase hexadecimal characters, a brace.
	 */
	private static function frozen_marker(): string {
		return '{' . str_repeat( '0123456789abcdef', 4 ) . '}';
	}

	/**
	 * Build a db_chunk EntryPlan.
	 *
	 * @param string $table_name      Source table name.
	 * @param int    $statement_count Number of statements in the chunk.
	 * @param string $sql             SQL bytes, each statement terminated with a semicolon and a newline.
	 * @return EntryPlan
	 */
	private static function db_chunk_plan( string $table_name, int $statement_count, string $sql ): EntryPlan {
		$header = EntryHeader::for_db_chunk( 0, $table_name, $statement_count, strlen( $sql ), 0 );
		return new EntryPlan( $header, RawCodec::ID, str_repeat( "\0", EntryWriter::NONCE_SIZE ), self::memory_stream( $sql ) );
	}

	/**
	 * Build a REAL RestoreRunner in safety-archive replay mode whose database writer repairs frozen markers.
	 *
	 * @param FakeDbAdapter $db           The adapter the writer replays into.
	 * @param string        $restore_root The directory the file writer is rooted at; created on demand.
	 * @return RestoreRunner
	 */
	private static function build_repairing_restore_runner( FakeDbAdapter $db, string $restore_root ): RestoreRunner {
		return new RestoreRunner(
			new EntryReader( CodecRegistry::with_defaults() ),
			new FileWriter( $restore_root, false, null, null, null, null, true ),
			new DatabaseWriter( $db, '', '', true )
		);
	}

	/**
	 * Roll back a marker-bearing safety archive through the command's OWN default wiring, recording what the database received.
	 *
	 * No runner is injected, so build_default_restore_runner() builds the real one,
	 * over a wpdb whose query() records what it is given. The WordPress root is a
	 * throw-away directory, removed afterwards.
	 *
	 * @param string             $exporter_version The Pontifex version the archive records as its writer.
	 * @param array<int, string> $queries          Out-parameter. Filled with every statement the wpdb was asked to run, in order.
	 * @param array<int, string> $printed          Out-parameter. Filled with every line the command printed, in order.
	 * @return void
	 */
	private function run_through_default_wiring( string $exporter_version, array &$queries, array &$printed ): void {
		$m = self::frozen_marker();
		self::write_archive_with_plans(
			$this->temp_archive_path,
			array( self::db_chunk_plan( 'wp_options', 1, "INSERT INTO `wp_options` VALUES ('/{$m}postname{$m}/');\n" ) ),
			$exporter_version
		);

		$store = Mockery::mock( RollbackStoreInterface::class );
		$store->shouldReceive( 'most_recent' )->once()->andReturn( $this->temp_archive_path );

		$root        = sys_get_temp_dir() . '/pontifex-rollback-wiring-' . bin2hex( random_bytes( 8 ) );
		$environment = Mockery::mock( Environment::class );
		$environment->shouldReceive( 'is_constant_defined' )->andReturn( true );
		$environment->shouldReceive( 'constant_value' )->andReturnUsing(
			static function ( string $name ) use ( $root ) {
				return 'ABSPATH' === $name ? $root . '/' : $root . '/wp-content';
			}
		);
		$environment->shouldReceive( 'ini_get' )->andReturn( '256M' );

		$wpdb = Mockery::mock( wpdb::class )->makePartial();
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			static function ( string $query ) use ( &$queries ) {
				$queries[] = $query;
				return 0;
			}
		);

		$context = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'option_value' )->andReturnUsing(
			static function ( string $name, $fallback = false ) {
				unset( $name );
				return $fallback;
			}
		);
		$context->shouldReceive( 'save_option' );
		$context->shouldReceive( 'flush_cache' );
		$context->shouldReceive( 'acquire_named_lock' )->andReturn( true );
		$context->shouldReceive( 'release_named_lock' );
		$context->shouldReceive( 'wpdb_prefix' )->andReturn( 'wp_' );
		$context->shouldReceive( 'wpdb_instance' )->andReturn( $wpdb );
		$context->shouldReceive( 'convert_hr_to_bytes' )->andReturn( 0 );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );

		$this->mock_wp_cli_capturing_output( $printed );

		try {
			$command = new RollbackCommand( $environment, $context, $store, null, new NullLogger(), new NullProgressBar() );
			$command( array(), array( 'yes' => true ) );
		} finally {
			self::rmtree( $root );
		}
	}

	/**
	 * Open a php://memory stream, optionally pre-filled and rewound.
	 *
	 * @param string $contents Optional initial contents.
	 * @return resource
	 * @throws RuntimeException If php://memory cannot be opened.
	 */
	private static function memory_stream( string $contents = '' ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://memory is an in-process buffer, not a file.
		$stream = fopen( 'php://memory', 'r+b' );
		if ( false === $stream ) {
			throw new RuntimeException( 'Could not open php://memory.' );
		}
		if ( '' !== $contents ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Operating on a test stream resource, not a filesystem path.
			fwrite( $stream, $contents );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource, not a filesystem path.
			rewind( $stream );
		}
		return $stream;
	}

	/**
	 * Write the given plans as a real, content-only, unencrypted archive to $path, overwriting whatever is there.
	 *
	 * @param string      $path             Absolute destination path (setUp()'s own $this->temp_archive_path).
	 * @param EntryPlan[] $plans            The plans to include.
	 * @param string      $exporter_version Optional. The Pontifex version to record as the writer; default '1.1.0', an affected version.
	 * @return void
	 * @throws RuntimeException If the destination cannot be opened for writing.
	 */
	private static function write_archive_with_plans( string $path, array $plans, string $exporter_version = '1.1.0' ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Overwriting the temp fixture archive setUp() created.
		$dest = fopen( $path, 'w+b' );
		if ( false === $dest ) {
			throw new RuntimeException( 'Could not open the fixture safety archive for writing.' );
		}
		$provenance = new Provenance(
			'6.6.1',
			'8.2.10',
			'https://example.test',
			'utf8mb4',
			'utf8mb4_unicode_520_ci',
			new ExporterInfo( 'pontifex', $exporter_version ),
			new DateTimeImmutable( '2026-09-03T10:00:00+00:00', new DateTimeZone( 'UTC' ) )
		);
		( new ArchiveWriter( new EntryWriter( CodecRegistry::with_defaults() ), new FooterWriter() ) )->write_archive( $provenance, $plans, $dest );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the fixture archive just written.
		fclose( $dest );
	}

	/**
	 * Recursively delete a directory tree, if it exists.
	 *
	 * @param string $path Absolute path to remove.
	 * @return void
	 */
	private static function rmtree( string $path ): void {
		if ( is_link( $path ) || ! is_dir( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Test fixture teardown.
			@unlink( $path );
			return;
		}
		foreach ( scandir( $path ) as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				self::rmtree( $path . '/' . $entry );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.PHP.NoSilencedErrors.Discouraged -- Test fixture teardown.
		@rmdir( $path );
	}

	// -----------------------------------------------------------------
	// The durable record: a rollback is written to the transfer history as
	// its own operation kind, so it can be read back as the last thing that
	// rewrote the site.
	// -----------------------------------------------------------------

	/**
	 * A successful rollback is recorded after the cache flush, with no relink and no recovery keys.
	 *
	 * The replay leaves a stale option cache that would swallow a write into the
	 * just-replaced wp_options, so the flush must come first. The size recorded is the
	 * safety archive's, the same figure the rollback counters hold.
	 *
	 * @return void
	 */
	public function test_invoke_records_a_successful_rollback_in_the_history_after_the_flush(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Giving the placeholder safety archive a known, non-zero size; the injected engine stands in for reading it.
		file_put_contents( $this->temp_archive_path, 'x' );

		$store = Mockery::mock( RollbackStoreInterface::class );
		$store->shouldReceive( 'most_recent' )->once()->andReturn( $this->temp_archive_path );

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'restore' )->once()->andReturnUsing(
			static function ( $source, ?callable $on_entry = null ): void {
				unset( $source );
				if ( null !== $on_entry ) {
					$on_entry( 1, 6 );
				}
			}
		);

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();

		$events  = array();
		$history = array();

		$command = $this->build_command( $store, $restore_runner, new NullLogger(), $this->history_capturing_context( $events, $history ) );
		$command( array(), array( 'yes' => true ) );

		$this->assertSame( array( 'flush', 'history' ), $events, 'The history must be written after the cache flush, never before it.' );
		$this->assertCount( 1, $history );
		$this->assertSame( 'rollback', $history[0]['operation'] );
		$this->assertSame( 'succeeded', $history[0]['outcome'] );
		$this->assertSame( 1, $history[0]['bytes'] );
		$this->assertSame(
			array(
				'entries'          => 6,
				'repaired_markers' => 0,
				'skipped_links'    => 0,
			),
			$history[0]['details'],
			'A rollback has no relink and no recovery of its own, so neither key is written.'
		);
	}

	/**
	 * A rollback records the links it skipped, read from the real engine's own ledger.
	 *
	 * @return void
	 */
	public function test_invoke_records_how_many_symlinks_a_rollback_skipped(): void {
		self::write_archive_with_plans(
			$this->temp_archive_path,
			array( self::symlink_plan( 'wp-content/uploads/media', '/mnt/shared/media' ) )
		);

		$store = Mockery::mock( RollbackStoreInterface::class );
		$store->shouldReceive( 'most_recent' )->once()->andReturn( $this->temp_archive_path );

		$restore_root   = sys_get_temp_dir() . '/pontifex-rollback-invoke-history-' . bin2hex( random_bytes( 8 ) );
		$restore_runner = new RestoreRunner(
			new EntryReader( CodecRegistry::with_defaults() ),
			new FileWriter( $restore_root, false, null, null, null, null, true ),
			new DatabaseWriter( new FakeDbAdapter() )
		);

		$printed = array();
		$this->mock_wp_cli_capturing_output( $printed );

		$events  = array();
		$history = array();

		try {
			$command = $this->build_command( $store, $restore_runner, new NullLogger(), $this->history_capturing_context( $events, $history ) );
			$command( array(), array( 'yes' => true ) );
		} finally {
			self::rmtree( $restore_root );
		}

		$this->assertCount( 1, $history );
		$this->assertSame( 1, $history[0]['details']['skipped_links'] );
	}

	/**
	 * A failed rollback is recorded as a failed rollback, with no recovery key.
	 *
	 * A rollback has no recovery of its own: a failure part-way may leave the site
	 * partly rolled back, and the record does not pretend otherwise by naming one.
	 *
	 * @return void
	 */
	public function test_invoke_records_a_failed_rollback_in_the_history(): void {
		$store = Mockery::mock( RollbackStoreInterface::class );
		$store->shouldReceive( 'most_recent' )->once()->andReturn( $this->temp_archive_path );

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'restore' )->once()->andThrow( new HostCannotComply( 'the database refused the write' ) );

		$printed = array();
		$wp_cli  = $this->mock_wp_cli_capturing_output( $printed );
		$wp_cli->shouldReceive( 'halt' )->once()->with( 1 );

		$events  = array();
		$history = array();

		$command = $this->build_command( $store, $restore_runner, new NullLogger(), $this->history_capturing_context( $events, $history ) );
		$command( array(), array( 'yes' => true ) );

		$this->assertSame( array( 'flush', 'history' ), $events, 'The replay may have replaced wp_options, so the cache is flushed before the history is written.' );
		$this->assertCount( 1, $history );
		$this->assertSame( 'rollback', $history[0]['operation'] );
		$this->assertSame( 'failed', $history[0]['outcome'] );
		$this->assertSame( 0, $history[0]['bytes'] );
		$this->assertSame( array( 'entries' => 0 ), $history[0]['details'] );
		$this->assertArrayNotHasKey( 'recovery', $history[0]['details'], 'A rollback has no recovery of its own, so no label is invented for one.' );
	}

	/**
	 * A dry run changes nothing, so it records nothing — not in the counters, not in the history.
	 *
	 * @return void
	 */
	public function test_invoke_dry_run_records_nothing_in_the_history(): void {
		$store = Mockery::mock( RollbackStoreInterface::class );
		$store->shouldReceive( 'most_recent' )->once()->andReturn( $this->temp_archive_path );

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'verify' )->once();
		$restore_runner->shouldNotReceive( 'restore' );

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();

		$events  = array();
		$history = array();

		$command = $this->build_command( $store, $restore_runner, new NullLogger(), $this->history_capturing_context( $events, $history ) );
		$command( array(), array( 'dry-run' => true ) );

		$this->assertSame( array(), $events );
		$this->assertSame( array(), $history );
	}

	/**
	 * A WordPressContext mock that records, in order, the cache flushes and the transfer-history writes.
	 *
	 * Only a write to the history option is captured; the counters' writes are
	 * accepted and ignored.
	 *
	 * @param array<int, string>               $events  Out-parameter. Receives 'flush' for each cache flush and 'history' for each history write, in the order they happen.
	 * @param array<int, array<string, mixed>> $history Out-parameter. Receives the history as it was last saved.
	 * @return WordPressContext&\Mockery\MockInterface
	 */
	private function history_capturing_context( array &$events, array &$history ) {
		$context = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'option_value' )->andReturnUsing(
			static function ( string $name, $fallback = false ) {
				unset( $name );
				return $fallback;
			}
		);
		$context->shouldReceive( 'flush_cache' )->andReturnUsing(
			static function () use ( &$events ): void {
				$events[] = 'flush';
			}
		);
		$context->shouldReceive( 'save_option' )->andReturnUsing(
			static function ( string $name, $value ) use ( &$events, &$history ): void {
				if ( TransferHistory::OPTION === $name ) {
					$events[] = 'history';
					$history  = $value;
				}
			}
		);
		return $context;
	}

	/**
	 * A rollback that repaired exactly one marker says "percent sign", not "percent signs".
	 *
	 * @return void
	 */
	public function test_invoke_uses_the_singular_when_a_rollback_repaired_one_marker(): void {
		$m = self::frozen_marker();
		self::write_archive_with_plans(
			$this->temp_archive_path,
			array( self::db_chunk_plan( 'wp_options', 1, "INSERT INTO `wp_options` VALUES ('/{$m}postname');\n" ) )
		);

		$store = Mockery::mock( RollbackStoreInterface::class );
		$store->shouldReceive( 'most_recent' )->once()->andReturn( $this->temp_archive_path );

		$db           = new FakeDbAdapter();
		$restore_root = sys_get_temp_dir() . '/pontifex-rollback-invoke-singular-' . bin2hex( random_bytes( 8 ) );
		$printed      = array();
		$this->mock_wp_cli_capturing_output( $printed );

		try {
			$command = $this->build_command( $store, self::build_repairing_restore_runner( $db, $restore_root ), new NullLogger() );
			$command( array(), array( 'yes' => true ) );
		} finally {
			self::rmtree( $restore_root );
		}

		$this->assertContains(
			'Repaired 1 percent sign that an older version of Pontifex had written as a long marker when this backup was taken.',
			$printed
		);
	}

	// -----------------------------------------------------------------
	// The attempt counter. A rollback counts its attempt in the same write
	// as its outcome, after the replay, so a replay that rebuilds wp_options
	// cannot leave it behind: these tests pin that, so it stays true.
	// -----------------------------------------------------------------

	/**
	 * A successful rollback leaves attempted covering succeeded plus failed, even though the replay rebuilt the counters.
	 *
	 * @return void
	 */
	public function test_a_successful_rollback_keeps_the_attempt_covered(): void {
		$store = Mockery::mock( RollbackStoreInterface::class );
		$store->shouldReceive( 'most_recent' )->once()->andReturn( $this->temp_archive_path );

		$options        = array( 'pontifex_rollback_stats' => self::rollback_stats( 2, 1, 1 ) );
		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'restore' )->once()->andReturnUsing(
			static function () use ( &$options ): void {
				$options['pontifex_rollback_stats'] = self::rollback_stats( 2, 1, 1 );
			}
		);

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();

		$command = $this->build_command( $store, $restore_runner, new NullLogger(), $this->stateful_counters_context( $options ) );
		$command( array(), array( 'yes' => true ) );

		$stats = $options['pontifex_rollback_stats'];
		$this->assertGreaterThanOrEqual( $stats['succeeded'] + $stats['failed'], $stats['attempted'] );
		$this->assertSame( 3, $stats['attempted'] );
		$this->assertSame( 2, $stats['succeeded'] );
	}

	/**
	 * A failed rollback leaves attempted covering succeeded plus failed, even though the replay rebuilt the counters.
	 *
	 * @return void
	 */
	public function test_a_failed_rollback_keeps_the_attempt_covered(): void {
		$store = Mockery::mock( RollbackStoreInterface::class );
		$store->shouldReceive( 'most_recent' )->once()->andReturn( $this->temp_archive_path );

		$options        = array( 'pontifex_rollback_stats' => self::rollback_stats( 2, 1, 1 ) );
		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'restore' )->once()->andReturnUsing(
			static function () use ( &$options ): void {
				$options['pontifex_rollback_stats'] = self::rollback_stats( 2, 1, 1 );
				throw new HostCannotComply( 'the database refused the write' );
			}
		);

		$printed = array();
		$wp_cli  = $this->mock_wp_cli_capturing_output( $printed );
		$wp_cli->shouldReceive( 'halt' )->once()->with( 1 );

		$command = $this->build_command( $store, $restore_runner, new NullLogger(), $this->stateful_counters_context( $options ) );
		$command( array(), array( 'yes' => true ) );

		$stats = $options['pontifex_rollback_stats'];
		$this->assertGreaterThanOrEqual( $stats['succeeded'] + $stats['failed'], $stats['attempted'] );
		$this->assertSame( 3, $stats['attempted'] );
		$this->assertSame( 2, $stats['failed'] );
	}

	/**
	 * A set of rollback counters.
	 *
	 * @param int $attempted How many runs were counted as attempted.
	 * @param int $succeeded How many succeeded.
	 * @param int $failed    How many failed.
	 * @return array<string, int>
	 */
	private static function rollback_stats( int $attempted, int $succeeded, int $failed ): array {
		return array(
			'attempted'         => $attempted,
			'succeeded'         => $succeeded,
			'failed'            => $failed,
			'bytes_rolled_back' => 0,
		);
	}

	/**
	 * A WordPressContext mock that really holds options, so a "replay" in an engine double can replace them.
	 *
	 * @param array<string, mixed> $options Out-parameter. The stored options, by name; read and written through the context.
	 * @return WordPressContext&\Mockery\MockInterface
	 */
	private function stateful_counters_context( array &$options ) {
		$context = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'option_value' )->andReturnUsing(
			static function ( string $name, $fallback = false ) use ( &$options ) {
				return $options[ $name ] ?? $fallback;
			}
		);
		$context->shouldReceive( 'save_option' )->andReturnUsing(
			static function ( string $name, $value ) use ( &$options ): void {
				$options[ $name ] = $value;
			}
		);
		$context->shouldReceive( 'flush_cache' );
		return $context;
	}
}
