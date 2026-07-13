<?php
/**
 * Surgical __invoke branch tests for ExportCommand's resumable path.
 *
 * @package Pontifex\Tests\Unit\Cli\ExportCommand
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Cli\ExportCommand;

use Mockery;
use RuntimeException;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Reader\ArchiveReader;
use Pontifex\Archive\Writer\EntryPlan;
use Pontifex\Archive\Writer\EntryWriter;
use Pontifex\Cli\ExportCommand;
use Pontifex\Cli\NullProgressBar;
use Pontifex\Environment\Environment;
use Pontifex\Manifest\ManifestBuilderInterface;
use Pontifex\Manifest\ManifestStream;
use Pontifex\Tests\TestCase;
use Pontifex\WordPress\WordPressContext;
use Psr\Log\NullLogger;

/**
 * Behavioural coverage of the CLI's resumable-export branches.
 *
 * The full step machine is covered by ResumableExportRunnerTest; these
 * tests pin the CLI wiring around it — the flag refusals that guard the
 * path, and one end-to-end resumable run through the injected manifest
 * builder proving the tick loop completes, cleans up its job, and leaves
 * a readable archive.
 */
final class ResumableInvokeTest extends TestCase {

	/**
	 * Fixture directory standing in for wp-content (jobs live beneath it).
	 *
	 * @var string
	 */
	private string $content_dir;

	/**
	 * The archive output path inside the fixture.
	 *
	 * @var string
	 */
	private string $output_path;

	/**
	 * Create the fixture tree.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->content_dir = sys_get_temp_dir() . '/pontifex-cli-resumable-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating the test fixture directory.
		mkdir( $this->content_dir, 0o755, true );
		$this->output_path = $this->content_dir . '/out.wpmig';
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
	 * Combining --resumable with encryption is refused before anything runs.
	 *
	 * @return void
	 */
	public function test_resumable_with_encryption_is_refused(): void {
		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'error' )
			->once()
			->with( Mockery::pattern( '/cannot be resumable/' ) )
			->andThrow( new RuntimeException( 'halt' ) );

		$command = new ExportCommand( $this->environment_mock(), $this->context_mock(), $this->builder_mock(), new NullLogger(), new NullProgressBar() );

		$this->expectExceptionMessage( 'halt' );

