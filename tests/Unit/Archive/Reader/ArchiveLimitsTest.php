<?php
/**
 * Behavioural tests for ArchiveLimits.
 *
 * @package Pontifex\Tests\Unit\Archive\Reader
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Archive\Reader;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pontifex\Archive\Reader\ArchiveLimits;

/**
 * Behavioural tests for the ArchiveLimits value object.
 *
 * Verifies the object's invariants:
 *
 *  - The default factory exposes the documented ceilings.
 *  - max_total_for_archive() takes the smaller of the ratio bound and
 *    the absolute ceiling, so small archives get a generous expansion
 *    and large ones are held to the ceiling.
 *  - with_max_total_bytes() returns a modified copy and never mutates
 *    the original (the seam for a future --max-size override).
 *  - The constructor rejects any non-positive limit.
 *  - Getters return exactly what the constructor was given.
 */
final class ArchiveLimitsTest extends TestCase {

	/**
	 * The default factory must expose the documented ceilings.
	 *
	 * @return void
	 */
	public function test_defaults_expose_documented_values(): void {
		$limits = ArchiveLimits::defaults();

		$this->assertSame( 50000, $limits->max_entry_count() );
		$this->assertSame( 2147483648, $limits->max_entry_bytes() );
		$this->assertSame( 100, $limits->max_total_ratio() );
		$this->assertSame( 1099511627776, $limits->max_total_bytes() );
	}

	/**
	 * The default constants must match the values the factory returns.
	 *
	 * @return void
	 */
	public function test_default_constants_match_factory(): void {
		$this->assertSame( 50000, ArchiveLimits::DEFAULT_MAX_ENTRY_COUNT );
		$this->assertSame( 2147483648, ArchiveLimits::DEFAULT_MAX_ENTRY_BYTES );
		$this->assertSame( 100, ArchiveLimits::DEFAULT_MAX_TOTAL_RATIO );
		$this->assertSame( 1099511627776, ArchiveLimits::DEFAULT_MAX_TOTAL_BYTES );
	}

	/**
	 * For a small archive the ratio bound is below the ceiling and must win.
	 *
	 * A 1 MiB archive at the default ratio of 100 yields a 100 MiB
	 * budget, well under the 1 TiB ceiling.
	 *
	 * @return void
	 */
	public function test_max_total_for_archive_uses_ratio_bound_for_small_archive(): void {
		$limits = ArchiveLimits::defaults();

		$this->assertSame( 100 * 1048576, $limits->max_total_for_archive( 1048576 ) );
	}

	/**
	 * For a large archive the ratio bound exceeds the ceiling, so the ceiling must win.
	 *
	 * A 50 GiB archive at ratio 100 would permit ~5 TiB, so the 1 TiB
	 * absolute ceiling applies instead.
	 *
	 * @return void
	 */
	public function test_max_total_for_archive_uses_ceiling_for_large_archive(): void {
		$limits = ArchiveLimits::defaults();

		$this->assertSame( 1099511627776, $limits->max_total_for_archive( 50 * 1073741824 ) );
	}

	/**
	 * Calling with_max_total_bytes() must return a copy carrying the new ceiling.
	 *
	 * @return void
	 */
	public function test_with_max_total_bytes_returns_modified_copy(): void {
		$limits  = ArchiveLimits::defaults();
		$relaxed = $limits->with_max_total_bytes( 5368709120 );

		$this->assertSame( 5368709120, $relaxed->max_total_bytes() );
		$this->assertSame( $limits->max_entry_count(), $relaxed->max_entry_count() );
		$this->assertSame( $limits->max_entry_bytes(), $relaxed->max_entry_bytes() );
		$this->assertSame( $limits->max_total_ratio(), $relaxed->max_total_ratio() );
	}

	/**
	 * Calling with_max_total_bytes() must not mutate the original instance.
	 *
	 * @return void
	 */
	public function test_with_max_total_bytes_leaves_original_unchanged(): void {
		$limits = ArchiveLimits::defaults();
		$limits->with_max_total_bytes( 1 );

		$this->assertSame( 1099511627776, $limits->max_total_bytes() );
	}

	/**
	 * The constructor must reject a non-positive entry count.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_zero_entry_count(): void {
		$this->expectException( InvalidArgumentException::class );

		new ArchiveLimits( 0, 1, 1, 1 );
	}

	/**
	 * The constructor must reject a non-positive per-entry byte ceiling.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_zero_entry_bytes(): void {
		$this->expectException( InvalidArgumentException::class );

		new ArchiveLimits( 1, 0, 1, 1 );
	}

	/**
	 * The constructor must reject a non-positive total ratio.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_zero_total_ratio(): void {
		$this->expectException( InvalidArgumentException::class );

		new ArchiveLimits( 1, 1, 0, 1 );
	}

	/**
	 * The constructor must reject a non-positive absolute ceiling.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_zero_total_bytes(): void {
		$this->expectException( InvalidArgumentException::class );

		new ArchiveLimits( 1, 1, 1, 0 );
	}

	/**
	 * Getters must return exactly the values supplied to the constructor.
	 *
	 * @return void
	 */
	public function test_getters_return_constructor_values(): void {
		$limits = new ArchiveLimits( 7, 11, 13, 17 );

		$this->assertSame( 7, $limits->max_entry_count() );
		$this->assertSame( 11, $limits->max_entry_bytes() );
		$this->assertSame( 13, $limits->max_total_ratio() );
		$this->assertSame( 17, $limits->max_total_bytes() );
	}
}
