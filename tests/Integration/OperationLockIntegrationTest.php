<?php
/**
 * Integration test: OperationLock over a real MySQL named lock and real transients.
 *
 * @package Pontifex\Tests\Integration
 */

declare(strict_types=1);

namespace Pontifex\Tests\Integration;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use Pontifex\Job\Job;
use Pontifex\Job\JobStore;
use Pontifex\Lock\OperationLock;
use Pontifex\WordPress\RealWordPressContext;
use wpdb;

/**
 * Proves OperationLock's acquire()/release() ordering, reclaim policy, and
 * cross-operation refusal over the real collaborators the unit suite fakes:
 * a real MySQL GET_LOCK/RELEASE_LOCK pair (via {@see RealWordPressContext}),
 * real WordPress transients (backed by the real `wp_options` table, since
 * WordPress is fully loaded here), and a real {@see JobStore} over the
 * filesystem.
 *
 * The unit suite ({@see \Pontifex\Tests\Unit\Lock\OperationLockTest}) already
 * proves OperationLock's own decision logic against faked collaborators; what
 * only this layer can prove is that the real pieces actually cooperate the
 * way that logic assumes — the named lock genuinely round-trips through
 * RealWordPressContext, the holder transient genuinely persists and is read
 * back, and Verify's own separate lock (untouched by this slice) does not
 * collide with the shared lock's name.
 *
 * Every test releases the lock it holds in a finally block, so a failed
 * assertion can never leak a held named lock or a stale transient into a
 * later test.
 */
final class OperationLockIntegrationTest extends TestCase {

	/**
	 * Temporary content directory a test's JobStore is rooted at.
	 *
	 * @var string
	 */
	private string $content_dir = '';

