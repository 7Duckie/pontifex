<?php
/**
 * Behavioural tests for the EntryHeader value object.
 *
 * @package Pontifex\Tests\Unit\Archive\Format
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Archive\Format;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pontifex\Archive\Format\ByteOrder;
use Pontifex\Archive\Format\EntryHeader;

/**
 * Behavioural tests for the EntryHeader class.
 *
 * Verifies the entry-header invariants:
 *
 *  - Kind constants and the maximum POSIX mode constant.
 *  - Each kind-specific factory accepts valid inputs and rejects
 *    invalid ones (empty strings, negative numbers, out-of-range
 *    modes).
 *  - Accessor methods return the constructed values; accessors for
 *    fields irrelevant to the entry kind return null.
 *  - Predicate methods (is_file, is_db_chunk, is_directory,
 *    is_symlink) report the kind correctly.
 *  - Serialisation produces canonical JSON with the kind-specific
 *    field order.
 *  - Parsing rejects under-size input, oversize declared length,
 *    mismatched total length, malformed JSON, unknown kinds, missing
 *    fields, and wrong-typed fields.
 *  - Round-trip preserves every field for each entry kind.
 */
final class EntryHeaderTest extends TestCase {

	/**
	 * Wrap a JSON payload in a valid length prefix for use with from_bytes.
	 *
	 * @param string $payload The JSON payload bytes.
	 * @return string The complete on-disk byte sequence.
	 */
	private function frame( string $payload ): string {
		return ByteOrder::pack_uint32( strlen( $payload ) ) . $payload;
	}

	/**
	 * KIND_FILE must hold the canonical string "file".
	 *
	 * @return void
	 */
	public function test_kind_file_constant(): void {
		$this->assertSame( 'file', EntryHeader::KIND_FILE );
	}

	/**
	 * KIND_DB_CHUNK must hold the canonical string "db_chunk".
	 *
	 * @return void
	 */
	public function test_kind_db_chunk_constant(): void {
		$this->assertSame( 'db_chunk', EntryHeader::KIND_DB_CHUNK );
	}

	/**
	 * KIND_DIRECTORY must hold the canonical string "directory".
	 *
	 * @return void
	 */
	public function test_kind_directory_constant(): void {
		$this->assertSame( 'directory', EntryHeader::KIND_DIRECTORY );
	}

	/**
	 * KIND_SYMLINK must hold the canonical string "symlink".
	 *
	 * @return void
	 */
	public function test_kind_symlink_constant(): void {
		$this->assertSame( 'symlink', EntryHeader::KIND_SYMLINK );
	}

	/**
	 * ALL_KINDS must contain every defined kind exactly once.
	 *
	 * @return void
	 */
	public function test_all_kinds_constant(): void {
		$this->assertSame(
			array( 'file', 'db_chunk', 'directory', 'symlink' ),
			EntryHeader::ALL_KINDS
		);
	}

	/**
	 * The length prefix and maximum payload constants must hold their spec values.
	 *
	 * @return void
	 */
	public function test_size_and_limit_constants(): void {
		$this->assertSame( 4, EntryHeader::LENGTH_PREFIX_SIZE );
		$this->assertSame( 16384, EntryHeader::MAX_PAYLOAD_SIZE );
		$this->assertSame( 4095, EntryHeader::MAX_POSIX_MODE );
	}


	/**
	 * The file factory must accept valid inputs and expose them via accessors.
	 *
	 * @return void
	 */
	public function test_for_file_accepts_valid_inputs(): void {
		$entry = EntryHeader::for_file( 'wp-config.php', 1234, 0644, 1690000000 );

		$this->assertSame( EntryHeader::KIND_FILE, $entry->kind() );
		$this->assertSame( 'wp-config.php', $entry->path() );
		$this->assertSame( 1234, $entry->size() );
		$this->assertSame( 0644, $entry->mode() );
		$this->assertSame( 1690000000, $entry->mtime() );
	}

	/**
	 * Accessors for fields unrelated to file entries must return null.
	 *
	 * @return void
	 */
	public function test_for_file_non_file_accessors_return_null(): void {
		$entry = EntryHeader::for_file( 'wp-config.php', 1234, 0644, 1690000000 );

		$this->assertNull( $entry->chunk_index() );
		$this->assertNull( $entry->table_name() );
		$this->assertNull( $entry->statement_count() );
		$this->assertNull( $entry->byte_count() );
		$this->assertNull( $entry->target() );
	}

