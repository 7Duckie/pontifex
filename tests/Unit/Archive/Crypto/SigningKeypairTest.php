<?php
/**
 * Unit tests for the Ed25519 signing keypair value object.
 *
 * @package Pontifex\Tests\Unit\Archive\Crypto
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Archive\Crypto;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pontifex\Archive\Crypto\SigningKeypair;

/**
 * Behavioural coverage of {@see SigningKeypair}: generation, validation and the
 * key id derived from the public key.
 *
 * Keypairs are generated at runtime (never hard-coded), so no key material
 * lives in the repository. The fixed-size dummy keys used for the validation
 * and key-id tests are low-entropy repeated bytes — the value object validates
 * length, not cryptographic validity, and the key id is a plain SHA-256.
 */
final class SigningKeypairTest extends TestCase {

	/**
	 * A real generated keypair has a 32-byte public key and a 64-byte secret key.
	 *
	 * @return void
	 */
	public function test_generate_produces_keys_of_the_right_sizes(): void {
		$keypair = SigningKeypair::generate();

		$this->assertSame( SigningKeypair::PUBLIC_KEY_SIZE, strlen( $keypair->public_key() ) );
		$this->assertSame( SigningKeypair::SECRET_KEY_SIZE, strlen( $keypair->secret_key() ) );
	}

	/**
	 * Each generation draws fresh random key material.
	 *
	 * @return void
	 */
	public function test_generate_returns_distinct_keypairs(): void {
		$this->assertNotSame(
			SigningKeypair::generate()->secret_key(),
			SigningKeypair::generate()->secret_key()
		);
	}

	/**
	 * The accessors return exactly the bytes the constructor was given.
	 *
	 * @return void
	 */
	public function test_accessors_return_constructor_values(): void {
		$public_key = str_repeat( 'p', SigningKeypair::PUBLIC_KEY_SIZE );
		$secret_key = str_repeat( 's', SigningKeypair::SECRET_KEY_SIZE );

		$keypair = new SigningKeypair( $public_key, $secret_key );

		$this->assertSame( $public_key, $keypair->public_key() );
		$this->assertSame( $secret_key, $keypair->secret_key() );
	}

	/**
	 * A public key of the wrong length is rejected at construction.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_wrong_public_key_length(): void {
		$this->expectException( InvalidArgumentException::class );

		new SigningKeypair( str_repeat( 'p', 10 ), str_repeat( 's', SigningKeypair::SECRET_KEY_SIZE ) );
	}

	/**
	 * A secret key of the wrong length is rejected at construction.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_wrong_secret_key_length(): void {
		$this->expectException( InvalidArgumentException::class );

		new SigningKeypair( str_repeat( 'p', SigningKeypair::PUBLIC_KEY_SIZE ), str_repeat( 's', 10 ) );
	}

	/**
	 * The key id is the raw 32-byte SHA-256 of the public key.
	 *
	 * @return void
	 */
	public function test_key_id_is_sha256_of_public_key(): void {
		$public_key = str_repeat( 'p', SigningKeypair::PUBLIC_KEY_SIZE );
		$keypair    = new SigningKeypair( $public_key, str_repeat( 's', SigningKeypair::SECRET_KEY_SIZE ) );

		$this->assertSame( hash( 'sha256', $public_key, true ), $keypair->key_id() );
		$this->assertSame( SigningKeypair::KEY_ID_SIZE, strlen( $keypair->key_id() ) );
	}

	/**
	 * The static key_id_of() agrees with the instance method for the same public key.
	 *
	 * @return void
	 */
	public function test_key_id_of_matches_instance(): void {
		$keypair = SigningKeypair::generate();

		$this->assertSame( $keypair->key_id(), SigningKeypair::key_id_of( $keypair->public_key() ) );
	}

	/**
	 * A wrong-length public key is rejected by key_id_of().
	 *
	 * @return void
	 */
	public function test_key_id_of_rejects_wrong_length(): void {
		$this->expectException( InvalidArgumentException::class );

		SigningKeypair::key_id_of( str_repeat( 'p', 10 ) );
	}
}
