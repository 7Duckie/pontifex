<?php
/**
 * Tests for the Argon2id key-derivation function.
 *
 * @package Pontifex\Tests\Unit\Archive\Crypto
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Archive\Crypto;

use PHPUnit\Framework\TestCase;
use Pontifex\Archive\Crypto\Argon2idKdf;
use Pontifex\Archive\Crypto\CipherException;

/**
 * Tests for {@see Argon2idKdf}.
 *
 * The headline test is a known-answer vector: a fixed passphrase and salt
 * must derive one exact 32-byte key. Argon2id v1.3 is a deterministic
 * function of its inputs and cost parameters, so this vector is stable
 * across libsodium versions and platforms — and it changes the moment the
 * locked cost parameters (4 iterations, 64 MiB) drift, which is exactly the
 * regression it exists to catch. The parameter constants are also asserted
 * directly, as a second, host-independent guard.
 *
 * Skipped when ext-sodium's sodium_crypto_pwhash is unavailable, since
 * Argon2id has no fallback implementation.
 */
final class Argon2idKdfTest extends TestCase {

	/**
	 * Skip the suite when Argon2id is unavailable.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'sodium_crypto_pwhash' ) ) {
			$this->markTestSkipped( 'Argon2id via ext-sodium (sodium_crypto_pwhash) is not available on this host.' );
		}
	}

	/**
	 * A fixed passphrase and salt must derive the exact expected key.
	 *
	 * The expected value was computed with the format-locked parameters
	 * (Argon2id, 4 iterations, 64 MiB, 32-byte output). Any change to those
	 * parameters changes this key and fails the test.
	 *
	 * @return void
	 */
	public function test_derives_known_answer_vector(): void {
		$key = ( new Argon2idKdf() )->derive( 'correct horse battery staple', 'pontifex-kat-016' );

		$this->assertSame(
			'62bec02b6246f2ee978826cc90d6030e4f09f8a6f666fa30466cac54a25bc940',
			bin2hex( $key )
		);
	}

	/**
	 * The derived key must be exactly 32 bytes.
	 *
	 * @return void
	 */
	public function test_derived_key_is_32_bytes(): void {
		$key = ( new Argon2idKdf() )->derive( 'a-decent-passphrase', 'salt-16-bytes-ok' );

		$this->assertSame( 32, strlen( $key ) );
	}

	/**
	 * The same passphrase under a different salt must derive a different key.
	 *
	 * @return void
	 */
	public function test_different_salt_yields_different_key(): void {
		$kdf = new Argon2idKdf();

		$first  = $kdf->derive( 'same-passphrase', 'salt-number-one!' );
		$second = $kdf->derive( 'same-passphrase', 'salt-number-two!' );

		$this->assertNotSame( bin2hex( $first ), bin2hex( $second ) );
	}

	/**
	 * A different passphrase under the same salt must derive a different key.
	 *
	 * @return void
	 */
	public function test_different_passphrase_yields_different_key(): void {
		$kdf  = new Argon2idKdf();
		$salt = 'one-fixed-salt16';

		$first  = $kdf->derive( 'passphrase-one', $salt );
		$second = $kdf->derive( 'passphrase-two', $salt );

		$this->assertNotSame( bin2hex( $first ), bin2hex( $second ) );
	}

	/**
	 * A salt of the wrong length must raise CipherException.
	 *
	 * @return void
	 */
	public function test_rejects_wrong_salt_length(): void {
		$this->expectException( CipherException::class );

		( new Argon2idKdf() )->derive( 'a-decent-passphrase', 'too-short-salt' );
	}

	/**
	 * The locked cost parameters must hold their format-mandated values.
	 *
	 * A host-independent guard against the named-preset trap: the spec's
	 * (4 iterations, 64 MiB) combination is not one of libsodium's presets.
	 *
	 * @return void
	 */
	public function test_locked_parameter_constants(): void {
		$this->assertSame( 32, Argon2idKdf::KEY_SIZE );
		$this->assertSame( 16, Argon2idKdf::SALT_SIZE );
		$this->assertSame( 4, Argon2idKdf::OPS_LIMIT );
		$this->assertSame( 67108864, Argon2idKdf::MEM_LIMIT );
	}
}
