<?php
/**
 * Produces an Ed25519 detached signature over a message.
 *
 * @package Pontifex\Archive\Crypto
 */

declare(strict_types=1);

namespace Pontifex\Archive\Crypto;

use SodiumException;

/**
 * Signs a message with an Ed25519 secret key, returning a detached signature.
 *
 * "Detached" means the signature is returned on its own rather than wrapped
 * around the message: the archive stores it after the footer, leaving the
 * signed bytes untouched (`ARCHIVE-FORMAT.md` §11). The message Pontifex signs
 * is the whole archive from offset 0 through the end of the footer, so one
 * 64-byte signature commits to everything the footer commits to.
 *
 * Ed25519 is ext-sodium-only here, exactly like {@see Argon2idKdf}: ext-openssl
 * offers no equivalent, so there is no fallback. Ed25519 signatures are
 * deterministic — the same message and key always yield the same signature —
 * which makes signing reproducible and testable without random state.
 *
 * Stateless; safe to reuse. The secret key is sensitive: it is never logged,
 * and callers should scrub their copy once finished.
 */
final class Ed25519Signer {

	/**
	 * Sign a message with an Ed25519 secret key.
	 *
	 * @param string $message    The bytes to sign.
	 * @param string $secret_key The Ed25519 secret key; must be exactly SigningKeypair::SECRET_KEY_SIZE bytes.
	 * @return string The 64-byte detached signature.
	 * @throws SignatureException If ext-sodium is unavailable, the secret key is the wrong length, or signing fails.
	 */
	public function sign( string $message, string $secret_key ): string {
		if ( ! function_exists( 'sodium_crypto_sign_detached' ) ) {
			throw new SignatureException(
				'Ed25519Signer: ext-sodium is required to sign but is not available; signed archives cannot be produced without it.'
			);
		}
		if ( SigningKeypair::SECRET_KEY_SIZE !== strlen( $secret_key ) ) {
			throw new SignatureException(
				sprintf( 'Ed25519Signer: secret key must be exactly %d bytes, got %d.', (int) SigningKeypair::SECRET_KEY_SIZE, (int) strlen( $secret_key ) )
			);
		}

		try {
			return sodium_crypto_sign_detached( $message, $secret_key );
		} catch ( SodiumException $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $e is the underlying libsodium exception, chained as the previous exception for diagnostics; not HTML output.
			throw new SignatureException( 'Ed25519Signer: Ed25519 signing failed.', 0, $e );
		}
	}
}
