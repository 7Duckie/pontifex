<?php
/**
 * Unit tests for the rolling transfer history.
 *
 * @package Pontifex\Tests\Unit\Cli
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Cli;

use Mockery;
use Pontifex\Cli\TransferHistory;
use Pontifex\Tests\TestCase;
use Pontifex\WordPress\WordPressContext;

/**
 * Behavioural coverage of {@see TransferHistory}: the cap logic, the read
 * tolerance, and that recording appends and persists a capped history.
 */
final class TransferHistoryTest extends TestCase {

	/**
	 * Appending adds the new entry to the end.
	 *
	 * @return void
	 */
	public function test_append_capped_appends_to_the_end(): void {
		$result = TransferHistory::append_capped( array( array( 'at' => '1' ) ), array( 'at' => '2' ), 20 );

		$this->assertSame( array( array( 'at' => '1' ), array( 'at' => '2' ) ), $result );
	}

	/**
	 * Appending drops the oldest entries once the window is full.
	 *
	 * @return void
	 */
	public function test_append_capped_drops_the_oldest_over_the_cap(): void {
		$history = array();
		for ( $i = 1; $i <= 20; $i++ ) {
			$history[] = array( 'n' => $i );
		}

		$result = TransferHistory::append_capped( $history, array( 'n' => 21 ), 20 );

		$this->assertCount( 20, $result );
		$this->assertSame( 2, $result[0]['n'], 'The oldest entry (n=1) should have been dropped.' );
		$this->assertSame( 21, $result[19]['n'], 'The newest entry should be at the end.' );
	}

	/**
	 * Reading returns the stored entries.
	 *
	 * @return void
	 */
	public function test_recent_returns_stored_entries(): void {
		$entries = array( array( 'at' => '1' ), array( 'at' => '2' ) );

		$context = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'option_value' )->with( TransferHistory::OPTION, array() )->andReturn( $entries );

