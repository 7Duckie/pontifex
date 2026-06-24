<?php
/**
 * Pontifex Diagnostics command — a sanitised support bundle.
 *
 * @package Pontifex\Cli
 */

declare(strict_types=1);

namespace Pontifex\Cli;

use DateTimeImmutable;
use DateTimeZone;
use Phar;
use PharData;
use Throwable;
use WP_CLI;
use Pontifex\Environment\Environment;
use Pontifex\Environment\RealEnvironment;
use Pontifex\Filesystem\ProtectedDirectory;
use Pontifex\WordPress\RealWordPressContext;
use Pontifex\WordPress\WordPressContext;

/**
 * `wp pontifex diagnostics` — write a sanitised support bundle.
 *
 * Gathers what a maintainer needs to diagnose a problem — the environment
 * audit (`doctor`), the activity readout (`stats`), an environment summary, and
 * the recent Pontifex logs — into a single tar.gz, **sanitised** so it is safe
 * to share: the site URL, absolute paths, and any `*_key` / `*_secret` /
 * `*_token` / `*_password` option values are redacted (see
 * {@see DiagnosticsRedactor}). Nothing is ever uploaded; the operator reviews
 * the bundle and decides what to share.
 *
 * ## OPTIONS
 *
 * [--output=<path>]
 * : Absolute path to write the bundle to. Must end in `.tar.gz`. Defaults to
 *   `wp-content/pontifex/diagnostics/pontifex-diagnostics-<timestamp>.tar.gz`.
 *
 * ## EXAMPLES
 *
 *     wp pontifex diagnostics
 *     wp pontifex diagnostics --output=/tmp/pontifex-support.tar.gz
 *
 * @when after_wp_load
 */
final class DiagnosticsCommand {

	/**
	 * The Pontifex log files to include, newest first.
	 *
	 * Mirrors FileLogger's rotation scheme (pontifex.log plus .1 .. .4).
	 *
	 * @var string[]
	 */
	private const LOG_FILENAMES = array(
		'pontifex.log',
		'pontifex.log.1',
		'pontifex.log.2',
		'pontifex.log.3',
		'pontifex.log.4',
	);

	/**
	 * PHP extensions whose presence is reported in the environment summary.
	 *
	 * @var string[]
	 */
	private const ENV_EXTENSIONS = array( 'zlib', 'zstd', 'sodium', 'openssl', 'mbstring', 'pcre', 'json', 'phar' );

	/**
	 * PHP ini directives reported in the environment summary.
	 *
	 * @var string[]
	 */
	private const ENV_INI_DIRECTIVES = array( 'memory_limit', 'max_execution_time', 'upload_max_filesize', 'post_max_size' );

	/**
	 * A small, non-sensitive set of options reported in the environment summary.
	 *
	 * Each is still passed through the redactor's option masking, so a sensitively
	 * named option added here later would be masked rather than leaked.
	 *
	 * @var string[]
	 */
	private const SAFE_OPTIONS = array( 'template', 'stylesheet', 'blog_charset', 'timezone_string', 'WPLANG' );

	/**
	 * The Environment abstraction this command queries.
	 *
	 * @var Environment
	 */
	private Environment $environment;

	/**
	 * The WordPressContext abstraction this command queries.
	 *
	 * @var WordPressContext
	 */
	private WordPressContext $wordpress_context;

	/**
	 * Construct a DiagnosticsCommand instance.
	 *
	 * @param Environment|null      $environment       Optional. Defaults to a fresh RealEnvironment.
	 * @param WordPressContext|null $wordpress_context Optional. Defaults to a fresh RealWordPressContext.
	 */
	public function __construct( ?Environment $environment = null, ?WordPressContext $wordpress_context = null ) {
		$this->environment       = $environment ?? new RealEnvironment();
		$this->wordpress_context = $wordpress_context ?? new RealWordPressContext();
	}

