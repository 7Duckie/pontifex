<?php
/**
 * Unit tests for the ArchiveWriter skeleton.
 *
 * @package Pontifex\Tests\Unit\Archive\Writer
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Archive\Writer;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pontifex\Archive\Format\ArchiveManifest;
use Pontifex\Archive\Format\ExporterInfo;
use Pontifex\Archive\Format\Footer;
use Pontifex\Archive\Format\Header;
use Pontifex\Archive\Format\Provenance;
use Pontifex\Archive\Integrity\Sha256;
use Pontifex\Archive\Writer\ArchiveWriter;
use Pontifex\Archive\Writer\FooterWriter;

/**
 * Tests for {@see ArchiveWriter}.
 *
 * The skeleton produces a valid archive containing only header,
 * provenance, an empty manifest, and a footer. Each test writes an
 * archive to a memory stream and either parses individual blocks via
 * their from_bytes() methods (the strongest correctness check) or
 * verifies offset/length invariants against the raw bytes.
 */
final class ArchiveWriterTest extends TestCase {

	/**
	 * Build a Provenance with realistic but arbitrary field values for tests.
	 *
	 * @return Provenance A valid Provenance value object suitable for writing.
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
	 * Build an ArchiveWriter wired up with a real FooterWriter.
	 *
	 * @return ArchiveWriter A fresh ArchiveWriter ready to write archives.
	 */
	private static function make_writer(): ArchiveWriter {
		return new ArchiveWriter( new FooterWriter() );
	}

