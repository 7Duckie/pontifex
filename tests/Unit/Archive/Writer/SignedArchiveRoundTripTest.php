<?php
/**
 * End-to-end round-trip tests for signed archives.
 *
 * @package Pontifex\Tests\Unit\Archive\Writer
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Archive\Writer;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use PHPUnit\Framework\TestCase;
use Pontifex\Archive\Codec\CodecRegistry;
use Pontifex\Archive\Codec\RawCodec;
use Pontifex\Archive\Crypto\Cipher;
use Pontifex\Archive\Crypto\EncryptionContext;
use Pontifex\Archive\Crypto\OpensslAesGcmCipher;
use Pontifex\Archive\Crypto\SigningContext;
use Pontifex\Archive\Crypto\SigningKeypair;
use Pontifex\Archive\Format\ArchiveSignature;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Format\ExporterInfo;
use Pontifex\Archive\Format\Provenance;
use Pontifex\Archive\Reader\ArchiveReader;
use Pontifex\Archive\Reader\EntryReader;
use Pontifex\Archive\Writer\ArchiveWriter;
use Pontifex\Archive\Writer\EntryPlan;
use Pontifex\Archive\Writer\EntryWriter;
use Pontifex\Archive\Writer\FooterWriter;

/**
 * End-to-end round-trip tests for signed archives.
 *
 * Writes a full archive with a {@see SigningContext} and reads it back: the
 * signed flag and the appended block are present, the footer and manifest are
 * still found (now located before the signature), and the signature verifies
 * against the right key while failing closed for a wrong key or a tampered
 * byte. A combined signed-and-encrypted archive proves the two are independent
 * and that signing covers the encrypted bytes.
 *
 * Keypairs are generated at runtime, so no key material is hard-coded.
 */
final class SignedArchiveRoundTripTest extends TestCase {

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
	 * Open an empty php://memory stream (seekable, readable and writable).
	 *
	 * @return resource A php://memory stream.
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
	 * Rewind a stream and return all of its contents.
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
	 * The payloads, by index, the sample plans carry.
	 *
	 * @return array<int, string> One payload per entry, in archive order.
	 */
	private static function sample_payloads(): array {
		return array(
			'file content for the signed round trip',
			"INSERT INTO wp_posts VALUES (1, 'hi');\n",
			'',
			'/var/www/html/wp-content/uploads',
		);
	}

	/**
	 * Build a fresh set of entry plans (one of each kind).
	 *
	 * Fresh because write_archive() consumes each plan's source stream.
	 *
	 * @return EntryPlan[] One file, db_chunk, directory and symlink plan.
	 */
	private static function sample_plans(): array {
		$payloads = self::sample_payloads();
		$nonce    = str_repeat( "\x00", EntryWriter::NONCE_SIZE );
		return array(
			new EntryPlan(
				EntryHeader::for_file( 'a.txt', strlen( $payloads[0] ), 0644, 0, 'application/octet-stream', 0 ),
				RawCodec::ID,
				$nonce,
				self::memory_stream_with( $payloads[0] )
			),
			new EntryPlan(
				EntryHeader::for_db_chunk( 0, 'wp_posts', 1, strlen( $payloads[1] ), 0 ),
				RawCodec::ID,
				$nonce,
				self::memory_stream_with( $payloads[1] )
			),
			new EntryPlan(
				EntryHeader::for_directory( 'wp-content/uploads', 0755, 0 ),
				RawCodec::ID,
				$nonce,
				self::memory_stream_with( $payloads[2] )
			),
			new EntryPlan(
				EntryHeader::for_symlink( 'wp-content/link', $payloads[3], 0 ),
				RawCodec::ID,
				$nonce,
				self::memory_stream_with( $payloads[3] )
			),
		);
	}

	/**
	 * Write a signed archive with the given keypair and return its bytes.
	 *
	 * @param SigningKeypair $keypair The keypair to sign with.
	 * @return string The complete archive bytes, including the signature block.
	 */
	private static function write_signed_archive( SigningKeypair $keypair ): string {
		$dest = self::memory_stream();
		self::make_writer()->write_archive(
			self::sample_provenance(),
			self::sample_plans(),
			$dest,
			null,
			null,
			SigningContext::from_keypair( $keypair )
		);
		return self::read_all( $dest );
	}

	/**
	 * A signed archive sets the signed flag and appends a 100-byte block carrying the key id.
	 *
	 * @return void
	 */
	public function test_signed_archive_sets_the_flag_and_appends_the_block(): void {
		$keypair = SigningKeypair::generate();
		$bytes   = self::write_signed_archive( $keypair );

		$reader = new ArchiveReader( self::memory_stream_with( $bytes ) );

		$this->assertTrue( $reader->header()->is_signed() );
		$this->assertInstanceOf( ArchiveSignature::class, $reader->signature() );
		$this->assertSame( $keypair->key_id(), $reader->signature()->key_id() );
		// The block is the final 100 bytes of the archive.
		$this->assertSame( $reader->signature()->to_bytes(), substr( $bytes, -ArchiveSignature::SIZE ) );
	}