	/**
	 * The file factory must reject an empty path.
	 *
	 * @return void
	 */
	public function test_for_file_rejects_empty_path(): void {
		$this->expectException( InvalidArgumentException::class );

		EntryHeader::for_file( '', 1234, 0644, 1690000000 );
	}

	/**
	 * The file factory must reject a negative size.
	 *
	 * @return void
	 */
	public function test_for_file_rejects_negative_size(): void {
		$this->expectException( InvalidArgumentException::class );

		EntryHeader::for_file( 'wp-config.php', -1, 0644, 1690000000 );
	}

	/**
	 * The file factory must accept a zero size (an empty file is valid).
	 *
	 * @return void
	 */
	public function test_for_file_accepts_zero_size(): void {
		$entry = EntryHeader::for_file( 'empty.txt', 0, 0644, 1690000000 );

		$this->assertSame( 0, $entry->size() );
	}

	/**
	 * The file factory must reject a negative mode.
	 *
	 * @return void
	 */
	public function test_for_file_rejects_negative_mode(): void {
		$this->expectException( InvalidArgumentException::class );

		EntryHeader::for_file( 'wp-config.php', 1234, -1, 1690000000 );
	}

	/**
	 * The file factory must reject a mode beyond the 12-bit POSIX range.
	 *
	 * @return void
	 */
	public function test_for_file_rejects_oversize_mode(): void {
		$this->expectException( InvalidArgumentException::class );

		EntryHeader::for_file( 'wp-config.php', 1234, 4096, 1690000000 );
	}

	/**
	 * The file factory must accept mode value 0 (no permissions set).
	 *
	 * @return void
	 */
	public function test_for_file_accepts_mode_zero(): void {
		$entry = EntryHeader::for_file( 'wp-config.php', 1234, 0, 1690000000 );

		$this->assertSame( 0, $entry->mode() );
	}

	/**
	 * The file factory must accept the maximum POSIX mode value.
	 *
	 * @return void
	 */
	public function test_for_file_accepts_max_mode(): void {
		$entry = EntryHeader::for_file( 'wp-config.php', 1234, EntryHeader::MAX_POSIX_MODE, 1690000000 );

		$this->assertSame( 4095, $entry->mode() );
	}

	/**
	 * The file factory must reject a negative mtime.
	 *
	 * @return void
	 */
	public function test_for_file_rejects_negative_mtime(): void {
		$this->expectException( InvalidArgumentException::class );

		EntryHeader::for_file( 'wp-config.php', 1234, 0644, -1 );
	}


	/**
	 * The db_chunk factory must accept valid inputs and expose them via accessors.
	 *
	 * @return void
	 */
	public function test_for_db_chunk_accepts_valid_inputs(): void {
		$entry = EntryHeader::for_db_chunk( 0, 'wp_posts', 42, 1234567 );

		$this->assertSame( EntryHeader::KIND_DB_CHUNK, $entry->kind() );
		$this->assertSame( 0, $entry->chunk_index() );
		$this->assertSame( 'wp_posts', $entry->table_name() );
		$this->assertSame( 42, $entry->statement_count() );
		$this->assertSame( 1234567, $entry->byte_count() );
	}

	/**
	 * Accessors for fields unrelated to db_chunk entries must return null.
	 *
	 * @return void
	 */
	public function test_for_db_chunk_non_db_chunk_accessors_return_null(): void {
		$entry = EntryHeader::for_db_chunk( 0, 'wp_posts', 42, 1234567 );

		$this->assertNull( $entry->path() );
		$this->assertNull( $entry->size() );
		$this->assertNull( $entry->mode() );
		$this->assertNull( $entry->mtime() );
		$this->assertNull( $entry->target() );
	}

	/**
	 * The db_chunk factory must reject a negative chunk index.
	 *
	 * @return void
	 */
	public function test_for_db_chunk_rejects_negative_index(): void {
		$this->expectException( InvalidArgumentException::class );

		EntryHeader::for_db_chunk( -1, 'wp_posts', 42, 1234567 );
	}

	/**
	 * The db_chunk factory must reject an empty table name.
	 *
	 * @return void
	 */
	public function test_for_db_chunk_rejects_empty_table_name(): void {
		$this->expectException( InvalidArgumentException::class );

		EntryHeader::for_db_chunk( 0, '', 42, 1234567 );
	}

