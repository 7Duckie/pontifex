<?php
/**
 * Pontifex job ticker — the WP-Cron hands that continue and finish export jobs.
 *
 * @package Pontifex\Schedule
 */

declare(strict_types=1);

namespace Pontifex\Schedule;

use Throwable;
use Pontifex\Admin\BackupStore;
use Pontifex\Cli\TransferHistory;
use Pontifex\Environment\Environment;
use Pontifex\Export\ResumableExportRunner;
use Pontifex\Job\Job;
use Pontifex\Job\JobStore;
use Pontifex\WordPress\WordPressContext;
use Psr\Log\LoggerInterface;

/**
 * Ticks the active export job from WP-Cron when no request is driving it.
 *
 * The unattended half of ADR 0014: a scheduled export's whole run, and the
 * continuation of a browser-started backup whose request the host killed,
 * both arrive here via the `pontifex_tick_jobs` event. The backup named
 * lock decides who drives — when a live request holds it, this handler
 * reschedules itself and leaves; when it is free but an active job exists,
 * the driving request died, and this handler takes the lock and ticks the
 * job to completion (rescheduling itself when the job outlasts its own
 * budget).
 *
 * Completion bookkeeping lives here because completion can happen here:
 * the archive is chmod-restricted like every stored backup, the export
 * counters and transfer history are updated, and a schedule-originated
 * backup prunes the store to its retention count — deleting from the
 * oldest, never below the newest {@see Schedule::MIN_RETENTION}.
 */
final class JobTicker {

	/**
	 * The single-event cron hook this ticker runs on.
	 *
	 * @var string
	 */
	public const CRON_HOOK = 'pontifex_tick_jobs';

	/**
	 * The backup lock name — shared with the admin controller by contract.
	 *
	 * @var string
	 */
	private const LOCK_NAME = 'pontifex_backup_lock';

	/**
	 * Wall-clock budget per runner tick (seconds).
	 *
	 * Sixty rather than twenty for the same reason as the admin controller:
	 * every tick pays the resume contract's fresh scan and log replay, so
	 * short ticks multiply that fixed overhead. Two ticks fit one invocation
	 * budget.
	 *
	 * @var float
	 */
	private const TICK_BUDGET_SECONDS = 60.0;

	/**
	 * Total budget for one cron invocation before rescheduling (seconds).
	 *
	 * Loopback cron runs under the host's normal PHP limits, so one
	 * invocation stays comfortably inside them and hands over to the next.
	 *
	 * @var float
	 */
	private const INVOCATION_BUDGET_SECONDS = 120.0;

	/**
	 * Delay before a rescheduled tick event fires (seconds).
	 *
	 * @var int
	 */
	public const RESCHEDULE_DELAY_SECONDS = 120;

	/**
	 * How many consecutive unclean ticker deaths a job survives before it is failed.
	 *
	 * The attempt counter is incremented when an invocation starts and reset
	 * when it ends cleanly, so it only ever accumulates across invocations
	 * that died mid-tick (a fatal: out of memory, a host kill). A job that
	 * legitimately needs many invocations resets the counter on every clean
	 * handover and never approaches this ceiling; a job whose every attempt
	 * dies the same way is failed loudly instead of crash-looping forever.
	 *
	 * @var int
	 */
	private const MAX_UNCLEAN_ATTEMPTS = 8;

	/**
	 * The Environment abstraction.
	 *
	 * @var Environment
	 */
	private Environment $environment;

	/**
	 * The WordPressContext abstraction (locks, options, site facts).
	 *
	 * @var WordPressContext
	 */
	private WordPressContext $wordpress_context;

	/**
	 * The job store.
	 *
	 * @var JobStore
	 */
	private JobStore $job_store;

	/**
	 * The backup store (for retention pruning and file securing).
	 *
	 * @var BackupStore
	 */
	private BackupStore $backup_store;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * Optional manifest-builder factory forwarded to the runner (test seam).
	 *
	 * @var callable|null
	 */
	private $manifest_builder_factory;

	/**
	 * Construct a JobTicker.
	 *
	 * @param Environment      $environment              The environment seam.
	 * @param WordPressContext $wordpress_context        The WordPress seam.
	 * @param JobStore         $job_store                The job store.
	 * @param BackupStore      $backup_store             The backup store.
	 * @param LoggerInterface  $logger                   The logger.
	 * @param callable|null    $manifest_builder_factory Optional builder factory for tests.
	 */
	public function __construct( Environment $environment, WordPressContext $wordpress_context, JobStore $job_store, BackupStore $backup_store, LoggerInterface $logger, ?callable $manifest_builder_factory = null ) {
		$this->environment              = $environment;
		$this->wordpress_context        = $wordpress_context;
		$this->job_store                = $job_store;
		$this->backup_store             = $backup_store;
		$this->logger                   = $logger;
		$this->manifest_builder_factory = $manifest_builder_factory;
	}

