<?php
/**
 * Pontifex safety archiver — writes a pre-import safety archive of the current site.
 *
 * @package Pontifex\Rollback
 */

declare(strict_types=1);

namespace Pontifex\Rollback;

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
use Pontifex\Manifest\ManifestBuilderInterface;
use Pontifex\Manifest\WpdbAdapter;
use Pontifex\WordPress\WordPressContext;

/**
 * Takes a safety archive of the current site by reusing the export pipeline.
 *
 * Implements {@see SafetyArchiverInterface}. This is the engine behind the
 * default pre-import safety archive (ADR 0005): before `wp pontifex import`
 * overwrites the site, a full `.wpmig` of the current site is written into the
 * rollback directory, so `wp pontifex rollback` can restore it.
 *
 * The pipeline is the same one ExportCommand drives — a ManifestBuilder
 * (FileScanner + DatabaseScanner) feeding an ArchiveWriter, with the curated
 * v0.1.0 default exclusions so caches and other backup directories are not
 * captured. Duplicating ExportCommand's wiring (rather than sharing it) is a
 * deliberate, temporary choice: it keeps the proven export command untouched
 * while rollback lands; folding both onto this archiver is a later cleanup.
 *
 * Order matters for safety: the archive is written *before* the caller's
 * destructive restore. A free-disk preflight refuses early when the estimate
 * obviously will not fit, but the real guarantee is that any write failure is
 * raised so the caller aborts the import before touching the site.
 */
final class SafetyArchiver implements SafetyArchiverInterface {

	/**
	 * Mode applied to a written safety archive: owner read/write only.
	 *
	 * @var int
	 */
	private const ARCHIVE_MODE = 0600;

	/**
	 * The Environment abstraction (PHP version, free disk space, constants).
	 *
	 * @var Environment
	 */
	private Environment $environment;

	/**
	 * The WordPressContext abstraction (site URL, versions, wpdb, charset).
	 *
	 * @var WordPressContext
	 */
	private WordPressContext $wordpress_context;

	/**
	 * The directory the safety archive is written into and pruned within.
	 *
	 * @var RollbackStoreInterface
	 */
	private RollbackStoreInterface $store;

	/**
	 * The manifest builder used to enumerate entries.
	 *
	 * Optional: when null, the archiver wires a default
	 * FileScanner + DatabaseScanner against the v0.1.0 exclusions. Tests inject
	 * a fake so the preflight and write logic can be exercised without scanning
	 * a real installation.
	 *
	 * @var ManifestBuilderInterface|null
	 */
	private ?ManifestBuilderInterface $manifest_builder;

	/**
	 * How many of the newest safety archives to retain after writing.
	 *
	 * @var int
	 */
	private int $retention;

	/**
	 * Construct a SafetyArchiver.
	 *
	 * @param Environment                   $environment       PHP-runtime and filesystem reads.
	 * @param WordPressContext              $wordpress_context WordPress-specific facts for provenance and the database scan.
	 * @param RollbackStoreInterface        $store             The rollback directory the archive is written into.
	 * @param ManifestBuilderInterface|null $manifest_builder  Optional. When null, a default scanner-backed builder is used.
	 * @param int                           $retention         How many newest archives to keep (ADR 0005 default: 1).
	 */
	public function __construct(
		Environment $environment,
		WordPressContext $wordpress_context,
		RollbackStoreInterface $store,
		?ManifestBuilderInterface $manifest_builder = null,
		int $retention = 1
	) {
		$this->environment       = $environment;
		$this->wordpress_context = $wordpress_context;
		$this->store             = $store;
		$this->manifest_builder  = $manifest_builder;
		$this->retention         = $retention;
	}

	/**
	 * Take a safety archive of the current site and return its path.
	 *
	 * @param string        $wordpress_root Absolute path of the WordPress installation to archive.
	 * @param callable|null $on_entry       Optional per-entry progress callback, called as `( int $done, int $total ): void`.
	 * @return string The absolute path of the safety archive written.
	 * @throws RuntimeException If the preflight refuses, or the archive cannot be written.
	 */
	public function create( string $wordpress_root, ?callable $on_entry = null ): string {
		$this->store->ensure_directory();

		$manifest_builder = $this->manifest_builder ?? $this->build_default_manifest_builder();
		$entry_plans      = $manifest_builder->build( $wordpress_root );

		$this->preflight_disk_space( $entry_plans );

		$path        = $this->store->next_archive_path( new DateTimeImmutable() );
		$destination = $this->open_destination( $path );

		try {
			$total = count( $entry_plans );
			$done  = 0;
			self::build_archive_writer()->write_archive(
				$this->build_provenance(),
				$entry_plans,
				$destination,
				function () use ( &$done, $total, $on_entry ): void {
					++$done;
					if ( null !== $on_entry ) {
						$on_entry( $done, $total );
					}
				}
			);
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing a stream resource opened in this method; not a WP_Filesystem operation.
			fclose( $destination );
		}

		// The archive holds the whole database, so it must be owner-only. On a
		// POSIX host a failed chmod means it could not be secured; rather than
		// leave a world-readable database backup on disk, remove it and fail
		// closed (this runs before any destructive restore, so the import simply
		// aborts). On non-POSIX hosts modes are not meaningful, so a false return
		// is ignored — matching the secret-key handling in SigningKeys.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Restricting the safety archive (it holds the whole database) to owner-only; WP_Filesystem is unavailable in CLI contexts.
		if ( ! chmod( $path, self::ARCHIVE_MODE ) && '/' === DIRECTORY_SEPARATOR ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Removing the unsecurable backup; best-effort, failure must not mask the error below.
			@unlink( $path );
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message naming the path for diagnostics; surfaced on the CLI, not HTML output.
				sprintf( 'SafetyArchiver: could not restrict the safety archive %s to owner-only; refusing to proceed with an insecure database backup.', $path )
			);
		}

