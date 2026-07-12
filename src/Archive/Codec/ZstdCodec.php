<?php
/**
 * Zstandard codec — codec 0x0002.
 *
 * @package Pontifex\Archive\Codec
 */

declare(strict_types=1);

namespace Pontifex\Archive\Codec;

/**
 * Codec 0x0002 — Zstandard compression, no encryption.
 *
 * The zstd counterpart to {@see GzipCodec}: lossless Zstandard compression
 * and decompression for archive entries (`ARCHIVE-FORMAT.md` §7). Uses
 * ext-zstd's incremental API (zstd_compress_init / zstd_compress_add /
 * zstd_uncompress_init / zstd_uncompress_add) so payloads stream
 * chunk-by-chunk rather than being buffered whole in memory — the same
 * streaming contract GzipCodec meets with PHP's incremental zlib API.
 *
 * zstd is an **optional** extension. The format reserves codec 0x0002 for
 * it and writers prefer it only when `ext-zstd` is present, falling back to
 * gzip otherwise (§7). This codec is therefore constructible and
 * registerable without the extension — so the registry can always resolve
 * 0x0002 — but {@see encode()} and {@see decode()} check the extension is
 * loaded and raise a clear {@see CodecException} when it is not, so an
 * archive that uses 0x0002 fails with an actionable message on a host that
 * lacks ext-zstd rather than a cryptic undefined-function fatal.
 *
 * Stateless beyond the configured chunk size; safe to reuse across entries.
 */
final class ZstdCodec implements Codec {

	/**
	 * Two-byte codec identifier as defined in the archive format spec.
	 *
	 * Exposed as a class constant in addition to {@see Codec::id()} so the
	 * codec registry can resolve identifiers to classes without instantiating
	 * each candidate.
	 *
	 * @var int
	 */
	public const ID = 0x0002;

	/**
	 * Default chunk size for streaming reads and writes: 8 KiB.
	 *
	 * Matches PHP's default stream buffer size and {@see GzipCodec}'s default.
	 *
	 * @var int
	 */
	public const DEFAULT_CHUNK_SIZE = 8192;

