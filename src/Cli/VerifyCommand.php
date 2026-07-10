<?php
/**
 * Pontifex Verify command — checks a Pontifex archive without restoring it.
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
use Pontifex\Archive\Format\ManifestEntry;
use Pontifex\Archive\Reader\ArchiveReader;
use Pontifex\Archive\Reader\EntryReader;
use Pontifex\Environment\Environment;
use Pontifex\Environment\RealEnvironment;
use Pontifex\Log\FileLogger;
use Pontifex\Manifest\WpdbAdapter;
use Pontifex\Restore\DatabaseWriter;
use Pontifex\Restore\FileWriter;
use Pontifex\Restore\RestoreRunner;
use Pontifex\Restore\RestoreRunnerInterface;
use Pontifex\WordPress\RealWordPressContext;
use Pontifex\WordPress\WordPressContext;

/**
 * `wp pontifex verify <archive>` — check a .wpmig archive without restoring it.
 *
 * Opens the archive, walks every entry in manifest order, and verifies
 * every SHA-256 hash, **writing nothing** to the site, the filesystem, or
 * the database. The same read-and-verify engine that powers
 * `import --dry-run`, exposed as a standalone command so a backup can be
 * checked against cold storage with no destination site involved.
 *
 * It exits 0 when the archive is sound and non-zero when it is broken or
 * refused (a failed hash, a malformed structure, or a defensive-limit
 * breach), so it can gate scripts and scheduled jobs.
 *
 * ## OPTIONS
 *
 * <archive>
 * : Absolute filesystem path to the .wpmig archive to verify.
 *
 * [--list]
 * : Also print the archive's contents — one row per entry (index, kind,
 *   name, codec, on-disk size, and a short hash), read from the manifest.
 *
 * [--format=<format>]
 * : Render format for --list.
 * ---
 * default: table
 * options:
 *   - table
 *   - json
 * ---
 *
 * [--passphrase-stdin]
 * : Read the passphrase to verify an encrypted archive as one line from STDIN
 *   instead of prompting. Ignored for an unencrypted archive.
 *
 * [--public-key=<path>]
 * : Verify the archive's Ed25519 signature against this public-key file (from
 *   `wp pontifex keygen`). A signed archive whose signature fails is reported
 *   BROKEN. Without it, a signed archive is checked for integrity only, with a
 *   warning that its signature was not verified.
 *
 * ## EXAMPLES
 *
 *     wp pontifex verify /backups/site.wpmig
 *     wp pontifex verify /backups/site.wpmig --list
 *     wp pontifex verify /backups/site.wpmig --list --format=json
 *     pass show backup | wp pontifex verify /backups/encrypted.wpmig --passphrase-stdin
 *     wp pontifex verify /backups/site.wpmig --public-key=/root/pontifex.pub
 *
 * @when after_wp_load
 */
final class VerifyCommand {


	/**
	 * The Environment abstraction this command queries.
	 *
	 * Injected so tests can substitute a mock. Used only by the default
	 * wiring (ABSPATH for the unused restore root, WP_CONTENT_DIR/WP_DEBUG
	 * for the logger); when a RestoreRunner and logger are injected, it is
	 * never touched.
	 *
	 * @var Environment
	 */
	private Environment $environment;

	/**
	 * The WordPressContext abstraction this command queries.
	 *
	 * Supplies the wpdb instance the default DatabaseWriter is built from.
	 * Verify never writes, so that writer is constructed but never invoked;
	 * the context exists only to keep the default wiring identical to
	 * ImportCommand's.
	 *
	 * @var WordPressContext
	 */
	private WordPressContext $wordpress_context;

	/**
	 * The engine used to read and verify the archive.
	 *
	 * Optional in the constructor: when null, the command wires one up from
	 * a fresh EntryReader + FileWriter + DatabaseWriter, exactly as import
	 * does. Tests inject a fake fulfilling the RestoreRunnerInterface
	 * contract — the reason that interface exists.
	 *
	 * @var RestoreRunnerInterface|null
	 */
	private ?RestoreRunnerInterface $restore_runner;

