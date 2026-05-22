<?php
/**
 * Behavioural tests for the ArchiveManifest value object.
 *
 * @package Pontifex\Tests\Unit\Archive\Format
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Archive\Format;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pontifex\Archive\Format\ArchiveManifest;
use Pontifex\Archive\Format\ByteOrder;
use Pontifex\Archive\Format\ManifestEntry;
use Pontifex\Archive\Integrity\Sha256;

/**
 * Behavioural tests for the ArchiveManifest class.
 *
 * Verifies the on-disk format invariants:
 *
 *  - Constants for length prefix, header size, and payload ceiling.
 *  - Constructor accepts empty and multi-entry lists; rejects
 *    non-ManifestEntry elements.
 *  - to_bytes produces canonical layout: 4-byte length + 32-byte
 *    SHA-256 + JSON payload with entries in given order.
 *  - from_bytes rejects under-size input, oversized declared
 *    length, mismatched total length, bad hash, malformed JSON,
 *    missing fields, and non-array entries.
 *  - Round-trip preserves entry count, entry order, and every
 *    field on each entry.
 */
final class ArchiveManifestTest extends TestCase {

	/**
	 * Test hash 1: incrementing bytes 0x01 to 0x20.
	 *
	 * @var string
	 */
	private const HASH_ONE = "\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x20";

	/**
	 * Test hash 2: incrementing bytes 0x21 to 0x40.
	 *
	 * @var string
	 */
	private const HASH_TWO = "\x21\x22\x23\x24\x25\x26\x27\x28\x29\x2A\x2B\x2C\x2D\x2E\x2F\x30\x31\x32\x33\x34\x35\x36\x37\x38\x39\x3A\x3B\x3C\x3D\x3E\x3F\x40";

	/**
	 * Build a manifest with two distinct entries used as a fixture.
	 *
	 * @return ArchiveManifest A manifest with two entries.
	 */
	private function two_entry_manifest(): ArchiveManifest {
		return new ArchiveManifest(
			array(
				ManifestEntry::for_file(
					0,
					16,
					650,
					'wp-config.php',
					1,
					self::HASH_ONE
				),
				ManifestEntry::for_db_chunk(
					1,
					600,
					3700,
					0,
					1,
					self::HASH_TWO
				),
			)
		);
	}

	/**
	 * The length prefix constant must be 4 bytes (uint32).
	 *
	 * @return void
	 */
	public function test_length_prefix_size_is_four(): void {
		$this->assertSame( 4, ArchiveManifest::LENGTH_PREFIX_SIZE );
	}

	/**
	 * HEADER_SIZE must equal 4 + 32 = 36 bytes.
	 *
	 * @return void
	 */
	public function test_header_size_is_thirty_six(): void {
		$this->assertSame( 36, ArchiveManifest::HEADER_SIZE );
		$this->assertSame(
			ArchiveManifest::LENGTH_PREFIX_SIZE + Sha256::DIGEST_SIZE,
			ArchiveManifest::HEADER_SIZE
		);
	}

	/**
	 * The maximum payload size must be 16 MiB (16777216 bytes).
	 *
	 * @return void
	 */
	public function test_max_payload_size_is_sixteen_mib(): void {
		$this->assertSame( 16777216, ArchiveManifest::MAX_PAYLOAD_SIZE );
		$this->assertSame( 16 * 1024 * 1024, ArchiveManifest::MAX_PAYLOAD_SIZE );
	}

	/**
	 * The constructor must accept an empty entries list.
	 *
	 * @return void
	 */
	public function test_constructor_accepts_empty_list(): void {
		$manifest = new ArchiveManifest( array() );

		$this->assertSame( 0, $manifest->entry_count() );
		$this->assertSame( array(), $manifest->entries() );
	}

	/**
	 * The constructor must accept a multi-entry list.
	 *
	 * @return void
	 */
	public function test_constructor_accepts_multiple_entries(): void {
		$manifest = $this->two_entry_manifest();

		$this->assertSame( 2, $manifest->entry_count() );
		$this->assertCount( 2, $manifest->entries() );
	}

