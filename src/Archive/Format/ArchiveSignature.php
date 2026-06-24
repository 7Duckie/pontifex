<?php
/**
 * Pontifex archive signature block — the optional structure appended after the footer.
 *
 * @package Pontifex\Archive\Format
 */

declare(strict_types=1);

namespace Pontifex\Archive\Format;

use InvalidArgumentException;
use Pontifex\Archive\Integrity\Sha256;

/**
 * Immutable value object representing the optional 100-byte signature block.
 *
 * When the header's signed flag is set, this block is appended after the
 * footer (ARCHIVE-FORMAT.md §11). Its 100 bytes break down as:
 *
 *  - key id    (32 bytes): SHA-256 of the public key used, a stable
 *    fingerprint letting a reader tell which key the signature claims to be
 *    from before verifying.
 *  - sig length (4 bytes, uint32 big-endian): always 0x00000040 (64) for
 *    Ed25519. Carried explicitly so the layout could accommodate another
 *    signature size in a future format version; a v1 reader rejects any other
 *    value.
 *  - sig bytes (64 bytes): the Ed25519 signature over the SHA-256 of the
 *    bytes from offset 0 through the end of the footer.
 *
 * Like {@see Footer}, this is a data holder: it validates its own byte layout
 * but knows nothing about whether the signature actually verifies — that is the
 * job of the reader and {@see \Pontifex\Archive\Crypto\Ed25519Verifier}.
 *
 * Round-trip contract: ArchiveSignature::from_bytes(ArchiveSignature::to_bytes())
 * returns an ArchiveSignature equal in every field to the original.
 */
final class ArchiveSignature {

	/**
	 * Size of the key id field in bytes (32 = SHA-256 output).
	 *
	 * @var int
	 */
	public const KEY_ID_SIZE = Sha256::DIGEST_SIZE;

	/**
	 * Size of the sig-length prefix in bytes (4 = one uint32).
	 *
	 * @var int
	 */
	public const SIG_LENGTH_PREFIX_SIZE = 4;

	/**
	 * Size of the Ed25519 signature in bytes (64), and the only valid sig-length value.
	 *
	 * @var int
	 */
	public const SIGNATURE_SIZE = 64;

	/**
	 * Total byte size of the signature block on disk (100).
	 *
	 * @var int
	 */
	public const SIZE = self::KEY_ID_SIZE + self::SIG_LENGTH_PREFIX_SIZE + self::SIGNATURE_SIZE;

	/**
	 * The 32-byte key id (SHA-256 of the public key).
	 *
	 * @var string
	 */
	private string $key_id;

	/**
	 * The 64-byte Ed25519 signature.
	 *
	 * @var string
	 */
	private string $signature;

	/**
	 * Construct a signature block from a key id and signature.
	 *
	 * @param string $key_id    The key id; must be exactly KEY_ID_SIZE bytes.
	 * @param string $signature The Ed25519 signature; must be exactly SIGNATURE_SIZE bytes.
	 * @throws InvalidArgumentException If the key id or signature is the wrong length.
	 */
	public function __construct( string $key_id, string $signature ) {
		if ( self::KEY_ID_SIZE !== strlen( $key_id ) ) {
			throw new InvalidArgumentException(
				sprintf( 'ArchiveSignature: key id must be exactly %d bytes, got %d.', (int) self::KEY_ID_SIZE, (int) strlen( $key_id ) )
			);
		}
		if ( self::SIGNATURE_SIZE !== strlen( $signature ) ) {
			throw new InvalidArgumentException(
				sprintf( 'ArchiveSignature: signature must be exactly %d bytes, got %d.', (int) self::SIGNATURE_SIZE, (int) strlen( $signature ) )
			);
		}

		$this->key_id    = $key_id;
		$this->signature = $signature;
	}

	/**
	 * Return the key id.
	 *
	 * @return string The 32-byte key id.
	 */
	public function key_id(): string {
		return $this->key_id;
	}

	/**
	 * Return the Ed25519 signature.
	 *
	 * @return string The 64-byte signature.
	 */
	public function signature(): string {
		return $this->signature;
	}

	/**
	 * Serialise the block to its 100-byte on-disk representation.
	 *
	 * @return string Exactly 100 bytes: 32 key id + 4 sig length (BE, =0x40) + 64 sig bytes.
	 */
	public function to_bytes(): string {
		return $this->key_id
			. ByteOrder::pack_uint32( self::SIGNATURE_SIZE )
			. $this->signature;
	}

	/**
	 * Parse a 100-byte on-disk signature block into an ArchiveSignature.
	 *
	 * Rejects a sig-length field that is not exactly SIGNATURE_SIZE (0x40): a v1
	 * reader understands only Ed25519's 64-byte signature.
	 *
	 * @param string $bytes Exactly 100 bytes representing a signature block on disk.
	 * @return self An ArchiveSignature value object reflecting the parsed bytes.
	 * @throws InvalidArgumentException If $bytes is the wrong length or the sig-length field is not 0x40.
	 */
	public static function from_bytes( string $bytes ): self {
		if ( strlen( $bytes ) !== self::SIZE ) {
			throw new InvalidArgumentException(
				sprintf(
					'ArchiveSignature::from_bytes: expected exactly %d bytes, got %d.',
					(int) self::SIZE,
					(int) strlen( $bytes )
				)
			);
		}

		$key_id     = substr( $bytes, 0, self::KEY_ID_SIZE );
		$sig_length = ByteOrder::unpack_uint32( substr( $bytes, self::KEY_ID_SIZE, self::SIG_LENGTH_PREFIX_SIZE ) );
		if ( self::SIGNATURE_SIZE !== $sig_length ) {
			throw new InvalidArgumentException(
				sprintf(
					'ArchiveSignature::from_bytes: sig length must be %d (Ed25519), got %d.',
					(int) self::SIGNATURE_SIZE,
					(int) $sig_length
				)
			);
		}
		$signature = substr( $bytes, self::KEY_ID_SIZE + self::SIG_LENGTH_PREFIX_SIZE, self::SIGNATURE_SIZE );

		return new self( $key_id, $signature );
	}
}
