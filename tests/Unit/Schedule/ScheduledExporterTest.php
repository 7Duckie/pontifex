<?php
/**
 * Unit tests for ScheduledExporter — the cron handler that starts the periodic backup.
 *
 * @package Pontifex\Tests\Unit\Schedule
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Schedule;

use Brain\Monkey\Functions;
use Mockery;
use Throwable;
use Pontifex\Admin\BackupStore;
use Pontifex\Archive\ArchiveName;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Format\Scope;
use Pontifex\Cli\TransferHistory;
use Pontifex\Environment\Environment;
use Pontifex\Export\ExportCounters;
use Pontifex\Job\Job;
use Pontifex\Job\JobStore;
use Pontifex\Lock\OperationLock;
use Pontifex\Manifest\ExclusionPattern;
use Pontifex\Manifest\ExclusionRules;
use Pontifex\Manifest\ManifestBuilderInterface;
use Pontifex\Schedule\JobTicker;
use Pontifex\Schedule\Schedule;
use Pontifex\Schedule\ScheduledExporter;
use Pontifex\Schedule\ScheduleStore;
use Pontifex\Tests\TestCase;
use Pontifex\WordPress\WordPressContext;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Behavioural tests for ScheduledExporter::run(), the `pontifex_scheduled_export`
 * handler.
 *
 * ScheduledExporter is the one place an unattended backup begins: it decides
 * whether the schedule is even on, refuses to start a second job while one is
 * already active, takes the shared {@see OperationLock} so it can never start
 * while a restore or rollback is running anywhere else, and — only once all of
 * that has cleared — starts the job and hands it to {@see JobTicker} to drive.
 * Every one of those decisions is real production behaviour with a real
 * failure mode if it regresses: two concurrent backups corrupting each
 * other's output, a scheduled run starting while a restore is mid-write, or
 * (the worst case) a 03:00 failure that is never recorded anywhere, so the
 * operator believes they have backups they do not.
 *
 * JobTicker is `final` and cannot be mocked, so its involvement is proven
 * with a REAL JobTicker instance, wired to its OWN, separate WordPressContext
 * mock whose `acquire_named_lock()` always returns false. Given the real
 * job store the exporter and the ticker share, a real ticker that reaches
 * that lock check can only mean it found the job the exporter just started —
 * so asserting that mock's `acquire_named_lock()` call count doubles as proof
 * the ticker was (or was not) reached at all.
 *
 * {@see OperationLock} reads and writes its transients directly via the
 * global `get_transient()`/`set_transient()`/`delete_transient()` functions,
 * not through WordPressContext, so every test stubs those three with an
 * in-memory array standing in for the transient store — the same approach
 * `OperationLockTest` uses — so the held/released state can be asserted on
 * directly rather than inferred.
 */
final class ScheduledExporterTest extends TestCase {

	/**
	 * The log line ScheduledExporter writes for both refusal paths: an
	 * already-active job, and a shared lock held elsewhere.
	 *
	 * @var string
	 */
	private const SKIP_MESSAGE = 'Scheduled backup skipped: another operation is already running.';

	/**
	 * Fixture directory standing in for wp-content.
	 *
	 * @var string
	 */
	private string $content_dir;

	/**
	 * The real job store the exporter and its ticker share.
	 *
	 * @var JobStore
	 */
	private JobStore $job_store;

	/**
	 * The real backup store the exporter writes into.
	 *
	 * @var BackupStore
	 */
	private BackupStore $backup_store;

