<?php
/**
 * Gzip codec — codec 0x0001.
 *
 * @package Pontifex\Archive\Codec
 */

declare(strict_types=1);

namespace Pontifex\Archive\Codec;

/**
 * Codec 0x0001 — gzip compression, no encryption.
 *
 * Implements lossless gzip compression and decompression for archive
 * entries as defined in `ARCHIVE-FORMAT.md` §7.2. Uses PHP's
 * incremental deflate API (deflate_init / deflate_add / inflate_init /
 * inflate_add) so payloads stream through chunk-by-chunk rather than
 * being buffered whole in memory.
 *
 * The chunk size is configurable through the constructor. The default
 * of 8 KiB matches PHP's default stream buffer and is a sensible
 * choice for the vast majority of workloads. Callers who have
 * benchmarked their specific environment and have a reason to deviate
 * may pass a different value, bounded above by MAX_CHUNK_SIZE to
 * catch obvious mistakes.
 *
 * GzipCodec is stateless beyond the configured chunk size: encoding
 * and decoding contexts are created and destroyed within each call,
 * so a single instance is safe to reuse across many archive entries.
 */
final class GzipCodec implements Codec {

	/**
	 * Two-byte codec identifier as defined in the archive format spec.
	 *
	 * Exposed as a class constant in addition to the {@see Codec::id()}
	 * method so the codec registry can resolve identifiers to classes
	 * without instantiating each candidate.
	 *
	 * @var int
	 */
	public const ID = 0x0001;

	/**
	 * Default chunk size for streaming reads and writes: 8 KiB.
	 *
	 * Matches PHP's default stream buffer size, which avoids surprising
	 * interactions with the underlying I/O layer.
	 *
	 * @var int
	 */
	public const DEFAULT_CHUNK_SIZE = 8192;

	/**
	 * Maximum permitted chunk size: 1 MiB.
	 *
	 * Larger values offer diminishing returns and risk unnecessary
	 * memory pressure on constrained hosts. Anything beyond this is
	 * almost certainly a programmer error.
	 *
	 * @var int
	 */
	public const MAX_CHUNK_SIZE = 1048576;

	/**
	 * Streaming chunk size used by this codec instance.
	 *
	 * @var int
	 */
	private int $chunk_size;

	/**
	 * Construct a gzip codec with the given streaming chunk size.
	 *
	 * @param int $chunk_size Chunk size in bytes (1 to MAX_CHUNK_SIZE inclusive).
	 * @throws CodecException If the chunk size is outside the valid range.
	 */
	public function __construct( int $chunk_size = self::DEFAULT_CHUNK_SIZE ) {
		if ( $chunk_size < 1 || $chunk_size > self::MAX_CHUNK_SIZE ) {
			throw new CodecException(
				sprintf(
					'GzipCodec: chunk size %d is out of range (1..%d).',
					(int) $chunk_size,
					(int) self::MAX_CHUNK_SIZE
				)
			);
		}
		$this->chunk_size = $chunk_size;
	}

	/**
	 * Return the two-byte codec identifier.
	 *
	 * @return int The codec identifier (always GzipCodec::ID).
	 */
	public function id(): int {
		return self::ID;
	}

	/**
	 * Read raw bytes from $input and write gzip-compressed bytes to $output.
	 *
	 * Even when the input is empty, the output will contain the gzip
	 * header and trailer — approximately twenty bytes. This is how the
	 * gzip format works, not a bug.
	 *
	 * @param resource $input  A readable stream resource.
	 * @param resource $output A writable stream resource.
	 * @return int The number of bytes written to $output.
	 * @throws CodecException On read failure, write failure, or zlib-internal error.
	 */
	public function encode( $input, $output ): int {
		$this->assert_streams( $input, $output );

		$ctx = deflate_init( ZLIB_ENCODING_GZIP );
		if ( false === $ctx ) {
			throw new CodecException( 'GzipCodec: deflate_init() failed.' );
		}

		$written = 0;

		while ( true ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Codec operates on arbitrary stream resources from the archive layer; WP_Filesystem has no streaming API and is the wrong abstraction for byte-stream codecs.
			$chunk = fread( $input, $this->chunk_size );
			if ( false === $chunk ) {
				throw new CodecException( 'GzipCodec: fread() failed during encode.' );
			}
			if ( '' === $chunk ) {
				break;
			}

			$compressed = deflate_add( $ctx, $chunk, ZLIB_NO_FLUSH );
			if ( false === $compressed ) {
				throw new CodecException( 'GzipCodec: deflate_add() failed during encode.' );
			}

			$written += $this->write_to_stream( $output, $compressed );
		}

		$final = deflate_add( $ctx, '', ZLIB_FINISH );
		if ( false === $final ) {
			throw new CodecException( 'GzipCodec: deflate_add() failed to finalise the encode stream.' );
		}

		$written += $this->write_to_stream( $output, $final );

		return $written;
	}

