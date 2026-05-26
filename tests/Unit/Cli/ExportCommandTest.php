<?php
/**
 * Structural smoke tests for the ExportCommand class.
 *
 * @package Pontifex\Tests\Unit\Cli
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Pontifex\Cli\ExportCommand;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Asserts the structural invariants of ExportCommand.
 *
 * Like DoctorCommandTest, these tests cover only the structural
 * contract of the class: it exists, is final, exposes __invoke, and
 * the invoke signature is the void return WP-CLI expects. They run
 * without WordPress and without brain/monkey because they assert
 * facts about the class shape itself rather than about runtime
 * behavior.
 *
 * Behavioural assertions about the pure helpers — flag parsing,
 * exclude-file parsing, exclusion-rule construction — live in the
 * sibling directory tests/Unit/Cli/ExportCommand/. The __invoke
 * orchestration is exercised end-to-end via Phase 5 integration
 * tests against a real WordPress installation; mocking out
 * ManifestBuilder, WP_CLI's confirm/error/log calls, and the
 * filesystem to unit-test the orchestration adds little real
 * coverage and is deliberately deferred.
 */
final class ExportCommandTest extends TestCase {

	/**
	 * The ExportCommand class must be present and loadable via PSR-4.
	 *
	 * @return void
	 */
	public function test_class_exists(): void {
		$this->assertTrue( class_exists( ExportCommand::class ) );
	}

	/**
	 * ExportCommand must be marked final to prevent extension.
	 *
	 * Loosening this requires deliberate review; it's a contract that
	 * external code does not depend on subclassing the command.
	 *
	 * @return void
	 */
	public function test_class_is_final(): void {
		$reflection = new ReflectionClass( ExportCommand::class );
		$this->assertTrue(
			$reflection->isFinal(),
			'ExportCommand is marked final to prevent extension; loosening this requires deliberate review.'
		);
	}

	/**
	 * WP-CLI single-command classes must expose __invoke.
	 *
	 * @return void
	 */
	public function test_invoke_method_exists(): void {
		$this->assertTrue(
			method_exists( ExportCommand::class, '__invoke' ),
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
		$invoke_reflection = new ReflectionMethod( ExportCommand::class, '__invoke' );
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

	/**
	 * The constructor must accept zero arguments (all defaults).
	 *
	 * WP-CLI registers commands by class name and instantiates them
	 * with no constructor arguments. The class contract requires the
	 * constructor to make every parameter optional with a sensible
	 * default.
	 *
	 * @return void
	 */
	public function test_constructor_accepts_no_arguments(): void {
		$reflection  = new ReflectionClass( ExportCommand::class );
		$constructor = $reflection->getConstructor();

		$this->assertNotNull( $constructor, 'ExportCommand must declare a constructor.' );
		foreach ( $constructor->getParameters() as $parameter ) {
			$this->assertTrue(
				$parameter->isOptional(),
				sprintf( 'ExportCommand constructor parameter $%s must be optional.', $parameter->getName() )
			);
		}
	}
}
