<?php
/**
 * Tests for ExportRunner — the shared engine that writes a site archive.
 *
 * @package Pontifex\Tests\Unit\Export
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Export;

use Mockery;
use wpdb;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Format\Provenance;
use Pontifex\Archive\Format\Scope;
use Pontifex\Archive\Reader\ArchiveReader;
use Pontifex\Archive\Writer\EntryPlan;
use Pontifex\Archive\Writer\EntryWriter;
use RuntimeException;
use Throwable;
use Pontifex\Environment\Environment;
use Pontifex\Export\ExportOptions;
use Pontifex\Export\ExportRunner;
use Pontifex\Manifest\ExclusionRules;
use Pontifex\Manifest\ManifestBuilder;
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
	 * A failed export must not clobber a prior good archive at the output path.
	 *
	 * The archive is written to a sibling temp file and moved into place only on success,
	 * so a write that fails part-way (here, an entry whose source throws) leaves any prior
	 * archive at the output path untouched — and no temp file behind.
	 *
	 * @return void
	 */
	public function test_a_failed_export_does_not_clobber_a_prior_archive(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Seeding a prior good archive at the output path.
		file_put_contents( $this->temp_output_path, 'PRIOR-GOOD-ARCHIVE' );

		$plans  = array(
			$this->file_plan( 'index.php', "<?php\n" ),
			$this->failing_plan( 'wp-content/broken.bin' ),
		);
		$runner = new ExportRunner( $this->environment_mock(), $this->wordpress_context_mock() );

		$threw = false;
		try {
			$runner->export( new ExportOptions( $this->temp_output_path ), $plans, null );
		} catch ( Throwable $error ) {
			unset( $error );
			$threw = true;
		}

		$this->assertTrue( $threw, 'A failing entry source must abort the export.' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading the output path to confirm the prior archive survived.
		$this->assertSame( 'PRIOR-GOOD-ARCHIVE', file_get_contents( $this->temp_output_path ), 'A failed export must not clobber the prior archive.' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_glob -- Confirming no temp export file is left behind.
		$leftover = glob( $this->temp_output_path . '.*.tmp' );
		$this->assertSame( array(), false === $leftover ? array() : $leftover, 'The temp export file is cleaned up on failure.' );
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
	 * The export() method forwards the byte-progress callback so it sees every source byte.
	 *
	 * Mirrors the per-entry progress test but for the byte callback the Backup
	 * screen uses to drive a bar that advances within a large entry: the reported
	 * deltas must sum to the total source bytes across all entries.
	 *
	 * @return void
	 */
	public function test_export_reports_bytes_read(): void {
		$contents = array( "alpha\n", "beta beta\n", "gamma gamma gamma\n" );
		$plans    = array(
			$this->file_plan( 'a.txt', $contents[0] ),
			$this->file_plan( 'b.txt', $contents[1] ),
			$this->file_plan( 'c.txt', $contents[2] ),
		);
		$expected = strlen( $contents[0] ) + strlen( $contents[1] ) + strlen( $contents[2] );

		$runner = new ExportRunner( $this->environment_mock(), $this->wordpress_context_mock() );

		$reported = 0;
		$runner->export(
			new ExportOptions( $this->temp_output_path ),
			$plans,
			null,
			static function ( int $bytes ) use ( &$reported ): void {
				$reported += $bytes;
			}
		);

		$this->assertSame( $expected, $reported, 'The byte callback should see every source byte across all entries.' );
	}

	/**
	 * An export carrying a scope records the scope and the source table prefix.
	 *
	 * The scope-aware path (the CLI export and the admin Backup screen): when the
	 * options carry a Scope, export() must record both v1.1 provenance fields — the
	 * scope object and the table prefix read from the WordPress context.
	 *
	 * @return void
	 */
	public function test_export_with_scope_records_scope_and_table_prefix(): void {
		$context = $this->wordpress_context_mock();
		$context->shouldReceive( 'wpdb_prefix' )->andReturn( 'wp_' );

		$runner = new ExportRunner( $this->environment_mock(), $context );

		$scope = Scope::content_only( array( 'wp-content/cache/**' ) );
		$runner->export( new ExportOptions( $this->temp_output_path, null, null, null, $scope ), array(), null );

		$provenance = $this->provenance( $this->temp_output_path );
		$this->assertSame( 'wp_', $provenance->table_prefix(), 'The source table prefix should be recorded.' );
		$recorded = $provenance->scope();
		$this->assertNotNull( $recorded, 'A scope-aware export should record a scope.' );
		$this->assertTrue( $recorded->is_content_only() );
		$this->assertSame( 'wp-content', $recorded->content_root() );
		$this->assertSame( array( 'wp-content/cache/**' ), $recorded->excluded_paths() );
	}

	/**
	 * An export with no scope records neither the scope nor the table prefix.
	 *
	 * The legacy path (the safety archiver): the two v1.1 fields travel together, so
	 * a no-scope export leaves both null and the provenance byte-identical to a
	 * pre-v1.1 archive. The context mock deliberately does NOT stub wpdb_prefix(), so
	 * this would also fail loudly (an unexpected Mockery call) if export() read the
	 * prefix when no scope was supplied.
	 *
	 * @return void
	 */
	public function test_export_without_scope_records_neither_scope_nor_table_prefix(): void {
		$runner = new ExportRunner( $this->environment_mock(), $this->wordpress_context_mock() );

		$runner->export( new ExportOptions( $this->temp_output_path ), array(), null );

		$provenance = $this->provenance( $this->temp_output_path );
		$this->assertNull( $provenance->scope(), 'A no-scope export should record no scope.' );
		$this->assertNull( $provenance->table_prefix(), 'A no-scope export should record no table prefix.' );
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
	// default_manifest_builder(): the snapshot connection and its fallbacks.
	// -------------------------------------------------------------------------

	/**
	 * The default builder must dump on a dedicated connection inside a snapshot.
	 *
	 * ADR 0011: when the context supplies a dedicated connection, the builder
	 * opens REPEATABLE READ + a consistent snapshot on it and never touches the
	 * global connection — the property that keeps progress writes visible.
	 *
	 * @return void
	 */
	public function test_default_builder_opens_a_snapshot_on_a_dedicated_connection(): void {
		require_once __DIR__ . '/../Manifest/Fakes/WpdbStub.php';
		$dedicated = Mockery::mock( wpdb::class );
		$dedicated->shouldReceive( 'query' )->once()->with( 'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ' )->andReturn( 1 );
		$dedicated->shouldReceive( 'query' )->once()->with( 'START TRANSACTION WITH CONSISTENT SNAPSHOT' )->andReturn( 1 );
		// The adapter's destructor commits the snapshot when the builder is released.
		$dedicated->shouldReceive( 'query' )->with( 'COMMIT' )->andReturn( 1 );

		$context = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'dedicated_wpdb_connection' )->once()->andReturn( $dedicated );
		$context->shouldNotReceive( 'wpdb_instance' );

		$builder = ExportRunner::default_manifest_builder( $context, ExclusionRules::none() );

		$this->assertInstanceOf( ManifestBuilder::class, $builder );
	}

	/**
	 * With no dedicated connection available, the builder must fall back to the global one.
	 *
	 * A host capping connections per user refuses the second connection; the
	 * export must still happen — a possibly-fuzzy backup beats no backup.
	 *
	 * @return void
	 */
	public function test_default_builder_falls_back_when_no_dedicated_connection(): void {
		require_once __DIR__ . '/../Manifest/Fakes/WpdbStub.php';
		$context = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'dedicated_wpdb_connection' )->once()->andReturn( null );
		$context->shouldReceive( 'wpdb_instance' )->once()->andReturn( Mockery::mock( wpdb::class ) );

		$builder = ExportRunner::default_manifest_builder( $context, ExclusionRules::none() );

		$this->assertInstanceOf( ManifestBuilder::class, $builder );
	}

	/**
	 * A snapshot that cannot open must also fall back to the global connection.
	 *
	 * @return void
	 */
	public function test_default_builder_falls_back_when_the_snapshot_cannot_open(): void {
		require_once __DIR__ . '/../Manifest/Fakes/WpdbStub.php';
		$dedicated = Mockery::mock( wpdb::class );
		$dedicated->shouldReceive( 'query' )->once()->with( 'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ' )->andReturn( false );

		$context = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'dedicated_wpdb_connection' )->once()->andReturn( $dedicated );
		$context->shouldReceive( 'wpdb_instance' )->once()->andReturn( Mockery::mock( wpdb::class ) );

		$builder = ExportRunner::default_manifest_builder( $context, ExclusionRules::none() );

		$this->assertInstanceOf( ManifestBuilder::class, $builder );
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
	 * Build an EntryPlan whose deferred source throws when the writer opens it.
	 *
	 * Used to fail an export mid-write without a real I/O error, so the atomic-write
	 * guarantee can be exercised.
	 *
	 * @param string $path Relative archive path for the entry.
	 * @return EntryPlan A plan whose source raises when pulled.
	 */
	private function failing_plan( string $path ): EntryPlan {
		$header = EntryHeader::for_file( $path, 10, 0o644, 1690000000, 'application/octet-stream', 0 );
		return new EntryPlan(
			$header,
			0,
			str_repeat( "\0", EntryWriter::NONCE_SIZE ),
			static function () {
				throw new RuntimeException( 'simulated entry source failure' );
			}
		);
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

	/**
	 * Open a written archive and return its parsed provenance block.
	 *
	 * @param string $path Absolute path to the archive.
	 * @return Provenance
	 */
	private function provenance( string $path ): Provenance {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Opening the just-written archive to read its provenance back.
		$source = fopen( $path, 'rb' );
		if ( false === $source ) {
			$this->fail( 'Could not open the written archive.' );
		}
		try {
			$reader = new ArchiveReader( $source );
			return $reader->provenance();
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the archive stream opened in this helper.
			fclose( $source );
		}
	}
}
