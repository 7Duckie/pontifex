<?php
/**
 * Behavioural tests for DoctorCommand's runtime and PHP config checks.
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
 * Tests for the first batch of DoctorCommand check methods.
 *
 * Covers the seven check methods that report Runtime versions and PHP
 * configuration: check_php_version, check_wordpress_version,
 * check_database_version, check_memory_limit, check_max_execution_time,
 * check_upload_max_filesize, and check_open_basedir.
 *
 * Each check method is exercised through the public surface of
 * DoctorCommand (via the constructor's Environment injection) and
 * called via reflection because the methods are private. The
 * alternative — promoting them to public for testability — would
 * weaken encapsulation without benefit, since nothing outside
 * collect_all_checks() needs to call them individually.
 *
 * brain/monkey is used to stub WordPress functions where present
 * (get_bloginfo, wp_convert_hr_to_bytes, wp_max_upload_size,
 * size_format). The Environment mock controls PHP-level inputs
 * (PHP version, ini_get values, defined constants).
 *
 * Status string values are hardcoded ('OK', 'WARN', 'FAIL', 'INFO')
 * because they are part of DoctorCommand's visible output contract;
 * if the strings ever change, the test failure is the correct
 * signal to update both the production constants and the tests.
 */
final class RuntimeAndPhpConfigChecksTest extends TestCase {

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
	 * Reflection is used here rather than promoting the check methods
	 * to public, because nothing outside collect_all_checks() needs to
	 * call them individually in production code.
	 *
	 * @param DoctorCommand $command     The command instance under test.
	 * @param string        $method_name The private method to invoke (e.g. 'check_php_version').
	 * @param mixed         ...$args     Any arguments the method takes.
	 * @return array<string, string> The row returned by the check method.
	 */
	private function invoke_check( DoctorCommand $command, string $method_name, ...$args ): array {
		$reflection = new ReflectionMethod( $command, $method_name );
		return (array) $reflection->invoke( $command, ...$args );
	}

	// -------------------------------------------------------------------------
	// check_php_version
	// -------------------------------------------------------------------------

	/**
	 * A supported PHP version (8.2+) yields an OK row with no note.
	 *
	 * @return void
	 */
	public function test_php_version_supported_returns_ok(): void {
		$environment = Mockery::mock( Environment::class );
		$environment->shouldReceive( 'php_version' )->andReturn( '8.4.0' );

		$row = $this->invoke_check( $this->build_command( $environment ), 'check_php_version' );

		$this->assertSame( 'Runtime', $row['category'] );
		$this->assertSame( 'PHP version', $row['name'] );
		$this->assertSame( '8.4.0', $row['value'] );
		$this->assertSame( 'OK', $row['status'] );
		$this->assertSame( '', $row['note'] );
	}

	/**
	 * An end-of-life PHP version (below 8.2) yields a WARN row.
	 *
	 * @return void
	 */
	public function test_php_version_end_of_life_returns_warn(): void {
		$environment = Mockery::mock( Environment::class );
		$environment->shouldReceive( 'php_version' )->andReturn( '8.1.29' );

		$row = $this->invoke_check( $this->build_command( $environment ), 'check_php_version' );

		$this->assertSame( '8.1.29', $row['value'] );
		$this->assertSame( 'WARN', $row['status'] );
		$this->assertNotSame( '', $row['note'] );
	}

	/**
	 * The WARN note for an end-of-life PHP version names the specific version.
	 *
	 * Catches a class of bugs where the note is built without the
	 * version interpolated correctly (e.g. forgotten sprintf format).
	 *
	 * @return void
	 */
	public function test_php_version_warn_note_names_the_version(): void {
		$environment = Mockery::mock( Environment::class );
		$environment->shouldReceive( 'php_version' )->andReturn( '8.1.29' );

		$row = $this->invoke_check( $this->build_command( $environment ), 'check_php_version' );

		$this->assertStringContainsString( '8.1.29', $row['note'] );
		$this->assertStringContainsString( 'end-of-life', $row['note'] );
	}

