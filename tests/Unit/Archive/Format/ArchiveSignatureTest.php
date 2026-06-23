<?php
/**
 * Unit tests for the archive signature block value object.
 *
 * @package Pontifex\Tests\Unit\Archive\Format
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Archive\Format;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pontifex\Archive\Format\ArchiveSignature;
use Pontifex\Archive\Format\ByteOrder;

/**
 * Behavioural coverage of {@see ArchiveSignature}: the 100-byte on-disk layout,
 * its round trip, and rejection of malformed blocks.
 *
 * The fixed-size keys here are low-entropy repeated bytes — the block validates
 * length and the sig-length field, not cryptographic content.
 */
final class ArchiveSignatureTest extends TestCase {

	/**
	 * A 32-byte key id for the tests.
	 *
	 * @return string A KEY_ID_SIZE-byte string.
	 */
	private static function key_id(): string {
		return str_repeat( 'k', ArchiveSignature::KEY_ID_SIZE );
	}

	/**
	 * A 64-byte signature for the tests.
	 *
	 * @return string A SIGNATURE_SIZE-byte string.
	 */
	private static function signature(): string {
		return str_repeat( 's', ArchiveSignature::SIGNATURE_SIZE );
	}

	/**
	 * The to_bytes then from_bytes round trip reproduces every field.
	 *
	 * @return void
	 */
	public function test_round_trip_reproduces_fields(): void {
		$block  = new ArchiveSignature( self::key_id(), self::signature() );
		$parsed = ArchiveSignature::from_bytes( $block->to_bytes() );

		$this->assertSame( self::key_id(), $parsed->key_id() );
		$this->assertSame( self::signature(), $parsed->signature() );
	}

	/**
	 * The serialised block is exactly 100 bytes laid out as key id, sig length, sig bytes.
	 *
	 * @return void
	 */
	public function test_to_bytes_layout(): void {
		$bytes = ( new ArchiveSignature( self::key_id(), self::signature() ) )->to_bytes();

		$this->assertSame( ArchiveSignature::SIZE, strlen( $bytes ) );
		$this->assertSame( self::key_id(), substr( $bytes, 0, ArchiveSignature::KEY_ID_SIZE ) );
		$this->assertSame(
			ArchiveSignature::SIGNATURE_SIZE,
			ByteOrder::unpack_uint32( substr( $bytes, ArchiveSignature::KEY_ID_SIZE, ArchiveSignature::SIG_LENGTH_PREFIX_SIZE ) )
		);
		$this->assertSame( self::signature(), substr( $bytes, ArchiveSignature::KEY_ID_SIZE + ArchiveSignature::SIG_LENGTH_PREFIX_SIZE, ArchiveSignature::SIGNATURE_SIZE ) );
	}

	/**
	 * A key id of the wrong length is rejected at construction.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_wrong_key_id_length(): void {
		$this->expectException( InvalidArgumentException::class );

		new ArchiveSignature( str_repeat( 'k', 10 ), self::signature() );
	}

	/**
	 * A signature of the wrong length is rejected at construction.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_wrong_signature_length(): void {
		$this->expectException( InvalidArgumentException::class );

		new ArchiveSignature( self::key_id(), str_repeat( 's', 10 ) );
	}

	/**
	 * A block of the wrong total length is rejected by from_bytes.
	 *
	 * @return void
	 */
	public function test_from_bytes_rejects_wrong_length(): void {
		$this->expectException( InvalidArgumentException::class );

		ArchiveSignature::from_bytes( str_repeat( 'x', ArchiveSignature::SIZE - 1 ) );
	}

	/**
	 * A sig-length field that is not 0x40 is rejected by from_bytes.
	 *
	 * @return void
	 */
	public function test_from_bytes_rejects_a_non_ed25519_sig_length(): void {
		// A well-formed 100 bytes, but with the sig length claiming 32 rather than 64.
		$bytes = self::key_id() . ByteOrder::pack_uint32( 32 ) . self::signature();

		$this->expectException( InvalidArgumentException::class );

		ArchiveSignature::from_bytes( $bytes );
	}
}
