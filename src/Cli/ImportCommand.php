<?php
/**
 * Pontifex Import command — restores a Pontifex archive over the current WordPress site.
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
use Pontifex\Archive\Format\Scope;
use Pontifex\Archive\Reader\ArchiveReader;
use Pontifex\Archive\Reader\EntryReader;
use Pontifex\Environment\Environment;
use Pontifex\Environment\RealEnvironment;
use Pontifex\Log\CompositeLogger;
use Pontifex\Log\FileLogger;
use Pontifex\Manifest\WpdbAdapter;
use Pontifex\Migrate\RewriteReport;
use Pontifex\Migrate\UrlMigrator;
use Pontifex\Migrate\UrlMigratorInterface;
use Pontifex\Restore\DatabaseWriter;
use Pontifex\Restore\FileWriter;
use Pontifex\Restore\RestoreRunner;
use Pontifex\Restore\RestoreRunnerInterface;
use Pontifex\Rollback\RollbackStore;
use Pontifex\Rollback\SafetyArchiver;
use Pontifex\Rollback\SafetyArchiverInterface;
use Pontifex\WordPress\RealWordPressContext;
use Pontifex\WordPress\WordPressContext;

/**
 * `wp pontifex import` — restore a Pontifex archive over the current WordPress site.
 *
 * Reads a single .wpmig archive and replays every entry: files back onto
 * the WordPress root, database chunks back into the database. By default the
 * restore is **content-only** — it writes the wp-content tree and the whole
 * database, and refuses an archive that would overwrite WordPress core or
 * wp-config.php; pass --whole-site to restore an entire-site archive onto a
 * fresh destination (ADR 0008). By default the restore is also to the
 * **same site URL**; passing --url=<new-url> additionally runs a
 * serialised-safe cross-URL migration over the restored database (ADR 0006),
 * with the pre-import safety archive as its undo.
 *
 * This is the dangerous half of Pontifex: it overwrites the live site.
 * It therefore confirms before acting (unless --yes), and offers
 * --dry-run, which reads and verifies the whole archive but writes
 * nothing.
 *
 * Before restoring, it writes a safety archive of the current site (unless
 * --no-rollback-archive), so a mistaken import can be undone with
 * `wp pontifex rollback`.
 *
 * ## OPTIONS
 *
 * <archive>
 * : Absolute filesystem path to the .wpmig archive to restore.
 *
 * [--url=<new-url>]
 * : Migrate the site to a new URL after restoring. Runs a serialised-safe
 *   search-replace over the restored database, rewriting the archive's source
 *   URL to <new-url>. Omit for a same-URL restore.
 *
 * [--whole-site]
 * : Restore an entire-site archive — WordPress core and wp-config.php included —
 *   not only wp-content. Required to restore a whole-site or legacy archive,
 *   which the default content-only restore refuses so it never overwrites a live
 *   site's core or wp-config.php. Use only when restoring onto a fresh, empty
 *   destination.
 *
 * [--dry-run]
 * : Read and verify the entire archive without writing anything to the
 *   site. Reports what would be restored, then stops. Touches nothing.
 *
 * [--yes]
 * : Skip the confirmation prompt and proceed immediately.
 *
 * [--no-rollback-archive]
 * : Skip the automatic pre-import safety archive. Faster and uses less
 *   disk, but the import cannot be undone with `wp pontifex rollback`.
 *
 * [--passphrase-stdin]
 * : Read the decryption passphrase as one line from STDIN instead of
 *   prompting. Needed for an encrypted archive in a non-interactive run;
 *   ignored for an unencrypted archive.
 *
 * [--public-key=<path>]
 * : Verify the archive's Ed25519 signature against this public-key file (from
 *   `wp pontifex keygen`) BEFORE restoring. A signed archive whose signature
 *   fails is refused and nothing is written. Without it, a signed archive is
 *   restored with a warning that its signature was not verified.
 *
 * [--allow-unsafe-symlinks]
 * : Allow restoring symlink entries whose target points outside the site root
 *   (or is an absolute path). Refused by default, because a hostile archive can
 *   plant an escaping link that later code follows. Use only for an archive you
 *   trust.
 *
 * ## EXAMPLES
 *
 *     wp pontifex import /tmp/site.wpmig
 *     wp pontifex import /tmp/site.wpmig --whole-site --yes
 *     wp pontifex import /tmp/site.wpmig --url=https://new-site.example
 *     wp pontifex import /tmp/site.wpmig --dry-run
 *     wp pontifex import /tmp/site.wpmig --yes
 *     wp pontifex import /tmp/site.wpmig --no-rollback-archive
 *     pass show backup | wp pontifex import /tmp/site.wpmig --passphrase-stdin
 *     wp pontifex import /tmp/site.wpmig --public-key=/root/pontifex.pub
 *
 * @when after_wp_load
 */
final class ImportCommand {


	/**
	 * The wp_options key under which the import counters are stored.
	 *
	 * A separate row from the export counters: imports and exports are
	 * two different facts, so each verb keeps its own tally. Autoload
	 * off — written occasionally, read almost never.
	 */
	private const STATS_OPTION = 'pontifex_import_stats';