	// -------------------------------------------------------------------------
	// check_wordpress_version
	// -------------------------------------------------------------------------

	/**
	 * A WordPress version meeting the minimum yields an OK row.
	 *
	 * @return void
	 */
	public function test_wordpress_version_meets_minimum_returns_ok(): void {
		$environment = Mockery::mock( Environment::class );
		$environment->shouldReceive( 'is_constant_defined' )
			->with( 'PONTIFEX_MINIMUM_WP_VERSION' )
			->andReturn( false );

		Functions\when( 'get_bloginfo' )->justReturn( '7.0' );

		$row = $this->invoke_check( $this->build_command( $environment ), 'check_wordpress_version' );

		$this->assertSame( 'WordPress version', $row['name'] );
		$this->assertSame( '7.0', $row['value'] );
		$this->assertSame( 'OK', $row['status'] );
		$this->assertSame( '', $row['note'] );
	}

	/**
	 * A WordPress version below the minimum yields a FAIL row.
	 *
	 * @return void
	 */
	public function test_wordpress_version_below_minimum_returns_fail(): void {
		$environment = Mockery::mock( Environment::class );
		$environment->shouldReceive( 'is_constant_defined' )
			->with( 'PONTIFEX_MINIMUM_WP_VERSION' )
			->andReturn( false );

		Functions\when( 'get_bloginfo' )->justReturn( '6.0' );

		$row = $this->invoke_check( $this->build_command( $environment ), 'check_wordpress_version' );

		$this->assertSame( '6.0', $row['value'] );
		$this->assertSame( 'FAIL', $row['status'] );
		$this->assertNotSame( '', $row['note'] );
	}

	/**
	 * When PONTIFEX_MINIMUM_WP_VERSION is defined, its value is used as the minimum.
	 *
	 * @return void
	 */
	public function test_wordpress_version_uses_constant_when_defined(): void {
		$environment = Mockery::mock( Environment::class );
		$environment->shouldReceive( 'is_constant_defined' )
			->with( 'PONTIFEX_MINIMUM_WP_VERSION' )
			->andReturn( true );
		$environment->shouldReceive( 'constant_value' )
			->with( 'PONTIFEX_MINIMUM_WP_VERSION' )
			->andReturn( '7.0' );

		// Site is running 6.9 — less than the bumped minimum of 7.0.
		Functions\when( 'get_bloginfo' )->justReturn( '6.9' );

		$row = $this->invoke_check( $this->build_command( $environment ), 'check_wordpress_version' );

		$this->assertSame( 'FAIL', $row['status'] );
		$this->assertStringContainsString( '7.0', $row['note'] );
	}

	/**
	 * When PONTIFEX_MINIMUM_WP_VERSION is not defined, the default '6.5' is used.
	 *
	 * @return void
	 */
	public function test_wordpress_version_uses_default_when_constant_not_defined(): void {
		$environment = Mockery::mock( Environment::class );
		$environment->shouldReceive( 'is_constant_defined' )
			->with( 'PONTIFEX_MINIMUM_WP_VERSION' )
			->andReturn( false );

		// 6.4 is below the hardcoded default 6.5.
		Functions\when( 'get_bloginfo' )->justReturn( '6.4' );

		$row = $this->invoke_check( $this->build_command( $environment ), 'check_wordpress_version' );

		$this->assertSame( 'FAIL', $row['status'] );
		$this->assertStringContainsString( '6.5', $row['note'] );
	}

	/**
	 * The FAIL note names the minimum WordPress version explicitly.
	 *
	 * @return void
	 */
	public function test_wordpress_version_fail_note_names_the_minimum(): void {
		$environment = Mockery::mock( Environment::class );
		$environment->shouldReceive( 'is_constant_defined' )
			->with( 'PONTIFEX_MINIMUM_WP_VERSION' )
			->andReturn( false );

		Functions\when( 'get_bloginfo' )->justReturn( '6.0' );

		$row = $this->invoke_check( $this->build_command( $environment ), 'check_wordpress_version' );

		$this->assertStringContainsString( 'WordPress', $row['note'] );
		$this->assertStringContainsString( '6.5', $row['note'] );
	}

