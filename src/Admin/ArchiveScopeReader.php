<?php
/**
 * Pontifex archive scope reader — a fail-soft "Contains" label for a backup list row.
 *
 * @package Pontifex\Admin
 */

declare(strict_types=1);

namespace Pontifex\Admin;

use Pontifex\Archive\Reader\ArchiveReader;
use Pontifex\Archive\ScopeSummary;
use Throwable;

/**
 * Reads one archive's recorded scope and turns it into a compact list label.
 *
 * Every admin backup list (Backup, Restore, Verify) shows a "Contains" column so
 * an operator can tell a content backup from a database-only or files-only one
 * without opening it. This is deliberately a light, read-only peek at the
 * archive's provenance block for one row at a time — open the file, read its
 * header and provenance, close it again. It is a different lifecycle from
 * {@see \Pontifex\Admin\VerifyController::archive_facts()}, which reads
 * provenance from a stream a verify run already has open mid-walk; unifying the
 * two is a candidate later tidy, not this slice.
 *
 * Fail-soft by design: a label is presentation, never integrity. A missing,
 * corrupt, truncated, non-archive, or otherwise unreadable file must not break
 * the list it appears in — it simply reports {@see ScopeSummary::unreadable_label()}
 * — because a stray or damaged file in the backups directory is exactly the kind
 * of thing an operator needs the list to keep working around, not fail on.
 *
 * Stateless and static: a presentation helper with no state of its own, kept out
 * of the constructor of the three page classes it serves so that adding it here
 * causes no churn to their existing constructors or tests.
 */
final class ArchiveScopeReader {

	/**
	 * Read an archive's recorded scope and return its "Contains" label.
	 *
	 * Opens the file, reads only its header and provenance block (never the
	 * manifest or entries), and closes it again. Never throws: any failure to
	 * open or parse the archive — a missing file, a truncated or corrupt
	 * archive, an encrypted archive whose provenance cannot be read, or
	 * anything else — reports {@see ScopeSummary::unreadable_label()} instead.
	 *
	 * @param string $path Absolute path to the archive on disk.
	 * @return string The "Contains" label for this archive.
	 */
	public static function label( string $path ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- Opening a plugin-owned backup as a stream; @ traps an unopenable-file warning converted to the fail-soft label below.
		$source = @fopen( $path, 'rb' );
		if ( false === $source ) {
			return ScopeSummary::unreadable_label();
		}

		try {
			$scope = ( new ArchiveReader( $source ) )->provenance()->scope();
			$label = ScopeSummary::label( $scope );
		} catch ( Throwable $error ) {
			unset( $error );
			$label = ScopeSummary::unreadable_label();
		} finally {
			if ( is_resource( $source ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the archive stream opened above; not a WP_Filesystem operation.
				fclose( $source );
			}
		}

		return $label;
	}
}
