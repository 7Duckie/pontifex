<?php
/**
 * Unit tests for DestinationRetention.
 *
 * @package Pontifex\Tests\Unit\Destination
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Destination;

use Pontifex\Destination\DestinationException;
use Pontifex\Destination\DestinationRetention;
use Pontifex\Tests\TestCase;
use Pontifex\Tests\Unit\Destination\Fakes\FakeDestinationAdapter;

/**
 * Behavioural coverage of {@see DestinationRetention}: the oldest-first sort
 * by remote name, the surplus-only delete, the retention-0/under-keep
 * no-op paths, the floor guarantee that the newest survivor is never
 * deleted, the best-effort swallow of an individual delete failure, and the
 * propagation of a listing failure.
 */
final class DestinationRetentionTest extends TestCase {

	/**
	 * Pruning down to fewer archives than are held deletes exactly the
	 * oldest surplus, proven against unsorted input so the sort itself is
	 * exercised, and leaves the newest archives untouched.
	 *
	 * @return void
	 */
	public function test_prune_deletes_the_oldest_surplus(): void {
		$adapter = new FakeDestinationAdapter( array( 'c', 'a', 'e', 'b', 'd' ) );

		$deleted = ( new DestinationRetention( $adapter, 3 ) )->prune();

		$this->assertSame( array( 'a', 'b' ), $deleted );
		$this->assertSame( array( 'a', 'b' ), $adapter->deleted_names() );
	}

	/**
	 * A retention of 0 means "keep all": prune() does not list or delete
	 * anything.
	 *
	 * @return void
	 */
	public function test_retention_zero_prunes_nothing(): void {
		$adapter = new FakeDestinationAdapter( array( 'a', 'b', 'c', 'd', 'e' ) );

		$deleted = ( new DestinationRetention( $adapter, 0 ) )->prune();

		$this->assertSame( array(), $deleted );
		$this->assertSame( array(), $adapter->deleted_names() );
	}

	/**
	 * When the destination holds exactly the keep count, nothing is pruned.
	 *
	 * @return void
	 */
	public function test_prune_at_exactly_the_keep_count_deletes_nothing(): void {
		$adapter = new FakeDestinationAdapter( array( 'a', 'b', 'c' ) );

		$deleted = ( new DestinationRetention( $adapter, 3 ) )->prune();

		$this->assertSame( array(), $deleted );
		$this->assertSame( array(), $adapter->deleted_names() );
	}

	/**
	 * When the destination holds fewer than the keep count, nothing is pruned.
	 *
	 * @return void
	 */
	public function test_prune_under_the_keep_count_deletes_nothing(): void {
		$adapter = new FakeDestinationAdapter( array( 'a', 'b' ) );

		$deleted = ( new DestinationRetention( $adapter, 3 ) )->prune();

		$this->assertSame( array(), $deleted );
		$this->assertSame( array(), $adapter->deleted_names() );
	}

	/**
	 * The floor guarantee: keeping 1 archive out of 3 deletes the two
	 * oldest and never the newest survivor.
	 *
	 * @return void
	 */
	public function test_prune_never_deletes_the_newest_survivor(): void {
		$adapter = new FakeDestinationAdapter( array( 'a', 'b', 'c' ) );

		$deleted = ( new DestinationRetention( $adapter, 1 ) )->prune();

		$this->assertSame( array( 'a', 'b' ), $deleted );
		$this->assertNotContains( 'c', $deleted );
	}

	/**
	 * An individual delete failure is swallowed: the failing archive is
	 * left in place, pruning continues, and prune() does not throw.
	 *
	 * @return void
	 */
	public function test_a_delete_failure_is_swallowed_and_pruning_continues(): void {
		$adapter = new FakeDestinationAdapter( array( 'a', 'b', 'c' ), false, array( 'a' ) );

		$deleted = ( new DestinationRetention( $adapter, 1 ) )->prune();

		$this->assertSame( array( 'b' ), $deleted );
		$this->assertSame( array( 'b' ), $adapter->deleted_names() );
	}

	/**
	 * A listing failure propagates as a DestinationException rather than
	 * being swallowed.
	 *
	 * @return void
	 */
	public function test_a_listing_failure_propagates(): void {
		$adapter = new FakeDestinationAdapter( array( 'a', 'b', 'c' ), true );

		$this->expectException( DestinationException::class );

		( new DestinationRetention( $adapter, 1 ) )->prune();
	}
}