	/**
	 * The footer and manifest are still found, now located before the signature block.
	 *
	 * @return void
	 */
	public function test_footer_and_manifest_are_found_before_the_signature(): void {
		$bytes  = self::write_signed_archive( SigningKeypair::generate() );
		$reader = new ArchiveReader( self::memory_stream_with( $bytes ) );

		// manifest() cross-checks its hash against the footer; reaching the right
		// entry count proves both the footer and manifest were located correctly.
		$this->assertCount( count( self::sample_payloads() ), $reader->manifest()->entries() );
	}

	/**
	 * A signature made by the matching key verifies true.
	 *
	 * @return void
	 */
	public function test_verify_signature_true_with_the_correct_key(): void {
		$keypair = SigningKeypair::generate();
		$reader  = new ArchiveReader( self::memory_stream_with( self::write_signed_archive( $keypair ) ) );

		$this->assertTrue( $reader->verify_signature( $keypair->public_key() ) );
	}

	/**
	 * Verifying against a different public key fails.
	 *
	 * @return void
	 */
	public function test_verify_signature_false_with_a_wrong_key(): void {
		$reader = new ArchiveReader( self::memory_stream_with( self::write_signed_archive( SigningKeypair::generate() ) ) );

		$this->assertFalse( $reader->verify_signature( SigningKeypair::generate()->public_key() ) );
	}

	/**
	 * Altering a byte within the signed range makes verification fail.
	 *
	 * @return void
	 */
	public function test_verify_signature_false_when_a_signed_byte_is_tampered(): void {
		$keypair = SigningKeypair::generate();
		$bytes   = self::write_signed_archive( $keypair );

		// Flip a byte in the provenance region (offset 20): inside the signed range,
		// but not part of the header, footer, or signature block the reader parses
		// eagerly, so the archive still opens — and the signature no longer matches.
		$bytes[20] = chr( ord( $bytes[20] ) ^ 0xFF );

		$reader = new ArchiveReader( self::memory_stream_with( $bytes ) );

		$this->assertFalse( $reader->verify_signature( $keypair->public_key() ) );
	}

	/**
	 * An unsigned archive has no signature block and cannot be verified.
	 *
	 * @return void
	 */
	public function test_unsigned_archive_has_no_signature_and_verify_is_false(): void {
		$dest = self::memory_stream();
		self::make_writer()->write_archive( self::sample_provenance(), self::sample_plans(), $dest );

		$reader = new ArchiveReader( self::memory_stream_with( self::read_all( $dest ) ) );

		$this->assertFalse( $reader->header()->is_signed() );
		$this->assertNull( $reader->signature() );
		$this->assertFalse( $reader->verify_signature( SigningKeypair::generate()->public_key() ) );
	}

	/**
	 * An archive can be both signed and encrypted: each entry decrypts and the signature verifies.
	 *
	 * @return void
	 */
	public function test_signed_and_encrypted_archive_round_trips(): void {
		$keypair    = SigningKeypair::generate();
		$key        = str_repeat( 'k', Cipher::KEY_SIZE );
		$encryption = new EncryptionContext( new OpensslAesGcmCipher(), $key, str_repeat( 's', 16 ) );

		$dest = self::memory_stream();
		self::make_writer()->write_archive(
			self::sample_provenance(),
			self::sample_plans(),
			$dest,
			null,
			$encryption,
			SigningContext::from_keypair( $keypair )
		);
		$bytes = self::read_all( $dest );

		$source = self::memory_stream_with( $bytes );
		$reader = new ArchiveReader( $source );

		$this->assertTrue( $reader->header()->is_encrypted() );
		$this->assertTrue( $reader->header()->is_signed() );
		$this->assertTrue( $reader->verify_signature( $keypair->public_key() ) );

		// The signature covers the encrypted bytes, and the entries still decrypt.
		$payloads     = self::sample_payloads();
		$entry_reader = new EntryReader( CodecRegistry::with_defaults(), new OpensslAesGcmCipher(), $key );
		foreach ( $reader->manifest()->entries() as $index => $entry ) {
			$this->assertSame( $payloads[ $index ], $entry_reader->read_entry( $source, $entry )->payload() );
		}
	}

	/**
	 * Signing a write-only destination is refused up front, before anything is written.
	 *
	 * @return void
	 */
	public function test_writer_rejects_a_non_readable_destination_when_signing(): void {
		$path = tempnam( sys_get_temp_dir(), 'pontifex-sign-wb-' );
		if ( false === $path ) {
			$this->fail( 'Could not create a temp file for the test.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Opening a write-only file handle to prove signing refuses a non-readable destination; WP_Filesystem cannot produce a raw stream.
		$dest = fopen( $path, 'wb' );
		if ( false === $dest ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test cleanup of a file the test created.
			unlink( $path );
			$this->fail( 'Could not open the temp file write-only.' );
		}

		try {
			$this->expectException( InvalidArgumentException::class );
			self::make_writer()->write_archive(
				self::sample_provenance(),
				self::sample_plans(),
				$dest,
				null,
				null,
				SigningContext::from_keypair( SigningKeypair::generate() )
			);
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the test's own handle.
			fclose( $dest );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test cleanup of a file the test created.
			unlink( $path );
		}
	}
}
