<?php
/**
 * Pontifex operation lock — the single-runner guard shared by every site-mutating operation.
 *
 * @package Pontifex\Lock
 */

declare(strict_types=1);

namespace Pontifex\Lock;

use Pontifex\Job\Job;
use Pontifex\Job\JobStore;
use Pontifex\WordPress\WordPressContext;

/**
 * Guards backup, restore, and rollback behind one shared single-runner lock.
 *
 * Before this class, the admin Backup and Restore screens each held their own,
 * independent lock, and the CLI held none at all — so a CLI export could run
 * happily alongside an admin restore, each unaware the other was rewriting the
 * site. Every SITE-MUTATING operation (backup/export, restore/import,
 * rollback — admin and CLI alike) now acquires this one lock before doing any
 * work. Verify stays on its own, separate lock: it only reads, so it can never
 * collide with a write and has no reason to queue behind one.
 *
 * Two independent guards, and both must pass to acquire:
 *
 *  - The primary is an atomic named database lock
 *    ({@see WordPressContext::acquire_named_lock()}), granted by the server to
 *    exactly one connection, so two simultaneous requests can never both
 *    acquire it — the check-then-set race a transient alone cannot close. It
 *    vanishes with the connection if the request crashes, which is why a
 *    second guard exists at all: a job-backed backup spans many requests
 *    (cron ticks), each opening and closing its own connection, so the named
 *    lock is NOT held for the whole run — only for as long as one tick's
 *    connection is open.
 *  - The secondary is a "holder" transient recording which kind of operation
 *    (backup, restore, or rollback) currently holds the lock and when it took
 *    it. It is what a request between ticks, or a request of a different
 *    kind entirely, actually checks: the named lock being free right now does
 *    not mean nothing is running.
 *
 * {@see self::acquire()} checks three things, IN THIS ORDER, and the order is
 * load-bearing:
 *
 *  1. The named lock — closes the simultaneous-request race.
 *  2. Whether a backup is genuinely still live (an active export job, or a
 *     synchronous run's fresh progress) — the R1 guard. This runs BEFORE the
 *     holder transient is consulted, because the transient carries a fixed
 *     TTL ({@see self::TTL}) that a long, job-backed backup can outlive
 *     between cron ticks: if the transient alone were trusted, a restore
 *     could slip in during that gap and start overwriting the site while the
 *     backup is still genuinely running. Anchoring liveness on the active job
 *     instead means a long backup stays protected for as long as its job
 *     stays active, however many ticks that takes.
 *  3. The holder transient itself, for everything the job check does not
 *     cover (a synchronous restore or rollback, or a backup between the
 *     moment it starts and the moment its job exists).
 *
 * Reclaim policy is asymmetric by design ({@see self::is_reclaimable()}): a
 * dead BACKUP holder is reclaimed automatically (step 2 already proved no
 * backup is genuinely live, so a leftover "backup" transient can only be a
 * crashed run's stale record) — but a RESTORE or ROLLBACK holder is NEVER
 * auto-reclaimed within its TTL, however old it looks. A restore that died
 * mid-write may have left the site in a broken, half-written state; treating
 * its lock as free would let a second restore or rollback start layering more
 * writes onto a site nobody has confirmed is safe to touch. Failing
 * conservative here — making the operator wait out the TTL, or investigate —
 * is the only sound default for a tool that promises never to make things
 * worse.
 */
final class OperationLock {

	/**
	 * The holder kind recorded for a backup or export.
	 *
	 * @var string
	 */
	public const OP_BACKUP = 'backup';

	/**
	 * The holder kind recorded for a restore or import.
	 *
	 * @var string
	 */
	public const OP_RESTORE = 'restore';

	/**
	 * The holder kind recorded for a rollback.
	 *
	 * @var string
	 */
	public const OP_ROLLBACK = 'rollback';

	/**
	 * The shared lock's logical name — both the GET_LOCK name and the holder
	 * transient's key.
	 *
	 * One name for both halves of the guard keeps them impossible to drift
	 * apart: every caller acquiring or releasing this lock, whatever
	 * operation it is running, contends for exactly this one name.
	 *
	 * @var string
	 */
	public const LOCK_NAME = 'pontifex_site_operation';

