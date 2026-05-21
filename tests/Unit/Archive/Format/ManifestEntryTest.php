<?php
/**
 * Behavioural tests for the ManifestEntry value object.
 *
 * @package Pontifex\Tests\Unit\Archive\Format
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Archive\Format;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Format\ManifestEntry;
use Pontifex\Archive\Integrity\Sha256;

/**
 * Behavioural tests for the ManifestEntry class.
 *
 * Verifies the manifest-entry invariants:
 *
 *  - Constructor validates offset, codec_id range, payload_length,
 *    and the exact SHA-256 byte length of payload_hash.
 *  - Accessors return the constructed values.
 *  - to_canonical_data produces the canonical field order and
 *    hex-encodes the payload_hash as 64 lowercase characters.
 *  - from_canonical_data validates that all five fields are
 *    present, integer fields are integers, the entry sub-object
 *    is a valid EntryHeader payload, and the hash is exactly 64
 *    lowercase hex characters.
 *  - Round-trip through to_canonical_data + from_canonical_data
 *    preserves every field.
 */
final class ManifestEntryTest extends TestCase {

	/**
	 * Standard test hash: 32 incrementing bytes from 0x01 to 0x20.
	 *
	 * @var string
	 */
	private const TEST_HASH = "\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x20";

	/**
	 * Hex encoding of TEST_HASH.
	 *
	 * @var string
	 */
	private const TEST_HASH_HEX = '0102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f20';

	/**
	 * Build a canonical valid ManifestEntry fixture.
	 *
	 * @return ManifestEntry A valid ManifestEntry with known field values.
	 */
	private function valid_entry(): ManifestEntry {
		return new ManifestEntry(
			16,
			EntryHeader::for_file( 'wp-config.php', 1234, 0644, 1690000000 ),
			1,
			500,
			self::TEST_HASH
		);
	}

	/**
	 * The MAX_CODEC_ID constant must hold the uint16 ceiling (65535).
	 *
	 * @return void
	 */
	public function test_max_codec_id_constant(): void {
		$this->assertSame( 0xFFFF, ManifestEntry::MAX_CODEC_ID );
		$this->assertSame( 65535, ManifestEntry::MAX_CODEC_ID );
	}

	/**
	 * The hex-length constant must be exactly twice the SHA-256 byte size.
	 *
	 * @return void
	 */
	public function test_hash_hex_length_constant(): void {
		$this->assertSame( 64, ManifestEntry::HASH_HEX_LENGTH );
		$this->assertSame( 2 * Sha256::DIGEST_SIZE, ManifestEntry::HASH_HEX_LENGTH );
	}

	/**
	 * The constructor must accept valid values and expose them via accessors.
	 *
	 * @return void
	 */
	public function test_constructor_accepts_valid_values(): void {
		$header = EntryHeader::for_file( 'wp-config.php', 1234, 0644, 1690000000 );
		$entry  = new ManifestEntry( 16, $header, 1, 500, self::TEST_HASH );

		$this->assertSame( 16, $entry->offset() );
		$this->assertSame( $header, $entry->entry_header() );
		$this->assertSame( 1, $entry->codec_id() );
		$this->assertSame( 500, $entry->payload_length() );
		$this->assertSame( self::TEST_HASH, $entry->payload_hash() );
	}

	/**
	 * The constructor must reject a negative offset.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_negative_offset(): void {
		$this->expectException( InvalidArgumentException::class );

		new ManifestEntry(
			-1,
			EntryHeader::for_file( 'a.txt', 0, 0644, 0 ),
			0,
			0,
			self::TEST_HASH
		);
	}

	/**
	 * The constructor must reject a negative codec_id.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_negative_codec_id(): void {
		$this->expectException( InvalidArgumentException::class );

		new ManifestEntry(
			0,
			EntryHeader::for_file( 'a.txt', 0, 0644, 0 ),
			-1,
			0,
			self::TEST_HASH
		);
	}

	/**
	 * The constructor must reject a codec_id above the uint16 maximum.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_codec_id_above_uint16(): void {
		$this->expectException( InvalidArgumentException::class );

		new ManifestEntry(
			0,
			EntryHeader::for_file( 'a.txt', 0, 0644, 0 ),
			65536,
			0,
			self::TEST_HASH
		);
	}

	/**
	 * The constructor must accept codec_id at the uint16 boundary.
	 *
	 * @return void
	 */
	public function test_constructor_accepts_max_codec_id(): void {
		$entry = new ManifestEntry(
			0,
			EntryHeader::for_file( 'a.txt', 0, 0644, 0 ),
			ManifestEntry::MAX_CODEC_ID,
			0,
			self::TEST_HASH
		);

		$this->assertSame( 65535, $entry->codec_id() );
	}

