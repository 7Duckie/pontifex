<?php
/**
 * Pontifex archive provenance — source-site metadata embedded in every archive.
 *
 * @package Pontifex\Archive\Format
 */

declare(strict_types=1);

namespace Pontifex\Archive\Format;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use JsonException;
use Pontifex\Archive\Integrity\Sha256;

/**
 * Immutable value object representing the archive provenance block.
 *
 * The provenance block is a small JSON document embedded in the
 * archive that records facts about the source site at the moment of
 * export: which WordPress version produced the archive, which PHP
 * version, what URL the site was at, the database character set and
 * collation, which version of Pontifex did the export, and when.
 *
 * The on-disk layout is:
 *
 *  - length        (4 bytes, uint32 big-endian): byte length of the
 *    JSON payload.
 *  - payload_hash  (32 bytes): SHA-256 of the JSON payload.
 *  - payload       (N bytes): UTF-8 JSON.
 *
 * Total on-disk size is 36 + N bytes.
 *
 * Writes use a fixed canonical field order so that a given set of
 * inputs always produces the same bytes (and therefore the same
 * hash). The reader does not enforce field order on parse; it
 * verifies the stored hash against whatever bytes were on disk.
 *
 * Reads reject payloads larger than MAX_PAYLOAD_SIZE (64 KiB) as a
 * defensive ceiling against malformed or malicious input.
 *
 * Round-trip contract:
 * Provenance::from_bytes(Provenance::to_bytes()) returns a
 * Provenance equal in every field to the original, with
 * second-precision on the timestamp (sub-second components are not
 * preserved by the ISO 8601 ATOM format).
 */
final class Provenance {

	/**
	 * Size of the length prefix field in bytes (4).
	 *
	 * @var int
	 */
	public const LENGTH_PREFIX_SIZE = 4;

	/**
	 * Combined size of the length prefix and payload hash (36).
	 *
	 * Used by writers to compute total on-disk size as
	 * HEADER_SIZE + len(payload), and by readers as the minimum
	 * valid on-disk size.
	 *
	 * @var int
	 */
	public const HEADER_SIZE = self::LENGTH_PREFIX_SIZE + Sha256::DIGEST_SIZE;

	/**
	 * Maximum permitted size of the JSON payload, in bytes (65536 = 64 KiB).
	 *
	 * Real provenance is typically under 1 KiB. Anything wildly
	 * larger is rejected as a defensive ceiling.
	 *
	 * @var int
	 */
	public const MAX_PAYLOAD_SIZE = 65536;

	/**
	 * Flags used for encoding the canonical JSON payload.
	 *
	 * Fixed for v1 archives so writes are deterministic — the same
	 * inputs always produce the same bytes and therefore the same
	 * hash.
	 *
	 * @var int
	 */
	private const JSON_ENCODE_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

	/**
	 * WordPress version string for the source site (e.g. "6.5.0").
	 *
	 * @var string
	 */
	private string $wp_version;

	/**
	 * PHP version string of the runtime that produced the archive (e.g. "8.2.10").
	 *
	 * @var string
	 */
	private string $php_version;

	/**
	 * URL of the source site (stored verbatim, no canonicalisation).
	 *
	 * @var string
	 */
	private string $url;

	/**
	 * Database character set (e.g. "utf8mb4").
	 *
	 * @var string
	 */
	private string $db_charset;

	/**
	 * Database collation (e.g. "utf8mb4_unicode_520_ci").
	 *
	 * @var string
	 */
	private string $db_collation;

	/**
	 * Exporter tool name and version that produced this archive.
	 *
	 * @var ExporterInfo
	 */
	private ExporterInfo $exporter;

	/**
	 * Moment of export, with at least timezone-offset precision.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $timestamp;

	/**
	 * Construct a Provenance with the seven required fields.
	 *
	 * @param string            $wp_version    WordPress version string; must be non-empty.
	 * @param string            $php_version   PHP version string; must be non-empty.
	 * @param string            $url           Source-site URL; must be non-empty; stored verbatim.
	 * @param string            $db_charset    Database character set; must be non-empty.
	 * @param string            $db_collation  Database collation; must be non-empty.
	 * @param ExporterInfo      $exporter      Exporter tool name and version.
	 * @param DateTimeImmutable $timestamp     Moment of export; serialised with second precision.
	 * @throws InvalidArgumentException If any string argument is the empty string.
	 */
	public function __construct(
		string $wp_version,
		string $php_version,
		string $url,
		string $db_charset,
		string $db_collation,
		ExporterInfo $exporter,
		DateTimeImmutable $timestamp
	) {
		if ( '' === $wp_version ) {
			throw new InvalidArgumentException( 'Provenance: wp_version must not be empty.' );
		}
		if ( '' === $php_version ) {
			throw new InvalidArgumentException( 'Provenance: php_version must not be empty.' );
		}
		if ( '' === $url ) {
			throw new InvalidArgumentException( 'Provenance: url must not be empty.' );
		}
		if ( '' === $db_charset ) {
			throw new InvalidArgumentException( 'Provenance: db_charset must not be empty.' );
		}
		if ( '' === $db_collation ) {
			throw new InvalidArgumentException( 'Provenance: db_collation must not be empty.' );
		}

		$this->wp_version   = $wp_version;
		$this->php_version  = $php_version;
		$this->url          = $url;
		$this->db_charset   = $db_charset;
		$this->db_collation = $db_collation;
		$this->exporter     = $exporter;
		$this->timestamp    = $timestamp;
	}

