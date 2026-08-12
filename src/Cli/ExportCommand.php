<?php
/**
 * Pontifex Export command — produces a Pontifex archive of the current WordPress site.
 *
 * @package Pontifex\Cli
 */

declare(strict_types=1);

namespace Pontifex\Cli;

use RuntimeException;
use Throwable;
use WP_CLI;
use Psr\Log\LoggerInterface;
use Pontifex\Archive\Format\Scope;
use Pontifex\Destination\DestinationAdapter;
use Pontifex\Destination\DestinationException;
use Pontifex\Destination\DestinationFactory;
use Pontifex\Destination\DestinationRetention;
use Pontifex\Destination\DestinationSpec;
use Pontifex\Destination\DestinationStore;
use Pontifex\Environment\Environment;
use Pontifex\Environment\RealEnvironment;
use Pontifex\Exception\ArchiveNotTrustworthy;
use Pontifex\Exception\HostCannotComply;
use Pontifex\Exception\InvalidRequest;
use Pontifex\Exception\PontifexException;
use Pontifex\Export\ExportCounters;
use Pontifex\Export\ExportOptions;
use Pontifex\Export\ExportResult;
use Pontifex\Export\ExportRunner;
use Pontifex\Export\ResumableExportRunner;
use Pontifex\Job\Job;
use Pontifex\Job\JobStore;
use Pontifex\Lock\OperationLock;
use Pontifex\Log\CompositeLogger;
use Pontifex\Log\FileLogger;
use Pontifex\Manifest\ExclusionRules;
use Pontifex\Manifest\ManifestBuilderInterface;
use Pontifex\WordPress\RealWordPressContext;
use Pontifex\WordPress\WordPressContext;
use Pontifex\WordPress\WordPressRoot;

/**
 * `wp pontifex export` — produce a Pontifex archive of the current WordPress site.
 *
 * Writes a single .wpmig archive file. By default the archive is
 * content-only: every file under wp-content (minus exclusions) plus
 * every WordPress-prefixed database table (chunked into ~4 MiB
 * pieces) — the everyday working-WordPress-to-working-WordPress
 * backup. Pass --whole-site to capture the entire WordPress root
 * instead, including core and wp-config.php, for cloning onto a bare
 * destination. Either way the archive is the on-disk artefact needed
 * to restore the site on a different host.
 *
 * It exits 0 when the backup completes, and non-zero when it is refused or
 * fails — reporting which of the three kinds of refusal happened (ADR 0022):
 * the archive cannot be trusted, this host cannot comply, or the request
 * itself needs correcting.
 *
 * ## OPTIONS
 *
 * [--output=<path>]
 * : Absolute filesystem path where the archive should be written.
 *   The parent directory must exist and be writable. Required —
 *   except with --resume, which reads it from the interrupted export.
 *
 * [--resumable]
 * : Run the export as a resumable job: progress is recorded as it goes,
 *   so an export interrupted by a timeout, a lost connection, or a kill
 *   can be continued from where it stopped with --resume instead of
 *   starting over. Not available together with encryption (the derived
 *   key exists only for one run and is never stored).
 *
 * [--resume]
 * : Continue the interrupted resumable export from its last verified
 *   entry. The output path, scope, and exclusions are remembered from
 *   the original run; a signed export needs the same --sign and
 *   --signing-key flags it began with.
 *
 * [--whole-site]
 * : Capture the entire WordPress root — WordPress core and
 *   wp-config.php included — rather than only wp-content. Use this
 *   when cloning onto a fresh, empty destination; the default
 *   content-only archive is the right choice for an existing
 *   WordPress install.
 *
 * [--files-only]
 * : Capture only the files (wp-content), leaving the database out — a
 *   quick file backup that skips the whole database dump. Its restore is
 *   partial: it writes only files and never touches the database. Cannot
 *   be combined with --db-only or --whole-site.
 *
 * [--db-only]
 * : Capture only the database, with no files. Its restore is partial: it
 *   writes only the database and never touches any file. Cannot be
 *   combined with --files-only or --whole-site.
 *
 * [--exclude-file=<path>]
 * : Path to a file containing additional exclusion patterns, one per
 *   line. Blank lines and lines starting with `#` are ignored.
 *   Pattern syntax matches Pontifex's ExclusionRules: regex
 *   (delimited with `/`), directory tree (`path/**`), glob (`*.log`),
 *   or exact string.
 *
 * [--exclude=<patterns>]
 * : Additional exclusion patterns for this run, without a file. One or
 *   more comma-separated patterns, same syntax as `--exclude-file`.
 *   Convenience for a quick one-off; `--exclude-file` remains the route
 *   for a long or reusable list.
 *
 * [--exclude-table=<patterns>]
 * : Database tables to leave out of the backup. One or more
 *   comma-separated patterns matched against the bare table name
 *   (exact, e.g. `wp_actionscheduler_logs`, or glob, e.g.
 *   `wp_actionscheduler_*`). The whole matched table is omitted —
 *   its restore is then a partial one, so exclude only tables the
 *   destination can rebuild.
 *
 * [--no-defaults]
 * : Skip the curated default exclusion list (Pontifex's working dir,
 *   wp-content/cache, and .git directories at any depth). Use only
 *   patterns from `--exclude-file`, `--exclude`, and `--exclude-table`,
 *   if any.
 *
 * [--yes]
 * : Skip the confirmation prompt and proceed immediately.
 *
 * [--encrypt]
 * : Encrypt the archive. Prompts for a passphrase (entered twice, not echoed)
 *   and derives an AES-256-GCM key with Argon2id. There is no passphrase
 *   recovery: lose it and the archive is unreadable.
 *
 * [--passphrase-stdin]
 * : Encrypt the archive, reading the passphrase as one line from STDIN (for
 *   scripts and pipes). Implies --encrypt.
 *
 * [--sign]
 * : Sign the archive with an Ed25519 secret key. Requires --signing-key. A
 *   detached signature is appended after the footer; verify it later with
 *   `verify` / `import --public-key`. Independent of --encrypt.
 *
 * [--signing-key=<path>]
 * : Path to the Ed25519 secret-key file (from `wp pontifex keygen`) to sign
 *   with. Used with --sign.
 *
 * [--destination=<name>]
 * : After writing the local archive, upload it to the configured offsite
 *   destination of this name (see `wp pontifex destination`). The upload runs
 *   in this command, which has no web timeout, so a large archive is not bound
 *   by a request limit. Credentials are read from the destination's configured
 *   environment variables, never from this command line.
 *
 * ## EXAMPLES
 *
 *     wp pontifex export --output=/tmp/site.wpmig
 *     wp pontifex export --output=/tmp/site.wpmig --yes
 *     wp pontifex export --output=/tmp/site.wpmig --whole-site --yes
 *     wp pontifex export --output=/tmp/site.wpmig --exclude-file=/tmp/extras.txt
 *     wp pontifex export --output=/tmp/site.wpmig --no-defaults --exclude-file=/tmp/only.txt
 *     wp pontifex export --output=/tmp/site.wpmig --encrypt
 *     pass show backup | wp pontifex export --output=/tmp/site.wpmig --passphrase-stdin
 *     wp pontifex export --output=/tmp/site.wpmig --sign --signing-key=/root/pontifex.key
 *
 * @when after_wp_load
 */
final class ExportCommand {


	/**
	 * The wp_options key under which the export counters are stored.
	 *
	 * One option holds all four counters as an array, autoload off:
	 * the stats are written occasionally and read almost never, so
	 * they have no business in the alloptions cache.
	 */
	private const STATS_OPTION = ExportCounters::OPTION;

