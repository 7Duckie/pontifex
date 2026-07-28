<?php
/**
 * Pontifex SQL span scanner — the one place that walks SQL text tracking which
 * quoted or comment span each byte falls inside.
 *
 * @package Pontifex\Manifest
 */

declare(strict_types=1);

namespace Pontifex\Manifest;

/**
 * A single, escaping-aware lexical pass over SQL text.
 *
 * Two call sites in this codebase each need to know, byte by byte, whether a
 * position in some SQL text sits inside a quoted literal, a quoted
 * identifier, or a comment: {@see \Pontifex\Restore\DatabaseWriter}, deciding
 * whether an archive-supplied statement carries a semicolon the destination
 * server would actually execute (ADR 0019), and {@see WpdbAdapter}, deciding
 * whether the server's own `SHOW CREATE TABLE` re-serialisation names a
 * partition storage-directory clause outside any quoted span (ADR 0019).
 * Before this class existed those two call sites carried separate copies of
 * the walk, and the copies drifted: the archive-scanning copy recognised a
 * backslash as escaping the next byte inside a single- or double-quoted
 * span, and the `SHOW CREATE TABLE`-scanning copy did not. Proven against a
 * live MariaDB server (12.3.2), a partition's `COMMENT` value is
 * re-serialised with BACKSLASH escaping (`COMMENT = 'it\'s'`) even though
 * the original `CREATE TABLE` statement supplied the doubled form
 * (`COMMENT = 'it''s'`) and even though an ordinary table or column
 * `COMMENT` in the SAME session, under the SAME `sql_mode`, is re-serialised
 * with DOUBLED-QUOTE escaping instead (`COMMENT='also it''s here'`) — MariaDB
 * is not internally consistent about which escaping convention its own DDL
 * printer uses for a given clause. The copy that did not recognise a
 * backslash as an escape closed the quoted span one byte early, at the
 * escaped apostrophe, which re-opened a bogus quoted span over the text that
 * followed and swallowed a later, genuinely structural `DATA DIRECTORY = `
 * clause into it — hiding a real partition-level storage-directory clause
 * from the pattern meant to catch it. There must be exactly one
 * implementation of this walk in the codebase, so a fix to one copy can
 * never again leave the other behind.
 *
 * Lives in `Pontifex\Manifest` — a plain, dependency-free static utility with
 * no `wpdb` type in its signature — rather than in `Pontifex\Restore`, so
 * that both {@see WpdbAdapter} (already in this namespace) and
 * {@see \Pontifex\Restore\DatabaseWriter} (which already depends on this
 * namespace's {@see DatabaseAdapter} interface) can use it without a
 * circular dependency: `Restore` depends on `Manifest`, never the reverse.
 *
 * Quoting model — three forms, matched identically:
 *
 *  - Single-quoted (`'...'`) and double-quoted (`"..."`, a string normally, an
 *    identifier under `ANSI_QUOTES` — opaque either way, the safe reading)
 *    spans recognise a doubled delimiter as an escaped literal quote, and
 *    ALSO recognise a backslash as escaping the following byte when
 *    $backslash_is_escape is true.
 *  - Backtick-quoted (`` `...` ``) spans recognise only the doubled
 *    delimiter; a backslash is NEVER an escape inside backticks, regardless
 *    of $backslash_is_escape — a MySQL/MariaDB rule of its own, not
 *    conditional on sql_mode.
 *
 * $backslash_is_escape should be the destination server's actual
 * `NO_BACKSLASH_ESCAPES`-derived fact for text that will be EXECUTED (an
 * archive-supplied statement); for text that is only being READ (the
 * server's own `SHOW CREATE TABLE` re-serialisation), callers should still
 * pass the connection's current sql_mode-derived fact, on the same
 * reasoning DatabaseWriter already applies — it is the best available
 * server fact, even though MariaDB's own inconsistency (documented above)
 * means no single boolean can describe every clause of a `SHOW CREATE
 * TABLE` definition with certainty. That residual gap is bounded, not
 * unbounded: recognising a backslash as a possible escape only ever makes a
 * quoted span close EARLIER (on encountering a real, unescaped delimiter) or
 * LATER (once escaped) than a naive scan would — it cannot fabricate a
 * quoted span where none exists, and it is what closes the actual,
 * proven-exploitable bypass this class exists to fix.
 *
 * Comment model — three inert forms, and two that are NOT comments at all:
 *
 *  - `#` and `-- ` (two dashes followed by whitespace or a control byte —
 *    bare `--foo` with no such byte after it is not a comment introducer at
 *    all under standard SQL) start a line comment, inert to the end of the
 *    line or the end of the text.
 *  - `/* ... *\/` starts a block comment, inert to the matching close.
 *  - MySQL's conditional-execution comment syntax, `/*! ... *\/` (optionally
 *    carrying a version number), and MariaDB's own equivalent marker,
 *    `/*M! ... *\/`, are NOT comments at all: the server executes the bytes
 *    they enclose whenever the version condition is met, so a quote or a
 *    semicolon inside either is scanned exactly as if the surrounding
 *    markers were not there.
 *
 * A comment introducer is recognised only OUTSIDE a quoted span (a quote
 * character inside a real comment, or a comment introducer inside a real
 * quoted value, is just data either way — the quote-state check always runs
 * first); once inside an ordinary line or block comment, no quote or
 * comment introducer inside it is recognised as anything.
 *
 * This is a lexical pass over statement bytes, tracking quote and comment
 * state one byte at a time — not a SQL parser. It builds no syntax tree and
 * does not understand every construct a real SQL parser does. Within that
 * scope it recognises every quoting and comment form documented above, and
 * nothing else.
 */
