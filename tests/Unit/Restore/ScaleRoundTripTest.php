<?php
/**
 * Scale round-trip tests proving the raised entry-count ceiling recovers
 * archives the old 50,000-entry limit refused purely on count.
 *
 * @package Pontifex\Tests\Unit\Restore
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Restore;

require_once __DIR__ . '/../Manifest/Fakes/FakeDbAdapter.php';

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Pontifex\Archive\Codec\CodecRegistry;
use Pontifex\Archive\Codec\RawCodec;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Format\ExporterInfo;
use Pontifex\Archive\Format\Provenance;
use Pontifex\Archive\Reader\ArchiveLimits;
use Pontifex\Archive\Reader\EntryReader;
use Pontifex\Archive\Writer\ArchiveWriter;
use Pontifex\Archive\Writer\EntryPlan;
use Pontifex\Archive\Writer\EntryWriter;
use Pontifex\Archive\Writer\FooterWriter;
use Pontifex\Restore\DatabaseWriter;
use Pontifex\Restore\FileWriter;
use Pontifex\Restore\RestoreRunner;
use Pontifex\Tests\Unit\Manifest\Fakes\FakeDbAdapter;

/**
 * Proves Part 2 of the entry-count-ceiling fix recovers previously-refused
 * archives: a 60,000- or 90,000-entry archive, built by the real
 * ArchiveWriter with short flat paths (so the manifest itself stays under
 * ArchiveManifest::MAX_PAYLOAD_SIZE), is refused under the old 50,000-entry
 * ceiling and reads and hash-verifies cleanly under the raised one.
 *
 * Archives are built directly via ArchiveWriter — not through ExportRunner —
 * because these tests simulate an archive that already exists on disk (the
 * "existing archives are sound, not bricks" scenario Part 2 is about), not
 * the export-time refusal Part 3 adds.
 */
final class ScaleRoundTripTest extends TestCase {

	/**
	 * Absolute path to the temp archive file used for the current test.
	 *
	 * @var string
	 */
	private string $archive_path;

	/**
	 * Create a fresh temp file path before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->archive_path = tempnam( sys_get_temp_dir(), 'pontifex-scale-' );
	}

	/**
	 * Remove the temp archive file after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ( is_file( $this->archive_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Test fixture teardown; best-effort.
			@unlink( $this->archive_path );
		}
		parent::tearDown();
	}

	/**
	 * Build a real archive of $count minimal file entries with short flat
	 * paths (24 characters, matching the archive-format.md measurement of
	 * roughly 92,235 such entries fitting under MAX_PAYLOAD_SIZE), written
	 * directly to $this->archive_path via ArchiveWriter.
	 *
	 * @param int $count How many file entries to write.
	 * @return void
	 */
	private function write_flat_path_archive( int $count ): void {
		$provenance = new Provenance(
			'6.6.1',
			'8.2.10',
			'https://example.test',
			'utf8mb4',
			'utf8mb4_unicode_520_ci',
			new ExporterInfo( 'pontifex', '0.1.0' ),
			new DateTimeImmutable( '2026-05-23T10:00:00+00:00' )
		);
		$writer     = new ArchiveWriter( new EntryWriter( CodecRegistry::with_defaults() ), new FooterWriter() );

		$plans = ( function () use ( $count ) {
			for ( $i = 0; $i < $count; $i++ ) {
				$path = sprintf( 'wp-content/f%06d.dat', $i );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- In-memory source stream for a tiny fixture entry.
				$src = fopen( 'php://temp', 'r+b' );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Writing the tiny fixture entry's content.
				fwrite( $src, 'x' );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Rewinding the fixture entry's source stream.
				rewind( $src );
				yield new EntryPlan(
					EntryHeader::for_file( $path, 1, 0644, 1690000000, 'application/octet-stream', 0 ),
					RawCodec::ID,
					str_repeat( "\0", EntryWriter::NONCE_SIZE ),
					$src
				);
			}
		} )();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Writing the fixture archive to a real temp file, not memory, so building it does not hold the whole archive in RAM.
		$dest = fopen( $this->archive_path, 'w+b' );
		$writer->write_archive( $provenance, $plans, $dest );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the fixture archive after writing it.
		fclose( $dest );
	}

	/**
	 * Build a RestoreRunner wired for a read-only verify() pass under the
	 * given limits.
	 *
	 * @param ArchiveLimits $limits The limits to enforce.
	 * @return RestoreRunner Ready to call verify() on.
	 */
	private function make_verifying_runner( ArchiveLimits $limits ): RestoreRunner {
		return new RestoreRunner(
			new EntryReader( CodecRegistry::with_defaults() ),
			new FileWriter( sys_get_temp_dir() ),
			new DatabaseWriter( new FakeDbAdapter() ),
			$limits
		);
	}

