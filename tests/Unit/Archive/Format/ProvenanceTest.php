<?php
/**
 * Behavioural tests for the Provenance value object.
 *
 * @package Pontifex\Tests\Unit\Archive\Format
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Archive\Format;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pontifex\Archive\Format\ByteOrder;
use Pontifex\Archive\Format\ExporterInfo;
use Pontifex\Archive\Format\Provenance;
use Pontifex\Archive\Format\Scope;
use Pontifex\Archive\Integrity\Sha256;

/**
 * Behavioural tests for the Provenance class.
 *
 * Verifies the on-disk format invariants:
 *
 *  - Constants for length prefix, header size, and payload ceiling.
 *  - Constructor accepts a valid seven-field combination and
 *    rejects each string field when empty.
 *  - to_bytes produces a canonical layout: 4-byte length + 32-byte
 *    SHA-256 + JSON payload, with JSON fields in a fixed order.
 *  - from_bytes rejects under-size input, oversized declared
 *    length, mismatched total length, bad hash, malformed JSON,
 *    missing fields, non-string fields, and invalid timestamps.
 *  - Round-trip preserves every field, including non-UTC timestamps.
 */
final class ProvenanceTest extends TestCase {

	/**
	 * Build a canonical valid Provenance used as a fixture by most tests.
	 *
	 * @return Provenance A valid Provenance with known field values.
	 */
	private function valid_provenance(): Provenance {
		return new Provenance(
			'6.5.0',
			'8.2.10',
			'https://example.com',
			'utf8mb4',
			'utf8mb4_unicode_520_ci',
			new ExporterInfo( 'pontifex', '0.1.0' ),
			new DateTimeImmutable( '2026-05-21T22:21:02+00:00' )
		);
	}

	/**
	 * Canonical JSON payload that the valid_provenance fixture must serialise to.
	 *
	 * @return string The expected canonical JSON byte string.
	 */
	private function expected_canonical_json(): string {
		return '{"wp_version":"6.5.0","php_version":"8.2.10","url":"https://example.com","db_charset":"utf8mb4","db_collation":"utf8mb4_unicode_520_ci","exporter":{"name":"pontifex","version":"0.1.0"},"timestamp":"2026-05-21T22:21:02+00:00"}';
	}

	/**
	 * The optional v1.1 scope and table prefix must encode in a fixed order and round-trip.
	 *
	 * @return void
	 */
	public function test_round_trip_preserves_scope_and_table_prefix(): void {
		$scope      = new Scope( true, 'wp-content', false, false, true, array( 'wp-content/pontifex/**', 'wp-content/cache/**' ) );
		$provenance = new Provenance(
			'6.5.0',
			'8.2.10',
			'https://example.com',
			'utf8mb4',
			'utf8mb4_unicode_520_ci',
			new ExporterInfo( 'pontifex', '0.5.0' ),
			new DateTimeImmutable( '2026-05-21T22:21:02+00:00' ),
			null,
			'wp_',
			$scope
		);

		$payload = substr( $provenance->to_bytes(), Provenance::HEADER_SIZE );
		$this->assertSame(
			'{"wp_version":"6.5.0","php_version":"8.2.10","url":"https://example.com","db_charset":"utf8mb4","db_collation":"utf8mb4_unicode_520_ci","exporter":{"name":"pontifex","version":"0.5.0"},"timestamp":"2026-05-21T22:21:02+00:00","table_prefix":"wp_","scope":{"content_only":true,"content_root":"wp-content","includes_core":false,"includes_wp_config":false,"includes_database":true,"excluded_paths":["wp-content/pontifex/**","wp-content/cache/**"]}}',
			$payload
		);

		$restored = Provenance::from_bytes( $provenance->to_bytes() );
		$this->assertSame( 'wp_', $restored->table_prefix() );
		$this->assertNotNull( $restored->scope() );
		$this->assertTrue( $restored->scope()->is_content_only() );
		$this->assertSame( 'wp-content', $restored->scope()->content_root() );
		$this->assertFalse( $restored->scope()->includes_core() );
		$this->assertFalse( $restored->scope()->includes_wp_config() );
		$this->assertTrue( $restored->scope()->includes_database() );
		$this->assertSame( array( 'wp-content/pontifex/**', 'wp-content/cache/**' ), $restored->scope()->excluded_paths() );
	}