final class SqlSpanScanner {

	/**
	 * Comment-tracking state: not inside any comment.
	 *
	 * @var string
	 */
	private const COMMENT_NONE = '';

	/**
	 * Comment-tracking state: inside an ordinary line comment ("-- " or "#"),
	 * running to the end of the line (or the end of the text, if no newline
	 * follows). Its content is opaque: a quote character inside it is just
	 * text, and a semicolon inside it is not executable.
	 *
	 * @var string
	 */
	private const COMMENT_LINE = 'line';

	/**
	 * Comment-tracking state: inside an ordinary block comment (slash-star to
	 * star-slash). Opaque in the same way as {@see self::COMMENT_LINE}.
	 *
	 * @var string
	 */
	private const COMMENT_BLOCK = 'block';

	/**
	 * Bytes MySQL accepts immediately after "--" for it to start a line comment.
	 *
	 * Standard SQL requires the second dash to be followed by whitespace or a
	 * control character; a bare "--foo" with no such byte after it is not a
	 * comment at all under that rule, and treating it as one anyway would be
	 * the dangerous direction — it would hide a real semicolon MySQL does
	 * execute. "#" carries no such requirement in MySQL and is always a
	 * comment introducer.
	 *
	 * @var string
	 */
	private const LINE_COMMENT_FOLLOW_BYTES = " \t\n\r\x0B\x0C";

	/**
	 * Prevent instantiation; every member is static.
	 */
	private function __construct() {
	}

	/**
	 * Whether $sql cannot be shown safe to execute as a single statement.
	 *
	 * True if $sql carries a semicolon outside any quoted span or ordinary
	 * comment that is followed by further non-whitespace content, or if $sql
	 * ends with a quoted span or an ordinary block comment left open — either
	 * of which means $sql cannot be trusted to be exactly one statement. A
	 * single TRAILING semicolon (nothing but whitespace after it) is
	 * tolerated. An ordinary LINE comment left open at the end is NOT unsafe:
	 * unlike a quoted span or a block comment, there is by definition nothing
	 * left after it to hide.
	 *
	 * @param string $sql                 One statement, exactly as it will be executed.
	 * @param bool   $backslash_is_escape Whether the destination server treats a backslash inside a single- or double-quoted span as an escape character; see the class docblock.
	 * @return bool True when $sql cannot be shown safe to execute as a single statement.
	 */
	public static function has_executable_semicolon( string $sql, bool $backslash_is_escape ): bool {
		return self::scan( $sql, $backslash_is_escape )['has_executable_semicolon'];
	}

	/**
	 * Remove every single-quoted, double-quoted, and backtick-quoted span, and
	 * every ordinary comment span, from $sql — leaving only its structural
	 * SQL syntax (plus the content of any conditional-execution comment,
	 * which is structural, executable SQL, not a comment; see the class
	 * docblock).
	 *
	 * A quoted span or block comment left OPEN at the end of $sql strips to
	 * the end rather than leaving the unmatched opening byte in the output —
	 * safe, because it can only remove bytes a caller's pattern might
	 * otherwise have matched, never fabricate a clause that was not there.
	 *
	 * @param string $sql                 The SQL text to reduce to its structural bytes.
	 * @param bool   $backslash_is_escape Whether a backslash inside a single- or double-quoted span is an escape character; see the class docblock.
	 * @return string $sql with every quoted and ordinary-comment span removed.
	 */
	public static function strip_quoted_and_identifier_spans( string $sql, bool $backslash_is_escape ): string {
		return self::scan( $sql, $backslash_is_escape )['structural'];
	}