	/**
	 * The Environment abstraction this command queries.
	 *
	 * Injected so tests can substitute a mock. Used only by the default
	 * wiring (ABSPATH for the restore root, WP_CONTENT_DIR/WP_DEBUG for
	 * the logger); when a RestoreRunner and logger are injected, it is
	 * never touched.
	 *
	 * @var Environment
	 */
	private Environment $environment;

	/**
	 * The WordPressContext abstraction this command queries.
	 *
	 * Supplies the wpdb instance for the default DatabaseWriter, the
	 * counters seam (option_value / save_option), and format_size for
	 * the summary line.
	 *
	 * @var WordPressContext
	 */
	private WordPressContext $wordpress_context;

	/**
	 * The restore engine used to replay the archive.
	 *
	 * Optional in the constructor: when null, the command wires one up
	 * from a fresh EntryReader + FileWriter + DatabaseWriter. Tests
	 * inject a fake fulfilling the RestoreRunnerInterface contract — the
	 * reason that interface exists.
	 *
	 * @var RestoreRunnerInterface|null
	 */
	private ?RestoreRunnerInterface $restore_runner;

	/**
	 * The PSR-3 logger this command records run milestones to.
	 *
	 * Injected so tests can substitute a spy or a NullLogger. When null,
	 * the constructor builds a FileLogger writing under
	 * wp-content/pontifex/logs.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * Whether the logger above was built by default (not injected).
	 *
	 * Only a defaulted logger writes to real files, so the per-transfer log is
	 * teed onto it alone; a test that injects a spy logger never touches disk.
	 *
	 * @var bool
	 */
	private bool $logger_was_defaulted;

	/**
	 * The progress reporter that shows restore progress.
	 *
	 * Injected so tests can substitute a silent NullProgressBar. When
	 * null, a WpCliProgressBar driving WP-CLI's native progress bar is
	 * used.
	 *
	 * @var ProgressReporter
	 */
	private ProgressReporter $progress;

	/**
	 * The safety archiver that writes a pre-import undo archive.
	 *
	 * Optional in the constructor: when null, the command wires one rooted at
	 * WP_CONTENT_DIR. Tests inject a fake fulfilling SafetyArchiverInterface.
	 *
	 * @var SafetyArchiverInterface|null
	 */
	private ?SafetyArchiverInterface $safety_archiver;

	/**
	 * The cross-URL migrator used when --url is supplied.
	 *
	 * Optional in the constructor: when null and --url is given, the command
	 * wires a UrlMigrator over the real $wpdb. Tests inject a fake fulfilling
	 * UrlMigratorInterface — the seam that exists for exactly that.
	 *
	 * @var UrlMigratorInterface|null
	 */
	private ?UrlMigratorInterface $url_migrator;

	/**
	 * The source of the operator's decryption passphrase.
	 *
	 * Injected so tests can supply a fixed passphrase without a terminal or a
	 * piped STDIN. When null, a CliPassphraseSource (hidden prompt + STDIN) is used.
	 *
	 * @var PassphraseSource
	 */
	private PassphraseSource $passphrase_source;

	/**
	 * Construct an ImportCommand instance.
	 *
	 * WP-CLI registers the command via its class name and does not pass
	 * constructor arguments, so all parameters are optional and default
	 * to real implementations. Tests pass mocks explicitly.
	 *
	 * @param Environment|null             $environment       Optional. Defaults to a fresh RealEnvironment.
	 * @param WordPressContext|null        $wordpress_context Optional. Defaults to a fresh RealWordPressContext.
	 * @param RestoreRunnerInterface|null  $restore_runner    Optional. When null, the command builds a concrete RestoreRunner at run time.
	 * @param LoggerInterface|null         $logger            Optional. When null, a FileLogger writing under wp-content/pontifex/logs is used.
	 * @param ProgressReporter|null        $progress          Optional. When null, a WpCliProgressBar driving WP-CLI's native progress bar is used.
	 * @param SafetyArchiverInterface|null $safety_archiver   Optional. When null, a SafetyArchiver rooted at WP_CONTENT_DIR is built.
	 * @param UrlMigratorInterface|null    $url_migrator      Optional. When null and --url is given, a UrlMigrator over the real $wpdb is built.
	 * @param PassphraseSource|null        $passphrase_source Optional. When null, a CliPassphraseSource (hidden prompt + STDIN) is used.
	 */
	public function __construct(
		?Environment $environment = null,
		?WordPressContext $wordpress_context = null,
		?RestoreRunnerInterface $restore_runner = null,
		?LoggerInterface $logger = null,
		?ProgressReporter $progress = null,
		?SafetyArchiverInterface $safety_archiver = null,
		?UrlMigratorInterface $url_migrator = null,
		?PassphraseSource $passphrase_source = null
	) {
		$this->environment          = $environment ?? new RealEnvironment();
		$this->wordpress_context    = $wordpress_context ?? new RealWordPressContext();
		$this->restore_runner       = $restore_runner;
		$this->logger_was_defaulted = null === $logger;
		$this->logger               = $logger ?? $this->build_default_logger();
		$this->progress             = $progress ?? new WpCliProgressBar();
		$this->safety_archiver      = $safety_archiver;
		$this->url_migrator         = $url_migrator;
		$this->passphrase_source    = $passphrase_source ?? new CliPassphraseSource();
	}