	/**
	 * The reason recorded in provenance when the archive is written unencrypted.
	 *
	 * The format requires a non-empty explanation when encryption is disabled
	 * (`ARCHIVE-FORMAT.md` §8.5); this is it for the export path.
	 *
	 * @var string
	 */
	private const ENCRYPTION_DISABLED_REASON = 'Encryption was not requested at export time (--encrypt / --passphrase-stdin not supplied).';

	/**
	 * The Environment abstraction this command queries.
	 *
	 * Injected via the constructor so tests can substitute a mock that
	 * returns deterministic values for PHP version, filesystem stat
	 * results, and constant values.
	 *
	 * @var Environment
	 */
	private Environment $environment;

	/**
	 * The WordPressContext abstraction this command queries.
	 *
	 * Where Environment covers PHP-runtime facts, WordPressContext
	 * covers WordPress-specific facts: site URL, WordPress version,
	 * wpdb instance, charset, collation, etc. Splitting the two means
	 * tests can mock each layer independently.
	 *
	 * @var WordPressContext
	 */
	private WordPressContext $wordpress_context;

	/**
	 * The manifest builder used to enumerate entries for the archive.
	 *
	 * Optional in the constructor: when null, the command wires one up
	 * from a fresh FileScanner+DatabaseScanner+WpdbAdapter against the
	 * computed ExclusionRules. Tests inject a fake fulfilling the
	 * ManifestBuilderInterface contract.
	 *
	 * @var ManifestBuilderInterface|null
	 */
	private ?ManifestBuilderInterface $manifest_builder;

	/**
	 * The PSR-3 logger this command records run milestones to.
	 *
	 * Injected via the constructor so tests can substitute a spy or a
	 * NullLogger. When null, the constructor builds a FileLogger writing
	 * under wp-content/pontifex/logs.
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
	 * The progress reporter that shows archive-writing progress.
	 *
	 * Injected via the constructor so tests can substitute a silent
	 * NullProgressBar. When null, a WpCliProgressBar driving WP-CLI's
	 * native progress bar is used.
	 *
	 * @var ProgressReporter
	 */
	private ProgressReporter $progress;

	/**
	 * The source of the operator's encryption passphrase.
	 *
	 * Injected so tests can supply a fixed passphrase without a terminal or a
	 * piped STDIN. When null, a CliPassphraseSource (hidden prompt + STDIN) is used.
	 *
	 * @var PassphraseSource
	 */
	private PassphraseSource $passphrase_source;

	/**
	 * The shared single-runner lock, contended with import and rollback.
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
	 * A destination adapter to use for the upload step, in place of the factory.
	 *
	 * The seam that lets a test observe the upload step — that it happens, that
	 * it does not, and what the command does when it fails — without a live
	 * remote server to upload to. Null in production, where
	 * {@see self::build_destination_adapter()} builds the real adapter from the
	 * resolved destination spec.
	 *
	 * @var DestinationAdapter|null
	 */
	private ?DestinationAdapter $destination_adapter;

	/**
	 * Construct an ExportCommand instance.
	 *
	 * WP-CLI registers the command via its class name and does not
	 * pass constructor arguments, so all parameters are optional and
	 * default to real implementations. Tests pass mocks explicitly.
	 *
	 * @param Environment|null              $environment Optional. Defaults to a fresh RealEnvironment.
	 * @param WordPressContext|null         $wordpress_context Optional. Defaults to a fresh RealWordPressContext.
	 * @param ManifestBuilderInterface|null $manifest_builder Optional. When null, the command builds a concrete ManifestBuilder from the exclusion rules at run time.
	 * @param LoggerInterface|null          $logger Optional. When null, a FileLogger writing under wp-content/pontifex/logs is used.
	 * @param ProgressReporter|null         $progress Optional. When null, a WpCliProgressBar driving WP-CLI's native progress bar is used.
	 * @param PassphraseSource|null         $passphrase_source Optional. When null, a CliPassphraseSource (hidden prompt + STDIN) is used.
	 * @param OperationLock|null            $lock Optional. When null, a default OperationLock is built lazily at run time.
	 * @param DestinationAdapter|null       $destination_adapter Optional. When null, a live adapter is built from the resolved destination spec.
	 */
	public function __construct(
		?Environment $environment = null,
		?WordPressContext $wordpress_context = null,
		?ManifestBuilderInterface $manifest_builder = null,
		?LoggerInterface $logger = null,
		?ProgressReporter $progress = null,
		?PassphraseSource $passphrase_source = null,
		?OperationLock $lock = null,
		?DestinationAdapter $destination_adapter = null
	) {
		$this->environment          = $environment ?? new RealEnvironment();
		$this->wordpress_context    = $wordpress_context ?? new RealWordPressContext();
		$this->manifest_builder     = $manifest_builder;
		$this->logger_was_defaulted = null === $logger;
		$this->logger               = $logger ?? $this->build_default_logger();
		$this->progress             = $progress ?? new WpCliProgressBar();
		$this->passphrase_source    = $passphrase_source ?? new CliPassphraseSource();
		$this->lock                 = $lock;
		$this->destination_adapter  = $destination_adapter;
	}

