<?php
/**
 * Unit tests for the EntryPlan value object.
 *
 * @package Pontifex\Tests\Unit\Archive\Writer
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Archive\Writer;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Writer\EntryPlan;
use Pontifex\Archive\Writer\EntryWriter;

/**
 * Tests for {@see EntryPlan}.
 */
final class EntryPlanTest extends TestCase {

	/**
	 * Build a sample draft EntryHeader for use in tests.
	 *
	 * @return EntryHeader A file-kind EntryHeader with size_compressed = 0.
	 */
	private static function sample_header(): EntryHeader {
		return EntryHeader::for_file( 'a.txt', 100, 0644, 1690000000, 'application/octet-stream', 0 );
	}

	/**
	 * Return a 12-byte all-zero nonce, the v0.1.0 convention.
	 *
	 * @return string A NONCE_SIZE-byte binary string of zero bytes.
	 */
	private static function zero_nonce(): string {
		return str_repeat( "\x00", EntryWriter::NONCE_SIZE );
	}

	/**
	 * Open a php://memory stream for tests.
	 *
	 * @return resource A readable and writable php://memory stream.
	 * @throws \RuntimeException If php://memory cannot be opened.
	 */
	private static function memory_stream() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://memory is an in-process buffer, not a file; WP_Filesystem cannot open it.
		$stream = fopen( 'php://memory', 'r+b' );
		if ( false === $stream ) {
			throw new \RuntimeException( 'Could not open php://memory for test.' );
		}
		return $stream;
	}

	/**
	 * The constructor must accept valid inputs and expose them via accessors.
	 *
	 * @return void
	 */
	public function test_constructor_accepts_valid_inputs(): void {
		$header = self::sample_header();
		$nonce  = self::zero_nonce();
		$source = self::memory_stream();

		$plan = new EntryPlan( $header, 1, $nonce, $source );

		$this->assertSame( $header, $plan->header() );
		$this->assertSame( 1, $plan->codec_id() );
		$this->assertSame( $nonce, $plan->nonce() );
		$this->assertSame( $source, $plan->source() );
	}

	/**
	 * The constructor must reject a nonce shorter than NONCE_SIZE.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_short_nonce(): void {
		$this->expectException( InvalidArgumentException::class );

		new EntryPlan(
			self::sample_header(),
			0,
			str_repeat( "\x00", EntryWriter::NONCE_SIZE - 1 ),
			self::memory_stream()
		);
	}

	/**
	 * The constructor must reject a nonce longer than NONCE_SIZE.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_long_nonce(): void {
		$this->expectException( InvalidArgumentException::class );

		new EntryPlan(
			self::sample_header(),
			0,
			str_repeat( "\x00", EntryWriter::NONCE_SIZE + 1 ),
			self::memory_stream()
		);
	}

	/**
	 * The constructor must reject a source that is not a stream resource.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_non_resource_source(): void {
		$this->expectException( InvalidArgumentException::class );

		new EntryPlan( self::sample_header(), 0, self::zero_nonce(), 'not a resource' );
	}

	/**
	 * The header accessor must return the exact same EntryHeader instance constructed with.
	 *
	 * @return void
	 */
	public function test_header_accessor_returns_constructed_instance(): void {
		$header = self::sample_header();
		$plan   = new EntryPlan( $header, 0, self::zero_nonce(), self::memory_stream() );

		$this->assertSame( $header, $plan->header() );
	}

	/**
	 * The codec_id accessor must return the constructed value.
	 *
	 * @return void
	 */
	public function test_codec_id_accessor_returns_constructed_value(): void {
		$plan = new EntryPlan( self::sample_header(), 42, self::zero_nonce(), self::memory_stream() );

		$this->assertSame( 42, $plan->codec_id() );
	}

	/**
	 * The nonce accessor must return the constructed bytes verbatim.
	 *
	 * @return void
	 */
	public function test_nonce_accessor_returns_constructed_bytes_verbatim(): void {
		$nonce = "\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C";
		$plan  = new EntryPlan( self::sample_header(), 0, $nonce, self::memory_stream() );

		$this->assertSame( $nonce, $plan->nonce() );
	}

	/**
	 * The source accessor must return the same resource instance constructed with.
	 *
	 * @return void
	 */
	public function test_source_accessor_returns_constructed_resource(): void {
		$source = self::memory_stream();
		$plan   = new EntryPlan( self::sample_header(), 0, self::zero_nonce(), $source );

		$this->assertSame( $source, $plan->source() );
	}
}
