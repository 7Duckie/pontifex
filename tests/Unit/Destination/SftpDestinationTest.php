<?php
/**
 * Unit tests for SftpDestination's pure, network-free logic.
 *
 * @package Pontifex\Tests\Unit\Destination
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Destination;

use Mockery;
use phpseclib3\Net\SFTP;
use Pontifex\Destination\DestinationException;
use Pontifex\Destination\SftpDestination;
use Pontifex\Tests\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Coverage of {@see SftpDestination::fingerprint_of()} — the host-key
 * fingerprint computation the pinning check compares against — and of
 * {@see SftpDestination::get()}'s temp-then-rename download path.
 *
 * The transport paths need a real SFTP server and are proven by an integration
 * drill, but the fingerprint maths is pure and security-critical (a wrong
 * algorithm here would reject a valid key, or worse), so it is pinned here with
 * a known-answer test independent of the implementation.
 *
 * get()'s own logic — writing to a temporary local path, comparing sizes,
 * renaming into place, and cleaning up on failure — is exercised for real
 * against a fresh fixture directory, with only the network transport
 * (phpseclib's SFTP class) replaced by a Mockery double. The double is
 * injected directly into the private `connection` property via reflection
 * ({@see self::destination_with()}), the same seeding-private-state pattern
 * FileWriterTest uses elsewhere in this suite, so connect() itself is never
 * exercised and no real network attempt is ever made.
 */
final class SftpDestinationTest extends TestCase {

	/**
	 * Absolute path to a fresh, empty fixture directory for the current test.
	 *
	 * @var string
	 */
	private string $fixture_dir;

	/**
	 * Create a fresh fixture directory before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->fixture_dir = sys_get_temp_dir() . '/pontifex-sftp-destination-test-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture setup; WP_Filesystem is not available in PHPUnit context.
		mkdir( $this->fixture_dir, 0o755, true );
	}

	/**
	 * Remove only what the test put in the fixture directory, then the
	 * directory itself — never a path this setUp() did not create.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$leftovers = glob( $this->fixture_dir . '/*' );
		foreach ( false !== $leftovers ? $leftovers : array() as $path ) {
			if ( is_dir( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.PHP.NoSilencedErrors.Discouraged -- Test fixture teardown; best-effort.
				@rmdir( $path );
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Test fixture teardown; WP_Filesystem is not available in PHPUnit context.
			@unlink( $path );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.PHP.NoSilencedErrors.Discouraged -- Test fixture teardown; best-effort.
		@rmdir( $this->fixture_dir );
		parent::tearDown();
	}

	/**
	 * Invoke the private static fingerprint_of() via reflection.
	 *
	 * @param string $server_key The `<type> <base64>` host key string.
	 * @return string The computed fingerprint.
	 */
	private function fingerprint_of( string $server_key ): string {
		$method = new ReflectionMethod( SftpDestination::class, 'fingerprint_of' );

		return (string) $method->invoke( null, $server_key );
	}

	/**
	 * Build an SftpDestination with a connection double injected directly.
	 *
	 * The constructor's own connection parameters are never actually used to
	 * connect anywhere — connect() is bypassed entirely by seeding the
	 * private `connection` property with the given double, so every
	 * phpseclib call get() makes goes through it instead of the network.
	 *
	 * @param SFTP $sftp The connection double.
	 * @return SftpDestination
	 */
	private function destination_with( SFTP $sftp ): SftpDestination {
		$destination = new SftpDestination( 'sftp.example.test', 22, 'pontifex', '/backups', '', 'secret', '', true );

		( new ReflectionProperty( SftpDestination::class, 'connection' ) )->setValue( $destination, $sftp );

		return $destination;
	}

	/**
	 * A host key yields the OpenSSH-style SHA-256 fingerprint of its blob.
	 *
	 * The base64 second field decodes to the bytes "pontifex-hostkey-blob";
	 * the expected value is `SHA256:` + unpadded base64 of that blob's SHA-256,
	 * computed offline with openssl, so this pins the algorithm, the encoding,
	 * and the parse without re-using the production code.
	 *
	 * @return void
	 */
	public function test_computes_the_openssh_sha256_fingerprint(): void {
		$server_key = 'ssh-ed25519 cG9udGlmZXgtaG9zdGtleS1ibG9i';
		$expected   = 'SHA256:BVWwjwbAeqxMWpULX1h1ZQk+J2yQ+gyc6cNVoQ4QGIk';

		$this->assertSame( $expected, $this->fingerprint_of( $server_key ) );
	}

