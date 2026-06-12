<?php
/**
 * Tests for ExportCommand's pure counter helpers.
 *
 * @package Pontifex\Tests\Unit\Cli\ExportCommand
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Cli\ExportCommand;

use PHPUnit\Framework\TestCase;
use Pontifex\Cli\ExportCommand;
use ReflectionMethod;

/**
 * Tests for ExportCommand's pure counter arithmetic.
 *
 * Both merge_counters and counter_int are private static helpers with no
 * I/O — they take arrays and return arrays/ints — so they are
 * exercised directly via reflection, the same pattern HelperMethodsTest
 * uses. The read-modify-write wiring around them (bump_counters, which
 * talks to the WordPress-context seam) is covered behaviourally where
 * __invoke runs; here we pin the arithmetic and its tolerance of
 * missing or corrupt stored data.
 */
final class CountersTest extends TestCase {

	/**
	 * Invoke a private static method on ExportCommand via reflection.
	 *
	 * @param string $method_name The method to invoke.
	 * @param mixed  ...$args     Arguments to pass.
	 * @return mixed The method's return value.
	 */
	private function invoke_static( string $method_name, ...$args ) {
		$reflection = new ReflectionMethod( ExportCommand::class, $method_name );
		return $reflection->invoke( null, ...$args );
	}

	// -------------------------------------------------------------------------
	// merge_counters
	// -------------------------------------------------------------------------

	/**
	 * Empty current and empty delta yield all four counters at zero.
	 *
	 * @return void
	 */
	public function test_merge_empty_yields_four_zeroes(): void {
		$merged = $this->invoke_static( 'merge_counters', array(), array() );

		$this->assertSame(
			array(
				'attempted'      => 0,
				'succeeded'      => 0,
				'failed'         => 0,
				'bytes_exported' => 0,
			),
			$merged
		);
	}

	/**
	 * A delta against empty current applies cleanly.
	 *
	 * @return void
	 */
	public function test_merge_delta_against_empty(): void {
		$merged = $this->invoke_static( 'merge_counters', array(), array( 'attempted' => 1 ) );

		$this->assertSame( 1, $merged['attempted'] );
		$this->assertSame( 0, $merged['succeeded'] );
		$this->assertSame( 0, $merged['failed'] );
		$this->assertSame( 0, $merged['bytes_exported'] );
	}

	/**
	 * A delta adds to existing stored values key by key.
	 *
	 * @return void
	 */
	public function test_merge_adds_to_existing(): void {
		$current = array(
			'attempted'      => 5,
			'succeeded'      => 2,
			'failed'         => 1,
			'bytes_exported' => 100,
		);
		$delta   = array(
			'succeeded'      => 1,
			'bytes_exported' => 50,
		);

		$merged = $this->invoke_static( 'merge_counters', $current, $delta );

		$this->assertSame(
			array(
				'attempted'      => 5,
				'succeeded'      => 3,
				'failed'         => 1,
				'bytes_exported' => 150,
			),
			$merged
		);
	}

	/**
	 * Corrupt, partial, or non-numeric stored values degrade to zero.
	 *
	 * @return void
	 */
	public function test_merge_tolerates_corrupt_stored_values(): void {
		$current = array(
			'attempted'      => 'not-a-number',
			'succeeded'      => null,
			'bytes_exported' => '7',
			// 'failed' missing entirely.
		);

		$merged = $this->invoke_static( 'merge_counters', $current, array( 'failed' => 2 ) );

		$this->assertSame(
			array(
				'attempted'      => 0,
				'succeeded'      => 0,
				'failed'         => 2,
				'bytes_exported' => 7,
			),
			$merged
		);
	}

	/**
	 * Only the four known keys are returned; stray keys are dropped.
	 *
	 * @return void
	 */
	public function test_merge_drops_unknown_keys(): void {
		$merged = $this->invoke_static(
			'merge_counters',
			array(
				'attempted' => 1,
				'mystery'   => 99,
			),
			array()
		);

		$this->assertArrayNotHasKey( 'mystery', $merged );
		$this->assertCount( 4, $merged );
	}

	// -------------------------------------------------------------------------
	// counter_int
	// -------------------------------------------------------------------------

	/**
	 * A present integer value is returned as-is.
	 *
	 * @return void
	 */
	public function test_counter_int_reads_present_integer(): void {
		$this->assertSame( 42, $this->invoke_static( 'counter_int', array( 'k' => 42 ), 'k' ) );
	}

	/**
	 * A numeric string is coerced to an integer.
	 *
	 * @return void
	 */
	public function test_counter_int_coerces_numeric_string(): void {
		$this->assertSame( 42, $this->invoke_static( 'counter_int', array( 'k' => '42' ), 'k' ) );
	}

	/**
	 * A missing key returns zero.
	 *
	 * @return void
	 */
	public function test_counter_int_missing_key_is_zero(): void {
		$this->assertSame( 0, $this->invoke_static( 'counter_int', array(), 'k' ) );
	}

	/**
	 * A non-numeric value returns zero.
	 *
	 * @return void
	 */
	public function test_counter_int_non_numeric_is_zero(): void {
		$this->assertSame( 0, $this->invoke_static( 'counter_int', array( 'k' => 'abc' ), 'k' ) );
	}
}