	/**
	 * The db_chunk factory must reject a negative statement count.
	 *
	 * @return void
	 */
	public function test_for_db_chunk_rejects_negative_statement_count(): void {
		$this->expectException( InvalidArgumentException::class );

		EntryHeader::for_db_chunk( 0, 'wp_posts', -1, 1234567 );
	}

	/**
	 * The db_chunk factory must reject a negative byte count.
	 *
	 * @return void
	 */
	public function test_for_db_chunk_rejects_negative_byte_count(): void {
		$this->expectException( InvalidArgumentException::class );

		EntryHeader::for_db_chunk( 0, 'wp_posts', 42, -1 );
	}


	/**
	 * The directory factory must accept valid inputs and expose them via accessors.
	 *
	 * @return void
	 */
	public function test_for_directory_accepts_valid_inputs(): void {
		$entry = EntryHeader::for_directory( 'wp-content/uploads', 0755 );

		$this->assertSame( EntryHeader::KIND_DIRECTORY, $entry->kind() );
		$this->assertSame( 'wp-content/uploads', $entry->path() );
		$this->assertSame( 0755, $entry->mode() );
	}

	/**
	 * Accessors for fields unrelated to directory entries must return null.
	 *
	 * @return void
	 */
	public function test_for_directory_non_directory_accessors_return_null(): void {
		$entry = EntryHeader::for_directory( 'wp-content/uploads', 0755 );

		$this->assertNull( $entry->size() );
		$this->assertNull( $entry->mtime() );
		$this->assertNull( $entry->chunk_index() );
		$this->assertNull( $entry->target() );
	}

	/**
	 * The directory factory must reject an empty path.
	 *
	 * @return void
	 */
	public function test_for_directory_rejects_empty_path(): void {
		$this->expectException( InvalidArgumentException::class );

		EntryHeader::for_directory( '', 0755 );
	}

	/**
	 * The directory factory must reject an oversize mode.
	 *
	 * @return void
	 */
	public function test_for_directory_rejects_oversize_mode(): void {
		$this->expectException( InvalidArgumentException::class );

		EntryHeader::for_directory( 'wp-content/uploads', 4096 );
	}


	/**
	 * The symlink factory must accept valid inputs and expose them via accessors.
	 *
	 * @return void
	 */
	public function test_for_symlink_accepts_valid_inputs(): void {
		$entry = EntryHeader::for_symlink( 'wp-content/cache', '/tmp/wp-cache' );

		$this->assertSame( EntryHeader::KIND_SYMLINK, $entry->kind() );
		$this->assertSame( 'wp-content/cache', $entry->path() );
		$this->assertSame( '/tmp/wp-cache', $entry->target() );
	}

	/**
	 * Accessors for fields unrelated to symlink entries must return null.
	 *
	 * @return void
	 */
	public function test_for_symlink_non_symlink_accessors_return_null(): void {
		$entry = EntryHeader::for_symlink( 'wp-content/cache', '/tmp/wp-cache' );

		$this->assertNull( $entry->size() );
		$this->assertNull( $entry->mode() );
		$this->assertNull( $entry->mtime() );
		$this->assertNull( $entry->chunk_index() );
	}

	/**
	 * The symlink factory must reject an empty path.
	 *
	 * @return void
	 */
	public function test_for_symlink_rejects_empty_path(): void {
		$this->expectException( InvalidArgumentException::class );

		EntryHeader::for_symlink( '', '/tmp/wp-cache' );
	}

	/**
	 * The symlink factory must reject an empty target.
	 *
	 * @return void
	 */
	public function test_for_symlink_rejects_empty_target(): void {
		$this->expectException( InvalidArgumentException::class );

		EntryHeader::for_symlink( 'wp-content/cache', '' );
	}


	/**
	 * Predicate methods must report kind correctly for each entry type.
	 *
	 * @return void
	 */
	public function test_predicates_report_kind_correctly(): void {
		$file      = EntryHeader::for_file( 'a.txt', 0, 0644, 0 );
		$db_chunk  = EntryHeader::for_db_chunk( 0, 't', 0, 0 );
		$directory = EntryHeader::for_directory( 'd', 0755 );
		$symlink   = EntryHeader::for_symlink( 's', 't' );

		$this->assertTrue( $file->is_file() );
		$this->assertFalse( $file->is_db_chunk() );

		$this->assertTrue( $db_chunk->is_db_chunk() );
		$this->assertFalse( $db_chunk->is_file() );

		$this->assertTrue( $directory->is_directory() );
		$this->assertFalse( $directory->is_symlink() );

		$this->assertTrue( $symlink->is_symlink() );
		$this->assertFalse( $symlink->is_directory() );
	}


