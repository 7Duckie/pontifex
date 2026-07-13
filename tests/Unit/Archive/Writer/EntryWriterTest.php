<?php
/**
 * Unit tests for the EntryWriter class.
 *
 * @package Pontifex\Tests\Unit\Archive\Writer
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Archive\Writer;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pontifex\Archive\Codec\CodecRegistry;
use Pontifex\Archive\Format\ByteOrder;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Integrity\Sha256;
use Pontifex\Archive\Writer\EntryWriter;

/**
 * Tests for {@see EntryWriter}.
 *
 * The tests round-trip entries through memory streams and parse the
 * bytes back to verify the on-disk layout matches the spec:
 *
 *   header_length || header_JSON || codec_id || nonce || payload || hash
 *
 * Raw codec (id 0) is the identity transform, so a raw round-trip lets
 * tests compare the on-disk payload byte-for-byte against the source.
 * Gzip codec (id 1) is used to verify the writer corrects
 * size_compressed in the on-disk header after encoding.
 */
final class EntryWriterTest extends TestCase {

	/**
	 * Build an EntryWriter against the default codec registry (raw and gzip).
	 *
	 * @return EntryWriter A fresh writer with raw and gzip codecs registered.
	 */
	private static function make_writer(): EntryWriter {
		return new EntryWriter( CodecRegistry::with_defaults() );
	}

	/**
	 * Return a 12-byte all-zero nonce, the v0.1.0 convention.
	 *
	 * @return string A NONCE_SIZE-byte binary string of zero bytes.
	 */
	private static function zero_nonce(): string {
		return str_repeat( "\x00", EntryWriter::NONCE_SIZE );
	}

	/**
	 * Open a php://memory stream, optionally pre-populated with bytes.
	 *
	 * If contents are supplied, they are written to the stream and the
	 * cursor is rewound so the first read returns the pre-populated bytes.
	 *
	 * @param string $contents Optional initial contents.
	 * @return resource A readable and writable php://memory stream.
	 * @throws \RuntimeException If php://memory cannot be opened.
	 */
	private static function memory_stream( string $contents = '' ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://memory is an in-process buffer, not a file; WP_Filesystem cannot open it.
		$stream = fopen( 'php://memory', 'r+b' );
		if ( false === $stream ) {
			throw new \RuntimeException( 'Could not open php://memory for test.' );
		}
		if ( '' !== $contents ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Operating on php://memory stream resource.
			fwrite( $stream, $contents );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on php://memory stream resource.
			rewind( $stream );
		}
		return $stream;
	}

	/**
	 * Rewind a stream and return all its contents as a string.
	 *
	 * @param resource $stream The stream to read.
	 * @return string The full stream contents.
	 * @throws \RuntimeException If the stream cannot be read.
	 */
	private static function read_all( $stream ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource, not a filesystem path.
		rewind( $stream );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_stream_get_contents -- Operating on a test stream resource, not a filesystem path.
		$contents = stream_get_contents( $stream );
		if ( false === $contents ) {
			throw new \RuntimeException( 'Could not read test stream.' );
		}
		return $contents;
	}

	/**
	 * Parse an entry record's raw bytes into its component parts.
	 *
	 * Returns an associative array with keys: header_bytes, codec_id,
	 * nonce, payload, hash. Used by tests that verify the on-disk
	 * byte layout.
	 *
	 * @param string $bytes The complete entry record on disk.
	 * @return array<string, mixed> The parsed components.
	 */
	private static function parse_entry_record( string $bytes ): array {
		$header_length = ByteOrder::unpack_uint32( substr( $bytes, 0, EntryHeader::LENGTH_PREFIX_SIZE ) );
		$header_bytes  = substr( $bytes, 0, EntryHeader::LENGTH_PREFIX_SIZE + $header_length );

		$cursor   = EntryHeader::LENGTH_PREFIX_SIZE + $header_length;
		$codec_id = ByteOrder::unpack_uint16( substr( $bytes, $cursor, ByteOrder::UINT16_SIZE ) );
		$cursor  += ByteOrder::UINT16_SIZE;

		$nonce   = substr( $bytes, $cursor, EntryWriter::NONCE_SIZE );
		$cursor += EntryWriter::NONCE_SIZE;

		$hash           = substr( $bytes, -Sha256::DIGEST_SIZE );
		$payload_length = strlen( $bytes ) - $cursor - Sha256::DIGEST_SIZE;
		$payload        = substr( $bytes, $cursor, $payload_length );

		return array(
			'header_bytes' => $header_bytes,
			'codec_id'     => $codec_id,
			'nonce'        => $nonce,
			'payload'      => $payload,
			'hash'         => $hash,
		);
	}

