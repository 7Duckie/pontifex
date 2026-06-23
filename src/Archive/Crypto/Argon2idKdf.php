<?php
/**
 * Argon2id key derivation for archive encryption.
 *
 * @package Pontifex\Archive\Crypto
 */

declare(strict_types=1);

namespace Pontifex\Archive\Crypto;

use SodiumException;

/**
 * Derives the archive encryption key from a passphrase using Argon2id.
 *
 * A key derivation function (KDF) turns a human-typed passphrase into a
 * fixed-size binary key. Done right it is deliberately slow and memory-
 * hungry, so brute-forcing the archive is expensive even when the
 * passphrase itself is not especially strong — the slow-and-hungry part is
 * the whole point.
 *
 * The algorithm and its cost parameters are locked by the archive format
 * (`ARCHIVE-FORMAT.md` §8.1, §13.2.2): Argon2id, 4 iterations, 64 MiB of
 * memory, a 32-byte output key, and a 16-byte salt. These exact values are
 * a format invariant — a different cost produces a different key from the
 * same passphrase, so an archive written with other parameters would be
 * undecryptable by a conforming reader.
 *
 * This is why the cost values below are the literal numbers and NOT
 * libsodium's named presets: SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE /
 * _MODERATE / _SENSITIVE pair with memory limits of 64 MiB / 256 MiB / 1 GiB
 * at op-counts of 2 / 3 / 4 — none of which is the format's (4 iterations,
 * 64 MiB) combination. Using a preset would silently violate the spec.
 *
 * Argon2id is ext-sodium-only: ext-openssl does not provide it, so there is
 * no fallback. A host without ext-sodium cannot derive a key and therefore
 * cannot produce or read an encrypted archive at all. The passphrase is
 * never logged, stored, or echoed; callers are responsible for scrubbing
 * the returned key from memory when finished (e.g. via sodium_memzero()).
 *
 * This class enforces no passphrase-strength policy. The minimum-length and
 * no-recovery rules (§8.4, §8.6) live in the CLI layer that collects the
 * passphrase; the KDF is a pure primitive that derives a key from whatever
 * it is given.
 *
 * Stateless; safe to reuse.
 */
final class Argon2idKdf {

	/**
	 * Length of the derived key in bytes (32 = 256 bits, for AES-256).
	 *
	 * @var int
	 */
	public const KEY_SIZE = 32;

	/**
	 * Length of the salt in bytes (16).
	 *
	 * Matches the footer's salt field (`ARCHIVE-FORMAT.md` §10, mirrored by
	 * {@see \Pontifex\Archive\Format\Footer::ARGON2ID_SALT_SIZE}) and
	 * libsodium's SODIUM_CRYPTO_PWHASH_SALTBYTES.
	 *
	 * @var int
	 */
	public const SALT_SIZE = 16;

	/**
	 * Time cost: number of iterations (4), locked by the format spec.
	 *
	 * @var int
	 */
	public const OPS_LIMIT = 4;

	/**
	 * Memory cost in bytes (67108864 = 64 MiB), locked by the format spec.
	 *
	 * @var int
	 */
	public const MEM_LIMIT = 67108864;

	/**
	 * Derive the 32-byte AES-256 key from a passphrase and salt.
	 *
	 * @param string $passphrase The operator's passphrase; used as-is, never logged or stored.
	 * @param string $salt        The per-archive salt; must be exactly SALT_SIZE bytes.
	 * @return string The derived 32-byte key.
	 * @throws CipherException If ext-sodium is unavailable, the salt is the wrong length, or key derivation fails.
	 */
	public function derive( string $passphrase, string $salt ): string {
		if ( ! function_exists( 'sodium_crypto_pwhash' ) ) {
			throw new CipherException(
				'Argon2idKdf: ext-sodium is required for Argon2id key derivation but is not available; encrypted archives cannot be produced or read without it.'
			);
		}
		if ( self::SALT_SIZE !== strlen( $salt ) ) {
			throw new CipherException(
				sprintf( 'Argon2idKdf: salt must be exactly %d bytes, got %d.', (int) self::SALT_SIZE, (int) strlen( $salt ) )
			);
		}

		try {
			return sodium_crypto_pwhash(
				self::KEY_SIZE,
				$passphrase,
				$salt,
				self::OPS_LIMIT,
				self::MEM_LIMIT,
				SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
			);
		} catch ( SodiumException $e ) {
			// The passphrase is deliberately kept out of the message; it must never be logged.
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $e is the underlying libsodium exception, chained as the previous exception for diagnostics; not HTML output.
			throw new CipherException( 'Argon2idKdf: Argon2id key derivation failed.', 0, $e );
		}
	}
}