	/**
	 * The in-memory fake transient store shared by every stubbed transient call.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients;

	/**
	 * Create the fixture tree and stub the transient functions OperationLock
	 * reads and writes directly.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->content_dir = sys_get_temp_dir() . '/pontifex-scheduled-exporter-test-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating the test fixture directory.
		mkdir( $this->content_dir, 0o755, true );

		$this->job_store    = new JobStore( $this->content_dir );
		$this->backup_store = new BackupStore( $this->content_dir );
		$this->transients   = array();

		// OperationLock's holder transient and BackupProgress's liveness transient
		// both travel through these three raw WordPress functions rather than
		// through WordPressContext, so every scenario needs a working fake store
		// for them, regardless of which WordPressContext mock is under test.
		Functions\when( 'get_transient' )->alias(
			function ( string $key ) {
				return $this->transients[ $key ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value, int $ttl ): bool {
				unset( $ttl );
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( string $key ): bool {
				unset( $this->transients[ $key ] );
				return true;
			}
		);
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
	 * Count the job records currently persisted, independent of JobStore's own
	 * reading, so a test can tell "one job" from "two jobs" rather than trusting
	 * JobStore::active_job()'s single-answer contract to catch a duplicate.
	 *
	 * @return int How many `*.json` job records exist on disk right now.
	 */
	private function job_record_count(): int {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_glob -- Test fixture inspection of the plugin-owned jobs directory; WP_Filesystem is unavailable in this unit-test context.
		$matches = glob( $this->job_store->directory() . '/*.json' );
		return false === $matches ? 0 : count( $matches );
	}

	/**
	 * Build a stored-schedule option array, enabled by default.
	 *
	 * @param array<string, mixed> $overrides Fields to override on top of the enabled default.
	 * @return array<string, mixed> The array ScheduleStore::load() decodes via Schedule::from_stored().
	 */
	private function schedule_option( array $overrides = array() ): array {
		return array_merge(
			array(
				'enabled'          => true,
				'frequency'        => Schedule::FREQUENCY_DAILY,
				'hour'             => 3,
				'retention'        => 3,
				'exclusions'       => array(),
				'table_exclusions' => array(),
			),
			$overrides
		);
	}

	/**
	 * Build the exporter under test over the shared job store and backup store.
	 *
	 * @param WordPressContext $exporter_context The context the exporter itself reads and writes through.
	 * @param JobTicker        $ticker           The ticker the exporter hands off to once a job is started.
	 * @param LoggerInterface  $logger           The logger the exporter reports through.
	 * @return ScheduledExporter The exporter under test.
	 */
	private function build_exporter( WordPressContext $exporter_context, JobTicker $ticker, LoggerInterface $logger ): ScheduledExporter {
		return new ScheduledExporter(
			Mockery::mock( Environment::class ),
			$exporter_context,
			$this->job_store,
			$this->backup_store,
			$ticker,
			$logger
		);
	}

	/**
	 * Build a real JobTicker over the shared job store and backup store, wired
	 * to its OWN WordPressContext so its lock decision is independent of the
	 * exporter's. A manifest-builder factory that fails the test if invoked
	 * guards the assumption every scenario here relies on: the ticker always
	 * declines the named lock and reschedules, so it must never reach the
	 * runner.
	 *
	 * @param WordPressContext $ticker_context The ticker's own context, deciding whether it can take the lock.
	 * @return JobTicker The ticker under test.
	 */
	private function build_ticker( WordPressContext $ticker_context ): JobTicker {
		return new JobTicker(
			Mockery::mock( Environment::class ),
			$ticker_context,
			$this->job_store,
			$this->backup_store,
			new NullLogger(),
			function (): ManifestBuilderInterface {
				$this->fail( 'JobTicker must never reach the runner in these tests: every scenario has it decline the named lock and reschedule.' );
			}
		);
	}

	// -------------------------------------------------------------------------
	// 1. Schedule disabled.
	// -------------------------------------------------------------------------

	/**
	 * A disabled schedule stands down completely, before touching the lock,
	 * the job store, or any option.
	 *
	 * Catches a defect where a disabled schedule still starts a backup — the
	 * operator turned the schedule off, and an unattended run happening
	 * anyway is exactly the surprise a "disable" toggle exists to prevent.
	 *
	 * @return void
	 */
	public function test_disabled_schedule_stands_down_completely(): void {
		$exporter_context = Mockery::mock( WordPressContext::class );
		$exporter_context->shouldReceive( 'option_value' )
			->once()
			->with( ScheduleStore::OPTION, array() )
			->andReturn( $this->schedule_option( array( 'enabled' => false ) ) );
		$exporter_context->shouldNotReceive( 'acquire_named_lock' );
		$exporter_context->shouldNotReceive( 'release_named_lock' );
		$exporter_context->shouldNotReceive( 'save_option' );

		$ticker_context = Mockery::mock( WordPressContext::class );
		$ticker_context->shouldNotReceive( 'acquire_named_lock' );

		$logger = Mockery::mock( LoggerInterface::class );
		$logger->shouldReceive( 'info' )->never();
		$logger->shouldReceive( 'error' )->never();

		$exporter = $this->build_exporter( $exporter_context, $this->build_ticker( $ticker_context ), $logger );
		$exporter->run();

		$this->assertNull( $this->job_store->active_job(), 'A disabled schedule must never create a job.' );
		$this->assertSame( 0, $this->job_record_count() );
	}

