<?php
/**
 * Surgical __invoke branch tests for ExportCommand.
 *
 * @package Pontifex\Tests\Unit\Cli\ExportCommand
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Cli\ExportCommand;

use Mockery;
use Pontifex\Cli\ExportCommand;
use Pontifex\Environment\Environment;
use Pontifex\Manifest\ManifestBuilderInterface;
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
 *     the destination) without swallowing the exception. Helper
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

		$command = new ExportCommand( $environment, $wordpress_context, $manifest_builder, new NullLogger() );

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
	 * An exception thrown by ManifestBuilder::build must propagate out of __invoke.
	 *
	 * The destination file is opened before the manifest is built. A
	 * try-finally protects the file descriptor so it is closed on
	 * failure paths as well as success paths. A regression that
	 * changed the finally to a catch-and-swallow would hide the
	 * underlying failure from the user; this test guards against
	 * that.
	 *
	 * We cannot directly assert that fclose() was invoked from
	 * inside the finally block — PHPUnit has no hook for "this
	 * resource handle was closed." What we CAN assert is that the
	 * exception is not swallowed; if the finally is missing or
	 * incorrectly catches, the test would fail because the
	 * exception either propagates wrongly-wrapped or not at all.
	 *
	 * @return void
	 */
	public function test_invoke_propagates_manifest_builder_exception(): void {
		$environment       = $this->build_environment_mock();
		$wordpress_context = $this->build_wordpress_context_mock();

		$manifest_builder = Mockery::mock( ManifestBuilderInterface::class );
		$manifest_builder
			->shouldReceive( 'build' )
			->once()
			->andThrow( new RuntimeException( 'simulated manifest-builder failure' ) );

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();

		$command = new ExportCommand( $environment, $wordpress_context, $manifest_builder, new NullLogger() );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'simulated manifest-builder failure' );

		$command(
			array(),
			array(
				'output' => $this->temp_output_path,
				'yes'    => true,
			)
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

		$command = new ExportCommand( $environment, $wordpress_context, $manifest_builder, $logger );

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
	 * A failing export records an error log line and re-throws unchanged.
	 *
	 * When the manifest builder throws, the command logs an "Export
	 * failed" line at error level and then re-throws the original
	 * exception, so the failure still reaches WP-CLI. This guards both
	 * halves: that the error is logged, and that it is not swallowed.
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

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();

		$logger = Mockery::mock( LoggerInterface::class );
		$logger->shouldReceive( 'info' )->zeroOrMoreTimes();
		$logger->shouldReceive( 'error' )->once();

		$command = new ExportCommand( $environment, $wordpress_context, $manifest_builder, $logger );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'simulated manifest-builder failure' );

		$command(
			array(),
			array(
				'output' => $this->temp_output_path,
				'yes'    => true,
			)
		);
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
		$mock->shouldReceive( 'format_size' )->andReturn( '0 B' );
		$mock->shouldReceive( 'option_value' )->andReturn( array() );
		$mock->shouldReceive( 'save_option' )->zeroOrMoreTimes();
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
		$mock->shouldReceive( 'build' )->once()->andReturn( array() );
		return $mock;
	}
}
