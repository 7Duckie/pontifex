<?php
/**
 * Behavioural tests for ByteOrder.
 *
 * @package Pontifex\Tests\Unit\Archive\Format
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Archive\Format;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pontifex\Archive\Format\ByteOrder;

/**
 * Behavioural tests for the ByteOrder class.
 *
 * Verifies the byte-order contract that every multi-byte integer in
 * the archive format depends on. The class invariants under test:
 *
 *  - Size constants match the byte widths (2, 4, 8).
 *  - Maximum value constants are exactly correct.
 *  - pack methods produce big-endian byte sequences, asserted against
 *    explicit hex literals so a switch to little-endian byte order
 *    would visibly fail.
 *  - unpack methods reverse the pack operation exactly.
 *  - pack rejects negative values and values above the type's maximum.
 *  - unpack rejects byte strings of the wrong length.
 *  - unpack_uint64 specifically rejects bytes representing values
 *    above PHP_INT_MAX (high bit set).
 */
final class ByteOrderTest extends TestCase {

	/**
	 * The byte-size constants must match the byte widths of their types.
	 *
	 * @return void
	 */
	public function test_size_constants_match_byte_widths(): void {
		$this->assertSame( 2, ByteOrder::UINT16_SIZE );
		$this->assertSame( 4, ByteOrder::UINT32_SIZE );
		$this->assertSame( 8, ByteOrder::UINT64_SIZE );
	}

	/**
	 * The maximum-value constants must hold the canonical type maxima.
	 *
	 * @return void
	 */
	public function test_max_value_constants_are_correct(): void {
		$this->assertSame( 65535, ByteOrder::MAX_UINT16 );
		$this->assertSame( 4294967295, ByteOrder::MAX_UINT32 );
		$this->assertSame( PHP_INT_MAX, ByteOrder::MAX_UINT64 );
	}

	// ============================================================
	// uint16
	// ============================================================

	/**
	 * Packing zero as a uint16 must produce two zero bytes.
	 *
	 * @return void
	 */
	public function test_pack_uint16_zero(): void {
		$this->assertSame( "\x00\x00", ByteOrder::pack_uint16( 0 ) );
	}

	/**
	 * Packing 0x1234 as a uint16 must produce 0x12 then 0x34 (big-endian, high byte first).
	 *
	 * This is the canonical byte-order check. If pack ever switched
	 * to little-endian, this test would fail with "\x34\x12" on the
	 * actual side.
	 *
	 * @return void
	 */
	public function test_pack_uint16_uses_big_endian_byte_order(): void {
		$this->assertSame( "\x12\x34", ByteOrder::pack_uint16( 0x1234 ) );
	}

	/**
	 * Packing the maximum uint16 must produce two 0xFF bytes.
	 *
	 * @return void
	 */
	public function test_pack_uint16_max_value(): void {
		$this->assertSame( "\xFF\xFF", ByteOrder::pack_uint16( ByteOrder::MAX_UINT16 ) );
	}

	/**
	 * Unpacking two zero bytes must yield zero.
	 *
	 * @return void
	 */
	public function test_unpack_uint16_zero(): void {
		$this->assertSame( 0, ByteOrder::unpack_uint16( "\x00\x00" ) );
	}

	/**
	 * Unpacking the canonical 0x12 0x34 bytes must yield 0x1234.
	 *
	 * Symmetric counterpart to the big-endian pack check.
	 *
	 * @return void
	 */
	public function test_unpack_uint16_reverses_big_endian_packing(): void {
		$this->assertSame( 0x1234, ByteOrder::unpack_uint16( "\x12\x34" ) );
	}

	/**
	 * Pack-then-unpack of a known uint16 value must round-trip exactly.
	 *
	 * @return void
	 */
	public function test_uint16_round_trip(): void {
		foreach ( array( 0, 1, 127, 128, 255, 256, 32767, 32768, 65535 ) as $value ) {
			$packed = ByteOrder::pack_uint16( $value );
			$this->assertSame( $value, ByteOrder::unpack_uint16( $packed ), "round trip failed for {$value}" );
		}
	}

