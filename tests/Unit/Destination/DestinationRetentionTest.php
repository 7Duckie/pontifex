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
	 * A file Pontifex did not name is never deleted, whatever it sorts as.
	 *
	 * Retention sorts by name and deletes from the front, which is age order
	 * only for the canonical `pontifex-backup-<UTC>.wpmig` shape. Nothing
	 * enforced that: the SFTP adapter lists every `.wpmig`, and
	 * `export --destination` uploads under whatever basename `--output` was
	 * given. So an operator running
	 *
	 *     wp pontifex export --output=/backups/before-upgrade.wpmig --destination=nas
	 *
	 * put `before-upgrade.wpmig` into the rotation. It sorts ahead of every
	 * timestamped name, so it was deleted as "the oldest" — the backup taken
	 * minutes earlier, specifically so it would be there if the upgrade went
	 * wrong — and the CLI logged it as pruning an old archive.
	 *
	 * A keep-count is a promise about the backups Pontifex made. Anything else
	 * in the directory is not part of that rotation and is not ours to delete.
	 *
	 * @return void
	 */
	public function test_prune_leaves_alone_a_file_pontifex_did_not_name(): void {
		$adapter = new FakeDestinationAdapter(
			array(
				'pontifex-backup-20260101T030000Z.wpmig',
				'pontifex-backup-20260701T030000Z.wpmig',
				'before-upgrade.wpmig',
			)
		);

		$deleted = ( new DestinationRetention( $adapter, 2 ) )->prune();

		$this->assertSame( array(), $deleted, 'Two generated archives with keep=2 leaves nothing to prune.' );
		$this->assertNotContains(
			'before-upgrade.wpmig',
			$adapter->deleted_names(),
			'A hand-named archive must never be deleted by retention — it sorts first but is the newest.'
		);
	}

	/**
	 * Surplus is counted over generated archives only, ignoring anything else.
	 *
	 * The twin of the test above. Without it, a fix that simply skipped
	 * non-conforming names when DELETING — while still counting them towards the
	 * surplus — would pass, and would prune one generated archive too many.
	 *
	 * @return void
	 */
	public function test_prune_counts_surplus_over_generated_archives_only(): void {
		$adapter = new FakeDestinationAdapter(
			array(
				'pontifex-backup-20260101T030000Z.wpmig',
				'pontifex-backup-20260201T030000Z.wpmig',
				'pontifex-backup-20260301T030000Z.wpmig',
				'notes.txt.wpmig',
				'before-upgrade.wpmig',
			)
		);

		$deleted = ( new DestinationRetention( $adapter, 2 ) )->prune();

		$this->assertSame(
			array( 'pontifex-backup-20260101T030000Z.wpmig' ),
			$deleted,
			'Exactly one generated archive is surplus to a keep of 2; the two other files are not counted.'
		);
	}

	/**
	 * Pruning down to fewer archives than are held deletes exactly the
	 * oldest surplus, proven against unsorted input so the sort itself is
	 * exercised, and leaves the newest archives untouched.
	 *
	 * @return void
	 */
	public function test_prune_deletes_the_oldest_surplus(): void {
		$adapter = new FakeDestinationAdapter( array( 'pontifex-backup-20260103T030000Z.wpmig', 'pontifex-backup-20260101T030000Z.wpmig', 'pontifex-backup-20260105T030000Z.wpmig', 'pontifex-backup-20260102T030000Z.wpmig', 'pontifex-backup-20260104T030000Z.wpmig' ) );

		$deleted = ( new DestinationRetention( $adapter, 3 ) )->prune();

		$this->assertSame( array( 'pontifex-backup-20260101T030000Z.wpmig', 'pontifex-backup-20260102T030000Z.wpmig' ), $deleted );
		$this->assertSame( array( 'pontifex-backup-20260101T030000Z.wpmig', 'pontifex-backup-20260102T030000Z.wpmig' ), $adapter->deleted_names() );
	}

	/**
	 * A retention of 0 means "keep all": prune() does not list or delete
	 * anything.
	 *
	 * @return void
	 */
	public function test_retention_zero_prunes_nothing(): void {
		$adapter = new FakeDestinationAdapter( array( 'pontifex-backup-20260101T030000Z.wpmig', 'pontifex-backup-20260102T030000Z.wpmig', 'pontifex-backup-20260103T030000Z.wpmig', 'pontifex-backup-20260104T030000Z.wpmig', 'pontifex-backup-20260105T030000Z.wpmig' ) );

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
		$adapter = new FakeDestinationAdapter( array( 'pontifex-backup-20260101T030000Z.wpmig', 'pontifex-backup-20260102T030000Z.wpmig', 'pontifex-backup-20260103T030000Z.wpmig' ) );

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
		$adapter = new FakeDestinationAdapter( array( 'pontifex-backup-20260101T030000Z.wpmig', 'pontifex-backup-20260102T030000Z.wpmig' ) );

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
		$adapter = new FakeDestinationAdapter( array( 'pontifex-backup-20260101T030000Z.wpmig', 'pontifex-backup-20260102T030000Z.wpmig', 'pontifex-backup-20260103T030000Z.wpmig' ) );

		$deleted = ( new DestinationRetention( $adapter, 1 ) )->prune();

		$this->assertSame( array( 'pontifex-backup-20260101T030000Z.wpmig', 'pontifex-backup-20260102T030000Z.wpmig' ), $deleted );
		$this->assertNotContains( 'c', $deleted );
	}

	/**
	 * An individual delete failure is swallowed: the failing archive is
	 * left in place, pruning continues, and prune() does not throw.
	 *
	 * @return void
	 */
	public function test_a_delete_failure_is_swallowed_and_pruning_continues(): void {
		$adapter = new FakeDestinationAdapter( array( 'pontifex-backup-20260101T030000Z.wpmig', 'pontifex-backup-20260102T030000Z.wpmig', 'pontifex-backup-20260103T030000Z.wpmig' ), false, array( 'pontifex-backup-20260101T030000Z.wpmig' ) );

		$deleted = ( new DestinationRetention( $adapter, 1 ) )->prune();

		$this->assertSame( array( 'pontifex-backup-20260102T030000Z.wpmig' ), $deleted );
		$this->assertSame( array( 'pontifex-backup-20260102T030000Z.wpmig' ), $adapter->deleted_names() );
	}

	/**
	 * A listing failure propagates as a DestinationException rather than
	 * being swallowed.
	 *
	 * @return void
	 */
	public function test_a_listing_failure_propagates(): void {
		$adapter = new FakeDestinationAdapter( array( 'pontifex-backup-20260101T030000Z.wpmig', 'pontifex-backup-20260102T030000Z.wpmig', 'pontifex-backup-20260103T030000Z.wpmig' ), true );

		$this->expectException( DestinationException::class );

		( new DestinationRetention( $adapter, 1 ) )->prune();
	}
}
