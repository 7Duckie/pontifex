<?php
/**
 * Pontifex archive manifest — ordered list of entries that lives at the end of every archive.
 *
 * @package Pontifex\Archive\Format
 */

declare(strict_types=1);

namespace Pontifex\Archive\Format;

use InvalidArgumentException;
use JsonException;
use Pontifex\Archive\Integrity\Sha256;

/**
 * Immutable value object representing the archive manifest block.
 *
 * The manifest is the index at the end of the archive. The Footer's
 * manifest_offset and manifest_length point to it, and its bytes are
 * verified against the Footer's manifest_hash. Without the manifest,
 * a reader would have to scan the entire archive to find any
 * particular entry; with it, a reader can jump straight to any
 * entry's offset.
 *
 * For each entry the manifest stores the offset where the entry
 * record begins, the entry's metadata (an EntryHeader), and the
 * verification data needed to decode the payload: codec id, encoded
 * payload length, and the SHA-256 hash of the encoded payload.
 *
 * The on-disk layout is the same three-part framing as Provenance:
 *
 *  - length        (4 bytes, uint32 big-endian): byte length of the
 *    JSON payload.
 *  - payload_hash  (32 bytes): SHA-256 of the JSON payload.
 *  - payload       (N bytes): UTF-8 JSON.
 *
 * Total on-disk size is 36 + N bytes.
 *
 * Writes use a fixed canonical field order so the same set of
 * entries always produces the same bytes (and therefore the same
 * hash). The reader does not enforce field order on parse; it
 * verifies the stored hash against whatever bytes were on disk.
 *
 * Reads reject payloads larger than MAX_PAYLOAD_SIZE (16 MiB) as a
 * defensive ceiling. 16 MiB is large enough to cover roughly 50,000
 * entries with comfortable headroom and small enough to flag
 * anything wildly out of range as malformed or malicious.
 *
 * The manifest preserves entry order exactly as constructed — no
 * sorting, no deduplication. Two entries with the same path both
 * appear if both were given. An empty manifest is structurally
 * valid (an archive with no entries is unusual but not malformed).
 *
 * Round-trip contract:
 * ArchiveManifest::from_bytes(ArchiveManifest::to_bytes()) returns
 * an ArchiveManifest equal in entry order and field values to the
 * original.
 */
final class ArchiveManifest {

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
	 * Maximum permitted size of the JSON payload, in bytes (16 MiB).
	 *
	 * Covers roughly 50,000 entries with headroom. Anything larger
	 * is rejected as a defensive ceiling.
	 *
	 * @var int
	 */
	public const MAX_PAYLOAD_SIZE = 16777216;

	/**
	 * Maximum nesting depth when decoding the canonical-JSON payload (PHP's default).
	 *
	 * @var int
	 */
	private const JSON_MAX_DEPTH = 512;

	/**
	 * Flags used for encoding the canonical JSON payload.
	 *
	 * Fixed for v1 archives so writes are deterministic.
	 *
	 * @var int
	 */
	private const JSON_ENCODE_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

	/**
	 * Ordered list of entries.
	 *
	 * @var array<int, ManifestEntry>
	 */
	private array $entries;

	/**
	 * Construct an ArchiveManifest from a list of entries.
	 *
	 * The list is preserved in the given order. Every element must
	 * be a ManifestEntry instance; this is enforced at construction
	 * so the rest of the class can assume the invariant. An empty
	 * list is accepted (archives with no entries are uncommon but
	 * structurally valid).
	 *
	 * @param array<int, ManifestEntry> $entries Ordered list of manifest entries.
	 * @throws InvalidArgumentException If any list element is not a ManifestEntry.
	 */
	public function __construct( array $entries ) {
		foreach ( $entries as $index => $entry ) {
			if ( ! $entry instanceof ManifestEntry ) {
				throw new InvalidArgumentException(
					sprintf(
						'ArchiveManifest: entry at index %d is not a ManifestEntry instance.',
						(int) $index
					)
				);
			}
		}

		// Reindex to ensure a 0-based sequential list regardless of input keys.
		$this->entries = array_values( $entries );
	}

	/**
	 * Return the ordered list of manifest entries.
	 *
	 * @return array<int, ManifestEntry> The entries in canonical order.
	 */
	public function entries(): array {
		return $this->entries;
	}

	/**
	 * Return the number of entries in the manifest.
	 *
	 * @return int The non-negative entry count.
	 */
	public function entry_count(): int {
		return count( $this->entries );
	}

	/**
	 * Serialise the manifest to its on-disk representation.
	 *
	 * Builds the JSON payload in canonical form, computes its
	 * SHA-256 hash, and concatenates length (4 BE) + hash (32) +
	 * payload.
	 *
	 * @return string Exactly HEADER_SIZE + N bytes, where N is the JSON payload length.
	 * @throws JsonException If JSON encoding fails.
	 */
	public function to_bytes(): string {
		$payload = $this->encode_canonical_json();
		$hash    = Sha256::of( $payload );

		return ByteOrder::pack_uint32( strlen( $payload ) ) . $hash . $payload;
	}

