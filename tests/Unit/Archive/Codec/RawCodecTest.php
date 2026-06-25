<?php
/**
 * Behavioural tests for RawCodec.
 *
 * @package Pontifex\Tests\Unit\Archive\Codec
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Archive\Codec;

use PHPUnit\Framework\TestCase;
use Pontifex\Archive\Codec\Codec;
use Pontifex\Archive\Codec\CodecException;
use Pontifex\Archive\Codec\RawCodec;

/**
 * Behavioural tests for the RawCodec class.
 *
 * Verifies the codec's invariants:
 *
 *  - Reports the correct format-mandated identifier (0x0000).
 *  - Encoding passes bytes through unchanged.
 *  - Decoding passes bytes through unchanged.
 *  - Encode then decode is the identity on any byte sequence.
 *  - Return value reflects the number of bytes written.
 *  - Handles payloads of non-trivial size correctly.
 *  - Raises CodecException on invalid stream resources.
 */
final class RawCodecTest extends TestCase {

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
	 * RawCodec must implement the Codec interface.
	 *
	 * @return void
	 */
	public function test_implements_codec_interface(): void {
		$this->assertInstanceOf( Codec::class, new RawCodec() );
	}

	/**
	 * The codec identifier must be 0x0000 per the archive format spec.
	 *
	 * Both the constant and the method are exercised because the codec
	 * registry will rely on the constant while archive entries written
	 * to disk will derive the value from the method.
	 *
	 * @return void
	 */
	public function test_id_returns_zero(): void {
		$codec = new RawCodec();

		$this->assertSame( 0x0000, $codec->id() );
		$this->assertSame( 0x0000, RawCodec::ID );
	}

	/**
	 * Encoding an empty input must produce empty output and return zero.
	 *
	 * @return void
	 */
	public function test_encode_empty_stream_writes_nothing(): void {
		$codec  = new RawCodec();
		$input  = $this->readable_stream( '' );
		$output = $this->writable_stream();

		$written = $codec->encode( $input, $output );

		$this->assertSame( 0, $written );
		$this->assertSame( '', $this->drain( $output ) );
	}

	/**
	 * Encoding a known payload must pass bytes through unchanged.
	 *
	 * @return void
	 */
	public function test_encode_passes_bytes_through_unchanged(): void {
		$payload = 'Pontifex archive entry payload.';
		$codec   = new RawCodec();
		$input   = $this->readable_stream( $payload );
		$output  = $this->writable_stream();

		$written = $codec->encode( $input, $output );

		$this->assertSame( strlen( $payload ), $written );
		$this->assertSame( $payload, $this->drain( $output ) );
	}

	/**
	 * Decoding a known payload must pass bytes through unchanged.
	 *
	 * @return void
	 */
	public function test_decode_passes_bytes_through_unchanged(): void {
		$payload = 'Pontifex archive entry payload.';
		$codec   = new RawCodec();
		$input   = $this->readable_stream( $payload );
		$output  = $this->writable_stream();

		$written = $codec->decode( $input, $output );

		$this->assertSame( strlen( $payload ), $written );
		$this->assertSame( $payload, $this->drain( $output ) );
	}

	/**
	 * Encode followed by decode must reproduce the original input exactly.
	 *
	 * Includes binary bytes (0x00, 0xFF, 0x01) to ensure the codec
	 * does not assume text content.
	 *
	 * @return void
	 */
	public function test_encode_decode_round_trip_is_identity(): void {
		$payload = 'Round-trip test payload with binary bytes: ' . chr( 0 ) . chr( 255 ) . chr( 1 );
		$codec   = new RawCodec();
		$source  = $this->readable_stream( $payload );
		$encoded = $this->writable_stream();

		$codec->encode( $source, $encoded );
		rewind( $encoded );

		$decoded = $this->writable_stream();
		$codec->decode( $encoded, $decoded );

		$this->assertSame( $payload, $this->drain( $decoded ) );
	}

	/**
	 * A non-trivial payload (1 MiB) must encode correctly.
	 *
	 * The streaming guarantee is implicit in the use of
	 * stream_copy_to_stream(); this test verifies functional correctness
	 * at a size where any catastrophic buffering bug would surface.
	 *
	 * @return void
	 */
	public function test_encode_handles_one_megabyte_payload(): void {
		$one_mib = str_repeat( 'X', 1024 * 1024 );
		$codec   = new RawCodec();
		$input   = $this->readable_stream( $one_mib );
		$output  = $this->writable_stream();

		$written = $codec->encode( $input, $output );

		$this->assertSame( strlen( $one_mib ), $written );
		$this->assertSame( strlen( $one_mib ), strlen( $this->drain( $output ) ) );
	}

	/**
	 * Calling encode() with a non-resource input must raise CodecException.
	 *
	 * @return void
	 */
	public function test_encode_throws_on_invalid_input(): void {
		$this->expectException( CodecException::class );

		$codec  = new RawCodec();
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

		$codec = new RawCodec();
		$input = $this->readable_stream( 'irrelevant' );

		$codec->encode( $input, null );
	}

	/**
	 * Decoding must abort with a CodecException when input exceeds the cap.
	 *
	 * @return void
	 */
	public function test_decode_aborts_when_output_exceeds_cap(): void {
		$codec = new RawCodec();
		$input = $this->readable_stream( str_repeat( 'Y', 200 ) );

		$this->expectException( CodecException::class );

		$codec->decode( $input, $this->writable_stream(), 50 );
	}

	/**
	 * Decoding within the cap must pass bytes through unchanged.
	 *
	 * @return void
	 */
	public function test_decode_within_cap_succeeds(): void {
		$payload = str_repeat( 'Z', 200 );
		$codec   = new RawCodec();
		$output  = $this->writable_stream();

		$written = $codec->decode( $this->readable_stream( $payload ), $output, 1000 );

		$this->assertSame( strlen( $payload ), $written );
		$this->assertSame( $payload, $this->drain( $output ) );
	}

	/**
	 * Encoding must report each chunk's source bytes to the progress callback.
	 *
	 * The reported deltas must sum to the original input size and arrive more
	 * than once for a multi-chunk payload, so a caller can advance a byte-based
	 * progress bar while a stored (uncompressed) entry streams.
	 *
	 * @return void
	 */
	public function test_encode_reports_source_bytes_to_the_callback(): void {
		$payload  = str_repeat( 'raw progress payload; ', 4000 );
		$codec    = new RawCodec();
		$output   = $this->writable_stream();
		$reported = 0;
		$calls    = 0;

		$written = $codec->encode(
			$this->readable_stream( $payload ),
			$output,
			function ( int $bytes ) use ( &$reported, &$calls ): void {
				$reported += $bytes;
				++$calls;
			}
		);

		$this->assertSame( strlen( $payload ), $reported, 'Reported bytes must sum to the input size.' );
		$this->assertGreaterThan( 1, $calls, 'A multi-chunk payload must report more than once.' );
		$this->assertSame( $payload, $this->drain( $output ), 'The reporting copy must still pass bytes through unchanged.' );
		$this->assertSame( strlen( $payload ), $written, 'The reporting copy must return the byte count written.' );
	}

	/**
	 * Encoding an empty input must not invoke the progress callback.
	 *
	 * @return void
	 */
	public function test_encode_empty_input_reports_nothing(): void {
		$codec = new RawCodec();
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