	/**
	 * The shared OperationLock, built lazily on first use.
	 *
	 * Deferred past the constructor because its default JobStore needs
	 * WP_CONTENT_DIR/ABSPATH resolved through {@see self::resolve_content_root()},
	 * which is only guaranteed once the command actually runs.
	 *
	 * @return OperationLock The lock to acquire before this run's work begins.
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
	 * `__invoke` is the magic method WP-CLI dispatches to for a single-
	 * command class. Orchestrates: parse flags, validate inputs, build
	 * exclusion rules, optionally confirm, build provenance, run the
	 * manifest builder, write the archive, print a summary.
	 *
	 * A failure is not re-thrown. It is logged, reported as a readable verdict
	 * naming which kind of refusal it was, and the command halts non-zero — so
	 * an operator sees why it stopped rather than a stack trace, and a script
	 * still sees a failing exit code.
	 *
	 * @param array<int, string>         $positional_args  Positional arguments passed on the CLI. Unused for `export`.
	 * @param array<string, string|bool> $associative_args Associative `--key=value` and `--flag` arguments.
	 * @return void
	 */
	public function __invoke( array $positional_args, array $associative_args ): void {

		// 1. Parse and validate flags.
		$resumable = isset( $associative_args['resumable'] ) && false !== $associative_args['resumable'];
		$resume    = isset( $associative_args['resume'] ) && false !== $associative_args['resume'];
		if ( $resumable && $resume ) {
			WP_CLI::error( __( 'Pass either --resumable (start a resumable export) or --resume (continue one), not both.', 'pontifex' ) );
		}

		// On --resume the output path is remembered by the interrupted job; any
		// --output supplied is only cross-checked against it later.
		$output_path       = $resume
			? ( isset( $associative_args['output'] ) ? (string) $associative_args['output'] : '' )
			: $this->require_output_path( $associative_args );
		$exclude_file_path = isset( $associative_args['exclude-file'] ) ? (string) $associative_args['exclude-file'] : '';
		$use_defaults      = self::should_use_defaults( $associative_args );
		$whole_site        = isset( $associative_args['whole-site'] ) && false !== $associative_args['whole-site'];
		$files_only        = isset( $associative_args['files-only'] ) && false !== $associative_args['files-only'];
		$db_only           = isset( $associative_args['db-only'] ) && false !== $associative_args['db-only'];

		// The scope selectors are mutually exclusive: an archive is one of
		// whole-site, content (the default), files-only, or db-only — never a
		// contradictory mixture (ADR 0016).
		if ( ( $files_only && $db_only ) || ( $whole_site && ( $files_only || $db_only ) ) ) {
			WP_CLI::error( __( 'Choose at most one of --whole-site, --files-only, or --db-only.', 'pontifex' ) );
		}
		$skip_confirmation = isset( $associative_args['yes'] ) && false !== $associative_args['yes'];
		$passphrase_stdin  = isset( $associative_args['passphrase-stdin'] ) && false !== $associative_args['passphrase-stdin'];
		$encrypting        = $passphrase_stdin || ( isset( $associative_args['encrypt'] ) && false !== $associative_args['encrypt'] );
		$signing_requested = isset( $associative_args['sign'] ) && false !== $associative_args['sign'];
		$signing_key_path  = isset( $associative_args['signing-key'] ) ? (string) $associative_args['signing-key'] : '';
		$destination_name  = isset( $associative_args['destination'] ) ? (string) $associative_args['destination'] : '';

		if ( ( $resumable || $resume ) && $encrypting ) {
			WP_CLI::error( __( 'An encrypted export cannot be resumable: the derived key exists only for one run and is never stored. Drop --resumable/--resume, or export without encryption.', 'pontifex' ) );
		}

		if ( ( $resumable || $resume ) && '' !== $destination_name ) {
			WP_CLI::error( __( 'A resumable export cannot upload to a destination yet: a resumed run would not know to push it. Export without --resumable to upload directly, or copy the finished archive to the destination server yourself — there is no command that uploads an archive Pontifex has already written.', 'pontifex' ) );
		}

		// Single-runner lock: refuse to start while any site-mutating operation
		// — a backup, restore, or rollback, admin or CLI — is already running, so
		// two of them can never fight over the site at once. A leaked "backup"
		// holder is always reclaimable (see OperationLock::is_reclaimable()), so
		// this acquire's position ahead of the flag validation below is safe —
		// unlike import/rollback, a refusal further down can never wedge a later
		// operation. The shutdown handler below is still registered, so a fatal
		// mid-export clears the transient promptly rather than waiting for the
		// next acquire attempt to reclaim it.
		// On --resume the blocking backup is the very job about to be adopted:
		// a killed export leaves its job active and its holder transient set,
		// and nothing else can finish it. See OperationLock::acquire().
		$lock = $this->operation_lock();
		if ( ! $lock->acquire( OperationLock::OP_BACKUP, $resume ) ) {
			WP_CLI::error( sprintf( /* translators: %s: the kind of operation currently running */ __( 'Another Pontifex operation is already running (%s). Wait for it to finish, or resume it, then retry.', 'pontifex' ), $lock->current_holder() ?? 'unknown' ) );
		}
		$this->lock = $lock;
		register_shutdown_function( array( $this, 'release_lock_on_shutdown' ) );

		// The failure the run ended on, or null when it succeeded. Recorded rather than
		// re-thrown, and acted on only after the finally below: WP_CLI::halt() calls
		// exit(), and PHP does not run a finally block when exit() is called — so
		// halting inside the catch would skip the lock release and leave the site's
		// operation lock to the shutdown backstop. That backstop is the last line of
		// defence, not the primary one.
		$failure = null;

		// Whether this run got as far as a complete archive on disk. Only an upload
		// can fail after that point, and the verdict must not then tell the operator
		// no archive was written when one is sitting there.
		$archive_written = false;

		try {
			if ( ! $resume ) {
				$this->validate_output_path( $output_path );

				// 1a. Tee a per-transfer log alongside the archive, so this export leaves a
				// self-contained record next to its .wpmig (in addition to the central log).
				// A resumed run attaches once its job reveals the remembered output path.
				$this->attach_transfer_log(
					static fn (): string => dirname( $output_path ),
					basename( $output_path ) . '.log'
				);
			}

			// 2. Build the exclusion rules. File patterns come from --exclude-file, then
			// inline --exclude; table patterns from --exclude-table are appended to the
			// same list — the pattern engine matches a bare table name the same way it
			// matches a path, so one uniform list drives both scanners.
			$user_patterns = '' !== $exclude_file_path
				? $this->load_exclude_file( $exclude_file_path )
				: array();
			$user_patterns = array_merge(
				$user_patterns,
				self::split_patterns( $associative_args['exclude'] ?? null ),
				self::split_patterns( $associative_args['exclude-table'] ?? null )
			);

			$exclusion_rules = self::build_exclusion_rules( $use_defaults, $user_patterns );

			// 2a. Resolve the offsite destination now (if one was named), so a mistyped
			// name or a missing credential fails before the export does any work.
			$destination_spec    = $this->resolve_destination_spec( $destination_name );
			$destination_adapter = null !== $destination_spec
				? ( $this->destination_adapter ?? $this->build_destination_adapter( $destination_spec ) )
				: null;

			// 3. Confirm with the user (unless --yes; a resume was confirmed when it started).
			if ( ! $skip_confirmation && ! $resume ) {
				$this->print_scope_summary( $whole_site, $files_only, $db_only );
				$this->print_exclusion_summary( $exclusion_rules );
				WP_CLI::confirm( sprintf( /* translators: %s: the output file path */ __( 'Export to %s?', 'pontifex' ), $output_path ), $associative_args );
			}

			// 3a. Collect the passphrase and build the encryption context, if encrypting.
			// The passphrase and derived key are secrets — never logged; the passphrase is
			// scrubbed once the key is derived.
			$encryption                 = null;
			$encryption_disabled_reason = self::ENCRYPTION_DISABLED_REASON;
			if ( $encrypting ) {
				if ( ! $passphrase_stdin ) {
					WP_CLI::warning( __( 'There is no passphrase recovery: if you lose this passphrase, the archive cannot be decrypted.', 'pontifex' ) );
				}
				$passphrase = Encryption::collect_for_export( $this->passphrase_source, $passphrase_stdin );
				try {
					$encryption                 = Encryption::context( $passphrase );
					$encryption_disabled_reason = null;
				} finally {
					// Always scrub the passphrase, even if context derivation throws.
					sodium_memzero( $passphrase );
				}
			}

			// 3b. Load the signing key and build the signing context, if signing. The
			// secret key is scrubbed once the context holds it; signing is independent
			// of encryption.
			$signing = null;
			if ( $signing_requested ) {
				if ( '' === $signing_key_path ) {
					WP_CLI::error( __( '--sign requires --signing-key=<path> (the secret-key file from "wp pontifex keygen").', 'pontifex' ) );
				}
				try {
					$secret_key = SigningKeys::load_secret_key( $signing_key_path );
					try {
						$signing = SigningKeys::signing_context( $secret_key );
					} finally {
						// Always scrub the secret key, even if building the context throws.
						sodium_memzero( $secret_key );
					}
				} catch ( \Exception $e ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WP_CLI::error renders the message to the terminal, not HTML; the message is our own.
					WP_CLI::error( PathRedactor::from_environment()->redact( $e->getMessage() ) );
				}
			}

			$this->logger->info(
				'Export started.',
				array(
					'output'     => $output_path,
					'exclusions' => count( $exclusion_rules->patterns() ),
					'encrypted'  => null !== $encryption,
					'signed'     => null !== $signing,
				)
			);

			$this->bump_counters( array( 'attempted' => 1 ) );

			// 4. Resolve the export scope. Content-only (the default) scans wp-content and
			// records each file under a "wp-content" path prefix, so the recorded paths
			// stay WordPress-root-relative; --whole-site scans the whole WordPress root
			// with no prefix. The scope facts are recorded in provenance so a destination
			// can tell what the archive holds before unpacking it (ADR 0008).
			$scan_root        = $whole_site ? $this->resolve_wordpress_root() : $this->resolve_content_root();
			$path_prefix      = $whole_site ? '' : 'wp-content';
			$include_files    = ! $db_only;
			$include_database = ! $files_only;
			if ( $whole_site ) {
				$scope = Scope::whole_site( $exclusion_rules->patterns() );
			} elseif ( $files_only ) {
				$scope = Scope::files_only( $exclusion_rules->patterns() );
			} elseif ( $db_only ) {
				$scope = Scope::db_only( $exclusion_rules->patterns() );
			} else {
				$scope = Scope::content_only( $exclusion_rules->patterns() );
			}

			// 4a. A resumable export (or its resumption) takes the step-machine path
			// (ADR 0014/0015) instead of the one-shot engine, and returns from there. The
			// step runner reads which halves to capture from the recorded scope.
			if ( $resumable || $resume ) {
				$this->run_resumable( $resume, $output_path, $signing, $encryption_disabled_reason, $scan_root, $path_prefix, $exclusion_rules, $scope );
				return;
			}

			// 5. Build the entry list — the files, the database, or both, per the scope.
			$manifest_builder = $this->manifest_builder ?? ExportRunner::default_manifest_builder( $this->wordpress_context, $exclusion_rules, $path_prefix, $include_files, $include_database );

			// 6. Write the archive through the shared export engine.
			$export_runner = new ExportRunner( $this->environment, $this->wordpress_context );

			try {
				$entry_plans = $manifest_builder->build( $scan_root );

				$this->progress->start( count( $entry_plans ), 'Writing archive' );
				$result = $export_runner->export(
					new ExportOptions( $output_path, $encryption, $signing, $encryption_disabled_reason, $scope ),
					$entry_plans,
					function (): void {
						$this->progress->advance();
					}
				);
				$this->progress->finish();

				// The engine moved the completed archive into place, so from here a
				// failure (an upload, a retention prune) leaves a real backup behind.
				$archive_written = true;

				$this->print_changed_file_warnings( $result );
				$this->print_media_type_warning( $result->media_type_unresolved_count() );

				$bytes_written = $result->bytes_written();

				$this->logger->info(
					'Export complete.',
					array(
						'output'                => $output_path,
						'entries'               => $result->entry_count(),
						'bytes'                 => $bytes_written,
						'files_changed'         => count( $result->changed_files() ),
						'media_type_unresolved' => $result->media_type_unresolved_count(),
					)
				);

				$this->bump_counters(
					array(
						'succeeded'             => 1,
						'bytes_exported'        => $bytes_written,
						'files_changed'         => count( $result->changed_files() ),
						'media_type_unresolved' => $result->media_type_unresolved_count(),
					)
				);
				TransferHistory::record( $this->wordpress_context, 'export', 'succeeded', $bytes_written, gmdate( 'c' ) );

				// 7. Print the summary.
				$this->print_summary( $output_path, $result->entry_count(), $bytes_written );
			} catch ( Throwable $error ) {
				$this->logger->error(
					'Export failed.',
					array(
						'output'    => $output_path,
						'exception' => $error,
					)
				);
				$this->bump_counters( array( 'failed' => 1 ) );
				TransferHistory::record( $this->wordpress_context, 'export', 'failed', 0, gmdate( 'c' ) );
				$failure = $error;
			}

			// Only upload an archive that exists. A failed write has already been
			// recorded above; pushing on to the destination would fail a second time
			// and bury the first, real reason under a transfer error.
			if ( null === $failure ) {
				$this->upload_archive(
					$destination_adapter,
					$output_path,
					$encrypting,
					null !== $destination_spec ? $destination_spec->retention() : 0
				);
			}
		} catch ( Throwable $error ) {
			// Everything the inner handler above does not cover: a rejected exclusion
			// pattern, a failed upload, and the resumable tick loop, which logs and
			// counts its own failure and then re-throws to here.
			$failure = $error;
		} finally {
			$lock->release();
		}

		// Report and halt here, outside the try, so the finally above has already
		// released the lock. See the note on $failure at its declaration.
		if ( null !== $failure ) {
			$this->print_failure_verdict( $output_path, $failure, $resumable || $resume, $archive_written );
			WP_CLI::halt( 1 );
		}
	}

