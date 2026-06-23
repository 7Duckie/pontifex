<?php
/**
 * Contract tests for SodiumAesGcmCipher.
 *
 * @package Pontifex\Tests\Unit\Archive\Crypto
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Archive\Crypto;

use Pontifex\Archive\Crypto\Cipher;
use Pontifex\Archive\Crypto\SodiumAesGcmCipher;

/**
 * Runs the shared Cipher contract against the ext-sodium implementation.
 *
 * AES-256-GCM via libsodium needs hardware AES support; the whole battery is
 * skipped when {@see sodium_crypto_aead_aes256gcm_is_available()} reports it
 * is absent, so a host without it records skips rather than failures (the
 * same approach ZstdCodecTest takes for the optional zstd extension).
 */
final class SodiumAesGcmCipherTest extends CipherContractTestCase {

	/**
	 * Provide the sodium-backed cipher under test.
	 *
	 * @return Cipher The ext-sodium AES-256-GCM cipher.
	 */
	protected function cipher(): Cipher {
		return new SodiumAesGcmCipher();
	}

	/**
	 * Skip when libsodium AES-256-GCM is unavailable on this host.
	 *
	 * @return void
	 */
	protected function skip_if_unavailable(): void {
		if ( ! function_exists( 'sodium_crypto_aead_aes256gcm_is_available' )
			|| ! sodium_crypto_aead_aes256gcm_is_available() ) {
			$this->markTestSkipped( 'AES-256-GCM via ext-sodium (hardware AES) is not available on this host.' );
		}
	}
}
