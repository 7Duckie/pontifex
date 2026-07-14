<?php
/**
 * Unit tests for DestinationFactory.
 *
 * @package Pontifex\Tests\Unit\Destination
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Destination;

use Pontifex\Destination\DestinationException;
use Pontifex\Destination\DestinationFactory;
use Pontifex\Destination\DestinationSpec;
use Pontifex\Destination\SftpDestination;
use Pontifex\Tests\TestCase;

/**
 * Behavioural coverage of {@see DestinationFactory}: the unsupported-type
 * refusal, the SFTP required-setting guards, the environment-variable
 * credential resolution, and the two auth branches (password needs
 * secret_env; key auth needs a readable key_path) — proven without a
 * network connection, since {@see SftpDestination} connects lazily.
 */
final class DestinationFactoryTest extends TestCase {

	/**
	 * Environment variable names this test set, so tearDown can unset them.
	 *
	 * @var array<int, string>
	 */
	private array $env_vars_set = array();

	/**
	 * Temp key-file paths this test created, so tearDown can remove them.
	 *
	 * @var array<int, string>
	 */
	private array $temp_files = array();

	/**
	 * Remove any environment variables and temp files this test created.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ( $this->env_vars_set as $name ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Test teardown; unsets an environment variable this test set for a credential-resolution fixture.
			putenv( $name );
		}
		foreach ( $this->temp_files as $path ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Test fixture teardown; best-effort.
			@unlink( $path );
		}
		parent::tearDown();
	}

	/**
	 * Set an environment variable for the duration of this test only.
	 *
	 * @param string $name  The variable name.
	 * @param string $value The value to set.
	 * @return void
	 */
	private function set_env( string $name, string $value ): void {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Test fixture; simulates the credential environment variable DestinationFactory resolves via getenv().
		putenv( $name . '=' . $value );
		$this->env_vars_set[] = $name;
	}

	/**
	 * Create a temp private-key file with the given contents, tracked for cleanup.
	 *
	 * @param string $contents The file contents.
	 * @return string The absolute path of the created file.
	 */
	private function create_temp_key_file( string $contents = "pontifex-test-key-material-not-a-real-key\n" ): string {
		$path = sys_get_temp_dir() . '/pontifex-destination-test-key-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing a test fixture private-key file to a temp path.
		file_put_contents( $path, $contents );
		$this->temp_files[] = $path;
		return $path;
	}

	/**
	 * An unsupported destination type (S3, not yet built) is refused by name.
	 *
	 * @return void
	 */
	public function test_an_unsupported_type_is_refused(): void {
		$spec = new DestinationSpec( 'backup-bucket', DestinationSpec::TYPE_S3, array(), 3 );

		$this->expectException( DestinationException::class );
		$this->expectExceptionMessage( 's3' );

		( new DestinationFactory() )->from_spec( $spec );
	}

	/**
	 * A missing host, username, or remote path is refused before any
	 * credential is resolved.
	 *
	 * @return void
	 */
	public function test_missing_host_username_or_path_is_refused(): void {
		$spec = new DestinationSpec( 'backup-server', DestinationSpec::TYPE_SFTP, array( 'username' => 'deploy' ), 3 );

		$this->expectException( DestinationException::class );

		( new DestinationFactory() )->from_spec( $spec );
	}

	/**
	 * A key-auth spec whose secret_env names an unset variable is refused,
	 * and the message names the variable so the site owner can fix it.
	 *
	 * @return void
	 */
	public function test_a_missing_env_var_is_refused_by_name(): void {
		$key_path = $this->create_temp_key_file();
		$spec     = new DestinationSpec(
			'backup-server',
			DestinationSpec::TYPE_SFTP,
			array(
				'host'       => 'example.test',
				'username'   => 'deploy',
				'path'       => '/backups',
				'key_path'   => $key_path,
				'secret_env' => 'PONTIFEX_TEST_UNSET_PASSPHRASE',
			),
			3
		);

		$this->expectException( DestinationException::class );
		$this->expectExceptionMessage( 'PONTIFEX_TEST_UNSET_PASSPHRASE' );

		( new DestinationFactory() )->from_spec( $spec );
	}

	/**
	 * Password auth without a secret_env is refused: there is no credential to resolve.
	 *
	 * @return void
	 */
	public function test_password_auth_without_secret_env_is_refused(): void {
		$spec = new DestinationSpec(
			'backup-server',
			DestinationSpec::TYPE_SFTP,
			array(
				'host'     => 'example.test',
				'username' => 'deploy',
				'path'     => '/backups',
				'auth'     => 'password',
			),
			3
		);

		$this->expectException( DestinationException::class );

		( new DestinationFactory() )->from_spec( $spec );
	}

