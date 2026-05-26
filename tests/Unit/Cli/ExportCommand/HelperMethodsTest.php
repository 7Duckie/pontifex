<?php
/**
 * Behavioural tests for ExportCommand's pure helper methods.
 *
 * @package Pontifex\Tests\Unit\Cli\ExportCommand
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Cli\ExportCommand;

use PHPUnit\Framework\TestCase;
use Pontifex\Cli\ExportCommand;
use Pontifex\Manifest\ExclusionRules;
use ReflectionMethod;

/**
 * Tests for ExportCommand's pure helper methods.
 *
 * Covers parse_exclude_file_contents (parses raw file bytes into
 * pattern strings, handling blanks, comments, and mixed line endings)
 * and build_exclusion_rules (combines defaults with user patterns
 * according to the --no-defaults flag).
 *
 * Both helpers are private static methods. Reflection is used to
 * exercise them directly rather than promoting them to public —
 * nothing outside the command needs to call them.
 *
 * Behavioural verification of the full __invoke orchestration lives
 * in Phase 5 integration tests against a real WordPress install. The
 * pure helpers ARE worth testing here because they have real edge
 * cases (Windows line endings, trailing whitespace, comments,
 * empty inputs) where bugs would silently corrupt the user's
 * intended exclusion list.
 */
final class HelperMethodsTest extends TestCase {

	// -------------------------------------------------------------------------
	// Helpers.
	// -------------------------------------------------------------------------

	/**
	 * Invoke a private static method on ExportCommand via reflection.
	 *
	 * @param string $method_name The method to invoke.
	 * @param mixed  ...$args     Arguments to pass.
	 * @return mixed The method's return value.
	 */
	private function invoke_static( string $method_name, ...$args ) {
		$reflection = new ReflectionMethod( ExportCommand::class, $method_name );
		return $reflection->invoke( null, ...$args );
	}

	// -------------------------------------------------------------------------
	// parse_exclude_file_contents
	// -------------------------------------------------------------------------

	/**
	 * Empty input yields an empty pattern list.
	 *
	 * @return void
	 */
	public function test_parse_exclude_file_empty_input(): void {
		$patterns = (array) $this->invoke_static( 'parse_exclude_file_contents', '' );

		$this->assertSame( array(), $patterns );
	}

	/**
	 * Single non-blank line yields one pattern.
	 *
	 * @return void
	 */
	public function test_parse_exclude_file_single_pattern(): void {
		$patterns = (array) $this->invoke_static( 'parse_exclude_file_contents', "*.log\n" );

		$this->assertSame( array( '*.log' ), $patterns );
	}

	/**
	 * Multiple non-blank lines preserve order.
	 *
	 * @return void
	 */
	public function test_parse_exclude_file_multiple_patterns_in_order(): void {
		$contents = "first.txt\nsecond.txt\nthird.txt\n";

		$patterns = (array) $this->invoke_static( 'parse_exclude_file_contents', $contents );

		$this->assertSame( array( 'first.txt', 'second.txt', 'third.txt' ), $patterns );
	}

	/**
	 * Blank lines are skipped.
	 *
	 * @return void
	 */
	public function test_parse_exclude_file_blank_lines_are_skipped(): void {
		$contents = "first.txt\n\n\nsecond.txt\n";

		$patterns = (array) $this->invoke_static( 'parse_exclude_file_contents', $contents );

		$this->assertSame( array( 'first.txt', 'second.txt' ), $patterns );
	}

	/**
	 * Lines starting with `#` are treated as comments and skipped.
	 *
	 * @return void
	 */
	public function test_parse_exclude_file_comment_lines_are_skipped(): void {
		$contents = "# this is a header\nfirst.txt\n# inline comment\nsecond.txt\n";

		$patterns = (array) $this->invoke_static( 'parse_exclude_file_contents', $contents );

		$this->assertSame( array( 'first.txt', 'second.txt' ), $patterns );
	}

	/**
	 * Leading and trailing whitespace on each line is trimmed.
	 *
	 * @return void
	 */
	public function test_parse_exclude_file_whitespace_is_trimmed(): void {
		$contents = "  first.txt  \n\t\tsecond.txt\t\n";

		$patterns = (array) $this->invoke_static( 'parse_exclude_file_contents', $contents );

		$this->assertSame( array( 'first.txt', 'second.txt' ), $patterns );
	}

