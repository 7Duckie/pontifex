<?php
/**
 * Unit tests for the PlaceholderMarkerRepair class.
 *
 * @package Pontifex\Tests\Unit\Restore
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Restore;

use PHPUnit\Framework\TestCase;
use Pontifex\Restore\PlaceholderMarkerRepair;

/**
 * Tests for {@see PlaceholderMarkerRepair}.
 *
 * The class is pure, so nothing here needs a database or WordPress. The tests
 * fall into two halves: the version gate that decides whether an archive is
 * repaired at all, and the replacement itself — including the near-miss shapes
 * that must be left alone, and every statement that is not an INSERT, because the
 * repair rewrites data and so must claim nothing beyond the one exact shape, in
 * the one kind of statement, that the defect produced.
 */
final class PlaceholderMarkerRepairTest extends TestCase {

	/**
	 * A marker as WordPress's wpdb generates it: a brace, 64 lowercase hex digits, a brace.
	 *
	 * @param string $digits Optional. The sixteen hexadecimal digits repeated four times to make the 64; default a mixed run.
	 * @return string The marker.
	 */
	private static function marker( string $digits = '0123456789abcdef' ): string {
		return '{' . str_repeat( $digits, 4 ) . '}';
	}

	// -------------------------------------------------------------------------
	// The version gate.
	// -------------------------------------------------------------------------

	/**
	 * A Pontifex archive written by a version before the boundary needs repair.
	 *
	 * @return void
	 */
	public function test_a_pontifex_archive_from_an_affected_version_needs_repair(): void {
		foreach ( array( '0.1.0', '0.9.5', '1.0.0', '1.0.3' ) as $version ) {
			$this->assertTrue(
				PlaceholderMarkerRepair::is_needed_for( 'pontifex', $version ),
				"Pontifex {$version} wrote the defect, so its archives must be repaired."
			);
		}
	}

	/**
	 * The boundary version itself is affected — the gate is inclusive.
	 *
	 * Also pins the boundary's value. It is the version gate for a repair that
	 * rewrites data, and must not be bumped casually: raising it starts rewriting
	 * archives that were never affected, and lowering it leaves affected ones
	 * broken. A change here should be a conscious act that fails this test first.
	 *
	 * @return void
	 */
	public function test_the_boundary_version_itself_needs_repair(): void {
		$this->assertSame( '1.1.0', PlaceholderMarkerRepair::LAST_AFFECTED_VERSION );
		$this->assertTrue( PlaceholderMarkerRepair::is_needed_for( 'pontifex', '1.1.0' ) );
	}

	/**
	 * A release candidate of the boundary version sorts before it, so it is repaired too.
	 *
	 * @return void
	 */
	public function test_a_pre_release_of_the_boundary_version_needs_repair(): void {
		$this->assertTrue( PlaceholderMarkerRepair::is_needed_for( 'pontifex', '1.1.0-rc1' ) );
	}

	/**
	 * An archive written after the boundary needs no repair and must never be touched.
	 *
	 * @return void
	 */
	public function test_a_later_version_needs_no_repair(): void {
		foreach ( array( '1.1.0.1', '1.1.1', '1.1.1-beta', '1.2.0', '2.0.0' ) as $version ) {
			$this->assertFalse(
				PlaceholderMarkerRepair::is_needed_for( 'pontifex', $version ),
				"Pontifex {$version} was written after the defect was fixed, so its archives must be left alone."
			);
		}
	}

	/**
	 * An archive from a different writer is never repaired, whatever its version.
	 *
	 * The marker shape is a Pontifex defect, not a feature of the archive format,
	 * so data of that shape from another writer would be real data. The name must
	 * match exactly: a different case, a suffix or stray whitespace is a
	 * different writer.
	 *
	 * @return void
	 */
	public function test_a_different_writer_is_never_repaired(): void {
		foreach ( array( 'someone-else', 'Pontifex', 'PONTIFEX', 'pontifex-pro', ' pontifex', 'pontifex ', '' ) as $name ) {
			$this->assertFalse(
				PlaceholderMarkerRepair::is_needed_for( $name, '1.0.0' ),
				'A writer named "' . $name . '" is not Pontifex, so its archives must be left alone even at an old version.'
			);
		}
	}

	/**
	 * A version string PHP cannot read, on an archive naming Pontifex, is repaired rather than skipped.
	 *
	 * PHP's version_compare() sorts unreadable text below any numbered release. The
	 * direction is deliberate and recorded on the method: skipping would leave
	 * certain corruption in place, while repairing risks only the one exact shape.
	 *
	 * @return void
	 */
	public function test_an_unreadable_version_on_a_pontifex_archive_is_repaired(): void {
		foreach ( array( 'not-a-version', 'dev', '' ) as $version ) {
			$this->assertTrue(
				PlaceholderMarkerRepair::is_needed_for( 'pontifex', $version ),
				'An unreadable version "' . $version . '" must fall on the side of repairing.'
			);
		}
	}