	/**
	 * A key string with no space (no base64 field) yields the empty string.
	 *
	 * @return void
	 */
	public function test_returns_empty_for_a_field_less_key(): void {
		$this->assertSame( '', $this->fingerprint_of( 'ssh-ed25519' ) );
	}

	/**
	 * A key whose second field is not valid base64 yields the empty string.
	 *
	 * @return void
	 */
	public function test_returns_empty_for_an_unparseable_blob(): void {
		$this->assertSame( '', $this->fingerprint_of( 'ssh-ed25519 !!!not-base64!!!' ) );
	}

	// -------------------------------------------------------------------
	// get() — the temp-then-rename download path (job 13)
	// -------------------------------------------------------------------

	/**
	 * A successful download writes through a temporary local name and
	 * renames it into the final path once verified: the final file holds
	 * the complete content, and nothing remains under the temporary name.
	 *
	 * @return void
	 */
	public function test_get_downloads_via_temp_path_and_renames_into_place_with_no_leftover(): void {
		$content    = 'the complete archive contents';
		$local_path = $this->fixture_dir . '/archive.wpmig';
		$temp_path  = $local_path . '.part';

		$sftp = Mockery::mock( SFTP::class );
		$sftp->shouldReceive( 'get' )
			->once()
			->with( '/backups/archive.wpmig', $temp_path )
			->andReturnUsing(
				function ( string $remote, string $local ) use ( $content ): bool {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test double standing in for phpseclib's real network write.
					file_put_contents( $local, $content );
					return true;
				}
			);
		$sftp->shouldReceive( 'filesize' )
			->once()
			->with( '/backups/archive.wpmig' )
			->andReturn( strlen( $content ) );

		$this->destination_with( $sftp )->get( 'archive.wpmig', $local_path );

		$this->assertFileDoesNotExist( $temp_path, 'No fragment should remain under the temporary name once the rename has succeeded.' );
		$this->assertFileExists( $local_path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test assertion against the downloaded fixture.
		$this->assertSame( $content, file_get_contents( $local_path ) );
	}

	/**
	 * A download whose reported size does not match the destination's
	 * throws, and the temporary file is removed rather than left as a
	 * fragment an operator might mistake for a usable partial download.
	 *
	 * @return void
	 */
	public function test_get_throws_and_removes_temp_file_on_size_mismatch(): void {
		$local_path = $this->fixture_dir . '/archive.wpmig';
		$temp_path  = $local_path . '.part';

		$sftp = Mockery::mock( SFTP::class );
		$sftp->shouldReceive( 'get' )
			->once()
			->with( '/backups/archive.wpmig', $temp_path )
			->andReturnUsing(
				function ( string $remote, string $local ): bool {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test double simulating a transfer that reported success while actually incomplete.
					file_put_contents( $local, 'only part of the archive' );
					return true;
				}
			);
		$sftp->shouldReceive( 'filesize' )
			->once()
			->with( '/backups/archive.wpmig' )
			->andReturn( 999999 );

		$thrown = null;
		try {
			$this->destination_with( $sftp )->get( 'archive.wpmig', $local_path );
		} catch ( DestinationException $e ) {
			$thrown = $e;
		}

		$this->assertInstanceOf( DestinationException::class, $thrown, 'A size mismatch must be refused, not accepted as a complete download.' );
		$this->assertStringContainsString( 'could not be verified', $thrown->getMessage() );
		$this->assertFileDoesNotExist( $temp_path, 'A mismatched download must not leave its fragment behind under the temporary name.' );
		$this->assertFileDoesNotExist( $local_path, 'A mismatched download must never be promoted to the final name.' );
	}

	/**
	 * A remote size that cannot be read throws, and the temporary file is
	 * removed — failing closed rather than promoting an unverifiable download.
	 *
	 * @return void
	 */
	public function test_get_throws_and_removes_temp_file_when_remote_size_unreadable(): void {
		$local_path = $this->fixture_dir . '/archive.wpmig';
		$temp_path  = $local_path . '.part';

		$sftp = Mockery::mock( SFTP::class );
		$sftp->shouldReceive( 'get' )
			->once()
			->with( '/backups/archive.wpmig', $temp_path )
			->andReturnUsing(
				function ( string $remote, string $local ): bool {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test double simulating a completed transfer whose destination-side size is unreadable.
					file_put_contents( $local, 'downloaded content' );
					return true;
				}
			);
		$sftp->shouldReceive( 'filesize' )
			->once()
			->with( '/backups/archive.wpmig' )
			->andReturn( false );

		$thrown = null;
		try {
			$this->destination_with( $sftp )->get( 'archive.wpmig', $local_path );
		} catch ( DestinationException $e ) {
			$thrown = $e;
		}

		$this->assertInstanceOf( DestinationException::class, $thrown, 'An unreadable remote size must fail closed rather than being treated as a match.' );
		$this->assertStringContainsString( 'could not be read', $thrown->getMessage() );
		$this->assertFileDoesNotExist( $temp_path, 'An unverifiable download must not leave its fragment behind under the temporary name.' );
	}

	/**
	 * A missing remote archive still produces the pre-existing "no archive
	 * named" message, unchanged, and never reaches size verification.
	 *
	 * @return void
	 */
	public function test_get_reports_missing_archive_with_unchanged_wording(): void {
		$local_path = $this->fixture_dir . '/archive.wpmig';
		$temp_path  = $local_path . '.part';

		$sftp = Mockery::mock( SFTP::class );
		$sftp->shouldReceive( 'get' )
			->once()
			->with( '/backups/archive.wpmig', $temp_path )
			->andReturn( false );
		$sftp->shouldNotReceive( 'filesize' );

		$thrown = null;
		try {
			$this->destination_with( $sftp )->get( 'archive.wpmig', $local_path );
		} catch ( DestinationException $e ) {
			$thrown = $e;
		}

		$this->assertInstanceOf( DestinationException::class, $thrown );
		$this->assertSame( 'The SFTP destination has no archive named "archive.wpmig".', $thrown->getMessage() );
		$this->assertFileDoesNotExist( $local_path );
		$this->assertFileDoesNotExist( $temp_path );
	}

	/**
	 * A failed rename reports clearly and does not silently lose the
	 * verified download: the temporary file — already known good — is left
	 * exactly where the message says it is, rather than deleted.
	 *
	 * The rename is made to fail deterministically and portably (no chmod,
	 * which a test run as root would simply bypass — a known trap in this
	 * suite) by pointing the final path at a location that is already an
	 * existing DIRECTORY: a POSIX rename() of a file onto an existing
	 * directory fails and leaves the source untouched, which is exactly the
	 * shape of failure a Windows "target already exists" rename produces
	 * too (the case this method's docblock calls out by name).
	 *
	 * @return void
	 */
	public function test_get_reports_a_failed_rename_without_losing_the_verified_download(): void {
		$content    = 'the complete archive contents';
		$local_path = $this->fixture_dir . '/archive.wpmig';
		$temp_path  = $local_path . '.part';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture setup: an existing directory at the final path is what forces rename() to fail.
		mkdir( $local_path, 0o755 );

		$sftp = Mockery::mock( SFTP::class );
		$sftp->shouldReceive( 'get' )
			->once()
			->with( '/backups/archive.wpmig', $temp_path )
			->andReturnUsing(
				function ( string $remote, string $local ) use ( $content ): bool {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test double standing in for phpseclib's real network write.
					file_put_contents( $local, $content );
					return true;
				}
			);
		$sftp->shouldReceive( 'filesize' )
			->once()
			->with( '/backups/archive.wpmig' )
			->andReturn( strlen( $content ) );

		$thrown = null;
		try {
			$this->destination_with( $sftp )->get( 'archive.wpmig', $local_path );
		} catch ( DestinationException $e ) {
			$thrown = $e;
		}

		$this->assertInstanceOf( DestinationException::class, $thrown, 'A rename onto an existing directory must be reported, not left as a raw filesystem warning.' );
		$this->assertStringContainsString( 'has not been lost', $thrown->getMessage() );
		$this->assertStringContainsString( $temp_path, $thrown->getMessage(), 'The message must name where the verified download actually is.' );
		$this->assertFileExists( $temp_path, 'The verified download must survive a failed rename, not be deleted.' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test assertion against the surviving fixture.
		$this->assertSame( $content, file_get_contents( $temp_path ) );

		// Cleanup of both the surviving temp file and the directory standing
		// in the final path is left to tearDown(), which already handles
		// both shapes found directly inside the fixture directory it created.
	}
}
