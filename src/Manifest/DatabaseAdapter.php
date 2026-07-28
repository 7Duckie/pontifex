<?php
/**
 * Pontifex manifest database adapter — the abstraction DatabaseScanner depends on.
 *
 * @package Pontifex\Manifest
 */

declare(strict_types=1);

namespace Pontifex\Manifest;

use RuntimeException;

/**
 * Database operations that DatabaseScanner needs.
 *
 * This interface exists so DatabaseScanner can be unit-tested without
 * any WordPress / wpdb mocking machinery. Tests inject a small
 * in-memory fake; production code injects {@see WpdbAdapter}.
 * Concentrates all knowledge of WordPress's `$wpdb` into a single
 * adapter class whose own tests use brain/monkey.
 *
 * All return values are byte-sized strings ready to be written to an
 * archive entry. SQL statements end with semicolons and newlines so
 * that concatenating multiple {@see DatabaseAdapter::dump_table_rows()}
 * results produces a valid SQL script.
 */
interface DatabaseAdapter {

	/**
	 * List every table the WordPress installation owns.
	 *
	 * Includes prefixed core tables (wp_posts, wp_options, etc.) and
	 * any prefixed tables that plugins / themes have added. Does NOT
	 * include unrelated tables in the same database that lack the
	 * WordPress prefix.
	 *
	 * @return string[] Table names in alphabetical order.
	 * @throws RuntimeException If the database cannot be queried.
	 */
	public function list_tables(): array;

	/**
	 * Return the number of rows in the given table.
	 *
	 * Used by DatabaseScanner to decide chunking — large tables are
	 * split into multiple chunks, small tables are dumped in one.
	 *
	 * @param string $table_name Fully prefixed table name as returned by list_tables().
	 * @return int The non-negative row count.
	 * @throws RuntimeException If the table cannot be queried.
	 */
	public function row_count( string $table_name ): int;

	/**
	 * Dump the schema (DROP TABLE IF EXISTS + CREATE TABLE) for the given table.
	 *
	 * Returned as ready-to-execute SQL, ending with a trailing
	 * semicolon and newline so that concatenating it with subsequent
	 * INSERT statements produces a valid script.
	 *
	 * @param string $table_name Fully prefixed table name.
	 * @return string SQL bytes encoding the schema.
	 * @throws RuntimeException If the schema cannot be retrieved.
	 */
	public function dump_table_schema( string $table_name ): string;

	/**
	 * Dump a slice of rows from the given table as INSERT statements.
	 *
	 * Returns the SQL for rows in the range [$offset, $offset + $limit).
	 * If $offset is past the end of the table, returns an empty string.
	 * The returned SQL ends with a trailing newline; concatenation
	 * with subsequent dumps produces a valid script.
	 *
	 * @param string $table_name Fully prefixed table name.
	 * @param int    $offset     0-based starting row index; must be non-negative.
	 * @param int    $limit      Maximum number of rows to dump; must be positive.
	 * @return string SQL bytes encoding the rows; empty if no rows match.
	 * @throws RuntimeException If the rows cannot be retrieved.
	 */
	public function dump_table_rows( string $table_name, int $offset, int $limit ): string;

	/**
	 * Execute one SQL statement against the database.
	 *
	 * Used during restore by {@see \Pontifex\Restore\DatabaseWriter}
	 * to replay the SQL bytes captured from db_chunk archive entries.
	 * Each call executes exactly one statement; callers split
	 * multi-statement payloads into individual statements before
	 * calling this method.
	 *
	 * The statement is run as-is. The adapter does not parse, rewrite,
	 * or validate it — the bytes came from a Pontifex-produced archive
	 * and are trusted to be syntactically correct for the destination
	 * MySQL/MariaDB server.
	 *
	 * @param string $sql The SQL statement to execute. Must not be empty.
	 * @throws RuntimeException If the statement fails to execute.
	 */
	public function execute_sql( string $sql ): void;