	/**
	 * An archive with no scope or table prefix (the v1.0 shape) decodes them as null.
	 *
	 * @return void
	 */
	public function test_scope_and_table_prefix_absent_decode_as_null(): void {
		$restored = Provenance::from_bytes( $this->valid_provenance()->to_bytes() );

		$this->assertNull( $restored->table_prefix() );
		$this->assertNull( $restored->scope() );
	}

	/**
	 * The length prefix constant must be 4 bytes (uint32).
	 *
	 * @return void
	 */
	public function test_length_prefix_size_is_four(): void {
		$this->assertSame( 4, Provenance::LENGTH_PREFIX_SIZE );
	}

	/**
	 * HEADER_SIZE must equal 4 + 32 = 36 bytes.
	 *
	 * @return void
	 */
	public function test_header_size_is_thirty_six(): void {
		$this->assertSame( 36, Provenance::HEADER_SIZE );
		$this->assertSame( Provenance::LENGTH_PREFIX_SIZE + Sha256::DIGEST_SIZE, Provenance::HEADER_SIZE );
	}

	/**
	 * The maximum payload size must be 64 KiB (65536 bytes).
	 *
	 * @return void
	 */
	public function test_max_payload_size_is_sixty_four_kib(): void {
		$this->assertSame( 65536, Provenance::MAX_PAYLOAD_SIZE );
	}

	/**
	 * The constructor must store and expose all seven fields via accessors.
	 *
	 * @return void
	 */
	public function test_constructor_accepts_valid_values(): void {
		$exporter  = new ExporterInfo( 'pontifex', '0.1.0' );
		$timestamp = new DateTimeImmutable( '2026-05-21T22:21:02+00:00' );

		$prov = new Provenance(
			'6.5.0',
			'8.2.10',
			'https://example.com',
			'utf8mb4',
			'utf8mb4_unicode_520_ci',
			$exporter,
			$timestamp
		);

		$this->assertSame( '6.5.0', $prov->wp_version() );
		$this->assertSame( '8.2.10', $prov->php_version() );
		$this->assertSame( 'https://example.com', $prov->url() );
		$this->assertSame( 'utf8mb4', $prov->db_charset() );
		$this->assertSame( 'utf8mb4_unicode_520_ci', $prov->db_collation() );
		$this->assertSame( $exporter, $prov->exporter() );
		$this->assertSame( $timestamp, $prov->timestamp() );
	}