	/**
	 * The WP-CLI command entry point.
	 *
	 * @param array<int, string>         $positional_args  Positional arguments. Unused.
	 * @param array<string, string|bool> $associative_args The `--output` path.
	 * @return void
	 */
	public function __invoke( array $positional_args, array $associative_args ): void {
		if ( ! class_exists( PharData::class ) ) {
			WP_CLI::error( 'ext-phar is required to build a diagnostics bundle but is not available on this host.' );
		}

		$output_path = $this->resolve_output_path( $associative_args );
		$redactor    = $this->build_redactor();
		$artifacts   = $this->gather_artifacts( $redactor );

		try {
			$this->write_bundle( $output_path, $artifacts );
		} catch ( Throwable $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WP_CLI::error renders to the terminal; the message is our own plus the underlying error text.
			WP_CLI::error( sprintf( 'Could not write the diagnostics bundle: %s', $error->getMessage() ) );
		}

		WP_CLI::log( sprintf( 'Diagnostics bundle written: %s', $output_path ) );
		WP_CLI::log( 'It is sanitised (site URL, absolute paths, and *_key/_secret/_token/_password options redacted), but review it before sharing. Pontifex never uploads it.' );
	}

	/**
	 * Resolve the output path: the --output value, or a timestamped default.
	 *
	 * @param array<string, string|bool> $associative_args The CLI's associative args.
	 * @return string The absolute path to write the bundle to.
	 */
	private function resolve_output_path( array $associative_args ): string {
		if ( isset( $associative_args['output'] ) && '' !== $associative_args['output'] && true !== $associative_args['output'] ) {
			$path = (string) $associative_args['output'];
			if ( ! str_ends_with( $path, '.tar.gz' ) ) {
				WP_CLI::error( sprintf( '--output must end in .tar.gz; got "%s".', $path ) );
			}
			return $path;
		}

		$directory = $this->content_dir() . '/pontifex/diagnostics';
		$timestamp = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->format( 'Ymd-His' );
		return $directory . '/pontifex-diagnostics-' . $timestamp . '.tar.gz';
	}

	/**
	 * Build the redactor for this site's URL and paths.
	 *
	 * @return DiagnosticsRedactor The configured redactor.
	 */
	private function build_redactor(): DiagnosticsRedactor {
		return new DiagnosticsRedactor(
			$this->wordpress_context->site_url(),
			$this->constant_string( 'ABSPATH', '' ),
			$this->constant_string( 'WP_CONTENT_DIR', '' )
		);
	}

	/**
	 * Gather every bundle artifact, sanitised, keyed by its path in the archive.
	 *
	 * @param DiagnosticsRedactor $redactor The redactor to sanitise text with.
	 * @return array<string, string> Archive path => sanitised content.
	 */
	private function gather_artifacts( DiagnosticsRedactor $redactor ): array {
		$artifacts = array(
			'README.txt'       => $this->readme(),
			'doctor.txt'       => $redactor->redact_text( $this->capture_command( 'pontifex doctor' ) ),
			'stats.txt'        => $redactor->redact_text( $this->capture_command( 'pontifex stats' ) ),
			'environment.json' => $redactor->redact_text( $this->environment_json( $redactor ) ),
		);

		foreach ( $this->read_logs( $redactor ) as $filename => $content ) {
			$artifacts[ 'logs/' . $filename ] = $content;
		}

		return $artifacts;
	}

	/**
	 * Capture a Pontifex sub-command's output as text.
	 *
	 * Runs in a subprocess (launch) so a sub-command that halts non-zero (doctor
	 * does on a FAIL) cannot abort the bundle, and a failure is tolerated rather
	 * than fatal.
	 *
	 * @param string $command The `pontifex …` command to run.
	 * @return string The captured stdout, or '' if nothing was captured.
	 */
	private function capture_command( string $command ): string {
		$output = WP_CLI::runcommand(
			$command,
			array(
				'return'     => true,
				'launch'     => true,
				'exit_error' => false,
			)
		);
		return is_string( $output ) ? $output : '';
	}