	// -------------------------------------------------------------------------
	// The replacement.
	// -------------------------------------------------------------------------

	/**
	 * The pattern matches the marker shape WordPress actually generates.
	 *
	 * Built the way wpdb::placeholder_escape() builds it — an HMAC-SHA256 digest in
	 * braces — rather than from a hand-typed string, so a drift in either
	 * direction (the digest's length or its case) fails here.
	 *
	 * @return void
	 */
	public function test_it_matches_a_marker_built_the_way_wordpress_builds_one(): void {
		$salt   = 'a-salt-for-the-test';
		$marker = '{' . hash_hmac( 'sha256', uniqid( $salt, true ), $salt ) . '}';
		$count  = 0;

		$this->assertSame( 66, strlen( $marker ), 'A marker is a brace, 64 hexadecimal characters and a brace.' );
		$this->assertSame( "INSERT INTO `t` VALUES ('50%')", PlaceholderMarkerRepair::repair( "INSERT INTO `t` VALUES ('50{$marker}')", $count ) );
		$this->assertSame( 1, $count );
	}

	/**
	 * One marker becomes one percent sign.
	 *
	 * @return void
	 */
	public function test_a_single_marker_becomes_one_percent_sign(): void {
		$count    = 0;
		$repaired = PlaceholderMarkerRepair::repair( "INSERT INTO `t` VALUES ('100" . self::marker() . " done')", $count );

		$this->assertSame( "INSERT INTO `t` VALUES ('100% done')", $repaired );
		$this->assertSame( 1, $count );
	}

	/**
	 * Several markers in one statement are all replaced, and all counted.
	 *
	 * The shape of a real permalink setting, `/%year%/%monthnum%/%postname%/`, which
	 * is the first thing an affected restore visibly breaks.
	 *
	 * @return void
	 */
	public function test_several_markers_in_one_statement_are_all_replaced_and_counted(): void {
		$m     = self::marker();
		$count = 0;

		$repaired = PlaceholderMarkerRepair::repair(
			"INSERT INTO `wp_options` VALUES ('permalink_structure', '/{$m}year{$m}/{$m}monthnum{$m}/{$m}postname{$m}/')",
			$count
		);

		$this->assertSame(
			"INSERT INTO `wp_options` VALUES ('permalink_structure', '/%year%/%monthnum%/%postname%/')",
			$repaired
		);
		$this->assertSame( 6, $count );
	}

	/**
	 * Distinct markers in one statement are all repaired, not only a repeated one.
	 *
	 * Each request generates its own marker, so a statement written across a
	 * boundary between requests could in principle hold more than one distinct
	 * value; the repair matches the shape, never one remembered string.
	 *
	 * @return void
	 */
	public function test_distinct_markers_are_each_replaced(): void {
		$count    = 0;
		$repaired = PlaceholderMarkerRepair::repair( "INSERT INTO `t` VALUES ('a" . self::marker( '0123456789abcdef' ) . 'b' . self::marker( 'fedcba9876543210' ) . "c')", $count );

		$this->assertSame( "INSERT INTO `t` VALUES ('a%b%c')", $repaired );
		$this->assertSame( 2, $count );
	}

	/**
	 * The count is added to, never overwritten, so a caller can total across statements.
	 *
	 * @return void
	 */
	public function test_the_count_accumulates_across_calls(): void {
		$count = 5;

		PlaceholderMarkerRepair::repair( "INSERT INTO `t` VALUES ('x" . self::marker() . "y')", $count );
		$this->assertSame( 6, $count );

		PlaceholderMarkerRepair::repair( 'INSERT INTO `t` VALUES (\'' . self::marker() . self::marker() . '\')', $count );
		$this->assertSame( 8, $count );

		PlaceholderMarkerRepair::repair( "INSERT INTO `t` VALUES ('nothing to repair here')", $count );
		$this->assertSame( 8, $count, 'A statement with no marker must leave the running total alone.' );
	}

	/**
	 * A statement with no marker comes back identical, even when it is full of braces and percent signs.
	 *
	 * The repair must not be a general brace-tidier: JSON, a CSS rule and a LIKE
	 * pattern are all ordinary row data.
	 *
	 * @return void
	 */
	public function test_a_statement_with_no_marker_is_returned_identical(): void {
		$statement = "INSERT INTO `t` VALUES ('{\"key\": \"value\"}', 'a { color: red }', 'LIKE 50%', '{}')";
		$count     = 0;

		$this->assertSame( $statement, PlaceholderMarkerRepair::repair( $statement, $count ) );
		$this->assertSame( 0, $count );
	}

