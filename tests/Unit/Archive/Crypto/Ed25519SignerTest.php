<?php
/**
 * Unit tests for the Ed25519 signer.
 *
 * @package Pontifex\Tests\Unit\Archive\Crypto
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Archive\Crypto;

use PHPUnit\Framework\TestCase;
use Pontifex\Archive\Crypto\Ed25519Signer;
use Pontifex\Archive\Crypto\SignatureException;
use Pontifex\Archive\Crypto\SigningKeypair;

/**
 * Behavioural coverage of {@see Ed25519Signer}: signature shape, determinism,
 * and rejection of a malformed key.
 *
 * The verify side (and the sign-then-verify round trip) lives in
 * {@see Ed25519VerifierTest}; here the focus is the signing primitive itself.
 */
final class Ed25519SignerTest extends TestCase {

	/**
	 * A signature is exactly 64 bytes.
	 *
	 * @return void
	 */
	public function test_sign_produces_a_64_byte_signature(): void {
		$keypair = SigningKeypair::generate();

		$signature = ( new Ed25519Signer() )->sign( 'the message', $keypair->secret_key() );

		$this->assertSame( 64, strlen( $signature ) );
	}

	/**
	 * Ed25519 is deterministic: the same message and key always sign identically.
	 *
	 * @return void
	 */
	public function test_signing_is_deterministic(): void {
		$keypair = SigningKeypair::generate();
		$signer  = new Ed25519Signer();

		$this->assertSame(
			$signer->sign( 'the message', $keypair->secret_key() ),
			$signer->sign( 'the message', $keypair->secret_key() )
		);
	}

	/**
	 * Different messages under the same key produce different signatures.
	 *
	 * @return void
	 */
	public function test_different_messages_produce_different_signatures(): void {
		$keypair = SigningKeypair::generate();
		$signer  = new Ed25519Signer();

		$this->assertNotSame(
			$signer->sign( 'message one', $keypair->secret_key() ),
			$signer->sign( 'message two', $keypair->secret_key() )
		);
	}

	/**
	 * A secret key of the wrong length is refused.
	 *
	 * @return void
	 */
	public function test_sign_rejects_wrong_secret_key_length(): void {
		$this->expectException( SignatureException::class );

		( new Ed25519Signer() )->sign( 'the message', str_repeat( 'x', 10 ) );
	}
}
