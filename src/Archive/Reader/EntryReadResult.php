<?php
/**
 * Pontifex entry read result — the parsed header and decoded payload from one entry.
 *
 * @package Pontifex\Archive\Reader
 */

declare(strict_types=1);

namespace Pontifex\Archive\Reader;

use RuntimeException;
use Pontifex\Archive\Format\EntryHeader;

/**
 * Immutable result of reading one entry from a Pontifex archive.
 *
 * Returned by {@see EntryReader::read_entry()}. Bundles the parsed
 * JSON header (describing the entry's kind, path, size, etc.) with
 * the decoded payload, which comes in one of two shapes (ADR 0010):
 *
 *  - a **string** — db_chunk SQL, directory/symlink placeholders,
 *    and encrypted entries (which PHP's one-shot AEAD must buffer);
 *  - a **stream** — a plain file entry's contents, spooled so a
 *    large file never occupies payload-sized memory. The stream is
 *    positioned at its start and is owned by the result's consumer.
 *
 * Asking a streamed result for the string payload (or vice versa)
 * fails loudly rather than silently materialising gigabytes: the
 * shape is part of the contract, and {@see self::is_streamed()}
 * tells a consumer which accessor is live.
 */
final class EntryReadResult {

	/**
	 * The parsed entry header.
	 *
	 * @var EntryHeader
	 */
	private EntryHeader $header;

	/**
	 * The decoded payload bytes, or null when this result carries a stream.
	 *
	 * For db_chunk entries, the SQL bytes. For directory and symlink
	 * entries, an empty string (those carry no payload). For encrypted
	 * entries of any kind, the decrypted, decompressed bytes.
	 *
	 * @var string|null
	 */
	private ?string $payload;

	/**
	 * The decoded payload stream, or null when this result carries a string.
	 *
	 * @var resource|null
	 */
	private $payload_stream;

	/**
	 * The decoded payload size in bytes, whichever shape carries it.
	 *
	 * @var int
	 */
	private int $decoded_size;

	/**
	 * Construct a string-shaped EntryReadResult.
	 *
	 * @param EntryHeader $header  The parsed entry header.
	 * @param string      $payload The decoded payload bytes.
	 */
	public function __construct( EntryHeader $header, string $payload ) {
		$this->header         = $header;
		$this->payload        = $payload;
		$this->payload_stream = null;
		$this->decoded_size   = strlen( $payload );
	}

	/**
	 * Construct a stream-shaped EntryReadResult.
	 *
	 * @param EntryHeader $header         The parsed entry header.
	 * @param resource    $payload_stream The decoded payload, rewound to its start.
	 * @param int         $decoded_size   The decoded payload's size in bytes.
	 * @return self A result whose payload is read through {@see self::payload_stream()}.
	 */
	public static function for_stream( EntryHeader $header, $payload_stream, int $decoded_size ): self {
		$result                 = new self( $header, '' );
		$result->payload        = null;
		$result->payload_stream = $payload_stream;
		$result->decoded_size   = $decoded_size;
		return $result;
	}

	/**
	 * Return the parsed entry header.
	 *
	 * @return EntryHeader The header that described this entry.
	 */
	public function header(): EntryHeader {
		return $this->header;
	}

	/**
	 * Whether the payload is carried as a stream rather than a string.
	 *
	 * @return bool True when {@see self::payload_stream()} is the live accessor.
	 */
	public function is_streamed(): bool {
		return null !== $this->payload_stream;
	}

	/**
	 * Return the decoded payload bytes.
	 *
	 * @return string The payload after the codec has run in reverse.
	 * @throws RuntimeException If this result carries a stream — materialising it into a string would defeat the streaming the shape exists for.
	 */
	public function payload(): string {
		if ( null === $this->payload ) {
			throw new RuntimeException( 'EntryReadResult: this entry\'s payload is a stream; read it through payload_stream() instead of materialising it.' );
		}
		return $this->payload;
	}

	/**
	 * Return the decoded payload stream, positioned at its start.
	 *
	 * @return resource The payload stream; the consumer owns and closes it.
	 * @throws RuntimeException If this result carries a string payload.
	 */
	public function payload_stream() {
		if ( null === $this->payload_stream ) {
			throw new RuntimeException( 'EntryReadResult: this entry\'s payload is a string; read it through payload().' );
		}
		return $this->payload_stream;
	}

	/**
	 * Return the decoded payload size in bytes, whichever shape carries it.
	 *
	 * The figure the restore walk charges against its archive-total decoded
	 * budget; for a streamed payload it is the byte count the codec reported
	 * while decoding.
	 *
	 * @return int Decoded bytes.
	 */
	public function decoded_size(): int {
		return $this->decoded_size;
	}
}