	/**
	 * Forwards the byte-progress callback to the codec as the payload streams.
	 *
	 * The callback must receive the entry's raw source bytes as the payload is
	 * encoded — the hook a caller uses to report progress within a single entry —
	 * so the reported total equals the source size.
	 *
	 * @return void
	 */
	public function test_write_entry_reports_source_bytes_to_the_callback(): void {
		$source_contents = str_repeat( 'entry payload chunk; ', 1000 );
		$source          = self::memory_stream( $source_contents );
		$destination     = self::memory_stream();

		$header   = EntryHeader::for_file( 'big.txt', strlen( $source_contents ), 0644, 1690000000, 'application/octet-stream', 0 );
		$reported = 0;
		self::make_writer()->write_entry(
			$header,
			0,
			self::zero_nonce(),
			$source,
			$destination,
			null,
			null,
			function ( int $bytes ) use ( &$reported ): void {
				$reported += $bytes;
			}
		);

		$this->assertSame( strlen( $source_contents ), $reported, 'The callback must see every source byte of the entry.' );
	}

	/**
	 * NONCE_SIZE must equal 12 bytes (spec §6).
	 *
	 * @return void
	 */
	public function test_nonce_size_constant(): void {
		$this->assertSame( 12, EntryWriter::NONCE_SIZE );
	}

	/**
	 * Writing a file entry with the raw codec must round-trip cleanly through memory streams.
	 *
	 * This is the most comprehensive layout test: it verifies every
	 * structural property of the on-disk entry record at once.
	 *
	 * @return void
	 */
	public function test_write_entry_file_raw_codec_round_trip(): void {
		$source_contents = 'hello world, this is a test file payload';
		$source          = self::memory_stream( $source_contents );
		$destination     = self::memory_stream();

		$header = EntryHeader::for_file( 'test.txt', strlen( $source_contents ), 0644, 1690000000, 'application/octet-stream', 0 );
		$result = self::make_writer()->write_entry( $header, 0, self::zero_nonce(), $source, $destination );

		$this->assertSame( strlen( $source_contents ), $result->payload_length() );

		$bytes = self::read_all( $destination );
		$this->assertSame( strlen( $bytes ), $result->total_entry_length() );

		$parts         = self::parse_entry_record( $bytes );
		$parsed_header = EntryHeader::from_bytes( $parts['header_bytes'] );

		$this->assertSame( strlen( $source_contents ), $parsed_header->size_compressed() );
		$this->assertSame( 'test.txt', $parsed_header->path() );
		$this->assertSame( 0, $parts['codec_id'] );
		$this->assertSame( self::zero_nonce(), $parts['nonce'] );
		$this->assertSame( $source_contents, $parts['payload'] );
		$this->assertSame( $result->entry_hash(), $parts['hash'] );
	}

	/**
	 * The trailing hash must equal SHA-256 of everything that came before it.
	 *
	 * Verifies the core integrity property: hash covers
	 * header || codec_id || nonce || payload, but not itself.
	 *
	 * @return void
	 */
	public function test_write_entry_hash_covers_everything_except_itself(): void {
		$source = self::memory_stream( 'payload bytes here' );
		$dest   = self::memory_stream();

		$header = EntryHeader::for_file( 'a.txt', 18, 0644, 0, 'application/octet-stream', 0 );
		$result = self::make_writer()->write_entry( $header, 0, self::zero_nonce(), $source, $dest );

		$bytes          = self::read_all( $dest );
		$hashed_portion = substr( $bytes, 0, -Sha256::DIGEST_SIZE );
		$expected_hash  = hash( 'sha256', $hashed_portion, true );

		$this->assertSame( $expected_hash, $result->entry_hash() );
	}

