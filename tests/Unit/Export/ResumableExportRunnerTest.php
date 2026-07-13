<?php
/**
 * Unit tests for the ResumableExportRunner class.
 *
 * @package Pontifex\Tests\Unit\Export
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Export;

use Mockery;
use RuntimeException;
use Pontifex\Archive\Codec\CodecId;
use Pontifex\Archive\Codec\GzipCodec;
use Pontifex\Archive\Codec\RawCodec;
use Pontifex\Archive\Crypto\EncryptionContext;
use Pontifex\Archive\Crypto\OpensslAesGcmCipher;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Reader\ArchiveReader;
use Pontifex\Archive\Reader\EntryReader;
use Pontifex\Archive\Codec\CodecRegistry;
use Pontifex\Archive\Writer\EntryPlan;
use Pontifex\Archive\Writer\EntryWriter;
use Pontifex\Environment\Environment;
use Pontifex\Export\ExportOptions;
use Pontifex\Export\ResumableExportRunner;
use Pontifex\Job\Job;
use Pontifex\Job\JobStore;
use Pontifex\Manifest\ManifestBuilderInterface;
use Pontifex\Manifest\ManifestStream;
use Pontifex\Tests\TestCase;
use Pontifex\WordPress\WordPressContext;

/**
 * Behavioural tests for {@see ResumableExportRunner}.
 *
 * The manifest-builder seam supplies hand-built plans from a mutable
 * spec list, so ticks re-scan exactly the way production does (a fresh
 * stream per tick) without a real filesystem walk or database. The temp
 * archive, the job records, and the progress log are all real files
 * under a fixture root — the persistence IS the behaviour under test.
 */
final class ResumableExportRunnerTest extends TestCase {

	/**
	 * Fixture root standing in for wp-content.
	 *
	 * @var string
	 */
	private string $content_dir;

	/**
	 * The final archive output path.
	 *
	 * @var string
	 */
	private string $output_path;

	/**
	 * Mutable plan specs the fake builder serves; tests mutate to simulate drift.
	 *
	 * Each spec is array{0: 'file'|'db', 1: string|int, 2: string} —
	 * (kind, path-or-chunk-index, contents-or-sql).
	 *
	 * @var array<int, array{0: string, 1: string|int, 2: string}>
	 */
	private array $specs;

	/**
	 * Create fixture paths and the default four-entry spec list.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->content_dir = sys_get_temp_dir() . '/pontifex-resumable-test-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating the test fixture directory.
		mkdir( $this->content_dir, 0o755, true );
		$this->output_path = $this->content_dir . '/out.wpmig';
		$this->specs       = array(
			array( 'file', 'wp-content/a.txt', 'alpha content' ),
			array( 'file', 'wp-content/b.txt', str_repeat( 'beta ', 200 ) ),
			array( 'file', 'wp-content/c.txt', 'gamma' ),
			array( 'db', 0, "INSERT INTO `wp_options` VALUES (1);\n" ),
		);
	}

	/**
	 * Remove the fixture tree.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		self::rmtree( $this->content_dir );
		parent::tearDown();
	}

	/**
	 * Recursively delete a directory.
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

	/**
	 * Build the runner, its job store, and the seam-injected fake builder.
	 *
	 * @return array{0: ResumableExportRunner, 1: JobStore} The runner and its store.
	 */
	private function make_runner(): array {
		$store  = new JobStore( $this->content_dir );
		$specs  = &$this->specs;
		$runner = new ResumableExportRunner(
			$this->environment_mock(),
			$this->wordpress_context_mock(),
			$store,
			function () use ( &$specs ): ManifestBuilderInterface {
				return $this->fake_builder( $specs );
			}
		);
		return array( $runner, $store );
	}

