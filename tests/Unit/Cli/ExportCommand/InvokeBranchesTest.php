<?php
/**
 * Surgical __invoke branch tests for ExportCommand.
 *
 * @package Pontifex\Tests\Unit\Cli\ExportCommand
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Cli\ExportCommand;

use Brain\Monkey\Functions;
use Mockery;
use Pontifex\Archive\Crypto\CipherException;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Format\Scope;
use Pontifex\Archive\Reader\ArchiveReader;
use Pontifex\Archive\Writer\EntryPlan;
use Pontifex\Archive\Writer\EntryWriter;
use Pontifex\Cli\ExportCommand;
use Pontifex\Cli\NullProgressBar;
use Pontifex\Environment\Environment;
use Pontifex\Exception\ArchiveNotTrustworthy;
use Pontifex\Exception\HostCannotComply;
use Pontifex\Exception\InvalidRequest;
use Pontifex\Job\JobStore;
use Pontifex\Lock\OperationLock;
use Pontifex\Manifest\ManifestBuilderInterface;
use Pontifex\Manifest\ManifestStream;
use Pontifex\Tests\TestCase;
use Pontifex\WordPress\WordPressContext;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Behavioural coverage of two specific __invoke branches.
 *
 * The bulk of ExportCommand is intentionally not covered by
 * behavioural __invoke tests at the unit level. The structural
 * tests in {@see \Pontifex\Tests\Unit\Cli\ExportCommandTest}, the
 * helper-method tests in
 * {@see \Pontifex\Tests\Unit\Cli\ExportCommand\HelperMethodsTest},
 * and the future Phase 5 integration tests against a real WordPress
 * installation together provide the right coverage for the
 * orchestration. Adding a comprehensive __invoke test would be
 * coverage theatre — mocking out every collaborator only to verify
 * "the code I wrote calls the methods I made it call."
 *
 * Two branches genuinely escape that layering and earn surgical
 * unit tests:
 *
 *  1. The --yes short-circuit. ExportCommand reads --yes from its
 *     own associative args BEFORE delegating to WP_CLI::confirm, so
 *     the call to confirm() is never made at all when --yes is set.
 *     Helper tests cannot catch a missing if-statement here;
 *     integration tests would, but slowly and noisily.
 *  2. The try-finally exception path. ExportCommand opens the
 *     destination file before invoking the manifest builder. If the
 *     manifest builder throws, the finally block must run (closing
 *     the destination and releasing the lock), and the failure must
 *     then be reported as a verdict naming which kind of refusal it
 *     was (ADR 0022) before the command halts non-zero. Helper
 *     tests cannot exercise this; integration tests would catch a
 *     hung file handle eventually but not pinpoint the cause.
 *
 * The logging assertions (info on success, error on failure) live
 * here for the same reason: they are __invoke control-flow facts
 * that the helper and structural layers cannot see.
 *
 * A third candidate branch — confirming that build_default_manifest_builder()
 * wires up the right collaborators when no ManifestBuilder is
 * injected — is deliberately not unit-tested because the production
 * code directly news up FileScanner, WpdbAdapter, DatabaseScanner,
 * and ManifestBuilder concretely. There is no seam to assert
 * against without a refactor we are not making mid-release. Phase 6
 * integration tests cover it for free; that is the right layer.
 */
final class InvokeBranchesTest extends TestCase {

	/**
	 * A real temporary file path used as the export destination.
	 *
	 * Created in setUp and removed in tearDown. Real path (not
	 * mocked) because ExportCommand calls fopen() against it
	 * directly and Mockery cannot intercept stream resources.
	 *
	 * @var string|null
	 */
	private ?string $temp_output_path = null;

