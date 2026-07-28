<?php
/**
 * Pontifex database writer — replays decoded SQL chunks into staging tables and cuts over atomically.
 *
 * @package Pontifex\Restore
 */

declare(strict_types=1);

namespace Pontifex\Restore;

use InvalidArgumentException;
use RuntimeException;
use Pontifex\Archive\Reader\EntryReadResult;
use Pontifex\Manifest\DatabaseAdapter;
use Pontifex\Manifest\SqlSpanScanner;

/**
 * Replays db_chunk entries into staging tables, then installs them atomically.
 *
 * The mirror of {@see \Pontifex\Manifest\DatabaseScanner}. Where the
 * scanner walked the database and captured each table's schema and
 * row data into SQL bytes, DatabaseWriter takes those SQL bytes back
 * out of the archive and executes them, one statement at a time,
 * against the destination database via a {@see DatabaseAdapter}.
 *
 * Staging (ADR 0009):
 *
 * Every table's first chunk carries `DROP TABLE IF EXISTS` +
 * `CREATE TABLE`, so replaying chunks straight onto the live tables
 * destroys each one the moment its first chunk executes — a failure
 * mid-replay would strand the database half-restored. Instead, each
 * chunk's table identifier is rewritten to a staging name
 * (`pontifexstg_` + the destination table name) as it replays, so the
 * whole restore builds beside the live tables without touching them.
 * Only after every chunk has replayed clean does
 * {@see self::commit_staged_tables()} cut over with ONE `RENAME TABLE`
 * statement — which MySQL executes atomically: any error and no
 * changes are made. A failure at any point before the cut-over is
 * cleaned up by {@see self::abort_staging()}, and the live database
 * has never been written. Transactions cannot provide this guarantee
 * (DDL implicit-commits at every table boundary); the staging + atomic
 * rename pattern is the one production schema-change tools use.
 *
 * Public API:
 *
 *  - {@see DatabaseWriter::__construct()} — takes the destination
 *    DatabaseAdapter and the optional source/destination prefixes for
 *    a cross-prefix restore.
 *  - {@see DatabaseWriter::begin_staging()} — reset for a new restore
 *    and sweep leftover staging tables from a crashed earlier run.
 *  - {@see DatabaseWriter::write_entry()} — replay one db_chunk entry
 *    into its staging table. Refuses file/directory/symlink entries
 *    (those go through {@see FileWriter}).
 *  - {@see DatabaseWriter::finalise_prefix_rewrite()} — the
 *    cross-prefix key-column rewrite, run against the staged tables.
 *  - {@see DatabaseWriter::commit_staged_tables()} — the atomic
 *    cut-over, then a best-effort drop of the parked old tables.
 *  - {@see DatabaseWriter::abort_staging()} — best-effort drop of the
 *    staging tables after a failure; the live tables were never touched.
 *
 * The writer carries per-restore state (the tables it has staged), so
 * a restore must be bracketed by begin_staging() and either
 * commit_staged_tables() or abort_staging(); {@see RestoreRunner}
 * drives that sequence. It may be reused for another restore after
 * either bracket closes.
 *
 * Statement splitting:
 *
 * SQL doesn't split cleanly on ";" in general — semicolons can
 * appear inside string literals, comments, and DELIMITER directives.
 * Pontifex's scanner-writer pair sidesteps this by producing SQL in
 * a constrained format: one statement per line, terminated with
 * ";\n", no DELIMITER directives, no embedded semicolons in
 * unescaped strings. The splitter relies on this contract.
 *
 * If a chunk's SQL violates the contract (for example, by containing
 * a string literal with an embedded ";\n"), the splitter will
 * produce broken statements and the adapter will throw. That's a
 * bug in the scanner-writer pair, not in this class.
 *
 * Verification:
 *
 *  1. The entry must be a db_chunk; other kinds are rejected at the
 *     boundary, and a db_chunk without a table name is refused (it
 *     could not be staged, so it must never replay onto live tables).
 *  2. The number of statements parsed from the payload must equal
 *     the recorded statement_count from the entry header. A
 *     mismatch indicates either a payload truncation or a bug in
 *     the writer, and is fatal.
 *  3. Every parsed statement's leading bytes must match one of a small
 *     set of sanctioned shapes — a DROP/CREATE/INSERT naming the
 *     chunk's own staged table and nothing else — before any statement
 *     executes; see {@see self::refuse_unsanctioned_statements()}. A
 *     hostile archive cannot smuggle a statement against a different
 *     table (an UPDATE against a live table, say) past a benign-looking
 *     chunk, because every statement in the chunk is checked up front.
 *  4. Each statement is executed individually. If any one throws,
 *     the rest are not attempted — and because every statement ran
 *     against staging tables, the live database is unchanged.
 *  5. Immediately after a chunk's CREATE TABLE statement has executed,
 *     and before any later statement in the same chunk runs, the
 *     object it just built is confirmed to be an ordinary local table;
 *     see {@see self::assert_staged_table_is_ordinary()}. The CREATE
 *     shape check in point 3 anchors only the statement's opening
 *     bytes and never inspects the body — a real table can
 *     legitimately carry a FOREIGN KEY reference or a PARTITION BY
 *     clause there — so the body is free to name a storage engine
 *     (MySQL's MERGE engine, say) that turns the "staged" table into a
 *     writable alias for a live one, entirely through bytes the shape
 *     check never looks at (ADR 0019). Asking the server what it
 *     actually built, rather than parsing what the payload asked it to
 *     build, is the same shift from parsing SQL text to trusting
 *     server-reported facts that already makes the shape check itself
 *     safer than extracting a statement's verb.
 *  6. That same post-CREATE moment also confirms the object holds no
 *     rows — or, for a MariaDB SEQUENCE, no more than the single state
 *     row a bare `CREATE ... SEQUENCE=1` legitimately seeds on its own
 *     (confirmed empirically against a live MariaDB server, 12.3.2).
 *     The CREATE shape check's mandatory `" ("` anchor requires a
 *     column list, but says nothing about what follows the list's
 *     closing paren: `CREATE TABLE `<staged>` (`c` INT) SELECT ... FROM
 *     `wp_users`` — with or without the `AS` keyword, and with no
 *     executable semicolon anywhere in the statement — still satisfies
 *     that anchor while populating the table from an arbitrary source
 *     in the very same statement, entirely through bytes the shape
 *     check never inspects; the object it builds is an entirely
 *     ordinary local table, so point 5's engine/create-options/
 *     table-type checks see nothing wrong with it either. Reading the
 *     table's actual row count via {@see DatabaseAdapter::table_row_count()}
 *     is the same server-fact shift point 5 already makes, applied to
 *     the one remaining question neither check answers: not just what
 *     kind of table did the CREATE build, but did it already hold data
 *     the moment it existed (ADR 0019).
 */
