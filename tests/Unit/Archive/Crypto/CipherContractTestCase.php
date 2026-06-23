<?php
/**
 * Shared contract tests every Cipher implementation must satisfy.
 *
 * @package Pontifex\Tests\Unit\Archive\Crypto
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Archive\Crypto;

use PHPUnit\Framework\TestCase;
use Pontifex\Archive\Crypto\Cipher;
use Pontifex\Archive\Crypto\CipherException;

/**
 * The behaviour every {@see Cipher} implementation must share.
 *
 * Both the sodium and openssl ciphers implement the same authenticated-
 * encryption contract and must be interchangeable, so the adversarial
 * battery is written once here and run against each concrete implementation
 * by a thin subclass. (A subclass supplies the implementation under test via
 * {@see self::cipher()} and decides, via {@see self::skip_if_unavailable()},
 * whether the host can run it at all.) The class is named `…TestCase` rather
 * than `…Test` so PHPUnit's test discovery does not try to run this base
 * directly; only its concrete subclasses are collected.
 *
 * The battery covers the properties that make authenticated encryption safe:
 * a faithful round trip, and a hard refusal — never silent, wrong output —
 * when the key, nonce, additional-authenticated-data (AAD), tag, or
 * ciphertext does not match what was used to encrypt.
 */
abstract class CipherContractTestCase extends TestCase {

	/**
	 * A valid 32-byte AES-256 key for the tests.
	 *
	 * @var string
	 */
	private const KEY = 'aes-256-aes-256-aes-256-aes-256-';

	/**
	 * A valid 12-byte nonce for the tests.
	 *
	 * @var string
	 */
	private const NONCE = 'nonce-12byte';

	/**
	 * Additional authenticated data bound to the ciphertext in the tests.
	 *
	 * @var string
	 */
	private const AAD = 'entry-header-bytes';

	/**
	 * Return the cipher implementation under test.
	 *
	 * @return Cipher The concrete cipher the subclass exercises.
	 */
	abstract protected function cipher(): Cipher;

	/**
	 * Skip the test when the implementation is unavailable on this host.
	 *
	 * @return void
	 */
	abstract protected function skip_if_unavailable(): void;

	/**
	 * Skip the whole battery when the host cannot run the implementation.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->skip_if_unavailable();
	}

	/**
	 * The implementation must satisfy the Cipher contract.
	 *
	 * @return void
	 */
	public function test_is_a_cipher(): void {
		$this->assertInstanceOf( Cipher::class, $this->cipher() );
	}

	/**
	 * Encrypt then decrypt must reproduce the original plaintext exactly.
	 *
	 * Uses binary bytes including 0x00 and 0xFF to prove the cipher does not
	 * assume text content.
	 *
	 * @return void
	 */
	public function test_round_trip_reproduces_plaintext(): void {
		$cipher    = $this->cipher();
		$plaintext = 'Pontifex payload with binary: ' . chr( 0 ) . chr( 255 ) . chr( 1 ) . ' end.';

		$sealed    = $cipher->encrypt( $plaintext, self::NONCE, self::AAD, self::KEY );
		$recovered = $cipher->decrypt( $sealed, self::NONCE, self::AAD, self::KEY );

		$this->assertSame( $plaintext, $recovered );
	}

	/**
	 * An empty payload must round-trip to an empty payload.
	 *
	 * Directories and empty files produce empty payloads, so this is a real
	 * case, not an edge curiosity. The sealed form is exactly the 16-byte tag.
	 *
	 * @return void
	 */
	public function test_round_trip_empty_plaintext(): void {
		$cipher = $this->cipher();

		$sealed = $cipher->encrypt( '', self::NONCE, self::AAD, self::KEY );

		$this->assertSame( Cipher::TAG_SIZE, strlen( $sealed ) );
		$this->assertSame( '', $cipher->decrypt( $sealed, self::NONCE, self::AAD, self::KEY ) );
	}

	/**
	 * A non-trivial payload must round-trip exactly.
	 *
	 * @return void
	 */
	public function test_round_trip_large_payload(): void {
		$cipher    = $this->cipher();
		$plaintext = str_repeat( 'Pontifex archive payload. ', 8192 );

		$sealed    = $cipher->encrypt( $plaintext, self::NONCE, self::AAD, self::KEY );
		$recovered = $cipher->decrypt( $sealed, self::NONCE, self::AAD, self::KEY );

		$this->assertSame( $plaintext, $recovered );
	}

	/**
	 * The sealed output must be the ciphertext plus the 16-byte tag.
	 *
	 * AES-GCM ciphertext is the same length as the plaintext, so the sealed
	 * form is exactly plaintext length plus the tag.
	 *
	 * @return void
	 */
	public function test_sealed_output_is_plaintext_length_plus_tag(): void {
		$cipher    = $this->cipher();
		$plaintext = 'twenty-byte payload!';

		$sealed = $cipher->encrypt( $plaintext, self::NONCE, self::AAD, self::KEY );

		$this->assertSame( strlen( $plaintext ) + Cipher::TAG_SIZE, strlen( $sealed ) );
	}

