<?php
/**
 * Behavioural tests for DoctorCommand's extension and filesystem checks.
 *
 * @package Pontifex\Tests\Unit\Cli\DoctorCommand
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Cli\DoctorCommand;

use Brain\Monkey\Functions;
use Mockery;
use Pontifex\Cli\DoctorCommand;
use Pontifex\Environment\Environment;
use Pontifex\Tests\TestCase;
use ReflectionMethod;

/**
 * Tests for the second batch of DoctorCommand check methods.
 *
 * Covers check_extension_present (the parameterised extension-loaded
 * probe used for zlib, zstd, sodium, openssl, mbstring, pcre, and json),
 * check_free_disk_space, and check_uploads_dir_writable.
 *
 * check_extension_present is exercised once per behavioural distinction
 * (loaded vs missing, required vs optional) rather than once per
 * specific extension name. The seven concrete extension checks in
 * collect_all_checks() are parameter choices, not behavioural variants;
 * testing each one separately would multiply test count without
 * adding coverage.
 *
 * Status string values are hardcoded ('OK', 'WARN', 'FAIL') because
 * they are part of DoctorCommand's visible output contract.
 */
final class ExtensionsAndFilesystemChecksTest extends TestCase {

	// -------------------------------------------------------------------------
	// Helpers.
	// -------------------------------------------------------------------------

	/**
	 * Construct a DoctorCommand instance with the given mock Environment.
	 *
	 * @param Environment $environment The mock environment to inject.
	 * @return DoctorCommand
	 */
	private function build_command( Environment $environment ): DoctorCommand {
		return new DoctorCommand( $environment );
	}

	/**
	 * Invoke a private check method on DoctorCommand via reflection.
	 *
	 * @param DoctorCommand $command     The command instance under test.
	 * @param string        $method_name The private method to invoke.
	 * @param mixed         ...$args     Any arguments the method takes.
	 * @return array<string, string> The row returned by the check method.
	 */
	private function invoke_check( DoctorCommand $command, string $method_name, ...$args ): array {
		$reflection = new ReflectionMethod( $command, $method_name );
		return (array) $reflection->invoke( $command, ...$args );
	}

	// -------------------------------------------------------------------------
	// check_extension_present
	// -------------------------------------------------------------------------

	/**
	 * A loaded extension yields OK regardless of required-vs-optional status.
	 *
	 * @return void
	 */
	public function test_extension_loaded_returns_ok_when_required(): void {
		$environment = Mockery::mock( Environment::class );
		$environment->shouldReceive( 'extension_loaded' )
			->with( 'sodium' )
			->andReturn( true );

		$row = $this->invoke_check(
			$this->build_command( $environment ),
			'check_extension_present',
			'sodium',
			true,
			'Required: archive encryption.'
		);

		$this->assertSame( 'PHP extensions', $row['category'] );
		$this->assertSame( 'ext-sodium', $row['name'] );
		$this->assertSame( 'loaded', $row['value'] );
		$this->assertSame( 'OK', $row['status'] );
		$this->assertSame( 'Required: archive encryption.', $row['note'] );
	}

	/**
	 * A loaded optional extension still yields OK.
	 *
	 * @return void
	 */
	public function test_extension_loaded_returns_ok_when_optional(): void {
		$environment = Mockery::mock( Environment::class );
		$environment->shouldReceive( 'extension_loaded' )
			->with( 'zstd' )
			->andReturn( true );

		$row = $this->invoke_check(
			$this->build_command( $environment ),
			'check_extension_present',
			'zstd',
			false,
			'Optional: zstd compression.'
		);

		$this->assertSame( 'ext-zstd', $row['name'] );
		$this->assertSame( 'OK', $row['status'] );
	}

