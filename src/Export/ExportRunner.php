<?php
/**
 * Pontifex export runner — the shared engine that writes a site archive.
 *
 * @package Pontifex\Export
 */

declare(strict_types=1);

namespace Pontifex\Export;

use DateTimeImmutable;
use RuntimeException;
use Pontifex\Archive\Codec\CodecRegistry;
use Pontifex\Archive\Format\ExporterInfo;
use Pontifex\Archive\Format\Provenance;
use Pontifex\Archive\Writer\ArchiveWriter;
use Pontifex\Archive\Writer\EntryWriter;
use Pontifex\Archive\Writer\FooterWriter;
use Pontifex\Environment\Environment;
use Pontifex\Manifest\DatabaseScanner;
use Pontifex\Manifest\ExclusionRules;
use Pontifex\Manifest\FileScanner;
use Pontifex\Manifest\ManifestBuilder;
use Pontifex\Manifest\WpdbAdapter;
use Pontifex\WordPress\WordPressContext;

/**
 * Writes a Pontifex archive of the current site, shared by every export caller.
 *
 * The archive-writing recipe used to be spelled out in full inside
 * {@see \Pontifex\Cli\ExportCommand} and again inside
 * {@see \Pontifex\Rollback\SafetyArchiver} (the audit's finding 061). This class
 * is the single copy both now delegate to, and the one the admin Backup screen
 * will reuse: given the list of entries to write and a small set of options, it
 * builds the provenance block, opens the destination, and writes the archive.
 *
 * What it deliberately does NOT own, so each caller keeps the behaviour it needs:
 *
 *  - Building the entry list. The caller builds it (via
 *    {@see self::default_manifest_builder()} or its own injected builder) and
 *    passes it in, because the safety archiver must inspect the list for a
 *    free-disk preflight before any byte is written, and the admin screen wants
 *    the entry count up front for its progress bar.
 *  - Counters, transfer history, logging, and all WP-CLI interaction. Those are
 *    the caller's concern; the runner stays free of the CLI and of side effects
 *    beyond writing the one file.
 *
 * Stateless after construction; safe to reuse for many exports.
 */
final class ExportRunner {

	/**
	 * Exporter version recorded when the PONTIFEX_VERSION constant is absent.
	 *
	 * Matches the fallback the export callers used before this engine existed, so
	 * an archive written without WordPress loaded (as in a unit test) still has a
	 * valid, non-empty exporter version.
	 *
	 * @var string
	 */
	private const FALLBACK_VERSION = '0.0.0-dev';

	/**
	 * The Environment abstraction (PHP version and constant reads).
	 *
	 * @var Environment
	 */
	private Environment $environment;

	/**
	 * The WordPressContext abstraction (site facts for the provenance block).
	 *
	 * @var WordPressContext
	 */
	private WordPressContext $wordpress_context;

	/**
	 * Construct an ExportRunner.
	 *
	 * @param Environment      $environment       PHP-runtime and constant reads for the provenance block.
	 * @param WordPressContext $wordpress_context WordPress-specific facts for the provenance block.
	 */
	public function __construct( Environment $environment, WordPressContext $wordpress_context ) {
		$this->environment       = $environment;
		$this->wordpress_context = $wordpress_context;
	}

	/**
	 * Build the standard scanner-backed manifest builder for an export.
	 *
	 * Wires a FileScanner and a DatabaseScanner (over the real $wpdb) under the
	 * given exclusion rules into a ManifestBuilder — the one copy of the wiring
	 * that the CLI export and the safety archiver previously duplicated. The
	 * caller invokes build() on the result to get the entry list to pass to
	 * {@see self::export()}.
	 *
	 * @param WordPressContext $wordpress_context Supplies the $wpdb instance for the database scan.
	 * @param ExclusionRules   $rules             Rules applied to both the file and database scans.
	 * @param string           $path_prefix       Prefix prepended to every scanned file path, so a scan rooted below the WordPress root still records WordPress-root-relative paths. Pass '' for a whole-site scan rooted at the WordPress root, or 'wp-content' for a content-only scan rooted at WP_CONTENT_DIR.
	 * @return ManifestBuilder A scanner-backed manifest builder.
	 */
	public static function default_manifest_builder( WordPressContext $wordpress_context, ExclusionRules $rules, string $path_prefix = '' ): ManifestBuilder {
		$file_scanner     = new FileScanner( $rules, $path_prefix );
		$database_adapter = new WpdbAdapter( $wordpress_context->wpdb_instance() );
		$database_scanner = new DatabaseScanner( $database_adapter, $rules );
		return new ManifestBuilder( $file_scanner, $database_scanner );
	}

