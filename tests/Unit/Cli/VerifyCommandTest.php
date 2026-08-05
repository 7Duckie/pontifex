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
use Pontifex\Archive\Crypto\SigningContext;
use Pontifex\Archive\Crypto\SigningKeypair;
use Pontifex\Archive\Format\ExporterInfo;
use Pontifex\Archive\Format\Provenance;
use Pontifex\Archive\Writer\ArchiveWriter;
use Pontifex\Archive\Writer\EntryWriter;
use Pontifex\Archive\Writer\FooterWriter;
use Pontifex\Cli\NullProgressBar;
use Pontifex\Cli\SigningKeys;
use Pontifex\Cli\VerifyCommand;
use Pontifex\Environment\Environment;
use Pontifex\Restore\RestoreRunnerInterface;
use Pontifex\Tests\TestCase;
use Pontifex\WordPress\WordPressContext;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Pins `wp pontifex verify --list` to a bounded-memory ArchiveReader, and
 * pins the ADR 0012 signature-enforcement policy on `wp pontifex verify`.
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
 *
 * The four `test_verify_*` methods below pin ADR 0012's whole policy on
 * `check_signature()`: a trusted key makes signing mandatory, refusing an
 * unsigned or badly-signed archive with a BROKEN verdict; without a key, a
 * signed archive still verifies sound (with a warning) and an unsigned one
 * verifies sound silently. Unlike VerifyCommand/InvokeBranchesTest.php's own
 * signature tests, every refusal here is asserted on the actual captured
 * exception message (via the injected logger's `error()` call, which
 * receives the real Throwable in its context array), not merely on
 * `WP_CLI::halt(1)` having fired — halt(1) alone cannot tell a signature
 * refusal apart from any other broken-archive verdict, which is exactly the
 * gap tests/Unit/Cli/ImportCommandTest.php documents for the import path.
 * VerifyCommand has no scope gate, so — unlike import — there is no shadowing
 * risk to guard the fixture against; a legacy (no-scope) archive is fine.
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
	 * Remove the temp archive file (and any sibling key files) the test created.
	 *
	 * The sibling .key/.pub cleanup only matters to the ADR 0012 signature-gate
	 * tests below; the --list test above never writes them.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ( null !== $this->temp_archive_path ) {
			foreach ( array( $this->temp_archive_path, $this->temp_archive_path . '.key', $this->temp_archive_path . '.pub' ) as $path ) {
				if ( file_exists( $path ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test-only cleanup of a file the test itself created in sys_get_temp_dir().
					unlink( $path );
				}
			}
		}
		$this->temp_archive_path = null;
		parent::tearDown();
	}

	/**
	 * Write a minimal, valid archive to the given path, optionally signed.
	 *
	 * Mirrors the helper of the same name in VerifyCommand/InvokeBranchesTest.php
	 * (duplicated here rather than shared, since this file is deliberately
	 * independent of that one — see the ADR 0012 tests' class-level note
	 * below). No scope is recorded: VerifyCommand has no scope gate to shadow
	 * the signature gate the way ImportCommand's does, so a legacy (no-scope)
	 * archive is fine here.
	 *
	 * @param string              $path    Destination path.
	 * @param SigningContext|null $signing Signing context, or null for an unsigned archive.
	 * @return void
	 */
	private static function write_archive_to( string $path, ?SigningContext $signing ): void {
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
		$destination = fopen( $path, 'w+b' );
		$writer      = new ArchiveWriter( new EntryWriter( CodecRegistry::with_defaults() ), new FooterWriter() );
		$writer->write_archive( $provenance, array(), $destination, null, null, $signing );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the test's own handle.
		fclose( $destination );
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

	/**
	 * Case 1: a never-signed archive is reported BROKEN under a trusted key,
	 * with the "looks exactly like never-signed" message.
	 *
	 * @return void
	 */
	public function test_verify_reports_broken_for_a_never_signed_archive_under_a_trusted_key(): void {
		self::write_archive_to( $this->temp_archive_path, null );
		SigningKeys::write_keypair( SigningKeypair::generate(), $this->temp_archive_path . '.key', $this->temp_archive_path . '.pub' );

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'verify' )->once();

		$captured_message = null;
		$logger           = Mockery::mock( LoggerInterface::class );
		$logger->shouldReceive( 'info' )->zeroOrMoreTimes();
		$logger->shouldReceive( 'error' )->once()->andReturnUsing(
			function ( string $message, array $context ) use ( &$captured_message ): void {
				$captured_message = $context['exception']->getMessage();
			}
		);

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldNotReceive( 'warning' );
		$wp_cli->shouldReceive( 'halt' )->once()->with( 1 );

		$command = new VerifyCommand( Mockery::mock( Environment::class ), Mockery::mock( WordPressContext::class ), $restore_runner, $logger, new NullProgressBar() );

		$command(
			array( $this->temp_archive_path ),
			array( 'public-key' => $this->temp_archive_path . '.pub' )
		);

		$this->assertNotNull( $captured_message, 'check_signature() must have thrown, and the failure must have been logged with the real exception.' );
		$this->assertStringContainsString( 'archive is NOT signed', $captured_message );
	}

	/**
	 * Case 2: a signed archive whose signature does not verify is reported
	 * BROKEN with a distinct message from case 1's.
	 *
	 * @return void
	 */
	public function test_verify_reports_broken_with_a_distinct_message_for_a_bad_signature(): void {
		$keypair = SigningKeypair::generate();
		self::write_archive_to( $this->temp_archive_path, SigningContext::from_keypair( $keypair ) );
		// A different keypair's public key — so the signature will not verify.
		SigningKeys::write_keypair( SigningKeypair::generate(), $this->temp_archive_path . '.key', $this->temp_archive_path . '.pub' );

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'verify' )->once();

		$captured_message = null;
		$logger           = Mockery::mock( LoggerInterface::class );
		$logger->shouldReceive( 'info' )->zeroOrMoreTimes();
		$logger->shouldReceive( 'error' )->once()->andReturnUsing(
			function ( string $message, array $context ) use ( &$captured_message ): void {
				$captured_message = $context['exception']->getMessage();
			}
		);

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'halt' )->once()->with( 1 );

		$command = new VerifyCommand( Mockery::mock( Environment::class ), Mockery::mock( WordPressContext::class ), $restore_runner, $logger, new NullProgressBar() );

		$command(
			array( $this->temp_archive_path ),
			array( 'public-key' => $this->temp_archive_path . '.pub' )
		);

		$this->assertNotNull( $captured_message, 'check_signature() must have thrown, and the failure must have been logged with the real exception.' );
		$this->assertStringContainsString( 'did not verify against the supplied public key', $captured_message );
	}

	/**
	 * Case 3: a signed archive with no key supplied stays sound, with a warning.
	 *
	 * The counterweight to cases 1-2: without it, an over-broad "always
	 * refuse when signed" mutation would still pass every other test here.
	 *
	 * @return void
	 */
	public function test_verify_a_signed_archive_with_no_key_stays_sound_with_a_warning(): void {
		$keypair = SigningKeypair::generate();
		self::write_archive_to( $this->temp_archive_path, SigningContext::from_keypair( $keypair ) );
		// No key file is written at all: no --public-key, and no PONTIFEX_PUBLIC_KEY pin.

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'verify' )->once();

		$environment = Mockery::mock( Environment::class );
		$environment->shouldReceive( 'is_constant_defined' )->with( 'PONTIFEX_PUBLIC_KEY' )->andReturn( false );

		$logger = Mockery::mock( LoggerInterface::class );
		$logger->shouldReceive( 'info' )->zeroOrMoreTimes();
		$logger->shouldNotReceive( 'error' );

		$captured_warning = null;
		$wp_cli           = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldNotReceive( 'halt' );
		$wp_cli->shouldReceive( 'warning' )->once()->andReturnUsing(
			function ( string $message ) use ( &$captured_warning ): void {
				$captured_warning = $message;
			}
		);

		$command = new VerifyCommand( $environment, Mockery::mock( WordPressContext::class ), $restore_runner, $logger, new NullProgressBar() );

		$command(
			array( $this->temp_archive_path ),
			array()
		);

		$this->assertNotNull( $captured_warning, 'The operator must be warned that a signature exists but was not verified.' );
		$this->assertStringContainsString( 'signature was NOT verified', $captured_warning );
	}

	/**
	 * Case 4: an unsigned archive with no key stays sound silently.
	 *
	 * The everyday case: no signing, no pin, no flag. Nothing about the
	 * signature gate should so much as log a warning here.
	 *
	 * @return void
	 */
	public function test_verify_an_unsigned_archive_with_no_key_stays_sound_silently(): void {
		self::write_archive_to( $this->temp_archive_path, null );

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'verify' )->once();

		$environment = Mockery::mock( Environment::class );
		$environment->shouldReceive( 'is_constant_defined' )->with( 'PONTIFEX_PUBLIC_KEY' )->andReturn( false );

		$logger = Mockery::mock( LoggerInterface::class );
		$logger->shouldReceive( 'info' )->zeroOrMoreTimes();
		$logger->shouldNotReceive( 'error' );

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldNotReceive( 'halt' );
		$wp_cli->shouldNotReceive( 'warning' );

		$command = new VerifyCommand( $environment, Mockery::mock( WordPressContext::class ), $restore_runner, $logger, new NullProgressBar() );

		$command(
			array( $this->temp_archive_path ),
			array()
		);

		$this->assertFileExists(
			$this->temp_archive_path,
			'Verify must complete without halting or warning (Mockery verifies the seam calls in tearDown).'
		);
	}
}