	/**
	 * The constructor must reject an empty wp_version.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_empty_wp_version(): void {
		$this->expectException( InvalidArgumentException::class );

		new Provenance(
			'',
			'8.2.10',
			'https://example.com',
			'utf8mb4',
			'utf8mb4_unicode_520_ci',
			new ExporterInfo( 'pontifex', '0.1.0' ),
			new DateTimeImmutable( '2026-05-21T22:21:02+00:00' )
		);
	}

	/**
	 * The constructor must reject an empty php_version.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_empty_php_version(): void {
		$this->expectException( InvalidArgumentException::class );

		new Provenance(
			'6.5.0',
			'',
			'https://example.com',
			'utf8mb4',
			'utf8mb4_unicode_520_ci',
			new ExporterInfo( 'pontifex', '0.1.0' ),
			new DateTimeImmutable( '2026-05-21T22:21:02+00:00' )
		);
	}

	/**
	 * The constructor must reject an empty url.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_empty_url(): void {
		$this->expectException( InvalidArgumentException::class );

		new Provenance(
			'6.5.0',
			'8.2.10',
			'',
			'utf8mb4',
			'utf8mb4_unicode_520_ci',
			new ExporterInfo( 'pontifex', '0.1.0' ),
			new DateTimeImmutable( '2026-05-21T22:21:02+00:00' )
		);
	}

	/**
	 * The constructor must reject an empty db_charset.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_empty_db_charset(): void {
		$this->expectException( InvalidArgumentException::class );

		new Provenance(
			'6.5.0',
			'8.2.10',
			'https://example.com',
			'',
			'utf8mb4_unicode_520_ci',
			new ExporterInfo( 'pontifex', '0.1.0' ),
			new DateTimeImmutable( '2026-05-21T22:21:02+00:00' )
		);
	}

	/**
	 * The constructor must reject an empty db_collation.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_empty_db_collation(): void {
		$this->expectException( InvalidArgumentException::class );

		new Provenance(
			'6.5.0',
			'8.2.10',
			'https://example.com',
			'utf8mb4',
			'',
			new ExporterInfo( 'pontifex', '0.1.0' ),
			new DateTimeImmutable( '2026-05-21T22:21:02+00:00' )
		);
	}

	/**
	 * The URL field must be stored verbatim with no canonicalisation.
	 *
	 * @return void
	 */
	public function test_url_is_stored_verbatim(): void {
		$weird_url = 'HTTPS://Example.COM/path/?q=1&r=2#fragment';
		$prov      = new Provenance(
			'6.5.0',
			'8.2.10',
			$weird_url,
			'utf8mb4',
			'utf8mb4_unicode_520_ci',
			new ExporterInfo( 'pontifex', '0.1.0' ),
			new DateTimeImmutable( '2026-05-21T22:21:02+00:00' )
		);

		$this->assertSame( $weird_url, $prov->url() );
	}

	/**
	 * Serialisation must produce HEADER_SIZE + payload-length bytes.
	 *
	 * @return void
	 */
	public function test_to_bytes_produces_header_size_plus_payload(): void {
		$bytes           = $this->valid_provenance()->to_bytes();
		$expected_length = strlen( $this->expected_canonical_json() );

		$this->assertSame( Provenance::HEADER_SIZE + $expected_length, strlen( $bytes ) );
	}

	/**
	 * The serialised JSON payload must match the canonical field order exactly.
	 *
	 * Locks in the field order so future changes will fail the test
	 * loudly rather than silently change the on-disk format.
	 *
	 * @return void
	 */
	public function test_to_bytes_payload_is_canonical_json(): void {
		$bytes   = $this->valid_provenance()->to_bytes();
		$payload = substr( $bytes, Provenance::HEADER_SIZE );

		$this->assertSame( $this->expected_canonical_json(), $payload );
	}

	/**
	 * The stored hash in the serialised bytes must match the SHA-256 of the payload.
	 *
	 * @return void
	 */
	public function test_to_bytes_hash_matches_payload(): void {
		$bytes         = $this->valid_provenance()->to_bytes();
		$stored_hash   = substr( $bytes, Provenance::LENGTH_PREFIX_SIZE, Sha256::DIGEST_SIZE );
		$payload       = substr( $bytes, Provenance::HEADER_SIZE );
		$computed_hash = Sha256::of( $payload );

		$this->assertSame( $computed_hash, $stored_hash );
	}

	/**
	 * The length prefix in the serialised bytes must equal the JSON payload length.
	 *
	 * @return void
	 */
	public function test_to_bytes_length_prefix_matches_payload(): void {
		$bytes           = $this->valid_provenance()->to_bytes();
		$declared_length = ByteOrder::unpack_uint32( substr( $bytes, 0, Provenance::LENGTH_PREFIX_SIZE ) );
		$payload         = substr( $bytes, Provenance::HEADER_SIZE );

		$this->assertSame( strlen( $payload ), $declared_length );
	}

	/**
	 * Parsing must reject input shorter than HEADER_SIZE.
	 *
	 * @return void
	 */
	public function test_from_bytes_rejects_under_size_input(): void {
		$this->expectException( InvalidArgumentException::class );

		Provenance::from_bytes( str_repeat( "\x00", Provenance::HEADER_SIZE - 1 ) );
	}