	/**
	 * Decrypting with the wrong key must fail, never return wrong plaintext.
	 *
	 * @return void
	 */
	public function test_decrypt_with_wrong_key_fails(): void {
		$cipher = $this->cipher();
		$sealed = $cipher->encrypt( 'secret', self::NONCE, self::AAD, self::KEY );

		$this->expectException( CipherException::class );

		$cipher->decrypt( $sealed, self::NONCE, self::AAD, 'WRONG-K-WRONG-K-WRONG-K-WRONG-K-' );
	}

	/**
	 * Decrypting with the wrong nonce must fail.
	 *
	 * @return void
	 */
	public function test_decrypt_with_wrong_nonce_fails(): void {
		$cipher = $this->cipher();
		$sealed = $cipher->encrypt( 'secret', self::NONCE, self::AAD, self::KEY );

		$this->expectException( CipherException::class );

		$cipher->decrypt( $sealed, 'WRONGnonce12', self::AAD, self::KEY );
	}

	/**
	 * Decrypting with different AAD must fail — this is what binds the entry header.
	 *
	 * Binding the entry header as AAD is what stops an attacker moving an
	 * encrypted payload to a different entry slot: the header changes, so the
	 * tag no longer verifies.
	 *
	 * @return void
	 */
	public function test_decrypt_with_tampered_aad_fails(): void {
		$cipher = $this->cipher();
		$sealed = $cipher->encrypt( 'secret', self::NONCE, self::AAD, self::KEY );

		$this->expectException( CipherException::class );

		$cipher->decrypt( $sealed, self::NONCE, 'different-header', self::KEY );
	}

	/**
	 * Flipping a byte of the ciphertext must make decryption fail.
	 *
	 * @return void
	 */
	public function test_decrypt_with_tampered_ciphertext_fails(): void {
		$cipher    = $this->cipher();
		$plaintext = 'a payload long enough to have a body before the tag';
		$sealed    = $cipher->encrypt( $plaintext, self::NONCE, self::AAD, self::KEY );

		$tampered = $this->flip_byte( $sealed, 0 );

		$this->expectException( CipherException::class );

		$cipher->decrypt( $tampered, self::NONCE, self::AAD, self::KEY );
	}

	/**
	 * Flipping a byte of the trailing tag must make decryption fail.
	 *
	 * @return void
	 */
	public function test_decrypt_with_tampered_tag_fails(): void {
		$cipher = $this->cipher();
		$sealed = $cipher->encrypt( 'secret', self::NONCE, self::AAD, self::KEY );

		$tampered = $this->flip_byte( $sealed, strlen( $sealed ) - 1 );

		$this->expectException( CipherException::class );

		$cipher->decrypt( $tampered, self::NONCE, self::AAD, self::KEY );
	}

	/**
	 * The same plaintext under two different nonces must produce different ciphertext.
	 *
	 * @return void
	 */
	public function test_different_nonce_produces_different_ciphertext(): void {
		$cipher    = $this->cipher();
		$plaintext = 'identical plaintext';

		$first  = $cipher->encrypt( $plaintext, 'nonce-aaaaaa', self::AAD, self::KEY );
		$second = $cipher->encrypt( $plaintext, 'nonce-bbbbbb', self::AAD, self::KEY );

		$this->assertNotSame( $first, $second );
	}

	/**
	 * Encrypting with a wrong-length nonce must raise CipherException.
	 *
	 * @return void
	 */
	public function test_encrypt_rejects_wrong_nonce_length(): void {
		$cipher = $this->cipher();

		$this->expectException( CipherException::class );

		$cipher->encrypt( 'payload', 'too-short', self::AAD, self::KEY );
	}

	/**
	 * Encrypting with a wrong-length key must raise CipherException.
	 *
	 * @return void
	 */
	public function test_encrypt_rejects_wrong_key_length(): void {
		$cipher = $this->cipher();

		$this->expectException( CipherException::class );

		$cipher->encrypt( 'payload', self::NONCE, self::AAD, 'short-key' );
	}

	/**
	 * Decrypting a payload shorter than the tag must raise CipherException.
	 *
	 * @return void
	 */
	public function test_decrypt_rejects_payload_shorter_than_tag(): void {
		$cipher = $this->cipher();

		$this->expectException( CipherException::class );

		$cipher->decrypt( 'too-short', self::NONCE, self::AAD, self::KEY );
	}

	/**
	 * Flip one byte of a string so it differs from the original.
	 *
	 * @param string $bytes    The bytes to alter.
	 * @param int    $position The zero-based byte position to flip.
	 * @return string A copy with the byte at $position changed.
	 */
	private function flip_byte( string $bytes, int $position ): string {
		$bytes[ $position ] = "\x00" === $bytes[ $position ] ? "\x01" : "\x00";
		return $bytes;
	}
}
