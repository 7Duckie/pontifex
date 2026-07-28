<?php
/**
 * Unit tests for the SqlSpanScanner class.
 *
 * @package Pontifex\Tests\Unit\Manifest
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Manifest;

use PHPUnit\Framework\TestCase;
use Pontifex\Manifest\SqlSpanScanner;

/**
 * Tests for {@see SqlSpanScanner}, the shared quote/comment span walk both
 * {@see \Pontifex\Restore\DatabaseWriter} and {@see \Pontifex\Manifest\WpdbAdapter}
 * depend on (ADR 0019).
 *
 * A pure, dependency-free unit — no WordPress/wpdb mocking needed. Beyond
 * ordinary correctness coverage, this file pins three guards a mutation run
 * found unprotected (memory `db-chunk-containment`, items 9–11): backslash
 * must never be an escape inside a backtick-quoted span; backtick- and
 * double-quoted spans must both be tracked so a semicolon or comment
 * introducer inside one is opaque; and "#", "/* *\/", and "--" followed by a
 * TAB must all be recognised as real comment introducers in the MUST-PERMIT
 * direction (a legitimate statement carrying one must not be refused).
 */
final class SqlSpanScannerTest extends TestCase {

	// -----------------------------------------------------------------
	// has_executable_semicolon(): baseline correctness.
	// -----------------------------------------------------------------

	/**
	 * An ordinary statement with no embedded semicolon is safe.
	 *
	 * @return void
	 */
	public function test_ordinary_statement_has_no_executable_semicolon(): void {
		$this->assertFalse( SqlSpanScanner::has_executable_semicolon( 'INSERT INTO `t` VALUES (1)', true ) );
	}

	/**
	 * A single trailing semicolon, with nothing but whitespace after it, is tolerated.
	 *
	 * @return void
	 */
	public function test_trailing_semicolon_is_tolerated(): void {
		$this->assertFalse( SqlSpanScanner::has_executable_semicolon( "INSERT INTO `t` VALUES (1);\n", true ) );
	}

	/**
	 * A semicolon followed by real content is executable.
	 *
	 * @return void
	 */
	public function test_semicolon_with_trailing_content_is_executable(): void {
		$this->assertTrue( SqlSpanScanner::has_executable_semicolon( 'INSERT INTO `t` VALUES (1); UPDATE `x` SET a=1', true ) );
	}

	/**
	 * A semicolon inside a single-quoted literal is not executable.
	 *
	 * @return void
	 */
	public function test_semicolon_inside_single_quotes_is_not_executable(): void {
		$this->assertFalse( SqlSpanScanner::has_executable_semicolon( "INSERT INTO `t` VALUES ('a;b')", true ) );
	}

	/**
	 * A quoted literal left open at the end of the text is unsafe.
	 *
	 * @return void
	 */
	public function test_unterminated_quote_is_unsafe(): void {
		$this->assertTrue( SqlSpanScanner::has_executable_semicolon( "INSERT INTO `t` VALUES ('never closed", true ) );
	}

	/**
	 * A block comment left open at the end of the text is unsafe.
	 *
	 * @return void
	 */
	public function test_unterminated_block_comment_is_unsafe(): void {
		$this->assertTrue( SqlSpanScanner::has_executable_semicolon( 'CREATE TABLE `t` (id INT) /* never closed', true ) );
	}

	/**
	 * A line comment left open at the end of the text is safe — there is by
	 * definition nothing left after it to hide.
	 *
	 * @return void
	 */
	public function test_unterminated_line_comment_is_safe(): void {
		$this->assertFalse( SqlSpanScanner::has_executable_semicolon( 'CREATE TABLE `t` (id INT) -- never closed', true ) );
	}

	/**
	 * A semicolon inside a conditional-execution comment IS executable — the
	 * server runs its contents, so this is not an ordinary, opaque comment.
	 *
	 * @return void
	 */
	public function test_semicolon_inside_conditional_comment_is_executable(): void {
		$this->assertTrue( SqlSpanScanner::has_executable_semicolon( 'CREATE TABLE `t` (id INT) /*!40101 SET @a=1; SET @b=2 */', true ) );
	}

