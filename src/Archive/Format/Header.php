<?php
/**
 * Pontifex archive header — the 16-byte structure at the start of every .wpmig file.
 *
 * @package Pontifex\Archive\Format
 */

declare(strict_types=1);

namespace Pontifex\Archive\Format;

use InvalidArgumentException;

/**
 * Immutable value object representing the 16-byte archive header.
 *
 * Every .wpmig file begins with this header. It tells a reader three
 * things, in a fixed 16-byte layout (ARCHIVE-FORMAT.md §4):
 *
 *  - The 8-byte magic marker confirming the file is a Pontifex
 *    archive. Always exactly the bytes "WPMIG\x00\x00\x01".
 *  - The 16-bit major and minor format versions, big-endian.
 *  - The 32-bit flags bitfield, big-endian. Bits 0 through 2 are
 *    defined by the v1 spec (encrypted, signed, provenance encrypted);
 *    bits 3 through 31 are reserved for future format versions and
 *    must be zero in any v1 archive.
 *
 * The reserved-bit rule is enforced at construction, not only at
 * parse time. A buggy writer that tried to set bit 4 or bit 30 would
 * be caught before any bytes hit disk.
 *
 * Round-trip contract: Header::from_bytes(Header::to_bytes()) returns
 * a Header equal in every field to the original.
 */
final class Header {

	/**
	 * The 8-byte magic marker that identifies a file as a Pontifex archive.
	 *
	 * Spells "WPMIG" in ASCII followed by \x00\x00\x01. The trailing
	 * non-printable byte is deliberate: it prevents the file from
	 * being mistaken for UTF-8 text by tools that probe the first
	 * few bytes.
	 *
	 * @var string
	 */
	public const MAGIC = "WPMIG\x00\x00\x01";

	/**
	 * Total byte size of the header on disk (16).
	 *
	 * @var int
	 */
	public const SIZE = 16;

	/**
	 * Format major version for v1 archives.
	 *
	 * @var int
	 */
	public const FORMAT_MAJOR_V1 = 1;

	/**
	 * Format minor version for v1.0 archives.
	 *
	 * @var int
	 */
	public const FORMAT_MINOR_V1_0 = 0;

	/**
	 * Format minor version for v1.1 archives — the current version Pontifex writes.
	 *
	 * Version 1.1 adds the optional `scope` and `table_prefix` provenance fields
	 * (ADR 0008). The change is backward-compatible: a v1.0 reader accepts a v1.1 archive and
	 * ignores the additions it does not understand (`ARCHIVE-FORMAT.md` §13.1).
	 *
	 * @var int
	 */
	public const FORMAT_MINOR_V1_1 = 1;

	/**
	 * Flag bit indicating the archive is encrypted (bit 0).
	 *
	 * @var int
	 */
	public const FLAG_ENCRYPTED = 0x00000001;

	/**
	 * Flag bit indicating the archive carries a detached signature (bit 1).
	 *
	 * @var int
	 */
	public const FLAG_SIGNED = 0x00000002;

	/**
	 * Flag bit indicating the provenance block itself is encrypted (bit 2).
	 *
	 * Only meaningful when FLAG_ENCRYPTED is also set.
	 *
	 * @var int
	 */
	public const FLAG_PROVENANCE_ENCRYPTED = 0x00000004;

	/**
	 * Bitmask covering every flag bit defined by the v1 spec.
	 *
	 * Any flags value with bits outside this mask set is rejected as
	 * "format version unknown" per ARCHIVE-FORMAT.md §4.
	 *
	 * @var int
	 */
	public const ALL_DEFINED_FLAGS = self::FLAG_ENCRYPTED | self::FLAG_SIGNED | self::FLAG_PROVENANCE_ENCRYPTED;

	/**
	 * Format major version held by this header instance.
	 *
	 * @var int
	 */
	private int $major;

	/**
	 * Format minor version held by this header instance.
	 *
	 * @var int
	 */
	private int $minor;

	/**
	 * Flags bitfield held by this header instance.
	 *
	 * @var int
	 */
	private int $flags;

