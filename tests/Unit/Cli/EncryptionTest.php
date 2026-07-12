<?php
/**
 * Unit tests for the CLI-side encryption helper.
 *
 * @package Pontifex\Tests\Unit\Cli
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Cli;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use PHPUnit\Framework\TestCase;
use Pontifex\Archive\Codec\CodecRegistry;
use Pontifex\Archive\Codec\RawCodec;
use Pontifex\Archive\Crypto\Argon2idKdf;
use Pontifex\Archive\Crypto\Cipher;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Format\ExporterInfo;
use Pontifex\Archive\Format\Provenance;
use Pontifex\Archive\Reader\ArchiveReader;
use Pontifex\Archive\Writer\ArchiveWriter;
use Pontifex\Archive\Writer\EntryPlan;
use Pontifex\Archive\Writer\EntryWriter;
use Pontifex\Archive\Writer\FooterWriter;
use Pontifex\Cli\Encryption;
use Pontifex\Tests\Unit\Cli\Fakes\FakePassphraseSource;

/**
 * Behavioural coverage of {@see Encryption}: the passphrase policy and the
 * key wiring that joins the CLI commands to the archive encryption layer.
 *
 * The passphrase-collection tests drive a {@see FakePassphraseSource} so the
 * policy (double-entry confirmation, the minimum length) is asserted without a
 * terminal. The key-wiring tests write a real single-entry archive — plain or
 * encrypted with {@see Encryption::context} — and read it back through the
 * reader {@see Encryption::entry_reader} builds, proving the two halves derive
 * the same key from the archive's stored salt and that a missing or wrong
 * passphrase fails closed.
 */
final class EncryptionTest extends TestCase {

	/**
	 * A passphrase comfortably above the minimum length.
	 *
	 * @var string
	 */
	private const GOOD_PASSPHRASE = 'a-good-passphrase';

	/**
	 * A passphrase below MIN_PASSPHRASE_LENGTH.
	 *
	 * @var string
	 */
	private const SHORT_PASSPHRASE = 'short';

	// -------------------------------------------------------------------------
	// collect_for_export(): double-entry confirmation and the minimum length.
	// -------------------------------------------------------------------------

	/**
	 * Reading from STDIN returns the piped line verbatim.
	 *
	 * @return void
	 */
	public function test_collect_for_export_via_stdin_returns_the_line(): void {
		$source = new FakePassphraseSource( array(), self::GOOD_PASSPHRASE );

		$this->assertSame( self::GOOD_PASSPHRASE, Encryption::collect_for_export( $source, true ) );
	}

	/**
	 * A STDIN passphrase below the minimum length is refused.
	 *
	 * @return void
	 */
	public function test_collect_for_export_rejects_a_short_stdin_passphrase(): void {
		$source = new FakePassphraseSource( array(), self::SHORT_PASSPHRASE );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'at least' );

