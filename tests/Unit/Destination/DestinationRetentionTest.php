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
 * by real modification time (never by remote name), the surplus-only delete,
 * the retention-0/under-keep no-op paths, the floor guarantee that the newest
 * survivor is never deleted, the failed-delete reporting on the returned
 * {@see \Pontifex\Destination\PruneResult}, and the propagation of a listing
 * failure.
 */
final class DestinationRetentionTest extends TestCase {

	/**
	 * A file Pontifex did not name is never deleted, whatever it sorts as.
	 *
	 * Retention used to sort by name and delete from the front, which was age
	 * order only for the canonical `pontifex-backup-<UTC>.wpmig` shape.
	 * Nothing enforced that: the SFTP adapter lists every `.wpmig`, and
	 * `export --destination` uploads under whatever basename `--output` was
	 * given. So an operator running
	 *
	 *     wp pontifex export --output=/backups/before-upgrade.wpmig --destination=nas
	 *
	 * put `before-upgrade.wpmig` into the rotation. It sorted ahead of every
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

		$result = ( new DestinationRetention( $adapter, 2 ) )->prune();

		$this->assertSame( array(), $result->deleted(), 'Two generated archives with keep=2 leaves nothing to prune.' );
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

		$result = ( new DestinationRetention( $adapter, 2 ) )->prune();

		$this->assertSame(
			array( 'pontifex-backup-20260101T030000Z.wpmig' ),
			$result->deleted(),
			'Exactly one generated archive is surplus to a keep of 2; the two other files are not counted.'
		);
	}

	/**
	 * Pruning down to fewer archives than are held deletes exactly the
	 * oldest surplus, proven against unsorted input so the sort itself is
	 * exercised, and leaves the newest archives untouched.
	 *
	 * Every archive here reports an unknown modification time (the fake's
	 * default), so this proves the name-based TIE-BREAK specifically —
	 * {@see self::test_prune_orders_by_real_age_over_the_name()} proves the
	 * real-age ordering that runs before it.
	 *
	 * @return void
	 */
	public function test_prune_deletes_the_oldest_surplus(): void {
		$adapter = new FakeDestinationAdapter( array( 'pontifex-backup-20260103T030000Z.wpmig', 'pontifex-backup-20260101T030000Z.wpmig', 'pontifex-backup-20260105T030000Z.wpmig', 'pontifex-backup-20260102T030000Z.wpmig', 'pontifex-backup-20260104T030000Z.wpmig' ) );

		$result = ( new DestinationRetention( $adapter, 3 ) )->prune();

		$this->assertSame( array( 'pontifex-backup-20260101T030000Z.wpmig', 'pontifex-backup-20260102T030000Z.wpmig' ), $result->deleted() );
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

		$result = ( new DestinationRetention( $adapter, 0 ) )->prune();

		$this->assertSame( array(), $result->deleted() );
		$this->assertSame( array(), $result->failed() );
		$this->assertSame( array(), $adapter->deleted_names() );
	}

	/**
	 * When the destination holds exactly the keep count, nothing is pruned.
	 *
	 * @return void
	 */
	public function test_prune_at_exactly_the_keep_count_deletes_nothing(): void {
		$adapter = new FakeDestinationAdapter( array( 'pontifex-backup-20260101T030000Z.wpmig', 'pontifex-backup-20260102T030000Z.wpmig', 'pontifex-backup-20260103T030000Z.wpmig' ) );

		$result = ( new DestinationRetention( $adapter, 3 ) )->prune();

		$this->assertSame( array(), $result->deleted() );
		$this->assertSame( array(), $adapter->deleted_names() );
	}

	/**
	 * When the destination holds fewer than the keep count, nothing is pruned.
	 *
	 * @return void
	 */
	public function test_prune_under_the_keep_count_deletes_nothing(): void {
		$adapter = new FakeDestinationAdapter( array( 'pontifex-backup-20260101T030000Z.wpmig', 'pontifex-backup-20260102T030000Z.wpmig' ) );

		$result = ( new DestinationRetention( $adapter, 3 ) )->prune();

		$this->assertSame( array(), $result->deleted() );
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

		$result = ( new DestinationRetention( $adapter, 1 ) )->prune();

		$this->assertSame( array( 'pontifex-backup-20260101T030000Z.wpmig', 'pontifex-backup-20260102T030000Z.wpmig' ), $result->deleted() );
		$this->assertNotContains( 'c', $result->deleted() );
	}

