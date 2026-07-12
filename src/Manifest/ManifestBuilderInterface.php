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
	 * Build a memory-bounded {@see ManifestStream} for the given WordPress installation.
	 *
	 * The returned stream is ready to feed to
	 * {@see \Pontifex\Archive\Writer\ArchiveWriter::write_archive()}. It yields
	 * {@see EntryPlan} instances one at a time, each carrying a deferred source
	 * that ArchiveWriter opens and closes as it writes the entry, so the caller
	 * need not manage source streams and the plans are never all held at once.
	 *
	 * Implementations may return an empty stream; an empty result is a
	 * legitimate outcome that produces a valid empty archive containing only the
	 * header, provenance, manifest, and footer blocks.
	 *
	 * @param string        $wordpress_root   Absolute filesystem path of the WordPress installation.
	 * @param callable|null $on_scan_progress Optional callback invoked with the running file-scan entry count, so a caller can report scan progress; receives one int argument.
	 * @return ManifestStream All entries that should be archived, in implementation-defined order.
	 * @throws InvalidArgumentException If $wordpress_root is empty or not a directory.
	 * @throws RuntimeException         If the build cannot complete (e.g. a scanner fails).
	 */
	public function build( string $wordpress_root, ?callable $on_scan_progress = null ): ManifestStream;
}
