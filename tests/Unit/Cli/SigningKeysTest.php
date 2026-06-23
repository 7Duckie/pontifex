<?php
/**
 * Unit tests for the CLI signing key-file helper.
 *
 * @package Pontifex\Tests\Unit\Cli
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Cli;

use RuntimeException;
use PHPUnit\Framework\TestCase;
use Pontifex\Archive\Crypto\SigningKeypair;
use Pontifex\Cli\SigningKeys;

/**
 * Behavioural coverage of {@see SigningKeys}: the key-file round trip, the
 * refuse-to-overwrite guard, secret-key permissions, the parse-error paths, and
 * building a signing context from a stored secret key.
 *
 * Keypairs are generated at runtime, so no key material lives in the repository.
 */
final class SigningKeysTest extends TestCase {

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
		$this->dir = sys_get_temp_dir() . '/pontifex-signingkeys-' . uniqid( '', true );
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
	 * Write raw contents to a path in the scratch directory.
	 *
	 * @param string $name     The file name.
	 * @param string $contents The bytes to write.
	 * @return string The path written.
	 */
	private function put( string $name, string $contents ): string {
		$path = $this->path( $name );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing a key-file fixture; WP_Filesystem is not bootstrapped in unit tests.
		file_put_contents( $path, $contents );
		return $path;
	}

	/**
	 * Writing then loading reproduces both halves of the keypair.
	 *
	 * @return void
	 */
	public function test_write_then_load_round_trips(): void {
		$keypair = SigningKeypair::generate();

		SigningKeys::write_keypair( $keypair, $this->path( 'k.key' ), $this->path( 'k.pub' ) );

		$this->assertSame( $keypair->secret_key(), SigningKeys::load_secret_key( $this->path( 'k.key' ) ) );
		$this->assertSame( $keypair->public_key(), SigningKeys::load_public_key( $this->path( 'k.pub' ) ) );
	}

	/**
	 * The secret-key file is written owner-only (0600).
	 *
	 * @return void
	 */
	public function test_secret_key_file_is_owner_only(): void {
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			$this->markTestSkipped( 'POSIX file modes are not meaningful on Windows.' );
		}

		SigningKeys::write_keypair( SigningKeypair::generate(), $this->path( 'k.key' ), $this->path( 'k.pub' ) );

		$this->assertSame( 0600, fileperms( $this->path( 'k.key' ) ) & 0777 );
	}

	/**
	 * Writing refuses to overwrite an existing secret-key file.
	 *
	 * @return void
	 */
	public function test_refuses_to_overwrite_existing_secret_key(): void {
		$this->put( 'k.key', 'existing' );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'refusing to overwrite' );

		SigningKeys::write_keypair( SigningKeypair::generate(), $this->path( 'k.key' ), $this->path( 'k.pub' ) );
	}

	/**
	 * Writing refuses to overwrite an existing public-key file.
	 *
	 * @return void
	 */
	public function test_refuses_to_overwrite_existing_public_key(): void {
		$this->put( 'k.pub', 'existing' );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'refusing to overwrite' );

		SigningKeys::write_keypair( SigningKeypair::generate(), $this->path( 'k.key' ), $this->path( 'k.pub' ) );
	}

	/**
	 * Loading a secret key from a public-key file is refused on the length check.
	 *
	 * @return void
	 */
	public function test_load_secret_key_rejects_a_public_key_file(): void {
		SigningKeys::write_keypair( SigningKeypair::generate(), $this->path( 'k.key' ), $this->path( 'k.pub' ) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'must be 64 bytes' );

		SigningKeys::load_secret_key( $this->path( 'k.pub' ) );
	}

	/**
	 * Loading a missing file is refused.
	 *
	 * @return void
	 */
	public function test_load_rejects_a_missing_file(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'could not read' );

		SigningKeys::load_public_key( $this->path( 'nope.pub' ) );
	}

	/**
	 * Loading a file whose key line is not valid base64 is refused.
	 *
	 * @return void
	 */
	public function test_load_rejects_invalid_base64(): void {
		$path = $this->put( 'bad.pub', "comment line\n!!! not base64 !!!\n" );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'valid base64' );

		SigningKeys::load_public_key( $path );
	}

	/**
	 * Loading a single-line file (no base64 line) is refused as malformed.
	 *
	 * @return void
	 */
	public function test_load_rejects_a_single_line_file(): void {
		$path = $this->put( 'short.pub', "only a comment line\n" );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'malformed' );

		SigningKeys::load_public_key( $path );
	}

	/**
	 * A signing context built from a loaded secret key carries the keypair's identity.
	 *
	 * @return void
	 */
	public function test_signing_context_from_loaded_secret_key(): void {
		$keypair = SigningKeypair::generate();
		SigningKeys::write_keypair( $keypair, $this->path( 'k.key' ), $this->path( 'k.pub' ) );

		$secret_key = SigningKeys::load_secret_key( $this->path( 'k.key' ) );
		$context    = SigningKeys::signing_context( $secret_key );

		$this->assertSame( $secret_key, $context->secret_key() );
		$this->assertSame( $keypair->key_id(), $context->key_id() );
	}
}
