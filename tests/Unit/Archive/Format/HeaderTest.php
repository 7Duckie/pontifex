<?php
/**
 * Behavioural tests for the Header value object.
 *
 * @package Pontifex\Tests\Unit\Archive\Format
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Archive\Format;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pontifex\Archive\Format\Header;

/**
 * Behavioural tests for the Header class.
 *
 * Verifies the header value object's invariants:
 *
 *  - The magic bytes constant matches the format spec exactly.
 *  - Size, version, and flag bit constants are correct.
 *  - Header::current_version() returns the v1.0 header used by
 *    v0.1.0 writers.
 *  - The constructor validates range, rejects negatives, rejects
 *    values above the on-disk field width, and rejects reserved
 *    flag bits.
 *  - Flag predicates report each defined bit correctly.
 *  - to_bytes() produces exactly 16 bytes in the spec-mandated
 *    layout.
 *  - from_bytes() rejects wrong-length input, mismatched magic, and
 *    reserved flag bits, and otherwise reconstructs the original
 *    Header exactly.
 */
final class HeaderTest extends TestCase {

	/**
	 * The magic constant must be exactly the eight bytes "WPMIG\x00\x00\x01".
	 *
	 * @return void
	 */
	public function test_magic_constant_is_the_canonical_eight_bytes(): void {
		$this->assertSame( "WPMIG\x00\x00\x01", Header::MAGIC );
		$this->assertSame( 8, strlen( Header::MAGIC ) );
	}

	/**
	 * The SIZE constant must be 16, matching the on-disk header layout.
	 *
	 * @return void
	 */
	public function test_size_constant_is_sixteen(): void {
		$this->assertSame( 16, Header::SIZE );
	}

	/**
	 * The format version constants must hold the v1.0 values.
	 *
	 * @return void
	 */
	public function test_format_version_constants(): void {
		$this->assertSame( 1, Header::FORMAT_MAJOR_V1 );
		$this->assertSame( 0, Header::FORMAT_MINOR_V1_0 );
	}

	/**
	 * The flag bit constants must hold the canonical bit positions.
	 *
	 * @return void
	 */
	public function test_flag_bit_constants(): void {
		$this->assertSame( 0x00000001, Header::FLAG_ENCRYPTED );
		$this->assertSame( 0x00000002, Header::FLAG_SIGNED );
		$this->assertSame( 0x00000004, Header::FLAG_PROVENANCE_ENCRYPTED );
		$this->assertSame( 0x00000007, Header::ALL_DEFINED_FLAGS );
	}

	/**
	 * Header::current_version() must return a v1.0 header with no flags set.
	 *
	 * @return void
	 */
	public function test_current_version_returns_v1_0_with_no_flags(): void {
		$header = Header::current_version();

		$this->assertSame( 1, $header->major() );
		$this->assertSame( 0, $header->minor() );
		$this->assertSame( 0, $header->flags() );
		$this->assertFalse( $header->is_encrypted() );
		$this->assertFalse( $header->is_signed() );
		$this->assertFalse( $header->is_provenance_encrypted() );
	}

	/**
	 * The constructor must accept any in-range values for the three fields.
	 *
	 * @return void
	 */
	public function test_constructor_accepts_in_range_values(): void {
		$header = new Header( 1, 0, Header::FLAG_ENCRYPTED );

		$this->assertSame( 1, $header->major() );
		$this->assertSame( 0, $header->minor() );
		$this->assertSame( Header::FLAG_ENCRYPTED, $header->flags() );
	}

	/**
	 * The constructor must reject a negative major version.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_negative_major(): void {
		$this->expectException( InvalidArgumentException::class );

		new Header( -1, 0, 0 );
	}

	/**
	 * The constructor must reject a major version above the uint16 maximum.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_major_above_uint16(): void {
		$this->expectException( InvalidArgumentException::class );

		new Header( 65536, 0, 0 );
	}

	/**
	 * The constructor must reject a negative minor version.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_negative_minor(): void {
		$this->expectException( InvalidArgumentException::class );

		new Header( 1, -1, 0 );
	}

	/**
	 * The constructor must reject negative flags.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_negative_flags(): void {
		$this->expectException( InvalidArgumentException::class );

		new Header( 1, 0, -1 );
	}

	/**
	 * The constructor must reject flag values that set reserved bits (bit 3 and above).
	 *
	 * Catches a buggy writer trying to set undefined flag bits
	 * before any bytes hit disk.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_reserved_flag_bits(): void {
		$this->expectException( InvalidArgumentException::class );

		new Header( 1, 0, 0x00000008 );
	}

	/**
	 * The is_encrypted() predicate must return true when FLAG_ENCRYPTED is set.
	 *
	 * @return void
	 */
	public function test_is_encrypted_reflects_flag_bit_zero(): void {
		$header = new Header( 1, 0, Header::FLAG_ENCRYPTED );

		$this->assertTrue( $header->is_encrypted() );
		$this->assertFalse( $header->is_signed() );
		$this->assertFalse( $header->is_provenance_encrypted() );
	}

