<?php
/**
 * Surgical __invoke branch tests for VerifyCommand.
 *
 * @package Pontifex\Tests\Unit\Cli\VerifyCommand
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Cli\VerifyCommand;

use DateTimeImmutable;
use DateTimeZone;
use Mockery;
use Pontifex\Archive\Codec\CodecRegistry;
use Pontifex\Archive\Codec\RawCodec;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Format\ExporterInfo;
use Pontifex\Archive\Format\Provenance;
use Pontifex\Archive\Crypto\SigningContext;
use Pontifex\Archive\Crypto\SigningKeypair;
use Pontifex\Archive\Reader\EntryReader;
use Pontifex\Archive\Writer\ArchiveWriter;
use Pontifex\Archive\Writer\EntryPlan;
use Pontifex\Archive\Writer\EntryWriter;
use Pontifex\Archive\Writer\FooterWriter;
use Pontifex\Cli\NullProgressBar;
use Pontifex\Cli\SigningKeys;
use Pontifex\Cli\VerifyCommand;
use Pontifex\Environment\Environment;
use Pontifex\Exception\ArchiveNotTrustworthy;
use Pontifex\Exception\HostCannotComply;
use Pontifex\Restore\DatabaseWriter;
use Pontifex\Restore\FileWriter;
use Pontifex\Restore\RestoreRunner;
use Pontifex\Restore\RestoreRunnerInterface;
use Pontifex\Tests\TestCase;
use Pontifex\Tests\Unit\Manifest\Fakes\FakeDbAdapter;
use Pontifex\WordPress\WordPressContext;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Behavioural coverage of the genuine __invoke branches.
 *
 * As with ImportCommand, most orchestration is not worth a behavioural
 * __invoke test. The branches that genuinely earn a surgical unit test are
 * verify's defining control-flow facts, all distinct from import:
 *
 *  1. A sound archive logs success, prints its verdict, and does NOT halt —
 *     the command exits 0 by returning normally.
 *  2. A broken archive does NOT propagate the engine's exception; it swallows
 *     it, logs an error, and halts non-zero so a script sees a failing exit
 *     code. Import handles a failure the same way, with a verdict per kind of
 *     refusal; the difference is that verify has no site state to recover.
 *  3. Verify drives the engine's verify() walk, never restore() — the
 *     "writes nothing" contract.
 *
 * The restore engine is injected as a RestoreRunnerInterface mock — the
 * interface that exists precisely so this final-class engine can be faked.
 * With a runner injected, the default wiring (build_default_restore_runner)
 * is never reached, so FileWriter, DatabaseWriter, WpdbAdapter and the
 * Environment/WordPressContext seams are not exercised here. The --list path
 * and its WP-CLI formatter are exercised by the wp-env smoke, since they
 * need the WP-CLI runtime; the pure row-building logic is unit-tested in
 * HelperMethodsTest.
 */
final class InvokeBranchesTest extends TestCase {


	/**
	 * A real temporary archive file used as the verify source.
	 *
	 * Created in setUp as a real (empty, unsigned) archive — VerifyCommand reads
	 * the header to check for a signature, so the source must parse as one — and
	 * removed in tearDown. Real path, not mocked, because VerifyCommand calls
	 * fopen() against it directly and Mockery cannot intercept stream resources.
	 *
	 * @var string|null
	 */
	private ?string $temp_archive_path = null;

	/**
	 * Create a real, readable temp archive file for the verify source.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->temp_archive_path = sys_get_temp_dir() . '/pontifex-verify-invoke-test-' . uniqid( '', true ) . '.wpmig';
		// A real (empty, unsigned) archive: VerifyCommand now reads the header to
		// check for a signature, so the source must parse as one.
		self::write_unsigned_archive( $this->temp_archive_path );
	}

	/**
	 * Write a minimal, valid, unsigned archive to the given path.
	 *
	 * @param string $path Destination path.
	 * @return void
	 */
	private static function write_unsigned_archive( string $path ): void {
		self::write_archive_to( $path, null );
	}