	/**
	 * Packing a negative value as uint16 must raise InvalidArgumentException.
	 *
	 * @return void
	 */
	public function test_pack_uint16_rejects_negative(): void {
		$this->expectException( InvalidArgumentException::class );

		ByteOrder::pack_uint16( -1 );
	}

	/**
	 * Packing a value above 0xFFFF as uint16 must raise InvalidArgumentException.
	 *
	 * @return void
	 */
	public function test_pack_uint16_rejects_overflow(): void {
		$this->expectException( InvalidArgumentException::class );

		ByteOrder::pack_uint16( ByteOrder::MAX_UINT16 + 1 );
	}

	/**
	 * Unpacking bytes of length other than two must raise InvalidArgumentException.
	 *
	 * @return void
	 */
	public function test_unpack_uint16_rejects_wrong_length(): void {
		$this->expectException( InvalidArgumentException::class );

		ByteOrder::unpack_uint16( "\x00" );
	}

	// ============================================================
	// uint32
	// ============================================================

	/**
	 * Packing zero as a uint32 must produce four zero bytes.
	 *
	 * @return void
	 */
	public function test_pack_uint32_zero(): void {
		$this->assertSame( "\x00\x00\x00\x00", ByteOrder::pack_uint32( 0 ) );
	}

	/**
	 * Packing 0xDEADBEEF as a uint32 must produce 0xDE 0xAD 0xBE 0xEF in that order.
	 *
	 * The canonical big-endian check for uint32. A little-endian
	 * implementation would produce "\xEF\xBE\xAD\xDE", visibly wrong.
	 *
	 * @return void
	 */
	public function test_pack_uint32_uses_big_endian_byte_order(): void {
		$this->assertSame( "\xDE\xAD\xBE\xEF", ByteOrder::pack_uint32( 0xDEADBEEF ) );
	}

	/**
	 * Packing the maximum uint32 must produce four 0xFF bytes.
	 *
	 * @return void
	 */
	public function test_pack_uint32_max_value(): void {
		$this->assertSame( "\xFF\xFF\xFF\xFF", ByteOrder::pack_uint32( ByteOrder::MAX_UINT32 ) );
	}

	/**
	 * Unpacking four zero bytes must yield zero.
	 *
	 * @return void
	 */
	public function test_unpack_uint32_zero(): void {
		$this->assertSame( 0, ByteOrder::unpack_uint32( "\x00\x00\x00\x00" ) );
	}

	/**
	 * Unpacking 0xDE 0xAD 0xBE 0xEF must yield 0xDEADBEEF.
	 *
	 * @return void
	 */
	public function test_unpack_uint32_reverses_big_endian_packing(): void {
		$this->assertSame( 0xDEADBEEF, ByteOrder::unpack_uint32( "\xDE\xAD\xBE\xEF" ) );
	}

	/**
	 * Pack-then-unpack of a known uint32 value must round-trip exactly.
	 *
	 * @return void
	 */
	public function test_uint32_round_trip(): void {
		foreach ( array( 0, 1, 0xFF, 0x100, 0xFFFF, 0x10000, 0x12345678, 0xFFFFFFFF ) as $value ) {
			$packed = ByteOrder::pack_uint32( $value );
			$this->assertSame( $value, ByteOrder::unpack_uint32( $packed ), "round trip failed for {$value}" );
		}
	}

	/**
	 * Packing a negative value as uint32 must raise InvalidArgumentException.
	 *
	 * @return void
	 */
	public function test_pack_uint32_rejects_negative(): void {
		$this->expectException( InvalidArgumentException::class );

		ByteOrder::pack_uint32( -1 );
	}

	/**
	 * Packing a value above 0xFFFFFFFF as uint32 must raise InvalidArgumentException.
	 *
	 * @return void
	 */
	public function test_pack_uint32_rejects_overflow(): void {
		$this->expectException( InvalidArgumentException::class );

		ByteOrder::pack_uint32( ByteOrder::MAX_UINT32 + 1 );
	}

	/**
	 * Unpacking bytes of length other than four must raise InvalidArgumentException.
	 *
	 * @return void
	 */
	public function test_unpack_uint32_rejects_wrong_length(): void {
		$this->expectException( InvalidArgumentException::class );

		ByteOrder::unpack_uint32( "\x00\x00\x00" );
	}