	/**
	 * Serialising a file entry must produce canonical JSON with kind-first ordering.
	 *
	 * @return void
	 */
	public function test_to_bytes_file_canonical_layout(): void {
		$entry   = EntryHeader::for_file( 'wp-config.php', 1234, 0644, 1690000000 );
		$bytes   = $entry->to_bytes();
		$payload = substr( $bytes, EntryHeader::LENGTH_PREFIX_SIZE );

		$this->assertSame(
			'{"kind":"file","path":"wp-config.php","size":1234,"mode":420,"mtime":1690000000}',
			$payload
		);
	}

	/**
	 * Serialising a db_chunk entry must produce canonical JSON with kind-first ordering.
	 *
	 * @return void
	 */
	public function test_to_bytes_db_chunk_canonical_layout(): void {
		$entry   = EntryHeader::for_db_chunk( 0, 'wp_posts', 42, 1234567 );
		$bytes   = $entry->to_bytes();
		$payload = substr( $bytes, EntryHeader::LENGTH_PREFIX_SIZE );

		$this->assertSame(
			'{"kind":"db_chunk","chunk_index":0,"table_name":"wp_posts","statement_count":42,"byte_count":1234567}',
			$payload
		);
	}

	/**
	 * Serialising a directory entry must produce canonical JSON with kind-first ordering.
	 *
	 * @return void
	 */
	public function test_to_bytes_directory_canonical_layout(): void {
		$entry   = EntryHeader::for_directory( 'wp-content/uploads', 0755 );
		$bytes   = $entry->to_bytes();
		$payload = substr( $bytes, EntryHeader::LENGTH_PREFIX_SIZE );

		$this->assertSame(
			'{"kind":"directory","path":"wp-content/uploads","mode":493}',
			$payload
		);
	}

	/**
	 * Serialising a symlink entry must produce canonical JSON with kind-first ordering.
	 *
	 * @return void
	 */
	public function test_to_bytes_symlink_canonical_layout(): void {
		$entry   = EntryHeader::for_symlink( 'wp-content/cache', '/tmp/wp-cache' );
		$bytes   = $entry->to_bytes();
		$payload = substr( $bytes, EntryHeader::LENGTH_PREFIX_SIZE );

		$this->assertSame(
			'{"kind":"symlink","path":"wp-content/cache","target":"/tmp/wp-cache"}',
			$payload
		);
	}

	/**
	 * The length prefix in the serialised bytes must equal the JSON payload length.
	 *
	 * @return void
	 */
	public function test_to_bytes_length_prefix_matches_payload(): void {
		$entry           = EntryHeader::for_file( 'wp-config.php', 1234, 0644, 1690000000 );
		$bytes           = $entry->to_bytes();
		$declared_length = ByteOrder::unpack_uint32( substr( $bytes, 0, 4 ) );
		$payload         = substr( $bytes, 4 );

		$this->assertSame( strlen( $payload ), $declared_length );
	}


	/**
	 * Parsing must reject input shorter than the length prefix.
	 *
	 * @return void
	 */
	public function test_from_bytes_rejects_under_size_input(): void {
		$this->expectException( InvalidArgumentException::class );

		EntryHeader::from_bytes( "\x00\x00\x00" );
	}

	/**
	 * Parsing must reject a length prefix that declares a payload above MAX_PAYLOAD_SIZE.
	 *
	 * @return void
	 */
	public function test_from_bytes_rejects_oversize_declared_length(): void {
		$bytes = ByteOrder::pack_uint32( EntryHeader::MAX_PAYLOAD_SIZE + 1 );

		$this->expectException( InvalidArgumentException::class );

		EntryHeader::from_bytes( $bytes );
	}

	/**
	 * Parsing must reject input whose total length disagrees with the declared payload length.
	 *
	 * @return void
	 */
	public function test_from_bytes_rejects_length_mismatch(): void {
		$entry = EntryHeader::for_file( 'wp-config.php', 1234, 0644, 1690000000 );
		$bytes = $entry->to_bytes();

		$this->expectException( InvalidArgumentException::class );

		EntryHeader::from_bytes( substr( $bytes, 0, strlen( $bytes ) - 1 ) );
	}

	/**
	 * Parsing must reject a malformed JSON payload.
	 *
	 * @return void
	 */
	public function test_from_bytes_rejects_malformed_json(): void {
		$bytes = $this->frame( 'not valid json' );

		$this->expectException( InvalidArgumentException::class );

		EntryHeader::from_bytes( $bytes );
	}

