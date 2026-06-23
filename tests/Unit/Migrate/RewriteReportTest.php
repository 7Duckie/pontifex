<?php
/**
 * Tests for RewriteReport — the counts-only rewrite tally.
 *
 * @package Pontifex\Tests\Unit\Migrate
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Migrate;

use InvalidArgumentException;
use Pontifex\Migrate\RewriteReport;
use Pontifex\Tests\TestCase;

/**
 * Confirms the report carries its tallies faithfully and rejects nonsense.
 */
final class RewriteReportTest extends TestCase {

	/**
	 * The report exposes exactly the tallies it was constructed with.
	 *
	 * @return void
	 */
	public function test_exposes_the_tallies_it_was_constructed_with(): void {
		$report = new RewriteReport( 7, array( 'wp_no_key', 'wp_logs' ), 1200, 34, 41, 3 );

		$this->assertSame( 7, $report->tables_scanned() );
		$this->assertSame( array( 'wp_no_key', 'wp_logs' ), $report->skipped_tables() );
		$this->assertSame( 1200, $report->rows_scanned() );
		$this->assertSame( 34, $report->rows_changed() );
		$this->assertSame( 41, $report->values_changed() );
		$this->assertSame( 3, $report->skipped_values() );
	}

	/**
	 * Skipped-table names are re-indexed to a clean list.
	 *
	 * @return void
	 */
	public function test_reindexes_skipped_table_names(): void {
		$report = new RewriteReport(
			0,
			array(
				3 => 'wp_a',
				9 => 'wp_b',
			),
			0,
			0,
			0,
			0
		);

		$this->assertSame( array( 'wp_a', 'wp_b' ), $report->skipped_tables() );
	}

	/**
	 * A negative count is rejected at construction.
	 *
	 * @return void
	 */
	public function test_rejects_a_negative_count(): void {
		$this->expectException( InvalidArgumentException::class );

		new RewriteReport( 1, array(), -1, 0, 0, 0 );
	}

	/**
	 * An empty skipped-table name is rejected at construction.
	 *
	 * @return void
	 */
	public function test_rejects_an_empty_skipped_table_name(): void {
		$this->expectException( InvalidArgumentException::class );

		new RewriteReport( 0, array( '' ), 0, 0, 0, 0 );
	}
}
