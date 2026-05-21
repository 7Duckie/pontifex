<?php
/**
 * SHA-256 hashing primitive for Pontifex archive integrity.
 *
 * @package Pontifex\Archive\Integrity
 */

declare(strict_types=1);

namespace Pontifex\Archive\Integrity;

use HashContext;
use RuntimeException;

/**
 * SHA-256 hash wrapper.
 *
 * Centralises Pontifex's use of SHA-256 — the integrity hash mandated
 * by the archive format spec for every entry payload, the provenance
 * block, and the manifest. Exposes two complementary APIs:
 *
 *  - A one-shot static method, Sha256::of(string $bytes): string, for
 *    hashing payloads that already exist in memory (headers, small
 *    manifest blocks, fully-buffered structures).
 *  - An instance-based streaming API (update + digest) for payloads
 *    too large to materialise in memory. Each instance is single-use:
 *    once digest() is called, further update() or digest() calls
 *    throw RuntimeException.
 *
 * The algorithm is locked to SHA-256 by `ARCHIVE-FORMAT.md` §13.2.2
 * and cannot change within v1 of the format. There is no interface
 * and no pluggability: if SHA-256 is ever cryptographically broken,
 * the format major version bumps and this class is rewritten as part
 * of the v2 work.
 */
final class Sha256 {

	/**
	 * The PHP hash() algorithm name for SHA-256.
	 *
	 * @var string
	 */
	private const ALGORITHM = 'sha256';

	/**
	 * Size of a SHA-256 digest in bytes (32).
	 *
	 * Exposed publicly so callers laying out fixed-size archive
	 * fields can reference Sha256::DIGEST_SIZE rather than hard-coding
	 * the constant 32 throughout the codebase.
	 *
	 * @var int
	 */
	public const DIGEST_SIZE = 32;

	/**
	 * The running hash context for this instance.
	 *
	 * @var HashContext
	 */
	private HashContext $ctx;

	/**
	 * Whether digest() has been called and the context consumed.
	 *
	 * @var bool
	 */
	private bool $finalised = false;

	/**
	 * Begin a new SHA-256 hashing context.
	 */
	public function __construct() {
		$this->ctx = hash_init( self::ALGORITHM );
	}

	/**
	 * Add bytes to the running hash.
	 *
	 * An empty input is a no-op; the hash is unchanged.
	 *
	 * @param string $bytes The bytes to add to the running hash.
	 * @return void
	 * @throws RuntimeException If digest() has already been called on this instance.
	 */
	public function update( string $bytes ): void {
		if ( $this->finalised ) {
			throw new RuntimeException( 'Sha256: cannot update after digest() has been called.' );
		}
		if ( '' === $bytes ) {
			return;
		}
		hash_update( $this->ctx, $bytes );
	}

	/**
	 * Finalise the hash and return the 32-byte binary SHA-256 digest.
	 *
	 * After this method is called, the instance is spent: further
	 * update() or digest() calls throw RuntimeException.
	 *
	 * @return string The 32-byte binary SHA-256 digest.
	 * @throws RuntimeException If digest() has already been called on this instance.
	 */
	public function digest(): string {
		if ( $this->finalised ) {
			throw new RuntimeException( 'Sha256: digest() has already been called on this instance.' );
		}
		$this->finalised = true;
		return hash_final( $this->ctx, true );
	}

	/**
	 * One-shot SHA-256 of a byte string.
	 *
	 * Convenience wrapper for callers that have the entire payload in
	 * memory and do not need to stream it. Equivalent to instantiating
	 * Sha256, calling update($bytes), then digest().
	 *
	 * @param string $bytes The bytes to hash.
	 * @return string The 32-byte binary SHA-256 digest.
	 */
	public static function of( string $bytes ): string {
		return hash( self::ALGORITHM, $bytes, true );
	}
}
