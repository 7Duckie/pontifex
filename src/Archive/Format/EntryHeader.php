<?php
/**
 * Pontifex archive entry header — per-entry metadata block for one item in an archive.
 *
 * @package Pontifex\Archive\Format
 */

declare(strict_types=1);

namespace Pontifex\Archive\Format;

use InvalidArgumentException;
use JsonException;

/**
 * Immutable value object representing one entry's JSON metadata block.
 *
 * An archive holds many entries — files, database chunks, directories,
 * and symlinks. Each one is preceded on disk by a small JSON header
 * describing what kind of thing it is and the kind-specific facts a
 * reader needs (file path and size, db-chunk index, symlink target,
 * and so on).
 *
 * The on-disk layout of one entry is:
 *
 *  - header_length (4 bytes, uint32 big-endian)
 *  - header_JSON   (N bytes, UTF-8) — this is what EntryHeader covers
 *  - codec_id      (2 bytes, big-endian)
 *  - nonce         (12 bytes; zero-filled in v0.1.0)
 *  - payload       (M bytes)
 *  - payload hash  (32 bytes)
 *
 * EntryHeader owns the first two parts. The rest is the framing that
 * the writer and reader wrap around this header.
 *
 * Construction goes through kind-specific static factories
 * (for_file, for_db_chunk, for_directory, for_symlink). Each factory
 * validates exactly the arguments its kind requires. The constructor
 * is private so callers cannot bypass the factories and create
 * malformed entries.
 *
 * The v0.1.0 writer only produces file and db_chunk entries.
 * Directory and symlink factories exist to honour the format spec;
 * the v0.1.0 reader handles all four kinds so future archives remain
 * readable.
 *
 * Round-trip contract:
 * EntryHeader::from_bytes(EntryHeader::to_bytes()) returns an
 * EntryHeader equal in every field to the original.
 */
final class EntryHeader {

	/**
	 * Kind constant for a regular file entry.
	 *
	 * @var string
	 */
	public const KIND_FILE = 'file';

	/**
	 * Kind constant for a database chunk entry.
	 *
	 * @var string
	 */
	public const KIND_DB_CHUNK = 'db_chunk';

	/**
	 * Kind constant for a directory entry.
	 *
	 * @var string
	 */
	public const KIND_DIRECTORY = 'directory';

	/**
	 * Kind constant for a symbolic-link entry.
	 *
	 * @var string
	 */
	public const KIND_SYMLINK = 'symlink';

	/**
	 * Set of all valid kind values.
	 *
	 * @var array<int, string>
	 */
	public const ALL_KINDS = array(
		self::KIND_FILE,
		self::KIND_DB_CHUNK,
		self::KIND_DIRECTORY,
		self::KIND_SYMLINK,
	);

	/**
	 * Size of the length prefix field in bytes (4).
	 *
	 * @var int
	 */
	public const LENGTH_PREFIX_SIZE = 4;

	/**
	 * Maximum permitted size of the JSON payload, in bytes (16384 = 16 KiB).
	 *
	 * An entry header is one item's metadata; a few hundred bytes is
	 * typical even for long paths. Anything wildly larger is rejected
	 * as a defensive ceiling against malformed or malicious input.
	 *
	 * @var int
	 */
	public const MAX_PAYLOAD_SIZE = 16384;

	/**
	 * Maximum permitted POSIX mode value (12 bits, 0o7777 = 4095).
	 *
	 * @var int
	 */
	public const MAX_POSIX_MODE = 4095;

	/**
	 * Flags used for encoding the canonical JSON payload.
	 *
	 * Fixed for v1 archives so writes are deterministic.
	 *
	 * @var int
	 */
	private const JSON_ENCODE_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

	/**
	 * Entry kind; one of the KIND_* constants.
	 *
	 * @var string
	 */
	private string $kind;

	/**
	 * Relative path; non-null for file, directory, and symlink kinds.
	 *
	 * @var string|null
	 */
	private ?string $path;

