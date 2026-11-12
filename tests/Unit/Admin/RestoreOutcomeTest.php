<?php
/**
 * Unit tests for RestoreOutcome — the one reader of the last restore or rollback.
 *
 * @package Pontifex\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Admin;

use Mockery;
use Pontifex\Admin\RestoreOutcome;
use Pontifex\Cli\TransferHistory;
use Pontifex\Tests\TestCase;
use Pontifex\WordPress\WordPressContext;

/**
 * Covers how the durable history is read back and described.
 *
 * The history is a stored option, so every test builds one as plain data and hands
 * it to {@see RestoreOutcome::latest()} through a mocked context. The clock is a
 * parameter of {@see RestoreOutcome::is_recent()}, so no test stubs time.
 */
final class RestoreOutcomeTest extends TestCase {

	/**
	 * A fixed "now" for the recency tests: 2026-10-29T12:00:00+00:00.
	 *
	 * @var int
	 */
	private const NOW = 1793275200;

	// -------------------------------------------------------------------------
	// latest(): which entry is read.
	// -------------------------------------------------------------------------

	/**
	 * With no history at all there is nothing to describe.
	 *
	 * @return void
	 */
	public function test_latest_is_null_when_nothing_has_been_recorded(): void {
		$this->assertNull( RestoreOutcome::latest( $this->context_with_history( array() ) ) );
	}

	/**
	 * Exports rewrite nothing, so a history of only exports describes no restore.
	 *
	 * @return void
	 */
	public function test_latest_is_null_when_only_exports_are_recorded(): void {
		$history = array(
			self::entry( 'export', 'succeeded' ),
			self::entry( 'export', 'failed' ),
		);

		$this->assertNull( RestoreOutcome::latest( $this->context_with_history( $history ) ) );
	}

	/**
	 * The newest import wins over an older one.
	 *
	 * @return void
	 */
	public function test_latest_picks_the_newest_import(): void {
		$history = array(
			self::entry( 'import', 'failed', null, '2026-10-29T08:00:00+00:00' ),
			self::entry( 'import', 'succeeded', array( 'entries' => 5 ), '2026-10-29T09:00:00+00:00' ),
		);

		$latest = RestoreOutcome::latest( $this->context_with_history( $history ) );

		$this->assertInstanceOf( RestoreOutcome::class, $latest );
		$this->assertSame( 'succeeded', $latest->outcome() );
		$this->assertSame( '2026-10-29T09:00:00+00:00', $latest->occurred_at() );
	}

	/**
	 * A rollback that happened after an import is what last rewrote the site.
	 *
	 * @return void
	 */
	public function test_latest_picks_a_rollback_over_an_older_import(): void {
		$history = array(
			self::entry( 'import', 'succeeded', array( 'entries' => 5 ), '2026-10-29T08:00:00+00:00' ),
			self::entry( 'rollback', 'succeeded', array( 'entries' => 9 ), '2026-10-29T09:00:00+00:00' ),
		);

		$latest = RestoreOutcome::latest( $this->context_with_history( $history ) );

		$this->assertInstanceOf( RestoreOutcome::class, $latest );
		$this->assertSame( RestoreOutcome::OPERATION_ROLLBACK, $latest->operation() );
		$this->assertSame( 9, $latest->entries() );
	}

	/**
	 * And the other way round: an import after a rollback is the latest.
	 *
	 * @return void
	 */
	public function test_latest_picks_an_import_over_an_older_rollback(): void {
		$history = array(
			self::entry( 'rollback', 'succeeded', array( 'entries' => 9 ), '2026-10-29T08:00:00+00:00' ),
			self::entry( 'import', 'succeeded', array( 'entries' => 5 ), '2026-10-29T09:00:00+00:00' ),
		);

		$latest = RestoreOutcome::latest( $this->context_with_history( $history ) );

		$this->assertInstanceOf( RestoreOutcome::class, $latest );
		$this->assertSame( RestoreOutcome::OPERATION_IMPORT, $latest->operation() );
		$this->assertSame( 5, $latest->entries() );
	}