	/**
	 * The constructor must reject a list containing non-ManifestEntry values.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_non_manifest_entry(): void {
		$this->expectException( InvalidArgumentException::class );

		new ArchiveManifest( array( 'not a ManifestEntry' ) );
	}

	/**
	 * The constructor must reindex the entries list to a 0-based sequence.
	 *
	 * @return void
	 */
	public function test_constructor_reindexes_entries(): void {
		$entry_a = ManifestEntry::for_file( 0, 16, 50, 'a.txt', 0, self::HASH_ONE );
		$entry_b = ManifestEntry::for_file( 1, 32, 50, 'b.txt', 0, self::HASH_TWO );

		$manifest = new ArchiveManifest(
			array(
				5 => $entry_a,
				7 => $entry_b,
			)
		);

		$this->assertSame( array( 0, 1 ), array_keys( $manifest->entries() ) );
	}

	/**
	 * Serialisation must produce HEADER_SIZE + payload bytes for an empty manifest.
	 *
	 * @return void
	 */
	public function test_to_bytes_empty_manifest_size(): void {
		$bytes = ( new ArchiveManifest( array() ) )->to_bytes();

		$this->assertGreaterThanOrEqual( ArchiveManifest::HEADER_SIZE, strlen( $bytes ) );
	}

	/**
	 * Serialisation must produce a length prefix that matches the payload length.
	 *
	 * @return void
	 */
	public function test_to_bytes_length_prefix_matches_payload(): void {
		$bytes           = $this->two_entry_manifest()->to_bytes();
		$declared_length = ByteOrder::unpack_uint32( substr( $bytes, 0, 4 ) );
		$payload         = substr( $bytes, ArchiveManifest::HEADER_SIZE );

		$this->assertSame( strlen( $payload ), $declared_length );
	}

	/**
	 * The stored hash must match the SHA-256 of the payload.
	 *
	 * @return void
	 */
	public function test_to_bytes_hash_matches_payload(): void {
		$bytes         = $this->two_entry_manifest()->to_bytes();
		$stored_hash   = substr( $bytes, ArchiveManifest::LENGTH_PREFIX_SIZE, Sha256::DIGEST_SIZE );
		$payload       = substr( $bytes, ArchiveManifest::HEADER_SIZE );
		$computed_hash = Sha256::of( $payload );

		$this->assertSame( $computed_hash, $stored_hash );
	}

	/**
	 * The empty manifest must serialise to the canonical empty-entries JSON.
	 *
	 * @return void
	 */
	public function test_to_bytes_empty_manifest_canonical_json(): void {
		$bytes   = ( new ArchiveManifest( array() ) )->to_bytes();
		$payload = substr( $bytes, ArchiveManifest::HEADER_SIZE );

		$this->assertSame( '{"entries":[]}', $payload );
	}

	/**
	 * Parsing must reject input shorter than HEADER_SIZE.
	 *
	 * @return void
	 */
	public function test_from_bytes_rejects_under_size_input(): void {
		$this->expectException( InvalidArgumentException::class );

		ArchiveManifest::from_bytes( str_repeat( "\x00", ArchiveManifest::HEADER_SIZE - 1 ) );
	}

	/**
	 * Parsing must reject a declared payload size above MAX_PAYLOAD_SIZE.
	 *
	 * @return void
	 */
	public function test_from_bytes_rejects_oversize_declared_length(): void {
		$length = ByteOrder::pack_uint32( ArchiveManifest::MAX_PAYLOAD_SIZE + 1 );
		$hash   = str_repeat( "\x00", Sha256::DIGEST_SIZE );
		$bytes  = $length . $hash;

		$this->expectException( InvalidArgumentException::class );

		ArchiveManifest::from_bytes( $bytes );
	}

	/**
	 * Parsing must reject input whose total length disagrees with the declared length.
	 *
	 * @return void
	 */
	public function test_from_bytes_rejects_length_mismatch(): void {
		$valid_bytes = $this->two_entry_manifest()->to_bytes();
		$truncated   = substr( $valid_bytes, 0, strlen( $valid_bytes ) - 1 );

		$this->expectException( InvalidArgumentException::class );

		ArchiveManifest::from_bytes( $truncated );
	}

