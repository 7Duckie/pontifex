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
		return $this->stream_copy( $input, $output );
	}

	/**
	 * Read raw bytes from the input stream and write them to the output stream unchanged.
	 *
	 * Decoding is symmetric with encoding for the raw codec.
	 *
	 * @param resource $input  A readable stream resource.
	 * @param resource $output A writable stream resource.
	 * @return int The number of bytes written to $output.
	 * @throws CodecException On read or write failure.
	 */
	public function decode( $input, $output ): int {
		return $this->stream_copy( $input, $output );
	}

	/**
	 * Copy bytes from one stream to another in a memory-bounded way.
	 *
	 * Uses PHP's native stream_copy_to_stream(), which internally
	 * buffers a small chunk at a time rather than materialising the
	 * whole payload in memory. This is what gives RawCodec its
	 * streaming guarantee.
	 *
	 * @param resource $input  A readable stream resource.
	 * @param resource $output A writable stream resource.
	 * @return int The number of bytes written to $output.
	 * @throws CodecException If either argument is not a stream resource, or if the copy fails mid-stream.
	 */
	private function stream_copy( $input, $output ): int {
		if ( ! is_resource( $input ) ) {
			throw new CodecException( 'RawCodec: input argument is not a valid stream resource.' );
		}
		if ( ! is_resource( $output ) ) {
			throw new CodecException( 'RawCodec: output argument is not a valid stream resource.' );
		}

		$written = stream_copy_to_stream( $input, $output );

		if ( false === $written ) {
			throw new CodecException( 'RawCodec: stream_copy_to_stream() failed during codec operation.' );
		}

		return $written;
	}
}
