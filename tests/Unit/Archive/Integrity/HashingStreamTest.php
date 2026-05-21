<?php
/**
 * Behavioural tests for HashingStream.
 *
 * @package Pontifex\Tests\Unit\Archive\Integrity
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Archive\Integrity;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pontifex\Archive\Integrity\HashingStream;
use Pontifex\Archive\Integrity\Sha256;
use RuntimeException;

/**
 * Behavioural tests for the HashingStream class.
 *
 * Verifies the streaming primitive's invariants:
 *
 *  - copy() preserves payload bytes exactly on the destination.
 *  - The digest of a copied payload matches Sha256::of() applied to
 *    the same bytes.
 *  - update() and copy() can be mixed in a single hash session.
 *  - bytes_processed() tracks the cumulative byte count correctly.
 *  - Instances are single-use: copy(), update(), and digest() after
 *    digest() all raise RuntimeException.
 *  - Non-resource arguments to copy() raise InvalidArgumentException.
 *  - Payloads of non-trivial size (1 MiB) stream correctly without
 *    losing bytes.
 */
final class HashingStreamTest extends TestCase {

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
	 * Copying an empty source must return 0 and digest to the empty-string SHA-256.
	 *
	 * @return void
	 */
	public function test_empty_copy_returns_zero_and_digest_matches_empty_string(): void {
		$source      = $this->readable_stream( '' );
		$destination = $this->writable_stream();
		$stream      = new HashingStream();

		$copied = $stream->copy( $source, $destination );

		$this->assertSame( 0, $copied );
		$this->assertSame( '', $this->drain( $destination ) );
		$this->assertSame( Sha256::of( '' ), $stream->digest() );
	}

	/**
	 * Copying a known payload must deliver the payload unchanged to the destination.
	 *
	 * @return void
	 */
	public function test_copy_destination_receives_bytes_unchanged(): void {
		$payload     = 'Pontifex archive payload with binary: ' . chr( 0 ) . chr( 255 ) . ' end.';
		$source      = $this->readable_stream( $payload );
		$destination = $this->writable_stream();
		$stream      = new HashingStream();

		$copied = $stream->copy( $source, $destination );

		$this->assertSame( strlen( $payload ), $copied );
		$this->assertSame( $payload, $this->drain( $destination ) );
	}

	/**
	 * The digest after copying a payload must equal the one-shot SHA-256 of that payload.
	 *
	 * @return void
	 */
	public function test_copy_digest_matches_one_shot_hash_of_payload(): void {
		$payload     = 'Pontifex archive payload, single chunk.';
		$source      = $this->readable_stream( $payload );
		$destination = $this->writable_stream();
		$stream      = new HashingStream();

		$stream->copy( $source, $destination );

		$this->assertSame( Sha256::of( $payload ), $stream->digest() );
	}

	/**
	 * Calling update() alone (without any copy() call) must produce a correct digest.
	 *
	 * @return void
	 */
	public function test_update_alone_produces_correct_digest(): void {
		$stream = new HashingStream();
		$stream->update( 'abc' );

		$this->assertSame( Sha256::of( 'abc' ), $stream->digest() );
	}

	/**
	 * Combining update() and copy() must produce the digest of their concatenation.
	 *
	 * Combines an in-memory header (via update) with a streamed body
	 * (via copy) and verifies the digest matches Sha256::of() applied
	 * to the concatenated bytes.
	 *
	 * @return void
	 */
	public function test_update_and_copy_combine_into_single_digest(): void {
		$header      = 'HEADER:';
		$body        = 'streamed body payload';
		$source      = $this->readable_stream( $body );
		$destination = $this->writable_stream();
		$stream      = new HashingStream();

		$stream->update( $header );
		$stream->copy( $source, $destination );

		$this->assertSame( Sha256::of( $header . $body ), $stream->digest() );
	}

	/**
	 * Calling update() with an empty string must be a no-op.
	 *
	 * @return void
	 */
	public function test_update_with_empty_string_is_noop(): void {
		$stream = new HashingStream();
		$stream->update( '' );
		$stream->update( 'abc' );
		$stream->update( '' );

		$this->assertSame( Sha256::of( 'abc' ), $stream->digest() );
		$this->assertSame( 3, $stream->bytes_processed() );
	}

	/**
	 * The bytes_processed() counter must track copy() and update() contributions together.
	 *
	 * @return void
	 */
	public function test_bytes_processed_tracks_copy_and_update_combined(): void {
		$header      = 'HEADER:';
		$body        = 'streamed body payload';
		$source      = $this->readable_stream( $body );
		$destination = $this->writable_stream();
		$stream      = new HashingStream();

		$stream->update( $header );
		$stream->copy( $source, $destination );

		$this->assertSame( strlen( $header ) + strlen( $body ), $stream->bytes_processed() );
	}

	/**
	 * Streaming a one-megabyte payload must produce the expected digest.
	 *
	 * Exercises the chunked-read path at a size that crosses many
	 * chunk boundaries (1 MiB at 8 KiB chunks = 128 iterations of the
	 * copy loop), confirming that the streaming hash matches the
	 * one-shot hash of the same content.
	 *
	 * @return void
	 */
	public function test_streams_one_megabyte_payload_correctly(): void {
		$payload = str_repeat( 'A', 1024 * 1024 );
		$this->assertSame( 1024 * 1024, strlen( $payload ) );

		$source      = $this->readable_stream( $payload );
		$destination = $this->writable_stream();
		$stream      = new HashingStream();

		$copied = $stream->copy( $source, $destination );

		$this->assertSame( 1024 * 1024, $copied );
		$this->assertSame( $payload, $this->drain( $destination ) );
		$this->assertSame( Sha256::of( $payload ), $stream->digest() );
	}

	/**
	 * Calling copy() after digest() must raise RuntimeException.
	 *
	 * @return void
	 */
	public function test_copy_after_digest_throws(): void {
		$stream = new HashingStream();
		$stream->digest();

		$this->expectException( RuntimeException::class );

		$source      = $this->readable_stream( 'irrelevant' );
		$destination = $this->writable_stream();
		$stream->copy( $source, $destination );
	}

	/**
	 * Calling update() after digest() must raise RuntimeException.
	 *
	 * @return void
	 */
	public function test_update_after_digest_throws(): void {
		$stream = new HashingStream();
		$stream->digest();

		$this->expectException( RuntimeException::class );

		$stream->update( 'too late' );
	}

	/**
	 * Calling digest() twice must raise RuntimeException.
	 *
	 * @return void
	 */
	public function test_digest_twice_throws(): void {
		$stream = new HashingStream();
		$stream->digest();

		$this->expectException( RuntimeException::class );

		$stream->digest();
	}

	/**
	 * Calling copy() with a non-resource source must raise InvalidArgumentException.
	 *
	 * @return void
	 */
	public function test_copy_rejects_non_resource_source(): void {
		$this->expectException( InvalidArgumentException::class );

		$destination = $this->writable_stream();
		$stream      = new HashingStream();
		$stream->copy( null, $destination );
	}

	/**
	 * Calling copy() with a non-resource destination must raise InvalidArgumentException.
	 *
	 * @return void
	 */
	public function test_copy_rejects_non_resource_destination(): void {
		$this->expectException( InvalidArgumentException::class );

		$source = $this->readable_stream( 'irrelevant' );
		$stream = new HashingStream();
		$stream->copy( $source, null );
	}
}