	/**
	 * The WP-CLI command entry point.
	 *
	 * `__invoke` is the magic method WP-CLI dispatches to for a single-
	 * command class. Orchestrates: read the archive path, validate it,
	 * announce the same-URL scope, confirm (unless --yes/--dry-run),
	 * open the archive, then restore it — or, under --dry-run, verify it
	 * without writing.
	 *
	 * @param array<int, string>         $positional_args  Positional arguments. The first is the required archive path.
	 * @param array<string, string|bool> $associative_args Associative `--flag` arguments (`--dry-run`, `--yes`).
	 * @return void
	 * @throws Throwable Re-thrown after logging if the restore fails.
	 */
	public function __invoke( array $positional_args, array $associative_args ): void {

		// 1. Read and validate the archive path and flags.
		$archive_path = $this->require_archive_path( $positional_args );
		$dry_run      = isset( $associative_args['dry-run'] ) && false !== $associative_args['dry-run'];
		$skip_confirm = isset( $associative_args['yes'] ) && false !== $associative_args['yes'];
		// WP-CLI delivers the documented `--no-rollback-archive` flag as
		// array( 'rollback-archive' => false ) via its --no-<name> convention, not
		// as a 'no-rollback-archive' key — so read that form, or the flag is ignored.
		$no_rollback      = array_key_exists( 'rollback-archive', $associative_args )
			&& false === $associative_args['rollback-archive'];
		$passphrase_stdin = isset( $associative_args['passphrase-stdin'] ) && false !== $associative_args['passphrase-stdin'];
		$allow_unsafe     = isset( $associative_args['allow-unsafe-symlinks'] ) && false !== $associative_args['allow-unsafe-symlinks'];
		$whole_site       = isset( $associative_args['whole-site'] ) && false !== $associative_args['whole-site'];
		$public_key       = $this->resolve_public_key( $associative_args );
		$target_url       = $this->require_target_url( $associative_args );

		$this->validate_archive_path( $archive_path );

		// 1a. For a real restore, tee a per-transfer log into the logs directory so
		// this import leaves a self-contained record (the input archive may be
		// read-only or elsewhere, so its file lives with the central log, not beside
		// the archive). A dry-run restores nothing, so it gets no per-transfer file.
		if ( ! $dry_run ) {
			$this->attach_transfer_log(
				fn (): string => $this->log_directory(),
				'import-' . gmdate( 'Ymd-His' ) . '.log'
			);
		}

		// 2. Announce the restore (and, with --url, the migration) scope, always.
		$this->print_scope( $target_url );

		// 3. Open the source archive for reading. Opened (and validated) before the
		// confirmation prompt so a forged signature or a scope mismatch refuses up
		// front, rather than after the operator has agreed to a destructive restore.
		$source = $this->open_source( $archive_path );

		// 3b. Signature gate: verify before anything is written, so a forged or
		// tampered archive never reaches the restore. A signed archive with no key
		// supplied is allowed but warned; an unsigned archive with a key is noted.
		$this->verify_signature_gate( $source, $public_key );

		// 3c. Scope gate: a default (content-only) restore refuses a whole-site or
		// legacy archive before any write, so it never overwrites a live site's core
		// or wp-config.php; --whole-site allows it (ADR 0008).
		$this->assert_scope_permits_restore( $source, $whole_site );

		// 4. Confirm with the user (unless --yes, or --dry-run which changes nothing).
		if ( ! $dry_run && ! $skip_confirm ) {
			WP_CLI::confirm( sprintf( /* translators: %s: the archive path */ __( 'Restore %s over the current site?', 'pontifex' ), $archive_path ), $associative_args );
		}

		// 5. Wire up the URL migrator when --url was given. The restore engine is wired
		// inside the try below, where opening the archive (to detect encryption and
		// collect the passphrase) is covered by the failure logging.
		$url_migrator = '' !== $target_url ? ( $this->url_migrator ?? $this->build_default_url_migrator() ) : null;

		// Import learns its entry total from the first callback (unlike export,
		// which counts entry plans up front), so the bar starts on entry one.
		$entry_total = 0;
		$on_entry    = function ( int $done, int $total ) use ( &$entry_total ): void {
			if ( 1 === $done ) {
				$this->progress->start( $total, 'Restoring archive' );
			}
			$entry_total = $total;
			$this->progress->advance();
		};

		try {
			// Wire the restore engine. For an encrypted archive this reads the salt and
			// collects the passphrase, so it sits inside the try where a failure is logged.
			// A default (content-only) restore restricts the file writer to the
			// wp-content tree as a write-boundary backstop behind the scope gate above.
			$required_prefix = $whole_site ? null : 'wp-content';
			$restore_runner  = $this->restore_runner ?? $this->build_default_restore_runner( $source, $passphrase_stdin, $allow_unsafe, $required_prefix );

			// With --url, read the source URL from the archive's provenance and
			// announce the migration before anything is written. Reading the
			// provenance also validates the archive up front.
			$source_url = '';
			if ( null !== $url_migrator ) {
				$source_url = $url_migrator->source_url( $source );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Rewinding the stream before the restore/verify walk re-reads it; not a WP_Filesystem operation.
				rewind( $source );
				$this->print_migration_plan( $source_url, $target_url );
			}

			if ( $dry_run ) {
				$this->logger->info( 'Import dry-run started.', array( 'archive' => $archive_path ) );

				$restore_runner->verify( $source, $on_entry );
				$this->progress->finish();

				$this->logger->info(
					'Import dry-run complete.',
					array(
						'archive' => $archive_path,
						'entries' => $entry_total,
					)
				);

				$this->print_dry_run_summary( $archive_path, $entry_total, $target_url );
			} else {
				$this->logger->info( 'Import started.', array( 'archive' => $archive_path ) );
				$this->bump_counters( array( 'attempted' => 1 ) );

				if ( ! $no_rollback ) {
					$this->take_safety_archive( $whole_site );
				}

				$restore_runner->restore( $source, $on_entry );
				$this->progress->finish();

				// 6. With --url, migrate the restored database to the new URL.
				if ( null !== $url_migrator ) {
					$this->run_migration( $url_migrator, $source_url, $target_url );
				}

				$bytes_imported = $this->archive_size( $archive_path );

				$this->logger->info(
					'Import complete.',
					array(
						'archive' => $archive_path,
						'entries' => $entry_total,
						'bytes'   => $bytes_imported,
					)
				);

				$this->bump_counters(
					array(
						'succeeded'      => 1,
						'bytes_imported' => $bytes_imported,
					)
				);
				TransferHistory::record( $this->wordpress_context, 'import', 'succeeded', $bytes_imported, gmdate( 'c' ) );

				$this->print_summary( $archive_path, $entry_total, $bytes_imported );
			}
		} catch ( Throwable $error ) {
			$this->logger->error(
				'Import failed.',
				array(
					'archive'   => $archive_path,
					'exception' => $error,
				)
			);
			if ( ! $dry_run ) {
				$this->bump_counters( array( 'failed' => 1 ) );
				TransferHistory::record( $this->wordpress_context, 'import', 'failed', 0, gmdate( 'c' ) );
			}
			throw $error;
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing a stream resource opened in this method; not a WP_Filesystem operation.
			fclose( $source );
		}
	}


