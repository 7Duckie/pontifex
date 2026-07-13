<?php
/**
 * Pontifex admin backup controller — the AJAX endpoints behind the Backup screen.
 *
 * @package Pontifex\Admin
 */

declare(strict_types=1);

namespace Pontifex\Admin;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use Pontifex\Archive\Format\Scope;
use Pontifex\Cli\TransferHistory;
use Pontifex\Environment\Environment;
use Pontifex\Export\ExportOptions;
use Pontifex\Export\ExportRunner;
use Pontifex\Export\ResumableExportRunner;
use Pontifex\Job\Job;
use Pontifex\Job\JobStore;
use Pontifex\Manifest\ExclusionRules;
use Pontifex\Manifest\ManifestBuilderInterface;
use Pontifex\Manifest\ScanProgressManifestBuilder;
use Pontifex\Schedule\JobTicker;
use Pontifex\Schedule\Schedule;
use Pontifex\Schedule\ScheduleStore;
use Pontifex\WordPress\WordPressContext;
use Psr\Log\LoggerInterface;

/**
 * Handles the four admin-ajax actions that drive the Backup screen.
 *
 * The Backup page is static HTML; the work happens here, over WordPress's
 * `admin-ajax.php` endpoint, so a minute-long export never blocks a page load:
 *
 *  - {@see self::create()} runs one export to completion, writing live progress
 *    to a transient the browser polls.
 *  - {@see self::progress()} returns that transient so the page can show a count.
 *  - {@see self::download()} streams a finished backup to the operator.
 *  - {@see self::delete()} removes a backup the operator no longer wants.
 *
 * Every action is deny-by-default: it requires the {@see Menu::CAPABILITY}
 * capability **and** a valid `pontifex_backup` nonce before doing anything, and
 * the download and delete actions additionally route the requested filename
 * through {@see BackupStore::resolve()} so a crafted name cannot read or delete a
 * file outside the backups directory. The actions are registered only as
 * `wp_ajax_` (logged-in) hooks, never `wp_ajax_nopriv_`.
 *
 * The export itself reuses {@see ExportRunner} — the same engine the CLI and the
 * safety archiver use — so the admin path produces a byte-identical archive. The
 * counters and history the Overview screen reads are updated here too, so an
 * admin backup is a first-class export (the read-modify-write mirrors
 * ExportCommand's; unifying the three copies is a candidate later tidy).
 */
final class BackupController {

	/**
	 * The nonce action shared by every Backup endpoint and the page's links.
	 *
	 * @var string
	 */
	public const NONCE_ACTION = 'pontifex_backup';

	/**
	 * The transient key holding the in-progress export's running count.
	 *
	 * @var string
	 */
	private const PROGRESS_TRANSIENT = 'pontifex_backup_progress';

	/**
	 * The transient key marking that a backup is currently running.
	 *
	 * The secondary single-runner guard: the primary is an atomic named
	 * database lock (this constant doubles as its logical name), and this
	 * transient is checked only while that lock is held — see
	 * {@see self::acquire_lock()}. create() refuses a second backup while
	 * either guard is engaged, so two concurrent exports can never fight
	 * over the progress transient. Carries a TTL so a crash that skips the
	 * shutdown handler still self-heals.
	 *
	 * @var string
	 */
	private const LOCK_TRANSIENT = 'pontifex_backup_lock';

	/**
	 * How long the progress transient lives, in seconds (15 minutes).
	 *
	 * A literal rather than MINUTE_IN_SECONDS so the class is testable without
	 * WordPress loaded; comfortably longer than any single export.
	 *
	 * @var int
	 */
	private const PROGRESS_TTL = 900;

	/**
	 * Progress phase: walking the filesystem to enumerate entries; the count climbs while the total is still unknown.
	 *
	 * @var string
	 */
	private const PHASE_SCANNING = 'scanning';

	/**
	 * Progress phase: writing the enumerated entries into the archive; determinate.
	 *
	 * @var string
	 */
	private const PHASE_COPYING = 'copying';

	/**
	 * Progress phase reported when no backup is running (no progress transient set).
	 *
	 * @var string
	 */
	private const PHASE_IDLE = 'idle';

	/**
	 * Age, in seconds, past which a progress transient is distrusted while a job is live.
	 *
	 * The transient is refreshed several times a second by the request driving
	 * the backup, so one older than this while an active job exists on disk
	 * means that request died — its last write must not be served as live
	 * progress (the "stuck bar" failure), and the job's persisted cursors
	 * answer instead. Generous against slow ticks: the runner also refreshes
	 * the job payload every few seconds of work.
	 *
	 * @var int
	 */
	private const PROGRESS_STALE_SECONDS = 10;

	/**
	 * Minimum interval, in seconds, between progress transient writes.
	 *
	 * Progress is refreshed at most this often rather than once per entry, which
	 * keeps option writes bounded while still moving the bar a few times a second —
	 * so it creeps continuously even through a slow patch (e.g. large early files)
	 * instead of appearing to stall.
	 *
	 * @var float
	 */
	private const PROGRESS_THROTTLE_SECONDS = 0.3;

	/**
	 * The wp_options key holding the export counters (mirrors ExportCommand).
	 *
	 * @var string
	 */
	private const STATS_OPTION = 'pontifex_export_stats';

	/**
	 * Mode applied to a written backup: owner read/write only.
	 *
	 * Defence in depth; the backups directory is already created 0700, so this is
	 * best-effort and a failure does not discard an otherwise good backup.
	 *
	 * @var int
	 */
	private const FILE_MODE = 0600;

