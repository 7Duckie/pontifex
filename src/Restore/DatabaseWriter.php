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
 *  3. Each statement is executed individually. If any one throws,
 *     the rest are not attempted — and because every statement ran
 *     against staging tables, the live database is unchanged.
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
	 * Reset for a new restore and sweep leftovers from a crashed earlier run.
	 *
	 * A restore that died without reaching commit or abort leaves
	 * `pontifexstg_*` (and, in a narrow window, `pontifexold_*`) tables behind.
	 * They are inert but occupy disk and would collide with this run's staging
	 * names, so they are dropped here. The sweep is best-effort: a table that
	 * cannot be listed or dropped is left for a later run, never a reason to
	 * refuse a restore.
	 *
	 * @return void
	 */
	public function begin_staging(): void {
		$this->staged_tables = array();
		foreach ( array( self::STAGING_PREFIX, self::OLD_PREFIX ) as $prefix ) {
			foreach ( $this->adapter->list_tables_by_prefix( $prefix ) as $leftover ) {
				$this->drop_table_best_effort( $leftover );
			}
		}
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
	 * @throws RuntimeException         If the chunk has no table name, the staged name would be over-long, statement_count disagrees with the parsed count, or any adapter call fails.
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

		$payload        = $this->rewrite_table_identifier( $source_table, self::STAGING_PREFIX . $dest_table, $result->payload() );
		$statements     = self::split_statements( $payload );
		$declared_count = (int) $header->statement_count();
		$parsed_count   = count( $statements );

		if ( $declared_count !== $parsed_count ) {
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $declared_count and $parsed_count are integers reported verbatim for diagnostic context; exception path, not HTML output.
				sprintf( 'DatabaseWriter: statement_count mismatch — header declared %d, payload contains %d.', $declared_count, $parsed_count )
			);
		}

		foreach ( $statements as $statement ) {
			$this->adapter->execute_sql( $statement );
		}
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
