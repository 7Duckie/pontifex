<?php
/**
 * Pontifex archive footer writer — emits the 64-byte footer to a stream per spec §10.
 *
 * @package Pontifex\Archive\Writer
 */

declare(strict_types=1);

namespace Pontifex\Archive\Writer;

use InvalidArgumentException;
use RuntimeException;
use Pontifex\Archive\Format\Footer;

/**
 * Writes the 64-byte archive footer to a destination stream.
 *
 * Per ARCHIVE-FORMAT.md §10, every archive ends with a 64-byte footer
 * at a known position (last 64 bytes of the file, or last 64 bytes
 * before an optional detached-signature block). A reader can seek to
 * the end of the file, step back 64 bytes, and find this block. From
 * it the reader learns:
 *
 *  - where the manifest is (manifest_offset)
 *  - how big the manifest is (manifest_length)
 *  - what hash the manifest should have (manifest_hash)
 *  - what salt was used for key derivation, when encryption is in
 *    use (argon2id_salt; zeros in unencrypted v0.1.0 archives)
 *
 * FooterWriter is intentionally thin. The {@see Footer} value object
 * already serialises itself via Footer::to_bytes() and validates its
 * fields at construction time. FooterWriter just persists those bytes
 * to a destination stream with partial-write detection.
 *
 * Stream positioning: the destination stream's seek position is the
 * caller's concern. FooterWriter writes from wherever the destination
 * is and advances it by Footer::SIZE bytes (64).
 *
 * Threading and reuse: FooterWriter is stateless. The write_footer()
 * method is safe to call any number of times.
 */
final class FooterWriter {

	/**
	 * Write a footer's 64 bytes to the destination stream.
	 *
	 * @param Footer   $footer      The footer to serialise and write.
	 * @param resource $destination Writable stream resource. Bytes are appended at
	 *                              the destination's current seek position. The
	 *                              destination is advanced by Footer::SIZE bytes.
	 * @return int The number of bytes written (always Footer::SIZE).
	 * @throws InvalidArgumentException If $destination is not a stream resource.
	 * @throws RuntimeException         If fwrite() fails or detects a partial write.
	 */
	public function write_footer( Footer $footer, $destination ): int {
		if ( ! is_resource( $destination ) ) {
			throw new InvalidArgumentException( 'FooterWriter: $destination must be a valid stream resource.' );
		}

		$bytes  = $footer->to_bytes();
		$length = strlen( $bytes );

		// Partial-write detection: short writes do not occur on the streams Pontifex uses,
		// but the check is cheap and prevents silent corruption when something unexpected happens.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- FooterWriter operates on arbitrary stream resources from the archive layer; WP_Filesystem has no streaming API.
		$written = fwrite( $destination, $bytes );
		if ( false === $written ) {
			throw new RuntimeException( 'FooterWriter: fwrite() failed on destination stream.' );
		}
		if ( $written !== $length ) {
			throw new RuntimeException(
				sprintf(
					'FooterWriter: partial write detected (%d of %d bytes); aborting to preserve footer integrity.',
					(int) $written,
					(int) $length
				)
			);
		}

		return $length;
	}
}