	/**
	 * Original (pre-codec) byte size; non-null for file kind only.
	 *
	 * @var int|null
	 */
	private ?int $size;

	/**
	 * POSIX mode bits; non-null for file and directory kinds.
	 *
	 * @var int|null
	 */
	private ?int $mode;

	/**
	 * Unix modification timestamp; non-null for file kind only.
	 *
	 * @var int|null
	 */
	private ?int $mtime;

	/**
	 * Zero-based chunk index; non-null for db_chunk kind only.
	 *
	 * @var int|null
	 */
	private ?int $chunk_index;

	/**
	 * Predominant table name in this chunk; non-null for db_chunk kind only.
	 *
	 * @var string|null
	 */
	private ?string $table_name;

	/**
	 * Number of SQL statements in this chunk; non-null for db_chunk kind only.
	 *
	 * @var int|null
	 */
	private ?int $statement_count;

	/**
	 * Original (pre-codec) byte size of the chunk; non-null for db_chunk kind only.
	 *
	 * @var int|null
	 */
	private ?int $byte_count;

	/**
	 * Symlink target path; non-null for symlink kind only.
	 *
	 * @var string|null
	 */
	private ?string $target;

	/**
	 * MIME type sniffed at scan time; present on file entries only.
	 *
	 * Captured at scan time by FileScanner via finfo_file() and
	 * passed through ManifestBuilder. Used at restore time for
	 * type-confusion defense (the reader can refuse to write an
	 * executable PHP file if the original was sniffed as
	 * something else) and to support future selective-restore
	 * filters (e.g. wp pontifex import --only=images).
	 *
	 * Defaults to 'application/octet-stream' (RFC 2046's standard
	 * "treat as raw bytes" fallback) when MIME detection fails or
	 * is not applicable.
	 *
	 * @var string|null
	 */
	private ?string $media_type;

	/**
	 * Encoded payload byte count on disk, after the codec runs.
	 *
	 * Present on all four kinds. Directories always carry 0 since
	 * they have no payload. Set explicitly by the writer once the
	 * codec has finished encoding; callers that build a draft
	 * header before encoding can use with_size_compressed() to
	 * produce a corrected copy.
	 *
	 * Per spec §6, this is the value stored in the on-disk entry
	 * header as `size_compressed`.
	 *
	 * @var int
	 */
	private int $size_compressed;

	/**
	 * Construct an EntryHeader directly with all field values.
	 *
	 * Private to force construction through the kind-specific
	 * factories, which validate their arguments and pass nulls for
	 * irrelevant fields. The constructor itself does no
	 * cross-field validation — that's the factories' job.
	 *
	 * @param string      $kind            One of the KIND_* constants.
	 * @param string|null $path            Relative path (file/directory/symlink).
	 * @param int|null    $size            Original byte size (file).
	 * @param int|null    $mode            POSIX mode bits (file/directory).
	 * @param int|null    $mtime           Modification timestamp (file).
	 * @param int|null    $chunk_index     Chunk index (db_chunk).
	 * @param string|null $table_name      Predominant table (db_chunk).
	 * @param int|null    $statement_count Statement count (db_chunk).
	 * @param int|null    $byte_count      Original byte count (db_chunk).
	 * @param string|null $target          Symlink target (symlink).
	 * @param string|null $media_type      MIME type (file).
	 * @param int         $size_compressed Encoded payload byte count on disk.
	 */
	private function __construct(
		string $kind,
		?string $path = null,
		?int $size = null,
		?int $mode = null,
		?int $mtime = null,
		?int $chunk_index = null,
		?string $table_name = null,
		?int $statement_count = null,
		?int $byte_count = null,
		?string $target = null,
		?string $media_type = null,
		int $size_compressed = 0
	) {
		$this->kind            = $kind;
		$this->path            = $path;
		$this->size            = $size;
		$this->mode            = $mode;
		$this->mtime           = $mtime;
		$this->chunk_index     = $chunk_index;
		$this->table_name      = $table_name;
		$this->statement_count = $statement_count;
		$this->byte_count      = $byte_count;
		$this->target          = $target;
		$this->media_type      = $media_type;
		$this->size_compressed = $size_compressed;
	}