		$this->store->prune( $this->retention );

		return $path;
	}

	/**
	 * Refuse early when free disk space obviously will not fit the archive.
	 *
	 * Best-effort: the estimate is the sum of the plans' original sizes —
	 * including the database, whose size lives in each db_chunk's byte_count()
	 * rather than size(), so estimated_bytes() is used to avoid counting the
	 * database (often the bulk of a backup) as zero. It is a conservative proxy,
	 * since the archive compresses them. A free-space reading that cannot be taken
	 * (false, e.g. under open_basedir) is treated as "proceed" — the write itself
	 * is the hard backstop, since it runs before any destructive restore.
	 *
	 * @param array<int, \Pontifex\Archive\Writer\EntryPlan> $entry_plans The plans about to be written.
	 * @return void
	 * @throws RuntimeException If the free space is known and smaller than the estimate.
	 */
	private function preflight_disk_space( array $entry_plans ): void {
		$estimate = 0;
		foreach ( $entry_plans as $plan ) {
			$estimate += $plan->header()->estimated_bytes();
		}

		$free = $this->environment->disk_free_space( $this->store->directory() );
		if ( false === $free ) {
			return;
		}

		if ( $free < $estimate ) {
			throw new RuntimeException(
				sprintf(
					'SafetyArchiver: not enough free disk space for a safety archive (need about %d bytes, %d available at %s). Free space, or pass --no-rollback-archive to skip the safety archive.',
					(int) $estimate,
					(int) $free,
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message only; the path is plugin-derived, not web output.
					$this->store->directory()
				)
			);
		}
	}

	/**
	 * Open the safety-archive destination for writing.
	 *
	 * @param string $path Absolute path to create.
	 * @return resource A writable binary stream resource.
	 * @throws RuntimeException If the file cannot be opened for writing.
	 */
	private function open_destination( string $path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- Opening the safety-archive destination as a stream; @ traps an unopenable-file warning converted to an exception below.
		$destination = @fopen( $path, 'wb' );
		if ( false === $destination ) {
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message only; the path is plugin-derived, not web output.
				sprintf( 'SafetyArchiver: could not open the safety archive for writing: %s', $path )
			);
		}
		return $destination;
	}

	/**
	 * Build a default ManifestBuilder over the v0.1.0 exclusions.
	 *
	 * Mirrors ExportCommand's default wiring: a FileScanner and a
	 * DatabaseScanner (over the real $wpdb) under the curated default exclusion
	 * rules, so caches and other backup directories are not captured.
	 *
	 * @return ManifestBuilder A scanner-backed manifest builder.
	 */
	private function build_default_manifest_builder(): ManifestBuilder {
		$exclusion_rules  = ExclusionRules::default_v010();
		$file_scanner     = new FileScanner( $exclusion_rules );
		$database_adapter = new WpdbAdapter( $this->wordpress_context->wpdb_instance() );
		$database_scanner = new DatabaseScanner( $database_adapter, $exclusion_rules );
		return new ManifestBuilder( $file_scanner, $database_scanner );
	}

	/**
	 * Build a Provenance block from current WordPress and PHP runtime facts.
	 *
	 * @return Provenance A fully-populated provenance value object.
	 */
	private function build_provenance(): Provenance {
		$pontifex_version = $this->environment->is_constant_defined( 'PONTIFEX_VERSION' )
			? (string) $this->environment->constant_value( 'PONTIFEX_VERSION' )
			: '0.0.0-dev';

		return new Provenance(
			$this->wordpress_context->wp_version(),
			$this->environment->php_version(),
			$this->wordpress_context->site_url(),
			$this->wordpress_context->wpdb_charset(),
			$this->wordpress_context->wpdb_collation(),
			new ExporterInfo( 'pontifex', $pontifex_version ),
			new DateTimeImmutable()
		);
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
