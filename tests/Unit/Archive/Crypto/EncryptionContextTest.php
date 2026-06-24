<?php
/**
 * Tests for the EncryptionContext value object.
 *
 * @package Pontifex\Tests\Unit\Archive\Crypto
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Archive\Crypto;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use Pontifex\Archive\Crypto\Cipher;
use Pontifex\Archive\Crypto\EncryptionContext;
use Pontifex\Archive\Crypto\OpensslAesGcmCipher;

/**
 * Tests for {@see EncryptionContext}.
 *
 * The context bundles the three inputs an encrypted archive needs and
 * validates the two that have a fixed size — the 32-byte key and the 16-byte
 * salt — so a wrong-sized value is caught at construction rather than deep in
 * the cipher. The openssl cipher is used as a stand-in Cipher because the
 * context only holds it; it does no cryptography itself.
 */
final class EncryptionContextTest extends TestCase {

	/**
	 * A valid 32-byte key for the tests.
	 *
	 * @return string A Cipher::KEY_SIZE-byte string.
	 */
	private static function valid_key(): string {
		return str_repeat( 'k', Cipher::KEY_SIZE );
	}

	/**
	 * A valid 16-byte salt for the tests.
	 *
	 * @return string A 16-byte string.
	 */
	private static function valid_salt(): string {
		return str_repeat( 's', 16 );
	}

	/**
	 * A valid context must expose the cipher, key and salt it was given.
	 *
	 * @return void
	 */
	public function test_holds_cipher_key_and_salt(): void {
		$cipher  = new OpensslAesGcmCipher();
		$key     = self::valid_key();
		$salt    = self::valid_salt();
		$context = new EncryptionContext( $cipher, $key, $salt );

		$this->assertSame( $cipher, $context->cipher() );
		$this->assertSame( $key, $context->key() );
		$this->assertSame( $salt, $context->salt() );
	}

	/**
	 * A key shorter than 32 bytes must be rejected.
	 *
	 * @return void
	 */
	public function test_rejects_short_key(): void {
		$this->expectException( InvalidArgumentException::class );

		new EncryptionContext( new OpensslAesGcmCipher(), str_repeat( 'k', Cipher::KEY_SIZE - 1 ), self::valid_salt() );
	}

	/**
	 * A key longer than 32 bytes must be rejected.
	 *
	 * @return void
	 */
	public function test_rejects_long_key(): void {
		$this->expectException( InvalidArgumentException::class );

		new EncryptionContext( new OpensslAesGcmCipher(), str_repeat( 'k', Cipher::KEY_SIZE + 1 ), self::valid_salt() );
	}

	/**
	 * A salt shorter than 16 bytes must be rejected.
	 *
	 * @return void
	 */
	public function test_rejects_short_salt(): void {
		$this->expectException( InvalidArgumentException::class );

		new EncryptionContext( new OpensslAesGcmCipher(), self::valid_key(), str_repeat( 's', 15 ) );
	}

	/**
	 * A salt longer than 16 bytes must be rejected.
	 *
	 * @return void
	 */
	public function test_rejects_long_salt(): void {
		$this->expectException( InvalidArgumentException::class );

		new EncryptionContext( new OpensslAesGcmCipher(), self::valid_key(), str_repeat( 's', 17 ) );
	}

	/**
	 * A context may be consumed once; a second use is refused.
	 *
	 * This is the guard against reusing one context (one key) across two
	 * archives, which would repeat the deterministic per-entry nonces.
	 *
	 * @return void
	 */
	public function test_consume_succeeds_once_then_refuses(): void {
		$context = new EncryptionContext( new OpensslAesGcmCipher(), self::valid_key(), self::valid_salt() );

		$context->consume();

		$this->expectException( LogicException::class );
		$context->consume();
	}

	/**
	 * Destruction wipes the key, after which the key accessor refuses to return it.
	 *
	 * Calling __destruct() twice proves the wipe is idempotent (it also runs
	 * implicitly when the object goes out of scope).
	 *
	 * @return void
	 */
	public function test_destruct_wipes_the_key(): void {
		if ( ! function_exists( 'sodium_memzero' ) ) {
			self::markTestSkipped( 'ext-sodium is required to wipe key material.' );
		}

		$context = new EncryptionContext( new OpensslAesGcmCipher(), self::valid_key(), self::valid_salt() );

		$context->__destruct();
		$context->__destruct();

		$this->expectException( LogicException::class );
		$context->key();
	}
}