	/**
	 * Build an EntryHeader for a regular file entry.
	 *
	 * @param string $path            Relative path from the archive root; must be non-empty.
	 * @param int    $size            Original byte size before any codec encoding; must be non-negative.
	 * @param int    $mode            POSIX mode bits; must be in the range 0 to MAX_POSIX_MODE inclusive.
	 * @param int    $mtime           Unix modification timestamp; must be non-negative.
	 * @param string $media_type      MIME type sniffed at scan time; must be non-empty. Use 'application/octet-stream' as the safe default for unsniffable bytes.
	 * @param int    $size_compressed Encoded payload byte count on disk; must be non-negative.
	 * @return self A file-kind EntryHeader.
	 * @throws InvalidArgumentException If any argument is out of range or empty.
	 */
	public static function for_file( string $path, int $size, int $mode, int $mtime, string $media_type, int $size_compressed ): self {
		if ( '' === $path ) {
			throw new InvalidArgumentException( 'EntryHeader::for_file: path must not be empty.' );
		}
		if ( $size < 0 ) {
			throw new InvalidArgumentException(
				sprintf( 'EntryHeader::for_file: size %d must be non-negative.', (int) $size )
			);
		}
		if ( $mode < 0 || $mode > self::MAX_POSIX_MODE ) {
			throw new InvalidArgumentException(
				sprintf( 'EntryHeader::for_file: mode %d is outside the valid POSIX range (0 to 4095).', (int) $mode )
			);
		}
		if ( $mtime < 0 ) {
			throw new InvalidArgumentException(
				sprintf( 'EntryHeader::for_file: mtime %d must be non-negative.', (int) $mtime )
			);
		}
		if ( '' === $media_type ) {
			throw new InvalidArgumentException( 'EntryHeader::for_file: media_type must not be empty.' );
		}
		if ( $size_compressed < 0 ) {
			throw new InvalidArgumentException(
				sprintf( 'EntryHeader::for_file: size_compressed %d must be non-negative.', (int) $size_compressed )
			);
		}

		return new self(
			self::KIND_FILE,
			$path,
			$size,
			$mode,
			$mtime,
			null,
			null,
			null,
			null,
			null,
			$media_type,
			$size_compressed
		);
	}

	/**
	 * Build an EntryHeader for a database chunk entry.
	 *
	 * @param int    $chunk_index     Zero-based sequence index; must be non-negative.
	 * @param string $table_name      Predominant table name; must be non-empty.
	 * @param int    $statement_count Number of SQL statements in this chunk; must be non-negative.
	 * @param int    $byte_count      Original byte size of the statements before encoding; must be non-negative.
	 * @param int    $size_compressed Encoded payload byte count on disk; must be non-negative.
	 * @return self A db_chunk-kind EntryHeader.
	 * @throws InvalidArgumentException If any argument is out of range or empty.
	 */
	public static function for_db_chunk( int $chunk_index, string $table_name, int $statement_count, int $byte_count, int $size_compressed ): self {
		if ( $chunk_index < 0 ) {
			throw new InvalidArgumentException(
				sprintf( 'EntryHeader::for_db_chunk: chunk_index %d must be non-negative.', (int) $chunk_index )
			);
		}
		if ( '' === $table_name ) {
			throw new InvalidArgumentException( 'EntryHeader::for_db_chunk: table_name must not be empty.' );
		}
		if ( $statement_count < 0 ) {
			throw new InvalidArgumentException(
				sprintf( 'EntryHeader::for_db_chunk: statement_count %d must be non-negative.', (int) $statement_count )
			);
		}
		if ( $byte_count < 0 ) {
			throw new InvalidArgumentException(
				sprintf( 'EntryHeader::for_db_chunk: byte_count %d must be non-negative.', (int) $byte_count )
			);
		}
		if ( $size_compressed < 0 ) {
			throw new InvalidArgumentException(
				sprintf( 'EntryHeader::for_db_chunk: size_compressed %d must be non-negative.', (int) $size_compressed )
			);
		}

		return new self(
			self::KIND_DB_CHUNK,
			null,
			null,
			null,
			null,
			$chunk_index,
			$table_name,
			$statement_count,
			$byte_count,
			null,
			null,
			$size_compressed
		);
	}

