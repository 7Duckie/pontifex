<?php
/**
 * Behavioural __invoke tests for DiagnosticsCommand — the sanitised support bundle.
 *
 * @package Pontifex\Tests\Unit\Cli\DiagnosticsCommand
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Cli\DiagnosticsCommand;

use Brain\Monkey\Functions;
use Mockery;
use Patchwork\CodeManipulation\Stream as PatchworkStream;
use PharData;
use RecursiveIteratorIterator;
use RuntimeException;
use Pontifex\Cli\DiagnosticsCommand;
use Pontifex\Cli\DiagnosticsRedactor;
use Pontifex\Environment\Environment;
use Pontifex\Tests\TestCase;
use Pontifex\WordPress\WordPressContext;

/**
 * Behavioural coverage of DiagnosticsCommand::__invoke — the WP-CLI command that packs
 * doctor/stats output, an environment summary, and recent logs into a single sanitised
 * tar.gz support bundle.
 *
 * The command exists to be safe to hand to a stranger: it is only trustworthy if the site
 * URL and every absolute filesystem path are genuinely gone from every artifact it writes,
 * not merely gone from the artefacts a narrower test happened to sample. These tests are
 * built around a single oracle — reading the real bytes back out of the produced archive —
 * plus the surrounding filesystem behaviour (default path, directory permissions, cleanup,
 * and the two ways the command can refuse or fail) that DiagnosticsRedactorTest and
 * PathRedactorTest, being unit tests of the redactor alone, cannot see.
 */
final class InvokeTest extends TestCase {

	/**
	 * The site URL seeded into the WordPressContext mock and every captured/log artifact.
	 *
	 * @var string
	 */
	private const SECRET_SITE_URL = 'https://client-site.example';

	/**
	 * The literal ABSPATH given to the Environment mock, WITH the trailing slash WordPress
	 * really supplies. A bug fixed only yesterday meant a trailing-slash ABSPATH never
	 * matched anything PathRedactor was asked to hide; this constant is what stops it
	 * coming back unnoticed.
	 *
	 * @var string
	 */
	private const SECRET_ABSPATH = '/var/www/html/';

	/**
	 * A plain absolute path secret: the site root followed by a filename.
	 *
	 * @var string
	 */
	private const SECRET_CONFIG_PATH = '/var/www/html/wp-config.php';

	/**
	 * A bare root-path secret with nothing after it, for seeding at the end of a line.
	 *
	 * @var string
	 */
	private const SECRET_BARE_ROOT = '/var/www/html';

	/**
	 * A root-path secret quoted inside a sentence — the shape a bug fixed yesterday let
	 * through unredacted, because the earlier matcher required a following slash or the
	 * end of the string and a closing quote mark is neither.
	 *
	 * @var string
	 */
	private const SECRET_QUOTED_SENTENCE = 'is not inside the site at "/var/www/html".';

	/**
	 * A unique temporary fixture directory this test owns, standing in for the WordPress
	 * install. Created in {@see self::setUp()} and removed in {@see self::tearDown()}.
	 * Every test builds its Environment mock's WP_CONTENT_DIR from this path, so the
	 * command's own real filesystem calls (log reads, directory creation, the archive
	 * write) land here rather than anywhere shared with other tests or other suites.
	 *
	 * @var string|null
	 */
	private ?string $fixture_dir = null;

