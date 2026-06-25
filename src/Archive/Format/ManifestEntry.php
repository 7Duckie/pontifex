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
 * Per ARCHIVE-FORMAT.md §9, a manifest entry carries only what a reader
 * needs to locate, verify, and identify an entry without parsing the
 * entry's on-disk header:
 *
 *  - index       — 0-based position in the archive's entry sequence
 *  - offset      — byte offset of the entry's first byte in the archive
 *  - length      — total entry record length on disk (header_length +
 *                  header_JSON + codec_id + nonce + payload + hash)
 *  - kind        — one of the EntryHeader::KIND_* constants
 *  - codec_id    — which codec encoded the payload
 *  - entry_hash  — SHA-256 of the entry record, 32 raw bytes
 *  - identifier  — kind-specific: path (file/directory/symlink) or
 *                  chunk_index (db_chunk)
 *
 * Detailed per-entry metadata (size_uncompressed, size_compressed,
 * mode, modified_at, media_type) lives in entry headers on disk, NOT
 * here. The manifest is a lightweight navigation index — a directory of
 * entries kept separate from the data they point to, the pattern common
 * to container and archive formats.
 *
 * Instances are constructed via the four static factories, one per
 * entry kind (for_file, for_db_chunk, for_directory, for_symlink).
 * Each factory ensures the identifier field matches the kind. The
 * private constructor enforces the common-field invariants.
 *
 * The entry_hash is stored as 32 raw bytes in PHP, but serialised as
 * a 64-character lowercase hex string in JSON under the key "hash"
 * (matching the spec example exactly).
 */
final class ManifestEntry {

	/**
	 * Maximum codec_id value (matches the on-disk uint16 range).
	 *
	 * @var int
	 */
	public const MAX_CODEC_ID = 0xFFFF;

	/**
	 * Expected hex-string length of a serialised entry_hash (64 = 2 * SHA-256 size).
	 *
	 * @var int
	 */
	public const HASH_HEX_LENGTH = 64;

	/**
	 * 0-based position of this entry within the archive's entry sequence.
	 *
	 * @var int
	 */
	private int $index;

	/**
	 * Byte offset where the entry record begins in the archive.
	 *
	 * @var int
	 */
	private int $offset;

	/**
	 * Total byte length of the entry record on disk.
	 *
	 * @var int
	 */
	private int $length;

	/**
	 * Entry kind; one of EntryHeader::KIND_*.
	 *
	 * @var string
	 */
	private string $kind;

	/**
	 * Codec id used to encode the payload (0 to MAX_CODEC_ID inclusive).
	 *
	 * @var int
	 */
	private int $codec_id;

	/**
	 * SHA-256 of the entry record, as 32 raw bytes.
	 *
	 * Per spec §6, this hash covers
	 * `header_length || header || codec_id || nonce || payload` —
	 * i.e. the entire entry record minus the hash field itself. It
	 * is identical to the value stored at the end of the entry on
	 * disk.
	 *
	 * @var string
	 */
	private string $entry_hash;

	/**
	 * Path identifier; non-null for file, directory, symlink kinds.
	 *
	 * @var string|null
	 */
	private ?string $path;

	/**
	 * Chunk-index identifier; non-null for db_chunk kind.
	 *
	 * @var int|null
	 */
	private ?int $chunk_index;