	/**
	 * Parsing must reject a JSON payload missing the kind field.
	 *
	 * @return void
	 */
	public function test_from_bytes_rejects_missing_kind(): void {
		$bytes = $this->frame( '{"path":"a.txt","size":0,"mode":420,"mtime":0}' );

		$this->expectException( InvalidArgumentException::class );

		EntryHeader::from_bytes( $bytes );
	}

	/**
	 * Parsing must reject a JSON payload with an unknown kind value.
	 *
	 * @return void
	 */
	public function test_from_bytes_rejects_unknown_kind(): void {
		$bytes = $this->frame( '{"kind":"mystery","path":"a.txt"}' );

		$this->expectException( InvalidArgumentException::class );

		EntryHeader::from_bytes( $bytes );
	}

	/**
	 * Parsing must reject a JSON payload whose kind field is not a string.
	 *
	 * @return void
	 */
	public function test_from_bytes_rejects_non_string_kind(): void {
		$bytes = $this->frame( '{"kind":42}' );

		$this->expectException( InvalidArgumentException::class );

		EntryHeader::from_bytes( $bytes );
	}

	/**
	 * Parsing a file entry must reject a JSON payload missing a required field.
	 *
	 * @return void
	 */
	public function test_from_bytes_file_rejects_missing_field(): void {
		// Missing the "size" field.
		$bytes = $this->frame( '{"kind":"file","path":"a.txt","mode":420,"mtime":0}' );

		$this->expectException( InvalidArgumentException::class );

		EntryHeader::from_bytes( $bytes );
	}

	/**
	 * Parsing a file entry must reject a JSON payload with a non-integer size.
	 *
	 * @return void
	 */
	public function test_from_bytes_file_rejects_non_int_size(): void {
		$bytes = $this->frame( '{"kind":"file","path":"a.txt","size":"big","mode":420,"mtime":0}' );

		$this->expectException( InvalidArgumentException::class );

		EntryHeader::from_bytes( $bytes );
	}

	/**
	 * Parsing a db_chunk entry must reject a JSON payload missing a required field.
	 *
	 * @return void
	 */
	public function test_from_bytes_db_chunk_rejects_missing_field(): void {
		// Missing the "byte_count" field.
		$bytes = $this->frame( '{"kind":"db_chunk","chunk_index":0,"table_name":"t","statement_count":1}' );

		$this->expectException( InvalidArgumentException::class );

		EntryHeader::from_bytes( $bytes );
	}

	/**
	 * Parsing a directory entry must reject a JSON payload missing the mode field.
	 *
	 * @return void
	 */
	public function test_from_bytes_directory_rejects_missing_mode(): void {
		$bytes = $this->frame( '{"kind":"directory","path":"d"}' );

		$this->expectException( InvalidArgumentException::class );

		EntryHeader::from_bytes( $bytes );
	}

	/**
	 * Parsing a symlink entry must reject a JSON payload missing the target field.
	 *
	 * @return void
	 */
	public function test_from_bytes_symlink_rejects_missing_target(): void {
		$bytes = $this->frame( '{"kind":"symlink","path":"s"}' );

		$this->expectException( InvalidArgumentException::class );

		EntryHeader::from_bytes( $bytes );
	}


	/**
	 * A file entry must survive a full serialise-then-parse round trip.
	 *
	 * @return void
	 */
	public function test_round_trip_file(): void {
		$original = EntryHeader::for_file( 'wp-config.php', 1234, 0644, 1690000000 );
		$parsed   = EntryHeader::from_bytes( $original->to_bytes() );

		$this->assertSame( $original->kind(), $parsed->kind() );
		$this->assertSame( $original->path(), $parsed->path() );
		$this->assertSame( $original->size(), $parsed->size() );
		$this->assertSame( $original->mode(), $parsed->mode() );
		$this->assertSame( $original->mtime(), $parsed->mtime() );
	}

	/**
	 * A db_chunk entry must survive a full serialise-then-parse round trip.
	 *
	 * @return void
	 */
	public function test_round_trip_db_chunk(): void {
		$original = EntryHeader::for_db_chunk( 5, 'wp_postmeta', 200, 5000000 );
		$parsed   = EntryHeader::from_bytes( $original->to_bytes() );

		$this->assertSame( $original->kind(), $parsed->kind() );
		$this->assertSame( $original->chunk_index(), $parsed->chunk_index() );
		$this->assertSame( $original->table_name(), $parsed->table_name() );
		$this->assertSame( $original->statement_count(), $parsed->statement_count() );
		$this->assertSame( $original->byte_count(), $parsed->byte_count() );
	}

