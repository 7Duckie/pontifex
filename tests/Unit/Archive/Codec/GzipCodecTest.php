<?php
/**
 * Behavioural tests for GzipCodec.
 *
 * @package Pontifex\Tests\Unit\Archive\Codec
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Archive\Codec;

use PHPUnit\Framework\TestCase;
use Pontifex\Archive\Codec\Codec;
use Pontifex\Archive\Codec\CodecException;
use Pontifex\Archive\Codec\GzipCodec;

/**
 * Behavioural tests for the GzipCodec class.
 *
 * Verifies the codec's invariants:
 *
 *  - Reports the correct format-mandated identifier (0x0001).
 *  - Constructor validates the chunk size and rejects out-of-range values.
 *  - Encoding produces valid gzip output even for empty input.
 *  - Encoding compresses repetitive content meaningfully.
 *  - Encode then decode is the identity on any byte sequence.
 *  - Round-trip works regardless of the chunk size used.
 *  - Decoding malformed input raises CodecException.
 *  - Encoding raises CodecException on invalid stream resources.
 */
final class GzipCodecTest extends TestCase {

	/**
	 * Helper: produce an in-memory readable stream pre-filled with $bytes.
	 *
	 * The returned stream is rewound to position 0 and ready to read.
	 *
	 * @param string $bytes Payload to write into the stream.
	 * @return resource A readable, rewound php://memory stream.
	 */
	private function readable_stream( string $bytes ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- In-memory php://memory stream for unit testing; WP_Filesystem is for site-level file operations and cannot work with memory streams.
		$stream = fopen( 'php://memory', 'r+b' );
		if ( '' !== $bytes ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Writing to in-memory test stream; WP_Filesystem is for site files, not memory streams.
			fwrite( $stream, $bytes );
		}
		rewind( $stream );
		return $stream;
	}

