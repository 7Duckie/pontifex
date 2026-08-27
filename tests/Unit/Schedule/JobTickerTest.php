<?php
/**
 * Unit tests for JobTicker's signed-export skip in run().
 *
 * @package Pontifex\Tests\Unit\Schedule
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Schedule;

use Brain\Monkey\Functions;
use DateTimeImmutable;
use Mockery;
use RuntimeException;
use Pontifex\Admin\BackupStore;
use Pontifex\Environment\Environment;
use Pontifex\Job\Job;
use Pontifex\Job\JobStore;
use Pontifex\Manifest\ManifestBuilderInterface;
use Pontifex\Manifest\ManifestStream;
use Pontifex\Schedule\JobTicker;
use Pontifex\Schedule\Schedule;
use Pontifex\Schedule\ScheduledBackups;
use Pontifex\Schedule\ScheduleStore;
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

	/**
	 * Build an Environment mock answering the provenance reads a completed
	 * tick performs, matching {@see \Pontifex\Tests\Unit\Export\ResumableExportRunnerTest}'s helper.
	 *
	 * @return Environment&\Mockery\MockInterface
	 */
	private function environment_mock() {
		$mock = Mockery::mock( Environment::class );
		$mock->shouldReceive( 'is_constant_defined' )->with( 'PONTIFEX_VERSION' )->andReturn( false );
		$mock->shouldReceive( 'php_version' )->andReturn( '8.3.0' );
		return $mock;
	}

	/**
	 * Build a WordPressContext mock wired for a full tick-to-completion run,
	 * whose ScheduleStore::load() answers with the given retention and whose
	 * ScheduledBackups ledger is backed by a real array passed by reference,
	 * so record() and recorded() calls made during the run observe each
	 * other's writes the way a real wp_options row would — rather than each
	 * independently seeing the empty default a plain stateless mock would
	 * hand back on every call.
	 *
	 * Built on {@see self::locking_context()} so the lock take/release
	 * expectations stay in one place; adds the provenance reads finalise()'s
	 * tick needs, and a keyed option_value()/save_option() pair so
	 * ScheduleStore's option, ScheduledBackups's ledger, and the export
	 * counters' option are all distinguished from one another.
	 *
	 * @param int      $retention        The retention count ScheduleStore::load() must answer.
	 * @param string[] $recorded_backups The ScheduledBackups ledger's starting content; updated in place by any record()/recorded() call the run makes, so a caller can inspect it afterwards.
	 * @return WordPressContext&\Mockery\MockInterface
	 */
	private function completion_context( int $retention, array &$recorded_backups = array() ) {
		$context = $this->locking_context();
		$context->shouldReceive( 'wp_version' )->andReturn( '6.6.0' );
		$context->shouldReceive( 'site_url' )->andReturn( 'https://example.test' );
		$context->shouldReceive( 'wpdb_charset' )->andReturn( 'utf8mb4' );
		$context->shouldReceive( 'wpdb_collation' )->andReturn( 'utf8mb4_unicode_520_ci' );
		$context->shouldReceive( 'option_value' )->andReturnUsing(
			static function ( string $key, $fallback ) use ( $retention, &$recorded_backups ) {
				if ( ScheduleStore::OPTION === $key ) {
					return array(
						'enabled'   => true,
						'frequency' => Schedule::FREQUENCY_DAILY,
						'hour'      => 3,
						'retention' => $retention,
					);
				}
				if ( ScheduledBackups::OPTION === $key ) {
					return $recorded_backups;
				}
				return $fallback;
			}
		);
		$context->shouldReceive( 'save_option' )->andReturnUsing(
			static function ( string $key, $value ) use ( &$recorded_backups ) {
				if ( ScheduledBackups::OPTION === $key ) {
					$recorded_backups = $value;
				}
			}
		);
		return $context;
	}

	/**
	 * Plant a real, minimal backup file under a valid, timestamped name.
	 *
	 * Creates the backups directory on first use. Every planted file is
	 * written back-to-back within the same test, so their real modification
	 * times land within the same wall-clock second in practice — meaning
	 * BackupStore::backups()'s ordering (modification time, then name;
	 * see {@see \Pontifex\Admin\BackupStore::compare_by_age()}) falls through
	 * to its name tie-break, which happens to agree with the stamps' own
	 * chronological order here. Callers relying on a SPECIFIC modification
	 * time — as opposed to "planted in this order" — should stamp the file
	 * explicitly instead.
	 *
	 * @param string $stamp A 'Ymd\THis\Z'-shaped UTC stamp, e.g. '20260101T000000Z'.
	 * @return void
	 */
	private function plant_backup( string $stamp ): void {
		$dir = $this->content_dir . '/pontifex/backups';
		if ( ! is_dir( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating the test fixture directory.
			mkdir( $dir, 0700, true );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Planting a fixture backup file in the test's own temp directory.
		file_put_contents( $dir . '/pontifex-backup-' . $stamp . '.wpmig', 'fixture' );
	}

	/**
	 * Create a pending export job payload shaped exactly as
	 * ResumableExportRunner::start() would leave it, ready to complete on one
	 * tick against an empty manifest.
	 *
	 * @param JobStore    $job_store The store to create the job in.
	 * @param bool        $schedule  Whether the payload carries schedule => true.
	 * @param string|null $output    Optional. The job's own output path; defaults to a
	 *                               path outside the backups directory, which is fine
	 *                               for tests that only assert on PRE-PLANTED fixtures.
	 *                               Pass a real BackupStore::next_backup_path() when the
	 *                               job's own completed output needs to be a real,
	 *                               listable backup itself.
	 * @return Job The pending job.
	 */
	private function create_completable_job( JobStore $job_store, bool $schedule, ?string $output = null ): Job {
		$payload = array(
			'output'                => $output ?? $this->content_dir . '/done.wpmig',
			'temp'                  => $this->content_dir . '/done.part',
			'scan_root'             => $this->content_dir,
			'path_prefix'           => 'wp-content',
			'exclusions'            => array(),
			'signed'                => false,
			'reason'                => null,
			'scope'                 => null,
			'phase'                 => 'files',
			'bytes_written'         => 0,
			'files_changed'         => 0,
			'media_type_unresolved' => 0,
		);
		if ( $schedule ) {
			$payload['schedule'] = true;
		}
		return $job_store->create( Job::KIND_EXPORT, $payload, 1700000000 );
	}

	/**
	 * A manifest-builder factory serving an empty stream, so the runner's
	 * tick finishes the archive on its first pass.
	 *
	 * @return callable
	 */
	private function empty_manifest_builder_factory(): callable {
		return static function (): ManifestBuilderInterface {
			$builder = Mockery::mock( ManifestBuilderInterface::class );
			$builder->shouldReceive( 'build' )->andReturn( ManifestStream::from_plans( array() ) );
			return $builder;
		};
	}

	/**
	 * Stub the WP-Cron and transient functions a completed tick touches, none
	 * of which these retention tests assert on.
	 *
	 * @return void
	 */
	private function stub_completion_wp_functions(): void {
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->justReturn( null );
		Functions\when( 'wp_clear_scheduled_hook' )->justReturn( null );
		Functions\when( 'get_transient' )->justReturn( false );
	}

	/**
	 * A completed SCHEDULE-originated job prunes the backup store down to the
	 * schedule's retention count, deleting from the oldest and keeping the
	 * newest ones by name — not merely by count.
	 *
	 * If pruning ever ran on the wrong set, or in the wrong direction, a
	 * nightly schedule would either delete an operator's newest, most useful
	 * backups and keep the stale ones, or delete nothing at all and quietly
	 * fill the disk. Five real fixture files are planted with valid,
	 * strictly-increasing timestamped names — first proven to be accepted by
	 * BackupStore::backups() — and, because retention now only ever considers
	 * RECORDED filenames (see {@see \Pontifex\Schedule\ScheduledBackups}),
	 * every one of them is pre-seeded into the ledger, standing in for five
	 * earlier scheduled runs. A schedule-flagged job is then ticked to
	 * completion and the exact surviving set is asserted by name.
	 *
	 * @return void
	 */
	public function test_finalise_prunes_schedule_backups_oldest_first(): void {
		$stamps = array( '20260101T000000Z', '20260102T000000Z', '20260103T000000Z', '20260104T000000Z', '20260105T000000Z' );
		foreach ( $stamps as $stamp ) {
			$this->plant_backup( $stamp );
		}

		$backup_store = new BackupStore( $this->content_dir );
		$this->assertCount( 5, $backup_store->backups(), 'Every planted fixture name must be a real, resolvable backup before it can be relied on.' );

		$job_store = new JobStore( $this->content_dir );
		$this->create_completable_job( $job_store, true );
		$this->stub_completion_wp_functions();

		$recorded = array_map( 'basename', $backup_store->backups() );

		$ticker = new JobTicker(
			$this->environment_mock(),
			$this->completion_context( 2, $recorded ),
			$job_store,
			$backup_store,
			new NullLogger(),
			$this->empty_manifest_builder_factory()
		);

		$ticker->run();

		$surviving = array_map( 'basename', $backup_store->backups() );
		sort( $surviving );
		$this->assertSame(
			array( 'pontifex-backup-20260104T000000Z.wpmig', 'pontifex-backup-20260105T000000Z.wpmig' ),
			$surviving,
			'Retention 2 must keep exactly the two newest RECORDED backups by name, not merely two backups of some kind.'
		);
	}

	/**
	 * A hand-made backup — never recorded in the ledger, because nothing
	 * scheduled ever wrote it — does not consume a retention slot: with
	 * retention 2 and two RECORDED (scheduled) backups plus one unrecorded
	 * (hand-made) one, both scheduled backups survive and so does the
	 * hand-made one. Before this fix, retention counted every backup on disk
	 * regardless of origin, so this exact shape could prune the hand-made
	 * backup, or a scheduled one, depending on nothing more meaningful than
	 * name order.
	 *
	 * @return void
	 */
	public function test_prune_never_counts_a_hand_made_backup_against_the_retention_slot(): void {
		$this->plant_backup( '20260101T000000Z' ); // Scheduled.
		$this->plant_backup( '20260102T000000Z' ); // Scheduled.
		$this->plant_backup( '20260103T000000Z' ); // Hand-made; never recorded.

		$backup_store = new BackupStore( $this->content_dir );
		$this->assertCount( 3, $backup_store->backups() );

		$job_store = new JobStore( $this->content_dir );
		$this->create_completable_job( $job_store, true );
		$this->stub_completion_wp_functions();

		$recorded = array(
			'pontifex-backup-20260101T000000Z.wpmig',
			'pontifex-backup-20260102T000000Z.wpmig',
		);

		$ticker = new JobTicker(
			$this->environment_mock(),
			$this->completion_context( 2, $recorded ),
			$job_store,
			$backup_store,
			new NullLogger(),
			$this->empty_manifest_builder_factory()
		);

		$ticker->run();

		$surviving = array_map( 'basename', $backup_store->backups() );
		sort( $surviving );
		$this->assertSame(
			array(
				'pontifex-backup-20260101T000000Z.wpmig',
				'pontifex-backup-20260102T000000Z.wpmig',
				'pontifex-backup-20260103T000000Z.wpmig',
			),
			$surviving,
			'Retention 2 on two RECORDED backups must not prune either of them, and the unrecorded hand-made third must never even be considered.'
		);
	}

	/**
	 * A completed SCHEDULE-originated job records its own output filename in
	 * the ledger — the fact {@see \Pontifex\Schedule\JobTicker::prune_to_retention()}
	 * depends on to tell a scheduler-written backup apart from a hand-made
	 * one sharing the exact same generated name.
	 *
	 * @return void
	 */
	public function test_finalise_records_the_scheduled_backups_own_filename(): void {
		$backup_store = new BackupStore( $this->content_dir );
		$backup_store->ensure_directory();
		$output = $backup_store->next_backup_path( new DateTimeImmutable( '2026-01-05T00:00:00+00:00' ) );

		$job_store = new JobStore( $this->content_dir );
		$this->create_completable_job( $job_store, true, $output );
		$this->stub_completion_wp_functions();

		$recorded = array();

		$ticker = new JobTicker(
			$this->environment_mock(),
			$this->completion_context( 5, $recorded ),
			$job_store,
			$backup_store,
			new NullLogger(),
			$this->empty_manifest_builder_factory()
		);

		$ticker->run();

		$this->assertSame(
			array( 'pontifex-backup-20260105T000000Z.wpmig' ),
			$recorded,
			'The scheduled run must record exactly its own output filename in the ledger.'
		);
	}

	/**
	 * A completed MANUAL job (the `schedule` flag absent from its payload)
	 * never prunes: an operator's own backups must never be deleted by
	 * someone else's retention setting.
	 *
	 * Retention is deliberately set to a stringent 1 so the test would catch
	 * ANY pruning at all, not merely an over-aggressive one.
	 *
	 * @return void
	 */
	public function test_finalise_never_prunes_a_manual_job(): void {
		$stamps = array( '20260101T000000Z', '20260102T000000Z', '20260103T000000Z' );
		foreach ( $stamps as $stamp ) {
			$this->plant_backup( $stamp );
		}

		$backup_store = new BackupStore( $this->content_dir );
		$this->assertCount( 3, $backup_store->backups() );

		$job_store = new JobStore( $this->content_dir );
		$this->create_completable_job( $job_store, false );
		$this->stub_completion_wp_functions();

		$ticker = new JobTicker(
			$this->environment_mock(),
			$this->completion_context( 1 ),
			$job_store,
			$backup_store,
			new NullLogger(),
			$this->empty_manifest_builder_factory()
		);

		$ticker->run();

		$this->assertCount( 3, $backup_store->backups(), 'A manual job must leave every backup untouched, even against a retention of 1 on record.' );
	}

	/**
	 * The retention floor holds when the stored schedule's retention is
	 * zero: {@see \Pontifex\Schedule\Schedule}'s constructor clamps up to
	 * MIN_RETENTION, so pruning can never empty the store.
	 *
	 * ADR 0005 exists precisely to stop a pruning rule from being
	 * configurable into deleting everything; this pins that guarantee
	 * through the whole finalise() -> prune_to_retention() ->
	 * ScheduleStore::load() path, not only at the Schedule value object in
	 * isolation.
	 *
	 * @return void
	 */
	public function test_finalise_never_empties_the_store_when_stored_retention_is_zero(): void {
		$stamps = array( '20260101T000000Z', '20260102T000000Z', '20260103T000000Z' );
		foreach ( $stamps as $stamp ) {
			$this->plant_backup( $stamp );
		}

		$backup_store = new BackupStore( $this->content_dir );
		$this->assertCount( 3, $backup_store->backups() );

		$job_store = new JobStore( $this->content_dir );
		$this->create_completable_job( $job_store, true );
		$this->stub_completion_wp_functions();

		$recorded = array_map( 'basename', $backup_store->backups() );

		$ticker = new JobTicker(
			$this->environment_mock(),
			$this->completion_context( 0, $recorded ),
			$job_store,
			$backup_store,
			new NullLogger(),
			$this->empty_manifest_builder_factory()
		);

		$ticker->run();

		$surviving = $backup_store->backups();
		$this->assertNotEmpty( $surviving, 'The floor must hold: a stored retention of zero must never empty the store.' );
		$this->assertCount( Schedule::MIN_RETENTION, $surviving, 'Retention clamps to exactly MIN_RETENTION, so only the newest backup survives.' );
		$this->assertSame(
			'pontifex-backup-20260103T000000Z.wpmig',
			basename( $surviving[0] ),
			'The single survivor must be the newest, not an arbitrary one.'
		);
	}

	/**
	 * The completion log line ("Cron-driven backup complete.") — the ONLY
	 * thing an operator ever sees from an unattended scheduled run — carries
	 * the exclusion match counts the finished job's payload holds.
	 *
	 * The job's payload is given one real exclusion pattern; the empty
	 * manifest builder factory means nothing is ever actually scanned, so
	 * the persisted count for it is 0 — this test is about the count
	 * REACHING the log line at all, not about a genuine match, which
	 * {@see \Pontifex\Tests\Unit\Export\ResumableExportRunnerTest} already
	 * covers in depth.
	 *
	 * @return void
	 */
	public function test_finalise_logs_the_exclusion_match_counts(): void {
		$job_store = new JobStore( $this->content_dir );
		$job       = $this->create_completable_job( $job_store, false );

		$payload               = $job->payload();
		$payload['exclusions'] = array(
			array(
				'pattern' => 'wp-content/cache/**',
				'scope'   => 'any',
			),
		);
		$job->set_payload( $payload );
		$job_store->save( $job );

		$this->stub_completion_wp_functions();

		$logger = Mockery::mock( LoggerInterface::class );
		$logger->shouldReceive( 'info' )->once()->with(
			'Cron-driven backup complete.',
			Mockery::on(
				static function ( array $context ): bool {
					return array(
						array(
							'pattern' => 'wp-content/cache/**',
							'count'   => 0,
						),
					) === ( $context['exclusion_matches'] ?? null );
				}
			)
		);

		$ticker = new JobTicker(
			$this->environment_mock(),
			$this->completion_context( 5 ),
			$job_store,
			new BackupStore( $this->content_dir ),
			$logger,
			$this->empty_manifest_builder_factory()
		);

		$ticker->run();

		$this->assertTrue( true, 'Reached without the logger expectation above failing — Mockery::close() in tearDown is the real assertion.' );
	}
}
