<?php
/**
 * Unit tests for the Ed25519 verifier (and the sign-then-verify round trip).
 *
 * @package Pontifex\Tests\Unit\Archive\Crypto
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Archive\Crypto;

use PHPUnit\Framework\TestCase;
use Pontifex\Archive\Crypto\Ed25519Signer;
use Pontifex\Archive\Crypto\Ed25519Verifier;
use Pontifex\Archive\Crypto\SignatureException;
use Pontifex\Archive\Crypto\SigningKeypair;

/**
 * Behavioural coverage of {@see Ed25519Verifier}: a genuine signature verifies,
 * every way of being wrong returns false, and malformed inputs throw.
 *
 * The signer and verifier are exercised together because verification is only
 * meaningful against a real signature; this is the round trip that proves the
 * two primitives meet.
 */
final class Ed25519VerifierTest extends TestCase {

	/**
	 * A signature made by the matching key verifies true.
	 *
	 * @return void
	 */
	public function test_a_valid_signature_verifies_true(): void {
		$keypair   = SigningKeypair::generate();
		$message   = 'archive bytes through the footer';
		$signature = ( new Ed25519Signer() )->sign( $message, $keypair->secret_key() );

		$this->assertTrue( ( new Ed25519Verifier() )->verify( $message, $signature, $keypair->public_key() ) );
	}

	/**
	 * A signature verified against a different public key fails.
	 *
	 * @return void
	 */
	public function test_a_different_public_key_fails(): void {
		$signer    = new Ed25519Signer();
		$signed_by = SigningKeypair::generate();
		$other     = SigningKeypair::generate();
		$message   = 'archive bytes through the footer';
		$signature = $signer->sign( $message, $signed_by->secret_key() );

		$this->assertFalse( ( new Ed25519Verifier() )->verify( $message, $signature, $other->public_key() ) );
	}

	/**
	 * A single altered message byte makes verification fail.
	 *
	 * @return void
	 */
	public function test_a_tampered_message_fails(): void {
		$keypair   = SigningKeypair::generate();
		$message   = 'archive bytes through the footer';
		$signature = ( new Ed25519Signer() )->sign( $message, $keypair->secret_key() );

		$this->assertFalse( ( new Ed25519Verifier() )->verify( $message . '!', $signature, $keypair->public_key() ) );
	}

	/**
	 * A single altered signature byte makes verification fail.
	 *
	 * @return void
	 */
	public function test_a_tampered_signature_fails(): void {
		$keypair   = SigningKeypair::generate();
		$message   = 'archive bytes through the footer';
		$signature = ( new Ed25519Signer() )->sign( $message, $keypair->secret_key() );

		// Flip the first byte, preserving the 64-byte length.
		$signature[0] = chr( ord( $signature[0] ) ^ 0xFF );

		$this->assertFalse( ( new Ed25519Verifier() )->verify( $message, $signature, $keypair->public_key() ) );
	}

	/**
	 * A signature of the wrong length is a structural error, not a mismatch.
	 *
	 * @return void
	 */
	public function test_verify_rejects_wrong_signature_length(): void {
		$keypair = SigningKeypair::generate();

		$this->expectException( SignatureException::class );

		( new Ed25519Verifier() )->verify( 'the message', str_repeat( 'x', 10 ), $keypair->public_key() );
	}

	/**
	 * A public key of the wrong length is a structural error, not a mismatch.
	 *
	 * @return void
	 */
	public function test_verify_rejects_wrong_public_key_length(): void {
		$keypair   = SigningKeypair::generate();
		$signature = ( new Ed25519Signer() )->sign( 'the message', $keypair->secret_key() );

		$this->expectException( SignatureException::class );

		( new Ed25519Verifier() )->verify( 'the message', $signature, str_repeat( 'x', 10 ) );
	}
}
