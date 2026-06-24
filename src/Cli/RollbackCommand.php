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
	 */
	public function __construct(
		?Environment $environment = null,
		?WordPressContext $wordpress_context = null,
		?RollbackStoreInterface $store = null,
		?RestoreRunnerInterface $restore_runner = null,
		?LoggerInterface $logger = null,
		?ProgressReporter $progress = null
	) {
		$this->environment       = $environment ?? new RealEnvironment();
		$this->wordpress_context = $wordpress_context ?? new RealWordPressContext();
		$this->store             = $store;
		$this->restore_runner    = $restore_runner;
		$this->logger            = $logger ?? $this->build_default_logger();
		$this->progress          = $progress ?? new WpCliProgressBar();
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
				sprintf( 'Restore the safety archive %s over the current site? This undoes your most recent import.', $archive_path ),
				$associative_args
			);
		}

		// 4. Open the safety archive and wire the restore engine.
		$source         = $this->open_source( $archive_path );
		$restore_runner = $this->restore_runner ?? $this->build_default_restore_runner();

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
			}
		} catch ( Throwable $error ) {
			$this->logger->error(
				'Rollback failed.',
				array(
					'archive'   => $archive_path,
					'exception' => $error,
				)
			);
			throw $error;
		} finally {
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
				sprintf( 'Could not open the safety archive for reading: %s', $archive_path )
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
		return new RestoreRunner( $entry_reader, $file_writer, $database_writer );
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
		WP_CLI::log( sprintf( 'Rolling back to the most recent safety archive: %s', $archive_path ) );
		WP_CLI::log( 'Restoring to the same site URL only; no URL rewriting.' );
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
			sprintf( 'Rolled back %d entries from %s', $entry_count, $archive_path )
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
			sprintf( 'Dry run complete: %d entries verified in %s. No changes were made.', $entry_count, $archive_path )
		);
	}
}