	/**
	 * A line that becomes empty after trimming is treated as blank and skipped.
	 *
	 * @return void
	 */
	public function test_parse_exclude_file_whitespace_only_lines_are_skipped(): void {
		$contents = "first.txt\n   \n\t\nsecond.txt\n";

		$patterns = (array) $this->invoke_static( 'parse_exclude_file_contents', $contents );

		$this->assertSame( array( 'first.txt', 'second.txt' ), $patterns );
	}

	/**
	 * Windows-style CRLF line endings parse the same as Unix LF.
	 *
	 * @return void
	 */
	public function test_parse_exclude_file_crlf_line_endings(): void {
		$contents = "first.txt\r\nsecond.txt\r\n";

		$patterns = (array) $this->invoke_static( 'parse_exclude_file_contents', $contents );

		$this->assertSame( array( 'first.txt', 'second.txt' ), $patterns );
	}

	/**
	 * Old-Mac-style bare-CR line endings parse the same as Unix LF.
	 *
	 * Unlikely in practice but cheap to support and defensible.
	 *
	 * @return void
	 */
	public function test_parse_exclude_file_bare_cr_line_endings(): void {
		$contents = "first.txt\rsecond.txt\r";

		$patterns = (array) $this->invoke_static( 'parse_exclude_file_contents', $contents );

		$this->assertSame( array( 'first.txt', 'second.txt' ), $patterns );
	}

	/**
	 * Input with no trailing newline still parses the final line.
	 *
	 * @return void
	 */
	public function test_parse_exclude_file_no_trailing_newline(): void {
		$contents = "first.txt\nsecond.txt";

		$patterns = (array) $this->invoke_static( 'parse_exclude_file_contents', $contents );

		$this->assertSame( array( 'first.txt', 'second.txt' ), $patterns );
	}

	/**
	 * A single comment-only file yields an empty pattern list.
	 *
	 * @return void
	 */
	public function test_parse_exclude_file_only_comments_yields_empty(): void {
		$contents = "# header one\n# header two\n";

		$patterns = (array) $this->invoke_static( 'parse_exclude_file_contents', $contents );

		$this->assertSame( array(), $patterns );
	}

	// -------------------------------------------------------------------------
	// build_exclusion_rules
	// -------------------------------------------------------------------------

	/**
	 * With defaults enabled and no user patterns, returns the curated default patterns.
	 *
	 * @return void
	 */
	public function test_build_rules_defaults_only(): void {
		$rules = $this->invoke_static( 'build_exclusion_rules', true, array() );

		$this->assertInstanceOf( ExclusionRules::class, $rules );
		$this->assertSame(
			ExclusionRules::default_v010()->patterns(),
			$rules->patterns()
		);
	}

	/**
	 * With defaults disabled and no user patterns, returns an empty pattern list.
	 *
	 * @return void
	 */
	public function test_build_rules_no_defaults_no_user_patterns(): void {
		$rules = $this->invoke_static( 'build_exclusion_rules', false, array() );

		$this->assertInstanceOf( ExclusionRules::class, $rules );
		$this->assertSame( array(), $rules->patterns() );
	}

	/**
	 * With defaults enabled and user patterns, user patterns appear AFTER defaults.
	 *
	 * Order matters because ExclusionRules matches "first match wins"
	 * inside the patterns array. Defaults coming first means a user
	 * pattern cannot override a default to keep something included
	 * (since both express EXclusions); but the order is still part of
	 * the contract.
	 *
	 * @return void
	 */
	public function test_build_rules_defaults_with_user_patterns(): void {
		$user_patterns = array( 'custom-thing/**', '*.tmp' );

		$rules = $this->invoke_static( 'build_exclusion_rules', true, $user_patterns );

		$expected = array_merge(
			ExclusionRules::default_v010()->patterns(),
			$user_patterns
		);
		$this->assertSame( $expected, $rules->patterns() );
	}

	/**
	 * With defaults disabled, only user patterns appear.
	 *
	 * @return void
	 */
	public function test_build_rules_no_defaults_with_user_patterns(): void {
		$user_patterns = array( 'custom-thing/**', '*.tmp' );

		$rules = $this->invoke_static( 'build_exclusion_rules', false, $user_patterns );

		$this->assertSame( $user_patterns, $rules->patterns() );
	}
}
