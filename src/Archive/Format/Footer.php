<?php
/**
 * Pontifex archive footer — the 64-byte structure at the end of every .wpmig file.
 *
 * @package Pontifex\Archive\Format
 */

declare(strict_types=1);

namespace Pontifex\Archive\Format;

use InvalidArgumentException;
use Pontifex\Archive\Integrity\Sha256;

/**
 * Immutable value object representing the 64-byte archive footer.
 *
 * Every .wpmig file ends with this footer (or has it immediately
 * before an optional detached-signature block, when present). The
 * footer tells the reader where to find the manifest and lets it
 * verify the manifest has not been tampered with (ARCHIVE-FORMAT.md
 * §10).
 *
 * The 64 bytes break down as:
 *
 *  - manifest_offset  (8 bytes, uint64 big-endian): byte offset into
 *    the file pointing to the first byte of the manifest block.
 *  - manifest_length  (8 bytes, uint64 big-endian): number of bytes
 *    the manifest occupies.
 *  - manifest_hash   (32 bytes): SHA-256 of the manifest bytes. The
 *    reader recomputes this and rejects the archive if it does not
 *    match.
 *  - argon2id_salt   (16 bytes): salt for the encryption key
 *    derivation function. v0.1.0 archives are unencrypted; the slot
 *    is zero-filled for them so the footer layout can stay the same
 *    when encryption arrives in v0.2.0.
 *
 * The Footer value object is a data holder. It does not enforce
 * "the salt should be zeros when there's no encryption" or any other
 * cross-field semantics. Those checks belong to the writer that
 * decides what to put in each slot.
 *
 * Round-trip contract: Footer::from_bytes(Footer::to_bytes()) returns
 * a Footer equal in every field to the original.
 */
final class Footer {

	/**
	 * Total byte size of the footer on disk (64).
	 *
	 * @var int
	 */
	public const SIZE = 64;

	/**
	 * Size of the argon2id_salt field in bytes (16).
	 *
	 * @var int
	 */
	public const ARGON2ID_SALT_SIZE = 16;

	/**
	 * Sixteen zero bytes — the salt value used by v0.1.0 unencrypted archives.
	 *
	 * Exposed as a constant so v0.1.0 writers can pass
	 * Footer::ZERO_SALT explicitly rather than constructing the
	 * literal at the call site.
	 *
	 * @var string
	 */
	public const ZERO_SALT = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00";

	/**
	 * Offset of the manifest block within the archive file.
	 *
	 * @var int
	 */
	private int $manifest_offset;

	/**
	 * Byte length of the manifest block.
	 *
	 * @var int
	 */
	private int $manifest_length;

	/**
	 * SHA-256 hash of the manifest bytes, as a 32-byte binary string.
	 *
	 * @var string
	 */
	private string $manifest_hash;

	/**
	 * Argon2id salt as a 16-byte binary string.
	 *
	 * @var string
	 */
	private string $argon2id_salt;

	/**
	 * Construct a Footer with the four field values.
	 *
	 * Validates the integer ranges and the byte-string lengths.
	 *
	 * @param int    $manifest_offset Byte offset of the manifest in the archive (non-negative).
	 * @param int    $manifest_length Byte length of the manifest (non-negative).
	 * @param string $manifest_hash   SHA-256 of the manifest bytes (exactly Sha256::DIGEST_SIZE bytes).
	 * @param string $argon2id_salt   Argon2id salt (exactly ARGON2ID_SALT_SIZE bytes; pass Footer::ZERO_SALT for unencrypted archives).
	 * @throws InvalidArgumentException If any argument is out of range or the wrong length.
	 */
	public function __construct(
		int $manifest_offset,
		int $manifest_length,
		string $manifest_hash,
		string $argon2id_salt
	) {
		if ( $manifest_offset < 0 ) {
			throw new InvalidArgumentException(
				sprintf( 'Footer: manifest_offset must be non-negative, got %d.', (int) $manifest_offset )
			);
		}
		if ( $manifest_length < 0 ) {
			throw new InvalidArgumentException(
				sprintf( 'Footer: manifest_length must be non-negative, got %d.', (int) $manifest_length )
			);
		}
		if ( strlen( $manifest_hash ) !== Sha256::DIGEST_SIZE ) {
			throw new InvalidArgumentException(
				sprintf(
					'Footer: manifest_hash must be exactly %d bytes (one SHA-256 digest), got %d.',
					(int) Sha256::DIGEST_SIZE,
					(int) strlen( $manifest_hash )
				)
			);
		}
		if ( strlen( $argon2id_salt ) !== self::ARGON2ID_SALT_SIZE ) {
			throw new InvalidArgumentException(
				sprintf(
					'Footer: argon2id_salt must be exactly %d bytes, got %d.',
					(int) self::ARGON2ID_SALT_SIZE,
					(int) strlen( $argon2id_salt )
				)
			);
		}

		$this->manifest_offset = $manifest_offset;
		$this->manifest_length = $manifest_length;
		$this->manifest_hash   = $manifest_hash;
		$this->argon2id_salt   = $argon2id_salt;
	}

	/**
	 * Return the byte offset of the manifest block within the archive.
	 *
	 * @return int The non-negative byte offset.
	 */
	public function manifest_offset(): int {
		return $this->manifest_offset;
	}

	/**
	 * Return the byte length of the manifest block.
	 *
	 * @return int The non-negative byte length.
	 */
	public function manifest_length(): int {
		return $this->manifest_length;
	}

	/**
	 * Return the SHA-256 hash of the manifest bytes.
	 *
	 * @return string A 32-byte binary string.
	 */
	public function manifest_hash(): string {
		return $this->manifest_hash;
	}

	/**
	 * Return the Argon2id salt.
	 *
	 * @return string A 16-byte binary string.
	 */
	public function argon2id_salt(): string {
		return $this->argon2id_salt;
	}

	/**
	 * Serialise the footer to its 64-byte on-disk representation.
	 *
	 * @return string Exactly 64 bytes: 8 manifest_offset + 8 manifest_length + 32 manifest_hash + 16 argon2id_salt.
	 */
	public function to_bytes(): string {
		return ByteOrder::pack_uint64( $this->manifest_offset )
			. ByteOrder::pack_uint64( $this->manifest_length )
			. $this->manifest_hash
			. $this->argon2id_salt;
	}

	/**
	 * Parse a 64-byte on-disk footer into a Footer value object.
	 *
	 * @param string $bytes Exactly 64 bytes representing a footer on disk.
	 * @return self A Footer value object reflecting the parsed bytes.
	 * @throws InvalidArgumentException If $bytes is the wrong length or any field is out of range.
	 */
	public static function from_bytes( string $bytes ): self {
		if ( strlen( $bytes ) !== self::SIZE ) {
			throw new InvalidArgumentException(
				sprintf(
					'Footer::from_bytes: expected exactly %d bytes, got %d.',
					(int) self::SIZE,
					(int) strlen( $bytes )
				)
			);
		}

		$manifest_offset = ByteOrder::unpack_uint64( substr( $bytes, 0, 8 ) );
		$manifest_length = ByteOrder::unpack_uint64( substr( $bytes, 8, 8 ) );
		$manifest_hash   = substr( $bytes, 16, Sha256::DIGEST_SIZE );
		$argon2id_salt   = substr( $bytes, 48, self::ARGON2ID_SALT_SIZE );

		return new self( $manifest_offset, $manifest_length, $manifest_hash, $argon2id_salt );
	}
}
