<?php
/**
 * Pontifex admin verify controller — the AJAX endpoints behind the Verify screen.
 *
 * @package Pontifex\Admin
 */

declare(strict_types=1);

namespace Pontifex\Admin;

use RuntimeException;
use Throwable;
use Pontifex\Archive\Codec\CodecRegistry;
use Pontifex\Archive\Reader\ArchiveReader;
use Pontifex\Archive\Reader\EntryReader;
use Pontifex\Environment\Environment;
use Pontifex\Manifest\WpdbAdapter;
use Pontifex\Restore\DatabaseWriter;
use Pontifex\Restore\FileWriter;
use Pontifex\Restore\RestoreRunner;
use Pontifex\Restore\RestoreRunnerInterface;
use Pontifex\WordPress\WordPressContext;
use Psr\Log\LoggerInterface;

/**
 * Handles the two admin-ajax actions that drive the Verify screen.
 *
 * Verify checks a backup's integrity without restoring it: it walks every entry
 * in the archive and re-computes each SHA-256 hash, **writing nothing** to the
 * site, the filesystem, or the database. It is the read-only twin of the Backup
 * screen, over the same `admin-ajax.php` endpoint so a long check never blocks a
 * page load:
 *
 *  - {@see self::verify()} runs one verification to completion, writing live
 *    progress to a transient the browser polls, and reports a sound or broken
 *    verdict as JSON.
 *  - {@see self::progress()} returns that transient so the page can fill a bar.
 *
 * Both actions are deny-by-default: the {@see Menu::CAPABILITY} capability and a
 * valid `pontifex_verify` nonce are required, and the requested filename is
 * routed through {@see BackupStore::resolve()} so a crafted name cannot reach a
 * file outside the backups directory. The actions are registered only as
 * `wp_ajax_` (logged-in) hooks, never `wp_ajax_nopriv_`.
 *
 * The verification reuses {@see RestoreRunner::verify()} — the same engine the
 * `wp pontifex verify` command and `import --dry-run` use. Only **plain**
 * archives are verified here; encryption and signatures stay CLI features, so an
 * encrypted backup is refused with a pointer to the CLI rather than a confusing
 * failure. A single-runner lock allows one verification at a time, mirroring the
 * Backup screen, so two concurrent checks can never fight over the shared
 * progress transient.
 */
final class VerifyController {

	/**
	 * The nonce action shared by every Verify endpoint and the page's script.
	 *
	 * @var string
	 */
	public const NONCE_ACTION = 'pontifex_verify';

	/**
	 * The transient key holding the in-progress verification's running count.
	 *
	 * @var string
	 */
	private const PROGRESS_TRANSIENT = 'pontifex_verify_progress';

	/**
	 * The transient key marking that a verification is currently running.
	 *
	 * A single-runner lock: verify() refuses a second verification while this is
	 * set. Carries a TTL so a crash that never releases it still self-heals.
	 *
	 * @var string
	 */
	private const LOCK_TRANSIENT = 'pontifex_verify_lock';

	/**
	 * How long the progress and lock transients live, in seconds (15 minutes).
	 *
	 * A literal rather than MINUTE_IN_SECONDS so the class is testable without
	 * WordPress loaded; comfortably longer than any single verification.
	 *
	 * @var int
	 */
	private const PROGRESS_TTL = 900;

	/**
	 * Progress phase reported while entries are being read and hash-checked.
	 *
	 * @var string
	 */
	private const PHASE_VERIFYING = 'verifying';

	/**
	 * Progress phase reported when no verification is running.
	 *
	 * @var string
	 */
	private const PHASE_IDLE = 'idle';

	/**
	 * Minimum interval, in seconds, between progress transient writes.
	 *
	 * The transient is refreshed at most this often rather than once per entry,
	 * which keeps option writes bounded while the bar still advances smoothly.
	 *
	 * @var float
	 */
	private const PROGRESS_THROTTLE_SECONDS = 0.3;

