<?php
/**
 * Pontifex Rollback command — restores the most recent pre-import safety archive.
 *
 * @package Pontifex\Cli
 */

declare(strict_types=1);

namespace Pontifex\Cli;

use RuntimeException;
use Throwable;
use WP_CLI;
use Psr\Log\LoggerInterface;
use Pontifex\Archive\Codec\CodecRegistry;
use Pontifex\Archive\Reader\EntryReader;
use Pontifex\Environment\Environment;
use Pontifex\Environment\RealEnvironment;
use Pontifex\Job\JobStore;
use Pontifex\Lock\OperationLock;
use Pontifex\Log\FileLogger;
use Pontifex\Manifest\WpdbAdapter;
use Pontifex\Restore\DatabaseWriter;
use Pontifex\Restore\FileWriter;
use Pontifex\Restore\RestoreRunner;
use Pontifex\Restore\RestoreRunnerInterface;
use Pontifex\Rollback\RollbackStore;
use Pontifex\Rollback\RollbackStoreInterface;
use Pontifex\WordPress\RealWordPressContext;
use Pontifex\WordPress\WordPressContext;

/**
 * `wp pontifex rollback` — undo the most recent import by restoring its safety archive.
 *
 * Before each `wp pontifex import`, Pontifex writes a safety archive of the
 * current site (unless `--no-rollback-archive`). This command restores the most
 * recent one — the undo button for a destructive import. Like import, it
 * restores to the **same URL** and overwrites the live site, so it confirms
 * before acting (unless `--yes`) and offers `--dry-run`.
 *
 * ## OPTIONS
 *
 * [--dry-run]
 * : Read and verify the safety archive without writing anything. Reports what
 *   would be restored, then stops. Touches nothing.
 *
 * [--yes]
 * : Skip the confirmation prompt and proceed immediately.
 *
 * ## EXAMPLES
 *
 *     wp pontifex rollback
 *     wp pontifex rollback --dry-run
 *     wp pontifex rollback --yes
 *
 * @when after_wp_load
 */
final class RollbackCommand {

	/**
	 * The wp_options key holding the rollback counters (read by the admin Overview).
	 *
	 * A rollback is an undo, not a transfer, so it is counted separately from the
	 * import counters and is not added to the transfer history.
	 *
	 * @var string
	 */
	private const STATS_OPTION = 'pontifex_rollback_stats';


	/**
	 * The Environment abstraction this command queries.
	 *
	 * Used only by the default wiring (ABSPATH for the restore root,
	 * WP_CONTENT_DIR/WP_DEBUG for the logger and the rollback store); when a
	 * store, runner and logger are injected, it is never touched.
	 *
	 * @var Environment
	 */
	private Environment $environment;

	/**
	 * The WordPressContext abstraction this command queries.
	 *
	 * Supplies the wpdb instance for the default DatabaseWriter.
	 *
	 * @var WordPressContext
	 */
	private WordPressContext $wordpress_context;

	/**
	 * The store that locates the most recent safety archive.
	 *
	 * Optional in the constructor: when null, the command builds one rooted at
	 * WP_CONTENT_DIR. Tests inject a fake fulfilling RollbackStoreInterface.
	 *
	 * @var RollbackStoreInterface|null
	 */
	private ?RollbackStoreInterface $store;

	/**
	 * The restore engine used to replay the safety archive.
	 *
	 * Optional in the constructor: when null, the command wires one up from a
	 * fresh EntryReader + FileWriter + DatabaseWriter. Tests inject a fake.
	 *
	 * @var RestoreRunnerInterface|null
	 */
	private ?RestoreRunnerInterface $restore_runner;

	/**
	 * The PSR-3 logger this command records run milestones to.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * The progress reporter that shows rollback progress.
	 *
	 * @var ProgressReporter
	 */
	private ProgressReporter $progress;

	/**
	 * The shared single-runner lock, contended with export and import.
	 *
	 * Optional in the constructor: when null, {@see self::operation_lock()}
	 * builds a default OperationLock lazily, at the point __invoke() needs it
	 * — not in the constructor, because building its default JobStore needs
	 * WP_CONTENT_DIR/ABSPATH, which is not available to every test that
	 * constructs this command. Tests inject a fake fulfilling the same class,
	 * or a real one over mocked collaborators.
	 *
	 * @var OperationLock|null
	 */
	private ?OperationLock $lock;