	// -------------------------------------------------------------------------
	// The resumable path (ADR 0014/0015).
	// -------------------------------------------------------------------------

	/**
	 * Wall-clock budget per tick when the CLI drives a resumable export.
	 *
	 * Each tick persists its progress, so this is the most work a kill can
	 * lose (plus one torn entry, which resume heals).
	 *
	 * @var float
	 */
	private const RESUMABLE_TICK_BUDGET_SECONDS = 20.0;

	/**
	 * Start or resume a job-backed export and tick it to completion in-process.
	 *
	 * The CLI is its own ticker (ADR 0014): it loops budgeted steps, reloading
	 * the job between them exactly as separate requests would, so the on-disk
	 * state is continuable at every boundary. A kill at any point loses at most
	 * one tick's unlogged work, and `--resume` picks up from the last verified
	 * entry.
	 *
	 * $output_path is taken by reference because a resume learns it from the
	 * job record rather than from the command line: without the reference the
	 * caller would still be holding the empty string it passed in, and would
	 * report a failure against no path at all.
	 *
	 * @param bool                                         $resume                     True to continue the interrupted job, false to start a new one.
	 * @param string                                       $output_path                The archive destination ('' on resume until the job supplies it); set here on resume.
	 * @param \Pontifex\Archive\Crypto\SigningContext|null $signing                  Signing inputs, or null.
	 * @param string|null                                  $encryption_disabled_reason The recorded reason the archive is unencrypted.
	 * @param string                                       $scan_root                  Absolute path the file scan starts from.
	 * @param string                                       $path_prefix                Prefix for recorded paths.
	 * @param ExclusionRules                               $exclusion_rules            The exclusion rules in force.
	 * @param Scope                                        $scope                      The scope facts to record in provenance.
	 * @return void
	 * @throws RuntimeException If the job record disappears mid-run.
	 * @throws Throwable        Re-thrown after logging if a tick fails (drift refusal, write failure); __invoke reports it and halts.
	 */
	private function run_resumable( bool $resume, string &$output_path, $signing, ?string $encryption_disabled_reason, string $scan_root, string $path_prefix, ExclusionRules $exclusion_rules, Scope $scope ): void {
		$store   = new JobStore( $this->resolve_content_root() );
		$factory = null !== $this->manifest_builder
			? fn (): ManifestBuilderInterface => $this->manifest_builder
			: null;
		$runner  = new ResumableExportRunner( $this->environment, $this->wordpress_context, $store, $factory );

		if ( $resume ) {
			$job = $store->active_job();
			if ( null === $job || Job::KIND_EXPORT !== $job->kind() ) {
				WP_CLI::error( __( 'No interrupted resumable export found to resume.', 'pontifex' ) );
			}
			$payload = $job->payload();
			if ( '' !== $output_path && (string) $payload['output'] !== $output_path ) {
				WP_CLI::error( sprintf( /* translators: %s: the output path the interrupted export writes to */ __( 'This resumable export writes to %s; --output cannot change on resume.', 'pontifex' ), (string) $payload['output'] ) );
			}
			$output_path   = (string) $payload['output'];
			$job_is_signed = (bool) $payload['signed'];
			if ( ( null !== $signing ) !== $job_is_signed ) {
				WP_CLI::error( __( 'This resumable export was started with different signing settings; resume with the same --sign/--signing-key flags it began with.', 'pontifex' ) );
			}
			$this->attach_transfer_log(
				static fn (): string => dirname( $output_path ),
				basename( $output_path ) . '.log'
			);
			WP_CLI::log( sprintf( /* translators: %s: the output path */ __( 'Resuming the interrupted export to %s.', 'pontifex' ), $output_path ) );
		} else {
			try {
				$job = $runner->start(
					new ExportOptions( $output_path, null, $signing, $encryption_disabled_reason, $scope ),
					$scan_root,
					$path_prefix,
					$exclusion_rules->patterns(),
					time()
				);
			} catch ( RuntimeException $e ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WP_CLI::error renders the message to the terminal, not HTML; the message is our own.
				WP_CLI::error( PathRedactor::from_environment()->redact( $e->getMessage() ) );
			}
			WP_CLI::log( __( 'This export is resumable: if it is interrupted, continue it with `wp pontifex export --resume`.', 'pontifex' ) );
		}

		$bar_started = false;
		try {
			$done = false;
			while ( ! $done ) {
				// Reload between ticks exactly as separate requests would, so the
				// persisted state is what carries the export forward.
				$current = $store->get( $job->id() );
				if ( null === $current ) {
					throw new RuntimeException( 'The resumable job record disappeared mid-run.' );
				}
				$done = $runner->tick(
					$current,
					self::RESUMABLE_TICK_BUDGET_SECONDS,
					$signing,
					null,
					function ( int $done_entries, int $total ) use ( &$bar_started ): void {
						if ( ! $bar_started ) {
							$this->progress->start( $total, __( 'Writing archive', 'pontifex' ) );
							$bar_started = true;
						}
						$this->progress->advance();
					}
				);
			}
			$this->progress->finish();

			$finished              = $store->get( $job->id() );
			$payload               = null !== $finished ? $finished->payload() : $job->payload();
			$bytes_written         = (int) ( $payload['bytes_written'] ?? 0 );
			$files_changed         = (int) ( $payload['files_changed'] ?? 0 );
			$media_type_unresolved = (int) ( $payload['media_type_unresolved'] ?? 0 );
			$entry_count           = count( $store->progress_log( $job->id() )->read_all() );

			// The job served its purpose; remove the record and its sidecar so the
			// single-active slot and the jobs directory stay clean.
			$store->delete( $job->id() );

			$this->logger->info(
				'Export complete.',
				array(
					'output'                => $output_path,
					'entries'               => $entry_count,
					'bytes'                 => $bytes_written,
					'files_changed'         => $files_changed,
					'media_type_unresolved' => $media_type_unresolved,
					'resumable'             => true,
				)
			);
			$this->bump_counters(
				array(
					'succeeded'             => 1,
					'bytes_exported'        => $bytes_written,
					'files_changed'         => $files_changed,
					'media_type_unresolved' => $media_type_unresolved,
				)
			);
			TransferHistory::record( $this->wordpress_context, 'export', 'succeeded', $bytes_written, gmdate( 'c' ) );

			if ( $files_changed > 0 ) {
				WP_CLI::warning(
					sprintf(
						/* translators: %d: number of files that changed during the export */
						_n(
							'%d file changed while the backup ran. The archive is consistent, but re-run the export if you want a settled copy of that file.',
							'%d files changed while the backup ran. The archive is consistent, but re-run the export if you want settled copies of those files.',
							$files_changed,
							'pontifex'
						),
						$files_changed
					)
				);
			}
			$this->print_media_type_warning( $media_type_unresolved );
			$this->print_summary( $output_path, $entry_count, $bytes_written );
		} catch ( Throwable $error ) {
			$this->logger->error(
				'Export failed.',
				array(
					'output'    => $output_path,
					'exception' => $error,
				)
			);
			$this->bump_counters( array( 'failed' => 1 ) );
			TransferHistory::record( $this->wordpress_context, 'export', 'failed', 0, gmdate( 'c' ) );
			throw $error;
		}
	}

