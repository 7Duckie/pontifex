<?php
/**
 * Unit tests for the EntryReader class.
 *
 * @package Pontifex\Tests\Unit\Archive\Reader
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Archive\Reader;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Pontifex\Archive\Codec\CodecRegistry;
use Pontifex\Archive\Codec\GzipCodec;
use Pontifex\Archive\Codec\RawCodec;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Format\ManifestEntry;
use Pontifex\Archive\Integrity\Sha256;
use Pontifex\Archive\Reader\EntryReader;
use Pontifex\Archive\Reader\EntryReadResult;
use Pontifex\Archive\Writer\EntryWriter;

/**
 * Tests for {@see EntryReader}.
 *
 * Strategy: write each test fixture using a real EntryWriter so the
 * on-disk bytes are known to be format-correct, build a matching
 * ManifestEntry pointing at those bytes, then call EntryReader and
 * verify the round-trip. Rejection tests corrupt the bytes after
 * the writer produces them.
 */
final class EntryReaderTest extends TestCase {

	/**
	 * Build a fresh EntryReader with the default codec registry.
	 *
	 * @return EntryReader A reader ready to call read_entry on.
	 */
	private static function make_reader(): EntryReader {
		return new EntryReader( CodecRegistry::with_defaults() );
	}

	/**
	 * Build a fresh EntryWriter with the default codec registry.
	 *
	 * @return EntryWriter A writer for setting up test fixtures.
	 */
	private static function make_writer(): EntryWriter {
		return new EntryWriter( CodecRegistry::with_defaults() );
	}

	/**
	 * Open a fresh php://memory stream.
	 *
	 * @param string $contents Optional initial contents to write and rewind.
	 * @return resource A readable, seekable in-memory stream.
	 * @throws RuntimeException If php://memory cannot be opened.
	 */
	private static function memory_stream( string $contents = '' ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://memory is an in-process buffer, not a file.
		$stream = fopen( 'php://memory', 'r+b' );
		if ( false === $stream ) {
			throw new RuntimeException( 'Could not open php://memory.' );
		}
		if ( '' !== $contents ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Operating on a test stream resource, not a filesystem path.
			fwrite( $stream, $contents );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource, not a filesystem path.
			rewind( $stream );
		}
		return $stream;
	}

	/**
	 * Return the all-zero nonce used for unencrypted v0.1.0 entries.
	 *
	 * @return string A NONCE_SIZE-byte string of zero bytes.
	 */
	private static function zero_nonce(): string {
		return str_repeat( "\0", EntryWriter::NONCE_SIZE );
	}

	/**
	 * Read all bytes from a stream, rewinding first.
	 *
	 * @param resource $stream The stream to read.
	 * @return string The full contents.
	 * @throws RuntimeException If the stream cannot be read.
	 */
	private static function read_all( $stream ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource, not a filesystem path.
		rewind( $stream );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_stream_get_contents -- Operating on a test stream resource, not a filesystem path.
		$contents = stream_get_contents( $stream );
		if ( false === $contents ) {
			throw new RuntimeException( 'Could not read test stream.' );
		}
		return $contents;
	}

	/**
	 * Write a file entry with EntryWriter and return [stream, ManifestEntry] for reading back.
	 *
	 * @param string $path     Relative path on the entry.
	 * @param string $contents The file contents to write as the payload.
	 * @param int    $codec_id Codec id to use (RawCodec::ID or GzipCodec::ID).
	 * @return array{0: resource, 1: ManifestEntry} The archive stream and matching manifest entry.
	 */
	private static function write_file_entry_to_fixture( string $path, string $contents, int $codec_id ): array {
		$dest   = self::memory_stream();
		$source = self::memory_stream( $contents );
		$header = EntryHeader::for_file( $path, strlen( $contents ), 0644, 1690000000, 'application/octet-stream', 0 );
		$result = self::make_writer()->write_entry( $header, $codec_id, self::zero_nonce(), $source, $dest );

		$manifest_entry = ManifestEntry::for_file( 0, 0, $result->total_entry_length(), $path, $codec_id, $result->entry_hash() );

		return array( $dest, $manifest_entry );
	}