	/**
	 * Construct a RollbackCommand instance.
	 *
	 * WP-CLI registers the command via its class name and does not pass
	 * constructor arguments, so all parameters are optional and default to real
	 * implementations. Tests pass mocks explicitly.
	 *
	 * @param Environment|null            $environment       Optional. Defaults to a fresh RealEnvironment.
	 * @param WordPressContext|null       $wordpress_context Optional. Defaults to a fresh RealWordPressContext.
	 * @param RollbackStoreInterface|null $store             Optional. When null, a store rooted at WP_CONTENT_DIR is built.
	 * @param RestoreRunnerInterface|null $restore_runner    Optional. When null, a concrete RestoreRunner is built at run time.
	 * @param LoggerInterface|null        $logger            Optional. When null, a FileLogger writing under wp-content/pontifex/logs is used.
	 * @param ProgressReporter|null       $progress          Optional. When null, a WpCliProgressBar driving WP-CLI's native progress bar is used.
	 * @param OperationLock|null          $lock              Optional. When null, a default OperationLock is built lazily at run time.
	 */
	public function __construct(
		?Environment $environment = null,
		?WordPressContext $wordpress_context = null,
		?RollbackStoreInterface $store = null,
		?RestoreRunnerInterface $restore_runner = null,
		?LoggerInterface $logger = null,
		?ProgressReporter $progress = null,
		?OperationLock $lock = null
	) {
		$this->environment       = $environment ?? new RealEnvironment();
		$this->wordpress_context = $wordpress_context ?? new RealWordPressContext();
		$this->store             = $store;
		$this->restore_runner    = $restore_runner;
		$this->logger            = $logger ?? $this->build_default_logger();
		$this->progress          = $progress ?? new WpCliProgressBar();
		$this->lock              = $lock;
	}

	/**
	 * The shared OperationLock, built lazily on first use.
	 *
	 * Deferred past the constructor because its default JobStore needs
	 * WP_CONTENT_DIR/ABSPATH resolved through {@see self::resolve_content_root()},
	 * which is only guaranteed once the command actually runs.
	 *
	 * @return OperationLock The lock to acquire before a real (non-dry-run) rollback.
	 */
	private function operation_lock(): OperationLock {
		if ( null === $this->lock ) {
			$this->lock = new OperationLock( $this->wordpress_context, new JobStore( $this->resolve_content_root() ) );
		}
		return $this->lock;
	}

	/**
	 * Release the site-operation lock if this command still holds it at shutdown.
	 *
	 * A WP-CLI command ends via exit() on WP_CLI::error/success/halt, and a PHP
	 * fatal ends it abruptly — both skip the finally that normally releases. This
	 * shutdown handler is the backstop that clears the holder transient so a
	 * failed or fatally-killed command cannot wedge every site operation for the
	 * lock's full TTL. It no-ops when the finally already released (is_held() is
	 * false), so a clean run releases exactly once.
	 *
	 * @return void
	 */
	public function release_lock_on_shutdown(): void {
		if ( null !== $this->lock && $this->lock->is_held() ) {
			$this->lock->release();
		}
	}