	/**
	 * Resolve a destination name to its stored spec, or null when none was asked for.
	 *
	 * Resolving the spec before the export runs (and, via
	 * {@see build_destination_adapter()}, building the adapter) means a
	 * mistyped name or a missing credential fails fast, without first
	 * spending time writing an archive that then cannot be uploaded.
	 *
	 * @param string $name The configured destination name, or '' for none.
	 * @return DestinationSpec|null The stored spec, or null when $name is empty.
	 */
	private function resolve_destination_spec( string $name ): ?DestinationSpec {
		if ( '' === $name ) {
			return null;
		}

		$spec = ( new DestinationStore( $this->wordpress_context ) )->get( $name );
		if ( null === $spec ) {
			WP_CLI::error( sprintf( /* translators: %s: the destination name */ __( 'No destination named "%s" is configured. Add one with `wp pontifex destination`.', 'pontifex' ), $name ) );
		}

		return $spec;
	}

	/**
	 * Build a live adapter from a resolved destination spec.
	 *
	 * @param DestinationSpec $spec The stored destination spec.
	 * @return DestinationAdapter The live adapter.
	 */
	private function build_destination_adapter( DestinationSpec $spec ): DestinationAdapter {
		try {
			return ( new DestinationFactory() )->from_spec( $spec );
		} catch ( DestinationException $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WP_CLI::error renders the message to the terminal, not HTML; DestinationException messages never carry a secret (ADR 0017).
			WP_CLI::error( PathRedactor::from_environment()->redact( $error->getMessage() ) );
		}
	}

	/**
	 * Upload a finished archive to a resolved destination, if one was given.
	 *
	 * The upload runs here in the CLI process, which has no web timeout, so a
	 * large archive is not bound by a request limit. An unencrypted upload warns
	 * first: the archive leaves the server for storage whose safety Pontifex
	 * cannot vouch for. Once uploaded, the destination's configured retention is
	 * enforced by pruning the oldest surplus archives; a prune failure — whether
	 * the listing itself failed, or individual deletes were attempted and
	 * refused — only warns and never fails the export, because the backup
	 * itself is already safely uploaded by the time retention runs. Silence
	 * used to cover both "nothing needed pruning" and "every delete was
	 * refused" alike; a refused delete is now always reported, because a
	 * destination quietly filling up looks identical to a healthy one until
	 * someone needs the space retention was supposed to have freed.
	 *
	 * @param DestinationAdapter|null $adapter     The resolved destination, or null for none.
	 * @param string                  $output_path The finished archive to upload.
	 * @param bool                    $encrypted   Whether the archive is encrypted.
	 * @param int                     $retention   How many archives to keep at the destination; below MIN_RETENTION keeps all.
	 * @return void
	 */
	private function upload_archive( ?DestinationAdapter $adapter, string $output_path, bool $encrypted, int $retention ): void {
		if ( null === $adapter ) {
			return;
		}

		// Both messages below routinely name an absolute server path (the local
		// archive, or a path inside the failure reason itself), and this output is
		// exactly what an operator pastes into a support thread — the same reason
		// print_failure_verdict() redacts. One instance, reused for both.
		$redactor = PathRedactor::from_environment();

		if ( ! $encrypted ) {
			WP_CLI::warning( __( 'Uploading an unencrypted archive to the destination — anyone who can read the destination can read this backup. Consider --encrypt.', 'pontifex' ) );
		}

		WP_CLI::log( __( 'Uploading the archive to the destination…', 'pontifex' ) );
		try {
			$adapter->put( $output_path );
		} catch ( DestinationException $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WP_CLI::error renders the message to the terminal, not HTML; the message is redacted, not escaped for markup.
			WP_CLI::error(
				sprintf(
					/* translators: 1: the failure reason, 2: the local archive path */
					__( 'The upload to the destination failed: %1$s The local archive is still at %2$s.', 'pontifex' ),
					$redactor->redact( $error->getMessage() ),
					$redactor->redact( $output_path )
				)
			);
		}

		WP_CLI::success( __( 'Uploaded the archive to the destination.', 'pontifex' ) );

		if ( $retention >= DestinationRetention::MIN_RETENTION ) {
			try {
				$result = ( new DestinationRetention( $adapter, $retention, $this->logger ) )->prune();
				foreach ( $result->deleted() as $remote_name ) {
					WP_CLI::log( sprintf( /* translators: %s: the remote archive name that was deleted */ __( 'Pruned old archive from the destination: %s', 'pontifex' ), $remote_name ) );
				}

				$failed = $result->failed();
				if ( array() !== $failed ) {
					// Deletes were attempted and refused: reported, never silent — see
					// this method's own docblock for the false "nothing was pruned"
					// this replaces. Still only a warning: the backup is already
					// safely uploaded, so a retention problem must never fail the export.
					WP_CLI::warning(
						sprintf(
							/* translators: %d: number of archives that could not be pruned */
							_n(
								'The archive uploaded successfully, but %d old archive at the destination could not be pruned and remains in place. Check the Pontifex log for the reason.',
								'The archive uploaded successfully, but %d old archives at the destination could not be pruned and remain in place. Check the Pontifex log for the reason.',
								count( $failed ),
								'pontifex'
							),
							count( $failed )
						)
					);
				}
			} catch ( DestinationException $error ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WP_CLI::warning renders the message to the terminal, not HTML; the message is redacted, not escaped for markup.
				WP_CLI::warning( sprintf( /* translators: %s: the failure reason */ __( 'The archive uploaded successfully, but pruning old archives at the destination failed: %s', 'pontifex' ), $redactor->redact( $error->getMessage() ) ) );
			}
		}
	}

