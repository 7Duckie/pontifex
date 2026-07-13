<?php
/**
 * Unit tests for the IncrementalArchiveWriter class.
 *
 * @package Pontifex\Tests\Unit\Archive\Writer
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Archive\Writer;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Pontifex\Archive\Codec\CodecRegistry;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Format\ExporterInfo;
use Pontifex\Archive\Format\Provenance;
use Pontifex\Archive\Writer\ArchiveWriter;
use Pontifex\Archive\Writer\EntryPlan;
use Pontifex\Archive\Writer\EntryWriter;
use Pontifex\Archive\Writer\FooterWriter;
use Pontifex\Archive\Writer\IncrementalArchiveWriter;

/**
 * Tests for {@see IncrementalArchiveWriter}.
 *
 * The load-bearing property is adoption: an archive written in two
 * instalments — one instance begins and appends, a second instance
 * adopts the partial bytes and finishes — must be byte-identical to the
 * same archive written in one pass. That identity is what makes a
 * resumed export produce exactly the archive an uninterrupted one would
 * (encryption aside, which is begin-only and refused on resume by the
 * export layer).
 */
final class IncrementalArchiveWriterTest extends TestCase {

	/**
	 * A fixed provenance so two writes of the same inputs are byte-identical.
	 *
	 * @return Provenance The provenance fixture.
	 */
	private static function sample_provenance(): Provenance {
		return new Provenance(
			'6.6.1',
			'8.3.0',
			'https://example.test',
			'utf8mb4',
			'utf8mb4_unicode_520_ci',
			new ExporterInfo( 'pontifex', '0.0.0-test' ),
			new DateTimeImmutable( '2026-01-01T00:00:00', new DateTimeZone( 'UTC' ) ),
			null,
			null,
			null
		);
	}

	/**
	 * Build the three deterministic entry plans used by the identity test.
	 *
	 * Fresh streams every call: sources are consumed by writing.
	 *
	 * @return EntryPlan[] Three file plans.
	 */
	private static function sample_plans(): array {
		$plans = array();
		foreach ( array(
			'a.txt' => 'alpha content',
			'b.txt' => str_repeat( 'beta ', 100 ),
			'c.txt' => 'gamma',
		) as $path => $contents ) {
			$plans[] = new EntryPlan(
				EntryHeader::for_file( $path, strlen( $contents ), 0644, 1690000000, 'application/octet-stream', 0 ),
				0,
				str_repeat( "\0", EntryWriter::NONCE_SIZE ),
				self::memory_stream( $contents )
			);
		}
		return $plans;
	}

	/**
	 * Open a php://memory stream pre-populated with bytes, cursor rewound.
	 *
	 * @param string $contents Bytes to seed.
	 * @return resource The stream.
	 * @throws RuntimeException If php://memory cannot be opened.
	 */
	private static function memory_stream( string $contents = '' ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://memory is an in-process buffer, not a file.
		$stream = fopen( 'php://memory', 'r+b' );
		if ( false === $stream ) {
			throw new RuntimeException( 'Could not open php://memory for test.' );
		}
		if ( '' !== $contents ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Operating on a test stream resource.
			fwrite( $stream, $contents );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource.
			rewind( $stream );
		}
		return $stream;
	}

	/**
	 * Rewind a stream and return all its contents.
	 *
	 * @param resource $stream The stream to read.
	 * @return string The full contents.
	 */
	private static function read_all( $stream ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource.
		rewind( $stream );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_stream_get_contents -- Operating on a test stream resource.
		return (string) stream_get_contents( $stream );
	}

	/**
	 * An archive finished by an adopting second instance is byte-identical to a one-shot write.
	 *
	 * @return void
	 */
	public function test_adoption_produces_a_byte_identical_archive(): void {
		// One-shot reference, via the public ArchiveWriter driver.
		$reference_stream = self::memory_stream();
		( new ArchiveWriter( new EntryWriter( CodecRegistry::with_defaults() ), new FooterWriter() ) )
			->write_archive( self::sample_provenance(), self::sample_plans(), $reference_stream );
		$reference = self::read_all( $reference_stream );

		// Instalment one: begin and append a single entry.
		$stream = self::memory_stream();
		$plans  = self::sample_plans();
		$first  = new IncrementalArchiveWriter( new EntryWriter( CodecRegistry::with_defaults() ), new FooterWriter() );
		$first->begin( $stream, self::sample_provenance() );
		$adopted_entries = array( $first->append_entry( $plans[0] ) );
		$adopted_bytes   = $first->bytes_written();

		// Instalment two: a NEW instance adopts the partial bytes and finishes.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek -- Positioning the test stream at its end, as the export layer's open_temp does.
		fseek( $stream, 0, SEEK_END );
		$second = new IncrementalArchiveWriter( new EntryWriter( CodecRegistry::with_defaults() ), new FooterWriter() );
		$second->adopt( $stream, $adopted_bytes, $adopted_entries );
		$second->append_entry( $plans[1] );
		$second->append_entry( $plans[2] );
		$second->finish();

		$this->assertSame( $reference, self::read_all( $stream ), 'A resumed write must produce exactly the bytes a one-shot write produces.' );
	}

	/**
	 * Appending before begin() or adopt() is refused.
	 *
	 * @return void
	 */
	public function test_append_before_start_is_refused(): void {
		$writer = new IncrementalArchiveWriter( new EntryWriter( CodecRegistry::with_defaults() ), new FooterWriter() );
		$plans  = self::sample_plans();

		$this->expectException( RuntimeException::class );

		$writer->append_entry( $plans[0] );
	}

	/**
	 * Adopting a stream whose length contradicts the claimed byte count is refused.
	 *
	 * @return void
	 */
	public function test_adopt_refuses_a_length_mismatch(): void {
		$writer = new IncrementalArchiveWriter( new EntryWriter( CodecRegistry::with_defaults() ), new FooterWriter() );
		$stream = self::memory_stream( 'only twenty bytes...' );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'refusing to append to an unverified file' );

		$writer->adopt( $stream, 9999, array() );
	}

	/**
	 * A second begin() on the same instance is refused — one instance per archive.
	 *
	 * @return void
	 */
	public function test_double_begin_is_refused(): void {
		$writer = new IncrementalArchiveWriter( new EntryWriter( CodecRegistry::with_defaults() ), new FooterWriter() );
		$writer->begin( self::memory_stream(), self::sample_provenance() );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'already started' );

		$writer->begin( self::memory_stream(), self::sample_provenance() );
	}
}
