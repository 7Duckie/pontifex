<?php
/**
 * Unit tests for JobTicker's signed-export skip in run().
 *
 * @package Pontifex\Tests\Unit\Schedule
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Schedule;

use Brain\Monkey\Functions;
use Mockery;
use RuntimeException;
use Pontifex\Admin\BackupStore;
use Pontifex\Environment\Environment;
use Pontifex\Job\Job;
use Pontifex\Job\JobStore;
use Pontifex\Manifest\ManifestBuilderInterface;
use Pontifex\Schedule\JobTicker;
use Pontifex\Tests\TestCase;
use Pontifex\WordPress\WordPressContext;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Behavioural tests for the signed-resumable-export skip in JobTicker::run().
 *
 * ResumableExportRunner::tick() throws immediately when a job's payload says
 * `signed => true`, because the operator's private key is deliberately never
 * stored with the job — cron cannot supply one. Before this fix, run() called
 * tick() anyway, the throw was caught by the generic `catch ( Throwable )`,
 * and the catch recorded a failure that had not really happened: it bumped
 * the `failed` counter, wrote a TransferHistory failure row, and (through
 * ResumableExportRunner::tick()'s own catch) marked the job failed. The job
 * itself was fine and still resumable via `wp pontifex export --resume`.
 *
 * The fix checks the `signed` flag near the top of run()'s try block, before
 * record_attempt() runs, and stands down without touching the job. These
 * tests assert the OUTCOMES that matter: no counters bumped, no history
 * row written, the job left exactly as it was, and — critically — that a
 * signed job ticked repeatedly never accumulates unclean-attempt counter
 * and so can never reach JobTicker::MAX_UNCLEAN_ATTEMPTS and be failed. A
 * final test proves the skip is conditional by showing an UNSIGNED job
 * still goes through the ordinary record-attempt-then-tick path.
 */
final class JobTickerTest extends TestCase {

	/**
	 * Fixture directory standing in for wp-content.
	 *
	 * @var string
	 */
	private string $content_dir;

