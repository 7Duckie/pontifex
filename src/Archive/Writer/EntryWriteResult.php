<?php
/**
 * Pontifex archive entry write result — the outputs EntryWriter returns.
 *
 * @package Pontifex\Archive\Writer
 */

declare(strict_types=1);

namespace Pontifex\Archive\Writer;

use InvalidArgumentException;
use Pontifex\Archive\Integrity\Sha256;

/**
 * Immutable value object holding the result of writing one archive entry.
 *
 * Carries the three pieces of information that the caller (typically
 * ArchiveWriter) needs once an entry has been written to disk:
 *
 *  - payload_length     — byte count of the encoded payload as written
 *                         to disk (after the codec ran). Used by the
 *                         caller when it needs to know how much of the
 *                         entry record is payload vs framing.
 *  - total_entry_length — byte count of the entire entry record on
 *                         disk, including the length prefix, the
 *                         JSON header, codec_id, nonce, payload, and
 *                         the trailing hash. Used by the caller to
 *                         build a ManifestEntry with the correct
 *                         "length" field (per spec §9.1).
 *  - entry_hash         — the SHA-256 of the entry record (covering
 *                         header_length || header || codec_id ||
 *                         nonce || payload, per spec §6), as 32 raw
 *                         bytes. Identical to the value written at
 *                         the end of the entry on disk. The caller
 *                         puts this into the ManifestEntry's hash
 *                         field (hex-encoded).
 *
 * EntryWriter knows nothing about offsets or other entries; it just
 * writes one record and reports back. The caller composes these
 * results into the broader archive structure.
 */
final class EntryWriteResult {

	/**
	 * Byte count of the encoded payload on disk.
	 *
	 * @var int
	 */
	private int $payload_length;

	/**
	 * Total byte count of the entry record on disk.
	 *
	 * @var int
	 */
	private int $total_entry_length;

	/**
	 * SHA-256 of the entry record, as 32 raw bytes.
	 *
	 * @var string
	 */
	private string $entry_hash;

	/**
	 * Construct an EntryWriteResult with the three reported values.
	 *
	 * @param int    $payload_length     Encoded payload byte count (non-negative).
	 * @param int    $total_entry_length Total entry record byte count (non-negative).
	 * @param string $entry_hash         SHA-256 of the entry record (exactly Sha256::DIGEST_SIZE bytes).
	 * @throws InvalidArgumentException If any argument is out of range or the wrong length.
	 */
	public function __construct( int $payload_length, int $total_entry_length, string $entry_hash ) {
		if ( $payload_length < 0 ) {
			throw new InvalidArgumentException(
				sprintf( 'EntryWriteResult: payload_length %d must be non-negative.', (int) $payload_length )
			);
		}
		if ( $total_entry_length < 0 ) {
			throw new InvalidArgumentException(
				sprintf( 'EntryWriteResult: total_entry_length %d must be non-negative.', (int) $total_entry_length )
			);
		}
		if ( Sha256::DIGEST_SIZE !== strlen( $entry_hash ) ) {
			throw new InvalidArgumentException(
				sprintf(
					'EntryWriteResult: entry_hash must be exactly %d bytes (one SHA-256 digest), got %d.',
					(int) Sha256::DIGEST_SIZE,
					(int) strlen( $entry_hash )
				)
			);
		}

		$this->payload_length     = $payload_length;
		$this->total_entry_length = $total_entry_length;
		$this->entry_hash         = $entry_hash;
	}

	/**
	 * Return the encoded payload byte count.
	 *
	 * @return int The non-negative payload byte count.
	 */
	public function payload_length(): int {
		return $this->payload_length;
	}

	/**
	 * Return the total entry record byte count on disk.
	 *
	 * @return int The non-negative total entry record byte count.
	 */
	public function total_entry_length(): int {
		return $this->total_entry_length;
	}

	/**
	 * Return the entry SHA-256 hash as 32 raw bytes.
	 *
	 * @return string A 32-byte binary string.
	 */
	public function entry_hash(): string {
		return $this->entry_hash;
	}
}
