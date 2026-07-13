<?php
/**
 * Integration test: large file entries stream through a memory-budgeted restore.
 *
 * @package Pontifex\Tests\Integration
 */

declare(strict_types=1);

namespace Pontifex\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use Pontifex\Archive\Codec\CodecRegistry;
use Pontifex\Archive\Codec\GzipCodec;
use Pontifex\Archive\Codec\RawCodec;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Format\ExporterInfo;
use Pontifex\Archive\Format\Provenance;
use Pontifex\Archive\Reader\EntryReader;
use Pontifex\Archive\Writer\ArchiveWriter;
use Pontifex\Archive\Writer\EntryPlan;
use Pontifex\Archive\Writer\EntryWriter;
use Pontifex\Archive\Writer\FooterWriter;
use Pontifex\Manifest\WpdbAdapter;
use Pontifex\Restore\DatabaseWriter;
use Pontifex\Restore\FileWriter;
use Pontifex\Restore\RestoreRunner;

/**
 * Proves ADR 0010's streaming contract with real payloads.
 *
 * The old reader buffered a whole entry, so the memory-derived budget refused
 * any file entry larger than a quarter of `memory_limit` — a site with one
 * large upload could take a backup the browser could not restore. These tests
 * run the real engine under a deliberately tiny memory budget:
 *
 *  - a compressed file entry EIGHT TIMES the whole memory limit restores
 *    byte-identically (it spools and streams, never occupying payload-sized
 *    memory), and the decoded-size accounting still charges the archive-total
 *    decompression-bomb budget correctly;
 *  - a tampered large entry is refused by the incremental hash check with
 *    nothing written to the destination — verify-before-use survives
 *    streaming.
 */
final class StreamedRestoreTest extends TestCase {

	/**
	 * The decoded size of the large fixture file: 8 MiB.
	 *
	 * @var int
	 */
	private const BIG_FILE_BYTES = 8388608;

	/**
	 * Memory limit handed to the runner: 4 MiB → a 1 MiB per-entry buffered budget.
	 *
	 * @var int
	 */
	private const MEMORY_LIMIT = 4194304;

	/**
	 * Temp directory the restore writes into.
	 *
	 * @var string
	 */
	private string $fixture_root = '';

