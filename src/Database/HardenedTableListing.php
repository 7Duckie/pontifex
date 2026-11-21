<?php
/**
 * Pontifex hardened table listing — enumerate the WordPress-prefixed tables, refusing a
 * silently-empty result that would otherwise yield a database-less backup.
 *
 * @package Pontifex\Database
 */

declare(strict_types=1);

namespace Pontifex\Database;

use RuntimeException;
use wpdb;

/**
 * Shared, hardened enumeration of a site's WordPress-prefixed tables.
 *
 * `$wpdb->get_col()` returns an empty array — not `false` — when its query fails, and
 * `$wpdb->last_error` is left empty when errors are suppressed (`suppress_errors`, which
 * WordPress turns on for many operations). So both failure signals can be silent at once,
 * and a failed `SHOW TABLES` is indistinguishable from "this database has no tables". A
 * real WordPress database always has a `{prefix}options` table, so an empty result is a
 * failure, never a legitimate empty database — and a backup or migration must refuse it
 * rather than proceed with no database.
 *
 * The one query is `SHOW FULL TABLES`, which returns each name together with its type, so a
 * view (which lists beside base tables but which a restore cannot recreate) is told apart
 * without a second query per table. {@see self::list_prefixed_tables()} leaves views out and
 * hands their names back from the same listing, for reporting.
 *
 * Lives in one trait shared by the backup scanner's adapter and the migration database, so
 * the guard cannot drift between the two the way the duplicated `last_error`-only pattern
 * did.
 */
trait HardenedTableListing {

	/**
	 * List the WordPress-prefixed tables a restore can replay, refusing an empty result.
	 *
	 * Reads the guarded listing from {@see self::read_prefixed_table_rows()} and leaves out
	 * every object a restore would refuse (a view, chiefly), keeping every type
	 * {@see RestorableTableTypes} accepts. The empty-result refusal has already been applied to
	 * the raw listing, before this filter runs.
	 *
	 * The objects left out are handed back through $excluded, from the very same listing, so a
	 * caller that wants to report them never has to ask the database a second time: a second
	 * query could disagree with the first, and on a snapshot connection would be a needless one.
	 *
	 * @param wpdb          $wpdb     The WordPress database object to query.
	 * @param string        $context  The calling class name, used in the exception messages.
	 * @param string[]|null $excluded Optional. Receives the names of the objects left out, alphabetically sorted; replaced, never added to.
	 * @return string[] The prefixed table names, alphabetically sorted.
	 * @throws RuntimeException If the query signals an error, or returns no tables.
	 */
	private function list_prefixed_tables( wpdb $wpdb, string $context, ?array &$excluded = null ): array {
		$tables   = array();
		$left_out = array();
		foreach ( $this->read_prefixed_table_rows( $wpdb, $context ) as $row ) {
			if ( null === $row['type'] || RestorableTableTypes::is_restorable( $row['type'] ) ) {
				$tables[] = $row['name'];
			} else {
				$left_out[] = $row['name'];
			}
		}

		sort( $tables, SORT_STRING );
		sort( $left_out, SORT_STRING );
		$excluded = $left_out;
		return $tables;
	}

	/**
	 * Read every WordPress-prefixed object with its type, refusing an empty result.
	 *
	 * Runs the prepared `SHOW FULL TABLES LIKE '{prefix}%'`, keeps the `last_error` guard, and
	 * additionally refuses an empty result: a real install always has `{prefix}options`, so
	 * nothing matching the prefix means the query failed silently or this is not a WordPress
	 * database. A positive probe for the options table sharpens the refusal message. The refusal
	 * applies to the raw result, before any filtering, so it keeps its meaning of "nothing
	 * matched the prefix".
	 *
	 * Columns are read by position: the first column's name is dynamic
	 * (`Tables_in_<db> (<pattern>)`) and must not be relied on. A row that reports no type
	 * carries a null type, which callers keep rather than drop.
	 *
	 * @param wpdb   $wpdb    The WordPress database object to query.
	 * @param string $context The calling class name, used in the exception messages.
	 * @return array<int, array{name: string, type: string|null}> The prefixed objects, in server order; never empty.
	 * @throws RuntimeException If the query signals an error, or returns no tables.
	 */
	private function read_prefixed_table_rows( wpdb $wpdb, string $context ): array {
		$prefix  = (string) $wpdb->prefix;
		$pattern = $wpdb->esc_like( $prefix ) . '%';
		$sql     = $wpdb->prepare( 'SHOW FULL TABLES LIKE %s', $pattern );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is the direct return value of $wpdb->prepare() on the line above (the sniffs do not track preparation across the assignment), and enumerating live schema for a backup is inherently a direct, uncacheable read.
		$rows = $wpdb->get_results( $sql, ARRAY_N );

		if ( '' !== $wpdb->last_error ) {
			$last_error = (string) $wpdb->last_error;
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $wpdb->last_error reported verbatim for diagnostic context; exception path, not HTML output.
			throw new RuntimeException( sprintf( '%s: list_tables query failed: %s', $context, $last_error ) );
		}

		$rows    = is_array( $rows ) ? $rows : array();
		$entries = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row[0] ) ) {
				continue;
			}
			$entries[] = array(
				'name' => (string) $row[0],
				'type' => isset( $row[1] ) ? (string) $row[1] : null,
			);
		}

		if ( array() === $entries ) {
			// A real WordPress install always has {prefix}options, so an empty result is a
			// silently-failed query (get_results returns [] not false; last_error is empty under
			// suppress_errors), never a legitimate empty database. Probe for the options table
			// to sharpen the message, then refuse: a backup must never be produced with no
			// database in it.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- The argument is a $wpdb->prepare() call, and the confirming probe reads live schema state for a single table name, so caching does not apply.
			$options = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . 'options' ) );
			$reason  = ( null === $options || '' === $options )
				? ', and the core options table is absent, so the database may be unavailable'
				: '';
			$message = sprintf(
				'%s: the database returned no %s-prefixed tables%s. Refusing to proceed with no database.',
				$context,
				$prefix,
				$reason
			);
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $context is the caller class name and $prefix is $wpdb->prefix; both plugin-derived, for diagnostic context; exception path, not HTML output.
			throw new RuntimeException( $message );
		}

		return $entries;
	}
}
