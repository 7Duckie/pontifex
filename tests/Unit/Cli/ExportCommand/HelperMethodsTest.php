<?php
/**
 * Behavioural tests for ExportCommand's pure helper methods.
 *
 * @package Pontifex\Tests\Unit\Cli\ExportCommand
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Cli\ExportCommand;

use Mockery;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Cli\ExportCommand;
use Pontifex\Cli\NullProgressBar;
use Pontifex\Environment\Environment;
use Pontifex\Manifest\ExclusionRules;
use Pontifex\Tests\TestCase;
use Pontifex\WordPress\WordPressContext;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * Tests for ExportCommand's pure helper methods.
 *
 * Covers parse_exclude_file_contents (parses raw file bytes into
 * pattern strings, handling blanks, comments, and mixed line endings)
 * and build_exclusion_rules (combines the curated defaults with file
 * patterns from --exclude/--exclude-file and table patterns from
 * --exclude-table, each kept in its own kind scope, according to the
 * --no-defaults flag).
 *
 * Both helpers are private static methods. Reflection is used to
 * exercise them directly rather than promoting them to public —
 * nothing outside the command needs to call them.
 *
 * Also covers print_exclusion_match_summary(), a private INSTANCE method
 * (so {@see self::invoke_instance()} builds a real ExportCommand to call
 * it on rather than reflecting on a static context), which prints how many
 * things each active exclusion pattern excluded once an export finishes.
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

	/**
	 * Invoke a private INSTANCE method on a freshly built ExportCommand via
	 * reflection.
	 *
	 * Unlike {@see self::invoke_static()}, print_exclusion_match_summary()
	 * is an instance method, so ReflectionMethod::invoke() needs a real
	 * object to call it on rather than null. The Environment and
	 * WordPressContext dependencies are never touched by that method, so
	 * bare Mockery doubles with no expectations stand in for them.
	 *
	 * @param string $method_name The method to invoke.
	 * @param mixed  ...$args     Arguments to pass.
	 * @return mixed The method's return value.
	 */
	private function invoke_instance( string $method_name, ...$args ) {
		$command    = new ExportCommand(
			Mockery::mock( Environment::class ),
			Mockery::mock( WordPressContext::class ),
			null,
			new NullLogger(),
			new NullProgressBar()
		);
		$reflection = new ReflectionMethod( ExportCommand::class, $method_name );
		return $reflection->invoke( $command, ...$args );
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
	 * With defaults enabled and no file or table patterns, returns the curated default patterns.
	 *
	 * @return void
	 */
	public function test_build_rules_defaults_only(): void {
		$rules = $this->invoke_static( 'build_exclusion_rules', true, array(), array() );

		$this->assertInstanceOf( ExclusionRules::class, $rules );
		$this->assertSame(
			ExclusionRules::default_v010()->patterns(),
			$rules->patterns()
		);
	}

	/**
	 * With defaults disabled and no file or table patterns, returns an empty pattern list.
	 *
	 * @return void
	 */
	public function test_build_rules_no_defaults_no_user_patterns(): void {
		$rules = $this->invoke_static( 'build_exclusion_rules', false, array(), array() );

		$this->assertInstanceOf( ExclusionRules::class, $rules );
		$this->assertSame( array(), $rules->patterns() );
	}

	/**
	 * With defaults enabled and file patterns, file patterns appear AFTER defaults.
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
		$file_patterns = array( 'custom-thing/**', '*.tmp' );

		$rules = $this->invoke_static( 'build_exclusion_rules', true, $file_patterns, array() );

		$expected = array_merge(
			ExclusionRules::default_v010()->patterns(),
			$file_patterns
		);
		$this->assertSame( $expected, $rules->patterns() );
	}

	/**
	 * With defaults disabled, only the file patterns appear.
	 *
	 * @return void
	 */
	public function test_build_rules_no_defaults_with_user_patterns(): void {
		$file_patterns = array( 'custom-thing/**', '*.tmp' );

		$rules = $this->invoke_static( 'build_exclusion_rules', false, $file_patterns, array() );

		$this->assertSame( $file_patterns, $rules->patterns() );
	}

	/**
	 * Table patterns appear after both the defaults and the file patterns.
	 *
	 * @return void
	 */
	public function test_build_rules_table_patterns_appear_after_file_patterns(): void {
		$file_patterns  = array( 'custom-thing/**' );
		$table_patterns = array( 'wp_actionscheduler_*' );

		$rules = $this->invoke_static( 'build_exclusion_rules', true, $file_patterns, $table_patterns );

		$expected = array_merge(
			ExclusionRules::default_v010()->patterns(),
			$file_patterns,
			$table_patterns
		);
		$this->assertSame( $expected, $rules->patterns() );
	}

	/**
	 * A file pattern must not exclude a table, even when its text would match one.
	 *
	 * The headline defect this job fixes: --exclude and --exclude-file build
	 * patterns that are scoped to files, directories, and symlinks only, so
	 * they can never reach a database table — see ExclusionPattern.
	 *
	 * @return void
	 */
	public function test_build_rules_file_pattern_does_not_reach_a_table(): void {
		$rules = $this->invoke_static( 'build_exclusion_rules', false, array( '/comments/' ), array() );

		$this->assertFalse( $rules->matches( 'wp_comments', EntryHeader::KIND_DB_CHUNK ) );
		$this->assertTrue( $rules->matches( 'comments/2026-01.html', EntryHeader::KIND_FILE ) );
	}

	/**
	 * A table pattern must not exclude a file, even when its text would match one.
	 *
	 * @return void
	 */
	public function test_build_rules_table_pattern_does_not_reach_a_file(): void {
		$rules = $this->invoke_static( 'build_exclusion_rules', false, array(), array( 'wp_actionscheduler_*' ) );

		$this->assertTrue( $rules->matches( 'wp_actionscheduler_logs', EntryHeader::KIND_DB_CHUNK ) );
		$this->assertFalse( $rules->matches( 'wp_actionscheduler_logs', EntryHeader::KIND_FILE ) );
	}

	/**
	 * The curated `.git`-at-any-depth default keeps matching at any depth even
	 * when combined with an operator's own (anchored) file pattern.
	 *
	 * This is the regression this job's brief calls out by name: the built-in
	 * default must be unaffected by whatever anchoring an operator pattern
	 * gets, however similar the two might look.
	 *
	 * @return void
	 */
	public function test_build_rules_git_default_still_matches_at_any_depth_when_combined(): void {
		$rules = $this->invoke_static( 'build_exclusion_rules', true, array( '/comments/' ), array() );

		$this->assertTrue( $rules->matches( 'wp-content/plugins/foo/.git/config', EntryHeader::KIND_FILE ) );
	}

	// -------------------------------------------------------------------------
	// split_patterns (the --exclude / --exclude-table comma splitting)
	// -------------------------------------------------------------------------

	/**
	 * A comma-separated value splits into trimmed, blank-free patterns.
	 *
	 * @return void
	 */
	public function test_split_patterns_comma_separated(): void {
		$patterns = (array) $this->invoke_static( 'split_patterns', '*.log, wp_actionscheduler_* , wp-content/cache/**' );

		$this->assertSame( array( '*.log', 'wp_actionscheduler_*', 'wp-content/cache/**' ), $patterns );
	}

	/**
	 * Blank segments (a stray comma) are dropped, not kept as empty patterns.
	 *
	 * @return void
	 */
	public function test_split_patterns_drops_blank_segments(): void {
		$patterns = (array) $this->invoke_static( 'split_patterns', 'a,,b, ,c' );

		$this->assertSame( array( 'a', 'b', 'c' ), $patterns );
	}

	/**
	 * A missing flag (null) or a bare boolean flag yields no patterns.
	 *
	 * @return void
	 */
	public function test_split_patterns_absent_or_boolean_yields_empty(): void {
		$this->assertSame( array(), (array) $this->invoke_static( 'split_patterns', null ) );
		$this->assertSame( array(), (array) $this->invoke_static( 'split_patterns', true ) );
		$this->assertSame( array(), (array) $this->invoke_static( 'split_patterns', '' ) );
	}

	/**
	 * A single pattern with no comma round-trips unchanged.
	 *
	 * @return void
	 */
	public function test_split_patterns_single_value(): void {
		$this->assertSame( array( 'wp_options' ), (array) $this->invoke_static( 'split_patterns', 'wp_options' ) );
	}

	// -------------------------------------------------------------------------
	// should_use_defaults (the --no-defaults parsing)
	// -------------------------------------------------------------------------

	/**
	 * With no flag, the curated defaults are applied.
	 *
	 * @return void
	 */
	public function test_should_use_defaults_true_by_default(): void {
		$this->assertTrue( $this->invoke_static( 'should_use_defaults', array() ) );
	}

	/**
	 * The real WP-CLI parse of --no-defaults (defaults => false) disables the defaults.
	 *
	 * This is the regression guard for the --no-defaults bug: WP-CLI's --no-<name>
	 * convention delivers the flag as defaults => false, not a no-defaults key.
	 *
	 * @return void
	 */
	public function test_should_use_defaults_false_when_no_defaults_passed(): void {
		$this->assertFalse( $this->invoke_static( 'should_use_defaults', array( 'defaults' => false ) ) );
	}

	/**
	 * An explicit --defaults (defaults => true) keeps the defaults on.
	 *
	 * @return void
	 */
	public function test_should_use_defaults_true_when_defaults_true(): void {
		$this->assertTrue( $this->invoke_static( 'should_use_defaults', array( 'defaults' => true ) ) );
	}

	// -------------------------------------------------------------------------
	// print_exclusion_match_summary
	// -------------------------------------------------------------------------

	/**
	 * One line per pattern, each carrying its own count, under a single
	 * heading — including a pattern whose count is 0, exactly as visible as
	 * one that matched a great deal.
	 *
	 * @return void
	 */
	public function test_print_exclusion_match_summary_prints_one_line_per_pattern(): void {
		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$logged = array();
		$wp_cli->shouldReceive( 'log' )->times( 3 )->andReturnUsing(
			static function ( string $line ) use ( &$logged ): void {
				$logged[] = $line;
			}
		);

		$this->invoke_instance(
			'print_exclusion_match_summary',
			array(
				array(
					'pattern' => 'wp-content/cache/**',
					'count'   => 1,
				),
				array(
					'pattern' => 'wp_actionscheduler_*',
					'count'   => 0,
				),
			)
		);

		$this->assertSame(
			array(
				'Exclusion pattern matches:',
				'  wp-content/cache/**: 1',
				'  wp_actionscheduler_*: 0',
			),
			$logged
		);
	}

	/**
	 * Nothing is printed at all when there are no exclusion patterns to report.
	 *
	 * @return void
	 */
	public function test_print_exclusion_match_summary_prints_nothing_when_empty(): void {
		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->never();

		$this->invoke_instance( 'print_exclusion_match_summary', array() );

		$this->assertTrue( true, 'Reached without WP_CLI::log ever firing — the shouldReceive()->never() above is the real assertion.' );
	}

	/**
	 * An element that is not shaped like {pattern: string, count: int} is
	 * skipped rather than fataling — one caller now hands this method
	 * counts that have round-tripped through a job payload, so a malformed
	 * element must degrade quietly instead of raising a TypeError or an
	 * undefined-array-key warning.
	 *
	 * @return void
	 */
	public function test_print_exclusion_match_summary_skips_malformed_elements(): void {
		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$logged = array();
		$wp_cli->shouldReceive( 'log' )->twice()->andReturnUsing(
			static function ( string $line ) use ( &$logged ): void {
				$logged[] = $line;
			}
		);

		$this->invoke_instance(
			'print_exclusion_match_summary',
			array(
				'not-an-array',
				array( 'pattern' => 'wp-content/cache/**' ), // Missing 'count': skipped.
				array(
					'pattern' => 'wp_actionscheduler_*',
					'count'   => 'not-numeric',
				), // Non-numeric count: skipped.
				array(
					'pattern' => 'wp-content/pontifex/**',
					'count'   => 2,
				), // The one well-shaped element.
			)
		);

		$this->assertSame(
			array(
				'Exclusion pattern matches:',
				'  wp-content/pontifex/**: 2',
			),
			$logged,
			'Every malformed element must be skipped without fataling, leaving only the one well-shaped entry printed.'
		);
	}
}