	/**
	 * Parsing must reject bytes whose stored hash does not match the payload.
	 *
	 * @return void
	 */
	public function test_from_bytes_rejects_bad_hash(): void {
		$valid_bytes = $this->two_entry_manifest()->to_bytes();
		$tampered    = $valid_bytes;

		// Flip one bit in the stored hash so it no longer matches.
		$tampered[ ArchiveManifest::LENGTH_PREFIX_SIZE ] = chr( ord( $tampered[ ArchiveManifest::LENGTH_PREFIX_SIZE ] ) ^ 0x01 );

		$this->expectException( InvalidArgumentException::class );

		ArchiveManifest::from_bytes( $tampered );
	}

	/**
	 * Parsing must reject a malformed JSON payload.
	 *
	 * @return void
	 */
	public function test_from_bytes_rejects_malformed_json(): void {
		$garbage = 'not valid json';
		$length  = ByteOrder::pack_uint32( strlen( $garbage ) );
		$hash    = Sha256::of( $garbage );
		$bytes   = $length . $hash . $garbage;

		$this->expectException( InvalidArgumentException::class );

		ArchiveManifest::from_bytes( $bytes );
	}

	/**
	 * Parsing must reject a JSON payload missing the entries field.
	 *
	 * @return void
	 */
	public function test_from_bytes_rejects_missing_entries_field(): void {
		$payload = '{"other":[]}';
		$length  = ByteOrder::pack_uint32( strlen( $payload ) );
		$hash    = Sha256::of( $payload );
		$bytes   = $length . $hash . $payload;

		$this->expectException( InvalidArgumentException::class );

		ArchiveManifest::from_bytes( $bytes );
	}

	/**
	 * Parsing must reject a JSON payload whose entries field is not an array.
	 *
	 * @return void
	 */
	public function test_from_bytes_rejects_non_array_entries(): void {
		$payload = '{"entries":"not an array"}';
		$length  = ByteOrder::pack_uint32( strlen( $payload ) );
		$hash    = Sha256::of( $payload );
		$bytes   = $length . $hash . $payload;

		$this->expectException( InvalidArgumentException::class );

		ArchiveManifest::from_bytes( $bytes );
	}

	/**
	 * Round-trip with an empty manifest must produce an empty manifest.
	 *
	 * @return void
	 */
	public function test_round_trip_empty_manifest(): void {
		$original = new ArchiveManifest( array() );
		$parsed   = ArchiveManifest::from_bytes( $original->to_bytes() );

		$this->assertSame( 0, $parsed->entry_count() );
	}

	/**
	 * Round-trip with multiple entries must preserve every entry and its order.
	 *
	 * @return void
	 */
	public function test_round_trip_multi_entry_preserves_order(): void {
		$original = $this->two_entry_manifest();
		$parsed   = ArchiveManifest::from_bytes( $original->to_bytes() );

		$this->assertSame( 2, $parsed->entry_count() );

		$original_entries = $original->entries();
		$parsed_entries   = $parsed->entries();

		// First entry: the file.
		$this->assertSame( $original_entries[0]->index(), $parsed_entries[0]->index() );
		$this->assertSame( $original_entries[0]->offset(), $parsed_entries[0]->offset() );
		$this->assertSame( $original_entries[0]->length(), $parsed_entries[0]->length() );
		$this->assertSame( $original_entries[0]->kind(), $parsed_entries[0]->kind() );
		$this->assertSame( $original_entries[0]->codec_id(), $parsed_entries[0]->codec_id() );
		$this->assertSame( $original_entries[0]->entry_hash(), $parsed_entries[0]->entry_hash() );
		$this->assertSame( $original_entries[0]->path(), $parsed_entries[0]->path() );

		// Second entry: the db chunk.
		$this->assertSame( $original_entries[1]->offset(), $parsed_entries[1]->offset() );
		$this->assertSame( 'db_chunk', $parsed_entries[1]->kind() );
		$this->assertSame( 0, $parsed_entries[1]->chunk_index() );
	}
}