	/**
	 * Create a fresh fixture directory with a wp-content/pontifex/logs tree, and stub wp_json_encode.
	 *
	 * The wp_json_encode() function is not one of the functions Pontifex\Tests\TestCase
	 * stubs by default, and DiagnosticsCommand's environment.json is built with it. It is
	 * aliased here to PHP's own json_encode(), which shares wp_json_encode's
	 * ($data, $options, $depth) signature exactly, so the real encoding behaviour
	 * (including JSON_PRETTY_PRINT) is exercised rather than faked.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->fixture_dir = sys_get_temp_dir() . '/pontifex-diagnostics-invoke-test-' . uniqid( '', true );
		$this->make_dir( $this->fixture_dir . '/wp-content/pontifex/logs' );

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	/**
	 * Remove the fixture directory tree.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ( null !== $this->fixture_dir ) {
			$this->remove_tree( $this->fixture_dir );
		}
		$this->fixture_dir = null;

		parent::tearDown();
	}

	/**
	 * The bundle genuinely contains no secret in any member — the oracle.
	 *
	 * Every prior redaction unit test (DiagnosticsRedactorTest, PathRedactorTest) can stay
	 * green while the command still leaks, if a call site forgets to route one artifact
	 * through the redactor before packing it. Rather than trust any single artifact, this
	 * reads every member's real bytes back out of the produced .tar.gz and concatenates
	 * them, so a leak in ANY member — the doctor/stats capture, the environment summary, or
	 * either log file — fails the assertion. It seeds all three shapes of absolute path
	 * PathRedactor must catch (plain, bare end-of-line, and quoted inside a sentence) plus
	 * the site URL, in both the captured command output and a real log file, including a
	 * rotated one, to prove rotated logs are sanitised too. The output path has no
	 * "/pontifex/" segment, so this also exercises the plain-mkdir branch of ensure_dir()
	 * rather than the ProtectedDirectory one covered by the default-path tests below.
	 *
	 * @return void
	 */
	public function test_invoke_writes_a_bundle_with_no_secret_in_any_member(): void {
		$doctor_text      = $this->secret_bearing_text( 'doctor' );
		$stats_text       = $this->secret_bearing_text( 'stats' );
		$log_text         = $this->secret_bearing_text( 'log' );
		$rotated_log_text = $this->secret_bearing_text( 'log.1' );

		$this->put( $this->fixture_dir . '/wp-content/pontifex/logs/pontifex.log', $log_text );
		$this->put( $this->fixture_dir . '/wp-content/pontifex/logs/pontifex.log.1', $rotated_log_text );

		$environment       = $this->build_environment_mock();
		$wordpress_context = $this->build_wordpress_context_mock();
		$this->build_wp_cli_mock( $doctor_text, $stats_text );

		$output_path = $this->fixture_dir . '/out/support.tar.gz';

		$command = new DiagnosticsCommand( $environment, $wordpress_context );
		$this->invoke_without_patchwork_stream_wrapper( $command, array(), array( 'output' => $output_path ) );

		$this->assertFileExists( $output_path, 'The command should have written the bundle to the given --output path.' );

		list( $members, $concatenated, $contents ) = $this->read_bundle( $output_path );

		$this->assertStringNotContainsString(
			self::SECRET_SITE_URL,
			$concatenated,
			'The real site URL must never appear anywhere in the bundle.'
		);
		$this->assertStringContainsString(
			DiagnosticsRedactor::URL_PLACEHOLDER,
			$concatenated,
			'The URL placeholder should stand in its place.'
		);

		$this->assertStringNotContainsString(
			'/var/www/html',
			$concatenated,
			'The site root must not appear in the bundle in any of its three shapes — plain, bare end-of-line, or quoted inside a sentence.'
		);
		$this->assertStringContainsString(
			'{ABSPATH}',
			$concatenated,
			'The redacted placeholder should stand in for the site root.'
		);

		$this->assertSame(
			array( 'README.txt', 'doctor.txt', 'environment.json', 'logs/pontifex.log', 'logs/pontifex.log.1', 'stats.txt' ),
			$members,
			'The bundle should contain exactly these members: the README, both captured commands, the environment summary, and both present log files.'
		);

		$environment_summary = json_decode( $contents['environment.json'], true );
		$this->assertIsArray( $environment_summary, 'environment.json must parse as JSON.' );
		$this->assertSame( '1.1.0', $environment_summary['pontifex_version'] ?? null );
		$this->assertSame( '8.2.4', $environment_summary['php_version'] ?? null );
		$this->assertSame( '6.7.1', $environment_summary['wordpress_version'] ?? null );
		$this->assertSame( '8.0.36', $environment_summary['database_version'] ?? null );
		$this->assertSame( 'utf8mb4', $environment_summary['wpdb_charset'] ?? null );
		$this->assertSame( 'utf8mb4_unicode_520_ci', $environment_summary['wpdb_collation'] ?? null );
		$this->assertSame( 'loaded', $environment_summary['extensions']['sodium'] ?? null );
		$this->assertSame( 'twentytwentyfour', $environment_summary['options']['template'] ?? null );
	}

