<?php
/**
 * Big-endian byte-order primitives for Pontifex archive serialisation.
 *
 * @package Pontifex\Archive\Format
 */

declare(strict_types=1);

namespace Pontifex\Archive\Format;

use InvalidArgumentException;
use RuntimeException;

/**
 * Big-endian pack and unpack helpers for unsigned integers.
 *
 * The archive format spec (ARCHIVE-FORMAT.md §4 and §13.2.1) mandates
 * big-endian byte order for every multi-byte integer field: the
 * 16-bit format major and minor versions, the 32-bit flags and
 * length prefixes throughout the format, the 64-bit manifest offset
 * and length in the footer, and every per-entry size in the manifest.
 * This class is the single point of dependence for that contract —
 * every byte-level integer serialisation in Pontifex goes through
 * these six methods, so the byte order is locked in one place and
 * verified by one test file.
 *
 * Why a dedicated class rather than calling pack() and unpack()
 * directly throughout the codebase? Two reasons:
 *
 *  1. PHP's pack format characters for big-endian (n, N, J) are
 *     visually similar to the little-endian variants (v, V, P). A
 *     typo would silently produce wrong-ordered bytes that look
 *     valid to PHP but render the archive unreadable to a
 *     spec-conforming reader. Routing every serialisation through
 *     these named methods makes the byte order explicit and a typo
 *     impossible to introduce except at this single location.
 *  2. Range and length validation. PHP's pack() silently truncates
 *     values that exceed the declared width, and unpack() silently
 *     pads or trims mismatched inputs. We want loud failure for
 *     either, since silent corruption in archive serialisation is
 *     the worst possible failure mode.
 *
 * uint64 caveat: PHP integers are signed 64-bit, so the maximum
 * value representable as a PHP int is PHP_INT_MAX (2^63 - 1), not
 * the full unsigned 2^64 - 1. Pontifex's uint64 fields are file
 * offsets and byte counts; the cap corresponds to a nine-exabyte
 * archive and so does not constrain any real use case. pack_uint64()
 * accepts the full signed range, and unpack_uint64() throws if the
 * bytes represent a value with the high bit set.
 */
final class ByteOrder {

	/**
	 * Size of a uint16 field in bytes (2).
	 *
	 * @var int
	 */
	public const UINT16_SIZE = 2;

	/**
	 * Size of a uint32 field in bytes (4).
	 *
	 * @var int
	 */
	public const UINT32_SIZE = 4;

	/**
	 * Size of a uint64 field in bytes (8).
	 *
	 * @var int
	 */
	public const UINT64_SIZE = 8;

	/**
	 * Maximum value representable as a uint16 (0xFFFF = 65535).
	 *
	 * @var int
	 */
	public const MAX_UINT16 = 0xFFFF;

	/**
	 * Maximum value representable as a uint32 (0xFFFFFFFF = 4294967295).
	 *
	 * @var int
	 */
	public const MAX_UINT32 = 0xFFFFFFFF;

	/**
	 * Maximum value Pontifex accepts in a uint64 field (PHP_INT_MAX = 2^63 - 1).
	 *
	 * Lower than a true unsigned 64-bit maximum because PHP integers
	 * are signed 64-bit. Real Pontifex uint64 values (file offsets,
	 * byte counts) never approach this limit.
	 *
	 * @var int
	 */
	public const MAX_UINT64 = PHP_INT_MAX;

	/**
	 * Prevent instantiation; this class exposes only static helpers.
	 */
	private function __construct() {
	}

	/**
	 * Pack an unsigned 16-bit integer into 2 big-endian bytes.
	 *
	 * @param int $value The integer to pack; must be in the range [0, 0xFFFF].
	 * @return string Exactly 2 bytes representing $value in big-endian order.
	 * @throws InvalidArgumentException If $value is negative or exceeds the uint16 maximum.
	 */
	public static function pack_uint16( int $value ): string {
		if ( $value < 0 ) {
			throw new InvalidArgumentException(
				sprintf(
					'ByteOrder::pack_uint16: value %d is negative; unsigned integers must be non-negative.',
					(int) $value
				)
			);
		}
		if ( $value > self::MAX_UINT16 ) {
			throw new InvalidArgumentException(
				sprintf(
					'ByteOrder::pack_uint16: value %d exceeds the uint16 maximum of %d.',
					(int) $value,
					(int) self::MAX_UINT16
				)
			);
		}
		return pack( 'n', $value );
	}

	/**
	 * Unpack 2 big-endian bytes into an unsigned 16-bit integer.
	 *
	 * @param string $bytes Exactly 2 bytes of big-endian uint16 data.
	 * @return int The integer value in the range [0, 0xFFFF].
	 * @throws InvalidArgumentException If $bytes is not exactly 2 bytes long.
	 * @throws RuntimeException         If PHP's unpack() unexpectedly returns false.
	 */
	public static function unpack_uint16( string $bytes ): int {
		if ( strlen( $bytes ) !== self::UINT16_SIZE ) {
			throw new InvalidArgumentException(
				sprintf(
					'ByteOrder::unpack_uint16: expected exactly %d bytes, got %d.',
					(int) self::UINT16_SIZE,
					(int) strlen( $bytes )
				)
			);
		}
		$unpacked = unpack( 'n', $bytes );
		if ( false === $unpacked ) {
			throw new RuntimeException( 'ByteOrder::unpack_uint16: unpack() returned false unexpectedly.' );
		}
		return (int) $unpacked[1];
	}

