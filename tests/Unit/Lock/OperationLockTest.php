<?php
/**
 * Tests for OperationLock — the shared single-runner guard.
 *
 * @package Pontifex\Tests\Unit\Lock
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Lock;

use Brain\Monkey\Functions;
use Mockery;
use Pontifex\Job\Job;
use Pontifex\Job\JobStore;
use Pontifex\Lock\OperationLock;
use Pontifex\Tests\TestCase;
use Pontifex\WordPress\WordPressContext;

/**
 * Covers the acquire() ordering, the asymmetric reclaim policy, and the
 * release()/is_held()/current_holder() bookkeeping.
 *
 * The named database lock itself is mocked here — its real mutual exclusion
 * across connections is proven separately, against a real MySQL server, by
 * {@see \Pontifex\Tests\Integration\NamedLockTest} and the new integration
 * coverage for this class. What is worth a unit test is OperationLock's OWN
 * decision logic: the three-step ordering, which holder kinds are reclaimed
 * and which never are, and that a refusal always hands the named lock back.
 * The holder transient is faked with an in-memory array shared by every
 * get_transient()/set_transient()/delete_transient() call in a test, so two
 * OperationLock instances in the same test genuinely see each other's state —
 * exactly as two real requests would, via the real transient store.
 */
final class OperationLockTest extends TestCase {

	/**
	 * Temporary content directory a test's JobStore is rooted at.
	 *
	 * @var string
	 */
	private string $base = '';

	/**
	 * The in-memory fake transient store shared by every stubbed transient call.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = array();

	/**
	 * Reserve a temp content directory and stub the transient functions.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->base       = sys_get_temp_dir() . '/pontifex-operation-lock-' . uniqid( '', true );
		$this->transients = array();

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
	 * Remove the temp directory tree.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		self::rmtree( $this->base );
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
	 * The job store every lock in a test is built over.
	 *
	 * @return JobStore
	 */
	private function jobs(): JobStore {
		return new JobStore( $this->base );
	}

