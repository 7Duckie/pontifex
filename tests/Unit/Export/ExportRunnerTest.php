<?php
/**
 * Tests for ExportRunner — the shared engine that writes a site archive.
 *
 * @package Pontifex\Tests\Unit\Export
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Export;

use Mockery;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Reader\ArchiveReader;
use Pontifex\Archive\Writer\EntryPlan;
use Pontifex\Archive\Writer\EntryWriter;
use Pontifex\Environment\Environment;
use Pontifex\Export\ExportOptions;
use Pontifex\Export\ExportRunner;
use Pontifex\Tests\TestCase;
use Pontifex\WordPress\WordPressContext;

/**
 * Exercises export() with a real archive writer and hand-built entry plans.
 *
 * A sociable test in the shape of
 * {@see \Pontifex\Tests\Unit\Rollback\SafetyArchiverTest}: the entry plans are
 * built from in-memory bytes and a real ArchiveWriter writes a genuine archive
 * to a temp file, which is then read back to prove it is well-formed. Environment
 * and WordPressContext are mocked for the provenance facts only; no real
 * installation is scanned and $wpdb is never touched (the scanner wiring in
 * default_manifest_builder() is covered by the round-trip integration tests,
 * matching the existing decision recorded in InvokeBranchesTest).
 */
final class ExportRunnerTest extends TestCase {

	/**
	 * A real temporary file path used as the export destination.
	 *
	 * @var string
	 */
	private string $temp_output_path = '';

	/**
	 * Reserve a unique destination path for the archive.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->temp_output_path = sys_get_temp_dir() . '/pontifex-export-runner-' . uniqid( '', true ) . '.wpmig';
	}

	/**
	 * Remove any output file the test left behind.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ( '' !== $this->temp_output_path && file_exists( $this->temp_output_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test-only cleanup of a file the test created in sys_get_temp_dir().
			unlink( $this->temp_output_path );
		}
		parent::tearDown();
	}

	/**
	 * The export() method writes a well-formed archive and reports bytes and entry count.
	 *
	 * @return void
	 */
	public function test_export_writes_a_well_formed_archive(): void {
		$plans = array(
			$this->file_plan( 'index.php', "<?php\n// fixture\n" ),
			$this->file_plan( 'wp-content/note.txt', "café ☕\n" ),
		);

		$runner = new ExportRunner( $this->environment_mock(), $this->wordpress_context_mock() );

		$result = $runner->export( new ExportOptions( $this->temp_output_path ), $plans, null );

		$this->assertFileExists( $this->temp_output_path );
		$this->assertSame( 2, $result->entry_count(), 'Both entries should be reported.' );
		$this->assertGreaterThan( 0, $result->bytes_written(), 'A non-empty archive should report bytes written.' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filesize -- Measuring the test's own output file to cross-check the reported byte count.
		$this->assertSame( (int) filesize( $this->temp_output_path ), $result->bytes_written(), 'Reported bytes should match the file on disk.' );
		$this->assertSame( 2, $this->entry_count( $this->temp_output_path ), 'The written archive should read back with both entries.' );
	}

	/**
	 * The export() method invokes the progress callback once per entry with a running count.
	 *
	 * @return void
	 */
	public function test_export_reports_progress_per_entry(): void {
		$plans = array(
			$this->file_plan( 'a.txt', "a\n" ),
			$this->file_plan( 'b.txt', "b\n" ),
			$this->file_plan( 'c.txt', "c\n" ),
		);

		$runner = new ExportRunner( $this->environment_mock(), $this->wordpress_context_mock() );

		$seen = array();
		$runner->export(
			new ExportOptions( $this->temp_output_path ),
			$plans,
			static function ( int $done, int $total ) use ( &$seen ): void {
				$seen[] = array( $done, $total );
			}
		);

		$this->assertSame(
			array( array( 1, 3 ), array( 2, 3 ), array( 3, 3 ) ),
			$seen,
			'The callback should fire once per entry with the running done count and the fixed total.'
		);
	}

	/**
	 * An empty entry list still produces a valid, readable archive.
	 *
	 * @return void
	 */
	public function test_export_with_no_entries_writes_a_valid_empty_archive(): void {
		$runner = new ExportRunner( $this->environment_mock(), $this->wordpress_context_mock() );

		$result = $runner->export( new ExportOptions( $this->temp_output_path ), array(), null );

		$this->assertSame( 0, $result->entry_count() );
		$this->assertSame( 0, $this->entry_count( $this->temp_output_path ), 'An empty archive should read back with no entries.' );
	}

	// -------------------------------------------------------------------------
	// Collaborator builders.
	// -------------------------------------------------------------------------

	/**
	 * An Environment mock answering the provenance reads export() makes.
	 *
	 * @return Environment&\Mockery\MockInterface
	 */
	private function environment_mock() {
		$environment = Mockery::mock( Environment::class );
		$environment->shouldReceive( 'php_version' )->andReturn( '8.2.0' );
		$environment->shouldReceive( 'is_constant_defined' )->with( 'PONTIFEX_VERSION' )->andReturn( false );
		return $environment;
	}

	/**
	 * A WordPressContext mock supplying the provenance facts.
	 *
	 * @return WordPressContext&\Mockery\MockInterface
	 */
	private function wordpress_context_mock() {
		$context = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'wp_version' )->andReturn( '6.6.0' );
		$context->shouldReceive( 'site_url' )->andReturn( 'https://example.test' );
		$context->shouldReceive( 'wpdb_charset' )->andReturn( 'utf8mb4' );
		$context->shouldReceive( 'wpdb_collation' )->andReturn( 'utf8mb4_unicode_520_ci' );
		return $context;
	}

	// -------------------------------------------------------------------------
	// Archive helpers.
	// -------------------------------------------------------------------------

	/**
	 * Build a file EntryPlan with the given path and contents.
	 *
	 * @param string $path     Relative path inside the archive.
	 * @param string $contents File contents.
	 * @return EntryPlan
	 */
	private function file_plan( string $path, string $contents ): EntryPlan {
		$header = EntryHeader::for_file( $path, strlen( $contents ), 0o644, 1690000000, 'application/octet-stream', 0 );
		return new EntryPlan( $header, 0, str_repeat( "\0", EntryWriter::NONCE_SIZE ), $this->memory_stream( $contents ) );
	}

	/**
	 * Open a php://memory stream seeded with the given bytes.
	 *
	 * @param string $contents Initial contents.
	 * @return resource
	 */
	private function memory_stream( string $contents ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://memory is an in-process buffer, not a file.
		$stream = fopen( 'php://memory', 'r+b' );
		if ( false === $stream ) {
			$this->fail( 'Could not open php://memory.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Operating on a test stream resource, not a filesystem path.
		fwrite( $stream, $contents );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource, not a filesystem path.
		rewind( $stream );
		return $stream;
	}

	/**
	 * Open a written archive and return how many entries its manifest declares.
	 *
	 * @param string $path Absolute path to the archive.
	 * @return int
	 */
	private function entry_count( string $path ): int {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Opening the just-written archive to read its manifest back.
		$source = fopen( $path, 'rb' );
		if ( false === $source ) {
			$this->fail( 'Could not open the written archive.' );
		}
		try {
			$reader = new ArchiveReader( $source );
			return $reader->manifest()->entry_count();
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the archive stream opened in this helper.
			fclose( $source );
		}
	}
}