	/**
	 * The headline real-age case: a name that LOOKS newest but whose real
	 * modification time is the oldest is the one pruned, and the name that
	 * looks oldest but is really the freshest survives.
	 *
	 * A pure name sort would get this backwards — it is exactly the shape of
	 * defect that motivated ordering by real age at all, mirroring
	 * {@see \Pontifex\Admin\BackupStore::compare_by_age()}'s own headline
	 * case for local backups.
	 *
	 * @return void
	 */
	public function test_prune_orders_by_real_age_over_the_name(): void {
		$now = time();

		// Name-wise this looks like the OLDEST archive (2026), but its real
		// modification time is the most recent of the two.
		$looks_old_is_really_new = 'pontifex-backup-20260101T030000Z.wpmig';
		// Name-wise this looks like the NEWEST archive (2099), but its real
		// modification time is the more distant of the two.
		$looks_new_is_really_old = 'pontifex-backup-20990101T030000Z.wpmig';

		$adapter = new FakeDestinationAdapter(
			array( $looks_old_is_really_new, $looks_new_is_really_old ),
			false,
			array(),
			array(
				$looks_old_is_really_new => $now - 100,
				$looks_new_is_really_old => $now - 1000000,
			)
		);

		$result = ( new DestinationRetention( $adapter, 1 ) )->prune();

		$this->assertSame(
			array( $looks_new_is_really_old ),
			$result->deleted(),
			'The archive with the older real modification time is pruned, even though its name looks newest.'
		);
	}

	/**
	 * An archive with a future modification time sorts first and is pruned
	 * even at the expense of a name that looks like the older backup.
	 *
	 * @return void
	 */
	public function test_prune_treats_a_future_modification_time_as_oldest(): void {
		$now = time();

		// Named as though it were the OLDER of the two, but its real
		// modification time is in the future — untrustworthy, so it must
		// still be pruned first.
		$future_dated = 'pontifex-backup-20260101T030000Z.wpmig';
		// Named as though it were the NEWER of the two, and its real
		// modification time genuinely is recent.
		$genuinely_fresh = 'pontifex-backup-20990101T030000Z.wpmig';

		$adapter = new FakeDestinationAdapter(
			array( $future_dated, $genuinely_fresh ),
			false,
			array(),
			array(
				$future_dated    => $now + 1000000,
				$genuinely_fresh => $now - 100,
			)
		);

		$result = ( new DestinationRetention( $adapter, 1 ) )->prune();

		$this->assertSame(
			array( $future_dated ),
			$result->deleted(),
			'A future modification time is untrustworthy and sorts first, however the two archives are named.'
		);
	}

	/**
	 * An unknown modification time is trusted as current, not treated as the
	 * oldest thing in the set.
	 *
	 * @return void
	 */
	public function test_prune_treats_an_unknown_modification_time_as_current(): void {
		$now = time();

		$unknown_time  = 'pontifex-backup-20260101T030000Z.wpmig';
		$genuinely_old = 'pontifex-backup-20260201T030000Z.wpmig';

		$adapter = new FakeDestinationAdapter(
			array( $unknown_time, $genuinely_old ),
			false,
			array(),
			array(
				$genuinely_old => $now - 1000000,
				// $unknown_time is deliberately absent, reporting the fake's
				// -1 "unknown" default.
			)
		);

		$result = ( new DestinationRetention( $adapter, 1 ) )->prune();

		$this->assertSame(
			array( $genuinely_old ),
			$result->deleted(),
			'An unknown modification time must not be pruned as though it were the oldest; a genuinely old one is pruned instead.'
		);
	}

	/**
	 * Two archives with the identical modification time fall back to a
	 * deterministic name tie-break.
	 *
	 * @return void
	 */
	public function test_prune_breaks_a_tied_modification_time_by_name(): void {
		$tied_time = time() - 1000000;
		$name_a    = 'pontifex-backup-20260101T030000Z.wpmig';
		$name_b    = 'pontifex-backup-20260201T030000Z.wpmig';

		$adapter = new FakeDestinationAdapter(
			array( $name_b, $name_a ),
			false,
			array(),
			array(
				$name_a => $tied_time,
				$name_b => $tied_time,
			)
		);

		$result = ( new DestinationRetention( $adapter, 1 ) )->prune();

		$this->assertSame( array( $name_a ), $result->deleted(), 'Equal modification times fall back to a name tie-break, ascending.' );
	}

