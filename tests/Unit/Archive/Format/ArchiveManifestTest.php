<?php
/**
 * Behavioural tests for the ArchiveManifest value object.
 *
 * @package Pontifex\Tests\Unit\Archive\Format
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Archive\Format;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pontifex\Archive\Codec\CodecRegistry;
use Pontifex\Archive\Codec\RawCodec;
use Pontifex\Archive\Format\ArchiveManifest;
use Pontifex\Archive\Format\ByteOrder;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Format\ExporterInfo;
use Pontifex\Archive\Format\ManifestEntry;
use Pontifex\Archive\Format\Provenance;
use Pontifex\Archive\Integrity\Sha256;
use Pontifex\Archive\Reader\ArchiveReader;
use Pontifex\Archive\Writer\ArchiveWriter;
use Pontifex\Archive\Writer\EntryPlan;
use Pontifex\Archive\Writer\EntryWriter;
use Pontifex\Archive\Writer\FooterWriter;

/**
 * Behavioural tests for the ArchiveManifest class.
 *
 * Verifies the on-disk format invariants:
 *
 *  - Constants for length prefix, header size, and payload ceiling.
 *  - Constructor accepts empty and multi-entry lists; rejects
 *    non-ManifestEntry elements.
 *  - to_bytes produces canonical layout: 4-byte length + 32-byte
 *    SHA-256 + JSON payload with entries in given order.
 *  - from_bytes rejects under-size input, oversized declared
 *    length, mismatched total length, bad hash, malformed JSON,
 *    missing fields, and non-array entries.
 *  - Round-trip preserves entry count, entry order, and every
 *    field on each entry.
 */
final class ArchiveManifestTest extends TestCase {

	/**
	 * Test hash 1: incrementing bytes 0x01 to 0x20.
	 *
	 * @var string
	 */
	private const HASH_ONE = "\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x20";

	/**
	 * Test hash 2: incrementing bytes 0x21 to 0x40.
	 *
	 * @var string
	 */
	private const HASH_TWO = "\x21\x22\x23\x24\x25\x26\x27\x28\x29\x2A\x2B\x2C\x2D\x2E\x2F\x30\x31\x32\x33\x34\x35\x36\x37\x38\x39\x3A\x3B\x3C\x3D\x3E\x3F\x40";

	/**
	 * Build a manifest with two distinct entries used as a fixture.
	 *
	 * @return ArchiveManifest A manifest with two entries.
	 */
	private function two_entry_manifest(): ArchiveManifest {
		return new ArchiveManifest(
			array(
				ManifestEntry::for_file(
					0,
					16,
					650,
					'wp-config.php',
					1,
					self::HASH_ONE
				),
				ManifestEntry::for_db_chunk(
					1,
					600,
					3700,
					0,
					1,
					self::HASH_TWO
				),
			)
		);
	}

	/**
	 * The length prefix constant must be 4 bytes (uint32).
	 *
	 * @return void
	 */
	public function test_length_prefix_size_is_four(): void {
		$this->assertSame( 4, ArchiveManifest::LENGTH_PREFIX_SIZE );
	}

	/**
	 * HEADER_SIZE must equal 4 + 32 = 36 bytes.
	 *
	 * @return void
	 */
	public function test_header_size_is_thirty_six(): void {
		$this->assertSame( 36, ArchiveManifest::HEADER_SIZE );
		$this->assertSame(
			ArchiveManifest::LENGTH_PREFIX_SIZE + Sha256::DIGEST_SIZE,
			ArchiveManifest::HEADER_SIZE
		);
	}

	/**
	 * The maximum payload size must be 16 MiB (16777216 bytes).
	 *
	 * @return void
	 */
	public function test_max_payload_size_is_sixteen_mib(): void {
		$this->assertSame( 16777216, ArchiveManifest::MAX_PAYLOAD_SIZE );
		$this->assertSame( 16 * 1024 * 1024, ArchiveManifest::MAX_PAYLOAD_SIZE );
	}

