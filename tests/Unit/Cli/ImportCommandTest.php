<?php
/**
 * Pins the ADR 0012 signature-enforcement policy on `wp pontifex import`.
 *
 * @package Pontifex\Tests\Unit\Cli
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Cli;

use Brain\Monkey\Functions;
use DateTimeImmutable;
use DateTimeZone;
use Mockery;
use PHPUnit\Framework\AssertionFailedError;
use Pontifex\Archive\Codec\CodecRegistry;
use Pontifex\Archive\Crypto\SigningContext;
use Pontifex\Archive\Crypto\SigningKeypair;
use Pontifex\Archive\Format\ArchiveSignature;
use Pontifex\Archive\Format\ByteOrder;
use Pontifex\Archive\Format\ExporterInfo;
use Pontifex\Archive\Format\Header;
use Pontifex\Archive\Format\Provenance;
use Pontifex\Archive\Format\Scope;
use Pontifex\Archive\Writer\ArchiveWriter;
use Pontifex\Archive\Writer\EntryWriter;
use Pontifex\Archive\Writer\FooterWriter;
use Pontifex\Cli\ImportCommand;
use Pontifex\Cli\NullProgressBar;
use Pontifex\Cli\SigningKeys;
use Pontifex\Environment\Environment;
use Pontifex\Restore\RestoreRunnerInterface;
use Pontifex\Rollback\SafetyArchiverInterface;
use Pontifex\Tests\TestCase;
use Pontifex\WordPress\WordPressContext;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Pins the whole ADR 0012 policy on the import path, one gate statement at a time.
 *
 * The file tests/Unit/Cli/ImportCommand/InvokeBranchesTest.php already carries
 * four signature-gate tests, but every one of them builds its fixture archive with
 * `write_archive_to( $path, $signing )` — a two-argument call that leaves the
 * archive's scope at its default, which is `null` (a legacy, pre-v0.7.0
 * archive). A legacy archive is refused by assert_scope_permits_restore()
 * (ImportCommand.php) BEFORE verify_signature_gate()'s refusal is ever
 * distinguishable from it, because both refusals go through the same
 * `WP_CLI::error()->once()`-and-throw shape with no message assertion. This
 * was confirmed directly: replacing the body of verify_signature_gate() with
 * `return;` leaves those four tests, and the full suite, green — the scope
 * refusal (line 923) fires in the signature gate's place and satisfies the
 * same mock expectation.
 *
 * Every fixture below is a **content-only** archive (`Scope::content_only()`),
 * which assert_scope_permits_restore() accepts unconditionally on the default
 * (non-whole-site) path. That removes the scope gate as a possible source of
 * refusal, so the signature gate is the only statement standing between the
 * fixture and a successful restore — the fixture the finding's own "Fix"
 * section calls for.
 *
 * Every refusal test also leaves the restore engine and the safety archiver
 * as bare `Mockery::mock()` doubles with **no** `shouldReceive()` at all — not
 * even `shouldNotReceive()`. A Mockery mock with zero expectations throws
 * `\Mockery\Exception\BadMethodCallException` (a LogicException, so it is
 * never confused with the RuntimeException the real refusal throws) the
 * instant any method on it is touched. If the signature gate is ever deleted
 * or narrowed, execution keeps going — past the (passing) scope gate, into
 * the safety archive and then the restore — and hits one of these bare
 * doubles immediately, failing loudly and for the right reason rather than
 * quietly succeeding.
 *
 * Every refusal is asserted on the captured WP_CLI::error() message text, not
 * merely on "some RuntimeException was thrown" — the exact gap the finding
 * names ("366 expectException calls against 61 message assertions"). The
 * message is captured via `andReturnUsing()` into a local variable and
 * compared with a real PHPUnit assertion after the command call, inside a
 * try/catch that re-throws AssertionFailedError first — PHPUnit's own
 * AssertionFailedError extends RuntimeException, so a bare
 * `catch ( RuntimeException $e )` would otherwise swallow a failed assertion
 * as if it were the expected refusal.
 */
final class ImportCommandTest extends TestCase {


	/**
	 * A real temporary archive file used as the import source.
	 *
	 * ImportCommand opens this with fopen() directly, so it must be a real,
	 * readable file, not a mock — Mockery cannot intercept stream resources.
	 *
	 * @var string|null
	 */
	private ?string $temp_archive_path = null;