	/**
	 * Build an EntryHeader for a directory entry.
	 *
	 * Directories carry no payload (per spec §6), so the writer passes
	 * 0 for size_compressed. The parameter is retained for consistency
	 * across all four factories.
	 *
	 * @param string $path            Relative path from the archive root; must be non-empty.
	 * @param int    $mode            POSIX mode bits; must be in the range 0 to MAX_POSIX_MODE inclusive.
	 * @param int    $size_compressed Encoded payload byte count on disk; must be non-negative (normally 0 for directories).
	 * @return self A directory-kind EntryHeader.
	 * @throws InvalidArgumentException If any argument is out of range or empty.
	 */
	public static function for_directory( string $path, int $mode, int $size_compressed ): self {
		if ( '' === $path ) {
			throw new InvalidArgumentException( 'EntryHeader::for_directory: path must not be empty.' );
		}
		if ( $mode < 0 || $mode > self::MAX_POSIX_MODE ) {
			throw new InvalidArgumentException(
				sprintf( 'EntryHeader::for_directory: mode %d is outside the valid POSIX range (0 to 4095).', (int) $mode )
			);
		}
		if ( $size_compressed < 0 ) {
			throw new InvalidArgumentException(
				sprintf( 'EntryHeader::for_directory: size_compressed %d must be non-negative.', (int) $size_compressed )
			);
		}

		return new self(
			self::KIND_DIRECTORY,
			$path,
			null,
			$mode,
			null,
			null,
			null,
			null,
			null,
			null,
			null,
			$size_compressed
		);
	}

	/**
	 * Build an EntryHeader for a symbolic-link entry.
	 *
	 * @param string $path            Relative path from the archive root; must be non-empty.
	 * @param string $target          The path the symlink points to; must be non-empty; stored verbatim.
	 * @param int    $size_compressed Encoded payload byte count on disk; must be non-negative.
	 * @return self A symlink-kind EntryHeader.
	 * @throws InvalidArgumentException If either path/target is empty or size_compressed is negative.
	 */
	public static function for_symlink( string $path, string $target, int $size_compressed ): self {
		if ( '' === $path ) {
			throw new InvalidArgumentException( 'EntryHeader::for_symlink: path must not be empty.' );
		}
		if ( '' === $target ) {
			throw new InvalidArgumentException( 'EntryHeader::for_symlink: target must not be empty.' );
		}
		if ( $size_compressed < 0 ) {
			throw new InvalidArgumentException(
				sprintf( 'EntryHeader::for_symlink: size_compressed %d must be non-negative.', (int) $size_compressed )
			);
		}

		return new self(
			self::KIND_SYMLINK,
			$path,
			null,
			null,
			null,
			null,
			null,
			null,
			null,
			$target,
			null,
			$size_compressed
		);
	}

	/**
	 * Return the entry kind.
	 *
	 * @return string One of the KIND_* constants.
	 */
	public function kind(): string {
		return $this->kind;
	}

	/**
	 * Return the relative path, or null for db_chunk entries.
	 *
	 * @return string|null The relative path.
	 */
	public function path(): ?string {
		return $this->path;
	}

	/**
	 * Return the original byte size, or null for non-file entries.
	 *
	 * @return int|null The byte size.
	 */
	public function size(): ?int {
		return $this->size;
	}

	/**
	 * Return the POSIX mode bits, or null for db_chunk and symlink entries.
	 *
	 * @return int|null The POSIX mode.
	 */
	public function mode(): ?int {
		return $this->mode;
	}

	/**
	 * Return the Unix modification timestamp, or null for non-file entries.
	 *
	 * @return int|null The timestamp.
	 */
	public function mtime(): ?int {
		return $this->mtime;
	}