	/**
	 * The min-entry-payload-bytes constant must be 169.
	 *
	 * @return void
	 */
	public function test_min_entry_payload_bytes_is_one_hundred_sixty_nine(): void {
		$this->assertSame( 169, ArchiveManifest::MIN_ENTRY_PAYLOAD_BYTES );
	}

	/**
	 * Pin the format's true structural floor: a hand-forged, maximally
	 * degenerate entry (the shortest kind, "file", a 1-character path,
	 * every numeric field at 0) encoded as the sole entry in a manifest.
	 *
	 * This is the literal "minimal one-entry manifest" MIN_ENTRY_PAYLOAD_BYTES's
	 * docblock refers to. Its size is pinned exactly, so a future format
	 * change that shrinks it further is caught here rather than silently
	 * eroding the margin the reader guard and the export-side projection
	 * both rely on.
	 *
	 * @return void
	 */
	public function test_minimal_one_entry_manifest_size_is_pinned(): void {
		$minimal = ManifestEntry::for_file( 0, 0, 0, 'a', 0, str_repeat( "\x00", Sha256::DIGEST_SIZE ) );

		$bytes = ( new ArchiveManifest( array( $minimal ) ) )->to_bytes();

		$this->assertSame( 195, strlen( $bytes ), 'ArchiveManifest::to_bytes() for one maximally-degenerate entry must be exactly 195 bytes.' );
	}

	/**
	 * The true asymptotic marginal cost of an additional maximally-degenerate
	 * entry (146 bytes) must stay comfortably below MIN_ENTRY_PAYLOAD_BYTES
	 * (169), proving the constant is a deliberate margin above the format's
	 * real floor rather than an accidental over-claim. See
	 * MIN_ENTRY_PAYLOAD_BYTES's docblock for why the guard is built this way.
	 *
	 * @return void
	 */
	public function test_true_minimal_marginal_entry_cost_stays_below_the_conservative_constant(): void {
		$minimal = ManifestEntry::for_file( 0, 0, 0, 'a', 0, str_repeat( "\x00", Sha256::DIGEST_SIZE ) );

		$one_entry_bytes = strlen( ( new ArchiveManifest( array( $minimal ) ) )->to_bytes() );
		$two_entry_bytes = strlen( ( new ArchiveManifest( array( $minimal, $minimal ) ) )->to_bytes() );
		$marginal_cost   = $two_entry_bytes - $one_entry_bytes;

		$this->assertSame( 146, $marginal_cost, 'The true marginal cost of a maximally-degenerate entry must be exactly 146 bytes.' );
		$this->assertLessThan( ArchiveManifest::MIN_ENTRY_PAYLOAD_BYTES, $marginal_cost, 'MIN_ENTRY_PAYLOAD_BYTES must sit above the format\'s real floor, never at or below it.' );
	}

	/**
	 * The constructor must accept an empty entries list.
	 *
	 * @return void
	 */
	public function test_constructor_accepts_empty_list(): void {
		$manifest = new ArchiveManifest( array() );

		$this->assertSame( 0, $manifest->entry_count() );
		$this->assertSame( array(), $manifest->entries() );
	}

	/**
	 * The constructor must accept a multi-entry list.
	 *
	 * @return void
	 */
	public function test_constructor_accepts_multiple_entries(): void {
		$manifest = $this->two_entry_manifest();

		$this->assertSame( 2, $manifest->entry_count() );
		$this->assertCount( 2, $manifest->entries() );
	}

	/**
	 * The constructor must reject a list containing non-ManifestEntry values.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_non_manifest_entry(): void {
		$this->expectException( InvalidArgumentException::class );

		new ArchiveManifest( array( 'not a ManifestEntry' ) );
	}

	/**
	 * The constructor must reindex the entries list to a 0-based sequence.
	 *
	 * @return void
	 */
	public function test_constructor_reindexes_entries(): void {
		$entry_a = ManifestEntry::for_file( 0, 16, 50, 'a.txt', 0, self::HASH_ONE );
		$entry_b = ManifestEntry::for_file( 1, 32, 50, 'b.txt', 0, self::HASH_TWO );

		$manifest = new ArchiveManifest(
			array(
				5 => $entry_a,
				7 => $entry_b,
			)
		);

		$this->assertSame( array( 0, 1 ), array_keys( $manifest->entries() ) );
	}