	/**
	 * The WP-CLI command entry point.
	 *
	 * Finds the most recent safety archive, confirms (unless --yes/--dry-run),
	 * then restores it over the current site — or, under --dry-run, verifies it
	 * without writing. Exits with a clear error when there is nothing to roll
	 * back to.
	 *
	 * @param array<int, string>         $positional_args  Positional arguments. Unused for `rollback`.
	 * @param array<string, string|bool> $associative_args Associative `--flag` arguments (`--dry-run`, `--yes`).
	 * @return void
	 * @throws Throwable Re-thrown after logging if the restore fails.
	 */
	public function __invoke( array $positional_args, array $associative_args ): void {

		$dry_run      = isset( $associative_args['dry-run'] ) && false !== $associative_args['dry-run'];
		$skip_confirm = isset( $associative_args['yes'] ) && false !== $associative_args['yes'];

		// 1. Find the most recent safety archive (exits with an error if none).
		$store        = $this->store ?? $this->build_default_store();
		$archive_path = $this->require_most_recent( $store );

		// 2. Announce what will be restored.
		$this->print_scope( $archive_path );

		// 3. Confirm (unless --yes, or --dry-run which changes nothing).
		if ( ! $dry_run && ! $skip_confirm ) {
			WP_CLI::confirm(
				sprintf( /* translators: %s: the safety archive path */ __( 'Restore the safety archive %s over the current site? This undoes your most recent import.', 'pontifex' ), $archive_path ),
				$associative_args
			);
		}

		// 4. Open the safety archive and wire the restore engine.
		$source         = $this->open_source( $archive_path );
		$restore_runner = $this->restore_runner ?? $this->build_default_restore_runner();

		// 4a. Single-runner lock: acquire only now, after every exit-prone step above
		// (finding the safety archive, the confirmation prompt, opening the archive)
		// has already passed. Each of those exits the process via WP_CLI::error() or
		// a declined WP_CLI::confirm(), which skips the finally that releases the
		// lock; acquiring this late means none of them can ever leave the holder
		// transient set behind a refusal or a decline. A dry-run touches nothing
		// (like the admin Restore screen's preview()), so it takes no lock.
		$lock = null;
		if ( ! $dry_run ) {
			$lock = $this->operation_lock();
			if ( ! $lock->acquire( OperationLock::OP_ROLLBACK ) ) {
				WP_CLI::error( sprintf( /* translators: %s: the kind of operation currently running */ __( 'Another Pontifex operation is already running (%s). Wait for it to finish, or resume it, then retry.', 'pontifex' ), $lock->current_holder() ?? 'unknown' ) );
			}
			$this->lock = $lock;
			register_shutdown_function( array( $this, 'release_lock_on_shutdown' ) );
		}

		$entry_total = 0;
		$on_entry    = function ( int $done, int $total ) use ( &$entry_total ): void {
			if ( 1 === $done ) {
				$this->progress->start( $total, 'Rolling back' );
			}
			$entry_total = $total;
			$this->progress->advance();
		};

		try {
			if ( $dry_run ) {
				$this->logger->info( 'Rollback dry-run started.', array( 'archive' => $archive_path ) );

				$restore_runner->verify( $source, $on_entry );
				$this->progress->finish();

				$this->logger->info(
					'Rollback dry-run complete.',
					array(
						'archive' => $archive_path,
						'entries' => $entry_total,
					)
				);

				$this->print_dry_run_summary( $archive_path, $entry_total );
			} else {
				$this->logger->info( 'Rollback started.', array( 'archive' => $archive_path ) );

				$restore_runner->restore( $source, $on_entry );
				$this->progress->finish();

				$this->logger->info(
					'Rollback complete.',
					array(
						'archive' => $archive_path,
						'entries' => $entry_total,
					)
				);

				$this->print_summary( $archive_path, $entry_total );

				// The rollback replayed the database with raw SQL, so flush WordPress's
				// stale option cache before recording, or the counter write is lost
				// (see RestoreController for the full rationale).
				$this->wordpress_context->flush_cache();
				$this->bump_counters(
					array(
						'attempted'         => 1,
						'succeeded'         => 1,
						'bytes_rolled_back' => $this->archive_size( $archive_path ),
					)
				);
			}
		} catch ( Throwable $error ) {
			$this->logger->error(
				'Rollback failed.',
				array(
					'archive'   => $archive_path,
					'exception' => $error,
				)
			);
			if ( ! $dry_run ) {
				$this->bump_counters(
					array(
						'attempted' => 1,
						'failed'    => 1,
					)
				);
			}
			throw $error;
		} finally {
			if ( null !== $lock ) {
				$lock->release();
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing a stream resource opened in this method; not a WP_Filesystem operation.
			fclose( $source );
		}
	}


	// -------------------------------------------------------------------------
	// Archive selection and opening.
	// -------------------------------------------------------------------------

	/**
	 * Return the most recent safety archive, or exit with a clear error.
	 *
	 * @param RollbackStoreInterface $store The store to query.
	 * @return string The absolute path of the most recent safety archive.
	 */
	private function require_most_recent( RollbackStoreInterface $store ): string {
		$archive_path = $store->most_recent();
		if ( null === $archive_path ) {
			WP_CLI::error(
				'No safety archive to roll back to. A safety archive is written automatically before each import, unless you pass --no-rollback-archive.'
			);
		}
		return (string) $archive_path;
	}

	/**
	 * Open the safety archive for reading.
	 *
	 * Exits via WP_CLI::error if fopen fails.
	 *
	 * @param string $archive_path Absolute path to the safety archive to read.
	 * @return resource
	 */
	private function open_source( string $archive_path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- Opening the safety archive as a stream; @ traps an unopenable-file warning that we convert to a WP_CLI error below.
		$source = @fopen( $archive_path, 'rb' );
		if ( false === $source ) {
			WP_CLI::error(
				sprintf( 'Could not open the safety archive for reading: %s', PathRedactor::from_environment()->redact( $archive_path ) )
			);
		}
		return $source;
	}


	// -------------------------------------------------------------------------
	// Per-run wiring.
	// -------------------------------------------------------------------------

	/**
	 * Build a RollbackStore rooted at WP_CONTENT_DIR.
	 *
	 * Reads WP_CONTENT_DIR through the Environment seam so tests can substitute
	 * a fixture path; falls back to the system temp directory only when the
	 * constant is absent (which should not happen inside WordPress).
	 *
	 * @return RollbackStore A store over wp-content/pontifex/rollback.
	 */
	private function build_default_store(): RollbackStore {
		$content_dir = $this->environment->is_constant_defined( 'WP_CONTENT_DIR' )
			? (string) $this->environment->constant_value( 'WP_CONTENT_DIR' )
			: sys_get_temp_dir();

		return new RollbackStore( $content_dir );
	}

	/**
	 * Build a RestoreRunner from the default collaborators.
	 *
	 * Identical to ImportCommand's wiring: an EntryReader with the v0.1.0
	 * default codecs, a FileWriter rooted at the WordPress installation, and a
	 * DatabaseWriter over the real $wpdb.
	 *
	 * @return RestoreRunner
	 */
	private function build_default_restore_runner(): RestoreRunner {
		$entry_reader    = new EntryReader( CodecRegistry::with_defaults() );
		$file_writer     = new FileWriter( $this->resolve_wordpress_root() );
		$database_writer = new DatabaseWriter( new WpdbAdapter( $this->wordpress_context->wpdb_instance() ) );
		return new RestoreRunner(
			$entry_reader,
			$file_writer,
			$database_writer,
			null,
			$this->wordpress_context->convert_hr_to_bytes( $this->environment->ini_get( 'memory_limit' ) )
		);
	}

	/**
	 * Build the default file logger when the caller supplies none.
	 *
	 * @return LoggerInterface
	 */
	private function build_default_logger(): LoggerInterface {
		$content_dir = $this->environment->is_constant_defined( 'WP_CONTENT_DIR' )
			? (string) $this->environment->constant_value( 'WP_CONTENT_DIR' )
			: sys_get_temp_dir();

		$debug_enabled = $this->environment->is_constant_defined( 'WP_DEBUG' )
			&& (bool) $this->environment->constant_value( 'WP_DEBUG' );

		return new FileLogger( $content_dir . '/pontifex/logs', $debug_enabled, protect_directory: true );
	}

	/**
	 * Resolve the WordPress installation root for the restore.
	 *
	 * @return string
	 * @throws RuntimeException If ABSPATH is not defined (should never happen inside a WordPress request).
	 */
	private function resolve_wordpress_root(): string {
		if ( ! $this->environment->is_constant_defined( 'ABSPATH' ) ) {
			throw new RuntimeException( 'RollbackCommand: ABSPATH is not defined; is WordPress loaded?' );
		}
		return rtrim( (string) $this->environment->constant_value( 'ABSPATH' ), '/' );
	}

	/**
	 * Resolve the wp-content root, for the shared lock's default job store.
	 *
	 * Reads WP_CONTENT_DIR through the Environment abstraction, falling back to
	 * ABSPATH/wp-content (WordPress's own default for the constant) when it is
	 * not defined, so the resolver still works outside a full WordPress
	 * request, as in unit tests.
	 *
	 * @return string The absolute path of the wp-content directory.
	 * @throws RuntimeException If WP_CONTENT_DIR is undefined and ABSPATH is too (should never happen inside a WordPress request).
	 */
	private function resolve_content_root(): string {
		if ( $this->environment->is_constant_defined( 'WP_CONTENT_DIR' ) ) {
			return rtrim( (string) $this->environment->constant_value( 'WP_CONTENT_DIR' ), '/' );
		}
		return $this->resolve_wordpress_root() . '/wp-content';
	}


	// -------------------------------------------------------------------------
	// Output formatting.
	// -------------------------------------------------------------------------

	/**
	 * Print what the rollback will restore, and its same-URL scope.
	 *
	 * @param string $archive_path The safety archive that will be restored.
	 * @return void
	 */
	private function print_scope( string $archive_path ): void {
		WP_CLI::log( sprintf( /* translators: %s: the safety archive path */ __( 'Rolling back to the most recent safety archive: %s', 'pontifex' ), PathRedactor::from_environment()->redact( $archive_path ) ) );
		WP_CLI::log( __( 'Restoring to the same site URL only; no URL rewriting.', 'pontifex' ) );
	}

	/**
	 * Print the final post-rollback summary line.
	 *
	 * @param string $archive_path The archive that was restored.
	 * @param int    $entry_count  How many entries were restored.
	 * @return void
	 */
	private function print_summary( string $archive_path, int $entry_count ): void {
		WP_CLI::log(
			sprintf( /* translators: 1: number of entries restored, 2: the archive path */ __( 'Rolled back %1$d entries from %2$s', 'pontifex' ), $entry_count, $archive_path )
		);
	}

	/**
	 * Print the dry-run summary line, making clear nothing was changed.
	 *
	 * @param string $archive_path The archive that was verified.
	 * @param int    $entry_count  How many entries were verified.
	 * @return void
	 */
	private function print_dry_run_summary( string $archive_path, int $entry_count ): void {
		WP_CLI::log(
			sprintf( /* translators: 1: number of entries verified, 2: the archive path */ __( 'Dry run complete: %1$d entries verified in %2$s. No changes were made.', 'pontifex' ), $entry_count, $archive_path )
		);
	}

	/**
	 * Read-modify-write the rollback counters by a delta.
	 *
	 * Mirrors ImportCommand's counter handling against the rollback option, so a CLI
	 * rollback shows on the admin Overview's Rollbacks row.
	 *
	 * @param array<string, int> $delta The amounts to add, keyed by counter name.
	 * @return void
	 */
	private function bump_counters( array $delta ): void {
		$current = $this->wordpress_context->option_value(
			self::STATS_OPTION,
			array(
				'attempted'         => 0,
				'succeeded'         => 0,
				'failed'            => 0,
				'bytes_rolled_back' => 0,
			)
		);
		$current = is_array( $current ) ? $current : array();

		$merged = array();
		foreach ( array( 'attempted', 'succeeded', 'failed', 'bytes_rolled_back' ) as $key ) {
			$stored         = isset( $current[ $key ] ) && is_numeric( $current[ $key ] ) ? (int) $current[ $key ] : 0;
			$merged[ $key ] = $stored + ( $delta[ $key ] ?? 0 );
		}

		$this->wordpress_context->save_option( self::STATS_OPTION, $merged );
	}

	/**
	 * The size of the safety archive in bytes, or 0 if it cannot be read.
	 *
	 * Recorded as bytes_rolled_back so the Overview's Rollbacks row shows a size.
	 *
	 * @param string $archive_path Absolute path to the safety archive, already opened successfully.
	 * @return int
	 */
	private function archive_size( string $archive_path ): int {
		$size = filesize( $archive_path );
		return false !== $size ? $size : 0;
	}
}