	/**
	 * The `pontifex_tick_jobs` handler: continue the active job, or stand down.
	 *
	 * Ordered for survival. The successor event is scheduled BEFORE any work
	 * and cleared only when this invocation ends in a decided state (job done
	 * or failed), because a fatal — a PHP timeout, out of memory, a host
	 * kill — runs neither catch nor finally: whatever this handler wants to
	 * survive its own death must already be on the calendar when the work
	 * starts. The time limit is lifted best-effort for the same reason; on
	 * hosts that refuse, the pre-scheduled successor is what carries the job
	 * to completion across short-lived invocations.
	 *
	 * @return void
	 */
	public function run(): void {
		$job = $this->job_store->active_job();
		if ( null === $job || Job::KIND_EXPORT !== $job->kind() ) {
			return;
		}

		// A held lock means a live request is driving this job right now; check
		// back later rather than fighting it for ticks.
		if ( ! $this->wordpress_context->acquire_named_lock( self::LOCK_NAME ) ) {
			$this->reschedule();
			return;
		}

		$this->extend_time_limit();

		// The dead-man's switch: with the successor already pending, a death at
		// ANY later point leaves the chain alive.
		$this->reschedule();

		try {
			// Count the attempt before working; reset only on a clean end. Only
			// consecutive unclean deaths accumulate, and past the ceiling the job
			// is failed loudly instead of crash-looping forever.
			$attempts = $this->record_attempt( $job->id() );
			if ( null === $attempts ) {
				return;
			}
			if ( $attempts > self::MAX_UNCLEAN_ATTEMPTS ) {
				$this->fail_stalled_job( $job->id(), $attempts );
				wp_clear_scheduled_hook( self::CRON_HOOK );
				return;
			}

			$runner   = new ResumableExportRunner( $this->environment, $this->wordpress_context, $this->job_store, $this->manifest_builder_factory );
			$deadline = microtime( true ) + self::INVOCATION_BUDGET_SECONDS;
			$done     = false;

			while ( ! $done && microtime( true ) < $deadline ) {
				$current = $this->job_store->get( $job->id() );
				if ( null === $current || ! $current->is_active() ) {
					return;
				}
				$done = $runner->tick( $current, self::TICK_BUDGET_SECONDS );
			}

			if ( $done ) {
				$this->finalise( $job->id() );
				// The job is decided; the pre-scheduled successor has nothing to do.
				wp_clear_scheduled_hook( self::CRON_HOOK );
				return;
			}
			// Budget spent, work remains: a clean handover to the successor that
			// is already pending. Reset the unclean-death counter.
			$this->reset_attempts( $job->id() );
		} catch ( Throwable $error ) {
			// The runner marked the job failed; record the failure the way every
			// export path does and stop — a failed job is not rescheduled, so the
			// pre-scheduled successor is cleared.
			$this->logger->error( 'Cron-driven backup failed.', array( 'exception' => $error ) );
			$this->bump_counters( array( 'failed' => 1 ) );
			TransferHistory::record( $this->wordpress_context, 'export', 'failed', 0, gmdate( 'c' ) );
			wp_clear_scheduled_hook( self::CRON_HOOK );
		} finally {
			$this->wordpress_context->release_named_lock( self::LOCK_NAME );
		}
	}

	/**
	 * Increment and persist the job's unclean-attempt counter; return the new count.
	 *
	 * @param string $job_id The active job's id.
	 * @return int|null The attempt count including this one, or null when the job vanished.
	 */
	private function record_attempt( string $job_id ): ?int {
		$job = $this->job_store->get( $job_id );
		if ( null === $job || ! $job->is_active() ) {
			return null;
		}
		$payload  = $job->payload();
		$attempts = ( isset( $payload['ticker_attempts'] ) && is_numeric( $payload['ticker_attempts'] ) ? (int) $payload['ticker_attempts'] : 0 ) + 1;

		$payload['ticker_attempts'] = $attempts;
		$job->set_payload( $payload );
		$this->job_store->save( $job );
		return $attempts;
	}

	/**
	 * Zero the job's unclean-attempt counter after a clean handover.
	 *
	 * @param string $job_id The job's id.
	 * @return void
	 */
	private function reset_attempts( string $job_id ): void {
		$job = $this->job_store->get( $job_id );
		if ( null === $job || ! $job->is_active() ) {
			return;
		}
		$payload = $job->payload();
		if ( 0 === (int) ( $payload['ticker_attempts'] ?? 0 ) ) {
			return;
		}
		$payload['ticker_attempts'] = 0;
		$job->set_payload( $payload );
		$this->job_store->save( $job );
	}

