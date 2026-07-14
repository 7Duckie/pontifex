<?php
/**
 * Pontifex admin restore controller — the AJAX endpoints behind the Restore screen.
 *
 * @package Pontifex\Admin
 */

declare(strict_types=1);

namespace Pontifex\Admin;

use RuntimeException;
use Throwable;
use Pontifex\Archive\Codec\CodecRegistry;
use Pontifex\Archive\Format\Scope;
use Pontifex\Archive\Reader\ArchiveReader;
use Pontifex\Archive\Reader\EntryReader;
use Pontifex\Cli\TransferHistory;
use Pontifex\Environment\Environment;
use Pontifex\Job\JobStore;
use Pontifex\Lock\OperationLock;
use Pontifex\Manifest\WpdbAdapter;
use Pontifex\Migrate\UrlMigrator;
use Pontifex\Migrate\UrlMigratorInterface;
use Pontifex\Restore\DatabaseWriter;
use Pontifex\Restore\FileWriter;
use Pontifex\Restore\RestoreRunner;
use Pontifex\Restore\RestoreRunnerInterface;
use Pontifex\Rollback\RollbackStoreInterface;
use Pontifex\Rollback\SafetyArchiver;
use Pontifex\Rollback\SafetyArchiverInterface;
use Pontifex\WordPress\WordPressContext;
use Psr\Log\LoggerInterface;

/**
 * Handles the three admin-ajax actions that drive the Restore screen.
 *
 * Restore is the dangerous half of Pontifex in the browser: it overwrites the
 * live site. The page is static HTML; the work happens here over
 * `admin-ajax.php`, so a long restore never blocks a page load:
 *
 *  - {@see self::restore()} replays a chosen backup over the current site. It
 *    first verifies the backup (a preview gate — a broken backup is refused
 *    before anything is overwritten), then takes a pre-import safety archive, then
 *    restores. Same-URL only; cross-URL migration stays a CLI feature for now.
 *  - {@see self::rollback()} restores the most recent safety archive — the undo
 *    for a destructive restore — after verifying it.
 *  - {@see self::progress()} returns the byte progress the running operation
 *    writes, so the page can fill a determinate, phase-labelled bar.
 *
 * Every action is deny-by-default: it requires the {@see Menu::CAPABILITY}
 * capability **and** a valid `pontifex_restore` nonce, and the chosen filename is
 * routed through {@see BackupStore::resolve()} so a crafted name cannot reach a
 * file outside the backups directory. The actions are registered only as
 * `wp_ajax_` (logged-in) hooks, never `wp_ajax_nopriv_`. Only **plain** archives
 * are restored here; an encrypted backup is refused with a pointer to the CLI,
 * and signatures are not checked — both stay CLI features, matching Verify.
 *
 * The engines are the same ones the `wp pontifex import` and `rollback` commands
 * use ({@see RestoreRunner}, {@see SafetyArchiver}), so a browser restore is
 * byte-for-byte the CLI restore; the import counters and transfer history the
 * Overview screen reads are updated here too. A single-runner lock allows one
 * restore or rollback at a time. There is no cancel: once a restore is committed
 * the site is being overwritten, so the safe recovery is to roll back, not to
 * interrupt a half-written restore.
 */
final class RestoreController {

	/**
	 * The nonce action shared by every Restore endpoint and the page's script.
	 *
	 * @var string
	 */
	public const NONCE_ACTION = 'pontifex_restore';

	/**
	 * The transient key holding the in-progress operation's byte progress.
	 *
	 * @var string
	 */
	private const PROGRESS_TRANSIENT = 'pontifex_restore_progress';

	/**
	 * How long the progress transient lives, in seconds (15 minutes).
	 *
	 * A literal rather than MINUTE_IN_SECONDS so the class is testable without
	 * WordPress loaded; comfortably longer than any single restore.
	 *
	 * @var int
	 */
	private const PROGRESS_TTL = 900;

	/**
	 * Minimum interval, in seconds, between progress transient writes.
	 *
	 * @var float
	 */
	private const PROGRESS_THROTTLE_SECONDS = 0.3;

	/**
	 * Progress phase: verifying the chosen archive before anything is written.
	 *
	 * @var string
	 */
	private const PHASE_VERIFYING = 'verifying';

	/**
	 * Progress phase: writing the pre-import safety archive of the current site.
	 *
	 * @var string
	 */
	private const PHASE_BACKING_UP = 'backing_up';

	/**
	 * Progress phase: replaying the chosen archive over the site.
	 *
	 * @var string
	 */
	private const PHASE_RESTORING = 'restoring';

	/**
	 * Progress phase: replaying the safety archive to undo the last restore.
	 *
	 * @var string
	 */
	private const PHASE_ROLLING_BACK = 'rolling_back';

	/**
	 * Progress phase reported when no operation is running.
	 *
	 * @var string
	 */
	private const PHASE_IDLE = 'idle';

	/**
	 * The wp_options key holding the import counters (mirrors ImportCommand).
	 *
	 * @var string
	 */
	private const STATS_OPTION = 'pontifex_import_stats';

	/**
	 * The wp_options key holding the rollback counters (mirrors RollbackCommand).
	 *
	 * A rollback is an undo, not a transfer, so it is counted separately from the
	 * import counters and stays out of the transfer history.
	 *
	 * @var string
	 */
	private const ROLLBACK_STATS_OPTION = 'pontifex_rollback_stats';

	/**
	 * PHP error types that are fatal and uncatchable, so they bypass the try/catch.
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
	 * The WordPressContext abstraction (wpdb, counters, size formatting).
	 *
	 * @var WordPressContext
	 */
	private WordPressContext $wordpress_context;