	// -------------------------------------------------------------------------
	// 2. Another job is already active.
	// -------------------------------------------------------------------------

	/**
	 * An already-active job makes the exporter skip rather than queue a second
	 * one; the existing job is left completely untouched.
	 *
	 * Catches the defect of two concurrent backups racing on one site: the
	 * check this pins is what stops a scheduled run from starting a second
	 * export while the first (scheduled or manual) is still running.
	 *
	 * @return void
	 */
	public function test_active_job_skips_rather_than_queueing_a_second(): void {
		$existing = $this->job_store->create(
			Job::KIND_EXPORT,
			array( 'output' => $this->content_dir . '/existing.wpmig' ),
			1700000000
		);

		$exporter_context = Mockery::mock( WordPressContext::class );
		$exporter_context->shouldReceive( 'option_value' )
			->once()
			->with( ScheduleStore::OPTION, array() )
			->andReturn( $this->schedule_option() );
		$exporter_context->shouldNotReceive( 'acquire_named_lock' );
		$exporter_context->shouldNotReceive( 'save_option' );

		$ticker_context = Mockery::mock( WordPressContext::class );
		$ticker_context->shouldNotReceive( 'acquire_named_lock' );

		$logger = Mockery::mock( LoggerInterface::class );
		$logger->shouldReceive( 'info' )->once()->with( self::SKIP_MESSAGE );
		$logger->shouldReceive( 'error' )->never();

		$exporter = $this->build_exporter( $exporter_context, $this->build_ticker( $ticker_context ), $logger );
		$exporter->run();

		$after = $this->job_store->active_job();
		$this->assertNotNull( $after, 'The pre-existing job must still be there.' );
		$this->assertSame( $existing->id(), $after->id(), 'It must be the SAME job, not a replacement.' );
		$this->assertSame( $existing->updated_at(), $after->updated_at(), 'The pre-existing job must not have been re-saved.' );
		$this->assertSame( 1, $this->job_record_count(), 'No second job may be queued while one is active.' );
	}

	// -------------------------------------------------------------------------
	// 3. A restore holds the shared lock.
	// -------------------------------------------------------------------------

	/**
	 * A restore holding the shared lock makes the exporter skip and hand the
	 * named database lock straight back, leaving the restore's own holder
	 * record untouched.
	 *
	 * Catches two defects at once: a scheduled backup starting while a
	 * restore is mid-write (which could corrupt both), and a refused
	 * acquire leaking the named lock — which would wedge every other
	 * operation on the site until the lock's own timeout, not just this one.
	 *
	 * @return void
	 */
	public function test_restore_holder_skips_and_hands_back_the_named_lock(): void {
		$this->transients[ OperationLock::LOCK_NAME ] = array(
			'kind' => OperationLock::OP_RESTORE,
			'at'   => time() - 5,
		);

		$exporter_context = Mockery::mock( WordPressContext::class );
		$exporter_context->shouldReceive( 'option_value' )
			->once()
			->with( ScheduleStore::OPTION, array() )
			->andReturn( $this->schedule_option() );
		$exporter_context->shouldReceive( 'acquire_named_lock' )->once()->with( OperationLock::LOCK_NAME )->andReturn( true );
		$exporter_context->shouldReceive( 'release_named_lock' )->once()->with( OperationLock::LOCK_NAME );
		$exporter_context->shouldNotReceive( 'save_option' );

		$ticker_context = Mockery::mock( WordPressContext::class );
		$ticker_context->shouldNotReceive( 'acquire_named_lock' );

		$logger = Mockery::mock( LoggerInterface::class );
		$logger->shouldReceive( 'info' )->once()->with( self::SKIP_MESSAGE );
		$logger->shouldReceive( 'error' )->never();

		$exporter = $this->build_exporter( $exporter_context, $this->build_ticker( $ticker_context ), $logger );
		$exporter->run();

		$this->assertNull( $this->job_store->active_job(), 'A restore in progress must never let a scheduled backup start a job.' );
		$this->assertSame(
			OperationLock::OP_RESTORE,
			$this->transients[ OperationLock::LOCK_NAME ]['kind'] ?? null,
			'The restore\'s own holder record must be left standing — only the named database lock is handed back.'
		);
	}