final class DatabaseWriter {

	/**
	 * The physical prefix staged tables carry until the atomic cut-over.
	 *
	 * Fixed rather than per-run: the single-runner lock guarantees no
	 * concurrent restore, and a fixed name lets a crashed run's leftovers be
	 * recognised and swept by {@see self::begin_staging()}.
	 *
	 * @var string
	 */
	public const STAGING_PREFIX = 'pontifexstg_';

	/**
	 * The physical prefix a replaced live table is parked under during the cut-over.
	 *
	 * The parked copies exist only between the RENAME and the best-effort drop
	 * that follows it; a leftover is inert and swept on the next restore.
	 *
	 * @var string
	 */
	public const OLD_PREFIX = 'pontifexold_';

	/**
	 * MySQL's maximum table-name length, in characters.
	 *
	 * @var int
	 */
	private const MAX_TABLE_NAME_LENGTH = 64;

	/**
	 * The statement delimiter used by Pontifex's SQL emitter.
	 *
	 * @var string
	 */
	private const STATEMENT_DELIMITER = ";\n";

	/**
	 * Regex fragment matching one backtick-quoted column identifier.
	 *
	 * A column name may contain any byte except a backtick, or a doubled
	 * backtick escaping a literal one — the same escaping
	 * {@see self::escape_identifier()} applies, so a real column named
	 * (for example) `wei``rd` is matched correctly.
	 *
	 * The run-then-escape form with possessive quantifiers is deliberate and
	 * load-bearing. The obvious alternation `(?:[^`]|``)+` costs the regex
	 * engine one backtrackable stack frame per matched CHARACTER, so a table
	 * with a wide column list — around eight thousand bytes of identifier text,
	 * which a plugin table with a few hundred columns reaches easily — exhausts
	 * the JIT stack and makes preg_match fail. Matching whole runs, and
	 * refusing to keep backtracking positions for them, keeps the cost linear.
	 *
	 * @var string
	 */
	private const COLUMN_IDENTIFIER_PATTERN = '`[^`]*+(?:``[^`]*+)*+`';

	/**
	 * The database adapter that executes individual statements.
	 *
	 * @var DatabaseAdapter
	 */
	private DatabaseAdapter $adapter;

	/**
	 * The table prefix recorded in the archive, or '' when none is to be rewritten.
	 *
	 * @var string
	 */
	private string $source_prefix;

	/**
	 * The destination site's table prefix, or '' when no rewrite is to be done.
	 *
	 * @var string
	 */
	private string $dest_prefix;

	/**
	 * Destination names of every table staged so far this restore, in first-seen order.
	 *
	 * Keys are the destination table names; the value is unused. Recorded
	 * before a table's first statement executes, so {@see self::abort_staging()}
	 * covers a table whose creation failed half-way.
	 *
	 * @var array<string, true>
	 */
	private array $staged_tables = array();

	/**
	 * Construct a DatabaseWriter that executes statements via $adapter.
	 *
	 * When the source and destination prefixes are both non-empty and differ, the
	 * writer additionally rewrites each chunk's table identifier to the destination
	 * prefix as it replays it, and {@see self::finalise_prefix_rewrite()} rewrites
	 * the prefix embedded in the options/usermeta key columns once the replay is
	 * complete (ADR 0008). When they are equal or either is empty, the destination
	 * name is the archive's own and only the staging prefix is applied.
	 *
	 * @param DatabaseAdapter $adapter       The destination database adapter.
	 * @param string          $source_prefix Optional. The prefix recorded in the archive; default '' (no rewrite).
	 * @param string          $dest_prefix   Optional. The destination site's prefix; default '' (no rewrite).
	 */
	public function __construct( DatabaseAdapter $adapter, string $source_prefix = '', string $dest_prefix = '' ) {
		$this->adapter       = $adapter;
		$this->source_prefix = $source_prefix;
		$this->dest_prefix   = $dest_prefix;
	}

	/**
	 * Whether this restore switched the connection charset to the archive's.
	 *
	 * @var bool
	 */
	private bool $replay_charset_set = false;

	/**
	 * Whether a backslash inside a quoted literal is an escape character, for {@see \Pontifex\Manifest\SqlSpanScanner::has_executable_semicolon()}.
	 *
	 * Read once per restore, in {@see self::begin_staging()}, from the
	 * destination connection's own SESSION sql_mode — the interpretation that
	 * matters is the one the DESTINATION SERVER will actually apply when it
	 * executes the statement, which is a server fact (ADR 0019), not
	 * something this code should assume. True unless the reported sql_mode
	 * contains `NO_BACKSLASH_ESCAPES`.
	 *
	 * Defaults to true (the ordinary MySQL/MariaDB default) so that a test
	 * exercising {@see self::write_entry()} alone, without first bracketing
	 * it in {@see self::begin_staging()}, keeps today's behaviour; production
	 * replay always goes through begin_staging() first (see the class
	 * docblock), which overwrites this with the real server fact before any
	 * statement is scanned.
	 *
	 * @var bool
	 */
	private bool $backslash_is_escape = true;

