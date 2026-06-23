<?php
/**
 * __invoke tests for the KeygenCommand.
 *
 * @package Pontifex\Tests\Unit\Cli
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Cli;

use Mockery;
use RuntimeException;
use Pontifex\Archive\Crypto\SigningKeypair;
use Pontifex\Cli\KeygenCommand;
use Pontifex\Cli\SigningKeys;
use Pontifex\Tests\TestCase;

/**
 * Behavioural coverage of {@see KeygenCommand}: it writes a loadable keypair,
 * refuses to overwrite an existing file, and requires both paths.
 *
 * The generated keypair is random, so the assertions check the files round-trip
 * to a consistent keypair (the public file is the public half of the secret
 * file) rather than any fixed bytes.
 */
final class KeygenCommandTest extends TestCase {

	/**
	 * A scratch directory for this test's key files.
	 *
	 * @var string
	 */
	private string $dir = '';

	/**
	 * Create the scratch directory.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/pontifex-keygen-' . uniqid( '', true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating a scratch directory for key-file fixtures; WP_Filesystem is not bootstrapped in unit tests.
		mkdir( $this->dir );
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
		parent::tearDown();
	}

	/**
	 * A path inside the scratch directory.
	 *
	 * @param string $name The file name.
	 * @return string The absolute path.
	 */
	private function path( string $name ): string {
		return $this->dir . '/' . $name;
	}

	/**
	 * The command writes a secret and public file that load back to a consistent keypair.
	 *
	 * @return void
	 */
	public function test_invoke_writes_a_loadable_keypair(): void {
		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->atLeast()->once();
		$wp_cli->shouldReceive( 'error' )->never();

		$command = new KeygenCommand();
		$command(
			array(),
			array(
				'secret-key' => $this->path( 'k.key' ),
				'public-key' => $this->path( 'k.pub' ),
			)
		);

		$this->assertFileExists( $this->path( 'k.key' ) );
		$this->assertFileExists( $this->path( 'k.pub' ) );

		$secret_key = SigningKeys::load_secret_key( $this->path( 'k.key' ) );
		$public_key = SigningKeys::load_public_key( $this->path( 'k.pub' ) );
		$this->assertSame( $public_key, SigningKeypair::from_secret_key( $secret_key )->public_key() );
	}

	/**
	 * The command refuses to overwrite an existing key file (surfaced via WP_CLI::error).
	 *
	 * @return void
	 */
	public function test_invoke_refuses_to_overwrite_an_existing_file(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Pre-creating a file the command must refuse to overwrite.
		file_put_contents( $this->path( 'k.key' ), 'existing' );

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'error' )->once()->andThrow( new RuntimeException( 'halt' ) );

		$this->expectException( RuntimeException::class );

		$command = new KeygenCommand();
		$command(
			array(),
			array(
				'secret-key' => $this->path( 'k.key' ),
				'public-key' => $this->path( 'k.pub' ),
			)
		);
	}

	/**
	 * The command requires the --secret-key path.
	 *
	 * @return void
	 */
	public function test_invoke_requires_the_secret_key_path(): void {
		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'error' )->once()->andThrow( new RuntimeException( 'required' ) );

		$this->expectException( RuntimeException::class );

		$command = new KeygenCommand();
		$command( array(), array( 'public-key' => $this->path( 'k.pub' ) ) );
	}
}