	// -------------------------------------------------------------------------
	// Flag parsing and validation.
	// -------------------------------------------------------------------------

	/**
	 * Extract the required --output path from the associative args.
	 *
	 * @param array<string, string|bool> $associative_args The CLI's associative args.
	 * @return string The output path as a non-empty string.
	 */
	private function require_output_path( array $associative_args ): string {
		if ( ! isset( $associative_args['output'] ) || '' === $associative_args['output'] ) {
			WP_CLI::error( __( '--output=<path> is required.', 'pontifex' ) );
		}
		return (string) $associative_args['output'];
	}

	/**
	 * Verify that the output path is usable: absolute, parent exists, parent is writable.
	 *
	 * Exits via WP_CLI::error (which halts the command) on any failure.
	 *
	 * @param string $output_path The path the user supplied.
	 * @return void
	 */
	private function validate_output_path( string $output_path ): void {
		if ( '/' !== substr( $output_path, 0, 1 ) ) {
			WP_CLI::error(
				sprintf( '--output must be an absolute path; got "%s".', $output_path )
			);
		}

		$parent_directory = dirname( $output_path );

		if ( ! $this->environment->is_dir( $parent_directory ) ) {
			WP_CLI::error(
				sprintf( 'Output directory does not exist: %s', $parent_directory )
			);
		}

		if ( ! $this->environment->is_writable( $parent_directory ) ) {
			WP_CLI::error(
				sprintf( 'Output directory is not writable: %s', $parent_directory )
			);
		}
	}

	/**
	 * Load and parse an exclude-file path into a list of pattern strings.
	 *
	 * Reads the file, then delegates parsing to parse_exclude_file_contents()
	 * which handles the blank-line and comment-skipping rules.
	 *
	 * Exits via WP_CLI::error if the file is missing or unreadable.
	 *
	 * @param string $exclude_file_path Absolute or relative path to the exclude file.
	 * @return string[] Patterns read from the file, in declaration order.
	 */
	private function load_exclude_file( string $exclude_file_path ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.PHP.NoSilencedErrors.Discouraged -- Reading a user-supplied exclude file from disk; @ traps an unreadable-file warning that we convert to a WP_CLI error below.
		$contents = @file_get_contents( $exclude_file_path );
		if ( false === $contents ) {
			WP_CLI::error(
				sprintf( 'Could not read --exclude-file: %s', $exclude_file_path )
			);
		}
		return self::parse_exclude_file_contents( $contents );
	}

	/**
	 * Parse exclude-file contents into a list of pattern strings.
	 *
	 * Splits on newlines, trims whitespace, skips blank lines and
	 * lines beginning with `#`. Lines that survive are emitted as
	 * patterns in declaration order. Pure function: no I/O.
	 *
	 * @param string $contents Raw bytes from the exclude file.
	 * @return string[] Parsed patterns; may be empty.
	 */
	private static function parse_exclude_file_contents( string $contents ): array {
		$patterns = array();
		$lines    = preg_split( '/\r\n|\r|\n/', $contents );
		if ( false === $lines ) {
			return array();
		}
		foreach ( $lines as $line ) {
			$trimmed = trim( $line );
			if ( '' === $trimmed ) {
				continue;
			}
			if ( '#' === substr( $trimmed, 0, 1 ) ) {
				continue;
			}
			$patterns[] = $trimmed;
		}
		return $patterns;
	}

	/**
	 * Whether the curated default exclusions should be applied.
	 *
	 * WP-CLI parses the documented `--no-defaults` flag with its --no-<name>
	 * convention, delivering it as array( 'defaults' => false ), NOT as a
	 * 'no-defaults' key — so reading 'no-defaults' would silently ignore the flag
	 * (the same trap that hid the --no-rollback-archive bug). Defaults apply unless
	 * WP-CLI delivered defaults => false.
	 *
	 * @param array<string, string|bool> $associative_args The CLI's associative args.
	 * @return bool True if the curated defaults should be applied.
	 */
	private static function should_use_defaults( array $associative_args ): bool {
		return ! ( array_key_exists( 'defaults', $associative_args ) && false === $associative_args['defaults'] );
	}

	/**
	 * Combine the curated default patterns and user-supplied patterns into a single ExclusionRules.
	 *
	 * When $use_defaults is true, the v0.1.0 curated defaults
	 * ({@see ExclusionRules::default_v010()}) come first, followed by
	 * the user's patterns. When false, only the user's patterns are
	 * used. Pure function: no I/O.
	 *
	 * @param bool     $use_defaults  True to include the curated defaults.
	 * @param string[] $user_patterns Additional patterns from --exclude-file.
	 * @return ExclusionRules A merged rule set.
	 */
	private static function build_exclusion_rules( bool $use_defaults, array $user_patterns ): ExclusionRules {
		$default_patterns = $use_defaults
			? ExclusionRules::default_v010()->patterns()
			: array();
		$merged_patterns  = array_merge( $default_patterns, $user_patterns );
		return ExclusionRules::from_array( $merged_patterns );
	}

	/**
	 * Split a comma-separated flag value into a trimmed, non-empty pattern list.
	 *
	 * WP-CLI keeps only the last value of a repeated same-name flag, so a
	 * repeatable exclusion is expressed as one comma-separated value. A missing
	 * flag (null) or a bare boolean flag yields no patterns.
	 *
	 * @param string|bool|null $value The raw flag value from the associative args.
	 * @return string[] The individual patterns, trimmed, with blanks dropped.
	 */
	private static function split_patterns( $value ): array {
		if ( ! is_string( $value ) || '' === $value ) {
			return array();
		}
		$patterns = array();
		foreach ( explode( ',', $value ) as $pattern ) {
			$pattern = trim( $pattern );
			if ( '' !== $pattern ) {
				$patterns[] = $pattern;
			}
		}
		return $patterns;
	}

	// -------------------------------------------------------------------------
	// Per-run wiring.
	// -------------------------------------------------------------------------