	// -------------------------------------------------------------------------
	// Flag parsing and validation.
	// -------------------------------------------------------------------------

	/**
	 * Extract the required archive path from the positional args.
	 *
	 * Exits via WP_CLI::error (which halts the command) when absent.
	 *
	 * @param array<int, string> $positional_args The CLI's positional args; the first is the archive path.
	 * @return string
	 */
	private function require_archive_path( array $positional_args ): string {
		if ( ! isset( $positional_args[0] ) || '' === $positional_args[0] ) {
			WP_CLI::error( __( 'An archive path is required: wp pontifex import <archive>.', 'pontifex' ) );
		}
		return (string) $positional_args[0];
	}

	/**
	 * Extract and validate the optional --url migration target.
	 *
	 * Returns an empty string when --url is absent (a same-URL restore). When
	 * present, it must carry a non-empty value; a bare --url is rejected via
	 * WP_CLI::error, which halts the command.
	 *
	 * @param array<string, string|bool> $associative_args The CLI's associative args.
	 * @return string The target URL, or '' when --url was not supplied.
	 */
	private function require_target_url( array $associative_args ): string {
		if ( ! isset( $associative_args['url'] ) ) {
			return '';
		}
		if ( ! is_string( $associative_args['url'] ) || '' === $associative_args['url'] ) {
			WP_CLI::error( __( '--url requires a new site URL, e.g. --url=https://new-site.example.', 'pontifex' ) );
		}
		return (string) $associative_args['url'];
	}

	/**
	 * Verify that the archive path is absolute.
	 *
	 * Existence and readability are checked at open time, in
	 * open_source(): a file we cannot fopen for reading is the single
	 * honest test of "can I read this archive", and it yields a clear
	 * error there. Here we only reject a non-absolute path early.
	 *
	 * @param string $archive_path The path the user supplied.
	 * @return void
	 */
	private function validate_archive_path( string $archive_path ): void {
		if ( '/' !== substr( $archive_path, 0, 1 ) ) {
			WP_CLI::error(
				sprintf( 'The archive path must be absolute; got "%s".', PathRedactor::from_environment()->redact( $archive_path ) )
			);
		}
	}

	/**
	 * Open the source archive for reading.
	 *
	 * Exits via WP_CLI::error if fopen fails — which is also how a
	 * missing or unreadable archive is reported.
	 *
	 * @param string $archive_path Absolute path to the archive to read.
	 * @return resource
	 */
	private function open_source( string $archive_path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- Opening the source archive as a stream; @ traps an unopenable-file warning that we convert to a WP_CLI error below.
		$source = @fopen( $archive_path, 'rb' );
		if ( false === $source ) {
			WP_CLI::error(
				sprintf( 'Could not open archive for reading (does it exist and is it readable?): %s', PathRedactor::from_environment()->redact( $archive_path ) )
			);
		}
		return $source;
	}


