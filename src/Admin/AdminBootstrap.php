<?php
/**
 * Pontifex admin UI bootstrap — wires the admin screens into WordPress.
 *
 * @package Pontifex\Admin
 */

declare(strict_types=1);

namespace Pontifex\Admin;

use Pontifex\Rollback\RollbackStore;
use Pontifex\WordPress\RealWordPressContext;

/**
 * Registers the Pontifex admin UI with WordPress.
 *
 * The plugin is CLI-first; this is the v0.5.0 admin layer for operators who do
 * not use WP-CLI. It is wired only on admin-side requests (see pontifex.php) and
 * adds no front-end surface. Every screen reuses the same engines the
 * `wp pontifex` commands use; the admin layer supplies only the WordPress
 * plumbing (menu, assets) and the security model the CLI does not need:
 * capability checks, nonces, and escaped output.
 *
 * {@see self::create()} is the composition root that builds the default object
 * graph from the real WordPress environment; {@see self::register()} attaches
 * the WordPress hooks. Tests construct the class with a {@see Menu} double.
 */
final class AdminBootstrap {

	/**
	 * The admin menu registrar.
	 *
	 * @var Menu
	 */
	private Menu $menu;

	/**
	 * Construct the bootstrap around a menu registrar.
	 *
	 * @param Menu $menu The menu registrar to hook into WordPress.
	 */
	public function __construct( Menu $menu ) {
		$this->menu = $menu;
	}

	/**
	 * Build the default admin object graph from the real environment.
	 *
	 * The composition root: it reads the content directory and plugin version
	 * from the constants pontifex.php defines, and wires the real WordPress
	 * context and rollback store into the Overview page. Kept separate from the
	 * constructor so tests inject doubles instead of touching global state.
	 *
	 * @return self A bootstrap wired to the real WordPress environment.
	 */
	public static function create(): self {
		$context        = new RealWordPressContext();
		$content_dir    = defined( 'WP_CONTENT_DIR' ) ? (string) constant( 'WP_CONTENT_DIR' ) : '';
		$plugin_version = defined( 'PONTIFEX_VERSION' ) ? (string) constant( 'PONTIFEX_VERSION' ) : '';
		$rollback_store = new RollbackStore( $content_dir );

		$overview = new OverviewPage( $context, $rollback_store, $plugin_version );

		return new self( new Menu( $overview ) );
	}

	/**
	 * Attach the admin hooks.
	 *
	 * Registers the menu on `admin_menu` and the page assets on
	 * `admin_enqueue_scripts`. Both callbacks are inert on non-Pontifex screens,
	 * so calling this unconditionally from an `is_admin()` block is safe.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this->menu, 'register_pages' ) );
		add_action( 'admin_enqueue_scripts', array( $this->menu, 'enqueue_assets' ) );
	}
}
