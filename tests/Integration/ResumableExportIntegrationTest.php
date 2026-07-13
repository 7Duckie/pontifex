<?php
/**
 * Resumable-export integration test: the resume machinery over real infrastructure.
 *
 * @package Pontifex\Tests\Integration
 */

declare(strict_types=1);

namespace Pontifex\Tests\Integration;

use RuntimeException;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use Pontifex\Archive\Codec\CodecRegistry;
use Pontifex\Archive\Codec\RawCodec;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Reader\ArchiveReader;
use Pontifex\Archive\Reader\EntryReader;
use Pontifex\Archive\Writer\EntryPlan;
use Pontifex\Archive\Writer\EntryWriter;
use Pontifex\Environment\RealEnvironment;
use Pontifex\Export\ExportOptions;
use Pontifex\Export\ResumableExportRunner;
use Pontifex\Job\JobStore;
use Pontifex\Manifest\ExclusionRules;
use Pontifex\Manifest\ManifestBuilderInterface;
use Pontifex\Manifest\ManifestStream;
use Pontifex\Manifest\WpdbAdapter;
use Pontifex\WordPress\RealWordPressContext;

/**
 * Proves the resumable export's resume machinery over real infrastructure.
 *
 * The runner's step machine, its adopt/verify-partial tail-healing, its
 * job persistence and progress log are unit-tested against php://memory
 * streams and a fake database. This is the integration counterpart the
 * v0.6.0 engine lacked: the same machine driven across many budgeted ticks
 * — each tick with a FRESH runner and a FRESH job store, the way separate
 * requests (a CLI loop iteration, an admin poll, a cron event) actually
 * continue the job — over a real filesystem archive and a real db_chunk
 * dumped by WpdbAdapter from a real MySQL table. The finished archive is
 * then read back through the real reader with every entry hash verified.
 *
 * It is non-destructive: files are hand-assembled fixtures, the archive is
 * a temp file, and the scratch table is dropped in tear_down. The plans are
 * hand-assembled (the scanners have their own unit tests) so the run is
 * deterministic; every tick re-scans and gets the identical sequence, which
 * is exactly what the resume contract requires.
 */
final class ResumableExportIntegrationTest extends TestCase {

	/**
	 * Absolute path to the temp working directory (content root + jobs + archive).
	 *
	 * @var string
	 */
	private string $work_dir = '';

	/**
	 * Fully-prefixed name of the scratch table the export dumps.
	 *
	 * @var string
	 */
	private string $scratch_table = '';