	/**
	 * Read gzip-compressed bytes from $input and write decoded bytes to $output.
	 *
	 * An empty input is treated as producing an empty output (zero
	 * bytes written), which matches the symmetry of the codec interface
	 * for empty inputs across all codecs. A non-empty but malformed
	 * input raises CodecException.
	 *
	 * @param resource $input  A readable stream resource.
	 * @param resource $output A writable stream resource.
	 * @return int The number of bytes written to $output.
	 * @throws CodecException On read failure, write failure, or malformed gzip input.
	 */
	public function decode( $input, $output ): int {
		$this->assert_streams( $input, $output );

		$ctx = inflate_init( ZLIB_ENCODING_GZIP );
		if ( false === $ctx ) {
			throw new CodecException( 'GzipCodec: inflate_init() failed.' );
		}

		$written  = 0;
		$any_data = false;

		while ( true ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Codec operates on arbitrary stream resources from the archive layer; WP_Filesystem has no streaming API and is the wrong abstraction for byte-stream codecs.
			$chunk = fread( $input, $this->chunk_size );
			if ( false === $chunk ) {
				throw new CodecException( 'GzipCodec: fread() failed during decode.' );
			}
			if ( '' === $chunk ) {
				break;
			}
			$any_data = true;

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- inflate_add() emits a warning on malformed input before returning false; we already handle the false return by throwing CodecException, so the warning is redundant noise.
			$decompressed = @inflate_add( $ctx, $chunk );
			if ( false === $decompressed ) {
				throw new CodecException( 'GzipCodec: inflate_add() failed; input may be malformed or truncated.' );
			}

			$written += $this->write_to_stream( $output, $decompressed );
		}

		if ( $any_data ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- inflate_add() emits a warning on truncated input before returning false; we already handle the false return by throwing CodecException, so the warning is redundant noise.
			$final = @inflate_add( $ctx, '', ZLIB_FINISH );
			if ( false === $final ) {
				throw new CodecException( 'GzipCodec: inflate_add() failed to finalise the decode stream; input may be truncated.' );
			}
			$written += $this->write_to_stream( $output, $final );
		}

		return $written;
	}

	/**
	 * Validate that both arguments are stream resources.
	 *
	 * @param mixed $input  Argument to check as a readable stream.
	 * @param mixed $output Argument to check as a writable stream.
	 * @return void
	 * @throws CodecException If either argument is not a stream resource.
	 */
	private function assert_streams( $input, $output ): void {
		if ( ! is_resource( $input ) ) {
			throw new CodecException( 'GzipCodec: input argument is not a valid stream resource.' );
		}
		if ( ! is_resource( $output ) ) {
			throw new CodecException( 'GzipCodec: output argument is not a valid stream resource.' );
		}
	}

	/**
	 * Write a byte string to a stream, handling empty strings and write failures.
	 *
	 * @param resource $stream A writable stream resource.
	 * @param string   $bytes  The bytes to write (may be empty).
	 * @return int The number of bytes written.
	 * @throws CodecException If the write fails.
	 */
	private function write_to_stream( $stream, string $bytes ): int {
		if ( '' === $bytes ) {
			return 0;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Codec operates on arbitrary stream resources from the archive layer; WP_Filesystem has no streaming API and is the wrong abstraction for byte-stream codecs.
		$count = fwrite( $stream, $bytes );
		if ( false === $count ) {
			throw new CodecException( 'GzipCodec: fwrite() failed during codec operation.' );
		}
		return $count;
	}
}
