<?php
/**
 * Base test case for Pontifex unit tests using brain/monkey and Mockery.
 *
 * @package Pontifex\Tests
 */

declare(strict_types=1);

namespace Pontifex\Tests;

use Brain\Monkey;
use Mockery;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Base class for unit tests that mock WordPress functions or static methods.
 *
 * Tests that need to stub or assert WordPress function calls extend this
 * class instead of PHPUnit\Framework\TestCase. brain/monkey is set up
 * before each test and torn down after, so mocks do not leak between
 * tests.
 *
 * Mockery is also closed in tearDown. Mockery handles two cases the
 * library distinguishes from brain/monkey: (1) mocking object instances
 * (Mockery::mock(SomeClass::class)) and (2) mocking static methods on
 * named classes via alias mocks (Mockery::mock('alias:WP_CLI')). Both
 * shapes require Mockery::close() at end-of-test to verify the
 * "shouldReceive" expectations were met and to release alias-mock state
 * so it does not leak across tests.
 *
 * Tests that do not need WordPress mocking (e.g. pure structural tests
 * like DoctorCommandTest's class-shape assertions) can extend PHPUnit's
 * TestCase directly and skip the brain/monkey and Mockery overhead.
 */
abstract class TestCase extends PHPUnitTestCase {

	/**
	 * Set up brain/monkey before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// The presentation layers wrap user-facing strings in __() and escape
		// output with the esc_*() helpers. No WordPress runtime is loaded here,
		// so stub them to return their first argument — the untranslated,
		// unescaped string the output assertions expect. _n() keeps its real
		// singular/plural selection so count-sensitive output stays testable.
		Monkey\Functions\stubs(
			array(
				'__',
				'esc_html',
				'esc_html__',
				'esc_attr',
				'esc_attr__',
				'_n' => static function ( string $single, string $plural, int $number ): string {
					return 1 === $number ? $single : $plural;
				},
			)
		);
	}

	/**
	 * Tear down brain/monkey and Mockery after each test.
	 *
	 * Order matters: Mockery::close() verifies expectations and may
	 * throw if a shouldReceive() was not satisfied; tearing down
	 * brain/monkey first would lose context for that diagnostic.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Mockery::close();
		Monkey\tearDown();
		parent::tearDown();
	}
}
