<?php
/**
 * Tests for CipherFactory host selection.
 *
 * @package Pontifex\Tests\Unit\Archive\Crypto
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Archive\Crypto;

use PHPUnit\Framework\TestCase;
use Pontifex\Archive\Crypto\Cipher;
use Pontifex\Archive\Crypto\CipherFactory;
use Pontifex\Archive\Crypto\SodiumAesGcmCipher;

/**
 * Tests for {@see CipherFactory}.
 *
 * The factory's job is to hand back a working AES-256-GCM cipher for the
 * host. The openssl-fallback selection cannot be exercised directly on a
 * host that *does* have hardware AES (it would mean simulating sodium's
 * absence), so the openssl path is proven by {@see OpensslAesGcmCipherTest}
 * and only the sodium-preference and returns-a-usable-cipher properties are
 * asserted here.
 */
final class CipherFactoryTest extends TestCase {

	/**
	 * The factory must return a cipher that satisfies the Cipher contract.
	 *
	 * @return void
	 */
	public function test_for_host_returns_a_cipher(): void {
		$cipher = ( new CipherFactory() )->for_host();

		$this->assertInstanceOf( Cipher::class, $cipher );
	}

	/**
	 * The returned cipher must actually round-trip a payload.
	 *
	 * @return void
	 */
	public function test_returned_cipher_round_trips(): void {
		$cipher = ( new CipherFactory() )->for_host();

		$key    = str_repeat( 'k', Cipher::KEY_SIZE );
		$nonce  = str_repeat( 'n', Cipher::NONCE_SIZE );
		$sealed = $cipher->encrypt( 'factory payload', $nonce, 'aad', $key );

		$this->assertSame( 'factory payload', $cipher->decrypt( $sealed, $nonce, 'aad', $key ) );
	}

	/**
	 * On a host with hardware AES, the factory must prefer the sodium cipher.
	 *
	 * @return void
	 */
	public function test_prefers_sodium_when_hardware_aes_available(): void {
		if ( ! function_exists( 'sodium_crypto_aead_aes256gcm_is_available' )
			|| ! sodium_crypto_aead_aes256gcm_is_available() ) {
			$this->markTestSkipped( 'Hardware AES is not available, so the sodium-preference path cannot be asserted here.' );
		}

		$this->assertInstanceOf( SodiumAesGcmCipher::class, ( new CipherFactory() )->for_host() );
	}
}