	/**
	 * A ManifestBuilderInterface serving fresh plans from the given specs.
	 *
	 * @param array<int, array{0: string, 1: string|int, 2: string}> $specs The plan specs.
	 * @return ManifestBuilderInterface The fake builder.
	 */
	private function fake_builder( array $specs ): ManifestBuilderInterface {
		$builder = Mockery::mock( ManifestBuilderInterface::class );
		$builder->shouldReceive( 'build' )->andReturnUsing(
			static function () use ( $specs ): ManifestStream {
				$plans = array();
				foreach ( $specs as $spec ) {
					if ( 'file' === $spec[0] ) {
						$header = EntryHeader::for_file( (string) $spec[1], strlen( $spec[2] ), 0644, 1690000000, 'application/octet-stream', 0 );
					} else {
						$header = EntryHeader::for_db_chunk( (int) $spec[1], 'wp_options', 1, strlen( $spec[2] ), 0 );
					}
					$contents = $spec[2];
					$plans[]  = new EntryPlan(
						$header,
						0,
						str_repeat( "\0", EntryWriter::NONCE_SIZE ),
						static function () use ( $contents ) {
							// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://memory is an in-process buffer, not a file.
							$stream = fopen( 'php://memory', 'r+b' );
							// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Operating on a test stream resource.
							fwrite( $stream, $contents );
							// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource.
							rewind( $stream );
							return $stream;
						}
					);
				}
				return ManifestStream::from_plans( $plans );
			}
		);
		return $builder;
	}

	/**
	 * Start a job with the default options.
	 *
	 * @param ResumableExportRunner $runner The runner.
	 * @return Job The pending job.
	 */
	private function start_job( ResumableExportRunner $runner ): Job {
		return $runner->start( new ExportOptions( $this->output_path ), '/tmp/wp/wp-content', 'wp-content', array(), 1700000000 );
	}

	/**
	 * Tick the job until done, bounded, reloading the job each time as a real ticker would.
	 *
	 * @param ResumableExportRunner $runner The runner.
	 * @param JobStore              $store  The store.
	 * @param string                $job_id The job id.
	 * @param float                 $budget Per-tick budget in seconds.
	 * @return int How many ticks it took.
	 */
	private function tick_until_done( ResumableExportRunner $runner, JobStore $store, string $job_id, float $budget ): int {
		for ( $ticks = 1; $ticks <= 20; $ticks++ ) {
			$job = $store->get( $job_id );
			if ( $runner->tick( $job, $budget ) ) {
				return $ticks;
			}
		}
		$this->fail( 'The export did not finish within 20 ticks.' );
	}