	/**
	 * With no --output, the default bundle path lives under a locked-down diagnostics directory.
	 *
	 * A diagnostics bundle can contain log excerpts, so the default directory Pontifex owns
	 * must be created owner-only (0700) rather than world-readable — otherwise the very
	 * thing this command exists to sanitise for sharing would sit readable by anyone else on
	 * a shared host. The default path always contains "/pontifex/", so this exercises the
	 * ProtectedDirectory branch of ensure_dir(), the counterpart to the plain-mkdir branch
	 * the oracle test above exercises.
	 *
	 * @return void
	 */
	public function test_invoke_default_output_path_is_locked_down(): void {
		$environment       = $this->build_environment_mock();
		$wordpress_context = $this->build_wordpress_context_mock();
		$this->build_wp_cli_mock( 'doctor report', 'stats report' );

		$command = new DiagnosticsCommand( $environment, $wordpress_context );
		$this->invoke_without_patchwork_stream_wrapper( $command, array(), array() );

		$diagnostics_dir = $this->fixture_dir . '/wp-content/pontifex/diagnostics';
		$bundles         = glob( $diagnostics_dir . '/pontifex-diagnostics-*.tar.gz' );

		$this->assertNotFalse( $bundles, 'glob() should not fail reading the diagnostics directory.' );
		$this->assertCount( 1, $bundles, 'Exactly one bundle should have been written under the default directory.' );
		$this->assertMatchesRegularExpression(
			'/^pontifex-diagnostics-\d{8}-\d{6}\.tar\.gz$/',
			basename( $bundles[0] ),
			'The default filename should carry the documented "pontifex-diagnostics-<timestamp>.tar.gz" shape.'
		);

		$this->assertSame(
			0700,
			fileperms( $diagnostics_dir ) & 0777,
			'The diagnostics directory can hold log excerpts, so it must be created owner-only, not world-readable.'
		);
	}

	/**
	 * No intermediate uncompressed tar is left behind after a successful run.
	 *
	 * The write_bundle() method builds the archive as an uncompressed
	 * ".pontifex-diagnostics-<random>.tar" sibling first, then gzips and renames it into
	 * place. A regression that dropped the final unlink() — for instance an early return
	 * added ahead of it — would leave that sibling sitting in the operator's own
	 * locked-down directory forever: a second, uncompressed artifact easy to sweep up and
	 * share by mistake alongside the real bundle.
	 *
	 * @return void
	 */
	public function test_invoke_leaves_no_intermediate_tar_behind(): void {
		$environment       = $this->build_environment_mock();
		$wordpress_context = $this->build_wordpress_context_mock();
		$this->build_wp_cli_mock( 'doctor report', 'stats report' );

		$command = new DiagnosticsCommand( $environment, $wordpress_context );
		$this->invoke_without_patchwork_stream_wrapper( $command, array(), array() );

		$diagnostics_dir = $this->fixture_dir . '/wp-content/pontifex/diagnostics';

		$this->assertSame( array(), glob( $diagnostics_dir . '/*.tar' ), 'No uncompressed intermediate tar should remain.' );
		$this->assertSame( array(), glob( $diagnostics_dir . '/.pontifex-diagnostics-*.tar' ), 'No hidden intermediate tar should remain.' );
		$this->assertCount( 1, glob( $diagnostics_dir . '/*.tar.gz' ), 'Exactly the one finished bundle should remain.' );
	}

	/**
	 * A wrong --output extension is refused before anything is written, and the error names the redacted path.
	 *
	 * The resolve_output_path() method checks the extension before the redactor, the
	 * captured commands, or the archive are ever touched, and its error message itself
	 * runs the given path through PathRedactor — because that error message is exactly the
	 * kind of operator-facing text this command's own contract promises never carries a raw
	 * server path. In production WP_CLI::error() exits; the alias mock's error() is made to
	 * throw a sentinel exception here so the test can assert the message before execution
	 * would have stopped.
	 *
	 * @return void
	 */
	public function test_invoke_refuses_a_wrong_extension_before_writing_anything(): void {
		$environment       = $this->build_environment_mock();
		$wordpress_context = $this->build_wordpress_context_mock();

		$bad_output_path = sys_get_temp_dir() . '/pontifex-diagnostics-invoke-test-wrong-ext-' . uniqid( '', true ) . '.zip';

		$captured_error = null;
		$wp_cli         = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'error' )->once()->andReturnUsing(
			static function ( string $message ) use ( &$captured_error ): void {
				$captured_error = $message;
				throw new RuntimeException( 'sentinel: wp-cli-error' );
			}
		);

		$command = new DiagnosticsCommand( $environment, $wordpress_context );

