<?php
/**
 * Pontifex archive manifest entry — one record in the manifest listing.
 *
 * @package Pontifex\Archive\Format
 */

declare(strict_types=1);

namespace Pontifex\Archive\Format;

use InvalidArgumentException;
use Pontifex\Archive\Integrity\Sha256;

/**
 * Immutable value object representing one entry in the archive manifest.
 *
 * A ManifestEntry bundles together everything a reader needs to find
 * one entry in the archive, decode its payload, and verify it:
 *
 *  - offset          — byte offset where the entry record begins
 *  - entry_header    — the EntryHeader (kind + kind-specific metadata)
 *  - codec_id        — which codec encoded the payload
 *  - payload_length  — byte length of the encoded payload
 *  - payload_hash    — SHA-256 of the encoded payload, 32 raw bytes
 *
 * The payload_hash is stored as 32 raw bytes in PHP (matching
 * Footer::manifest_hash()), but serialised as 64 lowercase hex
 * characters in JSON since JSON cannot carry binary data directly.
 *
 * The codec_id is constrained to the uint16 range (0 to 65535)
 * because the on-disk codec_id field is a single big-endian uint16.
 */
final class ManifestEntry {

	/**
	 * Maximum codec_id value (matches the on-disk uint16 range).
	 *
	 * @var int
	 */
	public const MAX_CODEC_ID = 0xFFFF;

	/**
	 * Expected hex-string length of a serialised payload_hash (64 = 2 * SHA-256 size).
	 *
	 * @var int
	 */
	public const HASH_HEX_LENGTH = 64;

	/**
	 * Byte offset where the entry record begins in the archive.
	 *
	 * @var int
	 */
	private int $offset;

	/**
	 * EntryHeader for this manifest entry.
	 *
	 * @var EntryHeader
	 */
	private EntryHeader $entry_header;

	/**
	 * Codec id used to encode the payload (0 to MAX_CODEC_ID inclusive).
	 *
	 * @var int
	 */
	private int $codec_id;

	/**
	 * Byte length of the encoded payload on disk.
	 *
	 * @var int
	 */
	private int $payload_length;

	/**
	 * SHA-256 of the encoded payload, as 32 raw bytes.
	 *
	 * @var string
	 */
	private string $payload_hash;

	/**
	 * Construct a ManifestEntry with all five fields.
	 *
	 * @param int         $offset         Byte offset of the entry in the archive (non-negative).
	 * @param EntryHeader $entry_header   The entry's metadata header.
	 * @param int         $codec_id       Codec id (0 to MAX_CODEC_ID inclusive).
	 * @param int         $payload_length Encoded payload byte length (non-negative).
	 * @param string      $payload_hash   SHA-256 of the payload (exactly Sha256::DIGEST_SIZE bytes).
	 * @throws InvalidArgumentException If any argument is out of range or the wrong length.
	 */
	public function __construct(
		int $offset,
		EntryHeader $entry_header,
		int $codec_id,
		int $payload_length,
		string $payload_hash
	) {
		if ( $offset < 0 ) {
			throw new InvalidArgumentException(
				sprintf( 'ManifestEntry: offset %d must be non-negative.', (int) $offset )
			);
		}
		if ( $codec_id < 0 || $codec_id > self::MAX_CODEC_ID ) {
			throw new InvalidArgumentException(
				sprintf( 'ManifestEntry: codec_id %d is outside the uint16 range (0 to 65535).', (int) $codec_id )
			);
		}
		if ( $payload_length < 0 ) {
			throw new InvalidArgumentException(
				sprintf( 'ManifestEntry: payload_length %d must be non-negative.', (int) $payload_length )
			);
		}
		if ( strlen( $payload_hash ) !== Sha256::DIGEST_SIZE ) {
			throw new InvalidArgumentException(
				sprintf(
					'ManifestEntry: payload_hash must be exactly %d bytes (one SHA-256 digest), got %d.',
					(int) Sha256::DIGEST_SIZE,
					(int) strlen( $payload_hash )
				)
			);
		}

		$this->offset         = $offset;
		$this->entry_header   = $entry_header;
		$this->codec_id       = $codec_id;
		$this->payload_length = $payload_length;
		$this->payload_hash   = $payload_hash;
	}

