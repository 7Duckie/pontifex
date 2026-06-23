<?php
/**
 * Tests for the CodecId decomposition helpers.
 *
 * @package Pontifex\Tests\Unit\Archive\Codec
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Archive\Codec;

use PHPUnit\Framework\TestCase;
use Pontifex\Archive\Codec\CodecId;

/**
 * Tests for {@see CodecId}.
 *
 * Verifies the two-byte codec-id split (high byte = encryption, low byte =
 * compression) the writer and reader both depend on. An error here would let
 * the two sides disagree and corrupt an archive, so the split is pinned with
 * the spec's example ids (0x0102 = AES-256-GCM over zstd).
 */
final class CodecIdTest extends TestCase {

	/**
	 * The AES-256-GCM family must be the high-byte value 0x0100.
	 *
	 * @return void
	 */
	public function test_aes_gcm_family_constant(): void {
		$this->assertSame( 0x0100, CodecId::ENCRYPTION_AES_GCM );
	}

	/**
	 * The compression() helper must return the low byte.
	 *
	 * @return void
	 */
	public function test_compression_returns_low_byte(): void {
		$this->assertSame( 0x0002, CodecId::compression( 0x0102 ) );
		$this->assertSame( 0x0001, CodecId::compression( 0x0101 ) );
		$this->assertSame( 0x0000, CodecId::compression( 0x0100 ) );
		$this->assertSame( 0x0002, CodecId::compression( 0x0002 ) );
	}

	/**
	 * The encryption_family() helper must return the high byte.
	 *
	 * @return void
	 */
	public function test_encryption_family_returns_high_byte(): void {
		$this->assertSame( 0x0100, CodecId::encryption_family( 0x0102 ) );
		$this->assertSame( 0x0100, CodecId::encryption_family( 0x0100 ) );
		$this->assertSame( 0x0000, CodecId::encryption_family( 0x0002 ) );
	}

	/**
	 * The is_encrypted() helper must be true exactly when the high byte is non-zero.
	 *
	 * @return void
	 */
	public function test_is_encrypted(): void {
		$this->assertTrue( CodecId::is_encrypted( 0x0100 ) );
		$this->assertTrue( CodecId::is_encrypted( 0x0101 ) );
		$this->assertTrue( CodecId::is_encrypted( 0x0102 ) );
		$this->assertFalse( CodecId::is_encrypted( 0x0000 ) );
		$this->assertFalse( CodecId::is_encrypted( 0x0001 ) );
		$this->assertFalse( CodecId::is_encrypted( 0x0002 ) );
	}

	/**
	 * The with_aes_gcm() helper must set the encryption-family byte, upgrading a compression id.
	 *
	 * @return void
	 */
	public function test_with_aes_gcm_sets_high_byte(): void {
		$this->assertSame( 0x0100, CodecId::with_aes_gcm( 0x0000 ) );
		$this->assertSame( 0x0101, CodecId::with_aes_gcm( 0x0001 ) );
		$this->assertSame( 0x0102, CodecId::with_aes_gcm( 0x0002 ) );
	}

	/**
	 * Upgrading with with_aes_gcm() then stripping with compression() must recover the compression id.
	 *
	 * @return void
	 */
	public function test_with_aes_gcm_and_compression_are_inverse(): void {
		foreach ( array( 0x0000, 0x0001, 0x0002 ) as $compression ) {
			$encrypted = CodecId::with_aes_gcm( $compression );
			$this->assertTrue( CodecId::is_encrypted( $encrypted ) );
			$this->assertSame( $compression, CodecId::compression( $encrypted ) );
		}
	}
}
