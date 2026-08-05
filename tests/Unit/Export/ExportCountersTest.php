<?php
/**
 * Tests for ExportCounters — the one master for the persisted export totals.
 *
 * @package Pontifex\Tests\Unit\Export
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Export;

use PHPUnit\Framework\TestCase;
use Pontifex\Export\ExportCounters;

/**
 * Covers the tolerant merge and the whitelist that made four writers drift.
 *
 * Four export paths used to keep their own copy of this schema, each rebuilding
 * the stored array from its own hardcoded key list. Because every writer
 * whitelists, a key one knew and another did not was not merely ignored by the
 * second — it was deleted the moment that path ran. Adding a counter meant
 * finding all four, and missing one meant the new counter silently vanished
 * whenever a backup happened to take the path that had not been updated.
 */
final class ExportCountersTest extends TestCase {

	/**
	 * Existing totals are added to, not replaced.
	 *
	 * @return void
	 */
	public function test_a_delta_is_added_to_the_stored_totals(): void {
		$merged = ExportCounters::merge(
			array(
				'attempted' => 3,
				'succeeded' => 2,
			),
			array(
				'attempted' => 1,
				'succeeded' => 1,
			)
		);

		$this->assertSame( 4, $merged['attempted'] );
		$this->assertSame( 3, $merged['succeeded'] );
	}

	/**
	 * Every known counter is present afterwards, whatever the delta touched.
	 *
	 * @return void
	 */
	public function test_every_known_counter_survives_a_partial_delta(): void {
		$merged = ExportCounters::merge( array(), array( 'attempted' => 1 ) );

		foreach ( ExportCounters::KEYS as $key ) {
			$this->assertArrayHasKey( $key, $merged, "Counter {$key} must survive a merge that did not mention it." );
		}
		$this->assertSame( 1, $merged['attempted'] );
		$this->assertSame( 0, $merged['succeeded'] );
	}

	/**
	 * A corrupt or absent option reads as zero rather than throwing.
	 *
	 * These are counters on somebody's live site. A total that has been mangled
	 * by something else is not worth failing a backup over.
	 *
	 * @return void
	 */
	public function test_a_corrupt_stored_value_reads_as_zero(): void {
		foreach ( array( null, 'nonsense', 42, array( 'attempted' => 'seven' ) ) as $stored ) {
			$merged = ExportCounters::merge( $stored, array( 'attempted' => 2 ) );
			$this->assertSame( 2, $merged['attempted'], 'A stored value that is not a usable number counts as zero.' );
		}
	}

	/**
	 * An unrecognised key is dropped, and that is deliberate.
	 *
	 * The whitelist stays: these counters are shown to the operator and travel
	 * in a support bundle, so an unknown key is not carried along on trust.
	 * Keeping the list in ONE place is what makes that safe rather than lossy —
	 * previously each writer had its own, so writers disagreed about which keys
	 * existed and deleted each other's.
	 *
	 * @return void
	 */
	public function test_an_unrecognised_key_is_dropped(): void {
		$merged = ExportCounters::merge(
			array(
				'attempted' => 1,
				'invented'  => 9,
			),
			array()
		);

		$this->assertArrayNotHasKey( 'invented', $merged );
		$this->assertSame( array_values( ExportCounters::KEYS ), array_keys( $merged ), 'The merged array is exactly the known counters, in order.' );
	}
}
