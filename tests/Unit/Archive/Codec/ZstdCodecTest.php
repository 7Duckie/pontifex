<?php
/**
 * Behavioural tests for ZstdCodec.
 *
 * @package Pontifex\Tests\Unit\Archive\Codec
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Archive\Codec;

use PHPUnit\Framework\TestCase;
use Pontifex\Archive\Codec\Codec;
use Pontifex\Archive\Codec\CodecException;
use Pontifex\Archive\Codec\ZstdCodec;

/**
 * Behavioural tests for the ZstdCodec class.
 *
 * Mirrors {@see GzipCodecTest}, verifying the codec's invariants: it reports
 * 0x0002, validates the chunk size, round-trips arbitrary bytes (including a
 * megabyte payload that crosses many chunk boundaries) at any chunk size,
 * compresses repetitive content, refuses malformed input and bad stream
 * resources, and enforces the decompression-bomb ceiling.
 *
 * zstd is an optional extension; the whole suite is skipped when it is not
 * loaded, so a host without ext-zstd reports skips rather than errors. The
 * extension's absence is itself exercised by the codec's own availability
 * guard, which is unit-independent of whether the extension is present here.
 */
final class ZstdCodecTest extends TestCase {

	/**
	 * Skip the suite when ext-zstd is not available.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		if ( ! extension_loaded( 'zstd' ) ) {
			$this->markTestSkipped( 'The zstd PHP extension (ext-zstd) is not loaded.' );
		}
	}

	/**
	 * Helper: produce an in-memory readable stream pre-filled with $bytes.
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
	 * ZstdCodec must implement the Codec interface.
	 *
	 * @return void
	 */
	public function test_implements_codec_interface(): void {
		$this->assertInstanceOf( Codec::class, new ZstdCodec() );
	}

	/**
	 * The codec identifier must be 0x0002 per the archive format spec.
	 *
	 * @return void
	 */
	public function test_id_returns_two(): void {
		$codec = new ZstdCodec();

		$this->assertSame( 0x0002, $codec->id() );
		$this->assertSame( 0x0002, ZstdCodec::ID );
	}

	/**
	 * The default chunk size must be 8 KiB as documented in the class.
	 *
	 * @return void
	 */
	public function test_default_chunk_size_is_eight_kibibytes(): void {
		$this->assertSame( 8192, ZstdCodec::DEFAULT_CHUNK_SIZE );
	}

	/**
	 * Constructing with a chunk size of zero must raise CodecException.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_zero_chunk_size(): void {
		$this->expectException( CodecException::class );

		new ZstdCodec( 0 );
	}

	/**
	 * Constructing with a chunk size above the maximum must raise CodecException.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_chunk_size_above_maximum(): void {
		$this->expectException( CodecException::class );

		new ZstdCodec( ZstdCodec::MAX_CHUNK_SIZE + 1 );
	}

	/**
	 * Constructing with an explicit in-range chunk size must succeed.
	 *
	 * @return void
	 */
	public function test_constructor_accepts_explicit_chunk_size(): void {
		$codec = new ZstdCodec( 65536 );

		$this->assertInstanceOf( ZstdCodec::class, $codec );
	}

	/**
	 * Encoding then decoding an empty input must reproduce the empty input.
	 *
	 * @return void
	 */
	public function test_encode_decode_round_trip_empty_input(): void {
		$codec = new ZstdCodec();

		$encoded = $this->writable_stream();
		$codec->encode( $this->readable_stream( '' ), $encoded );
		rewind( $encoded );

		$decoded = $this->writable_stream();
		$codec->decode( $encoded, $decoded );

		$this->assertSame( '', $this->drain( $decoded ) );
	}

	/**
	 * Encoding a highly compressible payload must produce a smaller output.
	 *
	 * @return void
	 */
	public function test_encode_compresses_repetitive_payload(): void {
		$payload = str_repeat( 'A', 4096 );
		$codec   = new ZstdCodec();
		$input   = $this->readable_stream( $payload );
		$output  = $this->writable_stream();

		$written = $codec->encode( $input, $output );

		$this->assertLessThan( strlen( $payload ), $written );
	}

	/**
	 * Encode then decode must reproduce the original input exactly.
	 *
	 * Uses binary bytes including 0x00, 0xFF, and 0x01 to ensure the codec
	 * handles arbitrary byte sequences, not only text.
	 *
	 * @return void
	 */
	public function test_encode_decode_round_trip_with_binary_bytes(): void {
		$payload = 'zstd round-trip with binary: ' . chr( 0 ) . chr( 255 ) . chr( 1 ) . ' end.';
		$codec   = new ZstdCodec();

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
	 * Exercises the streaming path at a size where any catastrophic buffering
	 * bug would surface, and that crosses several default chunk boundaries.
	 *
	 * @return void
	 */
	public function test_encode_decode_round_trip_one_megabyte_payload(): void {
		$payload = str_repeat( 'Pontifex archive payload. ', 50000 );
		$this->assertGreaterThan( 1024 * 1024, strlen( $payload ) );

		$codec   = new ZstdCodec();
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
	 * A codec constructed with 64 KiB chunks produces output that any other
	 * ZstdCodec instance can decode, regardless of its chunk size.
	 *
	 * @return void
	 */
	public function test_encode_decode_round_trip_with_custom_chunk_size(): void {
		$payload = str_repeat( 'zstd with custom chunk size; ', 5000 );
		$encoder = new ZstdCodec( 65536 );
		$decoder = new ZstdCodec( 4096 );

		$source  = $this->readable_stream( $payload );
		$encoded = $this->writable_stream();
		$encoder->encode( $source, $encoded );
		rewind( $encoded );

		$decoded = $this->writable_stream();
		$decoder->decode( $encoded, $decoded );

		$this->assertSame( $payload, $this->drain( $decoded ) );
	}

	/**
	 * Decoding clearly non-zstd input must raise CodecException.
	 *
	 * @return void
	 */
	public function test_decode_rejects_malformed_input(): void {
		$this->expectException( CodecException::class );

		$codec  = new ZstdCodec();
		$input  = $this->readable_stream( 'this is not a zstd stream at all' );
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

		$codec  = new ZstdCodec();
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

		$codec = new ZstdCodec();
		$input = $this->readable_stream( 'irrelevant' );

		$codec->encode( $input, null );
	}

	/**
	 * Decoding must abort with a CodecException once output exceeds the cap.
	 *
	 * A highly compressible payload (far larger than the cap once inflated) is
	 * encoded, then decoded with a tiny ceiling; the codec must refuse rather
	 * than inflate the whole stream.
	 *
	 * @return void
	 */
	public function test_decode_aborts_when_output_exceeds_cap(): void {
		$payload = str_repeat( 'A', 100000 );
		$codec   = new ZstdCodec();
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
		$codec   = new ZstdCodec();
		$encoded = $this->writable_stream();
		$codec->encode( $this->readable_stream( $payload ), $encoded );

		$output  = $this->writable_stream();
		$written = $codec->decode( $this->readable_stream( $this->drain( $encoded ) ), $output, 1048576 );

		$this->assertSame( strlen( $payload ), $written );
		$this->assertSame( $payload, $this->drain( $output ) );
	}
}