	/**
	 * Create the fixture tree.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->content_dir = sys_get_temp_dir() . '/pontifex-job-ticker-test-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating the test fixture directory.
		mkdir( $this->content_dir, 0o755, true );
	}

	/**
	 * Remove the fixture tree.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		self::rmtree( $this->content_dir );
		parent::tearDown();
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $path Absolute path to remove.
	 * @return void
	 */
	private static function rmtree( string $path ): void {
		if ( ! is_dir( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Test fixture teardown; best-effort.
			@unlink( $path );
			return;
		}
		foreach ( scandir( $path ) as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				self::rmtree( $path . '/' . $entry );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.PHP.NoSilencedErrors.Discouraged -- Test fixture teardown; best-effort.
		@rmdir( $path );
	}

	/**
	 * Build a WordPressContext mock wired for the lock take/release every
	 * run() call performs, with no expectation either way on save_option —
	 * callers add their own shouldReceive()/shouldNotReceive() for that.
	 *
	 * @return WordPressContext&\Mockery\MockInterface
	 */
	private function locking_context() {
		$context = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'acquire_named_lock' )->andReturn( true );
		$context->shouldReceive( 'release_named_lock' );
		return $context;
	}

	/**
	 * A manifest-builder factory that fails the test if the runner is ever built.
	 *
	 * Used as the ticker's test seam in the signed-skip tests: reaching the
	 * runner at all would mean the skip did not happen before record_attempt()
	 * and the tick loop, so this is a stronger guard than merely asserting on
	 * outcomes reachable only after the runner ran.
	 *
	 * @return callable
	 */
	private function runner_must_not_be_built(): callable {
		return function (): ManifestBuilderInterface {
			$this->fail( 'A signed job must never reach the runner: run() must skip before record_attempt().' );
		};
	}

	/**
	 * An active signed export job is left untouched: no failure counted, no
	 * history row, no status change, and the record is not even re-saved.
	 *
	 * @return void
	 */
	public function test_signed_active_job_is_left_untouched_and_no_failure_is_recorded(): void {
		$job_store = new JobStore( $this->content_dir );
		$job       = $job_store->create(
			Job::KIND_EXPORT,
			array(
				'output' => $this->content_dir . '/x.wpmig',
				'signed' => true,
			),
			1700000000
		);

		$context = $this->locking_context();
		// Both bump_counters() and TransferHistory::record() go through
		// save_option(); a skip that truly touches nothing must never call it.
		$context->shouldNotReceive( 'save_option' );
		$context->shouldNotReceive( 'option_value' );

		$logger = Mockery::mock( LoggerInterface::class );
		$logger->shouldReceive( 'info' )->once()->with(
			Mockery::on(
				static function ( string $message ): bool {
					return false !== stripos( $message, 'signed' );
				}
			)
		);
		$logger->shouldReceive( 'error' )->never();

		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_single_event' )->once()->with( Mockery::type( 'int' ), JobTicker::CRON_HOOK );
		Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( JobTicker::CRON_HOOK );

		$ticker = new JobTicker(
			Mockery::mock( Environment::class ),
			$context,
			$job_store,
			new BackupStore( $this->content_dir ),
			$logger,
			$this->runner_must_not_be_built()
		);

		$ticker->run();

		$after = $job_store->get( $job->id() );
		$this->assertNotNull( $after, 'The job record must still exist.' );
		$this->assertTrue( $after->is_active(), 'A signed job must stay active, never marked failed.' );
		$this->assertSame( Job::STATUS_PENDING, $after->status(), 'Status must be untouched — the job was never re-saved.' );
		$this->assertSame( $job->updated_at(), $after->updated_at(), 'The record must not have been re-saved at all.' );
		$this->assertTrue( $after->payload()['signed'] ?? false, 'The payload must survive unchanged.' );
	}

	/**
	 * A signed job left alone across many consecutive ticks never accumulates
	 * unclean-attempt counts and so can never reach the stall ceiling.
	 *
	 * Simulates the real cron scenario the fix targets: an unattended signed
	 * export gets ticked by `pontifex_tick_jobs` again and again because
	 * nobody has run `wp pontifex export --resume` yet. Ticked more times
	 * than JobTicker::MAX_UNCLEAN_ATTEMPTS (8), the job must still be active
	 * and its ticker_attempts counter must still read zero — proving the
	 * skip, not record_attempt(), is what runs on every one of those ticks.
	 *
	 * @return void
	 */
	public function test_signed_job_ticked_repeatedly_never_reaches_the_unclean_attempt_ceiling(): void {
		$job_store = new JobStore( $this->content_dir );
		$job       = $job_store->create(
			Job::KIND_EXPORT,
			array(
				'output' => $this->content_dir . '/x.wpmig',
				'signed' => true,
			),
			1700000000
		);

		$context = $this->locking_context();
		$context->shouldNotReceive( 'save_option' );
		$context->shouldNotReceive( 'option_value' );

		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->justReturn( null );
		Functions\when( 'wp_clear_scheduled_hook' )->justReturn( null );

		$ticker = new JobTicker(
			Mockery::mock( Environment::class ),
			$context,
			$job_store,
			new BackupStore( $this->content_dir ),
			new NullLogger(),
			$this->runner_must_not_be_built()
		);

		// MAX_UNCLEAN_ATTEMPTS is 8; tick well past it to prove the counter
		// never approaches the ceiling, not merely that it survives one tick.
		for ( $i = 0; $i < 12; $i++ ) {
			$ticker->run();
		}

		$after = $job_store->get( $job->id() );
		$this->assertNotNull( $after );
		$this->assertTrue( $after->is_active(), 'Twelve ticks on a signed job must never fail it.' );
		$this->assertSame( Job::STATUS_PENDING, $after->status() );
		$this->assertSame(
			0,
			(int) ( $after->payload()['ticker_attempts'] ?? 0 ),
			'record_attempt() must never run for a signed job, so the counter must stay at zero no matter how many ticks occur.'
		);
	}

	/**
	 * An active UNSIGNED export job still goes through the ordinary path:
	 * the skip is conditional on the `signed` flag, not a blanket early return.
	 *
	 * Reuses the "manifest builder throws" seam (as ScheduleTest's dead-man's-
	 * switch test does) purely to reach a decided outcome cheaply: the throw
	 * is caught by run()'s generic catch, which is the SAME catch the signed
	 * fix exists to bypass. Seeing that catch fire — the job failed and the
	 * attempt recorded — is the proof that, for an unsigned job, run() does
	 * not take the new early-return branch at all.
	 *
	 * @return void
	 */
	public function test_unsigned_active_job_still_ticks_the_skip_is_conditional(): void {
		$job_store = new JobStore( $this->content_dir );
		$job       = $job_store->create(
			Job::KIND_EXPORT,
			array(
				'output'      => $this->content_dir . '/x.wpmig',
				'temp'        => $this->content_dir . '/x.part',
				'scan_root'   => $this->content_dir,
				'path_prefix' => 'wp-content',
				'exclusions'  => array(),
				'signed'      => false,
				'phase'       => 'files',
			),
			1700000000
		);

		$context = $this->locking_context();
		$context->shouldReceive( 'option_value' )->andReturn( array() );
		$context->shouldReceive( 'save_option' );

		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_single_event' )->once()->with( Mockery::type( 'int' ), JobTicker::CRON_HOOK );
		Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( JobTicker::CRON_HOOK );

		$ticker = new JobTicker(
			Mockery::mock( Environment::class ),
			$context,
			$job_store,
			new BackupStore( $this->content_dir ),
			new NullLogger(),
			static function (): ManifestBuilderInterface {
				throw new RuntimeException( 'simulated mid-tick death' );
			}
		);

		$ticker->run();

		$after = $job_store->get( $job->id() );
		$this->assertNotNull( $after );
		$this->assertSame( Job::STATUS_FAILED, $after->status(), 'An unsigned job must still run the ordinary tick-and-catch path.' );
		$this->assertSame( 1, (int) ( $after->payload()['ticker_attempts'] ?? 0 ), 'record_attempt() must have run for an unsigned job.' );
	}
}