	/**
	 * Helper: produce an empty in-memory writable stream.
	 *
	 * @return resource A writable php://memory stream at position 0.
	 */
	private function writable_stream() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- In-memory php://memory stream for unit testing; WP_Filesystem is for site-level file operations and cannot work with memory streams.
		return fopen( 'php://memory', 'w+b' );
	}

	/**
	 * Helper: read the full contents of a stream from position 0.
	 *
	 * @param resource $stream A stream resource to drain.
	 * @return string The stream's contents from position 0 to end.
	 */
	private function drain( $stream ): string {
		rewind( $stream );
		return stream_get_contents( $stream );
	}

	/**
	 * GzipCodec must implement the Codec interface.
	 *
	 * @return void
	 */
	public function test_implements_codec_interface(): void {
		$this->assertInstanceOf( Codec::class, new GzipCodec() );
	}

	/**
	 * The codec identifier must be 0x0001 per the archive format spec.
	 *
	 * @return void
	 */
	public function test_id_returns_one(): void {
		$codec = new GzipCodec();

		$this->assertSame( 0x0001, $codec->id() );
		$this->assertSame( 0x0001, GzipCodec::ID );
	}

	/**
	 * The default chunk size must be 8 KiB as documented in the class.
	 *
	 * @return void
	 */
	public function test_default_chunk_size_is_eight_kibibytes(): void {
		$this->assertSame( 8192, GzipCodec::DEFAULT_CHUNK_SIZE );
	}

	/**
	 * Constructing with a chunk size of zero must raise CodecException.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_zero_chunk_size(): void {
		$this->expectException( CodecException::class );

		new GzipCodec( 0 );
	}

	/**
	 * Constructing with a chunk size above the maximum must raise CodecException.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_chunk_size_above_maximum(): void {
		$this->expectException( CodecException::class );

		new GzipCodec( GzipCodec::MAX_CHUNK_SIZE + 1 );
	}

	/**
	 * Constructing with an explicit in-range chunk size must succeed.
	 *
	 * @return void
	 */
	public function test_constructor_accepts_explicit_chunk_size(): void {
		$codec = new GzipCodec( 65536 );

		$this->assertInstanceOf( GzipCodec::class, $codec );
	}

	/**
	 * Encoding an empty input must produce a non-empty gzip stream.
	 *
	 * Gzip always emits at least a header and trailer, even when the
	 * content is empty. The exact size depends on the zlib version but
	 * is consistently around twenty bytes.
	 *
	 * @return void
	 */
	public function test_encode_empty_input_produces_valid_gzip(): void {
		$codec  = new GzipCodec();
		$input  = $this->readable_stream( '' );
		$output = $this->writable_stream();

		$written = $codec->encode( $input, $output );

		$this->assertGreaterThan( 0, $written );
		$this->assertSame( $written, strlen( $this->drain( $output ) ) );
	}

	/**
	 * Encoding a highly compressible payload must produce a smaller output.
	 *
	 * Verifies that compression is actually happening, not just that
	 * the codec is passing bytes through. A 4 KiB payload of repeated
	 * bytes should compress to a small fraction of its original size.
	 *
	 * @return void
	 */
	public function test_encode_compresses_repetitive_payload(): void {
		$payload = str_repeat( 'A', 4096 );
		$codec   = new GzipCodec();
		$input   = $this->readable_stream( $payload );
		$output  = $this->writable_stream();

		$written = $codec->encode( $input, $output );

		$this->assertLessThan( strlen( $payload ), $written );
	}

	/**
	 * Encode then decode must reproduce the original input exactly.
	 *
	 * Uses binary bytes including 0x00, 0xFF, and 0x01 to ensure the
	 * codec handles arbitrary byte sequences, not only text.
	 *
	 * @return void
	 */
	public function test_encode_decode_round_trip_with_binary_bytes(): void {
		$payload = 'gzip round-trip with binary: ' . chr( 0 ) . chr( 255 ) . chr( 1 ) . ' end.';
		$codec   = new GzipCodec();

		$source  = $this->readable_stream( $payload );
		$encoded = $this->writable_stream();
		$codec->encode( $source, $encoded );
		rewind( $encoded );

		$decoded = $this->writable_stream();
		$codec->decode( $encoded, $decoded );

		$this->assertSame( $payload, $this->drain( $decoded ) );
	}

	/**
	 * Encode then decode of a one-megabyte payload must round-trip exactly.
	 *
	 * Exercises the streaming path at a size where any catastrophic
	 * buffering bug would surface, and at a size that crosses several
	 * default chunk boundaries.
	 *
	 * @return void
	 */
	public function test_encode_decode_round_trip_one_megabyte_payload(): void {
		$payload = str_repeat( 'Pontifex archive payload. ', 50000 );
		$this->assertGreaterThan( 1024 * 1024, strlen( $payload ) );

		$codec   = new GzipCodec();
		$source  = $this->readable_stream( $payload );
		$encoded = $this->writable_stream();
		$codec->encode( $source, $encoded );
		rewind( $encoded );

		$decoded = $this->writable_stream();
		$codec->decode( $encoded, $decoded );

		$this->assertSame( $payload, $this->drain( $decoded ) );
	}

	/**
	 * Round-trip must work with a non-default chunk size.
	 *
	 * Verifies that the configurable chunk-size parameter does not
	 * break correctness — a codec constructed with 64 KiB chunks
	 * produces output that any other GzipCodec instance can decode.
	 *
	 * @return void
	 */
	public function test_encode_decode_round_trip_with_custom_chunk_size(): void {
		$payload = str_repeat( 'gzip with custom chunk size; ', 5000 );
		$encoder = new GzipCodec( 65536 );
		$decoder = new GzipCodec( 4096 );

		$source  = $this->readable_stream( $payload );
		$encoded = $this->writable_stream();
		$encoder->encode( $source, $encoded );
		rewind( $encoded );

		$decoded = $this->writable_stream();
		$decoder->decode( $encoded, $decoded );

		$this->assertSame( $payload, $this->drain( $decoded ) );
	}

	/**
	 * Decoding clearly non-gzip input must raise CodecException.
	 *
	 * @return void
	 */
	public function test_decode_rejects_malformed_input(): void {
		$this->expectException( CodecException::class );

		$codec  = new GzipCodec();
		$input  = $this->readable_stream( 'this is not a gzip stream at all' );
		$output = $this->writable_stream();

		$codec->decode( $input, $output );
	}

	/**
	 * Calling encode() with a non-resource input must raise CodecException.
	 *
	 * @return void
	 */
	public function test_encode_throws_on_invalid_input(): void {
		$this->expectException( CodecException::class );

		$codec  = new GzipCodec();
		$output = $this->writable_stream();

		$codec->encode( null, $output );
	}

	/**
	 * Calling encode() with a non-resource output must raise CodecException.
	 *
	 * @return void
	 */
	public function test_encode_throws_on_invalid_output(): void {
		$this->expectException( CodecException::class );

		$codec = new GzipCodec();
		$input = $this->readable_stream( 'irrelevant' );

		$codec->encode( $input, null );
	}

	/**
	 * Decoding must abort with a CodecException once output exceeds the cap.
	 *
	 * A highly compressible payload (far larger than the cap once
	 * inflated) is encoded, then decoded with a tiny ceiling; the codec
	 * must refuse rather than inflate the whole stream.
	 *
	 * @return void
	 */
	public function test_decode_aborts_when_output_exceeds_cap(): void {
		$payload = str_repeat( 'A', 100000 );
		$codec   = new GzipCodec();
		$encoded = $this->writable_stream();
		$codec->encode( $this->readable_stream( $payload ), $encoded );

		$this->expectException( CodecException::class );

		$codec->decode( $this->readable_stream( $this->drain( $encoded ) ), $this->writable_stream(), 100 );
	}

	/**
	 * Decoding within the cap must succeed and reproduce the payload exactly.
	 *
	 * @return void
	 */
	public function test_decode_within_cap_succeeds(): void {
		$payload = str_repeat( 'B', 4096 );
		$codec   = new GzipCodec();
		$encoded = $this->writable_stream();
		$codec->encode( $this->readable_stream( $payload ), $encoded );

		$output  = $this->writable_stream();
		$written = $codec->decode( $this->readable_stream( $this->drain( $encoded ) ), $output, 1048576 );

		$this->assertSame( strlen( $payload ), $written );
		$this->assertSame( $payload, $this->drain( $output ) );
	}

	/**
	 * Encoding must report each chunk's source bytes to the progress callback.
	 *
	 * The reported deltas must sum to the original input size and arrive more
	 * than once for a multi-chunk payload, so a caller can advance a byte-based
	 * progress bar within a single large entry rather than only at its boundary.
	 *
	 * @return void
	 */
	public function test_encode_reports_source_bytes_to_the_callback(): void {
		$payload  = str_repeat( 'progress reporting payload; ', 4000 );
		$codec    = new GzipCodec();
		$reported = 0;
		$calls    = 0;

		$codec->encode(
			$this->readable_stream( $payload ),
			$this->writable_stream(),
			function ( int $bytes ) use ( &$reported, &$calls ): void {
				$reported += $bytes;
				++$calls;
			}
		);

		$this->assertSame( strlen( $payload ), $reported, 'Reported bytes must sum to the input size.' );
		$this->assertGreaterThan( 1, $calls, 'A multi-chunk payload must report more than once.' );
	}

	/**
	 * Encoding an empty input must not invoke the progress callback.
	 *
	 * @return void
	 */
	public function test_encode_empty_input_reports_nothing(): void {
		$codec = new GzipCodec();
		$calls = 0;

		$codec->encode(
			$this->readable_stream( '' ),
			$this->writable_stream(),
			function () use ( &$calls ): void {
				++$calls;
			}
		);

		$this->assertSame( 0, $calls, 'Empty input reads nothing, so the callback must not fire.' );
	}
}
