<?php
/**
 * Streaming SHA-256 primitive for archive entry copying.
 *
 * @package Pontifex\Archive\Integrity
 */

declare(strict_types=1);

namespace Pontifex\Archive\Integrity;

use InvalidArgumentException;
use RuntimeException;

/**
 * Copy bytes between streams while computing SHA-256 in flight.
 *
 * The archive writer needs to write each entry's payload to disk and
 * record its SHA-256 digest in the manifest. Doing both in two passes
 * (write, then re-read to hash) is wasteful at WordPress scale, where
 * a single entry can be hundreds of megabytes. HashingStream is the
 * single-pass primitive: read a chunk from the source, write it to
 * the destination, feed it to the running hash, repeat.
 *
 * Three operations:
 *
 *  - copy() streams from a source resource to a destination resource,
 *    hashing each chunk as it passes through.
 *  - update() feeds bytes directly to the running hash without I/O,
 *    for in-memory data (headers, small structures) that needs to be
 *    part of the same overall digest.
 *  - digest() finalises and returns the 32-byte SHA-256 digest. After
 *    this is called the instance is spent; further copy(), update(),
 *    or digest() calls throw RuntimeException.
 *
 * Memory profile: copy() reads in CHUNK_SIZE-byte chunks (8 KiB),
 * matching PHP's default stream buffer. Working memory stays bounded
 * regardless of payload size, which is what makes the codec layer
 * usable on memory-constrained hosts.
 */
final class HashingStream {

	/**
	 * Chunk size for streaming reads and writes (8 KiB).
	 *
	 * Matches PHP's default stream buffer size, avoiding surprises in
	 * the underlying I/O layer.
	 *
	 * @var int
	 */
	private const CHUNK_SIZE = 8192;

	/**
	 * The running SHA-256 hasher.
	 *
	 * @var Sha256
	 */
	private Sha256 $hasher;

	/**
	 * Total bytes that have passed through the hash so far.
	 *
	 * Includes bytes contributed by both copy() and update() calls.
	 *
	 * @var int
	 */
	private int $bytes_processed = 0;

	/**
	 * Whether digest() has been called and the hasher consumed.
	 *
	 * @var bool
	 */
	private bool $finalised = false;

	/**
	 * Begin a new streaming hash session.
	 */
	public function __construct() {
		$this->hasher = new Sha256();
	}

	/**
	 * Copy bytes from $source to $destination, updating the running hash.
	 *
	 * Reads from the current position of $source until end-of-stream.
	 * Writes to the current position of $destination. Each byte that
	 * passes through is also fed to the SHA-256 hash. Returns the
	 * total bytes copied during this call.
	 *
	 * A partial write — fwrite() returning fewer bytes than the chunk
	 * being written — raises RuntimeException, because allowing it
	 * would leave the destination stream and the hash out of sync.
	 * For the file and memory streams Pontifex uses, partial writes
	 * do not occur in practice.
	 *
	 * @param resource $source      A readable stream resource.
	 * @param resource $destination A writable stream resource.
	 * @return int The number of bytes copied during this call.
	 * @throws RuntimeException         If the instance is finalised, a read or write fails, or a partial write is detected.
	 * @throws InvalidArgumentException If either argument is not a stream resource.
	 */
	public function copy( $source, $destination ): int {
		if ( $this->finalised ) {
			throw new RuntimeException( 'HashingStream: cannot copy after digest() has been called.' );
		}
		if ( ! is_resource( $source ) ) {
			throw new InvalidArgumentException( 'HashingStream: $source must be a stream resource.' );
		}
		if ( ! is_resource( $destination ) ) {
			throw new InvalidArgumentException( 'HashingStream: $destination must be a stream resource.' );
		}

		$copied_this_call = 0;

		while ( true ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- HashingStream operates on arbitrary stream resources from the archive layer; WP_Filesystem has no streaming API and is the wrong abstraction for byte-stream copy.
			$chunk = fread( $source, self::CHUNK_SIZE );
			if ( false === $chunk ) {
				throw new RuntimeException( 'HashingStream: fread() failed on source stream.' );
			}
			if ( '' === $chunk ) {
				break;
			}

			$chunk_length = strlen( $chunk );

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- HashingStream operates on arbitrary stream resources from the archive layer; WP_Filesystem has no streaming API and is the wrong abstraction for byte-stream copy.
			$written = fwrite( $destination, $chunk );
			if ( false === $written ) {
				throw new RuntimeException( 'HashingStream: fwrite() failed on destination stream.' );
			}
			if ( $written !== $chunk_length ) {
				throw new RuntimeException(
					sprintf(
						'HashingStream: partial write detected (%d of %d bytes); aborting to preserve hash integrity.',
						(int) $written,
						(int) $chunk_length
					)
				);
			}

			$this->hasher->update( $chunk );
			$this->bytes_processed += $chunk_length;
			$copied_this_call      += $chunk_length;
		}

		return $copied_this_call;
	}

	/**
	 * Add bytes directly to the running hash without any I/O.
	 *
	 * Useful for hashing in-memory data (headers, small manifest
	 * blocks) that needs to be part of the same overall digest as
	 * stream-copied payloads.
	 *
	 * An empty input is a no-op.
	 *
	 * @param string $bytes The bytes to feed to the hash.
	 * @return void
	 * @throws RuntimeException If the instance has been finalised.
	 */
	public function update( string $bytes ): void {
		if ( $this->finalised ) {
			throw new RuntimeException( 'HashingStream: cannot update after digest() has been called.' );
		}
		if ( '' === $bytes ) {
			return;
		}
		$this->hasher->update( $bytes );
		$this->bytes_processed += strlen( $bytes );
	}

	/**
	 * Return the total bytes that have passed through the hash so far.
	 *
	 * Counts contributions from both copy() and update().
	 *
	 * @return int The cumulative byte count.
	 */
	public function bytes_processed(): int {
		return $this->bytes_processed;
	}

	/**
	 * Finalise the hash and return the 32-byte binary SHA-256 digest.
	 *
	 * After this method is called, the instance is spent: further
	 * copy(), update(), or digest() calls throw RuntimeException.
	 *
	 * @return string The 32-byte binary SHA-256 digest.
	 * @throws RuntimeException If digest() has already been called on this instance.
	 */
	public function digest(): string {
		if ( $this->finalised ) {
			throw new RuntimeException( 'HashingStream: digest() has already been called on this instance.' );
		}
		$this->finalised = true;
		return $this->hasher->digest();
	}
}