	/**
	 * A file entry written with the raw codec must round-trip through EntryReader.
	 *
	 * @return void
	 */
	public function test_round_trip_file_raw_codec(): void {
		$contents = 'hello world from the round-trip test';
		$fixture  = self::write_file_entry_to_fixture( 'test.txt', $contents, RawCodec::ID );

		$result = self::make_reader()->read_entry( $fixture[0], $fixture[1] );

		$this->assertInstanceOf( EntryReadResult::class, $result );
		$this->assertSame( EntryHeader::KIND_FILE, $result->header()->kind() );
		$this->assertSame( 'test.txt', $result->header()->path() );
		$this->assertSame( $contents, $result->payload() );
	}

	/**
	 * A file entry written with the gzip codec must round-trip through EntryReader.
	 *
	 * @return void
	 */
	public function test_round_trip_file_gzip_codec(): void {
		// Repetitive content so gzip actually compresses noticeably; not strictly required for correctness.
		$contents = str_repeat( 'compress me ', 100 );
		$fixture  = self::write_file_entry_to_fixture( 'compressible.txt', $contents, GzipCodec::ID );

		$result = self::make_reader()->read_entry( $fixture[0], $fixture[1] );

		$this->assertSame( $contents, $result->payload() );
		$this->assertSame( 'compressible.txt', $result->header()->path() );
	}

	/**
	 * A db_chunk entry must round-trip through EntryReader.
	 *
	 * @return void
	 */
	public function test_round_trip_db_chunk(): void {
		$sql_bytes = "CREATE TABLE `wp_options` (id INT);\nINSERT INTO `wp_options` VALUES (1);\n";
		$dest      = self::memory_stream();
		$source    = self::memory_stream( $sql_bytes );
		$header    = EntryHeader::for_db_chunk( 0, 'wp_options', 2, strlen( $sql_bytes ), 0 );
		$result    = self::make_writer()->write_entry( $header, RawCodec::ID, self::zero_nonce(), $source, $dest );

		$manifest_entry = ManifestEntry::for_db_chunk( 0, 0, $result->total_entry_length(), 0, RawCodec::ID, $result->entry_hash() );

		$read_result = self::make_reader()->read_entry( $dest, $manifest_entry );

		$this->assertSame( EntryHeader::KIND_DB_CHUNK, $read_result->header()->kind() );
		$this->assertSame( 'wp_options', $read_result->header()->table_name() );
		$this->assertSame( $sql_bytes, $read_result->payload() );
	}

	/**
	 * A directory entry must round-trip with an empty payload.
	 *
	 * @return void
	 */
	public function test_round_trip_directory(): void {
		$dest   = self::memory_stream();
		$source = self::memory_stream();
		$header = EntryHeader::for_directory( 'wp-content/uploads', 0755, 0 );
		$result = self::make_writer()->write_entry( $header, RawCodec::ID, self::zero_nonce(), $source, $dest );

		$manifest_entry = ManifestEntry::for_directory( 0, 0, $result->total_entry_length(), 'wp-content/uploads', RawCodec::ID, $result->entry_hash() );

		$read_result = self::make_reader()->read_entry( $dest, $manifest_entry );

		$this->assertSame( EntryHeader::KIND_DIRECTORY, $read_result->header()->kind() );
		$this->assertSame( 'wp-content/uploads', $read_result->header()->path() );
		$this->assertSame( '', $read_result->payload() );
	}

	/**
	 * A symlink entry must round-trip and preserve its target.
	 *
	 * @return void
	 */
	public function test_round_trip_symlink(): void {
		$dest   = self::memory_stream();
		$source = self::memory_stream();
		$header = EntryHeader::for_symlink( 'wp-content/cache', '/tmp/wp-cache', 0 );
		$result = self::make_writer()->write_entry( $header, RawCodec::ID, self::zero_nonce(), $source, $dest );

		$manifest_entry = ManifestEntry::for_symlink( 0, 0, $result->total_entry_length(), 'wp-content/cache', RawCodec::ID, $result->entry_hash() );

		$read_result = self::make_reader()->read_entry( $dest, $manifest_entry );

		$this->assertSame( EntryHeader::KIND_SYMLINK, $read_result->header()->kind() );
		$this->assertSame( '/tmp/wp-cache', $read_result->header()->target() );
	}