	/**
	 * Return the MIME type, or null for non-file entries.
	 *
	 * Sniffed at scan time. For file entries this is always
	 * non-null (defaults to 'application/octet-stream' when
	 * detection fails). For db_chunk, directory, and symlink
	 * entries this is always null.
	 *
	 * @return string|null The MIME type.
	 */
	public function media_type(): ?string {
		return $this->media_type;
	}

	/**
	 * Return the chunk index, or null for non-db_chunk entries.
	 *
	 * @return int|null The chunk index.
	 */
	public function chunk_index(): ?int {
		return $this->chunk_index;
	}

	/**
	 * Return the table name, or null for non-db_chunk entries.
	 *
	 * @return string|null The table name.
	 */
	public function table_name(): ?string {
		return $this->table_name;
	}

	/**
	 * Return the statement count, or null for non-db_chunk entries.
	 *
	 * @return int|null The statement count.
	 */
	public function statement_count(): ?int {
		return $this->statement_count;
	}

	/**
	 * Return the original byte count, or null for non-db_chunk entries.
	 *
	 * @return int|null The byte count.
	 */
	public function byte_count(): ?int {
		return $this->byte_count;
	}

	/**
	 * Return a best-effort original-size estimate for any entry kind.
	 *
	 * For a file entry this is size(); for a db_chunk it is byte_count() (db
	 * chunks carry their size there, not in size()); for kinds with neither
	 * (directories, symlinks) it is 0. Used by the safety-archive disk preflight,
	 * which would otherwise count the whole database as zero.
	 *
	 * @return int The estimated original byte size.
	 */
	public function estimated_bytes(): int {
		return $this->size ?? $this->byte_count ?? 0;
	}

	/**
	 * Return the symlink target, or null for non-symlink entries.
	 *
	 * @return string|null The target path.
	 */
	public function target(): ?string {
		return $this->target;
	}

	/**
	 * Return the encoded payload byte count on disk.
	 *
	 * Present on all four kinds. Directories typically return 0 since
	 * they have no payload.
	 *
	 * @return int The non-negative encoded payload byte count.
	 */
	public function size_compressed(): int {
		return $this->size_compressed;
	}

	/**
	 * Return a copy of this EntryHeader with size_compressed updated.
	 *
	 * Immutable PSR-7-style update. Useful when a draft EntryHeader is
	 * built before the codec runs (with size_compressed = 0) and a
	 * corrected copy is needed once the encoded payload size is known.
	 *
	 * @param int $size_compressed New encoded payload byte count; must be non-negative.
	 * @return self A new EntryHeader instance with the updated size_compressed and all other fields preserved.
	 * @throws InvalidArgumentException If size_compressed is negative.
	 */
	public function with_size_compressed( int $size_compressed ): self {
		if ( $size_compressed < 0 ) {
			throw new InvalidArgumentException(
				sprintf( 'EntryHeader::with_size_compressed: size_compressed %d must be non-negative.', (int) $size_compressed )
			);
		}

		$copy                  = clone $this;
		$copy->size_compressed = $size_compressed;
		return $copy;
	}

	/**
	 * Whether this entry is a regular file.
	 *
	 * @return bool True if the kind is KIND_FILE.
	 */
	public function is_file(): bool {
		return self::KIND_FILE === $this->kind;
	}

	/**
	 * Whether this entry is a database chunk.
	 *
	 * @return bool True if the kind is KIND_DB_CHUNK.
	 */
	public function is_db_chunk(): bool {
		return self::KIND_DB_CHUNK === $this->kind;
	}

	/**
	 * Whether this entry is a directory.
	 *
	 * @return bool True if the kind is KIND_DIRECTORY.
	 */
	public function is_directory(): bool {
		return self::KIND_DIRECTORY === $this->kind;
	}

	/**
	 * Whether this entry is a symbolic link.
	 *
	 * @return bool True if the kind is KIND_SYMLINK.
	 */
	public function is_symlink(): bool {
		return self::KIND_SYMLINK === $this->kind;
	}

