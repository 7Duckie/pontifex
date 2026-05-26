<?php
/**
 * Pontifex database writer — replays decoded SQL chunks into the destination database.
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
 * Replays one decoded db_chunk entry into the destination database.
 *
 * The mirror of {@see \Pontifex\Manifest\DatabaseScanner}. Where the
 * scanner walked the database and captured each table's schema and
 * row data into SQL bytes, DatabaseWriter takes those SQL bytes back
 * out of the archive and executes them, one statement at a time,
 * against the destination database via a {@see DatabaseAdapter}.
 *
 * Public API (locked for v0.1.0):
 *
 *  - {@see DatabaseWriter::__construct()} — takes the destination
 *    DatabaseAdapter. Stateless after construction.
 *  - {@see DatabaseWriter::write_entry()} — replay one db_chunk
 *    entry. Refuses file/directory/symlink entries (those go through
 *    {@see FileWriter}).
 *
 * Statement splitting:
 *
 * SQL doesn't split cleanly on ";" in general — semicolons can
 * appear inside string literals, comments, and DELIMITER directives.
 * Pontifex's scanner-writer pair sidesteps this by producing SQL in
 * a constrained format: one statement per line, terminated with
 * ";\n", no DELIMITER directives, no embedded semicolons in
 * unescaped strings. The v0.1.0 splitter relies on this contract.
 *
 * If a chunk's SQL violates the contract (for example, by containing
 * a string literal with an embedded ";\n"), the splitter will
 * produce broken statements and the adapter will throw. That's a
 * bug in the scanner-writer pair, not in this class.
 *
 * Verification:
 *
 *  1. The entry must be a db_chunk; other kinds are rejected at the
 *     boundary.
 *  2. The number of statements parsed from the payload must equal
 *     the recorded statement_count from the entry header. A
 *     mismatch indicates either a payload truncation or a bug in
 *     the writer, and is fatal.
 *  3. Each statement is executed individually. If any one throws,
 *     the rest are not attempted. v0.1.0 does NOT wrap the
 *     statements in a transaction — that's a Phase 4 (CLI) concern;
 *     partial-restore rollback policy belongs in the orchestration
 *     layer, not here.
 *
 * Internal choices (implementation details; may change without
 * breaking the public API):
 *
 *  - Split on ";\n". The trailing newline is part of the writer's
 *    contract; using just ";" would over-split SQL that legitimately
 *    contains semicolons inside string literals.
 *  - Trim each statement of leading and trailing whitespace; skip
 *    fully-empty statements (which can arise from trailing
 *    delimiters).
 *  - Stateless after construction; safe to reuse across many
 *    db_chunk entries.
 */
final class DatabaseWriter {

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
	 * Construct a DatabaseWriter that executes statements via $adapter.
	 *
	 * @param DatabaseAdapter $adapter The destination database adapter.
	 */
	public function __construct( DatabaseAdapter $adapter ) {
		$this->adapter = $adapter;
	}

	/**
	 * Replay one db_chunk entry into the destination database.
	 *
	 * Splits the payload into individual SQL statements, verifies the
	 * statement count matches the recorded header, then executes
	 * each statement in order against the adapter.
	 *
	 * @param EntryReadResult $result A decoded entry whose header is a db_chunk.
	 * @throws InvalidArgumentException If $result is not a db_chunk entry.
	 * @throws RuntimeException         If statement_count disagrees with the parsed count, or any adapter call fails.
	 */
	public function write_entry( EntryReadResult $result ): void {
		$header = $result->header();

		if ( ! $header->is_db_chunk() ) {
			throw new InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $header->kind() is a validated KIND_* constant; reported verbatim for diagnostic context; exception path, not HTML output.
				sprintf( 'DatabaseWriter: expected a db_chunk entry; got kind "%s". File/directory/symlink entries belong to FileWriter.', $header->kind() )
			);
		}

		$statements     = self::split_statements( $result->payload() );
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
