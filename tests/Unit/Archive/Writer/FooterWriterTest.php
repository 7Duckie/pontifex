<?php
/**
 * Unit tests for the FooterWriter class.
 *
 * @package Pontifex\Tests\Unit\Archive\Writer
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Archive\Writer;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pontifex\Archive\Format\Footer;
use Pontifex\Archive\Integrity\Sha256;
use Pontifex\Archive\Writer\FooterWriter;

/**
 * Tests for {@see FooterWriter}.
 *
 * Each test writes a footer to a memory stream and asserts on the
 * stream's contents. The Footer value object's own round-trip
 * contract (Footer::from_bytes(Footer::to_bytes()) === original) is
 * exercised at the Footer level; these tests focus on the writer's
 * stream-handling behaviour.
 */
final class FooterWriterTest extends TestCase {

	/**
	 * Build a Footer with realistic but arbitrary field values for tests.
	 *
	 * @return Footer A v0.1.0-style unencrypted footer with placeholder values.
	 */
	private static function sample_footer(): Footer {
		return new Footer(
			1024,
			512,
			str_repeat( "\xAB", Sha256::DIGEST_SIZE ),
			Footer::ZERO_SALT
		);
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
	 * Rewind a stream and return all its contents as a string.
	 *
	 * @param resource $stream The stream to read.
	 * @return string The full stream contents.
	 * @throws \RuntimeException If the stream cannot be read.
	 */
	private static function read_all( $stream ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource, not a filesystem path.
		rewind( $stream );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_stream_get_contents -- Operating on a test stream resource, not a filesystem path.
		$contents = stream_get_contents( $stream );
		if ( false === $contents ) {
			throw new \RuntimeException( 'Could not read test stream.' );
		}
		return $contents;
	}

	/**
	 * The write_footer method must write exactly Footer::SIZE bytes to the destination.
	 *
	 * @return void
	 */
	public function test_write_footer_writes_64_bytes(): void {
		$dest   = self::memory_stream();
		$writer = new FooterWriter();

		$writer->write_footer( self::sample_footer(), $dest );

		$this->assertSame( Footer::SIZE, strlen( self::read_all( $dest ) ) );
	}

	/**
	 * The write_footer method must return the byte count it wrote (Footer::SIZE).
	 *
	 * @return void
	 */
	public function test_write_footer_returns_size_constant(): void {
		$dest   = self::memory_stream();
		$writer = new FooterWriter();

		$bytes_written = $writer->write_footer( self::sample_footer(), $dest );

		$this->assertSame( Footer::SIZE, $bytes_written );
	}

	/**
	 * The write_footer method must produce the same bytes that Footer::to_bytes produces.
	 *
	 * @return void
	 */
	public function test_write_footer_matches_to_bytes(): void {
		$dest   = self::memory_stream();
		$writer = new FooterWriter();
		$footer = self::sample_footer();

		$writer->write_footer( $footer, $dest );

		$this->assertSame( $footer->to_bytes(), self::read_all( $dest ) );
	}

	/**
	 * Written footer bytes must round-trip back through Footer::from_bytes.
	 *
	 * @return void
	 */
	public function test_write_footer_round_trip_via_from_bytes(): void {
		$dest   = self::memory_stream();
		$writer = new FooterWriter();

		$original = new Footer(
			999999,
			42,
			str_repeat( "\x7F", Sha256::DIGEST_SIZE ),
			str_repeat( "\xCC", Footer::ARGON2ID_SALT_SIZE )
		);
		$writer->write_footer( $original, $dest );

		$parsed = Footer::from_bytes( self::read_all( $dest ) );

		$this->assertSame( $original->manifest_offset(), $parsed->manifest_offset() );
		$this->assertSame( $original->manifest_length(), $parsed->manifest_length() );
		$this->assertSame( $original->manifest_hash(), $parsed->manifest_hash() );
		$this->assertSame( $original->argon2id_salt(), $parsed->argon2id_salt() );
	}

	/**
	 * The write_footer method must append at the destination's current seek position.
	 *
	 * Pre-existing bytes in the stream must remain ahead of the footer,
	 * and the writer must not seek to a different position before writing.
	 *
	 * @return void
	 */
	public function test_write_footer_appends_at_current_position(): void {
		$dest   = self::memory_stream();
		$writer = new FooterWriter();

		$prefix = 'PREFIX_BYTES_BEFORE_FOOTER';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Operating on a test stream resource.
		fwrite( $dest, $prefix );

		$writer->write_footer( self::sample_footer(), $dest );

		$contents = self::read_all( $dest );

		$this->assertSame( strlen( $prefix ) + Footer::SIZE, strlen( $contents ) );
		$this->assertSame( $prefix, substr( $contents, 0, strlen( $prefix ) ) );
		$this->assertSame( self::sample_footer()->to_bytes(), substr( $contents, strlen( $prefix ) ) );
	}

	/**
	 * The write_footer method must support being called multiple times on the same writer instance.
	 *
	 * The writer is stateless, so successive calls must each append a full
	 * footer block without interference.
	 *
	 * @return void
	 */
	public function test_write_footer_supports_multiple_calls(): void {
		$dest   = self::memory_stream();
		$writer = new FooterWriter();

		$writer->write_footer( self::sample_footer(), $dest );
		$writer->write_footer( self::sample_footer(), $dest );

		$this->assertSame( Footer::SIZE * 2, strlen( self::read_all( $dest ) ) );
	}

	/**
	 * The write_footer method must reject a destination that is not a stream resource.
	 *
	 * @return void
	 */
	public function test_write_footer_rejects_non_resource_destination(): void {
		$writer = new FooterWriter();

		$this->expectException( InvalidArgumentException::class );

		$writer->write_footer( self::sample_footer(), 'not a resource' );
	}
}