	// ============================================================
	// uint64
	// ============================================================

	/**
	 * Packing zero as a uint64 must produce eight zero bytes.
	 *
	 * @return void
	 */
	public function test_pack_uint64_zero(): void {
		$this->assertSame( "\x00\x00\x00\x00\x00\x00\x00\x00", ByteOrder::pack_uint64( 0 ) );
	}

	/**
	 * Packing 0x123456789ABCDEF0 as a uint64 must produce the bytes in that exact order.
	 *
	 * The canonical big-endian check for uint64. The expected byte
	 * sequence is high nibble first across all eight bytes.
	 *
	 * @return void
	 */
	public function test_pack_uint64_uses_big_endian_byte_order(): void {
		$this->assertSame(
			"\x12\x34\x56\x78\x9A\xBC\xDE\xF0",
			ByteOrder::pack_uint64( 0x123456789ABCDEF0 )
		);
	}

	/**
	 * Packing PHP_INT_MAX as a uint64 must produce 0x7F followed by seven 0xFF bytes.
	 *
	 * Verifies the upper boundary of Pontifex's supported uint64 range.
	 *
	 * @return void
	 */
	public function test_pack_uint64_php_int_max(): void {
		$this->assertSame(
			"\x7F\xFF\xFF\xFF\xFF\xFF\xFF\xFF",
			ByteOrder::pack_uint64( PHP_INT_MAX )
		);
	}

	/**
	 * Unpacking eight zero bytes must yield zero.
	 *
	 * @return void
	 */
	public function test_unpack_uint64_zero(): void {
		$this->assertSame( 0, ByteOrder::unpack_uint64( "\x00\x00\x00\x00\x00\x00\x00\x00" ) );
	}

	/**
	 * Unpacking 0x12 ... 0xF0 must yield 0x123456789ABCDEF0.
	 *
	 * @return void
	 */
	public function test_unpack_uint64_reverses_big_endian_packing(): void {
		$this->assertSame(
			0x123456789ABCDEF0,
			ByteOrder::unpack_uint64( "\x12\x34\x56\x78\x9A\xBC\xDE\xF0" )
		);
	}

	/**
	 * Pack-then-unpack of a known uint64 value must round-trip exactly.
	 *
	 * @return void
	 */
	public function test_uint64_round_trip(): void {
		foreach ( array( 0, 1, 0xFFFF, 0xFFFFFFFF, 0x123456789ABCDEF0, PHP_INT_MAX ) as $value ) {
			$packed = ByteOrder::pack_uint64( $value );
			$this->assertSame( $value, ByteOrder::unpack_uint64( $packed ), "round trip failed for {$value}" );
		}
	}

	/**
	 * Packing a negative value as uint64 must raise InvalidArgumentException.
	 *
	 * @return void
	 */
	public function test_pack_uint64_rejects_negative(): void {
		$this->expectException( InvalidArgumentException::class );

		ByteOrder::pack_uint64( -1 );
	}

	/**
	 * Unpacking bytes of length other than eight must raise InvalidArgumentException.
	 *
	 * @return void
	 */
	public function test_unpack_uint64_rejects_wrong_length(): void {
		$this->expectException( InvalidArgumentException::class );

		ByteOrder::unpack_uint64( "\x00\x00\x00\x00\x00\x00\x00" );
	}

	/**
	 * Unpacking bytes with the high bit set must raise InvalidArgumentException.
	 *
	 * Pontifex caps uint64 at PHP_INT_MAX (2^63 - 1). Bytes that
	 * would decode to a value with the high bit set (2^63 or above)
	 * are rejected to avoid surfacing negative integers to callers.
	 *
	 * @return void
	 */
	public function test_unpack_uint64_rejects_high_bit_set(): void {
		$this->expectException( InvalidArgumentException::class );

		ByteOrder::unpack_uint64( "\x80\x00\x00\x00\x00\x00\x00\x00" );
	}

	/**
	 * Unpacking all-0xFF bytes must also be rejected (high bit is set).
	 *
	 * @return void
	 */
	public function test_unpack_uint64_rejects_all_ones(): void {
		$this->expectException( InvalidArgumentException::class );

		ByteOrder::unpack_uint64( "\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF" );
	}
}