	/**
	 * Reserve a fixture root.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();
		$this->fixture_root = sys_get_temp_dir() . '/pontifex-streamed-restore-' . bin2hex( random_bytes( 8 ) );
	}

	/**
	 * Remove the fixture root.
	 *
	 * @return void
	 */
	protected function tear_down(): void {
		if ( is_dir( $this->fixture_root ) ) {
			foreach ( array( 'big.bin', 'tampered.bin' ) as $name ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Test fixture teardown.
				@unlink( $this->fixture_root . '/' . $name );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.PHP.NoSilencedErrors.Discouraged -- Test fixture teardown.
			@rmdir( $this->fixture_root );
		}
		parent::tear_down();
	}

	/**
	 * A file entry far over the memory budget restores byte-identically.
	 *
	 * @return void
	 */
	public function test_file_entry_over_the_memory_budget_streams_to_disk(): void {
		$contents = self::big_contents();
		$archive  = $this->archive_with_file( 'big.bin', $contents, GzipCodec::ID );

		$this->runner()->restore( $archive );

		$path = $this->fixture_root . '/big.bin';
		$this->assertTrue( file_exists( $path ), 'The over-budget file must restore — it streams, never occupying payload-sized memory.' );
		$this->assertSame( self::BIG_FILE_BYTES, filesize( $path ) );
		$this->assertSame( hash( 'sha256', $contents ), hash_file( 'sha256', $path ), 'The streamed restore must be byte-identical.' );
	}

	/**
	 * A tampered large entry is refused with nothing written to the destination.
	 *
	 * @return void
	 */
	public function test_tampered_large_entry_is_refused_before_any_write(): void {
		$contents = self::big_contents();
		$archive  = $this->archive_with_file( 'tampered.bin', $contents, RawCodec::ID );

		// Flip one byte inside the stored payload (raw codec: the archive
		// carries the content literally, so the unique marker locates it).
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_stream_get_contents -- Operating on an in-memory test stream, not a filesystem path.
		$bytes  = (string) stream_get_contents( $archive );
		$marker = strpos( $bytes, 'PONTIFEX-TAMPER-MARKER-' );
		$this->assertNotFalse( $marker, 'The fixture marker must be present in the raw payload.' );
		$bytes[ $marker + 3 ] = 'X';
		$tampered             = self::memory_stream( $bytes );

		try {
			$this->runner()->restore( $tampered );
			$this->fail( 'A tampered entry must be refused by the hash check.' );
		} catch ( RuntimeException $refusal ) {
			$this->assertStringContainsString( 'hash', $refusal->getMessage() );
		}

		$this->assertFileDoesNotExist( $this->fixture_root . '/tampered.bin', 'Nothing may be written before the stored bytes verify.' );
	}

	/**
	 * Build the runner under the tiny memory limit, against the real database adapter.
	 *
	 * @return RestoreRunner The runner under test.
	 */
	private function runner(): RestoreRunner {
		global $wpdb;
		return new RestoreRunner(
			new EntryReader( CodecRegistry::with_defaults() ),
			new FileWriter( $this->fixture_root ),
			new DatabaseWriter( new WpdbAdapter( $wpdb ) ),
			null,
			self::MEMORY_LIMIT
		);
	}

	/**
	 * Eight mebibytes of incompressible content with a locatable marker at its head.
	 *
	 * Incompressible on purpose: the decompression-bomb budget scales with the
	 * stored archive size, so a hyper-compressible fixture would trip that guard
	 * instead — these tests target the MEMORY budget, which streaming makes
	 * irrelevant for file entries.
	 *
	 * @return string The fixture bytes.
	 */
	private static function big_contents(): string {
		$contents = 'PONTIFEX-TAMPER-MARKER-';
		$length   = strlen( $contents );
		while ( $length < self::BIG_FILE_BYTES ) {
			$contents .= random_bytes( 65536 );
			$length   += 65536;
		}
		return substr( $contents, 0, self::BIG_FILE_BYTES );
	}

	/**
	 * Build an in-memory archive holding one file entry.
	 *
	 * @param string $path     The entry's relative path.
	 * @param string $contents The file contents.
	 * @param int    $codec_id The compression codec to store it with.
	 * @return resource A readable, seekable stream containing the archive bytes.
	 */
	private function archive_with_file( string $path, string $contents, int $codec_id ) {
		$header = EntryHeader::for_file( $path, strlen( $contents ), 0o644, 1690000000, 'application/octet-stream', 0 );
		$plan   = new EntryPlan( $header, $codec_id, str_repeat( "\0", EntryWriter::NONCE_SIZE ), self::memory_stream( $contents ) );

		$writer = new ArchiveWriter( new EntryWriter( CodecRegistry::with_defaults() ), new FooterWriter() );
		$dest   = self::memory_stream();
		$writer->write_archive( self::sample_provenance(), array( $plan ), $dest );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource, not a filesystem path.
		rewind( $dest );
		return $dest;
	}

	/**
	 * Open a php://memory stream.
	 *
	 * @param string $contents Optional initial contents.
	 * @return resource A readable, seekable in-memory stream.
	 * @throws RuntimeException If php://memory cannot be opened.
	 */
	private static function memory_stream( string $contents = '' ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://memory is an in-process buffer, not a file.
		$stream = fopen( 'php://memory', 'r+b' );
		if ( false === $stream ) {
			throw new RuntimeException( 'Could not open php://memory.' );
		}
		if ( '' !== $contents ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Operating on a test stream resource, not a filesystem path.
			fwrite( $stream, $contents );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource, not a filesystem path.
			rewind( $stream );
		}
		return $stream;
	}

	/**
	 * Build a sample Provenance for archive construction.
	 *
	 * @return Provenance A valid provenance instance.
	 */
	private static function sample_provenance(): Provenance {
		return new Provenance(
			'6.6.1',
			'8.2.10',
			'https://example.test',
			'utf8mb4',
			'utf8mb4_unicode_520_ci',
			new ExporterInfo( 'pontifex', '0.1.0' ),
			new DateTimeImmutable( '2026-07-11T10:00:00+00:00', new DateTimeZone( 'UTC' ) )
		);
	}
}
