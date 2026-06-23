<?php
/**
 * Verifies an Ed25519 detached signature over a message.
 *
 * @package Pontifex\Archive\Crypto
 */

declare(strict_types=1);

namespace Pontifex\Archive\Crypto;

use SodiumException;

/**
 * Verifies a detached Ed25519 signature against a message and public key.
 *
 * The counterpart to {@see Ed25519Signer}: given the signed bytes (offset 0
 * through the footer), the 64-byte signature read from the archive, and a
 * public key the operator trusts, it answers a single question — was this
 * archive signed by the holder of the matching secret key, and unchanged
 * since?
 *
 * A mismatch is a normal, expected answer, not an error: a wrong key or a
 * single altered byte makes verification return `false`. Only a structural
 * problem — ext-sodium missing, or a signature or key of the wrong length —
 * raises {@see SignatureException}. This split lets the reader treat `false`
 * as "untrusted or tampered" and an exception as "cannot even attempt the
 * check," which are different situations for the operator.
 *
 * Stateless; safe to reuse.
 */
final class Ed25519Verifier {

	/**
	 * Length of an Ed25519 signature in bytes (64).
	 *
	 * Libsodium's SODIUM_CRYPTO_SIGN_BYTES, pinned as a literal so a malformed
	 * signature can be rejected without ext-sodium being loaded.
	 *
	 * @var int
	 */
	public const SIGNATURE_SIZE = 64;

	/**
	 * Verify a detached signature against a message and public key.
	 *
	 * @param string $message    The signed bytes.
	 * @param string $signature  The detached signature; must be exactly SIGNATURE_SIZE bytes.
	 * @param string $public_key The signer's public key; must be exactly SigningKeypair::PUBLIC_KEY_SIZE bytes.
	 * @return bool True if the signature is valid for this message and key; false on any mismatch.
	 * @throws SignatureException If ext-sodium is unavailable, or the signature or public key is the wrong length.
	 */
	public function verify( string $message, string $signature, string $public_key ): bool {
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			throw new SignatureException(
				'Ed25519Verifier: ext-sodium is required to verify a signature but is not available.'
			);
		}
		if ( self::SIGNATURE_SIZE !== strlen( $signature ) ) {
			throw new SignatureException(
				sprintf( 'Ed25519Verifier: signature must be exactly %d bytes, got %d.', (int) self::SIGNATURE_SIZE, (int) strlen( $signature ) )
			);
		}
		if ( SigningKeypair::PUBLIC_KEY_SIZE !== strlen( $public_key ) ) {
			throw new SignatureException(
				sprintf( 'Ed25519Verifier: public key must be exactly %d bytes, got %d.', (int) SigningKeypair::PUBLIC_KEY_SIZE, (int) strlen( $public_key ) )
			);
		}

		try {
			return sodium_crypto_sign_verify_detached( $signature, $message, $public_key );
		} catch ( SodiumException $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $e is the underlying libsodium exception, chained as the previous exception for diagnostics; not HTML output.
			throw new SignatureException( 'Ed25519Verifier: Ed25519 verification could not be performed.', 0, $e );
		}
	}
}