	/**
	 * A missing required extension yields FAIL.
	 *
	 * @return void
	 */
	public function test_extension_missing_returns_fail_when_required(): void {
		$environment = Mockery::mock( Environment::class );
		$environment->shouldReceive( 'extension_loaded' )
			->with( 'sodium' )
			->andReturn( false );

		$row = $this->invoke_check(
			$this->build_command( $environment ),
			'check_extension_present',
			'sodium',
			true,
			'Required: archive encryption.'
		);

		$this->assertSame( 'ext-sodium', $row['name'] );
		$this->assertSame( 'missing', $row['value'] );
		$this->assertSame( 'FAIL', $row['status'] );
		$this->assertSame( 'Required: archive encryption.', $row['note'] );
	}

	/**
	 * A missing optional extension yields only WARN.
	 *
	 * @return void
	 */
	public function test_extension_missing_returns_warn_when_optional(): void {
		$environment = Mockery::mock( Environment::class );
		$environment->shouldReceive( 'extension_loaded' )
			->with( 'zstd' )
			->andReturn( false );

		$row = $this->invoke_check(
			$this->build_command( $environment ),
			'check_extension_present',
			'zstd',
			false,
			'Optional: zstd compression.'
		);

		$this->assertSame( 'ext-zstd', $row['name'] );
		$this->assertSame( 'missing', $row['value'] );
		$this->assertSame( 'WARN', $row['status'] );
	}

	/**
	 * The purpose_note argument is passed through to the row's note column verbatim.
	 *
	 * @return void
	 */
	public function test_extension_note_is_passed_through(): void {
		$environment = Mockery::mock( Environment::class );
		$environment->shouldReceive( 'extension_loaded' )
			->with( 'mbstring' )
			->andReturn( true );

		$row = $this->invoke_check(
			$this->build_command( $environment ),
			'check_extension_present',
			'mbstring',
			true,
			'A bespoke note for testing.'
		);

		$this->assertSame( 'A bespoke note for testing.', $row['note'] );
	}

	// -------------------------------------------------------------------------
	// check_free_disk_space
	// -------------------------------------------------------------------------

	/**
	 * Disk space at or above the recommended floor returns OK.
	 *
	 * @return void
	 */
	public function test_free_disk_space_sufficient_returns_ok(): void {
		$environment = Mockery::mock( Environment::class );
		$environment->shouldReceive( 'constant_value' )
			->with( 'WP_CONTENT_DIR' )
			->andReturn( '/var/www/wp-content' );
		$environment->shouldReceive( 'disk_free_space' )
			->with( '/var/www/wp-content' )
			->andReturn( (float) ( 10 * 1024 * 1024 * 1024 ) );

		Functions\when( 'size_format' )->justReturn( '10 GB' );

		$row = $this->invoke_check(
			$this->build_command( $environment ),
			'check_free_disk_space'
		);

		$this->assertSame( 'Filesystem', $row['category'] );
		$this->assertSame( 'Free disk space (wp-content)', $row['name'] );
		$this->assertSame( '10 GB', $row['value'] );
		$this->assertSame( 'OK', $row['status'] );
	}

	/**
	 * Disk space below the recommended floor returns WARN with guidance.
	 *
	 * @return void
	 */
	public function test_free_disk_space_insufficient_returns_warn(): void {
		$environment = Mockery::mock( Environment::class );
		$environment->shouldReceive( 'constant_value' )
			->with( 'WP_CONTENT_DIR' )
			->andReturn( '/var/www/wp-content' );
		$environment->shouldReceive( 'disk_free_space' )
			->with( '/var/www/wp-content' )
			->andReturn( (float) ( 500 * 1024 * 1024 ) );

		Functions\when( 'size_format' )->justReturn( '500 MB' );

		$row = $this->invoke_check(
			$this->build_command( $environment ),
			'check_free_disk_space'
		);

		$this->assertSame( '500 MB', $row['value'] );
		$this->assertSame( 'WARN', $row['status'] );
		$this->assertStringContainsString( '2 GB', $row['note'] );
	}

