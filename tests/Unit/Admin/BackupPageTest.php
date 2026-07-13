<?php
/**
 * Tests for the admin Backup page.
 *
 * @package Pontifex\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use Mockery;
use Pontifex\Admin\BackupPage;
use Pontifex\Admin\BackupStore;
use Pontifex\Tests\TestCase;
use Pontifex\WordPress\WordPressContext;
use RuntimeException;

/**
 * Covers the Backup page's pure data method and its capability gate.
 *
 * As with OverviewPageTest, the rendering is exercised only as a capability gate
 * and a smoke test; the exact markup is output formatting rather than logic. The
 * data method {@see BackupPage::backup_rows()} is asserted directly against a
 * real store seeded with backup fixtures.
 */
final class BackupPageTest extends TestCase {

	/**
	 * Temporary content directory the store is rooted at for one test.
	 *
	 * @var string
	 */
	private string $base = '';

	/**
	 * Reserve a unique temp content directory.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->base = sys_get_temp_dir() . '/pontifex-backup-page-' . uniqid( '', true );
		Functions\when( 'wp_date' )->alias(
			static function ( string $format, ?int $timestamp = null ): string {
				return gmdate( $format, $timestamp ?? 0 );
			}
		);
	}

	/**
	 * Remove the temp directory tree.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		self::rmtree( $this->base );
		parent::tearDown();
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
		$page = new BackupPage( $this->context_mock(), new BackupStore( $this->base ) );

		$this->expectException( RuntimeException::class );
		$page->render();
	}

	/**
	 * Produces the page for a capable user, including a download and delete control per backup.
	 *
	 * @return void
	 */
	public function test_render_outputs_the_page_for_a_capable_user(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_create_nonce' )->justReturn( 'test-nonce' );
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'add_query_arg' )->alias(
			static function ( array $args, string $url ): string {
				return $url . '?' . http_build_query( $args );
			}
		);

		$store = new BackupStore( $this->base );
		$store->ensure_directory();
		$this->seed( $store, 'pontifex-backup-20260101T000000Z.wpmig', 'archive-bytes' );
		$page = new BackupPage( $this->context_mock(), $store );