	/**
	 * Build the environment-summary JSON.
	 *
	 * @param DiagnosticsRedactor $redactor The redactor used to mask sensitive option values.
	 * @return string A pretty-printed JSON object, or '{}' on encode failure.
	 */
	private function environment_json( DiagnosticsRedactor $redactor ): string {
		$summary = array(
			'pontifex_version'  => $this->constant_string( 'PONTIFEX_VERSION', 'unknown' ),
			'php_version'       => $this->environment->php_version(),
			'wordpress_version' => $this->wordpress_context->wp_version(),
			'database_version'  => $this->wordpress_context->db_server_version(),
			'wpdb_charset'      => $this->wordpress_context->wpdb_charset(),
			'wpdb_collation'    => $this->wordpress_context->wpdb_collation(),
			'extensions'        => $this->extensions_status(),
			'ini'               => $this->ini_summary(),
			'options'           => $this->safe_options( $redactor ),
		);

		$json = wp_json_encode( $summary, JSON_PRETTY_PRINT );
		return false !== $json ? $json : '{}';
	}

	/**
	 * Report which of the tracked PHP extensions are loaded.
	 *
	 * @return array<string, string> Extension name => 'loaded' or 'missing'.
	 */
	private function extensions_status(): array {
		$status = array();
		foreach ( self::ENV_EXTENSIONS as $extension ) {
			$status[ $extension ] = $this->environment->extension_loaded( $extension ) ? 'loaded' : 'missing';
		}
		return $status;
	}

	/**
	 * Report the tracked ini directives, plus whether open_basedir is set.
	 *
	 * @return array<string, bool|string> Directive => value.
	 */
	private function ini_summary(): array {
		$ini = array();
		foreach ( self::ENV_INI_DIRECTIVES as $directive ) {
			$ini[ $directive ] = $this->environment->ini_get( $directive );
		}
		$ini['open_basedir_set'] = '' !== $this->environment->ini_get( 'open_basedir' );
		return $ini;
	}

	/**
	 * Read the small set of non-sensitive options, masking by name as a safety net.
	 *
	 * @param DiagnosticsRedactor $redactor The redactor used to mask sensitive option values.
	 * @return array<string, mixed> Option name => value (or the mask placeholder).
	 */
	private function safe_options( DiagnosticsRedactor $redactor ): array {
		$options = array();
		foreach ( self::SAFE_OPTIONS as $name ) {
			$options[ $name ] = $redactor->mask_option( $name, $this->wordpress_context->option_value( $name, null ) );
		}
		return $options;
	}

	/**
	 * Read the recent Pontifex log files, sanitised, keyed by filename.
	 *
	 * @param DiagnosticsRedactor $redactor The redactor to sanitise log text with.
	 * @return array<string, string> Filename => sanitised content (only files that exist).
	 */
	private function read_logs( DiagnosticsRedactor $redactor ): array {
		$log_dir = $this->content_dir() . '/pontifex/logs';
		$logs    = array();

		foreach ( self::LOG_FILENAMES as $filename ) {
			$content = $this->read_file( $log_dir . '/' . $filename );
			if ( null !== $content ) {
				$logs[ $filename ] = $redactor->redact_text( $content );
			}
		}

		return $logs;
	}