	/**
	 * After encoding, the on-disk header must carry the actual encoded size, not the draft value.
	 *
	 * The caller passes a draft EntryHeader with size_compressed = 0;
	 * EntryWriter encodes the payload, learns the actual size, and
	 * uses with_size_compressed() to produce a corrected header
	 * before writing it to disk.
	 *
	 * @return void
	 */
	public function test_write_entry_corrects_size_compressed_in_on_disk_header(): void {
		$source_contents = str_repeat( 'A', 1000 );
		$source          = self::memory_stream( $source_contents );
		$dest            = self::memory_stream();

		$draft_header = EntryHeader::for_file( 'a.txt', 1000, 0644, 0, 'application/octet-stream', 0 );
		$result       = self::make_writer()->write_entry( $draft_header, 1, self::zero_nonce(), $source, $dest );

		$parts         = self::parse_entry_record( self::read_all( $dest ) );
		$parsed_header = EntryHeader::from_bytes( $parts['header_bytes'] );

		$this->assertNotSame( 0, $parsed_header->size_compressed() );
		$this->assertSame( $result->payload_length(), $parsed_header->size_compressed() );
		$this->assertLessThan( 1000, $parsed_header->size_compressed() );
	}

	/**
	 * Writing a file entry with the gzip codec must produce encoded bytes that decode back to the source.
	 *
	 * @return void
	 */
	public function test_write_entry_file_gzip_codec_round_trip(): void {
		$source_contents = str_repeat( 'compress me ', 200 );
		$source          = self::memory_stream( $source_contents );
		$dest            = self::memory_stream();

		$header = EntryHeader::for_file( 'a.txt', strlen( $source_contents ), 0644, 0, 'application/octet-stream', 0 );
		$result = self::make_writer()->write_entry( $header, 1, self::zero_nonce(), $source, $dest );

		$parts = self::parse_entry_record( self::read_all( $dest ) );

		$this->assertSame( 1, $parts['codec_id'] );
		$this->assertNotSame( $source_contents, $parts['payload'] );
		$this->assertSame( $result->payload_length(), strlen( $parts['payload'] ) );

		$decoded = gzdecode( $parts['payload'] );
		$this->assertSame( $source_contents, $decoded );
	}

	/**
	 * Writing a db_chunk entry must produce the correct on-disk layout.
	 *
	 * @return void
	 */
	public function test_write_entry_db_chunk_round_trip(): void {
		$source_contents = "INSERT INTO wp_posts VALUES (1, 'hello');\n";
		$source          = self::memory_stream( $source_contents );
		$dest            = self::memory_stream();

		$header = EntryHeader::for_db_chunk( 0, 'wp_posts', 1, strlen( $source_contents ), 0 );
		$result = self::make_writer()->write_entry( $header, 0, self::zero_nonce(), $source, $dest );

		$parts         = self::parse_entry_record( self::read_all( $dest ) );
		$parsed_header = EntryHeader::from_bytes( $parts['header_bytes'] );

		$this->assertSame( EntryHeader::KIND_DB_CHUNK, $parsed_header->kind() );
		$this->assertSame( 'wp_posts', $parsed_header->table_name() );
		$this->assertSame( strlen( $source_contents ), $parsed_header->size_compressed() );
		$this->assertSame( $source_contents, $parts['payload'] );
		$this->assertSame( $result->entry_hash(), $parts['hash'] );
	}

	/**
	 * Writing a directory entry must produce a zero-payload record.
	 *
	 * Directories carry no payload per spec §6, so the source stream is
	 * empty and the encoded payload is zero bytes.
	 *
	 * @return void
	 */
	public function test_write_entry_directory_round_trip(): void {
		$source = self::memory_stream();
		$dest   = self::memory_stream();

		$header = EntryHeader::for_directory( 'wp-content/uploads/empty', 0755, 0 );
		$result = self::make_writer()->write_entry( $header, 0, self::zero_nonce(), $source, $dest );

		$this->assertSame( 0, $result->payload_length() );

		$parts         = self::parse_entry_record( self::read_all( $dest ) );
		$parsed_header = EntryHeader::from_bytes( $parts['header_bytes'] );

		$this->assertSame( EntryHeader::KIND_DIRECTORY, $parsed_header->kind() );
		$this->assertSame( 0, $parsed_header->size_compressed() );
		$this->assertSame( '', $parts['payload'] );
		$this->assertSame( $result->entry_hash(), $parts['hash'] );
	}