	/**
	 * Fail a job whose every continuation attempt has died mid-tick.
	 *
	 * The same bookkeeping as any failed export, plus a log line naming the
	 * real situation, so the operator learns the job was abandoned rather
	 * than finding a forever-"running" record.
	 *
	 * @param string $job_id   The stalled job's id.
	 * @param int    $attempts How many attempts have died.
	 * @return void
	 */
	private function fail_stalled_job( string $job_id, int $attempts ): void {
		$job = $this->job_store->get( $job_id );
		if ( null !== $job && $job->is_active() ) {
			$job->mark( Job::STATUS_FAILED, time() );
			$this->job_store->save( $job );
		}
		$this->logger->error(
			'Cron-driven backup abandoned: every continuation attempt died mid-tick; the host is ending the work before it can hand over.',
			array( 'attempts' => $attempts )
		);
		$this->bump_counters( array( 'failed' => 1 ) );
		TransferHistory::record( $this->wordpress_context, 'export', 'failed', 0, gmdate( 'c' ) );
	}

	/**
	 * Lift the script time limit for the duration of the invocation, where allowed.
	 *
	 * @return void
	 */
	private function extend_time_limit(): void {
		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,Squiz.PHP.DiscouragedFunctions.Discouraged -- A cron continuation must outlive the host's web timeout, the same posture as the admin backup; set_time_limit can be disabled by the host, so the call is best-effort and the pre-scheduled successor covers refusal.
			@set_time_limit( 0 );
		}
	}

	/**
	 * Schedule the next tick event.
	 *
	 * @return void
	 */
	private function reschedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + self::RESCHEDULE_DELAY_SECONDS, self::CRON_HOOK );
		}
	}

	/**
	 * Book-keep a completed job: secure the file, count it, prune retention.
	 *
	 * @param string $job_id The finished job's id.
	 * @return void
	 */
	private function finalise( string $job_id ): void {
		$job     = $this->job_store->get( $job_id );
		$payload = null !== $job ? $job->payload() : array();
		$output  = isset( $payload['output'] ) ? (string) $payload['output'] : '';

		if ( '' !== $output && is_file( $output ) ) {
			// The store's convention for every archive it holds: owner-only.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod,WordPress.PHP.NoSilencedErrors.Discouraged -- Restricting the finished archive like every stored backup; best-effort.
			@chmod( $output, 0600 );
		}

		$bytes_written = isset( $payload['bytes_written'] ) ? (int) $payload['bytes_written'] : 0;
		$files_changed = isset( $payload['files_changed'] ) ? (int) $payload['files_changed'] : 0;
		$this->job_store->delete( $job_id );

		$this->bump_counters(
			array(
				'succeeded'      => 1,
				'bytes_exported' => $bytes_written,
				'files_changed'  => $files_changed,
			)
		);
		TransferHistory::record( $this->wordpress_context, 'export', 'succeeded', $bytes_written, gmdate( 'c' ) );
		$this->logger->info(
			'Cron-driven backup complete.',
			array(
				'output' => $output,
				'bytes'  => $bytes_written,
			)
		);

		if ( ! empty( $payload['schedule'] ) ) {
			$this->prune_to_retention();
		}
	}

	/**
	 * Prune the backup store to the schedule's retention count, oldest first.
	 *
	 * @return void
	 */
	private function prune_to_retention(): void {
		$retention = ( new ScheduleStore( $this->wordpress_context ) )->load()->retention();
		$paths     = $this->backup_store->backups();
		if ( count( $paths ) <= $retention ) {
			return;
		}
		// backups() lists paths sorted ascending — the timestamped names make that
		// oldest-first — so everything before the newest $retention goes.
		foreach ( array_slice( $paths, 0, count( $paths ) - $retention ) as $path ) {
			$filename = basename( (string) $path );
			if ( $this->backup_store->delete( $filename ) ) {
				$this->logger->info( 'Retention pruned an old scheduled backup.', array( 'filename' => $filename ) );
			}
		}
	}

	/**
	 * Read-modify-write the shared export counters, tolerantly.
	 *
	 * @param array<string, int> $delta The amounts to add per counter.
	 * @return void
	 */
	private function bump_counters( array $delta ): void {
		$current = $this->wordpress_context->option_value( 'pontifex_export_stats', array() );
		$current = is_array( $current ) ? $current : array();
		$merged  = array();
		foreach ( array( 'attempted', 'succeeded', 'failed', 'bytes_exported', 'files_changed' ) as $key ) {
			$stored         = isset( $current[ $key ] ) && is_numeric( $current[ $key ] ) ? (int) $current[ $key ] : 0;
			$merged[ $key ] = $stored + (int) ( $delta[ $key ] ?? 0 );
		}
		$this->wordpress_context->save_option( 'pontifex_export_stats', $merged );
	}
}