	/**
	 * Serialise the entry header to its on-disk representation.
	 *
	 * Builds the JSON payload in canonical field order (kind first,
	 * then kind-specific fields in fixed order) and prepends a
	 * 4-byte big-endian length prefix.
	 *
	 * @return string LENGTH_PREFIX_SIZE + N bytes, where N is the JSON payload length.
	 * @throws JsonException If JSON encoding fails (should not happen for the validated fields).
	 */
	public function to_bytes(): string {
		$payload = $this->encode_canonical_json();

		return ByteOrder::pack_uint32( strlen( $payload ) ) . $payload;
	}

	/**
	 * Parse on-disk bytes into an EntryHeader value object.
	 *
	 * Verifies the payload size against the length prefix, rejects
	 * declared sizes above MAX_PAYLOAD_SIZE, decodes the JSON, and
	 * dispatches to the kind-specific factory.
	 *
	 * @param string $bytes On-disk bytes representing an entry header.
	 * @return self An EntryHeader value object reflecting the parsed bytes.
	 * @throws InvalidArgumentException If the bytes are malformed, oversize, the wrong total length, or contain an invalid kind or missing fields.
	 */
	public static function from_bytes( string $bytes ): self {
		if ( strlen( $bytes ) < self::LENGTH_PREFIX_SIZE ) {
			throw new InvalidArgumentException(
				sprintf(
					'EntryHeader::from_bytes: input must be at least %d bytes, got %d.',
					(int) self::LENGTH_PREFIX_SIZE,
					(int) strlen( $bytes )
				)
			);
		}

		$length = ByteOrder::unpack_uint32( substr( $bytes, 0, self::LENGTH_PREFIX_SIZE ) );

		if ( $length > self::MAX_PAYLOAD_SIZE ) {
			throw new InvalidArgumentException(
				sprintf(
					'EntryHeader::from_bytes: declared payload size %d exceeds maximum %d bytes.',
					(int) $length,
					(int) self::MAX_PAYLOAD_SIZE
				)
			);
		}

		$expected_total = self::LENGTH_PREFIX_SIZE + $length;
		if ( strlen( $bytes ) !== $expected_total ) {
			throw new InvalidArgumentException(
				sprintf(
					'EntryHeader::from_bytes: expected exactly %d bytes (4 length + %d payload), got %d.',
					(int) $expected_total,
					(int) $length,
					(int) strlen( $bytes )
				)
			);
		}

		$payload = substr( $bytes, self::LENGTH_PREFIX_SIZE, $length );

		return self::decode_canonical_json( $payload );
	}

	/**
	 * Return the canonical data array representation of this EntryHeader.
	 *
	 * Produces the same data the canonical JSON encoder serialises:
	 * the "kind" field first, then the kind-specific fields in a
	 * fixed order. Other classes (notably ArchiveManifest) use this
	 * to nest an EntryHeader inside their own JSON without going
	 * through string serialisation and back.
	 *
	 * @return array<string, mixed> The canonical data array.
	 */
	public function to_canonical_data(): array {
		$data = array( 'kind' => $this->kind );

		switch ( $this->kind ) {
			case self::KIND_FILE:
				$data['path']            = $this->path;
				$data['size']            = $this->size;
				$data['mode']            = $this->mode;
				$data['mtime']           = $this->mtime;
				$data['media_type']      = $this->media_type;
				$data['size_compressed'] = $this->size_compressed;
				break;
			case self::KIND_DB_CHUNK:
				$data['chunk_index']     = $this->chunk_index;
				$data['table_name']      = $this->table_name;
				$data['statement_count'] = $this->statement_count;
				$data['byte_count']      = $this->byte_count;
				$data['size_compressed'] = $this->size_compressed;
				break;
			case self::KIND_DIRECTORY:
				$data['path']            = $this->path;
				$data['mode']            = $this->mode;
				$data['size_compressed'] = $this->size_compressed;
				break;
			case self::KIND_SYMLINK:
				$data['path']            = $this->path;
				$data['target']          = $this->target;
				$data['size_compressed'] = $this->size_compressed;
				break;
		}

		return $data;
	}