	/**
	 * Private constructor. Use the for_* static factories instead.
	 *
	 * @param int         $index       Entry's 0-based position (non-negative).
	 * @param int         $offset      Byte offset in the archive (non-negative).
	 * @param int         $length      Total entry record length (non-negative).
	 * @param string      $kind        One of EntryHeader::KIND_*.
	 * @param int         $codec_id    Codec id (0 to MAX_CODEC_ID).
	 * @param string      $entry_hash  SHA-256 (exactly Sha256::DIGEST_SIZE bytes).
	 * @param string|null $path        Path identifier (file/directory/symlink) or null.
	 * @param int|null    $chunk_index Chunk index (db_chunk) or null.
	 * @throws InvalidArgumentException If any common field is out of range, or if
	 *                                  the kind/identifier combination is inconsistent.
	 */
	private function __construct(
		int $index,
		int $offset,
		int $length,
		string $kind,
		int $codec_id,
		string $entry_hash,
		?string $path,
		?int $chunk_index
	) {
		if ( $index < 0 ) {
			throw new InvalidArgumentException(
				sprintf( 'ManifestEntry: index %d must be non-negative.', (int) $index )
			);
		}
		if ( $offset < 0 ) {
			throw new InvalidArgumentException(
				sprintf( 'ManifestEntry: offset %d must be non-negative.', (int) $offset )
			);
		}
		if ( $length < 0 ) {
			throw new InvalidArgumentException(
				sprintf( 'ManifestEntry: length %d must be non-negative.', (int) $length )
			);
		}
		if ( ! in_array( $kind, EntryHeader::ALL_KINDS, true ) ) {
			throw new InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $kind is validated input, exception message only.
				sprintf( 'ManifestEntry: kind "%s" is not one of the supported kinds.', $kind )
			);
		}
		if ( $codec_id < 0 || $codec_id > self::MAX_CODEC_ID ) {
			throw new InvalidArgumentException(
				sprintf( 'ManifestEntry: codec_id %d is outside the uint16 range (0 to 65535).', (int) $codec_id )
			);
		}
		if ( Sha256::DIGEST_SIZE !== strlen( $entry_hash ) ) {
			throw new InvalidArgumentException(
				sprintf(
					'ManifestEntry: entry_hash must be exactly %d bytes (one SHA-256 digest), got %d.',
					(int) Sha256::DIGEST_SIZE,
					(int) strlen( $entry_hash )
				)
			);
		}

		// Identifier consistency check: exactly one of path or chunk_index must be set.
		// The kind determines which identifier is required.
		$path_kinds = array(
			EntryHeader::KIND_FILE,
			EntryHeader::KIND_DIRECTORY,
			EntryHeader::KIND_SYMLINK,
		);
		if ( in_array( $kind, $path_kinds, true ) ) {
			if ( null === $path ) {
				throw new InvalidArgumentException(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $kind is validated input.
					sprintf( 'ManifestEntry: kind "%s" requires a path identifier.', $kind )
				);
			}
			if ( null !== $chunk_index ) {
				throw new InvalidArgumentException(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $kind is validated input.
					sprintf( 'ManifestEntry: kind "%s" must not carry a chunk_index identifier.', $kind )
				);
			}
		} elseif ( EntryHeader::KIND_DB_CHUNK === $kind ) {
			if ( null === $chunk_index ) {
				throw new InvalidArgumentException( 'ManifestEntry: kind "db_chunk" requires a chunk_index identifier.' );
			}
			if ( null !== $path ) {
				throw new InvalidArgumentException( 'ManifestEntry: kind "db_chunk" must not carry a path identifier.' );
			}
		}