	/**
	 * Rewrite the WordPress table prefix embedded in key columns, after a restore.
	 *
	 * Used during a cross-prefix restore by {@see \Pontifex\Restore\DatabaseWriter}
	 * once every db_chunk has been replayed (table identifiers are already rewritten
	 * to the destination prefix at replay time). The prefix is also embedded in two
	 * plain key columns, which a table rename does not touch:
	 *
	 *  - `{prefix}options.option_name = '{prefix}user_roles'`, and
	 *  - every `{prefix}usermeta.meta_key` that begins with the prefix
	 *    (`{prefix}capabilities`, `{prefix}user_level`, `{prefix}user-settings`, …).
	 *
	 * The rewrite is column-aware (it updates only the key column, never a value), so
	 * it is bounded and never touches serialised data. Implementations must escape
	 * both prefixes — the source prefix comes from the archive and is untrusted.
	 *
	 * During a staging-table restore (ADR 0009) the replayed tables carry a
	 * physical staging prefix on top of the destination prefix until the atomic
	 * cut-over; $staging_prefix names that extra prefix so the rewrite targets
	 * the staged copies, not the still-live tables.
	 *
	 * @param string $source_prefix  The prefix recorded in the archive (the rows' current prefix).
	 * @param string $dest_prefix    The destination site's prefix (the rows' target prefix).
	 * @param string $staging_prefix Optional. A physical prefix currently prepended to the tables being rewritten; default '' (rewrite the live tables).
	 * @return void
	 * @throws RuntimeException If a rewrite statement fails to execute.
	 */
	public function rewrite_prefix_keys( string $source_prefix, string $dest_prefix, string $staging_prefix = '' ): void;

	/**
	 * Whether a table with exactly this name exists in the database.
	 *
	 * Used by the staging-table restore (ADR 0009) to decide, per table, whether
	 * the atomic cut-over must move a live table aside (`T → old, staged → T`)
	 * or simply install a table new to the destination (`staged → T`).
	 * Implementations must match the name literally (escaping any pattern
	 * characters), and should report "does not exist" on a query error: a wrong
	 * "exists" answer merely adds a harmless move-aside, while the cut-over
	 * RENAME itself stays the atomic arbiter — if the answer was wrong in the
	 * dangerous direction the RENAME fails as a whole and no changes are made.
	 *
	 * @param string $table_name The exact table name to look for.
	 * @return bool True when the table exists.
	 */
	public function table_exists( string $table_name ): bool;

	/**
	 * List every table whose name begins with the given prefix.
	 *
	 * Used by the staging-table restore (ADR 0009) to sweep leftover
	 * `pontifexstg_*` / `pontifexold_*` tables a crashed earlier run may have
	 * abandoned. Unlike {@see self::list_tables()}, the prefix is the caller's,
	 * not the WordPress prefix, and an empty result is an ordinary answer, not
	 * a failure. Implementations should return an empty list on a query error —
	 * the sweep is best-effort housekeeping, never a gate.
	 *
	 * @param string $prefix The literal name prefix to match; must not be empty.
	 * @return string[] Matching table names in alphabetical order; empty when none match.
	 * @throws RuntimeException If $prefix is empty (a full-database listing is never intended).
	 */
	public function list_tables_by_prefix( string $prefix ): array;

	/**
	 * The table's average stored row width, in bytes, or 0 when unknown.
	 *
	 * Used by {@see DatabaseScanner} to size chunks from the table's real row
	 * width rather than a fixed guess, so a wide-row table (huge serialised
	 * options, page-builder LONGTEXT) produces proportionally fewer rows per
	 * chunk and every chunk stays near the byte budget — keeping the archive
	 * restorable under a memory-budgeted web request.
	 *
	 * The figure is a sizing hint, not a correctness input: implementations
	 * report the storage engine's own estimate and return 0 when it cannot be
	 * read, in which case the scanner falls back to its fixed estimate. A wrong
	 * answer only changes how a table is split, never what is captured.
	 *
	 * @param string $table_name Fully prefixed table name.
	 * @return int Average bytes per row; 0 when unknown.
	 */
	public function average_row_bytes( string $table_name ): int;

	/**
	 * Set the connection's character set for a database replay.
	 *
	 * The connection charset governs how the server interprets the bytes of
	 * every statement sent over it. A restore replays SQL captured under the
	 * archive's charset, so the connection must speak that charset for the
	 * replay's duration or multibyte content is silently transcoded to
	 * mojibake — the reason standalone dump tools emit SET NAMES in every
	 * dump. Implementations must fail loudly: proceeding after a failed
	 * charset change risks exactly the corruption this call prevents.
	 *
	 * @param string $charset The archive's character set, e.g. "utf8mb4". Callers validate it; implementations must re-validate before interpolating.
	 * @return void
	 * @throws RuntimeException If the charset is malformed or the server refuses it.
	 */
	public function set_session_charset( string $charset ): void;

	/**
	 * Restore the connection's own configured character set after a replay.
	 *
	 * The counterpart to {@see self::set_session_charset()}: the replay is
	 * over, so the connection goes back to the destination site's configured
	 * charset before any later query runs on it. Best-effort — the replayed
	 * data is already committed, so a failure here must not undo a completed
	 * restore.
	 *
	 * @return void
	 */
	public function restore_session_charset(): void;