	/**
	 * Create a temp directory and reserve a path for the export output.
	 *
	 * The directory is real and writable; the destination file is
	 * not pre-created — ExportCommand creates it via fopen('wb').
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$temp_directory = sys_get_temp_dir();
		// Unique per-test to avoid cross-test interference.
		$this->temp_output_path = $temp_directory . '/pontifex-invoke-test-' . uniqid( '', true ) . '.wpmig';
	}

	/**
	 * Remove any output file left behind by the test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ( null !== $this->temp_output_path && file_exists( $this->temp_output_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test-only cleanup of a file the test itself created in sys_get_temp_dir(); WordPress functions like wp_delete_file are not available in the unit-test bootstrap.
			unlink( $this->temp_output_path );
		}
		$this->temp_output_path = null;
		parent::tearDown();
	}

	/**
	 * Passing --yes must short-circuit WP_CLI::confirm so it is never invoked.
	 *
	 * The check sits in __invoke before the confirm call. A regression
	 * that drops the if-statement would still pass every helper test
	 * (helpers do not see WP_CLI). Worth a focused assertion.
	 *
	 * @return void
	 */
	public function test_invoke_with_yes_flag_short_circuits_confirmation(): void {
		$environment       = $this->build_environment_mock();
		$wordpress_context = $this->build_wordpress_context_mock();
		$manifest_builder  = $this->build_manifest_builder_mock_returning_empty();

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		// The critical assertion: confirm() must NEVER be called when --yes is set.
		// Verified by Mockery::close() in the parent tearDown.
		$wp_cli->shouldNotReceive( 'confirm' );

		$command = new ExportCommand( $environment, $wordpress_context, $manifest_builder, new NullLogger(), new NullProgressBar() );

		$command(
			array(),
			array(
				'output' => $this->temp_output_path,
				'yes'    => true,
			)
		);

		// PHPUnit-visible assertion so the test is not flagged as risky.
		// Confirms __invoke ran to completion: the empty archive was written
		// to the temp path. The Mockery expectations above are the primary
		// behavioural assertion; this is the structural backstop.
		$this->assertFileExists(
			$this->temp_output_path,
			'ExportCommand should have written the output archive when --yes is set.'
		);
	}

	/**
	 * A manifest-builder failure halts non-zero instead of escaping as an uncaught exception.
	 *
	 * It used to be re-thrown out of __invoke, where WordPress's fatal handler caught
	 * it and printed a raw stack trace followed by "There has been a critical error on
	 * this website" — the sentence that says Pontifex is broken when the truth is
	 * usually that this server cannot do what was asked of it. The exit code was
	 * already non-zero and stays so; what changes is that a human can now read why it
	 * stopped.
	 *
	 * The destination file is opened before the manifest is built, and a try-finally
	 * protects the file descriptor so it is closed on failure paths as well as success
	 * paths. We cannot directly assert that fclose() was invoked from inside the
	 * finally block — PHPUnit has no hook for "this resource handle was closed." What
	 * we CAN assert is that the failure is reported rather than silently swallowed.
	 *
	 * @return void
	 */
	public function test_invoke_halts_instead_of_propagating_a_manifest_builder_failure(): void {
		$environment       = $this->build_environment_mock();
		$wordpress_context = $this->build_wordpress_context_mock();

		$manifest_builder = Mockery::mock( ManifestBuilderInterface::class );
		$manifest_builder
			->shouldReceive( 'build' )
			->once()
			->andThrow( new RuntimeException( 'simulated manifest-builder failure' ) );

		$printed = array();
		$wp_cli  = $this->mock_wp_cli_capturing_output( $printed );
		$wp_cli->shouldReceive( 'halt' )->once()->with( 1 );

		$command = new ExportCommand( $environment, $wordpress_context, $manifest_builder, new NullLogger(), new NullProgressBar() );

		$command(
			array(),
			array(
				'output' => $this->temp_output_path,
				'yes'    => true,
			)
		);

		$this->assertStringContainsString(
			'simulated manifest-builder failure',
			implode( "\n", $printed ),
			'The failure is reported to the operator, not re-thrown (halt is mocked, so execution continues).'
		);
	}

	/**
	 * A successful export records informational log lines and no error.
	 *
	 * The command logs an "Export started" line and an "Export
	 * complete" line on the happy path. A regression that dropped the
	 * logging, or that logged an error on success, would fail here.
	 *
	 * @return void
	 */
	public function test_invoke_logs_info_on_success(): void {
		$environment       = $this->build_environment_mock();
		$wordpress_context = $this->build_wordpress_context_mock();
		$manifest_builder  = $this->build_manifest_builder_mock_returning_empty();

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();

		$logger = Mockery::mock( LoggerInterface::class );
		$logger->shouldReceive( 'info' )->atLeast()->once();
		$logger->shouldReceive( 'error' )->never();

		$command = new ExportCommand( $environment, $wordpress_context, $manifest_builder, $logger, new NullProgressBar() );

		$command(
			array(),
			array(
				'output' => $this->temp_output_path,
				'yes'    => true,
			)
		);

		$this->assertFileExists(
			$this->temp_output_path,
			'ExportCommand should have written the output archive on the happy path.'
		);
	}

