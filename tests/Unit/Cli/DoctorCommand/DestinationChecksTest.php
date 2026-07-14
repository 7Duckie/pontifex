<?php
/**
 * Behavioural tests for DoctorCommand's offsite-destination checks.
 *
 * @package Pontifex\Tests\Unit\Cli\DoctorCommand
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Cli\DoctorCommand;

use Mockery;
use Pontifex\Cli\DoctorCommand;
use Pontifex\Destination\DestinationSpec;
use Pontifex\Destination\DestinationStore;
use Pontifex\Environment\Environment;
use Pontifex\Tests\TestCase;
use Pontifex\WordPress\WordPressContext;
use ReflectionMethod;

/**
 * Tests for check_destinations() and check_destination(): the read-only,
 * network-free "could an upload work?" grading of each stored destination.
 *
 * Exercised through check_destinations() so the coverage proves the whole
 * path — DestinationStore::all() parsing the stored option, through to the
 * precedence ladder in check_destination() — rather than constructing
 * DestinationSpec instances directly and skipping the store. Environment is
 * still injected (a bare mock, unused) for consistency with the other
 * DoctorCommand behavioural test files and to defend against future drift:
 * if a destination check ever starts touching Environment, the mock catches
 * it as an unexpected call instead of silently delegating to the real
 * implementation.
 *
 * Status string values are hardcoded ('OK', 'WARN', 'INFO') because they are
 * part of DoctorCommand's visible output contract.
 */