	/**
	 * Build an EntryHeader from a decoded canonical data array.
	 *
	 * Validates the kind field is present, is a string, and is one
	 * of the recognised values, then dispatches to the
	 * kind-specific parser that verifies the expected fields are
	 * present and well-typed.
	 *
	 * @param array<string, mixed> $data The canonical data array.
	 * @return self An EntryHeader reflecting the data.
	 * @throws InvalidArgumentException If the kind field is missing, mis-typed, or unrecognised, or if kind-specific fields are missing or wrong-typed.
	 */
	public static function from_canonical_data( array $data ): self {
		if ( ! array_key_exists( 'kind', $data ) ) {
			throw new InvalidArgumentException( 'EntryHeader: data is missing required field "kind".' );
		}
		if ( ! is_string( $data['kind'] ) ) {
			throw new InvalidArgumentException( 'EntryHeader: field "kind" must be a string.' );
		}
		if ( ! in_array( $data['kind'], self::ALL_KINDS, true ) ) {
			throw new InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- User-supplied value embedded in exception message for diagnostic context; exception path, not HTML output.
				sprintf( 'EntryHeader: unknown kind "%s"; expected one of file, db_chunk, directory, symlink.', $data['kind'] )
			);
		}

		switch ( $data['kind'] ) {
			case self::KIND_FILE:
				return self::parse_file_payload( $data );
			case self::KIND_DB_CHUNK:
				return self::parse_db_chunk_payload( $data );
			case self::KIND_DIRECTORY:
				return self::parse_directory_payload( $data );
			case self::KIND_SYMLINK:
				return self::parse_symlink_payload( $data );
			default:
				// Should be unreachable given the in_array guard above.
				throw new InvalidArgumentException( 'EntryHeader: kind dispatch fell through.' );
		}
	}

	/**
	 * Encode this EntryHeader to a canonical JSON byte string.
	 *
	 * Thin wrapper over to_canonical_data + json_encode. Kept
	 * private because callers that need the data shape should use
	 * to_canonical_data directly; this method is for the on-disk
	 * binary serialisation path only.
	 *
	 * @return string A canonical JSON byte string in UTF-8.
	 * @throws JsonException If encoding fails (should not happen for the validated fields).
	 */
	private function encode_canonical_json(): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Deterministic byte output required for hash stability; wp_json_encode wraps json_encode without adding anything needed here, and depends on WordPress being loaded.
		return json_encode( $this->to_canonical_data(), self::JSON_ENCODE_FLAGS );
	}

	/**
	 * Decode a JSON payload into an EntryHeader value object.
	 *
	 * Thin wrapper over json_decode + from_canonical_data. Kept
	 * private because the JSON form is only used in the on-disk
	 * binary serialisation path; nested-data callers should use
	 * from_canonical_data directly.
	 *
	 * @param string $json The JSON payload bytes as read from disk.
	 * @return self An EntryHeader reflecting the decoded data.
	 * @throws InvalidArgumentException If the JSON is malformed or the decoded data is invalid.
	 */
	private static function decode_canonical_json( string $json ): self {
		try {
			$data = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		} catch ( JsonException $e ) {
			throw new InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message embedded for diagnostic context; not HTML output.
				'EntryHeader: JSON payload is malformed: ' . $e->getMessage()
			);
		}

		if ( ! is_array( $data ) ) {
			throw new InvalidArgumentException( 'EntryHeader: JSON payload must decode to an object.' );
		}

		return self::from_canonical_data( $data );
	}

	/**
	 * Parse the JSON payload for a file-kind entry.
	 *
	 * @param array<string, mixed> $data The decoded JSON payload.
	 * @return self A file-kind EntryHeader.
	 * @throws InvalidArgumentException If required fields are missing or have the wrong type.
	 */
	private static function parse_file_payload( array $data ): self {
		self::require_string( $data, 'path' );
		self::require_int( $data, 'size' );
		self::require_int( $data, 'mode' );
		self::require_int( $data, 'mtime' );
		self::require_string( $data, 'media_type' );
		self::require_int( $data, 'size_compressed' );

		return self::for_file(
			$data['path'],
			$data['size'],
			$data['mode'],
			$data['mtime'],
			$data['media_type'],
			$data['size_compressed']
		);
	}

	/**
	 * Parse the JSON payload for a db_chunk-kind entry.
	 *
	 * @param array<string, mixed> $data The decoded JSON payload.
	 * @return self A db_chunk-kind EntryHeader.
	 * @throws InvalidArgumentException If required fields are missing or have the wrong type.
	 */
	private static function parse_db_chunk_payload( array $data ): self {
		self::require_int( $data, 'chunk_index' );
		self::require_string( $data, 'table_name' );
		self::require_int( $data, 'statement_count' );
		self::require_int( $data, 'byte_count' );
		self::require_int( $data, 'size_compressed' );

		return self::for_db_chunk(
			$data['chunk_index'],
			$data['table_name'],
			$data['statement_count'],
			$data['byte_count'],
			$data['size_compressed']
		);
	}

	/**
	 * Parse the JSON payload for a directory-kind entry.
	 *
	 * @param array<string, mixed> $data The decoded JSON payload.
	 * @return self A directory-kind EntryHeader.
	 * @throws InvalidArgumentException If required fields are missing or have the wrong type.
	 */
	private static function parse_directory_payload( array $data ): self {
		self::require_string( $data, 'path' );
		self::require_int( $data, 'mode' );
		self::require_int( $data, 'size_compressed' );

		return self::for_directory(
			$data['path'],
			$data['mode'],
			$data['size_compressed']
		);
	}

	/**
	 * Parse the JSON payload for a symlink-kind entry.
	 *
	 * @param array<string, mixed> $data The decoded JSON payload.
	 * @return self A symlink-kind EntryHeader.
	 * @throws InvalidArgumentException If required fields are missing or have the wrong type.
	 */
	private static function parse_symlink_payload( array $data ): self {
		self::require_string( $data, 'path' );
		self::require_string( $data, 'target' );
		self::require_int( $data, 'size_compressed' );

		return self::for_symlink(
			$data['path'],
			$data['target'],
			$data['size_compressed']
		);
	}

	/**
	 * Verify that a JSON field is present and holds a string value.
	 *
	 * @param array<string, mixed> $data  The decoded JSON payload.
	 * @param string               $field The required field name.
	 * @return void
	 * @throws InvalidArgumentException If the field is missing or not a string.
	 */
	private static function require_string( array $data, string $field ): void {
		if ( ! array_key_exists( $field, $data ) ) {
			throw new InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $field is supplied by trusted callers in this class; exception message, not HTML output.
				sprintf( 'EntryHeader: JSON payload is missing required field "%s".', $field )
			);
		}
		if ( ! is_string( $data[ $field ] ) ) {
			throw new InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $field is supplied by trusted callers in this class; exception message, not HTML output.
				sprintf( 'EntryHeader: field "%s" must be a string.', $field )
			);
		}
	}

	/**
	 * Verify that a JSON field is present and holds an integer value.
	 *
	 * Strict: only PHP int values are accepted; floats and numeric
	 * strings are rejected.
	 *
	 * @param array<string, mixed> $data  The decoded JSON payload.
	 * @param string               $field The required field name.
	 * @return void
	 * @throws InvalidArgumentException If the field is missing or not an integer.
	 */
	private static function require_int( array $data, string $field ): void {
		if ( ! array_key_exists( $field, $data ) ) {
			throw new InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $field is supplied by trusted callers in this class; exception message, not HTML output.
				sprintf( 'EntryHeader: JSON payload is missing required field "%s".', $field )
			);
		}
		if ( ! is_int( $data[ $field ] ) ) {
			throw new InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $field is supplied by trusted callers in this class; exception message, not HTML output.
				sprintf( 'EntryHeader: field "%s" must be an integer.', $field )
			);
		}
	}
}
