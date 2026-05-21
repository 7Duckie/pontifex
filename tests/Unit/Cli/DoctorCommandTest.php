<?php
/**
 * Structural smoke tests for the DoctorCommand class.
 *
 * @package Pontifex\Tests\Unit\Cli
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Pontifex\Cli\DoctorCommand;
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
 * PHP-level inputs.
 */
final class DoctorCommandTest extends TestCase {

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
}