	// -------------------------------------------------------------------------
	// 4. The named database lock is held elsewhere.
	// -------------------------------------------------------------------------

	/**
	 * The named database lock being held elsewhere makes the exporter skip,
	 * the same as an active job or a restore holder, but for a genuinely
	 * different reason: another request is contending for the lock right now.
	 *
	 * Catches a defect where the exporter proceeds to start a job despite
	 * being unable to acquire the single-runner guard at all — the case the
	 * guard exists for.
	 *
	 * @return void
	 */
	public function test_named_lock_held_elsewhere_skips(): void {
		$exporter_context = Mockery::mock( WordPressContext::class );
		$exporter_context->shouldReceive( 'option_value' )
			->once()
			->with( ScheduleStore::OPTION, array() )
			->andReturn( $this->schedule_option() );
		$exporter_context->shouldReceive( 'acquire_named_lock' )->once()->with( OperationLock::LOCK_NAME )->andReturn( false );
		$exporter_context->shouldNotReceive( 'release_named_lock' );
		$exporter_context->shouldNotReceive( 'save_option' );

		$ticker_context = Mockery::mock( WordPressContext::class );
		$ticker_context->shouldNotReceive( 'acquire_named_lock' );

		$logger = Mockery::mock( LoggerInterface::class );
		$logger->shouldReceive( 'info' )->once()->with( self::SKIP_MESSAGE );
		$logger->shouldReceive( 'error' )->never();

		$exporter = $this->build_exporter( $exporter_context, $this->build_ticker( $ticker_context ), $logger );
		$exporter->run();

		$this->assertNull( $this->job_store->active_job() );
		$this->assertSame( 0, $this->job_record_count() );
	}

	// -------------------------------------------------------------------------
	// 5. Happy path.
	// -------------------------------------------------------------------------

