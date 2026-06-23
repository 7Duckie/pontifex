<?php
/**
 * Contract tests for OpensslAesGcmCipher.
 *
 * @package Pontifex\Tests\Unit\Archive\Crypto
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Archive\Crypto;

use Pontifex\Archive\Crypto\Cipher;
use Pontifex\Archive\Crypto\OpensslAesGcmCipher;

/**
 * Runs the shared Cipher contract against the ext-openssl implementation.
 *
 * AES-256-GCM in openssl is implemented in software, so it is available on
 * effectively every host; the battery is still skipped if ext-openssl or the
 * aes-256-gcm method is somehow absent, so the suite degrades to skips rather
 * than errors in that unusual case.
 */
final class OpensslAesGcmCipherTest extends CipherContractTestCase {

	/**
	 * Provide the openssl-backed cipher under test.
	 *
	 * @return Cipher The ext-openssl AES-256-GCM cipher.
	 */
	protected function cipher(): Cipher {
		return new OpensslAesGcmCipher();
	}

	/**
	 * Skip when ext-openssl's aes-256-gcm method is unavailable on this host.
	 *
	 * @return void
	 */
	protected function skip_if_unavailable(): void {
		if ( ! function_exists( 'openssl_get_cipher_methods' )
			|| ! in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true ) ) {
			$this->markTestSkipped( 'AES-256-GCM via ext-openssl is not available on this host.' );
		}
	}
}