	/**
	 * A false return from disk_free_space (e.g. open_basedir block) yields WARN unavailable.
	 *
	 * @return void
	 */
	public function test_free_disk_space_unavailable_returns_warn(): void {
		$environment = Mockery::mock( Environment::class );
		$environment->shouldReceive( 'constant_value' )
			->with( 'WP_CONTENT_DIR' )
			->andReturn( '/var/www/wp-content' );
		$environment->shouldReceive( 'disk_free_space' )
			->with( '/var/www/wp-content' )
			->andReturn( false );

		$row = $this->invoke_check(
			$this->build_command( $environment ),
			'check_free_disk_space'
		);

		$this->assertSame( '(unavailable)', $row['value'] );
		$this->assertSame( 'WARN', $row['status'] );
		$this->assertStringContainsString( 'open_basedir', $row['note'] );
	}

	// -------------------------------------------------------------------------
	// check_uploads_dir_writable
	// -------------------------------------------------------------------------

	/**
	 * An existing writable uploads directory returns OK with the basedir as value.
	 *
	 * @return void
	 */
	public function test_uploads_dir_writable_returns_ok(): void {
		$environment = Mockery::mock( Environment::class );
		$environment->shouldReceive( 'is_dir' )
			->with( '/var/www/wp-content/uploads' )
			->andReturn( true );
		$environment->shouldReceive( 'is_writable' )
			->with( '/var/www/wp-content/uploads' )
			->andReturn( true );

		Functions\when( 'wp_upload_dir' )->justReturn(
			array( 'basedir' => '/var/www/wp-content/uploads' )
		);

		$row = $this->invoke_check(
			$this->build_command( $environment ),
			'check_uploads_dir_writable'
		);

		$this->assertSame( 'Filesystem', $row['category'] );
		$this->assertSame( 'Uploads directory writable', $row['name'] );
		$this->assertSame( '/var/www/wp-content/uploads', $row['value'] );
		$this->assertSame( 'OK', $row['status'] );
	}

	/**
	 * An existing but non-writable uploads directory returns FAIL.
	 *
	 * @return void
	 */
	public function test_uploads_dir_not_writable_returns_fail(): void {
		$environment = Mockery::mock( Environment::class );
		$environment->shouldReceive( 'is_dir' )
			->with( '/var/www/wp-content/uploads' )
			->andReturn( true );
		$environment->shouldReceive( 'is_writable' )
			->with( '/var/www/wp-content/uploads' )
			->andReturn( false );

		Functions\when( 'wp_upload_dir' )->justReturn(
			array( 'basedir' => '/var/www/wp-content/uploads' )
		);

		$row = $this->invoke_check(
			$this->build_command( $environment ),
			'check_uploads_dir_writable'
		);

		$this->assertSame( '/var/www/wp-content/uploads', $row['value'] );
		$this->assertSame( 'FAIL', $row['status'] );
		$this->assertStringContainsString( 'not writable', $row['note'] );
	}

	/**
	 * A missing uploads basedir directory returns FAIL with "(not found)".
	 *
	 * @return void
	 */
	public function test_uploads_dir_missing_returns_fail(): void {
		$environment = Mockery::mock( Environment::class );
		$environment->shouldReceive( 'is_dir' )
			->with( '/var/www/wp-content/uploads' )
			->andReturn( false );

		Functions\when( 'wp_upload_dir' )->justReturn(
			array( 'basedir' => '/var/www/wp-content/uploads' )
		);

		$row = $this->invoke_check(
			$this->build_command( $environment ),
			'check_uploads_dir_writable'
		);

		$this->assertSame( '(not found)', $row['value'] );
		$this->assertSame( 'FAIL', $row['status'] );
		$this->assertStringContainsString( 'wp_upload_dir', $row['note'] );
	}

	/**
	 * An empty uploads basedir string returns FAIL with "(not found)".
	 *
	 * @return void
	 */
	public function test_uploads_dir_empty_basedir_returns_fail(): void {
		$environment = Mockery::mock( Environment::class );

		Functions\when( 'wp_upload_dir' )->justReturn( array( 'basedir' => '' ) );

		$row = $this->invoke_check(
			$this->build_command( $environment ),
			'check_uploads_dir_writable'
		);

		$this->assertSame( '(not found)', $row['value'] );
		$this->assertSame( 'FAIL', $row['status'] );
	}
}