	/**
	 * A failing export still records its error log line, now alongside the verdict.
	 *
	 * The log entry is what a browser-side operator has to fall back on, so the
	 * readable CLI verdict is in addition to it, never instead of it.
	 *
	 * @return void
	 */
	public function test_invoke_logs_error_when_build_fails(): void {
		$environment       = $this->build_environment_mock();
		$wordpress_context = $this->build_wordpress_context_mock();

		$manifest_builder = Mockery::mock( ManifestBuilderInterface::class );
		$manifest_builder
			->shouldReceive( 'build' )
			->once()
			->andThrow( new RuntimeException( 'simulated manifest-builder failure' ) );

		$printed = array();
		$wp_cli  = $this->mock_wp_cli_capturing_output( $printed );
		$wp_cli->shouldReceive( 'halt' )->once()->with( 1 );

		$logger = Mockery::mock( LoggerInterface::class );
		$logger->shouldReceive( 'info' )->zeroOrMoreTimes();
		$logger->shouldReceive( 'error' )->once();

		$command = new ExportCommand( $environment, $wordpress_context, $manifest_builder, $logger, new NullProgressBar() );

		$command(
			array(),
			array(
				'output' => $this->temp_output_path,
				'yes'    => true,
			)
		);

		$this->assertNotSame( array(), $printed, 'The failure is logged and reported, not re-thrown.' );
	}

	/**
	 * The default export scans the wp-content root and records a content-only scope.
	 *
	 * Two coupled facts of the content-only default (ADR 0008): the manifest builder
	 * is handed the resolved wp-content root, and the written archive's provenance
	 * carries a content-only scope.
	 *
	 * @return void
	 */
	public function test_invoke_default_writes_a_content_only_archive(): void {
		$environment       = $this->build_environment_mock();
		$wordpress_context = $this->build_wordpress_context_mock();

		$manifest_builder = Mockery::mock( ManifestBuilderInterface::class );
		$manifest_builder
			->shouldReceive( 'build' )
			->once()
			->with( '/tmp/wp/wp-content' )
			->andReturn( ManifestStream::from_plans( array() ) );

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();

		$command = new ExportCommand( $environment, $wordpress_context, $manifest_builder, new NullLogger(), new NullProgressBar() );
		$command(
			array(),
			array(
				'output' => $this->temp_output_path,
				'yes'    => true,
			)
		);

		$scope = $this->written_scope( $this->temp_output_path );
		$this->assertNotNull( $scope, 'A default export should record a scope.' );
		$this->assertTrue( $scope->is_content_only() );
		$this->assertSame( 'wp-content', $scope->content_root() );
	}

	/**
	 * The --whole-site export scans the WordPress root and records a whole-site scope.
	 *
	 * The opt-in counterpart: --whole-site hands the manifest builder the WordPress
	 * root (ABSPATH, with no wp-content prefix), and the written archive's provenance
	 * records a whole-site scope.
	 *
	 * @return void
	 */
	public function test_invoke_whole_site_writes_a_whole_site_archive(): void {
		$environment       = $this->build_environment_mock();
		$wordpress_context = $this->build_wordpress_context_mock();

		$manifest_builder = Mockery::mock( ManifestBuilderInterface::class );
		$manifest_builder
			->shouldReceive( 'build' )
			->once()
			->with( '/tmp/wp' )
			->andReturn( ManifestStream::from_plans( array() ) );

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();

		$command = new ExportCommand( $environment, $wordpress_context, $manifest_builder, new NullLogger(), new NullProgressBar() );
		$command(
			array(),
			array(
				'output'     => $this->temp_output_path,
				'whole-site' => true,
				'yes'        => true,
			)
		);

		$scope = $this->written_scope( $this->temp_output_path );
		$this->assertNotNull( $scope, 'A whole-site export should record a scope.' );
		$this->assertFalse( $scope->is_content_only() );
		$this->assertSame( '', $scope->content_root() );
	}