	/**
	 * Serialisation must produce HEADER_SIZE + payload bytes for an empty manifest.
	 *
	 * @return void
	 */
	public function test_to_bytes_empty_manifest_size(): void {
		$bytes = ( new ArchiveManifest( array() ) )->to_bytes();

		$this->assertGreaterThanOrEqual( ArchiveManifest::HEADER_SIZE, strlen( $bytes ) );
	}

	/**
	 * Serialisation must produce a length prefix that matches the payload length.
	 *
	 * @return void
	 */
	public function test_to_bytes_length_prefix_matches_payload(): void {
		$bytes           = $this->two_entry_manifest()->to_bytes();
		$declared_length = ByteOrder::unpack_uint32( substr( $bytes, 0, 4 ) );
		$payload         = substr( $bytes, ArchiveManifest::HEADER_SIZE );

		$this->assertSame( strlen( $payload ), $declared_length );
	}

	/**
	 * The stored hash must match the SHA-256 of the payload.
	 *
	 * @return void
	 */
	public function test_to_bytes_hash_matches_payload(): void {
		$bytes         = $this->two_entry_manifest()->to_bytes();
		$stored_hash   = substr( $bytes, ArchiveManifest::LENGTH_PREFIX_SIZE, Sha256::DIGEST_SIZE );
		$payload       = substr( $bytes, ArchiveManifest::HEADER_SIZE );
		$computed_hash = Sha256::of( $payload );

		$this->assertSame( $computed_hash, $stored_hash );
	}

	/**
	 * The empty manifest must serialise to the canonical empty-entries JSON.
	 *
	 * @return void
	 */
	public function test_to_bytes_empty_manifest_canonical_json(): void {
		$bytes   = ( new ArchiveManifest( array() ) )->to_bytes();
		$payload = substr( $bytes, ArchiveManifest::HEADER_SIZE );

		$this->assertSame( '{"entries":[]}', $payload );
	}

	/**
	 * Parsing must reject input shorter than HEADER_SIZE.
	 *
	 * @return void
	 */
	public function test_from_bytes_rejects_under_size_input(): void {
		$this->expectException( InvalidArgumentException::class );

		ArchiveManifest::from_bytes( str_repeat( "\x00", ArchiveManifest::HEADER_SIZE - 1 ) );
	}

	/**
	 * Parsing must reject a declared payload size above MAX_PAYLOAD_SIZE.
	 *
	 * @return void
	 */
	public function test_from_bytes_rejects_oversize_declared_length(): void {
		$length = ByteOrder::pack_uint32( ArchiveManifest::MAX_PAYLOAD_SIZE + 1 );
		$hash   = str_repeat( "\x00", Sha256::DIGEST_SIZE );
		$bytes  = $length . $hash;

		$this->expectException( InvalidArgumentException::class );

		ArchiveManifest::from_bytes( $bytes );
	}

	/**
	 * Parsing must reject input whose total length disagrees with the declared length.
	 *
	 * @return void
	 */
	public function test_from_bytes_rejects_length_mismatch(): void {
		$valid_bytes = $this->two_entry_manifest()->to_bytes();
		$truncated   = substr( $valid_bytes, 0, strlen( $valid_bytes ) - 1 );

		$this->expectException( InvalidArgumentException::class );

		ArchiveManifest::from_bytes( $truncated );
	}

	/**
	 * Parsing must reject bytes whose stored hash does not match the payload.
	 *
	 * @return void
	 */
	public function test_from_bytes_rejects_bad_hash(): void {
		$valid_bytes = $this->two_entry_manifest()->to_bytes();
		$tampered    = $valid_bytes;

		// Flip one bit in the stored hash so it no longer matches.
		$tampered[ ArchiveManifest::LENGTH_PREFIX_SIZE ] = chr( ord( $tampered[ ArchiveManifest::LENGTH_PREFIX_SIZE ] ) ^ 0x01 );

		$this->expectException( InvalidArgumentException::class );

		ArchiveManifest::from_bytes( $tampered );
	}