	/**
	 * How long the holder transient lives, in seconds (15 minutes).
	 *
	 * A literal rather than MINUTE_IN_SECONDS so the class is testable without
	 * WordPress loaded; comfortably longer than any single synchronous run. A
	 * job-backed backup that outlives this TTL stays protected regardless, by
	 * the active-job check in {@see self::backup_is_live()} — see the class
	 * docblock's ordering note.
	 *
	 * @var int
	 */
	private const TTL = 900;

	/**
	 * The transient key holding the running backup's progress.
	 *
	 * Owned by the backup screen's progress reporting, not by this class; read
	 * here only as a liveness signal for a synchronous (non-job-backed) run
	 * that has not yet — or will never — become an active job.
	 *
	 * @var string
	 */
	private const BACKUP_PROGRESS_KEY = 'pontifex_backup_progress';

	/**
	 * Age, in seconds, past which the backup progress transient is distrusted.
	 *
	 * Mirrors the admin Backup controller's own staleness floor: refreshed
	 * several times a second by the request driving the backup, so a value
	 * older than this while no active job exists means that request has died.
	 *
	 * @var int
	 */
	private const PROGRESS_STALE_SECONDS = 10;

	/**
	 * Progress phase reported when no backup is running.
	 *
	 * @var string
	 */
	private const PHASE_IDLE = 'idle';

	/**
	 * The WordPressContext abstraction, for the named database lock.
	 *
	 * @var WordPressContext
	 */
	private WordPressContext $wordpress_context;

	/**
	 * The job store, for the active-job liveness check.
	 *
	 * @var JobStore
	 */
	private JobStore $job_store;

	/**
	 * Resolves "now" as a Unix timestamp.
	 *
	 * Injected so tests can pin the clock deterministically; defaults to the
	 * real time.
	 *
	 * @var callable(): int
	 */
	private $now;

	/**
	 * Whether THIS instance currently holds the lock.
	 *
	 * Set by a successful {@see self::acquire()} and cleared by
	 * {@see self::release()}, so a caller can tell whether it — specifically —
	 * is the one that must clean up on a fatal shutdown.
	 *
	 * @var bool
	 */
	private bool $held = false;

	/**
	 * Construct an OperationLock around its collaborators.
	 *
	 * @param WordPressContext $wordpress_context The context supplying the named database lock.
	 * @param JobStore         $job_store         The job store the active-job liveness check reads.
	 * @param callable(): int  $now               Optional. Resolves "now" as a Unix timestamp; defaults to the real clock.
	 */
	public function __construct( WordPressContext $wordpress_context, JobStore $job_store, ?callable $now = null ) {
		$this->wordpress_context = $wordpress_context;
		$this->job_store         = $job_store;
		$this->now               = $now ?? static function (): int {
			return time();
		};
	}

	/**
	 * Acquire the shared lock for the given kind of operation.
	 *
	 * See the class docblock for why the three checks run in exactly this
	 * order. Any refusal after the named lock was taken hands it back
	 * immediately, so a request that fails to acquire never leaves the named
	 * lock lingering behind it.
	 *
	 * @param string $kind One of {@see self::OP_BACKUP}, {@see self::OP_RESTORE}, {@see self::OP_ROLLBACK}.
	 * @return bool True if the lock was acquired; false if another operation already holds it.
	 */
	public function acquire( string $kind ): bool {
		if ( ! $this->wordpress_context->acquire_named_lock( self::LOCK_NAME ) ) {
			return false;
		}

		if ( $this->backup_is_live() ) {
			// A job-backed backup between cron ticks: the named lock above was free
			// (no request currently holds a connection open for it), but the job
			// itself is still active, so this must refuse regardless of what the
			// holder transient says — it may already have expired.
			$this->wordpress_context->release_named_lock( self::LOCK_NAME );
			return false;
		}

		$holder = get_transient( self::LOCK_NAME );
		if ( is_array( $holder ) && ! $this->is_reclaimable( $holder ) ) {
			$this->wordpress_context->release_named_lock( self::LOCK_NAME );
			return false;
		}

		set_transient(
			self::LOCK_NAME,
			array(
				'kind' => $kind,
				'at'   => ( $this->now )(),
			),
			self::TTL
		);
		$this->held = true;
		return true;
	}

