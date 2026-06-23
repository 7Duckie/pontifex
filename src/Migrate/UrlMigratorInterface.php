<?php
/**
 * Pontifex URL-migrator contract — the seam a command drives to rewrite URLs after a restore.
 *
 * @package Pontifex\Migrate
 */

declare(strict_types=1);

namespace Pontifex\Migrate;

use InvalidArgumentException;
use RuntimeException;

/**
 * Contract for the cross-URL migration step that runs after a same-URL restore.
 *
 * Extracted from {@see UrlMigrator} so {@see \Pontifex\Cli\ImportCommand} can
 * depend on the behaviour rather than the final class — the same
 * interface-around-final pattern import already uses for
 * {@see \Pontifex\Restore\RestoreRunnerInterface} and
 * {@see \Pontifex\Rollback\SafetyArchiverInterface}. The command holds a
 * UrlMigratorInterface; production wires the concrete UrlMigrator; unit tests
 * substitute a fake.
 *
 * Two steps, split so the command can announce the migration before the
 * destructive restore and apply it after:
 *
 *  - {@see source_url()} reads the source-site URL from the archive's
 *    provenance — the URL to replace. It writes nothing and can run before the
 *    restore (or under --dry-run).
 *  - {@see migrate()} rewrites that URL to the new one across the live
 *    database, after the restore has put the source data in place.
 */
interface UrlMigratorInterface {

	/**
	 * Return the source-site URL recorded in the archive's provenance.
	 *
	 * This is the URL a migration replaces: the address the site was exported
	 * from, which a same-URL restore writes throughout the destination
	 * database. Reading it touches nothing on the destination.
	 *
	 * @param resource $archive_source A seekable, readable stream containing a Pontifex archive.
	 * @return string The source-site URL.
	 * @throws RuntimeException If the archive cannot be read or its provenance is malformed.
	 */
	public function source_url( $archive_source ): string;

	/**
	 * Rewrite every occurrence of the old URL with the new one across the live database.
	 *
	 * Runs the serialised-safe rewrite pass over the destination database — the
	 * step that turns a same-URL restore into a cross-URL migration. Intended to
	 * run after the restore, with the pre-import safety archive (ADR 0005) as
	 * the undo if it goes wrong.
	 *
	 * @param string $old_url The URL to replace (typically {@see source_url()}); must be non-empty.
	 * @param string $new_url The replacement URL.
	 * @return RewriteReport The counts of what changed.
	 * @throws InvalidArgumentException If $old_url is empty.
	 * @throws RuntimeException         If the database signals a failure.
	 */
	public function migrate( string $old_url, string $new_url ): RewriteReport;
}