		Encryption::collect_for_export( $source, true );
	}

	/**
	 * A multibyte passphrase is measured by characters, not bytes.
	 *
	 * Nine two-byte characters is 18 bytes but only 9 characters — above the old
	 * byte-count minimum yet below the character minimum, so it is refused.
	 *
	 * @return void
	 */
	public function test_collect_for_export_measures_multibyte_passphrase_by_characters(): void {
		if ( ! function_exists( 'mb_strlen' ) ) {
			self::markTestSkipped( 'ext-mbstring is required to measure characters.' );
		}

		$source = new FakePassphraseSource( array(), str_repeat( 'é', 9 ) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'at least' );

		Encryption::collect_for_export( $source, true );
	}

	/**
	 * Two matching hidden prompts above the minimum return the passphrase.
	 *
	 * @return void
	 */
	public function test_collect_for_export_accepts_matching_prompts(): void {
		$source = new FakePassphraseSource( array( self::GOOD_PASSPHRASE, self::GOOD_PASSPHRASE ) );

		$this->assertSame( self::GOOD_PASSPHRASE, Encryption::collect_for_export( $source, false ) );
	}

	/**
	 * Two hidden prompts that disagree are refused before any key is derived.
	 *
	 * @return void
	 */
	public function test_collect_for_export_rejects_mismatched_prompts(): void {
		$source = new FakePassphraseSource( array( self::GOOD_PASSPHRASE, 'a-different-passphrase' ) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'did not match' );

		Encryption::collect_for_export( $source, false );
	}

	/**
	 * Matching prompts that are too short are refused on the length check.
	 *
	 * @return void
	 */
	public function test_collect_for_export_rejects_a_short_prompted_passphrase(): void {
		$source = new FakePassphraseSource( array( self::SHORT_PASSPHRASE, self::SHORT_PASSPHRASE ) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'at least' );

		Encryption::collect_for_export( $source, false );
	}

	// -------------------------------------------------------------------------
	// collect_for_import(): a single read, no minimum enforced.
	// -------------------------------------------------------------------------

	/**
	 * Import reads one STDIN line and returns it unchanged.
	 *
	 * @return void
	 */
	public function test_collect_for_import_via_stdin_returns_the_line(): void {
		$source = new FakePassphraseSource( array(), self::GOOD_PASSPHRASE );

		$this->assertSame( self::GOOD_PASSPHRASE, Encryption::collect_for_import( $source, true ) );
	}

	/**
	 * Import prompts exactly once and returns that single entry.
	 *
	 * @return void
	 */
	public function test_collect_for_import_via_prompt_returns_the_single_entry(): void {
		$source = new FakePassphraseSource( array( self::GOOD_PASSPHRASE ) );

		$this->assertSame( self::GOOD_PASSPHRASE, Encryption::collect_for_import( $source, false ) );
	}

	// -------------------------------------------------------------------------
	// context(): a fresh random salt and a correctly sized derived key.
	// -------------------------------------------------------------------------

	/**
	 * The context carries a salt and key of the sizes the format requires.
	 *
	 * @return void
	 */
	public function test_context_derives_a_key_and_salt_of_the_right_sizes(): void {
		$context = Encryption::context( self::GOOD_PASSPHRASE );

		$this->assertSame( Argon2idKdf::SALT_SIZE, strlen( $context->salt() ) );
		$this->assertSame( Cipher::KEY_SIZE, strlen( $context->key() ) );
	}

	/**
	 * Each call draws a fresh random salt, so two contexts never share one.
	 *
	 * @return void
	 */
	public function test_context_uses_a_fresh_salt_each_call(): void {
		$this->assertNotSame(
			Encryption::context( self::GOOD_PASSPHRASE )->salt(),
			Encryption::context( self::GOOD_PASSPHRASE )->salt()
		);
	}

	// -------------------------------------------------------------------------
	// entry_reader(): plain when unencrypted, keyed from the stored salt otherwise.
	// -------------------------------------------------------------------------

	/**
	 * An unencrypted archive reads back without a passphrase.
	 *
	 * @return void
	 */
	public function test_entry_reader_for_an_unencrypted_archive_ignores_the_passphrase(): void {
		$bytes  = self::single_file_archive_bytes( 'plain payload', null );
		$source = self::memory_stream_with( $bytes );
		$reader = new ArchiveReader( $source );

		$entry_reader = Encryption::entry_reader( $reader, CodecRegistry::with_defaults(), null );
		$entry        = $reader->manifest()->entries()[0];

		// A plain file entry's payload arrives as a stream (ADR 0010).
		$this->assertSame( 'plain payload', stream_get_contents( $entry_reader->read_entry( $source, $entry )->payload_stream() ) );
	}

	/**
	 * An encrypted archive with no passphrase is refused before any read.
	 *
	 * @return void
	 */
	public function test_entry_reader_requires_a_passphrase_for_an_encrypted_archive(): void {
		$bytes  = self::single_file_archive_bytes( 'secret payload', self::GOOD_PASSPHRASE );
		$reader = new ArchiveReader( self::memory_stream_with( $bytes ) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'passphrase is required' );

		Encryption::entry_reader( $reader, CodecRegistry::with_defaults(), null );
	}

	/**
	 * The correct passphrase re-derives the key from the stored salt and decrypts.
	 *
	 * @return void
	 */
	public function test_entry_reader_decrypts_with_the_correct_passphrase(): void {
		$bytes  = self::single_file_archive_bytes( 'secret payload', self::GOOD_PASSPHRASE );
		$source = self::memory_stream_with( $bytes );
		$reader = new ArchiveReader( $source );

		$entry_reader = Encryption::entry_reader( $reader, CodecRegistry::with_defaults(), self::GOOD_PASSPHRASE );
		$entry        = $reader->manifest()->entries()[0];

		$this->assertSame( 'secret payload', $entry_reader->read_entry( $source, $entry )->payload() );
	}

	/**
	 * A wrong passphrase derives a wrong key, so the read fails closed.
	 *
	 * @return void
	 */
	public function test_entry_reader_with_a_wrong_passphrase_fails_closed(): void {
		$bytes  = self::single_file_archive_bytes( 'secret payload', self::GOOD_PASSPHRASE );
		$source = self::memory_stream_with( $bytes );
		$reader = new ArchiveReader( $source );

		$entry_reader = Encryption::entry_reader( $reader, CodecRegistry::with_defaults(), 'the-wrong-passphrase' );
		$entry        = $reader->manifest()->entries()[0];

		$this->expectException( RuntimeException::class );

		$entry_reader->read_entry( $source, $entry );
	}

	// -------------------------------------------------------------------------
	// Archive helpers.
	// -------------------------------------------------------------------------

	/**
	 * Write a single-file archive — plain or encrypted — and return its bytes.
	 *
	 * @param string      $payload    The file payload to pack.
	 * @param string|null $passphrase A passphrase to encrypt under, or null for a plain archive.
	 * @return string The complete archive bytes.
	 */
	private static function single_file_archive_bytes( string $payload, ?string $passphrase ): string {
		$plan = new EntryPlan(
			EntryHeader::for_file( 'note.txt', strlen( $payload ), 0644, 0, 'application/octet-stream', 0 ),
			RawCodec::ID,
			str_repeat( "\x00", EntryWriter::NONCE_SIZE ),
			self::memory_stream_with( $payload )
		);

		$context = null !== $passphrase ? Encryption::context( $passphrase ) : null;
		$dest    = self::memory_stream();

		$writer = new ArchiveWriter( new EntryWriter( CodecRegistry::with_defaults() ), new FooterWriter() );
		$writer->write_archive( self::sample_provenance(), array( $plan ), $dest, null, $context );

		return self::read_all( $dest );
	}

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
}
