<?php
/**
 * Encryption-path tests for the EntryWriter class.
 *
 * @package Pontifex\Tests\Unit\Archive\Writer
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Archive\Writer;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pontifex\Archive\Codec\CodecId;
use Pontifex\Archive\Codec\CodecRegistry;
use Pontifex\Archive\Codec\GzipCodec;
use Pontifex\Archive\Crypto\Cipher;
use Pontifex\Archive\Crypto\OpensslAesGcmCipher;
use Pontifex\Archive\Format\ByteOrder;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Integrity\Sha256;
use Pontifex\Archive\Writer\EntryWriter;

/**
 * Tests for {@see EntryWriter}'s encryption path.
 *
 * The openssl cipher backs these tests because it is available on every host
 * (no hardware-AES dependency, so no skips). They verify the write-side
 * structure of an encrypted entry: the encrypted codec id is recorded on
 * disk, the stored payload is the ciphertext with the 16-byte GCM tag
 * appended (so it is exactly size_compressed + 16 bytes and differs from the
 * plaintext), and the writer refuses an encrypted codec without a cipher and
 * key or with an unknown encryption family.
 */
final class EntryWriterEncryptionTest extends TestCase {

	/**
	 * Open a php://memory stream pre-populated with bytes, cursor rewound.
	 *
	 * @param string $contents Bytes to write before rewinding.
	 * @return resource A readable php://memory stream at offset 0.
	 * @throws \RuntimeException If php://memory cannot be opened.
	 */
	private static function memory_stream_with( string $contents = '' ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://memory is an in-process buffer, not a file; WP_Filesystem cannot open it.
		$stream = fopen( 'php://memory', 'r+b' );
		if ( false === $stream ) {
			throw new \RuntimeException( 'Could not open php://memory for test.' );
		}
		if ( '' !== $contents ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Operating on a test stream resource.
			fwrite( $stream, $contents );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource.
			rewind( $stream );
		}
		return $stream;
	}

	/**
	 * Rewind a stream and return all its contents.
	 *
	 * @param resource $stream The stream to read.
	 * @return string The full contents.
	 * @throws \RuntimeException If the stream cannot be read.
	 */
	private static function read_all( $stream ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource.
		rewind( $stream );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_stream_get_contents -- Operating on a test stream resource.
		$contents = stream_get_contents( $stream );
		if ( false === $contents ) {
			throw new \RuntimeException( 'Could not read test stream.' );
		}
		return $contents;
	}

	/**
	 * Parse an entry record's raw bytes into its component parts.
	 *
	 * @param string $bytes The complete entry record on disk.
	 * @return array<string, mixed> Keys: header_bytes, codec_id, nonce, payload, hash.
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
	 * A 32-byte key for the tests.
	 *
	 * @return string A Cipher::KEY_SIZE-byte string.
	 */
	private static function key(): string {
		return str_repeat( 'k', Cipher::KEY_SIZE );
	}

	/**
	 * A 12-byte nonce for the tests (entry index 0, then fixed bytes).
	 *
	 * @return string A NONCE_SIZE-byte string.
	 */
	private static function nonce(): string {
		return ByteOrder::pack_uint32( 0 ) . str_repeat( "\x07", EntryWriter::NONCE_SIZE - ByteOrder::UINT32_SIZE );
	}

	/**
	 * The encrypted codec id for gzip-then-AES (0x0101).
	 *
	 * @return int The encrypted codec id.
	 */
	private static function gzip_aes_codec_id(): int {
		return CodecId::with_aes_gcm( GzipCodec::ID );
	}

	/**
	 * An encrypted entry must record the encrypted codec id on disk.
	 *
	 * @return void
	 */
	public function test_encrypted_entry_records_encrypted_codec_id(): void {
		$writer = new EntryWriter( CodecRegistry::with_defaults() );
		$dest   = self::memory_stream_with();
		$header = EntryHeader::for_file( 'a.txt', 11, 0644, 0, 'application/octet-stream', 0 );

		$writer->write_entry( $header, self::gzip_aes_codec_id(), self::nonce(), self::memory_stream_with( 'hello world' ), $dest, new OpensslAesGcmCipher(), self::key() );

		$parts = self::parse_entry_record( self::read_all( $dest ) );
		$this->assertSame( self::gzip_aes_codec_id(), $parts['codec_id'] );
	}

	/**
	 * An encrypted entry's stored payload must be the ciphertext plus the 16-byte tag.
	 *
	 * The header's size_compressed records the compression output; the stored payload is
	 * that plus the GCM tag, and differs from the plaintext.
	 *
	 * @return void
	 */
	public function test_encrypted_payload_is_ciphertext_plus_tag(): void {
		$plaintext = str_repeat( 'compress me ', 50 );
		$writer    = new EntryWriter( CodecRegistry::with_defaults() );
		$dest      = self::memory_stream_with();
		$header    = EntryHeader::for_file( 'a.txt', strlen( $plaintext ), 0644, 0, 'application/octet-stream', 0 );

		$result = $writer->write_entry( $header, self::gzip_aes_codec_id(), self::nonce(), self::memory_stream_with( $plaintext ), $dest, new OpensslAesGcmCipher(), self::key() );

		$parts         = self::parse_entry_record( self::read_all( $dest ) );
		$parsed_header = EntryHeader::from_bytes( $parts['header_bytes'] );

		$this->assertSame( $parsed_header->size_compressed() + Cipher::TAG_SIZE, $result->payload_length() );
		$this->assertSame( $result->payload_length(), strlen( $parts['payload'] ) );
		$this->assertNotSame( $plaintext, $parts['payload'] );
	}

	/**
	 * An encrypted codec id without a cipher and key must be rejected.
	 *
	 * @return void
	 */
	public function test_encrypted_codec_without_cipher_and_key_throws(): void {
		$writer = new EntryWriter( CodecRegistry::with_defaults() );
		$header = EntryHeader::for_file( 'a.txt', 1, 0644, 0, 'application/octet-stream', 0 );

		$this->expectException( InvalidArgumentException::class );

		$writer->write_entry( $header, self::gzip_aes_codec_id(), self::nonce(), self::memory_stream_with( 'x' ), self::memory_stream_with() );
	}

	/**
	 * A codec id with an unknown encryption family must be rejected.
	 *
	 * @return void
	 */
	public function test_unknown_encryption_family_throws(): void {
		$writer = new EntryWriter( CodecRegistry::with_defaults() );
		$header = EntryHeader::for_file( 'a.txt', 1, 0644, 0, 'application/octet-stream', 0 );

		$this->expectException( InvalidArgumentException::class );

		// 0x0200 is an undefined encryption family in v1.
		$writer->write_entry( $header, 0x0201, self::nonce(), self::memory_stream_with( 'x' ), self::memory_stream_with(), new OpensslAesGcmCipher(), self::key() );
	}
}
