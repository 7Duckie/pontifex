<?php
/**
 * Pontifex restorable table types — the one list of what a restore can replay.
 *
 * @package Pontifex\Database
 */

declare(strict_types=1);

namespace Pontifex\Database;

/**
 * The database object types a restore will replay, and the test for one.
 *
 * Kept in one place on purpose. The restore refuses any staged object whose type is not on
 * this list, and the export must leave out exactly what the restore would refuse — so both
 * sides read the same list rather than each holding a copy that could drift. A copy that
 * drifted would either let a backup carry an object its restore then rejects (a backup that
 * verifies sound and fails half-way through), or drop an object the restore accepts (silent
 * data loss).
 */
final class RestorableTableTypes {

	/**
	 * TABLE_TYPE values an ordinary local table may report (ADR 0019).
	 *
	 * A plain 'BASE TABLE' is the ordinary case. MariaDB 10.3+ additionally
	 * reports a table created `WITH SYSTEM VERSIONING` as TABLE_TYPE
	 * 'SYSTEM VERSIONED' — confirmed empirically against a live MariaDB
	 * server (12.3.2): `information_schema.TABLES.TABLE_TYPE` for such a
	 * table reads exactly `SYSTEM VERSIONED`, never `BASE TABLE`. This is
	 * still ordinary local storage — the server merely keeps an extra
	 * historical row version alongside the current one, on the same local
	 * disk, with no cross-table, cross-connection, or file-path capability
	 * — so refusing it here would refuse a site restoring its own,
	 * perfectly legitimate backup.
	 *
	 * MariaDB additionally reports a `CREATE SEQUENCE` object's TABLE_TYPE
	 * as 'SEQUENCE' — also confirmed empirically against a live MariaDB
	 * server (12.3.2): `CREATE SEQUENCE x START WITH 1 INCREMENT BY 1`
	 * reads `TABLE_TYPE = 'SEQUENCE'`, `ENGINE = 'InnoDB'` (already
	 * allow-listed), `CREATE_OPTIONS = ''`. Pontifex's own DatabaseScanner
	 * already exports a sequence as an ordinary db_chunk — `SHOW TABLES`
	 * lists it alongside real tables, and `SHOW CREATE TABLE` returns a
	 * plain `CREATE TABLE ... SEQUENCE=1` statement over eight ordinary
	 * bigint columns — and replaying that SQL against a fresh connection
	 * rebuilds a working sequence (`SELECT NEXTVAL(...)` succeeds). A
	 * sequence is local, single-table storage with no cross-table,
	 * cross-connection, or file-path capability of its own — the same
	 * standard SYSTEM VERSIONED meets above — so refusing it here would
	 * make any site with so much as one sequence anywhere unable to
	 * restore its own backup at all, since restore is all-or-nothing (the
	 * same defect class SYSTEM VERSIONED closed; this sibling was missed
	 * the first time). Anything else this column can report (`VIEW`,
	 * `SYSTEM VIEW`) is refused, as before.
	 *
	 * @var string[]
	 */
	private const TYPES = array( 'BASE TABLE', 'SYSTEM VERSIONED', 'SEQUENCE' );

	/**
	 * Whether a restore will replay an object of the given type.
	 *
	 * @param string $table_type The type as the server reports it (information_schema.TABLES.TABLE_TYPE, or the Table_type column of SHOW FULL TABLES).
	 * @return bool True when the restore accepts it; false for a view or anything else it refuses.
	 */
	public static function is_restorable( string $table_type ): bool {
		return in_array( $table_type, self::TYPES, true );
	}
}