	/**
	 * Parse on-disk bytes into an ArchiveManifest value object.
	 *
	 * Verifies the payload size against the length prefix, rejects
	 * declared sizes above MAX_PAYLOAD_SIZE, verifies the SHA-256
	 * hash with a constant-time compare, and decodes the JSON into
	 * a list of ManifestEntry instances.
	 *
	 * @param string $bytes On-disk bytes representing a manifest block.
	 * @return self An ArchiveManifest reflecting the parsed bytes.
	 * @throws InvalidArgumentException If the bytes are too short, too long, oversize, hash-mismatched, malformed, or contain invalid entries.
	 */
	public static function from_bytes( string $bytes ): self {
		if ( strlen( $bytes ) < self::HEADER_SIZE ) {
			throw new InvalidArgumentException(
				sprintf(
					'ArchiveManifest::from_bytes: input must be at least %d bytes, got %d.',
					(int) self::HEADER_SIZE,
					(int) strlen( $bytes )
				)
			);
		}

		$length = ByteOrder::unpack_uint32( substr( $bytes, 0, self::LENGTH_PREFIX_SIZE ) );

		if ( $length > self::MAX_PAYLOAD_SIZE ) {
			throw new InvalidArgumentException(
				sprintf(
					'ArchiveManifest::from_bytes: declared payload size %d exceeds maximum %d bytes.',
					(int) $length,
					(int) self::MAX_PAYLOAD_SIZE
				)
			);
		}

		$expected_total = self::HEADER_SIZE + $length;
		if ( strlen( $bytes ) !== $expected_total ) {
			throw new InvalidArgumentException(
				sprintf(
					'ArchiveManifest::from_bytes: expected exactly %d bytes (4 length + 32 hash + %d payload), got %d.',
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
				'ArchiveManifest::from_bytes: payload hash does not match stored hash; the block is corrupt or has been tampered with.'
			);
		}

		return self::decode_canonical_json( $payload );
	}

	/**
	 * Encode this ArchiveManifest to a canonical JSON byte string.
	 *
	 * Top-level shape is {"entries": [...]}, where each entry is the
	 * canonical data of a ManifestEntry. Field order within each
	 * entry is fixed by ManifestEntry::to_canonical_data.
	 *
	 * @return string A canonical JSON byte string in UTF-8.
	 * @throws JsonException If encoding fails.
	 */
	private function encode_canonical_json(): string {
		$entries_data = array();
		foreach ( $this->entries as $entry ) {
			$entries_data[] = $entry->to_canonical_data();
		}

		$data = array( 'entries' => $entries_data );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Deterministic byte output required for hash stability; wp_json_encode wraps json_encode without adding anything needed here, and depends on WordPress being loaded.
		return json_encode( $data, self::JSON_ENCODE_FLAGS );
	}

	/**
	 * Decode a JSON payload into an ArchiveManifest value object.
	 *
	 * Validates the top-level structure, then constructs a
	 * ManifestEntry for each element of the entries array. The
	 * ManifestEntry constructor and from_canonical_data perform
	 * field-level validation.
	 *
	 * @param string $json The JSON payload bytes as read from disk.
	 * @return self An ArchiveManifest reflecting the decoded data.
	 * @throws InvalidArgumentException If the JSON is malformed, missing fields, or contains invalid entries.
	 */
	private static function decode_canonical_json( string $json ): self {
		try {
			$data = json_decode( $json, true, self::JSON_MAX_DEPTH, JSON_THROW_ON_ERROR );
		} catch ( JsonException $e ) {
			throw new InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message embedded for diagnostic context; not HTML output.
				'ArchiveManifest: JSON payload is malformed: ' . $e->getMessage()
			);
		}

		if ( ! is_array( $data ) ) {
			throw new InvalidArgumentException( 'ArchiveManifest: JSON payload must decode to an object.' );
		}

		if ( ! array_key_exists( 'entries', $data ) ) {
			throw new InvalidArgumentException( 'ArchiveManifest: JSON payload is missing required field "entries".' );
		}

		if ( ! is_array( $data['entries'] ) ) {
			throw new InvalidArgumentException( 'ArchiveManifest: field "entries" must be an array.' );
		}

		$entries = array();
		foreach ( $data['entries'] as $index => $entry_data ) {
			if ( ! is_array( $entry_data ) ) {
				throw new InvalidArgumentException(
					sprintf(
						'ArchiveManifest: entry at index %d must be an object.',
						(int) $index
					)
				);
			}
			$entries[] = ManifestEntry::from_canonical_data( $entry_data );
		}

		return new self( $entries );
	}
}