	/**
	 * Open a php://memory stream for tests.
	 *
	 * @return resource A readable and writable php://memory stream.
	 * @throws \RuntimeException If php://memory cannot be opened.
	 */
	private static function memory_stream() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://memory is an in-process buffer, not a file; WP_Filesystem cannot open it.
		$stream = fopen( 'php://memory', 'r+b' );
		if ( false === $stream ) {
			throw new \RuntimeException( 'Could not open php://memory for test.' );
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
	 * Write a sample archive and return its raw bytes for inspection.
	 *
	 * @return string The complete archive bytes.
	 */
	private static function write_sample_archive(): string {
		$dest = self::memory_stream();
		self::make_writer()->write_archive( self::sample_provenance(), $dest );
		return self::read_all( $dest );
	}

	/**
	 * The write_archive method must return the total byte count written to the destination.
	 *
	 * @return void
	 */
	public function test_write_archive_returns_total_bytes_written(): void {
		$dest          = self::memory_stream();
		$bytes_written = self::make_writer()->write_archive( self::sample_provenance(), $dest );

		$this->assertSame( strlen( self::read_all( $dest ) ), $bytes_written );
	}

	/**
	 * The archive must begin with the 16-byte Pontifex magic header.
	 *
	 * @return void
	 */
	public function test_archive_begins_with_header(): void {
		$bytes  = self::write_sample_archive();
		$header = Header::from_bytes( substr( $bytes, 0, Header::SIZE ) );

		$this->assertSame( Header::FORMAT_MAJOR_V1, $header->major() );
		$this->assertSame( Header::FORMAT_MINOR_V1_0, $header->minor() );
		$this->assertSame( 0, $header->flags() );
	}

	/**
	 * The archive must end with a parseable 64-byte footer.
	 *
	 * @return void
	 */
	public function test_archive_ends_with_footer(): void {
		$bytes  = self::write_sample_archive();
		$footer = Footer::from_bytes( substr( $bytes, -Footer::SIZE ) );

		$this->assertSame( Footer::SIZE, strlen( substr( $bytes, -Footer::SIZE ) ) );
		$this->assertGreaterThanOrEqual( Header::SIZE, $footer->manifest_offset() );
	}

	/**
	 * The footer salt slot must hold Footer::ZERO_SALT in v0.1.0 archives.
	 *
	 * @return void
	 */
	public function test_archive_footer_salt_is_zero_in_v010(): void {
		$bytes  = self::write_sample_archive();
		$footer = Footer::from_bytes( substr( $bytes, -Footer::SIZE ) );

		$this->assertSame( Footer::ZERO_SALT, $footer->argon2id_salt() );
	}

	/**
	 * The footer's manifest_offset must point at the actual position of the manifest block.
	 *
	 * @return void
	 */
	public function test_footer_manifest_offset_matches_manifest_position(): void {
		$bytes  = self::write_sample_archive();
		$footer = Footer::from_bytes( substr( $bytes, -Footer::SIZE ) );

		// Manifest starts right after Header + Provenance.
		// Its offset must equal Header::SIZE plus the Provenance block length.
		$provenance_bytes = self::sample_provenance()->to_bytes();
		$expected_offset  = Header::SIZE + strlen( $provenance_bytes );

		$this->assertSame( $expected_offset, $footer->manifest_offset() );
	}

	/**
	 * The footer's manifest_length must match the actual byte length of the written manifest block.
	 *
	 * @return void
	 */
	public function test_footer_manifest_length_matches_manifest_block(): void {
		$bytes  = self::write_sample_archive();
		$footer = Footer::from_bytes( substr( $bytes, -Footer::SIZE ) );

		$manifest_block = substr( $bytes, $footer->manifest_offset(), $footer->manifest_length() );

		$this->assertSame( $footer->manifest_length(), strlen( $manifest_block ) );
		// The block must be a well-formed empty manifest (zero entries).
		$manifest = ArchiveManifest::from_bytes( $manifest_block );
		$this->assertSame( 0, $manifest->entry_count() );
	}

	/**
	 * The footer's manifest_hash must equal the SHA-256 hash inside the manifest block.
	 *
	 * Per spec, the Footer reuses the manifest's internal hash digest as
	 * a navigation/integrity convenience.
	 *
	 * @return void
	 */
	public function test_footer_manifest_hash_matches_internal_manifest_hash(): void {
		$bytes  = self::write_sample_archive();
		$footer = Footer::from_bytes( substr( $bytes, -Footer::SIZE ) );

		$manifest_block      = substr( $bytes, $footer->manifest_offset(), $footer->manifest_length() );
		$internal_hash_bytes = substr( $manifest_block, ArchiveManifest::LENGTH_PREFIX_SIZE, Sha256::DIGEST_SIZE );

		$this->assertSame( $internal_hash_bytes, $footer->manifest_hash() );
	}

	/**
	 * The provenance block must round-trip through Provenance::from_bytes intact.
	 *
	 * @return void
	 */
	public function test_provenance_block_round_trips_via_from_bytes(): void {
		$bytes      = self::write_sample_archive();
		$footer     = Footer::from_bytes( substr( $bytes, -Footer::SIZE ) );
		$provenance = self::sample_provenance();

		$provenance_bytes  = substr( $bytes, Header::SIZE, strlen( $provenance->to_bytes() ) );
		$parsed_provenance = Provenance::from_bytes( $provenance_bytes );

		$this->assertSame( $provenance->wp_version(), $parsed_provenance->wp_version() );
		$this->assertSame( $provenance->php_version(), $parsed_provenance->php_version() );
		$this->assertSame( $provenance->url(), $parsed_provenance->url() );
	}

	/**
	 * The empty manifest must contain zero entries when parsed back.
	 *
	 * @return void
	 */
	public function test_empty_manifest_contains_no_entries(): void {
		$bytes  = self::write_sample_archive();
		$footer = Footer::from_bytes( substr( $bytes, -Footer::SIZE ) );

		$manifest_block = substr( $bytes, $footer->manifest_offset(), $footer->manifest_length() );
		$manifest       = ArchiveManifest::from_bytes( $manifest_block );

		$this->assertSame( 0, $manifest->entry_count() );
		$this->assertSame( array(), $manifest->entries() );
	}

	/**
	 * The write_archive method must support being called multiple times on the same instance.
	 *
	 * The writer is stateless, so successive calls must each produce a
	 * complete, independently valid archive.
	 *
	 * @return void
	 */
	public function test_write_archive_is_stateless_across_multiple_calls(): void {
		$writer = self::make_writer();

		$dest1 = self::memory_stream();
		$dest2 = self::memory_stream();

		$bytes1 = $writer->write_archive( self::sample_provenance(), $dest1 );
		$bytes2 = $writer->write_archive( self::sample_provenance(), $dest2 );

		$this->assertSame( $bytes1, $bytes2 );
		$this->assertSame( self::read_all( $dest1 ), self::read_all( $dest2 ) );
	}

	/**
	 * The write_archive method must reject a destination that is not a stream resource.
	 *
	 * @return void
	 */
	public function test_write_archive_rejects_non_resource_destination(): void {
		$writer = self::make_writer();

		$this->expectException( InvalidArgumentException::class );

		$writer->write_archive( self::sample_provenance(), 'not a resource' );
	}

	/**
	 * The total archive bytes must equal the sum of all four block sizes.
	 *
	 * Tightest sanity check that nothing extra is written and nothing is skipped.
	 *
	 * @return void
	 */
	public function test_total_archive_bytes_equals_sum_of_block_sizes(): void {
		$bytes  = self::write_sample_archive();
		$footer = Footer::from_bytes( substr( $bytes, -Footer::SIZE ) );

		$expected_total = Header::SIZE
			+ strlen( self::sample_provenance()->to_bytes() )
			+ $footer->manifest_length()
			+ Footer::SIZE;

		$this->assertSame( $expected_total, strlen( $bytes ) );
	}
}