	/**
	 * Pack the artifacts into a gzipped tar at the output path.
	 *
	 * Builds an uncompressed tar at a unique temporary path, gzips it, moves the
	 * result into place, and removes the intermediate tar.
	 *
	 * @param string                $output_path The .tar.gz path to write.
	 * @param array<string, string> $artifacts   Archive path => content.
	 * @return void
	 * @throws \RuntimeException If the parent directory cannot be created or the move fails.
	 */
	private function write_bundle( string $output_path, array $artifacts ): void {
		$directory = dirname( $output_path );
		$this->ensure_dir( $directory );

		// Unpredictable temp name (not time-based uniqid), so an attacker cannot
		// pre-create or symlink the path before PharData writes the bundle. The
		// .tar suffix is required for PharData to recognise the format.
		$temp_tar = $directory . '/.pontifex-diagnostics-' . bin2hex( random_bytes( 16 ) ) . '.tar';

		$phar = new PharData( $temp_tar );
		foreach ( $artifacts as $name => $content ) {
			$phar->addFromString( $name, $content );
		}
		$phar->compress( Phar::GZ );
		unset( $phar );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename,WordPress.PHP.NoSilencedErrors.Discouraged -- Moving the just-built bundle into place on the same filesystem; @ traps a move failure converted to an exception below. WP_Filesystem is not loaded in a WP-CLI command.
		if ( false === @rename( $temp_tar . '.gz', $output_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort cleanup of the intermediate tar.
			@unlink( $temp_tar );
			throw new \RuntimeException( 'could not move the bundle into place.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Removing the intermediate uncompressed tar.
		@unlink( $temp_tar );
	}

	/**
	 * The bundle README explaining what was sanitised.
	 *
	 * @return string The README text.
	 */
	private function readme(): string {
		$lines = array(
			'Pontifex diagnostics bundle',
			'',
			'This bundle is SANITISED so it is safe to share with a maintainer:',
			'  - the site URL is replaced with ' . DiagnosticsRedactor::URL_PLACEHOLDER,
			'  - absolute paths are replaced with {ABSPATH} / {WP_CONTENT_DIR}',
			'  - option values whose names end in _key/_secret/_token/_password are masked',
			'',
			'Contents: doctor.txt, stats.txt, environment.json, and recent logs/.',
			'',
			'Pontifex never uploads this bundle. Review it, then attach it to a support',
			'request yourself. If a maintainer needs unredacted detail, send it separately.',
			'',
		);
		return implode( "\n", $lines );
	}

	/**
	 * Read a file's contents, returning null if it cannot be read.
	 *
	 * @param string $path The file path.
	 * @return string|null The contents, or null if the file is missing or unreadable.
	 */
	private function read_file( string $path ): ?string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.PHP.NoSilencedErrors.Discouraged -- Reading a Pontifex log file for the bundle; @ traps a missing/unreadable-file warning treated as "skip this file".
		$contents = @file_get_contents( $path );
		return false !== $contents ? $contents : null;
	}

	/**
	 * Create a directory (and parents) if it does not already exist.
	 *
	 * @param string $directory The directory path.
	 * @return void
	 * @throws \RuntimeException If the directory cannot be created.
	 */
	private function ensure_dir( string $directory ): void {
		// The plugin-owned default bundle directory (…/pontifex/diagnostics) is
		// created not-world-readable and locked against direct web access, because
		// the bundle can contain log excerpts. A user-supplied --output directory
		// is left untouched so we never drop guard files into the operator's own
		// location.
		if ( str_contains( $directory, '/pontifex/' ) ) {
			if ( ! ProtectedDirectory::ensure( $directory, 0700 ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message naming the directory for diagnostics; surfaced on the CLI, not HTML output.
				throw new \RuntimeException( sprintf( 'could not create the output directory: %s', $directory ) );
			}
			return;
		}

		if ( is_dir( $directory ) ) {
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir,WordPress.PHP.NoSilencedErrors.Discouraged -- Creating the bundle's output directory; @ traps a creation warning converted to an exception below.
		if ( ! @mkdir( $directory, 0755, true ) && ! is_dir( $directory ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message naming the directory for diagnostics; surfaced on the CLI, not HTML output.
			throw new \RuntimeException( sprintf( 'could not create the output directory: %s', $directory ) );
		}
	}

	/**
	 * Resolve WP_CONTENT_DIR, falling back to the system temp dir.
	 *
	 * @return string The content directory path.
	 */
	private function content_dir(): string {
		return $this->constant_string( 'WP_CONTENT_DIR', sys_get_temp_dir() );
	}

	/**
	 * Read a constant as a string through the Environment seam, with a fallback.
	 *
	 * @param string $name     The constant name.
	 * @param string $fallback The value when the constant is not defined.
	 * @return string The constant value, or the fallback.
	 */
	private function constant_string( string $name, string $fallback ): string {
		return $this->environment->is_constant_defined( $name )
			? (string) $this->environment->constant_value( $name )
			: $fallback;
	}
}