	/**
	 * Shapes that are close to a marker but not exactly one are left alone.
	 *
	 * The corruption has exactly one shape, and a repair that rewrites data owes
	 * it nothing wider: 63 or 65 hexadecimal characters, uppercase, a
	 * non-hexadecimal character, a missing brace or a space inside are all
	 * ordinary text.
	 *
	 * @return void
	 */
	public function test_a_near_miss_shape_is_left_alone(): void {
		$near_misses = array(
			'63 hex characters'             => '{' . str_repeat( 'a', 63 ) . '}',
			'65 hex characters'             => '{' . str_repeat( 'a', 65 ) . '}',
			'uppercase hex'                 => '{' . str_repeat( 'A', 64 ) . '}',
			'one uppercase among lowercase' => '{' . str_repeat( 'a', 63 ) . 'F}',
			'non-hex content'               => '{' . str_repeat( 'g', 64 ) . '}',
			'one non-hex character'         => '{' . str_repeat( 'a', 63 ) . 'z}',
			'no opening brace'              => str_repeat( 'a', 64 ) . '}',
			'no closing brace'              => '{' . str_repeat( 'a', 64 ),
			'a space inside'                => '{' . str_repeat( 'a', 32 ) . ' ' . str_repeat( 'a', 32 ) . '}',
		);

		foreach ( $near_misses as $description => $text ) {
			$count     = 0;
			$statement = "INSERT INTO `t` VALUES ('x{$text}y')";

			$this->assertSame( $statement, PlaceholderMarkerRepair::repair( $statement, $count ), "{$description} is not a marker and must be left alone." );
			$this->assertSame( 0, $count, "{$description} must not be counted." );
		}
	}

	/**
	 * Only the exact marker is replaced; braces around it and markers back to back are handled exactly.
	 *
	 * @return void
	 */
	public function test_only_the_exact_shape_is_replaced_whatever_surrounds_it(): void {
		$m = self::marker();

		$count = 0;
		$this->assertSame( "INSERT INTO `t` VALUES ('{%}')", PlaceholderMarkerRepair::repair( "INSERT INTO `t` VALUES ('{" . $m . "}')", $count ), 'Braces outside the marker are ordinary text and stay.' );
		$this->assertSame( 1, $count );

		$count = 0;
		$this->assertSame( "INSERT INTO `t` VALUES ('%%')", PlaceholderMarkerRepair::repair( "INSERT INTO `t` VALUES ('" . $m . $m . "')", $count ), 'Two markers back to back are two percent signs.' );
		$this->assertSame( 2, $count );
	}

	/**
	 * An escaping backslash in front of a marker is preserved exactly.
	 *
	 * The marker replaced a percent sign that sat in the escaped text, so putting
	 * a percent sign back reproduces what the escaping returned — a backslash that
	 * was escaping something earlier is untouched.
	 *
	 * @return void
	 */
	public function test_an_escaping_backslash_before_a_marker_is_preserved(): void {
		$m     = self::marker();
		$count = 0;

		$this->assertSame( "INSERT INTO `t` VALUES ('C:\\\\%')", PlaceholderMarkerRepair::repair( "INSERT INTO `t` VALUES ('C:\\\\{$m}')", $count ) );
		$this->assertSame( 1, $count );
	}

	/**
	 * Only an INSERT statement is repaired; a marker-shaped run of text in any other statement is deliberately left alone.
	 *
	 * Only a row value can carry a marker. The exporter's INSERT values are the one
	 * thing it escapes with the call that makes them, while its CREATE text is copied
	 * verbatim from the server and its DROP and identifiers are built by doubling
	 * backticks. A marker-shaped default or comment in a schema is therefore real
	 * schema text, and repairing it could only ever be a false positive: the
	 * statement is returned exactly as it came, and nothing is counted.
	 *
	 * @return void
	 */
	public function test_only_an_insert_statement_is_repaired(): void {
		$m = self::marker();

		$left_alone = array(
			'a CREATE TABLE with a marker-shaped default' => "CREATE TABLE `t` (`ratio` varchar(8) DEFAULT '50{$m}') ENGINE=InnoDB",
			'a CREATE TABLE with a marker-shaped comment' => "CREATE TABLE `t` (`id` int) COMMENT='{$m}'",
			'a DROP TABLE'                                => "DROP TABLE IF EXISTS `t{$m}`",
			'an UPDATE'                                   => "UPDATE `t` SET `note` = '{$m}' WHERE `id` = 1",
			'a SELECT'                                    => "SELECT '{$m}'",
		);

		foreach ( $left_alone as $description => $statement ) {
			$count = 0;

			$this->assertSame( $statement, PlaceholderMarkerRepair::repair( $statement, $count ), "{$description} is not an INSERT and must be left alone." );
			$this->assertSame( 0, $count, "{$description} must not be counted." );
		}
	}