	/**
	 * Writing a symlink entry must produce a record whose payload is the target string.
	 *
	 * The symlink payload by convention is the target path's bytes,
	 * supplied by the caller as the source stream.
	 *
	 * @return void
	 */
	public function test_write_entry_symlink_round_trip(): void {
		$target_path = '/var/www/html/wp-content/uploads';
		$source      = self::memory_stream( $target_path );
		$dest        = self::memory_stream();

		$header = EntryHeader::for_symlink( 'wp-content/upload-link', $target_path, 0 );
		$result = self::make_writer()->write_entry( $header, 0, self::zero_nonce(), $source, $dest );

		$parts         = self::parse_entry_record( self::read_all( $dest ) );
		$parsed_header = EntryHeader::from_bytes( $parts['header_bytes'] );

		$this->assertSame( EntryHeader::KIND_SYMLINK, $parsed_header->kind() );
		$this->assertSame( $target_path, $parsed_header->target() );
		$this->assertSame( $target_path, $parts['payload'] );
		$this->assertSame( strlen( $target_path ), $result->payload_length() );
	}

	/**
	 * The raw codec must produce on-disk payload bytes identical to the source.
	 *
	 * @return void
	 */
	public function test_write_entry_raw_codec_payload_equals_source(): void {
		$source_contents = "arbitrary \x00\x01\x02 binary bytes \xFF";
		$source          = self::memory_stream( $source_contents );
		$dest            = self::memory_stream();

		$header = EntryHeader::for_file( 'a.bin', strlen( $source_contents ), 0644, 0, 'application/octet-stream', 0 );
		self::make_writer()->write_entry( $header, 0, self::zero_nonce(), $source, $dest );

		$parts = self::parse_entry_record( self::read_all( $dest ) );

		$this->assertSame( $source_contents, $parts['payload'] );
	}

	/**
	 * The gzip codec must produce on-disk payload bytes that differ from the source.
	 *
	 * For a highly compressible input, the encoded form is smaller and
	 * structurally different from the raw bytes.
	 *
	 * @return void
	 */
	public function test_write_entry_gzip_codec_payload_differs_from_source(): void {
		$source_contents = str_repeat( 'A', 1000 );
		$source          = self::memory_stream( $source_contents );
		$dest            = self::memory_stream();

		$header = EntryHeader::for_file( 'a.txt', 1000, 0644, 0, 'application/octet-stream', 0 );
		self::make_writer()->write_entry( $header, 1, self::zero_nonce(), $source, $dest );

		$parts = self::parse_entry_record( self::read_all( $dest ) );

		$this->assertNotSame( $source_contents, $parts['payload'] );
		$this->assertLessThan( 1000, strlen( $parts['payload'] ) );
	}

	/**
	 * EntryWriter must be reusable: multiple write_entry calls to the same destination must append cleanly.
	 *
	 * @return void
	 */
	public function test_write_entry_supports_multiple_entries_to_same_destination(): void {
		$writer = self::make_writer();
		$dest   = self::memory_stream();

		$source1 = self::memory_stream( 'first entry payload' );
		$header1 = EntryHeader::for_file( 'a.txt', 19, 0644, 0, 'application/octet-stream', 0 );
		$result1 = $writer->write_entry( $header1, 0, self::zero_nonce(), $source1, $dest );

		$source2 = self::memory_stream( 'second entry payload, slightly longer' );
		$header2 = EntryHeader::for_file( 'b.txt', 37, 0644, 0, 'application/octet-stream', 0 );
		$result2 = $writer->write_entry( $header2, 0, self::zero_nonce(), $source2, $dest );

		$all_bytes = self::read_all( $dest );

		$this->assertSame(
			$result1->total_entry_length() + $result2->total_entry_length(),
			strlen( $all_bytes )
		);
		$this->assertNotSame( $result1->entry_hash(), $result2->entry_hash() );
	}