	/**
	 * Write a minimal, valid archive to the given path, optionally signed.
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
	 * Remove the temp archive file the test created.
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
	 * A sound archive logs success, never logs an error, and never halts.
	 *
	 * The verify() call returns cleanly, so the command must report the sound
	 * verdict and exit 0 by returning — a regression that halted on success,
	 * or logged an error, would fail here.
	 *
	 * @return void
	 */
	public function test_invoke_sound_archive_logs_info_and_does_not_halt(): void {
		$environment = Mockery::mock( Environment::class );
		// No pinned key by default; pin-specific tests build their own mock.
		$environment->shouldReceive( 'is_constant_defined' )->with( 'PONTIFEX_PUBLIC_KEY' )->andReturn( false );
		$wordpress_context = Mockery::mock( WordPressContext::class );

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'verify' )->once();
		$restore_runner->shouldNotReceive( 'restore' );

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldNotReceive( 'halt' );

		$logger = Mockery::mock( LoggerInterface::class );
		$logger->shouldReceive( 'info' )->atLeast()->once();
		$logger->shouldReceive( 'error' )->never();

		$command = new VerifyCommand( $environment, $wordpress_context, $restore_runner, $logger, new NullProgressBar() );

		$command(
			array( $this->temp_archive_path ),
			array()
		);