	/**
	 * Reserve a temp content directory for the job store.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();
		$this->content_dir = sys_get_temp_dir() . '/pontifex-operation-lock-int-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating the integration test's temp working directory.
		mkdir( $this->content_dir, 0o755, true );
	}

	/**
	 * Clear the holder transient, hand back the shared named lock (idempotent
	 * even when this test never took it), and remove the temp directory.
	 *
	 * @return void
	 */
	protected function tear_down(): void {
		delete_transient( OperationLock::LOCK_NAME );
		( new RealWordPressContext() )->release_named_lock( OperationLock::LOCK_NAME );
		if ( '' !== $this->content_dir && is_dir( $this->content_dir ) ) {
			self::rmtree( $this->content_dir );
		}
		parent::tear_down();
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $path Absolute path to remove.
	 * @return void
	 */
	private static function rmtree( string $path ): void {
		foreach ( scandir( $path ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$full = $path . '/' . $entry;
			if ( is_dir( $full ) ) {
				self::rmtree( $full );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Integration test fixture teardown.
				unlink( $full );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Integration test fixture teardown.
		rmdir( $path );
	}

	/**
	 * A fresh OperationLock over a real named lock, real transients, and a
	 * real job store rooted at this test's content directory.
	 *
	 * @return OperationLock
	 */
	private function lock(): OperationLock {
		return new OperationLock( new RealWordPressContext(), new JobStore( $this->content_dir ) );
	}

	/**
	 * A restore holding the shared lock refuses a backup attempt, through the
	 * real named lock and the real holder transient — and a backup holding it
	 * refuses a restore in turn.
	 *
	 * @return void
	 */
	public function test_two_site_mutating_operations_refuse_each_other_through_the_real_lock(): void {
		$restore_lock = $this->lock();
		$this->assertTrue( $restore_lock->acquire( OperationLock::OP_RESTORE ), 'A restore must acquire the free shared lock.' );

		try {
			$backup_lock = $this->lock();
			$this->assertFalse( $backup_lock->acquire( OperationLock::OP_BACKUP ), 'A backup must be refused while the real restore holder stands.' );
			$this->assertFalse( $backup_lock->is_held() );

			// The real transient still names the restore as holder — untouched by
			// the refused attempt.
			$holder = get_transient( OperationLock::LOCK_NAME );
			$this->assertIsArray( $holder );
			$this->assertSame( OperationLock::OP_RESTORE, $holder['kind'] );
		} finally {
			$restore_lock->release();
		}

		// Now the other direction: a real backup holder refuses a real restore.
		$backup_lock = $this->lock();
		$this->assertTrue( $backup_lock->acquire( OperationLock::OP_BACKUP ) );

		try {
			// A job-backed liveness signal, so the R1 guard — not only the
			// holder transient — is exercised by a genuinely active job on disk.
			$jobs = new JobStore( $this->content_dir );
			$jobs->create( Job::KIND_EXPORT, array(), time() );

			$restore_lock = $this->lock();
			$this->assertFalse( $restore_lock->acquire( OperationLock::OP_RESTORE ), 'A restore must be refused while a real, job-backed backup is live.' );
		} finally {
			$backup_lock->release();
			// The job created above is this test's own fixture, not a real
			// backup; remove it so it cannot affect a later test.
			$active = ( new JobStore( $this->content_dir ) )->active_job();
			if ( null !== $active ) {
				( new JobStore( $this->content_dir ) )->delete( $active->id() );
			}
		}
	}

	/**
	 * A dead backup's holder is reclaimed through the real transient store,
	 * but a restore holder standing in exactly the same way is not.
	 *
	 * "Dead" is simulated the way {@see NamedLockTest} does: a genuinely
	 * separate second `wpdb` connection takes the named lock and writes the
	 * holder transient, then the connection is closed — MySQL releases a
	 * GET_LOCK() automatically when its owning connection closes, exactly as
	 * a crashed request's connection would drop it, so this proves the real
	 * reclaim path, not an artefact of one PHP process reacquiring its own
	 * lock.
	 *
	 * @return void
	 */
	public function test_a_dead_backup_holder_is_reclaimed_but_a_restore_holder_is_not(): void {
		$original_wpdb     = $GLOBALS['wpdb'];
		$second_connection = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$second_connection->set_prefix( $original_wpdb->prefix );

		try {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Swapping in the second connection so the "dead" acquire runs through it, mirroring NamedLockTest.
			$GLOBALS['wpdb'] = $second_connection;
			// No active job and no live progress transient anywhere in this
			// test, so backup_is_live() is false throughout.
			$dead_backup = $this->lock();
			$this->assertTrue( $dead_backup->acquire( OperationLock::OP_BACKUP ), 'The second connection must acquire the free shared lock.' );
		} finally {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the primary connection before closing the second.
			$GLOBALS['wpdb'] = $original_wpdb;
			$second_connection->close();
		}

		// The holder transient (real `wp_options` data, visible from either
		// connection) still names the backup; the connection that took the
		// named lock is now gone, so MySQL has already released it.
		$holder = get_transient( OperationLock::LOCK_NAME );
		$this->assertIsArray( $holder, 'The dead run\'s holder transient must still be standing.' );
		$this->assertSame( OperationLock::OP_BACKUP, $holder['kind'] );

		$reclaiming_lock = $this->lock();
		$this->assertTrue(
			$reclaiming_lock->acquire( OperationLock::OP_RESTORE ),
			'A dead backup holder must be reclaimed through the real transient store.'
		);

		try {
			$refused_backup = $this->lock();
			$this->assertFalse(
				$refused_backup->acquire( OperationLock::OP_BACKUP ),
				'A restore holder must never be auto-reclaimed, even through the real transient store.'
			);
		} finally {
			$reclaiming_lock->release();
		}
	}

	/**
	 * Verify's own separate lock does not collide with the shared lock a live
	 * backup holds — both are real, simultaneously-held named locks under
	 * different names.
	 *
	 * Verify is deliberately untouched by the unified-lock work (it is
	 * read-only, so it can never clash with a write); this proves that
	 * independence holds at the real-MySQL layer, not only in the source.
	 *
	 * @return void
	 */
	public function test_verify_runs_alongside_a_live_backup(): void {
		$backup_lock = $this->lock();
		$this->assertTrue( $backup_lock->acquire( OperationLock::OP_BACKUP ), 'The backup must acquire the shared lock.' );

		$context           = new RealWordPressContext();
		$verify_lock_name  = 'pontifex_verify_lock';
		$verify_lock_taken = false;
		try {
			$verify_lock_taken = $context->acquire_named_lock( $verify_lock_name );
			$this->assertTrue( $verify_lock_taken, 'Verify must acquire its own, differently-named lock even while a backup holds the shared one.' );
		} finally {
			if ( $verify_lock_taken ) {
				$context->release_named_lock( $verify_lock_name );
			}
			$backup_lock->release();
		}
	}
}