	// -------------------------------------------------------------------------
	// Per-run wiring.
	// -------------------------------------------------------------------------

	/**
	 * Build a RestoreRunner from the default collaborators.
	 *
	 * Used when no RestoreRunner was injected. Reads the archive header to see
	 * whether it is encrypted; if so, collects the passphrase and builds a keyed
	 * EntryReader (the key derived from the footer salt), otherwise a plain one.
	 * Then wires a FileWriter rooted at the WordPress installation (ABSPATH) and
	 * a DatabaseWriter backed by a WpdbAdapter wrapping the real $wpdb. On a
	 * content-only restore the FileWriter is additionally restricted to the
	 * wp-content tree.
	 *
	 * @param resource    $source                The open archive stream, read for its header and footer.
	 * @param bool        $passphrase_stdin      True to read the passphrase from STDIN rather than prompt.
	 * @param bool        $allow_unsafe_symlinks True to allow symlink targets that escape the restore root.
	 * @param string|null $required_prefix       Prefix every restored file path must sit under ("wp-content" for a content-only restore), or null to allow any path (a whole-site restore).
	 * @return RestoreRunner
	 */
	private function build_default_restore_runner( $source, bool $passphrase_stdin, bool $allow_unsafe_symlinks, ?string $required_prefix = null ): RestoreRunner {
		$archive_reader = new ArchiveReader( $source );
		$passphrase     = $archive_reader->header()->is_encrypted()
			? Encryption::collect_for_import( $this->passphrase_source, $passphrase_stdin )
			: null;
		try {
			$entry_reader = Encryption::entry_reader( $archive_reader, CodecRegistry::with_defaults(), $passphrase );
		} finally {
			// Always scrub the passphrase, even if building the reader throws.
			if ( null !== $passphrase ) {
				sodium_memzero( $passphrase );
			}
		}

		// ArchiveReader sought through the stream; rewind so the RestoreRunner's own
		// reader starts from a known position.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Rewinding the open archive stream resource; not a WP_Filesystem operation.
		rewind( $source );

		$file_writer     = new FileWriter( $this->resolve_wordpress_root(), $allow_unsafe_symlinks, $required_prefix );
		$database_writer = new DatabaseWriter( new WpdbAdapter( $this->wordpress_context->wpdb_instance() ) );
		return new RestoreRunner( $entry_reader, $file_writer, $database_writer );
	}

	/**
	 * Build a UrlMigrator over the real $wpdb for the --url migration.
	 *
	 * Used when --url is given and no migrator was injected. The migrator walks
	 * every prefixed table (the wp search-replace default) with the class
	 * allowlist resolved from the pontifex_serialized_classes filter.
	 *
	 * @return UrlMigrator
	 */
	private function build_default_url_migrator(): UrlMigrator {
		return new UrlMigrator( $this->wordpress_context );
	}

	/**
	 * Take a pre-import safety archive of the current site.
	 *
	 * Writes a safety archive before the restore overwrites anything, so a mistaken
	 * import can be undone with `wp pontifex rollback`. It runs before the
	 * destructive restore: if it fails (the free-disk preflight refuses, or the
	 * write fails), the exception propagates and the import aborts before touching
	 * the site. The safety archive follows the restore's scope — content-only for a
	 * default restore (capturing only the wp-content tree being overwritten),
	 * whole-site for a --whole-site restore (ADR 0008).
	 *
	 * @param bool $whole_site True for a whole-site restore; false for a content-only restore.
	 * @return void
	 */
	private function take_safety_archive( bool $whole_site ): void {
		$safety_archiver = $this->safety_archiver ?? $this->build_default_safety_archiver( ! $whole_site );

		$on_entry = function ( int $done, int $total ): void {
			if ( 1 === $done ) {
				$this->progress->start( $total, 'Writing safety archive' );
			}
			$this->progress->advance();
		};

		$safety_root = $whole_site ? $this->resolve_wordpress_root() : $this->resolve_content_root();
		$path        = $safety_archiver->create( $safety_root, $on_entry );
		$this->progress->finish();

		$this->logger->info( 'Safety archive written.', array( 'safety_archive' => $path ) );
		WP_CLI::log( sprintf( 'Safety archive written: %s (undo this import with: wp pontifex rollback)', PathRedactor::from_environment()->redact( $path ) ) );
	}

	/**
	 * Build a SafetyArchiver whose rollback store lives under WP_CONTENT_DIR.
	 *
	 * The content directory is the location the safety archive is *stored* in
	 * (wp-content/pontifex/rollback), independent of what it captures; the
	 * $content_only flag controls whether the archive captures only the wp-content
	 * tree or the whole site.
	 *
	 * @param bool $content_only True to take a content-only safety archive; false for a whole-site one.
	 * @return SafetyArchiver
	 */
	private function build_default_safety_archiver( bool $content_only ): SafetyArchiver {
		$content_dir = $this->environment->is_constant_defined( 'WP_CONTENT_DIR' )
			? (string) $this->environment->constant_value( 'WP_CONTENT_DIR' )
			: sys_get_temp_dir();

		return new SafetyArchiver( $this->environment, $this->wordpress_context, new RollbackStore( $content_dir ), null, 1, $content_only );
	}

