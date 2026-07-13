<?php
/**
 * Unit tests for the Job, JobStore, and JobProgressLog classes.
 *
 * @package Pontifex\Tests\Unit\Job
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Job;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Pontifex\Job\Job;
use Pontifex\Job\JobProgressLog;
use Pontifex\Job\JobStore;

/**
 * Behavioural tests for the job layer, against a real temp filesystem.
 *
 * The job layer's whole purpose is surviving between processes, so the
 * tests exercise real files under a fresh tempdir rather than mocks —
 * the same strategy as FileWriterTest. Every test gets its own root.
 */
final class JobStoreTest extends TestCase {

	/**
	 * Absolute path standing in for wp-content in the current test.
	 *
	 * @var string
	 */
	private string $content_dir;

	/**
	 * Create a fresh content-dir fixture before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->content_dir = sys_get_temp_dir() . '/pontifex-jobstore-test-' . bin2hex( random_bytes( 8 ) );
		mkdir( $this->content_dir, 0o755, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating the test fixture directory.
	}

	/**
	 * Remove the fixture tree after each test.
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
	 * A created job persists and reloads with identical fields.
	 *
	 * @return void
	 */
	public function test_create_persists_a_pending_job_that_reloads_identically(): void {
		$store = new JobStore( $this->content_dir );

		$job = $store->create( Job::KIND_EXPORT, array( 'output' => '/tmp/x.wpmig' ), 1700000000 );

		$reloaded = $store->get( $job->id() );
		$this->assertNotNull( $reloaded );
		$this->assertSame( $job->id(), $reloaded->id() );
		$this->assertSame( Job::STATUS_PENDING, $reloaded->status() );
		$this->assertSame( array( 'output' => '/tmp/x.wpmig' ), $reloaded->payload() );
		$this->assertSame( 1700000000, $reloaded->created_at() );
	}

	/**
	 * At most one active job may exist: a second create refuses.
	 *
	 * The persisted half of the single-runner invariant (the named lock is
	 * the race-proof primary guard).
	 *
	 * @return void
	 */
	public function test_create_refuses_while_a_job_is_active(): void {
		$store = new JobStore( $this->content_dir );
		$store->create( Job::KIND_EXPORT, array(), 1700000000 );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'an active job already exists' );