	/**
	 * PHP error types that are fatal and uncatchable, so they bypass the try/catch.
	 *
	 * A run that ends on one of these (out of memory, an exceeded time limit) never
	 * reaches the catch in {@see self::create()}; {@see self::handle_shutdown()}
	 * cleans up after it instead.
	 *
	 * @var int[]
	 */
	private const FATAL_ERROR_TYPES = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR );

	/**
	 * The Environment abstraction (constants and PHP version).
	 *
	 * @var Environment
	 */
	private Environment $environment;

	/**
	 * The WordPressContext abstraction (provenance facts, counters, formatting).
	 *
	 * @var WordPressContext
	 */
	private WordPressContext $wordpress_context;

	/**
	 * The store the backup is written to, listed from, and resolved against.
	 *
	 * @var BackupStore
	 */
	private BackupStore $store;

	/**
	 * PSR-3 logger; a failed backup's real cause is recorded here so the
	 * "check the Pontifex log" message it shows the operator is honest.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * The manifest builder used to enumerate entries.
	 *
	 * Optional: when null, create() wires the default scanner-backed builder over
	 * the v0.1.0 exclusions. Tests inject a fake so the handler can be exercised
	 * without scanning a real installation.
	 *
	 * @var ManifestBuilderInterface|null
	 */
	private ?ManifestBuilderInterface $manifest_builder;

	/**
	 * Absolute path of the backup currently being written, or null when idle.
	 *
	 * Set once the destination path is known and cleared when the run ends, so the
	 * shutdown handler knows whether a fatal left a partial archive to remove.
	 *
	 * @var string|null
	 */
	private ?string $active_backup_path = null;

	/**
	 * Wall-clock budget per tick when this controller drives the job (seconds).
	 *
	 * Every tick pays the resume contract's fixed overhead — a fresh scan and
	 * a progress-log replay (ADR 0015) — so short ticks multiply that cost
	 * across the run; the browser gate measured a large backup paying the
	 * scan roughly nine times. Sixty seconds keeps the overhead to a few
	 * ticks per run, and costs nothing in safety: a killed request's work is
	 * healed and continued by resume, so the budget bounds re-done work, not
	 * lost data.
	 *
	 * @var float
	 */
	private const TICK_BUDGET_SECONDS = 60.0;

	/**
	 * The job the running backup persists through, while this request drives it.
	 *
	 * Null outside create(). Lets the shutdown handler mark the job failed on a
	 * fatal (so the single-active-job slot never wedges) and the cleanup helper
	 * find the job's temp archive.
	 *
	 * @var Job|null
	 */
	private ?Job $active_job = null;

	/**
	 * Whether this request holds the single-runner backup lock.
	 *
	 * Set when create() acquires the lock and cleared when it releases it, so the
	 * shutdown handler knows whether a fatal left the lock (and a partial) behind.
	 *
	 * @var bool
	 */
	private bool $lock_held = false;

	/**
	 * Construct the controller around its collaborators.
	 *
	 * @param Environment                   $environment       Constant and PHP-version reads.
	 * @param WordPressContext              $wordpress_context Provenance facts, counters, and size formatting.
	 * @param BackupStore                   $store             The backups directory.
	 * @param LoggerInterface               $logger            Records a failed backup's real cause.
	 * @param ManifestBuilderInterface|null $manifest_builder  Optional. When null, a default scanner-backed builder is used.
	 */
	public function __construct(
		Environment $environment,
		WordPressContext $wordpress_context,
		BackupStore $store,
		LoggerInterface $logger,
		?ManifestBuilderInterface $manifest_builder = null
	) {
		$this->environment       = $environment;
		$this->wordpress_context = $wordpress_context;
		$this->store             = $store;
		$this->logger            = $logger;
		$this->manifest_builder  = $manifest_builder;
	}

	/**
	 * Run one backup to completion, reporting progress through a transient.
	 *
	 * The `wp_ajax_pontifex_create_backup` handler. Refuses without capability and
	 * nonce, lifts the time limit where allowed, then builds the entry list, runs
	 * the export into the backups directory, and reports success as JSON. Progress
	 * is written to a transient as the archive is written so {@see self::progress()}
	 * can report it. The export counters and transfer history are updated so the
	 * Overview screen reflects the backup.
	 *
	 * @return void
	 * @throws RuntimeException Caught by this method's own handler; raised when the
	 *                          job record disappears mid-run (the tick loop's guard).
	 * @throws BackupCancelled  Caught by this method's own handler; raised at a tick
	 *                          boundary when the operator requested cancellation.
	 */
	public function create(): void {
		if ( ! $this->is_authorised() ) {
			wp_send_json_error( array( 'message' => $this->unauthorised_message() ), 403 );
		}

		// Read the operator's extra exclusion patterns and validate them at this
		// boundary. A malformed regex would otherwise only throw deep inside a tick's
		// scan and fail the backup mid-run; refusing it here keeps the failure at the
		// click, before any lock is taken or work begins.
		$user_patterns = $this->read_user_exclusions();
		$invalid       = self::first_invalid_pattern( $user_patterns );
		if ( null !== $invalid ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: the exclusion pattern that could not be understood */
						__( 'That exclusion pattern is not valid: %s. Fix or remove it and try again.', 'pontifex' ),
						$invalid
					),
				),
				400
			);
		}

		// Single-runner lock: refuse a second backup while one is already running, so
		// two concurrent exports can never fight over the shared progress transient.
		if ( ! $this->acquire_lock() ) {
			wp_send_json_error( array( 'message' => __( 'A backup is already running. Please wait for it to finish.', 'pontifex' ) ), 409 );
		}

		$this->extend_time_limit();
		$this->store->ensure_directory();
		// Drop any stale cancel sentinel a crashed run may have left, so this backup
		// is not cancelled before it starts.
		$this->store->clear_cancel();
		$this->bump_counters( array( 'attempted' => 1 ) );

		// A fatal error — out of memory, an exceeded time limit — cannot be caught,
		// so the catch below never runs for it: PHP would die mid-write and leave a
		// partial archive in the store, unlogged. Register a shutdown handler that
		// removes the partial file and records the real reason if that happens.
		register_shutdown_function(
			function (): void {
				$this->handle_shutdown();
			}
		);

		try {
			$this->set_scan_progress( 0 );

			// The admin backup is always content-only: it scans wp-content and records
			// each file under a "wp-content" path prefix, with a content-only scope in
			// provenance (ADR 0008). A whole-site clone stays a CLI-only operation.
			// There is deliberately NO pre-scan here: each tick's own scan (ADR 0015)
			// is the only walk, with the screen's scanning counter and mid-scan cancel
			// carried into it by the decorated builder, and the byte total persisted
			// onto the job by the runner as soon as the first scan knows it. The old
			// duplicate pre-scan doubled the wait before the first byte was written.
			// The curated defaults always apply; the operator's validated patterns are
			// appended and travel into the job payload, so re-attach and cron
			// continuation honour them too.
			$exclusions = ExclusionRules::from_array(
				array_merge( ExclusionRules::default_v010()->patterns(), $user_patterns )
			);

			$path                     = $this->store->next_backup_path( new DateTimeImmutable() );
			$this->active_backup_path = $path;

			// The backup runs as a persisted job (ADR 0014/0015): every tick saves
			// its progress, so a request the host kills mid-run leaves a continuable
			// job on disk instead of nothing, and the page can re-attach to it.
			$job_store = $this->jobs_store();
			// Whatever builder runs — injected double or the real scanner — it is
			// decorated with the screen's scan callback: the scanning counter and,
			// critically, mid-scan cancellation ride inside every tick's scan.
			$factory = function ( ExclusionRules $rules, string $path_prefix ): ManifestBuilderInterface {
				$inner = $this->manifest_builder ?? ExportRunner::default_manifest_builder( $this->wordpress_context, $rules, $path_prefix );
				return new ScanProgressManifestBuilder( $inner, $this->scan_progress_callback() );
			};
			$runner  = new ResumableExportRunner( $this->environment, $this->wordpress_context, $job_store, $factory );
			$options = new ExportOptions( $path, null, null, null, Scope::content_only( $exclusions->patterns() ) );

			$job              = $runner->start( $options, $this->resolve_content_root(), 'wp-content', $exclusions->patterns(), time() );
			$this->active_job = $job;

			// Insurance for a request the host kills outright (no shutdown handler
			// runs): a cron tick will find the still-active job and continue it
			// unattended. A clean finish, cancel, or failure clears the event.
			wp_schedule_single_event( time() + JobTicker::RESCHEDULE_DELAY_SECONDS, JobTicker::CRON_HOOK );

			// One byte callback for the WHOLE run, so the reported count accumulates
			// across ticks. A per-tick callback restarted its tally from zero every
			// tick; the browser's never-go-backwards clamp masked that as a bar that
			// stalled at the highest tick's count.
			$total_bytes = 0;
			$byte_cb     = $this->accumulating_byte_callback( $job_store, $job->id(), $total_bytes );

			$done = false;
			while ( ! $done ) {
				// The cancel sentinel is honoured at tick boundaries: the job is
				// terminal-marked before cleanup so the active slot frees correctly.
				if ( $this->store->is_cancel_requested() ) {
					$current = $job_store->get( $job->id() );
					if ( null !== $current && $current->is_active() ) {
						$current->mark( Job::STATUS_CANCELLED, time() );
						$job_store->save( $current );
					}
					throw new BackupCancelled();
				}
				$current = $job_store->get( $job->id() );
				if ( null === $current ) {
					throw new RuntimeException( 'BackupController: the backup job record disappeared mid-run.' );
				}
				$total_bytes = max( $total_bytes, $this->counter_int( $current->payload(), 'total_bytes' ) );
				$done        = $runner->tick( $current, self::TICK_BUDGET_SECONDS, null, null, null, $byte_cb );
			}

			$finished      = $job_store->get( $job->id() );
			$job_payload   = null !== $finished ? $finished->payload() : array();
			$bytes_written = (int) ( $job_payload['bytes_written'] ?? 0 );
			$total_bytes   = max( $total_bytes, $this->counter_int( $job_payload, 'total_bytes' ) );
			$entry_count   = count( $job_store->progress_log( $job->id() )->read_all() );
			$job_store->delete( $job->id() );
			$this->active_job = null;
			wp_clear_scheduled_hook( JobTicker::CRON_HOOK );

			$this->secure_file( $path );
			$this->clear_progress();
			$this->active_backup_path = null;
			$this->release_lock();
			$this->store->clear_cancel();

			$this->bump_counters(
				array(
					'succeeded'      => 1,
					'bytes_exported' => $bytes_written,
				)
			);
			TransferHistory::record( $this->wordpress_context, 'export', 'succeeded', $bytes_written, gmdate( 'c' ) );

			wp_send_json_success(
				array(
					'filename'     => basename( $path ),
					'entries'      => $entry_count,
					'bytes'        => $bytes_written,
					'size'         => $this->wordpress_context->format_size( $bytes_written ),
					'source_bytes' => $total_bytes,
				)
			);
		} catch ( BackupCancelled $cancelled ) {
			// Cancellation is not a failure: remove the partial archive and the job,
			// release the lock, and report it as cancelled. The attempted counter
			// stays bumped, but neither succeeded nor failed is recorded.
			$this->cleanup_job_artefacts();
			$this->delete_partial_backup();
			$this->active_backup_path = null;
			$this->release_lock();
			$this->clear_progress();
			$this->store->clear_cancel();
			wp_send_json_success( array( 'cancelled' => true ) );
		} catch ( Throwable $error ) {
			$this->logger->error( 'Admin backup failed.', array( 'exception' => $error ) );
			$this->cleanup_job_artefacts();
			$this->delete_partial_backup();
			$this->active_backup_path = null;
			$this->release_lock();
			$this->clear_progress();
			$this->store->clear_cancel();
			$this->bump_counters( array( 'failed' => 1 ) );
			TransferHistory::record( $this->wordpress_context, 'export', 'failed', 0, gmdate( 'c' ) );
			wp_send_json_error( array( 'message' => $this->failure_message( $error ) ), 500 );
		}
	}

	/**
	 * The job store, rooted at the same content directory as the backup store.
	 *
	 * Derived from the injected store rather than re-resolving WP_CONTENT_DIR,
	 * so the two stores can never diverge — in production they are identical,
	 * and in tests both live under the test's own fixture root.
	 *
	 * @return JobStore The job store.
	 */
	private function jobs_store(): JobStore {
		return new JobStore( dirname( $this->store->directory(), 2 ) );
	}

	/**
	 * Remove the active job's temp archive and records after a cancel or failure.
	 *
	 * Best-effort by design: cleanup must never mask the outcome being reported.
	 * The job is already terminal (the runner marks failed on a throwing tick;
	 * the cancel path marks cancelled), so deleting the records simply keeps the
	 * jobs directory tidy — a skipped deletion would be swept by the store's TTL
	 * cleanup anyway.
	 *
	 * @return void
	 */
	private function cleanup_job_artefacts(): void {
		if ( null === $this->active_job ) {
			return;
		}
		wp_clear_scheduled_hook( JobTicker::CRON_HOOK );
		try {
			$job_store = $this->jobs_store();
			$current   = $job_store->get( $this->active_job->id() );
			$payload   = null !== $current ? $current->payload() : $this->active_job->payload();
			$temp      = isset( $payload['temp'] ) ? (string) $payload['temp'] : '';
			if ( '' !== $temp && is_file( $temp ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort removal of the job's partial archive; a failure must not mask the outcome being reported.
				@unlink( $temp );
			}
			$job_store->delete( $this->active_job->id() );
		} catch ( Throwable $cleanup_error ) {
			$this->logger->error( 'Backup job cleanup failed.', array( 'exception' => $cleanup_error ) );
		}
		$this->active_job = null;
	}

	/**
	 * Remove a partial archive and record the cause when a fatal error ended a run.
	 *
	 * Registered with register_shutdown_function() at the start of {@see create()}.
	 * A fatal — out of memory, an exceeded time limit — cannot be caught, so the
	 * try/catch in create() never runs and PHP dies after the destination file was
	 * opened, leaving a half-written archive. On shutdown, when a backup was still
	 * in progress and the request ended on a fatal, this logs the real reason and
	 * removes the partial file and releases the lock. A clean run, or one that
	 * failed with a catchable exception, has already released the lock, so this
	 * does nothing.
	 *
	 * @return void
	 */
	public function handle_shutdown(): void {
		if ( ! $this->lock_held ) {
			return;
		}

		$last_error = error_get_last();
		if ( ! is_array( $last_error ) || ! in_array( $last_error['type'], self::FATAL_ERROR_TYPES, true ) ) {
			return;
		}

		$this->logger->error(
			'Admin backup ended on a fatal error; cleaning up the partial archive.',
			array( 'error' => $last_error['message'] )
		);
		// Mark the job failed so the single-active-job slot frees immediately
		// instead of waiting for the store's abandonment sweep; the job's temp
		// stays on disk deliberately — it is what a future resume could adopt,
		// and the store's TTL cleanup removes it with the failed record.
		if ( null !== $this->active_job ) {
			try {
				$job_store = $this->jobs_store();
				$current   = $job_store->get( $this->active_job->id() );
				if ( null !== $current && $current->is_active() ) {
					$current->mark( Job::STATUS_FAILED, time() );
					$job_store->save( $current );
				}
			} catch ( Throwable $cleanup_error ) {
				$this->logger->error( 'Backup job shutdown cleanup failed.', array( 'exception' => $cleanup_error ) );
			}
			$this->active_job = null;
		}
		$this->delete_partial_backup();
		$this->active_backup_path = null;
		$this->release_lock();
		$this->store->clear_cancel();
	}

	/**
	 * Delete the in-progress backup file if one was left on disk.
	 *
	 * Best-effort: a failure to remove the partial archive must not mask the error
	 * that caused the backup to fail, so a missing file or an unlink failure is
	 * ignored.
	 *
	 * @return void
	 */
	private function delete_partial_backup(): void {
		if ( null === $this->active_backup_path ) {
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort removal of a partial archive after a failed backup; a failure (including a missing file) must not mask the original error.
		@unlink( $this->active_backup_path );
	}

	/**
	 * Report the in-progress export's running progress.
	 *
	 * The `wp_ajax_pontifex_backup_progress` handler, polled by the page while a
	 * backup runs. Returns the scanning file count and the copying byte counts the
	 * running export writes, or zeroes when none is recorded.
	 *
	 * @return void
	 */
	public function progress(): void {
		if ( ! $this->is_authorised() ) {
			wp_send_json_error( array( 'message' => $this->unauthorised_message() ), 403 );
		}

		$progress = get_transient( self::PROGRESS_TRANSIENT );
		$progress = is_array( $progress ) ? $progress : array();

		$phase = isset( $progress['phase'] ) && is_string( $progress['phase'] ) ? $progress['phase'] : self::PHASE_IDLE;

		$job = ( $this->jobs_store() )->active_job();
		if ( null !== $job && Job::KIND_EXPORT !== $job->kind() ) {
			$job = null;
		}

		// The transient only lives honestly while a request is actively rewriting
		// it. Two situations mean the job's persisted cursors must answer instead:
		// no transient at all (this page was reloaded after the writer finished
		// its TTL, or never saw one), or a transient that has stopped being
		// refreshed while a job is still live on disk — the writing request died,
		// and serving its last value would freeze the bar at a lie for the rest
		// of the transient's TTL.
		$transient_stale = self::PHASE_IDLE !== $phase
			&& ( time() - $this->counter_int( $progress, 'at' ) ) > self::PROGRESS_STALE_SECONDS;

		// One response, one send: the answer is decided first and sent once, so
		// the endpoint's behaviour never depends on wp_send_json_success halting.
		if ( null !== $job && ( self::PHASE_IDLE === $phase || $transient_stale ) ) {
			$payload = $job->payload();
			// Source bytes so the bar speaks the same units as the live byte
			// callback; older in-flight jobs without the cursor degrade to the
			// compressed count rather than to zero.
			$bytes_done = $this->counter_int( $payload, 'source_bytes_done' );
			if ( 0 === $bytes_done ) {
				$bytes_done = $this->counter_int( $payload, 'bytes_written' );
			}
			$response = array(
				'phase'       => self::PHASE_COPYING,
				'done'        => 0,
				'bytes_done'  => $bytes_done,
				'bytes_total' => $this->counter_int( $payload, 'total_bytes' ),
			);
		} else {
			$response = array(
				'phase'       => $phase,
				'done'        => $this->counter_int( $progress, 'done' ),
				'bytes_done'  => $this->counter_int( $progress, 'bytes_done' ),
				'bytes_total' => $this->counter_int( $progress, 'bytes_total' ),
			);
		}
		if ( null !== $job ) {
			// Whatever answered, a live job's start time rides along so a page
			// that re-attached mid-run can show elapsed time.
			$response['started_at'] = $job->created_at();
		}
		wp_send_json_success( $response );
	}

	/**
	 * Stream a finished backup to the operator as a download.
	 *
	 * The `wp_ajax_pontifex_download_backup` handler. Refuses without capability
	 * and nonce, and serves a file only when {@see BackupStore::resolve()} turns
	 * the requested name into a real backup in the backups directory — so the
	 * archive is never reachable by a public URL and the filename can never escape
	 * that directory.
	 *
	 * @return void
	 */
	public function download(): void {
		if ( ! $this->is_authorised() ) {
			$this->deny_download();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The nonce is verified in is_authorised() above; this only reads the filename to validate.
		$requested = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( (string) $_GET['file'] ) ) : '';

		$path = $this->store->resolve( $requested );
		if ( null === $path ) {
			$this->deny_download();
		}

		$this->extend_time_limit();
		$this->stream_download( (string) $path );
	}

	/**
	 * Delete a backup the operator chose to remove.
	 *
	 * The `wp_ajax_pontifex_delete_backup` handler. Refuses without capability and
	 * nonce, and removes a file only when {@see BackupStore::delete()} (via
	 * resolve()) confirms it is a real backup in the directory.
	 *
	 * @return void
	 */
	public function delete(): void {
		if ( ! $this->is_authorised() ) {
			wp_send_json_error( array( 'message' => $this->unauthorised_message() ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The nonce is verified in is_authorised() above; this only reads the filename to validate.
		$requested = isset( $_POST['file'] ) ? sanitize_file_name( wp_unslash( (string) $_POST['file'] ) ) : '';

		if ( $this->store->delete( $requested ) ) {
			wp_send_json_success();
		} else {
			wp_send_json_error( array( 'message' => __( 'That backup could not be deleted.', 'pontifex' ) ), 404 );
		}
	}

	/**
	 * Ask the running backup to stop.
	 *
	 * The `wp_ajax_pontifex_cancel_backup` handler. Refuses without capability and
	 * nonce, then writes the cancel sentinel the running export polls for; the
	 * export unwinds cooperatively at its next progress checkpoint and reports the
	 * backup as cancelled. Returns immediately — the operator's create request is
	 * what resolves once the export has stopped.
	 *
	 * @return void
	 */
	public function cancel(): void {
		if ( ! $this->is_authorised() ) {
			wp_send_json_error( array( 'message' => $this->unauthorised_message() ), 403 );
		}

		$this->store->ensure_directory();
		$this->store->request_cancel();
		wp_send_json_success();
	}

	/**
	 * Save the periodic-backup schedule from the Backup screen's form.
	 *
	 * The `wp_ajax_pontifex_save_schedule` handler. Refuses without capability
	 * and nonce, validates the submitted fields through the {@see Schedule}
	 * value object (an out-of-range hour or unknown frequency is refused, and
	 * retention is clamped up to its floor), then persists through
	 * {@see ScheduleStore::save()} — the same store the CLI uses, and the single
	 * choke point that keeps the recurring cron event in step with the stored
	 * settings. Responds with the next run time so the page can confirm it.
	 *
	 * @return void
	 */
	public function save_schedule(): void {
		if ( ! $this->is_authorised() ) {
			wp_send_json_error( array( 'message' => $this->unauthorised_message() ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The nonce is verified in is_authorised() above; these only read the schedule fields to validate.
		$enabled = isset( $_POST['enabled'] ) && '1' === sanitize_text_field( wp_unslash( (string) $_POST['enabled'] ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The nonce is verified in is_authorised() above.
		$frequency = isset( $_POST['frequency'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['frequency'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The nonce is verified in is_authorised() above.
		$hour = isset( $_POST['hour'] ) && is_numeric( wp_unslash( (string) $_POST['hour'] ) ) ? (int) wp_unslash( (string) $_POST['hour'] ) : -1;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The nonce is verified in is_authorised() above.
		$retention = isset( $_POST['retention'] ) && is_numeric( wp_unslash( (string) $_POST['retention'] ) ) ? (int) wp_unslash( (string) $_POST['retention'] ) : 0;

		// The scheduled backup carries the same exclusion patterns as a manual one,
		// validated at this boundary so a malformed regex is refused at save rather
		// than failing every unattended run.
		$exclusions = $this->read_user_exclusions();
		$invalid    = self::first_invalid_pattern( $exclusions );
		if ( null !== $invalid ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: the exclusion pattern that could not be understood */
						__( 'That exclusion pattern is not valid: %s. Fix or remove it and try again.', 'pontifex' ),
						$invalid
					),
				),
				400
			);
		}

		try {
			$schedule = new Schedule( $enabled, $frequency, $hour, $retention, $exclusions );
		} catch ( InvalidArgumentException $invalid_schedule ) {
			wp_send_json_error( array( 'message' => __( 'The schedule could not be saved. Reload the page and try again.', 'pontifex' ) ), 400 );
		}

		$now = time();
		$this->schedules()->save( $schedule, $now );

		wp_send_json_success(
			array(
				'enabled'  => $schedule->is_enabled(),
				'next_run' => $schedule->is_enabled() ? gmdate( 'Y-m-d H:i', ScheduleStore::next_occurrence( $schedule, $now ) ) . ' UTC' : '',
			)
		);
	}

	/**
	 * The schedule store over this controller's context.
	 *
	 * Constructed inline (the {@see self::jobs_store()} pattern) so the
	 * controller's constructor stays stable; the store is deterministic over
	 * the injected context, so tests control it through the same seam.
	 *
	 * @return ScheduleStore The schedule store.
	 */
	private function schedules(): ScheduleStore {
		return new ScheduleStore( $this->wordpress_context );
	}

	/**
	 * Read the operator's extra exclusion patterns from the create request.
	 *
	 * One pattern per line (a textarea), with blank lines and `#` comments
	 * dropped — the same shape the CLI's --exclude-file accepts. The nonce is
	 * verified in {@see self::is_authorised()} before create() calls this.
	 *
	 * @return string[] The submitted patterns, trimmed, blanks and comments removed.
	 */
	private function read_user_exclusions(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The nonce is verified in is_authorised() at the top of create() before this runs.
		if ( ! isset( $_POST['exclusions'] ) ) {
			return array();
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The nonce is verified in is_authorised() at the top of create() before this runs.
		$raw   = sanitize_textarea_field( wp_unslash( (string) $_POST['exclusions'] ) );
		$lines = preg_split( '/\R/', $raw );
		if ( false === $lines ) {
			return array();
		}
		$patterns = array();
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' !== $line && '#' !== $line[0] ) {
				$patterns[] = $line;
			}
		}
		return $patterns;
	}

	/**
	 * Return the first exclusion pattern that cannot even be compiled, or null.
	 *
	 * Only a regex-shaped pattern (delimited with `/`) can fail to parse; a glob,
	 * directory-tree, or exact pattern is always usable. An unparseable regex
	 * would otherwise surface only mid-scan inside a tick and fail the backup
	 * partway, so it is rejected here at the submit boundary. This catches a
	 * pattern that will not compile, not one that compiles but later exhausts a
	 * PCRE limit on a pathological input — that residual case still fails closed
	 * (the shutdown handler removes the partial archive), it just fails later.
	 *
	 * @param string[] $patterns The patterns to check.
	 * @return string|null The first uncompilable pattern, or null when all parse.
	 */
	private static function first_invalid_pattern( array $patterns ): ?string {
		foreach ( $patterns as $pattern ) {
			if ( strlen( $pattern ) >= 2 && '/' === $pattern[0] && '/' === $pattern[ strlen( $pattern ) - 1 ] ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A malformed pattern makes preg_match emit a warning and return false; the @ turns that into the boolean this validates on, which is the point.
				if ( false === @preg_match( $pattern, '' ) ) {
					return $pattern;
				}
			}
		}
		return null;
	}

	// -------------------------------------------------------------------------
	// Authorisation.
	// -------------------------------------------------------------------------

	/**
	 * Whether the current request is a permitted Backup action.
	 *
	 * Deny-by-default: the user must hold the managing capability and present a
	 * valid `pontifex_backup` nonce. Both halves must pass.
	 *
	 * @return bool True when the request is authorised.
	 */
	private function is_authorised(): bool {
		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			return false;
		}
		return false !== check_ajax_referer( self::NONCE_ACTION, '_wpnonce', false );
	}

	/**
	 * End an unauthorised download with a 403 and no file.
	 *
	 * @return void
	 */
	private function deny_download(): void {
		status_header( 403 );
		wp_die( esc_html__( 'You do not have permission to download this backup.', 'pontifex' ), '', array( 'response' => 403 ) );
	}

	/**
	 * The message returned when an action is refused.
	 *
	 * @return string A human-readable refusal message.
	 */
	private function unauthorised_message(): string {
		return __( 'You do not have permission to manage backups, or your session has expired. Reload the page and try again.', 'pontifex' );
	}

	/**
	 * The message returned when an export fails.
	 *
	 * The underlying error is recorded in the log via the export path; the
	 * operator sees a plain sentence rather than an exception string.
	 *
	 * @param Throwable $error The failure (used only to keep the signature honest; not echoed).
	 * @return string A human-readable failure message.
	 */
	private function failure_message( Throwable $error ): string {
		unset( $error );
		return __( 'The backup could not be completed. Check the Pontifex log for details.', 'pontifex' );
	}

	// -------------------------------------------------------------------------
	// Export wiring.
	// -------------------------------------------------------------------------

	/**
	 * Resolve the wp-content root for the content-only file scan.
	 *
	 * Reads WP_CONTENT_DIR through the Environment abstraction — the directory
	 * WordPress actually serves wp-content from, which a site may have relocated —
	 * and falls back to ABSPATH/wp-content (WordPress's own default for the constant)
	 * when it is not defined, so the resolver still works outside a full WordPress
	 * request, as in unit tests.
	 *
	 * @return string The absolute path of the wp-content directory.
	 * @throws RuntimeException If WP_CONTENT_DIR is undefined and ABSPATH is too (should never happen in an admin request).
	 */
	private function resolve_content_root(): string {
		if ( $this->environment->is_constant_defined( 'WP_CONTENT_DIR' ) ) {
			return rtrim( (string) $this->environment->constant_value( 'WP_CONTENT_DIR' ), '/' );
		}
		if ( ! $this->environment->is_constant_defined( 'ABSPATH' ) ) {
			throw new RuntimeException( 'BackupController: neither WP_CONTENT_DIR nor ABSPATH is defined; is WordPress loaded?' );
		}
		return rtrim( (string) $this->environment->constant_value( 'ABSPATH' ), '/' ) . '/wp-content';
	}

	/**
	 * Build the run-long byte-progress callback that writes the throttled transient.
	 *
	 * Handed to every tick of the run: the export feeds it each chunk's raw
	 * source byte count, which it accumulates ACROSS ticks and reports against
	 * the total. Reporting bytes rather than entries keeps the bar advancing
	 * smoothly through a single large file instead of freezing at its boundary.
	 * The denominator arrives lazily: the first tick's scan persists the byte
	 * total onto the job, so until it is known the callback re-reads the job at
	 * the throttle cadence — a bounded handful of small file reads, never one
	 * per chunk. The transient is refreshed at most once every
	 * {@see self::PROGRESS_THROTTLE_SECONDS} to keep option writes bounded.
	 *
	 * @param JobStore $job_store   The job store the total is read from.
	 * @param string   $job_id      The running job's id.
	 * @param int      $total_bytes Reference to the caller's running total estimate.
	 * @return callable(int): void The byte callback to hand to every tick.
	 */
	private function accumulating_byte_callback( JobStore $job_store, string $job_id, int &$total_bytes ): callable {
		$last       = 0.0;
		$bytes_done = 0;
		return function ( int $bytes ) use ( &$last, &$bytes_done, &$total_bytes, $job_store, $job_id ): void {
			$bytes_done += $bytes;
			$now         = microtime( true );
			if ( ( $now - $last ) >= self::PROGRESS_THROTTLE_SECONDS ) {
				if ( 0 === $total_bytes ) {
					$job = $job_store->get( $job_id );
					if ( null !== $job ) {
						$total_bytes = $this->counter_int( $job->payload(), 'total_bytes' );
					}
				}
				$this->set_copy_progress( $bytes_done, $total_bytes );
				$last = $now;
				$this->throw_if_cancelled();
			}
		};
	}

	/**
	 * Build the scan-phase progress callback that writes the throttled transient.
	 *
	 * The total is unknown while the filesystem is being walked, so this reports a
	 * climbing count with total left at zero; the browser renders it as the
	 * indeterminate "scanning" phase. Throttled by time (at most once every
	 * {@see self::PROGRESS_THROTTLE_SECONDS}) to keep option writes bounded.
	 *
	 * @return callable(int): void The callback to hand to {@see \Pontifex\Manifest\ManifestBuilderInterface::build()}.
	 */
	private function scan_progress_callback(): callable {
		$last = 0.0;
		return function ( int $scanned ) use ( &$last ): void {
			$now = microtime( true );
			if ( ( $now - $last ) >= self::PROGRESS_THROTTLE_SECONDS ) {
				$this->set_scan_progress( $scanned );
				$last = $now;
				$this->throw_if_cancelled();
			}
		};
	}

	/**
	 * Throw {@see BackupCancelled} when the operator has requested a cancel.
	 *
	 * Called from the scan and copy progress callbacks at their throttle point, so
	 * a cancel is observed within {@see self::PROGRESS_THROTTLE_SECONDS} — including
	 * partway through a single large file, where a per-entry check could not fire.
	 *
	 * @return void
	 * @throws BackupCancelled When a cancel has been requested for this backup.
	 */
	private function throw_if_cancelled(): void {
		if ( $this->store->is_cancel_requested() ) {
			throw new BackupCancelled();
		}
	}

	/**
	 * Restrict a written backup to owner-only, best-effort.
	 *
	 * @param string $path Absolute path of the backup just written.
	 * @return void
	 */
	private function secure_file( string $path ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Restricting a plugin-owned backup (it holds the whole database); the 0700 directory is the primary guard, so this is best-effort.
		chmod( $path, self::FILE_MODE );
	}

	/**
	 * Lift the script time limit for the duration of a long operation, where allowed.
	 *
	 * @return void
	 */
	private function extend_time_limit(): void {
		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,Squiz.PHP.DiscouragedFunctions.Discouraged -- A long backup must outlive the host's web timeout, the accepted pattern for backup tooling; set_time_limit can be disabled by the host, so the call is best-effort and its failure must not abort the backup.
			@set_time_limit( 0 );
		}
	}

	// -------------------------------------------------------------------------
	// Progress transient.
	// -------------------------------------------------------------------------

	/**
	 * Write the scanning-phase progress to the transient.
	 *
	 * The total is unknown while the filesystem is walked, so only the climbing
	 * file count is reported; the browser renders it as the indeterminate phase.
	 *
	 * @param int $scanned Files enumerated so far.
	 * @return void
	 */
	private function set_scan_progress( int $scanned ): void {
		set_transient(
			self::PROGRESS_TRANSIENT,
			array(
				'phase' => self::PHASE_SCANNING,
				'done'  => $scanned,
				'at'    => time(),
			),
			self::PROGRESS_TTL
		);
	}

	/**
	 * Write the copying-phase byte progress to the transient.
	 *
	 * Reports the bytes processed against the estimated total so the browser can
	 * fill a determinate bar that advances within a large entry, not only between
	 * entries.
	 *
	 * @param int $bytes_done  Source bytes processed so far.
	 * @param int $bytes_total Estimated total source bytes.
	 * @return void
	 */
	private function set_copy_progress( int $bytes_done, int $bytes_total ): void {
		set_transient(
			self::PROGRESS_TRANSIENT,
			array(
				'phase'       => self::PHASE_COPYING,
				'bytes_done'  => $bytes_done,
				'bytes_total' => $bytes_total,
				'at'          => time(),
			),
			self::PROGRESS_TTL
		);
	}

	/**
	 * Clear the progress transient once a run ends (success or failure).
	 *
	 * @return void
	 */
	private function clear_progress(): void {
		delete_transient( self::PROGRESS_TRANSIENT );
	}

	/**
	 * Acquire the single-runner backup lock, or report that a backup is already running.
	 *
	 * Two independent guards, and both must pass. The primary is a named
	 * database lock ({@see WordPressContext::acquire_named_lock()}): the
	 * server grants it atomically, so two simultaneous requests can never
	 * both acquire — closing the check-then-set race a transient alone
	 * cannot — and it vanishes with the connection if the request crashes.
	 * The transient stays as a second, independent guard behind it: checked
	 * only while the named lock is held, its old race is gone, and it still
	 * refuses a run in the rare case a runner's named lock is silently lost
	 * mid-operation (on old MySQL, other code taking its own named lock on
	 * the same connection releases ours).
	 *
	 * @return bool True if the lock was acquired; false if a backup is already running.
	 */
	private function acquire_lock(): bool {
		if ( ! $this->wordpress_context->acquire_named_lock( self::LOCK_TRANSIENT ) ) {
			return false;
		}
		if ( false !== get_transient( self::LOCK_TRANSIENT ) ) {
			// A concurrent runner, or a crashed run's transient still inside its
			// TTL: refuse, and hand back the named lock just taken.
			$this->wordpress_context->release_named_lock( self::LOCK_TRANSIENT );
			return false;
		}
		set_transient( self::LOCK_TRANSIENT, time(), self::PROGRESS_TTL );
		$this->lock_held = true;
		return true;
	}

	/**
	 * Release the single-runner backup lock held by this request.
	 *
	 * @return void
	 */
	private function release_lock(): void {
		delete_transient( self::LOCK_TRANSIENT );
		$this->wordpress_context->release_named_lock( self::LOCK_TRANSIENT );
		$this->lock_held = false;
	}

	// -------------------------------------------------------------------------
	// Counters and streaming.
	// -------------------------------------------------------------------------

	/**
	 * Read-modify-write the stored export counters by a delta.
	 *
	 * Mirrors ExportCommand's counter handling so an admin backup updates the same
	 * Overview figures as a CLI export.
	 *
	 * @param array<string, int> $delta The amounts to add, keyed by counter name.
	 * @return void
	 */
	private function bump_counters( array $delta ): void {
		$defaults = array(
			'attempted'      => 0,
			'succeeded'      => 0,
			'failed'         => 0,
			'bytes_exported' => 0,
		);

		$current = $this->wordpress_context->option_value( self::STATS_OPTION, $defaults );
		$current = is_array( $current ) ? $current : array();

		$merged = array();
		foreach ( array_keys( $defaults ) as $key ) {
			$merged[ $key ] = $this->counter_int( $current, $key ) + $this->counter_int( $delta, $key );
		}

		$this->wordpress_context->save_option( self::STATS_OPTION, $merged );
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

	/**
	 * Stream a resolved backup file to the client as an attachment, then exit.
	 *
	 * @param string $path Absolute path of a backup already validated by the store.
	 * @return void
	 */
	private function stream_download( string $path ): void {
		$filename = basename( $path );

		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filesize -- Reporting the size of a plugin-owned backup for the download; WP_Filesystem has no streaming equivalent.
		$size = filesize( $path );
		if ( false !== $size ) {
			header( 'Content-Length: ' . (string) $size );
		}

		// Drop any buffering so a large archive streams rather than being held in memory.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming a plugin-owned backup to the authenticated operator; WP_Filesystem has no streaming reader.
		readfile( $path );
		exit;
	}
}