		$command(
			array(),
			array(
				'output'    => $this->output_path,
				'resumable' => true,
				'encrypt'   => true,
				'yes'       => true,
			)
		);
	}

	/**
	 * Passing --resumable and --resume together is refused.
	 *
	 * @return void
	 */
	public function test_resumable_and_resume_together_are_refused(): void {
		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'error' )
			->once()
			->with( Mockery::pattern( '/not both/' ) )
			->andThrow( new RuntimeException( 'halt' ) );

		$command = new ExportCommand( $this->environment_mock(), $this->context_mock(), $this->builder_mock(), new NullLogger(), new NullProgressBar() );

		$this->expectExceptionMessage( 'halt' );

		$command(
			array(),
			array(
				'resumable' => true,
				'resume'    => true,
				'yes'       => true,
			)
		);
	}

	/**
	 * Resuming with no interrupted export is refused with a clear message.
	 *
	 * @return void
	 */
	public function test_resume_with_nothing_interrupted_is_refused(): void {
		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'error' )
			->once()
			->with( Mockery::pattern( '/No interrupted resumable export/' ) )
			->andThrow( new RuntimeException( 'halt' ) );

		$command = new ExportCommand( $this->environment_mock(), $this->context_mock(), $this->builder_mock(), new NullLogger(), new NullProgressBar() );

		$this->expectExceptionMessage( 'halt' );

		$command(
			array(),
			array(
				'resume' => true,
				'yes'    => true,
			)
		);
	}

	/**
	 * A --resumable export ticks to completion, cleans up its job, and leaves a readable archive.
	 *
	 * @return void
	 */
	public function test_a_resumable_export_runs_to_completion(): void {
		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'error' )->never();

		$command = new ExportCommand( $this->environment_mock(), $this->context_mock(), $this->builder_mock(), new NullLogger(), new NullProgressBar() );

		$command(
			array(),
			array(
				'output'    => $this->output_path,
				'resumable' => true,
				'yes'       => true,
			)
		);

		$this->assertFileExists( $this->output_path, 'The resumable export must produce the archive.' );
		$this->assertSame( array(), glob( $this->content_dir . '/pontifex/jobs/*.json' ), 'The finished job record must be cleaned up.' );
		$this->assertSame( array(), glob( $this->content_dir . '/*.part' ), 'The temp archive must be renamed away.' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Opening the just-written archive to verify it in a unit test.
		$source = fopen( $this->output_path, 'rb' );
		$reader = new ArchiveReader( $source );
		$this->assertSame( 2, $reader->manifest()->entry_count(), 'Both planned entries must be in the archive.' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the test's own archive handle.
		fclose( $source );
	}

	/**
	 * A ManifestBuilderInterface serving one file and one db chunk, fresh per build().
	 *
	 * @return ManifestBuilderInterface&\Mockery\MockInterface The builder mock.
	 */
	private function builder_mock() {
		$builder = Mockery::mock( ManifestBuilderInterface::class );
		$builder->shouldReceive( 'build' )->andReturnUsing(
			static function (): ManifestStream {
				$plans = array();
				foreach ( array(
					array( EntryHeader::for_file( 'wp-content/a.txt', 5, 0644, 1690000000, 'application/octet-stream', 0 ), 'alpha' ),
					array( EntryHeader::for_db_chunk( 0, 'wp_options', 1, 30, 0 ), "INSERT INTO `wp_options` (1);\n" ),
				) as $pair ) {
					$contents = $pair[1];
					$plans[]  = new EntryPlan(
						$pair[0],
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
	 * Build an Environment mock for the resumable happy path.
	 *
	 * @return Environment&\Mockery\MockInterface The mock.
	 */
	private function environment_mock() {
		$mock = Mockery::mock( Environment::class );
		$mock->shouldReceive( 'is_dir' )->andReturn( true );
		$mock->shouldReceive( 'is_writable' )->andReturn( true );
		$mock->shouldReceive( 'is_constant_defined' )->with( 'PONTIFEX_VERSION' )->andReturn( true );
		$mock->shouldReceive( 'constant_value' )->with( 'PONTIFEX_VERSION' )->andReturn( '0.0.0-test' );
		$mock->shouldReceive( 'is_constant_defined' )->with( 'ABSPATH' )->andReturn( true );
		$mock->shouldReceive( 'constant_value' )->with( 'ABSPATH' )->andReturn( $this->content_dir . '/' );
		$mock->shouldReceive( 'is_constant_defined' )->with( 'WP_CONTENT_DIR' )->andReturn( true );
		$mock->shouldReceive( 'constant_value' )->with( 'WP_CONTENT_DIR' )->andReturn( $this->content_dir );
		$mock->shouldReceive( 'php_version' )->andReturn( '8.3.0' );
		return $mock;
	}

	/**
	 * Build a WordPressContext mock for the resumable happy path.
	 *
	 * @return WordPressContext&\Mockery\MockInterface The mock.
	 */
	private function context_mock() {
		$mock = Mockery::mock( WordPressContext::class );
		$mock->shouldReceive( 'wp_version' )->andReturn( '6.6.1' );
		$mock->shouldReceive( 'site_url' )->andReturn( 'https://example.test' );
		$mock->shouldReceive( 'wpdb_charset' )->andReturn( 'utf8mb4' );
		$mock->shouldReceive( 'wpdb_collation' )->andReturn( 'utf8mb4_unicode_520_ci' );
		$mock->shouldReceive( 'wpdb_prefix' )->andReturn( 'wp_' );
		$mock->shouldReceive( 'format_size' )->andReturn( '1 KB' );
		$mock->shouldReceive( 'option_value' )->andReturn( array() );
		$mock->shouldReceive( 'save_option' )->zeroOrMoreTimes();
		return $mock;
	}
}