	/**
	 * Return the WordPress version string.
	 *
	 * @return string The WordPress version string.
	 */
	public function wp_version(): string {
		return $this->wp_version;
	}

	/**
	 * Return the PHP version string.
	 *
	 * @return string The PHP version string.
	 */
	public function php_version(): string {
		return $this->php_version;
	}

	/**
	 * Return the source-site URL.
	 *
	 * @return string The URL exactly as provided to the constructor.
	 */
	public function url(): string {
		return $this->url;
	}

	/**
	 * Return the database character set.
	 *
	 * @return string The database character set.
	 */
	public function db_charset(): string {
		return $this->db_charset;
	}

	/**
	 * Return the database collation.
	 *
	 * @return string The database collation.
	 */
	public function db_collation(): string {
		return $this->db_collation;
	}

	/**
	 * Return the exporter tool name and version.
	 *
	 * @return ExporterInfo The exporter value object.
	 */
	public function exporter(): ExporterInfo {
		return $this->exporter;
	}

	/**
	 * Return the export timestamp.
	 *
	 * @return DateTimeImmutable The timestamp value object.
	 */
	public function timestamp(): DateTimeImmutable {
		return $this->timestamp;
	}

	/**
	 * Serialise the provenance to its on-disk representation.
	 *
	 * Builds the JSON payload in canonical field order, computes its
	 * SHA-256 hash, and concatenates length (4 bytes BE) + hash
	 * (32 bytes) + payload.
	 *
	 * @return string Exactly HEADER_SIZE + N bytes, where N is the JSON payload length.
	 * @throws JsonException If JSON encoding fails (should not happen for the fields validated by the constructor).
	 */
	public function to_bytes(): string {
		$payload = $this->encode_canonical_json();
		$hash    = Sha256::of( $payload );

		return ByteOrder::pack_uint32( strlen( $payload ) )
			. $hash
			. $payload;
	}

	/**
	 * Parse on-disk bytes into a Provenance value object.
	 *
	 * Verifies the payload size against the length prefix, rejects
	 * declared sizes above MAX_PAYLOAD_SIZE, verifies the SHA-256
	 * hash, and decodes the JSON. Hash mismatch is treated as
	 * corruption or tampering.
	 *
	 * @param string $bytes On-disk bytes representing a provenance block.
	 * @return self A Provenance value object reflecting the parsed bytes.
	 * @throws InvalidArgumentException If the bytes are too short, too long, oversize, hash-mismatched, malformed, or missing required fields.
	 */
	public static function from_bytes( string $bytes ): self {
		if ( strlen( $bytes ) < self::HEADER_SIZE ) {
			throw new InvalidArgumentException(
				sprintf(
					'Provenance::from_bytes: input must be at least %d bytes, got %d.',
					(int) self::HEADER_SIZE,
					(int) strlen( $bytes )
				)
			);
		}

		$length = ByteOrder::unpack_uint32( substr( $bytes, 0, self::LENGTH_PREFIX_SIZE ) );

		if ( $length > self::MAX_PAYLOAD_SIZE ) {
			throw new InvalidArgumentException(
				sprintf(
					'Provenance::from_bytes: declared payload size %d exceeds maximum %d bytes.',
					(int) $length,
					(int) self::MAX_PAYLOAD_SIZE
				)
			);
		}

		$expected_total = self::HEADER_SIZE + $length;
		if ( strlen( $bytes ) !== $expected_total ) {
			throw new InvalidArgumentException(
				sprintf(
					'Provenance::from_bytes: expected exactly %d bytes (4 length + 32 hash + %d payload), got %d.',
					(int) $expected_total,
					(int) $length,
					(int) strlen( $bytes )
				)
			);
		}

		$stored_hash = substr( $bytes, self::LENGTH_PREFIX_SIZE, Sha256::DIGEST_SIZE );
		$payload     = substr( $bytes, self::HEADER_SIZE, $length );

		$computed_hash = Sha256::of( $payload );
		if ( ! hash_equals( $stored_hash, $computed_hash ) ) {
			throw new InvalidArgumentException(
				'Provenance::from_bytes: payload hash does not match stored hash; the block is corrupt or has been tampered with.'
			);
		}

		return self::decode_canonical_json( $payload );
	}