	/**
	 * Reset for a new restore and sweep leftovers from a crashed earlier run.
	 *
	 * A restore that died without reaching commit or abort leaves
	 * `pontifexstg_*` (and, in a narrow window, `pontifexold_*`) tables behind.
	 * They are inert but occupy disk and would collide with this run's staging
	 * names, so they are dropped here. The sweep is best-effort: a table that
	 * cannot be listed or dropped is left for a later run, never a reason to
	 * refuse a restore.
	 *
	 * When the archive's character set is given, the connection is switched to
	 * it for the replay's duration (and back on commit or abort), so the SQL's
	 * bytes are interpreted as they were captured — without this, multibyte
	 * content restored over a differently-configured connection is silently
	 * transcoded to mojibake. The charset comes from the archive and is
	 * untrusted, so a malformed one refuses the restore before any write.
	 *
	 * Also reads the destination connection's own SESSION sql_mode once, to
	 * decide for the whole restore whether a backslash inside a quoted
	 * literal is an escape character (see {@see self::$backslash_is_escape}).
	 * When the mode cannot be read at all, this chooses the STRICT
	 * interpretation — `NO_BACKSLASH_ESCAPES` assumed active, so a backslash
	 * is treated as an ordinary character, never an escape — rather than the
	 * permissive one, because the two wrong guesses are not equally
	 * dangerous: wrongly assuming a backslash escapes when the server does
	 * not honour that can make the scan believe it remains inside a quoted
	 * literal past the point the server itself would have closed it,
	 * masking genuinely executable bytes — including a stacked statement's
	 * own semicolon — as opaque literal content (a bypass); wrongly assuming
	 * a backslash does NOT escape when the server does only closes the
	 * scan's belief in a literal earlier than the server would, which can
	 * over-refuse a legitimate statement but never masks executable bytes as
	 * inert.
	 *
	 * Known limit — the connection character set (ADR 0019): {@see \Pontifex\Manifest\SqlSpanScanner}
	 * reads sql_mode as a server fact, but it does NOT read the destination
	 * connection's own character set, and it assumes that charset is
	 * single-byte-safe or UTF-8 — one where a multibyte sequence can never
	 * contain the byte 0x5C ('\') or a quote byte as a TRAILING byte of a
	 * valid character, so those bytes are always read correctly on their
	 * own. That assumption does not hold for every charset MySQL/MariaDB
	 * support: under a legacy multibyte charset such as `gbk`, a two-byte
	 * character can legitimately end in a byte that reads as `\` or `'` to a
	 * byte-at-a-time scan, letting a crafted byte pair inside a quoted
	 * literal swallow the character that should have closed or escaped it
	 * and desynchronise the scan from what the server will actually parse.
	 * $source_charset is untrusted, from the archive, and drives the
	 * `SET NAMES` this method issues before any chunk is scanned, so a
	 * hostile archive can choose the very charset its own bytes are parsed
	 * under. This is a real gap in what the scan's model of "one statement"
	 * can prove, not a hypothetical: it was found by deliberately
	 * constructing such a byte pair under `SET NAMES gbk` and observing the
	 * scan desynchronise exactly as described. It is NOT currently
	 * exploitable on this stack, for reasons independent of the scan: wpdb's
	 * own invalid-byte-sequence validation rejected the crafted payload
	 * before executing it, the restore failed loudly with the canary row
	 * untouched and no staging residue, and the destination driver only
	 * ever executes one statement per call regardless. Those are the
	 * remaining protections against this gap, not the scan itself — a
	 * multibyte-aware version (tracking character boundaries under the
	 * archive's own charset, not just bytes) would close it properly, but is
	 * deliberately not attempted here: recording the limit honestly is worth
	 * more than a scanner whose completeness cannot actually be demonstrated
	 * for every charset MySQL/MariaDB support.
	 *
	 * @param string $source_charset Optional. The archive's database character set (from provenance); '' skips the charset switch.
	 * @return void
	 * @throws RuntimeException If the charset is malformed, or the server refuses it.
	 */
	public function begin_staging( string $source_charset = '' ): void {
		$this->staged_tables = array();
		if ( '' !== $source_charset ) {
			if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/', $source_charset ) ) {
				throw new RuntimeException(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $source_charset is reported verbatim for diagnostic context; exception path, not HTML output.
					sprintf( 'DatabaseWriter: the archive declares a malformed database character set "%s"; refusing to restore it.', $source_charset )
				);
			}
			$this->adapter->set_session_charset( $source_charset );
			$this->replay_charset_set = true;
		}
		$sql_mode                  = $this->adapter->sql_mode();
		$this->backslash_is_escape = null === $sql_mode ? false : ! str_contains( $sql_mode, 'NO_BACKSLASH_ESCAPES' );
		foreach ( array( self::STAGING_PREFIX, self::OLD_PREFIX ) as $prefix ) {
			foreach ( $this->adapter->list_tables_by_prefix( $prefix ) as $leftover ) {
				$this->drop_table_best_effort( $leftover );
			}
		}
	}

	/**
	 * Hand the connection back its own charset once the replay is over.
	 *
	 * @return void
	 */
	private function restore_replay_charset(): void {
		if ( ! $this->replay_charset_set ) {
			return;
		}
		$this->replay_charset_set = false;
		$this->adapter->restore_session_charset();
	}

	/**
	 * Replay one db_chunk entry into its staging table.
	 *
	 * Resolves the chunk's destination table name (applying the cross-prefix
	 * rewrite when active), refuses a name that would not fit MySQL's limit once
	 * staged, rewrites the payload's table identifier to the staging name, then
	 * splits the payload into individual SQL statements, verifies the statement
	 * count matches the recorded header, and executes each statement in order
	 * against the adapter. The live table of the same name is never touched.
	 *
	 * @param EntryReadResult $result A decoded entry whose header is a db_chunk.
	 * @throws InvalidArgumentException If $result is not a db_chunk entry.
	 * @throws RuntimeException         If the chunk has no table name, the staged name would be over-long, statement_count disagrees with the parsed count, a statement fails the shape/containment checks, the object a CREATE built is not an ordinary local table or already held rows the moment it existed (ADR 0019), or any adapter call fails.
	 */
	public function write_entry( EntryReadResult $result ): void {
		$header = $result->header();

		if ( ! $header->is_db_chunk() ) {
			throw new InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $header->kind() is a validated KIND_* constant; reported verbatim for diagnostic context; exception path, not HTML output.
				sprintf( 'DatabaseWriter: expected a db_chunk entry; got kind "%s". File/directory/symlink entries belong to FileWriter.', $header->kind() )
			);
		}

		$source_table = (string) $header->table_name();
		if ( '' === $source_table ) {
			throw new RuntimeException(
				'DatabaseWriter: db_chunk entry carries no table name, so it cannot be staged; refusing to replay it against the live database.'
			);
		}

		$dest_table = $this->destination_table_name( $source_table );
		$this->refuse_over_long_staged_name( $dest_table );

		// Recorded before execution so abort_staging() also removes a table
		// whose creation failed half-way through its first chunk.
		$this->staged_tables[ $dest_table ] = true;

		$staged_table   = self::STAGING_PREFIX . $dest_table;
		$payload        = $this->rewrite_table_identifier( $source_table, $staged_table, $result->payload() );
		$statements     = self::split_statements( $payload );
		$declared_count = (int) $header->statement_count();
		$parsed_count   = count( $statements );

		if ( $declared_count !== $parsed_count ) {
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $declared_count and $parsed_count are integers reported verbatim for diagnostic context; exception path, not HTML output.
				sprintf( 'DatabaseWriter: statement_count mismatch — header declared %d, payload contains %d.', $declared_count, $parsed_count )
			);
		}

		$this->refuse_unsanctioned_statements( $statements, $source_table, $staged_table, $header->chunk_index() );

		// Only the CREATE for THIS chunk's own staged table can match this exact
		// prefix — refuse_unsanctioned_statements() has already confirmed every
		// statement in $statements does, so a match here is never a false trigger
		// on an unrelated statement.
		$create_prefix = 'CREATE TABLE `' . self::escape_identifier( $staged_table ) . '` (';

		foreach ( $statements as $statement ) {
			$this->adapter->execute_sql( $statement );
			if ( str_starts_with( $statement, $create_prefix ) ) {
				// Runs immediately after execution and before any later statement in
				// this chunk (an INSERT that would populate whatever the CREATE just
				// built) — see the class docblock's Verification point 5 and ADR 0019.
				$this->assert_staged_table_is_ordinary( $staged_table );
			}
		}
	}

	/**
	 * Refuse a chunk whose statements do not match a sanctioned shape.
	 *
	 * Runs on the EXACT array {@see self::write_entry()} then executes — never
	 * a re-split or re-normalised copy, because a validator that parses the
	 * payload differently from the executor is the standard way to defeat this
	 * kind of guard. Every statement is checked before any one of them
	 * executes, so a hostile chunk is refused whole: a benign-looking first
	 * statement never buys a later one a free pass.
	 *
	 * Only a statement's LEADING bytes are inspected, anchored at offset 0,
	 * byte-exact and case-sensitive. Real row data can legitimately contain
	 * text that reads like SQL (a post's content is free-form), so scanning a
	 * whole statement for forbidden words would refuse ordinary backups —
	 * the shape check never looks past the point it needs to. A statement
	 * against the chunk's own staged table may only be:
	 *
	 *  - the exact `DROP TABLE IF EXISTS` for the staged table, and nothing
	 *    else on the line;
	 *  - a `CREATE TABLE` for the staged table, immediately followed by
	 *    `" ("`. The body is not inspected — it is `SHOW CREATE TABLE`
	 *    output copied verbatim, so it can legitimately span real newlines
	 *    and carry a `FOREIGN KEY` reference to another table;
	 *  - an `INSERT INTO` the staged table, with or without a column list,
	 *    immediately followed by `"VALUES ("`. This anchor refuses the
	 *    `INSERT ... SELECT` and `INSERT ... SET` statement FORMS — an
	 *    `INSERT` opening with `SELECT` or `SET` instead of `VALUES (` never
	 *    matches this shape — but it does not, and cannot, inspect what a
	 *    matched `VALUES` expression itself contains: a scalar subquery
	 *    inside `VALUES` (for example `VALUES ((SELECT user_pass FROM
	 *    `wp_users` ORDER BY ID LIMIT 1))`) still matches this shape and
	 *    still executes, reading another table's data into the staged
	 *    table. That is a recorded residual risk this check does not close,
	 *    not a gap in what it claims to do; see ADR 0019.
	 *
	 * A `CREATE VIEW` naming the staged table is refused with its own
	 * message: a view could later be written through by an ordinary-looking
	 * statement that writes to the live table it names, and Pontifex never
	 * restores views. Anything else is refused with a generic message.
	 *
	 * @param string[] $statements     The statements about to be executed, in order.
	 * @param string   $declared_table The chunk's own table name, from the entry header, for the refusal message only.
	 * @param string   $staged_table   The staging-prefixed table name every statement must target.
	 * @param int|null $chunk_index    The chunk's index, for the refusal message; null when the entry carries none.
	 * @return void
	 * @throws RuntimeException If any statement does not match a sanctioned shape.
	 */
	private function refuse_unsanctioned_statements( array $statements, string $declared_table, string $staged_table, ?int $chunk_index ): void {
		$quoted         = '`' . self::escape_identifier( $staged_table ) . '`';
		$quoted_pattern = preg_quote( $quoted, '/' );

		$drop_shape          = 'DROP TABLE IF EXISTS ' . $quoted;
		$create_shape        = 'CREATE TABLE ' . $quoted . ' (';
		$insert_no_columns   = 'INSERT INTO ' . $quoted . ' VALUES (';
		$insert_with_columns = '/\AINSERT INTO ' . $quoted_pattern . ' \(' . self::COLUMN_IDENTIFIER_PATTERN . '(?:, ' . self::COLUMN_IDENTIFIER_PATTERN . ')*\) VALUES \(/';

		foreach ( $statements as $index => $statement ) {
			$position = $index + 1;
			$location = null === $chunk_index
				? sprintf( 'an unknown chunk, statement %d', $position )
				: sprintf( 'chunk %d, statement %d', $chunk_index, $position );

			// The shapes anchor a statement's OPENING bytes; they say nothing
			// about what follows. A payload may therefore carry a sanctioned
			// opening and then a second statement after a semicolon — the split
			// on ";\n" leaves "; " untouched — so the opening check alone would
			// hand "INSERT INTO `staged` VALUES (1); UPDATE `wp_users` ..." to
			// the database intact. Refusing an executable semicolon closes that,
			// and closes it here rather than relying on the driver to reject
			// stacked statements, which is a behaviour this code neither states
			// nor controls.
			if ( SqlSpanScanner::has_executable_semicolon( $statement, $this->backslash_is_escape ) ) {
				throw new RuntimeException(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $declared_table and $location are reported verbatim for diagnostic context, never the statement's own bytes; exception path, not HTML output.
					sprintf( 'DatabaseWriter: table "%s" (%s) carries more than one statement; refusing to replay it against the live database.', $declared_table, $location )
				);
			}

			if ( $drop_shape === $statement ) {
				continue;
			}
			if ( str_starts_with( $statement, $create_shape ) ) {
				continue;
			}
			if ( str_starts_with( $statement, $insert_no_columns ) ) {
				continue;
			}

			// A regex ENGINE failure must never be read as "this shape did not
			// match". preg_match returns false on failure and 0 on no-match, and
			// conflating the two would condemn a perfectly valid archive because
			// of a limit on the host — refusing the user's backup and blaming
			// their file. Fail loudly and truthfully instead.
			$insert_matched = preg_match( $insert_with_columns, $statement );
			if ( false === $insert_matched ) {
				throw new RuntimeException(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The regex engine's own error text and the table name are reported for diagnostic context, never the statement's own bytes; exception path, not HTML output.
					sprintf( 'DatabaseWriter: could not check the statements for table "%s" because the regular-expression engine failed (%s); refusing to replay them against the live database.', $declared_table, preg_last_error_msg() )
				);
			}
			if ( 1 === $insert_matched ) {
				continue;
			}

			if ( self::declares_a_view( $statement ) ) {
				throw new RuntimeException(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $declared_table and $location are reported verbatim for diagnostic context, never the statement's own bytes; exception path, not HTML output.
					sprintf( 'DatabaseWriter: table "%s" (%s) declares a CREATE VIEW, which Pontifex does not restore; refusing to replay it against the live database.', $declared_table, $location )
				);
			}

			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $declared_table and $location are reported verbatim for diagnostic context, never the statement's own bytes; exception path, not HTML output.
				sprintf( 'DatabaseWriter: table "%s" (%s) contains a statement that does not match a sanctioned shape; refusing to replay it against the live database.', $declared_table, $location )
			);
		}
	}

	/**
	 * Storage engines an ordinary local table may report (ADR 0019).
	 *
	 * These hold only their own rows, on local disk, and expose no
	 * cross-table, cross-connection, or file-path clause: the row-store
	 * engines MySQL/MariaDB ship built in (InnoDB, MyISAM, Aria, MEMORY),
	 * plus the ordinary LOCAL row-store engines a real WordPress install can
	 * be running as a compiled-in storage-engine plugin — MyRocks/RocksDB,
	 * TokuDB, and ColumnStore all hold their own rows on local disk exactly
	 * like the built-in four, so refusing them would refuse a site
	 * restoring its own, entirely legitimate backup, with no route forward
	 * for the operator; a false refusal like that is worse than the
	 * vulnerability this list defends against. Compared case-insensitively
	 * against {@see self::assert_staged_table_is_ordinary()}'s lower-cased
	 * reading of the server's own ENGINE column: MySQL/MariaDB report engine
	 * names in mixed case ("InnoDB", "MyISAM"), and a hostile CREATE could
	 * deliberately vary case to try to dodge an exact-case check. Every
	 * other engine — including MRG_MyISAM (a MERGE table is a writable
	 * alias for OTHER tables, not local storage of its own), FEDERATED,
	 * CONNECT, and SPIDER (all reach a remote server), and
	 * CSV/ARCHIVE/BLACKHOLE (plain-file or no-storage engines) — is refused.
	 *
	 * This is an allow-list, never a deny-list (ADR 0019's central design
	 * choice), so an engine that is not one of the specifically-named
	 * cross-table/remote/file engines above is refused too, deliberately,
	 * the moment it is not on this list. A deny-list can only ever enumerate
	 * the engines already known to be dangerous; a storage engine not yet
	 * considered here — a future MySQL/MariaDB addition, or a third-party
	 * engine plugin nobody has reviewed — would sail straight past a
	 * deny-list with no warning at all. An allow-list instead requires a new
	 * engine to be positively reviewed and added here, with the same
	 * "ordinary local row-store, no cross-table/remote/file-path clause"
	 * standard the four named above meet, before a restore may use it — the
	 * safer default when the alternative is silently trusting an unknown.
	 *
	 * @var string[]
	 */
	private const ALLOWED_ENGINES = array( 'innodb', 'myisam', 'aria', 'memory', 'rocksdb', 'tokudb', 'columnstore' );

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
	private const ALLOWED_TABLE_TYPES = array( 'BASE TABLE', 'SYSTEM VERSIONED', 'SEQUENCE' );

	/**
	 * CREATE_OPTIONS fragments that mark a table as reading or writing
	 * through something other than its own ordinary local storage (ADR
	 * 0019): a MERGE table's UNION of other tables, a FEDERATED/CONNECT
	 * table's remote CONNECTION, or a table redirected to an arbitrary
	 * filesystem path via DATA DIRECTORY / INDEX DIRECTORY. Matched as plain
	 * substrings against a CREATE_OPTIONS value {@see self::normalise_create_options()}
	 * has already folded to lower case with every underscore and run of
	 * whitespace collapsed to a single space, so this list only needs the
	 * words themselves — not "union=" or "data_directory=" — to match every
	 * spacing and casing variant a server might report. Confirmed empirically
	 * against a live MariaDB server (see the class docblock and ADR 0019):
	 * `DATA DIRECTORY='/tmp/'` and `INDEX DIRECTORY='/tmp/'` are what that
	 * server actually reports, verbatim, for any engine that accepts the
	 * clause — including InnoDB and MyISAM, both on the engine allow-list
	 * above, so this check is the ONLY thing standing between a
	 * DATA/INDEX DIRECTORY attack and an otherwise-ordinary engine. That
	 * same server left CREATE_OPTIONS empty for a MERGE table's UNION — the
	 * engine allow-list is what actually refuses MRG_MyISAM there — but
	 * "union"/"connection" are kept here too as defence in depth for any
	 * server that does report them.
	 *
	 * @var string[]
	 */
	private const FORBIDDEN_CREATE_OPTION_FRAGMENTS = array( 'union', 'connection', 'data directory', 'index directory' );

	/**
	 * Refuse a staged table whose actual storage facts are not an ordinary local table.
	 *
	 * Called by {@see self::write_entry()} immediately after a chunk's CREATE
	 * TABLE statement has executed, and before any later statement in the
	 * same chunk (an INSERT that would populate whatever the CREATE just
	 * built) runs. The CREATE shape check in
	 * {@see self::refuse_unsanctioned_statements()} anchors only the
	 * statement's opening bytes — up to and including the opening `" ("` —
	 * and deliberately never inspects the body, because the body is
	 * legitimately `SHOW CREATE TABLE` output that can carry a FOREIGN KEY
	 * reference, a CHECK constraint, or a PARTITION BY clause. That silence
	 * is exactly what a storage-engine clause can exploit: naming MySQL's
	 * MERGE engine (or FEDERATED/CONNECT, where the server compiles them in)
	 * in the body turns the staged identifier into a writable alias for a
	 * table the chunk never declared, a connection to a remote server, or a
	 * local file — entirely through bytes the shape check never looks at,
	 * and entirely compatible with a subsequent INSERT of the same staged
	 * identifier that satisfies the ordinary shape check on its own merits.
	 * Parsing the CREATE body to catch this would be the same losing game
	 * the shape check already avoids for the rest of the statement — every
	 * defence tried against parsing a statement's VERB was defeated by some
	 * comment or casing variant (ADR 0019), and a body-clause deny-list
	 * would face the same fate against an engine or option not yet
	 * considered. Instead this asks the server what it actually built and
	 * allow-lists the answer: the same shift from parsing SQL text to
	 * trusting server-reported facts that already makes shape anchoring
	 * itself safer than extracting a statement's verb.
	 *
	 * A table refused here has never been written to (it was just CREATEd,
	 * empty, and no INSERT for it has run) and is dropped, along with every
	 * other table this restore staged, by {@see RestoreRunner}'s
	 * catch(Throwable) calling {@see self::abort_staging()} — including when
	 * the "table" is a MERGE alias for a live one: dropping a MERGE table
	 * removes only its own definition, never the underlying tables it
	 * unions, so the live database stays untouched.
	 *
	 * @param string $staged_table The staging-prefixed table name just created.
	 * @return void
	 * @throws RuntimeException If the table's storage facts could not be read at all, its TABLE_TYPE is not on the allow-list, its engine is not on the allow-list, its CREATE_OPTIONS names a unioned table list, a remote connection, or a data/index directory, a partitioned table names a data/index directory on one of its partitions, its row count could not be read, or it already held rows (more than the single state row a MariaDB SEQUENCE's own CREATE legitimately seeds) the moment it existed.
	 */
	private function assert_staged_table_is_ordinary( string $staged_table ): void {
		$facts = $this->adapter->table_storage_facts( $staged_table );

		if ( null === $facts ) {
			throw new RuntimeException(
				'DatabaseWriter: could not read the storage facts for a just-created staged table; refusing to continue this restore.'
			);
		}

		if ( ! in_array( $facts['table_type'], self::ALLOWED_TABLE_TYPES, true ) ) {
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $facts['table_type'] is the server's own information_schema.TABLES.TABLE_TYPE value, reported for diagnostic context, never the statement's own bytes; exception path, not HTML output.
				sprintf( 'DatabaseWriter: a staged CREATE built a "%s", not an ordinary table; refusing to replay it against the live database.', $facts['table_type'] )
			);
		}

		$engine = strtolower( $facts['engine'] );
		if ( ! in_array( $engine, self::ALLOWED_ENGINES, true ) ) {
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $facts['engine'] is the server's own information_schema.TABLES.ENGINE value (a storage-engine name), never the statement's own bytes; exception path, not HTML output.
				sprintf( 'DatabaseWriter: a staged CREATE built a table using the "%s" storage engine, which is not an ordinary local table; Pontifex restores only ordinary local tables — never one that reaches other tables, a remote server, or an arbitrary file — so this table cannot be restored; refusing to replay it against the live database.', $facts['engine'] )
			);
		}

		$normalised_options = self::normalise_create_options( $facts['create_options'] );
		foreach ( self::FORBIDDEN_CREATE_OPTION_FRAGMENTS as $fragment ) {
			if ( str_contains( $normalised_options, $fragment ) ) {
				throw new RuntimeException(
					'DatabaseWriter: a staged CREATE carries table options that point outside its own local storage; refusing to replay it against the live database.'
				);
			}
		}

		// CREATE_OPTIONS reports only the single word "partitioned" for a
		// partitioned table, with no per-partition detail — a DATA DIRECTORY or
		// INDEX DIRECTORY written on an individual PARTITION clause, rather than
		// on the table itself, is invisible to the check above and must be read
		// a second way; see DatabaseAdapter::partition_storage_directory_present().
		if ( str_contains( $normalised_options, 'partitioned' ) && $this->adapter->partition_storage_directory_present( $staged_table ) ) {
			throw new RuntimeException(
				'DatabaseWriter: a staged CREATE names a DATA DIRECTORY or INDEX DIRECTORY on one of its partitions; refusing to replay it against the live database.'
			);
		}

		// A bare `CREATE ... SEQUENCE=1` legitimately seeds its own single state
		// row as an intrinsic part of the CREATE itself — confirmed empirically
		// against a live MariaDB server (12.3.2) — so a SEQUENCE may hold exactly
		// one row here; every other allowed table_type must hold none. Read AFTER
		// every check above has already passed, never before: an engine this
		// class is about to refuse anyway (FEDERATED/CONNECT, reaching a remote
		// server) must never have a query issued against it first.
		$max_seeded_rows = ( 'SEQUENCE' === $facts['table_type'] ) ? 1 : 0;
		if ( $this->adapter->table_row_count( $staged_table ) > $max_seeded_rows ) {
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $staged_table is the identifier this class composed itself, reported for diagnostic context, never the statement's own bytes; exception path, not HTML output.
				sprintf( 'DatabaseWriter: a staged CREATE for table "%s" produced rows; refusing to replay it against the live database.', $staged_table )
			);
		}
	}

	/**
	 * Normalise a CREATE_OPTIONS value before matching it against the forbidden-fragment list.
	 *
	 * MySQL/MariaDB report this column with varying case and spacing
	 * depending on server and version — this codebase has directly observed
	 * `DATA DIRECTORY='/tmp/'` (upper case, a literal space between the two
	 * words) from a live MariaDB server, and the wider MySQL/MariaDB
	 * ecosystem is documented elsewhere to also use forms such as
	 * `data_directory=/tmp` (lower case, an underscore) — see the class
	 * docblock. Folding to lower case and collapsing every underscore and
	 * run of whitespace to a single space makes
	 * {@see self::FORBIDDEN_CREATE_OPTION_FRAGMENTS}'s plain-word matches hit
	 * regardless of which form the server used, without the fragment list
	 * itself needing to enumerate every spacing variant.
	 *
	 * @param string $create_options The raw value from information_schema.TABLES.CREATE_OPTIONS.
	 * @return string The normalised value.
	 */
	private static function normalise_create_options( string $create_options ): string {
		$lower     = strtolower( $create_options );
		$unified   = str_replace( '_', ' ', $lower );
		$collapsed = preg_replace( '/\s+/', ' ', $unified );
		// preg_replace returns null only on a PCRE engine failure; falling back to
		// the unified-but-uncollapsed string still matches every fragment above
		// correctly (collapsing whitespace only removes false NEGATIVES from
		// repeated spaces, never introduces a false negative of its own), so a
		// transient engine failure here degrades safely rather than throwing.
		return null === $collapsed ? $unified : $collapsed;
	}

	/**
	 * Whether a refused statement is a database view, for the refusal message.
	 *
	 * Only reached once a statement has already failed every sanctioned shape,
	 * so this decides the wording rather than the verdict. It is deliberately
	 * not a match on a leading "CREATE VIEW": a real server does not emit that
	 * form. It writes the view's full definition —
	 * `CREATE ALGORITHM=UNDEFINED DEFINER=`someone`@`somewhere` SQL SECURITY
	 * DEFINER VIEW `name` AS ...` — so a check for the literal opening would
	 * never fire on an archive taken from an actual site, and the operator
	 * would be told only that something unrecognised was found.
	 *
	 * @param string $statement One statement that has already been refused.
	 * @return bool True if the statement defines a view.
	 */
	private static function declares_a_view( string $statement ): bool {
		if ( ! str_starts_with( $statement, 'CREATE ' ) ) {
			return false;
		}
		return str_contains( $statement, ' VIEW `' );
	}

	/**
	 * Rewrite the prefix embedded in key columns, once every chunk has been replayed.
	 *
	 * The companion to the per-chunk table-identifier rewrite: renaming the tables
	 * does not touch the prefix that also lives in `{prefix}options.option_name` and
	 * the `{prefix}usermeta.meta_key` rows, so this finalises the cross-prefix restore
	 * by rewriting those key columns through the adapter — against the *staged*
	 * copies, before the cut-over, so the live tables are never written. A no-op
	 * unless a prefix rewrite is active. Call it after the restore walk has written
	 * every db_chunk and before {@see self::commit_staged_tables()}.
	 *
	 * @return void
	 * @throws RuntimeException If a rewrite statement fails to execute.
	 */
	public function finalise_prefix_rewrite(): void {
		if ( ! $this->prefix_rewrite_active() ) {
			return;
		}
		$this->adapter->rewrite_prefix_keys( $this->source_prefix, $this->dest_prefix, self::STAGING_PREFIX );
	}

	/**
	 * Cut the staged tables over atomically, then drop the parked old tables.
	 *
	 * Builds ONE `RENAME TABLE` statement covering every staged table: a table
	 * that exists live is moved aside (`T → pontifexold_T, pontifexstg_T → T`),
	 * a table new to the destination is simply installed
	 * (`pontifexstg_T → T`). MySQL executes the whole statement atomically —
	 * no other session sees an intermediate mix, and if any part fails no
	 * changes are made, leaving the live database exactly as it was (the
	 * caller then aborts staging). After a successful cut-over the parked
	 * `pontifexold_*` copies are dropped best-effort; a leftover is inert and
	 * swept on the next restore. A no-op when nothing was staged.
	 *
	 * @return void
	 * @throws RuntimeException If the cut-over RENAME fails (the live database is unchanged), or a pre-swap DROP of a stale parked table fails.
	 */
	public function commit_staged_tables(): void {
		if ( array() === $this->staged_tables ) {
			// Nothing to cut over (a database-less archive), but a replay charset
			// switched in begin_staging() must still be handed back.
			$this->restore_replay_charset();
			return;
		}

		$operations = array();
		$old_tables = array();
		foreach ( array_keys( $this->staged_tables ) as $dest_table ) {
			$staged = self::STAGING_PREFIX . $dest_table;
			$old    = self::OLD_PREFIX . $dest_table;
			if ( $this->adapter->table_exists( $dest_table ) ) {
				// Free the parking name first: begin_staging() swept leftovers, but
				// this run must not fail its atomic swap over a racing artefact.
				$this->adapter->execute_sql( 'DROP TABLE IF EXISTS `' . self::escape_identifier( $old ) . '`' );
				$operations[] = '`' . self::escape_identifier( $dest_table ) . '` TO `' . self::escape_identifier( $old ) . '`';
				$old_tables[] = $old;
			}
			$operations[] = '`' . self::escape_identifier( $staged ) . '` TO `' . self::escape_identifier( $dest_table ) . '`';
		}

		$this->adapter->execute_sql( 'RENAME TABLE ' . implode( ', ', $operations ) );

		// The database is now entirely the restored one; nothing below may undo
		// that, so the staged bookkeeping is cleared before the best-effort drops.
		$this->staged_tables = array();

		foreach ( $old_tables as $old ) {
			$this->drop_table_best_effort( $old );
		}

		$this->restore_replay_charset();
	}

	/**
	 * Drop the staging tables after a failed restore.
	 *
	 * Every staged table is removed best-effort; the live tables were never
	 * written, so after this the database carries no trace of the failed
	 * restore (a table that cannot be dropped is inert and swept by the next
	 * run's {@see self::begin_staging()}). Safe to call when nothing was staged.
	 *
	 * @return void
	 */
	public function abort_staging(): void {
		foreach ( array_keys( $this->staged_tables ) as $dest_table ) {
			$this->drop_table_best_effort( self::STAGING_PREFIX . $dest_table );
		}
		$this->staged_tables = array();
		$this->restore_replay_charset();
	}

	/**
	 * Resolve a chunk's destination table name, applying the cross-prefix rewrite.
	 *
	 * @param string $source_table The table name recorded in the entry header.
	 * @return string The name the table will carry on the destination site.
	 */
	private function destination_table_name( string $source_table ): string {
		if ( ! $this->prefix_rewrite_active() || ! str_starts_with( $source_table, $this->source_prefix ) ) {
			return $source_table;
		}
		return $this->dest_prefix . substr( $source_table, strlen( $this->source_prefix ) );
	}

	/**
	 * Refuse a table whose staged or parked name would exceed MySQL's limit.
	 *
	 * MySQL caps table names at 64 characters; a destination name long enough
	 * that `pontifexstg_`/`pontifexold_` + name overflows the cap would fail at
	 * CREATE or RENAME time with an opaque server error, so it is refused here
	 * with the table named. Fails closed before the table's first statement
	 * executes — and only staging tables would have been written in any case.
	 *
	 * @param string $dest_table The destination table name to check.
	 * @return void
	 * @throws RuntimeException If a prefixed form of the name would be over-long.
	 */
	private function refuse_over_long_staged_name( string $dest_table ): void {
		$longest_prefix = max( strlen( self::STAGING_PREFIX ), strlen( self::OLD_PREFIX ) );
		if ( ( $longest_prefix + strlen( $dest_table ) ) > self::MAX_TABLE_NAME_LENGTH ) {
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $dest_table is reported verbatim for diagnostic context; exception path, not HTML output.
				sprintf( 'DatabaseWriter: table "%s" cannot be restored atomically — its staged name would exceed MySQL\'s %d-character table-name limit.', $dest_table, self::MAX_TABLE_NAME_LENGTH )
			);
		}
	}

	/**
	 * Drop one table, swallowing failure.
	 *
	 * Used only for housekeeping drops (leftover sweeps, parked old tables,
	 * aborted staging) where a failed drop leaves an inert table that a later
	 * run sweeps — never for a step whose failure must abort the restore.
	 *
	 * @param string $table_name The table to drop.
	 * @return void
	 */
	private function drop_table_best_effort( string $table_name ): void {
		try {
			$this->adapter->execute_sql( 'DROP TABLE IF EXISTS `' . self::escape_identifier( $table_name ) . '`' );
		} catch ( RuntimeException $ignored ) {
			unset( $ignored ); // Best-effort housekeeping: the leftover is inert and swept on a later run.
		}
	}

	/**
	 * Rewrite a chunk's table identifier to its staging form.
	 *
	 * The chunk's one table name — always backtick-quoted in the
	 * DROP/CREATE/INSERT the export emits, where row values are single-quoted —
	 * is swapped for the staged destination form. Matching the full
	 * backtick-quoted identifier keeps the rewrite from touching a
	 * single-quoted value or a prefix-substring sibling table.
	 *
	 * @param string $source_table The chunk's table name, from the entry header.
	 * @param string $staged_table The staging-prefixed destination name to install.
	 * @param string $payload      The chunk's decoded SQL bytes.
	 * @return string The payload with the table identifier rewritten.
	 */
	private function rewrite_table_identifier( string $source_table, string $staged_table, string $payload ): string {
		$from = '`' . self::escape_identifier( $source_table ) . '`';
		$to   = '`' . self::escape_identifier( $staged_table ) . '`';
		return str_replace( $from, $to, $payload );
	}

	/**
	 * Whether a table-prefix rewrite should be performed.
	 *
	 * @return bool True when both prefixes are non-empty and differ.
	 */
	private function prefix_rewrite_active(): bool {
		return '' !== $this->source_prefix
			&& '' !== $this->dest_prefix
			&& $this->source_prefix !== $this->dest_prefix;
	}

	/**
	 * Escape an SQL identifier by doubling backticks.
	 *
	 * Mirrors the escaping {@see \Pontifex\Manifest\WpdbAdapter} applies when emitting
	 * the identifier, so the rewrite's search string matches the bytes in the payload.
	 *
	 * @param string $identifier Raw identifier.
	 * @return string The identifier with embedded backticks doubled.
	 */
	private static function escape_identifier( string $identifier ): string {
		return str_replace( '`', '``', $identifier );
	}

	/**
	 * Split a Pontifex-produced SQL payload into individual statements.
	 *
	 * Splits on ";\n" (the writer's delimiter), trims each piece, and
	 * discards empty pieces. The result is the list of statements
	 * ready for execution; semicolons are NOT re-appended because
	 * the adapter doesn't require them.
	 *
	 * @param string $payload The decoded payload bytes from a db_chunk entry.
	 * @return string[] The statements, in order.
	 */
	private static function split_statements( string $payload ): array {
		if ( '' === $payload ) {
			return array();
		}
		$pieces     = explode( self::STATEMENT_DELIMITER, $payload );
		$statements = array();
		foreach ( $pieces as $piece ) {
			$trimmed = trim( $piece );
			if ( '' !== $trimmed ) {
				$statements[] = $trimmed;
			}
		}
		return $statements;
	}
}
