<?php
/**
 * Behavioural tests for PathEncoding — the test for a path the format can record.
 *
 * @package Pontifex\Tests\Unit\Archive\Format
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Archive\Format;

use PHPUnit\Framework\TestCase;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Format\PathEncoding;

/**
 * Covers the UTF-8 test, the printable rendering and the one-pass offender search.
 */
final class PathEncodingTest extends TestCase {

	/**
	 * Build a file header for the given path.
	 *
	 * @param string $path Relative path.
	 * @return EntryHeader
	 */
	private function file_header( string $path ): EntryHeader {
		return EntryHeader::for_file( $path, 1, 0o644, 1690000000, 'application/octet-stream', 0 );
	}

	/**
	 * Plain ASCII is recordable in the format.
	 *
	 * @return void
	 */
	public function test_ascii_is_encodable(): void {
		$this->assertTrue( PathEncoding::is_encodable( 'wp-content/uploads/cafe.txt' ) );
	}

	/**
	 * A legitimate multi-byte UTF-8 name is recordable in the format.
	 *
	 * @return void
	 */
	public function test_a_legitimate_multibyte_name_is_encodable(): void {
		$this->assertTrue( PathEncoding::is_encodable( "caf\u{e9}.txt" ) );
	}

	/**
	 * A lone Latin-1 byte is not valid UTF-8 and so is not recordable.
	 *
	 * @return void
	 */
	public function test_a_raw_latin_one_byte_is_not_encodable(): void {
		$this->assertFalse( PathEncoding::is_encodable( "caf\xe9.txt" ) );
	}

	/**
	 * A high byte renders as an octal escape, leaving the rest of the path readable.
	 *
	 * @return void
	 */
	public function test_printable_escapes_a_high_byte_as_octal(): void {
		$this->assertSame( 'wp-content/uploads/caf\351.txt', PathEncoding::printable( "wp-content/uploads/caf\xe9.txt" ) );
	}

	/**
	 * A newline, a backslash and the extreme high bytes are each escaped unambiguously.
	 *
	 * @return void
	 */
	public function test_printable_escapes_newline_backslash_and_extreme_bytes(): void {
		$this->assertSame( 'wp-content/a\nb\\\\c\200\377.txt', PathEncoding::printable( "wp-content/a\nb\\c\x80\xff.txt" ) );
	}

	/**
	 * The rendering of an invalid name is itself valid UTF-8, so it is safe in a message or JSON.
	 *
	 * @return void
	 */
	public function test_printable_output_is_always_valid_utf8(): void {
		$this->assertSame( 1, preg_match( '//u', PathEncoding::printable( "bad\xff\xfe\x80name" ) ) );
	}

	/**
	 * A set of recordable entries yields no offenders and a total of zero.
	 *
	 * @return void
	 */
	public function test_find_unencodable_returns_nothing_for_a_clean_set(): void {
		$result = PathEncoding::find_unencodable(
			array( $this->file_header( 'a.txt' ), $this->file_header( "caf\u{e9}.txt" ) ),
			5
		);

		$this->assertSame(
			array(
				'offenders' => array(),
				'total'     => 0,
			),
			$result
		);
	}

	/**
	 * An unencodable name is reported with the name fault and its escaped path.
	 *
	 * @return void
	 */
	public function test_find_unencodable_finds_a_bad_name(): void {
		$result = PathEncoding::find_unencodable( array( $this->file_header( 'ok.txt' ), $this->file_header( "bad\xe9.txt" ) ), 5 );

		$this->assertSame( 1, $result['total'] );
		$this->assertSame(
			array(
				array(
					'entry' => 'bad\351.txt',
					'fault' => PathEncoding::FAULT_NAME,
				),
			),
			$result['offenders']
		);
	}

	/**
	 * A symbolic link with a valid name but an unencodable target is reported by its own path.
	 *
	 * @return void
	 */
	public function test_find_unencodable_finds_a_bad_target_and_reports_the_entry_path(): void {
		$link   = EntryHeader::for_symlink( 'wp-content/link', "dest\xe9", 0 );
		$result = PathEncoding::find_unencodable( array( $link ), 5 );

		$this->assertSame( 1, $result['total'] );
		$this->assertSame(
			array(
				array(
					'entry' => 'wp-content/link',
					'fault' => PathEncoding::FAULT_TARGET,
				),
			),
			$result['offenders']
		);
	}

	/**
	 * An entry whose name and target are both unencodable is counted once, as a name fault.
	 *
	 * @return void
	 */
	public function test_find_unencodable_counts_a_doubly_bad_entry_once_as_a_name(): void {
		$link   = EntryHeader::for_symlink( "bad\xe9", "dest\xe9", 0 );
		$result = PathEncoding::find_unencodable( array( $link ), 5 );

		$this->assertSame( 1, $result['total'] );
		$this->assertCount( 1, $result['offenders'] );
		$this->assertSame( PathEncoding::FAULT_NAME, $result['offenders'][0]['fault'] );
	}

	/**
	 * Collection stops at the limit but the total keeps counting every offender.
	 *
	 * @return void
	 */
	public function test_find_unencodable_collects_up_to_the_limit_but_counts_them_all(): void {
		$headers = array();
		for ( $i = 0; $i < 7; $i++ ) {
			$headers[] = $this->file_header( "bad{$i}\xe9.txt" );
		}

		$result = PathEncoding::find_unencodable( $headers, 3 );

		$this->assertCount( 3, $result['offenders'] );
		$this->assertSame( 7, $result['total'] );
		$this->assertSame( 'bad0\351.txt', $result['offenders'][0]['entry'], 'Offenders are collected in the order the headers were given.' );
	}

	/**
	 * A database chunk has no path and no target, so it can never be an offender.
	 *
	 * @return void
	 */
	public function test_find_unencodable_ignores_a_db_chunk(): void {
		$chunk  = EntryHeader::for_db_chunk( 0, 'wp_options', 1, 10, 0 );
		$result = PathEncoding::find_unencodable( array( $chunk ), 5 );

		$this->assertSame( 0, $result['total'] );
	}

	/**
	 * The offender list marks a target fault, joins entries with commas and counts the unlisted rest.
	 *
	 * @return void
	 */
	public function test_describe_offenders_marks_targets_and_counts_the_remainder(): void {
		$text = PathEncoding::describe_offenders(
			array(
				'offenders' => array(
					array(
						'entry' => 'a',
						'fault' => PathEncoding::FAULT_NAME,
					),
					array(
						'entry' => 'b',
						'fault' => PathEncoding::FAULT_TARGET,
					),
				),
				'total'     => 5,
			)
		);

		$this->assertSame( 'a, b (its symbolic-link target) (and 3 more)', $text );
	}
}