	/**
	 * The read_entry method must reject a non-resource source.
	 *
	 * @return void
	 */
	public function test_read_entry_rejects_non_resource_source(): void {
		$manifest_entry = ManifestEntry::for_file( 0, 0, 100, 'a.txt', 0, str_repeat( "\0", Sha256::DIGEST_SIZE ) );

		$this->expectException( InvalidArgumentException::class );

		// @phpstan-ignore-next-line — intentionally passing wrong type to verify validation.
		self::make_reader()->read_entry( 'not a resource', $manifest_entry );
	}

	/**
	 * The read_entry method must reject an unseekable source stream.
	 *
	 * @return void
	 */
	public function test_read_entry_rejects_unseekable_source(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://output is an in-process pseudo-stream, not a file.
		$stream         = fopen( 'php://output', 'w' );
		$manifest_entry = ManifestEntry::for_file( 0, 0, 100, 'a.txt', 0, str_repeat( "\0", Sha256::DIGEST_SIZE ) );

		$this->expectException( InvalidArgumentException::class );

		try {
			self::make_reader()->read_entry( $stream, $manifest_entry );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Cleanup of test stream resource.
			fclose( $stream );
		}
	}

	/**
	 * The read_entry method must reject a stream too short to contain the declared entry record.
	 *
	 * @return void
	 */
	public function test_read_entry_rejects_truncated_stream(): void {
		$stream         = self::memory_stream( "\x00\x00\x00" );
		$manifest_entry = ManifestEntry::for_file( 0, 0, 1000, 'a.txt', 0, str_repeat( "\0", Sha256::DIGEST_SIZE ) );

		$this->expectException( RuntimeException::class );

		self::make_reader()->read_entry( $stream, $manifest_entry );
	}

	/**
	 * The read_entry method must reject an entry whose codec_id disagrees with the manifest entry.
	 *
	 * @return void
	 */
	public function test_read_entry_rejects_codec_id_mismatch(): void {
		$fixture             = self::write_file_entry_to_fixture( 'a.txt', 'data', RawCodec::ID );
		$stream              = $fixture[0];
		$real_manifest_entry = $fixture[1];

		// Construct a manifest entry claiming GzipCodec for the same on-disk bytes (which used RawCodec).
		$tampered_entry = ManifestEntry::for_file(
			$real_manifest_entry->index(),
			$real_manifest_entry->offset(),
			$real_manifest_entry->length(),
			'a.txt',
			GzipCodec::ID,
			$real_manifest_entry->entry_hash()
		);

		$this->expectException( RuntimeException::class );

		self::make_reader()->read_entry( $stream, $tampered_entry );
	}

	/**
	 * The read_entry method must reject an entry whose recorded hash disagrees with the on-disk bytes.
	 *
	 * @return void
	 */
	public function test_read_entry_rejects_hash_mismatch(): void {
		$fixture             = self::write_file_entry_to_fixture( 'a.txt', 'data', RawCodec::ID );
		$stream              = $fixture[0];
		$real_manifest_entry = $fixture[1];

		// Flip a byte in the middle of the on-disk record (mutates the payload area).
		$bytes    = self::read_all( $stream );
		$middle   = (int) ( strlen( $bytes ) / 2 );
		$tampered = substr( $bytes, 0, $middle ) . "\xFF" . substr( $bytes, $middle + 1 );

		$tampered_stream = self::memory_stream( $tampered );

		$this->expectException( RuntimeException::class );

		self::make_reader()->read_entry( $tampered_stream, $real_manifest_entry );
	}

	/**
	 * The read_entry method must reject an entry that uses a codec id not in the registry.
	 *
	 * @return void
	 */
	public function test_read_entry_rejects_unknown_codec(): void {
		// Write a valid entry with codec 0 (raw), then construct an entry pointing at it with a fake codec id.
		// To trip the "not registered" check before the codec_id-mismatch check, we need both on-disk and manifest to agree on a fake id.
		// Easiest: build a registry without RawCodec, then read an archive that uses RawCodec.
		$empty_registry = new CodecRegistry();
		$reader         = new EntryReader( $empty_registry );

		$fixture        = self::write_file_entry_to_fixture( 'a.txt', 'data', RawCodec::ID );
		$stream         = $fixture[0];
		$manifest_entry = $fixture[1];

		$this->expectException( RuntimeException::class );

		$reader->read_entry( $stream, $manifest_entry );
	}
}
