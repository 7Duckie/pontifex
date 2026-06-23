<?php
/**
 * Selects the AES-256-GCM cipher implementation for the current host.
 *
 * @package Pontifex\Archive\Crypto
 */

declare(strict_types=1);

namespace Pontifex\Archive\Crypto;

/**
 * Chooses the right {@see Cipher} implementation for the host.
 *
 * Both implementations produce byte-for-byte identical archives, so the
 * choice is purely about availability and speed, never about format
 * compatibility:
 *
 *  - {@see SodiumAesGcmCipher} when libsodium reports hardware AES support
 *    ({@see sodium_crypto_aead_aes256gcm_is_available()}) — faster and
 *    constant-time.
 *  - {@see OpensslAesGcmCipher} otherwise, provided ext-openssl offers the
 *    aes-256-gcm method (it does on effectively every WordPress host).
 *
 * If neither is available the factory raises {@see CipherException} rather
 * than returning a broken cipher: a host that can produce neither cannot
 * read or write encrypted archives, and saying so plainly is the safe
 * behaviour. Note that Argon2id key derivation ({@see Argon2idKdf}) is
 * ext-sodium-only and has no fallback, so encryption as a whole still
 * requires ext-sodium; this factory governs only the symmetric cipher.
 *
 * Stateless; safe to reuse.
 */
final class CipherFactory {

	/**
	 * Return the preferred AES-256-GCM cipher for this host.
	 *
	 * @return Cipher A sodium-backed cipher when hardware AES is available, otherwise an openssl-backed one.
	 * @throws CipherException If neither ext-sodium (with hardware AES) nor ext-openssl can provide AES-256-GCM.
	 */
	public function for_host(): Cipher {
		if ( function_exists( 'sodium_crypto_aead_aes256gcm_is_available' )
			&& sodium_crypto_aead_aes256gcm_is_available() ) {
			return new SodiumAesGcmCipher();
		}

		if ( $this->openssl_has_aes_256_gcm() ) {
			return new OpensslAesGcmCipher();
		}

		throw new CipherException(
			'CipherFactory: no AES-256-GCM implementation is available; reading or writing an encrypted archive requires ext-sodium with hardware AES support, or ext-openssl.'
		);
	}

	/**
	 * Whether ext-openssl is loaded and offers the aes-256-gcm cipher method.
	 *
	 * @return bool True when openssl can perform AES-256-GCM.
	 */
	private function openssl_has_aes_256_gcm(): bool {
		if ( ! function_exists( 'openssl_get_cipher_methods' ) || ! function_exists( 'openssl_encrypt' ) ) {
			return false;
		}

		return in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true );
	}
}
