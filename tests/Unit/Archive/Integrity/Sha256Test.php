<?php
/**
 * Behavioural tests for Sha256.
 *
 * @package Pontifex\Tests\Unit\Archive\Integrity
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Archive\Integrity;

use PHPUnit\Framework\TestCase;
use Pontifex\Archive\Integrity\Sha256;
use RuntimeException;

/**
 * Behavioural tests for the Sha256 class.
 *
 * Verifies correctness against canonical NIST SHA-256 test vectors
 * and the class's own invariants:
 *
 *  - One-shot Sha256::of() matches published test vectors.
 *  - Streaming update() + digest() produces the same digest as
 *    one-shot for the same input.
 *  - DIGEST_SIZE constant is 32, matching the SHA-256 specification.
 *  - Instances are single-use: update() or digest() after digest()
 *    raises RuntimeException.
 *
 * The test vectors used are well-known reference values from the
 * NIST SHA-256 specification and supplementary publications.
 */
final class Sha256Test extends TestCase {

	/**
	 * Canonical NIST SHA-256 digest of the empty string, as hex.
	 *
	 * @var string
	 */
	private const HEX_EMPTY = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

	/**
	 * Canonical NIST SHA-256 digest of the ASCII string "abc", as hex.
	 *
	 * @var string
	 */
	private const HEX_ABC = 'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad';

	/**
	 * Canonical SHA-256 digest of the pangram, as hex.
	 *
	 * @var string
	 */
	private const HEX_PANGRAM = 'd7a8fbb307d7809469ca9abcb0082e4f8d5651e46d3cdb762d02d0bf37c9e592';

	/**
	 * One-shot Sha256::of('') must equal the canonical empty-string digest.
	 *
	 * @return void
	 */
	public function test_one_shot_of_empty_string_matches_nist_vector(): void {
		$this->assertSame( self::HEX_EMPTY, bin2hex( Sha256::of( '' ) ) );
	}

	/**
	 * One-shot Sha256::of('abc') must equal the canonical "abc" digest.
	 *
	 * @return void
	 */
	public function test_one_shot_of_abc_matches_nist_vector(): void {
		$this->assertSame( self::HEX_ABC, bin2hex( Sha256::of( 'abc' ) ) );
	}

	/**
	 * One-shot Sha256::of() of the pangram must match its canonical digest.
	 *
	 * @return void
	 */
	public function test_one_shot_of_pangram_matches_canonical_vector(): void {
		$digest = Sha256::of( 'The quick brown fox jumps over the lazy dog' );
		$this->assertSame( self::HEX_PANGRAM, bin2hex( $digest ) );
	}

	/**
	 * The DIGEST_SIZE constant must equal 32 (the SHA-256 output size).
	 *
	 * @return void
	 */
	public function test_digest_size_constant_is_thirty_two(): void {
		$this->assertSame( 32, Sha256::DIGEST_SIZE );
	}

	/**
	 * Every one-shot digest must be exactly 32 bytes long.
	 *
	 * @return void
	 */
	public function test_one_shot_output_is_thirty_two_bytes(): void {
		$this->assertSame( 32, strlen( Sha256::of( '' ) ) );
		$this->assertSame( 32, strlen( Sha256::of( 'abc' ) ) );
		$this->assertSame( 32, strlen( Sha256::of( str_repeat( 'x', 1024 ) ) ) );
	}

	/**
	 * A fresh Sha256 with no updates must digest() to the empty-string SHA-256.
	 *
	 * @return void
	 */
	public function test_streaming_empty_matches_nist_vector(): void {
		$hasher = new Sha256();

		$this->assertSame( self::HEX_EMPTY, bin2hex( $hasher->digest() ) );
	}

	/**
	 * Streaming via update() must produce the same digest as one-shot Sha256::of().
	 *
	 * Splits a known payload across three update() calls; the final
	 * digest must equal the one-shot digest of the concatenated payload.
	 *
	 * @return void
	 */
	public function test_streaming_with_split_updates_matches_one_shot(): void {
		$payload = 'The quick brown fox jumps over the lazy dog';
		$hasher  = new Sha256();
		$hasher->update( 'The quick brown ' );
		$hasher->update( 'fox jumps over ' );
		$hasher->update( 'the lazy dog' );

		$this->assertSame( Sha256::of( $payload ), $hasher->digest() );
	}

	/**
	 * Calling update() with an empty string must be a no-op.
	 *
	 * Repeated empty-string updates between meaningful updates must
	 * not change the final digest.
	 *
	 * @return void
	 */
	public function test_update_with_empty_string_is_noop(): void {
		$hasher = new Sha256();
		$hasher->update( '' );
		$hasher->update( 'abc' );
		$hasher->update( '' );
		$hasher->update( '' );

		$this->assertSame( self::HEX_ABC, bin2hex( $hasher->digest() ) );
	}

	/**
	 * Calling update() after digest() must raise RuntimeException.
	 *
	 * @return void
	 */
	public function test_update_after_digest_throws(): void {
		$hasher = new Sha256();
		$hasher->digest();

		$this->expectException( RuntimeException::class );

		$hasher->update( 'too late' );
	}

	/**
	 * Calling digest() twice must raise RuntimeException.
	 *
	 * @return void
	 */
	public function test_digest_twice_throws(): void {
		$hasher = new Sha256();
		$hasher->digest();

		$this->expectException( RuntimeException::class );

		$hasher->digest();
	}
}