final class DestinationChecksTest extends TestCase {

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
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Test teardown; unsets an environment variable this test set for a credential-presence fixture.
			putenv( $name );
		}
		foreach ( $this->temp_files as $path ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Test fixture teardown; best-effort.
			@unlink( $path );
		}
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers.
	// -------------------------------------------------------------------------

	/**
	 * Construct a DoctorCommand instance with the given mock dependencies.
	 *
	 * @param Environment      $environment       The mock environment to inject.
	 * @param WordPressContext $wordpress_context The mock WordPress context to inject.
	 * @return DoctorCommand
	 */
	private function build_command( Environment $environment, WordPressContext $wordpress_context ): DoctorCommand {
		return new DoctorCommand( $environment, $wordpress_context );
	}

	/**
	 * Invoke a private method on DoctorCommand via reflection.
	 *
	 * @param DoctorCommand $command     The command instance under test.
	 * @param string        $method_name The private method to invoke.
	 * @param mixed         ...$args     Any arguments the method takes.
	 * @return mixed The method's return value, cast to the caller's expected type.
	 */
	private function invoke_private( DoctorCommand $command, string $method_name, ...$args ) {
		$reflection = new ReflectionMethod( $command, $method_name );
		return $reflection->invoke( $command, ...$args );
	}

	/**
	 * Set an environment variable for the duration of this test only.
	 *
	 * @param string $name  The variable name.
	 * @param string $value The value to set.
	 * @return void
	 */
	private function set_env( string $name, string $value ): void {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Test fixture; simulates the credential environment variable check_destination() probes via getenv().
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
		$path = sys_get_temp_dir() . '/pontifex-doctor-test-key-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing a test fixture private-key file to a temp path.
		file_put_contents( $path, $contents );
		$this->temp_files[] = $path;
		return $path;
	}

	/**
	 * Build a stored destination record in the DestinationStore::all() wire shape.
	 *
	 * @param array<string, scalar> $settings Non-secret settings for the record.
	 * @param string                $type     The destination type. Defaults to sftp.
	 * @return array<string, mixed>
	 */
	private function destination_record( array $settings, string $type = DestinationSpec::TYPE_SFTP ): array {
		return array(
			'type'      => $type,
			'settings'  => $settings,
			'retention' => 0,
		);
	}

	/**
	 * Stub the WordPressContext mock to return the given stored records for
	 * DestinationStore's option read.
	 *
	 * @param WordPressContext     $wordpress_context The mock to stub.
	 * @param array<string, mixed> $records           Records keyed by destination name.
	 * @return void
	 */
	private function stub_destinations( WordPressContext $wordpress_context, array $records ): void {
		$wordpress_context->shouldReceive( 'option_value' )
			->with( DestinationStore::OPTION, array() )
			->andReturn( $records );
	}

	// -------------------------------------------------------------------------
	// check_destinations() — no destinations configured.
	// -------------------------------------------------------------------------

	/**
	 * With no destinations configured, a single informational row is reported.
	 *
	 * @return void
	 */
	public function test_no_destinations_reports_a_single_info_row(): void {
		$environment       = Mockery::mock( Environment::class );
		$wordpress_context = Mockery::mock( WordPressContext::class );
		$this->stub_destinations( $wordpress_context, array() );

		$rows = (array) $this->invoke_private(
			$this->build_command( $environment, $wordpress_context ),
			'check_destinations'
		);

		$this->assertCount( 1, $rows );
		$row = $rows[0];
		$this->assertSame( 'Destinations', $row['category'] );
		$this->assertSame( 'Offsite destinations', $row['name'] );
		$this->assertSame( 'none configured', $row['value'] );
		$this->assertSame( 'INFO', $row['status'] );
		$this->assertStringContainsString( 'wp pontifex destination add', $row['note'] );
	}

	// -------------------------------------------------------------------------
	// check_destination() via check_destinations() — healthy destinations.
	// -------------------------------------------------------------------------

	/**
	 * A key-auth destination with a readable key and a pinned host key is OK.
	 *
	 * @return void
	 */
	public function test_healthy_key_auth_destination_is_ok(): void {
		$key_path = $this->create_temp_key_file();

		$environment       = Mockery::mock( Environment::class );
		$wordpress_context = Mockery::mock( WordPressContext::class );
		$this->stub_destinations(
			$wordpress_context,
			array(
				'backup-server' => $this->destination_record(
					array(
						'host'     => 'example.test',
						'username' => 'deploy',
						'path'     => '/backups',
						'auth'     => 'key',
						'key_path' => $key_path,
						'host_key' => 'SHA256:abc123',
					)
				),
			)
		);

		$rows = (array) $this->invoke_private(
			$this->build_command( $environment, $wordpress_context ),
			'check_destinations'
		);

		$this->assertCount( 1, $rows );
		$row = $rows[0];
		$this->assertSame( 'Destinations', $row['category'] );
		$this->assertSame( 'backup-server', $row['name'] );
		$this->assertSame( 'sftp', $row['value'] );
		$this->assertSame( 'OK', $row['status'] );
		$this->assertStringContainsString( 'wp pontifex destination test backup-server', $row['note'] );
	}

	/**
	 * A password-auth destination with a resolvable secret and a pinned host key is OK.
	 *
	 * @return void
	 */
	public function test_healthy_password_auth_destination_is_ok(): void {
		$this->set_env( 'PONTIFEX_TEST_DOCTOR_PASSWORD', 'hunter2' );

		$environment       = Mockery::mock( Environment::class );
		$wordpress_context = Mockery::mock( WordPressContext::class );
		$this->stub_destinations(
			$wordpress_context,
			array(
				'backup-server' => $this->destination_record(
					array(
						'host'       => 'example.test',
						'username'   => 'deploy',
						'path'       => '/backups',
						'auth'       => 'password',
						'secret_env' => 'PONTIFEX_TEST_DOCTOR_PASSWORD',
						'host_key'   => 'SHA256:abc123',
					)
				),
			)
		);

		$rows = (array) $this->invoke_private(
			$this->build_command( $environment, $wordpress_context ),
			'check_destinations'
		);

		$this->assertCount( 1, $rows );
		$this->assertSame( 'OK', $rows[0]['status'] );
		$this->assertStringNotContainsString( 'hunter2', $rows[0]['note'] );
	}

	// -------------------------------------------------------------------------
	// check_destination() via check_destinations() — the WARN precedence ladder.
	// -------------------------------------------------------------------------

	/**
	 * An unsupported destination type is refused by name, before any other check runs.
	 *
	 * @return void
	 */
	public function test_unsupported_type_warns_and_names_it(): void {
		$environment       = Mockery::mock( Environment::class );
		$wordpress_context = Mockery::mock( WordPressContext::class );
		$this->stub_destinations(
			$wordpress_context,
			array(
				'backup-bucket' => $this->destination_record( array(), DestinationSpec::TYPE_S3 ),
			)
		);

		$rows = (array) $this->invoke_private(
			$this->build_command( $environment, $wordpress_context ),
			'check_destinations'
		);

		$this->assertCount( 1, $rows );
		$row = $rows[0];
		$this->assertSame( 'Destinations', $row['category'] );
		$this->assertSame( 'backup-bucket', $row['name'] );
		$this->assertSame( 's3', $row['value'] );
		$this->assertSame( 'WARN', $row['status'] );
		$this->assertStringContainsString( 'not supported', $row['note'] );
	}

	/**
	 * A missing required setting (host) is named in the note.
	 *
	 * @return void
	 */
	public function test_missing_required_setting_lists_it_by_name(): void {
		$environment       = Mockery::mock( Environment::class );
		$wordpress_context = Mockery::mock( WordPressContext::class );
		$this->stub_destinations(
			$wordpress_context,
			array(
				'backup-server' => $this->destination_record(
					array(
						'host'     => '',
						'username' => 'deploy',
						'path'     => '/backups',
					)
				),
			)
		);

		$rows = (array) $this->invoke_private(
			$this->build_command( $environment, $wordpress_context ),
			'check_destinations'
		);

		$row = $rows[0];
		$this->assertSame( 'WARN', $row['status'] );
		$this->assertStringContainsString( 'host', $row['note'] );
	}

	/**
	 * Key authentication with no key_path is refused before the host-key check runs.
	 *
	 * @return void
	 */
	public function test_key_auth_without_key_path_warns(): void {
		$environment       = Mockery::mock( Environment::class );
		$wordpress_context = Mockery::mock( WordPressContext::class );
		$this->stub_destinations(
			$wordpress_context,
			array(
				'backup-server' => $this->destination_record(
					array(
						'host'     => 'example.test',
						'username' => 'deploy',
						'path'     => '/backups',
						'auth'     => 'key',
					)
				),
			)
		);

		$rows = (array) $this->invoke_private(
			$this->build_command( $environment, $wordpress_context ),
			'check_destinations'
		);

		$row = $rows[0];
		$this->assertSame( 'WARN', $row['status'] );
		$this->assertStringContainsString( 'key_path', $row['note'] );
	}

	/**
	 * No host key pinned, and --insecure-host-key not set, warns and points at --host-key.
	 *
	 * @return void
	 */
	public function test_unpinned_host_key_warns_and_mentions_host_key_flag(): void {
		$key_path = $this->create_temp_key_file();

		$environment       = Mockery::mock( Environment::class );
		$wordpress_context = Mockery::mock( WordPressContext::class );
		$this->stub_destinations(
			$wordpress_context,
			array(
				'backup-server' => $this->destination_record(
					array(
						'host'     => 'example.test',
						'username' => 'deploy',
						'path'     => '/backups',
						'auth'     => 'key',
						'key_path' => $key_path,
					)
				),
			)
		);

		$rows = (array) $this->invoke_private(
			$this->build_command( $environment, $wordpress_context ),
			'check_destinations'
		);

		$row = $rows[0];
		$this->assertSame( 'WARN', $row['status'] );
		$this->assertStringContainsString( '--host-key', $row['note'] );
	}

	/**
	 * A secret_env naming an unset environment variable warns by the variable's
	 * name, never its value.
	 *
	 * @return void
	 */
	public function test_missing_credential_env_var_warns_by_name_not_value(): void {
		$environment       = Mockery::mock( Environment::class );
		$wordpress_context = Mockery::mock( WordPressContext::class );
		$this->stub_destinations(
			$wordpress_context,
			array(
				'backup-server' => $this->destination_record(
					array(
						'host'       => 'example.test',
						'username'   => 'deploy',
						'path'       => '/backups',
						'auth'       => 'password',
						'secret_env' => 'PONTIFEX_TEST_DOCTOR_UNSET_PASSWORD',
						'host_key'   => 'SHA256:abc123',
					)
				),
			)
		);

		$rows = (array) $this->invoke_private(
			$this->build_command( $environment, $wordpress_context ),
			'check_destinations'
		);

		$row = $rows[0];
		$this->assertSame( 'WARN', $row['status'] );
		$this->assertStringContainsString( 'PONTIFEX_TEST_DOCTOR_UNSET_PASSWORD', $row['note'] );
		$this->assertStringNotContainsString( 'hunter2', $row['note'] );
	}

	/**
	 * A secret_env naming a set-but-empty environment variable warns: an empty
	 * value is no credential, and the factory refuses it the same way.
	 *
	 * @return void
	 */
	public function test_empty_credential_env_var_warns(): void {
		$this->set_env( 'PONTIFEX_TEST_DOCTOR_EMPTY_PASSWORD', '' );

		$environment       = Mockery::mock( Environment::class );
		$wordpress_context = Mockery::mock( WordPressContext::class );
		$this->stub_destinations(
			$wordpress_context,
			array(
				'backup-server' => $this->destination_record(
					array(
						'host'       => 'example.test',
						'username'   => 'deploy',
						'path'       => '/backups',
						'auth'       => 'password',
						'secret_env' => 'PONTIFEX_TEST_DOCTOR_EMPTY_PASSWORD',
						'host_key'   => 'SHA256:abc123',
					)
				),
			)
		);

		$rows = (array) $this->invoke_private(
			$this->build_command( $environment, $wordpress_context ),
			'check_destinations'
		);

		$row = $rows[0];
		$this->assertSame( 'WARN', $row['status'] );
		$this->assertStringContainsString( 'PONTIFEX_TEST_DOCTOR_EMPTY_PASSWORD', $row['note'] );
	}

	/**
	 * A key-auth destination whose key file exists but is unreadable warns
	 * with the path.
	 *
	 * @return void
	 */
	public function test_unreadable_key_file_warns(): void {
		$key_path = $this->create_temp_key_file();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Test fixture; unreadability behaviour is the subject under test.
		chmod( $key_path, 0o000 );

		if ( is_readable( $key_path ) ) {
			// Running as root (or on a filesystem that ignores 0000) makes this
			// guard unobservable; skip rather than assert a false failure.
			$this->markTestSkipped( 'The current user can read a 0000-permission file; the unreadable-key guard cannot be observed.' );
		}

		$environment       = Mockery::mock( Environment::class );
		$wordpress_context = Mockery::mock( WordPressContext::class );
		$this->stub_destinations(
			$wordpress_context,
			array(
				'backup-server' => $this->destination_record(
					array(
						'host'     => 'example.test',
						'username' => 'deploy',
						'path'     => '/backups',
						'auth'     => 'key',
						'key_path' => $key_path,
						'host_key' => 'SHA256:abc123',
					)
				),
			)
		);

		try {
			$rows = (array) $this->invoke_private(
				$this->build_command( $environment, $wordpress_context ),
				'check_destinations'
			);

			$row = $rows[0];
			$this->assertSame( 'WARN', $row['status'] );
			$this->assertStringContainsString( $key_path, $row['note'] );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Test fixture cleanup, restoring readability so tearDown can unlink the file.
			chmod( $key_path, 0o644 );
		}
	}

	/**
	 * An insecure host key (verification disabled) warns and mentions the risk.
	 *
	 * @return void
	 */
	public function test_insecure_host_key_warns_and_mentions_man_in_the_middle(): void {
		$key_path = $this->create_temp_key_file();

		$environment       = Mockery::mock( Environment::class );
		$wordpress_context = Mockery::mock( WordPressContext::class );
		$this->stub_destinations(
			$wordpress_context,
			array(
				'backup-server' => $this->destination_record(
					array(
						'host'              => 'example.test',
						'username'          => 'deploy',
						'path'              => '/backups',
						'auth'              => 'key',
						'key_path'          => $key_path,
						'insecure_host_key' => true,
					)
				),
			)
		);

		$rows = (array) $this->invoke_private(
			$this->build_command( $environment, $wordpress_context ),
			'check_destinations'
		);

		$row = $rows[0];
		$this->assertSame( 'WARN', $row['status'] );
		$this->assertStringContainsString( 'man-in-the-middle', $row['note'] );
	}

	// -------------------------------------------------------------------------
	// check_destinations() — multiple destinations.
	// -------------------------------------------------------------------------

	/**
	 * Two stored destinations produce two rows, one per destination.
	 *
	 * @return void
	 */
	public function test_two_destinations_produce_two_rows(): void {
		$key_path = $this->create_temp_key_file();

		$environment       = Mockery::mock( Environment::class );
		$wordpress_context = Mockery::mock( WordPressContext::class );
		$this->stub_destinations(
			$wordpress_context,
			array(
				'backup-server-a' => $this->destination_record(
					array(
						'host'     => 'a.example.test',
						'username' => 'deploy',
						'path'     => '/backups',
						'auth'     => 'key',
						'key_path' => $key_path,
						'host_key' => 'SHA256:abc123',
					)
				),
				'backup-server-b' => $this->destination_record(
					array(
						'host'     => 'b.example.test',
						'username' => 'deploy',
						'path'     => '/backups',
					)
				),
			)
		);

		$rows = (array) $this->invoke_private(
			$this->build_command( $environment, $wordpress_context ),
			'check_destinations'
		);

		$this->assertCount( 2, $rows );
	}
}