		$this->assertFileExists(
			$this->temp_archive_path,
			'VerifyCommand should have run to completion on a sound archive.'
		);
	}

	/**
	 * A signed archive with the matching public key verifies and stays sound.
	 *
	 * @return void
	 */
	public function test_invoke_verifies_a_good_signature_and_stays_sound(): void {
		$keypair = SigningKeypair::generate();
		self::write_archive_to( $this->temp_archive_path, SigningContext::from_keypair( $keypair ) );
		SigningKeys::write_keypair( $keypair, $this->temp_archive_path . '.key', $this->temp_archive_path . '.pub' );

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'verify' )->once();

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->atLeast()->once();
		$wp_cli->shouldReceive( 'warning' )->never();
		$wp_cli->shouldNotReceive( 'halt' );

		$command = new VerifyCommand( Mockery::mock( Environment::class ), Mockery::mock( WordPressContext::class ), $restore_runner, new NullLogger(), new NullProgressBar() );

		$command(
			array( $this->temp_archive_path ),
			array( 'public-key' => $this->temp_archive_path . '.pub' )
		);

		$this->assertFileExists( $this->temp_archive_path, 'A good signature must leave the archive sound (halt is asserted never).' );
	}

	/**
	 * An unsigned archive is reported BROKEN when a trusted key is supplied.
	 *
	 * ADR 0012: a stripped signature yields a well-formed unsigned archive, so
	 * under a trusted key "unsigned" must be treated as tampering, not merely
	 * warned about.
	 *
	 * @return void
	 */
	public function test_invoke_reports_an_unsigned_archive_broken_when_a_key_is_supplied(): void {
		self::write_archive_to( $this->temp_archive_path, null );
		SigningKeys::write_keypair( SigningKeypair::generate(), $this->temp_archive_path . '.key', $this->temp_archive_path . '.pub' );

		$environment       = Mockery::mock( Environment::class );
		$wordpress_context = Mockery::mock( WordPressContext::class );

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'verify' )->zeroOrMoreTimes();
		$restore_runner->shouldNotReceive( 'restore' );

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'warning' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'error' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'halt' )->once()->with( 1 )->andThrow( new RuntimeException( 'halt-1' ) );

		$command = new VerifyCommand( $environment, $wordpress_context, $restore_runner, new NullLogger(), new NullProgressBar() );

		$this->expectException( RuntimeException::class );

		$command(
			array( $this->temp_archive_path ),
			array( 'public-key' => $this->temp_archive_path . '.pub' )
		);
	}

	/**
	 * A signed archive verified against the wrong public key is reported broken.
	 *
	 * @return void
	 */
	public function test_invoke_rejects_a_bad_signature_as_broken(): void {
		$keypair = SigningKeypair::generate();
		self::write_archive_to( $this->temp_archive_path, SigningContext::from_keypair( $keypair ) );
		// A different keypair's public key — so the signature will not verify.
		SigningKeys::write_keypair( SigningKeypair::generate(), $this->temp_archive_path . '.key', $this->temp_archive_path . '.pub' );

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'verify' )->once();

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'warning' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'halt' )->once()->with( 1 );

		$command = new VerifyCommand( Mockery::mock( Environment::class ), Mockery::mock( WordPressContext::class ), $restore_runner, new NullLogger(), new NullProgressBar() );

		$command(
			array( $this->temp_archive_path ),
			array( 'public-key' => $this->temp_archive_path . '.pub' )
		);

		$this->assertFileExists( $this->temp_archive_path, 'A bad signature must halt(1) as a broken verdict (halt is mocked).' );
	}

	/**
	 * A broken archive is not re-thrown: the command swallows it and halts non-zero.
	 *
	 * Unlike import, verify converts a failure into a verdict and an exit
	 * code rather than letting the exception reach WP-CLI. It must log the
	 * error, print the broken verdict, and call WP_CLI::halt(1).
	 *
	 * @return void
	 */
	public function test_invoke_broken_archive_halts_nonzero_and_logs_error(): void {
		$environment = Mockery::mock( Environment::class );
		// No pinned key by default; pin-specific tests build their own mock.
		$environment->shouldReceive( 'is_constant_defined' )->with( 'PONTIFEX_PUBLIC_KEY' )->andReturn( false );
		$wordpress_context = Mockery::mock( WordPressContext::class );

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner
			->shouldReceive( 'verify' )
			->once()
			->andThrow( new RuntimeException( 'entry 3: stored hash does not match computed hash' ) );

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'halt' )->once()->with( 1 );

		$logger = Mockery::mock( LoggerInterface::class );
		$logger->shouldReceive( 'info' )->zeroOrMoreTimes();
		$logger->shouldReceive( 'error' )->once();

		$command = new VerifyCommand( $environment, $wordpress_context, $restore_runner, $logger, new NullProgressBar() );

		// No expectException: the command must NOT re-throw. It returns after
		// halting, and Mockery verifies halt(1) and error() were called.
		$command(
			array( $this->temp_archive_path ),
			array()
		);

		$this->assertFileExists(
			$this->temp_archive_path,
			'VerifyCommand should swallow the failure and run to completion (halt is mocked).'
		);
	}

	/**
	 * Verify drives the engine's verify() walk and never its restore() walk.
	 *
	 * The "writes nothing" contract: a regression that called restore()
	 * would write to the destination. This guards that verify() — and only
	 * verify() — is invoked.
	 *
	 * @return void
	 */
	public function test_invoke_calls_verify_never_restore(): void {
		$environment = Mockery::mock( Environment::class );
		// No pinned key by default; pin-specific tests build their own mock.
		$environment->shouldReceive( 'is_constant_defined' )->with( 'PONTIFEX_PUBLIC_KEY' )->andReturn( false );
		$wordpress_context = Mockery::mock( WordPressContext::class );

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'verify' )->once();
		$restore_runner->shouldNotReceive( 'restore' );

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'halt' )->zeroOrMoreTimes();

		$command = new VerifyCommand( $environment, $wordpress_context, $restore_runner, new NullLogger(), new NullProgressBar() );

		$command(
			array( $this->temp_archive_path ),
			array()
		);

		$this->assertFileExists(
			$this->temp_archive_path,
			'A verify must read the archive without removing or altering it.'
		);
	}

	/**
	 * A HostCannotComply from the verify walk is its own outcome, not broken.
	 *
	 * This host, not the archive, is what stopped the walk — a low
	 * memory_limit unable to buffer a db_chunk it must decode whole is the
	 * case that keeps happening in practice, and WordPress's own default is
	 * 40 MB. Nothing was learned about the archive either way, so it must be
	 * reported as "could not check", never as "broken", and must halt with
	 * exit code 2, not 1 — a script gating on this command needs to be able
	 * to tell "unknown" apart from "bad".
	 *
	 * @return void
	 */
	public function test_invoke_host_cannot_comply_halts_2_and_logs_warning(): void {
		$environment = Mockery::mock( Environment::class );
		$environment->shouldReceive( 'is_constant_defined' )->with( 'PONTIFEX_PUBLIC_KEY' )->andReturn( false );
		$wordpress_context = Mockery::mock( WordPressContext::class );

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner
			->shouldReceive( 'verify' )
			->once()
			->andThrow( new HostCannotComply( 'Entry declares 41943040 decoded bytes, exceeding the 10485760-byte budget for this restore.' ) );

		$captured_message = null;
		$logger           = Mockery::mock( LoggerInterface::class );
		$logger->shouldReceive( 'info' )->zeroOrMoreTimes();
		$logger->shouldReceive( 'error' )->never();
		$logger->shouldReceive( 'warning' )->once()->andReturnUsing(
			function ( string $message, array $context ) use ( &$captured_message ): void {
				$captured_message = $context['exception']->getMessage();
			}
		);

		$captured_logs = array();
		$wp_cli        = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes()->andReturnUsing(
			function ( string $message ) use ( &$captured_logs ): void {
				$captured_logs[] = $message;
			}
		);
		$wp_cli->shouldReceive( 'halt' )->once()->with( 2 );

		$command = new VerifyCommand( $environment, $wordpress_context, $restore_runner, $logger, new NullProgressBar() );

		// No expectException: the command must NOT re-throw. It returns after
		// halting, and Mockery verifies halt(2), warning(), and never error().
		$command(
			array( $this->temp_archive_path ),
			array()
		);

		$this->assertNotNull( $captured_message, 'The HostCannotComply must have been logged with the real exception.' );
		$this->assertStringContainsString( 'budget for this restore', $captured_message );
		$this->assertNotEmpty(
			array_filter( $captured_logs, static fn ( string $line ): bool => false !== strpos( $line, 'Could not check' ) ),
			'The printed verdict must say "Could not check".'
		);
		foreach ( $captured_logs as $line ) {
			$this->assertStringNotContainsStringIgnoringCase( 'broken', $line, 'A could-not-check outcome must never use the word "broken".' );
		}
	}

	/**
	 * A genuine ArchiveNotTrustworthy still halts 1 and is reported broken.
	 *
	 * The new HostCannotComply catch sits ahead of the generic Throwable
	 * catch in __invoke(). ArchiveNotTrustworthy is a sibling type, not a
	 * HostCannotComply subtype, so this pins that it still falls through to
	 * the existing broken path unaffected, rather than being caught by the
	 * new branch.
	 *
	 * @return void
	 */
	public function test_invoke_archive_not_trustworthy_still_halts_1_and_logs_error(): void {
		$environment = Mockery::mock( Environment::class );
		$environment->shouldReceive( 'is_constant_defined' )->with( 'PONTIFEX_PUBLIC_KEY' )->andReturn( false );
		$wordpress_context = Mockery::mock( WordPressContext::class );

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner
			->shouldReceive( 'verify' )
			->once()
			->andThrow( new ArchiveNotTrustworthy( 'Entry hash does not match the bytes on disk; the entry has been tampered with or is corrupt.' ) );

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'halt' )->once()->with( 1 );

		$logger = Mockery::mock( LoggerInterface::class );
		$logger->shouldReceive( 'info' )->zeroOrMoreTimes();
		$logger->shouldReceive( 'warning' )->never();
		$logger->shouldReceive( 'error' )->once();

		$command = new VerifyCommand( $environment, $wordpress_context, $restore_runner, $logger, new NullProgressBar() );

		$command(
			array( $this->temp_archive_path ),
			array()
		);

		$this->assertFileExists(
			$this->temp_archive_path,
			'VerifyCommand should swallow the failure and run to completion (halt is mocked).'
		);
	}

	/**
	 * A sound archive still reports a host finding beside it — the ADR 0023
	 * behaviour this change must not regress.
	 *
	 * A host finding (here: no free disk space for a restore) says nothing
	 * about the archive, so it must not touch the verdict or the exit code:
	 * the archive is still SOUND and the command still returns without
	 * halting; the finding is reported as a warning alongside the sound
	 * verdict via print_restorability(). This is the preflight's own
	 * HostCannotComply, thrown and caught entirely inside
	 * preflight_report() well before __invoke()'s outer try/catch ever sees
	 * it — proving the new catch added there does not swallow it.
	 *
	 * @return void
	 */
	public function test_invoke_sound_archive_still_reports_a_host_finding_beside_it(): void {
		self::write_archive_with_a_file_entry_to( $this->temp_archive_path );

		$destination_root = sys_get_temp_dir() . '/pontifex-verify-host-finding-' . uniqid( '', true );

		$restore_runner = new RestoreRunner(
			new EntryReader( CodecRegistry::with_defaults() ),
			// A 1-byte free-space stub guarantees the preflight's free-space
			// check refuses, matching RestorePreflightTest's own convention for
			// this exact scenario.
			new FileWriter( $destination_root, false, null, static fn(): int => 1 ),
			new DatabaseWriter( new FakeDbAdapter() )
		);

		$environment = Mockery::mock( Environment::class );
		$environment->shouldReceive( 'is_constant_defined' )->with( 'PONTIFEX_PUBLIC_KEY' )->andReturn( false );
		$wordpress_context = Mockery::mock( WordPressContext::class );

		$captured_logs = array();
		$wp_cli        = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes()->andReturnUsing(
			function ( string $message ) use ( &$captured_logs ): void {
				$captured_logs[] = $message;
			}
		);
		$wp_cli->shouldReceive( 'warning' )->once()->with( Mockery::on( static fn ( string $message ): bool => false !== strpos( $message, 'this host could not restore it right now' ) ) );
		$wp_cli->shouldNotReceive( 'halt' );

		$command = new VerifyCommand( $environment, $wordpress_context, $restore_runner, new NullLogger(), new NullProgressBar() );

		$command(
			array( $this->temp_archive_path ),
			array()
		);

		$this->assertNotEmpty(
			array_filter( $captured_logs, static fn ( string $line ): bool => false !== strpos( $line, 'Archive is sound' ) ),
			'The archive must still be reported sound alongside the host finding.'
		);
	}

	/**
	 * Open a php://memory stream, optionally pre-filled and rewound.
	 *
	 * @param string $contents Optional initial contents.
	 * @return resource A readable, seekable in-memory stream.
	 */
	private static function memory_stream( string $contents = '' ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- In-memory test stream, not a filesystem path.
		$stream = fopen( 'php://memory', 'r+b' );
		if ( '' !== $contents ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Writing to an in-memory test stream, not a filesystem path.
			fwrite( $stream, $contents );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Test stream resource, not a filesystem path.
			rewind( $stream );
		}
		return $stream;
	}

	/**
	 * Write a minimal, valid, unsigned archive with one real file entry to the given path.
	 *
	 * Unlike {@see self::write_unsigned_archive()} (an empty archive, enough
	 * for most tests here), this carries a real payload so the free-space
	 * preflight has a non-zero estimate to refuse against.
	 *
	 * @param string $path Destination path.
	 * @return void
	 */
	private static function write_archive_with_a_file_entry_to( string $path ): void {
		$provenance = new Provenance(
			'6.6.1',
			'8.2.10',
			'https://example.test',
			'utf8mb4',
			'utf8mb4_unicode_520_ci',
			new ExporterInfo( 'pontifex', '0.3.0' ),
			new DateTimeImmutable( '2026-06-23T10:00:00+00:00', new DateTimeZone( 'UTC' ) )
		);

		$header = EntryHeader::for_file( 'wp-content/big.txt', 4096, 0o644, 1690000000, 'application/octet-stream', 0 );
		$plan   = new EntryPlan( $header, RawCodec::ID, str_repeat( "\0", EntryWriter::NONCE_SIZE ), self::memory_stream( str_repeat( 'x', 4096 ) ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Opening a temp destination file for the command to read; WP_Filesystem is not bootstrapped in unit tests.
		$destination = fopen( $path, 'w+b' );
		$writer      = new ArchiveWriter( new EntryWriter( CodecRegistry::with_defaults() ), new FooterWriter() );
		$writer->write_archive( $provenance, array( $plan ), $destination );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the test's own handle.
		fclose( $destination );
	}
}
