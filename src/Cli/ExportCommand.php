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
use Pontifex\Environment\Environment;
use Pontifex\Environment\RealEnvironment;
use Pontifex\Export\ExportOptions;
use Pontifex\Export\ExportRunner;
use Pontifex\Log\CompositeLogger;
use Pontifex\Log\FileLogger;
use Pontifex\Manifest\ExclusionRules;
use Pontifex\Manifest\ManifestBuilderInterface;
use Pontifex\WordPress\RealWordPressContext;
use Pontifex\WordPress\WordPressContext;

/**
 * `wp pontifex export` — produce a Pontifex archive of the current WordPress site.
 *
 * Writes a single .wpmig archive file containing every file under the
 * WordPress root (minus exclusions) and every WordPress-prefixed
 * database table (chunked into ~4 MiB pieces). The archive is the
 * complete on-disk artefact needed to restore the site to its
 * current state on a different host.
 *
 * ## OPTIONS
 *
 * --output=<path>
 * : Absolute filesystem path where the archive should be written.
 *   The parent directory must exist and be writable.
 *
 * [--exclude-file=<path>]
 * : Path to a file containing additional exclusion patterns, one per
 *   line. Blank lines and lines starting with `#` are ignored.
 *   Pattern syntax matches Pontifex's ExclusionRules: regex
 *   (delimited with `/`), directory tree (`path/**`), glob (`*.log`),
 *   or exact string.
 *
 * [--no-defaults]
 * : Skip the curated default exclusion list (Pontifex's working dir,
 *   wp-content/cache, other backup plugins' directories). Use only
 *   patterns from `--exclude-file`, if any.
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
 * ## EXAMPLES
 *
 *     wp pontifex export --output=/tmp/site.wpmig
 *     wp pontifex export --output=/tmp/site.wpmig --yes
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
	private const STATS_OPTION = 'pontifex_export_stats';

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
	 */
	public function __construct(
		?Environment $environment = null,
		?WordPressContext $wordpress_context = null,
		?ManifestBuilderInterface $manifest_builder = null,
		?LoggerInterface $logger = null,
		?ProgressReporter $progress = null,
		?PassphraseSource $passphrase_source = null
	) {
		$this->environment          = $environment ?? new RealEnvironment();
		$this->wordpress_context    = $wordpress_context ?? new RealWordPressContext();
		$this->manifest_builder     = $manifest_builder;
		$this->logger_was_defaulted = null === $logger;
		$this->logger               = $logger ?? $this->build_default_logger();
		$this->progress             = $progress ?? new WpCliProgressBar();
		$this->passphrase_source    = $passphrase_source ?? new CliPassphraseSource();
	}

	/**
	 * The WP-CLI command entry point.
	 *
	 * `__invoke` is the magic method WP-CLI dispatches to for a single-
	 * command class. Orchestrates: parse flags, validate inputs, build
	 * exclusion rules, optionally confirm, build provenance, run the
	 * manifest builder, write the archive, print a summary.
	 *
	 * @param array<int, string>         $positional_args  Positional arguments passed on the CLI. Unused for `export`.
	 * @param array<string, string|bool> $associative_args Associative `--key=value` and `--flag` arguments.
	 * @return void
	 * @throws Throwable Re-thrown after logging if the archive write fails.
	 */
	public function __invoke( array $positional_args, array $associative_args ): void {

		// 1. Parse and validate flags.
		$output_path       = $this->require_output_path( $associative_args );
		$exclude_file_path = isset( $associative_args['exclude-file'] ) ? (string) $associative_args['exclude-file'] : '';
		$use_defaults      = self::should_use_defaults( $associative_args );
		$skip_confirmation = isset( $associative_args['yes'] ) && false !== $associative_args['yes'];
		$passphrase_stdin  = isset( $associative_args['passphrase-stdin'] ) && false !== $associative_args['passphrase-stdin'];
		$encrypting        = $passphrase_stdin || ( isset( $associative_args['encrypt'] ) && false !== $associative_args['encrypt'] );
		$signing_requested = isset( $associative_args['sign'] ) && false !== $associative_args['sign'];
		$signing_key_path  = isset( $associative_args['signing-key'] ) ? (string) $associative_args['signing-key'] : '';

		$this->validate_output_path( $output_path );

		// 1a. Tee a per-transfer log alongside the archive, so this export leaves a
		// self-contained record next to its .wpmig (in addition to the central log).
		$this->attach_transfer_log(
			static fn (): string => dirname( $output_path ),
			basename( $output_path ) . '.log'
		);

		// 2. Build the exclusion rules.
		$user_patterns = '' !== $exclude_file_path
			? $this->load_exclude_file( $exclude_file_path )
			: array();

		$exclusion_rules = self::build_exclusion_rules( $use_defaults, $user_patterns );

		// 3. Confirm with the user (unless --yes).
		if ( ! $skip_confirmation ) {
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

		// 4. Build the entry list (every file plus every database table to archive).
		$manifest_builder = $this->manifest_builder ?? ExportRunner::default_manifest_builder( $this->wordpress_context, $exclusion_rules );

		// 5. Write the archive through the shared export engine.
		$export_runner = new ExportRunner( $this->environment, $this->wordpress_context );

		try {
			$entry_plans = $manifest_builder->build( $this->resolve_wordpress_root() );

			$this->progress->start( count( $entry_plans ), 'Writing archive' );
			$result = $export_runner->export(
				new ExportOptions( $output_path, $encryption, $signing, $encryption_disabled_reason ),
				$entry_plans,
				function (): void {
					$this->progress->advance();
				}
			);
			$this->progress->finish();

			$bytes_written = $result->bytes_written();

			$this->logger->info(
				'Export complete.',
				array(
					'output'  => $output_path,
					'entries' => $result->entry_count(),
					'bytes'   => $bytes_written,
				)
			);

			$this->bump_counters(
				array(
					'succeeded'      => 1,
					'bytes_exported' => $bytes_written,
				)
			);
			TransferHistory::record( $this->wordpress_context, 'export', 'succeeded', $bytes_written, gmdate( 'c' ) );

			// 6. Print the summary.
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
			throw $error;
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
		if ( ! $this->environment->is_constant_defined( 'ABSPATH' ) ) {
			throw new RuntimeException( 'ExportCommand: ABSPATH is not defined; is WordPress loaded?' );
		}
		return rtrim( (string) $this->environment->constant_value( 'ABSPATH' ), '/' );
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
				'attempted'      => 0,
				'succeeded'      => 0,
				'failed'         => 0,
				'bytes_exported' => 0,
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
	 * @return array<string, int> The merged counters.
	 */
	private static function merge_counters( array $current, array $delta ): array {
		$merged = array();
		foreach ( array( 'attempted', 'succeeded', 'failed', 'bytes_exported' ) as $key ) {
			$merged[ $key ] = self::counter_int( $current, $key ) + self::counter_int( $delta, $key );
		}
		return $merged;
	}

	/**
	 * Read one counter from an array as a non-negative-safe integer.
	 *
	 * Returns 0 when the key is absent or its value is non-numeric,
	 * so corrupt stored data degrades to zero rather than a type error.
	 *
	 * @param array<array-key, mixed> $values The array to read from.
	 * @param string                  $key    The counter key.
	 * @return int The value as an int, or 0.
	 */
	private static function counter_int( array $values, string $key ): int {
		return isset( $values[ $key ] ) && is_numeric( $values[ $key ] ) ? (int) $values[ $key ] : 0;
	}

	// -------------------------------------------------------------------------
	// Output formatting.
	// -------------------------------------------------------------------------

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
}