	/**
	 * Parsing must reject a malformed JSON payload.
	 *
	 * @return void
	 */
	public function test_from_bytes_rejects_malformed_json(): void {
		$garbage = 'not valid json';
		$length  = ByteOrder::pack_uint32( strlen( $garbage ) );
		$hash    = Sha256::of( $garbage );
		$bytes   = $length . $hash . $garbage;

		$this->expectException( InvalidArgumentException::class );

		ArchiveManifest::from_bytes( $bytes );
	}

	/**
	 * Parsing must reject a JSON payload missing the entries field.
	 *
	 * @return void
	 */
	public function test_from_bytes_rejects_missing_entries_field(): void {
		$payload = '{"other":[]}';
		$length  = ByteOrder::pack_uint32( strlen( $payload ) );
		$hash    = Sha256::of( $payload );
		$bytes   = $length . $hash . $payload;

		$this->expectException( InvalidArgumentException::class );

		ArchiveManifest::from_bytes( $bytes );
	}

	/**
	 * Parsing must reject a JSON payload whose entries field is not an array.
	 *
	 * @return void
	 */
	public function test_from_bytes_rejects_non_array_entries(): void {
		$payload = '{"entries":"not an array"}';
		$length  = ByteOrder::pack_uint32( strlen( $payload ) );
		$hash    = Sha256::of( $payload );
		$bytes   = $length . $hash . $payload;

		$this->expectException( InvalidArgumentException::class );

		ArchiveManifest::from_bytes( $bytes );
	}

	/**
	 * Round-trip with an empty manifest must produce an empty manifest.
	 *
	 * @return void
	 */
	public function test_round_trip_empty_manifest(): void {
		$original = new ArchiveManifest( array() );
		$parsed   = ArchiveManifest::from_bytes( $original->to_bytes() );

		$this->assertSame( 0, $parsed->entry_count() );
	}

	/**
	 * Round-trip with multiple entries must preserve every entry and its order.
	 *
	 * @return void
	 */
	public function test_round_trip_multi_entry_preserves_order(): void {
		$original = $this->two_entry_manifest();
		$parsed   = ArchiveManifest::from_bytes( $original->to_bytes() );

		$this->assertSame( 2, $parsed->entry_count() );

		$original_entries = $original->entries();
		$parsed_entries   = $parsed->entries();

		// First entry: the file.
		$this->assertSame( $original_entries[0]->index(), $parsed_entries[0]->index() );
		$this->assertSame( $original_entries[0]->offset(), $parsed_entries[0]->offset() );
		$this->assertSame( $original_entries[0]->length(), $parsed_entries[0]->length() );
		$this->assertSame( $original_entries[0]->kind(), $parsed_entries[0]->kind() );
		$this->assertSame( $original_entries[0]->codec_id(), $parsed_entries[0]->codec_id() );
		$this->assertSame( $original_entries[0]->entry_hash(), $parsed_entries[0]->entry_hash() );
		$this->assertSame( $original_entries[0]->path(), $parsed_entries[0]->path() );

		// Second entry: the db chunk.
		$this->assertSame( $original_entries[1]->offset(), $parsed_entries[1]->offset() );
		$this->assertSame( 'db_chunk', $parsed_entries[1]->kind() );
		$this->assertSame( 0, $parsed_entries[1]->chunk_index() );
	}

	/**
	 * The project_payload_bytes() method over no entries must project the fixed framing only.
	 *
	 * @return void
	 */
	public function test_project_payload_bytes_empty_is_framing_only(): void {
		$this->assertSame( 50, ArchiveManifest::project_payload_bytes( array() ) );
	}