	/**
	 * Create a real, readable temp archive path for the import source.
	 *
	 * The file itself is written per-test (each test needs a different
	 * signing/scope shape), so setUp only reserves the path.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->temp_archive_path = sys_get_temp_dir() . '/pontifex-import-adr0012-test-' . uniqid( '', true ) . '.wpmig';
	}

	/**
	 * Remove the temp archive file (and any sibling key files) the test created.
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
	 * Write a minimal, valid, content-only archive, optionally signed.
	 *
	 * Content-only (not the two-argument legacy shape the existing
	 * InvokeBranchesTest.php fixtures use) so assert_scope_permits_restore()
	 * always accepts it on the default restore path: the signature gate is
	 * left as the only statement able to refuse.
	 *
	 * @param string              $path    Destination path.
	 * @param SigningContext|null $signing Signing context, or null for an unsigned archive.
	 * @return void
	 */
	private static function write_content_only_archive_to( string $path, ?SigningContext $signing ): void {
		$provenance = new Provenance(
			'6.6.1',
			'8.2.10',
			'https://example.test',
			'utf8mb4',
			'utf8mb4_unicode_520_ci',
			new ExporterInfo( 'pontifex', '0.3.0' ),
			new DateTimeImmutable( '2026-06-23T10:00:00+00:00', new DateTimeZone( 'UTC' ) ),
			null,
			'wp_',
			Scope::content_only( array() )
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Opening a temp source file for the command to read; WP_Filesystem is not bootstrapped in unit tests.
		$destination = fopen( $path, 'w+b' );
		$writer      = new ArchiveWriter( new EntryWriter( CodecRegistry::with_defaults() ), new FooterWriter() );
		$writer->write_archive( $provenance, array(), $destination, null, null, $signing );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the test's own handle.
		fclose( $destination );
	}

	/**
	 * Perform the signature-strip attack on an archive file in place.
	 *
	 * Clears the header's signed flag and truncates the trailing signature
	 * block — after which the archive is a well-formed UNSIGNED one, because
	 * none of the unkeyed hashes covers the header flags. This is the exact
	 * downgrade ADR 0012 closes, mirroring the helper of the same name in
	 * ImportCommand/InvokeBranchesTest.php (duplicated here rather than
	 * shared, since this file is deliberately independent of that one).
	 *
	 * @param string $path Absolute path of the signed archive to strip.
	 * @return void
	 */
	private static function strip_signature( string $path ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test fixture manipulation of a temp archive.
		$bytes = (string) file_get_contents( $path );
		$flags = ByteOrder::unpack_uint32( substr( $bytes, 12, 4 ) );
		$bytes = substr_replace( $bytes, ByteOrder::pack_uint32( $flags & ~Header::FLAG_SIGNED ), 12, 4 );
		$bytes = substr( $bytes, 0, -ArchiveSignature::SIZE );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture manipulation of a temp archive.
		file_put_contents( $path, $bytes );
	}

	/**
	 * Build an Environment mock that answers ABSPATH/WP_CONTENT_DIR with no pinned key.
	 *
	 * @return Environment&\Mockery\MockInterface
	 */
	private function build_environment_mock() {
		$mock = Mockery::mock( Environment::class );
		$mock->shouldReceive( 'is_constant_defined' )->with( 'ABSPATH' )->andReturn( true );
		$mock->shouldReceive( 'constant_value' )->with( 'ABSPATH' )->andReturn( '/var/www/html' );
		$mock->shouldReceive( 'is_constant_defined' )->with( 'WP_CONTENT_DIR' )->andReturn( true );
		$mock->shouldReceive( 'constant_value' )->with( 'WP_CONTENT_DIR' )->andReturn( '/var/www/html/wp-content' );
		$mock->shouldReceive( 'is_constant_defined' )->with( 'PONTIFEX_PUBLIC_KEY' )->andReturn( false );
		return $mock;
	}

	/**
	 * Build a permissive WordPressContext mock for a real (non-dry-run) import.
	 *
	 * Permissive rather than a `shouldNotReceive` fence: these tests rely on
	 * the bare restore-runner/safety-archiver doubles (see the class docblock)
	 * to catch a bypassed gate, so the lock/counters seams here just need to
	 * stay out of the way when a refusal fires correctly (before they are
	 * ever touched) and to behave normally when a test's fixture is meant to
	 * proceed all the way to a real restore.
	 *
	 * @return WordPressContext&\Mockery\MockInterface
	 */
	private function build_wordpress_context_mock() {
		$mock = Mockery::mock( WordPressContext::class );
		$mock->shouldReceive( 'option_value' )->andReturn( array() );
		$mock->shouldReceive( 'save_option' )->zeroOrMoreTimes();
		$mock->shouldReceive( 'format_size' )->andReturn( '0 B' );
		$mock->shouldReceive( 'acquire_named_lock' )->andReturn( true );
		$mock->shouldReceive( 'release_named_lock' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		return $mock;
	}

	/**
	 * Case 1a: a never-signed archive is refused under a trusted key.
	 *
	 * ADR 0012: supplying a key declares "only signed archives are trusted".
	 * The unkeyed integrity hashes detect corruption, not tampering, so an
	 * unsigned archive under a trusted key must refuse before any write.
	 *
	 * @return void
	 */
	public function test_import_refuses_a_never_signed_archive_when_a_key_is_supplied(): void {
		self::write_content_only_archive_to( $this->temp_archive_path, null );
		SigningKeys::write_keypair( SigningKeypair::generate(), $this->temp_archive_path . '.key', $this->temp_archive_path . '.pub' );

		$this->assert_import_is_refused_by_the_signature_gate(
			array(
				'yes'        => true,
				'public-key' => $this->temp_archive_path . '.pub',
			),
			'archive is NOT signed'
		);
	}

	/**
	 * Case 1b: the strip attack — a signed archive with its signature removed is refused.
	 *
	 * The exact downgrade the audit found: clear the header's signed flag and
	 * truncate the trailing signature block, and the archive presents as a
	 * well-formed UNSIGNED one. Under a trusted key it must refuse with the
	 * same "looks exactly like never-signed" message as case 1a.
	 *
	 * @return void
	 */
	public function test_import_refuses_a_stripped_signature_when_a_key_is_supplied(): void {
		$keypair = SigningKeypair::generate();
		self::write_content_only_archive_to( $this->temp_archive_path, SigningContext::from_keypair( $keypair ) );
		SigningKeys::write_keypair( $keypair, $this->temp_archive_path . '.key', $this->temp_archive_path . '.pub' );
		self::strip_signature( $this->temp_archive_path );

		$this->assert_import_is_refused_by_the_signature_gate(
			array(
				'yes'        => true,
				'public-key' => $this->temp_archive_path . '.pub',
			),
			'archive is NOT signed'
		);
	}

	/**
	 * Case 2: a signed archive whose signature does not verify is refused, with a distinct message.
	 *
	 * The signature gate runs before the safety archive and the restore, so a
	 * bad signature must reach neither: nothing is written.
	 *
	 * @return void
	 */
	public function test_import_refuses_a_signed_archive_with_a_bad_signature(): void {
		$keypair = SigningKeypair::generate();
		self::write_content_only_archive_to( $this->temp_archive_path, SigningContext::from_keypair( $keypair ) );
		// A different keypair's public key — so the signature will not verify.
		SigningKeys::write_keypair( SigningKeypair::generate(), $this->temp_archive_path . '.key', $this->temp_archive_path . '.pub' );

		$this->assert_import_is_refused_by_the_signature_gate(
			array(
				'yes'        => true,
				'public-key' => $this->temp_archive_path . '.pub',
			),
			'did not verify against the supplied public key'
		);
	}

	/**
	 * Case 3: a signed archive with no key supplied proceeds, with a warning.
	 *
	 * ADR 0012 does not make signing itself mandatory — only enforcement,
	 * once a trusted key exists. Without one, a signed archive still
	 * restores; the operator is warned the signature was not verified.
	 * This is the counterweight to cases 1-2: an over-broad "always refuse"
	 * gate would fail here.
	 *
	 * @return void
	 */
	public function test_import_a_signed_archive_with_no_key_proceeds_with_a_warning(): void {
		$keypair = SigningKeypair::generate();
		self::write_content_only_archive_to( $this->temp_archive_path, SigningContext::from_keypair( $keypair ) );
		// No key file is written at all: no --public-key, and no PONTIFEX_PUBLIC_KEY pin.

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'restore' )->once();

		// A bare double: --no-rollback-archive means create() must never be
		// touched, and an untouched Mockery mock throws immediately if it is.
		$safety_archiver = Mockery::mock( SafetyArchiverInterface::class );

		$captured_warning = null;
		$wp_cli           = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldNotReceive( 'error' );
		$wp_cli->shouldReceive( 'warning' )->once()->andReturnUsing(
			function ( string $message ) use ( &$captured_warning ): void {
				$captured_warning = $message;
			}
		);

		$command = new ImportCommand(
			$this->build_environment_mock(),
			$this->build_wordpress_context_mock(),
			$restore_runner,
			new NullLogger(),
			new NullProgressBar(),
			$safety_archiver
		);

		$command(
			array( $this->temp_archive_path ),
			array(
				'yes'              => true,
				'rollback-archive' => false,
			)
		);

		$this->assertNotNull( $captured_warning, 'The operator must be warned that an unverified signature was accepted.' );
		$this->assertStringContainsString( 'signature was NOT verified', $captured_warning );
	}

	/**
	 * Case 4: an unsigned archive with no key proceeds silently.
	 *
	 * The everyday case: no signing, no pin, no flag. Nothing about the
	 * signature gate should so much as log a warning here.
	 *
	 * @return void
	 */
	public function test_import_an_unsigned_archive_with_no_key_proceeds_silently(): void {
		self::write_content_only_archive_to( $this->temp_archive_path, null );

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'restore' )->once();

		// A bare double: --no-rollback-archive means create() must never be
		// touched, and an untouched Mockery mock throws immediately if it is.
		$safety_archiver = Mockery::mock( SafetyArchiverInterface::class );

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldNotReceive( 'error' );
		$wp_cli->shouldNotReceive( 'warning' );

		$command = new ImportCommand(
			$this->build_environment_mock(),
			$this->build_wordpress_context_mock(),
			$restore_runner,
			new NullLogger(),
			new NullProgressBar(),
			$safety_archiver
		);

		$command(
			array( $this->temp_archive_path ),
			array(
				'yes'              => true,
				'rollback-archive' => false,
			)
		);

		$this->assertFileExists( $this->temp_archive_path, 'The restore must have proceeded (Mockery verifies restore() was called exactly once).' );
	}

