<?php
/**
 * Unit tests for the admin menu registrar.
 *
 * @package Pontifex\Tests
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use Mockery;
use Pontifex\Admin\Menu;
use Pontifex\Admin\OverviewPage;
use Pontifex\Rollback\RollbackStoreInterface;
use Pontifex\Tests\TestCase;
use Pontifex\WordPress\WordPressContext;

/**
 * Covers the security-relevant menu wiring: the capability gate on the pages,
 * and the rule that the stylesheet loads only on Pontifex screens.
 */
final class MenuTest extends TestCase {

	/**
	 * A menu with a real Overview page whose render callback is never invoked here.
	 *
	 * OverviewPage is final (so it cannot be mocked), but Menu never calls its
	 * render method during registration or enqueuing — it only stores it as a
	 * callback — so a real instance built on mocked interface dependencies is the
	 * right double.
	 *
	 * @return Menu
	 */
	private function menu(): Menu {
		$overview = new OverviewPage(
			Mockery::mock( WordPressContext::class ),
			Mockery::mock( RollbackStoreInterface::class ),
			'0.5.0'
		);
		return new Menu( $overview );
	}

	/**
	 * Both the top-level page and its subpage require the manage_options capability.
	 *
	 * @return void
	 */
	public function test_register_pages_gates_on_manage_options(): void {
		$menu_args    = array();
		$submenu_args = array();
		Functions\when( 'add_menu_page' )->alias(
			static function ( ...$args ) use ( &$menu_args ) {
				$menu_args = $args;
				return 'toplevel_page_pontifex';
			}
		);
		Functions\when( 'add_submenu_page' )->alias(
			static function ( ...$args ) use ( &$submenu_args ) {
				$submenu_args = $args;
				return 'pontifex_page_overview';
			}
		);

		$this->menu()->register_pages();

		// add_menu_page( page_title, menu_title, capability, slug, callback, icon, position ).
		$this->assertSame( 'manage_options', $menu_args[2] );
		$this->assertSame( 'pontifex', $menu_args[3] );
		// add_submenu_page( parent_slug, page_title, menu_title, capability, slug, callback ).
		$this->assertSame( 'pontifex', $submenu_args[0] );
		$this->assertSame( 'manage_options', $submenu_args[3] );
	}

	/**
	 * The stylesheet is enqueued on a Pontifex screen.
	 *
	 * @return void
	 */
	public function test_enqueue_assets_loads_on_a_pontifex_screen(): void {
		Functions\when( 'add_menu_page' )->justReturn( 'toplevel_page_pontifex' );
		Functions\when( 'add_submenu_page' )->justReturn( 'pontifex_page_overview' );
		$enqueued = false;
		Functions\when( 'wp_enqueue_style' )->alias(
			static function () use ( &$enqueued ): void {
				$enqueued = true;
			}
		);

		$menu = $this->menu();
		$menu->register_pages();
		$menu->enqueue_assets( 'toplevel_page_pontifex' );

		$this->assertTrue( $enqueued );
	}

	/**
	 * The stylesheet is not enqueued on any other admin screen.
	 *
	 * @return void
	 */
	public function test_enqueue_assets_skips_other_screens(): void {
		Functions\when( 'add_menu_page' )->justReturn( 'toplevel_page_pontifex' );
		Functions\when( 'add_submenu_page' )->justReturn( 'pontifex_page_overview' );
		$enqueued = false;
		Functions\when( 'wp_enqueue_style' )->alias(
			static function () use ( &$enqueued ): void {
				$enqueued = true;
			}
		);

		$menu = $this->menu();
		$menu->register_pages();
		$menu->enqueue_assets( 'edit.php' );

		$this->assertFalse( $enqueued );
	}
}