	/**
	 * Read a table's storage facts — engine, create-options, and object type —
	 * from the server's own catalogue, for the CURRENT connection's schema.
	 *
	 * Used by {@see \Pontifex\Restore\DatabaseWriter} immediately after a
	 * db_chunk's CREATE TABLE statement has executed, to confirm the object it
	 * just built is an ordinary local table before any later statement in the
	 * same chunk runs (ADR 0019). A CREATE statement's shape check anchors
	 * only its opening bytes and never inspects the body — a real table can
	 * legitimately carry a FOREIGN KEY reference or a PARTITION BY clause
	 * there — so the body is free to name a storage engine or option that
	 * turns the "staged" table into something other than its own local
	 * storage. Asking the server what it actually built, rather than parsing
	 * what the payload asked it to build, is the same shift from parsing SQL
	 * text to trusting server-reported facts that already makes the shape
	 * check itself safer than extracting a statement's verb.
	 *
	 * Implementations must read from information_schema.TABLES (or the
	 * equivalent server catalogue) filtered to the CURRENT connection's own
	 * schema — never a schema name taken from $table_name or from anything
	 * else archive-supplied, and never a name qualifying $table_name itself
	 * with a database prefix.
	 *
	 * @param string $table_name The exact table name to inspect (the writer's own staged identifier, never one taken from the archive payload).
	 * @return array{engine: string, create_options: string, table_type: string}|null The table's reported engine, create-options, and object type; null if the table cannot be found in the catalogue at all.
	 */
	public function table_storage_facts( string $table_name ): ?array;

	/**
	 * Whether the table's own definition, as the server itself reports it, names a
	 * DATA DIRECTORY or INDEX DIRECTORY on any of its partitions.
	 *
	 * Used by {@see \Pontifex\Restore\DatabaseWriter} as a second, narrower
	 * server-fact check alongside {@see self::table_storage_facts()} (ADR 0019).
	 * A table-level `DATA DIRECTORY` / `INDEX DIRECTORY` clause is already
	 * visible in `information_schema.TABLES.CREATE_OPTIONS` and refused there
	 * directly; the same clause written on an individual PARTITION is not —
	 * `CREATE_OPTIONS` reports only the single word `partitioned` for such a
	 * table, with no per-partition detail. The obvious next place to look,
	 * `information_schema.PARTITIONS`, does **not** carry a `DATA_DIRECTORY` or
	 * `INDEX_DIRECTORY` column at all — confirmed against both the MySQL and
	 * MariaDB `INFORMATION_SCHEMA.PARTITIONS` column references, and empirically
	 * against a live MariaDB server, where `SHOW COLUMNS FROM
	 * information_schema.PARTITIONS` lists no such column. No structured
	 * catalogue field for this exists on either engine.
	 *
	 * `SHOW CREATE TABLE` is therefore the only server-reported source for this
	 * fact, and implementations must use it — but this is not a return to
	 * parsing untrusted bytes for a deny-list, which ADR 0019 rejects
	 * elsewhere: the text an implementation inspects here is the SERVER's own
	 * canonical re-serialisation of the object it actually built, generated
	 * fresh from its stored metadata, in a fixed and consistently-cased form
	 * (`DATA DIRECTORY = '...'`) regardless of how the original CREATE
	 * statement cased, spaced, or commented the clause — confirmed empirically
	 * against a live MariaDB server with both an upper-case and a lower-case
	 * original clause; both echoed back identically. That is the same
	 * distinction that already makes `ENGINE` and `CREATE_OPTIONS` trustworthy
	 * elsewhere in this design: it is never the archive's own bytes being
	 * pattern-matched pre-execution, only what the server itself says about an
	 * object that already exists.
	 *
	 * Only meaningful, and intended to be called, when
	 * {@see self::table_storage_facts()} has already reported the table's
	 * `CREATE_OPTIONS` as partitioned; an unpartitioned table's own
	 * `DATA DIRECTORY` / `INDEX DIRECTORY` is refused by the `CREATE_OPTIONS`
	 * check directly and never needs this call.
	 *
	 * Reading `SHOW CREATE TABLE` text is not itself a return to parsing
	 * untrusted SQL (see above), but the definition's string and identifier
	 * literals can legitimately contain arbitrary text — a table COMMENT, a
	 * column COMMENT or DEFAULT, a partition COMMENT, or even a column
	 * literally named to look like the clause — so implementations MUST NOT
	 * match a DATA DIRECTORY / INDEX DIRECTORY pattern against the raw
	 * definition text as a whole: doing so refuses a table merely for
	 * MENTIONING the words inside a quoted value or a backtick-quoted
	 * identifier, confirmed empirically against a live MariaDB server for
	 * every one of the shapes above. The match must be scoped to the
	 * definition's structural SQL syntax only — outside any single-quoted,
	 * double-quoted, or backtick-quoted span — the same discipline
	 * {@see \Pontifex\Restore\DatabaseWriter::has_executable_semicolon()}
	 * already applies to archive-supplied SQL, applied here to the server's
	 * own re-serialised DDL instead.
	 *
	 * @param string $table_name The exact table name to inspect (the writer's own staged identifier).
	 * @return bool True when the table's own CREATE TABLE definition names a DATA DIRECTORY or INDEX DIRECTORY on any partition.
	 * @throws RuntimeException If the definition could not be read at all.
	 */
	public function partition_storage_directory_present( string $table_name ): bool;

