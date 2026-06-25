<?php
/**
 * Pontifex admin backup controller — the AJAX endpoints behind the Backup screen.
 *
 * @package Pontifex\Admin
 */

declare(strict_types=1);

namespace Pontifex\Admin;

use DateTimeImmutable;
use RuntimeException;
use Throwable;
use Pontifex\Cli\TransferHistory;
use Pontifex\Environment\Environment;
use Pontifex\Export\ExportOptions;
use Pontifex\Export\ExportRunner;
use Pontifex\Manifest\ExclusionRules;
use Pontifex\Manifest\ManifestBuilderInterface;
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
	 * A single-runner lock: create() refuses a second backup while this is set, so
	 * two concurrent exports can never fight over the progress transient. Carries a
	 * TTL so a crash that skips the shutdown handler still self-heals.
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
	 */
	public function create(): void {
		if ( ! $this->is_authorised() ) {
			wp_send_json_error( array( 'message' => $this->unauthorised_message() ), 403 );
		}

		// Single-runner lock: refuse a second backup while one is already running, so
		// two concurrent exports can never fight over the shared progress transient.
		if ( ! $this->acquire_lock() ) {
			wp_send_json_error( array( 'message' => __( 'A backup is already running. Please wait for it to finish.', 'pontifex' ) ), 409 );
		}

		$this->extend_time_limit();
		$this->store->ensure_directory();
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

			$builder     = $this->manifest_builder ?? ExportRunner::default_manifest_builder( $this->wordpress_context, ExclusionRules::default_v010() );
			$entry_plans = $builder->build( $this->resolve_wordpress_root(), $this->scan_progress_callback() );
			$total_bytes = $entry_plans->estimated_bytes();

			$this->set_copy_progress( 0, $total_bytes );

			$path                     = $this->store->next_backup_path( new DateTimeImmutable() );
			$this->active_backup_path = $path;
			$runner                   = new ExportRunner( $this->environment, $this->wordpress_context );
			$result                   = $runner->export( new ExportOptions( $path ), $entry_plans, null, $this->byte_progress_callback( $total_bytes ) );

			$this->secure_file( $path );
			$this->clear_progress();
			$this->active_backup_path = null;
			$this->release_lock();

			$this->bump_counters(
				array(
					'succeeded'      => 1,
					'bytes_exported' => $result->bytes_written(),
				)
			);
			TransferHistory::record( $this->wordpress_context, 'export', 'succeeded', $result->bytes_written(), gmdate( 'c' ) );

			wp_send_json_success(
				array(
					'filename' => basename( $path ),
					'entries'  => $result->entry_count(),
					'bytes'    => $result->bytes_written(),
					'size'     => $this->wordpress_context->format_size( $result->bytes_written() ),
				)
			);
		} catch ( Throwable $error ) {
			$this->logger->error( 'Admin backup failed.', array( 'exception' => $error ) );
			$this->delete_partial_backup();
			$this->active_backup_path = null;
			$this->release_lock();
			$this->clear_progress();
			$this->bump_counters( array( 'failed' => 1 ) );
			TransferHistory::record( $this->wordpress_context, 'export', 'failed', 0, gmdate( 'c' ) );
			wp_send_json_error( array( 'message' => $this->failure_message( $error ) ), 500 );
		}
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
		$this->delete_partial_backup();
		$this->active_backup_path = null;
		$this->release_lock();
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

		wp_send_json_success(
			array(
				'phase'       => $phase,
				'done'        => $this->counter_int( $progress, 'done' ),
				'bytes_done'  => $this->counter_int( $progress, 'bytes_done' ),
				'bytes_total' => $this->counter_int( $progress, 'bytes_total' ),
			)
		);
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
	 * Resolve the WordPress installation root for the file scan.
	 *
	 * @return string The absolute path of the WordPress root.
	 * @throws RuntimeException If ABSPATH is not defined (should never happen in an admin request).
	 */
	private function resolve_wordpress_root(): string {
		if ( ! $this->environment->is_constant_defined( 'ABSPATH' ) ) {
			throw new RuntimeException( 'BackupController: ABSPATH is not defined; is WordPress loaded?' );
		}
		return rtrim( (string) $this->environment->constant_value( 'ABSPATH' ), '/' );
	}

	/**
	 * Build the copy-phase byte-progress callback that writes the throttled transient.
	 *
	 * Handed to {@see ExportRunner::export()} as its byte callback: the export feeds
	 * it each chunk's raw source byte count, which it accumulates and reports
	 * against the total. Reporting bytes rather than entries keeps the bar advancing
	 * smoothly through a single large file instead of freezing at its boundary. The
	 * transient is refreshed at most once every {@see self::PROGRESS_THROTTLE_SECONDS}
	 * to keep option writes bounded.
	 *
	 * @param int $total_bytes The estimated total source bytes — the progress denominator.
	 * @return callable(int): void The byte callback to hand to {@see ExportRunner::export()}.
	 */
	private function byte_progress_callback( int $total_bytes ): callable {
		$last       = 0.0;
		$bytes_done = 0;
		return function ( int $bytes ) use ( &$last, &$bytes_done, $total_bytes ): void {
			$bytes_done += $bytes;
			$now         = microtime( true );
			if ( ( $now - $last ) >= self::PROGRESS_THROTTLE_SECONDS ) {
				$this->set_copy_progress( $bytes_done, $total_bytes );
				$last = $now;
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
			}
		};
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
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- set_time_limit can be disabled by the host; the call is best-effort and its failure must not abort the backup.
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
	 * Acquire the single-runner backup lock, or report that it is already held.
	 *
	 * @return bool True if the lock was acquired; false if a backup is already running.
	 */
	private function acquire_lock(): bool {
		if ( false !== get_transient( self::LOCK_TRANSIENT ) ) {
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
