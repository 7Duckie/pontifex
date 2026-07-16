<?php
/**
 * Pontifex scheduled exporter — starts the periodic backup when its cron event fires.
 *
 * @package Pontifex\Schedule
 */

declare(strict_types=1);

namespace Pontifex\Schedule;

use DateTimeImmutable;
use Throwable;
use Pontifex\Admin\BackupStore;
use Pontifex\Archive\Format\Scope;
use Pontifex\Cli\TransferHistory;
use Pontifex\Environment\Environment;
use Pontifex\Export\ExportOptions;
use Pontifex\Export\ResumableExportRunner;
use Pontifex\Job\JobStore;
use Pontifex\Lock\OperationLock;
use Pontifex\Manifest\ExclusionRules;
use Pontifex\WordPress\WordPressContext;
use Psr\Log\LoggerInterface;

/**
 * The `pontifex_scheduled_export` handler: start the periodic backup as a job.
 *
 * This class only STARTS the job — content-only, default exclusions, into
 * the backup store, marked as schedule-originated so completion prunes to
 * the retention count — and then hands the ticking to {@see JobTicker},
 * the one place cron-driven work runs. If another operation is already
 * running — an active job exists, or the shared {@see OperationLock} is held
 * by a backup, restore, or rollback anywhere else (admin or CLI) — the
 * scheduled run skips with a log line rather than queueing: the next
 * occurrence will try again, which for a periodic backup is the honest
 * behaviour.
 *
 * The shared lock is held only long enough to start the job, not for the
 * whole run: once the job is active, {@see OperationLock}'s own
 * backup-liveness check protects it across every cron tick that follows, the
 * same way a request-driven admin backup's job survives between ticks. The
 * ticker that then drives the job to completion takes its own, per-tick
 * named lock, so releasing here before calling it avoids the two ever
 * contending for the same lock within one invocation.
 */
final class ScheduledExporter {

	/**
	 * The Environment abstraction.
	 *
	 * @var Environment
	 */
	private Environment $environment;

	/**
	 * The WordPressContext abstraction.
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
	 * The backup store the archive lands in.
	 *
	 * @var BackupStore
	 */
	private BackupStore $backup_store;

	/**
	 * The ticker that runs the started job.
	 *
	 * @var JobTicker
	 */
	private JobTicker $ticker;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * Construct a ScheduledExporter.
	 *
	 * @param Environment      $environment       The environment seam.
	 * @param WordPressContext $wordpress_context The WordPress seam.
	 * @param JobStore         $job_store         The job store.
	 * @param BackupStore      $backup_store      The backup store.
	 * @param JobTicker        $ticker            The ticker that will drive the job.
	 * @param LoggerInterface  $logger            The logger.
	 */
	public function __construct( Environment $environment, WordPressContext $wordpress_context, JobStore $job_store, BackupStore $backup_store, JobTicker $ticker, LoggerInterface $logger ) {
		$this->environment       = $environment;
		$this->wordpress_context = $wordpress_context;
		$this->job_store         = $job_store;
		$this->backup_store      = $backup_store;
		$this->ticker            = $ticker;
		$this->logger            = $logger;
	}

	/**
	 * The cron handler: start the periodic backup and drive it.
	 *
	 * @return void
	 */
	public function run(): void {
		$schedule = ( new ScheduleStore( $this->wordpress_context ) )->load();
		if ( ! $schedule->is_enabled() ) {
			return;
		}

		if ( null !== $this->job_store->active_job() ) {
			$this->logger->info( 'Scheduled backup skipped: another operation is already running.' );
			return;
		}

		// The shared single-runner lock: refuses to start while a restore or
		// rollback is running anywhere (admin or CLI), not only another backup.
		// Released again below, as soon as the job exists — see the class
		// docblock for why this instance does not hold it for the whole run.
		$lock = $this->operation_lock();
		if ( ! $lock->acquire( OperationLock::OP_BACKUP ) ) {
			$this->logger->info( 'Scheduled backup skipped: another operation is already running.' );
			return;
		}

		try {
			$this->backup_store->ensure_directory();
			// The scheduled backup applies the same exclusions the operator configured,
			// on top of the curated defaults, so a scheduled run matches a manual one
			// rather than silently differing.
			$exclusions = ExclusionRules::from_array(
				array_merge( ExclusionRules::default_v010()->patterns(), $schedule->exclusions() )
			);
			$path       = $this->backup_store->next_backup_path( new DateTimeImmutable() );
			$options    = new ExportOptions( $path, null, null, null, Scope::content_only( $exclusions->patterns() ) );

			$content_dir = rtrim( dirname( $this->backup_store->directory(), 2 ), '/' );
			$runner      = new ResumableExportRunner( $this->environment, $this->wordpress_context, $this->job_store );
			$job         = $runner->start( $options, $content_dir, 'wp-content', $exclusions->patterns(), time() );

			// Mark the job schedule-originated so completion prunes to retention.
			$payload             = $job->payload();
			$payload['schedule'] = true;
			$job->set_payload( $payload );
			$this->job_store->save( $job );

			$this->bump_attempted();
			$this->logger->info( 'Scheduled backup started.', array( 'output' => $path ) );
		} catch ( Throwable $error ) {
			$this->logger->error( 'Scheduled backup could not start.', array( 'exception' => $error ) );
			TransferHistory::record( $this->wordpress_context, 'export', 'failed', 0, gmdate( 'c' ) );
			return;
		} finally {
			$lock->release();
		}

		$this->ticker->run();
	}

	/**
	 * Build the shared OperationLock over this exporter's own collaborators.
	 *
	 * @return OperationLock The lock to acquire before starting the job.
	 */
	private function operation_lock(): OperationLock {
		return new OperationLock( $this->wordpress_context, $this->job_store );
	}

	/**
	 * Count the attempt, mirroring every other export path.
	 *
	 * @return void
	 */
	private function bump_attempted(): void {
		$current = $this->wordpress_context->option_value( 'pontifex_export_stats', array() );
		$current = is_array( $current ) ? $current : array();
		$merged  = array();
		foreach ( array( 'attempted', 'succeeded', 'failed', 'bytes_exported', 'files_changed' ) as $key ) {
			$merged[ $key ] = isset( $current[ $key ] ) && is_numeric( $current[ $key ] ) ? (int) $current[ $key ] : 0;
		}
		++$merged['attempted'];
		$this->wordpress_context->save_option( 'pontifex_export_stats', $merged );
	}
}
