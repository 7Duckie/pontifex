<?php
/**
 * Behavioural tests for the Footer value object.
 *
 * @package Pontifex\Tests\Unit\Archive\Format
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Archive\Format;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pontifex\Archive\Format\Footer;
use Pontifex\Archive\Integrity\Sha256;

/**
 * Behavioural tests for the Footer class.
 *
 * Verifies the footer value object's invariants:
 *
 *  - The SIZE constant is 64 and ZERO_SALT is 16 null bytes.
 *  - The constructor validates non-negative offsets, the exact
 *    SHA-256 digest size for manifest_hash (32 bytes), and the
 *    fixed Argon2id salt size (16 bytes).
 *  - Accessor methods return what was passed to the constructor.
 *  - to_bytes() produces exactly 64 bytes in the layout the spec
 *    mandates: offset (8) + length (8) + hash (32) + salt (16).
 *  - The v0.1.0 zero-salt pattern round-trips correctly.
 *  - from_bytes() rejects wrong-length input and otherwise
 *    reconstructs the original Footer.
 */
final class FooterTest extends TestCase {

	/**
	 * A 32-byte test hash used as a stand-in for a real manifest digest.
	 *
	 * @var string
	 */
	private const TEST_HASH = "\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x20";

	/**
	 * The SIZE constant must be 64, matching the on-disk footer layout.
	 *
	 * @return void
	 */
	public function test_size_constant_is_sixty_four(): void {
		$this->assertSame( 64, Footer::SIZE );
	}

	/**
	 * The ARGON2ID_SALT_SIZE constant must be 16.
	 *
	 * @return void
	 */
	public function test_argon2id_salt_size_is_sixteen(): void {
		$this->assertSame( 16, Footer::ARGON2ID_SALT_SIZE );
	}

	/**
	 * The ZERO_SALT constant must be exactly 16 null bytes.
	 *
	 * @return void
	 */
	public function test_zero_salt_is_sixteen_null_bytes(): void {
		$this->assertSame( 16, strlen( Footer::ZERO_SALT ) );
		$this->assertSame( str_repeat( "\x00", 16 ), Footer::ZERO_SALT );
	}

	/**
	 * The constructor must accept valid values and store them via the accessors.
	 *
	 * @return void
	 */
	public function test_constructor_accepts_valid_values(): void {
		$footer = new Footer( 100, 200, self::TEST_HASH, Footer::ZERO_SALT );

		$this->assertSame( 100, $footer->manifest_offset() );
		$this->assertSame( 200, $footer->manifest_length() );
		$this->assertSame( self::TEST_HASH, $footer->manifest_hash() );
		$this->assertSame( Footer::ZERO_SALT, $footer->argon2id_salt() );
	}

	/**
	 * The constructor must reject a negative manifest offset.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_negative_offset(): void {
		$this->expectException( InvalidArgumentException::class );

		new Footer( -1, 200, self::TEST_HASH, Footer::ZERO_SALT );
	}

	/**
	 * The constructor must reject a negative manifest length.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_negative_length(): void {
		$this->expectException( InvalidArgumentException::class );

		new Footer( 100, -1, self::TEST_HASH, Footer::ZERO_SALT );
	}

	/**
	 * The constructor must reject a manifest hash of the wrong length.
	 *
	 * The hash field is fixed at one SHA-256 digest (32 bytes).
	 *
	 * @return void
	 */
	public function test_constructor_rejects_wrong_hash_length(): void {
		$this->expectException( InvalidArgumentException::class );

		new Footer( 100, 200, str_repeat( "\x00", 31 ), Footer::ZERO_SALT );
	}

	/**
	 * The constructor must reject an argon2id salt of the wrong length.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_wrong_salt_length(): void {
		$this->expectException( InvalidArgumentException::class );

		new Footer( 100, 200, self::TEST_HASH, str_repeat( "\x00", 15 ) );
	}

	/**
	 * Serialisation must produce exactly 64 bytes regardless of field values.
	 *
	 * @return void
	 */
	public function test_to_bytes_produces_sixty_four_bytes(): void {
		$footer = new Footer( 0, 0, self::TEST_HASH, Footer::ZERO_SALT );

		$this->assertSame( 64, strlen( $footer->to_bytes() ) );
	}

	/**
	 * Serialisation must lay out the four fields in the canonical order.
	 *
	 * Verifies that bytes 0-7 hold manifest_offset, bytes 8-15 hold
	 * manifest_length, bytes 16-47 hold manifest_hash, and bytes
	 * 48-63 hold argon2id_salt.
	 *
	 * @return void
	 */
	public function test_to_bytes_field_layout_is_correct(): void {
		$salt   = str_repeat( "\xAB", 16 );
		$footer = new Footer( 0x1122334455667788, 0x7766554433221100, self::TEST_HASH, $salt );
		$bytes  = $footer->to_bytes();

		// manifest_offset (bytes 0-7), big-endian.
		$this->assertSame( "\x11\x22\x33\x44\x55\x66\x77\x88", substr( $bytes, 0, 8 ) );
		// manifest_length (bytes 8-15), big-endian.
		$this->assertSame( "\x77\x66\x55\x44\x33\x22\x11\x00", substr( $bytes, 8, 8 ) );
		// manifest_hash (bytes 16-47), 32 raw bytes.
		$this->assertSame( self::TEST_HASH, substr( $bytes, 16, Sha256::DIGEST_SIZE ) );
		// argon2id_salt (bytes 48-63), 16 raw bytes.
		$this->assertSame( $salt, substr( $bytes, 48, Footer::ARGON2ID_SALT_SIZE ) );
	}

	/**
	 * Footer::from_bytes() must reject input that is not exactly 64 bytes long.
	 *
	 * @return void
	 */
	public function test_from_bytes_rejects_wrong_length(): void {
		$this->expectException( InvalidArgumentException::class );

		Footer::from_bytes( str_repeat( "\x00", 63 ) );
	}

	/**
	 * Round-trip through serialisation must reconstruct the original footer exactly.
	 *
	 * @return void
	 */
	public function test_round_trip_with_nonzero_values(): void {
		$salt     = str_repeat( "\xCD", 16 );
		$original = new Footer( 1024, 512, self::TEST_HASH, $salt );
		$parsed   = Footer::from_bytes( $original->to_bytes() );

		$this->assertSame( $original->manifest_offset(), $parsed->manifest_offset() );
		$this->assertSame( $original->manifest_length(), $parsed->manifest_length() );
		$this->assertSame( $original->manifest_hash(), $parsed->manifest_hash() );
		$this->assertSame( $original->argon2id_salt(), $parsed->argon2id_salt() );
	}

	/**
	 * The v0.1.0 zero-salt round-trip pattern must work end-to-end.
	 *
	 * Archives at v0.1.0 are unencrypted and use Footer::ZERO_SALT
	 * for the salt slot. This test locks that pattern as a supported
	 * use case.
	 *
	 * @return void
	 */
	public function test_round_trip_with_v0_1_0_zero_salt_pattern(): void {
		$original = new Footer( 4096, 256, self::TEST_HASH, Footer::ZERO_SALT );
		$parsed   = Footer::from_bytes( $original->to_bytes() );

		$this->assertSame( Footer::ZERO_SALT, $parsed->argon2id_salt() );
		$this->assertSame( self::TEST_HASH, $parsed->manifest_hash() );
		$this->assertSame( 4096, $parsed->manifest_offset() );
		$this->assertSame( 256, $parsed->manifest_length() );
	}
}
