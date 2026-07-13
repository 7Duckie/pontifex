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
	 * @var float
	 */
	private const TICK_BUDGET_SECONDS = 20.0;

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

		try {
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
				return;
			}
			// Budget spent, work remains: hand over to the next invocation.
			$this->reschedule();
		} catch ( Throwable $error ) {
			// The runner marked the job failed; record the failure the way every
			// export path does and stop — a failed job is not rescheduled.
			$this->logger->error( 'Cron-driven backup failed.', array( 'exception' => $error ) );
			$this->bump_counters( array( 'failed' => 1 ) );
			TransferHistory::record( $this->wordpress_context, 'export', 'failed', 0, gmdate( 'c' ) );
		} finally {
			$this->wordpress_context->release_named_lock( self::LOCK_NAME );
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