	/**
	 * Build the default file logger when the caller supplies none.
	 *
	 * Reads WP_CONTENT_DIR and WP_DEBUG through the Environment seam so
	 * the path and verbosity follow the host WordPress. Mirrors
	 * ExportCommand's logger wiring (a shared base is a post-v0.1.0
	 * refactor, not a mid-milestone one).
	 *
	 * @return LoggerInterface
	 */
	private function build_default_logger(): LoggerInterface {
		return new FileLogger( $this->log_directory(), $this->debug_enabled(), protect_directory: true );
	}

	/**
	 * Tee a per-transfer log file onto the central logger.
	 *
	 * Only the default logger writes to real files, so an injected spy logger
	 * (the unit tests) is left untouched and no file is created. The directory is
	 * resolved through a callback, so a run with an injected logger never reaches
	 * the filesystem or the Environment seam. The per-transfer file uses the same
	 * debug floor as the central log, so the two stay in step.
	 *
	 * @param callable(): string $directory Resolves the directory the per-transfer file lives in.
	 * @param string             $filename  Name of the per-transfer file.
	 * @return void
	 */
	private function attach_transfer_log( callable $directory, string $filename ): void {
		if ( ! $this->logger_was_defaulted ) {
			return;
		}

		$this->logger = new CompositeLogger(
			$this->logger,
			new FileLogger( $directory(), $this->debug_enabled(), $filename )
		);
	}

	/**
	 * Resolve the directory the central and per-transfer logs live in.
	 *
	 * Reads WP_CONTENT_DIR through the Environment seam, falling back to the
	 * system temp directory when WordPress is not loaded (as in unit tests).
	 *
	 * @return string The absolute log directory path.
	 */
	private function log_directory(): string {
		$content_dir = $this->environment->is_constant_defined( 'WP_CONTENT_DIR' )
			? (string) $this->environment->constant_value( 'WP_CONTENT_DIR' )
			: sys_get_temp_dir();

		return $content_dir . '/pontifex/logs';
	}

	/**
	 * Whether debug-level lines should be recorded (WP_DEBUG is on).
	 *
	 * @return bool True when WP_DEBUG is defined and truthy.
	 */
	private function debug_enabled(): bool {
		return $this->environment->is_constant_defined( 'WP_DEBUG' )
			&& (bool) $this->environment->constant_value( 'WP_DEBUG' );
	}

	/**
	 * Resolve the WordPress installation root for the restore.
	 *
	 * Reads the ABSPATH constant via the Environment abstraction so tests
	 * can substitute a fixture path. This is the root FileWriter restores
	 * files beneath.
	 *
	 * @return string
	 * @throws RuntimeException If ABSPATH is not defined (should never happen inside a WordPress request).
	 */
	private function resolve_wordpress_root(): string {
		if ( ! $this->environment->is_constant_defined( 'ABSPATH' ) ) {
			throw new RuntimeException( 'ImportCommand: ABSPATH is not defined; is WordPress loaded?' );
		}
		return rtrim( (string) $this->environment->constant_value( 'ABSPATH' ), '/' );
	}

	/**
	 * Resolve the wp-content root for a content-only safety archive.
	 *
	 * Reads WP_CONTENT_DIR through the Environment abstraction, falling back to
	 * ABSPATH/wp-content (WordPress's own default for the constant) when it is not
	 * defined, so the resolver still works outside a full WordPress request, as in
	 * unit tests. This is the root the content-only safety archive scans — the
	 * current site's wp-content, which the restore is about to overwrite.
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

	/**
	 * Refuse a default restore of a whole-site or legacy archive, before any write.
	 *
	 * Reads the archive's recorded scope from its provenance and, unless --whole-site
	 * was given, refuses an archive that is not content-only: a whole-site archive
	 * (its scope records core/wp-config.php) or a legacy archive (it records no scope
	 * at all, so it predates the content-only format and is treated as whole-site).
	 * This fails closed, so a default content-only restore never overwrites a live
	 * site's WordPress core or wp-config.php. The same gate applies to a --dry-run,
	 * so the dry-run honestly previews what a real restore would do. The stream is
	 * rewound for the restore/verify walk that follows.
	 *
	 * @param resource $source     The open archive stream.
	 * @param bool     $whole_site True when --whole-site was given; then any archive is allowed.
	 * @return void
	 */
	private function assert_scope_permits_restore( $source, bool $whole_site ): void {
		$scope = ( new ArchiveReader( $source ) )->provenance()->scope();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Rewinding the open archive stream resource so the restore walk re-reads it; not a WP_Filesystem operation.
		rewind( $source );

		if ( $whole_site ) {
			return;
		}

		if ( $scope instanceof Scope && $scope->is_content_only() ) {
			return;
		}

		WP_CLI::error(
			__( 'This archive includes WordPress core and wp-config.php (it is a whole-site or legacy archive). A default restore is content-only and will not overwrite them. Re-run with --whole-site to restore the entire site — only onto a fresh, empty destination.', 'pontifex' )
		);
	}