	/**
	 * The constructor must reject a negative payload_length.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_negative_payload_length(): void {
		$this->expectException( InvalidArgumentException::class );

		new ManifestEntry(
			0,
			EntryHeader::for_file( 'a.txt', 0, 0644, 0 ),
			0,
			-1,
			self::TEST_HASH
		);
	}

	/**
	 * The constructor must reject a payload_hash with the wrong byte length.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_wrong_hash_length(): void {
		$this->expectException( InvalidArgumentException::class );

		new ManifestEntry(
			0,
			EntryHeader::for_file( 'a.txt', 0, 0644, 0 ),
			0,
			0,
			str_repeat( "\x00", 31 )
		);
	}

	/**
	 * The canonical data shape must list the five fields in the fixed order with hex-encoded hash.
	 *
	 * @return void
	 */
	public function test_to_canonical_data_shape(): void {
		$entry = $this->valid_entry();

		$this->assertSame(
			array(
				'offset'         => 16,
				'entry'          => array(
					'kind'  => 'file',
					'path'  => 'wp-config.php',
					'size'  => 1234,
					'mode'  => 420,
					'mtime' => 1690000000,
				),
				'codec_id'       => 1,
				'payload_length' => 500,
				'payload_hash'   => self::TEST_HASH_HEX,
			),
			$entry->to_canonical_data()
		);
	}

	/**
	 * Round-trip through to_canonical_data and from_canonical_data must preserve every field.
	 *
	 * @return void
	 */
	public function test_round_trip_via_canonical_data(): void {
		$original = $this->valid_entry();
		$parsed   = ManifestEntry::from_canonical_data( $original->to_canonical_data() );

		$this->assertSame( $original->offset(), $parsed->offset() );
		$this->assertSame( $original->codec_id(), $parsed->codec_id() );
		$this->assertSame( $original->payload_length(), $parsed->payload_length() );
		$this->assertSame( $original->payload_hash(), $parsed->payload_hash() );
		$this->assertSame(
			$original->entry_header()->to_canonical_data(),
			$parsed->entry_header()->to_canonical_data()
		);
	}

	/**
	 * Parsing must reject canonical data missing the offset field.
	 *
	 * @return void
	 */
	public function test_from_canonical_data_rejects_missing_offset(): void {
		$data = $this->valid_entry()->to_canonical_data();
		unset( $data['offset'] );

		$this->expectException( InvalidArgumentException::class );

		ManifestEntry::from_canonical_data( $data );
	}

	/**
	 * Parsing must reject canonical data missing the entry field.
	 *
	 * @return void
	 */
	public function test_from_canonical_data_rejects_missing_entry(): void {
		$data = $this->valid_entry()->to_canonical_data();
		unset( $data['entry'] );

		$this->expectException( InvalidArgumentException::class );

		ManifestEntry::from_canonical_data( $data );
	}

	/**
	 * Parsing must reject canonical data missing the payload_hash field.
	 *
	 * @return void
	 */
	public function test_from_canonical_data_rejects_missing_payload_hash(): void {
		$data = $this->valid_entry()->to_canonical_data();
		unset( $data['payload_hash'] );

		$this->expectException( InvalidArgumentException::class );

		ManifestEntry::from_canonical_data( $data );
	}

	/**
	 * Parsing must reject canonical data whose offset is not an integer.
	 *
	 * @return void
	 */
	public function test_from_canonical_data_rejects_non_int_offset(): void {
		$data           = $this->valid_entry()->to_canonical_data();
		$data['offset'] = '16';

		$this->expectException( InvalidArgumentException::class );

		ManifestEntry::from_canonical_data( $data );
	}

	/**
	 * Parsing must reject canonical data whose entry field is not an object.
	 *
	 * @return void
	 */
	public function test_from_canonical_data_rejects_non_object_entry(): void {
		$data          = $this->valid_entry()->to_canonical_data();
		$data['entry'] = 'not an object';

		$this->expectException( InvalidArgumentException::class );

		ManifestEntry::from_canonical_data( $data );
	}

	/**
	 * Parsing must reject a hex hash with the wrong character length.
	 *
	 * @return void
	 */
	public function test_from_canonical_data_rejects_wrong_hex_length(): void {
		$data                 = $this->valid_entry()->to_canonical_data();
		$data['payload_hash'] = substr( self::TEST_HASH_HEX, 0, 63 );

		$this->expectException( InvalidArgumentException::class );

		ManifestEntry::from_canonical_data( $data );
	}

	/**
	 * Parsing must reject a hex hash containing uppercase characters.
	 *
	 * Lowercase-only is enforced for canonical form consistency.
	 *
	 * @return void
	 */
	public function test_from_canonical_data_rejects_uppercase_hex(): void {
		$data                 = $this->valid_entry()->to_canonical_data();
		$data['payload_hash'] = strtoupper( self::TEST_HASH_HEX );

		$this->expectException( InvalidArgumentException::class );

		ManifestEntry::from_canonical_data( $data );
	}

	/**
	 * Parsing must reject a hex hash containing non-hex characters.
	 *
	 * @return void
	 */
	public function test_from_canonical_data_rejects_non_hex_characters(): void {
		$data                 = $this->valid_entry()->to_canonical_data();
		$data['payload_hash'] = str_repeat( 'g', 64 );

		$this->expectException( InvalidArgumentException::class );

		ManifestEntry::from_canonical_data( $data );
	}
}
