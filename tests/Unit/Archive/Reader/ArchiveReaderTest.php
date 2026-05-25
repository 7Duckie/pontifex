<?php
/**
 * Unit tests for the ArchiveReader class.
 *
 * @package Pontifex\Tests\Unit\Archive\Reader
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Archive\Reader;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Pontifex\Archive\Codec\CodecRegistry;
use Pontifex\Archive\Format\ExporterInfo;
use Pontifex\Archive\Format\Footer;
use Pontifex\Archive\Format\Header;
use Pontifex\Archive\Format\Provenance;
use Pontifex\Archive\Reader\ArchiveReader;
use Pontifex\Archive\Writer\ArchiveWriter;
use Pontifex\Archive\Writer\EntryWriter;
use Pontifex\Archive\Writer\FooterWriter;

/**
 * Tests for {@see ArchiveReader}.
 *
 * Exercises the skeleton reader: validation of the input stream,
 * eager parsing of Header and Footer, and the four public
 * accessors. Round-trip tests build a real archive with
 * ArchiveWriter and verify ArchiveReader recovers the matching
 * Header and Footer values.
 */
final class ArchiveReaderTest extends TestCase {

	/**
	 * Build a sample Provenance for archive construction in tests.
	 *
	 * @return Provenance A valid Provenance.
	 */
	private static function sample_provenance(): Provenance {
		return new Provenance(
			'6.6.1',
			'8.2.10',
			'https://example.test',
			'utf8mb4',
			'utf8mb4_unicode_520_ci',
			new ExporterInfo( 'pontifex', '0.1.0' ),
			new DateTimeImmutable( '2026-05-23T10:00:00+00:00', new DateTimeZone( 'UTC' ) )
		);
	}

	/**
	 * Build an ArchiveWriter wired with real default codec registry.
	 *
	 * @return ArchiveWriter A fresh writer.
	 */
	private static function make_writer(): ArchiveWriter {
		return new ArchiveWriter( new EntryWriter( CodecRegistry::with_defaults() ), new FooterWriter() );
	}

	/**
	 * Open a php://memory stream for tests.
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
	 * Build a complete sample archive in memory and return a stream positioned at offset 0.
	 *
	 * The returned stream is the archive bytes, ready to feed to ArchiveReader.
	 *
	 * @return resource A readable, seekable stream containing a valid archive.
	 */
	private static function build_sample_archive_stream() {
		$dest = self::memory_stream();
		self::make_writer()->write_archive( self::sample_provenance(), array(), $dest );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource, not a filesystem path.
		rewind( $dest );
		return $dest;
	}

	/**
	 * Wrap arbitrary bytes in a seekable in-memory stream.
	 *
	 * @param string $bytes The bytes to wrap.
	 * @return resource A readable, seekable stream containing $bytes.
	 */
	private static function bytes_to_stream( string $bytes ) {
		$stream = self::memory_stream();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Operating on a test stream resource, not a filesystem path.
		fwrite( $stream, $bytes );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource, not a filesystem path.
		rewind( $stream );
		return $stream;
	}

	/**
	 * Read all bytes from a stream (rewind first), so we can build derived test fixtures.
	 *
	 * @param resource $stream The stream to read.
	 * @return string The full stream contents.
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
	 * The constructor must accept a valid archive stream.
	 *
	 * @return void
	 */
	public function test_construct_accepts_valid_archive(): void {
		$stream = self::build_sample_archive_stream();
		$reader = new ArchiveReader( $stream );

		$this->assertInstanceOf( ArchiveReader::class, $reader );
	}

	/**
	 * The constructor must reject a non-resource argument.
	 *
	 * @return void
	 */
	public function test_construct_rejects_non_resource(): void {
		$this->expectException( InvalidArgumentException::class );

		// @phpstan-ignore-next-line — intentionally passing wrong type to verify validation.
		new ArchiveReader( 'not a resource' );
	}

	/**
	 * The constructor must reject a stream that is not seekable.
	 *
	 * @return void
	 */
	public function test_construct_rejects_unseekable_stream(): void {
		// php://output is a write-only, non-seekable stream.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://output is an in-process pseudo-stream, not a file.
		$stream = fopen( 'php://output', 'w' );

		$this->expectException( InvalidArgumentException::class );

		try {
			new ArchiveReader( $stream );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Cleanup of test stream resource.
			fclose( $stream );
		}
	}

	/**
	 * The constructor must reject a stream too short to contain a header + footer.
	 *
	 * @return void
	 */
	public function test_construct_rejects_truncated_stream(): void {
		$stream = self::bytes_to_stream( "\x00" );

		$this->expectException( RuntimeException::class );

		new ArchiveReader( $stream );
	}

	/**
	 * The constructor must reject a stream whose header bytes do not parse.
	 *
	 * @return void
	 */
	public function test_construct_rejects_corrupt_header(): void {
		// Start with a real archive, then overwrite the first 16 bytes with junk.
		$bytes   = self::read_all( self::build_sample_archive_stream() );
		$corrupt = str_repeat( "\x00", Header::SIZE ) . substr( $bytes, Header::SIZE );
		$stream  = self::bytes_to_stream( $corrupt );

		$this->expectException( RuntimeException::class );

		new ArchiveReader( $stream );
	}

	/**
	 * The header() accessor must return a Header instance reflecting the on-disk bytes.
	 *
	 * @return void
	 */
	public function test_header_accessor_returns_parsed_header(): void {
		$reader = new ArchiveReader( self::build_sample_archive_stream() );
		$header = $reader->header();

		$this->assertInstanceOf( Header::class, $header );
		$this->assertSame( Header::FORMAT_MAJOR_V1, $header->major() );
		$this->assertSame( Header::FORMAT_MINOR_V1_0, $header->minor() );
	}

	/**
	 * The footer() accessor must return a Footer instance reflecting the on-disk bytes.
	 *
	 * @return void
	 */
	public function test_footer_accessor_returns_parsed_footer(): void {
		$reader = new ArchiveReader( self::build_sample_archive_stream() );
		$footer = $reader->footer();

		$this->assertInstanceOf( Footer::class, $footer );
		// Manifest offset must be non-negative and the manifest must fit inside the archive.
		$this->assertGreaterThanOrEqual( Header::SIZE, $footer->manifest_offset() );
		$this->assertGreaterThanOrEqual( 0, $footer->manifest_length() );
	}

	/**
	 * The manifest_offset() convenience accessor must match the footer's recorded offset.
	 *
	 * @return void
	 */
	public function test_manifest_offset_matches_footer(): void {
		$reader = new ArchiveReader( self::build_sample_archive_stream() );

		$this->assertSame( $reader->footer()->manifest_offset(), $reader->manifest_offset() );
	}

	/**
	 * The manifest_length() convenience accessor must match the footer's recorded length.
	 *
	 * @return void
	 */
	public function test_manifest_length_matches_footer(): void {
		$reader = new ArchiveReader( self::build_sample_archive_stream() );

		$this->assertSame( $reader->footer()->manifest_length(), $reader->manifest_length() );
	}

	/**
	 * The constructor must succeed even when the source stream is positioned mid-file at call time.
	 *
	 * Defends against a regression where the reader assumed the caller had positioned
	 * the stream at offset 0.
	 *
	 * @return void
	 */
	public function test_construct_works_when_stream_not_at_offset_zero(): void {
		$stream = self::build_sample_archive_stream();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek -- Positioning a test stream resource, not a filesystem path.
		fseek( $stream, 5 );

		$reader = new ArchiveReader( $stream );

		$this->assertInstanceOf( Header::class, $reader->header() );
	}
}
