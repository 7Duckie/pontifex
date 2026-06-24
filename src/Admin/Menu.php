<?php
/**
 * Pontifex admin menu — registers the top-level Pontifex screens and assets.
 *
 * @package Pontifex\Admin
 */

declare(strict_types=1);

namespace Pontifex\Admin;

/**
 * Registers the top-level "Pontifex" admin menu and enqueues its stylesheet.
 *
 * Version 0.5.0 ships one screen — the Overview — under a top-level menu; later slices
 * add Backup, Verify and Restore as sibling subpages. The menu and every page
 * require the `manage_options` capability (the WordPress "manage this site"
 * gate): the admin UI is deny-by-default, unlike the shell-trust CLI.
 *
 * The stylesheet is enqueued only on Pontifex screens, identified by the hook
 * suffixes WordPress returns from add_menu_page(), so the plugin adds nothing to
 * the weight of any other admin page.
 */
final class Menu {

	/**
	 * The menu/page slug, and the base of every Pontifex screen's hook suffix.
	 *
	 * @var string
	 */
	public const SLUG = 'pontifex';

	/**
	 * The capability required to see and use the Pontifex screens.
	 *
	 * @var string
	 */
	public const CAPABILITY = 'manage_options';

	/**
	 * The Overview page controller.
	 *
	 * @var OverviewPage
	 */
	private OverviewPage $overview;

	/**
	 * The hook suffixes WordPress assigned to the Pontifex screens.
	 *
	 * Collected as the pages register so {@see self::enqueue_assets()} can tell a
	 * Pontifex screen from any other admin page without guessing at strings.
	 *
	 * @var array<int, string>
	 */
	private array $page_hooks = array();

	/**
	 * Construct the menu around its page controllers.
	 *
	 * @param OverviewPage $overview The Overview page controller.
	 */
	public function __construct( OverviewPage $overview ) {
		$this->overview = $overview;
	}

	/**
	 * Register the top-level menu and its subpages.
	 *
	 * Runs on the `admin_menu` hook. The top-level entry and the first subpage
	 * both render the Overview; without the explicit subpage WordPress would
	 * label the first child with the raw slug.
	 *
	 * @return void
	 */
	public function register_pages(): void {
		$overview_hook = add_menu_page(
			__( 'Pontifex', 'pontifex' ),
			__( 'Pontifex', 'pontifex' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this->overview, 'render' ),
			'dashicons-migrate',
			80
		);

		add_submenu_page(
			self::SLUG,
			__( 'Pontifex — Overview', 'pontifex' ),
			__( 'Overview', 'pontifex' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this->overview, 'render' )
		);

		if ( is_string( $overview_hook ) && '' !== $overview_hook ) {
			$this->page_hooks[] = $overview_hook;
		}
	}

	/**
	 * Enqueue the admin stylesheet on Pontifex screens only.
	 *
	 * Runs on `admin_enqueue_scripts`. The current screen's hook suffix is passed
	 * in; the stylesheet loads only when it is one of ours, versioned by the
	 * plugin version so a release busts the cache.
	 *
	 * @param string $hook_suffix The current admin page's hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, $this->page_hooks, true ) ) {
			return;
		}

		$base    = defined( 'PONTIFEX_PLUGIN_URL' ) ? (string) constant( 'PONTIFEX_PLUGIN_URL' ) : '';
		$version = defined( 'PONTIFEX_VERSION' ) ? (string) constant( 'PONTIFEX_VERSION' ) : false;

		wp_enqueue_style(
			'pontifex-admin',
			$base . 'assets/admin/pontifex-admin.css',
			array(),
			$version
		);
	}
}