		$this->assertSame( $entries, TransferHistory::recent( $context ) );
	}

	/**
	 * Reading degrades a non-array option to an empty list.
	 *
	 * @return void
	 */
	public function test_recent_tolerates_a_non_array_option(): void {
		$context = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'option_value' )->andReturn( 'corrupt' );

		$this->assertSame( array(), TransferHistory::recent( $context ) );
	}

	/**
	 * Reading filters out non-array entries within the stored list.
	 *
	 * @return void
	 */
	public function test_recent_filters_out_non_array_entries(): void {
		$context = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'option_value' )->andReturn( array( array( 'at' => '1' ), 'garbage', array( 'at' => '2' ) ) );

		$this->assertSame( array( array( 'at' => '1' ), array( 'at' => '2' ) ), TransferHistory::recent( $context ) );
	}

	/**
	 * Recording appends the new entry to the existing history and saves it.
	 *
	 * @return void
	 */
	public function test_record_appends_the_entry_and_saves_it(): void {
		$saved   = null;
		$context = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'option_value' )->andReturn( array( array( 'at' => 'older' ) ) );
		$context->shouldReceive( 'save_option' )->once()->with(
			TransferHistory::OPTION,
			Mockery::on(
				static function ( $value ) use ( &$saved ): bool {
					$saved = $value;
					return true;
				}
			)
		);

		TransferHistory::record( $context, 'export', 'succeeded', 1000, '2026-06-24T10:00:00+00:00' );

		$this->assertIsArray( $saved );
		$this->assertCount( 2, $saved );
		$this->assertSame( 'older', $saved[0]['at'] );
		$this->assertSame( '2026-06-24T10:00:00+00:00', $saved[1]['at'] );
		$this->assertSame( 'export', $saved[1]['operation'] );
		$this->assertSame( 'succeeded', $saved[1]['outcome'] );
		$this->assertSame( 1000, $saved[1]['bytes'] );
	}

	/**
	 * Recording with details stores them under a `details` key, beside the four fixed fields.
	 *
	 * @return void
	 */
	public function test_record_stores_details_under_a_details_key(): void {
		$details = array(
			'entries'  => 42,
			'relinked' => true,
			'recovery' => 'precise',
		);

		$saved = $this->record_into_empty_history( 'import', 'succeeded', 2048, $details );

		$this->assertCount( 1, $saved );
		$this->assertSame( $details, $saved[0]['details'] );
		$this->assertSame( 'import', $saved[0]['operation'] );
		$this->assertSame( 2048, $saved[0]['bytes'] );
	}

	/**
	 * A caller that passes no details writes exactly the entry it always did, key for key.
	 *
	 * @return void
	 */
	public function test_record_without_details_writes_exactly_the_entry_it_always_did(): void {
		$saved = $this->record_into_empty_history( 'export', 'succeeded', 1000 );

		$this->assertSame(
			array(
				'at'        => '2026-10-29T10:00:00+00:00',
				'operation' => 'export',
				'outcome'   => 'succeeded',
				'bytes'     => 1000,
			),
			$saved[0]
		);
	}

	/**
	 * An empty details array leaves the key out as well, rather than storing an empty one.
	 *
	 * @return void
	 */
	public function test_record_with_an_empty_details_array_leaves_the_key_out(): void {
		$saved = $this->record_into_empty_history( 'import', 'failed', 0, array() );

		$this->assertArrayNotHasKey( 'details', $saved[0] );
		$this->assertCount( 4, $saved[0] );
	}

	/**
	 * A rollback is a third operation kind, stored as given with its details.
	 *
	 * @return void
	 */
	public function test_record_accepts_a_rollback_operation(): void {
		$saved = $this->record_into_empty_history( 'rollback', 'succeeded', 512, array( 'entries' => 7 ) );

		$this->assertSame( 'rollback', $saved[0]['operation'] );
		$this->assertSame( 'succeeded', $saved[0]['outcome'] );
		$this->assertSame( array( 'entries' => 7 ), $saved[0]['details'] );
	}

	/**
	 * Record one transfer into an empty history and return the history that was saved.
	 *
	 * @param string                              $operation The operation to record.
	 * @param string                              $outcome   The outcome to record.
	 * @param int                                 $bytes     The byte count to record.
	 * @param array<string, bool|int|string>|null $details   Optional. The details to pass; null passes none at all, so the default is exercised.
	 * @return array<int, array<string, mixed>> The history handed to save_option().
	 */
	private function record_into_empty_history( string $operation, string $outcome, int $bytes, ?array $details = null ): array {
		$saved   = array();
		$context = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'option_value' )->andReturn( array() );
		$context->shouldReceive( 'save_option' )->once()->with(
			TransferHistory::OPTION,
			Mockery::on(
				static function ( $value ) use ( &$saved ): bool {
					$saved = $value;
					return true;
				}
			)
		);

		if ( null === $details ) {
			TransferHistory::record( $context, $operation, $outcome, $bytes, '2026-10-29T10:00:00+00:00' );
		} else {
			TransferHistory::record( $context, $operation, $outcome, $bytes, '2026-10-29T10:00:00+00:00', $details );
		}

		return $saved;
	}

	/**
	 * The `gate` label is stored exactly as given, and never added on a caller's behalf.
	 *
	 * @return void
	 */
	public function test_record_stores_the_gate_label_only_when_the_caller_gives_it(): void {
		$with    = $this->record_into_empty_history( 'import', 'failed', 0, array( 'gate' => TransferHistory::GATE_BROKEN ) );
		$without = $this->record_into_empty_history( 'import', 'failed', 0, array( 'entries' => 0 ) );

		$this->assertSame( 'broken', $with[0]['details']['gate'] );
		$this->assertArrayNotHasKey( 'gate', $without[0]['details'] );
	}

	/**
	 * The stored vocabulary is pinned: a rename here would change what every existing record means.
	 *
	 * These strings are written into people's databases. The operation kinds, the
	 * recovery labels and the gate labels are the whole of the vocabulary a stored
	 * entry uses, and this class owns it.
	 *
	 * @return void
	 */
	public function test_the_stored_vocabulary_is_pinned(): void {
		$this->assertSame( 'export', TransferHistory::OPERATION_EXPORT );
		$this->assertSame( 'import', TransferHistory::OPERATION_IMPORT );
		$this->assertSame( 'rollback', TransferHistory::OPERATION_ROLLBACK );

		$this->assertSame( '', TransferHistory::RECOVERY_NONE );
		$this->assertSame( 'precise', TransferHistory::RECOVERY_PRECISE );
		$this->assertSame( 'merge', TransferHistory::RECOVERY_MERGE );
		$this->assertSame( 'merge_links', TransferHistory::RECOVERY_MERGE_LINKS );
		$this->assertSame( 'failed', TransferHistory::RECOVERY_FAILED );

		$this->assertSame( '', TransferHistory::GATE_NONE );
		$this->assertSame( 'broken', TransferHistory::GATE_BROKEN );
		$this->assertSame( 'refused', TransferHistory::GATE_REFUSED );
		$this->assertSame( 'could_not_check', TransferHistory::GATE_COULD_NOT_CHECK );
	}
}
