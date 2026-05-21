<?php
/**
 * Contract for archive-entry codecs.
 *
 * @package Pontifex\Archive\Codec
 */

declare(strict_types=1);

namespace Pontifex\Archive\Codec;

/**
 * Codec contract for archive-entry encoding and decoding.
 *
 * A codec is responsible for transforming the bytes of a single archive
 * entry as it is written to or read from a Pontifex archive file. The
 * transform may be the identity (no compression, no encryption), a
 * compression step (e.g. gzip, zstd), an encryption step, or a
 * composition of the two.
 *
 * The two-byte codec identifier is defined in the archive format
 * specification (`ARCHIVE-FORMAT.md`, §7). Implementations expose the
 * identifier through the {@see Codec::id()} method, and are recommended
 * to also expose it as a public class constant `ID` so the codec
 * registry can resolve identifiers to classes without instantiation.
 *
 * Streaming contract: every implementation must process its input in a
 * streaming fashion. An implementation that buffers the entire payload
 * in memory violates the contract and will fail the large-payload
 * tests in the test suite.
 */
interface Codec {

	/**
	 * Return the two-byte codec identifier as defined in the archive format spec.
	 *
	 * @return int The codec identifier in the range 0x0000-0xFFFF.
	 */
	public function id(): int;

	/**
	 * Read raw bytes from the input stream and write encoded bytes to the output stream.
	 *
	 * Reads from the current position of $input until end-of-stream.
	 * Writes to the current position of $output. The caller is
	 * responsible for stream positioning before and after the call.
	 *
	 * @param resource $input  A readable stream resource.
	 * @param resource $output A writable stream resource.
	 * @return int The number of bytes written to $output.
	 * @throws CodecException On read failure, write failure, or codec-internal error.
	 */
	public function encode( $input, $output ): int;

	/**
	 * Read encoded bytes from the input stream and write decoded bytes to the output stream.
	 *
	 * Reads from the current position of $input until end-of-stream.
	 * Writes to the current position of $output. The caller is
	 * responsible for stream positioning before and after the call.
	 *
	 * @param resource $input  A readable stream resource.
	 * @param resource $output A writable stream resource.
	 * @return int The number of bytes written to $output.
	 * @throws CodecException On read failure, write failure, or codec-internal error.
	 */
	public function decode( $input, $output ): int;
}