		$store->create( Job::KIND_EXPORT, array(), 1700000001 );
	}

	/**
	 * A terminal job frees the active slot for the next create.
	 *
	 * @return void
	 */
	public function test_a_finished_job_frees_the_active_slot(): void {
		$store = new JobStore( $this->content_dir );
		$job   = $store->create( Job::KIND_EXPORT, array(), 1700000000 );
		$job->mark( Job::STATUS_RUNNING, 1700000001 );
		$job->mark( Job::STATUS_DONE, 1700000002 );
		$store->save( $job );

		$this->assertNull( $store->active_job(), 'A done job must not count as active.' );
		$second = $store->create( Job::KIND_EXPORT, array(), 1700000003 );
		$this->assertSame( Job::STATUS_PENDING, $second->status() );
	}

	/**
	 * The status state machine refuses to revive a terminal job.
	 *
	 * @return void
	 */
	public function test_terminal_statuses_never_transition(): void {
		$job = new Job( str_repeat( 'ab', 8 ), Job::KIND_EXPORT, Job::STATUS_DONE, array(), 1, 1 );

		$this->expectException( InvalidArgumentException::class );

		$job->mark( Job::STATUS_RUNNING, 2 );
	}

	/**
	 * Cleanup fails an abandoned active job and deletes an old terminal one.
	 *
	 * @return void
	 */
	public function test_cleanup_fails_abandoned_jobs_and_deletes_old_terminal_ones(): void {
		$store = new JobStore( $this->content_dir );

		$abandoned = $store->create( Job::KIND_EXPORT, array(), 1700000000 );
		$abandoned->mark( Job::STATUS_RUNNING, 1700000000 );
		$store->save( $abandoned );

		$old = new Job( str_repeat( 'cd', 8 ), Job::KIND_EXPORT, Job::STATUS_DONE, array(), 1600000000, 1600000000 );
		$store->save( $old );

		$swept = $store->cleanup( 1700010000, 3600, 604800 );

		$this->assertSame( 2, $swept );
		$this->assertSame( Job::STATUS_FAILED, $store->get( $abandoned->id() )->status(), 'A silent active job must be failed, not left wedging the slot.' );
		$this->assertNull( $store->get( $old->id() ), 'An old terminal job must be deleted.' );
	}

	/**
	 * A recently-updated running job survives cleanup untouched.
	 *
	 * @return void
	 */
	public function test_cleanup_leaves_a_live_job_alone(): void {
		$store = new JobStore( $this->content_dir );
		$job   = $store->create( Job::KIND_EXPORT, array(), 1700000000 );
		$job->mark( Job::STATUS_RUNNING, 1700000000 );
		$store->save( $job );

		$this->assertSame( 0, $store->cleanup( 1700000100, 3600, 604800 ) );
		$this->assertSame( Job::STATUS_RUNNING, $store->get( $job->id() )->status() );
	}

	/**
	 * The progress log appends and reads records in order.
	 *
	 * @return void
	 */
	public function test_progress_log_round_trips_records_in_order(): void {
		$store = new JobStore( $this->content_dir );
		$job   = $store->create( Job::KIND_EXPORT, array(), 1700000000 );
		$log   = $store->progress_log( $job->id() );

		$log->append(
			array(
				'index'  => 0,
				'offset' => 16,
				'length' => 100,
			)
		);
		$log->append(
			array(
				'index'  => 1,
				'offset' => 116,
				'length' => 250,
			)
		);

		$this->assertSame(
			array(
				array(
					'index'  => 0,
					'offset' => 16,
					'length' => 100,
				),
				array(
					'index'  => 1,
					'offset' => 116,
					'length' => 250,
				),
			),
			$log->read_all()
		);
	}

	/**
	 * A torn final line (a ticker killed mid-append) is dropped silently.
	 *
	 * @return void
	 */
	public function test_progress_log_tolerates_a_torn_final_line(): void {
		$store = new JobStore( $this->content_dir );
		$job   = $store->create( Job::KIND_EXPORT, array(), 1700000000 );
		$log   = $store->progress_log( $job->id() );
		$log->append( array( 'index' => 0 ) );
		// Simulate the crash: an unterminated partial append.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Simulating a torn append on the test's own fixture.
		file_put_contents( $log->path(), '{"index":1,"off', FILE_APPEND );

		$this->assertSame( array( array( 'index' => 0 ) ), $log->read_all(), 'The torn tail must be dropped, the complete records kept.' );
	}

	/**
	 * Corruption mid-file is refused loudly, not skipped.
	 *
	 * @return void
	 */
	public function test_progress_log_refuses_mid_file_corruption(): void {
		$store = new JobStore( $this->content_dir );
		$job   = $store->create( Job::KIND_EXPORT, array(), 1700000000 );
		$log   = $store->progress_log( $job->id() );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Seeding a corrupt fixture.
		file_put_contents( $log->path(), "not json\n" . '{"index":1}' . "\n" );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'corrupt mid-file' );

		$log->read_all();
	}

	/**
	 * The truncate_to method rewrites the log to a known-good prefix.
	 *
	 * @return void
	 */
	public function test_progress_log_truncates_to_a_known_good_count(): void {
		$store = new JobStore( $this->content_dir );
		$job   = $store->create( Job::KIND_EXPORT, array(), 1700000000 );
		$log   = $store->progress_log( $job->id() );
		$log->append( array( 'index' => 0 ) );
		$log->append( array( 'index' => 1 ) );
		$log->append( array( 'index' => 2 ) );

		$log->truncate_to( 2 );

		$this->assertSame( array( array( 'index' => 0 ), array( 'index' => 1 ) ), $log->read_all() );
	}

	/**
	 * The delete method removes both the record and its progress sidecar.
	 *
	 * @return void
	 */
	public function test_delete_removes_the_record_and_its_sidecar(): void {
		$store = new JobStore( $this->content_dir );
		$job   = $store->create( Job::KIND_EXPORT, array(), 1700000000 );
		$log   = $store->progress_log( $job->id() );
		$log->append( array( 'index' => 0 ) );

		$store->delete( $job->id() );

		$this->assertNull( $store->get( $job->id() ) );
		$this->assertFileDoesNotExist( $log->path() );
	}

	/**
	 * A corrupt job record does not block the active-job lookup or cleanup.
	 *
	 * @return void
	 */
	public function test_a_corrupt_record_does_not_wedge_the_store(): void {
		$store = new JobStore( $this->content_dir );
		$store->ensure_directory();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Seeding a corrupt fixture.
		file_put_contents( $store->directory() . '/' . str_repeat( 'ef', 8 ) . '.json', 'not json' );

		$this->assertNull( $store->active_job(), 'A corrupt record must be skipped, not treated as active.' );
		$job = $store->create( Job::KIND_EXPORT, array(), 1700000000 );
		$this->assertSame( Job::STATUS_PENDING, $job->status(), 'Creation must proceed despite the corrupt record.' );
	}

	/**
	 * The get method refuses a malformed id without touching the filesystem.
	 *
	 * @return void
	 */
	public function test_get_returns_null_for_a_malformed_id(): void {
		$store = new JobStore( $this->content_dir );

		$this->assertNull( $store->get( '../../etc/passwd' ) );
		$this->assertNull( $store->get( 'SHOUTING-HEX-00' ) );
	}
}
