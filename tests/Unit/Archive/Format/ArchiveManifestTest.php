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
	 * plus the real path length for every path-bearing entry, plus that entry's
	 * real kind width and the shared unseen-numeric margin.
	 *
	 * The kind term matters: MIN_ENTRY_PAYLOAD_BYTES assumes the shortest kind
	 * string (`file`), so a `directory` entry — five bytes longer — would be
	 * under-charged without it.
	 *
	 * @return void
	 */
	public function test_project_payload_bytes_charges_overhead_plus_real_path_length(): void {
		$headers = array(
			EntryHeader::for_file( 'wp-content/uploads/photo.jpg', 100, 0644, 1690000000, 'image/jpeg', 0 ),
			EntryHeader::for_directory( 'wp-content/uploads', 0755, 0 ),
		);

		$margin = self::EXPECTED_UNSEEN_MARGIN;

		$expected = 50
			+ ( ArchiveManifest::MIN_ENTRY_PAYLOAD_BYTES - 1 + strlen( 'wp-content/uploads/photo.jpg' ) + 0 + $margin )
			+ ( ArchiveManifest::MIN_ENTRY_PAYLOAD_BYTES - 1 + strlen( 'wp-content/uploads' ) + 5 + $margin );

		$this->assertSame( $expected, ArchiveManifest::project_payload_bytes( $headers ) );
	}

	/**
	 * The project_payload_bytes() method must charge a db_chunk entry
	 * MIN_ENTRY_PAYLOAD_BYTES plus its chunk_index's own real digit width
	 * (known from the header) plus the shared margin for the separator and the
	 * numeric fields the header cannot carry at all — no longer a flat
	 * MIN_ENTRY_PAYLOAD_BYTES regardless of chunk_index, now that db_chunk digit
	 * growth is measured exactly where it can be and margined honestly where it
	 * cannot (see {@see ArchiveManifest::project_payload_bytes()}).
	 *
	 * @return void
	 */
	public function test_project_payload_bytes_charges_measured_estimate_for_db_chunk(): void {
		$margin = self::EXPECTED_UNSEEN_MARGIN;

		// chunk_index 0: a single digit, the same width MIN_ENTRY_PAYLOAD_BYTES
		// already assumes, so only the shared unseen-field margin applies on top.
		$single_digit_headers = array( EntryHeader::for_db_chunk( 0, 'wp_posts', 10, 5000, 0 ) );
		$this->assertSame(
			50 + ArchiveManifest::MIN_ENTRY_PAYLOAD_BYTES + $margin,
			ArchiveManifest::project_payload_bytes( $single_digit_headers )
		);

		// chunk_index 12345: five digits, so the projection must additionally
		// charge the four digits beyond the single-digit baseline.
		$five_digit_headers = array( EntryHeader::for_db_chunk( 12345, 'wp_posts', 10, 5000, 0 ) );
		$this->assertSame(
			50 + ArchiveManifest::MIN_ENTRY_PAYLOAD_BYTES + 4 + $margin,
			ArchiveManifest::project_payload_bytes( $five_digit_headers )
		);
	}

	/**
	 * The shared per-entry margin project_payload_bytes() adds to every entry,
	 * pinned as a literal derived independently of the class's own constants.
	 *
	 * Deliberately NOT rebuilt by reflecting ArchiveManifest's constants and
	 * repeating its arithmetic: that expresses the implementation twice rather
	 * than pinning it, so both sides move together and any change to a constant
	 * passes unnoticed. (Measured, not assumed — an earlier revision of this
	 * class did exactly that, and zeroing ENTRY_SEPARATOR_BYTES left the whole
	 * suite green.) The derivation, one term per field the projection cannot
	 * see on an EntryHeader:
	 *
	 *   entry separator (the `,` between entries)                     =  1
	 *   index    — bounded at MAX_INDEX_DIGITS (5), less the 1 digit
	 *              MIN_ENTRY_PAYLOAD_BYTES already assumes             =  4
	 *   codec_id — bounded at MAX_CODEC_ID_DIGITS (5), same less-one    =  4
	 *   offset   — MAX_INT_DIGITS (19), same less-one                   = 18
	 *   length   — MAX_INT_DIGITS (19), same less-one                   = 18
	 *                                                                   ----
	 *                                                                     45
	 *
	 * A deliberate change to any of those constants must update this literal;
	 * that failure is the point.
	 *
	 * @var int
	 */
	private const EXPECTED_UNSEEN_MARGIN = 45;

	/**
	 * MAX_CODEC_ID_DIGITS must stay pinned to the real digit width of
	 * ManifestEntry::MAX_CODEC_ID.
	 *
	 * The projection bounds codec_id at this width instead of MAX_INT_DIGITS,
	 * which is only sound while the two agree; if that ceiling is ever raised,
	 * this fails rather than letting the projection quietly under-charge.
	 *
	 * @return void
	 */
	public function test_max_codec_id_digits_matches_the_real_codec_ceiling(): void {
		$this->assertSame(
			strlen( (string) ManifestEntry::MAX_CODEC_ID ),
			(int) ( new \ReflectionClassConstant( ArchiveManifest::class, 'MAX_CODEC_ID_DIGITS' ) )->getValue(),
			'MAX_CODEC_ID_DIGITS must equal the digit width of ManifestEntry::MAX_CODEC_ID.'
		);
	}

	/**
	 * MAX_INDEX_DIGITS must genuinely bound the largest index any export the
	 * guard approves can reach.
	 *
	 * The bound is derived, not assumed: project_payload_bytes() charges every
	 * entry at least MIN_ENTRY_PAYLOAD_BYTES, so an approved projection (one at
	 * or below MAX_PAYLOAD_SIZE) cannot span more entries than the framing
	 * budget divided by that floor. This pins the derivation so a change to
	 * either constant cannot silently invalidate it.
	 *
	 * @return void
	 */
	public function test_max_index_digits_bounds_every_approvable_entry_count(): void {
		$framing_budget = ArchiveManifest::MAX_PAYLOAD_SIZE
			- ArchiveManifest::HEADER_SIZE
			- (int) ( new \ReflectionClassConstant( ArchiveManifest::class, 'ENTRIES_JSON_WRAPPER_BYTES' ) )->getValue();

		$max_entries = intdiv( $framing_budget, ArchiveManifest::MIN_ENTRY_PAYLOAD_BYTES );
		$max_index   = $max_entries - 1;

		$this->assertSame(
			strlen( (string) $max_index ),
			(int) ( new \ReflectionClassConstant( ArchiveManifest::class, 'MAX_INDEX_DIGITS' ) )->getValue(),
			sprintf(
				'MAX_INDEX_DIGITS must cover the largest approvable index (%d entries, largest index %d).',
				$max_entries,
				$max_index
			)
		);
	}

	/**
	 * The project_payload_bytes() method must never under-estimate real
	 * ArchiveWriter output — it is the whole point of the projection (§Part 3
	 * of the entry-count-ceiling fix): a projection that could fall short
	 * would let a doomed-to-be-unreadable export slip past the pre-write
	 * refusal.
	 *
	 * Two phases, because one technique cannot honestly cover every shape:
	 *
	 *  - Phase 1 compares the projection against a REAL archive built by
	 *    ArchiveWriter over a deliberately varied plan list — not just the
	 *    200 flat-path files this test used to check alone (the most
	 *    favourable shape possible: no escaping, no digit growth, small
	 *    offsets), but a directory, a symlink, a db_chunk, a path needing
	 *    JSON escaping, and a non-ASCII path too, so a regression in any one
	 *    of the fixed causes (JSON escaping, db_chunk digit growth) would
	 *    actually be caught here.
	 *  - Phase 2 proves the db_chunk term at realistically large offsets —
	 *    2e9 and 9e11, the exact magnitudes a large real backup reaches —
	 *    plus the theoretical worst case a PHP int can ever hold
	 *    (PHP_INT_MAX for index/offset/length, together with chunk_index and
	 *    codec_id both at their own real maximums). Physically writing
	 *    terabytes through ArchiveWriter to reach those offsets for real is
	 *    not practical in a unit test, so this phase constructs a real
	 *    ManifestEntry with the chosen field values directly and calls
	 *    to_bytes() — the exact same encoder
	 *    {@see \Pontifex\Archive\Writer\IncrementalArchiveWriter::finish()}
	 *    calls to serialise the real manifest, so the byte count is exactly
	 *    what the real writer would have produced for those field values,
	 *    not an approximation of it.
	 *
	 * @return void
	 */
	public function test_project_payload_bytes_never_under_estimates_real_writer_output(): void {
		$plans = array();
		for ( $i = 0; $i < 200; $i++ ) {
			$plans[] = self::real_file_plan( sprintf( 'wp-content/uploads/2026/07/file-%04d.jpg', $i ), 'x' );
		}
		$plans[] = self::real_directory_plan( 'wp-content/uploads/2026/07' );
		$plans[] = self::real_symlink_plan( 'wp-content/link', '/var/www/target/with/a/reasonably/long/path' );
		$plans[] = self::real_db_chunk_plan( 0, 'wp_postmeta', 10, "INSERT INTO wp_postmeta VALUES (1, 1, 'a', 'b');\n" );
		$plans[] = self::real_file_plan( 'wp-content/uploads/say "hello".jpg', 'x' );
		$plans[] = self::real_file_plan( "wp-content/uploads/\u{65E5}\u{672C}\u{8A9E}\u{540D}\u{524D}.jpg", 'x' );

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

		$this->assertGreaterThanOrEqual( $actual, $projected, 'The projection must be at or above what the real writer produced for a realistically varied archive.' );

		// Phase 2: db_chunk at real-world-large and theoretical-worst-case numeric widths.
		$hash = str_repeat( "\x5a", Sha256::DIGEST_SIZE );
		foreach ( $this->large_db_chunk_offset_cases() as $case_label => $case ) {
			[$index, $offset, $length, $chunk_index, $codec_id] = $case;

			$header = EntryHeader::for_db_chunk( $chunk_index, 'wp_postmeta', 4000, 6000000, 0 );
			$entry  = ManifestEntry::for_db_chunk( $index, $offset, $length, $chunk_index, $codec_id, $hash );

			$case_projected = ArchiveManifest::project_payload_bytes( array( $header ) );
			$case_actual    = strlen( ( new ArchiveManifest( array( $entry ) ) )->to_bytes() );

			$this->assertGreaterThanOrEqual(
				$case_actual,
				$case_projected,
				sprintf( 'The projection must be at or above the real manifest bytes for the "%s" case.', $case_label )
			);
		}

		// Phase 3: the SAME proof for the path-bearing kinds. Phase 1 exercises
		// these only through a real writer, whose offsets sit in the low
		// thousands, so nothing there could catch digit growth in index, offset,
		// length or codec_id — none of which an EntryHeader carries either. That
		// gap is what let a reachable under-count survive the db_chunk fix: at a
		// multi-terabyte archive the margin above `directory`'s floor (the
		// narrowest of the three, its kind string being the longest) is exhausted
		// and the projection falls under the real bytes.
		foreach ( $this->large_path_entry_cases() as $case_label => $case ) {
			[$kind, $path, $index, $offset, $length, $codec_id] = $case;

			$header = self::path_header_for_kind( $kind, $path );
			$entry  = self::path_entry_for_kind( $kind, $index, $offset, $length, $path, $codec_id, $hash );

			$case_projected = ArchiveManifest::project_payload_bytes( array( $header ) );
			$case_actual    = strlen( ( new ArchiveManifest( array( $entry ) ) )->to_bytes() );

			$this->assertGreaterThanOrEqual(
				$case_actual,
				$case_projected,
				sprintf( 'The projection must be at or above the real manifest bytes for the "%s" case.', $case_label )
			);
		}
	}

	/**
	 * The projection must stay at or above the real bytes across a WHOLE
	 * multi-entry manifest, not just entry by entry.
	 *
	 * Every other proof in this class compares a ONE-entry manifest, where the
	 * framing cancels exactly and the `,` between entries never appears. This is
	 * the only assertion that exercises the projection the way a real export
	 * does — against a manifest long enough for the separators to be a real cost.
	 *
	 * Scope, stated honestly: this does NOT isolate ENTRY_SEPARATOR_BYTES.
	 * Zeroing that constant leaves this green, because the numeric margin
	 * over-covers a single byte many times over. The separator charge is pinned
	 * by EXPECTED_UNSEEN_MARGIN instead; what this catches is any aggregate
	 * error large enough to outrun the margin — the failure mode a per-entry
	 * assertion structurally cannot see.
	 *
	 * @return void
	 */
	public function test_project_payload_bytes_covers_the_separators_between_entries(): void {
		$hash    = str_repeat( "\x5a", Sha256::DIGEST_SIZE );
		$path    = 'wp-content/uploads/2026/07/photo.jpg';
		$headers = array();
		$entries = array();

		// Offsets and lengths deliberately wide enough that the numeric margin is
		// doing real work, so this fails if the separator charge is removed.
		for ( $i = 0; $i < 500; $i++ ) {
			$headers[] = EntryHeader::for_file( $path, 1048576, 0644, 1690000000, 'image/jpeg', 0 );
			$entries[] = ManifestEntry::for_file( $i, $i * 1048576, 1048576, $path, 1, $hash );
		}

		$this->assertGreaterThanOrEqual(
			strlen( ( new ArchiveManifest( $entries ) )->to_bytes() ),
			ArchiveManifest::project_payload_bytes( $headers ),
			'The projection must cover the separators a multi-entry manifest carries between its entries.'
		);
	}

	/**
	 * Numeric field combinations for the path-bearing kinds, mirroring the
	 * db_chunk cases: realistic large archives up to the theoretical worst case
	 * a PHP int can ever reach.
	 *
	 * `directory` carries the narrowest margin of the three and so appears at
	 * the sizes where it breaks first; every kind is covered at PHP_INT_MAX.
	 *
	 * @return array<string, array{0: string, 1: string, 2: int, 3: int, 4: int, 5: int}> Each entry is [kind, path, index, offset, length, codec_id].
	 */
	private function large_path_entry_cases(): array {
		$path = 'wp-content/uploads/2026/07/some-photo-name-1024x768.jpg';

		return array(
			'file at a 2e9 offset (a real multi-gigabyte archive)' => array( 'file', $path, 50000, 2000000000, 6000000, 1 ),
			'file at a 9e11 offset (a real multi-hundred-gigabyte archive)' => array( 'file', $path, 90000, 900000000000, 104857600, 1 ),
			'file at a 7e12 offset (a real multi-terabyte archive)' => array( 'file', $path, 75000, 7000000000000, 104857600, 1 ),
			'directory at a 7e12 offset (the narrowest margin of the three)' => array( 'directory', $path, 75000, 7000000000000, 137, 0 ),
			'symlink at a 7e12 offset' => array( 'symlink', $path, 75000, 7000000000000, 166, 0 ),
			'file with everything at PHP_INT_MAX and codec_id maxed' => array( 'file', $path, PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX, ManifestEntry::MAX_CODEC_ID ),
			'directory with everything at PHP_INT_MAX and codec_id maxed' => array( 'directory', $path, PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX, ManifestEntry::MAX_CODEC_ID ),
			'symlink with everything at PHP_INT_MAX and codec_id maxed' => array( 'symlink', $path, PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX, ManifestEntry::MAX_CODEC_ID ),
			'file whose path needs JSON escaping, at a large offset' => array( 'file', 'wp-content/uploads/say "hello"\\here.jpg', 75000, 7000000000000, 104857600, 1 ),
		);
	}

	/**
	 * Build an EntryHeader of the given path-bearing kind.
	 *
	 * @param string $kind One of file, directory, symlink.
	 * @param string $path Relative archive path.
	 * @return EntryHeader The header for that kind.
	 */
	private static function path_header_for_kind( string $kind, string $path ): EntryHeader {
		return match ( $kind ) {
			'directory' => EntryHeader::for_directory( $path, 0755, 0 ),
			'symlink'   => EntryHeader::for_symlink( $path, '/var/www/target/path', 0 ),
			default     => EntryHeader::for_file( $path, 1024, 0644, 1690000000, 'image/jpeg', 0 ),
		};
	}

	/**
	 * Build a ManifestEntry of the given path-bearing kind.
	 *
	 * @param string $kind       One of file, directory, symlink.
	 * @param int    $index      Entry index.
	 * @param int    $offset     Byte offset.
	 * @param int    $length     Record length.
	 * @param string $path       Relative archive path.
	 * @param int    $codec_id   Codec id.
	 * @param string $entry_hash Raw SHA-256 bytes.
	 * @return ManifestEntry The entry for that kind.
	 */
	private static function path_entry_for_kind( string $kind, int $index, int $offset, int $length, string $path, int $codec_id, string $entry_hash ): ManifestEntry {
		return match ( $kind ) {
			'directory' => ManifestEntry::for_directory( $index, $offset, $length, $path, $codec_id, $entry_hash ),
			'symlink'   => ManifestEntry::for_symlink( $index, $offset, $length, $path, $codec_id, $entry_hash ),
			default     => ManifestEntry::for_file( $index, $offset, $length, $path, $codec_id, $entry_hash ),
		};
	}

	/**
	 * Numeric field combinations for a db_chunk entry to prove
	 * project_payload_bytes() against, from a realistic large archive up to
	 * the theoretical worst case a PHP int can ever reach.
	 *
	 * @return array<string, array{0: int, 1: int, 2: int, 3: int, 4: int}> Each entry is [index, offset, length, chunk_index, codec_id].
	 */
	private function large_db_chunk_offset_cases(): array {
		return array(
			'offset around 2e9 (a real multi-gigabyte archive)'                        => array( 50000, 2000000000, 6000000, 12345, 1 ),
			'offset around 9e11 (a real multi-hundred-gigabyte archive)'                => array( 90000, 900000000000, 6000000, 45000, 1 ),
			'index, offset and length all at PHP_INT_MAX, chunk_index and codec_id maxed' => array( PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX, 999999, ManifestEntry::MAX_CODEC_ID ),
		);
	}

	/**
	 * Build a real file EntryPlan for the writer-comparison test.
	 *
	 * @param string $path     Relative archive path.
	 * @param string $contents Raw file contents.
	 * @return EntryPlan A raw-codec file plan.
	 */
	private static function real_file_plan( string $path, string $contents ): EntryPlan {
		return new EntryPlan(
			EntryHeader::for_file( $path, strlen( $contents ), 0644, 1690000000, 'application/octet-stream', 0 ),
			RawCodec::ID,
			str_repeat( "\0", EntryWriter::NONCE_SIZE ),
			self::real_content_stream( $contents )
		);
	}

	/**
	 * Build a real directory EntryPlan for the writer-comparison test.
	 *
	 * @param string $path Relative archive path.
	 * @return EntryPlan A raw-codec directory plan.
	 */
	private static function real_directory_plan( string $path ): EntryPlan {
		return new EntryPlan(
			EntryHeader::for_directory( $path, 0755, 0 ),
			RawCodec::ID,
			str_repeat( "\0", EntryWriter::NONCE_SIZE ),
			self::real_content_stream( '' )
		);
	}

	/**
	 * Build a real symlink EntryPlan for the writer-comparison test.
	 *
	 * @param string $path   Relative archive path.
	 * @param string $target Symlink target.
	 * @return EntryPlan A raw-codec symlink plan.
	 */
	private static function real_symlink_plan( string $path, string $target ): EntryPlan {
		return new EntryPlan(
			EntryHeader::for_symlink( $path, $target, 0 ),
			RawCodec::ID,
			str_repeat( "\0", EntryWriter::NONCE_SIZE ),
			self::real_content_stream( $target )
		);
	}

	/**
	 * Build a real db_chunk EntryPlan for the writer-comparison test.
	 *
	 * @param int    $chunk_index Zero-based chunk index.
	 * @param string $table_name  Predominant table name.
	 * @param int    $stmt_count  Statement count.
	 * @param string $sql         Raw SQL contents.
	 * @return EntryPlan A raw-codec db_chunk plan.
	 */
	private static function real_db_chunk_plan( int $chunk_index, string $table_name, int $stmt_count, string $sql ): EntryPlan {
		return new EntryPlan(
			EntryHeader::for_db_chunk( $chunk_index, $table_name, $stmt_count, strlen( $sql ), 0 ),
			RawCodec::ID,
			str_repeat( "\0", EntryWriter::NONCE_SIZE ),
			self::real_content_stream( $sql )
		);
	}

	/**
	 * Open an in-memory stream pre-filled with the given contents, rewound to offset 0.
	 *
	 * @param string $contents The bytes to seed the stream with.
	 * @return resource A readable, seekable stream.
	 */
	private static function real_content_stream( string $contents ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://temp is an in-process buffer, not a file; WP_Filesystem cannot open it.
		$src = fopen( 'php://temp', 'r+b' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Operating on a test stream resource, not a filesystem path.
		fwrite( $src, $contents );
		rewind( $src );
		return $src;
	}
}
