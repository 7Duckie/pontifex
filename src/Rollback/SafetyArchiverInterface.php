<?php
/**
 * Pontifex safety-archiver contract — takes a pre-import safety archive.
 *
 * @package Pontifex\Rollback
 */

declare(strict_types=1);

namespace Pontifex\Rollback;

use RuntimeException;

/**
 * Contract for taking a pre-import safety archive of the current site.
 *
 * Extracted from {@see SafetyArchiver} so the import command can depend on the
 * behaviour rather than the final concrete class, and substitute a fake in
 * tests. The archiver reuses the export pipeline to write a full `.wpmig` of the
 * current site into the rollback directory, so a destructive import can be
 * undone (ADR 0005).
 */
interface SafetyArchiverInterface {

	/**
	 * Take a safety archive of the current site and return its path.
	 *
	 * Writes a full export of the site rooted at $wordpress_root into the
	 * rollback directory, prunes older archives to the retention limit, and
	 * returns the absolute path written. A free-disk preflight may refuse
	 * before writing; any write failure is raised so the caller can abort the
	 * import *before* the destructive restore begins.
	 *
	 * @param string        $wordpress_root Absolute path of the WordPress installation to archive.
	 * @param callable|null $on_entry       Optional per-entry progress callback, called as `( int $done, int $total ): void`.
	 * @param callable|null $on_bytes       Optional byte-progress callback forwarded to the export, called as `( int $bytes ): void`.
	 * @return string The absolute path of the safety archive written.
	 * @throws RuntimeException If the preflight refuses, or the archive cannot be written.
	 */
	public function create( string $wordpress_root, ?callable $on_entry = null, ?callable $on_bytes = null ): string;
}