	/**
	 * The is_signed() predicate must return true when FLAG_SIGNED is set.
	 *
	 * @return void
	 */
	public function test_is_signed_reflects_flag_bit_one(): void {
		$header = new Header( 1, 0, Header::FLAG_SIGNED );

		$this->assertFalse( $header->is_encrypted() );
		$this->assertTrue( $header->is_signed() );
		$this->assertFalse( $header->is_provenance_encrypted() );
	}

	/**
	 * The is_provenance_encrypted() predicate must return true when FLAG_PROVENANCE_ENCRYPTED is set.
	 *
	 * @return void
	 */
	public function test_is_provenance_encrypted_reflects_flag_bit_two(): void {
		$header = new Header( 1, 0, Header::FLAG_PROVENANCE_ENCRYPTED );

		$this->assertFalse( $header->is_encrypted() );
		$this->assertFalse( $header->is_signed() );
		$this->assertTrue( $header->is_provenance_encrypted() );
	}

	/**
	 * Serialising the current-version header must produce the canonical 16 bytes.
	 *
	 * @return void
	 */
	public function test_to_bytes_for_current_version(): void {
		$bytes = Header::current_version()->to_bytes();

		$this->assertSame( 16, strlen( $bytes ) );
		$this->assertSame(
			"WPMIG\x00\x00\x01\x00\x01\x00\x00\x00\x00\x00\x00",
			$bytes
		);
	}

	/**
	 * Serialising a header with all defined flags set must place each bit correctly.
	 *
	 * @return void
	 */
	public function test_to_bytes_with_all_defined_flags(): void {
		$header = new Header( 1, 0, Header::ALL_DEFINED_FLAGS );
		$bytes  = $header->to_bytes();

		$this->assertSame(
			"WPMIG\x00\x00\x01\x00\x01\x00\x00\x00\x00\x00\x07",
			$bytes
		);
	}

	/**
	 * Header::from_bytes() must reject input shorter or longer than 16 bytes.
	 *
	 * @return void
	 */
	public function test_from_bytes_rejects_wrong_length(): void {
		$this->expectException( InvalidArgumentException::class );

		Header::from_bytes( "WPMIG\x00\x00\x01\x00\x01\x00\x00\x00\x00\x00" );
	}

	/**
	 * Header::from_bytes() must reject input whose magic does not match.
	 *
	 * @return void
	 */
	public function test_from_bytes_rejects_invalid_magic(): void {
		$this->expectException( InvalidArgumentException::class );

		Header::from_bytes( "WPRESS\x00\x01\x00\x01\x00\x00\x00\x00\x00\x00" );
	}

	/**
	 * Header::from_bytes() must reject bytes whose flags field sets reserved bits.
	 *
	 * @return void
	 */
	public function test_from_bytes_rejects_reserved_flag_bits(): void {
		$this->expectException( InvalidArgumentException::class );

		Header::from_bytes( "WPMIG\x00\x00\x01\x00\x01\x00\x00\x00\x00\x00\x08" );
	}

	/**
	 * Round-trip of the current-version header must reconstruct the original exactly.
	 *
	 * @return void
	 */
	public function test_round_trip_current_version(): void {
		$original = Header::current_version();
		$parsed   = Header::from_bytes( $original->to_bytes() );

		$this->assertSame( $original->major(), $parsed->major() );
		$this->assertSame( $original->minor(), $parsed->minor() );
		$this->assertSame( $original->flags(), $parsed->flags() );
	}

	/**
	 * Round-trip with every defined flag bit set must reconstruct the original exactly.
	 *
	 * @return void
	 */
	public function test_round_trip_with_all_defined_flags(): void {
		$original = new Header( 1, 0, Header::ALL_DEFINED_FLAGS );
		$parsed   = Header::from_bytes( $original->to_bytes() );

		$this->assertSame( Header::ALL_DEFINED_FLAGS, $parsed->flags() );
		$this->assertTrue( $parsed->is_encrypted() );
		$this->assertTrue( $parsed->is_signed() );
		$this->assertTrue( $parsed->is_provenance_encrypted() );
	}
}
