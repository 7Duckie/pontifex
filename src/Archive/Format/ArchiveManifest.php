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
 * defensive ceiling. 16 MiB is the format's own structural cap on entry
 * count regardless of any configured limit: at MIN_ENTRY_PAYLOAD_BYTES per
 * entry it holds at most 99,273 entries, and small enough to flag anything
 * wildly out of range as malformed or malicious.
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
	 * At MIN_ENTRY_PAYLOAD_BYTES per entry this holds at most 99,273
	 * entries — the format's own implicit entry-count cap, independent of
	 * whatever {@see \Pontifex\Archive\Reader\ArchiveLimits::max_entry_count()}
	 * is separately configured to. Anything larger is rejected as a
	 * defensive ceiling.
	 *
	 * @var int
	 */
	public const MAX_PAYLOAD_SIZE = 16777216;

	/**
	 * Conservative bytes-per-entry threshold used to (a) estimate an upper
	 * bound on entry count from a manifest's declared byte length alone,
	 * before it is read or decoded (the pre-decode guard in
	 * {@see \Pontifex\Archive\Reader\ArchiveReader}), and (b) project the
	 * manifest size a pending export would produce from the real entries
	 * about to be written, before a single byte reaches disk (see
	 * {@see self::project_payload_bytes()}).
	 *
	 * This is deliberately NOT the format's absolute theoretical floor.
	 * That floor is lower: a hand-forged, maximally degenerate entry (the
	 * shortest kind, "file", a 1-character path, every numeric field at 0)
	 * measures 145 bytes — proven by
	 * {@see \Pontifex\Tests\Unit\Archive\Format\ArchiveManifestTest}. Using
	 * that true floor here would falsely refuse a real, legitimate archive
	 * whose declared length approaches MAX_PAYLOAD_SIZE with many small
	 * entries (a database-chunk-heavy backup, say) — worse than the defect
	 * this constant defends against. 169 sits above realistic per-entry
	 * costs instead: a real archive's file entries measure roughly 158
	 * bytes at a short (24-character) flat path, rising with path depth
	 * (measured up to roughly 318 bytes at 160 characters), so genuine
	 * archives never trip this estimate.
	 *
	 * The accepted trade-off is a residual gap: a manifest hand-forged
	 * entirely from sub-169-byte entries can still slip past this cheap
	 * estimate. Closing that is not this constant's job — the existing
	 * post-parse entry-count check in {@see \Pontifex\Restore\RestoreRunner}
	 * is defence in depth for exactly that case, catching it once the (by
	 * then unavoidable) decode has already happened.
	 *
	 * @var int
	 */
	public const MIN_ENTRY_PAYLOAD_BYTES = 169;

	/**
	 * Fixed byte cost of the manifest's JSON array wrapper (`{"entries":[` + `]}`),
	 * present once per manifest regardless of entry count.
	 *
	 * Used only by {@see self::project_payload_bytes()} to keep its estimate
	 * honest about the one-time framing cost; negligible next to
	 * MIN_ENTRY_PAYLOAD_BYTES at any entry count this format permits, but
	 * omitting it would be an unexplained magic number in the projection.
	 *
	 * @var int
	 */
	private const ENTRIES_JSON_WRAPPER_BYTES = 14;

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

	// phpcs:disable Squiz.Commenting.FunctionComment.IncorrectTypeHint -- $entry_headers is documented as iterable<int, EntryHeader> because PHPStan level 6 requires the value type; this sniff cannot reduce an iterable<> generic to its base iterable hint the way it reduces array<> to array (matches the identical disable on ExportRunner::export()).
	/**
	 * Project the manifest payload size an export of these entries would produce.
	 *
	 * Computed from the entries' real identifiers (paths for file/directory/
	 * symlink entries, a flat estimate for db_chunk entries, which carry no
	 * path) rather than from entry count alone, because the byte cost moves
	 * with path depth: a deep-path archive fills MAX_PAYLOAD_SIZE at far
	 * fewer entries than a flat-path one. Every path-bearing entry is
	 * estimated at (MIN_ENTRY_PAYLOAD_BYTES - 1) bytes of fixed overhead plus
	 * its real path length — MIN_ENTRY_PAYLOAD_BYTES already assumes a
	 * 1-character path, so the "- 1" swaps that assumed character for the
	 * real one being added. A db_chunk entry (no path) is estimated flat at
	 * MIN_ENTRY_PAYLOAD_BYTES, comfortably above its real measured cost
	 * (roughly 155-165 bytes), keeping the estimate conservative rather than
	 * an under-count.
	 *
	 * Lets a caller (the export engine) refuse an oversized backup BEFORE
	 * writing a single byte, rather than discovering only after a
	 * multi-hour export completes that the reader will refuse the result.
	 *
	 * @param iterable<int, EntryHeader> $entry_headers The archive's entry headers, in the order they will be written.
	 * @return int The projected manifest payload size in bytes (never an under-estimate against real writer output, validated empirically).
	 */
	public static function project_payload_bytes( iterable $entry_headers ): int {
		// phpcs:enable Squiz.Commenting.FunctionComment.IncorrectTypeHint
		$total = self::HEADER_SIZE + self::ENTRIES_JSON_WRAPPER_BYTES;

		foreach ( $entry_headers as $header ) {
			$path   = $header->path();
			$total += null !== $path
				? ( self::MIN_ENTRY_PAYLOAD_BYTES - 1 + strlen( $path ) )
				: self::MIN_ENTRY_PAYLOAD_BYTES;
		}

		return $total;
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