		try {
			$this->invoke_without_patchwork_stream_wrapper( $command, array(), array( 'output' => $bad_output_path ) );
			$this->fail( 'Expected the sentinel exception modelling WP_CLI::error() exiting.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'sentinel: wp-cli-error', $exception->getMessage() );
		}

		$this->assertNotNull( $captured_error, 'WP_CLI::error() should have been called.' );
		$this->assertStringContainsString(
			'{TMP}',
			$captured_error,
			'The redacted placeholder should stand in for the system temp directory.'
		);
		$this->assertStringNotContainsString(
			rtrim( sys_get_temp_dir(), '/' ),
			$captured_error,
			'The error message itself runs through the redactor and is a leak surface — the raw temp directory must not appear.'
		);
		$this->assertFileDoesNotExist( $bad_output_path, 'Nothing should have been written once the extension check refused the request.' );
	}

	/**
	 * A write failure is reported through WP_CLI::error with the path redacted.
	 *
	 * A plain regular file is planted exactly where write_bundle()'s ensure_dir() needs to
	 * create the default diagnostics directory, so neither ProtectedDirectory::ensure() nor
	 * a plain mkdir() can succeed and ensure_dir() throws. The catch block in __invoke builds
	 * its message from that exception via PathRedactor::from_environment() — a second,
	 * independent redaction path from the one the happy-path tests exercise — so this is the
	 * one test that proves a failure message is sanitised too, not just a successful bundle.
	 *
	 * @return void
	 */
	public function test_invoke_reports_a_write_failure_with_the_path_redacted(): void {
		$environment       = $this->build_environment_mock();
		$wordpress_context = $this->build_wordpress_context_mock();

		$diagnostics_dir = $this->fixture_dir . '/wp-content/pontifex/diagnostics';
		$this->put( $diagnostics_dir, 'blocker' );
		$this->assertFalse( is_dir( $diagnostics_dir ), 'The fixture set-up itself must not already be a usable directory, or the test would prove nothing.' );

		$captured_error = null;
		$wp_cli         = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'runcommand' )->with( 'pontifex doctor', Mockery::any() )->once()->andReturn( 'doctor report' );
		$wp_cli->shouldReceive( 'runcommand' )->with( 'pontifex stats', Mockery::any() )->once()->andReturn( 'stats report' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'error' )->once()->andReturnUsing(
			static function ( string $message ) use ( &$captured_error ): void {
				$captured_error = $message;
				throw new RuntimeException( 'sentinel: wp-cli-error' );
			}
		);

		$command = new DiagnosticsCommand( $environment, $wordpress_context );

		try {
			$this->invoke_without_patchwork_stream_wrapper( $command, array(), array() );
			$this->fail( 'Expected the sentinel exception modelling WP_CLI::error() exiting.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'sentinel: wp-cli-error', $exception->getMessage() );
		}

		$this->assertNotNull( $captured_error, 'WP_CLI::error() should have been called once ensure_dir() threw.' );
		$this->assertStringContainsString( 'could not create the output directory', $captured_error );
		$this->assertStringContainsString(
			'{TMP}',
			$captured_error,
			'The redacted placeholder should stand in for the system temp directory the fixture lives under.'
		);
		$this->assertStringNotContainsString(
			$this->fixture_dir,
			$captured_error,
			'The raw fixture path must not appear in an operator-facing error message.'
		);
	}

	/**
	 * Build an Environment mock answering DiagnosticsCommand's constant and PHP-runtime reads.
	 *
	 * WP_CONTENT_DIR points inside this test's own fixture directory, so the command's log
	 * reads and default output path land there rather than any real path. ABSPATH is the
	 * literal secret constant, WITH the trailing slash WordPress really supplies.
	 *
	 * @return Environment&\Mockery\MockInterface
	 */
	private function build_environment_mock() {
		$mock = Mockery::mock( Environment::class );

		$mock->shouldReceive( 'is_constant_defined' )->with( 'ABSPATH' )->andReturn( true );
		$mock->shouldReceive( 'constant_value' )->with( 'ABSPATH' )->andReturn( self::SECRET_ABSPATH );
		$mock->shouldReceive( 'is_constant_defined' )->with( 'WP_CONTENT_DIR' )->andReturn( true );
		$mock->shouldReceive( 'constant_value' )->with( 'WP_CONTENT_DIR' )->andReturn( $this->fixture_dir . '/wp-content' );
		$mock->shouldReceive( 'is_constant_defined' )->with( 'PONTIFEX_VERSION' )->andReturn( true );
		$mock->shouldReceive( 'constant_value' )->with( 'PONTIFEX_VERSION' )->andReturn( '1.1.0' );

		$mock->shouldReceive( 'php_version' )->andReturn( '8.2.4' );
		$mock->shouldReceive( 'extension_loaded' )->andReturn( true );
		$mock->shouldReceive( 'ini_get' )->with( 'open_basedir' )->andReturn( '' );
		$mock->shouldReceive( 'ini_get' )->with( Mockery::not( 'open_basedir' ) )->andReturn( '128M' );

		return $mock;
	}