	/**
	 * A directory entry must survive a full serialise-then-parse round trip.
	 *
	 * @return void
	 */
	public function test_round_trip_directory(): void {
		$original = EntryHeader::for_directory( 'wp-content/uploads', 0755 );
		$parsed   = EntryHeader::from_bytes( $original->to_bytes() );

		$this->assertSame( $original->kind(), $parsed->kind() );
		$this->assertSame( $original->path(), $parsed->path() );
		$this->assertSame( $original->mode(), $parsed->mode() );
	}

	/**
	 * A symlink entry must survive a full serialise-then-parse round trip.
	 *
	 * @return void
	 */
	public function test_round_trip_symlink(): void {
		$original = EntryHeader::for_symlink( 'wp-content/cache', '/tmp/wp-cache' );
		$parsed   = EntryHeader::from_bytes( $original->to_bytes() );

		$this->assertSame( $original->kind(), $parsed->kind() );
		$this->assertSame( $original->path(), $parsed->path() );
		$this->assertSame( $original->target(), $parsed->target() );
	}

	/**
	 * Serialisation of a file entry must produce the canonical data shape.
	 *
	 * @return void
	 */
	public function test_to_canonical_data_file_shape(): void {
		$entry = EntryHeader::for_file( 'wp-config.php', 1234, 0644, 1690000000 );

		$this->assertSame(
			array(
				'kind'  => 'file',
				'path'  => 'wp-config.php',
				'size'  => 1234,
				'mode'  => 420,
				'mtime' => 1690000000,
			),
			$entry->to_canonical_data()
		);
	}

	/**
	 * Serialisation of a db_chunk entry must produce the canonical data shape.
	 *
	 * @return void
	 */
	public function test_to_canonical_data_db_chunk_shape(): void {
		$entry = EntryHeader::for_db_chunk( 0, 'wp_posts', 42, 1234567 );

		$this->assertSame(
			array(
				'kind'            => 'db_chunk',
				'chunk_index'     => 0,
				'table_name'      => 'wp_posts',
				'statement_count' => 42,
				'byte_count'      => 1234567,
			),
			$entry->to_canonical_data()
		);
	}

	/**
	 * Serialisation of a directory entry must produce the canonical data shape.
	 *
	 * @return void
	 */
	public function test_to_canonical_data_directory_shape(): void {
		$entry = EntryHeader::for_directory( 'wp-content/uploads', 0755 );

		$this->assertSame(
			array(
				'kind' => 'directory',
				'path' => 'wp-content/uploads',
				'mode' => 493,
			),
			$entry->to_canonical_data()
		);
	}

	/**
	 * Serialisation of a symlink entry must produce the canonical data shape.
	 *
	 * @return void
	 */
	public function test_to_canonical_data_symlink_shape(): void {
		$entry = EntryHeader::for_symlink( 'wp-content/cache', '/tmp/wp-cache' );

		$this->assertSame(
			array(
				'kind'   => 'symlink',
				'path'   => 'wp-content/cache',
				'target' => '/tmp/wp-cache',
			),
			$entry->to_canonical_data()
		);
	}

	/**
	 * Round-trip through to_canonical_data + from_canonical_data must preserve every kind.
	 *
	 * @return void
	 */
	public function test_canonical_data_round_trip_each_kind(): void {
		$entries = array(
			EntryHeader::for_file( 'wp-config.php', 1234, 0644, 1690000000 ),
			EntryHeader::for_db_chunk( 5, 'wp_postmeta', 200, 5000000 ),
			EntryHeader::for_directory( 'wp-content/uploads', 0755 ),
			EntryHeader::for_symlink( 'wp-content/cache', '/tmp/wp-cache' ),
		);

		foreach ( $entries as $original ) {
			$parsed = EntryHeader::from_canonical_data( $original->to_canonical_data() );
			$this->assertSame( $original->to_canonical_data(), $parsed->to_canonical_data() );
		}
	}

	/**
	 * Parsing of canonical data must reject an unknown kind.
	 *
	 * @return void
	 */
	public function test_from_canonical_data_rejects_unknown_kind(): void {
		$this->expectException( InvalidArgumentException::class );

		EntryHeader::from_canonical_data( array( 'kind' => 'mystery' ) );
	}
}
