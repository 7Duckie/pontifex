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
	 * How long the progress transient lives, in seconds (15 minutes).
	 *
	 * A literal rather than MINUTE_IN_SECONDS so the class is testable without
	 * WordPress loaded; comfortably longer than any single export.
	 *
	 * @var int
	 */
	private const PROGRESS_TTL = 900;

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
	 * Construct the controller around its collaborators.
	 *
	 * @param Environment                   $environment       Constant and PHP-version reads.
	 * @param WordPressContext              $wordpress_context Provenance facts, counters, and size formatting.
	 * @param BackupStore                   $store             The backups directory.
	 * @param ManifestBuilderInterface|null $manifest_builder  Optional. When null, a default scanner-backed builder is used.
	 */
	public function __construct(
		Environment $environment,
		WordPressContext $wordpress_context,
		BackupStore $store,
		?ManifestBuilderInterface $manifest_builder = null
	) {
		$this->environment       = $environment;
		$this->wordpress_context = $wordpress_context;
		$this->store             = $store;
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

		$this->extend_time_limit();
		$this->store->ensure_directory();
		$this->bump_counters( array( 'attempted' => 1 ) );

		try {
			$builder     = $this->manifest_builder ?? ExportRunner::default_manifest_builder( $this->wordpress_context, ExclusionRules::default_v010() );
			$entry_plans = $builder->build( $this->resolve_wordpress_root() );
			$total       = count( $entry_plans );

			$this->set_progress( 0, $total );

			$path   = $this->store->next_backup_path( new DateTimeImmutable() );
			$runner = new ExportRunner( $this->environment, $this->wordpress_context );
			$result = $runner->export( new ExportOptions( $path ), $entry_plans, $this->progress_callback( $total ) );

			$this->secure_file( $path );
			$this->clear_progress();

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
			$this->clear_progress();
			$this->bump_counters( array( 'failed' => 1 ) );
			TransferHistory::record( $this->wordpress_context, 'export', 'failed', 0, gmdate( 'c' ) );
			wp_send_json_error( array( 'message' => $this->failure_message( $error ) ), 500 );
		}
	}

	/**
	 * Report the in-progress export's running count.
	 *
	 * The `wp_ajax_pontifex_backup_progress` handler, polled by the page while a
	 * backup runs. Returns the done/total counts the running export writes, or
	 * zeroes when none is recorded.
	 *
	 * @return void
	 */
	public function progress(): void {
		if ( ! $this->is_authorised() ) {
			wp_send_json_error( array( 'message' => $this->unauthorised_message() ), 403 );
		}

		$progress = get_transient( self::PROGRESS_TRANSIENT );
		$progress = is_array( $progress ) ? $progress : array();

		wp_send_json_success(
			array(
				'done'  => $this->counter_int( $progress, 'done' ),
				'total' => $this->counter_int( $progress, 'total' ),
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
	 * Build the per-entry progress callback that writes the throttled transient.
	 *
	 * Writing on every entry would mean tens of thousands of option writes on a
	 * large site, so the transient is refreshed at most a hundred times across the
	 * run, plus once on the final entry.
	 *
	 * @param int $total The total entry count, used to size the throttle step.
	 * @return callable(int, int): void The callback to hand to {@see ExportRunner::export()}.
	 */
	private function progress_callback( int $total ): callable {
		$step = max( 1, intdiv( $total, 100 ) );
		return function ( int $done, int $count ) use ( $step ): void {
			if ( $done === $count || 0 === $done % $step ) {
				$this->set_progress( $done, $count );
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
	 * Write the running progress to the transient.
	 *
	 * @param int $done  Entries written so far.
	 * @param int $total Total entries to write.
	 * @return void
	 */
	private function set_progress( int $done, int $total ): void {
		set_transient(
			self::PROGRESS_TRANSIENT,
			array(
				'done'  => $done,
				'total' => $total,
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
