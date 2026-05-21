<?php
/**
 * Base test case for Pontifex unit tests using brain/monkey.
 *
 * @package Pontifex\Tests
 */

declare(strict_types=1);

namespace Pontifex\Tests;

use Brain\Monkey;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Base class for unit tests that mock WordPress functions.
 *
 * Tests that need to stub or assert WordPress function calls extend this
 * class instead of PHPUnit\Framework\TestCase. brain/monkey is set up
 * before each test and torn down after, so mocks do not leak between
 * tests.
 *
 * Tests that do not need WordPress mocking (e.g. pure structural tests
 * like DoctorCommandTest's class-shape assertions) can extend PHPUnit's
 * TestCase directly and skip the brain/monkey overhead.
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
	}

	/**
	 * Tear down brain/monkey after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}