	/**
	 * Build the default file logger when the caller supplies none.
	 *
	 * Reads WP_CONTENT_DIR and WP_DEBUG through the Environment seam so
	 * the path and verbosity follow the host WordPress, and tests that
	 * inject their own logger never reach this code.
	 *
	 * @return LoggerInterface A FileLogger writing under wp-content/pontifex/logs.
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
	 * Resolve the WordPress installation root for the file scan.
	 *
	 * Reads the ABSPATH constant via the Environment abstraction so
	 * tests can substitute a fixture path.
	 *
	 * @return string The absolute path of the WordPress root.
	 * @throws RuntimeException If ABSPATH is not defined (should never happen inside a WordPress request).
	 */
	private function resolve_wordpress_root(): string {
		return WordPressRoot::resolve( $this->environment );
	}

	/**
	 * Resolve the wp-content root for a content-only file scan.
	 *
	 * Reads WP_CONTENT_DIR through the Environment abstraction — the directory
	 * WordPress actually serves wp-content from, which a site may have relocated —
	 * and falls back to ABSPATH/wp-content (WordPress's own default for the constant)
	 * when it is not defined, so the resolver still works outside a full WordPress
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
	// Counters.
	// -------------------------------------------------------------------------

	/**
	 * Read-modify-write the stored export counters by a delta.
	 *
	 * Reads the single stats option through the WordPress-context
	 * seam, merges the delta in, and writes it back. The arithmetic
	 * lives in merge_counters so this method is only the I/O.
	 *
	 * @param array<string, int> $delta The amounts to add, keyed by counter name.
	 * @return void
	 */
	private function bump_counters( array $delta ): void {
		$current = $this->wordpress_context->option_value(
			self::STATS_OPTION,
			array(
				'attempted'             => 0,
				'succeeded'             => 0,
				'failed'                => 0,
				'bytes_exported'        => 0,
				'files_changed'         => 0,
				'media_type_unresolved' => 0,
			)
		);

		$merged = self::merge_counters( is_array( $current ) ? $current : array(), $delta );
		$this->wordpress_context->save_option( self::STATS_OPTION, $merged );
	}

	/**
	 * Combine the stored counters with a delta into a clean six-key set.
	 *
	 * Pure function. Tolerant of a missing, partial, or corrupt stored
	 * value: every counter coerces through counter_int, so a garbage
	 * option can never throw. Only the six known keys are returned.
	 *
	 * @param array<array-key, mixed> $current The counters as currently stored.
	 * @param array<array-key, mixed> $delta   The amounts to add per key.
	 * @return array<string, int> The merged counters.
	 */
	private static function merge_counters( array $current, array $delta ): array {
		return ExportCounters::merge( $current, $delta );
	}

	// -------------------------------------------------------------------------
	// Output formatting.
	// -------------------------------------------------------------------------

	/**
	 * Print which scope the export will use, so the user sees it before confirming.
	 *
	 * The default changed to content-only in v0.5.x (ADR 0008), so this line is
	 * also the cue that points a user wanting the old full-site behaviour at
	 * --whole-site.
	 *
	 * @param bool $whole_site True for a whole-site export.
	 * @param bool $files_only  True for a files-only export (wp-content, no database).
	 * @param bool $db_only     True for a database-only export (no files).
	 * @return void
	 */
	private function print_scope_summary( bool $whole_site, bool $files_only = false, bool $db_only = false ): void {
		if ( $whole_site ) {
			WP_CLI::log( __( 'Scope: whole-site (the entire WordPress root, including core and wp-config.php).', 'pontifex' ) );
			return;
		}
		if ( $files_only ) {
			WP_CLI::log( __( 'Scope: files-only (wp-content, no database). Its restore writes only files.', 'pontifex' ) );
			return;
		}
		if ( $db_only ) {
			WP_CLI::log( __( 'Scope: database-only (every table belonging to this WordPress site, no files). Its restore writes only the database.', 'pontifex' ) );
			return;
		}
		WP_CLI::log( __( 'Scope: content-only (wp-content plus every table belonging to this WordPress site). Use --whole-site for a full-site clone.', 'pontifex' ) );
	}

	/**
	 * Print the active exclusion patterns so the user can review them before confirming.
	 *
	 * @param ExclusionRules $exclusion_rules The rules that will be applied to the export.
	 * @return void
	 */
	private function print_exclusion_summary( ExclusionRules $exclusion_rules ): void {
		$patterns = $exclusion_rules->patterns();
		if ( empty( $patterns ) ) {
			WP_CLI::log( __( 'No exclusion patterns are active.', 'pontifex' ) );
			return;
		}
		WP_CLI::log( sprintf( /* translators: %d: number of active exclusion patterns */ __( 'Active exclusion patterns (%d):', 'pontifex' ), count( $patterns ) ) );
		foreach ( $patterns as $pattern ) {
			WP_CLI::log( '  ' . $pattern );
		}
	}

	/**
	 * Warn about files whose content changed while the export was reading them.
	 *
	 * The archive records each such file's content at the byte count actually
	 * captured — never the stale scan-time claim — so the backup is internally
	 * consistent and restores exactly what was read. The warnings exist because
	 * the user should know those files were moving while the backup ran and may
	 * want to re-run the export at a quieter moment.
	 *
	 * @param ExportResult $result The completed export's result.
	 * @return void
	 */
	private function print_changed_file_warnings( ExportResult $result ): void {
		$changed_files = $result->changed_files();
		if ( array() === $changed_files ) {
			return;
		}

		foreach ( $changed_files as $changed_file ) {
			WP_CLI::warning(
				sprintf(
					/* translators: 1: file path, 2: byte count recorded at scan time, 3: byte count actually captured */
					__( '%1$s changed while it was being read (the scan recorded %2$d bytes; %3$d were captured). The archive records the captured content.', 'pontifex' ),
					$changed_file['path'],
					$changed_file['declared_size'],
					$changed_file['actual_size']
				)
			);
		}

		WP_CLI::warning(
			sprintf(
				/* translators: %d: number of files that changed during the export */
				_n(
					'%d file changed while the backup ran. The archive is consistent, but re-run the export if you want a settled copy of that file.',
					'%d files changed while the backup ran. The archive is consistent, but re-run the export if you want settled copies of those files.',
					count( $changed_files ),
					'pontifex'
				),
				count( $changed_files )
			)
		);
	}

	/**
	 * Warn when file entries' media type could not be genuinely determined.
	 *
	 * Sniffing failure (the fileinfo extension missing, an unreadable source, or
	 * finfo itself failing) records the same generic fallback media_type a
	 * genuinely unidentifiable file (a real .DS_Store, say) also legitimately
	 * sniffs as — the two are otherwise indistinguishable after the fact. The
	 * archive itself is entirely unaffected: every such entry still restores
	 * its exact content, and the media type is metadata only. This warning
	 * exists so a systemic failure — the whole host missing fileinfo, for
	 * instance — is visible rather than silently reading as an ordinary run
	 * of unremarkable, unrecognised files.
	 *
	 * @param int $unresolved_count Number of file entries whose media type could not be determined.
	 * @return void
	 */
	private function print_media_type_warning( int $unresolved_count ): void {
		if ( 0 === $unresolved_count ) {
			return;
		}

		WP_CLI::warning(
			sprintf(
				/* translators: %d: number of files whose media type could not be determined */
				_n(
					"%d file's media type could not be determined and was recorded as unknown. The archive is unaffected and restores normally.",
					"%d files' media types could not be determined and were recorded as unknown. The archive is unaffected and restores normally.",
					$unresolved_count,
					'pontifex'
				),
				$unresolved_count
			)
		);
	}