	/**
	 * The store the chosen backup is resolved against and listed from.
	 *
	 * @var BackupStore
	 */
	private BackupStore $store;

	/**
	 * The rollback store locating the most recent safety archive.
	 *
	 * @var RollbackStoreInterface
	 */
	private RollbackStoreInterface $rollback_store;

	/**
	 * PSR-3 logger; a failure's real cause is recorded here so the operator's
	 * "check the Pontifex log" message is honest.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * The engine used to verify and replay an archive.
	 *
	 * Optional: when null, a default plain-archive RestoreRunner over the live
	 * site (ABSPATH + $wpdb) is wired. Tests inject a fake so the handler can be
	 * exercised without writing to the filesystem or database.
	 *
	 * @var RestoreRunnerInterface|null
	 */
	private ?RestoreRunnerInterface $restore_runner;

	/**
	 * The archiver that writes the pre-import safety archive.
	 *
	 * Optional: when null, a default SafetyArchiver over the rollback store is
	 * wired. Tests inject a fake so no real export runs.
	 *
	 * @var SafetyArchiverInterface|null
	 */
	private ?SafetyArchiverInterface $safety_archiver;

	/**
	 * The cross-URL migrator used when a new URL is supplied with a restore.
	 *
	 * Optional: when null and a URL is given, a UrlMigrator over the live $wpdb is
	 * wired (matching ImportCommand). Tests inject a fake fulfilling the interface.
	 *
	 * @var UrlMigratorInterface|null
	 */
	private ?UrlMigratorInterface $url_migrator;

	/**
	 * The shared single-runner lock, contended with backup and rollback.
	 *
	 * Optional in the constructor: when null, a default OperationLock over this
	 * controller's own context and job store is built. Tests inject a fake or a
	 * real one over mocked collaborators.
	 *
	 * @var OperationLock
	 */
	private OperationLock $lock;

	/**
	 * Construct the controller around its collaborators.
	 *
	 * @param Environment                  $environment       Constant and PHP-version reads.
	 * @param WordPressContext             $wordpress_context wpdb, counters, and size formatting.
	 * @param BackupStore                  $store             The backups directory the chosen file is resolved against.
	 * @param RollbackStoreInterface       $rollback_store    Locates the most recent safety archive for rollback.
	 * @param LoggerInterface              $logger            Records a failure's real cause.
	 * @param RestoreRunnerInterface|null  $restore_runner    Optional. When null, a default live-site engine is used.
	 * @param SafetyArchiverInterface|null $safety_archiver   Optional. When null, a default archiver is used.
	 * @param UrlMigratorInterface|null    $url_migrator      Optional. When null and a URL is given, a UrlMigrator over the live $wpdb is wired.
	 * @param OperationLock|null           $lock              Optional. When null, a default OperationLock over this controller's own context and job store is built.
	 */
	public function __construct(
		Environment $environment,
		WordPressContext $wordpress_context,
		BackupStore $store,
		RollbackStoreInterface $rollback_store,
		LoggerInterface $logger,
		?RestoreRunnerInterface $restore_runner = null,
		?SafetyArchiverInterface $safety_archiver = null,
		?UrlMigratorInterface $url_migrator = null,
		?OperationLock $lock = null
	) {
		$this->environment       = $environment;
		$this->wordpress_context = $wordpress_context;
		$this->store             = $store;
		$this->rollback_store    = $rollback_store;
		$this->logger            = $logger;
		$this->restore_runner    = $restore_runner;
		$this->safety_archiver   = $safety_archiver;
		$this->url_migrator      = $url_migrator;
		$this->lock              = $lock ?? new OperationLock( $this->wordpress_context, $this->jobs_store() );
	}

