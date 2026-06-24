<?php
/**
 * Pontifex manifest builder contract.
 *
 * @package Pontifex\Manifest
 */

declare(strict_types=1);

namespace Pontifex\Manifest;

use InvalidArgumentException;
use RuntimeException;
use Pontifex\Archive\Writer\EntryPlan;

/**
 * Contract for objects that turn a WordPress installation into a list of EntryPlans.
 *
 * Pontifex's archive writer (ArchiveWriter) consumes EntryPlans. The
 * mechanism that produces them — walking the filesystem, walking the
 * database, applying exclusion rules, choosing a codec, opening
 * source streams — is encapsulated behind this interface so that
 * callers can substitute alternative implementations.
 *
 * The standard implementation is {@see ManifestBuilder}, which is
 * marked final and wires together FileScanner and DatabaseScanner at
 * construction time. Tests substitute mock or fake
 * implementations of this interface to exercise command-level
 * orchestration logic without standing up the full scanner stack.
 *
 * Future versions of Pontifex may add additional implementations
 * here (e.g. a CachingManifestBuilder that memoises results between
 * runs, or a LazyManifestBuilder that streams entries instead of
 * materialising them all up front). New implementations of this
 * interface are unconstrained by ManifestBuilder's internal choices
 * — they need only honour the build() method's contract.
 */
interface ManifestBuilderInterface {

	/**
	 * Build a complete EntryPlan list for the given WordPress installation.
	 *
	 * The returned list is ready to feed to
	 * {@see \Pontifex\Archive\Writer\ArchiveWriter::write_archive()}.
	 * Each EntryPlan in the returned array carries an opened source
	 * stream; the caller is responsible for closing the streams
	 * (typically by passing the EntryPlans through ArchiveWriter,
	 * which closes them as it consumes them).
	 *
	 * Implementations may return an empty array; an empty result is
	 * a legitimate outcome that produces a valid empty archive
	 * containing only the header, provenance, manifest, and footer
	 * blocks.
	 *
	 * @param string $wordpress_root Absolute filesystem path of the WordPress installation.
	 * @return EntryPlan[] All entries that should be archived, in implementation-defined order.
	 * @throws InvalidArgumentException If $wordpress_root is empty or not a directory.
	 * @throws RuntimeException         If the build cannot complete (e.g. a file source stream cannot be opened, a scanner fails).
	 */
	public function build( string $wordpress_root ): array;
}