	/**
	 * A newer export is stepped over to reach the restore underneath it.
	 *
	 * @return void
	 */
	public function test_latest_steps_over_a_newer_export(): void {
		$history = array(
			self::entry( 'import', 'succeeded', array( 'entries' => 5 ), '2026-10-29T08:00:00+00:00' ),
			self::entry( 'export', 'succeeded', null, '2026-10-29T09:00:00+00:00' ),
		);

		$latest = RestoreOutcome::latest( $this->context_with_history( $history ) );

		$this->assertInstanceOf( RestoreOutcome::class, $latest );
		$this->assertSame( RestoreOutcome::OPERATION_IMPORT, $latest->operation() );
	}

	/**
	 * An entry that names no operation is not mistaken for a restore.
	 *
	 * @return void
	 */
	public function test_latest_ignores_an_entry_with_no_operation(): void {
		$history = array(
			array(
				'at'      => '2026-10-29T09:00:00+00:00',
				'outcome' => 'succeeded',
			),
		);

		$this->assertNull( RestoreOutcome::latest( $this->context_with_history( $history ) ) );
	}

	// -------------------------------------------------------------------------
	// Accessors and tolerance.
	// -------------------------------------------------------------------------

	/**
	 * Every recorded fact is available through its accessor.
	 *
	 * @return void
	 */
	public function test_every_recorded_detail_is_readable(): void {
		$details = array(
			'entries'          => 120,
			'relinked'         => true,
			'rows_rewritten'   => 31,
			'repaired_markers' => 4,
			'skipped_links'    => 2,
			'recovery'         => 'merge_links',
		);
		$history = array( self::entry( 'import', 'failed', $details, '2026-10-29T09:00:00+00:00' ) );

		$latest = RestoreOutcome::latest( $this->context_with_history( $history ) );

		$this->assertInstanceOf( RestoreOutcome::class, $latest );
		$this->assertSame( 'import', $latest->operation() );
		$this->assertSame( 'failed', $latest->outcome() );
		$this->assertSame( '2026-10-29T09:00:00+00:00', $latest->occurred_at() );
		$this->assertSame( 120, $latest->entries() );
		$this->assertTrue( $latest->relinked() );
		$this->assertSame( 31, $latest->rows_rewritten() );
		$this->assertSame( 4, $latest->repaired_markers() );
		$this->assertSame( 2, $latest->skipped_links() );
		$this->assertSame( RestoreOutcome::RECOVERY_MERGE_LINKS, $latest->recovery() );
	}

	/**
	 * An entry written before details existed reads as zeroes and false, not an error.
	 *
	 * @return void
	 */
	public function test_an_entry_with_no_details_degrades_to_zeroes_and_false(): void {
		$latest = RestoreOutcome::latest( $this->context_with_history( array( self::entry( 'import', 'succeeded' ) ) ) );

		$this->assertInstanceOf( RestoreOutcome::class, $latest );
		$this->assertSame( 0, $latest->entries() );
		$this->assertFalse( $latest->relinked() );
		$this->assertSame( 0, $latest->rows_rewritten() );
		$this->assertSame( 0, $latest->repaired_markers() );
		$this->assertSame( 0, $latest->skipped_links() );
		$this->assertSame( RestoreOutcome::RECOVERY_NONE, $latest->recovery() );
	}

	/**
	 * A rollback entry carries no `relinked` and no `recovery` key, and reads cleanly without them.
	 *
	 * That is the shape the rollback writers produce: a rollback has no relink and
	 * no recovery of its own, so neither key is written.
	 *
	 * @return void
	 */
	public function test_a_rollback_entry_without_relink_or_recovery_keys_degrades_cleanly(): void {
		$details = array(
			'entries'          => 5,
			'repaired_markers' => 0,
			'skipped_links'    => 0,
		);

		$latest = RestoreOutcome::latest( $this->context_with_history( array( self::entry( 'rollback', 'succeeded', $details ) ) ) );

		$this->assertInstanceOf( RestoreOutcome::class, $latest );
		$this->assertFalse( $latest->relinked() );
		$this->assertSame( 0, $latest->rows_rewritten() );
		$this->assertSame( RestoreOutcome::RECOVERY_NONE, $latest->recovery() );
		$this->assertSame( 'Rolled back 5 entries.', $latest->summary() );
	}