	/**
	 * Walk $sql once, tracking quote and comment state, producing both facts
	 * {@see self::has_executable_semicolon()} and
	 * {@see self::strip_quoted_and_identifier_spans()} need.
	 *
	 * A single shared loop rather than two copies of the same state machine:
	 * the bug this class exists to fix was exactly two copies drifting apart.
	 * Every byte not consumed by a quote/comment span, or by an introducer
	 * being recognised, is structural and is appended to the structural
	 * output — including a structural semicolon itself, which does not stop
	 * the walk (unlike an early-return design, every byte is still visited so
	 * the full structural text is always available).
	 *
	 * @param string $sql                 The SQL text to scan.
	 * @param bool   $backslash_is_escape Whether a backslash inside a single- or double-quoted span is an escape character.
	 * @return array{has_executable_semicolon: bool, structural: string}
	 */
	private static function scan( string $sql, bool $backslash_is_escape ): array {
		$length                   = strlen( $sql );
		$quote                    = '';
		$comment                  = self::COMMENT_NONE;
		$structural               = '';
		$has_executable_semicolon = false;

		for ( $i = 0; $i < $length; $i++ ) {
			$byte = $sql[ $i ];

			if ( self::COMMENT_LINE === $comment ) {
				if ( "\n" === $byte ) {
					$comment = self::COMMENT_NONE;
				}
				continue;
			}

			if ( self::COMMENT_BLOCK === $comment ) {
				if ( '*' === $byte && $i + 1 < $length && '/' === $sql[ $i + 1 ] ) {
					$comment = self::COMMENT_NONE;
					++$i;
				}
				continue;
			}

			if ( '' !== $quote ) {
				// A backslash escapes the next byte inside a single- or double-quoted
				// span, but only when $backslash_is_escape says it does. Backticked
				// identifiers never recognise backslash escaping, regardless.
				if ( $backslash_is_escape && '\\' === $byte && '`' !== $quote ) {
					++$i;
					continue;
				}
				if ( $byte === $quote ) {
					// A doubled quote is an escaped literal quote, not the closer: it
					// stays inside the quoted span.
					if ( $i + 1 < $length && $sql[ $i + 1 ] === $quote ) {
						++$i;
						continue;
					}
					$quote = '';
				}
				continue;
			}

			if ( '#' === $byte ) {
				$comment = self::COMMENT_LINE;
				continue;
			}

			if ( '-' === $byte && $i + 1 < $length && '-' === $sql[ $i + 1 ] ) {
				$after = $i + 2 < $length ? $sql[ $i + 2 ] : '';
				if ( '' === $after || false !== strpos( self::LINE_COMMENT_FOLLOW_BYTES, $after ) ) {
					$comment = self::COMMENT_LINE;
					++$i;
					continue;
				}
				// No qualifying byte follows: standard SQL does not treat this "--"
				// as a comment introducer at all, so it is left as two ordinary,
				// structural bytes and falls through to the checks below.
			}

			if ( '/' === $byte && $i + 1 < $length && '*' === $sql[ $i + 1 ] ) {
				$is_conditional_comment = ( $i + 2 < $length && '!' === $sql[ $i + 2 ] )
					|| ( $i + 3 < $length && 'M' === $sql[ $i + 2 ] && '!' === $sql[ $i + 3 ] );
				if ( ! $is_conditional_comment ) {
					$comment = self::COMMENT_BLOCK;
					++$i;
					continue;
				}
				// A conditional-execution comment's contents ARE executed by the
				// server (see the class docblock), so — unlike an ordinary block
				// comment — they are left to the normal quote/semicolon tracking
				// below and to the structural output; only the two-byte introducer
				// itself falls through as ordinary structural bytes here.
			}

			if ( "'" === $byte || '"' === $byte || '`' === $byte ) {
				$quote = $byte;
				continue;
			}

			if ( ! $has_executable_semicolon && ';' === $byte && '' !== trim( substr( $sql, $i + 1 ) ) ) {
				$has_executable_semicolon = true;
			}

			$structural .= $byte;
		}

		$unterminated = '' !== $quote || self::COMMENT_BLOCK === $comment;

		return array(
			'has_executable_semicolon' => $has_executable_semicolon || $unterminated,
			'structural'               => $structural,
		);
	}
}
