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
use Throwable;
use Pontifex\Archive\Format\Scope;
use Pontifex\Environment\Environment;
use Pontifex\Export\ExportOptions;
use Pontifex\Export\ExportRunner;
use Pontifex\Export\ManifestTooLargeException;
use Pontifex\Filesystem\TempArtefact;
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
	 * @throws RuntimeException If the preflight refuses, the safety archive's file listing is too large for this installation to read back (a caught ManifestTooLargeException is converted to this, so the restore stops rather than proceed without a usable undo), or the archive cannot be written.
	 */
	public function create( string $wordpress_root, ?callable $on_entry = null, ?callable $on_bytes = null, ?callable $on_total = null ): string {
		$this->store->ensure_directory();

		// Runs BEFORE the free-space preflight below, deliberately: an earlier,
		// interrupted safety-archive write can leave a temp behind that is as
		// large as however much of the archive had been written, and removing
		// it is exactly what can let that preflight pass. See
		// {@see self::sweep_orphaned_archive_temps()}'s own docblock for the
		// full reasoning, including why this is silent and why it deliberately
		// does not refuse when the rollback directory is a symlink.
		$this->sweep_orphaned_archive_temps();

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
		$options       = new ExportOptions( $path, null, null, null, $scope );

		// ArchiveManifest::MAX_PAYLOAD_SIZE is a structural cap enforced by
		// ArchiveReader::read_manifest() before memory is ever considered — no
		// memory_limit, however large, makes an over-cap manifest readable. A
		// safety archive that trips the refusal is therefore not a
		// harder-to-open undo, it is no undo at all, and this method never
		// reads back what it wrote to notice the difference. So the refusal is
		// not exempted here: it is converted into a plain failure that stops
		// the caller's destructive restore outright, rather than let it
		// proceed believing a rollback exists when none was written.
		try {
			$export_runner->export( $options, $entry_plans, $on_entry, $on_bytes );
		} catch ( ManifestTooLargeException $e ) {
			throw new RuntimeException(
				sprintf(
					'A safety archive could not be taken for this site because its file listing (%d entries) is too large for Pontifex to read back; the restore has been stopped because it could not be undone.',
					count( $entry_plans )
				),
				0,
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $e is the underlying ManifestTooLargeException, chained as the previous exception for diagnostics; not HTML output.
				$e
			);
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
				sprintf( 'Could not restrict the safety archive %s to owner-only; refusing to proceed with an insecure database backup.', $path )
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
					'Not enough free disk space for a safety archive (need about %d bytes, %d available at %s). Free space, or pass --no-rollback-archive to skip the safety archive.',
					(int) $estimate,
					(int) $free,
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message only; the path is plugin-derived, not web output.
					$this->store->directory()
				)
			);
		}
	}

	/**
	 * Remove every leftover temp artefact an earlier, interrupted safety-archive write abandoned.
	 *
	 * The rollback-directory twin of
	 * {@see \Pontifex\Restore\FileWriter::sweep_orphaned_temp_files()}:
	 * {@see \Pontifex\Export\ExportRunner::temp_destination_path()} — which
	 * {@see self::create()} drives via {@see ExportRunner::export()} — writes
	 * the safety archive to a sibling temp path and renames it into place only
	 * once the whole archive is complete. An import killed between that write
	 * and its rename (SIGKILL, a host timeout, a fatal error) leaves the temp
	 * sitting in the rollback directory, potentially as large as however much
	 * of the archive had been written when it died. Nothing else ever
	 * revisits this directory to notice: a NEW safety archive is taken before
	 * EVERY import (ADR 0005), so the directory only ever grows, and
	 * {@see RollbackStoreInterface::prune()} matches archives by their
	 * `pre-import-rollback-<UTC>.wpmig` name, never a temp shape, so it walks
	 * straight past an orphan without ever counting or removing it.
	 *
	 * CALLED ONCE, by {@see self::create()}, immediately after
	 * {@see RollbackStoreInterface::ensure_directory()} and BEFORE
	 * {@see self::preflight_disk_space()}. That ordering is deliberate and
	 * load-bearing, not incidental: removing gigabytes an earlier abandoned
	 * write left behind is exactly what can let the free-space preflight PASS.
	 * Sweeping AFTER that preflight instead would refuse a safety archive
	 * that, once the orphan was accounted for, actually had room all along —
	 * which for a pre-import safety net is a worse failure than a slightly
	 * slower import: a refusal to take the very undo the destructive step
	 * depends on, over space an orphan was itself occupying for no reason.
	 *
	 * NON-RECURSIVE, deliberately. {@see RollbackStoreInterface::directory()}
	 * only ever holds flat files — the safety archives themselves, plus
	 * whatever this method is here to remove — so a single directory listing
	 * is a COMPLETE listing; there is no subtree a recursive walk could reach
	 * that a flat one would miss.
	 *
	 * WHAT COUNTS AS AN ORPHAN. Only a basename matching
	 * {@see \Pontifex\Filesystem\TempArtefact::is_orphan_name()} — the exact
	 * shape {@see \Pontifex\Filesystem\TempArtefact::suffix()} produces. See
	 * that class's own docblocks for the two shapes it deliberately does not
	 * match: an ordinary file that merely contains "pontifex" or ".tmp"
	 * somewhere in its name, and a resumable export's `*.part` file. The
	 * second exclusion is unconditional in {@see TempArtefact}, not scoped by
	 * directory, even though this particular directory could never legitimately
	 * hold one anyway — admin and scheduled backups write their `.part` files
	 * through {@see \Pontifex\Export\ResumableExportRunner}, a different
	 * caller entirely, never through this archiver.
	 *
	 * REMOVAL. isLink() is checked BEFORE isFile(), the same ordering
	 * {@see \Pontifex\Restore\FileWriter::sweep_orphaned_temp_files()} uses,
	 * for the identical reason: a dangling symlink reports false from
	 * isFile(), because isFile() follows the link to ask what the TARGET is,
	 * and a dangling link has none. Nothing this archiver writes ever leaves a
	 * dangling symlink behind — unlike FileWriter's symlink-capability probe,
	 * nothing here creates one — but matching the ordering costs nothing and
	 * is what stops this sweep and its sibling from silently disagreeing about
	 * how an orphan is recognised and removed. A directory that happens to be
	 * NAMED like a temp artefact — never produced by anything in this plugin,
	 * but not this method's business to assume impossible — is left alone
	 * entirely and does not contribute to the count.
	 *
	 * The returned count is the number of unlink() calls that actually
	 * returned true — never the number of matching names FOUND. A name that
	 * matched but failed to unlink (a permissions problem, a race) is not
	 * counted as removed, because it was not.
	 *
	 * THE RETURN VALUE IS DELIBERATELY UNUSED AT THE CALL SITE. Sweeping here
	 * is silent, exactly like {@see RollbackStoreInterface::prune()} — called
	 * a few lines later in {@see self::create()} — which already deletes
	 * whole, VALID safety archives from this same directory with no report at
	 * all. This method returns its count only so it is independently
	 * testable, not because any caller today surfaces it.
	 *
	 * MUST NEVER THROW. The whole body runs inside
	 * `try { … } catch ( Throwable $error )`, returning however many
	 * artefacts were actually removed before whatever happened, happened.
	 * This is best-effort housekeeping run immediately before a restore's own
	 * undo — the safety archive itself — is taken; its own failure must never
	 * be capable of stopping that safety archive, still less the destructive
	 * import it protects against, from proceeding.
	 *
	 * DELIBERATE DIFFERENCE FROM THE RESTORE-SIDE SWEEP — do not "fix" this.
	 * {@see \Pontifex\Restore\FileWriter::sweep_orphaned_temp_files()} refuses
	 * outright when its sweep root is a symlink, because it walks a
	 * potentially deep tree RECURSIVELY and a symlinked root could redirect
	 * that whole walk onto a foreign tree it has no business touching (see
	 * that method's own TRAVERSAL section). This method deliberately does NOT
	 * apply that guard, and must not gain one: it performs a single, flat
	 * listing of exactly one directory that this plugin itself creates and
	 * owns, so there is no recursive traversal here for a symlink to redirect
	 * — listing a symlinked directory lists that directory's own real
	 * contents, nothing more, exactly as if the symlink were not there.
	 * Meanwhile symlinking `wp-content/pontifex/rollback` onto a larger disk
	 * is a legitimate thing for an operator to do — safety archives are
	 * whole-database, whole-site copies, and a host with an undersized primary
	 * disk but a roomy secondary one is a real, ordinary shape — and refusing
	 * to sweep through that link would let orphans accumulate forever in
	 * exactly the directory an operator moved specifically because they were
	 * short on room, which is the very problem this method exists to fix.
	 * Applying FileWriter's guard here would trade a security property this
	 * method does not need for a false refusal this method exists to prevent.
	 *
	 * @return int How many leftover temp artefacts were actually removed.
	 */
	private function sweep_orphaned_archive_temps(): int {
		$removed = 0;

		try {
			// is_link()/is_file() results are cached by PHP for the rest of the
			// request, and a deleter must read the filesystem as it is now, not
			// as it was earlier in the same request — the same reason the
			// sibling sweep, {@see \Pontifex\Restore\FileWriter::sweep_orphaned_temp_files()},
			// clears it.
			clearstatcache();

			$directory = $this->store->directory();

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort housekeeping listing of this plugin's own rollback directory; a read failure here simply means there is nothing this step can find to sweep.
			$entries = @scandir( $directory );
			if ( false === $entries ) {
				return $removed;
			}

			foreach ( $entries as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				if ( ! TempArtefact::is_orphan_name( $entry ) ) {
					continue;
				}

				$path = $directory . '/' . $entry;

				// isLink() FIRST: a dangling symlink reports false from isFile() —
				// see REMOVAL in this method's docblock.
				if ( is_link( $path ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort removal of a leftover artefact from an earlier, interrupted safety-archive write; this method must never throw, so a failure here is simply not counted rather than surfaced.
					if ( @unlink( $path ) ) {
						++$removed;
					}
					continue;
				}

				if ( is_file( $path ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort removal of a leftover artefact from an earlier, interrupted safety-archive write; this method must never throw, so a failure here is simply not counted rather than surfaced.
					if ( @unlink( $path ) ) {
						++$removed;
					}
				}

				// Anything else (a directory named like a temp artefact) is left
				// alone and does not contribute to the count.
			}
		} catch ( Throwable $error ) {
			// Best-effort housekeeping must never abort the safety archive it runs
			// ahead of; whatever was removed before the failure is still reported.
			unset( $error );
		}

		return $removed;
	}
}
