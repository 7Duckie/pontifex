<?php
/**
 * Pontifex Export command — produces a Pontifex archive of the current WordPress site.
 *
 * @package Pontifex\Cli
 */

declare(strict_types=1);

namespace Pontifex\Cli;

use DateTimeImmutable;
use RuntimeException;
use Throwable;
use WP_CLI;
use Psr\Log\LoggerInterface;
use Pontifex\Archive\Codec\CodecRegistry;
use Pontifex\Archive\Format\ExporterInfo;
use Pontifex\Archive\Format\Provenance;
use Pontifex\Archive\Writer\ArchiveWriter;
use Pontifex\Archive\Writer\EntryWriter;
use Pontifex\Archive\Writer\FooterWriter;
use Pontifex\Environment\Environment;
use Pontifex\Environment\RealEnvironment;
use Pontifex\Log\FileLogger;
use Pontifex\Manifest\DatabaseScanner;
use Pontifex\Manifest\ExclusionRules;
use Pontifex\Manifest\FileScanner;
use Pontifex\Manifest\ManifestBuilder;
use Pontifex\Manifest\ManifestBuilderInterface;
use Pontifex\Manifest\WpdbAdapter;
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
 * ## EXAMPLES
 *
 *     wp pontifex export --output=/tmp/site.wpmig
 *     wp pontifex export --output=/tmp/site.wpmig --yes
 *     wp pontifex export --output=/tmp/site.wpmig --exclude-file=/tmp/extras.txt
 *     wp pontifex export --output=/tmp/site.wpmig --no-defaults --exclude-file=/tmp/only.txt
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
	 */
	public function __construct(
		?Environment $environment = null,
		?WordPressContext $wordpress_context = null,
		?ManifestBuilderInterface $manifest_builder = null,
		?LoggerInterface $logger = null
	) {
		$this->environment       = $environment ?? new RealEnvironment();
		$this->wordpress_context = $wordpress_context ?? new RealWordPressContext();
		$this->manifest_builder  = $manifest_builder;
		$this->logger            = $logger ?? $this->build_default_logger();
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
		$use_defaults      = ! ( isset( $associative_args['no-defaults'] ) && false !== $associative_args['no-defaults'] );
		$skip_confirmation = isset( $associative_args['yes'] ) && false !== $associative_args['yes'];

		$this->validate_output_path( $output_path );

		// 2. Build the exclusion rules.
		$user_patterns = '' !== $exclude_file_path
			? $this->load_exclude_file( $exclude_file_path )
			: array();

		$exclusion_rules = self::build_exclusion_rules( $use_defaults, $user_patterns );

		// 3. Confirm with the user (unless --yes).
		if ( ! $skip_confirmation ) {
			$this->print_exclusion_summary( $exclusion_rules );
			WP_CLI::confirm( sprintf( 'Export to %s?', $output_path ), $associative_args );
		}

		$this->logger->info(
			'Export started.',
			array(
				'output'     => $output_path,
				'exclusions' => count( $exclusion_rules->patterns() ),
			)
		);

		$this->bump_counters( array( 'attempted' => 1 ) );

		// 4. Build the Provenance block.
		$provenance = $this->build_provenance();

		// 5. Wire up the manifest builder if the caller did not supply one.
		$manifest_builder = $this->manifest_builder ?? $this->build_default_manifest_builder( $exclusion_rules );

		// 6. Open the destination file.
		$destination = $this->open_destination( $output_path );

		try {
			// 7. Build entry plans and write the archive.
			$wordpress_root = $this->resolve_wordpress_root();
			$entry_plans    = $manifest_builder->build( $wordpress_root );

			$archive_writer = self::build_archive_writer();
			$bytes_written  = $archive_writer->write_archive( $provenance, $entry_plans, $destination );

			$this->logger->info(
				'Export complete.',
				array(
					'output'  => $output_path,
					'entries' => count( $entry_plans ),
					'bytes'   => $bytes_written,
				)
			);

			$this->bump_counters(
				array(
					'succeeded'      => 1,
					'bytes_exported' => $bytes_written,
				)
			);

			// 8. Print the summary.
			$this->print_summary( $output_path, count( $entry_plans ), $bytes_written );
		} catch ( Throwable $error ) {
			$this->logger->error(
				'Export failed.',
				array(
					'output'    => $output_path,
					'exception' => $error,
				)
			);
			$this->bump_counters( array( 'failed' => 1 ) );
			throw $error;
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing a stream resource opened in this method; not a WP_Filesystem operation.
			fclose( $destination );
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
			WP_CLI::error( '--output=<path> is required.' );
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
	 * Build a Provenance block from current WordPress and PHP runtime facts.
	 *
	 * Reads wp_version, php_version, site_url, wpdb_charset, and
	 * wpdb_collation from the injected abstractions. The exporter
	 * name is the hardcoded literal "pontifex"; the version comes
	 * from the PONTIFEX_VERSION constant defined in pontifex.php.
	 *
	 * @return Provenance A fully-populated provenance value object.
	 */
	private function build_provenance(): Provenance {
		$pontifex_version = $this->environment->is_constant_defined( 'PONTIFEX_VERSION' )
			? (string) $this->environment->constant_value( 'PONTIFEX_VERSION' )
			: '0.0.0-dev';

		$exporter_info = new ExporterInfo( 'pontifex', $pontifex_version );

		return new Provenance(
			$this->wordpress_context->wp_version(),
			$this->environment->php_version(),
			$this->wordpress_context->site_url(),
			$this->wordpress_context->wpdb_charset(),
			$this->wordpress_context->wpdb_collation(),
			$exporter_info,
			new DateTimeImmutable()
		);
	}

	/**
	 * Build a ManifestBuilder from the supplied exclusion rules.
	 *
	 * Used when no ManifestBuilder was passed to the constructor.
	 * Wires up FileScanner + DatabaseScanner (with a WpdbAdapter
	 * wrapping the real $wpdb) into a ManifestBuilder.
	 *
	 * @param ExclusionRules $exclusion_rules Rules to apply to both scanners.
	 * @return ManifestBuilder A scanner-backed manifest builder.
	 */
	private function build_default_manifest_builder( ExclusionRules $exclusion_rules ): ManifestBuilder {
		$file_scanner     = new FileScanner( $exclusion_rules );
		$database_adapter = new WpdbAdapter( $this->wordpress_context->wpdb_instance() );
		$database_scanner = new DatabaseScanner( $database_adapter, $exclusion_rules );
		return new ManifestBuilder( $file_scanner, $database_scanner );
	}

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
		$content_dir = $this->environment->is_constant_defined( 'WP_CONTENT_DIR' )
			? (string) $this->environment->constant_value( 'WP_CONTENT_DIR' )
			: sys_get_temp_dir();

		$debug_enabled = $this->environment->is_constant_defined( 'WP_DEBUG' )
			&& (bool) $this->environment->constant_value( 'WP_DEBUG' );

		return new FileLogger( $content_dir . '/pontifex/logs', $debug_enabled );
	}

	/**
	 * Build the ArchiveWriter with its writer dependencies.
	 *
	 * The codec registry uses the v0.1.0 defaults (RawCodec + GzipCodec).
	 * The EntryWriter and FooterWriter wrap the registry and the
	 * footer-serialisation logic respectively.
	 *
	 * @return ArchiveWriter A ready-to-use archive writer.
	 */
	private static function build_archive_writer(): ArchiveWriter {
		$codec_registry = CodecRegistry::with_defaults();
		$entry_writer   = new EntryWriter( $codec_registry );
		$footer_writer  = new FooterWriter();
		return new ArchiveWriter( $entry_writer, $footer_writer );
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

	/**
	 * Open the destination file for writing.
	 *
	 * Exits via WP_CLI::error if fopen fails.
	 *
	 * @param string $output_path Absolute path to the file to create.
	 * @return resource A writable binary stream resource.
	 */
	private function open_destination( string $output_path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- Opening the destination archive file as a stream; @ traps an unopenable-file warning that we convert to a WP_CLI error below.
		$destination = @fopen( $output_path, 'wb' );
		if ( false === $destination ) {
			WP_CLI::error(
				sprintf( 'Could not open destination for writing: %s', $output_path )
			);
		}
		return $destination;
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
			WP_CLI::log( 'No exclusion patterns are active.' );
			return;
		}
		WP_CLI::log( sprintf( 'Active exclusion patterns (%d):', count( $patterns ) ) );
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
				'Exported %d entries (%s) to %s',
				$entry_count,
				$this->wordpress_context->format_size( $bytes_written ),
				$output_path
			)
		);
	}
}
