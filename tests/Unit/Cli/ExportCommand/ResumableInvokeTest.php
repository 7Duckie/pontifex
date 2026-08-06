<?php
/**
 * Surgical __invoke branch tests for ExportCommand's resumable path.
 *
 * @package Pontifex\Tests\Unit\Cli\ExportCommand
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Cli\ExportCommand;

use Brain\Monkey\Functions;
use Mockery;
use RuntimeException;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Reader\ArchiveReader;
use Pontifex\Archive\Writer\EntryPlan;
use Pontifex\Archive\Writer\EntryWriter;
use Pontifex\Archive\Format\Scope;
use Pontifex\Cli\ExportCommand;
use Pontifex\Cli\NullProgressBar;
use Pontifex\Environment\Environment;
use Pontifex\Export\ExportOptions;
use Pontifex\Export\ResumableExportRunner;
use Pontifex\Job\JobStore;
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
	 * Unlike the two refusals above, this one is raised after the export has taken its
	 * lock and entered the try, so the RuntimeException standing in for WP_CLI::error's
	 * process exit is caught by the command's own failure handler and turned into a
	 * halt. The refusal under test is unchanged — error() is raised exactly once, with
	 * the message that names the missing job.
	 *
	 * @return void
	 */
	public function test_resume_with_nothing_interrupted_is_refused(): void {
		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'warning' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'error' )
			->once()
			->with( Mockery::pattern( '/No interrupted resumable export/' ) )
			->andThrow( new RuntimeException( 'halt' ) );
		$wp_cli->shouldReceive( 'halt' )->once()->with( 1 );

		$command = new ExportCommand( $this->environment_mock(), $this->context_mock(), $this->builder_mock(), new NullLogger(), new NullProgressBar() );

		$command(
			array(),
			array(
				'resume' => true,
				'yes'    => true,
			)
		);

		$this->assertTrue( true, 'Reaching this line means the stand-in exit was handled rather than escaping.' );
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
	 * A resumable export that fails says --resume will NOT pick it up, and why.
	 *
	 * This is the verdict most at risk of a plausible lie. "Continue it with --resume"
	 * is the natural thing to say about a resumable export and it is wrong: tick()
	 * marks its job FAILED on any exception, FAILED is terminal, and --resume takes
	 * only an active job — so it would refuse, after the operator had been told to try
	 * it. The test asserts the absence of that advice as firmly as the presence of the
	 * truth, and pins the orphaned .part file the run really leaves behind.
	 *
	 * @return void
	 */
	public function test_a_failed_resumable_export_says_resume_will_not_pick_it_up(): void {
		$printed = array();
		$collect = static function ( string $message ) use ( &$printed ): void {
			$printed[] = $message;
		};

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes()->andReturnUsing( $collect );
		$wp_cli->shouldReceive( 'warning' )->zeroOrMoreTimes()->andReturnUsing( $collect );
		$wp_cli->shouldReceive( 'halt' )->once()->with( 1 );

		$command = new ExportCommand(
			$this->environment_mock(),
			$this->context_mock(),
			$this->failing_builder_mock(),
			new NullLogger(),
			new NullProgressBar()
		);

		$command(
			array(),
			array(
				'output'    => $this->output_path,
				'resumable' => true,
				'yes'       => true,
			)
		);

		$output = implode( "\n", $printed );
		$this->assertStringContainsString( 'Export failed.', $output );
		$this->assertStringContainsString( 'simulated tick failure', $output );
		$this->assertStringContainsString( 'cannot be continued with --resume', $output );
		$this->assertStringContainsString( 'A part-written file is left at', $output );

		// The file is named from a glob, not predicted, so the name printed must be the
		// name on disk. A verdict that describes a plausible file rather than the real
		// one sends its reader to delete something that is not there.
		$orphans = glob( $this->content_dir . '/*.part' );
		$this->assertNotSame( array(), $orphans, 'The .part file the verdict describes must really be there.' );
		$this->assertStringContainsString(
			basename( (string) $orphans[0] ),
			$output,
			'The verdict names the orphan that is actually on disk, redacted but identifiable.'
		);
		// Not the bare mention of --resume: the announcement at the top of a resumable
		// run offers it for an *interrupted* export, which stays true. What must not
		// appear is the verdict offering it for this failure.
		$this->assertStringNotContainsString(
			'once the problem is fixed, continue it',
			$output,
			'--resume refuses a failed job, so advising it would send the operator to a second refusal.'
		);

		$this->assertNotSame(
			array(),
			glob( $this->content_dir . '/*.part' ),
			'The .part file the verdict describes must really be there.'
		);
	}

	/**
	 * A failed --resume names the archive it was continuing, which it was never told.
	 *
	 * `--resume` takes no --output: the path lives in the job record an interrupted
	 * run left behind, and __invoke holds only the empty string it started with until
	 * run_resumable() reads the record back into it. Without that read-back reaching
	 * the failure handler, a resumed export would report against no path at all.
	 *
	 * The interrupted job is created the way a real one is — through the runner's own
	 * start() — because a job that a failure closed cannot be resumed at all, so
	 * failing a run first would prove nothing about this path.
	 *
	 * @return void
	 */
	public function test_a_failed_resume_names_the_archive_from_the_job_record(): void {
		$store  = new JobStore( $this->content_dir );
		$runner = new ResumableExportRunner( $this->environment_mock(), $this->context_mock(), $store );
		$runner->start(
			new ExportOptions( $this->output_path, null, null, 'test', Scope::content_only( array() ) ),
			$this->content_dir,
			'wp-content',
			array(),
			1690000000
		);

		$printed = array();
		$collect = static function ( string $message ) use ( &$printed ): void {
			$printed[] = $message;
		};

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes()->andReturnUsing( $collect );
		$wp_cli->shouldReceive( 'warning' )->zeroOrMoreTimes()->andReturnUsing( $collect );
		$wp_cli->shouldReceive( 'halt' )->once()->with( 1 );

		$command = new ExportCommand(
			$this->environment_mock(),
			$this->context_mock(),
			$this->failing_builder_mock(),
			new NullLogger(),
			new NullProgressBar()
		);

		// No --output at all: everything this says about the path, it read back.
		$command(
			array(),
			array(
				'resume' => true,
				'yes'    => true,
			)
		);

		$this->assertStringContainsString(
			basename( $this->output_path ),
			implode( "\n", $printed ),
			'A resumed export must report against the path it read back from the job record, not the empty string it was invoked with.'
		);
	}

	/**
	 * A ManifestBuilderInterface whose single entry throws when the writer opens it.
	 *
	 * Failing at the point the entry's content is read is a faithful stand-in for a
	 * tick that dies partway — an unreadable file, a disk that has filled since the
	 * plan was made — and leaves the job record active, exactly as a real one would.
	 *
	 * @return ManifestBuilderInterface&\Mockery\MockInterface The builder mock.
	 */
	private function failing_builder_mock() {
		$builder = Mockery::mock( ManifestBuilderInterface::class );
		$builder->shouldReceive( 'build' )->andReturnUsing(
			static function (): ManifestStream {
				return ManifestStream::from_plans(
					array(
						new EntryPlan(
							EntryHeader::for_file( 'wp-content/a.txt', 5, 0644, 1690000000, 'application/octet-stream', 0 ),
							0,
							str_repeat( "\0", EntryWriter::NONCE_SIZE ),
							static function () {
								throw new RuntimeException( 'simulated tick failure' );
							}
						),
					)
				);
			}
		);
		return $builder;
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
		// The shared single-runner lock: free by default so __invoke's new lock
		// acquisition does not need a dedicated stub in every test. The named
		// lock is granted through the context mock above; the holder transient
		// OperationLock reads/writes directly via the global WordPress transient
		// functions, stubbed here to a plain "nothing is running" default.
		$mock->shouldReceive( 'acquire_named_lock' )->andReturn( true );
		$mock->shouldReceive( 'release_named_lock' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		return $mock;
	}
}
