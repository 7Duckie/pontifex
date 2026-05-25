<?php
/**
 * Pontifex entry read result — the parsed header and decoded payload from one entry.
 *
 * @package Pontifex\Archive\Reader
 */

declare(strict_types=1);

namespace Pontifex\Archive\Reader;

use Pontifex\Archive\Format\EntryHeader;

/**
 * Immutable result of reading one entry from a Pontifex archive.
 *
 * Returned by {@see EntryReader::read_entry()}. Bundles the two
 * things callers typically need together: the parsed JSON header
 * (describing the entry's kind, path, size, etc.) and the decoded
 * payload bytes (after the codec has run in reverse).
 *
 * Bundling them avoids forcing the caller to parse the header
 * separately or hold a side reference. Both pieces come from the
 * same on-disk entry record so they belong together.
 */
final class EntryReadResult {

	/**
	 * The parsed entry header.
	 *
	 * @var EntryHeader
	 */
	private EntryHeader $header;

	/**
	 * The decoded payload bytes.
	 *
	 * For file entries, this is the original file contents. For
	 * db_chunk entries, this is the SQL bytes. For directory and
	 * symlink entries, this is an empty string (those carry no
	 * payload in v0.1.0).
	 *
	 * @var string
	 */
	private string $payload;

	/**
	 * Construct an EntryReadResult.
	 *
	 * @param EntryHeader $header  The parsed entry header.
	 * @param string      $payload The decoded payload bytes.
	 */
	public function __construct( EntryHeader $header, string $payload ) {
		$this->header  = $header;
		$this->payload = $payload;
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
	 * Return the decoded payload bytes.
	 *
	 * @return string The payload after the codec has run in reverse.
	 */
	public function payload(): string {
		return $this->payload;
	}
}