	/**
	 * Details of the wrong shape or type degrade rather than raising.
	 *
	 * @return void
	 */
	public function test_malformed_details_degrade_rather_than_raise(): void {
		$entry            = self::entry( 'import', 'succeeded' );
		$entry['details'] = 'not an array';

		$latest = RestoreOutcome::latest( $this->context_with_history( array( $entry ) ) );
		$this->assertInstanceOf( RestoreOutcome::class, $latest );
		$this->assertSame( 0, $latest->entries() );

		$entry['details'] = array(
			'entries'          => 'lots',
			'relinked'         => 'yes',
			'rows_rewritten'   => -4,
			'repaired_markers' => array( 1 ),
			'skipped_links'    => null,
			'recovery'         => 'a label nobody defined',
		);

		$latest = RestoreOutcome::latest( $this->context_with_history( array( $entry ) ) );
		$this->assertInstanceOf( RestoreOutcome::class, $latest );
		$this->assertSame( 0, $latest->entries(), 'A non-numeric count reads as zero.' );
		$this->assertFalse( $latest->relinked(), 'Only a real true counts as a relink.' );
		$this->assertSame( 0, $latest->rows_rewritten(), 'A negative count reads as zero.' );
		$this->assertSame( 0, $latest->repaired_markers() );
		$this->assertSame( 0, $latest->skipped_links() );
		$this->assertSame( RestoreOutcome::RECOVERY_NONE, $latest->recovery(), 'An unknown recovery label reads as none, not as a verdict.' );
	}

	/**
	 * An entry with no outcome or timestamp reads as empty strings.
	 *
	 * @return void
	 */
	public function test_missing_outcome_and_timestamp_read_as_empty_strings(): void {
		$latest = RestoreOutcome::latest( $this->context_with_history( array( array( 'operation' => 'import' ) ) ) );

		$this->assertInstanceOf( RestoreOutcome::class, $latest );
		$this->assertSame( '', $latest->outcome() );
		$this->assertSame( '', $latest->occurred_at() );
	}

	/**
	 * The recovery labels are the stored vocabulary, so their values are pinned.
	 *
	 * A rename here would silently change what every existing record means.
	 *
	 * @return void
	 */
	public function test_the_recovery_labels_are_the_stored_vocabulary(): void {
		$this->assertSame( '', RestoreOutcome::RECOVERY_NONE );
		$this->assertSame( 'precise', RestoreOutcome::RECOVERY_PRECISE );
		$this->assertSame( 'merge', RestoreOutcome::RECOVERY_MERGE );
		$this->assertSame( 'merge_links', RestoreOutcome::RECOVERY_MERGE_LINKS );
		$this->assertSame( 'failed', RestoreOutcome::RECOVERY_FAILED );
	}

	// -------------------------------------------------------------------------
	// is_recent().
	// -------------------------------------------------------------------------

	/**
	 * A record inside the window is recent, and one on its very edge still is.
	 *
	 * @return void
	 */
	public function test_is_recent_is_true_inside_the_window_and_on_its_edge(): void {
		$inside = $this->outcome_at( '2026-10-29T11:59:00+00:00' );
		$edge   = $this->outcome_at( '2026-10-29T11:55:00+00:00' );

		$this->assertTrue( $inside->is_recent( self::NOW, 300 ) );
		$this->assertTrue( $edge->is_recent( self::NOW, 300 ), 'Exactly the window old is still within it.' );
	}

	/**
	 * A record older than the window is not recent.
	 *
	 * @return void
	 */
	public function test_is_recent_is_false_beyond_the_window(): void {
		$this->assertFalse( $this->outcome_at( '2026-10-29T11:54:59+00:00' )->is_recent( self::NOW, 300 ) );
	}

	/**
	 * A timestamp that cannot be read is not recent: its age cannot be established.
	 *
	 * @return void
	 */
	public function test_is_recent_is_false_for_an_unreadable_timestamp(): void {
		$this->assertFalse( $this->outcome_at( 'not a time' )->is_recent( self::NOW, 300 ) );
		$this->assertFalse( $this->outcome_at( '' )->is_recent( self::NOW, 300 ) );
	}

	/**
	 * A timestamp slightly ahead of "now" counts as recent rather than being hidden.
	 *
	 * @return void
	 */
	public function test_is_recent_is_true_for_a_timestamp_slightly_in_the_future(): void {
		$this->assertTrue( $this->outcome_at( '2026-10-29T12:00:30+00:00' )->is_recent( self::NOW, 300 ) );
	}