	/**
	 * Return the size of the archive file in bytes.
	 *
	 * Recorded as bytes_imported on a successful restore — the symmetric
	 * mirror of export's bytes_exported (which is the archive size it
	 * wrote). Returns 0 if the size cannot be read.
	 *
	 * @param string $archive_path Absolute path to the archive, already opened successfully.
	 * @return int
	 */
	private function archive_size( string $archive_path ): int {
		$size = filesize( $archive_path );
		return false !== $size ? $size : 0;
	}

	/**
	 * Resolve the --public-key option to a loaded public key, or null when absent.
	 *
	 * A bad or unreadable key file is the operator's mistake, so it exits via
	 * WP_CLI::error rather than being treated as a restore failure.
	 *
	 * @param array<string, string|bool> $associative_args The CLI's associative args.
	 * @return string|null The 32-byte public key, or null when --public-key was not supplied.
	 */
	private function resolve_public_key( array $associative_args ): ?string {
		if ( ! isset( $associative_args['public-key'] ) || '' === $associative_args['public-key'] || true === $associative_args['public-key'] ) {
			return null;
		}

		$key = '';
		try {
			$key = SigningKeys::load_public_key( (string) $associative_args['public-key'] );
		} catch ( \Exception $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WP_CLI::error renders the message to the terminal, not HTML; the message is our own.
			WP_CLI::error( PathRedactor::from_environment()->redact( $e->getMessage() ) );
		}
		return $key;
	}

	/**
	 * Verify the archive's signature before restoring, refusing a bad one.
	 *
	 * Runs before any write. Unsigned archive: nothing to check (a stray
	 * --public-key earns a warning). Signed with no key: warn that the signature
	 * is unverified and proceed. Signed with a key: a failed signature aborts the
	 * import via WP_CLI::error so nothing is written; a good one logs that it
	 * verified.
	 *
	 * @param resource    $source     The open archive stream.
	 * @param string|null $public_key The trusted public key, or null when none was supplied.
	 * @return void
	 */
	private function verify_signature_gate( $source, ?string $public_key ): void {
		$reader = new ArchiveReader( $source );

		if ( null === $reader->signature() ) {
			if ( null !== $public_key ) {
				WP_CLI::warning( __( 'A public key was supplied with --public-key, but this archive is not signed.', 'pontifex' ) );
			}
			return;
		}

		if ( null === $public_key ) {
			WP_CLI::warning( __( 'This archive is signed, but its signature was NOT verified (no --public-key supplied). Proceeding with the restore.', 'pontifex' ) );
			return;
		}

		if ( ! $reader->verify_signature( $public_key ) ) {
			WP_CLI::error( __( 'The Ed25519 signature did not verify against the supplied public key; refusing to restore (wrong key, or the archive was modified after signing).', 'pontifex' ) );
		}

		WP_CLI::log( __( 'Signature verified against the supplied public key.', 'pontifex' ) );
	}


	// -------------------------------------------------------------------------
	// Counters.
	// -------------------------------------------------------------------------

	/**
	 * Read-modify-write the stored import counters by a delta.
	 *
	 * Reads the single stats option through the WordPress-context seam,
	 * merges the delta in, and writes it back. The arithmetic lives in
	 * merge_counters so this method is only the I/O.
	 *
	 * @param array<string, int> $delta The amounts to add, keyed by counter name.
	 * @return void
	 */
	private function bump_counters( array $delta ): void {
		$current = $this->wordpress_context->option_value(
			self::STATS_OPTION,
			array(
				'attempted'      => 0,
				'succeeded'      => 0,
				'failed'         => 0,
				'bytes_imported' => 0,
			)
		);

		$merged = self::merge_counters( is_array( $current ) ? $current : array(), $delta );
		$this->wordpress_context->save_option( self::STATS_OPTION, $merged );
	}

	/**
	 * Combine the stored counters with a delta into a clean four-key set.
	 *
	 * Pure function. Tolerant of a missing, partial, or corrupt stored
	 * value: every counter coerces through counter_int, so a garbage
	 * option can never throw. Only the four known keys are returned.
	 *
	 * @param array<array-key, mixed> $current The counters as currently stored.
	 * @param array<array-key, mixed> $delta   The amounts to add per key.
	 * @return array<string, int>
	 */
	private static function merge_counters( array $current, array $delta ): array {
		$merged = array();
		foreach ( array( 'attempted', 'succeeded', 'failed', 'bytes_imported' ) as $key ) {
			$merged[ $key ] = self::counter_int( $current, $key ) + self::counter_int( $delta, $key );
		}
		return $merged;
	}