	/**
	 * With the schedule enabled and nothing else running, the exporter starts
	 * exactly one correctly-configured job, bumps the attempt counter by one,
	 * releases the shared lock, and hands off to the ticker.
	 *
	 * This is the everyday case, and every assertion pins a way it could
	 * quietly go wrong for an unattended site: a missing `schedule` flag
	 * means retention pruning never runs and backups accumulate until disk
	 * fills; dropped operator exclusions mean the scheduled run silently
	 * differs from what the operator configured on the Schedule screen;
	 * the wrong scope would carry WordPress core into a backup meant to be
	 * content-only; and a lock never released or a ticker never reached
	 * would each wedge every later operation.
	 *
	 * @return void
	 */
	public function test_happy_path_starts_a_correctly_configured_job_and_reaches_the_ticker(): void {
		$operator_patterns = array( 'wp-content/uploads/tmp/**', 'wp-content/private-notes.txt' );

		$exporter_context = Mockery::mock( WordPressContext::class );
		$exporter_context->shouldReceive( 'option_value' )
			->once()
			->with( ScheduleStore::OPTION, array() )
			->andReturn( $this->schedule_option( array( 'exclusions' => $operator_patterns ) ) );
		$exporter_context->shouldReceive( 'acquire_named_lock' )->once()->with( OperationLock::LOCK_NAME )->andReturn( true );
		$exporter_context->shouldReceive( 'release_named_lock' )->once()->with( OperationLock::LOCK_NAME );

		$exporter_context->shouldReceive( 'option_value' )
			->once()
			->with( ExportCounters::OPTION, array() )
			->andReturn( array() );

		$captured_counters = null;
		$exporter_context->shouldReceive( 'save_option' )
			->once()
			->with(
				ExportCounters::OPTION,
				Mockery::on(
					function ( array $value ) use ( &$captured_counters ): bool {
						$captured_counters = $value;
						return true;
					}
				)
			);

		$ticker_context = Mockery::mock( WordPressContext::class );
		$ticker_context->shouldReceive( 'acquire_named_lock' )->once()->with( OperationLock::LOCK_NAME )->andReturn( false );

		$logger = Mockery::mock( LoggerInterface::class );
		$logger->shouldReceive( 'info' )->once()->with(
			'Scheduled backup started.',
			Mockery::on(
				static function ( array $context ): bool {
					return isset( $context['output'] ) && is_string( $context['output'] );
				}
			)
		);
		$logger->shouldReceive( 'error' )->never();

		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_single_event' )->once()->with( Mockery::type( 'int' ), JobTicker::CRON_HOOK );

		$exporter = $this->build_exporter( $exporter_context, $this->build_ticker( $ticker_context ), $logger );
		$exporter->run();

		$job = $this->job_store->active_job();
		$this->assertNotNull( $job, 'The happy path must start exactly one job.' );
		$this->assertSame( 1, $this->job_record_count() );
		$this->assertSame( Job::KIND_EXPORT, $job->kind() );
		$this->assertTrue( $job->is_active() );

		$payload = $job->payload();
		$this->assertTrue(
			$payload['schedule'] ?? false,
			'schedule must be true: it is the only flag that makes retention pruning ever run, and without it a scheduled site accumulates full backups until the disk fills.'
		);

		$this->assertStringStartsWith( $this->backup_store->directory() . '/', (string) $payload['output'] );
		$this->assertSame(
			1,
			preg_match( ArchiveName::PATTERN, basename( (string) $payload['output'] ) ),
			'The output filename must match the pontifex-backup-<UTC>.wpmig shape.'
		);

		$expected_exclusions = array_merge( ExclusionRules::default_v010()->patterns(), $operator_patterns );

		// The job payload carries the TAGGED shape (ResumableExportRunner
		// persists pattern + scope, not raw text) so the round trip through a
		// tick reconstructs each pattern with its original kind scope intact.
		// The defaults come first, untagged ('any'); the operator's own
		// patterns follow, scoped to files ('file') — proving an unattended
		// run applies what the operator configured, in the same order, and
		// with the same file-only scope ExportCommand gives --exclude.
		$expected_tagged_exclusions = array_merge(
			array_map(
				static fn ( string $pattern ): array => array(
					'pattern' => $pattern,
					'scope'   => ExclusionPattern::SCOPE_ANY,
				),
				ExclusionRules::default_v010()->patterns()
			),
			array_map(
				static fn ( string $pattern ): array => array(
					'pattern' => $pattern,
					'scope'   => ExclusionPattern::SCOPE_FILE,
				),
				$operator_patterns
			)
		);
		$this->assertSame(
			$expected_tagged_exclusions,
			$payload['exclusions'],
			'The defaults must come first, untagged, and the operator\'s own patterns after, scoped to files — proving an unattended run applies what the operator actually configured, not just the curated defaults, and cannot let a file pattern reach a database table.'
		);

		$scope = Scope::from_array( (array) $payload['scope'] );
		$this->assertTrue( $scope->is_content_only() );
		$this->assertSame( 'wp-content', $scope->content_root() );
		$this->assertTrue( $scope->includes_files() );
		$this->assertTrue( $scope->includes_database() );
		$this->assertFalse( $scope->includes_core() );
		$this->assertFalse( $scope->includes_wp_config() );
		$this->assertSame( $expected_exclusions, $scope->excluded_paths() );

		$this->assertSame( $this->content_dir, $payload['scan_root'], 'scan_root must be the content directory: the backup directory\'s grandparent.' );

		$this->assertNotNull( $captured_counters, 'The attempted counter must actually have been written.' );
		$this->assertSame( 1, $captured_counters['attempted'] ?? null, 'The attempted export counter must be incremented by exactly one.' );

		$this->assertArrayNotHasKey( OperationLock::LOCK_NAME, $this->transients, 'The shared lock must be released once the job is started, not held for the whole run.' );
	}