	// -------------------------------------------------------------------------
	// check_database_version
	// -------------------------------------------------------------------------

	/**
	 * A present database version is reported verbatim with INFO status.
	 *
	 * @return void
	 */
	public function test_database_version_present_returns_info_with_value(): void {
		$wpdb_mock = Mockery::mock();
		$wpdb_mock->shouldReceive( 'get_var' )
			->with( 'SELECT VERSION()' )
			->andReturn( '8.4.0' );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Deliberate test setup; the global is unset after the assertion.
		$GLOBALS['wpdb'] = $wpdb_mock;

		$environment = Mockery::mock( Environment::class );

		$row = $this->invoke_check( $this->build_command( $environment ), 'check_database_version' );

		$this->assertSame( 'Database version', $row['name'] );
		$this->assertSame( '8.4.0', $row['value'] );
		$this->assertSame( 'INFO', $row['status'] );

		unset( $GLOBALS['wpdb'] );
	}

	/**
	 * An empty database version response yields the '(unknown)' placeholder.
	 *
	 * @return void
	 */
	public function test_database_version_empty_returns_unknown(): void {
		$wpdb_mock = Mockery::mock();
		$wpdb_mock->shouldReceive( 'get_var' )
			->with( 'SELECT VERSION()' )
			->andReturn( null );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Deliberate test setup; the global is unset after the assertion.
		$GLOBALS['wpdb'] = $wpdb_mock;

		$environment = Mockery::mock( Environment::class );

		$row = $this->invoke_check( $this->build_command( $environment ), 'check_database_version' );

		$this->assertSame( '(unknown)', $row['value'] );
		$this->assertSame( 'INFO', $row['status'] );

		unset( $GLOBALS['wpdb'] );
	}

	// -------------------------------------------------------------------------
	// check_memory_limit
	// -------------------------------------------------------------------------

	/**
	 * An unlimited memory_limit value of '-1' is reported with OK status.
	 *
	 * @return void
	 */
	public function test_memory_limit_unlimited_returns_ok(): void {
		$environment = Mockery::mock( Environment::class );
		$environment->shouldReceive( 'ini_get' )
			->with( 'memory_limit' )
			->andReturn( '-1' );

		Functions\when( 'wp_convert_hr_to_bytes' )->justReturn( 0 );

		$row = $this->invoke_check( $this->build_command( $environment ), 'check_memory_limit' );

		$this->assertSame( 'memory_limit', $row['name'] );
		$this->assertSame( 'unlimited', $row['value'] );
		$this->assertSame( 'OK', $row['status'] );
	}

	/**
	 * A memory_limit at or above the recommended floor returns OK.
	 *
	 * @return void
	 */
	public function test_memory_limit_sufficient_returns_ok(): void {
		$environment = Mockery::mock( Environment::class );
		$environment->shouldReceive( 'ini_get' )
			->with( 'memory_limit' )
			->andReturn( '256M' );

		Functions\when( 'wp_convert_hr_to_bytes' )->justReturn( 256 * 1024 * 1024 );

		$row = $this->invoke_check( $this->build_command( $environment ), 'check_memory_limit' );

		$this->assertSame( '256M', $row['value'] );
		$this->assertSame( 'OK', $row['status'] );
	}

	/**
	 * A memory_limit below the recommended floor returns WARN with guidance.
	 *
	 * @return void
	 */
	public function test_memory_limit_insufficient_returns_warn(): void {
		$environment = Mockery::mock( Environment::class );
		$environment->shouldReceive( 'ini_get' )
			->with( 'memory_limit' )
			->andReturn( '128M' );

		Functions\when( 'wp_convert_hr_to_bytes' )->justReturn( 128 * 1024 * 1024 );

		$row = $this->invoke_check( $this->build_command( $environment ), 'check_memory_limit' );

		$this->assertSame( '128M', $row['value'] );
		$this->assertSame( 'WARN', $row['status'] );
		$this->assertStringContainsString( '256M', $row['note'] );
	}

	// -------------------------------------------------------------------------
	// check_max_execution_time
	// -------------------------------------------------------------------------