		ob_start();
		$page->render();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( '<p class="pontifex-eyebrow">Pontifex</p>', $output );
		$this->assertStringContainsString( '<h1 class="pontifex-title">Backup</h1>', $output );
		$this->assertStringContainsString( 'id="pontifex-create-backup"', $output );
		$this->assertStringContainsString( 'id="pontifex-backup-track"', $output );
		$this->assertStringContainsString( 'role="progressbar"', $output );
		$this->assertStringContainsString( 'aria-label="Backup progress"', $output, 'The progressbar carries an accessible name.' );
		$this->assertStringContainsString( 'aria-describedby="pontifex-backup-progress"', $output, 'The status line is associated with the bar.' );
		$this->assertStringContainsString( 'pontifex-backup-20260101T000000Z.wpmig', $output );
		$this->assertStringContainsString( 'Download', $output );
		$this->assertStringContainsString( 'pontifex-delete-backup', $output );
	}

	/**
	 * Renders the Scheduled backups section pre-filled from the stored schedule.
	 *
	 * @return void
	 */
	public function test_render_prefills_the_schedule_section(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_create_nonce' )->justReturn( 'test-nonce' );
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'add_query_arg' )->alias(
			static function ( array $args, string $url ): string {
				return $url . '?' . http_build_query( $args );
			}
		);
		// The schedule is enabled and its cron event is pending, so the live-status
		// line shows the next run rather than the dead-schedule warning.
		Functions\when( 'wp_next_scheduled' )->justReturn( 1_700_000_000 );

		$store = new BackupStore( $this->base );
		$store->ensure_directory();
		$page = new BackupPage(
			$this->context_mock(
				array(
					'enabled'   => true,
					'frequency' => 'weekly',
					'hour'      => 5,
					'retention' => 4,
				)
			),
			$store
		);

		ob_start();
		$page->render();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Scheduled backups', $output );
		$this->assertStringContainsString( 'id="pontifex-schedule-enabled" checked', $output, 'The enabled box reflects the stored schedule.' );
		$this->assertStringContainsString( '<option value="weekly" selected>', $output, 'The stored frequency is pre-selected.' );
		$this->assertStringContainsString( '<option value="5" selected>05:00</option>', $output, 'The stored hour is pre-selected and shown as UTC-style time.' );
		$this->assertStringContainsString( 'id="pontifex-schedule-retention" class="pontifex-action-input" min="1" step="1" value="4"', $output, 'The stored retention pre-fills the number field with the floor as its minimum.' );
		$this->assertStringContainsString( 'id="pontifex-schedule-save"', $output );
		$this->assertStringContainsString( 'Time (UTC)', $output, 'The hour is labelled as UTC, never site time.' );
		$this->assertStringContainsString( 'Next automatic backup: 2023-11-14 22:13 UTC.', $output, 'An enabled, healthy schedule shows its real next run time from the pending cron event.' );
	}

	/**
	 * An enabled schedule whose cron event has vanished shows a health warning.
	 *
	 * The silently-dead-schedule case the CLI `show` also catches: the settings
	 * say on, but WordPress has no pending event, so nothing will fire until the
	 * schedule is saved again. The admin-only operator must be told.
	 *
	 * @return void
	 */
	public function test_render_warns_when_an_enabled_schedule_has_no_pending_event(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_create_nonce' )->justReturn( 'test-nonce' );
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'add_query_arg' )->alias(
			static function ( array $args, string $url ): string {
				return $url . '?' . http_build_query( $args );
			}
		);
		// The schedule is enabled but WordPress has no pending event for it.
		Functions\when( 'wp_next_scheduled' )->justReturn( false );

		$store = new BackupStore( $this->base );
		$store->ensure_directory();
		$page = new BackupPage(
			$this->context_mock(
				array(
					'enabled'   => true,
					'frequency' => 'daily',
					'hour'      => 3,
					'retention' => 3,
				)
			),
			$store
		);

		ob_start();
		$page->render();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'pontifex-notice-warning', $output, 'The dead-schedule warning is rendered.' );
		$this->assertStringContainsString( 'has no pending event', $output );
		$this->assertStringNotContainsString( 'Next automatic backup:', $output, 'A dead schedule must not claim a next run.' );
	}

	/**
	 * A disabled schedule shows neither a next-run line nor a health warning.
	 *
	 * @return void
	 */
	public function test_render_shows_no_status_line_when_the_schedule_is_off(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_create_nonce' )->justReturn( 'test-nonce' );
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'add_query_arg' )->alias(
			static function ( array $args, string $url ): string {
				return $url . '?' . http_build_query( $args );
			}
		);
		// A disabled schedule never consults the scheduler; fail loudly if it does.
		Functions\when( 'wp_next_scheduled' )->alias(
			static function (): void {
				throw new RuntimeException( 'wp_next_scheduled must not be called for a disabled schedule.' );
			}
		);

		$store = new BackupStore( $this->base );
		$store->ensure_directory();
		$page = new BackupPage( $this->context_mock(), $store );

		ob_start();
		$page->render();
		$output = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'Next automatic backup:', $output );
		$this->assertStringNotContainsString( 'pontifex-notice-warning', $output );
	}

	/**
	 * Lists backups newest-first, with the time parsed from the name and the size formatted.
	 *
	 * @return void
	 */
	public function test_backup_rows_are_newest_first(): void {
		$store = new BackupStore( $this->base );
		$store->ensure_directory();
		$this->seed( $store, 'pontifex-backup-20260101T120000Z.wpmig', 'old' );
		$this->seed( $store, 'pontifex-backup-20260301T093000Z.wpmig', 'newer-bytes' );
		$page = new BackupPage( $this->context_mock(), $store );

		$rows = $page->backup_rows();

		$this->assertCount( 2, $rows );
		$this->assertSame( 'pontifex-backup-20260301T093000Z.wpmig', $rows[0]['filename'] );
		$this->assertSame( '09:30 on 01-03-2026', $rows[0]['when'] );
		$this->assertSame( '11 B', $rows[0]['size'], 'Size should be the file length, formatted.' );
		$this->assertSame( 'pontifex-backup-20260101T120000Z.wpmig', $rows[1]['filename'] );
		$this->assertSame( '12:00 on 01-01-2026', $rows[1]['when'] );
	}

	/**
	 * A WordPressContext mock with a simple byte-count size formatter.
	 *
	 * The stored-schedule read is stubbed too: render() loads the schedule for
	 * the Scheduled backups section, and an empty option reads as the disabled
	 * default.
	 *
	 * @param array<string, mixed> $schedule Optional stored schedule option data.
	 * @return WordPressContext&\Mockery\MockInterface
	 */
	private function context_mock( array $schedule = array() ) {
		$context = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'format_size' )->andReturnUsing(
			static function ( int $bytes ): string {
				return $bytes . ' B';
			}
		);
		$context->shouldReceive( 'option_value' )->andReturn( $schedule );
		return $context;
	}

	/**
	 * Create a backup fixture with the given name and contents in the store.
	 *
	 * @param BackupStore $store    The store whose directory to seed.
	 * @param string      $filename The filename to create.
	 * @param string      $contents The bytes to write (its length is the reported size).
	 * @return void
	 */
	private function seed( BackupStore $store, string $filename, string $contents ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Seeding a fixture backup in a temp directory.
		file_put_contents( $store->directory() . '/' . $filename, $contents );
	}

	/**
	 * Recursively delete a directory tree.
	 *
	 * @param string $path Absolute path to remove.
	 * @return void
	 */
	private static function rmtree( string $path ): void {
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( scandir( $path ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$full = $path . '/' . $entry;
			if ( is_dir( $full ) ) {
				self::rmtree( $full );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Test fixture teardown.
				@unlink( $full );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.PHP.NoSilencedErrors.Discouraged -- Test fixture teardown.
		@rmdir( $path );
	}
}