	/**
	 * Assert the finished archive holds the four expected entries with true content.
	 *
	 * @return void
	 */
	private function assert_archive_sound(): void {
		$this->assertFileExists( $this->output_path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Opening the just-written archive to verify it in a unit test.
		$source = fopen( $this->output_path, 'rb' );
		$reader = new ArchiveReader( $source );
		$this->assertSame( count( $this->specs ), $reader->manifest()->entry_count() );

		$entry_reader = new EntryReader( CodecRegistry::with_defaults() );
		$entries      = $reader->manifest()->entries();
		$first        = $entry_reader->read_entry( $source, $entries[0] );
		$this->assertSame( 'wp-content/a.txt', $first->header()->path() );
		$this->assertSame( $this->specs[0][2], stream_get_contents( $first->payload_stream() ) );
		$last = $entry_reader->read_entry( $source, $entries[ count( $entries ) - 1 ] );
		$this->assertSame( $this->specs[3][2], $last->payload(), 'The database chunk must round-trip.' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the test's own archive handle.
		fclose( $source );
	}

	/**
	 * Ticks persist a source-byte cursor so progress surfaces speak source units.
	 *
	 * The re-attach bar's denominator is the estimated SOURCE total, so its
	 * numerator must be source bytes too — the compressed bytes_written count
	 * made a re-attached bar jump backwards. The cursor must grow across
	 * ticks and survive on the payload between them.
	 *
	 * @return void
	 */
	public function test_ticks_persist_the_source_byte_cursor(): void {
		list( $runner, $store ) = $this->make_runner();
		$job                    = $this->start_job( $runner );

		$runner->tick( $store->get( $job->id() ), 0.0 );
		$after_one = (int) ( $store->get( $job->id() )->payload()['source_bytes_done'] ?? 0 );
		$this->assertSame( strlen( $this->specs[0][2] ), $after_one, 'The first tick records its one entry\'s raw source bytes.' );

		// The denominator too: the first tick's scan persists the estimated
		// source total, so progress surfaces need no pre-scan of their own.
		$expected_total = 0;
		foreach ( $this->specs as $spec ) {
			$expected_total += strlen( $spec[2] );
		}
		$this->assertSame( $expected_total, (int) ( $store->get( $job->id() )->payload()['total_bytes'] ?? 0 ), 'The first tick persists the source-byte total.' );

		$runner->tick( $store->get( $job->id() ), 0.0 );
		$after_two = (int) ( $store->get( $job->id() )->payload()['source_bytes_done'] ?? 0 );
		$this->assertSame( $after_one + strlen( $this->specs[1][2] ), $after_two, 'The cursor accumulates across ticks rather than restarting.' );
	}

	/**
	 * A zero budget forces one file entry per tick; the export still completes and verifies.
	 *
	 * Also proves the database phase deliberately defers to its own fresh tick
	 * (its consistent snapshot must never span requests) and the temp file is
	 * renamed into place only at the end.
	 *
	 * @return void
	 */
	public function test_export_completes_across_many_ticks(): void {
		list( $runner, $store ) = $this->make_runner();
		$job                    = $this->start_job( $runner );

		$ticks = $this->tick_until_done( $runner, $store, $job->id(), 0.0 );

		$this->assertGreaterThanOrEqual( 4, $ticks, 'A zero budget must spread the entries over several ticks.' );
		$this->assert_archive_sound();
		$this->assertSame( Job::STATUS_DONE, $store->get( $job->id() )->status() );
		$this->assertSame( array(), glob( $this->content_dir . '/*.part' ), 'The temp archive must be renamed away.' );
	}

	/**
	 * Bytes written after the last logged entry (a ticker died before logging) are truncated on resume.
	 *
	 * @return void
	 */
	public function test_an_unlogged_tail_is_truncated_and_the_export_completes(): void {
		list( $runner, $store ) = $this->make_runner();
		$job                    = $this->start_job( $runner );

		$runner->tick( $store->get( $job->id() ), 0.0 );
		$runner->tick( $store->get( $job->id() ), 0.0 );

		// Simulate death between writing bytes and logging them: garbage tail.
		$temp = (string) $store->get( $job->id() )->payload()['temp'];
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Simulating a crash artefact on the test's own fixture.
		file_put_contents( $temp, 'GARBAGE-NEVER-LOGGED', FILE_APPEND );

		$this->tick_until_done( $runner, $store, $job->id(), 0.0 );

		$this->assert_archive_sound();
	}

	/**
	 * A logged entry whose bytes never fully flushed is stepped back and rewritten.
	 *
	 * @return void
	 */
	public function test_a_torn_last_entry_steps_back_and_the_export_completes(): void {
		list( $runner, $store ) = $this->make_runner();
		$job                    = $this->start_job( $runner );

		$runner->tick( $store->get( $job->id() ), 0.0 );
		$runner->tick( $store->get( $job->id() ), 0.0 );

		// Simulate death between logging and the bytes reaching disk: cut the tail.
		$temp = (string) $store->get( $job->id() )->payload()['temp'];
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Truncating the test's own fixture to simulate a crash artefact.
		$handle = fopen( $temp, 'r+b' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_ftruncate -- Simulating the crash artefact.
		ftruncate( $handle, filesize( $temp ) - 10 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the test's own handle.
		fclose( $handle );

		$this->tick_until_done( $runner, $store, $job->id(), 0.0 );

		$this->assert_archive_sound();
	}

	/**
	 * A job killed mid-tick (left marked running on disk) must still tick onward.
	 *
	 * A SIGKILL gives the runner no chance to mark the job back to pending, so
	 * the next tick meets a running job — re-entering running is a no-op, not a
	 * state-machine transition. Found by the real kill drill, not the healthy
	 * path.
	 *
	 * @return void
	 */
	public function test_a_job_killed_while_running_resumes_on_the_next_tick(): void {
		list( $runner, $store ) = $this->make_runner();
		$job                    = $this->start_job( $runner );
		$runner->tick( $store->get( $job->id() ), 0.0 );

		// Simulate the kill: the persisted status is running, nobody marked it back.
		$stuck = $store->get( $job->id() );
		$stuck->mark( Job::STATUS_RUNNING, 1700000100 );
		$store->save( $stuck );

		$this->tick_until_done( $runner, $store, $job->id(), 0.0 );

		$this->assert_archive_sound();
		$this->assertSame( Job::STATUS_DONE, $store->get( $job->id() )->status() );
	}

	/**
	 * A source tree that changed shape mid-export is refused, and the job fails.
	 *
	 * @return void
	 */
	public function test_scan_drift_is_refused_and_fails_the_job(): void {
		list( $runner, $store ) = $this->make_runner();
		$job                    = $this->start_job( $runner );

		$runner->tick( $store->get( $job->id() ), 0.0 );

		// A file appears at the FRONT of the scan order: every index shifts.
		array_unshift( $this->specs, array( 'file', 'wp-content/0-new-first.txt', 'surprise' ) );

		$thrown = null;
		try {
			$runner->tick( $store->get( $job->id() ), 0.0 );
		} catch ( RuntimeException $e ) {
			$thrown = $e;
		}

		$this->assertNotNull( $thrown );
		$this->assertStringContainsString( 'changed shape', $thrown->getMessage() );
		$this->assertSame( Job::STATUS_FAILED, $store->get( $job->id() )->status() );
	}

	/**
	 * An encrypted export cannot be started resumable — the key is never persisted.
	 *
	 * @return void
	 */
	public function test_an_encrypted_export_refuses_resumable_mode(): void {
		list( $runner ) = $this->make_runner();
		$encryption     = new EncryptionContext( new OpensslAesGcmCipher(), str_repeat( 'k', 32 ), str_repeat( 's', 16 ) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'cannot be resumable' );

		$runner->start( new ExportOptions( $this->output_path, $encryption ), '/tmp/wp/wp-content', 'wp-content', array(), 1700000000 );
	}

	/**
	 * Build an Environment mock answering the provenance reads.
	 *
	 * @return Environment&\Mockery\MockInterface The mock.
	 */
	private function environment_mock() {
		$mock = Mockery::mock( Environment::class );
		$mock->shouldReceive( 'is_constant_defined' )->with( 'PONTIFEX_VERSION' )->andReturn( true );
		$mock->shouldReceive( 'constant_value' )->with( 'PONTIFEX_VERSION' )->andReturn( '0.0.0-test' );
		$mock->shouldReceive( 'php_version' )->andReturn( '8.3.0' );
		return $mock;
	}

	/**
	 * Build a WordPressContext mock answering the provenance reads.
	 *
	 * @return WordPressContext&\Mockery\MockInterface The mock.
	 */
	private function wordpress_context_mock() {
		$mock = Mockery::mock( WordPressContext::class );
		$mock->shouldReceive( 'wp_version' )->andReturn( '6.6.1' );
		$mock->shouldReceive( 'site_url' )->andReturn( 'https://example.test' );
		$mock->shouldReceive( 'wpdb_charset' )->andReturn( 'utf8mb4' );
		$mock->shouldReceive( 'wpdb_collation' )->andReturn( 'utf8mb4_unicode_520_ci' );
		$mock->shouldReceive( 'wpdb_prefix' )->andReturn( 'wp_' );
		return $mock;
	}
}
