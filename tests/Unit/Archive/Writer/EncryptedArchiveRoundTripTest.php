<?php
/**
 * End-to-end round-trip tests for encrypted archives.
 *
 * @package Pontifex\Tests\Unit\Archive\Writer
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Archive\Writer;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use PHPUnit\Framework\TestCase;
use Pontifex\Archive\Codec\CodecId;
use Pontifex\Archive\Codec\CodecRegistry;
use Pontifex\Archive\Codec\GzipCodec;
use Pontifex\Archive\Crypto\Cipher;
use Pontifex\Archive\Crypto\EncryptionContext;
use Pontifex\Archive\Crypto\OpensslAesGcmCipher;
use Pontifex\Archive\Crypto\SodiumAesGcmCipher;
use Pontifex\Archive\Format\ByteOrder;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Format\ExporterInfo;
use Pontifex\Archive\Format\Footer;
use Pontifex\Archive\Format\ManifestEntry;
use Pontifex\Archive\Format\Provenance;
use Pontifex\Archive\Reader\ArchiveReader;
use Pontifex\Archive\Reader\EntryReader;
use Pontifex\Archive\Writer\ArchiveWriter;
use Pontifex\Archive\Writer\EntryPlan;
use Pontifex\Archive\Writer\EntryWriter;
use Pontifex\Archive\Writer\FooterWriter;

/**
 * End-to-end round-trip tests for encrypted archives.
 *
 * The headline tests write a full archive with an {@see EncryptionContext}
 * and read every entry back through a keyed {@see EntryReader}, proving the
 * write and read encryption paths meet: each entry kind reconstructs exactly,
 * the header's encrypted flag and the footer salt are set, the manifest
 * records encrypted codec ids, and every entry carries a unique
 * index-prefixed nonce. They also prove the archive fails closed when read
 * without the key or with the wrong key.
 *
 * The openssl cipher backs the main tests so they run on every host; a
 * separate cross-implementation test (sodium-written, openssl-read) is
 * skipped where hardware AES is unavailable.
 */
final class EncryptedArchiveRoundTripTest extends TestCase {

	/**
	 * Build a Provenance with arbitrary but valid values.
	 *
	 * @return Provenance A valid provenance block.
	 */
	private static function sample_provenance(): Provenance {
		return new Provenance(
			'6.6.1',
			'8.2.10',
			'https://example.test',
			'utf8mb4',
			'utf8mb4_unicode_520_ci',
			new ExporterInfo( 'pontifex', '0.3.0' ),
			new DateTimeImmutable( '2026-06-23T10:00:00+00:00', new DateTimeZone( 'UTC' ) )
		);
	}