	/**
	 * An unlimited max_execution_time of 0 returns OK.
	 *
	 * @return void
	 */
	public function test_max_execution_time_unlimited_returns_ok(): void {
		$environment = Mockery::mock( Environment::class );
		$environment->shouldReceive( 'ini_get' )
			->with( 'max_execution_time' )
			->andReturn( '0' );

		$row = $this->invoke_check( $this->build_command( $environment ), 'check_max_execution_time' );

		$this->assertSame( 'max_execution_time', $row['name'] );
		$this->assertSame( 'unlimited', $row['value'] );
		$this->assertSame( 'OK', $row['status'] );
	}

	/**
	 * A max_execution_time at or above the recommended floor returns OK.
	 *
	 * @return void
	 */
	public function test_max_execution_time_sufficient_returns_ok(): void {
		$environment = Mockery::mock( Environment::class );
		$environment->shouldReceive( 'ini_get' )
			->with( 'max_execution_time' )
			->andReturn( '120' );

		$row = $this->invoke_check( $this->build_command( $environment ), 'check_max_execution_time' );

		$this->assertSame( '120 seconds', $row['value'] );
		$this->assertSame( 'OK', $row['status'] );
	}

	/**
	 * A short max_execution_time returns WARN.
	 *
	 * @return void
	 */
	public function test_max_execution_time_short_returns_warn(): void {
		$environment = Mockery::mock( Environment::class );
		$environment->shouldReceive( 'ini_get' )
			->with( 'max_execution_time' )
			->andReturn( '30' );

		$row = $this->invoke_check( $this->build_command( $environment ), 'check_max_execution_time' );

		$this->assertSame( '30 seconds', $row['value'] );
		$this->assertSame( 'WARN', $row['status'] );
		$this->assertStringContainsString( '120s', $row['note'] );
	}

	// -------------------------------------------------------------------------
	// check_upload_max_filesize
	// -------------------------------------------------------------------------

	/**
	 * The effective upload limit is reported as INFO with the formatted size.
	 *
	 * @return void
	 */
	public function test_upload_max_filesize_returns_info_with_formatted_size(): void {
		$environment = Mockery::mock( Environment::class );

		// 300 MB.
		Functions\when( 'wp_max_upload_size' )->justReturn( 314572800 );
		Functions\when( 'size_format' )->justReturn( '300 MB' );

		$row = $this->invoke_check( $this->build_command( $environment ), 'check_upload_max_filesize' );

		$this->assertSame( 'Effective upload limit', $row['name'] );
		$this->assertSame( '300 MB', $row['value'] );
		$this->assertSame( 'INFO', $row['status'] );
	}

	// -------------------------------------------------------------------------
	// check_open_basedir
	// -------------------------------------------------------------------------

	/**
	 * An empty open_basedir setting returns OK with "(not set)".
	 *
	 * @return void
	 */
	public function test_open_basedir_not_set_returns_ok(): void {
		$environment = Mockery::mock( Environment::class );
		$environment->shouldReceive( 'ini_get' )
			->with( 'open_basedir' )
			->andReturn( '' );

		$row = $this->invoke_check( $this->build_command( $environment ), 'check_open_basedir' );

		$this->assertSame( 'open_basedir', $row['name'] );
		$this->assertSame( '(not set)', $row['value'] );
		$this->assertSame( 'OK', $row['status'] );
	}

	/**
	 * A configured open_basedir returns WARN with the path in value.
	 *
	 * @return void
	 */
	public function test_open_basedir_set_returns_warn(): void {
		$environment = Mockery::mock( Environment::class );
		$environment->shouldReceive( 'ini_get' )
			->with( 'open_basedir' )
			->andReturn( '/var/www/html:/tmp' );

		$row = $this->invoke_check( $this->build_command( $environment ), 'check_open_basedir' );

		$this->assertSame( '/var/www/html:/tmp', $row['value'] );
		$this->assertSame( 'WARN', $row['status'] );
		$this->assertStringContainsString( 'open_basedir', $row['note'] );
	}
}