	/**
	 * Restore a chosen backup over the current site, reporting progress.
	 *
	 * The `wp_ajax_pontifex_restore` handler. Refuses without capability and
	 * nonce, resolves the chosen filename to a real backup, refuses an encrypted
	 * archive, then runs the three phases: verify the backup (a broken one is
	 * refused before anything is overwritten), write the pre-import safety
	 * archive, and replay the backup. Updates the import counters and history.
	 *
	 * @return void
	 */
	public function restore(): void {
		if ( ! $this->is_authorised() ) {
			wp_send_json_error( array( 'message' => $this->unauthorised_message() ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The nonce is verified in is_authorised() above; this only reads the filename to validate.
		$requested = isset( $_POST['file'] ) ? sanitize_file_name( wp_unslash( (string) $_POST['file'] ) ) : '';
		$path      = $this->store->resolve( $requested );
		if ( null === $path ) {
			wp_send_json_error( array( 'message' => __( 'That backup could not be found.', 'pontifex' ) ), 404 );
		}

		// The opt-in "relink to this site" box turns this into a cross-URL migration run
		// after the restore, rewriting the backup's own URL to this destination's. Read
		// before any side effect; the target is always this site, never a typed address.
		$migrate = $this->migrate_requested();

		if ( ! $this->lock->acquire( OperationLock::OP_RESTORE ) ) {
			wp_send_json_error( array( 'message' => $this->lock_busy_message() ), 409 );
		}

		$this->extend_time_limit();
		$this->register_shutdown();

		$size   = $this->archive_size( (string) $path );
		$source = null;
		// The safety archive's path, once taken — the undo the failure handler replays to
		// recover the site if the restore then fails part-written. Null until it is taken,
		// so a failure before that point knows the site was not yet changed.
		$safety_path = null;
		try {
			$source = $this->open_source( (string) $path );

			$reader = new ArchiveReader( $source );

			// Encrypted archives need a passphrase the admin UI does not collect;
			// refuse with a pointer to the CLI rather than a confusing decode failure.
			if ( $reader->header()->is_encrypted() ) {
				$this->finish( $source );
				wp_send_json_error(
					array( 'message' => __( 'This backup is encrypted. Restore it with the WP-CLI command: wp pontifex import.', 'pontifex' ) ),
					422
				);
			}

			// The admin restore is content-only: it has no --whole-site escape hatch, so a
			// whole-site or legacy backup (one that would overwrite WordPress core or
			// wp-config.php) is refused here with a pointer to the CLI, exactly as an
			// encrypted backup is — failing closed, never overwriting live core (ADR 0008).
			$scope = $reader->provenance()->scope();
			if ( ! ( $scope instanceof Scope && $scope->is_content_only() ) ) {
				$this->finish( $source );
				wp_send_json_error(
					array( 'message' => __( 'This is a whole-site backup (it includes WordPress core and wp-config.php). The admin restore is content-only; restore a whole-site backup with the WP-CLI command: wp pontifex import --whole-site.', 'pontifex' ) ),
					422
				);
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Rewinding the open archive stream before the verify/restore walks re-read it; not a WP_Filesystem operation.
			rewind( $source );
			// A content-only restore restricts the file writer to the wp-content tree — the
			// write-boundary backstop behind the scope gate above.
			$runner = $this->restore_runner ?? $this->default_restore_runner( 'wp-content' );

			// Phase 1: verify the backup as a gate. A broken backup is refused before
			// the safety archive or any write — the whole point of previewing first.
			$this->bump_counters( array( 'attempted' => 1 ) );
			if ( ! $this->verify_gate( $runner, $source, $size ) ) {
				$this->bump_counters( array( 'failed' => 1 ) );
				TransferHistory::record( $this->wordpress_context, 'import', 'failed', 0, gmdate( 'c' ) );
				$this->finish( $source );
				wp_send_json_success(
					array(
						'restored' => false,
						'message'  => __( 'Broken — this backup did not verify, so nothing was restored. Check the Pontifex log for details.', 'pontifex' ),
					)
				);
			} else {
				// For a relink restore, read the backup's own source URL before the
				// restore consumes the stream; the rewrite runs on the restored database
				// afterwards.
				$migrator   = $migrate ? ( $this->url_migrator ?? new UrlMigrator( $this->wordpress_context ) ) : null;
				$source_url = '';
				if ( null !== $migrator ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Rewinding the open archive stream so source_url can re-read its provenance; not a WP_Filesystem operation.
					rewind( $source );
					$source_url = $migrator->source_url( $source );
				}

				// Phase 2: the pre-import safety archive (the undo), then phase 3: restore.
				$safety_path = $this->take_safety_archive();
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Rewinding the open archive stream before the restore walk re-reads it; not a WP_Filesystem operation.
				rewind( $source );
				$entries = $this->restore_phase( $runner, $source, $size );

				// The restore replayed the database with raw SQL, so WordPress's option
				// cache still holds the pre-restore values. Flush before recording, or the
				// post-restore counter write reads/writes stale cached state and is
				// silently lost (a stale "exists" cache makes update_option run an UPDATE
				// that matches zero rows in the just-replaced wp_options).
				$this->wordpress_context->flush_cache();

				// Phase 4 (relink only): rewrite the backup's own URL to this site's across
				// the restored database, serialised-safe (ADR 0006). The target is always
				// this destination — never a typed address — so a relinked site can only
				// ever point at the address the operator is already looking at, and the
				// site does not move, so no new login URL is needed.
				$migration = null !== $migrator
					? $migrator->migrate( $source_url, $this->wordpress_context->site_url() )
					: null;

				$this->finish( $source );
				// The pre-restore `attempted` bump was wiped when the replay replaced
				// wp_options, so re-apply it here alongside the success, keeping the
				// Overview figures consistent (attempted >= succeeded).
				$this->bump_counters(
					array(
						'attempted'      => 1,
						'succeeded'      => 1,
						'bytes_imported' => $size,
					)
				);
				TransferHistory::record( $this->wordpress_context, 'import', 'succeeded', $size, gmdate( 'c' ) );

				wp_send_json_success(
					array(
						'restored' => true,
						'entries'  => $entries,
						'message'  => null !== $migration
							? sprintf(
								/* translators: 1: number of entries restored, 2: the backup's size, human-readable, 3: number of database rows rewritten */
								__( 'Restored and relinked — %1$d entries (%2$s) written, then the backup\'s links were rewritten to this site across %3$d database rows.', 'pontifex' ),
								$entries,
								$this->wordpress_context->format_size( $size ),
								$migration->rows_changed()
							)
							: sprintf(
								/* translators: 1: number of entries restored, 2: the backup's size, human-readable, 3: the backup filename */
								__( 'Restored — %1$d entries (%2$s) written from %3$s. Your site now matches that backup.', 'pontifex' ),
								$entries,
								$this->wordpress_context->format_size( $size ),
								basename( (string) $path )
							),
					)
				);
			}
		} catch ( Throwable $error ) {
			$this->logger->error( 'Admin restore failed.', array( 'exception' => $error ) );
			$this->finish( is_resource( $source ) ? $source : null );

			// If the safety archive was taken, the restore may have written part of the
			// database, so replay it to return the site to its pre-restore state. When it was
			// not taken (the failure preceded it), the site was not changed and nothing is
			// replayed. Recovery never throws — it reports whether the site was restored.
			$recovered = null !== $safety_path ? $this->recover_from_safety_archive( $safety_path ) : null;

			$this->bump_counters( array( 'failed' => 1 ) );
			TransferHistory::record( $this->wordpress_context, 'import', 'failed', 0, gmdate( 'c' ) );
			wp_send_json_error( array( 'message' => $this->failure_message( $error, $recovered ) ), 500 );
		}
	}

	/**
	 * Replay a safety archive to recover the site after a failed restore.
	 *
	 * Called only from the restore failure handler, once the safety archive has been
	 * taken and the restore has then failed — so the database may be part-written. It
	 * opens the safety archive, verifies it, and replays it unrestricted (like a
	 * rollback), returning the site to its pre-restore state. It is wrapped so it can
	 * never throw out of the failure handler: a recovery that itself fails is reported
	 * as such, not escalated.
	 *
	 * @param string $path The absolute path of the safety archive to replay.
	 * @return bool True when the site was recovered; false when recovery could not complete.
	 */
	private function recover_from_safety_archive( string $path ): bool {
		$source = null;
		try {
			$size   = $this->archive_size( $path );
			$source = $this->open_source( $path );
			// A safety archive is the site's own undo, so its replay is unrestricted — no
			// required prefix, matching a rollback.
			$runner = $this->restore_runner ?? $this->default_restore_runner( null );

			if ( ! $this->verify_gate( $runner, $source, $size ) ) {
				$this->finish( $source );
				return false;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Rewinding the open archive stream before the recovery walk re-reads it; not a WP_Filesystem operation.
			rewind( $source );
			$this->restore_phase( $runner, $source, $size, self::PHASE_ROLLING_BACK );
			$this->wordpress_context->flush_cache();
			$this->finish( $source );
			return true;
		} catch ( Throwable $recovery_error ) {
			$this->logger->error( 'Admin restore auto-recovery failed.', array( 'exception' => $recovery_error ) );
			$this->finish( is_resource( $source ) ? $source : null );
			return false;
		}
	}

	/**
	 * Restore the most recent safety archive over the current site (the undo).
	 *
	 * The `wp_ajax_pontifex_rollback` handler. Refuses without capability and
	 * nonce, finds the most recent safety archive (a clear message when there is
	 * none), verifies it, then replays it. No new safety archive is taken — the
	 * same as `wp pontifex rollback`. A rollback is an undo, not a transfer, so the
	 * import counters and transfer history are left untouched; instead it bumps its
	 * own rollback counters so the Overview shows rollback activity.
	 *
	 * @return void
	 */
	public function rollback(): void {
		if ( ! $this->is_authorised() ) {
			wp_send_json_error( array( 'message' => $this->unauthorised_message() ), 403 );
		}

		$path = $this->rollback_store->most_recent();
		if ( null === $path ) {
			wp_send_json_error(
				array( 'message' => __( 'There is no safety archive to roll back to. One is written automatically before each restore.', 'pontifex' ) ),
				404
			);
		}

		if ( ! $this->lock->acquire( OperationLock::OP_ROLLBACK ) ) {
			wp_send_json_error( array( 'message' => $this->lock_busy_message() ), 409 );
		}

		$this->extend_time_limit();
		$this->register_shutdown();

		$size   = $this->archive_size( (string) $path );
		$source = null;
		try {
			$source = $this->open_source( (string) $path );
			// A safety archive is the site's own backup of whatever scope was overwritten
			// (content-only for an admin restore, or whole-site for a CLI --whole-site
			// import), so its replay is unrestricted — no required prefix, matching
			// `wp pontifex rollback`.
			$runner = $this->restore_runner ?? $this->default_restore_runner( null );

			// Verify the safety archive before restoring it; a corrupt one is refused
			// rather than replayed over the site.
			if ( ! $this->verify_gate( $runner, $source, $size ) ) {
				$this->bump_counters(
					array(
						'attempted' => 1,
						'failed'    => 1,
					),
					self::ROLLBACK_STATS_OPTION,
					'bytes_rolled_back'
				);
				$this->finish( $source );
				wp_send_json_success(
					array(
						'rolled_back' => false,
						'message'     => __( 'Broken — the safety archive did not verify, so nothing was rolled back. Check the Pontifex log for details.', 'pontifex' ),
					)
				);
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Rewinding the open archive stream before the restore walk re-reads it; not a WP_Filesystem operation.
				rewind( $source );
				$entries = $this->restore_phase( $runner, $source, $size, self::PHASE_ROLLING_BACK );

				// The rollback replayed the database too, so flush the now-stale option
				// cache (see restore() for the full rationale) before anything reads it.
				$this->wordpress_context->flush_cache();
				// Record the rollback in its own counters — separate from the import
				// transfer counters and history — so Overview shows rollback activity.
				$this->bump_counters(
					array(
						'attempted'         => 1,
						'succeeded'         => 1,
						'bytes_rolled_back' => $size,
					),
					self::ROLLBACK_STATS_OPTION,
					'bytes_rolled_back'
				);

				$this->finish( $source );

				wp_send_json_success(
					array(
						'rolled_back' => true,
						'entries'     => $entries,
						'message'     => sprintf(
							/* translators: %d: number of entries restored from the safety archive */
							__( 'Rolled back — %d entries restored from the most recent safety archive. Your last restore has been undone.', 'pontifex' ),
							$entries
						),
					)
				);
			}
		} catch ( Throwable $error ) {
			$this->logger->error( 'Admin rollback failed.', array( 'exception' => $error ) );
			$this->finish( is_resource( $source ) ? $source : null );
			$this->bump_counters(
				array(
					'attempted' => 1,
					'failed'    => 1,
				),
				self::ROLLBACK_STATS_OPTION,
				'bytes_rolled_back'
			);
			wp_send_json_error( array( 'message' => $this->rollback_failure_message() ), 500 );
		}
	}

	/**
	 * The message returned when a rollback itself fails.
	 *
	 * Distinct from {@see self::failure_message()}: a rollback has no undo of its own, so
	 * a failure part-way may leave the site partially rolled back, and the only recovery
	 * is to restore a backup.
	 *
	 * @return string A human-readable rollback-failure message.
	 */
	private function rollback_failure_message(): string {
		return __( 'The rollback could not be completed. Your site may be partially rolled back — restore a backup to recover. Check the Pontifex log for details.', 'pontifex' );
	}

	/**
	 * Report the in-progress operation's byte progress.
	 *
	 * The `wp_ajax_pontifex_restore_progress` handler, polled by the page while a
	 * restore or rollback runs. Returns the phase and the bytes processed against
	 * the phase total, or the idle phase when none is recorded.
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

	/**
	 * Report whether a chosen backup would need its links rewritten to this site.
	 *
	 * The `wp_ajax_pontifex_restore_preview` handler, queried by the page when a
	 * backup is selected. It is read-only and fail-soft: it opens the archive
	 * only to read its recorded source URL, compares that to this site's URL, and
	 * answers whether the two differ — a purely advisory signal for the "rewrite
	 * its links" checkbox, which it never changes. Any archive that cannot be read,
	 * an encrypted archive (which the admin cannot restore in any case), or one
	 * recording no source URL all answer "no" — the hint is a convenience, never a
	 * gate, so it fails towards staying silent. Unlike {@see self::restore()} it
	 * takes no lock, writes no progress, and touches neither the database nor any
	 * file.
	 *
	 * @return void
	 */
	public function preview(): void {
		if ( ! $this->is_authorised() ) {
			wp_send_json_error( array( 'message' => $this->unauthorised_message() ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The nonce is verified in is_authorised() above; this only reads the filename to resolve.
		$requested = isset( $_POST['file'] ) ? sanitize_file_name( wp_unslash( (string) $_POST['file'] ) ) : '';
		$path      = $this->store->resolve( $requested );

		wp_send_json_success(
			array(
				'migration' => ( null !== $path && $this->archive_needs_migration( (string) $path ) ),
			)
		);
	}

	/**
	 * Report whether the current request is an authenticated Pontifex manager.
	 *
	 * The `wp_ajax_pontifex_auth_check` handler, polled by the post-restore
	 * signed-out overlay so it can clear itself once the operator has logged back
	 * in. It deliberately verifies NO nonce: a restore that replaces the users
	 * table invalidates the session, and a fresh login mints a new session token,
	 * so the page's baked-in nonce — bound to the old session — can never verify
	 * again; requiring one here would make the overlay impossible to dismiss,
	 * which is the very bug this closes. Skipping the nonce is safe: the handler
	 * changes nothing and returns only whether THIS request is a logged-in
	 * manager — state the same-origin caller already knows and that CORS keeps
	 * any cross-origin page from reading. It is registered only as `wp_ajax_`
	 * (logged-in), never `wp_ajax_nopriv_`, so a logged-out request is never
	 * dispatched and admin-ajax answers a bare `0`.
	 *
	 * @return void
	 */
	public function auth_check(): void {
		wp_send_json_success( array( 'authenticated' => current_user_can( Menu::CAPABILITY ) ) );
	}

	/**
	 * Release the lock and clear progress if a fatal error ended a run.
	 *
	 * Registered with register_shutdown_function() at the start of a run. A fatal
	 * — out of memory, an exceeded time limit — cannot be caught, so the try/catch
	 * never runs and PHP dies mid-operation. Unlike a backup there is no partial
	 * file to remove (the target is the live site), and a half-written restore
	 * cannot be safely undone here; the safety archive taken first is the undo, so
	 * this only releases the lock, clears progress, and records the cause with a
	 * pointer to rollback. A clean run, or one that failed with a catchable
	 * exception, has already released the lock, so this does nothing.
	 *
	 * @return void
	 */
	public function handle_shutdown(): void {
		if ( ! $this->lock->is_held() ) {
			return;
		}

		$last_error = error_get_last();
		if ( ! is_array( $last_error ) || ! in_array( $last_error['type'], self::FATAL_ERROR_TYPES, true ) ) {
			return;
		}

		$this->logger->error(
			'Admin restore ended on a fatal error; the site may be partially restored. Roll back to recover.',
			array( 'error' => $last_error['message'] )
		);
		$this->lock->release();
		$this->clear_progress();
	}

	// -------------------------------------------------------------------------
	// Phases.
	// -------------------------------------------------------------------------

	/**
	 * Verify the archive as a preview gate, reporting byte progress.
	 *
	 * Runs {@see RestoreRunnerInterface::verify()} over the source with the
	 * verifying-phase byte callback. A verification failure is not a server error
	 * — it means the backup is broken — so it is caught and reported as a false
	 * return, with the real cause logged, and nothing is written.
	 *
	 * @param RestoreRunnerInterface $runner The engine to verify with.
	 * @param resource               $source The open archive stream.
	 * @param int                    $size   The archive size, the progress denominator.
	 * @return bool True when the archive verified sound; false when it is broken.
	 */
	private function verify_gate( RestoreRunnerInterface $runner, $source, int $size ): bool {
		$this->set_progress( self::PHASE_VERIFYING, 0, $size );
		try {
			$runner->verify( $source, null, $this->byte_callback( self::PHASE_VERIFYING, $size ) );
		} catch ( Throwable $error ) {
			$this->logger->warning( 'Admin restore: the archive failed verification; nothing was written.', array( 'exception' => $error ) );
			return false;
		}
		return true;
	}

	/**
	 * Replay the archive over the site, reporting byte progress.
	 *
	 * @param RestoreRunnerInterface $runner The engine to restore with.
	 * @param resource               $source The open archive stream.
	 * @param int                    $size   The archive size, the progress denominator.
	 * @param string                 $phase  The progress phase to report under — restoring, or rolling back for an undo.
	 * @return int The number of entries restored.
	 */
	private function restore_phase( RestoreRunnerInterface $runner, $source, int $size, string $phase = self::PHASE_RESTORING ): int {
		$this->set_progress( $phase, 0, $size );
		$entry_total = 0;
		$on_entry    = function ( int $done, int $total ) use ( &$entry_total ): void {
			unset( $done );
			$entry_total = $total;
		};
		$runner->restore( $source, $on_entry, $this->byte_callback( $phase, $size ) );
		return $entry_total;
	}

	/**
	 * Take the content-only pre-import safety archive of the current site, reporting progress.
	 *
	 * The safety archive is a content-only export of the live site (the wp-content
	 * tree plus the database — exactly what a content-only restore overwrites). The
	 * archiver reports its estimated total up front, so the "Backing up…" step shows
	 * a determinate byte bar that fills as the content is copied — the same progress
	 * the Backup screen shows, so the experience is consistent across the two.
	 *
	 * @return string The absolute path of the safety archive written — the undo the
	 *                restore automatically replays if the restore then fails.
	 */
	private function take_safety_archive(): string {
		$archiver = $this->safety_archiver ?? $this->default_safety_archiver();

		$this->set_progress( self::PHASE_BACKING_UP, 0, 0 );

		$bytes_done = 0;
		$total      = 0;
		$last       = 0.0;
		$on_total   = function ( int $estimated ) use ( &$total ): void {
			$total = $estimated;
		};
		$on_bytes   = function ( int $bytes ) use ( &$bytes_done, &$total, &$last ): void {
			$bytes_done += $bytes;
			$now         = microtime( true );
			if ( ( $now - $last ) >= self::PROGRESS_THROTTLE_SECONDS ) {
				$this->set_progress( self::PHASE_BACKING_UP, $bytes_done, $total );
				$last = $now;
			}
		};

		return $this->safety_archiver_create( $archiver, $on_bytes, $on_total );
	}

	/**
	 * Invoke the archiver, isolating the one call that names the content scan root.
	 *
	 * The safety archive is content-only, so it is scanned from WP_CONTENT_DIR (its
	 * recorded paths stay wp-content-prefixed), not ABSPATH.
	 *
	 * @param SafetyArchiverInterface $archiver The archiver to run.
	 * @param callable                $on_bytes The byte-progress callback.
	 * @param callable                $on_total The estimated-total callback, for a determinate bar.
	 * @return string The absolute path of the safety archive just written.
	 */
	private function safety_archiver_create( SafetyArchiverInterface $archiver, callable $on_bytes, callable $on_total ): string {
		return $archiver->create( $this->resolve_content_root(), null, $on_bytes, $on_total );
	}

	// -------------------------------------------------------------------------
	// Engine wiring.
	// -------------------------------------------------------------------------

	/**
	 * Build the default plain-archive restore engine over the live site.
	 *
	 * A plain EntryReader (no cipher) is all a plain archive needs; the FileWriter is
	 * rooted at the WordPress installation and the DatabaseWriter wraps the real
	 * $wpdb, so this writes to the running site. The forward restore passes
	 * "wp-content" as the required prefix, so the file writer refuses any entry
	 * outside the wp-content tree; a rollback passes null, replaying the safety
	 * archive whatever its scope.
	 *
	 * @param string|null $required_prefix Prefix every restored file path must sit under ("wp-content" for the content-only forward restore), or null to allow any path (rollback).
	 * @return RestoreRunner A runner ready to verify and restore a plain archive.
	 */
	private function default_restore_runner( ?string $required_prefix ): RestoreRunner {
		return new RestoreRunner(
			new EntryReader( CodecRegistry::with_defaults() ),
			new FileWriter( $this->resolve_wordpress_root(), false, $required_prefix ),
			new DatabaseWriter( new WpdbAdapter( $this->wordpress_context->wpdb_instance() ) ),
			null,
			$this->wordpress_context->convert_hr_to_bytes( $this->environment->ini_get( 'memory_limit' ) )
		);
	}

	/**
	 * Build the default content-only safety archiver over the rollback store.
	 *
	 * The admin restore is content-only, so the safety archive it takes is content-only
	 * too: it captures exactly the wp-content tree the restore overwrites, and rolls
	 * back through the same engine (ADR 0008).
	 *
	 * @return SafetyArchiver An archiver ready to write a content-only pre-import safety archive.
	 */
	private function default_safety_archiver(): SafetyArchiver {
		return new SafetyArchiver( $this->environment, $this->wordpress_context, $this->rollback_store, null, 2, true );
	}

	/**
	 * Open the archive for reading.
	 *
	 * @param string $path Absolute path of an archive already validated by the store.
	 * @return resource A readable binary stream.
	 * @throws RuntimeException If the file cannot be opened for reading.
	 */
	private function open_source( string $path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- Opening a plugin-owned archive as a stream; @ traps an unopenable-file warning converted to the exception below.
		$source = @fopen( $path, 'rb' );
		if ( false === $source ) {
			throw new RuntimeException( 'RestoreController: could not open the archive for reading.' );
		}
		return $source;
	}

	/**
	 * Read the archive's on-disk size, the byte-progress denominator.
	 *
	 * @param string $path Absolute path to the archive.
	 * @return int The size in bytes, or 0 if it cannot be read.
	 */
	private function archive_size( string $path ): int {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filesize -- Reading the size of a plugin-owned archive as the progress denominator; WP_Filesystem is unavailable in this context.
		$size = filesize( $path );
		return false !== $size ? $size : 0;
	}

	/**
	 * Whether the archive at the given path records a source URL unlike this site.
	 *
	 * Opens the archive only for its header and provenance block, never its
	 * manifest or entries. An encrypted archive answers false (the admin refuses
	 * to restore one, so the hint would mislead); an unreadable, corrupt, or
	 * non-archive file answers false (a convenience must never break on a bad
	 * file); an archive recording no source URL answers false. Otherwise it
	 * answers whether the recorded source URL differs from this site's, compared
	 * case-insensitively and ignoring a single trailing slash.
	 *
	 * @param string $path Absolute path to the archive on disk.
	 * @return bool True when a link rewrite would apply on restore.
	 */
	private function archive_needs_migration( string $path ): bool {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- Opening a plugin-owned backup as a stream; @ traps an unopenable-file warning handled by the fail-soft return below.
		$source = @fopen( $path, 'rb' );
		if ( false === $source ) {
			return false;
		}

		try {
			$reader = new ArchiveReader( $source );
			if ( $reader->header()->is_encrypted() ) {
				return false;
			}
			$source_url = $reader->provenance()->url();
		} catch ( Throwable $error ) {
			unset( $error );
			return false;
		} finally {
			if ( is_resource( $source ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the archive stream opened above; not a WP_Filesystem operation.
				fclose( $source );
			}
		}

		if ( '' === $source_url ) {
			return false;
		}

		return $this->normalise_url( $source_url ) !== $this->normalise_url( $this->wordpress_context->site_url() );
	}

	/**
	 * Normalise a site URL for comparison: lower case, no trailing slash.
	 *
	 * Scheme, host, port and path remain significant; only case and a single
	 * trailing slash — neither of which changes which site a URL points at — are
	 * folded away, so `https://Example.test/` and `https://example.test` compare
	 * equal.
	 *
	 * @param string $url The URL to normalise.
	 * @return string The normalised URL.
	 */
	private function normalise_url( string $url ): string {
		return strtolower( rtrim( $url, '/' ) );
	}

	/**
	 * Resolve the WordPress installation root the restore writes beneath.
	 *
	 * @return string The absolute path of the WordPress root.
	 * @throws RuntimeException If ABSPATH is not defined (should never happen in an admin request).
	 */
	private function resolve_wordpress_root(): string {
		if ( ! $this->environment->is_constant_defined( 'ABSPATH' ) ) {
			throw new RuntimeException( 'RestoreController: ABSPATH is not defined; is WordPress loaded?' );
		}
		return rtrim( (string) $this->environment->constant_value( 'ABSPATH' ), '/' );
	}

	/**
	 * Resolve the wp-content root the content-only safety archive scans.
	 *
	 * Reads WP_CONTENT_DIR through the Environment seam, falling back to
	 * ABSPATH/wp-content (WordPress's own default for the constant) when it is not
	 * defined, so the resolver still works outside a full WordPress request, as in
	 * unit tests.
	 *
	 * @return string The absolute path of the wp-content directory.
	 * @throws RuntimeException If WP_CONTENT_DIR is undefined and ABSPATH is too (should never happen in an admin request).
	 */
	private function resolve_content_root(): string {
		if ( $this->environment->is_constant_defined( 'WP_CONTENT_DIR' ) ) {
			return rtrim( (string) $this->environment->constant_value( 'WP_CONTENT_DIR' ), '/' );
		}
		return $this->resolve_wordpress_root() . '/wp-content';
	}

	/**
	 * Register the fatal-error shutdown handler for this request.
	 *
	 * @return void
	 */
	private function register_shutdown(): void {
		register_shutdown_function(
			function (): void {
				$this->handle_shutdown();
			}
		);
	}

	/**
	 * Lift the script time limit for the duration of a long operation, where allowed.
	 *
	 * @return void
	 */
	private function extend_time_limit(): void {
		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,Squiz.PHP.DiscouragedFunctions.Discouraged -- A long restore must outlive the host's web timeout, the accepted pattern for backup tooling; set_time_limit can be disabled by the host, so the call is best-effort and its failure must not abort the restore.
			@set_time_limit( 0 );
		}
	}

	/**
	 * Release the lock, clear progress, and close the archive stream.
	 *
	 * @param resource|null $source The open archive stream to close, or null.
	 * @return void
	 */
	private function finish( $source ): void {
		$this->lock->release();
		$this->clear_progress();
		if ( is_resource( $source ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the archive stream opened in this request; not a WP_Filesystem operation.
			fclose( $source );
		}
	}

	// -------------------------------------------------------------------------
	// Authorisation.
	// -------------------------------------------------------------------------

	/**
	 * Whether the current request is a permitted Restore action.
	 *
	 * Deny-by-default: the user must hold the managing capability and present a
	 * valid `pontifex_restore` nonce. Both halves must pass.
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
		return __( 'You do not have permission to restore backups, or your session has expired. Reload the page and try again.', 'pontifex' );
	}

	/**
	 * The message returned when the shared single-runner lock refuses a restore or rollback.
	 *
	 * Names whichever kind of operation is actually holding the lock, so an
	 * operator refused because a backup is running is not told (misleadingly)
	 * that a restore is already running.
	 *
	 * @return string A human-readable refusal message.
	 */
	private function lock_busy_message(): string {
		if ( OperationLock::OP_BACKUP === $this->lock->current_holder() ) {
			return __( 'A backup is currently running. Please wait for it to finish before restoring or rolling back.', 'pontifex' );
		}
		return __( 'A restore is already running. Please wait for it to finish.', 'pontifex' );
	}

	/**
	 * The message returned when a restore fails.
	 *
	 * The underlying error is recorded in the log; the operator sees a plain
	 * sentence rather than an exception string, and is told whether the site was
	 * automatically rolled back.
	 *
	 * @param Throwable $error     The failure (kept to keep the signature honest; not echoed).
	 * @param bool|null $recovered Recovery outcome: true if the site was auto-rolled back to
	 *                             its pre-restore state, false if that recovery also failed,
	 *                             or null when no recovery was attempted (the site was not
	 *                             changed, or this is a rollback failure).
	 * @return string A human-readable failure message.
	 */
	private function failure_message( Throwable $error, ?bool $recovered = null ): string {
		unset( $error );
		if ( true === $recovered ) {
			return __( 'The restore failed, so your site was automatically rolled back to its state before the restore. Check the Pontifex log for details.', 'pontifex' );
		}
		if ( false === $recovered ) {
			return __( 'The restore failed and automatic recovery also failed — your site may be partially restored. Roll back to the most recent safety archive, or restore another backup. Check the Pontifex log for details.', 'pontifex' );
		}
		return __( 'The restore could not be completed; your site was not changed. Check the Pontifex log for details.', 'pontifex' );
	}

	// -------------------------------------------------------------------------
	// Progress transient and lock.
	// -------------------------------------------------------------------------

	/**
	 * Build a throttled byte-progress callback for a known-total phase.
	 *
	 * Accumulates each chunk's byte count and writes it against the total at most
	 * once every {@see self::PROGRESS_THROTTLE_SECONDS}, so the bar advances
	 * smoothly through a large entry without unbounded option writes.
	 *
	 * @param string $phase The phase the bytes belong to.
	 * @param int    $total The phase's total bytes, the progress denominator.
	 * @return callable(int): void The byte callback.
	 */
	private function byte_callback( string $phase, int $total ): callable {
		$bytes_done = 0;
		$last       = 0.0;
		return function ( int $bytes ) use ( &$bytes_done, &$last, $phase, $total ): void {
			$bytes_done += $bytes;
			$now         = microtime( true );
			if ( ( $now - $last ) >= self::PROGRESS_THROTTLE_SECONDS ) {
				$this->set_progress( $phase, $bytes_done, $total );
				$last = $now;
			}
		};
	}

	/**
	 * Write the running phase and byte progress to the transient.
	 *
	 * @param string $phase       The current phase.
	 * @param int    $bytes_done  Bytes processed so far in the phase.
	 * @param int    $bytes_total The phase total, the progress denominator.
	 * @return void
	 */
	private function set_progress( string $phase, int $bytes_done, int $bytes_total ): void {
		set_transient(
			self::PROGRESS_TRANSIENT,
			array(
				'phase'       => $phase,
				'bytes_done'  => $bytes_done,
				'bytes_total' => $bytes_total,
			),
			self::PROGRESS_TTL
		);
	}

	/**
	 * Clear the progress transient once an operation ends.
	 *
	 * @return void
	 */
	private function clear_progress(): void {
		delete_transient( self::PROGRESS_TRANSIENT );
	}

	/**
	 * The job store, rooted at the wp-content directory.
	 *
	 * Built for the default OperationLock's backup-liveness check (a restore
	 * or rollback must never slip in while a job-backed backup is still
	 * genuinely running, even between its cron ticks). Derived from the
	 * Environment seam rather than the (admin-only) BackupStore, so it is
	 * available before any store-specific wiring.
	 *
	 * @return JobStore The job store.
	 */
	private function jobs_store(): JobStore {
		return new JobStore( $this->resolve_content_root() );
	}

	// -------------------------------------------------------------------------
	// Counters.
	// -------------------------------------------------------------------------

	/**
	 * Whether the operator ticked "relink this backup to this site".
	 *
	 * The nonce is already verified by {@see self::is_authorised()}. A checkbox, so
	 * the target is never a typed address: when set, the restore rewrites the
	 * backup's own URL to this destination's site_url() afterwards. Absent (the box
	 * unticked) means an ordinary same-URL restore.
	 *
	 * @return bool True when the relink box is ticked.
	 */
	private function migrate_requested(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The nonce is verified in is_authorised() before this method runs.
		return isset( $_POST['migrate'] ) && '' !== sanitize_text_field( wp_unslash( (string) $_POST['migrate'] ) );
	}

	/**
	 * Read-modify-write a stored counters option by a delta.
	 *
	 * Mirrors ImportCommand's counter handling so a browser restore updates the
	 * same Overview figures as a CLI import. The option and its byte-total key are
	 * parameters so the same routine maintains both the import counters (default)
	 * and the separate rollback counters.
	 *
	 * @param array<string, int> $delta     The amounts to add, keyed by counter name.
	 * @param string             $option    The wp_options key to update. Defaults to the import counters.
	 * @param string             $bytes_key The counter key holding the byte total. Defaults to bytes_imported.
	 * @return void
	 */
	private function bump_counters( array $delta, string $option = self::STATS_OPTION, string $bytes_key = 'bytes_imported' ): void {
		$defaults = array(
			'attempted' => 0,
			'succeeded' => 0,
			'failed'    => 0,
			$bytes_key  => 0,
		);

		$current = $this->wordpress_context->option_value( $option, $defaults );
		$current = is_array( $current ) ? $current : array();

		$merged = array();
		foreach ( array_keys( $defaults ) as $key ) {
			$merged[ $key ] = $this->counter_int( $current, $key ) + $this->counter_int( $delta, $key );
		}

		$this->wordpress_context->save_option( $option, $merged );
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
