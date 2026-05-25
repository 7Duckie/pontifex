<?php
/**
 * Pontifex archive entry plan — describes one entry queued for writing.
 *
 * @package Pontifex\Archive\Writer
 */

declare(strict_types=1);

namespace Pontifex\Archive\Writer;

use InvalidArgumentException;
use Pontifex\Archive\Format\EntryHeader;

/**
 * Immutable description of one entry to be written by ArchiveWriter.
 *
 * Bundles the four pieces of information ArchiveWriter needs per
 * entry, so the public API can take a typed array instead of nested
 * raw arrays:
 *
 *  - header   — the draft EntryHeader for this entry. size_compressed
 *               will be corrected by EntryWriter once the codec runs;
 *               typical practice is to pass it as 0 here.
 *  - codec_id — which codec to encode the payload with.
 *  - nonce    — per-entry nonce; exactly EntryWriter::NONCE_SIZE
 *               bytes. For v0.1.0 unencrypted archives this is the
 *               12-byte zero string.
 *  - source   — readable stream resource the payload is read from.
 *               The caller owns the resource and its initial seek
 *               position; ArchiveWriter reads from current position
 *               to EOF.
 *
 * Codec-id validation is deferred to EntryWriter, which is the
 * component that knows which codecs are registered. EntryPlan only
 * checks what it can check on its own: nonce length, source
 * resource-ness.
 *
 * Threading and reuse: instances are immutable. Note that the source
 * stream itself is mutable state held by reference; calling
 * ArchiveWriter::write_archive() consumes the source's contents from
 * its current position. An EntryPlan whose source has already been
 * consumed is not safely reusable for a second write.
 */
final class EntryPlan {

	/**
	 * Draft EntryHeader for this entry.
	 *
	 * @var EntryHeader
	 */
	private EntryHeader $header;

	/**
	 * Codec id to encode the payload with.
	 *
	 * @var int
	 */
	private int $codec_id;

	/**
	 * Per-entry nonce; exactly NONCE_SIZE bytes.
	 *
	 * @var string
	 */
	private string $nonce;

	/**
	 * Readable stream resource for the payload.
	 *
	 * @var resource
	 */
	private $source;

	/**
	 * Construct an EntryPlan for one entry.
	 *
	 * @param EntryHeader $header   Draft EntryHeader (size_compressed may be 0).
	 * @param int         $codec_id Codec id to encode the payload with.
	 * @param string      $nonce    Per-entry nonce; exactly EntryWriter::NONCE_SIZE bytes.
	 * @param resource    $source   Readable stream resource. Read from current position to EOF.
	 * @throws InvalidArgumentException If nonce is the wrong length or source is not a resource.
	 */
	public function __construct( EntryHeader $header, int $codec_id, string $nonce, $source ) {
		if ( EntryWriter::NONCE_SIZE !== strlen( $nonce ) ) {
			throw new InvalidArgumentException(
				sprintf(
					'EntryPlan: nonce must be exactly %d bytes, got %d.',
					(int) EntryWriter::NONCE_SIZE,
					(int) strlen( $nonce )
				)
			);
		}
		if ( ! is_resource( $source ) ) {
			throw new InvalidArgumentException( 'EntryPlan: $source must be a valid stream resource.' );
		}

		$this->header   = $header;
		$this->codec_id = $codec_id;
		$this->nonce    = $nonce;
		$this->source   = $source;
	}

	/**
	 * Return the draft EntryHeader.
	 *
	 * @return EntryHeader The header that will be written for this entry.
	 */
	public function header(): EntryHeader {
		return $this->header;
	}

	/**
	 * Return the codec id.
	 *
	 * @return int The codec id used to encode this entry's payload.
	 */
	public function codec_id(): int {
		return $this->codec_id;
	}

	/**
	 * Return the per-entry nonce as raw bytes.
	 *
	 * @return string A NONCE_SIZE-byte binary string.
	 */
	public function nonce(): string {
		return $this->nonce;
	}

	/**
	 * Return the source stream resource.
	 *
	 * @return resource The readable stream for the payload.
	 */
	public function source() {
		return $this->source;
	}
}