	/**
	 * The Environment abstraction (constant reads).
	 *
	 * @var Environment
	 */
	private Environment $environment;

	/**
	 * The WordPressContext abstraction (the wpdb instance for the unused writer).
	 *
	 * @var WordPressContext
	 */
	private WordPressContext $wordpress_context;

	/**
	 * The store the requested backup is resolved against.
	 *
	 * @var BackupStore
	 */
	private BackupStore $store;

	/**
	 * PSR-3 logger; a broken verdict's real cause is recorded here so the
	 * "check the Pontifex log" message it shows the operator is honest.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * The engine used to read and verify the archive.
	 *
	 * Optional: when null, verify() wires a default RestoreRunner over a plain
	 * EntryReader. Tests inject a fake fulfilling the RestoreRunnerInterface
	 * contract so the handler can be exercised without a real archive.
	 *
	 * @var RestoreRunnerInterface|null
	 */
	private ?RestoreRunnerInterface $restore_runner;

	/**
	 * Construct the controller around its collaborators.
	 *
	 * @param Environment                 $environment       Constant reads for the (unused) restore root.
	 * @param WordPressContext            $wordpress_context Supplies the wpdb instance for the unused database writer.
	 * @param BackupStore                 $store             The backups directory the requested file is resolved against.
	 * @param LoggerInterface             $logger            Records a broken verdict's real cause.
	 * @param RestoreRunnerInterface|null $restore_runner    Optional. When null, a default plain-archive engine is used.
	 */
	public function __construct(
		Environment $environment,
		WordPressContext $wordpress_context,
		BackupStore $store,
		LoggerInterface $logger,
		?RestoreRunnerInterface $restore_runner = null
	) {
		$this->environment       = $environment;
		$this->wordpress_context = $wordpress_context;
		$this->store             = $store;
		$this->logger            = $logger;
		$this->restore_runner    = $restore_runner;
	}