	/**
	 * A semicolon inside MariaDB's own "/*M! *\/" conditional comment form IS
	 * executable, exactly like the standard "/*! *\/" form.
	 *
	 * @return void
	 */
	public function test_semicolon_inside_mariadb_conditional_comment_is_executable(): void {
		$this->assertTrue( SqlSpanScanner::has_executable_semicolon( 'CREATE TABLE `t` (id INT) /*M!100000 SET @a=1; SET @b=2 */', true ) );
	}

	// -----------------------------------------------------------------
	// strip_quoted_and_identifier_spans(): baseline correctness.
	// -----------------------------------------------------------------

	/**
	 * Every quoted span is removed, leaving only structural syntax.
	 *
	 * @return void
	 */
	public function test_strip_removes_quoted_spans(): void {
		$structural = SqlSpanScanner::strip_quoted_and_identifier_spans( "COMMENT='hello DATA DIRECTORY = here' ENGINE=InnoDB", true );
		$this->assertSame( 'COMMENT= ENGINE=InnoDB', $structural );
	}

	/**
	 * A doubled quote is an escaped literal quote, not a closer — the whole
	 * value stays inside one quoted span and is stripped as a unit.
	 *
	 * @return void
	 */
	public function test_strip_treats_doubled_quote_as_one_escaped_span(): void {
		$structural = SqlSpanScanner::strip_quoted_and_identifier_spans( "COMMENT='it''s DATA DIRECTORY = fine' ENGINE=InnoDB", true );
		$this->assertSame( 'COMMENT= ENGINE=InnoDB', $structural );
	}

	/**
	 * A backtick-quoted identifier is stripped like any other quoted span.
	 *
	 * @return void
	 */
	public function test_strip_removes_backtick_quoted_spans(): void {
		$structural = SqlSpanScanner::strip_quoted_and_identifier_spans( 'CREATE TABLE `DATA DIRECTORY =` (`id` INT)', true );
		$this->assertSame( 'CREATE TABLE  ( INT)', $structural );
	}

	/**
	 * A quoted span left open at the end strips to the end of the text rather
	 * than leaving the unmatched opening byte behind.
	 *
	 * @return void
	 */
	public function test_strip_of_unterminated_quote_drops_to_end(): void {
		$structural = SqlSpanScanner::strip_quoted_and_identifier_spans( "COMMENT='never closed DATA DIRECTORY = /tmp", true );
		$this->assertSame( 'COMMENT=', $structural );
	}

	/**
	 * The bug this class exists to fix, proven directly at the scanner level:
	 * a partition COMMENT re-serialised with BACKSLASH escaping (confirmed
	 * empirically against a live MariaDB server, see the class docblock) must
	 * not let a later, genuinely structural DATA DIRECTORY clause be swallowed
	 * into a bogus re-opened quoted span.
	 *
	 * @return void
	 */
	public function test_strip_with_backslash_escaped_apostrophe_does_not_swallow_later_directory_clause(): void {
		$definition = "(PARTITION `p0` VALUES LESS THAN (100) COMMENT = 'it\\'s' ENGINE = MyISAM,\n"
			. " PARTITION `p1` VALUES LESS THAN MAXVALUE DATA DIRECTORY = '/tmp' ENGINE = MyISAM)";

		$structural = SqlSpanScanner::strip_quoted_and_identifier_spans( $definition, true );

		$this->assertMatchesRegularExpression( '/DATA DIRECTORY\s*=/', $structural );
	}

	/**
	 * The same case with $backslash_is_escape false (NO_BACKSLASH_ESCAPES) must
	 * still leave the structural DATA DIRECTORY clause behind — the doubled
	 * form still round-trips regardless of the backslash flag.
	 *
	 * @return void
	 */
	public function test_strip_with_doubled_apostrophe_does_not_swallow_later_directory_clause(): void {
		$definition = "(PARTITION `p0` VALUES LESS THAN (100) COMMENT = 'it''s' ENGINE = MyISAM,\n"
			. " PARTITION `p1` VALUES LESS THAN MAXVALUE DATA DIRECTORY = '/tmp' ENGINE = MyISAM)";

		$structural = SqlSpanScanner::strip_quoted_and_identifier_spans( $definition, false );

		$this->assertMatchesRegularExpression( '/DATA DIRECTORY\s*=/', $structural );
	}

	// -----------------------------------------------------------------
	// Item 9 (db-chunk-containment mutation backlog): backslash is NEVER an
	// escape inside a backtick-quoted span, regardless of $backslash_is_escape.
	// -----------------------------------------------------------------