	/**
	 * Build a WordPressContext mock answering site identity, database facts, and safe options.
	 *
	 * The site_url() method returns the secret site URL the redactor must strip. The safe-option values
	 * are deliberately ordinary — none end in a sensitive suffix — matching the fixed
	 * SAFE_OPTIONS list DiagnosticsCommand reads (template, stylesheet, blog_charset,
	 * timezone_string, WPLANG).
	 *
	 * @return WordPressContext&\Mockery\MockInterface
	 */
	private function build_wordpress_context_mock() {
		$mock = Mockery::mock( WordPressContext::class );

		$mock->shouldReceive( 'site_url' )->andReturn( self::SECRET_SITE_URL );
		$mock->shouldReceive( 'wp_version' )->andReturn( '6.7.1' );
		$mock->shouldReceive( 'db_server_version' )->andReturn( '8.0.36' );
		$mock->shouldReceive( 'wpdb_charset' )->andReturn( 'utf8mb4' );
		$mock->shouldReceive( 'wpdb_collation' )->andReturn( 'utf8mb4_unicode_520_ci' );
		$mock->shouldReceive( 'option_value' )->andReturnUsing(
			static function ( string $name ) {
				$values = array(
					'template'        => 'twentytwentyfour',
					'stylesheet'      => 'twentytwentyfour-child',
					'blog_charset'    => 'UTF-8',
					'timezone_string' => 'Europe/London',
					'WPLANG'          => 'en_GB',
				);
				return $values[ $name ] ?? null;
			}
		);

		return $mock;
	}