	// -------------------------------------------------------------------------
	// summary().
	// -------------------------------------------------------------------------

	/**
	 * Each operation and outcome gets its own verb.
	 *
	 * @return void
	 */
	public function test_the_headline_names_the_operation_and_the_outcome(): void {
		$cases = array(
			array( 'import', 'succeeded', 'Restored 12 entries.' ),
			array( 'import', 'failed', 'The restore failed.' ),
			array( 'rollback', 'succeeded', 'Rolled back 12 entries.' ),
			array( 'rollback', 'failed', 'The rollback failed.' ),
		);

		foreach ( $cases as $case ) {
			$latest = RestoreOutcome::latest( $this->context_with_history( array( self::entry( $case[0], $case[1], array( 'entries' => 12 ) ) ) ) );

			$this->assertInstanceOf( RestoreOutcome::class, $latest );
			$this->assertSame( $case[2], $latest->summary(), $case[0] . ' ' . $case[1] );
		}
	}

	/**
	 * Every count agrees with its noun: one is singular, none and many are plural.
	 *
	 * Covers all four counted phrases a summary can carry — entries restored, entries
	 * rolled back, percent signs repaired and symbolic links skipped — plus the rows
	 * a relink changed, each at one, zero and several.
	 *
	 * @return void
	 */
	public function test_every_count_agrees_with_its_noun(): void {
		$cases = array(
			array( 'import', 'entries', 1, 'Restored 1 entry.' ),
			array( 'import', 'entries', 0, 'Restored 0 entries.' ),
			array( 'import', 'entries', 2, 'Restored 2 entries.' ),
			array( 'rollback', 'entries', 1, 'Rolled back 1 entry.' ),
			array( 'rollback', 'entries', 0, 'Rolled back 0 entries.' ),
			array( 'rollback', 'entries', 2, 'Rolled back 2 entries.' ),
		);

		foreach ( $cases as $case ) {
			$latest = RestoreOutcome::latest( $this->context_with_history( array( self::entry( $case[0], 'succeeded', array( $case[1] => $case[2] ) ) ) ) );

			$this->assertInstanceOf( RestoreOutcome::class, $latest );
			$this->assertSame( $case[3], $latest->summary(), $case[0] . ' ' . $case[2] );
		}

		$clauses = array(
			array( 'repaired_markers', 1, ' Repaired 1 percent sign that an older version of Pontifex had written as a long marker.' ),
			array( 'repaired_markers', 2, ' Repaired 2 percent signs that an older version of Pontifex had written as a long marker.' ),
			array( 'skipped_links', 1, ' 1 symbolic link could not be re-created and was skipped.' ),
			array( 'skipped_links', 2, ' 2 symbolic links could not be re-created and were skipped.' ),
		);

		foreach ( $clauses as $clause ) {
			$details = array(
				'entries'  => 5,
				$clause[0] => $clause[1],
			);
			$latest  = RestoreOutcome::latest( $this->context_with_history( array( self::entry( 'import', 'succeeded', $details ) ) ) );

			$this->assertInstanceOf( RestoreOutcome::class, $latest );
			$this->assertSame( 'Restored 5 entries.' . $clause[2], $latest->summary(), $clause[0] . ' ' . $clause[1] );
		}

		$rows = array(
			array( 1, ' The backup\'s links were rewritten across 1 database row.' ),
			array( 0, ' The backup\'s links were rewritten across 0 database rows.' ),
			array( 9, ' The backup\'s links were rewritten across 9 database rows.' ),
		);

		foreach ( $rows as $row ) {
			$details = array(
				'entries'        => 5,
				'relinked'       => true,
				'rows_rewritten' => $row[0],
			);
			$latest  = RestoreOutcome::latest( $this->context_with_history( array( self::entry( 'import', 'succeeded', $details ) ) ) );

			$this->assertInstanceOf( RestoreOutcome::class, $latest );
			$this->assertSame( 'Restored 5 entries.' . $row[1], $latest->summary(), 'rows ' . $row[0] );
		}
	}

