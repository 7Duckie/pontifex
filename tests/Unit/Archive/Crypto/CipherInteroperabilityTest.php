<?php
/**
 * Cross-implementation interoperability of the AES-256-GCM ciphers.
 *
 * @package Pontifex\Tests\Unit\Archive\Crypto
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Archive\Crypto;

use PHPUnit\Framework\TestCase;
use Pontifex\Archive\Crypto\Cipher;
use Pontifex\Archive\Crypto\OpensslAesGcmCipher;
use Pontifex\Archive\Crypto\SodiumAesGcmCipher;

/**
 * The two ciphers must be byte-for-byte interchangeable.
 *
 * This is the property that lets an archive written on a host with hardware
 * AES (sodium) be opened on a host without it (openssl), and vice versa. If
 * it ever failed, an encrypted archive could become unreadable purely
 * because it moved between machines — exactly the data-loss outcome Pontifex
 * exists to prevent. AES-256-GCM is a deterministic function of key, nonce,
 * AAD and plaintext, so the two implementations must not merely round-trip
 * each other's output — they must produce the *same bytes*.
 *
 * Skipped when libsodium AES-256-GCM is unavailable, since the comparison
 * needs both implementations present.
 */
final class CipherInteroperabilityTest extends TestCase {

	/**
	 * A valid 32-byte AES-256 key for the tests.
	 *
	 * @var string
	 */
	private const KEY = 'interop-interop-interop-interop-';

	/**
	 * A valid 12-byte nonce for the tests.
	 *
	 * @var string
	 */
	private const NONCE = 'interop-non1';

	/**
	 * Additional authenticated data bound to the ciphertext in the tests.
	 *
	 * @var string
	 */
	private const AAD = 'bound-entry-header';

	/**
	 * Skip the suite when libsodium AES-256-GCM is unavailable.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'sodium_crypto_aead_aes256gcm_is_available' )
			|| ! sodium_crypto_aead_aes256gcm_is_available() ) {
			$this->markTestSkipped( 'AES-256-GCM via ext-sodium (hardware AES) is not available, so the cross-implementation comparison cannot run.' );
		}
	}

	/**
	 * Both implementations must produce identical sealed bytes for identical inputs.
	 *
	 * @return void
	 */
	public function test_implementations_produce_identical_bytes(): void {
		$plaintext = 'identical-input across both implementations';

		$sodium  = ( new SodiumAesGcmCipher() )->encrypt( $plaintext, self::NONCE, self::AAD, self::KEY );
		$openssl = ( new OpensslAesGcmCipher() )->encrypt( $plaintext, self::NONCE, self::AAD, self::KEY );

		$this->assertSame( bin2hex( $sodium ), bin2hex( $openssl ) );
	}

	/**
	 * A payload sealed by sodium must decrypt under openssl.
	 *
	 * @return void
	 */
	public function test_sodium_sealed_decrypts_under_openssl(): void {
		$plaintext = 'written on a host with hardware AES';

		$sealed    = ( new SodiumAesGcmCipher() )->encrypt( $plaintext, self::NONCE, self::AAD, self::KEY );
		$recovered = ( new OpensslAesGcmCipher() )->decrypt( $sealed, self::NONCE, self::AAD, self::KEY );

		$this->assertSame( $plaintext, $recovered );
	}

	/**
	 * A payload sealed by openssl must decrypt under sodium.
	 *
	 * @return void
	 */
	public function test_openssl_sealed_decrypts_under_sodium(): void {
		$plaintext = 'written on a host without hardware AES';

		$sealed    = ( new OpensslAesGcmCipher() )->encrypt( $plaintext, self::NONCE, self::AAD, self::KEY );
		$recovered = ( new SodiumAesGcmCipher() )->decrypt( $sealed, self::NONCE, self::AAD, self::KEY );

		$this->assertSame( $plaintext, $recovered );
	}

	/**
	 * An empty payload must cross between implementations in both directions.
	 *
	 * @return void
	 */
	public function test_empty_payload_is_interoperable(): void {
		$sodium  = new SodiumAesGcmCipher();
		$openssl = new OpensslAesGcmCipher();

		$sodium_sealed  = $sodium->encrypt( '', self::NONCE, self::AAD, self::KEY );
		$openssl_sealed = $openssl->encrypt( '', self::NONCE, self::AAD, self::KEY );

		$this->assertSame( bin2hex( $sodium_sealed ), bin2hex( $openssl_sealed ) );
		$this->assertSame( '', $openssl->decrypt( $sodium_sealed, self::NONCE, self::AAD, self::KEY ) );
		$this->assertSame( '', $sodium->decrypt( $openssl_sealed, self::NONCE, self::AAD, self::KEY ) );
	}
}
