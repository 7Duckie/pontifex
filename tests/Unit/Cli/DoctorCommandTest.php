<?php
/**
 * Structural smoke tests for the DoctorCommand class.
 *
 * @package Pontifex\Tests\Unit\Cli
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Cli;

use Mockery;
use PHPUnit\Framework\TestCase;
use Pontifex\Cli\DoctorCommand;
use Pontifex\Environment\Environment;
use Pontifex\WordPress\WordPressContext;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Asserts the structural invariants of DoctorCommand.
 *
 * These tests cover only the structural contract of the class: it
 * exists, is final, exposes __invoke, and the invoke signature is
 * the void return WP-CLI expects. They run without WordPress and
 * without brain/monkey because they assert facts about the class
 * shape itself rather than about runtime behavior.
 *
 * Behavioral assertions — what each check method actually reports
 * under different environmental conditions — live in separate test
 * classes that extend Pontifex\Tests\TestCase and use brain/monkey
 * to mock WordPress functions and a mock Environment to control
 * PHP-level inputs. The check_symlink_support() tests below are the one
 * deliberate exception: that slice's file ownership assigned this file,
 * not the DoctorCommand/ directory, so its behavioural tests live here —
 * using Mockery directly (no WordPress functions are involved) rather than
 * pulling in Pontifex\Tests\TestCase's brain/monkey machinery for a single
 * check.
 */
final class DoctorCommandTest extends TestCase {

	/**
	 * Close Mockery expectations after each test.
	 *
	 * Only the check_symlink_support() tests below use Mockery; the
	 * structural tests above need no teardown, but closing unconditionally
	 * is harmless when nothing was mocked.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Mockery::close();
		parent::tearDown();
	}

	/**
	 * The DoctorCommand class must be present and loadable via PSR-4.
	 *
	 * @return void
	 */
	public function test_class_exists(): void {
		$this->assertTrue( class_exists( DoctorCommand::class ) );
	}

	/**
	 * DoctorCommand must be marked final to prevent extension.
	 *
	 * Loosening this requires deliberate review; it's a contract that
	 * external code does not depend on subclassing the command.
	 *
	 * @return void
	 */
	public function test_class_is_final(): void {
		$reflection = new ReflectionClass( DoctorCommand::class );
		$this->assertTrue(
			$reflection->isFinal(),
			'DoctorCommand is marked final to prevent extension; loosening this requires deliberate review.'
		);
	}

	/**
	 * WP-CLI single-command classes must expose __invoke.
	 *
	 * @return void
	 */
	public function test_invoke_method_exists(): void {
		$this->assertTrue(
			method_exists( DoctorCommand::class, '__invoke' ),
			'WP-CLI single-command classes must expose __invoke.'
		);
	}

	/**
	 * The __invoke signature must declare a void return type.
	 *
	 * WP-CLI relies on commands returning nothing; an explicit return
	 * type catches typos and accidental drift in the contract.
	 *
	 * @return void
	 */
	public function test_invoke_returns_void(): void {
		$invoke_reflection = new ReflectionMethod( DoctorCommand::class, '__invoke' );
		$return_type       = $invoke_reflection->getReturnType();

		$this->assertInstanceOf(
			ReflectionNamedType::class,
			$return_type,
			'__invoke must declare an explicit return type.'
		);
		$this->assertSame(
			'void',
			$return_type->getName(),
			'WP-CLI single-command __invoke must return void.'
		);
	}

	// -------------------------------------------------------------------------
	// check_symlink_support
	// -------------------------------------------------------------------------

	/**
	 * Invoke the private check_symlink_support() method via reflection.
	 *
	 * Mirrors the invoke_check() helper the DoctorCommand/ behavioural test
	 * classes already use (see, for example,
	 * tests/Unit/Cli/DoctorCommand/RuntimeAndPhpConfigChecksTest); the check
	 * method stays private because nothing outside collect_all_checks()
	 * needs to call it individually in production code.
	 *
	 * @param Environment      $environment       The mock environment to inject.
	 * @param WordPressContext $wordpress_context The mock WordPress context to inject.
	 * @return array<string, string> The row returned by check_symlink_support().
	 */
	private function invoke_check_symlink_support( Environment $environment, WordPressContext $wordpress_context ): array {
		$command    = new DoctorCommand( $environment, $wordpress_context );
		$reflection = new ReflectionMethod( $command, 'check_symlink_support' );
		return (array) $reflection->invoke( $command );
	}