	/**
	 * Each verify-gate verdict has its own headline, stating the cause and that nothing was changed.
	 *
	 * Damaged, refused and could-not-check call for three different responses (ADR 0023),
	 * so a stopped restore never collapses them into one word, and a gate verdict is
	 * itself the proof that nothing was written: the gate runs before any write.
	 *
	 * @return void
	 */
	public function test_each_gate_verdict_has_its_own_headline(): void {
		$cases = array(
			array( 'import', 'broken', 'The backup is damaged, so nothing was changed.' ),
			array( 'import', 'refused', 'The backup was refused before anything was changed.' ),
			array( 'import', 'could_not_check', 'The backup could not be checked here, so nothing was changed.' ),
			array( 'rollback', 'broken', 'The safety archive is damaged, so nothing was changed.' ),
			array( 'rollback', 'refused', 'The safety archive was refused before anything was changed.' ),
			array( 'rollback', 'could_not_check', 'The safety archive could not be checked here, so nothing was changed.' ),
		);

		foreach ( $cases as $case ) {
			$latest = RestoreOutcome::latest( $this->context_with_history( array( self::entry( $case[0], 'failed', array( 'gate' => $case[1] ) ) ) ) );

			$this->assertInstanceOf( RestoreOutcome::class, $latest );
			$this->assertSame( $case[1], $latest->gate(), $case[0] . ' ' . $case[1] );
			$this->assertSame( $case[2], $latest->summary(), $case[0] . ' ' . $case[1] );
		}
	}

	/**
	 * Without a gate label a failure reads as a failure: absent means not known, never "nothing was written".
	 *
	 * An unrecognised label, or one of the wrong type, reads as none rather than as a verdict.
	 *
	 * @return void
	 */
	public function test_a_failure_without_a_gate_label_is_still_a_failure(): void {
		$cases = array(
			array( 'import', null, 'The restore failed.' ),
			array( 'import', array( 'entries' => 0 ), 'The restore failed.' ),
			array( 'import', array( 'gate' => '' ), 'The restore failed.' ),
			array( 'import', array( 'gate' => 'ok' ), 'The restore failed.' ),
			array( 'import', array( 'gate' => 'a verdict nobody defined' ), 'The restore failed.' ),
			array( 'import', array( 'gate' => true ), 'The restore failed.' ),
			array( 'rollback', array( 'entries' => 0 ), 'The rollback failed.' ),
		);

		foreach ( $cases as $case ) {
			$latest = RestoreOutcome::latest( $this->context_with_history( array( self::entry( $case[0], 'failed', $case[1] ) ) ) );

			$this->assertInstanceOf( RestoreOutcome::class, $latest );
			$this->assertSame( RestoreOutcome::GATE_NONE, $latest->gate() );
			$this->assertSame( $case[2], $latest->summary() );
		}
	}

	/**
	 * A gate label only changes the headline of a failure: a success ignores it, and later clauses still append.
	 *
	 * @return void
	 */
	public function test_a_gate_label_changes_only_a_failure_headline(): void {
		$succeeded_details = array(
			'entries' => 3,
			'gate'    => 'refused',
		);
		$refused_details   = array(
			'gate'          => 'refused',
			'skipped_links' => 1,
		);

		$succeeded = RestoreOutcome::latest( $this->context_with_history( array( self::entry( 'import', 'succeeded', $succeeded_details ) ) ) );
		$refused   = RestoreOutcome::latest( $this->context_with_history( array( self::entry( 'import', 'failed', $refused_details ) ) ) );

		$this->assertInstanceOf( RestoreOutcome::class, $succeeded );
		$this->assertInstanceOf( RestoreOutcome::class, $refused );
		$this->assertSame( 'Restored 3 entries.', $succeeded->summary() );
		$this->assertSame( 'The backup was refused before anything was changed. 1 symbolic link could not be re-created and was skipped.', $refused->summary() );
	}

