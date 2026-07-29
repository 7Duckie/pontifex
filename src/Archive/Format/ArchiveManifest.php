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
	 * {@see self::project_payload_bytes()} uses this constant as a starting
	 * baseline only, not as the final per-entry charge: it layers measured (not
	 * assumed) terms on top — the real JSON-encoded path length and real kind
	 * width for path-bearing entries, the real chunk_index digit width for
	 * db_chunk entries — plus a provable margin, shared by every kind, for the
	 * entry separator and the four numeric fields no EntryHeader carries. That
	 * is what makes the method's own guarantee hold regardless of path content,
	 * entry kind, or archive size.
	 *
	 * Note that the floor quoted above (145 bytes) is a `file` entry's. The
	 * other kinds' floors are higher, purely because their kind strings are
	 * longer: `symlink` 148, `directory` 150, `db_chunk` 154. This constant sits
	 * above all four, but the difference is not uniform, which is exactly why
	 * project_payload_bytes() charges the kind width rather than leaning on the
	 * cushion to absorb it.
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
	 * JSON-encoded byte length of the shortest possible path value: the two
	 * quote characters plus one unescaped character, e.g. `"a"`.
	 *
	 * MIN_ENTRY_PAYLOAD_BYTES already assumes exactly this text cost for a
	 * path-bearing entry's path field (see its own docblock: "a 1-character
	 * path"). {@see self::project_payload_bytes()} swaps that assumption for
	 * a path's real canonical-JSON-encoded length (quotes and any escaping
	 * included), so it must subtract this constant — not the raw 1-character
	 * count — to avoid double-charging the two quote bytes that were already
	 * folded into MIN_ENTRY_PAYLOAD_BYTES.
	 *
	 * @var int
	 */
	private const MINIMAL_PATH_JSON_BYTES = 3;

	/**
	 * Decimal digit width of PHP_INT_MAX (9,223,372,036,854,775,807) on the
	 * 64-bit builds this project targets.
	 *
	 * Used by {@see self::project_payload_bytes()} as a provable — not
	 * heuristic — upper bound on how many digits a manifest numeric field can
	 * ever contribute once JSON-encoded: no int PHP can hold encodes to more
	 * digits than this, regardless of how large a real archive grows.
	 *
	 * @var int
	 */
	private const MAX_INT_DIGITS = 19;

	/**
	 * Byte cost of the single `,` that separates one entry from the next inside
	 * the manifest's `entries` array.
	 *
	 * ENTRIES_JSON_WRAPPER_BYTES accounts for the array's brackets, which occur
	 * once; the separators occur once per entry beyond the first, and nothing
	 * else in this projection charges for them. {@see self::project_payload_bytes()}
	 * therefore charges this for EVERY entry — one byte more than the N-1
	 * separators a real manifest carries. That deliberate one-byte-per-manifest
	 * over-charge keeps the per-entry arithmetic uniform (no special case for
	 * the first entry) and errs in the only safe direction.
	 *
	 * @var int
	 */
	private const ENTRY_SEPARATOR_BYTES = 1;

	/**
	 * Decimal digit width of {@see ManifestEntry::MAX_CODEC_ID} (0xFFFF = 65535).
	 *
	 * The codec_id field is chosen at write time (it travels on an EntryPlan,
	 * not an EntryHeader), so this projection cannot see an entry's codec. It is
	 * bounded, though: ManifestEntry rejects any codec_id above MAX_CODEC_ID,
	 * so no entry that can exist in a valid manifest prints more digits than
	 * this — a far tighter bound than MAX_INT_DIGITS, and just as provable.
	 * Pinned against ManifestEntry::MAX_CODEC_ID by
	 * {@see \Pontifex\Tests\Unit\Archive\Format\ArchiveManifestTest} so it
	 * cannot drift if that ceiling ever moves.
	 *
	 * @var int
	 */
	private const MAX_CODEC_ID_DIGITS = 5;

	/**
	 * Provable upper bound on the digit width of a manifest entry's `index`.
	 *
	 * The index field is assigned only once an entry is appended, so no
	 * EntryHeader carries it — but unlike offset and length it needs no blunt
	 * MAX_INT_DIGITS margin, because the format's own ceiling bounds it:
	 * {@see self::project_payload_bytes()} charges every entry at least
	 * MIN_ENTRY_PAYLOAD_BYTES, so whenever a caller's guard approves an export
	 * (projection at or below MAX_PAYLOAD_SIZE) the entry count cannot exceed
	 * (MAX_PAYLOAD_SIZE - HEADER_SIZE - ENTRIES_JSON_WRAPPER_BYTES) /
	 * MIN_ENTRY_PAYLOAD_BYTES = 99,273 — a largest index of 99,272, five
	 * digits. An export the guard REFUSES is never written, so its indices
	 * never exist to be under-counted; the bound is therefore not circular.
	 *
	 * Deliberately a bound and not the loop position: charging the real
	 * position would be a byte or two cheaper, but it would silently make this
	 * method's guarantee depend on every caller passing the complete entry list
	 * in write order. A caller that projected a subset — a resumed export's
	 * remaining entries, say — would then under-count with no failing test to
	 * show for it. Pinned by
	 * {@see \Pontifex\Tests\Unit\Archive\Format\ArchiveManifestTest}.
	 *
	 * @var int
	 */
	private const MAX_INDEX_DIGITS = 5;

	/**
	 * Count of a manifest entry's numeric fields that this projection can
	 * neither see nor bound below PHP's own integer ceiling: offset and length.
	 *
	 * Neither lives on EntryHeader — both are known only once the entry's real
	 * encoded size and its position in the finished archive exist — and neither
	 * has a format-level maximum the way index and codec_id do, because both
	 * scale with the archive's total byte size. Each is therefore charged the
	 * full MAX_INT_DIGITS margin.
	 *
	 * @var int
	 */
	private const UNBOUNDED_NUMERIC_FIELDS = 2;

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
	 * symlink entries, the chunk_index for db_chunk entries, which carry no
	 * path) rather than from entry count alone, because the byte cost moves
	 * with both path depth and how many digits an entry's numeric fields
	 * print as: a deep-path archive fills MAX_PAYLOAD_SIZE at far fewer
	 * entries than a flat-path one, and a large archive's own offsets need
	 * more digits than a small one's.
	 *
	 * Every path-bearing entry is charged (MIN_ENTRY_PAYLOAD_BYTES -
	 * MINIMAL_PATH_JSON_BYTES) bytes of fixed overhead plus the path's own
	 * canonical-JSON-encoded length, quotes included — MINIMAL_PATH_JSON_BYTES
	 * is exactly what MIN_ENTRY_PAYLOAD_BYTES already assumes for a
	 * 1-character path (`"a"`), so the subtraction swaps that assumption for
	 * the path's real encoded byte count, measured with
	 * {@see self::JSON_ENCODE_FLAGS} (the same flags the manifest itself
	 * encodes with) rather than approximated with strlen() on the raw path.
	 * The two differ whenever the path contains a byte JSON must escape — a
	 * `"`, a `\`, a control character, or a U+2028/U+2029 line separator are
	 * all legal POSIX filename bytes that cost more than one byte once
	 * escaped, so measuring the encoded form (not the raw one) is what makes
	 * this exact rather than approximate.
	 *
	 * A path-bearing entry's `kind` is charged exactly too. MIN_ENTRY_PAYLOAD_BYTES
	 * assumes the shortest kind string, `file`; `symlink` costs three bytes more
	 * and `directory` five, so the difference against KIND_FILE is added back
	 * rather than left to be absorbed by the constant's cushion.
	 *
	 * A db_chunk entry carries no path. Its chunk_index is known from the
	 * header, so — the same swap-the-assumed-digit approach as the path
	 * term — its real digit width is charged exactly in place of the single
	 * digit MIN_ENTRY_PAYLOAD_BYTES assumes.
	 *
	 * Every entry of every kind then takes the same
	 * {@see self::unseen_numeric_margin_bytes()} charge for what no EntryHeader
	 * can carry: the entry separator, plus index, offset, length and codec_id.
	 * Each of those four is bounded provably rather than guessed — index by the
	 * format's own entry ceiling (MAX_INDEX_DIGITS), codec_id by
	 * ManifestEntry::MAX_CODEC_ID (MAX_CODEC_ID_DIGITS), and offset and length,
	 * which scale with archive size and so have no format-level maximum, by
	 * MAX_INT_DIGITS: the most digits any PHP int can ever print as. The
	 * projection therefore stays an over-estimate at any archive size, right up
	 * to PHP's own integer ceiling. The trade-off is a markedly higher flat cost
	 * per entry than the pre-fix estimate assumed, which lowers how many entries
	 * fit under MAX_PAYLOAD_SIZE; that is the honest ceiling, where the previous
	 * one permitted archives this installation's own reader would refuse.
	 *
	 * Lets a caller (the export engine) refuse an oversized backup BEFORE
	 * writing a single byte, rather than discovering only after a
	 * multi-hour export completes that the reader will refuse the result.
	 *
	 * @param iterable<int, EntryHeader> $entry_headers The archive's entry headers, in the order they will be written.
	 * @return int The projected manifest payload size in bytes. Proven never an under-estimate against real writer output: the path term measures the real JSON-encoded length instead of assuming no byte needs escaping, the kind and chunk_index terms are charged exactly, and every entry carries a provable worst-case margin for the separator and the four numeric fields an EntryHeader cannot carry — see {@see \Pontifex\Tests\Unit\Archive\Format\ArchiveManifestTest} for the empirical proof, including at PHP_INT_MAX for all four kinds.
	 * @throws JsonException If a path cannot be JSON-encoded (e.g. it contains invalid UTF-8 byte sequences) — the same failure the real manifest encoder would hit later, surfaced here instead, before a single byte has been written.
	 * @throws InvalidArgumentException If a db_chunk header is somehow missing its chunk_index (structurally unreachable: EntryHeader::for_db_chunk() always sets it).
	 */
	public static function project_payload_bytes( iterable $entry_headers ): int {
		// phpcs:enable Squiz.Commenting.FunctionComment.IncorrectTypeHint
		$total = self::HEADER_SIZE + self::ENTRIES_JSON_WRAPPER_BYTES;

		foreach ( $entry_headers as $header ) {
			$path = $header->path();

			if ( null !== $path ) {
				$total += self::MIN_ENTRY_PAYLOAD_BYTES
					- self::MINIMAL_PATH_JSON_BYTES
					+ strlen( self::encode_json_string( $path ) )
					+ ( strlen( $header->kind() ) - strlen( EntryHeader::KIND_FILE ) )
					+ self::unseen_numeric_margin_bytes();
				continue;
			}

			$chunk_index = self::require_db_chunk_index( $header );

			$total += self::MIN_ENTRY_PAYLOAD_BYTES
				+ ( strlen( (string) $chunk_index ) - 1 )
				+ self::unseen_numeric_margin_bytes();
		}

		return $total;
	}

	/**
	 * Worst-case bytes an entry's manifest-only fields can add beyond what
	 * MIN_ENTRY_PAYLOAD_BYTES already assumes for them.
	 *
	 * Shared by every kind, because every kind carries exactly these fields and
	 * an EntryHeader carries none of them. MIN_ENTRY_PAYLOAD_BYTES assumes a
	 * single digit for each of the four numeric fields, so each contributes its
	 * bound MINUS that already-assumed digit; the separator is additional to
	 * anything the constant assumes, so it contributes in full.
	 *
	 * Bounding index and codec_id at their real format maximums rather than at
	 * MAX_INT_DIGITS is what keeps this from needlessly shrinking the entry
	 * ceiling: the blunt margin would cost 28 more bytes on every entry of
	 * every archive, to defend against digit widths neither field can reach.
	 *
	 * @return int The per-entry margin in bytes; a proven upper bound, not an estimate.
	 */
	private static function unseen_numeric_margin_bytes(): int {
		return self::ENTRY_SEPARATOR_BYTES
			+ ( self::MAX_INDEX_DIGITS - 1 )
			+ ( self::MAX_CODEC_ID_DIGITS - 1 )
			+ self::UNBOUNDED_NUMERIC_FIELDS * ( self::MAX_INT_DIGITS - 1 );
	}

	/**
	 * Extract a db_chunk header's chunk_index, which is guaranteed non-null by construction.
	 *
	 * EntryHeader::for_db_chunk() takes chunk_index as a required, non-nullable
	 * constructor argument, so a null here is structurally unreachable for a
	 * genuine db_chunk header — this guards it explicitly anyway rather than
	 * silently trusting that invariant, and gives the caller a concrete int
	 * instead of the property's own nullable type.
	 *
	 * @param EntryHeader $header A db_chunk-kind header (one whose path() is null).
	 * @return int The chunk_index.
	 * @throws InvalidArgumentException If chunk_index is unexpectedly null.
	 */
	private static function require_db_chunk_index( EntryHeader $header ): int {
		$chunk_index = $header->chunk_index();
		if ( null === $chunk_index ) {
			throw new InvalidArgumentException( 'ArchiveManifest::project_payload_bytes: a database-chunk header must carry a chunk_index.' );
		}
		return $chunk_index;
	}

	/**
	 * Encode a single string value exactly as the manifest's own JSON encoder would.
	 *
	 * Used by {@see self::project_payload_bytes()} to measure a path's real
	 * encoded byte length (quotes and any escaping included) instead of
	 * assuming its raw strlen(). Kept as its own method so that measurement
	 * uses the identical flags {@see self::encode_canonical_json()} does,
	 * rather than a second, potentially-drifting copy of them.
	 *
	 * @param string $value The raw string value (e.g. a path) to encode.
	 * @return string The value's canonical JSON encoding, including its surrounding quotes.
	 * @throws JsonException If the value cannot be encoded (e.g. invalid UTF-8 byte sequences).
	 */
	private static function encode_json_string( string $value ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Deterministic byte output required to match the manifest's own encoder exactly; wp_json_encode wraps json_encode without adding anything needed here, and depends on WordPress being loaded.
		return json_encode( $value, self::JSON_ENCODE_FLAGS );
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
