<?php
/**
 * __invoke signing-wiring tests for ExportCommand.
 *
 * @package Pontifex\Tests\Unit\Cli\ExportCommand
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Cli\ExportCommand;

use Mockery;
use RuntimeException;
use Pontifex\Archive\Crypto\SigningKeypair;
use Pontifex\Archive\Reader\ArchiveReader;
use Pontifex\Cli\ExportCommand;
use Pontifex\Cli\NullProgressBar;
use Pontifex\Cli\SigningKeys;
use Pontifex\Environment\Environment;
use Pontifex\Manifest\ManifestBuilderInterface;
use Pontifex\Tests\TestCase;
use Pontifex\WordPress\WordPressContext;
use Psr\Log\NullLogger;

/**
 * Behavioural coverage of ExportCommand's --sign wiring.
 *
 * The signing round trip is proven engine-level elsewhere
 * ({@see \Pontifex\Tests\Unit\Archive\Writer\SignedArchiveRoundTripTest}); what
 * these tests pin down is the command wiring: --sign with --signing-key loads
 * the key, signs during the write, and produces an archive that verifies
 * against the matching public key — while the default path stays unsigned. The
 * key file is written with the real {@see SigningKeys} helper.
 */
final class SigningInvokeTest extends TestCase {

	/**
	 * A scratch directory for the output archive and key files.
	 *
	 * @var string
	 */
	private string $dir = '';

	/**
	 * The keypair the test signs with.
	 *
	 * @var SigningKeypair|null
	 */
	private ?SigningKeypair $keypair = null;

	/**
	 * Create the scratch directory and a signing keypair on disk.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/pontifex-sign-invoke-' . uniqid( '', true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating a scratch directory for the output archive and key files; WP_Filesystem is not bootstrapped in unit tests.
		mkdir( $this->dir );
		$this->keypair = SigningKeypair::generate();
		SigningKeys::write_keypair( $this->keypair, $this->dir . '/k.key', $this->dir . '/k.pub' );
	}

	/**
	 * Remove the scratch directory and its contents.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ( '' !== $this->dir && is_dir( $this->dir ) ) {
			foreach ( (array) scandir( $this->dir ) as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Test cleanup of files the test created.
				@unlink( $this->dir . '/' . $entry );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.PHP.NoSilencedErrors.Discouraged -- Test cleanup of the scratch directory.
			@rmdir( $this->dir );
		}
		$this->keypair = null;
		parent::tearDown();
	}

	/**
	 * --sign with --signing-key writes an archive that verifies against the public key.
	 *
	 * @return void
	 */
	public function test_invoke_with_sign_writes_a_verifiable_signed_archive(): void {
		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'warning' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'error' )->never();
		$wp_cli->shouldNotReceive( 'confirm' );

		$command = new ExportCommand(
			$this->build_environment_mock(),
			$this->build_wordpress_context_mock(),
			$this->build_manifest_builder_mock_returning_empty(),
			new NullLogger(),
			new NullProgressBar()
		);

		$command(
			array(),
			array(
				'output'      => $this->dir . '/out.wpmig',
				'yes'         => true,
				'sign'        => true,
				'signing-key' => $this->dir . '/k.key',
			)
		);

		$reader = $this->open_reader( $this->dir . '/out.wpmig' );
		$this->assertTrue( $reader->header()->is_signed() );
		$this->assertNotNull( $this->keypair );
		$this->assertTrue( $reader->verify_signature( $this->keypair->public_key() ) );
	}

	/**
	 * Without --sign the archive is unsigned.
	 *
	 * @return void
	 */
	public function test_invoke_without_sign_writes_an_unsigned_archive(): void {
		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'error' )->never();
		$wp_cli->shouldNotReceive( 'confirm' );

		$command = new ExportCommand(
			$this->build_environment_mock(),
			$this->build_wordpress_context_mock(),
			$this->build_manifest_builder_mock_returning_empty(),
			new NullLogger(),
			new NullProgressBar()
		);

		$command(
			array(),
			array(
				'output' => $this->dir . '/out.wpmig',
				'yes'    => true,
			)
		);

		$reader = $this->open_reader( $this->dir . '/out.wpmig' );
		$this->assertFalse( $reader->header()->is_signed() );
		$this->assertNull( $reader->signature() );
	}

	/**
	 * --sign without --signing-key is refused.
	 *
	 * @return void
	 */
	public function test_invoke_sign_without_signing_key_errors(): void {
		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'warning' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'error' )->once()->andThrow( new RuntimeException( 'halt' ) );

		// The command errors out in the signing step, before the manifest is built,
		// so build() is never called — a bare mock with no expectation.
		$command = new ExportCommand(
			$this->build_environment_mock(),
			$this->build_wordpress_context_mock(),
			Mockery::mock( ManifestBuilderInterface::class ),
			new NullLogger(),
			new NullProgressBar()
		);

		$this->expectException( RuntimeException::class );

		$command(
			array(),
			array(
				'output' => $this->dir . '/out.wpmig',
				'yes'    => true,
				'sign'   => true,
			)
		);
	}

	/**
	 * Open an ArchiveReader on a written archive file.
	 *
	 * @param string $path The archive path.
	 * @return ArchiveReader The reader.
	 * @throws RuntimeException If the archive cannot be reopened.
	 */
	private function open_reader( string $path ): ArchiveReader {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Reopening the archive the command wrote to inspect it; WP_Filesystem is not bootstrapped in unit tests.
		$source = fopen( $path, 'rb' );
		if ( false === $source ) {
			throw new RuntimeException( 'Could not reopen the exported archive for inspection.' );
		}
		return new ArchiveReader( $source );
	}

	/**
	 * Build an Environment mock answering the calls __invoke makes on the happy path.
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
		$mock->shouldReceive( 'php_version' )->andReturn( '8.2.10' );
		return $mock;
	}

	/**
	 * Build a WordPressContext mock answering the calls __invoke makes on the happy path.
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
	 * @return ManifestBuilderInterface&\Mockery\MockInterface
	 */
	private function build_manifest_builder_mock_returning_empty() {
		$mock = Mockery::mock( ManifestBuilderInterface::class );
		$mock->shouldReceive( 'build' )->once()->andReturn( array() );
		return $mock;
	}
}