	/**
	 * Encode this Provenance to a canonical JSON byte string.
	 *
	 * Field order is fixed: wp_version, php_version, url,
	 * db_charset, db_collation, exporter (with sub-fields in the
	 * order name, version), timestamp. This makes the byte output
	 * deterministic.
	 *
	 * @return string A canonical JSON byte string in UTF-8.
	 * @throws JsonException If encoding fails (should not happen for the validated fields).
	 */
	private function encode_canonical_json(): string {
		$data = array(
			'wp_version'   => $this->wp_version,
			'php_version'  => $this->php_version,
			'url'          => $this->url,
			'db_charset'   => $this->db_charset,
			'db_collation' => $this->db_collation,
			'exporter'     => array(
				'name'    => $this->exporter->name(),
				'version' => $this->exporter->version(),
			),
			'timestamp'    => $this->timestamp->format( DateTimeInterface::ATOM ),
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Deterministic byte output required for hash stability; wp_json_encode wraps json_encode without adding anything needed here, and depends on WordPress being loaded.
		return json_encode( $data, self::JSON_ENCODE_FLAGS );
	}

	/**
	 * Decode a JSON payload into a Provenance value object.
	 *
	 * Validates that all required fields are present, that string
	 * fields are actually strings, that the exporter sub-object has
	 * its two required fields, and that the timestamp parses as
	 * ISO 8601 with a timezone offset.
	 *
	 * @param string $json The JSON payload bytes as read from disk.
	 * @return self A Provenance reflecting the decoded data.
	 * @throws InvalidArgumentException If the JSON is malformed, missing fields, or contains invalid values.
	 */
	private static function decode_canonical_json( string $json ): self {
		try {
			$data = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		} catch ( JsonException $e ) {
			throw new InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message embedded for diagnostic context; not HTML output.
				'Provenance: JSON payload is malformed: ' . $e->getMessage()
			);
		}

		if ( ! is_array( $data ) ) {
			throw new InvalidArgumentException( 'Provenance: JSON payload must decode to an object.' );
		}

		$required_string_fields = array( 'wp_version', 'php_version', 'url', 'db_charset', 'db_collation', 'timestamp' );
		foreach ( $required_string_fields as $field ) {
			if ( ! array_key_exists( $field, $data ) ) {
				throw new InvalidArgumentException(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $field is a hardcoded value from $required_string_fields above; exception message, not HTML output.
					sprintf( 'Provenance: JSON payload is missing required field "%s".', $field )
				);
			}
			if ( ! is_string( $data[ $field ] ) ) {
				throw new InvalidArgumentException(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $field is a hardcoded value from $required_string_fields above; exception message, not HTML output.
					sprintf( 'Provenance: field "%s" must be a string.', $field )
				);
			}
		}

		if ( ! array_key_exists( 'exporter', $data ) ) {
			throw new InvalidArgumentException( 'Provenance: JSON payload is missing required field "exporter".' );
		}
		if ( ! is_array( $data['exporter'] ) ) {
			throw new InvalidArgumentException( 'Provenance: exporter field must be an object.' );
		}
		foreach ( array( 'name', 'version' ) as $exporter_field ) {
			if ( ! array_key_exists( $exporter_field, $data['exporter'] ) ) {
				throw new InvalidArgumentException(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $exporter_field is a hardcoded literal ('name' or 'version'); exception message, not HTML output.
					sprintf( 'Provenance: exporter object is missing required field "%s".', $exporter_field )
				);
			}
			if ( ! is_string( $data['exporter'][ $exporter_field ] ) ) {
				throw new InvalidArgumentException(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $exporter_field is a hardcoded literal ('name' or 'version'); exception message, not HTML output.
					sprintf( 'Provenance: exporter.%s must be a string.', $exporter_field )
				);
			}
		}

		$timestamp = DateTimeImmutable::createFromFormat( DateTimeInterface::ATOM, $data['timestamp'] );
		if ( false === $timestamp ) {
			throw new InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- User-supplied value embedded in exception message for diagnostic context; this is an exception path, not HTML output to a browser.
				sprintf( 'Provenance: timestamp "%s" is not a valid ISO 8601 string with timezone offset.', $data['timestamp'] )
			);
		}

		return new self(
			$data['wp_version'],
			$data['php_version'],
			$data['url'],
			$data['db_charset'],
			$data['db_collation'],
			new ExporterInfo( $data['exporter']['name'], $data['exporter']['version'] ),
			$timestamp
		);
	}
}
