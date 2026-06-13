<?php
/**
 * Raw passthrough codec — codec 0x0000.
 *
 * @package Pontifex\Archive\Codec
 */

declare(strict_types=1);

namespace Pontifex\Archive\Codec;

/**
 * Codec 0x0000 — no compression, no encryption.
 *
 * The mandatory baseline codec defined by the archive format spec
 * (`ARCHIVE-FORMAT.md`, §7.1). Reads bytes from the input stream and
 * writes them to the output stream unchanged. Serves two roles:
 *
 *  1. The format-mandated identity codec, used for entries that are
 *     already compressed at the source (e.g. JPEG, PNG, video files)
 *     where additional compression would waste CPU cycles for no
 *     space saving.
 *
 *  2. A test harness for the rest of the archive machinery. When a
 *     codec bug is suspected, falling back to RawCodec isolates
 *     whether the fault lies in the codec layer or in the surrounding
 *     writer/reader code.
 *
 * RawCodec is intentionally stateless. Encoding and decoding are
 * symmetric — both delegate to the same underlying stream-copy
 * primitive.
 */
final class RawCodec implements Codec {

	/**
	 * Two-byte codec identifier as defined in the archive format spec.
	 *
	 * Exposed as a class constant in addition to the {@see Codec::id()}
	 * method so the codec registry (introduced in commit 2) can resolve
	 * identifiers to classes without instantiating each candidate.
	 *
	 * @var int
	 */
	public const ID = 0x0000;

	/**
	 * Return the two-byte codec identifier.
	 *
	 * @return int The codec identifier (always RawCodec::ID).
	 */
	public function id(): int {
		return self::ID;
	}

	/**
	 * Read raw bytes from the input stream and write them to the output stream unchanged.
	 *
	 * @param resource $input  A readable stream resource.
	 * @param resource $output A writable stream resource.
	 * @return int The number of bytes written to $output.
	 * @throws CodecException On read or write failure.
	 */
	public function encode( $input, $output ): int {
		return $this->stream_copy( $input, $output, null );
	}

	/**
	 * Read raw bytes from the input stream and write them to the output stream unchanged.
	 *
	 * Decoding is symmetric with encoding for the raw codec. Raw bytes
	 * cannot expand, so a cap is largely belt-and-braces here, but it is
	 * honoured for contract consistency: if more than $max_output_bytes
	 * bytes are present, the copy is refused with a CodecException.
	 *
	 * @param resource $input            A readable stream resource.
	 * @param resource $output           A writable stream resource.
	 * @param int|null $max_output_bytes Maximum bytes to write before refusing, or null for no limit.
	 * @return int The number of bytes written to $output.
	 * @throws CodecException On read or write failure, or decoded output exceeding $max_output_bytes.
	 */
	public function decode( $input, $output, ?int $max_output_bytes = null ): int {
		return $this->stream_copy( $input, $output, $max_output_bytes );
	}

	/**
	 * Copy bytes from one stream to another in a memory-bounded way.
	 *
	 * Uses PHP's native stream_copy_to_stream(), which internally
	 * buffers a small chunk at a time rather than materialising the
	 * whole payload in memory. This is what gives RawCodec its
	 * streaming guarantee.
	 *
	 * When $max_output_bytes is non-null, at most that many bytes are
	 * copied; if any input remains afterwards the payload was larger
	 * than the ceiling and a CodecException is raised.
	 *
	 * @param resource $input            A readable stream resource.
	 * @param resource $output           A writable stream resource.
	 * @param int|null $max_output_bytes Maximum bytes to copy before refusing, or null for no limit.
	 * @return int The number of bytes written to $output.
	 * @throws CodecException If either argument is not a stream resource, the copy fails mid-stream, or the input exceeds $max_output_bytes.
	 */
	private function stream_copy( $input, $output, ?int $max_output_bytes ): int {
		if ( ! is_resource( $input ) ) {
			throw new CodecException( 'RawCodec: input argument is not a valid stream resource.' );
		}
		if ( ! is_resource( $output ) ) {
			throw new CodecException( 'RawCodec: output argument is not a valid stream resource.' );
		}

		if ( null === $max_output_bytes ) {
			$written = stream_copy_to_stream( $input, $output );
			if ( false === $written ) {
				throw new CodecException( 'RawCodec: stream_copy_to_stream() failed during codec operation.' );
			}
			return $written;
		}

		$written = stream_copy_to_stream( $input, $output, $max_output_bytes );
		if ( false === $written ) {
			throw new CodecException( 'RawCodec: stream_copy_to_stream() failed during codec operation.' );
		}

		// Any bytes still readable mean the payload exceeded the ceiling.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Probing an in-process codec stream for overflow; WP_Filesystem has no streaming API and is the wrong abstraction for byte-stream codecs.
		$overflow = fread( $input, 1 );
		if ( false !== $overflow && '' !== $overflow ) {
			throw new CodecException(
				sprintf( 'RawCodec: decoded output exceeded the maximum of %d bytes.', (int) $max_output_bytes )
			);
		}

		return $written;
	}
}
