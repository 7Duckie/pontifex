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
	 * The size the header declared before the write, when the writer corrected it.
	 *
	 * Null when the entry's content matched its declared size (the usual case)
	 * or the entry kind carries no size. Non-null only when the source file
	 * changed between scan and write, so the writer recorded the actual byte
	 * count instead — this field preserves what the scan had claimed, so the
	 * caller can tell the user exactly what changed.
	 *
	 * @var int|null
	 */
	private ?int $declared_size;

	/**
	 * The byte count actually read and captured, when the writer corrected the size.
	 *
	 * Null exactly when $declared_size is null; the two travel together.
	 *
	 * @var int|null
	 */
	private ?int $actual_size;

	/**
	 * Construct an EntryWriteResult with the reported values.
	 *
	 * @param int      $payload_length     Encoded payload byte count (non-negative).
	 * @param int      $total_entry_length Total entry record byte count (non-negative).
	 * @param string   $entry_hash         SHA-256 of the entry record (exactly Sha256::DIGEST_SIZE bytes).
	 * @param int|null $declared_size      The size the header declared before the writer corrected it, or null when no correction happened.
	 * @param int|null $actual_size        The byte count actually captured, or null when no correction happened. Must be null exactly when $declared_size is null.
	 * @throws InvalidArgumentException If any argument is out of range, the wrong length, or the two correction fields do not travel together.
	 */
	public function __construct( int $payload_length, int $total_entry_length, string $entry_hash, ?int $declared_size = null, ?int $actual_size = null ) {
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

		if ( ( null === $declared_size ) !== ( null === $actual_size ) ) {
			throw new InvalidArgumentException( 'EntryWriteResult: declared_size and actual_size must both be null or both be set.' );
		}
		if ( null !== $declared_size && ( $declared_size < 0 || $actual_size < 0 ) ) {
			throw new InvalidArgumentException( 'EntryWriteResult: declared_size and actual_size must be non-negative.' );
		}

		$this->payload_length     = $payload_length;
		$this->total_entry_length = $total_entry_length;
		$this->entry_hash         = $entry_hash;
		$this->declared_size      = $declared_size;
		$this->actual_size        = $actual_size;
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

	/**
	 * Whether the writer corrected the entry's declared size at write time.
	 *
	 * True means the source file's content did not match the size its header
	 * declared — it changed between scan and write — and the written header
	 * records the actual captured byte count instead.
	 *
	 * @return bool True when a size correction happened.
	 */
	public function size_was_corrected(): bool {
		return null !== $this->declared_size;
	}

	/**
	 * Return the size the header declared before the correction.
	 *
	 * @return int|null The scan-time size claim, or null when no correction happened.
	 */
	public function declared_size(): ?int {
		return $this->declared_size;
	}

	/**
	 * Return the byte count actually read and captured.
	 *
	 * @return int|null The captured byte count, or null when no correction happened.
	 */
	public function actual_size(): ?int {
		return $this->actual_size;
	}
}
