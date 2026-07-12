<?php
/**
 * Unit tests for the admin bootstrap's hook wiring.
 *
 * @package Pontifex\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use Mockery;
use Pontifex\Admin\AdminBootstrap;
use Pontifex\Admin\BackupController;
use Pontifex\Admin\BackupPage;
use Pontifex\Admin\BackupStore;
use Pontifex\Admin\Menu;
use Pontifex\Admin\OverviewPage;
use Pontifex\Admin\RestoreController;
use Pontifex\Admin\RestorePage;
use Pontifex\Admin\UploadController;
use Pontifex\Admin\VerifyController;
use Pontifex\Admin\VerifyPage;
use Pontifex\Environment\Environment;
use Pontifex\Rollback\RollbackStoreInterface;
use Pontifex\Tests\TestCase;
use Pontifex\WordPress\WordPressContext;
use Psr\Log\NullLogger;

/**
 * Covers that register() attaches every hook the admin layer needs.
 *
 * The bootstrap is built with real Menu and BackupController instances over
 * mocked interface dependencies (both are final, so they are not doubled); the
 * collaborators are only used as callback targets, never invoked, so the test
 * asserts the set of action hooks that were registered.
 */
final class AdminBootstrapTest extends TestCase {

	/**
	 * Attaches the menu, the assets, and the Backup, Verify and Restore admin-ajax actions.
	 *
	 * @return void
	 */
	public function test_register_hooks_menu_assets_and_backup_actions(): void {
		$hooks = array();
		Functions\when( 'add_action' )->alias(
			static function ( string $hook ) use ( &$hooks ): void {
				$hooks[] = $hook;
			}
		);

		( new AdminBootstrap( $this->menu(), $this->controller(), $this->verify_controller(), $this->restore_controller(), $this->upload_controller() ) )->register();

		$this->assertContains( 'admin_menu', $hooks );
		$this->assertContains( 'admin_enqueue_scripts', $hooks );
		$this->assertContains( 'wp_ajax_pontifex_create_backup', $hooks );
		$this->assertContains( 'wp_ajax_pontifex_backup_progress', $hooks );
		$this->assertContains( 'wp_ajax_pontifex_cancel_backup', $hooks );
		$this->assertContains( 'wp_ajax_pontifex_download_backup', $hooks );
		$this->assertContains( 'wp_ajax_pontifex_delete_backup', $hooks );
		$this->assertContains( 'wp_ajax_pontifex_verify', $hooks );
		$this->assertContains( 'wp_ajax_pontifex_verify_progress', $hooks );
		$this->assertContains( 'wp_ajax_pontifex_restore', $hooks );
		$this->assertContains( 'wp_ajax_pontifex_rollback', $hooks );
		$this->assertContains( 'wp_ajax_pontifex_restore_progress', $hooks );
		$this->assertContains( 'wp_ajax_pontifex_upload_chunk', $hooks );
	}

	/**
	 * A real Menu over mocked page dependencies.
	 *
	 * @return Menu
	 */
	private function menu(): Menu {
		$overview = new OverviewPage(
			Mockery::mock( WordPressContext::class ),
			Mockery::mock( RollbackStoreInterface::class ),
			'0.5.0'
		);
		$backup   = new BackupPage(
			Mockery::mock( WordPressContext::class ),
			new BackupStore( sys_get_temp_dir() )
		);
		$verify   = new VerifyPage(
			Mockery::mock( WordPressContext::class ),
			new BackupStore( sys_get_temp_dir() )
		);
		$restore  = new RestorePage(
			Mockery::mock( WordPressContext::class ),
			new BackupStore( sys_get_temp_dir() ),
			Mockery::mock( RollbackStoreInterface::class )
		);
		return new Menu( $overview, $backup, $verify, $restore );
	}

	/**
	 * A real BackupController over mocked dependencies.
	 *
	 * @return BackupController
	 */
	private function controller(): BackupController {
		return new BackupController(
			Mockery::mock( Environment::class ),
			Mockery::mock( WordPressContext::class ),
			new BackupStore( sys_get_temp_dir() ),
			new NullLogger()
		);
	}

	/**
	 * A real VerifyController over mocked dependencies.
	 *
	 * @return VerifyController
	 */
	private function verify_controller(): VerifyController {
		return new VerifyController(
			Mockery::mock( Environment::class ),
			Mockery::mock( WordPressContext::class ),
			new BackupStore( sys_get_temp_dir() ),
			new NullLogger()
		);
	}

	/**
	 * A real RestoreController over mocked dependencies.
	 *
	 * @return RestoreController
	 */
	private function restore_controller(): RestoreController {
		return new RestoreController(
			Mockery::mock( Environment::class ),
			Mockery::mock( WordPressContext::class ),
			new BackupStore( sys_get_temp_dir() ),
			Mockery::mock( RollbackStoreInterface::class ),
			new NullLogger()
		);
	}

	/**
	 * A real UploadController over mocked dependencies.
	 *
	 * @return UploadController
	 */
	private function upload_controller(): UploadController {
		return new UploadController(
			Mockery::mock( WordPressContext::class ),
			new BackupStore( sys_get_temp_dir() ),
			new NullLogger()
		);
	}
}