	/**
	 * Headline case: a scheduled backup's file pattern never excludes a
	 * database table, and its table pattern does exclude the table it names.
	 *
	 * Job 14 exists because a pattern meaning "skip the comments folder"
	 * silently dropped the wp_comments database table from an unattended
	 * run too — repeated every night, unwatched, which is what makes it
	 * worse than the same mistake on a one-off manual export. This pins
	 * that the schedule's two pattern lists ({@see Schedule::exclusions()}
	 * and {@see Schedule::table_exclusions()}) keep their scope all the way
	 * through to the persisted job payload, by reconstructing the tagged
	 * entries the payload actually stores and checking real matching
	 * behaviour against both kinds of entry.
	 *
	 * @return void
	 */
	public function test_scheduled_backup_file_pattern_does_not_exclude_a_table_and_table_pattern_does(): void {
		$file_pattern  = 'wp_comments';
		$table_pattern = 'wp_sessions';

		$exporter_context = Mockery::mock( WordPressContext::class );
		$exporter_context->shouldReceive( 'option_value' )
			->once()
			->with( ScheduleStore::OPTION, array() )
			->andReturn(
				$this->schedule_option(
					array(
						'exclusions'       => array( $file_pattern ),
						'table_exclusions' => array( $table_pattern ),
					)
				)
			);
		$exporter_context->shouldReceive( 'acquire_named_lock' )->once()->with( OperationLock::LOCK_NAME )->andReturn( true );
		$exporter_context->shouldReceive( 'release_named_lock' )->once()->with( OperationLock::LOCK_NAME );
		$exporter_context->shouldReceive( 'option_value' )->once()->with( ExportCounters::OPTION, array() )->andReturn( array() );
		$exporter_context->shouldReceive( 'save_option' );

		$ticker_context = Mockery::mock( WordPressContext::class );
		$ticker_context->shouldReceive( 'acquire_named_lock' )->once()->with( OperationLock::LOCK_NAME )->andReturn( false );

		$logger = Mockery::mock( LoggerInterface::class );
		$logger->shouldReceive( 'info' )->once();
		$logger->shouldReceive( 'error' )->never();

		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_single_event' )->once()->with( Mockery::type( 'int' ), JobTicker::CRON_HOOK );

		$exporter = $this->build_exporter( $exporter_context, $this->build_ticker( $ticker_context ), $logger );
		$exporter->run();

		$job = $this->job_store->active_job();
		$this->assertNotNull( $job );
		$payload = $job->payload();

		$rules = ExclusionRules::from_tagged_patterns( self::reconstruct_tagged_entries( (array) $payload['exclusions'] ) );

		$this->assertFalse(
			$rules->matches( $file_pattern, EntryHeader::KIND_DB_CHUNK ),
			'A file-scoped pattern must never exclude a database table, even one whose name it happens to match.'
		);
		$this->assertTrue(
			$rules->matches( $file_pattern, EntryHeader::KIND_FILE ),
			'The same pattern must still exclude a matching file.'
		);
		$this->assertTrue(
			$rules->matches( $table_pattern, EntryHeader::KIND_DB_CHUNK ),
			'A table-scoped pattern must exclude the table it names.'
		);
		$this->assertFalse(
			$rules->matches( $table_pattern, EntryHeader::KIND_FILE ),
			'A table-scoped pattern must never exclude a file, even one whose name it happens to match.'
		);
	}

	/**
	 * Rebuild ExclusionPattern entries from the tagged shape
	 * ResumableExportRunner::start() persists onto a job payload — the same
	 * {pattern, scope} arrays {@see \Pontifex\Export\ResumableExportRunner}
	 * reconstructs on every tick. Kept local to this test file (that
	 * reconstruction is a private implementation detail of the runner) so
	 * this suite can assert on real matching behaviour rather than the raw
	 * persisted shape alone.
	 *
	 * @param array<int, mixed> $stored The payload's 'exclusions' entries, as persisted.
	 * @return ExclusionPattern[] The reconstructed tagged patterns.
	 */
	private static function reconstruct_tagged_entries( array $stored ): array {
		$entries = array();
		foreach ( $stored as $item ) {
			if ( ! is_array( $item ) ) {
				$entries[] = ExclusionPattern::untagged( (string) $item );
				continue;
			}
			$pattern   = isset( $item['pattern'] ) ? (string) $item['pattern'] : '';
			$scope     = isset( $item['scope'] ) ? (string) $item['scope'] : ExclusionPattern::SCOPE_ANY;
			$entries[] = match ( $scope ) {
				ExclusionPattern::SCOPE_FILE  => ExclusionPattern::operator_file( $pattern ),
				ExclusionPattern::SCOPE_TABLE => ExclusionPattern::operator_table( $pattern ),
				default                       => ExclusionPattern::untagged( $pattern ),
			};
		}
		return $entries;
	}

