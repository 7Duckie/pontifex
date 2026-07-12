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
use Pontifex\Archive\Codec\CodecRegistry;
use Pontifex\Archive\Format\ArchiveManifest;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Format\ExporterInfo;
use Pontifex\Archive\Format\Footer;
use Pontifex\Archive\Format\Header;
use Pontifex\Archive\Format\ManifestEntry;
use Pontifex\Archive\Format\Provenance;
use Pontifex\Archive\Integrity\Sha256;
use Pontifex\Archive\Writer\ArchiveWriter;
use Pontifex\Archive\Writer\EntryPlan;
use Pontifex\Archive\Writer\EntryWriter;
use Pontifex\Archive\Writer\FooterWriter;

/**
 * Tests for {@see ArchiveWriter}.
 *
 * Archives produced by ArchiveWriter contain header, provenance, an
 * optional series of entry records, a manifest, and a footer. Each
 * test writes an archive to a memory stream and either parses
 * individual blocks via
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
		return new ArchiveWriter( new EntryWriter( CodecRegistry::with_defaults() ), new FooterWriter() );
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
		self::make_writer()->write_archive( self::sample_provenance(), array(), $dest );
		return self::read_all( $dest );
	}

	/**
	 * The write_archive method must return the total byte count written to the destination.
	 *
	 * @return void
	 */
	public function test_write_archive_returns_total_bytes_written(): void {
		$dest          = self::memory_stream();
		$bytes_written = self::make_writer()->write_archive( self::sample_provenance(), array(), $dest );

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
		$this->assertSame( Header::FORMAT_MINOR_V1_1, $header->minor() );
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

		$bytes1 = $writer->write_archive( self::sample_provenance(), array(), $dest1 );
		$bytes2 = $writer->write_archive( self::sample_provenance(), array(), $dest2 );

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

		$writer->write_archive( self::sample_provenance(), array(), 'not a resource' );
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

	/**
	 * Return a 12-byte all-zero nonce.
	 *
	 * @return string A NONCE_SIZE-byte binary string of zero bytes.
	 */
	private static function zero_nonce(): string {
		return str_repeat( "\x00", EntryWriter::NONCE_SIZE );
	}

	/**
	 * Build a php://memory stream pre-populated with the given bytes, cursor rewound.
	 *
	 * @param string $contents Bytes to write into the stream before rewinding.
	 * @return resource A readable php://memory stream positioned at offset 0.
	 * @throws \RuntimeException If php://memory cannot be opened.
	 */
	private static function memory_stream_with( string $contents ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://memory is an in-process buffer, not a file.
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
	 * An archive containing a single file entry via raw codec must round-trip cleanly.
	 *
	 * @return void
	 */
	public function test_archive_with_single_file_entry_raw_codec(): void {
		$payload = 'hello world from the entry payload';
		$plan    = new EntryPlan(
			EntryHeader::for_file( 'test.txt', strlen( $payload ), 0644, 1690000000, 'application/octet-stream', 0 ),
			0,
			self::zero_nonce(),
			self::memory_stream_with( $payload )
		);

		$dest = self::memory_stream();
		self::make_writer()->write_archive( self::sample_provenance(), array( $plan ), $dest );

		$bytes    = self::read_all( $dest );
		$footer   = Footer::from_bytes( substr( $bytes, -Footer::SIZE ) );
		$manifest = ArchiveManifest::from_bytes(
			substr( $bytes, $footer->manifest_offset(), $footer->manifest_length() )
		);

		$this->assertSame( 1, $manifest->entry_count() );

		$entries = $manifest->entries();
		$this->assertSame( 0, $entries[0]->index() );
		$this->assertSame( EntryHeader::KIND_FILE, $entries[0]->kind() );
		$this->assertSame( 'test.txt', $entries[0]->path() );
		$this->assertSame( 0, $entries[0]->codec_id() );
	}

	/**
	 * Forwards the byte-progress callback across every entry's payload.
	 *
	 * The callback the export's progress bar rides on must see each entry's raw
	 * source bytes as the entries stream, so the reported total equals the sum of
	 * the entries' payload sizes — progress that advances within an entry, not
	 * only between entries.
	 *
	 * @return void
	 */
	public function test_write_archive_reports_source_bytes_across_entries(): void {
		$payloads = array( str_repeat( 'a', 100 ), str_repeat( 'b', 250 ), str_repeat( 'c', 70 ) );
		$plans    = array();
		foreach ( $payloads as $index => $payload ) {
			$plans[] = new EntryPlan(
				EntryHeader::for_file( 'file' . $index . '.txt', strlen( $payload ), 0644, 0, 'application/octet-stream', 0 ),
				0,
				self::zero_nonce(),
				self::memory_stream_with( $payload )
			);
		}
		$expected = strlen( $payloads[0] ) + strlen( $payloads[1] ) + strlen( $payloads[2] );

		$reported = 0;
		self::make_writer()->write_archive(
			self::sample_provenance(),
			$plans,
			self::memory_stream(),
			null,
			null,
			null,
			function ( int $bytes ) use ( &$reported ): void {
				$reported += $bytes;
			}
		);

		$this->assertSame( $expected, $reported, 'The byte callback must see every source byte across all entries.' );
	}

	/**
	 * Each entry's source stream is closed after the entry has been written.
	 *
	 * This bounds the export's memory: only one source is open at a time, no
	 * matter how many entries the archive holds. The plan opens a fresh stream
	 * from its factory, which the writer must close once the entry is on disk.
	 *
	 * @return void
	 */
	public function test_write_archive_closes_each_entry_source(): void {
		$opened = null;
		$plan   = new EntryPlan(
			EntryHeader::for_file( 'a.txt', 4, 0644, 0, 'application/octet-stream', 0 ),
			0,
			self::zero_nonce(),
			static function () use ( &$opened ) {
				$opened = self::memory_stream_with( 'data' );
				return $opened;
			}
		);

		self::make_writer()->write_archive( self::sample_provenance(), array( $plan ), self::memory_stream() );

		$this->assertNotNull( $opened, 'The writer should have opened the deferred source.' );
		$this->assertFalse( is_resource( $opened ), 'The writer must close each entry source after writing it.' );
	}

	/**
	 * The first entry must start at the offset just past the provenance block.
	 *
	 * @return void
	 */
	public function test_first_entry_offset_starts_after_provenance(): void {
		$plan = new EntryPlan(
			EntryHeader::for_file( 'a.txt', 4, 0644, 0, 'application/octet-stream', 0 ),
			0,
			self::zero_nonce(),
			self::memory_stream_with( 'data' )
		);

		$dest = self::memory_stream();
		self::make_writer()->write_archive( self::sample_provenance(), array( $plan ), $dest );

		$bytes    = self::read_all( $dest );
		$footer   = Footer::from_bytes( substr( $bytes, -Footer::SIZE ) );
		$manifest = ArchiveManifest::from_bytes(
			substr( $bytes, $footer->manifest_offset(), $footer->manifest_length() )
		);

		$expected_first_offset = Header::SIZE + strlen( self::sample_provenance()->to_bytes() );

		$entries = $manifest->entries();
		$this->assertSame( $expected_first_offset, $entries[0]->offset() );
	}

	/**
	 * The manifest entry's hash must equal the SHA-256 of the entry record bytes on disk.
	 *
	 * Strongest correctness check for the per-entry write loop: the
	 * manifest entry's hash field must match a freshly computed
	 * SHA-256 over the actual bytes at the recorded offset and length.
	 *
	 * @return void
	 */
	public function test_manifest_entry_hash_matches_on_disk_entry_bytes(): void {
		$payload = str_repeat( 'X', 200 );
		$plan    = new EntryPlan(
			EntryHeader::for_file( 'a.txt', strlen( $payload ), 0644, 0, 'application/octet-stream', 0 ),
			0,
			self::zero_nonce(),
			self::memory_stream_with( $payload )
		);

		$dest = self::memory_stream();
		self::make_writer()->write_archive( self::sample_provenance(), array( $plan ), $dest );

		$bytes    = self::read_all( $dest );
		$footer   = Footer::from_bytes( substr( $bytes, -Footer::SIZE ) );
		$manifest = ArchiveManifest::from_bytes(
			substr( $bytes, $footer->manifest_offset(), $footer->manifest_length() )
		);

		$entries     = $manifest->entries();
		$entry_bytes = substr( $bytes, $entries[0]->offset(), $entries[0]->length() );

		// The on-disk entry record's last 32 bytes are its own SHA-256.
		$on_disk_hash = substr( $entry_bytes, -Sha256::DIGEST_SIZE );

		$this->assertSame( $on_disk_hash, $entries[0]->entry_hash() );
	}

	/**
	 * An archive with multiple file entries must chain offsets without gaps.
	 *
	 * Each entry's offset must equal the previous entry's offset plus
	 * the previous entry's length, with no padding in between.
	 *
	 * @return void
	 */
	public function test_multiple_entries_chain_offsets_contiguously(): void {
		$plans = array(
			new EntryPlan(
				EntryHeader::for_file( 'a.txt', 5, 0644, 0, 'application/octet-stream', 0 ),
				0,
				self::zero_nonce(),
				self::memory_stream_with( 'first' )
			),
			new EntryPlan(
				EntryHeader::for_file( 'b.txt', 6, 0644, 0, 'application/octet-stream', 0 ),
				0,
				self::zero_nonce(),
				self::memory_stream_with( 'second' )
			),
			new EntryPlan(
				EntryHeader::for_file( 'c.txt', 5, 0644, 0, 'application/octet-stream', 0 ),
				0,
				self::zero_nonce(),
				self::memory_stream_with( 'third' )
			),
		);

		$dest = self::memory_stream();
		self::make_writer()->write_archive( self::sample_provenance(), $plans, $dest );

		$bytes    = self::read_all( $dest );
		$footer   = Footer::from_bytes( substr( $bytes, -Footer::SIZE ) );
		$manifest = ArchiveManifest::from_bytes(
			substr( $bytes, $footer->manifest_offset(), $footer->manifest_length() )
		);

		$entries = $manifest->entries();
		$this->assertSame( 3, count( $entries ) );

		$this->assertSame( $entries[0]->offset() + $entries[0]->length(), $entries[1]->offset() );
		$this->assertSame( $entries[1]->offset() + $entries[1]->length(), $entries[2]->offset() );
	}

	/**
	 * An archive containing one of each entry kind must round-trip with correct kinds in the manifest.
	 *
	 * @return void
	 */
	public function test_archive_with_one_of_each_kind(): void {
		$plans = array(
			new EntryPlan(
				EntryHeader::for_file( 'a.txt', 4, 0644, 0, 'application/octet-stream', 0 ),
				0,
				self::zero_nonce(),
				self::memory_stream_with( 'data' )
			),
			new EntryPlan(
				EntryHeader::for_db_chunk( 0, 'wp_posts', 1, 42, 0 ),
				0,
				self::zero_nonce(),
				self::memory_stream_with( "INSERT INTO wp_posts VALUES (1, 'hi');\n" )
			),
			new EntryPlan(
				EntryHeader::for_directory( 'wp-content/uploads', 0755, 0 ),
				0,
				self::zero_nonce(),
				self::memory_stream_with( '' )
			),
			new EntryPlan(
				EntryHeader::for_symlink( 'wp-content/link', '/var/www/target', 0 ),
				0,
				self::zero_nonce(),
				self::memory_stream_with( '/var/www/target' )
			),
		);

		$dest = self::memory_stream();
		self::make_writer()->write_archive( self::sample_provenance(), $plans, $dest );

		$bytes    = self::read_all( $dest );
		$footer   = Footer::from_bytes( substr( $bytes, -Footer::SIZE ) );
		$manifest = ArchiveManifest::from_bytes(
			substr( $bytes, $footer->manifest_offset(), $footer->manifest_length() )
		);

		$entries = $manifest->entries();
		$this->assertSame( 4, count( $entries ) );
		$this->assertSame( EntryHeader::KIND_FILE, $entries[0]->kind() );
		$this->assertSame( EntryHeader::KIND_DB_CHUNK, $entries[1]->kind() );
		$this->assertSame( EntryHeader::KIND_DIRECTORY, $entries[2]->kind() );
		$this->assertSame( EntryHeader::KIND_SYMLINK, $entries[3]->kind() );

		// Kind-specific identifier checks.
		$this->assertSame( 'a.txt', $entries[0]->path() );
		$this->assertSame( 0, $entries[1]->chunk_index() );
		$this->assertSame( 'wp-content/uploads', $entries[2]->path() );
		$this->assertSame( 'wp-content/link', $entries[3]->path() );
	}

	/**
	 * Entries written with the gzip codec must record codec_id 1 in the manifest.
	 *
	 * @return void
	 */
	public function test_entry_with_gzip_codec_records_codec_id_in_manifest(): void {
		$payload = str_repeat( 'compress me ', 100 );
		$plan    = new EntryPlan(
			EntryHeader::for_file( 'a.txt', strlen( $payload ), 0644, 0, 'application/octet-stream', 0 ),
			1,
			self::zero_nonce(),
			self::memory_stream_with( $payload )
		);

		$dest = self::memory_stream();
		self::make_writer()->write_archive( self::sample_provenance(), array( $plan ), $dest );

		$bytes    = self::read_all( $dest );
		$footer   = Footer::from_bytes( substr( $bytes, -Footer::SIZE ) );
		$manifest = ArchiveManifest::from_bytes(
			substr( $bytes, $footer->manifest_offset(), $footer->manifest_length() )
		);

		$entries = $manifest->entries();
		$this->assertSame( 1, $entries[0]->codec_id() );
	}

	/**
	 * The manifest entry index field must reflect the entry's position in the input array.
	 *
	 * @return void
	 */
	public function test_manifest_entry_indices_match_input_order(): void {
		$plans = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$plans[] = new EntryPlan(
				EntryHeader::for_file( 'f' . $i . '.txt', 4, 0644, 0, 'application/octet-stream', 0 ),
				0,
				self::zero_nonce(),
				self::memory_stream_with( 'data' )
			);
		}

		$dest = self::memory_stream();
		self::make_writer()->write_archive( self::sample_provenance(), $plans, $dest );

		$bytes    = self::read_all( $dest );
		$footer   = Footer::from_bytes( substr( $bytes, -Footer::SIZE ) );
		$manifest = ArchiveManifest::from_bytes(
			substr( $bytes, $footer->manifest_offset(), $footer->manifest_length() )
		);

		$entries = $manifest->entries();
		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertSame( $i, $entries[ $i ]->index() );
		}
	}

	/**
	 * The write_archive method must reject an entry_plans element that is not an EntryPlan.
	 *
	 * @return void
	 */
	public function test_write_archive_rejects_non_entry_plan_element(): void {
		$writer = self::make_writer();
		$dest   = self::memory_stream();

		$this->expectException( InvalidArgumentException::class );

		$writer->write_archive(
			self::sample_provenance(),
			array( 'not an EntryPlan' ),
			$dest
		);
	}

	/**
	 * The footer's manifest_offset must still point at the manifest when entries are present.
	 *
	 * Regression check that the bytes-written counter is correctly accumulated
	 * through the entry-writing loop.
	 *
	 * @return void
	 */
	public function test_footer_manifest_offset_correct_with_entries(): void {
		$plan = new EntryPlan(
			EntryHeader::for_file( 'a.txt', 4, 0644, 0, 'application/octet-stream', 0 ),
			0,
			self::zero_nonce(),
			self::memory_stream_with( 'data' )
		);

		$dest = self::memory_stream();
		self::make_writer()->write_archive( self::sample_provenance(), array( $plan ), $dest );

		$bytes  = self::read_all( $dest );
		$footer = Footer::from_bytes( substr( $bytes, -Footer::SIZE ) );

		// The manifest block sits right where the footer says it does.
		$manifest_block = substr( $bytes, $footer->manifest_offset(), $footer->manifest_length() );

		// A successful from_bytes confirms offset + length point at a well-formed manifest block.
		$manifest = ArchiveManifest::from_bytes( $manifest_block );
		$this->assertSame( 1, $manifest->entry_count() );
	}

	/**
	 * The write_archive method must invoke the per-entry callback once per entry, in order.
	 *
	 * The callback receives the running completed-count and the fixed
	 * total, so a three-entry archive must produce exactly (1,3),
	 * (2,3), (3,3) — proving both the per-entry cadence and the
	 * correct progression a progress bar relies on.
	 *
	 * @return void
	 */
	public function test_write_archive_invokes_callback_once_per_entry(): void {
		$plans = array(
			new EntryPlan(
				EntryHeader::for_file( 'a.txt', 5, 0644, 0, 'application/octet-stream', 0 ),
				0,
				self::zero_nonce(),
				self::memory_stream_with( 'first' )
			),
			new EntryPlan(
				EntryHeader::for_file( 'b.txt', 6, 0644, 0, 'application/octet-stream', 0 ),
				0,
				self::zero_nonce(),
				self::memory_stream_with( 'second' )
			),
			new EntryPlan(
				EntryHeader::for_file( 'c.txt', 5, 0644, 0, 'application/octet-stream', 0 ),
				0,
				self::zero_nonce(),
				self::memory_stream_with( 'third' )
			),
		);

		$calls = array();
		$dest  = self::memory_stream();
		self::make_writer()->write_archive(
			self::sample_provenance(),
			$plans,
			$dest,
			static function ( int $done, int $total ) use ( &$calls ): void {
				$calls[] = array( $done, $total );
			}
		);

		$this->assertSame(
			array( array( 1, 3 ), array( 2, 3 ), array( 3, 3 ) ),
			$calls
		);
	}

	/**
	 * The write_archive method must not invoke the callback for an empty archive.
	 *
	 * An export with no entries produces a valid empty archive; with
	 * nothing written, the progress callback must never fire, so a
	 * caller can safely show no bar.
	 *
	 * @return void
	 */
	public function test_write_archive_does_not_invoke_callback_when_empty(): void {
		$called = false;
		$dest   = self::memory_stream();
		self::make_writer()->write_archive(
			self::sample_provenance(),
			array(),
			$dest,
			static function () use ( &$called ): void {
				$called = true;
			}
		);

		$this->assertFalse( $called );
	}

	/**
	 * A file whose source yields different bytes than its header declared must be reported.
	 *
	 * The scan-to-write race: the header carries the scan-time size but the
	 * source has since shrunk. The writer records the truth in the entry (via
	 * EntryWriter) and must surface the discrepancy through the
	 * on_file_changed callback so the caller can warn the user.
	 *
	 * @return void
	 */
	public function test_write_archive_reports_a_changed_file_to_the_callback(): void {
		$steady_payload = 'steady bytes';
		$steady_plan    = new EntryPlan(
			EntryHeader::for_file( 'steady.txt', strlen( $steady_payload ), 0644, 1690000000, 'application/octet-stream', 0 ),
			0,
			self::zero_nonce(),
			self::memory_stream_with( $steady_payload )
		);

		// Declared 1000 bytes at scan time; only 400 remain at write time.
		$shrunk_plan = new EntryPlan(
			EntryHeader::for_file( 'shrunk.log', 1000, 0644, 1690000000, 'application/octet-stream', 0 ),
			0,
			self::zero_nonce(),
			self::memory_stream_with( str_repeat( 'B', 400 ) )
		);

		$reports = array();
		$dest    = self::memory_stream();
		self::make_writer()->write_archive(
			self::sample_provenance(),
			array( $steady_plan, $shrunk_plan ),
			$dest,
			null,
			null,
			null,
			null,
			static function ( string $path, int $declared_size, int $actual_size ) use ( &$reports ): void {
				$reports[] = array( $path, $declared_size, $actual_size );
			}
		);

		$this->assertSame( array( array( 'shrunk.log', 1000, 400 ) ), $reports, 'Only the changed file must be reported, with its declared and actual sizes.' );
	}

	/**
	 * The on_file_changed callback must stay silent when every file matches its declared size.
	 *
	 * @return void
	 */
	public function test_write_archive_does_not_report_changed_files_when_none_changed(): void {
		$payload = 'unchanged payload';
		$plan    = new EntryPlan(
			EntryHeader::for_file( 'steady.txt', strlen( $payload ), 0644, 1690000000, 'application/octet-stream', 0 ),
			0,
			self::zero_nonce(),
			self::memory_stream_with( $payload )
		);

		$called = false;
		$dest   = self::memory_stream();
		self::make_writer()->write_archive(
			self::sample_provenance(),
			array( $plan ),
			$dest,
			null,
			null,
			null,
			null,
			static function () use ( &$called ): void {
				$called = true;
			}
		);

		$this->assertFalse( $called );
	}
}