	/**
	 * Release the lock this instance holds.
	 *
	 * Clears the holder transient and hands back the named lock. Idempotent,
	 * guarded on {@see self::is_held()}: a second call — the finally in a CLI
	 * command followed by its shutdown-handler backstop, say — does nothing on
	 * the second call, so it can never clear a transient another operation has
	 * since taken over the same name. Safe to call even when this instance
	 * never held the lock in the first place, for the same reason.
	 *
	 * @return void
	 */
	public function release(): void {
		if ( ! $this->held ) {
			return;
		}
		delete_transient( self::LOCK_NAME );
		$this->wordpress_context->release_named_lock( self::LOCK_NAME );
		$this->held = false;
	}

	/**
	 * Whether THIS instance currently holds the lock.
	 *
	 * @return bool True when the most recent {@see self::acquire()} on this instance succeeded and has not since been released.
	 */
	public function is_held(): bool {
		return $this->held;
	}

	/**
	 * The kind of operation currently recorded as holding the lock, if any.
	 *
	 * Reads the holder transient regardless of which instance — or process —
	 * set it, so a refused caller can name what is blocking it (e.g. "a
	 * restore is already running").
	 *
	 * @return string|null One of the OP_* constants, or null when no holder is recorded.
	 */
	public function current_holder(): ?string {
		$holder = get_transient( self::LOCK_NAME );
		if ( ! is_array( $holder ) || ! isset( $holder['kind'] ) || ! is_string( $holder['kind'] ) ) {
			return null;
		}
		return $holder['kind'];
	}

	/**
	 * Whether a recorded holder may be reclaimed by a new acquire attempt.
	 *
	 * Only a BACKUP holder is ever reclaimable, and only because
	 * {@see self::acquire()} already proved — via {@see self::backup_is_live()}
	 * — that no backup is genuinely running before this is reached: a
	 * "backup" transient still standing at that point can only be a crashed
	 * run's leftover record. A RESTORE or ROLLBACK holder is never reclaimed
	 * here, however old it looks: a restore that died mid-write may have left
	 * the site half-written, and nothing here can tell a genuinely stuck
	 * operator (who should wait or investigate) apart from a truly dead one,
	 * so the safe default is to make both wait out the TTL.
	 *
	 * @param array<string, mixed> $holder The decoded holder transient.
	 * @return bool True when the holder may be reclaimed.
	 */
	private function is_reclaimable( array $holder ): bool {
		return isset( $holder['kind'] ) && self::OP_BACKUP === $holder['kind'];
	}

	/**
	 * Whether a backup is genuinely running right now.
	 *
	 * A job-backed export between cron ticks has released the DB-level named
	 * lock but is still live (its job is active); a synchronous run is live
	 * while it is still writing fresh progress. Anything else — no active
	 * export job and no fresh progress — is not a live backup, whatever the
	 * holder transient claims.
	 *
	 * @return bool True when a backup is currently running.
	 */
	private function backup_is_live(): bool {
		$job = $this->job_store->active_job();
		if ( null !== $job && Job::KIND_EXPORT === $job->kind() ) {
			return true;
		}

		$progress = get_transient( self::BACKUP_PROGRESS_KEY );
		if ( ! is_array( $progress ) ) {
			return false;
		}

		$phase = isset( $progress['phase'] ) && is_string( $progress['phase'] ) ? $progress['phase'] : self::PHASE_IDLE;
		if ( self::PHASE_IDLE === $phase ) {
			return false;
		}

		return ( ( $this->now )() - $this->counter_int( $progress, 'at' ) ) <= self::PROGRESS_STALE_SECONDS;
	}

	/**
	 * Read one integer from an array, defaulting to zero when absent or non-numeric.
	 *
	 * @param array<array-key, mixed> $values The array to read from.
	 * @param string                  $key    The key to read.
	 * @return int The value as an int, or 0.
	 */
	private function counter_int( array $values, string $key ): int {
		return isset( $values[ $key ] ) && is_numeric( $values[ $key ] ) ? (int) $values[ $key ] : 0;
	}
}