	/**
	 * An individual delete failure is recorded, not merely swallowed: the
	 * failing archive is left in place, pruning continues, prune() does not
	 * throw, and the failure appears on the returned result.
	 *
	 * @return void
	 */
	public function test_a_delete_failure_is_recorded_and_pruning_continues(): void {
		$adapter = new FakeDestinationAdapter( array( 'pontifex-backup-20260101T030000Z.wpmig', 'pontifex-backup-20260102T030000Z.wpmig', 'pontifex-backup-20260103T030000Z.wpmig' ), false, array( 'pontifex-backup-20260101T030000Z.wpmig' ) );

		$result = ( new DestinationRetention( $adapter, 1 ) )->prune();

		$this->assertSame( array( 'pontifex-backup-20260102T030000Z.wpmig' ), $result->deleted() );
		$this->assertSame( array( 'pontifex-backup-20260102T030000Z.wpmig' ), $adapter->deleted_names() );
		$this->assertArrayHasKey( 'pontifex-backup-20260101T030000Z.wpmig', $result->failed() );
	}

	/**
	 * When every delete fails, the result reports the failures and reports
	 * no successes at all — never a partial "removed 1" that omits the rest.
	 *
	 * @return void
	 */
	public function test_prune_where_every_delete_fails_reports_no_successes(): void {
		$names   = array(
			'pontifex-backup-20260101T030000Z.wpmig',
			'pontifex-backup-20260102T030000Z.wpmig',
			'pontifex-backup-20260103T030000Z.wpmig',
		);
		$adapter = new FakeDestinationAdapter( $names, false, array( $names[0], $names[1] ) );

		$result = ( new DestinationRetention( $adapter, 1 ) )->prune();

		$this->assertSame( array(), $result->deleted() );
		$this->assertSame( array( $names[0], $names[1] ), array_keys( $result->failed() ) );
	}

	/**
	 * When some deletes succeed and some fail, the result reports both —
	 * not just whichever one happened to be non-empty.
	 *
	 * @return void
	 */
	public function test_prune_where_some_deletes_succeed_and_some_fail_reports_both(): void {
		$names   = array(
			'pontifex-backup-20260101T030000Z.wpmig',
			'pontifex-backup-20260102T030000Z.wpmig',
			'pontifex-backup-20260103T030000Z.wpmig',
		);
		$adapter = new FakeDestinationAdapter( $names, false, array( $names[0] ) );

		$result = ( new DestinationRetention( $adapter, 1 ) )->prune();

		$this->assertSame( array( $names[1] ), $result->deleted() );
		$this->assertSame( array( $names[0] ), array_keys( $result->failed() ) );
	}

	/**
	 * "Nothing needed pruning" and "every delete was refused" are otherwise
	 * both a bare empty deleted() list — this is the distinction that used to
	 * collapse into the same false "nothing was pruned" report.
	 *
	 * @return void
	 */
	public function test_nothing_to_prune_is_distinguishable_from_everything_failing(): void {
		$nothing_to_prune = ( new DestinationRetention( new FakeDestinationAdapter( array( 'pontifex-backup-20260101T030000Z.wpmig' ) ), 1 ) )->prune();

		$names             = array( 'pontifex-backup-20260101T030000Z.wpmig', 'pontifex-backup-20260102T030000Z.wpmig' );
		$everything_failed = ( new DestinationRetention( new FakeDestinationAdapter( $names, false, $names ), 1 ) )->prune();

		$this->assertSame( array(), $nothing_to_prune->deleted() );
		$this->assertSame( array(), $nothing_to_prune->failed(), 'Nothing surplus, so nothing was even attempted.' );

		$this->assertSame( array(), $everything_failed->deleted(), 'Every attempted delete failed, so nothing was actually removed.' );
		$this->assertNotSame( array(), $everything_failed->failed(), 'Unlike the case above, deletes WERE attempted here — they just all failed.' );
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