	/**
	 * Construct a Header with explicit version and flag values.
	 *
	 * Validates each argument against the on-disk field width and
	 * rejects flag values with reserved bits set.
	 *
	 * @param int $major Format major version (0 to 65535).
	 * @param int $minor Format minor version (0 to 65535).
	 * @param int $flags Flag bitfield (0 to 0xFFFFFFFF; only bits 0-2 may be set).
	 * @throws InvalidArgumentException If any argument is out of range or sets a reserved flag bit.
	 */
	public function __construct( int $major, int $minor, int $flags ) {
		if ( $major < 0 || $major > ByteOrder::MAX_UINT16 ) {
			throw new InvalidArgumentException(
				sprintf( 'Header: major version %d is outside the uint16 range.', (int) $major )
			);
		}
		if ( $minor < 0 || $minor > ByteOrder::MAX_UINT16 ) {
			throw new InvalidArgumentException(
				sprintf( 'Header: minor version %d is outside the uint16 range.', (int) $minor )
			);
		}
		if ( $flags < 0 || $flags > ByteOrder::MAX_UINT32 ) {
			throw new InvalidArgumentException(
				sprintf( 'Header: flags value %d is outside the uint32 range.', (int) $flags )
			);
		}
		if ( ( $flags & ~self::ALL_DEFINED_FLAGS ) !== 0 ) {
			throw new InvalidArgumentException(
				sprintf(
					'Header: reserved flag bits set in 0x%08X; only bits 0-2 are defined in the v1 format.',
					(int) $flags
				)
			);
		}

		$this->major = $major;
		$this->minor = $minor;
		$this->flags = $flags;
	}

	/**
	 * Build the standard header for the current Pontifex format version (v1.1, no flags).
	 *
	 * Most writers should call this rather than the full constructor.
	 * The full constructor exists for tests and for code that parses
	 * arbitrary header bytes.
	 *
	 * @return self A Header with major=1, minor=1, flags=0.
	 */
	public static function current_version(): self {
		return new self( self::FORMAT_MAJOR_V1, self::FORMAT_MINOR_V1_1, 0 );
	}

	/**
	 * Return the format major version.
	 *
	 * @return int The format major version.
	 */
	public function major(): int {
		return $this->major;
	}

	/**
	 * Return the format minor version.
	 *
	 * @return int The format minor version.
	 */
	public function minor(): int {
		return $this->minor;
	}

	/**
	 * Return the raw flags bitfield.
	 *
	 * @return int The flags as an integer.
	 */
	public function flags(): int {
		return $this->flags;
	}

	/**
	 * Whether the encrypted flag (bit 0) is set.
	 *
	 * @return bool True if FLAG_ENCRYPTED is set, false otherwise.
	 */
	public function is_encrypted(): bool {
		return ( $this->flags & self::FLAG_ENCRYPTED ) !== 0;
	}

	/**
	 * Whether the signed flag (bit 1) is set.
	 *
	 * @return bool True if FLAG_SIGNED is set, false otherwise.
	 */
	public function is_signed(): bool {
		return ( $this->flags & self::FLAG_SIGNED ) !== 0;
	}

	/**
	 * Whether the provenance-encrypted flag (bit 2) is set.
	 *
	 * @return bool True if FLAG_PROVENANCE_ENCRYPTED is set, false otherwise.
	 */
	public function is_provenance_encrypted(): bool {
		return ( $this->flags & self::FLAG_PROVENANCE_ENCRYPTED ) !== 0;
	}

	/**
	 * Serialise the header to its 16-byte on-disk representation.
	 *
	 * @return string Exactly 16 bytes: 8 magic + 2 major (BE) + 2 minor (BE) + 4 flags (BE).
	 */
	public function to_bytes(): string {
		return self::MAGIC
			. ByteOrder::pack_uint16( $this->major )
			. ByteOrder::pack_uint16( $this->minor )
			. ByteOrder::pack_uint32( $this->flags );
	}

	/**
	 * Parse a 16-byte on-disk header into a Header value object.
	 *
	 * Validates the magic marker and all field ranges. A file whose
	 * first 16 bytes do not match the magic is rejected as "not a
	 * Pontifex archive."
	 *
	 * @param string $bytes Exactly 16 bytes representing a header on disk.
	 * @return self A Header value object reflecting the parsed bytes.
	 * @throws InvalidArgumentException If $bytes is the wrong length, the magic does not match, or the flags contain reserved bits.
	 */
	public static function from_bytes( string $bytes ): self {
		if ( strlen( $bytes ) !== self::SIZE ) {
			throw new InvalidArgumentException(
				sprintf(
					'Header::from_bytes: expected exactly %d bytes, got %d.',
					(int) self::SIZE,
					(int) strlen( $bytes )
				)
			);
		}
		$magic_part = substr( $bytes, 0, 8 );
		if ( self::MAGIC !== $magic_part ) {
			throw new InvalidArgumentException(
				'Header::from_bytes: magic bytes do not match; the file is not a Pontifex archive.'
			);
		}

		$major = ByteOrder::unpack_uint16( substr( $bytes, 8, 2 ) );
		$minor = ByteOrder::unpack_uint16( substr( $bytes, 10, 2 ) );
		$flags = ByteOrder::unpack_uint32( substr( $bytes, 12, 4 ) );

		return new self( $major, $minor, $flags );
	}
}
