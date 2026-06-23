<?php
/**
 * Tests for the Provenance encryption_disabled_reason field.
 *
 * @package Pontifex\Tests\Unit\Archive\Format
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Archive\Format;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pontifex\Archive\Format\ExporterInfo;
use Pontifex\Archive\Format\Provenance;

/**
 * Tests for the provenance block's encryption_disabled_reason field.
 *
 * The format records why encryption was disabled when an unencrypted archive
 * is produced (`ARCHIVE-FORMAT.md` §8.5). The field round-trips when set, is
 * null by default, and — so existing archives keep byte-identical provenance
 * — is omitted from the JSON when null and read back as null when absent. A
 * present-but-non-string value is rejected on read.
 */
final class ProvenanceEncryptionReasonTest extends TestCase {

	/**
	 * Build a Provenance with the given reason (or null).
	 *
	 * @param string|null $reason The encryption-disabled reason, or null.
	 * @return Provenance The provenance value object.
	 */
	private static function provenance( ?string $reason ): Provenance {
		return new Provenance(
			'6.6.1',
			'8.2.10',
			'https://example.test',
			'utf8mb4',
			'utf8mb4_unicode_520_ci',
			new ExporterInfo( 'pontifex', '0.3.0' ),
			new DateTimeImmutable( '2026-06-23T10:00:00+00:00', new DateTimeZone( 'UTC' ) ),
			$reason
		);
	}

	/**
	 * Frame a canonical data array into provenance on-disk bytes (length, hash, payload).
	 *
	 * Lets a test craft a payload the constructor would not produce (e.g. a
	 * non-string reason), with a correct hash so from_bytes() reaches the
	 * field validation rather than failing the hash check first.
	 *
	 * @param array<string, mixed> $data The canonical data array.
	 * @return string The framed provenance block bytes.
	 */
	private static function frame( array $data ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Building deterministic test bytes; wp_json_encode depends on WordPress being loaded.
		$payload = (string) json_encode( $data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$hash    = hash( 'sha256', $payload, true );
		return pack( 'N', strlen( $payload ) ) . $hash . $payload;
	}

	/**
	 * A valid base data array matching the canonical provenance fields.
	 *
	 * @return array<string, mixed> The canonical data.
	 */
	private static function base_data(): array {
		return array(
			'wp_version'   => '6.6.1',
			'php_version'  => '8.2.10',
			'url'          => 'https://example.test',
			'db_charset'   => 'utf8mb4',
			'db_collation' => 'utf8mb4_unicode_520_ci',
			'exporter'     => array(
				'name'    => 'pontifex',
				'version' => '0.3.0',
			),
			'timestamp'    => '2026-06-23T10:00:00+00:00',
		);
	}

	/**
	 * A reason set on the provenance must round-trip through to_bytes/from_bytes.
	 *
	 * @return void
	 */
	public function test_reason_round_trips_when_set(): void {
		$reason = 'operator passed --no-encrypt; archive stored on an encrypted volume';
		$parsed = Provenance::from_bytes( self::provenance( $reason )->to_bytes() );

		$this->assertSame( $reason, $parsed->encryption_disabled_reason() );
	}

	/**
	 * The reason must default to null when not supplied.
	 *
	 * @return void
	 */
	public function test_reason_defaults_to_null(): void {
		$this->assertNull( self::provenance( null )->encryption_disabled_reason() );
	}

	/**
	 * A null reason must be omitted from the JSON, keeping existing provenance byte-stable.
	 *
	 * @return void
	 */
	public function test_null_reason_is_omitted_from_json(): void {
		$payload = substr( self::provenance( null )->to_bytes(), Provenance::HEADER_SIZE );

		$this->assertStringNotContainsString( 'encryption_disabled_reason', $payload );
	}

	/**
	 * A provenance block without the field (an older archive) must read back as null.
	 *
	 * @return void
	 */
	public function test_absent_field_reads_as_null(): void {
		$parsed = Provenance::from_bytes( self::frame( self::base_data() ) );

		$this->assertNull( $parsed->encryption_disabled_reason() );
	}

	/**
	 * A present-but-non-string reason must be rejected on read.
	 *
	 * @return void
	 */
	public function test_non_string_reason_is_rejected(): void {
		$data                               = self::base_data();
		$data['encryption_disabled_reason'] = 123;

		$this->expectException( InvalidArgumentException::class );

		Provenance::from_bytes( self::frame( $data ) );
	}
}