	/**
	 * The INSERT gate is anchored at byte offset 0 and byte-exact, the way the restore's containment check reads a statement.
	 *
	 * It is not a search for a verb: a statement that merely contains `INSERT INTO `
	 * somewhere, or opens with something close to it, is not repaired. The
	 * exporter writes exactly `INSERT INTO ` at the start of every INSERT, so
	 * nothing it wrote is missed by being strict.
	 *
	 * @return void
	 */
	public function test_the_insert_gate_is_anchored_and_byte_exact(): void {
		$m = self::marker();

		$left_alone = array(
			'lowercase'                     => "insert into `t` values ('{$m}')",
			'a leading space'               => " INSERT INTO `t` VALUES ('{$m}')",
			'INSERT IGNORE INTO'            => "INSERT IGNORE INTO `t` VALUES ('{$m}')",
			'REPLACE INTO'                  => "REPLACE INTO `t` VALUES ('{$m}')",
			'no space after INTO'           => "INSERT INTO`t` VALUES ('{$m}')",
			'INSERT INTO later in the text' => "UPDATE `t` SET `note` = 'INSERT INTO ' WHERE `x` = '{$m}'",
			'an empty statement'            => '',
		);

		foreach ( $left_alone as $description => $statement ) {
			$count = 0;

			$this->assertSame( $statement, PlaceholderMarkerRepair::repair( $statement, $count ), "{$description} does not open with exactly the INSERT prefix and must be left alone." );
			$this->assertSame( 0, $count, "{$description} must not be counted." );
		}

		$count = 0;
		$this->assertSame( "INSERT INTO `t` VALUES ('%')", PlaceholderMarkerRepair::repair( "INSERT INTO `t` VALUES ('{$m}')", $count ), 'The exact prefix is repaired.' );
		$this->assertSame( 1, $count );
	}

	/**
	 * A failure of the regular-expression engine throws; it never returns the statement unrepaired.
	 *
	 * Passing a statement through unrepaired on an engine failure would restore the
	 * very damage the caller has just promised to repair, with nothing saying so.
	 *
	 * Forced in a child PHP process, not in this one. PHP caches a compiled pattern
	 * together with whether it was JIT-compiled, so by the time this test runs
	 * another test in the same process has usually compiled the pattern with the
	 * JIT on, and changing `pcre.jit` afterwards does nothing to it. Nor does a
	 * backtrack limit alone fail it: measured, the JIT-compiled pattern matched
	 * happily with the limit at 1. A fresh process started with the JIT off and a
	 * backtrack limit of 1 compiles the pattern for the interpreter and fails it
	 * every time, whatever order the tests ran in.
	 *
	 * @return void
	 */
	public function test_an_engine_failure_throws_rather_than_returning_the_statement_unrepaired(): void {
		$statement = "INSERT INTO `t` VALUES ('50" . self::marker() . "')";
		$autoload  = dirname( __DIR__, 3 ) . '/vendor/autoload.php';

		// The autoloader's path and the statement travel as arguments ($argv[1] and
		// $argv[2]) rather than being spliced into the script's source.
		$script = 'require $argv[1];'
			. '$count = 0;'
			. 'try {'
			. '$out = \\Pontifex\\Restore\\PlaceholderMarkerRepair::repair( $argv[2], $count );'
			. 'echo "RETURNED:" . $out;'
			. '} catch ( \\RuntimeException $failure ) {'
			. 'echo "THROWN:" . $failure->getMessage();'
			. '}'
			. 'echo "|COUNT:" . $count;';

		$command = escapeshellarg( PHP_BINARY ) . ' -d pcre.jit=0 -d pcre.backtrack_limit=1 -r ' . escapeshellarg( $script ) . ' -- ' . escapeshellarg( $autoload ) . ' ' . escapeshellarg( $statement ) . ' 2>&1';
		$output  = array();
		$status  = 0;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Starting a child PHP process is the only deterministic way to compile the pattern without the JIT; the command is built from PHP_BINARY and values this test wrote, each passed through escapeshellarg(), with no external input.
		exec( $command, $output, $status );
		$printed = implode( "\n", $output );

		$this->assertSame( 0, $status, 'The child process must run cleanly; it printed: ' . $printed );
		$this->assertStringNotContainsString( 'RETURNED:', $printed, 'An engine failure must throw, not hand back the damaged statement.' );
		$this->assertStringContainsString( 'THROWN:', $printed );
		$this->assertStringContainsString( 'regular-expression engine failed', $printed );
		$this->assertStringNotContainsString( 'INSERT INTO', $printed, 'The message must never carry the statement\'s own bytes.' );
		$this->assertStringEndsWith( '|COUNT:0', $printed, 'Nothing was repaired, so nothing may be counted.' );
	}
}