	/**
	 * Seed a scratch table and reserve a temp working directory.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		global $wpdb;

		$suffix              = bin2hex( random_bytes( 8 ) );
		$this->work_dir      = sys_get_temp_dir() . '/pontifex-resumable-int-' . $suffix;
		$this->scratch_table = $wpdb->prefix . 'pontifex_resumable_int';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating the integration test's temp working directory.
		mkdir( $this->work_dir, 0o755, true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test: drop any leftover scratch table.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $this->scratch_table ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test: create the isolated scratch table.
		$wpdb->query( $wpdb->prepare( 'CREATE TABLE %i ( id INT PRIMARY KEY, label VARCHAR(255) ) DEFAULT CHARSET=utf8mb4', $this->scratch_table ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test: seed the scratch table.
		$wpdb->query( $wpdb->prepare( 'INSERT INTO %i ( id, label ) VALUES ( %d, %s )', $this->scratch_table, 1, 'café ☕' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test: seed the scratch table.
		$wpdb->query( $wpdb->prepare( 'INSERT INTO %i ( id, label ) VALUES ( %d, %s )', $this->scratch_table, 2, '日本語' ) );
	}

	/**
	 * Drop the scratch table and remove the temp working directory.
	 *
	 * @return void
	 */
	protected function tear_down(): void {
		global $wpdb;

		if ( '' !== $this->scratch_table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test cleanup: drop the scratch table.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $this->scratch_table ) );
		}

		if ( '' !== $this->work_dir && is_dir( $this->work_dir ) ) {
			self::rmtree( $this->work_dir );
		}

		parent::tear_down();
	}

	/**
	 * A resumable export driven across many ticks by fresh runners produces a sound archive.
	 *
	 * A zero per-tick budget forces one file entry per tick and the database
	 * phase into its own tick (its snapshot must never span a request). Each
	 * tick uses a brand-new runner and job store, so the run only completes
	 * if the persisted job and progress log carry every scrap of state across
	 * "requests" — the resume contract, over real files and a real db_chunk.
	 * The finished archive is then read back with every entry hash verified.
	 *
	 * @return void
	 */
	public function test_resumable_export_completes_across_fresh_ticks_and_verifies_sound(): void {
		global $wpdb;

		$files = array(
			'wp-content/a.txt'            => "alpha content\n",
			'wp-content/uploads/note.txt' => "utf8 content: café ☕ 日本語\n",
			'wp-content/b.txt'            => str_repeat( "beta line\n", 64 ),
		);
		foreach ( $files as $relative => $contents ) {
			$this->write_fixture_file( $relative, $contents );
		}

		// The db_chunk's SQL is the real WpdbAdapter dump of the scratch table.
		$adapter = new WpdbAdapter( $wpdb );
		$db_sql  = $adapter->dump_table_schema( $this->scratch_table ) . $adapter->dump_table_rows( $this->scratch_table, 0, 100 );

		$output    = $this->work_dir . '/out.wpmig';
		$jobs_root = $this->work_dir;
		$factory   = $this->builder_factory( $files, $db_sql );

		// Start the job with one runner; it is created fresh every tick below.
		$store  = new JobStore( $jobs_root );
		$runner = new ResumableExportRunner( new RealEnvironment(), new RealWordPressContext(), $store, $factory );
		$job    = $runner->start( new ExportOptions( $output ), $this->work_dir, 'wp-content', array(), time() );
		$job_id = $job->id();

		$ticks = 0;
		$done  = false;
		while ( ! $done ) {
			if ( ++$ticks > 40 ) {
				$this->fail( 'The resumable export did not finish within 40 ticks.' );
			}
			// A fresh store and runner every tick: nothing survives in memory, only
			// on disk — exactly what a new request continuing the job would see.
			$tick_store  = new JobStore( $jobs_root );
			$tick_runner = new ResumableExportRunner( new RealEnvironment(), new RealWordPressContext(), $tick_store, $factory );
			$current     = $tick_store->get( $job_id );
			$this->assertNotNull( $current, 'The persisted job must be loadable on every tick.' );
			$done = $tick_runner->tick( $current, 0.0 );
		}

		$this->assertGreaterThan( count( $files ), $ticks, 'A zero budget must force many ticks (one file per tick plus the database tick).' );

		// The archive was renamed into place, and the temp .part is gone.
		$this->assertFileExists( $output, 'The finished archive must be renamed into place.' );
		$this->assertSame( array(), glob( $this->work_dir . '/*.part' ), 'The temp archive must be renamed away, not left behind.' );

		// The runner marks the finished job terminal (done) and leaves the record
		// for its caller to delete — the contract BackupController and JobTicker
		// rely on. It must no longer be active.
		$finished_job = ( new JobStore( $jobs_root ) )->get( $job_id );
		$this->assertNotNull( $finished_job, 'The finished job record persists until the caller deletes it.' );
		$this->assertFalse( $finished_job->is_active(), 'The finished job must be marked terminal, not left active.' );

		$this->assert_archive_sound( $output, $files, $db_sql );
	}

	/**
	 * Read the archive back and verify every entry, file, and the db_chunk.
	 *
	 * Constructing the reader verifies the manifest hash; reading each entry
	 * through EntryReader verifies that entry's own hash — so a successful
	 * read of every entry IS the soundness proof. The file entries must carry
	 * back their exact bytes, and the db_chunk its exact SQL.
	 *
	 * @param string                $output The archive path.
	 * @param array<string, string> $files  The expected file paths and contents.
	 * @param string                $db_sql The expected db_chunk SQL.
	 * @return void
	 */
	private function assert_archive_sound( string $output, array $files, string $db_sql ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Opening the just-written archive to verify it.
		$source = fopen( $output, 'rb' );
		$this->assertIsResource( $source, 'The finished archive must be openable for verification.' );

		try {
			$reader   = new ArchiveReader( $source );
			$manifest = $reader->manifest();
			$this->assertSame( count( $files ) + 1, $manifest->entry_count(), 'Every file plus the one db_chunk must be in the archive.' );

			$entry_reader = new EntryReader( CodecRegistry::with_defaults() );
			$seen_files   = array();
			$seen_db      = false;

			foreach ( $manifest->entries() as $entry ) {
				// read_entry verifies the entry's hash; a corrupt entry throws here.
				$result = $entry_reader->read_entry( $source, $entry );
				if ( $result->header()->is_db_chunk() ) {
					$this->assertSame( $db_sql, $result->payload(), 'The db_chunk SQL must round-trip byte-for-byte.' );
					$seen_db = true;
					continue;
				}
				$path                = $result->header()->path();
				$seen_files[ $path ] = stream_get_contents( $result->payload_stream() );
			}

			$this->assertTrue( $seen_db, 'The db_chunk entry must be present and verified.' );
			foreach ( $files as $relative => $contents ) {
				$this->assertArrayHasKey( $relative, $seen_files, sprintf( 'File entry missing from the archive: %s', $relative ) );
				$this->assertSame( $contents, $seen_files[ $relative ], sprintf( 'File content differs after the resumable export: %s', $relative ) );
			}
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the test's own archive handle.
			fclose( $source );
		}
	}

	/**
	 * Build the manifest-builder factory the runner calls each tick.
	 *
	 * The returned builder yields the same plan sequence on every build — real
	 * files (opened fresh each time, since the writer consumes the stream) and
	 * a db_chunk whose SQL is the captured real dump — so the re-scan every
	 * tick performs is deterministic, as the resume contract requires.
	 *
	 * @param array<string, string> $files  The fixture files, relative path => contents.
	 * @param string                $db_sql The db_chunk SQL.
	 * @return callable The factory: `( ExclusionRules, string ): ManifestBuilderInterface`.
	 */
	private function builder_factory( array $files, string $db_sql ): callable {
		$work_dir      = $this->work_dir;
		$scratch_table = $this->scratch_table;

		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- The factory must match the runner's ( ExclusionRules, string ) contract; the deterministic fixture builder needs neither argument.
		return static function ( ExclusionRules $rules, string $path_prefix ) use ( $files, $db_sql, $work_dir, $scratch_table ): ManifestBuilderInterface {
			return new class( $files, $db_sql, $work_dir, $scratch_table ) implements ManifestBuilderInterface {

				/**
				 * The fixture files, relative path => contents.
				 *
				 * @var array<string, string>
				 */
				private array $files;

				/**
				 * The db_chunk SQL.
				 *
				 * @var string
				 */
				private string $db_sql;

				/**
				 * The temp working directory the fixture files live under.
				 *
				 * @var string
				 */
				private string $work_dir;

				/**
				 * The scratch table name recorded on the db_chunk header.
				 *
				 * @var string
				 */
				private string $scratch_table;

				/**
				 * Construct the fixture builder.
				 *
				 * @param array<string, string> $files         Fixture files.
				 * @param string                $db_sql        The db_chunk SQL.
				 * @param string                $work_dir      Temp working directory.
				 * @param string                $scratch_table Scratch table name.
				 */
				public function __construct( array $files, string $db_sql, string $work_dir, string $scratch_table ) {
					$this->files         = $files;
					$this->db_sql        = $db_sql;
					$this->work_dir      = $work_dir;
					$this->scratch_table = $scratch_table;
				}

				/**
				 * Yield the deterministic plan sequence: files, then the db_chunk.
				 *
				 * @param string        $wordpress_root   Ignored; fixtures are self-contained.
				 * @param callable|null $on_scan_progress Optional progress callback (unused).
				 * @return ManifestStream The plan stream.
				 */
				public function build( string $wordpress_root, ?callable $on_scan_progress = null ): ManifestStream {
					$plans = array();
					foreach ( $this->files as $relative => $contents ) {
						$absolute = $this->work_dir . '/' . $relative;
						$header   = EntryHeader::for_file( $relative, strlen( $contents ), 0o644, 1690000000, 'application/octet-stream', 0 );
						$plans[]  = new EntryPlan(
							$header,
							RawCodec::ID,
							str_repeat( "\0", EntryWriter::NONCE_SIZE ),
							static function () use ( $absolute ) {
								// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Opening a real fixture file as the entry source; a fresh handle per build, as the writer consumes it.
								return fopen( $absolute, 'rb' );
							}
						);
					}

					$db_sql     = $this->db_sql;
					$statements = substr_count( $db_sql, ";\n" );
					$db_header  = EntryHeader::for_db_chunk( 0, $this->scratch_table, $statements, strlen( $db_sql ), 0 );
					$plans[]    = new EntryPlan(
						$db_header,
						RawCodec::ID,
						str_repeat( "\0", EntryWriter::NONCE_SIZE ),
						static function () use ( $db_sql ) {
							// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://memory is an in-process buffer for the db_chunk SQL, not a file.
							$stream = fopen( 'php://memory', 'r+b' );
							// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Operating on a test stream resource.
							fwrite( $stream, $db_sql );
							// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource.
							rewind( $stream );
							return $stream;
						}
					);

					return ManifestStream::from_plans( $plans );
				}
			};
		};
	}

	/**
	 * Write a fixture file under the temp working directory, creating parents.
	 *
	 * @param string $relative Relative path inside the working directory.
	 * @param string $contents File contents.
	 * @return void
	 */
	private function write_fixture_file( string $relative, string $contents ): void {
		$absolute = $this->work_dir . '/' . $relative;
		$dir      = dirname( $absolute );
		if ( ! is_dir( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating a fixture file's parent directory in the temp tree.
			mkdir( $dir, 0o755, true );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing a fixture file into the temp tree.
		file_put_contents( $absolute, $contents );
	}

	/**
	 * Recursively delete a directory tree.
	 *
	 * @param string $path Absolute path to remove.
	 * @return void
	 */
	private static function rmtree( string $path ): void {
		if ( ! is_dir( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Test fixture teardown; best-effort.
			@unlink( $path );
			return;
		}
		foreach ( scandir( $path ) as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				self::rmtree( $path . '/' . $entry );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.PHP.NoSilencedErrors.Discouraged -- Test fixture teardown; best-effort.
		@rmdir( $path );
	}
}