	/**
	 * The project_payload_bytes() method must charge (MIN_ENTRY_PAYLOAD_BYTES - 1)
	 * plus the real path length for every path-bearing entry.
	 *
	 * @return void
	 */
	public function test_project_payload_bytes_charges_overhead_plus_real_path_length(): void {
		$headers = array(
			EntryHeader::for_file( 'wp-content/uploads/photo.jpg', 100, 0644, 1690000000, 'image/jpeg', 0 ),
			EntryHeader::for_directory( 'wp-content/uploads', 0755, 0 ),
		);

		$expected = 50
			+ ( ArchiveManifest::MIN_ENTRY_PAYLOAD_BYTES - 1 + strlen( 'wp-content/uploads/photo.jpg' ) )
			+ ( ArchiveManifest::MIN_ENTRY_PAYLOAD_BYTES - 1 + strlen( 'wp-content/uploads' ) );

		$this->assertSame( $expected, ArchiveManifest::project_payload_bytes( $headers ) );
	}

	/**
	 * The project_payload_bytes() method must charge a flat MIN_ENTRY_PAYLOAD_BYTES
	 * for a db_chunk entry, which carries no path.
	 *
	 * @return void
	 */
	public function test_project_payload_bytes_charges_flat_estimate_for_db_chunk(): void {
		$headers = array( EntryHeader::for_db_chunk( 0, 'wp_posts', 10, 5000, 0 ) );

		$this->assertSame( 50 + ArchiveManifest::MIN_ENTRY_PAYLOAD_BYTES, ArchiveManifest::project_payload_bytes( $headers ) );
	}

	/**
	 * The project_payload_bytes() method must never under-estimate real
	 * ArchiveWriter output — it is the whole point of the projection (§Part 3
	 * of the entry-count-ceiling fix): a projection that could fall short
	 * would let a doomed-to-be-unreadable export slip past the pre-write
	 * refusal. Compares the projection against a real archive built by
	 * ArchiveWriter over EntryHeaders with the same identifiers.
	 *
	 * @return void
	 */
	public function test_project_payload_bytes_never_under_estimates_real_writer_output(): void {
		$plans = array();
		for ( $i = 0; $i < 200; $i++ ) {
			$plans[] = self::real_file_plan( sprintf( 'wp-content/uploads/2026/07/file-%04d.jpg', $i ), 'x' );
		}

		$headers   = array_map( static fn( $plan ) => $plan->header(), $plans );
		$projected = ArchiveManifest::project_payload_bytes( $headers );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://temp is an in-process buffer, not a file; WP_Filesystem cannot open it.
		$destination = fopen( 'php://temp', 'r+b' );
		$writer      = new ArchiveWriter( new EntryWriter( CodecRegistry::with_defaults() ), new FooterWriter() );
		$writer->write_archive(
			new Provenance(
				'6.6.1',
				'8.2.10',
				'https://example.test',
				'utf8mb4',
				'utf8mb4_unicode_520_ci',
				new ExporterInfo( 'pontifex', '0.1.0' ),
				new DateTimeImmutable( '2026-05-23T10:00:00+00:00' )
			),
			$plans,
			$destination
		);
		rewind( $destination );
		$reader = new ArchiveReader( $destination );
		$actual = $reader->footer()->manifest_length();

		$this->assertGreaterThanOrEqual( $actual, $projected, 'The projection must be at or above what the real writer produced.' );
	}

	/**
	 * Build a real file EntryPlan for the writer-comparison test.
	 *
	 * @param string $path     Relative archive path.
	 * @param string $contents Raw file contents.
	 * @return EntryPlan A raw-codec file plan.
	 */
	private static function real_file_plan( string $path, string $contents ): EntryPlan {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://temp is an in-process buffer, not a file; WP_Filesystem cannot open it.
		$src = fopen( 'php://temp', 'r+b' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Operating on a test stream resource, not a filesystem path.
		fwrite( $src, $contents );
		rewind( $src );

		return new EntryPlan(
			EntryHeader::for_file( $path, strlen( $contents ), 0644, 1690000000, 'application/octet-stream', 0 ),
			RawCodec::ID,
			str_repeat( "\0", EntryWriter::NONCE_SIZE ),
			$src
		);
	}
}
