<?php
/**
 * Unit tests for the EntryWriteResult value object.
 *
 * @package Pontifex\Tests\Unit\Archive\Writer
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Archive\Writer;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pontifex\Archive\Integrity\Sha256;
use Pontifex\Archive\Writer\EntryWriteResult;

/**
 * Tests for {@see EntryWriteResult}.
 */
final class EntryWriteResultTest extends TestCase {

	/**
	 * Build a 32-byte placeholder hash for tests that don't care about content.
	 *
	 * @return string A 32-byte binary string.
	 */
	private static function placeholder_hash(): string {
		return str_repeat( "\xAB", Sha256::DIGEST_SIZE );
	}

	/**
	 * The constructor must accept valid inputs and expose them via accessors.
	 *
	 * @return void
	 */
	public function test_constructor_accepts_valid_inputs(): void {
		$hash   = self::placeholder_hash();
		$result = new EntryWriteResult( 1024, 1100, $hash );

		$this->assertSame( 1024, $result->payload_length() );
		$this->assertSame( 1100, $result->total_entry_length() );
		$this->assertSame( $hash, $result->entry_hash() );
	}

	/**
	 * The constructor must accept a zero payload_length (empty payload edge case).
	 *
	 * @return void
	 */
	public function test_constructor_accepts_zero_payload_length(): void {
		$result = new EntryWriteResult( 0, 64, self::placeholder_hash() );

		$this->assertSame( 0, $result->payload_length() );
	}

	/**
	 * The constructor must reject a negative payload_length.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_negative_payload_length(): void {
		$this->expectException( InvalidArgumentException::class );

		new EntryWriteResult( -1, 1100, self::placeholder_hash() );
	}

	/**
	 * The constructor must reject a negative total_entry_length.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_negative_total_entry_length(): void {
		$this->expectException( InvalidArgumentException::class );

		new EntryWriteResult( 1024, -1, self::placeholder_hash() );
	}

	/**
	 * The constructor must reject an entry_hash that is too short.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_short_hash(): void {
		$this->expectException( InvalidArgumentException::class );

		new EntryWriteResult( 1024, 1100, str_repeat( "\x00", Sha256::DIGEST_SIZE - 1 ) );
	}

	/**
	 * The constructor must reject an entry_hash that is too long.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_long_hash(): void {
		$this->expectException( InvalidArgumentException::class );

		new EntryWriteResult( 1024, 1100, str_repeat( "\x00", Sha256::DIGEST_SIZE + 1 ) );
	}

	/**
	 * The constructor must reject an empty entry_hash.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_empty_hash(): void {
		$this->expectException( InvalidArgumentException::class );

		new EntryWriteResult( 1024, 1100, '' );
	}

	/**
	 * The entry_hash accessor must return the constructed binary value exactly.
	 *
	 * Verifies that the value object stores raw bytes verbatim and does not
	 * apply any encoding or normalisation.
	 *
	 * @return void
	 */
	public function test_entry_hash_accessor_returns_constructed_bytes_verbatim(): void {
		$hash   = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F"
			. "\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F";
		$result = new EntryWriteResult( 1, 1, $hash );

		$this->assertSame( $hash, $result->entry_hash() );
		$this->assertSame( Sha256::DIGEST_SIZE, strlen( $result->entry_hash() ) );
	}

	/**
	 * Without correction arguments, the result must report no size correction.
	 *
	 * @return void
	 */
	public function test_correction_fields_default_to_no_correction(): void {
		$result = new EntryWriteResult( 1, 1, self::placeholder_hash() );

		$this->assertFalse( $result->size_was_corrected() );
		$this->assertNull( $result->declared_size() );
		$this->assertNull( $result->actual_size() );
	}

	/**
	 * The correction fields must round-trip through the accessors.
	 *
	 * @return void
	 */
	public function test_correction_fields_round_trip(): void {
		$result = new EntryWriteResult( 1, 1, self::placeholder_hash(), 1000, 400 );

		$this->assertTrue( $result->size_was_corrected() );
		$this->assertSame( 1000, $result->declared_size() );
		$this->assertSame( 400, $result->actual_size() );
	}

	/**
	 * The two correction fields must travel together: a lone declared_size is rejected.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_a_lone_declared_size(): void {
		$this->expectException( InvalidArgumentException::class );

		new EntryWriteResult( 1, 1, self::placeholder_hash(), 1000, null );
	}

	/**
	 * The two correction fields must travel together: a lone actual_size is rejected too.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_a_lone_actual_size(): void {
		$this->expectException( InvalidArgumentException::class );

		new EntryWriteResult( 1, 1, self::placeholder_hash(), null, 400 );
	}

	/**
	 * Negative correction values must be rejected.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_negative_correction_sizes(): void {
		$this->expectException( InvalidArgumentException::class );

		new EntryWriteResult( 1, 1, self::placeholder_hash(), -1, 400 );
	}
}
