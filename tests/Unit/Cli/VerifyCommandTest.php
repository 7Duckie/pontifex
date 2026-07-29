<?php
/**
 * Unit tests for the memory budget VerifyCommand's --list path gives ArchiveReader.
 *
 * @package Pontifex\Tests\Unit\Cli
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Cli;

use Brain\Monkey\Functions;
use DateTimeImmutable;
use DateTimeZone;
use Mockery;
use Pontifex\Archive\Codec\CodecRegistry;
use Pontifex\Archive\Format\ExporterInfo;
use Pontifex\Archive\Format\Provenance;
use Pontifex\Archive\Writer\ArchiveWriter;
use Pontifex\Archive\Writer\EntryWriter;
use Pontifex\Archive\Writer\FooterWriter;
use Pontifex\Cli\NullProgressBar;
use Pontifex\Cli\VerifyCommand;
use Pontifex\Environment\Environment;
use Pontifex\Restore\RestoreRunnerInterface;
use Pontifex\Tests\TestCase;
use Pontifex\WordPress\WordPressContext;
use Psr\Log\NullLogger;

/**
 * Pins `wp pontifex verify --list` to a bounded-memory ArchiveReader.
 *
 * VerifyCommand::print_list() previously built its ArchiveReader with no memory budget:
 * `new ArchiveReader( $source )`. Decoding a large manifest with no budget
 * exhausts memory as an uncatchable fatal — on the very command an operator
 * reaches for when they are already worried about a backup. The fix passes
 * a resolved memory limit through as the reader's second argument, exactly
 * as the restore path does:
 * `$this->wordpress_context->convert_hr_to_bytes( $this->environment->ini_get( 'memory_limit' ) )`.
 *
 * The only honest way to pin this without reaching into ArchiveReader's
 * private state is to assert that the two seams supplying that budget are
 * actually consulted on the --list path, and that the value one returns is
 * the value fed to the other — proving the wiring, not just that each mock
 * was touched independently. A RestoreRunnerInterface fake is injected (as
 * VerifyCommand/InvokeBranchesTest.php does) so the default restore-runner
 * wiring — which also touches Environment and WordPressContext, for
 * unrelated reasons — never runs; every call captured here can only have
 * come from print_list().
 */
final class VerifyCommandTest extends TestCase {

	/**
	 * A real temporary archive file used as the verify source.
	 *
	 * VerifyCommand opens this with fopen() directly, so it must be a real,
	 * readable file, not a mock — Mockery cannot intercept stream resources.
	 *
	 * @var string|null
	 */
	private ?string $temp_archive_path = null;

	/**
	 * Write a real, minimal, unsigned, unencrypted archive to a temp path.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->temp_archive_path = sys_get_temp_dir() . '/pontifex-verify-list-test-' . uniqid( '', true ) . '.wpmig';

		$provenance = new Provenance(
			'6.6.1',
			'8.2.10',
			'https://example.test',
			'utf8mb4',
			'utf8mb4_unicode_520_ci',
			new ExporterInfo( 'pontifex', '0.3.0' ),
			new DateTimeImmutable( '2026-06-23T10:00:00+00:00', new DateTimeZone( 'UTC' ) )
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Opening a temp source file for the command to read; WP_Filesystem is not bootstrapped in unit tests.
		$destination = fopen( $this->temp_archive_path, 'w+b' );
		$writer      = new ArchiveWriter( new EntryWriter( CodecRegistry::with_defaults() ), new FooterWriter() );
		$writer->write_archive( $provenance, array(), $destination, null, null, null );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the test's own handle.
		fclose( $destination );
	}

	/**
	 * Remove the temp archive file the test created.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ( null !== $this->temp_archive_path && file_exists( $this->temp_archive_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test-only cleanup of a file the test itself created in sys_get_temp_dir().
			unlink( $this->temp_archive_path );
		}
		$this->temp_archive_path = null;
		parent::tearDown();
	}

	/**
	 * `--list` must resolve the runtime memory_limit through Environment and
	 * WordPressContext and hand the result to the ArchiveReader it builds.
	 *
	 * Break-verified: reverting print_list() to `new ArchiveReader( $source )`
	 * (dropping the second constructor argument) makes this test fail, because
	 * neither ini_get( 'memory_limit' ) nor convert_hr_to_bytes() would be
	 * called at all — Mockery's once()-expectations go unmet at tearDown.
	 *
	 * @return void
	 */
	public function test_list_flag_resolves_and_passes_the_memory_limit_to_the_reader(): void {
		$environment = Mockery::mock( Environment::class );
		$environment->shouldReceive( 'is_constant_defined' )->with( 'PONTIFEX_PUBLIC_KEY' )->andReturn( false );
		// The exact string PHP's own ini_get( 'memory_limit' ) would return;
		// asserted below to be the very value handed to convert_hr_to_bytes().
		$environment->shouldReceive( 'ini_get' )->once()->with( 'memory_limit' )->andReturn( '256M' );

		$wordpress_context = Mockery::mock( WordPressContext::class );
		$wordpress_context->shouldReceive( 'convert_hr_to_bytes' )->once()->with( '256M' )->andReturn( 268435456 );

		// Injected so VerifyCommand's default restore-runner wiring (which also
		// reads Environment/WordPressContext, for the FileWriter root and the
		// wpdb instance) never runs; only print_list() can be the source of the
		// two calls above.
		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'verify' )->once();
		$restore_runner->shouldNotReceive( 'restore' );

		// The manifest is empty, so the row list format_items() receives is
		// empty too; the row-building logic itself is unit-tested separately
		// in VerifyCommand/HelperMethodsTest.php.
		Functions\expect( 'WP_CLI\\Utils\\format_items' )
			->once()
			->with( 'table', array(), array( 'index', 'kind', 'name', 'codec', 'size', 'hash' ) );

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'halt' )->never();

		$command = new VerifyCommand( $environment, $wordpress_context, $restore_runner, new NullLogger(), new NullProgressBar() );

		$command(
			array( $this->temp_archive_path ),
			array( 'list' => true )
		);

		$this->assertFileExists(
			$this->temp_archive_path,
			'The command must run to completion on a sound archive (Mockery verifies the seam calls in tearDown).'
		);
	}
}