	/**
	 * Read one counter from an array as a non-negative-safe integer.
	 *
	 * Returns 0 when the key is absent or its value is non-numeric, so
	 * corrupt stored data degrades to zero rather than a type error.
	 *
	 * @param array<array-key, mixed> $values The array to read from.
	 * @param string                  $key    The counter key.
	 * @return int
	 */
	private static function counter_int( array $values, string $key ): int {
		return isset( $values[ $key ] ) && is_numeric( $values[ $key ] ) ? (int) $values[ $key ] : 0;
	}


	// -------------------------------------------------------------------------
	// Output formatting.
	// -------------------------------------------------------------------------

	/**
	 * Print the restore scope so the user knows what import does — and does not — do.
	 *
	 * @param string $target_url The migration target, or '' for a same-URL restore.
	 * @return void
	 */
	private function print_scope( string $target_url ): void {
		if ( '' === $target_url ) {
			WP_CLI::log( __( 'Restoring to the same site URL; no URL rewriting.', 'pontifex' ) );
			return;
		}
		WP_CLI::log( sprintf( /* translators: %s: the target site URL */ __( 'Restoring, then migrating the site URL to %s.', 'pontifex' ), $target_url ) );
	}

	/**
	 * Run the cross-URL migration over the restored database and report it.
	 *
	 * @param UrlMigratorInterface $url_migrator The migrator to run.
	 * @param string               $source_url   The URL the archive was exported from.
	 * @param string               $target_url   The new URL to migrate to.
	 * @return void
	 * @throws RuntimeException If the migration fails.
	 */
	private function run_migration( UrlMigratorInterface $url_migrator, string $source_url, string $target_url ): void {
		$report = $url_migrator->migrate( $source_url, $target_url );

		$this->logger->info(
			'Cross-URL migration complete.',
			array(
				'from'           => $source_url,
				'to'             => $target_url,
				'rows_changed'   => $report->rows_changed(),
				'values_changed' => $report->values_changed(),
				'tables_skipped' => count( $report->skipped_tables() ),
				'values_skipped' => $report->skipped_values(),
			)
		);

		$this->print_migration_summary( $source_url, $target_url, $report );
	}

	/**
	 * Announce the migration plan before any database write.
	 *
	 * @param string $source_url The URL the archive was exported from.
	 * @param string $target_url The new URL to migrate to.
	 * @return void
	 */
	private function print_migration_plan( string $source_url, string $target_url ): void {
		WP_CLI::log( sprintf( /* translators: 1: the source URL, 2: the target URL */ __( 'Migrating URLs from %1$s to %2$s.', 'pontifex' ), $source_url, $target_url ) );
	}

	/**
	 * Print the counts-only migration summary — no row contents.
	 *
	 * @param string        $source_url The URL the archive was exported from.
	 * @param string        $target_url The new URL migrated to.
	 * @param RewriteReport $report     The counts the migration produced.
	 * @return void
	 */
	private function print_migration_summary( string $source_url, string $target_url, RewriteReport $report ): void {
		WP_CLI::log(
			sprintf(
				/* translators: 1: source URL, 2: target URL, 3: values rewritten, 4: rows changed, 5: tables skipped, 6: values kept unchanged */
				__( 'Migrated %1$s to %2$s: rewrote %3$d value(s) across %4$d row(s); %5$d table(s) skipped (no single-column key); %6$d value(s) kept unchanged for safety.', 'pontifex' ),
				$source_url,
				$target_url,
				$report->values_changed(),
				$report->rows_changed(),
				count( $report->skipped_tables() ),
				$report->skipped_values()
			)
		);
	}

	/**
	 * Print the final post-restore summary line.
	 *
	 * @param string $archive_path   The archive that was restored.
	 * @param int    $entry_count    How many entries were restored.
	 * @param int    $bytes_imported Size of the archive in bytes.
	 * @return void
	 */
	private function print_summary( string $archive_path, int $entry_count, int $bytes_imported ): void {
		WP_CLI::log(
			sprintf(
				/* translators: 1: number of entries, 2: human-readable size, 3: the archive path */
				__( 'Restored %1$d entries (%2$s) from %3$s', 'pontifex' ),
				$entry_count,
				$this->wordpress_context->format_size( $bytes_imported ),
				$archive_path
			)
		);
	}

	/**
	 * Print the dry-run summary line, making clear nothing was changed.
	 *
	 * @param string $archive_path The archive that was verified.
	 * @param int    $entry_count  How many entries were verified.
	 * @param string $target_url   The migration target, or '' when --url was not given.
	 * @return void
	 */
	private function print_dry_run_summary( string $archive_path, int $entry_count, string $target_url ): void {
		$migration_note = '' === $target_url
			? ''
			: ' ' . sprintf( /* translators: %s: the target site URL */ __( 'The site URL would be migrated to %s after a real restore.', 'pontifex' ), $target_url );

		WP_CLI::log(
			sprintf(
				/* translators: 1: number of entries, 2: the archive path, 3: optional migration note */
				__( 'Dry run complete: %1$d entries verified in %2$s. No changes were made.%3$s', 'pontifex' ),
				$entry_count,
				$archive_path,
				$migration_note
			)
		);
	}
}
