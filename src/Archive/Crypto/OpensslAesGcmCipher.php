<?php
/**
 * AES-256-GCM cipher over ext-openssl.
 *
 * @package Pontifex\Archive\Crypto
 */

declare(strict_types=1);

namespace Pontifex\Archive\Crypto;

/**
 * AES-256-GCM via ext-openssl.
 *
 * The fallback cipher for hosts whose CPU lacks the hardware AES support
 * that libsodium's AES-256-GCM requires (see {@see SodiumAesGcmCipher}).
 * ext-openssl implements AES-256-GCM in software, so it works everywhere
 * openssl is built with the cipher — which is effectively every WordPress
 * host.
 *
 * openssl splits the ciphertext and the authentication tag across two
 * values: openssl_encrypt() returns the ciphertext and writes the tag to a
 * by-reference argument. Pontifex stores the two concatenated (ciphertext
 * then 16-byte tag), so this implementation appends the tag on encrypt and
 * splits the trailing 16 bytes on decrypt. The result is byte-for-byte
 * identical to {@see SodiumAesGcmCipher}: a payload encrypted by one
 * decrypts under the other.
 *
 * The 32-byte key is passed as openssl's "passphrase" argument together with
 * OPENSSL_RAW_DATA, which makes openssl treat it as the raw key rather than
 * deriving one. Stateless; safe to reuse across many entries.
 */
final class OpensslAesGcmCipher implements Cipher {

	/**
	 * The openssl cipher-method name for AES-256 in Galois/Counter Mode.
	 *
	 * @var string
	 */
	private const CIPHER_METHOD = 'aes-256-gcm';

	/**
	 * Encrypt a payload with AES-256-GCM, returning ciphertext with the tag appended.
	 *
	 * @param string $plaintext The bytes to encrypt; may be empty.
	 * @param string $nonce     The per-entry nonce; must be exactly Cipher::NONCE_SIZE bytes.
	 * @param string $aad       Additional authenticated data bound to the ciphertext; may be empty.
	 * @param string $key       The AES-256 key; must be exactly Cipher::KEY_SIZE bytes.
	 * @return string The ciphertext followed by the 16-byte authentication tag.
	 * @throws CipherException If ext-openssl is unavailable, the nonce or key is the wrong length, or the cipher fails.
	 */
	public function encrypt( string $plaintext, string $nonce, string $aad, string $key ): string {
		$this->assert_available();
		$this->assert_sizes( $nonce, $key );

		$tag        = '';
		$ciphertext = openssl_encrypt( $plaintext, self::CIPHER_METHOD, $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad, Cipher::TAG_SIZE );
		if ( false === $ciphertext ) {
			throw new CipherException( 'OpensslAesGcmCipher: openssl_encrypt() failed.' );
		}

		return $ciphertext . $tag;
	}

	/**
	 * Decrypt a ciphertext-and-tag with AES-256-GCM, verifying the tag.
	 *
	 * @param string $ciphertext_and_tag The ciphertext with the 16-byte GCM tag appended; must be at least Cipher::TAG_SIZE bytes.
	 * @param string $nonce              The per-entry nonce used to encrypt; must be exactly Cipher::NONCE_SIZE bytes.
	 * @param string $aad                The additional authenticated data used to encrypt; may be empty.
	 * @param string $key                The AES-256 key; must be exactly Cipher::KEY_SIZE bytes.
	 * @return string The decrypted plaintext.
	 * @throws CipherException If ext-openssl is unavailable, a size is wrong, or authentication fails.
	 */
	public function decrypt( string $ciphertext_and_tag, string $nonce, string $aad, string $key ): string {
		$this->assert_available();
		$this->assert_sizes( $nonce, $key );

		if ( strlen( $ciphertext_and_tag ) < Cipher::TAG_SIZE ) {
			throw new CipherException(
				sprintf(
					'OpensslAesGcmCipher: encrypted payload is %d bytes, shorter than the %d-byte authentication tag; the archive is truncated or corrupt.',
					(int) strlen( $ciphertext_and_tag ),
					(int) Cipher::TAG_SIZE
				)
			);
		}

		$tag        = substr( $ciphertext_and_tag, -Cipher::TAG_SIZE );
		$ciphertext = substr( $ciphertext_and_tag, 0, strlen( $ciphertext_and_tag ) - Cipher::TAG_SIZE );

		$plaintext = openssl_decrypt( $ciphertext, self::CIPHER_METHOD, $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad );
		if ( false === $plaintext ) {
			throw new CipherException(
				'OpensslAesGcmCipher: decryption failed; the archive was tampered with or truncated, or the passphrase is wrong.'
			);
		}

		return $plaintext;
	}

	/**
	 * Assert that ext-openssl's encrypt and decrypt functions are available.
	 *
	 * The AES-256-GCM method itself is confirmed by {@see CipherFactory} before
	 * this cipher is selected; here we guard only the function presence so a
	 * direct caller on a host without ext-openssl fails clearly.
	 *
	 * @return void
	 * @throws CipherException If ext-openssl is not loaded.
	 */
	private function assert_available(): void {
		if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'openssl_decrypt' ) ) {
			throw new CipherException( 'OpensslAesGcmCipher: ext-openssl is required but is not loaded.' );
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
				sprintf( 'OpensslAesGcmCipher: nonce must be exactly %d bytes, got %d.', (int) Cipher::NONCE_SIZE, (int) strlen( $nonce ) )
			);
		}
		if ( Cipher::KEY_SIZE !== strlen( $key ) ) {
			throw new CipherException(
				sprintf( 'OpensslAesGcmCipher: key must be exactly %d bytes, got %d.', (int) Cipher::KEY_SIZE, (int) strlen( $key ) )
			);
		}
	}
}