	/**
	 * Password auth with a resolvable secret_env succeeds without touching the network.
	 *
	 * @return void
	 */
	public function test_password_auth_with_a_resolvable_secret_succeeds(): void {
		$this->set_env( 'PONTIFEX_TEST_PASSWORD', 'hunter2' );
		$spec = new DestinationSpec(
			'backup-server',
			DestinationSpec::TYPE_SFTP,
			array(
				'host'       => 'example.test',
				'username'   => 'deploy',
				'path'       => '/backups',
				'auth'       => 'password',
				'secret_env' => 'PONTIFEX_TEST_PASSWORD',
			),
			3
		);

		$adapter = ( new DestinationFactory() )->from_spec( $spec );

		$this->assertInstanceOf( SftpDestination::class, $adapter );
	}

	/**
	 * Key auth with no key_path setting is refused before the filesystem is touched.
	 *
	 * @return void
	 */
	public function test_key_auth_without_a_key_path_is_refused(): void {
		$spec = new DestinationSpec(
			'backup-server',
			DestinationSpec::TYPE_SFTP,
			array(
				'host'     => 'example.test',
				'username' => 'deploy',
				'path'     => '/backups',
			),
			3
		);

		$this->expectException( DestinationException::class );

		( new DestinationFactory() )->from_spec( $spec );
	}

	/**
	 * Key auth whose key_path does not exist is refused.
	 *
	 * @return void
	 */
	public function test_key_auth_with_a_missing_key_file_is_refused(): void {
		$spec = new DestinationSpec(
			'backup-server',
			DestinationSpec::TYPE_SFTP,
			array(
				'host'     => 'example.test',
				'username' => 'deploy',
				'path'     => '/backups',
				'key_path' => sys_get_temp_dir() . '/pontifex-destination-test-key-does-not-exist',
			),
			3
		);

		$this->expectException( DestinationException::class );

		( new DestinationFactory() )->from_spec( $spec );
	}

	/**
	 * Key auth whose key_path exists but is unreadable is refused.
	 *
	 * @return void
	 */
	public function test_key_auth_with_an_unreadable_key_file_is_refused(): void {
		$key_path = $this->create_temp_key_file();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Test fixture; unreadability behaviour is the subject under test.
		chmod( $key_path, 0o000 );

		if ( is_readable( $key_path ) ) {
			// Running as root (or on a filesystem that ignores 0000) makes this
			// guard unobservable; skip rather than assert a false failure.
			$this->markTestSkipped( 'The current user can read a 0000-permission file; the unreadable-key guard cannot be observed.' );
		}

		$spec = new DestinationSpec(
			'backup-server',
			DestinationSpec::TYPE_SFTP,
			array(
				'host'     => 'example.test',
				'username' => 'deploy',
				'path'     => '/backups',
				'key_path' => $key_path,
			),
			3
		);

		try {
			$this->expectException( DestinationException::class );

			( new DestinationFactory() )->from_spec( $spec );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Test fixture cleanup, restoring readability so tearDown can unlink the file.
			chmod( $key_path, 0o644 );
		}
	}

	/**
	 * Key auth with a real, readable temp key file succeeds and returns a
	 * usable SftpDestination — without touching the network, since the
	 * adapter connects lazily.
	 *
	 * @return void
	 */
	public function test_key_auth_with_a_readable_key_file_succeeds(): void {
		$key_path = $this->create_temp_key_file();
		$spec     = new DestinationSpec(
			'backup-server',
			DestinationSpec::TYPE_SFTP,
			array(
				'host'     => 'example.test',
				'username' => 'deploy',
				'path'     => '/backups',
				'key_path' => $key_path,
			),
			3
		);

		$adapter = ( new DestinationFactory() )->from_spec( $spec );

		$this->assertInstanceOf( SftpDestination::class, $adapter );
	}

	/**
	 * Key auth may also carry a secret_env for the key's own passphrase; a
	 * resolvable one does not block construction.
	 *
	 * @return void
	 */
	public function test_key_auth_with_a_passphrase_env_succeeds(): void {
		$this->set_env( 'PONTIFEX_TEST_PASSPHRASE', 'letmein' );
		$key_path = $this->create_temp_key_file();
		$spec     = new DestinationSpec(
			'backup-server',
			DestinationSpec::TYPE_SFTP,
			array(
				'host'       => 'example.test',
				'username'   => 'deploy',
				'path'       => '/backups',
				'key_path'   => $key_path,
				'secret_env' => 'PONTIFEX_TEST_PASSPHRASE',
			),
			3
		);

		$adapter = ( new DestinationFactory() )->from_spec( $spec );

		$this->assertInstanceOf( SftpDestination::class, $adapter );
	}
}