	/**
	 * The write_entry method must reject a codec_id that is not registered.
	 *
	 * @return void
	 */
	public function test_write_entry_rejects_unknown_codec_id(): void {
		$writer  = self::make_writer();
		$source  = self::memory_stream( 'x' );
		$dest    = self::memory_stream();
		$header  = EntryHeader::for_file( 'a.txt', 1, 0644, 0, 'application/octet-stream', 0 );
		$unknown = 0xABCD;

		$this->expectException( InvalidArgumentException::class );

		$writer->write_entry( $header, $unknown, self::zero_nonce(), $source, $dest );
	}

	/**
	 * The write_entry method must reject a nonce shorter than NONCE_SIZE.
	 *
	 * @return void
	 */
	public function test_write_entry_rejects_short_nonce(): void {
		$writer = self::make_writer();
		$source = self::memory_stream( 'x' );
		$dest   = self::memory_stream();
		$header = EntryHeader::for_file( 'a.txt', 1, 0644, 0, 'application/octet-stream', 0 );

		$this->expectException( InvalidArgumentException::class );

		$writer->write_entry( $header, 0, str_repeat( "\x00", EntryWriter::NONCE_SIZE - 1 ), $source, $dest );
	}

	/**
	 * The write_entry method must reject a nonce longer than NONCE_SIZE.
	 *
	 * @return void
	 */
	public function test_write_entry_rejects_long_nonce(): void {
		$writer = self::make_writer();
		$source = self::memory_stream( 'x' );
		$dest   = self::memory_stream();
		$header = EntryHeader::for_file( 'a.txt', 1, 0644, 0, 'application/octet-stream', 0 );

		$this->expectException( InvalidArgumentException::class );

		$writer->write_entry( $header, 0, str_repeat( "\x00", EntryWriter::NONCE_SIZE + 1 ), $source, $dest );
	}

	/**
	 * The write_entry method must reject a source that is not a resource.
	 *
	 * @return void
	 */
	public function test_write_entry_rejects_non_resource_source(): void {
		$writer = self::make_writer();
		$dest   = self::memory_stream();
		$header = EntryHeader::for_file( 'a.txt', 1, 0644, 0, 'application/octet-stream', 0 );

		$this->expectException( InvalidArgumentException::class );

		$writer->write_entry( $header, 0, self::zero_nonce(), 'not a resource', $dest );
	}

	/**
	 * The write_entry method must reject a destination that is not a resource.
	 *
	 * @return void
	 */
	public function test_write_entry_rejects_non_resource_destination(): void {
		$writer = self::make_writer();
		$source = self::memory_stream( 'x' );
		$header = EntryHeader::for_file( 'a.txt', 1, 0644, 0, 'application/octet-stream', 0 );

		$this->expectException( InvalidArgumentException::class );

		$writer->write_entry( $header, 0, self::zero_nonce(), $source, 'not a resource' );
	}

	/**
	 * A file that shrank between scan and write must be recorded at its actual size.
	 *
	 * The scan-to-write TOCTOU race: the header declares the scan-time size,
	 * but by write time the file holds fewer bytes. The writer must record the
	 * byte count it actually captured — never the stale claim — and report the
	 * discrepancy, so the archive stays truthful and the caller can warn.
	 *
	 * @return void
	 */
	public function test_write_entry_corrects_declared_size_when_the_file_shrank(): void {
		$actual_contents = str_repeat( 'B', 400 );
		$source          = self::memory_stream( $actual_contents );
		$dest            = self::memory_stream();

		// The scan saw 1000 bytes; by write time only 400 remain.
		$draft_header = EntryHeader::for_file( 'shrunk.log', 1000, 0644, 1690000000, 'application/octet-stream', 0 );
		$result       = self::make_writer()->write_entry( $draft_header, 0, self::zero_nonce(), $source, $dest );

		$parts         = self::parse_entry_record( self::read_all( $dest ) );
		$parsed_header = EntryHeader::from_bytes( $parts['header_bytes'] );

		$this->assertSame( 400, $parsed_header->size(), 'The on-disk header must record the byte count actually captured.' );
		$this->assertTrue( $result->size_was_corrected(), 'The result must report that the size was corrected.' );
		$this->assertSame( 1000, $result->declared_size() );
		$this->assertSame( 400, $result->actual_size() );
		$this->assertSame( $actual_contents, $parts['payload'], 'The payload must be the bytes actually read.' );
	}