	/**
	 * A backtick-quoted identifier ending in a literal, unescaped backslash —
	 * a column genuinely named "a\" — must close cleanly at the real closing
	 * backtick, under $backslash_is_escape = true.
	 *
	 * Before this guard, treating the backslash as an escape would consume
	 * the real closing backtick as "escaped", leaving the span open for the
	 * rest of the text and wrongly marking an entirely ordinary statement as
	 * unsafe (or, in {@see SqlSpanScanner::strip_quoted_and_identifier_spans()},
	 * swallowing real structural text that follows).
	 *
	 * @return void
	 */
	public function test_backslash_not_an_escape_inside_backticks_when_flag_true(): void {
		$this->assertFalse( SqlSpanScanner::has_executable_semicolon( 'SELECT `a\\` FROM t', true ) );
	}

	/**
	 * The same column name must also close cleanly under
	 * $backslash_is_escape = false — backtick escaping is a MySQL/MariaDB
	 * rule of its own, never conditional on the flag.
	 *
	 * @return void
	 */
	public function test_backslash_not_an_escape_inside_backticks_when_flag_false(): void {
		$this->assertFalse( SqlSpanScanner::has_executable_semicolon( 'SELECT `a\\` FROM t', false ) );
	}

	// -----------------------------------------------------------------
	// Item 10: backtick- and double-quoted spans must both be tracked.
	// -----------------------------------------------------------------

	/**
	 * A backtick-quoted column named "a;b" must not have its embedded
	 * semicolon read as a statement terminator.
	 *
	 * @return void
	 */
	public function test_semicolon_inside_backtick_identifier_is_not_executable(): void {
		$this->assertFalse( SqlSpanScanner::has_executable_semicolon( 'SELECT `a;b` FROM t', true ) );
	}

	/**
	 * A backtick-quoted column named "a/*b" must not have its embedded
	 * block-comment-opening bytes read as starting a real comment.
	 *
	 * @return void
	 */
	public function test_block_comment_open_bytes_inside_backtick_identifier_are_inert(): void {
		$this->assertFalse( SqlSpanScanner::has_executable_semicolon( 'SELECT `a/*b` FROM t;', true ) );
	}

	/**
	 * A backtick-quoted column named "a'b" must not have its embedded
	 * apostrophe read as opening a single-quoted literal.
	 *
	 * @return void
	 */
	public function test_apostrophe_inside_backtick_identifier_is_inert(): void {
		$this->assertFalse( SqlSpanScanner::has_executable_semicolon( "SELECT `a'b` FROM t;", true ) );
	}

	/**
	 * An ANSI_QUOTES double-quoted identifier named "a;b" must not have its
	 * embedded semicolon read as a statement terminator — the double-quote
	 * span must be tracked exactly like the single-quote and backtick spans.
	 *
	 * @return void
	 */
	public function test_semicolon_inside_double_quoted_identifier_is_not_executable(): void {
		$this->assertFalse( SqlSpanScanner::has_executable_semicolon( 'SELECT "a;b" FROM t;', true ) );
	}

	// -----------------------------------------------------------------
	// Item 11: "#", "/* *\/", and "--" + TAB must be recognised as comments,
	// in the MUST-PERMIT direction.
	// -----------------------------------------------------------------

	/**
	 * A "#" comment in structural position, hiding a semicolon and trailing
	 * content, must be permitted — the same MUST-PERMIT shape already proven
	 * for "-- " with a space follow byte, for the "#" introducer.
	 *
	 * @return void
	 */
	public function test_hash_comment_hiding_semicolon_is_permitted(): void {
		$this->assertFalse( SqlSpanScanner::has_executable_semicolon( 'CREATE TABLE `t` (id INT) # x; inert', true ) );
	}

	/**
	 * A "/* *\/" block comment hiding a semicolon and trailing content, then
	 * properly closed, must be permitted.
	 *
	 * @return void
	 */
	public function test_block_comment_hiding_semicolon_is_permitted(): void {
		$this->assertFalse( SqlSpanScanner::has_executable_semicolon( 'CREATE TABLE `t` (id INT) /* x; inert */', true ) );
	}