		$this->index       = $index;
		$this->offset      = $offset;
		$this->length      = $length;
		$this->kind        = $kind;
		$this->codec_id    = $codec_id;
		$this->entry_hash  = $entry_hash;
		$this->path        = $path;
		$this->chunk_index = $chunk_index;
	}

	/**
	 * Construct a ManifestEntry for a file kind.
	 *
	 * @param int    $index      Entry's 0-based position (non-negative).
	 * @param int    $offset     Byte offset (non-negative).
	 * @param int    $length     Total record length (non-negative).
	 * @param string $path       Non-empty path relative to the WordPress root.
	 * @param int    $codec_id   Codec id (0 to MAX_CODEC_ID).
	 * @param string $entry_hash SHA-256 (exactly Sha256::DIGEST_SIZE bytes).
	 * @return self
	 * @throws InvalidArgumentException If path is empty or any common field is invalid.
	 */
	public static function for_file(
		int $index,
		int $offset,
		int $length,
		string $path,
		int $codec_id,
		string $entry_hash
	): self {
		if ( '' === $path ) {
			throw new InvalidArgumentException( 'ManifestEntry: path must be a non-empty string for file entries.' );
		}
		return new self(
			$index,
			$offset,
			$length,
			EntryHeader::KIND_FILE,
			$codec_id,
			$entry_hash,
			$path,
			null
		);
	}

	/**
	 * Construct a ManifestEntry for a db_chunk kind.
	 *
	 * @param int    $index       Entry's 0-based position (non-negative).
	 * @param int    $offset      Byte offset (non-negative).
	 * @param int    $length      Total record length (non-negative).
	 * @param int    $chunk_index 0-based chunk index within the database dump.
	 * @param int    $codec_id    Codec id (0 to MAX_CODEC_ID).
	 * @param string $entry_hash  SHA-256 (exactly Sha256::DIGEST_SIZE bytes).
	 * @return self
	 * @throws InvalidArgumentException If chunk_index is negative or any common field is invalid.
	 */
	public static function for_db_chunk(
		int $index,
		int $offset,
		int $length,
		int $chunk_index,
		int $codec_id,
		string $entry_hash
	): self {
		if ( $chunk_index < 0 ) {
			throw new InvalidArgumentException(
				sprintf( 'ManifestEntry: chunk_index %d must be non-negative.', (int) $chunk_index )
			);
		}
		return new self(
			$index,
			$offset,
			$length,
			EntryHeader::KIND_DB_CHUNK,
			$codec_id,
			$entry_hash,
			null,
			$chunk_index
		);
	}

	/**
	 * Construct a ManifestEntry for a directory kind.
	 *
	 * @param int    $index      Entry's 0-based position (non-negative).
	 * @param int    $offset     Byte offset (non-negative).
	 * @param int    $length     Total record length (non-negative).
	 * @param string $path       Non-empty directory path.
	 * @param int    $codec_id   Codec id (0 to MAX_CODEC_ID).
	 * @param string $entry_hash SHA-256 (exactly Sha256::DIGEST_SIZE bytes).
	 * @return self
	 * @throws InvalidArgumentException If path is empty or any common field is invalid.
	 */
	public static function for_directory(
		int $index,
		int $offset,
		int $length,
		string $path,
		int $codec_id,
		string $entry_hash
	): self {
		if ( '' === $path ) {
			throw new InvalidArgumentException( 'ManifestEntry: path must be a non-empty string for directory entries.' );
		}
		return new self(
			$index,
			$offset,
			$length,
			EntryHeader::KIND_DIRECTORY,
			$codec_id,
			$entry_hash,
			$path,
			null
		);
	}

	/**
	 * Construct a ManifestEntry for a symlink kind.
	 *
	 * @param int    $index      Entry's 0-based position (non-negative).
	 * @param int    $offset     Byte offset (non-negative).
	 * @param int    $length     Total record length (non-negative).
	 * @param string $path       Non-empty symlink path.
	 * @param int    $codec_id   Codec id (0 to MAX_CODEC_ID).
	 * @param string $entry_hash SHA-256 (exactly Sha256::DIGEST_SIZE bytes).
	 * @return self
	 * @throws InvalidArgumentException If path is empty or any common field is invalid.
	 */
	public static function for_symlink(
		int $index,
		int $offset,
		int $length,
		string $path,
		int $codec_id,
		string $entry_hash
	): self {
		if ( '' === $path ) {
			throw new InvalidArgumentException( 'ManifestEntry: path must be a non-empty string for symlink entries.' );
		}
		return new self(
			$index,
			$offset,
			$length,
			EntryHeader::KIND_SYMLINK,
			$codec_id,
			$entry_hash,
			$path,
			null
		);
	}

	/**
	 * Return the 0-based index of this entry within the archive's entry sequence.
	 *
	 * @return int The non-negative index.
	 */
	public function index(): int {
		return $this->index;
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
	 * Return the total entry record length on disk.
	 *
	 * @return int The non-negative length.
	 */
	public function length(): int {
		return $this->length;
	}

	/**
	 * Return the entry's kind.
	 *
	 * @return string One of the EntryHeader::KIND_* constants.
	 */
	public function kind(): string {
		return $this->kind;
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
	 * Return the entry SHA-256 hash as 32 raw bytes.
	 *
	 * @return string A 32-byte binary string.
	 */
	public function entry_hash(): string {
		return $this->entry_hash;
	}

	/**
	 * Return the path identifier, or null for db_chunk entries.
	 *
	 * @return string|null The path, or null when this entry is a db_chunk.
	 */
	public function path(): ?string {
		return $this->path;
	}

	/**
	 * Return the chunk_index identifier, or null for non-db_chunk entries.
	 *
	 * @return int|null The chunk index, or null when this entry is not a db_chunk.
	 */
	public function chunk_index(): ?int {
		return $this->chunk_index;
	}

	/**
	 * Whether this entry is a file kind.
	 *
	 * @return bool True if the kind is KIND_FILE.
	 */
	public function is_file(): bool {
		return EntryHeader::KIND_FILE === $this->kind;
	}

	/**
	 * Whether this entry is a db_chunk kind.
	 *
	 * @return bool True if the kind is KIND_DB_CHUNK.
	 */
	public function is_db_chunk(): bool {
		return EntryHeader::KIND_DB_CHUNK === $this->kind;
	}

	/**
	 * Whether this entry is a directory kind.
	 *
	 * @return bool True if the kind is KIND_DIRECTORY.
	 */
	public function is_directory(): bool {
		return EntryHeader::KIND_DIRECTORY === $this->kind;
	}

	/**
	 * Whether this entry is a symlink kind.
	 *
	 * @return bool True if the kind is KIND_SYMLINK.
	 */
	public function is_symlink(): bool {
		return EntryHeader::KIND_SYMLINK === $this->kind;
	}

	/**
	 * Return the canonical data array representation of this ManifestEntry.
	 *
	 * Field order matches the spec §9 example exactly: index, offset,
	 * length, kind, then the kind-specific identifier (path or
	 * chunk_index), then codec_id and hash. The entry_hash is
	 * hex-encoded as 64 lowercase characters under the JSON key "hash".
	 *
	 * @return array<string, mixed> The canonical data array.
	 */
	public function to_canonical_data(): array {
		$data = array(
			'index'  => $this->index,
			'offset' => $this->offset,
			'length' => $this->length,
			'kind'   => $this->kind,
		);

		if ( null !== $this->path ) {
			$data['path'] = $this->path;
		}
		if ( null !== $this->chunk_index ) {
			$data['chunk_index'] = $this->chunk_index;
		}

		$data['codec_id'] = $this->codec_id;
		$data['hash']     = bin2hex( $this->entry_hash );

		return $data;
	}

	/**
	 * Build a ManifestEntry from a decoded canonical data array.
	 *
	 * Validates the common fields (index, offset, length, kind,
	 * codec_id, hash) and dispatches to the appropriate factory
	 * based on kind.
	 *
	 * @param array<string, mixed> $data The decoded data array.
	 * @return self A ManifestEntry reflecting the data.
	 * @throws InvalidArgumentException If any field is missing, mis-typed, or invalid.
	 */
	public static function from_canonical_data( array $data ): self {
		foreach ( array( 'index', 'offset', 'length', 'codec_id' ) as $field ) {
			if ( ! array_key_exists( $field, $data ) ) {
				throw new InvalidArgumentException(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $field is a hardcoded literal.
					sprintf( 'ManifestEntry: data is missing required field "%s".', $field )
				);
			}
			if ( ! is_int( $data[ $field ] ) ) {
				throw new InvalidArgumentException(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $field is a hardcoded literal.
					sprintf( 'ManifestEntry: field "%s" must be an integer.', $field )
				);
			}
		}

		if ( ! array_key_exists( 'kind', $data ) ) {
			throw new InvalidArgumentException( 'ManifestEntry: data is missing required field "kind".' );
		}
		if ( ! is_string( $data['kind'] ) ) {
			throw new InvalidArgumentException( 'ManifestEntry: field "kind" must be a string.' );
		}

		if ( ! array_key_exists( 'hash', $data ) ) {
			throw new InvalidArgumentException( 'ManifestEntry: data is missing required field "hash".' );
		}
		if ( ! is_string( $data['hash'] ) ) {
			throw new InvalidArgumentException( 'ManifestEntry: field "hash" must be a string.' );
		}
		if ( 1 !== preg_match( '/^[0-9a-f]{' . self::HASH_HEX_LENGTH . '}$/', $data['hash'] ) ) {
			throw new InvalidArgumentException(
				sprintf(
					'ManifestEntry: hash must be exactly %d lowercase hex characters.',
					(int) self::HASH_HEX_LENGTH
				)
			);
		}

		$entry_hash = hex2bin( $data['hash'] );
		// hex2bin returns false on failure, but the regex above guarantees success here.
		if ( false === $entry_hash ) {
			throw new InvalidArgumentException( 'ManifestEntry: hash failed to decode despite passing validation.' );
		}

		switch ( $data['kind'] ) {
			case EntryHeader::KIND_FILE:
				return self::for_file(
					$data['index'],
					$data['offset'],
					$data['length'],
					self::require_path( $data, EntryHeader::KIND_FILE ),
					$data['codec_id'],
					$entry_hash
				);

			case EntryHeader::KIND_DB_CHUNK:
				return self::for_db_chunk(
					$data['index'],
					$data['offset'],
					$data['length'],
					self::require_chunk_index( $data ),
					$data['codec_id'],
					$entry_hash
				);

			case EntryHeader::KIND_DIRECTORY:
				return self::for_directory(
					$data['index'],
					$data['offset'],
					$data['length'],
					self::require_path( $data, EntryHeader::KIND_DIRECTORY ),
					$data['codec_id'],
					$entry_hash
				);

			case EntryHeader::KIND_SYMLINK:
				return self::for_symlink(
					$data['index'],
					$data['offset'],
					$data['length'],
					self::require_path( $data, EntryHeader::KIND_SYMLINK ),
					$data['codec_id'],
					$entry_hash
				);

			default:
				throw new InvalidArgumentException(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- kind is parsed input, exception only.
					sprintf( 'ManifestEntry: unknown kind "%s".', $data['kind'] )
				);
		}
	}

	/**
	 * Extract and validate the path field for path-bearing kinds.
	 *
	 * @param array<string, mixed> $data The canonical data array.
	 * @param string               $kind The entry kind requiring a path.
	 * @return string The validated non-empty path.
	 * @throws InvalidArgumentException If path is missing or mis-typed.
	 */
	private static function require_path( array $data, string $kind ): string {
		if ( ! array_key_exists( 'path', $data ) ) {
			throw new InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $kind is validated input.
				sprintf( 'ManifestEntry: kind "%s" requires a "path" field.', $kind )
			);
		}
		if ( ! is_string( $data['path'] ) ) {
			throw new InvalidArgumentException( 'ManifestEntry: field "path" must be a string.' );
		}
		return $data['path'];
	}

	/**
	 * Extract and validate the chunk_index field for db_chunk kind.
	 *
	 * @param array<string, mixed> $data The canonical data array.
	 * @return int The validated non-negative chunk index.
	 * @throws InvalidArgumentException If chunk_index is missing or mis-typed.
	 */
	private static function require_chunk_index( array $data ): int {
		if ( ! array_key_exists( 'chunk_index', $data ) ) {
			throw new InvalidArgumentException( 'ManifestEntry: kind "db_chunk" requires a "chunk_index" field.' );
		}
		if ( ! is_int( $data['chunk_index'] ) ) {
			throw new InvalidArgumentException( 'ManifestEntry: field "chunk_index" must be an integer.' );
		}
		return $data['chunk_index'];
	}
}