	/**
	 * Print the final post-export summary line.
	 *
	 * @param string $output_path     Where the archive was written.
	 * @param int    $entry_count     How many entries the archive contains.
	 * @param int    $bytes_written   Total bytes written to disk.
	 * @return void
	 */
	private function print_summary( string $output_path, int $entry_count, int $bytes_written ): void {
		WP_CLI::log(
			sprintf(
				/* translators: 1: number of entries, 2: human-readable size, 3: the output file path */
				__( 'Exported %1$d entries (%2$s) to %3$s', 'pontifex' ),
				$entry_count,
				$this->wordpress_context->format_size( $bytes_written ),
				$output_path
			)
		);
	}

	/**
	 * Print why the export stopped, in terms an operator can act on.
	 *
	 * Three situations demand three different responses, and the exception's
	 * type is what tells them apart (ADR 0022): an archive that cannot be
	 * trusted means this backup is not one; a host that cannot comply means the
	 * site is fine and the server is the fixable problem; a wrong request means
	 * correct the invocation. A failure carrying none of those types is
	 * reported plainly rather than guessed at — most of the safety core still
	 * throws untyped exceptions, and inventing a kind for one would be worse
	 * than admitting we do not know.
	 *
	 * Unlike import's verdict, the kind lines carry no path: an operator
	 * exporting already knows where they asked the archive to go, and what they
	 * do not know is whether one is now there. {@see self::print_output_state()}
	 * answers that instead, once, at the end.
	 *
	 * The engine's message goes through the redactor, because it routinely
	 * names an absolute path and this output is exactly what an operator pastes
	 * into a support thread.
	 *
	 * @param string    $output_path     Where the archive was being written ('' if a resume failed before learning it).
	 * @param Throwable $error           The failure that ended the run.
	 * @param bool      $resumable       True when this run was resumable, so its finished work is kept.
	 * @param bool      $archive_written True when a complete archive reached the output path before the failure.
	 * @return void
	 */
	private function print_failure_verdict( string $output_path, Throwable $error, bool $resumable, bool $archive_written ): void {
		$message = PathRedactor::from_environment()->redact( $error->getMessage() );

		WP_CLI::log( self::failure_headline( $error instanceof PontifexException ) );

		if ( $error instanceof ArchiveNotTrustworthy ) {
			WP_CLI::log(
				sprintf(
					/* translators: %s: the reason the archive was refused */
					__( 'The archive cannot be trusted: %s', 'pontifex' ),
					$message
				)
			);
			// Deliberately no output-state line: on a resumable run this is the one
			// failure where "continue it with --resume" would be the wrong advice.
			WP_CLI::log( __( 'Do not continue this export — start a fresh one.', 'pontifex' ) );
			return;
		}

		if ( $error instanceof HostCannotComply ) {
			WP_CLI::log(
				sprintf(
					/* translators: %s: the reason this host could not comply */
					__( 'This host cannot complete the backup: %s', 'pontifex' ),
					$message
				)
			);
			WP_CLI::log( __( 'The problem is this server — usually disk space, memory, or permissions — and it is usually fixable.', 'pontifex' ) );
			$this->print_output_state( $output_path, $resumable, $archive_written );
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
				/* translators: %s: the failure message */
				__( 'The failure was: %s', 'pontifex' ),
				$message
			)
		);
		WP_CLI::log( __( 'Full details are in the Pontifex log: wp-content/pontifex/logs/pontifex.log', 'pontifex' ) );
		$this->print_output_state( $output_path, $resumable, $archive_written );
	}

	/**
	 * Say what, if anything, this run left at the output path.
	 *
	 * The question a failed backup raises is not only why it stopped but
	 * whether there is now a backup — and all three answers are different. A
	 * completed archive that failed on upload is a usable backup sitting on
	 * this server. A one-shot run leaves nothing: the engine writes to a temp
	 * sibling and removes it, so any file still at the output path is an
	 * earlier archive it never replaced — which is exactly the file an operator
	 * could otherwise mistake for the backup they just tried to take.
	 *
	 * A resumable run is the case that most invites a wrong answer. "Continue
	 * it with --resume" is the obvious thing to say and it is false: a tick
	 * that raises marks its job FAILED, that status is terminal, and --resume
	 * accepts only an active job — so it would refuse. Whether such a run also
	 * strands its part-written file depends on where it died (a tick leaves
	 * one; a failure at the final move discards it), which is too fine a
	 * distinction to assert blind — so this looks for the file rather than
	 * predicting it. The output path it looks beside is one a resumed run
	 * learned from the job record rather than the command line, so its operator
	 * may never have typed it.
	 *
	 * @param string $output_path     Where the archive was being written ('' if a resume failed before learning it).
	 * @param bool   $resumable       True when this run was resumable.
	 * @param bool   $archive_written True when a complete archive reached the output path before the failure.
	 * @return void
	 */
	private function print_output_state( string $output_path, bool $resumable, bool $archive_written ): void {
		$path = PathRedactor::from_environment()->redact( $output_path );

		if ( $archive_written ) {
			WP_CLI::log(
				sprintf(
					/* translators: %s: the output file path */
					__( 'The archive itself was written to %s and is complete; the failure came after it.', 'pontifex' ),
					$path
				)
			);
			return;
		}

		if ( $resumable ) {
			WP_CLI::log( __( 'This export cannot be continued with --resume: a failed job is closed, and only an interrupted one can be picked up again.', 'pontifex' ) );

			$orphan = self::orphaned_part_file( $output_path );
			if ( null !== $orphan ) {
				WP_CLI::log(
					sprintf(
						/* translators: %s: the path of the part-written file left behind */
						__( 'A part-written file is left at %s. It is not a backup and nothing will resume it — delete it and export again.', 'pontifex' ),
						PathRedactor::from_environment()->redact( $orphan )
					)
				);
				return;
			}

			WP_CLI::log( __( 'No archive was written. Export again once the problem is fixed.', 'pontifex' ) );
			return;
		}

		if ( '' === $output_path ) {
			WP_CLI::log( __( 'No archive was written.', 'pontifex' ) );
			return;
		}

		WP_CLI::log(
			sprintf(
				/* translators: %s: the output file path */
				__( 'No archive was written to %s. Anything already there is an earlier backup, left untouched.', 'pontifex' ),
				$path
			)
		);
	}

	/**
	 * The part-written file a failed resumable export left beside its output, if any.
	 *
	 * A resumable run writes to `<output>.pontifex-job-<unique>.part` and moves
	 * it into place at the end, so whether one survives a failure depends on
	 * where the failure happened. Rather than guess, look: telling an operator
	 * to delete a file that is not there, or leaving a large one unmentioned,
	 * are both worse than the one glob this costs.
	 *
	 * @param string $output_path The archive destination ('' when a resume failed before learning it).
	 * @return string|null The first matching part file, or null when there is none to report.
	 */
	private static function orphaned_part_file( string $output_path ): ?string {
		if ( '' === $output_path ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_glob -- Listing the plugin's own part-written files beside the output path; WP_Filesystem is unavailable in CLI contexts.
		$matches = glob( $output_path . '.pontifex-job-*.part' );
		if ( false === $matches || array() === $matches ) {
			return null;
		}

		sort( $matches );
		return $matches[0];
	}

	/**
	 * The opening line of a failure verdict: what happened.
	 *
	 * A refusal is a decision Pontifex made and can explain; anything else is a
	 * fault, and calling a fault a refusal would claim an intent that was not
	 * there. Export has no dry run, so unlike import there is no rehearsal to
	 * distinguish — every failure here is a real one.
	 *
	 * @param bool $is_refusal True when Pontifex refused deliberately (ADR 0022's marker).
	 * @return string
	 */
	private static function failure_headline( bool $is_refusal ): string {
		return $is_refusal
			? __( 'Export refused.', 'pontifex' )
			: __( 'Export failed.', 'pontifex' );
	}
}