	/**
	 * Files that changed while being read must surface as WP_CLI warnings.
	 *
	 * The scan-to-write race: the entry's header declares the scan-time size
	 * but the source yields different bytes at write time. The engine records
	 * the truth in the archive; the command must tell the user — one warning
	 * naming the file with both byte counts, plus a summary. The happy-path
	 * tests above double as the negative case: the WP_CLI alias mock throws
	 * on any unexpected warning() call, so a steady export must stay silent.
	 *
	 * @return void
	 */
	public function test_invoke_warns_when_a_file_changed_during_the_export(): void {
		$environment       = $this->build_environment_mock();
		$wordpress_context = $this->build_wordpress_context_mock();

		// The header claims 1000 bytes (the scan-time stat); only 400 remain by write time.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://memory is an in-process buffer, not a file.
		$source = fopen( 'php://memory', 'r+b' );
		if ( false === $source ) {
			$this->fail( 'Could not open php://memory.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Operating on a test stream resource, not a filesystem path.
		fwrite( $source, str_repeat( 'B', 400 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource, not a filesystem path.
		rewind( $source );

		$plan = new EntryPlan(
			EntryHeader::for_file( 'wp-content/moving.log', 1000, 0o644, 1690000000, 'application/octet-stream', 0 ),
			0,
			str_repeat( "\0", EntryWriter::NONCE_SIZE ),
			$source
		);

		$manifest_builder = Mockery::mock( ManifestBuilderInterface::class );
		$manifest_builder->shouldReceive( 'build' )->once()->andReturn( ManifestStream::from_plans( array( $plan ) ) );

		$warnings = array();
		$wp_cli   = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'warning' )
			->twice()
			->andReturnUsing(
				static function ( string $message ) use ( &$warnings ): void {
					$warnings[] = $message;
				}
			);

		$command = new ExportCommand( $environment, $wordpress_context, $manifest_builder, new NullLogger(), new NullProgressBar() );
		$command(
			array(),
			array(
				'output' => $this->temp_output_path,
				'yes'    => true,
			)
		);

		$this->assertStringContainsString( 'wp-content/moving.log', $warnings[0], 'The per-file warning must name the changed file.' );
		$this->assertStringContainsString( '1000', $warnings[0], 'The per-file warning must state the scan-time byte count.' );
		$this->assertStringContainsString( '400', $warnings[0], 'The per-file warning must state the captured byte count.' );
		$this->assertStringContainsString( '1 file changed while the backup ran', $warnings[1], 'The summary must count the changed files.' );
	}

	/**
	 * Read the scope recorded in a written archive's provenance.
	 *
	 * @param string $path Absolute path to the archive.
	 * @return Scope|null The recorded scope, or null if none was recorded.
	 */
	private function written_scope( string $path ): ?Scope {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Opening the just-written archive to read its provenance back in a unit test.
		$source = fopen( $path, 'rb' );
		if ( false === $source ) {
			$this->fail( 'Could not open the written archive.' );
		}
		try {
			$reader = new ArchiveReader( $source );
			return $reader->provenance()->scope();
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the archive stream opened in this helper.
			fclose( $source );
		}
	}

	/**
	 * Build an Environment mock with the calls __invoke makes during the happy path.
	 *
	 * The mock answers is_dir(parent), is_writable(parent),
	 * is_constant_defined('PONTIFEX_VERSION'), constant_value('PONTIFEX_VERSION'),
	 * is_constant_defined('ABSPATH'), constant_value('ABSPATH'),
	 * and php_version(). Every value is plausible-but-irrelevant
	 * because the tests are about control flow, not data.
	 *
	 * @return Environment&\Mockery\MockInterface
	 */
	private function build_environment_mock() {
		$mock = Mockery::mock( Environment::class );
		$mock->shouldReceive( 'is_dir' )->andReturn( true );
		$mock->shouldReceive( 'is_writable' )->andReturn( true );
		$mock->shouldReceive( 'is_constant_defined' )->with( 'PONTIFEX_VERSION' )->andReturn( true );
		$mock->shouldReceive( 'constant_value' )->with( 'PONTIFEX_VERSION' )->andReturn( '0.0.0-test' );
		$mock->shouldReceive( 'is_constant_defined' )->with( 'ABSPATH' )->andReturn( true );
		$mock->shouldReceive( 'constant_value' )->with( 'ABSPATH' )->andReturn( '/tmp/wp/' );
		$mock->shouldReceive( 'is_constant_defined' )->with( 'WP_CONTENT_DIR' )->andReturn( true );
		$mock->shouldReceive( 'constant_value' )->with( 'WP_CONTENT_DIR' )->andReturn( '/tmp/wp/wp-content' );
		$mock->shouldReceive( 'php_version' )->andReturn( '8.1.29' );
		return $mock;
	}

	/**
	 * Build a WordPressContext mock with the calls __invoke makes during the happy path.
	 *
	 * Provenance construction reads wp_version, site_url,
	 * wpdb_charset, and wpdb_collation. format_size is called at
	 * print_summary time on success paths; included with a
	 * placeholder return.
	 *
	 * @return WordPressContext&\Mockery\MockInterface
	 */
	private function build_wordpress_context_mock() {
		$mock = Mockery::mock( WordPressContext::class );
		$mock->shouldReceive( 'wp_version' )->andReturn( '6.6.1' );
		$mock->shouldReceive( 'site_url' )->andReturn( 'https://example.test' );
		$mock->shouldReceive( 'wpdb_charset' )->andReturn( 'utf8mb4' );
		$mock->shouldReceive( 'wpdb_collation' )->andReturn( 'utf8mb4_unicode_520_ci' );
		$mock->shouldReceive( 'wpdb_prefix' )->andReturn( 'wp_' );
		$mock->shouldReceive( 'format_size' )->andReturn( '0 B' );
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

	/**
	 * Build a ManifestBuilderInterface mock that returns an empty entry-plan list.
	 *
	 * Empty plans produce a valid empty archive. ArchiveWriter
	 * handles the empty case naturally; the test does not care
	 * what bytes end up on disk, only that __invoke runs to
	 * completion without invoking the branches under assertion.
	 *
	 * @return ManifestBuilderInterface&\Mockery\MockInterface
	 */
	private function build_manifest_builder_mock_returning_empty() {
		$mock = Mockery::mock( ManifestBuilderInterface::class );
		$mock->shouldReceive( 'build' )->once()->andReturn( \Pontifex\Manifest\ManifestStream::from_plans( array() ) );
		return $mock;
	}

	/**
	 * The shutdown backstop releases a lock this command still holds.
	 *
	 * Mirrors ImportCommand's and RollbackCommand's own shutdown-handler tests:
	 * a leaked "backup" holder is always reclaimable (see
	 * OperationLock::is_reclaimable()), so this is defence in depth rather than
	 * the reorder fix those two commands needed — but the handler must still
	 * behave identically here. A second call afterwards must be a no-op, proving
	 * the idempotent release() guard: the transient is cleared exactly once.
	 *
	 * @return void
	 */
	public function test_release_lock_on_shutdown_releases_a_held_lock(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		$delete_transient_calls = 0;
		Functions\when( 'delete_transient' )->alias(
			static function () use ( &$delete_transient_calls ): bool {
				++$delete_transient_calls;
				return true;
			}
		);

		$lock_context = Mockery::mock( WordPressContext::class );
		$lock_context->shouldReceive( 'acquire_named_lock' )->once()->andReturn( true );
		$lock_context->shouldReceive( 'release_named_lock' )->once();

		$lock = new OperationLock( $lock_context, new JobStore( sys_get_temp_dir() . '/pontifex-export-lock-test-' . uniqid( '', true ) ) );
		$this->assertTrue( $lock->acquire( OperationLock::OP_BACKUP ), 'The lock must be genuinely held before the shutdown handler runs.' );

		$command = new ExportCommand(
			logger: new NullLogger(),
			progress: new NullProgressBar(),
			lock: $lock
		);

		$command->release_lock_on_shutdown();
		$this->assertSame( 1, $delete_transient_calls, 'A held lock must be released at shutdown, clearing the holder transient.' );
		$this->assertFalse( $lock->is_held(), 'The lock must no longer be held once the shutdown handler has released it.' );

		// A second shutdown call must not clear another operation's transient a second time.
		$command->release_lock_on_shutdown();
		$this->assertSame( 1, $delete_transient_calls, 'A second shutdown call after a clean release must be a no-op.' );
	}

	/**
	 * The shutdown backstop is a no-op when the lock was never acquired (or was
	 * already released cleanly through the normal finally).
	 *
	 * @return void
	 */
	public function test_release_lock_on_shutdown_is_a_no_op_when_the_lock_is_not_held(): void {
		$lock_context = Mockery::mock( WordPressContext::class );
		$lock_context->shouldNotReceive( 'acquire_named_lock' );
		$lock_context->shouldNotReceive( 'release_named_lock' );

		$lock = new OperationLock( $lock_context, new JobStore( sys_get_temp_dir() . '/pontifex-export-lock-test-' . uniqid( '', true ) ) );

		$command = new ExportCommand(
			logger: new NullLogger(),
			progress: new NullProgressBar(),
			lock: $lock
		);

		$command->release_lock_on_shutdown();

		$this->assertFalse( $lock->is_held(), 'A lock that was never held must still report unheld after the no-op shutdown call.' );
	}

	/**
	 * An archive that cannot be trusted is named as such, and this export is not continued.
	 *
	 * The advice is deliberately not the output-state line the other kinds get. On a
	 * resumable run that line says "continue it with --resume", which is the one piece
	 * of advice that must never follow an archive Pontifex has just refused to trust.
	 *
	 * @return void
	 */
	public function test_invoke_reports_an_untrustworthy_archive_and_halts(): void {
		$printed = array();
		$wp_cli  = $this->mock_wp_cli_capturing_output( $printed );
		$wp_cli->shouldReceive( 'halt' )->once()->with( 1 );

		$this->run_failing_export( new ArchiveNotTrustworthy( 'the entry hash does not match' ) );

		$output = implode( "\n", $printed );
		$this->assertStringContainsString( 'Export refused.', $output );
		$this->assertStringContainsString( 'The archive cannot be trusted: the entry hash does not match', $output );
		$this->assertStringContainsString( 'Do not continue this export', $output );
		$this->assertStringNotContainsString( 'No archive was written', $output, 'The output-state line must not follow a trust refusal.' );
	}

	/**
	 * A host that cannot comply is distinguished from a bad archive, and says nothing was written.
	 *
	 * A full disk is the everyday case, and the two things an operator needs are that
	 * the server is the fixable problem and that there is no backup at the output path
	 * despite the command having run.
	 *
	 * @return void
	 */
	public function test_invoke_reports_a_host_that_cannot_comply_and_halts(): void {
		$printed = array();
		$wp_cli  = $this->mock_wp_cli_capturing_output( $printed );
		$wp_cli->shouldReceive( 'halt' )->once()->with( 1 );

		$this->run_failing_export( new HostCannotComply( 'there is not enough free disk space' ) );

		$output = implode( "\n", $printed );
		$this->assertStringContainsString( 'Export refused.', $output );
		$this->assertStringContainsString( 'This host cannot complete the backup: there is not enough free disk space', $output );
		$this->assertStringContainsString( 'The problem is this server', $output );
		$this->assertStringContainsString( 'No archive was written to', $output );
		$this->assertStringContainsString( 'an earlier backup, left untouched', $output );
	}

	/**
	 * A wrong request is reported without blaming the archive or the host.
	 *
	 * Nothing is wrong with anything and nothing ran, so neither the log pointer nor
	 * the output-state line belongs here.
	 *
	 * @return void
	 */
	public function test_invoke_reports_an_invalid_request_and_halts(): void {
		$printed = array();
		$wp_cli  = $this->mock_wp_cli_capturing_output( $printed );
		$wp_cli->shouldReceive( 'halt' )->once()->with( 1 );

		$this->run_failing_export( new InvalidRequest( '--exclude-table needs at least one pattern' ) );

		$output = implode( "\n", $printed );
		$this->assertStringContainsString( 'The request needs correcting: --exclude-table needs at least one pattern', $output );
		$this->assertStringNotContainsString( 'cannot be trusted', $output );
		$this->assertStringNotContainsString( 'Full details are in the Pontifex log', $output );
		$this->assertStringNotContainsString( 'No archive was written', $output );
	}

	/**
	 * A refusal Pontifex has not yet classified is still reported as a refusal.
	 *
	 * Most of the safety core still throws exceptions that predate the taxonomy, and
	 * five of them carry the marker interface without being one of the three kinds.
	 * Those are decisions Pontifex made deliberately, so calling them a failure would
	 * misreport a refusal as a fault.
	 *
	 * @return void
	 */
	public function test_invoke_reports_an_unclassified_refusal_as_a_refusal(): void {
		$printed = array();
		$wp_cli  = $this->mock_wp_cli_capturing_output( $printed );
		$wp_cli->shouldReceive( 'halt' )->once()->with( 1 );

		$this->run_failing_export( new CipherException( 'the cipher could not be initialised' ) );

		$output = implode( "\n", $printed );
		$this->assertStringContainsString( 'Export refused.', $output );
		$this->assertStringContainsString( 'The failure was: the cipher could not be initialised', $output );
	}

	/**
	 * A failure carrying no Pontifex type is reported as a failure, not a refusal.
	 *
	 * This is why the three kinds alone are not enough: most of the export path still
	 * raises bare SPL exceptions, and claiming a refusal for one would assert an intent
	 * that was never there.
	 *
	 * @return void
	 */
	public function test_invoke_reports_an_unrecognised_failure_as_a_failure_not_a_refusal(): void {
		$printed = array();
		$wp_cli  = $this->mock_wp_cli_capturing_output( $printed );
		$wp_cli->shouldReceive( 'halt' )->once()->with( 1 );

		$this->run_failing_export( new RuntimeException( 'FileScanner: the directory could not be read' ) );

		$output = implode( "\n", $printed );
		$this->assertStringContainsString( 'Export failed.', $output );
		$this->assertStringNotContainsString( 'Export refused.', $output );
		$this->assertStringContainsString( 'The failure was: FileScanner: the directory could not be read', $output );
		$this->assertStringContainsString( 'Full details are in the Pontifex log', $output );
	}

	/**
	 * The verdict carries no absolute server paths.
	 *
	 * This output is exactly what an operator pastes into a support thread or a bug
	 * report, and the engine's own messages routinely name absolute paths — so the
	 * message is redacted as well as the output path.
	 *
	 * @return void
	 */
	public function test_invoke_redacts_absolute_paths_from_the_verdict(): void {
		$temp_dir = rtrim( sys_get_temp_dir(), '/' );

		$printed = array();
		$wp_cli  = $this->mock_wp_cli_capturing_output( $printed );
		$wp_cli->shouldReceive( 'halt' )->once()->with( 1 );

		$this->run_failing_export( new HostCannotComply( sprintf( 'cannot write to %s/nowhere', $temp_dir ) ) );

		$output = implode( "\n", $printed );
		$this->assertStringNotContainsString( $temp_dir, $output, 'Neither the message nor the output path may leak an absolute path.' );
		$this->assertStringContainsString( '{TMP}/nowhere', $output, 'The redacted message still names the file, just not where it lives.' );
	}

	/**
	 * A failure after the archive was written must not claim no archive was written.
	 *
	 * The engine writes to a temp sibling and moves it into place, so once that move
	 * has happened there is a real, complete backup on disk — and a later fault must
	 * not send the operator looking for a file that is sitting right there. The lever
	 * here is the completion log line failing, which is both the first thing to happen
	 * after the move and a realistic way for it to go wrong: the log is a file on the
	 * same disk that has just proved troublesome.
	 *
	 * @return void
	 */
	public function test_invoke_says_the_archive_is_complete_when_the_failure_came_after_it(): void {
		$logger = Mockery::mock( LoggerInterface::class );
		$logger->shouldReceive( 'info' )->with( 'Export complete.', Mockery::any() )->once()->andThrow( new RuntimeException( 'simulated post-write failure' ) );
		$logger->shouldReceive( 'info' )->zeroOrMoreTimes();
		$logger->shouldReceive( 'error' )->once();

		$printed = array();
		$wp_cli  = $this->mock_wp_cli_capturing_output( $printed );
		$wp_cli->shouldReceive( 'halt' )->once()->with( 1 );

		$command = new ExportCommand(
			$this->build_environment_mock(),
			$this->build_wordpress_context_mock(),
			$this->build_manifest_builder_mock_returning_empty(),
			$logger,
			new NullProgressBar()
		);

		$command(
			array(),
			array(
				'output' => $this->temp_output_path,
				'yes'    => true,
			)
		);

		$output = implode( "\n", $printed );
		$this->assertStringContainsString( 'was written to', $output );
		$this->assertStringContainsString( 'and is complete; the failure came after it', $output );
		$this->assertStringNotContainsString( 'No archive was written', $output );
	}

	/**
	 * The operation lock is released before the command halts.
	 *
	 * WP_CLI::halt() calls exit(), and PHP does not run a finally block when exit() is
	 * called — so halting inside the catch would skip the release in the finally and
	 * leave the site's lock to the shutdown backstop. That backstop exists for a
	 * mid-work fatal, not as the normal path off a handled failure.
	 *
	 * @return void
	 */
	public function test_invoke_releases_the_lock_before_halting(): void {
		$lock_context = Mockery::mock( WordPressContext::class );
		$lock_context->shouldReceive( 'acquire_named_lock' )->once()->andReturn( true );
		$lock_context->shouldReceive( 'release_named_lock' )->once();
		$lock = new OperationLock( $lock_context, new JobStore( sys_get_temp_dir() . '/pontifex-export-lock-test-' . uniqid( '', true ) ) );

		$manifest_builder = Mockery::mock( ManifestBuilderInterface::class );
		$manifest_builder->shouldReceive( 'build' )->once()->andThrow( new RuntimeException( 'simulated manifest-builder failure' ) );

		$held_at_halt = null;
		$wp_cli       = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'warning' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'halt' )->once()->with( 1 )->andReturnUsing(
			static function () use ( $lock, &$held_at_halt ): void {
				$held_at_halt = $lock->is_held();
			}
		);

		$command = new ExportCommand(
			$this->build_environment_mock(),
			$this->build_wordpress_context_mock(),
			$manifest_builder,
			new NullLogger(),
			new NullProgressBar(),
			null,
			$lock
		);

		$command(
			array(),
			array(
				'output' => $this->temp_output_path,
				'yes'    => true,
			)
		);

		$this->assertFalse( $held_at_halt, 'The lock must already be released by the time the command halts.' );
	}

	/**
	 * Run an export whose manifest build raises the given failure.
	 *
	 * The manifest builder is the earliest injectable collaborator that can fail, so
	 * it is the shortest path to the failure handler with nothing else in the way.
	 *
	 * @param \Throwable $failure The failure the manifest build raises.
	 * @return void
	 */
	private function run_failing_export( \Throwable $failure ): void {
		$manifest_builder = Mockery::mock( ManifestBuilderInterface::class );
		$manifest_builder->shouldReceive( 'build' )->once()->andThrow( $failure );

		$command = new ExportCommand(
			$this->build_environment_mock(),
			$this->build_wordpress_context_mock(),
			$manifest_builder,
			new NullLogger(),
			new NullProgressBar()
		);

		$command(
			array(),
			array(
				'output' => $this->temp_output_path,
				'yes'    => true,
			)
		);
	}

	/**
	 * Build the WP_CLI alias mock, collecting everything it prints in order.
	 *
	 * Both streams land in one list so a test can assert not just that a line was
	 * printed but where it came in the sequence.
	 *
	 * @param array<int, string> $printed Receives each printed line, by reference.
	 * @return \Mockery\MockInterface The WP_CLI alias mock, for adding further expectations.
	 */
	private function mock_wp_cli_capturing_output( array &$printed ) {
		$collect = static function ( string $message ) use ( &$printed ): void {
			$printed[] = $message;
		};

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes()->andReturnUsing( $collect );
		$wp_cli->shouldReceive( 'warning' )->zeroOrMoreTimes()->andReturnUsing( $collect );
		return $wp_cli;
	}
}
