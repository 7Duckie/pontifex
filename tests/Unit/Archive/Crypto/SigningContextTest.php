<?php
/**
 * Unit tests for the signing context value object.
 *
 * @package Pontifex\Tests\Unit\Archive\Crypto
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Archive\Crypto;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pontifex\Archive\Crypto\Ed25519Signer;
use Pontifex\Archive\Crypto\SigningContext;
use Pontifex\Archive\Crypto\SigningKeypair;

/**
 * Behavioural coverage of {@see SigningContext}: construction, validation, and
 * the from_keypair() convenience factory.
 */
final class SigningContextTest extends TestCase {

	/**
	 * The from_keypair factory carries the keypair's secret key and key id, and defaults the signer.
	 *
	 * @return void
	 */
	public function test_from_keypair_carries_keypair_values(): void {
		$keypair = SigningKeypair::generate();

		$context = SigningContext::from_keypair( $keypair );

		$this->assertSame( $keypair->secret_key(), $context->secret_key() );
		$this->assertSame( $keypair->key_id(), $context->key_id() );
		$this->assertInstanceOf( Ed25519Signer::class, $context->signer() );
	}

	/**
	 * The accessors return exactly the values the constructor was given.
	 *
	 * @return void
	 */
	public function test_accessors_return_constructor_values(): void {
		$signer     = new Ed25519Signer();
		$secret_key = str_repeat( 's', SigningKeypair::SECRET_KEY_SIZE );
		$key_id     = str_repeat( 'k', SigningKeypair::KEY_ID_SIZE );

		$context = new SigningContext( $signer, $secret_key, $key_id );

		$this->assertSame( $signer, $context->signer() );
		$this->assertSame( $secret_key, $context->secret_key() );
		$this->assertSame( $key_id, $context->key_id() );
	}

	/**
	 * A secret key of the wrong length is rejected at construction.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_wrong_secret_key_length(): void {
		$this->expectException( InvalidArgumentException::class );

		new SigningContext( new Ed25519Signer(), str_repeat( 's', 10 ), str_repeat( 'k', SigningKeypair::KEY_ID_SIZE ) );
	}

	/**
	 * A key id of the wrong length is rejected at construction.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_wrong_key_id_length(): void {
		$this->expectException( InvalidArgumentException::class );

		new SigningContext( new Ed25519Signer(), str_repeat( 's', SigningKeypair::SECRET_KEY_SIZE ), str_repeat( 'k', 10 ) );
	}
}