	/**
	 * The exact number of rows the table currently holds, for the CURRENT
	 * connection's schema.
	 *
	 * Used by {@see \Pontifex\Restore\DatabaseWriter} immediately after a
	 * db_chunk's CREATE TABLE statement has executed, alongside
	 * {@see self::table_storage_facts()}, to confirm the object the CREATE
	 * just built holds no rows (ADR 0019). The CREATE shape check anchors
	 * only the statement's opening bytes up to and including the mandatory
	 * `" ("` — a column-list open paren — but says nothing about what
	 * follows the list's closing paren: `CREATE TABLE `<staged>` (`c` INT)
	 * SELECT ... FROM `wp_users`` (with or without the `AS` keyword)
	 * satisfies that anchor just as an ordinary CREATE does, while
	 * populating the table from an arbitrary source in the very same
	 * statement — no executable semicolon involved, and the object built
	 * is an entirely ordinary local table, so neither the shape check nor
	 * {@see self::table_storage_facts()}'s engine/create-options/table-type
	 * checks see anything wrong. Reading the table's actual row count is
	 * the same shift those checks already make: ask the server what it
	 * built, rather than parse what the payload asked it to build.
	 *
	 * Implementations must read an EXACT count (`SELECT COUNT(*)` or
	 * equivalent) for the CURRENT connection's own schema, using the exact
	 * $table_name given — never `information_schema.TABLES.TABLE_ROWS`.
	 * That catalogue column is documented by MySQL/MariaDB as an
	 * approximation for InnoDB, refreshed by an asynchronous background
	 * thread (`innodb_stats_auto_recalc`) rather than synchronously by the
	 * write that just happened, so a read immediately after a
	 * `CREATE ... SELECT` is not guaranteed to reflect it. Confirmed
	 * empirically against a live MariaDB server (12.3.2, this codebase's
	 * test target): in every trial run — a bare empty CREATE, and
	 * `CREATE ... SELECT` populating 5, 10, and 50,000 rows — TABLE_ROWS
	 * happened to read back exactly the same value an exact COUNT(*) did,
	 * but that same server also reports `innodb_stats_on_metadata = OFF`,
	 * meaning a read of `information_schema.TABLES` does not itself force
	 * a synchronous recalculation; the empirical match reflects the
	 * background thread's timing on a lightly-loaded test server, not a
	 * guarantee, and this containment check cannot rely on a value
	 * MySQL/MariaDB's own documentation describes as approximate.
	 *
	 * @param string $table_name The exact table name to inspect (the writer's own staged identifier, never one taken from the archive payload).
	 * @return int The exact row count.
	 * @throws RuntimeException If the count could not be read.
	 */
	public function table_row_count( string $table_name ): int;

	/**
	 * Read the destination connection's own SESSION sql_mode, as the server reports it.
	 *
	 * Used by {@see \Pontifex\Restore\DatabaseWriter} to decide whether a
	 * backslash inside a quoted literal is an escape character when scanning a
	 * chunk's statements for a hidden, executable semicolon (ADR 0019). Under
	 * `sql_mode=NO_BACKSLASH_ESCAPES` the server itself does not treat a
	 * backslash as an escape character at all, so a scan that always assumes it
	 * does can desynchronise against a value legitimately ending in a single
	 * backslash. The interpretation that matters is the one the DESTINATION
	 * SERVER will actually apply when it executes the statement — a server
	 * fact, exactly the kind of thing this design already prefers over
	 * assuming or parsing.
	 *
	 * Read once per restore and cached by the caller; implementations must not
	 * be called once per statement.
	 *
	 * @return string|null The SESSION sql_mode, e.g. "STRICT_TRANS_TABLES,NO_ZERO_DATE" (an empty string is a valid, legitimate answer — no modes set); null if it could not be read at all.
	 */
	public function sql_mode(): ?string;
}