	/**
	 * Both symlink() and readlink() available returns an OK row with no note.
	 *
	 * @return void
	 */
	public function test_symlink_support_both_available_returns_ok(): void {
		$environment = Mockery::mock( Environment::class );
		$environment->shouldReceive( 'function_exists' )->with( 'symlink' )->andReturn( true );
		$environment->shouldReceive( 'function_exists' )->with( 'readlink' )->andReturn( true );

		$wordpress_context = Mockery::mock( WordPressContext::class );

		$row = $this->invoke_check_symlink_support( $environment, $wordpress_context );

		$this->assertSame( 'PHP config', $row['category'] );
		$this->assertSame( 'Symlink support', $row['name'] );
		$this->assertSame( 'available', $row['value'] );
		$this->assertSame( 'OK', $row['status'] );
		$this->assertSame( '', $row['note'] );
	}

	/**
	 * A missing symlink() returns a WARN row whose note names restore, the operation it blocks.
	 *
	 * @return void
	 */
	public function test_symlink_support_symlink_missing_warns_naming_restore(): void {
		$environment = Mockery::mock( Environment::class );
		$environment->shouldReceive( 'function_exists' )->with( 'symlink' )->andReturn( false );
		$environment->shouldReceive( 'function_exists' )->with( 'readlink' )->andReturn( true );

		$wordpress_context = Mockery::mock( WordPressContext::class );

		$row = $this->invoke_check_symlink_support( $environment, $wordpress_context );

		$this->assertSame( 'unavailable (symlink)', $row['value'] );
		$this->assertSame( 'WARN', $row['status'] );
		$this->assertStringContainsString( 'symlink()', $row['note'] );
		$this->assertStringContainsString( 'restored', $row['note'] );
		$this->assertStringNotContainsString( 'backup', $row['note'] );
	}

	/**
	 * A missing readlink() returns a WARN row whose note names backup, the operation it blocks.
	 *
	 * @return void
	 */
	public function test_symlink_support_readlink_missing_warns_naming_backup(): void {
		$environment = Mockery::mock( Environment::class );
		$environment->shouldReceive( 'function_exists' )->with( 'symlink' )->andReturn( true );
		$environment->shouldReceive( 'function_exists' )->with( 'readlink' )->andReturn( false );

		$wordpress_context = Mockery::mock( WordPressContext::class );

		$row = $this->invoke_check_symlink_support( $environment, $wordpress_context );

		$this->assertSame( 'unavailable (readlink)', $row['value'] );
		$this->assertSame( 'WARN', $row['status'] );
		$this->assertStringContainsString( 'readlink()', $row['note'] );
		$this->assertStringContainsString( 'backup', $row['note'] );
		$this->assertStringNotContainsString( 'restored', $row['note'] );
	}

	/**
	 * Both primitives missing returns a WARN row naming both affected operations.
	 *
	 * @return void
	 */
	public function test_symlink_support_both_missing_warns_naming_both_operations(): void {
		$environment = Mockery::mock( Environment::class );
		$environment->shouldReceive( 'function_exists' )->with( 'symlink' )->andReturn( false );
		$environment->shouldReceive( 'function_exists' )->with( 'readlink' )->andReturn( false );

		$wordpress_context = Mockery::mock( WordPressContext::class );

		$row = $this->invoke_check_symlink_support( $environment, $wordpress_context );

		$this->assertSame( 'unavailable (symlink, readlink)', $row['value'] );
		$this->assertSame( 'WARN', $row['status'] );
		$this->assertStringContainsString( 'restored', $row['note'] );
		$this->assertStringContainsString( 'backup', $row['note'] );
	}
}