	/**
	 * Parsing must reject a length prefix that declares a payload above MAX_PAYLOAD_SIZE.
	 *
	 * Crafts a header whose length field claims 64 KiB + 1 bytes
	 * of payload.
	 *
	 * @return void
	 */
	public function test_from_bytes_rejects_oversize_declared_length(): void {
		$length = ByteOrder::pack_uint32( Provenance::MAX_PAYLOAD_SIZE + 1 );
		$hash   = str_repeat( "\x00", Sha256::DIGEST_SIZE );
		$bytes  = $length . $hash;

		$this->expectException( InvalidArgumentException::class );

		Provenance::from_bytes( $bytes );
	}

	/**
	 * Parsing must reject input whose total length disagrees with the declared payload length.
	 *
	 * @return void
	 */
	public function test_from_bytes_rejects_length_mismatch(): void {
		$valid_bytes = $this->valid_provenance()->to_bytes();
		$truncated   = substr( $valid_bytes, 0, strlen( $valid_bytes ) - 1 );

		$this->expectException( InvalidArgumentException::class );

		Provenance::from_bytes( $truncated );
	}

	/**
	 * Parsing must reject bytes whose stored hash does not match the payload.
	 *
	 * @return void
	 */
	public function test_from_bytes_rejects_bad_hash(): void {
		$valid_bytes = $this->valid_provenance()->to_bytes();
		$tampered    = $valid_bytes;

		// Flip one bit in the stored hash so it no longer matches.
		$tampered[ Provenance::LENGTH_PREFIX_SIZE ] = chr( ord( $tampered[ Provenance::LENGTH_PREFIX_SIZE ] ) ^ 0x01 );

		$this->expectException( InvalidArgumentException::class );

		Provenance::from_bytes( $tampered );
	}

	/**
	 * Parsing must reject a malformed JSON payload.
	 *
	 * Builds a block with a valid length and matching hash but
	 * non-JSON payload content.
	 *
	 * @return void
	 */
	public function test_from_bytes_rejects_malformed_json(): void {
		$garbage = 'not valid json at all';
		$length  = ByteOrder::pack_uint32( strlen( $garbage ) );
		$hash    = Sha256::of( $garbage );
		$bytes   = $length . $hash . $garbage;

		$this->expectException( InvalidArgumentException::class );

		Provenance::from_bytes( $bytes );
	}

	/**
	 * Parsing must reject a JSON payload missing a required field.
	 *
	 * @return void
	 */
	public function test_from_bytes_rejects_missing_field(): void {
		$missing_url = '{"wp_version":"6.5.0","php_version":"8.2.10","db_charset":"utf8mb4","db_collation":"utf8mb4_unicode_520_ci","exporter":{"name":"pontifex","version":"0.1.0"},"timestamp":"2026-05-21T22:21:02+00:00"}';
		$length      = ByteOrder::pack_uint32( strlen( $missing_url ) );
		$hash        = Sha256::of( $missing_url );
		$bytes       = $length . $hash . $missing_url;

		$this->expectException( InvalidArgumentException::class );

		Provenance::from_bytes( $bytes );
	}

	/**
	 * Parsing must reject a JSON payload with a non-string field value.
	 *
	 * @return void
	 */
	public function test_from_bytes_rejects_non_string_field(): void {
		// wp_version is an integer here instead of a string.
		$bad_json = '{"wp_version":6,"php_version":"8.2.10","url":"https://example.com","db_charset":"utf8mb4","db_collation":"utf8mb4_unicode_520_ci","exporter":{"name":"pontifex","version":"0.1.0"},"timestamp":"2026-05-21T22:21:02+00:00"}';
		$length   = ByteOrder::pack_uint32( strlen( $bad_json ) );
		$hash     = Sha256::of( $bad_json );
		$bytes    = $length . $hash . $bad_json;

		$this->expectException( InvalidArgumentException::class );

		Provenance::from_bytes( $bytes );
	}