	/**
	 * Return the byte offset of this entry in the archive.
	 *
	 * @return int The non-negative offset.
	 */
	public function offset(): int {
		return $this->offset;
	}

	/**
	 * Return the entry's metadata header.
	 *
	 * @return EntryHeader The EntryHeader value object.
	 */
	public function entry_header(): EntryHeader {
		return $this->entry_header;
	}

	/**
	 * Return the codec id used to encode the payload.
	 *
	 * @return int The codec id, in the range 0 to MAX_CODEC_ID.
	 */
	public function codec_id(): int {
		return $this->codec_id;
	}

	/**
	 * Return the encoded payload byte length.
	 *
	 * @return int The non-negative byte length.
	 */
	public function payload_length(): int {
		return $this->payload_length;
	}

	/**
	 * Return the payload SHA-256 hash as 32 raw bytes.
	 *
	 * @return string A 32-byte binary string.
	 */
	public function payload_hash(): string {
		return $this->payload_hash;
	}

	/**
	 * Return the canonical data array representation of this ManifestEntry.
	 *
	 * Field order: offset, entry, codec_id, payload_length,
	 * payload_hash. The payload_hash is encoded as a 64-character
	 * lowercase hex string. The entry sub-object is the nested
	 * canonical data of the EntryHeader.
	 *
	 * @return array<string, mixed> The canonical data array.
	 */
	public function to_canonical_data(): array {
		return array(
			'offset'         => $this->offset,
			'entry'          => $this->entry_header->to_canonical_data(),
			'codec_id'       => $this->codec_id,
			'payload_length' => $this->payload_length,
			'payload_hash'   => bin2hex( $this->payload_hash ),
		);
	}

	/**
	 * Build a ManifestEntry from a decoded canonical data array.
	 *
	 * Validates that all five fields are present and well-typed,
	 * decodes the hex-encoded payload_hash, and builds the nested
	 * EntryHeader from its sub-object.
	 *
	 * @param array<string, mixed> $data The decoded data array.
	 * @return self A ManifestEntry reflecting the data.
	 * @throws InvalidArgumentException If any field is missing, mis-typed, or invalid.
	 */
	public static function from_canonical_data( array $data ): self {
		foreach ( array( 'offset', 'codec_id', 'payload_length' ) as $field ) {
			if ( ! array_key_exists( $field, $data ) ) {
				throw new InvalidArgumentException(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $field is a hardcoded literal; exception message, not HTML output.
					sprintf( 'ManifestEntry: data is missing required field "%s".', $field )
				);
			}
			if ( ! is_int( $data[ $field ] ) ) {
				throw new InvalidArgumentException(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $field is a hardcoded literal; exception message, not HTML output.
					sprintf( 'ManifestEntry: field "%s" must be an integer.', $field )
				);
			}
		}

		if ( ! array_key_exists( 'entry', $data ) ) {
			throw new InvalidArgumentException( 'ManifestEntry: data is missing required field "entry".' );
		}
		if ( ! is_array( $data['entry'] ) ) {
			throw new InvalidArgumentException( 'ManifestEntry: field "entry" must be an object.' );
		}

		if ( ! array_key_exists( 'payload_hash', $data ) ) {
			throw new InvalidArgumentException( 'ManifestEntry: data is missing required field "payload_hash".' );
		}
		if ( ! is_string( $data['payload_hash'] ) ) {
			throw new InvalidArgumentException( 'ManifestEntry: field "payload_hash" must be a string.' );
		}
		if ( 1 !== preg_match( '/^[0-9a-f]{' . self::HASH_HEX_LENGTH . '}$/', $data['payload_hash'] ) ) {
			throw new InvalidArgumentException(
				sprintf(
					'ManifestEntry: payload_hash must be exactly %d lowercase hex characters.',
					(int) self::HASH_HEX_LENGTH
				)
			);
		}

		$entry_header = EntryHeader::from_canonical_data( $data['entry'] );
		$payload_hash = hex2bin( $data['payload_hash'] );

		// hex2bin returns false on failure, but the regex above guarantees success here.
		if ( false === $payload_hash ) {
			throw new InvalidArgumentException( 'ManifestEntry: payload_hash failed to decode despite passing validation.' );
		}

		return new self(
			$data['offset'],
			$entry_header,
			$data['codec_id'],
			$data['payload_length'],
			$payload_hash
		);
	}
}