	/**
	 * Open an empty php://memory stream.
	 *
	 * @return resource A readable and writable php://memory stream.
	 * @throws RuntimeException If php://memory cannot be opened.
	 */
	private static function memory_stream() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://memory is an in-process buffer, not a file; WP_Filesystem cannot open it.
		$stream = fopen( 'php://memory', 'r+b' );
		if ( false === $stream ) {
			throw new RuntimeException( 'Could not open php://memory for test.' );
		}
		return $stream;
	}

	/**
	 * Open a php://memory stream pre-populated with bytes, cursor rewound.
	 *
	 * @param string $contents Bytes to write before rewinding.
	 * @return resource A readable php://memory stream at offset 0.
	 * @throws RuntimeException If php://memory cannot be opened.
	 */
	private static function memory_stream_with( string $contents ) {
		$stream = self::memory_stream();
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
	 * @throws RuntimeException If the stream cannot be read.
	 */
	private static function read_all( $stream ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource.
		rewind( $stream );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_stream_get_contents -- Operating on a test stream resource.
		$contents = stream_get_contents( $stream );
		if ( false === $contents ) {
			throw new RuntimeException( 'Could not read test stream.' );
		}
		return $contents;
	}

	/**
	 * Build an ArchiveWriter with the default codec registry.
	 *
	 * @return ArchiveWriter A fresh archive writer.
	 */
	private static function make_writer(): ArchiveWriter {
		return new ArchiveWriter( new EntryWriter( CodecRegistry::with_defaults() ), new FooterWriter() );
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
	 * A 16-byte salt for the tests.
	 *
	 * @return string A 16-byte string.
	 */
	private static function salt(): string {
		return str_repeat( 's', 16 );
	}

	/**
	 * The 12-byte zero nonce a plan carries before the writer overrides it.
	 *
	 * @return string A NONCE_SIZE-byte string of zero bytes.
	 */
	private static function zero_nonce(): string {
		return str_repeat( "\x00", EntryWriter::NONCE_SIZE );
	}

	/**
	 * The payloads, by index, the sample plans carry.
	 *
	 * @return array<int, string> One payload per entry, in archive order.
	 */
	private static function sample_payloads(): array {
		return array(
			'file content for the encrypted round trip',
			"INSERT INTO wp_posts VALUES (1, 'hi');\n",
			'',
			'/var/www/html/wp-content/uploads',
		);
	}

	/**
	 * Build a fresh set of entry plans (one of each kind), with gzip codecs.
	 *
	 * Fresh because write_archive() consumes each plan's source stream.
	 *
	 * @return EntryPlan[] One file, db_chunk, directory and symlink plan.
	 */
	private static function sample_plans(): array {
		$payloads = self::sample_payloads();
		return array(
			new EntryPlan(
				EntryHeader::for_file( 'a.txt', strlen( $payloads[0] ), 0644, 0, 'application/octet-stream', 0 ),
				GzipCodec::ID,
				self::zero_nonce(),
				self::memory_stream_with( $payloads[0] )
			),
			new EntryPlan(
				EntryHeader::for_db_chunk( 0, 'wp_posts', 1, strlen( $payloads[1] ), 0 ),
				GzipCodec::ID,
				self::zero_nonce(),
				self::memory_stream_with( $payloads[1] )
			),
			new EntryPlan(
				EntryHeader::for_directory( 'wp-content/uploads', 0755, 0 ),
				GzipCodec::ID,
				self::zero_nonce(),
				self::memory_stream_with( $payloads[2] )
			),
			new EntryPlan(
				EntryHeader::for_symlink( 'wp-content/link', $payloads[3], 0 ),
				GzipCodec::ID,
				self::zero_nonce(),
				self::memory_stream_with( $payloads[3] )
			),
		);
	}

	/**
	 * Write an encrypted archive with the given cipher and return its bytes.
	 *
	 * @param Cipher $cipher The cipher the encryption context uses.
	 * @return string The complete archive bytes.
	 */
	private static function write_encrypted_archive( Cipher $cipher ): string {
		$context = new EncryptionContext( $cipher, self::key(), self::salt() );
		$dest    = self::memory_stream();
		self::make_writer()->write_archive( self::sample_provenance(), self::sample_plans(), $dest, null, $context );
		return self::read_all( $dest );
	}

	/**
	 * Extract the 12-byte nonce of an entry from the raw archive bytes.
	 *
	 * @param string        $archive The full archive bytes.
	 * @param ManifestEntry $entry   The manifest entry locating the record.
	 * @return string The 12-byte nonce.
	 */
	private static function entry_nonce( string $archive, ManifestEntry $entry ): string {
		$record        = substr( $archive, $entry->offset(), $entry->length() );
		$header_length = ByteOrder::unpack_uint32( substr( $record, 0, EntryHeader::LENGTH_PREFIX_SIZE ) );
		$nonce_start   = EntryHeader::LENGTH_PREFIX_SIZE + $header_length + ByteOrder::UINT16_SIZE;
		return substr( $record, $nonce_start, EntryWriter::NONCE_SIZE );
	}

	/**
	 * Every entry kind must decrypt back to its exact original payload.
	 *
	 * @return void
	 */
	public function test_round_trip_decrypts_every_kind(): void {
		$bytes    = self::write_encrypted_archive( new OpensslAesGcmCipher() );
		$payloads = self::sample_payloads();

		$source       = self::memory_stream_with( $bytes );
		$reader       = new ArchiveReader( $source );
		$entry_reader = new EntryReader( CodecRegistry::with_defaults(), new OpensslAesGcmCipher(), self::key() );

		$entries = $reader->manifest()->entries();
		$this->assertSame( count( $payloads ), count( $entries ) );

		foreach ( $entries as $index => $entry ) {
			$result = $entry_reader->read_entry( $source, $entry );
			$this->assertSame( $payloads[ $index ], $result->payload() );
		}
	}

	/**
	 * An encrypted archive must set the header's encrypted flag.
	 *
	 * @return void
	 */
	public function test_sets_encrypted_flag(): void {
		$bytes  = self::write_encrypted_archive( new OpensslAesGcmCipher() );
		$reader = new ArchiveReader( self::memory_stream_with( $bytes ) );

		$this->assertTrue( $reader->header()->is_encrypted() );
	}

	/**
	 * An encrypted archive must write the salt into the footer, not the zero salt.
	 *
	 * @return void
	 */
	public function test_writes_salt_into_footer(): void {
		$bytes  = self::write_encrypted_archive( new OpensslAesGcmCipher() );
		$reader = new ArchiveReader( self::memory_stream_with( $bytes ) );

		$this->assertSame( self::salt(), $reader->footer()->argon2id_salt() );
		$this->assertNotSame( Footer::ZERO_SALT, $reader->footer()->argon2id_salt() );
	}

	/**
	 * The manifest must record encrypted codec ids (gzip upgraded to gzip-over-AES).
	 *
	 * @return void
	 */
	public function test_manifest_records_encrypted_codec_ids(): void {
		$bytes  = self::write_encrypted_archive( new OpensslAesGcmCipher() );
		$reader = new ArchiveReader( self::memory_stream_with( $bytes ) );

		foreach ( $reader->manifest()->entries() as $entry ) {
			$this->assertTrue( CodecId::is_encrypted( $entry->codec_id() ) );
			$this->assertSame( GzipCodec::ID, CodecId::compression( $entry->codec_id() ) );
		}
	}

	/**
	 * Each entry must carry a nonce prefixed with its index and a distinct random tail.
	 *
	 * @return void
	 */
	public function test_entries_use_indexed_unique_nonces(): void {
		$bytes  = self::write_encrypted_archive( new OpensslAesGcmCipher() );
		$reader = new ArchiveReader( self::memory_stream_with( $bytes ) );

		$random_tails = array();
		foreach ( $reader->manifest()->entries() as $index => $entry ) {
			$nonce = self::entry_nonce( $bytes, $entry );
			$this->assertSame( ByteOrder::pack_uint32( $index ), substr( $nonce, 0, ByteOrder::UINT32_SIZE ) );
			$random_tails[] = substr( $nonce, ByteOrder::UINT32_SIZE );
		}

		$this->assertSame( count( $random_tails ), count( array_unique( $random_tails ) ) );
	}

	/**
	 * Reading an encrypted entry without a key must fail closed with a clear error.
	 *
	 * @return void
	 */
	public function test_reading_without_key_fails_closed(): void {
		$bytes  = self::write_encrypted_archive( new OpensslAesGcmCipher() );
		$source = self::memory_stream_with( $bytes );
		$reader = new ArchiveReader( $source );

		$entry        = $reader->manifest()->entries()[0];
		$entry_reader = new EntryReader( CodecRegistry::with_defaults() );

		$this->expectException( RuntimeException::class );

		$entry_reader->read_entry( $source, $entry );
	}

	/**
	 * Reading an encrypted entry with the wrong key must fail.
	 *
	 * @return void
	 */
	public function test_reading_with_wrong_key_fails(): void {
		$bytes  = self::write_encrypted_archive( new OpensslAesGcmCipher() );
		$source = self::memory_stream_with( $bytes );
		$reader = new ArchiveReader( $source );

		$entry        = $reader->manifest()->entries()[0];
		$entry_reader = new EntryReader( CodecRegistry::with_defaults(), new OpensslAesGcmCipher(), str_repeat( 'x', Cipher::KEY_SIZE ) );

		$this->expectException( RuntimeException::class );

		$entry_reader->read_entry( $source, $entry );
	}

	/**
	 * An archive encrypted with sodium must read back under openssl, and vice versa.
	 *
	 * This is the portability guarantee: a hardware-AES host (sodium) and a
	 * host without it (openssl) must produce and consume interchangeable
	 * archives. Skipped where hardware AES is unavailable.
	 *
	 * @return void
	 */
	public function test_sodium_written_archive_reads_under_openssl(): void {
		if ( ! function_exists( 'sodium_crypto_aead_aes256gcm_is_available' )
			|| ! sodium_crypto_aead_aes256gcm_is_available() ) {
			$this->markTestSkipped( 'Hardware AES (ext-sodium AES-256-GCM) is not available on this host.' );
		}

		$bytes    = self::write_encrypted_archive( new SodiumAesGcmCipher() );
		$payloads = self::sample_payloads();

		$source       = self::memory_stream_with( $bytes );
		$reader       = new ArchiveReader( $source );
		$entry_reader = new EntryReader( CodecRegistry::with_defaults(), new OpensslAesGcmCipher(), self::key() );

		foreach ( $reader->manifest()->entries() as $index => $entry ) {
			$result = $entry_reader->read_entry( $source, $entry );
			$this->assertSame( $payloads[ $index ], $result->payload() );
		}
	}

	/**
	 * Without an encryption context the archive stays unencrypted: no flag, zero salt, plain codecs.
	 *
	 * @return void
	 */
	public function test_unencrypted_archive_has_no_flag_or_salt(): void {
		$dest = self::memory_stream();
		self::make_writer()->write_archive( self::sample_provenance(), self::sample_plans(), $dest );

		$reader = new ArchiveReader( self::memory_stream_with( self::read_all( $dest ) ) );

		$this->assertFalse( $reader->header()->is_encrypted() );
		$this->assertSame( Footer::ZERO_SALT, $reader->footer()->argon2id_salt() );
		foreach ( $reader->manifest()->entries() as $entry ) {
			$this->assertFalse( CodecId::is_encrypted( $entry->codec_id() ) );
		}
	}
}