	/**
	 * The constants this class exposes are aliases of the stored vocabulary, which TransferHistory defines.
	 *
	 * @return void
	 */
	public function test_the_exposed_constants_are_aliases_of_the_stored_vocabulary(): void {
		$this->assertSame( TransferHistory::OPERATION_IMPORT, RestoreOutcome::OPERATION_IMPORT );
		$this->assertSame( TransferHistory::OPERATION_ROLLBACK, RestoreOutcome::OPERATION_ROLLBACK );
		$this->assertSame( TransferHistory::RECOVERY_NONE, RestoreOutcome::RECOVERY_NONE );
		$this->assertSame( TransferHistory::RECOVERY_PRECISE, RestoreOutcome::RECOVERY_PRECISE );
		$this->assertSame( TransferHistory::RECOVERY_MERGE, RestoreOutcome::RECOVERY_MERGE );
		$this->assertSame( TransferHistory::RECOVERY_MERGE_LINKS, RestoreOutcome::RECOVERY_MERGE_LINKS );
		$this->assertSame( TransferHistory::RECOVERY_FAILED, RestoreOutcome::RECOVERY_FAILED );
		$this->assertSame( TransferHistory::GATE_NONE, RestoreOutcome::GATE_NONE );
		$this->assertSame( TransferHistory::GATE_BROKEN, RestoreOutcome::GATE_BROKEN );
		$this->assertSame( TransferHistory::GATE_REFUSED, RestoreOutcome::GATE_REFUSED );
		$this->assertSame( TransferHistory::GATE_COULD_NOT_CHECK, RestoreOutcome::GATE_COULD_NOT_CHECK );
	}

	/**
	 * Nothing is appended to the headline when nothing else applies.
	 *
	 * @return void
	 */
	public function test_a_plain_success_is_only_the_headline(): void {
		$details = array(
			'entries'          => 3,
			'relinked'         => false,
			'rows_rewritten'   => 0,
			'repaired_markers' => 0,
			'skipped_links'    => 0,
			'recovery'         => '',
		);

		$latest = RestoreOutcome::latest( $this->context_with_history( array( self::entry( 'import', 'succeeded', $details ) ) ) );

		$this->assertInstanceOf( RestoreOutcome::class, $latest );
		$this->assertSame( 'Restored 3 entries.', $latest->summary() );
	}

	/**
	 * A relink adds its sentence, with the row count.
	 *
	 * @return void
	 */
	public function test_a_relink_is_described_with_its_row_count(): void {
		$details = array(
			'entries'        => 3,
			'relinked'       => true,
			'rows_rewritten' => 31,
		);

		$latest = RestoreOutcome::latest( $this->context_with_history( array( self::entry( 'import', 'succeeded', $details ) ) ) );

		$this->assertInstanceOf( RestoreOutcome::class, $latest );
		$this->assertSame( 'Restored 3 entries. The backup\'s links were rewritten across 31 database rows.', $latest->summary() );
	}

	/**
	 * Repaired percent signs and skipped links each add their sentence, and only when above zero.
	 *
	 * @return void
	 */
	public function test_repairs_and_skipped_links_are_described_only_when_above_zero(): void {
		$repaired_details = array(
			'entries'          => 3,
			'repaired_markers' => 6,
		);
		$skipped_details  = array(
			'entries'       => 3,
			'skipped_links' => 2,
		);

		$repaired = RestoreOutcome::latest( $this->context_with_history( array( self::entry( 'import', 'succeeded', $repaired_details ) ) ) );
		$skipped  = RestoreOutcome::latest( $this->context_with_history( array( self::entry( 'rollback', 'succeeded', $skipped_details ) ) ) );

		$this->assertInstanceOf( RestoreOutcome::class, $repaired );
		$this->assertInstanceOf( RestoreOutcome::class, $skipped );
		$this->assertSame( 'Restored 3 entries. Repaired 6 percent signs that an older version of Pontifex had written as a long marker.', $repaired->summary() );
		$this->assertSame( 'Rolled back 3 entries. 2 symbolic links could not be re-created and were skipped.', $skipped->summary() );
	}

