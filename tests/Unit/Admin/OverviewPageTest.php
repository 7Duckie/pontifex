<?php
/**
 * Unit tests for the admin Overview page.
 *
 * @package Pontifex\Tests
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use Mockery;
use Pontifex\Admin\OverviewPage;
use Pontifex\Rollback\RollbackStoreInterface;
use Pontifex\Tests\TestCase;
use Pontifex\WordPress\WordPressContext;
use RuntimeException;

/**
 * Covers the Overview page's pure data methods and its capability gate.
 *
 * The HTML rendering itself is exercised only as a smoke test (does a capable
 * user get a page without a fatal); asserting on exact markup would be testing
 * output formatting rather than logic.
 */
final class OverviewPageTest extends TestCase {

	/**
	 * A WordPressContext returning the given option values and a simple size formatter.
	 *
	 * @param array<string, mixed> $options Map of option name to stored value.
	 * @return WordPressContext
	 */
	private function context_with( array $options ): WordPressContext {
		$context = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'option_value' )->andReturnUsing(
			static function ( string $name, $fallback = false ) use ( $options ) {
				return $options[ $name ] ?? $fallback;
			}
		);
		$context->shouldReceive( 'format_size' )->andReturnUsing(
			static function ( int $bytes ): string {
				return $bytes . ' B';
			}
		);
		return $context;
	}

	/**
	 * A rollback store returning the given archive paths.
	 *
	 * @param array<int, string> $paths Absolute archive paths, oldest first.
	 * @return RollbackStoreInterface
	 */
	private function store_with( array $paths ): RollbackStoreInterface {
		$store = Mockery::mock( RollbackStoreInterface::class );
		$store->shouldReceive( 'archives' )->andReturn( $paths );
		return $store;
	}

	/**
	 * Reads all three counter options into one activity row each.
	 *
	 * @return void
	 */
	public function test_stats_rows_reads_export_import_and_rollback_counters(): void {
		$context = $this->context_with(
			array(
				'pontifex_export_stats'   => array(
					'attempted'      => 5,
					'succeeded'      => 4,
					'failed'         => 1,
					'bytes_exported' => 2048,
				),
				'pontifex_import_stats'   => array(
					'attempted'      => 2,
					'succeeded'      => 2,
					'failed'         => 0,
					'bytes_imported' => 1024,
				),
				'pontifex_rollback_stats' => array(
					'attempted'         => 3,
					'succeeded'         => 3,
					'failed'            => 0,
					'bytes_rolled_back' => 512,
				),
			)
		);
		$page    = new OverviewPage( $context, $this->store_with( array() ), '0.5.0' );

		$rows = $page->stats_rows();

		$this->assertCount( 3, $rows );
		$this->assertSame( 5, $rows[0]['attempted'] );
		$this->assertSame( 4, $rows[0]['succeeded'] );
		$this->assertSame( 1, $rows[0]['failed'] );
		$this->assertSame( '2048 B', $rows[0]['size'] );
		$this->assertSame( 2, $rows[1]['succeeded'] );
		$this->assertSame( '1024 B', $rows[1]['size'] );
		$this->assertSame( 'Rollbacks', $rows[2]['operation'] );
		$this->assertSame( 3, $rows[2]['succeeded'] );
		$this->assertSame( '512 B', $rows[2]['size'] );
	}

	/**
	 * A missing or corrupt counters option degrades to zeros, never a type error.
	 *
	 * @return void
	 */
	public function test_stats_rows_tolerate_corrupt_counters(): void {
		$page = new OverviewPage(
			$this->context_with( array( 'pontifex_export_stats' => 'not-an-array' ) ),
			$this->store_with( array() ),
			'0.5.0'
		);

		$rows = $page->stats_rows();

		$this->assertSame( 0, $rows[0]['attempted'] );
		$this->assertSame( 0, $rows[0]['succeeded'] );
		$this->assertSame( 0, $rows[1]['attempted'] );
	}

	/**
	 * Returns the rolling history newest-first.
	 *
	 * @return void
	 */
	public function test_history_rows_are_newest_first(): void {
		$context = $this->context_with(
			array(
				'pontifex_transfer_history' => array(
					array(
						'at'        => '2026-01-01T00:00:00Z',
						'operation' => 'export',
						'outcome'   => 'succeeded',
						'bytes'     => 100,
					),
					array(
						'at'        => '2026-02-01T00:00:00Z',
						'operation' => 'import',
						'outcome'   => 'failed',
						'bytes'     => 0,
					),
				),
			)
		);
		$page    = new OverviewPage( $context, $this->store_with( array() ), '0.5.0' );

		$rows = $page->history_rows();

		$this->assertCount( 2, $rows );
		$this->assertSame( '2026-02-01T00:00:00Z', $rows[0]['when'] );
		$this->assertSame( 'import', $rows[0]['operation'] );
		$this->assertSame( 'export', $rows[1]['operation'] );
	}

	/**
	 * Parses each archive's filename and the UTC time encoded in it, newest-first.
	 *
	 * @return void
	 */
	public function test_archive_rows_parse_name_and_date_newest_first(): void {
		$store = $this->store_with(
			array(
				'/var/www/wp-content/pontifex/rollback/pre-import-rollback-20260101T120000Z.wpmig',
				'/var/www/wp-content/pontifex/rollback/pre-import-rollback-20260301T093000Z.wpmig',
			)
		);
		$page  = new OverviewPage( $this->context_with( array() ), $store, '0.5.0' );

		$rows = $page->archive_rows();

		$this->assertCount( 2, $rows );
		$this->assertSame( 'pre-import-rollback-20260301T093000Z.wpmig', $rows[0]['filename'] );
		$this->assertSame( '2026-03-01 09:30 UTC', $rows[0]['when'] );
		$this->assertSame( '2026-01-01 12:00 UTC', $rows[1]['when'] );
	}

	/**
	 * Refuses a user without the managing capability.
	 *
	 * @return void
	 */
	public function test_render_denies_without_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'wp_die' )->alias(
			static function (): void {
				throw new RuntimeException( 'wp_die called' );
			}
		);
		$page = new OverviewPage( $this->context_with( array() ), $this->store_with( array() ), '0.5.0' );

		$this->expectException( RuntimeException::class );
		$page->render();
	}

	/**
	 * Produces the page for a capable user without a fatal.
	 *
	 * @return void
	 */
	public function test_render_outputs_the_page_for_a_capable_user(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$context = $this->context_with(
			array(
				'pontifex_export_stats' => array(
					'attempted'      => 1,
					'succeeded'      => 1,
					'failed'         => 0,
					'bytes_exported' => 10,
				),
			)
		);
		$page    = new OverviewPage( $context, $this->store_with( array() ), '0.5.0' );

		ob_start();
		$page->render();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Pontifex', $output );
		$this->assertStringContainsString( 'Transfer activity', $output );
		$this->assertStringContainsString( '0.5.0', $output );
	}

	/**
	 * A stored history entry, as TransferHistory::record() writes it.
	 *
	 * @param string                    $operation 'export', 'import' or 'rollback'.
	 * @param string                    $outcome   'succeeded' or 'failed'.
	 * @param string                    $at        The recorded ISO-8601 timestamp.
	 * @param array<string, mixed>|null $details   Optional. The details to store.
	 * @return array<string, mixed>
	 */
	private static function history_entry( string $operation, string $outcome, string $at, ?array $details = null ): array {
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
	 * Stub wp_date to UTC, so the formatted time is deterministic.
	 *
	 * @return void
	 */
	private function stub_wp_date(): void {
		Functions\when( 'wp_date' )->alias(
			static function ( string $format, int $timestamp ): string {
				return gmdate( $format, $timestamp );
			}
		);
	}

	/**
	 * A restore on record becomes a "Last restore" row: the sentence, and the time in the project's usual format.
	 *
	 * @return void
	 */
	public function test_last_outcome_row_describes_a_restore(): void {
		$this->stub_wp_date();
		$history = array(
			self::history_entry( 'import', 'succeeded', '2026-10-29T10:42:00+00:00', array( 'entries' => 4 ) ),
		);
		$page    = new OverviewPage( $this->context_with( array( 'pontifex_transfer_history' => $history ) ), $this->store_with( array() ), '1.1.0' );

		$row = $page->last_outcome_row();

		$this->assertSame(
			array(
				'operation' => 'Last restore',
				'text'      => 'Restored 4 entries.',
				'when'      => '10:42 on 29-10-2026',
			),
			$row
		);
	}

	/**
	 * A rollback on record becomes a "Last rollback" row — the label follows the operation, so it never contradicts the sentence.
	 *
	 * The sentence for a rollback says "Rolled back …", which would read wrong under
	 * a "Last restore" label.
	 *
	 * @return void
	 */
	public function test_last_outcome_row_describes_a_rollback_under_its_own_label(): void {
		$this->stub_wp_date();
		$history = array(
			self::history_entry( 'rollback', 'succeeded', '2026-10-29T10:42:00+00:00', array( 'entries' => 2 ) ),
		);
		$page    = new OverviewPage( $this->context_with( array( 'pontifex_transfer_history' => $history ) ), $this->store_with( array() ), '1.1.0' );

		$row = $page->last_outcome_row();

		$this->assertIsArray( $row );
		$this->assertSame( 'Last rollback', $row['operation'] );
		$this->assertSame( 'Rolled back 2 entries.', $row['text'] );
	}

	/**
	 * Whichever of an import and a rollback happened last decides the row.
	 *
	 * @return void
	 */
	public function test_last_outcome_row_follows_whichever_happened_last(): void {
		$this->stub_wp_date();

		$rollback_last = array(
			self::history_entry( 'import', 'succeeded', '2026-10-29T09:00:00+00:00', array( 'entries' => 4 ) ),
			self::history_entry( 'rollback', 'succeeded', '2026-10-29T10:00:00+00:00', array( 'entries' => 2 ) ),
		);
		$import_last   = array(
			self::history_entry( 'rollback', 'succeeded', '2026-10-29T09:00:00+00:00', array( 'entries' => 2 ) ),
			self::history_entry( 'import', 'succeeded', '2026-10-29T10:00:00+00:00', array( 'entries' => 4 ) ),
			self::history_entry( 'export', 'succeeded', '2026-10-29T11:00:00+00:00' ),
		);

		$first  = new OverviewPage( $this->context_with( array( 'pontifex_transfer_history' => $rollback_last ) ), $this->store_with( array() ), '1.1.0' );
		$second = new OverviewPage( $this->context_with( array( 'pontifex_transfer_history' => $import_last ) ), $this->store_with( array() ), '1.1.0' );

		$this->assertSame( 'Last rollback', $first->last_outcome_row()['operation'] ?? null );
		$this->assertSame( 'Last restore', $second->last_outcome_row()['operation'] ?? null, 'A newer export is stepped over.' );
	}

	/**
	 * With no restore or rollback on record there is nothing to show — and exports alone are not a restore.
	 *
	 * @return void
	 */
	public function test_last_outcome_row_is_null_with_no_record(): void {
		$none        = new OverviewPage( $this->context_with( array() ), $this->store_with( array() ), '1.1.0' );
		$only_export = new OverviewPage(
			$this->context_with( array( 'pontifex_transfer_history' => array( self::history_entry( 'export', 'succeeded', '2026-10-29T10:00:00+00:00' ) ) ) ),
			$this->store_with( array() ),
			'1.1.0'
		);

		$this->assertNull( $none->last_outcome_row() );
		$this->assertNull( $only_export->last_outcome_row() );
	}

	/**
	 * The Overview is a status screen: an old record is still shown, with no recency bound.
	 *
	 * @return void
	 */
	public function test_last_outcome_row_has_no_recency_bound(): void {
		$this->stub_wp_date();
		$history = array( self::history_entry( 'import', 'failed', '2019-01-02T03:04:00+00:00', array( 'gate' => 'broken' ) ) );
		$page    = new OverviewPage( $this->context_with( array( 'pontifex_transfer_history' => $history ) ), $this->store_with( array() ), '1.1.0' );

		$row = $page->last_outcome_row();

		$this->assertIsArray( $row );
		$this->assertSame( 'The backup is damaged, so nothing was changed.', $row['text'] );
		$this->assertSame( '03:04 on 02-01-2019', $row['when'] );
	}

	/**
	 * A record whose time cannot be read still shows, with a placeholder; one that cannot be described shows nothing.
	 *
	 * @return void
	 */
	public function test_last_outcome_row_tolerates_a_damaged_record(): void {
		$this->stub_wp_date();

		$unreadable_time = new OverviewPage(
			$this->context_with( array( 'pontifex_transfer_history' => array( self::history_entry( 'import', 'succeeded', 'not a time', array( 'entries' => 1 ) ) ) ) ),
			$this->store_with( array() ),
			'1.1.0'
		);
		$unrecognised    = new OverviewPage(
			$this->context_with( array( 'pontifex_transfer_history' => array( self::history_entry( 'import', 'weird', '2026-10-29T10:00:00+00:00', array( 'entries' => 1 ) ) ) ) ),
			$this->store_with( array() ),
			'1.1.0'
		);

		$row = $unreadable_time->last_outcome_row();
		$this->assertIsArray( $row );
		$this->assertSame( '(unknown)', $row['when'] );
		$this->assertNull( $unrecognised->last_outcome_row() );
	}

	/**
	 * The rendered screen carries the last outcome as one more stat, under the right label, and nothing when there is none.
	 *
	 * @return void
	 */
	public function test_render_shows_the_last_outcome_only_when_there_is_one(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->stub_wp_date();

		$with = new OverviewPage(
			$this->context_with( array( 'pontifex_transfer_history' => array( self::history_entry( 'rollback', 'succeeded', '2026-10-29T10:42:00+00:00', array( 'entries' => 2 ) ) ) ) ),
			$this->store_with( array() ),
			'1.1.0'
		);
		$none = new OverviewPage( $this->context_with( array() ), $this->store_with( array() ), '1.1.0' );

		ob_start();
		$with->render();
		$shown = (string) ob_get_clean();

		ob_start();
		$none->render();
		$absent = (string) ob_get_clean();

		$this->assertStringContainsString( '<span class="pontifex-stat-operation">Last rollback</span>', $shown );
		$this->assertStringContainsString( '<span class="pontifex-stat-line">Rolled back 2 entries.</span>', $shown );
		$this->assertStringContainsString( '<span class="pontifex-stat-size">10:42 on 29-10-2026</span>', $shown );
		$this->assertStringNotContainsString( 'Last restore', $shown );
		$this->assertStringNotContainsString( 'Last rollback', $absent );
		$this->assertStringNotContainsString( 'Last restore', $absent );
	}

	/**
	 * The sentence is escaped at the point of output, like every other dynamic value on the screen.
	 *
	 * The sentence is built from counts and fixed labels, so nothing hostile can reach it
	 * through the record; this pins that the page still escapes it rather than trusting that.
	 *
	 * @return void
	 */
	public function test_render_escapes_the_last_outcome(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->stub_wp_date();
		Functions\when( 'esc_html' )->alias(
			static function ( string $text ): string {
				return htmlspecialchars( $text, ENT_QUOTES );
			}
		);

		$details = array(
			'entries'        => 1,
			'relinked'       => true,
			'rows_rewritten' => 2,
		);
		$history = array( self::history_entry( 'import', 'succeeded', '2026-10-29T10:42:00+00:00', $details ) );
		$page    = new OverviewPage( $this->context_with( array( 'pontifex_transfer_history' => $history ) ), $this->store_with( array() ), '1.1.0' );

		ob_start();
		$page->render();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'The backup&#039;s links were rewritten across 2 database rows.', $output );
	}
}
