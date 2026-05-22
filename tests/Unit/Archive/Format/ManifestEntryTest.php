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
 * Verifies the manifest-entry invariants per ARCHIVE-FORMAT.md §9:
 *
 *  - Each of the four static factories (for_file, for_db_chunk,
 *    for_directory, for_symlink) builds a valid ManifestEntry of the
 *    matching kind, with the right identifier.
 *  - Common-field validation rejects negative index/offset/length,
 *    codec_id outside the uint16 range, and entry_hash with the wrong
 *    byte length.
 *  - Identifier validation rejects empty path strings for path-bearing
 *    kinds and negative chunk_index for db_chunk.
 *  - is_* kind predicates return true for the matching kind only.
 *  - to_canonical_data produces the spec §9 field order, hex-encoded
 *    hash under the JSON key "hash", and the correct kind-specific
 *    identifier field.
 *  - from_canonical_data validates required fields, types, and the
 *    hex hash, then dispatches to the appropriate factory.
 *  - Round-trip via to_canonical_data + from_canonical_data preserves
 *    every field for every kind.
 */
final class ManifestEntryTest extends TestCase {

	/**
	 * Standard test hash: 32 incrementing bytes from 0x01 to 0x20.
	 *
	 * @var string
	 */
	private const TEST_HASH = "\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x20";

	/**
	 * Hex encoding of TEST_HASH (64 lowercase characters).
	 *
	 * @var string
	 */
	private const TEST_HASH_HEX = '0102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f20';

	/**
	 * Build a canonical valid file ManifestEntry fixture.
	 *
	 * @return ManifestEntry A valid file-kind manifest entry.
	 */
	private function valid_file_entry(): ManifestEntry {
		return ManifestEntry::for_file(
			0,
			1289,
			2147,
			'wp-content/themes/twentytwentyfour/style.css',
			1,
			self::TEST_HASH
		);
	}

	/**
	 * Build a canonical valid db_chunk ManifestEntry fixture.
	 *
	 * @return ManifestEntry A valid db_chunk-kind manifest entry.
	 */
	private function valid_db_chunk_entry(): ManifestEntry {
		return ManifestEntry::for_db_chunk(
			1,
			3436,
			4196,
			0,
			1,
			self::TEST_HASH
		);
	}

	/**
	 * Build a canonical valid directory ManifestEntry fixture.
	 *
	 * @return ManifestEntry A valid directory-kind manifest entry.
	 */
	private function valid_directory_entry(): ManifestEntry {
		return ManifestEntry::for_directory(
			2,
			7632,
			120,
			'wp-content/uploads/2026',
			0,
			self::TEST_HASH
		);
	}

