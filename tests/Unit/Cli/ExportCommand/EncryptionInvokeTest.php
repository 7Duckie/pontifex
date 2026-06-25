<?php
/**
 * __invoke encryption-wiring tests for ExportCommand.
 *
 * @package Pontifex\Tests\Unit\Cli\ExportCommand
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Cli\ExportCommand;

use Mockery;
use RuntimeException;
use Pontifex\Archive\Format\Footer;
use Pontifex\Archive\Reader\ArchiveReader;
use Pontifex\Cli\ExportCommand;
use Pontifex\Cli\NullProgressBar;
use Pontifex\Environment\Environment;
use Pontifex\Manifest\ManifestBuilderInterface;
use Pontifex\Tests\TestCase;
use Pontifex\Tests\Unit\Cli\Fakes\FakePassphraseSource;
use Pontifex\WordPress\WordPressContext;
use Psr\Log\NullLogger;

/**
 * Behavioural coverage of ExportCommand's encryption flags.
 *
 * The crypto round trip itself is proven exhaustively at the archive layer
 * (see {@see \Pontifex\Tests\Unit\Archive\Writer\EncryptedArchiveRoundTripTest}).
 * What these tests pin down is the command wiring the helper tests cannot see:
 * that --encrypt and --passphrase-stdin reach the writer with an encryption
 * context (so the written archive's header carries the encrypted flag and a
 * real salt), that --encrypt warns about the absence of passphrase recovery
 * while --passphrase-stdin stays silent for pipes, and that the default path
 * writes a plain archive. A {@see FakePassphraseSource} supplies the passphrase
 * so no terminal or piped STDIN is needed.
 */
final class EncryptionInvokeTest extends TestCase {

	/**
	 * A passphrase above the minimum length, used across the encrypting tests.
	 *
	 * @var string
	 */
	private const PASSPHRASE = 'a-good-passphrase';

	/**
	 * A real temporary file path used as the export destination.
	 *
	 * Reserved in setUp and removed in tearDown. Real (not mocked) because
	 * ExportCommand fopen()s it directly and the test reopens it to inspect
	 * the archive header.
	 *
	 * @var string|null
	 */
	private ?string $temp_output_path = null;

	/**
	 * Reserve a unique destination path for the export output.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->temp_output_path = sys_get_temp_dir() . '/pontifex-encrypt-invoke-' . uniqid( '', true ) . '.wpmig';
	}

	/**
	 * Remove any output file the test left behind.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ( null !== $this->temp_output_path && file_exists( $this->temp_output_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test-only cleanup of a file the test itself created in sys_get_temp_dir(); WordPress filesystem helpers are not bootstrapped in unit tests.
			unlink( $this->temp_output_path );
		}
		$this->temp_output_path = null;
		parent::tearDown();
	}

	/**
	 * --encrypt collects a confirmed passphrase, warns about recovery, and encrypts.
	 *
	 * @return void
	 */
	public function test_invoke_with_encrypt_writes_an_encrypted_archive(): void {
		$source = new FakePassphraseSource( array( self::PASSPHRASE, self::PASSPHRASE ) );

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'warning' )->once();
		$wp_cli->shouldReceive( 'error' )->never();
		$wp_cli->shouldNotReceive( 'confirm' );

		$command = new ExportCommand(
			$this->build_environment_mock(),
			$this->build_wordpress_context_mock(),
			$this->build_manifest_builder_mock_returning_empty(),
			new NullLogger(),
			new NullProgressBar(),
			$source
		);

		$command(
			array(),
			array(
				'output'  => $this->temp_output_path,
				'yes'     => true,
				'encrypt' => true,
			)
		);

		$this->assert_archive_encryption( true );
	}

	/**
	 * --passphrase-stdin encrypts from a piped line and prints no recovery warning.
	 *
	 * @return void
	 */
	public function test_invoke_with_passphrase_stdin_encrypts_without_a_recovery_warning(): void {
		$source = new FakePassphraseSource( array(), self::PASSPHRASE );

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'error' )->never();
		// A piped passphrase is non-interactive: the recovery warning is suppressed.
		$wp_cli->shouldNotReceive( 'warning' );
		$wp_cli->shouldNotReceive( 'confirm' );

		$command = new ExportCommand(
			$this->build_environment_mock(),
			$this->build_wordpress_context_mock(),
			$this->build_manifest_builder_mock_returning_empty(),
			new NullLogger(),
			new NullProgressBar(),
			$source
		);

		$command(
			array(),
			array(
				'output'           => $this->temp_output_path,
				'yes'              => true,
				'passphrase-stdin' => true,
			)
		);

		$this->assert_archive_encryption( true );
	}

	/**
	 * With no encryption flag the archive is written in the clear.
	 *
	 * @return void
	 */
	public function test_invoke_without_encryption_writes_a_plain_archive(): void {
		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'error' )->never();
		$wp_cli->shouldNotReceive( 'warning' );
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
				'output' => $this->temp_output_path,
				'yes'    => true,
			)
		);

		$this->assert_archive_encryption( false );
	}

	// -------------------------------------------------------------------------
	// Assertion and mock helpers.
	// -------------------------------------------------------------------------

	/**
	 * Reopen the written archive and assert its encryption state from the header and footer.
	 *
	 * @param bool $expected_encrypted True if the archive should be encrypted.
	 * @return void
	 * @throws RuntimeException If the archive the command wrote cannot be reopened.
	 */
	private function assert_archive_encryption( bool $expected_encrypted ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Reopening the archive the command just wrote to inspect its header; WP_Filesystem is not bootstrapped in unit tests.
		$source = fopen( (string) $this->temp_output_path, 'rb' );
		if ( false === $source ) {
			throw new RuntimeException( 'Could not reopen the exported archive for inspection.' );
		}

		$reader = new ArchiveReader( $source );
		$this->assertSame( $expected_encrypted, $reader->header()->is_encrypted() );

		if ( $expected_encrypted ) {
			$this->assertNotSame( Footer::ZERO_SALT, $reader->footer()->argon2id_salt(), 'An encrypted archive must carry a real salt.' );
		} else {
			$this->assertSame( Footer::ZERO_SALT, $reader->footer()->argon2id_salt(), 'A plain archive must carry the zero salt.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the test's own read handle.
		fclose( $source );
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
	 * An empty manifest still produces a valid archive; the encrypted flag and
	 * salt come from the encryption context, not the entries, so an empty
	 * archive is enough to assert the wiring.
	 *
	 * @return ManifestBuilderInterface&\Mockery\MockInterface
	 */
	private function build_manifest_builder_mock_returning_empty() {
		$mock = Mockery::mock( ManifestBuilderInterface::class );
		$mock->shouldReceive( 'build' )->once()->andReturn( \Pontifex\Manifest\ManifestStream::from_plans( array() ) );
		return $mock;
	}
}
