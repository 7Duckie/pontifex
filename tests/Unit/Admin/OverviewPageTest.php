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
	 * Reads both counter options into one activity row each.
	 *
	 * @return void
	 */
	public function test_stats_rows_reads_export_and_import_counters(): void {
		$context = $this->context_with(
			array(
				'pontifex_export_stats' => array(
					'attempted'      => 5,
					'succeeded'      => 4,
					'failed'         => 1,
					'bytes_exported' => 2048,
				),
				'pontifex_import_stats' => array(
					'attempted'      => 2,
					'succeeded'      => 2,
					'failed'         => 0,
					'bytes_imported' => 1024,
				),
			)
		);
		$page    = new OverviewPage( $context, $this->store_with( array() ), '0.5.0' );

		$rows = $page->stats_rows();

		$this->assertCount( 2, $rows );
		$this->assertSame( 5, $rows[0]['attempted'] );
		$this->assertSame( 4, $rows[0]['succeeded'] );
		$this->assertSame( 1, $rows[0]['failed'] );
		$this->assertSame( '2048 B', $rows[0]['size'] );
		$this->assertSame( 2, $rows[1]['succeeded'] );
		$this->assertSame( '1024 B', $rows[1]['size'] );
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
}