	/**
	 * Build the WP_CLI alias mock for a happy-path run, returning the given doctor/stats text.
	 *
	 * Exactly one alias mock may exist per test (see tests/Unit/Cli/INVOKE_TESTING.md), so
	 * every test that needs WP_CLI builds it here (or adds runcommand/error expectations
	 * directly) rather than creating a second one.
	 *
	 * @param string $doctor_text The text WP_CLI::runcommand('pontifex doctor', …) returns.
	 * @param string $stats_text  The text WP_CLI::runcommand('pontifex stats', …) returns.
	 * @return \Mockery\MockInterface The WP_CLI alias mock.
	 */
	private function build_wp_cli_mock( string $doctor_text, string $stats_text ) {
		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'runcommand' )->with( 'pontifex doctor', Mockery::any() )->once()->andReturn( $doctor_text );
		$wp_cli->shouldReceive( 'runcommand' )->with( 'pontifex stats', Mockery::any() )->once()->andReturn( $stats_text );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'error' )->never();

		return $wp_cli;
	}

	/**
	 * Build text containing the site URL and all three absolute-path shapes PathRedactor must catch.
	 *
	 * The $label distinguishes which artifact a given piece of text was seeded into, purely
	 * so a failing assertion is easy to trace back to its source; it plays no part in the
	 * redaction itself.
	 *
	 * @param string $label Identifies the artifact this text is destined for (e.g. 'doctor').
	 * @return string Multi-line text carrying every secret shape.
	 */
	private function secret_bearing_text( string $label ): string {
		return implode(
			"\n",
			array(
				sprintf( '[%s] site: %s', $label, self::SECRET_SITE_URL ),
				sprintf( '[%s] config file: %s', $label, self::SECRET_CONFIG_PATH ),
				sprintf( '[%s] site root: %s', $label, self::SECRET_BARE_ROOT ),
				sprintf( '[%s] %s', $label, self::SECRET_QUOTED_SENTENCE ),
			)
		) . "\n";
	}

	/**
	 * Read every member of a tar.gz bundle back, proving PharData's read path rather than trusting what was written.
	 *
	 * This is the oracle's mechanism: rather than inspect any single artifact, every
	 * member's actual bytes are read back from the archive on disk. The relative member
	 * name is recovered by stripping the "phar://<realpath>/" prefix PharFileInfo reports.
	 *
	 * @param string $archive_path Absolute path to the .tar.gz bundle.
	 * @return array{0: string[], 1: string, 2: array<string, string>} Sorted relative member names; the concatenation of every member's bytes (so a leak anywhere fails one assertion); and a member name => content map for inspecting one artifact in isolation.
	 */
	private function read_bundle( string $archive_path ): array {
		$members      = array();
		$concatenated = '';
		$contents     = array();

		$prefix = 'phar://' . (string) realpath( $archive_path ) . '/';
		foreach ( new RecursiveIteratorIterator( new PharData( $archive_path ) ) as $file ) {
			$pathname = $file->getPathname();
			$relative = str_starts_with( $pathname, $prefix ) ? substr( $pathname, strlen( $prefix ) ) : $pathname;

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading back every member of the just-written bundle to prove no secret survived redaction; PharData exposes no other read API for member bytes.
			$bytes = (string) file_get_contents( $pathname );

			$members[]             = $relative;
			$contents[ $relative ] = $bytes;
			$concatenated         .= $bytes;
		}
		sort( $members );

		return array( $members, $concatenated, $contents );
	}

	/**
	 * Invoke the command with Brain Monkey's Patchwork stream-wrapper override briefly disabled.
	 *
	 * Pontifex\Tests\TestCase::setUp() (via Brain\Monkey\setUp()) unconditionally registers
	 * Patchwork's own stream wrapper for the "file" and "phar" protocols so it can rewrite
	 * stubbed functions (wp_json_encode() here) at require-time. That rewrite has already
	 * happened by the time any test runs — DiagnosticsCommand.php was compiled into that
	 * form the first time it was autoloaded — so the wrapper serves no further purpose
	 * during the call itself, but leaving it active collides with PharData's own
	 * tar-then-gzip-then-rename sequence in write_bundle(): something inside ext-phar's
	 * internals independently calls stat()/fopen() against the just-renamed intermediate
	 * ".tar" through that wrapper and finds it already gone, raising a spurious E_WARNING
	 * that phpunit.xml.dist's failOnWarning="true" would otherwise turn into a false
	 * failure of an entirely correct run — confirmed correct by every other assertion in
	 * these tests, and by the same production code exercised outside this harness (no
	 * Patchwork involved) producing zero warnings. Disabling just the wrapper — not the
	 * function stubs it already baked in at require-time — for the duration of this one
	 * call is the narrowest fix available; Stream::wrap()/unwrap() are the same public
	 * toggle Patchwork's own bypass() helper uses internally around its real I/O calls.
	 *
	 * @param DiagnosticsCommand         $command          The command under test.
	 * @param array<int, string>         $positional_args  Positional arguments to pass through.
	 * @param array<string, string|bool> $associative_args Associative arguments to pass through.
	 * @return void
	 */
	private function invoke_without_patchwork_stream_wrapper( DiagnosticsCommand $command, array $positional_args, array $associative_args ): void {
		PatchworkStream::unwrap();
		try {
			$command( $positional_args, $associative_args );
		} finally {
			PatchworkStream::wrap();
		}
	}

	/**
	 * Create a directory for fixture setup.
	 *
	 * @param string $path The directory to create.
	 * @return void
	 */
	private function make_dir( string $path ): void {
		if ( ! is_dir( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture directory created under the system temp path.
			mkdir( $path, 0777, true );
		}
	}

	/**
	 * Write fixture bytes to a path.
	 *
	 * @param string $path  The file path.
	 * @param string $bytes The contents.
	 * @return void
	 */
	private function put( string $path, string $bytes ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture write under the system temp path.
		file_put_contents( $path, $bytes );
	}

	/**
	 * Recursively delete a file or directory tree.
	 *
	 * @param string $path The path to remove.
	 * @return void
	 */
	private function remove_tree( string $path ): void {
		if ( is_file( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test cleanup of a fixture created under the system temp path.
			unlink( $path );
			return;
		}

		if ( ! is_dir( $path ) ) {
			return;
		}

		$entries = (array) scandir( $path );
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$this->remove_tree( $path . '/' . $entry );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test cleanup of a fixture directory created under the system temp path.
		rmdir( $path );
	}
}