	/**
	 * A "--" followed by a TAB — one of the qualifying follow bytes alongside
	 * space — must be recognised as a real line comment, hiding a semicolon
	 * and trailing content, and permitted.
	 *
	 * The existing coverage for this follow-byte requirement only proves a
	 * SPACE follow byte is recognised; TAB is a distinct byte in
	 * LINE_COMMENT_FOLLOW_BYTES with no test of its own before this one.
	 *
	 * @return void
	 */
	public function test_dash_dash_tab_comment_hiding_semicolon_is_permitted(): void {
		$this->assertFalse( SqlSpanScanner::has_executable_semicolon( "CREATE TABLE `t` (id INT) --\tx; inert", true ) );
	}

	// -----------------------------------------------------------------
	// Item 12 (db-chunk-containment mutation backlog): the second-dash
	// requirement itself. A differential fuzz over 1.2 million inputs found a
	// mutant that drops it — treating a LONE '-' as starting a line comment —
	// surviving the entire unit suite: 13,178 discriminating inputs, 12,931 of
	// them in the DANGEROUS direction, where the real scanner reports a
	// genuinely executable stacked ';' while the mutant hides it inside a
	// bogus comment the real scanner never opens. These two tests pin both
	// halves of the rule directly.
	// -----------------------------------------------------------------

	/**
	 * A single, lone '-' — ordinary SQL subtraction, not a comment introducer on
	 * its own — must not swallow the rest of the statement: the semicolon that
	 * follows, with real trailing content after it, is genuinely executable.
	 *
	 * This is the DANGEROUS half of the rule. A mutant that drops the
	 * second-dash requirement (treating this one lone '-' as already starting a
	 * line comment) reads everything from that dash to the end of the text as
	 * opaque comment content, hiding the stacked statement below from
	 * has_executable_semicolon() entirely — the real scanner must return true
	 * here; the mutant returns false.
	 *
	 * @return void
	 */
	public function test_single_dash_is_structural_and_following_semicolon_is_executable(): void {
		$this->assertTrue( SqlSpanScanner::has_executable_semicolon( 'UPDATE `t` SET a = 1 - 1; DROP TABLE `wp_users`', true ) );
	}

	/**
	 * A REAL "--" pair, followed by a qualifying byte (a plain space here), DOES
	 * start a line comment — the counterpart half of the same rule, so the fix
	 * for the dangerous half above cannot simply stop recognising "--" as a
	 * comment introducer at all. A semicolon inside it, with real trailing
	 * content after it in the source text, is opaque comment content, not
	 * executable.
	 *
	 * @return void
	 */
	public function test_double_dash_space_comment_hiding_semicolon_is_permitted(): void {
		$this->assertFalse( SqlSpanScanner::has_executable_semicolon( 'CREATE TABLE `t` (id INT) -- x; inert', true ) );
	}

	// -----------------------------------------------------------------
	// Item 13 (db-chunk-containment mutation backlog): the follow-byte read
	// itself must stay anchored to the SECOND dash, not to whatever byte
	// happens to sit two positions after a lone one. A differential fuzz
	// over 400,000 inputs found a mutant that drops ONLY the
	// `'-' === $sql[$i + 1]` conjunct while leaving the follow-byte read at
	// `$i + 2` in place — a different, offset-shifted variant from the one
	// Item 12 above pins, and one the Item 12 tests do not kill. That fuzz
	// found ~47,000 disagreements between the real scanner and this mutant,
	// including the dangerous direction this test pins.
	// -----------------------------------------------------------------

	/**
	 * A lone '-' followed by a non-dash byte must not start a line comment,
	 * even when the byte TWO positions after the dash happens to be a
	 * qualifying follow byte (a space, here). The real scanner's
	 * second-dash requirement reads `$sql[$i + 1]`, so `-x ` never matches
	 * it and the dash stays structural, ordinary SQL; a mutant that drops
	 * that requirement but still reads its follow byte from `$sql[$i + 2]`
	 * instead reads the SPACE after 'x' as if it followed a real "--" pair,
	 * opening a bogus line comment that swallows the genuinely executable
	 * semicolon and the stacked `UPDATE `wp_users`` statement after it —
	 * the real scanner must return true here; the mutant returns false,
	 * confirmed against a mutated copy of the scanner (never against this
	 * repository's own copy).
	 *
	 * @return void
	 */
	public function test_lone_dash_followed_by_non_dash_byte_does_not_start_comment(): void {
		$this->assertTrue(
			SqlSpanScanner::has_executable_semicolon(
				"INSERT INTO `t` VALUES (1)-x ; UPDATE `wp_users` SET user_pass='hacked'",
				true
			)
		);
	}
}