	// -------------------------------------------------------------------------
	// 6. The export cannot start.
	// -------------------------------------------------------------------------

	/**
	 * When the export cannot even start — here, because BackupStore::ensure_directory()
	 * fails — the failure is recorded (logged and written to the transfer
	 * history), never silently swallowed, and the shared lock is still released.
	 *
	 * The obstruction is a real regular file planted where the backups
	 * directory needs to be created, proven empirically (a standalone spike
	 * against a real temp directory) to make BackupStore::ensure_directory()
	 * throw before this test relied on it.
	 *
	 * Catches the worst failure mode this class has: a backup that fails at
	 * 03:00 and leaves no trace anywhere, so the operator goes on believing
	 * they have backups they do not.
	 *
	 * @return void
	 */
	public function test_export_cannot_start_records_the_failure_and_does_not_swallow_it(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture: planting a plain file where BackupStore needs to create a directory, so ensure_directory() really fails.
		file_put_contents( $this->content_dir . '/pontifex', 'not a directory' );

		$exporter_context = Mockery::mock( WordPressContext::class );
		$exporter_context->shouldReceive( 'option_value' )
			->once()
			->with( ScheduleStore::OPTION, array() )
			->andReturn( $this->schedule_option() );
		$exporter_context->shouldReceive( 'acquire_named_lock' )->once()->with( OperationLock::LOCK_NAME )->andReturn( true );
		$exporter_context->shouldReceive( 'release_named_lock' )->once()->with( OperationLock::LOCK_NAME );

		// The attempted counter belongs only to a job that actually started;
		// bump_attempted() must never run on this path.
		$exporter_context->shouldNotReceive( 'save_option' )->with( ExportCounters::OPTION, Mockery::any() );
		$exporter_context->shouldNotReceive( 'option_value' )->with( ExportCounters::OPTION, Mockery::any() );

		$exporter_context->shouldReceive( 'option_value' )
			->once()
			->with( TransferHistory::OPTION, array() )
			->andReturn( array() );

		$captured_history = null;
		$exporter_context->shouldReceive( 'save_option' )
			->once()
			->with(
				TransferHistory::OPTION,
				Mockery::on(
					function ( array $value ) use ( &$captured_history ): bool {
						$captured_history = $value;
						return true;
					}
				)
			);

		$ticker_context = Mockery::mock( WordPressContext::class );
		$ticker_context->shouldNotReceive( 'acquire_named_lock' );

		$logger = Mockery::mock( LoggerInterface::class );
		$logger->shouldReceive( 'info' )->never();
		$logger->shouldReceive( 'error' )->once()->with(
			'Scheduled backup could not start.',
			Mockery::on(
				static function ( array $context ): bool {
					return isset( $context['exception'] ) && $context['exception'] instanceof Throwable;
				}
			)
		);

		$exporter = $this->build_exporter( $exporter_context, $this->build_ticker( $ticker_context ), $logger );
		$exporter->run();

		$this->assertNull( $this->job_store->active_job(), 'A failed start must leave no job behind.' );
		$this->assertSame( 0, $this->job_record_count() );

		$this->assertNotNull( $captured_history, 'A transfer history row must actually have been written, not merely attempted.' );
		$this->assertCount( 1, $captured_history );
		$this->assertSame( 'export', $captured_history[0]['operation'] ?? null );
		$this->assertSame( 'failed', $captured_history[0]['outcome'] ?? null, 'The recorded outcome must be a real failure, not silently reported as anything else.' );
		$this->assertSame( 0, $captured_history[0]['bytes'] ?? null );

		$this->assertArrayNotHasKey( OperationLock::LOCK_NAME, $this->transients, 'The shared lock must be released via the finally block even when the export fails to start.' );
	}
}