	/**
	 * The PSR-3 logger this command records run milestones to.
	 *
	 * Injected so tests can substitute a spy or a NullLogger. When null, the
	 * constructor builds a FileLogger writing under wp-content/pontifex/logs.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * The progress reporter that shows verification progress.
	 *
	 * Injected so tests can substitute a silent NullProgressBar. When null, a
	 * WpCliProgressBar driving WP-CLI's native progress bar is used.
	 *
	 * @var ProgressReporter
	 */
	private ProgressReporter $progress;

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
	 * Construct a VerifyCommand instance.
	 *
	 * WP-CLI registers the command via its class name and does not pass
	 * constructor arguments, so all parameters are optional and default to
	 * real implementations. Tests pass mocks explicitly.
	 *
	 * @param Environment|null            $environment       Optional. Defaults to a fresh RealEnvironment.
	 * @param WordPressContext|null       $wordpress_context Optional. Defaults to a fresh RealWordPressContext.
	 * @param RestoreRunnerInterface|null $restore_runner    Optional. When null, the command builds a concrete RestoreRunner at run time.
	 * @param LoggerInterface|null        $logger            Optional. When null, a FileLogger writing under wp-content/pontifex/logs is used.
	 * @param ProgressReporter|null       $progress          Optional. When null, a WpCliProgressBar driving WP-CLI's native progress bar is used.
	 * @param PassphraseSource|null       $passphrase_source Optional. When null, a CliPassphraseSource (hidden prompt + STDIN) is used.
	 */
	public function __construct(
		?Environment $environment = null,
		?WordPressContext $wordpress_context = null,
		?RestoreRunnerInterface $restore_runner = null,
		?LoggerInterface $logger = null,
		?ProgressReporter $progress = null,
		?PassphraseSource $passphrase_source = null
	) {
		$this->environment       = $environment ?? new RealEnvironment();
		$this->wordpress_context = $wordpress_context ?? new RealWordPressContext();
		$this->restore_runner    = $restore_runner;
		$this->logger            = $logger ?? $this->build_default_logger();
		$this->progress          = $progress ?? new WpCliProgressBar();
		$this->passphrase_source = $passphrase_source ?? new CliPassphraseSource();
	}

	/**
	 * The WP-CLI command entry point.
	 *
	 * `__invoke` is the magic method WP-CLI dispatches to for a single-
	 * command class. Orchestrates: read the archive path, validate it, open
	 * it, optionally list its contents, then read-and-verify every entry.
	 * A sound archive logs and prints its verdict and exits 0; a broken one
	 * logs the failure, prints why, and halts non-zero.
	 *
	 * @param array<int, string>         $positional_args  Positional arguments. The first is the required archive path.
	 * @param array<string, string|bool> $associative_args Associative `--flag` arguments (`--list`, `--format`).
	 * @return void
	 */
	public function __invoke( array $positional_args, array $associative_args ): void {

		// 1. Read and validate the archive path.
		$archive_path     = $this->require_archive_path( $positional_args );
		$show_list        = isset( $associative_args['list'] ) && false !== $associative_args['list'];
		$format           = isset( $associative_args['format'] ) ? (string) $associative_args['format'] : 'table';
		$passphrase_stdin = isset( $associative_args['passphrase-stdin'] ) && false !== $associative_args['passphrase-stdin'];
		$public_key       = $this->resolve_public_key( $associative_args );

		$this->validate_archive_path( $archive_path );

		// 2. Open the source archive for reading.
		$source = $this->open_source( $archive_path );

		// Verify learns its entry total from the first callback, so the bar
		// starts on entry one (the same shape as import's restore bar).
		$entry_total = 0;
		$on_entry    = function ( int $done, int $total ) use ( &$entry_total ): void {
			if ( 1 === $done ) {
				$this->progress->start( $total, 'Verifying archive' );
			}
			$entry_total = $total;
			$this->progress->advance();
		};

		try {
			// 4. Optionally list the contents first (reading the manifest is
			// independent of the hash walk; a manifest that will not parse is
			// itself a broken archive, caught below).
			if ( $show_list ) {
				$this->print_list( $source, $format );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Rewinding the stream resource before the verify walk re-reads it; not a WP_Filesystem operation.
				rewind( $source );
			}

			// 5. Wire the verify engine. For an encrypted archive this reads the salt
			// and collects the passphrase; --list above needs none (the manifest is
			// unencrypted), so only the verify walk prompts.
			$restore_runner = $this->restore_runner ?? $this->build_default_restore_runner( $source, $passphrase_stdin );

			// 6. Read and verify every entry. Writes nothing.
			$this->logger->info( 'Verify started.', array( 'archive' => $archive_path ) );

			$restore_runner->verify( $source, $on_entry );
			$this->progress->finish();

			// 7. Signature last (ARCHIVE-FORMAT.md §12.1). A signed archive whose
			// signature fails against the supplied key throws here, so the verdict
			// is BROKEN; signed-but-no-key warns and stays sound.
			$this->check_signature( $source, $public_key );

			$this->logger->info(
				'Verify complete: archive is sound.',
				array(
					'archive' => $archive_path,
					'entries' => $entry_total,
				)
			);

			$this->print_sound( $archive_path, $entry_total );
		} catch ( Throwable $error ) {
			$this->logger->error(
				'Verify failed: archive is not sound.',
				array(
					'archive'   => $archive_path,
					'exception' => $error,
				)
			);

			$this->print_broken( $archive_path, $error );
			WP_CLI::halt( 1 );
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
			WP_CLI::error( __( 'An archive path is required: wp pontifex verify <archive>.', 'pontifex' ) );
		}
		return (string) $positional_args[0];
	}

