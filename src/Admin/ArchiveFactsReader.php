<?php
/**
 * Pontifex archive facts reader — a fail-soft identity read for a backup list row.
 *
 * @package Pontifex\Admin
 */

declare(strict_types=1);

namespace Pontifex\Admin;

use Pontifex\Archive\Reader\ArchiveReader;
use Pontifex\Archive\ScopeSummary;
use Throwable;

/**
 * Reads one archive's provenance and turns it into the {@see ArchiveFacts} a
 * backup list row shows: what it contains, where it truly came from, and when
 * it was really made.
 *
 * Every admin backup list (Backup, Restore, Verify) shows this per row so an
 * operator can identify a backup without opening it — in particular, without
 * being misled by an uploaded archive's filename, which is stamped with the
 * upload time, not the source site's export time. This is deliberately a
 * light, read-only peek at the archive's provenance block for one row at a
 * time — open the file, read its header and provenance, close it again. It is
 * a different lifecycle from
 * {@see \Pontifex\Admin\VerifyController::archive_facts()}, which reads
 * provenance from a stream a verify run already has open mid-walk; unifying
 * the two is a candidate later tidy, not this slice.
 *
 * Fail-soft by design: these facts are presentation, never integrity. A
 * missing, corrupt, truncated, non-archive, or otherwise unreadable file must
 * not break the list it appears in — it simply reports
 * {@see ArchiveFacts::unreadable()} — because a stray or damaged file in the
 * backups directory is exactly the kind of thing an operator needs the list
 * to keep working around, not fail on.
 *
 * Stateless and static: a presentation helper with no state of its own, kept
 * out of the constructor of the three page classes it serves so that adding
 * it here causes no churn to their existing constructors or tests.
 */
final class ArchiveFactsReader {

	/**
	 * Read an archive's provenance and return the facts a list row shows.
	 *
	 * Opens the file, reads only its header and provenance block (never the
	 * manifest or entries), and closes it again — one read services the
	 * scope, source, and creation facts together. Never throws: any failure
	 * to open or parse the archive — a missing file, a truncated or corrupt
	 * archive, an encrypted archive whose provenance cannot be read, or
	 * anything else — reports {@see ArchiveFacts::unreadable()} instead.
	 *
	 * @param string $path Absolute path to the archive on disk.
	 * @return ArchiveFacts The facts for this archive.
	 */
	public static function facts( string $path ): ArchiveFacts {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- Opening a plugin-owned backup as a stream; @ traps an unopenable-file warning converted to the fail-soft facts below.
		$source = @fopen( $path, 'rb' );
		if ( false === $source ) {
			return ArchiveFacts::unreadable();
		}

		try {
			$provenance = ( new ArchiveReader( $source ) )->provenance();
			$facts      = new ArchiveFacts(
				ScopeSummary::label( $provenance->scope() ),
				$provenance->url(),
				$provenance->timestamp()
			);
		} catch ( Throwable $error ) {
			unset( $error );
			$facts = ArchiveFacts::unreadable();
		} finally {
			if ( is_resource( $source ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the archive stream opened above; not a WP_Filesystem operation.
				fclose( $source );
			}
		}

		return $facts;
	}
}
