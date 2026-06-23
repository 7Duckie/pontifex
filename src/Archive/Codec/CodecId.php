<?php
/**
 * Codec-id decomposition for the compression/encryption byte scheme.
 *
 * @package Pontifex\Archive\Codec
 */

declare(strict_types=1);

namespace Pontifex\Archive\Codec;

/**
 * Splits and composes the two-byte codec id: high byte = encryption, low byte = compression.
 *
 * The archive format packs the combination of compression and encryption
 * applied to an entry into one two-byte codec id, where the low byte is the
 * compression family (0x00 none, 0x01 gzip, 0x02 zstd) and the high byte is
 * the encryption family (0x00 none, 0x01 AES-256-GCM) — so 0x0102 reads as
 * "AES-256-GCM over zstd" (`ARCHIVE-FORMAT.md` §7, §13.2.3).
 *
 * Both the writer and the reader have to split a codec id into those two
 * halves, and an off-by-one in the masks would let them disagree and corrupt
 * an archive. Centralising the split here — the role {@see ByteOrder} plays
 * for byte order — keeps the one rule in one place. All static; not
 * instantiable.
 */
final class CodecId {

	/**
	 * Encryption-family byte for AES-256-GCM, the only encryption mode v1 defines.
	 *
	 * @var int
	 */
	public const ENCRYPTION_AES_GCM = 0x0100;

	/**
	 * Mask selecting the compression-family low byte of a codec id.
	 *
	 * @var int
	 */
	public const COMPRESSION_MASK = 0x00FF;

	/**
	 * Mask selecting the encryption-family high byte of a codec id.
	 *
	 * @var int
	 */
	public const ENCRYPTION_MASK = 0xFF00;

	/**
	 * Prevent instantiation; this class exposes only static helpers.
	 */
	private function __construct() {
	}

	/**
	 * Return the compression-family codec id (the low byte) of a codec id.
	 *
	 * @param int $codec_id The full two-byte codec id.
	 * @return int The compression codec id, in the range 0x0000-0x00FF.
	 */
	public static function compression( int $codec_id ): int {
		return $codec_id & self::COMPRESSION_MASK;
	}

	/**
	 * Return the encryption-family byte (the high byte) of a codec id.
	 *
	 * @param int $codec_id The full two-byte codec id.
	 * @return int The encryption family: 0x0000 for none, 0x0100 for AES-256-GCM.
	 */
	public static function encryption_family( int $codec_id ): int {
		return $codec_id & self::ENCRYPTION_MASK;
	}

	/**
	 * Whether a codec id marks the entry as encrypted.
	 *
	 * @param int $codec_id The full two-byte codec id.
	 * @return bool True when the encryption-family byte is non-zero.
	 */
	public static function is_encrypted( int $codec_id ): bool {
		return 0 !== ( $codec_id & self::ENCRYPTION_MASK );
	}

	/**
	 * Return a codec id with the AES-256-GCM encryption family set.
	 *
	 * Upgrades a compression-only codec id (e.g. 0x0002 zstd) to its encrypted
	 * variant (0x0102) by setting the encryption-family byte.
	 *
	 * @param int $codec_id The compression-only codec id.
	 * @return int The codec id with AES-256-GCM applied.
	 */
	public static function with_aes_gcm( int $codec_id ): int {
		return $codec_id | self::ENCRYPTION_AES_GCM;
	}
}