	/**
	 * A WordPressContext mock granting the named lock a fixed number of times.
	 *
	 * @param int $acquire_times How many times acquire_named_lock() must be called.
	 * @param int $release_times How many times release_named_lock() must be called.
	 * @return WordPressContext&Mockery\MockInterface
	 */
	private function context_granting_lock( int $acquire_times, int $release_times ) {
		$context = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'acquire_named_lock' )->times( $acquire_times )->with( OperationLock::LOCK_NAME )->andReturn( true );
		$context->shouldReceive( 'release_named_lock' )->times( $release_times )->with( OperationLock::LOCK_NAME );
		return $context;
	}

	/**
	 * A fixed clock for deterministic staleness maths.
	 *
	 * @return callable(): int
	 */
	private function fixed_clock(): callable {
		return static function (): int {
			return 1_700_000_000;
		};
	}

	// -------------------------------------------------------------------------
	// Mutual refusal, both directions.
	// -------------------------------------------------------------------------

	/**
	 * A live backup refuses a restore attempt, and hands the named lock back.
	 *
	 * The backup is kept "live" by an active export job, so the R1 guard
	 * refuses the restore before the holder transient is even consulted.
	 *
	 * @return void
	 */
	public function test_a_live_backup_refuses_a_restore_and_hands_back_the_named_lock(): void {
		$context = $this->context_granting_lock( 2, 1 );
		$now     = $this->fixed_clock();

		$backup_lock = new OperationLock( $context, $this->jobs(), $now );
		$this->assertTrue( $backup_lock->acquire( OperationLock::OP_BACKUP ), 'The first acquire must succeed on a free lock.' );

		// The backup is job-backed and still active — R1 guard territory.
		$this->jobs()->create( Job::KIND_EXPORT, array(), 1_700_000_000 );

		$restore_lock = new OperationLock( $context, $this->jobs(), $now );
		$this->assertFalse( $restore_lock->acquire( OperationLock::OP_RESTORE ), 'A restore must be refused while the backup is genuinely live.' );
		$this->assertFalse( $restore_lock->is_held() );
	}

	/**
	 * A restore holder refuses a backup attempt, and hands the named lock back.
	 *
	 * @return void
	 */
	public function test_a_restore_holder_refuses_a_backup_and_hands_back_the_named_lock(): void {
		$context = $this->context_granting_lock( 2, 1 );
		$now     = $this->fixed_clock();

		$restore_lock = new OperationLock( $context, $this->jobs(), $now );
		$this->assertTrue( $restore_lock->acquire( OperationLock::OP_RESTORE ), 'The first acquire must succeed on a free lock.' );

		$backup_lock = new OperationLock( $context, $this->jobs(), $now );
		$this->assertFalse( $backup_lock->acquire( OperationLock::OP_BACKUP ), 'A backup must be refused while a restore holds the lock.' );
		$this->assertFalse( $backup_lock->is_held() );
	}

	// -------------------------------------------------------------------------
	// Reclaim policy.
	// -------------------------------------------------------------------------

	/**
	 * A dead backup's holder is reclaimed: no active job, no live progress.
	 *
	 * @return void
	 */
	public function test_a_dead_backup_holder_is_reclaimed(): void {
		$context = $this->context_granting_lock( 2, 0 );
		$now     = $this->fixed_clock();

		$dead_lock = new OperationLock( $context, $this->jobs(), $now );
		$this->assertTrue( $dead_lock->acquire( OperationLock::OP_BACKUP ) );
		// Simulate the crash: this instance never releases, so the transient
		// is left standing as a "backup" holder with no active job behind it.

		$restore_lock = new OperationLock( $context, $this->jobs(), $now );
		$this->assertTrue( $restore_lock->acquire( OperationLock::OP_RESTORE ), 'A dead backup holder must be reclaimed — no live signal backs it.' );
		$this->assertTrue( $restore_lock->is_held() );
	}

	/**
	 * A dead backup's holder is also reclaimed when its progress transient has
	 * simply gone stale (not just absent).
	 *
	 * @return void
	 */
	public function test_a_dead_backup_holder_with_stale_progress_is_reclaimed(): void {
		$context = $this->context_granting_lock( 1, 0 );
		$now     = $this->fixed_clock();

		$this->transients[ OperationLock::LOCK_NAME ] = array(
			'kind' => OperationLock::OP_BACKUP,
			'at'   => 1_700_000_000,
		);
		// A progress transient far older than the staleness floor — the writer died.
		$this->transients['pontifex_backup_progress'] = array(
			'phase' => 'copying',
			'at'    => 1_700_000_000 - 60,
		);

		$lock = new OperationLock( $context, $this->jobs(), $now );
		$this->assertTrue( $lock->acquire( OperationLock::OP_RESTORE ), 'Stale progress with no active job must not count as live.' );
	}

	/**
	 * A restore holder is never reclaimed within its TTL, whatever the job state.
	 *
	 * @return void
	 */
	public function test_a_restore_holder_is_never_reclaimed(): void {
		$context = $this->context_granting_lock( 2, 1 );
		$now     = $this->fixed_clock();

		$restore_lock = new OperationLock( $context, $this->jobs(), $now );
		$this->assertTrue( $restore_lock->acquire( OperationLock::OP_RESTORE ) );

		$backup_lock = new OperationLock( $context, $this->jobs(), $now );
		$this->assertFalse( $backup_lock->acquire( OperationLock::OP_BACKUP ), 'A restore holder must never be auto-reclaimed.' );
	}

	/**
	 * A rollback holder is never reclaimed within its TTL either.
	 *
	 * @return void
	 */
	public function test_a_rollback_holder_is_never_reclaimed(): void {
		$context = $this->context_granting_lock( 2, 1 );
		$now     = $this->fixed_clock();

		$rollback_lock = new OperationLock( $context, $this->jobs(), $now );
		$this->assertTrue( $rollback_lock->acquire( OperationLock::OP_ROLLBACK ) );

		$backup_lock = new OperationLock( $context, $this->jobs(), $now );
		$this->assertFalse( $backup_lock->acquire( OperationLock::OP_BACKUP ), 'A rollback holder must never be auto-reclaimed.' );
	}

	// -------------------------------------------------------------------------
	// The R1 guard: a live backup outranks even an EXPIRED or absent holder transient.
	// -------------------------------------------------------------------------

	/**
	 * A long, job-backed backup stays protected even once its holder transient
	 * has expired — any kind of acquire is refused, not only a backup's own.
	 *
	 * @return void
	 */
	public function test_an_active_job_refuses_any_acquire_even_with_no_holder_transient(): void {
		$this->jobs()->create( Job::KIND_EXPORT, array(), 1_700_000_000 );
		// No holder transient at all — as if its 900-second TTL had already passed.
		$this->assertArrayNotHasKey( OperationLock::LOCK_NAME, $this->transients );

		$context = $this->context_granting_lock( 2, 2 );
		$now     = $this->fixed_clock();

		$restore_lock = new OperationLock( $context, $this->jobs(), $now );
		$this->assertFalse( $restore_lock->acquire( OperationLock::OP_RESTORE ), 'An active export job must refuse a restore regardless of the holder transient.' );

		$rollback_lock = new OperationLock( $context, $this->jobs(), $now );
		$this->assertFalse( $rollback_lock->acquire( OperationLock::OP_ROLLBACK ), 'An active export job must refuse a rollback regardless of the holder transient.' );
	}

	// -------------------------------------------------------------------------
	// The named lock itself.
	// -------------------------------------------------------------------------

	/**
	 * When the named lock is held elsewhere, acquire() refuses without ever
	 * touching the holder transient.
	 *
	 * @return void
	 */
	public function test_acquire_refuses_when_the_named_lock_is_held_elsewhere(): void {
		$context = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'acquire_named_lock' )->once()->with( OperationLock::LOCK_NAME )->andReturn( false );
		$context->shouldNotReceive( 'release_named_lock' );

		$lock = new OperationLock( $context, $this->jobs(), $this->fixed_clock() );
		$this->assertFalse( $lock->acquire( OperationLock::OP_BACKUP ) );
		$this->assertFalse( $lock->is_held() );
		// Asserted through the fake transient store rather than a second
		// Functions\expect() on set_transient: setUp()'s file-wide when()
		// stub for that function would swallow a later expect() on it
		// (a known brain/monkey interaction — see BackupControllerTest).
		$this->assertArrayNotHasKey( OperationLock::LOCK_NAME, $this->transients, 'A refusal before the named lock is granted must never write the holder transient.' );
	}

	// -------------------------------------------------------------------------
	// release(), is_held(), current_holder().
	// -------------------------------------------------------------------------

	/**
	 * Release() clears the holder transient and releases the named lock, and
	 * is_held() reflects only this instance's own acquire/release lifecycle.
	 *
	 * @return void
	 */
	public function test_release_clears_the_transient_and_the_named_lock(): void {
		$context = $this->context_granting_lock( 1, 1 );
		$lock    = new OperationLock( $context, $this->jobs(), $this->fixed_clock() );

		$this->assertFalse( $lock->is_held(), 'is_held() must be false before any acquire.' );

		$this->assertTrue( $lock->acquire( OperationLock::OP_BACKUP ) );
		$this->assertTrue( $lock->is_held() );
		$this->assertArrayHasKey( OperationLock::LOCK_NAME, $this->transients );

		$lock->release();

		$this->assertFalse( $lock->is_held(), 'is_held() must be false after release().' );
		$this->assertArrayNotHasKey( OperationLock::LOCK_NAME, $this->transients, 'release() must clear the holder transient.' );
	}

	/**
	 * A release() when the lock was never acquired is a no-op: it never touches
	 * the transient or the named lock.
	 *
	 * The guard is what makes release() safe to call twice — once from a normal
	 * finally, once from a shutdown-handler backstop — without the second call
	 * clearing a transient another operation may have taken over the same name
	 * in the meantime.
	 *
	 * @return void
	 */
	public function test_release_on_a_lock_never_acquired_is_a_no_op(): void {
		$context = Mockery::mock( WordPressContext::class );
		$context->shouldNotReceive( 'release_named_lock' );

		$lock = new OperationLock( $context, $this->jobs(), $this->fixed_clock() );
		$this->assertFalse( $lock->is_held() );

		$lock->release();

		$this->assertFalse( $lock->is_held() );
		$this->assertArrayNotHasKey( OperationLock::LOCK_NAME, $this->transients, 'A release() on a never-acquired lock must never write or delete the holder transient.' );
	}

	/**
	 * A second release() after a clean one is also a no-op: the transient and
	 * named lock are only ever touched once per genuine acquire.
	 *
	 * @return void
	 */
	public function test_a_second_release_after_a_clean_one_is_a_no_op(): void {
		$context = $this->context_granting_lock( 1, 1 );
		$lock    = new OperationLock( $context, $this->jobs(), $this->fixed_clock() );

		$this->assertTrue( $lock->acquire( OperationLock::OP_BACKUP ) );

		$lock->release();
		$this->assertFalse( $lock->is_held() );

		// A second release must not call release_named_lock() again — the mock's
		// times(1) expectation on release_named_lock() would fail verification at
		// tearDown() if it did.
		$lock->release();
		$this->assertFalse( $lock->is_held() );
	}

	/**
	 * Current_holder() reads the transient's recorded kind, from any instance.
	 *
	 * @return void
	 */
	public function test_current_holder_reports_the_recorded_kind(): void {
		$context = Mockery::mock( WordPressContext::class );
		$lock    = new OperationLock( $context, $this->jobs(), $this->fixed_clock() );

		$this->assertNull( $lock->current_holder(), 'No holder is recorded when nothing is running.' );

		$this->transients[ OperationLock::LOCK_NAME ] = array(
			'kind' => OperationLock::OP_ROLLBACK,
			'at'   => 1_700_000_000,
		);

		$this->assertSame( OperationLock::OP_ROLLBACK, $lock->current_holder() );
	}
}