	/**
	 * Verify that the archive path is absolute.
	 *
	 * Existence and readability are checked at open time, in open_source():
	 * a file we cannot fopen for reading is the single honest test of "can I
	 * read this archive", and it yields a clear error there. Here we only
	 * reject a non-absolute path early.
	 *
	 * @param string $archive_path The path the user supplied.
	 * @return void
	 */
	private function validate_archive_path( string $archive_path ): void {
		if ( '/' !== substr( $archive_path, 0, 1 ) ) {
			WP_CLI::error(
				sprintf( 'The archive path must be absolute; got "%s".', $archive_path )
			);
		}
	}

	/**
	 * Open the source archive for reading.
	 *
	 * Exits via WP_CLI::error if fopen fails — which is also how a missing or
	 * unreadable archive is reported.
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
	 * EntryReader (the key derived from the footer salt), otherwise a plain one —
	 * identical to ImportCommand's wiring. A FileWriter and DatabaseWriter are
	 * also wired because RestoreRunner's constructor requires them, but verify()
	 * never invokes them: it writes nothing.
	 *
	 * @param resource $source           The open archive stream, read for its header and footer.
	 * @param bool     $passphrase_stdin True to read the passphrase from STDIN rather than prompt.
	 * @return RestoreRunner
	 */
	private function build_default_restore_runner( $source, bool $passphrase_stdin ): RestoreRunner {
		$archive_reader = new ArchiveReader( $source );
		$passphrase     = $archive_reader->header()->is_encrypted()
			? Encryption::collect_for_import( $this->passphrase_source, $passphrase_stdin )
			: null;
		$entry_reader   = Encryption::entry_reader( $archive_reader, CodecRegistry::with_defaults(), $passphrase );
		if ( null !== $passphrase ) {
			sodium_memzero( $passphrase );
		}

		// ArchiveReader sought through the stream; rewind so the RestoreRunner's own
		// reader starts from a known position.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Rewinding the open archive stream resource; not a WP_Filesystem operation.
		rewind( $source );

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
	 * Reads WP_CONTENT_DIR and WP_DEBUG through the Environment seam so the
	 * path and verbosity follow the host WordPress. Mirrors ImportCommand's
	 * logger wiring (a shared base is a post-v0.1.0 refactor, not a
	 * mid-milestone one).
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
	 * Resolve the WordPress installation root for the default FileWriter.
	 *
	 * Reads the ABSPATH constant via the Environment abstraction so tests can
	 * substitute a fixture path. Verify never writes a file, so this root is
	 * only the (unused) anchor the FileWriter is constructed with.
	 *
	 * @return string
	 * @throws RuntimeException If ABSPATH is not defined (should never happen inside a WordPress request).
	 */
	private function resolve_wordpress_root(): string {
		if ( ! $this->environment->is_constant_defined( 'ABSPATH' ) ) {
			throw new RuntimeException( 'VerifyCommand: ABSPATH is not defined; is WordPress loaded?' );
		}
		return rtrim( (string) $this->environment->constant_value( 'ABSPATH' ), '/' );
	}

	/**
	 * Resolve the --public-key option to a loaded public key, or null when absent.
	 *
	 * A bad or unreadable key file is the operator's mistake, not a broken
	 * archive, so it exits via WP_CLI::error rather than the broken-verdict path.
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
	 * Check the archive's signature as the final verification step.
	 *
	 * Unsigned archive: nothing to check (a stray --public-key earns a warning).
	 * Signed with no key: warn that the signature was not verified, stay sound.
	 * Signed with a key: a failed signature throws (caught as a BROKEN verdict);
	 * a good one logs that it verified.
	 *
	 * @param resource    $source     The open archive stream.
	 * @param string|null $public_key The trusted public key, or null when none was supplied.
	 * @return void
	 * @throws RuntimeException If the archive is signed and the signature does not verify against $public_key.
	 */
	private function check_signature( $source, ?string $public_key ): void {
		$reader = new ArchiveReader( $source );

		if ( null === $reader->signature() ) {
			if ( null !== $public_key ) {
				WP_CLI::warning( __( 'A public key was supplied with --public-key, but this archive is not signed.', 'pontifex' ) );
			}
			return;
		}

		if ( null === $public_key ) {
			WP_CLI::warning( __( 'This archive is signed, but its signature was NOT verified. Pass --public-key=<path> to verify it.', 'pontifex' ) );
			return;
		}

		if ( ! $reader->verify_signature( $public_key ) ) {
			throw new RuntimeException( 'the Ed25519 signature did not verify against the supplied public key (wrong key, or the archive was modified after signing).' );
		}

		WP_CLI::log( __( 'Signature verified against the supplied public key.', 'pontifex' ) );
	}


	// -------------------------------------------------------------------------
	// Output formatting.
	// -------------------------------------------------------------------------

	/**
	 * Print the archive's contents as a table or JSON, read from the manifest.
	 *
	 * The manifest is the archive's lightweight navigation index, so this is
	 * a cheap read that needs no entry decode. It carries names, kinds,
	 * codecs, on-disk sizes and hashes — not uncompressed sizes or mtimes,
	 * which live in the entry headers on disk.
	 *
	 * @param resource $source A seekable, readable stream containing the archive.
	 * @param string   $format The render format ('table' or 'json').
	 * @return void
	 * @throws RuntimeException If the archive's header, footer, or manifest will not parse.
	 */
	private function print_list( $source, string $format ): void {
		$reader = new ArchiveReader( $source );
		$rows   = self::manifest_rows( $reader->manifest()->entries() );
		\WP_CLI\Utils\format_items( $format, $rows, array( 'index', 'kind', 'name', 'codec', 'size', 'hash' ) );
	}

	/**
	 * Map manifest entries to display rows for --list.
	 *
	 * Pure transform, kept separate from the WP-CLI rendering so it can be
	 * unit-tested without the WP-CLI runtime. The name column is the entry's
	 * path for file/directory/symlink kinds, or "db chunk #N" for a db_chunk.
	 * The hash is shortened to its first twelve hex characters — enough to
	 * eyeball, not so much it dominates the row.
	 *
	 * @param array<int, ManifestEntry> $entries The manifest entries, in order.
	 * @return array<int, array<string, int|string>> One display row per entry.
	 */
	private static function manifest_rows( array $entries ): array {
		$rows = array();
		foreach ( $entries as $entry ) {
			$name   = $entry->path();
			$rows[] = array(
				'index' => $entry->index(),
				'kind'  => $entry->kind(),
				'name'  => null !== $name ? $name : sprintf( 'db chunk #%d', (int) $entry->chunk_index() ),
				'codec' => $entry->codec_id(),
				'size'  => $entry->length(),
				'hash'  => substr( bin2hex( $entry->entry_hash() ), 0, 12 ),
			);
		}
		return $rows;
	}

	/**
	 * Print the sound verdict for an archive whose every hash checked out.
	 *
	 * @param string $archive_path The archive that was verified.
	 * @param int    $entry_count  How many entries were verified.
	 * @return void
	 */
	private function print_sound( string $archive_path, int $entry_count ): void {
		WP_CLI::log(
			sprintf(
				/* translators: 1: number of entries verified, 2: the archive path */
				__( 'Archive is sound: %1$d entries verified, every hash checked. %2$s', 'pontifex' ),
				$entry_count,
				$archive_path
			)
		);
	}

	/**
	 * Print the broken verdict, naming what failed and where.
	 *
	 * The engine's exception message carries the specific failure — which
	 * entry's hash mismatched, which structure would not parse, or which
	 * defensive limit was breached — so it is surfaced verbatim.
	 *
	 * @param string    $archive_path The archive that failed verification.
	 * @param Throwable $error        The failure the verify walk raised.
	 * @return void
	 */
	private function print_broken( string $archive_path, Throwable $error ): void {
		$redactor = PathRedactor::from_environment();
		WP_CLI::log(
			sprintf(
				/* translators: 1: the failure message, 2: the archive path */
				__( 'Archive is BROKEN: %1$s (%2$s)', 'pontifex' ),
				$redactor->redact( $error->getMessage() ),
				$redactor->redact( $archive_path )
			)
		);
	}
}