	/**
	 * A file that grew between scan and write must be recorded at its actual size.
	 *
	 * The growth direction of the same race: without the correction the header
	 * would under-declare the payload, which also quietly weakens the
	 * decompression-budget claim a reader relies on.
	 *
	 * @return void
	 */
	public function test_write_entry_corrects_declared_size_when_the_file_grew(): void {
		$actual_contents = str_repeat( 'C', 250 );
		$source          = self::memory_stream( $actual_contents );
		$dest            = self::memory_stream();

		// The scan saw 100 bytes; by write time there are 250.
		$draft_header = EntryHeader::for_file( 'grew.log', 100, 0644, 1690000000, 'application/octet-stream', 0 );
		$result       = self::make_writer()->write_entry( $draft_header, 0, self::zero_nonce(), $source, $dest );

		$parsed_header = EntryHeader::from_bytes( self::parse_entry_record( self::read_all( $dest ) )['header_bytes'] );

		$this->assertSame( 250, $parsed_header->size() );
		$this->assertTrue( $result->size_was_corrected() );
		$this->assertSame( 100, $result->declared_size() );
		$this->assertSame( 250, $result->actual_size() );
	}

	/**
	 * The trailing hash must cover the CORRECTED header bytes, not the draft.
	 *
	 * The hash is computed over the header as written to disk; if it covered
	 * the stale draft the record would fail verification on read.
	 *
	 * @return void
	 */
	public function test_write_entry_hash_covers_the_corrected_header(): void {
		$source = self::memory_stream( 'short' );
		$dest   = self::memory_stream();

		$draft_header = EntryHeader::for_file( 'shrunk.txt', 5000, 0644, 0, 'application/octet-stream', 0 );
		$result       = self::make_writer()->write_entry( $draft_header, 0, self::zero_nonce(), $source, $dest );

		$bytes          = self::read_all( $dest );
		$hashed_portion = substr( $bytes, 0, -Sha256::DIGEST_SIZE );

		$this->assertSame( hash( 'sha256', $hashed_portion, true ), $result->entry_hash() );
	}

	/**
	 * An unchanged file must produce no size correction and no report.
	 *
	 * @return void
	 */
	public function test_write_entry_reports_no_correction_when_content_matches(): void {
		$contents = 'exactly as scanned';
		$source   = self::memory_stream( $contents );
		$dest     = self::memory_stream();

		$header = EntryHeader::for_file( 'steady.txt', strlen( $contents ), 0644, 1690000000, 'application/octet-stream', 0 );
		$result = self::make_writer()->write_entry( $header, 0, self::zero_nonce(), $source, $dest );

		$parsed_header = EntryHeader::from_bytes( self::parse_entry_record( self::read_all( $dest ) )['header_bytes'] );

		$this->assertFalse( $result->size_was_corrected() );
		$this->assertNull( $result->declared_size() );
		$this->assertNull( $result->actual_size() );
		$this->assertSame( strlen( $contents ), $parsed_header->size() );
	}

	/**
	 * A db_chunk whose byte_count drifted from its payload must NOT be corrected.
	 *
	 * A db_chunk's byte_count is a sizing estimate, not a content claim (its
	 * truth is guaranteed by the consistent snapshot, ADR 0011), and only file
	 * entries carry the size field the correction exists for.
	 *
	 * @return void
	 */
	public function test_write_entry_does_not_correct_a_db_chunk_whose_byte_count_drifted(): void {
		$sql    = "INSERT INTO `wp_options` VALUES (1);\n";
		$source = self::memory_stream( $sql );
		$dest   = self::memory_stream();

		// byte_count deliberately does not match the payload length.
		$header = EntryHeader::for_db_chunk( 0, 'wp_options', 1, 5000, 0 );
		$result = self::make_writer()->write_entry( $header, 0, self::zero_nonce(), $source, $dest );

		$parsed_header = EntryHeader::from_bytes( self::parse_entry_record( self::read_all( $dest ) )['header_bytes'] );

		$this->assertFalse( $result->size_was_corrected() );
		$this->assertSame( 5000, $parsed_header->byte_count(), 'A db_chunk byte_count must pass through untouched.' );
	}
}