	/**
	 * A 60,000-entry archive, refused outright by the old 50,000-entry
	 * ceiling, must read and hash-verify every entry under the raised one.
	 *
	 * Runs in a separate, fresh process (see the docblock on the 90,000-entry
	 * test below for the memory-budget half of this attribute's reasoning;
	 * this test's own reason is different and load-bearing): once any
	 * brain/monkey-based test has run earlier in the suite's shared process,
	 * Patchwork's global `file://` stream-wrapper registration caps every
	 * later fread() at its 8192-byte default chunk size, however many bytes
	 * were requested — invisible for every other test's small archives, but
	 * this manifest is megabytes, so a single fread() call genuinely needs
	 * more than that. A fresh process has never loaded Patchwork.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	public function test_sixty_thousand_entries_refused_under_old_ceiling_recovered_under_new(): void {
		$this->write_flat_path_archive( 60000 );

		$old_ceiling_limits = new ArchiveLimits( 50000, 2147483648, 100, 1099511627776 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Reading the fixture archive back from its real temp file.
		$stream = fopen( $this->archive_path, 'rb' );
		try {
			$this->make_verifying_runner( $old_ceiling_limits )->verify( $stream );
			$this->fail( 'A 60,000-entry archive must be refused under the old 50,000-entry ceiling.' );
		} catch ( RuntimeException $e ) {
			// The pre-decode guard (checked from the declared manifest length
			// alone) now fires before the post-parse count check ever gets a
			// chance to run — both refuse the archive, but the cheaper,
			// earlier one wins the race, which is the whole point of Part 1
			// of this fix.
			$this->assertStringContainsString( 'more than the 50000 entries this installation will read', $e->getMessage() );
		}

		$raised_ceiling_limits = ArchiveLimits::defaults();
		$this->assertSame( 100000, $raised_ceiling_limits->max_entry_count() );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Reading the fixture archive back from its real temp file, fresh for the second pass.
		$stream         = fopen( $this->archive_path, 'rb' );
		$verified_count = 0;
		$this->make_verifying_runner( $raised_ceiling_limits )->verify(
			$stream,
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- The closure must match RestoreRunner::verify()'s `( int $done, int $total ): void` progress-callback contract; only the running count is needed here.
			static function ( int $done, int $total ) use ( &$verified_count ): void {
				$verified_count = $done;
			}
		);
		$this->assertSame( 60000, $verified_count, 'Every one of the 60,000 entries must hash-verify.' );
	}

	/**
	 * A 90,000-entry archive — above even the raised entry-count ceiling's
	 * neighbourhood of the format's own structural maximum (99,273 entries,
	 * MAX_PAYLOAD_SIZE / MIN_ENTRY_PAYLOAD_BYTES) — must still read and
	 * hash-verify every entry.
	 *
	 * Runs in a separate process with an explicit, generous memory_limit:
	 * decoding 90,000 manifest entries costs roughly 1.6 KB of PHP memory
	 * each (see ArchiveLimits::DEFAULT_MAX_ENTRY_COUNT's docblock), around
	 * 144 MB, comfortably inside the suite's ambient 512 MiB — but this is
	 * exactly the scale where that memory requirement starts to matter on a
	 * modest host, so the limit is set explicitly here rather than silently
	 * relying on phpunit.xml.dist's default and letting a future, larger
	 * scale test fatal without explanation.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	public function test_ninety_thousand_entries_read_and_hash_verify_under_raised_ceiling(): void {
		// phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- Test-only, in the isolated child process #[RunInSeparateProcess] runs; there is no WordPress runtime here for wp_raise_memory_limit() to hook into.
		ini_set( 'memory_limit', '768M' );

		$this->write_flat_path_archive( 90000 );

		$limits = ArchiveLimits::defaults();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Reading the fixture archive back from its real temp file.
		$stream         = fopen( $this->archive_path, 'rb' );
		$verified_count = 0;
		$this->make_verifying_runner( $limits )->verify(
			$stream,
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- The closure must match RestoreRunner::verify()'s `( int $done, int $total ): void` progress-callback contract; only the running count is needed here.
			static function ( int $done, int $total ) use ( &$verified_count ): void {
				$verified_count = $done;
			}
		);

		$this->assertSame( 90000, $verified_count, 'Every one of the 90,000 entries must hash-verify.' );
	}
}