	/**
	 * Maximum permitted chunk size: 1 MiB.
	 *
	 * Larger values offer diminishing returns and risk unnecessary memory
	 * pressure on constrained hosts. Anything beyond this is almost certainly
	 * a programmer error.
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
	 * Construct a zstd codec with the given streaming chunk size.
	 *
	 * Does not require ext-zstd: a codec can be constructed and registered on
	 * a host without the extension so the registry can resolve codec 0x0002;
	 * the extension is required only at encode/decode time.
	 *
	 * @param int $chunk_size Chunk size in bytes (1 to MAX_CHUNK_SIZE inclusive).
	 * @throws CodecException If the chunk size is outside the valid range.
	 */
	public function __construct( int $chunk_size = self::DEFAULT_CHUNK_SIZE ) {
		if ( $chunk_size < 1 || $chunk_size > self::MAX_CHUNK_SIZE ) {
			throw new CodecException(
				sprintf(
					'ZstdCodec: chunk size %d is out of range (1..%d).',
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
	 * @return int The codec identifier (always ZstdCodec::ID).
	 */
	public function id(): int {
		return self::ID;
	}

	/**
	 * Read raw bytes from $input and write zstd-compressed bytes to $output.
	 *
	 * Streams the input through ext-zstd's incremental compressor, flushing a
	 * final frame at end-of-input. Even an empty input produces a small zstd
	 * frame (a few bytes of header and footer); this is how the format works,
	 * not a bug.
	 *
	 * When $on_read is supplied it is called with each chunk's raw input byte
	 * count as the input streams in, so a caller can report byte-level progress;
	 * it has no effect on the bytes written.
	 *
	 * @param resource      $input   A readable stream resource.
	 * @param resource      $output  A writable stream resource.
	 * @param callable|null $on_read Optional progress callback, called as `( int $bytes ): void` with each chunk's raw input byte count.
	 * @return int The number of bytes written to $output.
	 * @throws CodecException If ext-zstd is unavailable, on read/write failure, or on a zstd-internal error.
	 */
	public function encode( $input, $output, ?callable $on_read = null ): int {
		$this->assert_available();
		$this->assert_streams( $input, $output );

		$ctx = zstd_compress_init();
		if ( false === $ctx ) {
			throw new CodecException( 'ZstdCodec: zstd_compress_init() failed.' );
		}

		$written = 0;

		while ( true ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Codec operates on arbitrary stream resources from the archive layer; WP_Filesystem has no streaming API and is the wrong abstraction for byte-stream codecs.
			$chunk = fread( $input, $this->chunk_size );
			if ( false === $chunk ) {
				throw new CodecException( 'ZstdCodec: fread() failed during encode.' );
			}
			if ( '' === $chunk ) {
				break;
			}

			if ( null !== $on_read ) {
				$on_read( strlen( $chunk ) );
			}

			$compressed = zstd_compress_add( $ctx, $chunk, false );
			if ( false === $compressed ) {
				throw new CodecException( 'ZstdCodec: zstd_compress_add() failed during encode.' );
			}

			$written += $this->write_to_stream( $output, $compressed );
		}

		$final = zstd_compress_add( $ctx, '', true );
		if ( false === $final ) {
			throw new CodecException( 'ZstdCodec: zstd_compress_add() failed to finalise the encode stream.' );
		}

		$written += $this->write_to_stream( $output, $final );

		return $written;
	}

	/**
	 * Read zstd-compressed bytes from $input and write decoded bytes to $output.
	 *
	 * An empty input produces an empty output (zero bytes written), matching
	 * the codec interface's symmetry for empty inputs. A non-empty but
	 * malformed input raises CodecException.
	 *
	 * When $max_output_bytes is non-null, decoding aborts with a CodecException
	 * the moment the running output count exceeds it. The check runs after each
	 * inflated chunk, so a hostile payload can overshoot by at most one chunk
	 * before being refused. This is the defence against zstd decompression
	 * bombs.
	 *
	 * @param resource $input            A readable stream resource.
	 * @param resource $output           A writable stream resource.
	 * @param int|null $max_output_bytes Maximum bytes to write before refusing, or null for no limit.
	 * @return int The number of bytes written to $output.
	 * @throws CodecException If ext-zstd is unavailable, on read/write failure, on decoded output exceeding $max_output_bytes, or on malformed zstd input.
	 */
	public function decode( $input, $output, ?int $max_output_bytes = null ): int {
		$this->assert_available();
		$this->assert_streams( $input, $output );

		$ctx = zstd_uncompress_init();
		if ( false === $ctx ) {
			throw new CodecException( 'ZstdCodec: zstd_uncompress_init() failed.' );
		}

		$written = 0;

		while ( true ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Codec operates on arbitrary stream resources from the archive layer; WP_Filesystem has no streaming API and is the wrong abstraction for byte-stream codecs.
			$chunk = fread( $input, $this->chunk_size );
			if ( false === $chunk ) {
				throw new CodecException( 'ZstdCodec: fread() failed during decode.' );
			}
			if ( '' === $chunk ) {
				break;
			}

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- zstd_uncompress_add() emits a warning on malformed input before returning false; we already handle the false return by throwing CodecException, so the warning is redundant noise.
			$decompressed = @zstd_uncompress_add( $ctx, $chunk );
			if ( false === $decompressed ) {
				throw new CodecException( 'ZstdCodec: zstd_uncompress_add() failed; input may be malformed or truncated.' );
			}

			$written += $this->write_to_stream( $output, $decompressed );

			if ( null !== $max_output_bytes && $written > $max_output_bytes ) {
				throw new CodecException(
					sprintf( 'ZstdCodec: decoded output exceeded the maximum of %d bytes.', (int) $max_output_bytes )
				);
			}
		}

		return $written;
	}

	/**
	 * Assert that the zstd PHP extension is loaded.
	 *
	 * @return void
	 * @throws CodecException If ext-zstd is not loaded.
	 */
	private function assert_available(): void {
		if ( ! extension_loaded( 'zstd' ) ) {
			throw new CodecException( 'ZstdCodec: the zstd PHP extension (ext-zstd) is required for codec 0x0002 but is not loaded.' );
		}
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
			throw new CodecException( 'ZstdCodec: input argument is not a valid stream resource.' );
		}
		if ( ! is_resource( $output ) ) {
			throw new CodecException( 'ZstdCodec: output argument is not a valid stream resource.' );
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
			throw new CodecException( 'ZstdCodec: fwrite() failed during codec operation.' );
		}
		return $count;
	}
}