	/**
	 * Pack an unsigned 32-bit integer into 4 big-endian bytes.
	 *
	 * @param int $value The integer to pack; must be in the range [0, 0xFFFFFFFF].
	 * @return string Exactly 4 bytes representing $value in big-endian order.
	 * @throws InvalidArgumentException If $value is negative or exceeds the uint32 maximum.
	 */
	public static function pack_uint32( int $value ): string {
		if ( $value < 0 ) {
			throw new InvalidArgumentException(
				sprintf(
					'ByteOrder::pack_uint32: value %d is negative; unsigned integers must be non-negative.',
					(int) $value
				)
			);
		}
		if ( $value > self::MAX_UINT32 ) {
			throw new InvalidArgumentException(
				sprintf(
					'ByteOrder::pack_uint32: value %d exceeds the uint32 maximum of %d.',
					(int) $value,
					(int) self::MAX_UINT32
				)
			);
		}
		return pack( 'N', $value );
	}

	/**
	 * Unpack 4 big-endian bytes into an unsigned 32-bit integer.
	 *
	 * @param string $bytes Exactly 4 bytes of big-endian uint32 data.
	 * @return int The integer value in the range [0, 0xFFFFFFFF].
	 * @throws InvalidArgumentException If $bytes is not exactly 4 bytes long.
	 * @throws RuntimeException         If PHP's unpack() unexpectedly returns false.
	 */
	public static function unpack_uint32( string $bytes ): int {
		if ( strlen( $bytes ) !== self::UINT32_SIZE ) {
			throw new InvalidArgumentException(
				sprintf(
					'ByteOrder::unpack_uint32: expected exactly %d bytes, got %d.',
					(int) self::UINT32_SIZE,
					(int) strlen( $bytes )
				)
			);
		}
		$unpacked = unpack( 'N', $bytes );
		if ( false === $unpacked ) {
			throw new RuntimeException( 'ByteOrder::unpack_uint32: unpack() returned false unexpectedly.' );
		}
		return (int) $unpacked[1];
	}

	/**
	 * Pack an unsigned 64-bit integer into 8 big-endian bytes.
	 *
	 * Pontifex caps uint64 values at PHP_INT_MAX (2^63 - 1) because
	 * PHP integers are signed 64-bit. This covers every realistic
	 * Pontifex file offset or byte count by many orders of magnitude.
	 *
	 * @param int $value The integer to pack; must be in the range [0, PHP_INT_MAX].
	 * @return string Exactly 8 bytes representing $value in big-endian order.
	 * @throws InvalidArgumentException If $value is negative.
	 */
	public static function pack_uint64( int $value ): string {
		if ( $value < 0 ) {
			throw new InvalidArgumentException(
				sprintf(
					'ByteOrder::pack_uint64: value %d is negative; unsigned integers must be non-negative.',
					(int) $value
				)
			);
		}
		return pack( 'J', $value );
	}

	/**
	 * Unpack 8 big-endian bytes into an unsigned 64-bit integer.
	 *
	 * Rejects bytes that represent a value with the high bit set,
	 * because PHP's signed integers cannot safely hold values above
	 * PHP_INT_MAX (2^63 - 1) and Pontifex never legitimately writes
	 * such values.
	 *
	 * @param string $bytes Exactly 8 bytes of big-endian uint64 data.
	 * @return int The integer value in the range [0, PHP_INT_MAX].
	 * @throws InvalidArgumentException If $bytes is the wrong length or represents a value above PHP_INT_MAX.
	 * @throws RuntimeException         If PHP's unpack() unexpectedly returns false.
	 */
	public static function unpack_uint64( string $bytes ): int {
		if ( strlen( $bytes ) !== self::UINT64_SIZE ) {
			throw new InvalidArgumentException(
				sprintf(
					'ByteOrder::unpack_uint64: expected exactly %d bytes, got %d.',
					(int) self::UINT64_SIZE,
					(int) strlen( $bytes )
				)
			);
		}
		$unpacked = unpack( 'J', $bytes );
		if ( false === $unpacked ) {
			throw new RuntimeException( 'ByteOrder::unpack_uint64: unpack() returned false unexpectedly.' );
		}
		$value = (int) $unpacked[1];
		if ( $value < 0 ) {
			throw new InvalidArgumentException(
				'ByteOrder::unpack_uint64: bytes represent a value with the high bit set; Pontifex does not support uint64 values above PHP_INT_MAX.'
			);
		}
		return $value;
	}
}