	/**
	 * Each recovery verdict has its own sentence, including the two kinds of merge.
	 *
	 * A merge whose file cleanup could not be confirmed sends the operator to the log
	 * for stray paths. A links-only merge had a provably complete cleanup, so that
	 * advice would be false there: it says what is actually left to check instead.
	 *
	 * @return void
	 */
	public function test_each_recovery_verdict_is_described(): void {
		$cases = array(
			array( 'precise', 'The restore failed. Your site was put back to its state before the restore.' ),
			array( 'merge', 'The restore failed. Your site was merged back rather than put back exactly — check the Pontifex log.' ),
			array( 'merge_links', 'The restore failed. Your site\'s database was put back and the file cleanup completed, but a symbolic link could not be re-created, so your site cannot be confirmed to be exactly as it was.' ),
			array( 'failed', 'The restore failed. Automatic recovery also failed; your site may be partially restored.' ),
			array( '', 'The restore failed.' ),
		);

		foreach ( $cases as $case ) {
			$latest = RestoreOutcome::latest( $this->context_with_history( array( self::entry( 'import', 'failed', array( 'recovery' => $case[0] ) ) ) ) );

			$this->assertInstanceOf( RestoreOutcome::class, $latest );
			$this->assertSame( $case[1], $latest->summary(), 'recovery "' . $case[0] . '"' );
		}
	}

	/**
	 * Every piece, when it applies, lands in the one fixed order.
	 *
	 * @return void
	 */
	public function test_the_pieces_compose_in_a_fixed_order(): void {
		$details = array(
			'entries'          => 0,
			'relinked'         => true,
			'rows_rewritten'   => 8,
			'repaired_markers' => 2,
			'skipped_links'    => 1,
			'recovery'         => 'merge_links',
		);

		$latest = RestoreOutcome::latest( $this->context_with_history( array( self::entry( 'import', 'failed', $details ) ) ) );

		$this->assertInstanceOf( RestoreOutcome::class, $latest );
		$this->assertSame(
			'The restore failed.'
			. ' The backup\'s links were rewritten across 8 database rows.'
			. ' Repaired 2 percent signs that an older version of Pontifex had written as a long marker.'
			. ' 1 symbolic link could not be re-created and was skipped.'
			. ' Your site\'s database was put back and the file cleanup completed, but a symbolic link could not be re-created, so your site cannot be confirmed to be exactly as it was.',
			$latest->summary()
		);
	}

	/**
	 * A record whose outcome is neither succeeded nor failed is not described at all.
	 *
	 * The plugin writes only those two. Anything else is a damaged record, and a
	 * sentence claiming a verdict on its strength would assert something unchecked.
	 *
	 * @return void
	 */
	public function test_a_record_with_an_unrecognised_outcome_has_no_summary(): void {
		$latest = RestoreOutcome::latest( $this->context_with_history( array( self::entry( 'import', 'weird', array( 'entries' => 3 ) ) ) ) );

		$this->assertInstanceOf( RestoreOutcome::class, $latest );
		$this->assertSame( '', $latest->summary() );
	}

	// -------------------------------------------------------------------------
	// Builders.
	// -------------------------------------------------------------------------

	/**
	 * Build one history entry as TransferHistory::record() stores it.
	 *
	 * @param string                    $operation 'export', 'import' or 'rollback'.
	 * @param string                    $outcome   'succeeded' or 'failed'.
	 * @param array<string, mixed>|null $details   Optional. The details to store; null stores none, as a caller that passes no details does.
	 * @param string                    $at        Optional. The recorded timestamp.
	 * @return array<string, mixed>
	 */
	private static function entry( string $operation, string $outcome, ?array $details = null, string $at = '2026-10-29T10:00:00+00:00' ): array {
		$entry = array(
			'at'        => $at,
			'operation' => $operation,
			'outcome'   => $outcome,
			'bytes'     => 0,
		);

		if ( null !== $details ) {
			$entry['details'] = $details;
		}

		return $entry;
	}

	/**
	 * A WordPressContext mock whose stored transfer history is the given list.
	 *
	 * @param array<int, array<string, mixed>> $history The stored history, oldest first.
	 * @return WordPressContext&\Mockery\MockInterface
	 */
	private function context_with_history( array $history ) {
		$context = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'option_value' )->with( TransferHistory::OPTION, array() )->andReturn( $history );
		return $context;
	}

	/**
	 * Read back an import outcome recorded at the given timestamp.
	 *
	 * @param string $at The recorded timestamp.
	 * @return RestoreOutcome
	 */
	private function outcome_at( string $at ): RestoreOutcome {
		$latest = RestoreOutcome::latest( $this->context_with_history( array( self::entry( 'import', 'succeeded', null, $at ) ) ) );
		$this->assertInstanceOf( RestoreOutcome::class, $latest );
		return $latest;
	}
}