	/**
	 * Parsing must reject a JSON payload whose timestamp does not parse as ISO 8601 with offset.
	 *
	 * @return void
	 */
	public function test_from_bytes_rejects_bad_timestamp(): void {
		$bad_json = '{"wp_version":"6.5.0","php_version":"8.2.10","url":"https://example.com","db_charset":"utf8mb4","db_collation":"utf8mb4_unicode_520_ci","exporter":{"name":"pontifex","version":"0.1.0"},"timestamp":"not a real timestamp"}';
		$length   = ByteOrder::pack_uint32( strlen( $bad_json ) );
		$hash     = Sha256::of( $bad_json );
		$bytes    = $length . $hash . $bad_json;

		$this->expectException( InvalidArgumentException::class );

		Provenance::from_bytes( $bytes );
	}

	/**
	 * Parsing must reject a timestamp that parses only with warnings (e.g. trailing data).
	 *
	 * The createFromFormat() call returns an object for such a value rather than false, so the
	 * parser must also consult getLastErrors() — otherwise a malformed timestamp slips through.
	 *
	 * @return void
	 */
	public function test_from_bytes_rejects_timestamp_with_trailing_data(): void {
		$bad_json = '{"wp_version":"6.5.0","php_version":"8.2.10","url":"https://example.com","db_charset":"utf8mb4","db_collation":"utf8mb4_unicode_520_ci","exporter":{"name":"pontifex","version":"0.1.0"},"timestamp":"2026-05-21T22:21:02+00:00 trailing"}';
		$length   = ByteOrder::pack_uint32( strlen( $bad_json ) );
		$hash     = Sha256::of( $bad_json );
		$bytes    = $length . $hash . $bad_json;

		$this->expectException( InvalidArgumentException::class );

		Provenance::from_bytes( $bytes );
	}

	/**
	 * Parsing must reject a JSON payload whose exporter sub-object is missing a field.
	 *
	 * @return void
	 */
	public function test_from_bytes_rejects_incomplete_exporter(): void {
		// exporter has name but no version.
		$bad_json = '{"wp_version":"6.5.0","php_version":"8.2.10","url":"https://example.com","db_charset":"utf8mb4","db_collation":"utf8mb4_unicode_520_ci","exporter":{"name":"pontifex"},"timestamp":"2026-05-21T22:21:02+00:00"}';
		$length   = ByteOrder::pack_uint32( strlen( $bad_json ) );
		$hash     = Sha256::of( $bad_json );
		$bytes    = $length . $hash . $bad_json;

		$this->expectException( InvalidArgumentException::class );

		Provenance::from_bytes( $bytes );
	}

	/**
	 * Round-trip must preserve every field exactly.
	 *
	 * @return void
	 */
	public function test_round_trip_preserves_all_fields(): void {
		$original = $this->valid_provenance();
		$parsed   = Provenance::from_bytes( $original->to_bytes() );

		$this->assertSame( $original->wp_version(), $parsed->wp_version() );
		$this->assertSame( $original->php_version(), $parsed->php_version() );
		$this->assertSame( $original->url(), $parsed->url() );
		$this->assertSame( $original->db_charset(), $parsed->db_charset() );
		$this->assertSame( $original->db_collation(), $parsed->db_collation() );
		$this->assertSame( $original->exporter()->name(), $parsed->exporter()->name() );
		$this->assertSame( $original->exporter()->version(), $parsed->exporter()->version() );
		$this->assertSame(
			$original->timestamp()->format( DateTimeInterface::ATOM ),
			$parsed->timestamp()->format( DateTimeInterface::ATOM )
		);
	}

	/**
	 * Round-trip must preserve a non-UTC timezone offset on the timestamp.
	 *
	 * @return void
	 */
	public function test_round_trip_preserves_non_utc_timestamp(): void {
		$timestamp = new DateTimeImmutable( '2026-05-21T15:21:02+05:00' );
		$original  = new Provenance(
			'6.5.0',
			'8.2.10',
			'https://example.com',
			'utf8mb4',
			'utf8mb4_unicode_520_ci',
			new ExporterInfo( 'pontifex', '0.1.0' ),
			$timestamp
		);

		$parsed = Provenance::from_bytes( $original->to_bytes() );

		$this->assertSame(
			'2026-05-21T15:21:02+05:00',
			$parsed->timestamp()->format( DateTimeInterface::ATOM )
		);
	}
}