	/**
	 * Verify one backup's integrity, reporting progress through a transient.
	 *
	 * The `wp_ajax_pontifex_verify` handler. Refuses without capability and
	 * nonce, resolves the requested filename to a real backup, refuses an
	 * encrypted archive (CLI-only), then reads and hash-checks every entry. A
	 * sound archive reports the verified entry count; a broken one reports the
	 * failure (the detail goes to the log). Writes nothing to the site.
	 *
	 * @return void
	 */
	public function verify(): void {
		if ( ! $this->is_authorised() ) {
			wp_send_json_error( array( 'message' => $this->unauthorised_message() ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The nonce is verified in is_authorised() above; this only reads the filename to validate.
		$requested = isset( $_POST['file'] ) ? sanitize_file_name( wp_unslash( (string) $_POST['file'] ) ) : '';
		$path      = $this->store->resolve( $requested );
		if ( null === $path ) {
			wp_send_json_error( array( 'message' => __( 'That backup could not be found.', 'pontifex' ) ), 404 );
		}

		// Single-runner lock: one verification at a time, so two concurrent checks
		// cannot fight over the shared progress transient.
		if ( ! $this->acquire_lock() ) {
			wp_send_json_error( array( 'message' => __( 'A verification is already running. Please wait for it to finish.', 'pontifex' ) ), 409 );
		}

		$this->extend_time_limit();

		$source      = null;
		$bytes_total = $this->archive_size( (string) $path );
		try {
			$this->set_progress( 0, $bytes_total );
			$source = $this->open_source( (string) $path );

			// Encrypted archives need a passphrase, which the admin UI does not collect;
			// refuse with a pointer to the CLI rather than a confusing decode failure.
			if ( ( new ArchiveReader( $source ) )->header()->is_encrypted() ) {
				$this->finish( $source );
				wp_send_json_error(
					array( 'message' => __( 'This backup is encrypted. Verify it with the WP-CLI command: wp pontifex verify.', 'pontifex' ) ),
					422
				);
			}

			// ArchiveReader sought through the stream to read the header; rewind so the
			// verify walk starts from the beginning.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Rewinding the open archive stream resource before the verify walk; not a WP_Filesystem operation.
			rewind( $source );

			// Verify reports bytes read from the archive, so the bar advances through a
			// large entry; the per-entry callback only captures the final entry count for
			// the verdict message.
			$entry_total = 0;
			$bytes_done  = 0;
			$last        = 0.0;
			$runner      = $this->restore_runner ?? $this->default_restore_runner();
			$runner->verify(
				$source,
				function ( int $done, int $total ) use ( &$entry_total ): void {
					unset( $done );
					$entry_total = $total;
				},
				function ( int $bytes ) use ( &$bytes_done, &$last, $bytes_total ): void {
					$bytes_done += $bytes;
					$now         = microtime( true );
					if ( ( $now - $last ) >= self::PROGRESS_THROTTLE_SECONDS ) {
						$this->set_progress( $bytes_done, $bytes_total );
						$last = $now;
					}
				}
			);

			$this->finish( $source );

			wp_send_json_success(
				array(
					'sound'   => true,
					'entries' => $entry_total,
					'message' => sprintf(
						/* translators: 1: number of entries verified, 2: the archive's size, human-readable */
						__( 'Verified — this backup is intact. All %1$d entries (%2$s) were re-read and every hash matched.', 'pontifex' ),
						$entry_total,
						$this->wordpress_context->format_size( $bytes_total )
					),
				)
			);
		} catch ( Throwable $error ) {
			$this->logger->error( 'Admin verify failed.', array( 'exception' => $error ) );
			$this->finish( is_resource( $source ) ? $source : null );
			wp_send_json_success(
				array(
					'sound'   => false,
					'message' => __( 'Broken — this backup did not verify. Check the Pontifex log for details.', 'pontifex' ),
				)
			);
		}
	}

	/**
	 * Report the in-progress verification's byte progress.
	 *
	 * The `wp_ajax_pontifex_verify_progress` handler, polled by the page while a
	 * verification runs. Returns the bytes read so far against the archive size,
	 * or zeroes when none is recorded.
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
				'bytes_done'  => $this->counter_int( $progress, 'bytes_done' ),
				'bytes_total' => $this->counter_int( $progress, 'bytes_total' ),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Engine wiring.
	// -------------------------------------------------------------------------

	/**
	 * Build the default plain-archive verify engine.
	 *
	 * A plain EntryReader (no cipher) is all a plain archive needs. The FileWriter
	 * and DatabaseWriter are required by RestoreRunner's constructor but verify()
	 * never invokes them — it writes nothing — so their roots are inert.
	 *
	 * @return RestoreRunner A runner ready to verify a plain archive.
	 */
	private function default_restore_runner(): RestoreRunner {
		return new RestoreRunner(
			new EntryReader( CodecRegistry::with_defaults() ),
			new FileWriter( $this->resolve_wordpress_root() ),
			new DatabaseWriter( new WpdbAdapter( $this->wordpress_context->wpdb_instance() ) ),
			null,
			$this->wordpress_context->convert_hr_to_bytes( $this->environment->ini_get( 'memory_limit' ) )
		);
	}

	/**
	 * Open the backup for reading.
	 *
	 * @param string $path Absolute path of a backup already validated by the store.
	 * @return resource A readable binary stream.
	 * @throws RuntimeException If the file cannot be opened for reading.
	 */
	private function open_source( string $path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- Opening a plugin-owned backup as a stream; @ traps an unopenable-file warning converted to the exception below.
		$source = @fopen( $path, 'rb' );
		if ( false === $source ) {
			throw new RuntimeException( 'VerifyController: could not open the backup for reading.' );
		}
		return $source;
	}

	/**
	 * Read the archive's on-disk size, the byte-progress denominator.
	 *
	 * @param string $path Absolute path to the backup.
	 * @return int The size in bytes, or 0 if it cannot be read.
	 */
	private function archive_size( string $path ): int {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filesize -- Reading the size of a plugin-owned backup as the verify progress denominator; WP_Filesystem is unavailable in this context.
		$size = filesize( $path );
		return false !== $size ? $size : 0;
	}

	/**
	 * Resolve the WordPress installation root for the (unused) restore writer.
	 *
	 * @return string The absolute path of the WordPress root.
	 * @throws RuntimeException If ABSPATH is not defined (should never happen in an admin request).
	 */
	private function resolve_wordpress_root(): string {
		if ( ! $this->environment->is_constant_defined( 'ABSPATH' ) ) {
			throw new RuntimeException( 'VerifyController: ABSPATH is not defined; is WordPress loaded?' );
		}
		return rtrim( (string) $this->environment->constant_value( 'ABSPATH' ), '/' );
	}

	/**
	 * Release the lock, clear the progress, and close the archive stream.
	 *
	 * The single teardown both the success and failure paths run, so a
	 * verification never leaves the lock or the progress transient behind.
	 *
	 * @param resource|null $source The open archive stream to close, or null if none.
	 * @return void
	 */
	private function finish( $source ): void {
		$this->release_lock();
		$this->clear_progress();
		if ( is_resource( $source ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the archive stream opened in this request; not a WP_Filesystem operation.
			fclose( $source );
		}
	}

	/**
	 * Lift the script time limit for the duration of a long read, where allowed.
	 *
	 * @return void
	 */
	private function extend_time_limit(): void {
		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,Squiz.PHP.DiscouragedFunctions.Discouraged -- A long verification must outlive the host's web timeout, the accepted pattern for backup tooling; set_time_limit can be disabled by the host, so the call is best-effort and its failure must not abort the verification.
			@set_time_limit( 0 );
		}
	}

	// -------------------------------------------------------------------------
	// Authorisation.
	// -------------------------------------------------------------------------

	/**
	 * Whether the current request is a permitted Verify action.
	 *
	 * Deny-by-default: the user must hold the managing capability and present a
	 * valid `pontifex_verify` nonce. Both halves must pass.
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
	 * The message returned when an action is refused.
	 *
	 * @return string A human-readable refusal message.
	 */
	private function unauthorised_message(): string {
		return __( 'You do not have permission to verify backups, or your session has expired. Reload the page and try again.', 'pontifex' );
	}

	// -------------------------------------------------------------------------
	// Progress transient and lock.
	// -------------------------------------------------------------------------

	/**
	 * Write the running byte progress to the transient.
	 *
	 * @param int $bytes_done  Archive bytes read so far.
	 * @param int $bytes_total The archive size, the progress denominator.
	 * @return void
	 */
	private function set_progress( int $bytes_done, int $bytes_total ): void {
		set_transient(
			self::PROGRESS_TRANSIENT,
			array(
				'phase'       => self::PHASE_VERIFYING,
				'bytes_done'  => $bytes_done,
				'bytes_total' => $bytes_total,
			),
			self::PROGRESS_TTL
		);
	}

	/**
	 * Clear the progress transient once a verification ends.
	 *
	 * @return void
	 */
	private function clear_progress(): void {
		delete_transient( self::PROGRESS_TRANSIENT );
	}

	/**
	 * Acquire the single-runner verify lock, or report that it is already held.
	 *
	 * @return bool True if the lock was acquired; false if a verification is already running.
	 */
	private function acquire_lock(): bool {
		if ( false !== get_transient( self::LOCK_TRANSIENT ) ) {
			return false;
		}
		set_transient( self::LOCK_TRANSIENT, time(), self::PROGRESS_TTL );
		return true;
	}

	/**
	 * Release the single-runner verify lock held by this request.
	 *
	 * @return void
	 */
	private function release_lock(): void {
		delete_transient( self::LOCK_TRANSIENT );
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