	// phpcs:disable Squiz.Commenting.FunctionComment.IncorrectTypeHint -- $entry_plans is documented as iterable<EntryPlan> because PHPStan level 6 requires the value type; this sniff cannot reduce an iterable<> generic to its base iterable hint the way it reduces array<> to array.
	/**
	 * Write a Pontifex archive of the supplied entries to the destination.
	 *
	 * Builds the provenance block from the current runtime, opens the destination
	 * for writing (read+write, so a signed export can re-read its own bytes), and
	 * writes the header, provenance, every entry, the manifest, and the footer via
	 * {@see ArchiveWriter}. The destination is always closed, success or failure.
	 *
	 * @param ExportOptions                                     $options     Where to write, plus optional encryption, signing, and the unencrypted-archive reason.
	 * @param iterable<int, \Pontifex\Archive\Writer\EntryPlan> $entry_plans Entries to write, in archive order; a plain array or a Countable ManifestStream. May be empty.
	 * @param callable|null                                     $on_entry    Optional per-entry progress callback, called as `( int $done, int $total ): void`.
	 * @param callable|null                                     $on_bytes    Optional byte-progress callback forwarded to the archive writer, called as `( int $bytes ): void` with each chunk's raw source byte count, so a caller can report progress within a large entry.
	 * @return ExportResult The bytes written and the entry count.
	 * @throws RuntimeException If the destination cannot be opened, or the archive cannot be written.
	 */
	public function export( ExportOptions $options, iterable $entry_plans, ?callable $on_entry = null, ?callable $on_bytes = null ): ExportResult {
		// phpcs:enable Squiz.Commenting.FunctionComment.IncorrectTypeHint
		// Capture the entry count up front from Countable — both a plain array and a
		// ManifestStream satisfy it in O(1); the write consumes the entries by
		// iterating them.
		$entry_count = is_countable( $entry_plans ) ? count( $entry_plans ) : 0;

		$provenance  = $this->build_provenance( $options );
		$destination = $this->open_destination( $options->output_path() );

		try {
			$bytes_written = self::build_archive_writer()->write_archive(
				$provenance,
				$entry_plans,
				$destination,
				$on_entry,
				$options->encryption(),
				$options->signing(),
				$on_bytes
			);
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing a stream resource opened in this method; not a WP_Filesystem operation.
			fclose( $destination );
		}

		return new ExportResult( $bytes_written, $entry_count );
	}

	/**
	 * Build a Provenance block from current WordPress and PHP runtime facts.
	 *
	 * When the options carry a scope (a scope-aware export), the scope and the
	 * source database table prefix are recorded too (format v1.1). When they do not
	 * (the safety archiver), both are left null, so the provenance bytes stay
	 * identical to a pre-v1.1 archive — the two v1.1 fields travel together.
	 *
	 * @param ExportOptions $options The per-export options, supplying the unencrypted-archive reason and the optional scope.
	 * @return Provenance A fully-populated provenance value object.
	 */
	private function build_provenance( ExportOptions $options ): Provenance {
		$pontifex_version = $this->environment->is_constant_defined( 'PONTIFEX_VERSION' )
			? (string) $this->environment->constant_value( 'PONTIFEX_VERSION' )
			: self::FALLBACK_VERSION;

		$scope        = $options->scope();
		$table_prefix = null !== $scope ? $this->wordpress_context->wpdb_prefix() : null;

		return new Provenance(
			$this->wordpress_context->wp_version(),
			$this->environment->php_version(),
			$this->wordpress_context->site_url(),
			$this->wordpress_context->wpdb_charset(),
			$this->wordpress_context->wpdb_collation(),
			new ExporterInfo( 'pontifex', $pontifex_version ),
			new DateTimeImmutable(),
			$options->encryption_disabled_reason(),
			$table_prefix,
			$scope
		);
	}

	/**
	 * Open the destination file for writing.
	 *
	 * Opened read+write ("w+b"): writing is all an unsigned export needs, but a
	 * signed export re-reads the just-written bytes to compute its signature, so
	 * the handle must also be readable. The mode truncates on open either way.
	 *
	 * @param string $output_path Absolute path to the file to create.
	 * @return resource A readable, writable binary stream resource.
	 * @throws RuntimeException If the file cannot be opened for writing.
	 */
	private function open_destination( string $output_path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- Opening the destination archive as a stream; @ traps an unopenable-file warning converted to an exception below.
		$destination = @fopen( $output_path, 'w+b' );
		if ( false === $destination ) {
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message naming the path for diagnostics; surfaced on the CLI / in logs, not HTML output.
				sprintf( 'ExportRunner: could not open the destination archive for writing: %s', $output_path )
			);
		}
		return $destination;
	}

	/**
	 * Build the ArchiveWriter with the v0.1.0 default codecs.
	 *
	 * @return ArchiveWriter A ready-to-use archive writer.
	 */
	private static function build_archive_writer(): ArchiveWriter {
		return new ArchiveWriter( new EntryWriter( CodecRegistry::with_defaults() ), new FooterWriter() );
	}
}
