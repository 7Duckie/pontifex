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
use Pontifex\Archive\Reader\ArchiveReader;
use Pontifex\Archive\Reader\EntryReader;
use Pontifex\Environment\Environment;
use Pontifex\Environment\RealEnvironment;
use Pontifex\Exception\ArchiveNotTrustworthy;
use Pontifex\Exception\HostCannotComply;
use Pontifex\Exception\InvalidRequest;
use Pontifex\Exception\PontifexException;
use Pontifex\Job\JobStore;
use Pontifex\Lock\OperationLock;
use Pontifex\Log\FileLogger;
use Pontifex\Manifest\WpdbAdapter;
use Pontifex\Restore\DatabaseWriter;
use Pontifex\Restore\FileWriter;
use Pontifex\Restore\RestoreRunner;
use Pontifex\Restore\RestoreRunnerInterface;
use Pontifex\Restore\SourceTablePrefix;
use Pontifex\Rollback\RollbackStore;
use Pontifex\Rollback\RollbackStoreInterface;
use Pontifex\WordPress\RealWordPressContext;
use Pontifex\WordPress\WordPressContext;
use Pontifex\WordPress\WordPressRoot;

/**
 * `wp pontifex rollback` — undo the most recent import by restoring its safety archive.
 *
 * Before each `wp pontifex import`, Pontifex writes a safety archive of the
 * current site (unless `--no-rollback-archive`). This command restores the most
 * recent one — the undo button for a destructive import. Like import, it
 * restores to the **same URL** and overwrites the live site, so it confirms
 * before acting (unless `--yes`) and offers `--dry-run`.
 *
 * It exits 0 when the safety archive is replayed (or the dry run completes),
 * and non-zero when it is refused or fails — reporting which of the three
 * kinds of refusal happened (ADR 0022): the archive cannot be trusted, this
 * host cannot comply, or the request itself needs correcting.
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
	 * A failure is not re-thrown. It is logged, reported as a readable verdict
	 * naming which kind of refusal it was, and the command halts non-zero — so
	 * an operator sees why it stopped rather than a stack trace, and a script
	 * still sees a failing exit code.
	 *
	 * @param array<int, string>         $positional_args  Positional arguments. Unused for `rollback`.
	 * @param array<string, string|bool> $associative_args Associative `--flag` arguments (`--dry-run`, `--yes`).
	 * @return void
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
		$restore_runner = $this->restore_runner ?? $this->build_default_restore_runner( $source );

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

		// The failure the run ended on, or null when it succeeded. Recorded rather than
		// re-thrown, and acted on only after the finally below: WP_CLI::halt() calls
		// exit(), and PHP does not run a finally block when exit() is called — so
		// halting inside the catch would skip the lock release and leave the site's
		// operation lock to the shutdown backstop. That backstop is the last line of
		// defence, not the primary one.
		$failure = null;

		// How many entries actually landed. RestoreRunner calls the progress callback
		// after it has written each entry, so a non-zero count here is proof the replay
		// had begun — which is what decides whether a failure leaves the site half
		// rolled back or entirely untouched.
		$entries_done = 0;

		$entry_total = 0;
		$on_entry    = function ( int $done, int $total ) use ( &$entry_total, &$entries_done ): void {
			if ( 1 === $done ) {
				$this->progress->start( $total, 'Rolling back' );
			}
			$entry_total  = $total;
			$entries_done = $done;
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
			$failure = $error;
		} finally {
			if ( null !== $lock ) {
				$lock->release();
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing a stream resource opened in this method; not a WP_Filesystem operation.
			fclose( $source );
		}

		// Report and halt here, outside the try, so the finally above has already
		// released the lock and closed the archive. See the note on $failure at its
		// declaration. Why it stopped comes first, then what state that leaves the
		// site in — the same order import prints its verdict and its recovery warning.
		if ( null !== $failure ) {
			$this->print_failure_verdict( $archive_path, $failure, $dry_run );
			if ( ! $dry_run ) {
				$this->warn_if_partly_rolled_back( $entries_done );
			}
			WP_CLI::halt( 1 );
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
	 * DatabaseWriter over the real $wpdb, wired with both table prefixes the
	 * same way — the source prefix from the archive's own provenance when it
	 * carries one, or derived from its table names via
	 * {@see SourceTablePrefix::resolve()} when it does not. A safety archive is
	 * written by this site, so in the ordinary case its recorded (or derived)
	 * prefix already agrees with the destination and this is a no-op — but
	 * leaving a restore path unwired is how these gaps start, and consistency
	 * costs nothing here. This command's own $this->logger is passed through
	 * as the runner's optional sixth argument, so the few things RestoreRunner
	 * itself still only mentions in passing — a directory mode that could not
	 * be restored, temp artefacts an interrupted earlier restore left behind
	 * and this run swept up — reach the real per-transfer log file instead of
	 * the NullLogger a caller that passes nothing would silently get.
	 *
	 * @param resource $source The open, seekable safety-archive stream, read here for its provenance, manifest, and (when needed) its db_chunk headers.
	 * @return RestoreRunner
	 */
	private function build_default_restore_runner( $source ): RestoreRunner {
		$entry_reader    = new EntryReader( CodecRegistry::with_defaults() );
		$archive_reader  = new ArchiveReader( $source );
		$source_prefix   = SourceTablePrefix::resolve( $archive_reader->provenance()->table_prefix(), $archive_reader->manifest(), $source, $entry_reader );
		$dest_prefix     = self::valid_table_prefix( $this->wordpress_context->wpdb_prefix() );
		$file_writer     = new FileWriter( $this->resolve_wordpress_root() );
		$database_writer = new DatabaseWriter( new WpdbAdapter( $this->wordpress_context->wpdb_instance() ), $source_prefix, $dest_prefix );
		return new RestoreRunner(
			$entry_reader,
			$file_writer,
			$database_writer,
			null,
			$this->wordpress_context->convert_hr_to_bytes( $this->environment->ini_get( 'memory_limit' ) ),
			$this->logger
		);
	}

	/**
	 * Validate the destination table prefix to a sane identifier shape, or drop it.
	 *
	 * Used for the destination prefix only — this site's own, read from
	 * `$this->wordpress_context->wpdb_prefix()` — never the source prefix, which
	 * goes through {@see SourceTablePrefix::resolve()} instead. Returns the
	 * prefix only when it is a non-empty run of ASCII letters, digits, and
	 * underscores — the shape a WordPress table prefix always takes. Anything
	 * else yields '', which the DatabaseWriter reads as "no rewrite", so a
	 * malformed value can never reach a rewrite statement. Pure function.
	 *
	 * @param string|null $prefix The candidate prefix.
	 * @return string The prefix when valid, otherwise ''.
	 */
	private static function valid_table_prefix( ?string $prefix ): string {
		if ( null === $prefix || '' === $prefix ) {
			return '';
		}
		return 1 === preg_match( '/^[A-Za-z0-9_]+$/', $prefix ) ? $prefix : '';
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
		return WordPressRoot::resolve( $this->environment );
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
	 * Print why the rollback stopped, in terms an operator can act on.
	 *
	 * Three situations demand three different responses, and the exception's
	 * type is what tells them apart (ADR 0022): a safety archive that cannot be
	 * trusted means this undo is not available; a host that cannot comply means
	 * the archive may be fine and the server is the fixable problem; a wrong
	 * request means correct the invocation. A failure carrying none of those
	 * types is reported plainly rather than guessed at — most of the safety
	 * core still throws untyped exceptions, and inventing a kind for one would
	 * be worse than admitting we do not know.
	 *
	 * The advice for an untrustworthy archive is not import's. An import can be
	 * told to fetch a fresh copy of the backup; a safety archive is written
	 * automatically, in one copy, at the moment of the import it undoes. There
	 * is no fresh copy to fetch, and saying otherwise would send an operator
	 * looking for a file that has never existed.
	 *
	 * Both the message and the path go through the redactor, because an engine
	 * message routinely names an absolute path and this output is exactly what
	 * an operator pastes into a support thread.
	 *
	 * @param string    $archive_path The safety archive the rollback was replaying.
	 * @param Throwable $error        The failure that ended the run.
	 * @param bool      $dry_run      True when this was a rehearsal, so nothing was written.
	 * @return void
	 */
	private function print_failure_verdict( string $archive_path, Throwable $error, bool $dry_run ): void {
		$redactor = PathRedactor::from_environment();
		$message  = $redactor->redact( $error->getMessage() );
		$path     = $redactor->redact( $archive_path );

		WP_CLI::log( self::failure_headline( $dry_run, $error instanceof PontifexException ) );

		if ( $error instanceof ArchiveNotTrustworthy ) {
			WP_CLI::log(
				sprintf(
					/* translators: 1: the reason the safety archive was refused, 2: the safety archive path */
					__( 'This safety archive cannot be trusted: %1$s (%2$s)', 'pontifex' ),
					$message,
					$path
				)
			);
			WP_CLI::log( __( 'A safety archive is written automatically before an import and there is no second copy of it — to undo that import, restore a backup you took yourself.', 'pontifex' ) );
			return;
		}

		if ( $error instanceof HostCannotComply ) {
			WP_CLI::log(
				sprintf(
					/* translators: 1: the reason this host could not comply, 2: the safety archive path */
					__( 'This host cannot complete the rollback: %1$s (%2$s)', 'pontifex' ),
					$message,
					$path
				)
			);
			WP_CLI::log( __( 'The safety archive may be perfectly good — the problem is this server, and it is usually fixable.', 'pontifex' ) );
			return;
		}

		if ( $error instanceof InvalidRequest ) {
			WP_CLI::log(
				sprintf(
					/* translators: %s: what was wrong with the command that was run */
					__( 'The request needs correcting: %s', 'pontifex' ),
					$message
				)
			);
			return;
		}

		WP_CLI::log(
			sprintf(
				/* translators: 1: the failure message, 2: the archive path */
				__( 'The failure was: %1$s (%2$s)', 'pontifex' ),
				$message,
				$path
			)
		);
		WP_CLI::log( __( 'Full details are in the Pontifex log: wp-content/pontifex/logs/pontifex.log', 'pontifex' ) );
	}

	/**
	 * Warn that a failed rollback has left the site in a mixed state.
	 *
	 * A rollback that stops partway is the one failure in Pontifex with no undo
	 * behind it: some of the site is now the safety archive's copy and the rest
	 * is whatever the import left, and nothing will reconcile the two. Saying so
	 * is the difference between an operator checking the site and an operator
	 * assuming a failed command changed nothing.
	 *
	 * It is said only when entries actually landed. The restore engine refuses
	 * ahead of any write when it can — a preflight rejection, an unreadable
	 * archive — and warning about a half-restored site that was never touched
	 * would be a false alarm about the most alarming thing this tool can report.
	 *
	 * @param int $entries_done How many entries were written before the failure.
	 * @return void
	 */
	private function warn_if_partly_rolled_back( int $entries_done ): void {
		if ( $entries_done < 1 ) {
			return;
		}

		WP_CLI::warning(
			sprintf(
				/* translators: %d: number of entries restored before the rollback stopped */
				_n(
					'Your site is now part rolled back: %d entry was restored from the safety archive before it stopped, and everything else is as the import left it. Check the site before using it.',
					'Your site is now part rolled back: %d entries were restored from the safety archive before it stopped, and everything else is as the import left it. Check the site before using it.',
					$entries_done,
					'pontifex'
				),
				$entries_done
			)
		);
	}

	/**
	 * The opening line of a failure verdict: what happened, and to what.
	 *
	 * Two facts decide it. A refusal is a decision Pontifex made and can
	 * explain; anything else is a fault, and calling a fault a refusal would
	 * claim an intent that was not there. And a dry run changed nothing, which
	 * is the first thing an operator wants to know — on a real rollback
	 * {@see self::warn_if_partly_rolled_back()} reports the site's state
	 * instead, because there it is not something that can be promised.
	 *
	 * @param bool $dry_run    True when this was a rehearsal, so nothing was written.
	 * @param bool $is_refusal True when Pontifex refused deliberately (ADR 0022's marker).
	 * @return string
	 */
	private static function failure_headline( bool $dry_run, bool $is_refusal ): string {
		if ( $dry_run ) {
			return $is_refusal
				? __( 'Dry run: this rollback would be refused. Your site was not changed.', 'pontifex' )
				: __( 'Dry run: this rollback failed. Your site was not changed.', 'pontifex' );
		}

		return $is_refusal
			? __( 'Rollback refused.', 'pontifex' )
			: __( 'Rollback failed.', 'pontifex' );
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