	/**
	 * Build a canonical valid symlink ManifestEntry fixture.
	 *
	 * @return ManifestEntry A valid symlink-kind manifest entry.
	 */
	private function valid_symlink_entry(): ManifestEntry {
		return ManifestEntry::for_symlink(
			3,
			7752,
			180,
			'wp-content/symlink-example',
			0,
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
	 * The for_file factory must build a file-kind entry with the right fields.
	 *
	 * @return void
	 */
	public function test_for_file_builds_file_entry(): void {
		$entry = $this->valid_file_entry();

		$this->assertSame( 0, $entry->index() );
		$this->assertSame( 1289, $entry->offset() );
		$this->assertSame( 2147, $entry->length() );
		$this->assertSame( EntryHeader::KIND_FILE, $entry->kind() );
		$this->assertSame( 1, $entry->codec_id() );
		$this->assertSame( self::TEST_HASH, $entry->entry_hash() );
		$this->assertSame( 'wp-content/themes/twentytwentyfour/style.css', $entry->path() );
		$this->assertNull( $entry->chunk_index() );
	}

	/**
	 * The for_db_chunk factory must build a db_chunk-kind entry with the right fields.
	 *
	 * @return void
	 */
	public function test_for_db_chunk_builds_db_chunk_entry(): void {
		$entry = $this->valid_db_chunk_entry();

		$this->assertSame( 1, $entry->index() );
		$this->assertSame( 3436, $entry->offset() );
		$this->assertSame( 4196, $entry->length() );
		$this->assertSame( EntryHeader::KIND_DB_CHUNK, $entry->kind() );
		$this->assertSame( 1, $entry->codec_id() );
		$this->assertSame( self::TEST_HASH, $entry->entry_hash() );
		$this->assertNull( $entry->path() );
		$this->assertSame( 0, $entry->chunk_index() );
	}

	/**
	 * The for_directory factory must build a directory-kind entry with the right fields.
	 *
	 * @return void
	 */
	public function test_for_directory_builds_directory_entry(): void {
		$entry = $this->valid_directory_entry();

		$this->assertSame( 2, $entry->index() );
		$this->assertSame( EntryHeader::KIND_DIRECTORY, $entry->kind() );
		$this->assertSame( 'wp-content/uploads/2026', $entry->path() );
		$this->assertNull( $entry->chunk_index() );
	}

	/**
	 * The for_symlink factory must build a symlink-kind entry with the right fields.
	 *
	 * @return void
	 */
	public function test_for_symlink_builds_symlink_entry(): void {
		$entry = $this->valid_symlink_entry();

		$this->assertSame( 3, $entry->index() );
		$this->assertSame( EntryHeader::KIND_SYMLINK, $entry->kind() );
		$this->assertSame( 'wp-content/symlink-example', $entry->path() );
		$this->assertNull( $entry->chunk_index() );
	}

	/**
	 * Factories must reject a negative index.
	 *
	 * @return void
	 */
	public function test_factory_rejects_negative_index(): void {
		$this->expectException( InvalidArgumentException::class );

		ManifestEntry::for_file( -1, 0, 0, 'a.txt', 0, self::TEST_HASH );
	}

	/**
	 * Factories must reject a negative offset.
	 *
	 * @return void
	 */
	public function test_factory_rejects_negative_offset(): void {
		$this->expectException( InvalidArgumentException::class );

		ManifestEntry::for_file( 0, -1, 0, 'a.txt', 0, self::TEST_HASH );
	}

	/**
	 * Factories must reject a negative length.
	 *
	 * @return void
	 */
	public function test_factory_rejects_negative_length(): void {
		$this->expectException( InvalidArgumentException::class );

		ManifestEntry::for_file( 0, 0, -1, 'a.txt', 0, self::TEST_HASH );
	}

	/**
	 * Factories must reject a negative codec_id.
	 *
	 * @return void
	 */
	public function test_factory_rejects_negative_codec_id(): void {
		$this->expectException( InvalidArgumentException::class );

		ManifestEntry::for_file( 0, 0, 0, 'a.txt', -1, self::TEST_HASH );
	}

	/**
	 * Factories must reject a codec_id above the uint16 maximum.
	 *
	 * @return void
	 */
	public function test_factory_rejects_codec_id_above_uint16(): void {
		$this->expectException( InvalidArgumentException::class );

		ManifestEntry::for_file( 0, 0, 0, 'a.txt', 65536, self::TEST_HASH );
	}

	/**
	 * Factories must accept codec_id at the uint16 boundary.
	 *
	 * @return void
	 */
	public function test_factory_accepts_max_codec_id(): void {
		$entry = ManifestEntry::for_file( 0, 0, 0, 'a.txt', ManifestEntry::MAX_CODEC_ID, self::TEST_HASH );

		$this->assertSame( 65535, $entry->codec_id() );
	}

	/**
	 * Factories must reject an entry_hash with the wrong byte length.
	 *
	 * @return void
	 */
	public function test_factory_rejects_wrong_hash_length(): void {
		$this->expectException( InvalidArgumentException::class );

		ManifestEntry::for_file( 0, 0, 0, 'a.txt', 0, str_repeat( "\x00", 31 ) );
	}

	/**
	 * The for_file factory must reject an empty path.
	 *
	 * @return void
	 */
	public function test_for_file_rejects_empty_path(): void {
		$this->expectException( InvalidArgumentException::class );

		ManifestEntry::for_file( 0, 0, 0, '', 0, self::TEST_HASH );
	}

	/**
	 * The for_directory factory must reject an empty path.
	 *
	 * @return void
	 */
	public function test_for_directory_rejects_empty_path(): void {
		$this->expectException( InvalidArgumentException::class );

		ManifestEntry::for_directory( 0, 0, 0, '', 0, self::TEST_HASH );
	}

	/**
	 * The for_symlink factory must reject an empty path.
	 *
	 * @return void
	 */
	public function test_for_symlink_rejects_empty_path(): void {
		$this->expectException( InvalidArgumentException::class );

		ManifestEntry::for_symlink( 0, 0, 0, '', 0, self::TEST_HASH );
	}

	/**
	 * The for_db_chunk factory must reject a negative chunk_index.
	 *
	 * @return void
	 */
	public function test_for_db_chunk_rejects_negative_chunk_index(): void {
		$this->expectException( InvalidArgumentException::class );

		ManifestEntry::for_db_chunk( 0, 0, 0, -1, 0, self::TEST_HASH );
	}

	/**
	 * The is_file predicate must return true only for file-kind entries.
	 *
	 * @return void
	 */
	public function test_is_file_predicate(): void {
		$this->assertTrue( $this->valid_file_entry()->is_file() );
		$this->assertFalse( $this->valid_db_chunk_entry()->is_file() );
		$this->assertFalse( $this->valid_directory_entry()->is_file() );
		$this->assertFalse( $this->valid_symlink_entry()->is_file() );
	}

	/**
	 * The is_db_chunk predicate must return true only for db_chunk-kind entries.
	 *
	 * @return void
	 */
	public function test_is_db_chunk_predicate(): void {
		$this->assertFalse( $this->valid_file_entry()->is_db_chunk() );
		$this->assertTrue( $this->valid_db_chunk_entry()->is_db_chunk() );
		$this->assertFalse( $this->valid_directory_entry()->is_db_chunk() );
		$this->assertFalse( $this->valid_symlink_entry()->is_db_chunk() );
	}

	/**
	 * The is_directory predicate must return true only for directory-kind entries.
	 *
	 * @return void
	 */
	public function test_is_directory_predicate(): void {
		$this->assertFalse( $this->valid_file_entry()->is_directory() );
		$this->assertFalse( $this->valid_db_chunk_entry()->is_directory() );
		$this->assertTrue( $this->valid_directory_entry()->is_directory() );
		$this->assertFalse( $this->valid_symlink_entry()->is_directory() );
	}

	/**
	 * The is_symlink predicate must return true only for symlink-kind entries.
	 *
	 * @return void
	 */
	public function test_is_symlink_predicate(): void {
		$this->assertFalse( $this->valid_file_entry()->is_symlink() );
		$this->assertFalse( $this->valid_db_chunk_entry()->is_symlink() );
		$this->assertFalse( $this->valid_directory_entry()->is_symlink() );
		$this->assertTrue( $this->valid_symlink_entry()->is_symlink() );
	}

	/**
	 * The file canonical data shape must match the spec §9 example exactly.
	 *
	 * @return void
	 */
	public function test_to_canonical_data_file_shape(): void {
		$entry = $this->valid_file_entry();

		$this->assertSame(
			array(
				'index'    => 0,
				'offset'   => 1289,
				'length'   => 2147,
				'kind'     => 'file',
				'path'     => 'wp-content/themes/twentytwentyfour/style.css',
				'codec_id' => 1,
				'hash'     => self::TEST_HASH_HEX,
			),
			$entry->to_canonical_data()
		);
	}

	/**
	 * The db_chunk canonical data shape must include chunk_index instead of path.
	 *
	 * @return void
	 */
	public function test_to_canonical_data_db_chunk_shape(): void {
		$entry = $this->valid_db_chunk_entry();

		$this->assertSame(
			array(
				'index'       => 1,
				'offset'      => 3436,
				'length'      => 4196,
				'kind'        => 'db_chunk',
				'chunk_index' => 0,
				'codec_id'    => 1,
				'hash'        => self::TEST_HASH_HEX,
			),
			$entry->to_canonical_data()
		);
	}

	/**
	 * The directory canonical data shape must include path and use kind="directory".
	 *
	 * @return void
	 */
	public function test_to_canonical_data_directory_shape(): void {
		$entry = $this->valid_directory_entry();

		$this->assertSame(
			array(
				'index'    => 2,
				'offset'   => 7632,
				'length'   => 120,
				'kind'     => 'directory',
				'path'     => 'wp-content/uploads/2026',
				'codec_id' => 0,
				'hash'     => self::TEST_HASH_HEX,
			),
			$entry->to_canonical_data()
		);
	}

	/**
	 * The symlink canonical data shape must include path and use kind="symlink".
	 *
	 * @return void
	 */
	public function test_to_canonical_data_symlink_shape(): void {
		$entry = $this->valid_symlink_entry();

		$this->assertSame(
			array(
				'index'    => 3,
				'offset'   => 7752,
				'length'   => 180,
				'kind'     => 'symlink',
				'path'     => 'wp-content/symlink-example',
				'codec_id' => 0,
				'hash'     => self::TEST_HASH_HEX,
			),
			$entry->to_canonical_data()
		);
	}

	/**
	 * Round-trip through to_canonical_data + from_canonical_data must preserve all file fields.
	 *
	 * @return void
	 */
	public function test_round_trip_via_canonical_data_file(): void {
		$original = $this->valid_file_entry();
		$parsed   = ManifestEntry::from_canonical_data( $original->to_canonical_data() );

		$this->assertSame( $original->index(), $parsed->index() );
		$this->assertSame( $original->offset(), $parsed->offset() );
		$this->assertSame( $original->length(), $parsed->length() );
		$this->assertSame( $original->kind(), $parsed->kind() );
		$this->assertSame( $original->codec_id(), $parsed->codec_id() );
		$this->assertSame( $original->entry_hash(), $parsed->entry_hash() );
		$this->assertSame( $original->path(), $parsed->path() );
		$this->assertNull( $parsed->chunk_index() );
	}

	/**
	 * Round-trip must preserve all db_chunk fields.
	 *
	 * @return void
	 */
	public function test_round_trip_via_canonical_data_db_chunk(): void {
		$original = $this->valid_db_chunk_entry();
		$parsed   = ManifestEntry::from_canonical_data( $original->to_canonical_data() );

		$this->assertSame( $original->kind(), $parsed->kind() );
		$this->assertSame( $original->chunk_index(), $parsed->chunk_index() );
		$this->assertNull( $parsed->path() );
	}

	/**
	 * Round-trip must preserve all directory fields.
	 *
	 * @return void
	 */
	public function test_round_trip_via_canonical_data_directory(): void {
		$original = $this->valid_directory_entry();
		$parsed   = ManifestEntry::from_canonical_data( $original->to_canonical_data() );

		$this->assertSame( $original->kind(), $parsed->kind() );
		$this->assertSame( $original->path(), $parsed->path() );
	}

	/**
	 * Round-trip must preserve all symlink fields.
	 *
	 * @return void
	 */
	public function test_round_trip_via_canonical_data_symlink(): void {
		$original = $this->valid_symlink_entry();
		$parsed   = ManifestEntry::from_canonical_data( $original->to_canonical_data() );

		$this->assertSame( $original->kind(), $parsed->kind() );
		$this->assertSame( $original->path(), $parsed->path() );
	}

	/**
	 * Parsing must reject canonical data missing the index field.
	 *
	 * @return void
	 */
	public function test_from_canonical_data_rejects_missing_index(): void {
		$data = $this->valid_file_entry()->to_canonical_data();
		unset( $data['index'] );

		$this->expectException( InvalidArgumentException::class );

		ManifestEntry::from_canonical_data( $data );
	}

	/**
	 * Parsing must reject canonical data missing the offset field.
	 *
	 * @return void
	 */
	public function test_from_canonical_data_rejects_missing_offset(): void {
		$data = $this->valid_file_entry()->to_canonical_data();
		unset( $data['offset'] );

		$this->expectException( InvalidArgumentException::class );

		ManifestEntry::from_canonical_data( $data );
	}

	/**
	 * Parsing must reject canonical data missing the length field.
	 *
	 * @return void
	 */
	public function test_from_canonical_data_rejects_missing_length(): void {
		$data = $this->valid_file_entry()->to_canonical_data();
		unset( $data['length'] );

		$this->expectException( InvalidArgumentException::class );

		ManifestEntry::from_canonical_data( $data );
	}

	/**
	 * Parsing must reject canonical data missing the kind field.
	 *
	 * @return void
	 */
	public function test_from_canonical_data_rejects_missing_kind(): void {
		$data = $this->valid_file_entry()->to_canonical_data();
		unset( $data['kind'] );

		$this->expectException( InvalidArgumentException::class );

		ManifestEntry::from_canonical_data( $data );
	}

	/**
	 * Parsing must reject canonical data missing the codec_id field.
	 *
	 * @return void
	 */
	public function test_from_canonical_data_rejects_missing_codec_id(): void {
		$data = $this->valid_file_entry()->to_canonical_data();
		unset( $data['codec_id'] );

		$this->expectException( InvalidArgumentException::class );

		ManifestEntry::from_canonical_data( $data );
	}

	/**
	 * Parsing must reject canonical data missing the hash field.
	 *
	 * @return void
	 */
	public function test_from_canonical_data_rejects_missing_hash(): void {
		$data = $this->valid_file_entry()->to_canonical_data();
		unset( $data['hash'] );

		$this->expectException( InvalidArgumentException::class );

		ManifestEntry::from_canonical_data( $data );
	}

	/**
	 * Parsing must reject canonical data whose offset is not an integer.
	 *
	 * @return void
	 */
	public function test_from_canonical_data_rejects_non_int_offset(): void {
		$data           = $this->valid_file_entry()->to_canonical_data();
		$data['offset'] = '16';

		$this->expectException( InvalidArgumentException::class );

		ManifestEntry::from_canonical_data( $data );
	}

	/**
	 * Parsing must reject canonical data whose kind is not a string.
	 *
	 * @return void
	 */
	public function test_from_canonical_data_rejects_non_string_kind(): void {
		$data         = $this->valid_file_entry()->to_canonical_data();
		$data['kind'] = 42;

		$this->expectException( InvalidArgumentException::class );

		ManifestEntry::from_canonical_data( $data );
	}

	/**
	 * Parsing must reject canonical data with an unknown kind value.
	 *
	 * @return void
	 */
	public function test_from_canonical_data_rejects_unknown_kind(): void {
		$data         = $this->valid_file_entry()->to_canonical_data();
		$data['kind'] = 'mystery';

		$this->expectException( InvalidArgumentException::class );

		ManifestEntry::from_canonical_data( $data );
	}

	/**
	 * Parsing must reject a hex hash with the wrong character length.
	 *
	 * @return void
	 */
	public function test_from_canonical_data_rejects_wrong_hex_length(): void {
		$data         = $this->valid_file_entry()->to_canonical_data();
		$data['hash'] = substr( self::TEST_HASH_HEX, 0, 63 );

		$this->expectException( InvalidArgumentException::class );

		ManifestEntry::from_canonical_data( $data );
	}

	/**
	 * Parsing must reject a hex hash containing uppercase characters.
	 *
	 * Lowercase-only is enforced for canonical-form consistency.
	 *
	 * @return void
	 */
	public function test_from_canonical_data_rejects_uppercase_hex(): void {
		$data         = $this->valid_file_entry()->to_canonical_data();
		$data['hash'] = strtoupper( self::TEST_HASH_HEX );

		$this->expectException( InvalidArgumentException::class );

		ManifestEntry::from_canonical_data( $data );
	}

	/**
	 * Parsing must reject a hex hash containing non-hex characters.
	 *
	 * @return void
	 */
	public function test_from_canonical_data_rejects_non_hex_characters(): void {
		$data         = $this->valid_file_entry()->to_canonical_data();
		$data['hash'] = str_repeat( 'g', 64 );

		$this->expectException( InvalidArgumentException::class );

		ManifestEntry::from_canonical_data( $data );
	}

	/**
	 * Parsing a file-kind entry must reject canonical data missing the path field.
	 *
	 * @return void
	 */
	public function test_from_canonical_data_file_rejects_missing_path(): void {
		$data = $this->valid_file_entry()->to_canonical_data();
		unset( $data['path'] );

		$this->expectException( InvalidArgumentException::class );

		ManifestEntry::from_canonical_data( $data );
	}

	/**
	 * Parsing a db_chunk entry must reject canonical data missing the chunk_index field.
	 *
	 * @return void
	 */
	public function test_from_canonical_data_db_chunk_rejects_missing_chunk_index(): void {
		$data = $this->valid_db_chunk_entry()->to_canonical_data();
		unset( $data['chunk_index'] );

		$this->expectException( InvalidArgumentException::class );

		ManifestEntry::from_canonical_data( $data );
	}

	/**
	 * Parsing a file-kind entry must reject a non-string path field.
	 *
	 * @return void
	 */
	public function test_from_canonical_data_file_rejects_non_string_path(): void {
		$data         = $this->valid_file_entry()->to_canonical_data();
		$data['path'] = 12345;

		$this->expectException( InvalidArgumentException::class );

		ManifestEntry::from_canonical_data( $data );
	}

	/**
	 * Parsing a db_chunk entry must reject a non-int chunk_index field.
	 *
	 * @return void
	 */
	public function test_from_canonical_data_db_chunk_rejects_non_int_chunk_index(): void {
		$data                = $this->valid_db_chunk_entry()->to_canonical_data();
		$data['chunk_index'] = '0';

		$this->expectException( InvalidArgumentException::class );

		ManifestEntry::from_canonical_data( $data );
	}
}