	/**
	 * Shared body for the two refusal cases (1a/1b) and case 2: run the import
	 * and assert it was refused by WP_CLI::error() carrying the given message.
	 *
	 * The restore engine and the safety archiver are left as bare Mockery
	 * doubles with no expectations at all (see the class docblock): if the
	 * signature gate is bypassed, execution reaches one of them and Mockery's
	 * own BadMethodCallException (a LogicException) surfaces uncaught,
	 * distinguishable from the RuntimeException this method expects.
	 *
	 * @param array<string, string|bool> $associative_args      The CLI flags to invoke with.
	 * @param string                     $expected_message_part A substring that must appear in the captured WP_CLI::error() message.
	 * @return void
	 * @throws AssertionFailedError If the import is not refused, or refuses for a different reason than the mocked WP_CLI::error().
	 */
	private function assert_import_is_refused_by_the_signature_gate( array $associative_args, string $expected_message_part ): void {
		$restore_runner  = Mockery::mock( RestoreRunnerInterface::class );
		$safety_archiver = Mockery::mock( SafetyArchiverInterface::class );

		$captured_message = null;
		$wp_cli           = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'warning' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'error' )->once()->andReturnUsing(
			function ( string $message ) use ( &$captured_message ): void {
				$captured_message = $message;
				throw new RuntimeException( 'test-forced-halt' );
			}
		);

		$command = new ImportCommand(
			$this->build_environment_mock(),
			$this->build_wordpress_context_mock(),
			$restore_runner,
			new NullLogger(),
			new NullProgressBar(),
			$safety_archiver
		);

		try {
			$command( array( $this->temp_archive_path ), $associative_args );
			$this->fail( 'The import must be refused before any write.' );
		} catch ( AssertionFailedError $bug ) {
			// PHPUnit's own AssertionFailedError extends RuntimeException; re-throw
			// it first so the catch below can never mistake a failed assertion
			// (e.g. the fail() above, or a Mockery bad-method-call surfacing here)
			// for the expected refusal.
			throw $bug;
		} catch ( RuntimeException $error ) {
			$this->assertSame(
				'test-forced-halt',
				$error->getMessage(),
				'The exception must be the one thrown by the mocked WP_CLI::error(), not some other RuntimeException.'
			);
		}

		$this->assertNotNull( $captured_message, 'WP_CLI::error() must have been called exactly once.' );
		$this->assertStringContainsString( $expected_message_part, $captured_message );
	}
}
