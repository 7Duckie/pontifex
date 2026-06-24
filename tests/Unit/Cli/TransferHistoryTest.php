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
}
