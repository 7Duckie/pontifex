<?php
/**
 * AES-256-GCM cipher over ext-sodium.
 *
 * @package Pontifex\Archive\Crypto
 */

declare(strict_types=1);

namespace Pontifex\Archive\Crypto;

use SodiumException;

/**
 * AES-256-GCM via ext-sodium's libsodium binding.
 *
 * The preferred cipher when the host CPU has hardware AES support: libsodium
 * uses the AES-NI instruction set, which is both faster and constant-time
 * (resistant to cache-timing side channels). sodium's AEAD functions return
 * and consume the ciphertext with the 16-byte tag already appended, which is
 * exactly the on-disk form Pontifex stores, so this implementation adds no
 * framing of its own.
 *
 * libsodium's AES-256-GCM is only available when the CPU supports it; the
 * runtime check is {@see sodium_crypto_aead_aes256gcm_is_available()}. On a
 * host without it, {@see CipherFactory} hands back {@see OpensslAesGcmCipher}
 * instead. This class still guards every operation with the availability
 * check so a direct caller on an unsupported host fails with a clear message
 * rather than a fatal undefined-behaviour call.
 *
 * Stateless; safe to reuse across many entries.
 */
final class SodiumAesGcmCipher implements Cipher {

	/**
	 * Encrypt a payload with AES-256-GCM, returning ciphertext with the tag appended.
	 *
	 * @param string $plaintext The bytes to encrypt; may be empty.
	 * @param string $nonce     The per-entry nonce; must be exactly Cipher::NONCE_SIZE bytes.
	 * @param string $aad       Additional authenticated data bound to the ciphertext; may be empty.
	 * @param string $key       The AES-256 key; must be exactly Cipher::KEY_SIZE bytes.
	 * @return string The ciphertext followed by the 16-byte authentication tag.
	 * @throws CipherException If AES-256-GCM is unavailable on this host, or the nonce or key is the wrong length.
	 */
	public function encrypt( string $plaintext, string $nonce, string $aad, string $key ): string {
		$this->assert_available();
		$this->assert_sizes( $nonce, $key );

		try {
			return sodium_crypto_aead_aes256gcm_encrypt( $plaintext, $aad, $nonce, $key );
		} catch ( SodiumException $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $e is the underlying libsodium exception, chained as the previous exception for diagnostics; not HTML output.
			throw new CipherException( 'SodiumAesGcmCipher: AES-256-GCM encryption failed.', 0, $e );
		}
	}

	/**
	 * Decrypt a ciphertext-and-tag with AES-256-GCM, verifying the tag.
	 *
	 * @param string $ciphertext_and_tag The ciphertext with the 16-byte GCM tag appended; must be at least Cipher::TAG_SIZE bytes.
	 * @param string $nonce              The per-entry nonce used to encrypt; must be exactly Cipher::NONCE_SIZE bytes.
	 * @param string $aad                The additional authenticated data used to encrypt; may be empty.
	 * @param string $key                The AES-256 key; must be exactly Cipher::KEY_SIZE bytes.
	 * @return string The decrypted plaintext.
	 * @throws CipherException If AES-256-GCM is unavailable, a size is wrong, or authentication fails.
	 */
	public function decrypt( string $ciphertext_and_tag, string $nonce, string $aad, string $key ): string {
		$this->assert_available();
		$this->assert_sizes( $nonce, $key );

		if ( strlen( $ciphertext_and_tag ) < Cipher::TAG_SIZE ) {
			throw new CipherException(
				sprintf(
					'SodiumAesGcmCipher: encrypted payload is %d bytes, shorter than the %d-byte authentication tag; the archive is truncated or corrupt.',
					(int) strlen( $ciphertext_and_tag ),
					(int) Cipher::TAG_SIZE
				)
			);
		}

		try {
			$plaintext = sodium_crypto_aead_aes256gcm_decrypt( $ciphertext_and_tag, $aad, $nonce, $key );
		} catch ( SodiumException $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $e is the underlying libsodium exception, chained as the previous exception for diagnostics; not HTML output.
			throw new CipherException( 'SodiumAesGcmCipher: decryption failed; the archive was tampered with or truncated, or the passphrase is wrong.', 0, $e );
		}
		if ( false === $plaintext ) {
			throw new CipherException(
				'SodiumAesGcmCipher: decryption failed; the archive was tampered with or truncated, or the passphrase is wrong.'
			);
		}

		return $plaintext;
	}

	/**
	 * Assert that AES-256-GCM is available via ext-sodium on this host.
	 *
	 * @return void
	 * @throws CipherException If ext-sodium is absent or the CPU lacks the hardware AES support sodium's AES-256-GCM requires.
	 */
	private function assert_available(): void {
		if ( ! function_exists( 'sodium_crypto_aead_aes256gcm_is_available' )
			|| ! sodium_crypto_aead_aes256gcm_is_available() ) {
			throw new CipherException(
				'SodiumAesGcmCipher: AES-256-GCM via ext-sodium is not available on this host (no hardware AES support); the openssl cipher is the fallback.'
			);
		}
	}

	/**
	 * Assert that the nonce and key are the exact lengths AES-256-GCM requires.
	 *
	 * @param string $nonce The nonce to check against Cipher::NONCE_SIZE.
	 * @param string $key   The key to check against Cipher::KEY_SIZE.
	 * @return void
	 * @throws CipherException If either length is wrong.
	 */
	private function assert_sizes( string $nonce, string $key ): void {
		if ( Cipher::NONCE_SIZE !== strlen( $nonce ) ) {
			throw new CipherException(
				sprintf( 'SodiumAesGcmCipher: nonce must be exactly %d bytes, got %d.', (int) Cipher::NONCE_SIZE, (int) strlen( $nonce ) )
			);
		}
		if ( Cipher::KEY_SIZE !== strlen( $key ) ) {
			throw new CipherException(
				sprintf( 'SodiumAesGcmCipher: key must be exactly %d bytes, got %d.', (int) Cipher::KEY_SIZE, (int) strlen( $key ) )
			);
		}
	}
}
