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
use Pontifex\Archive\Format\Scope;
use Pontifex\Environment\Environment;
use Pontifex\Export\ExportOptions;
use Pontifex\Export\ExportRunner;
use Pontifex\Manifest\ExclusionRules;
use Pontifex\Manifest\ManifestBuilderInterface;
use Pontifex\Manifest\ManifestStream;
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
	 * The minimum number of safety archives retained, whatever the caller asks for.
	 *
	 * The floor is 2 because both undo paths — the automatic roll-back when a
	 * restore fails and the operator's manual `wp pontifex rollback` — depend on
	 * the *previous* safety archive still existing. With a retention of 1, the
	 * safety archive taken for a second restore would prune the first restore's
	 * archive: the only undo for the site state the second restore is about to
	 * overwrite. Enforcing the floor here, rather than only at call sites, means
	 * no future caller can reintroduce that loss. (Amends ADR 0005, which set
	 * N = 1 before the automatic roll-back existed.)
	 *
	 * @var int
	 */
	private const MIN_RETENTION = 2;

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
	 * Never below {@see self::MIN_RETENTION}; the constructor clamps it.
	 *
	 * @var int
	 */
	private int $retention;

	/**
	 * Whether to take a content-only safety archive rather than a whole-site one.
	 *
	 * False (the default) scans the whole WordPress root and records a whole-site
	 * scope, matching a whole-site restore. True scans wp-content under a
	 * "wp-content" path prefix and records a content-only scope, matching a
	 * content-only restore — so the safety archive captures exactly what the restore
	 * is about to overwrite, and rolls back through the same restore engine (ADR
	 * 0008). The caller passes the matching scan root to {@see self::create()}.
	 *
	 * @var bool
	 */
	private bool $content_only;

	/**
	 * Construct a SafetyArchiver.
	 *
	 * @param Environment                   $environment       PHP-runtime and filesystem reads.
	 * @param WordPressContext              $wordpress_context WordPress-specific facts for provenance and the database scan.
	 * @param RollbackStoreInterface        $store             The rollback directory the archive is written into.
	 * @param ManifestBuilderInterface|null $manifest_builder  Optional. When null, a default scanner-backed builder is used.
	 * @param int                           $retention         How many newest archives to keep (ADR 0005 as amended; clamped to at least MIN_RETENTION so a second restore can never prune the first's undo).
	 * @param bool                          $content_only      Optional. Take a content-only safety archive (scan root passed to create() must be WP_CONTENT_DIR); default false takes a whole-site one.
	 */
	public function __construct(
		Environment $environment,
		WordPressContext $wordpress_context,
		RollbackStoreInterface $store,
		?ManifestBuilderInterface $manifest_builder = null,
		int $retention = self::MIN_RETENTION,
		bool $content_only = false
	) {
		$this->environment       = $environment;
		$this->wordpress_context = $wordpress_context;
		$this->store             = $store;
		$this->manifest_builder  = $manifest_builder;
		$this->retention         = max( self::MIN_RETENTION, $retention );
		$this->content_only      = $content_only;
	}

	/**
	 * Take a safety archive of the current site and return its path.
	 *
	 * @param string        $wordpress_root Absolute path of the WordPress installation to archive.
	 * @param callable|null $on_entry       Optional per-entry progress callback, called as `( int $done, int $total ): void`.
	 * @param callable|null $on_bytes       Optional byte-progress callback forwarded to the export, called as `( int $bytes ): void` with each chunk's raw source byte count.
	 * @param callable|null $on_total       Optional callback, called once before copying with the estimated total source bytes, as `( int $estimated_bytes ): void`, so the caller can show a determinate bar.
	 * @return string The absolute path of the safety archive written.
	 * @throws RuntimeException If the preflight refuses, or the archive cannot be written.
	 */
	public function create( string $wordpress_root, ?callable $on_entry = null, ?callable $on_bytes = null, ?callable $on_total = null ): string {
		$this->store->ensure_directory();

		// The safety archive follows the restore's scope (ADR 0008): a content-only
		// restore takes a content-only safety archive (wp-content under a "wp-content"
		// prefix), so it captures exactly what is about to be overwritten and rolls
		// back through the same restore engine. The caller passes the matching scan
		// root ($wordpress_root is WP_CONTENT_DIR for content-only, ABSPATH for whole-site).
		$exclusions       = ExclusionRules::default_v010();
		$path_prefix      = $this->content_only ? 'wp-content' : '';
		$manifest_builder = $this->manifest_builder ?? ExportRunner::default_manifest_builder( $this->wordpress_context, $exclusions, $path_prefix );
		$entry_plans      = $manifest_builder->build( $wordpress_root );

		// Report the estimated total up front so a caller (the admin Backing-up bar)
		// can show determinate progress, the same way the Backup screen does.
		if ( null !== $on_total ) {
			$on_total( $entry_plans->estimated_bytes() );
		}

		$this->preflight_disk_space( $entry_plans );

		$path = $this->store->next_archive_path( new DateTimeImmutable() );

		$scope         = $this->content_only
			? Scope::content_only( $exclusions->patterns() )
			: Scope::whole_site( $exclusions->patterns() );
		$export_runner = new ExportRunner( $this->environment, $this->wordpress_context );
		$export_runner->export( new ExportOptions( $path, null, null, null, $scope ), $entry_plans, $on_entry, $on_bytes );

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
	 * Best-effort: the estimate is the stream's precomputed estimated_bytes() —
	 * the sum of the entries' original sizes, including the database (whose size
	 * lives in each db_chunk's byte_count(), not size()), so the database is not
	 * counted as zero. It is a conservative proxy, since the archive compresses
	 * them. Reading the total from the stream avoids walking — and so rebuilding —
	 * every plan. A free-space reading that cannot be taken (false, e.g. under
	 * open_basedir) is treated as "proceed" — the write itself is the hard
	 * backstop, since it runs before any destructive restore.
	 *
	 * @param ManifestStream $entry_plans The stream of entries about to be written.
	 * @return void
	 * @throws RuntimeException If the free space is known and smaller than the estimate.
	 */
	private function preflight_disk_space( ManifestStream $entry_plans ): void {
		$estimate = $entry_plans->estimated_bytes();

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
}
