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
	 * The Backup page controller.
	 *
	 * @var BackupPage
	 */
	private BackupPage $backup;

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
	 * The hook suffix of the Backup screen — the one screen that also loads a script.
	 *
	 * @var string
	 */
	private string $backup_hook = '';

	/**
	 * Construct the menu around its page controllers.
	 *
	 * @param OverviewPage $overview The Overview page controller.
	 * @param BackupPage   $backup   The Backup page controller.
	 */
	public function __construct( OverviewPage $overview, BackupPage $backup ) {
		$this->overview = $overview;
		$this->backup   = $backup;
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

		$backup_hook = add_submenu_page(
			self::SLUG,
			__( 'Pontifex — Backup', 'pontifex' ),
			__( 'Backup', 'pontifex' ),
			self::CAPABILITY,
			self::SLUG . '-backup',
			array( $this->backup, 'render' )
		);

		if ( is_string( $overview_hook ) && '' !== $overview_hook ) {
			$this->page_hooks[] = $overview_hook;
		}

		if ( is_string( $backup_hook ) && '' !== $backup_hook ) {
			$this->page_hooks[] = $backup_hook;
			$this->backup_hook  = $backup_hook;
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

		if ( '' !== $this->backup_hook && $hook_suffix === $this->backup_hook ) {
			$this->enqueue_backup_script( $base, $version );
		}
	}

	/**
	 * Enqueue and configure the Backup screen's script.
	 *
	 * The script drives the create, progress, download, and delete actions over
	 * admin-ajax. It is localised with the ajax URL, a `pontifex_backup` nonce, and
	 * the translated strings it shows; the server re-checks the capability and the
	 * nonce on every action, so the localised nonce only spares the operator a
	 * stale-page failure, it is not the security boundary.
	 *
	 * @param string       $base    The plugin's base URL.
	 * @param string|false $version The asset version, or false when undefined.
	 * @return void
	 */
	private function enqueue_backup_script( string $base, string|false $version ): void {
		wp_enqueue_script(
			'pontifex-backup',
			$base . 'assets/admin/pontifex-backup.js',
			array(),
			$version,
			true
		);

		wp_localize_script(
			'pontifex-backup',
			'pontifexBackup',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( BackupController::NONCE_ACTION ),
				'strings' => array(
					'starting'      => __( 'Starting backup…', 'pontifex' ),
					/* translators: %s: number of files found so far */
					'scanning'      => __( 'Scanning files… %s', 'pontifex' ),
					/* translators: 1: bytes copied so far, 2: total bytes, both as human-readable sizes */
					'progress'      => __( '%1$s of %2$s', 'pontifex' ),
					/* translators: %s: elapsed time, e.g. 0:48 */
					'elapsed'       => __( '%s elapsed', 'pontifex' ),
					/* translators: 1: elapsed time, 2: estimated time remaining */
					'timing'        => __( '%1$s elapsed with about %2$s left', 'pontifex' ),
					'failed'        => __( 'The backup could not be completed. Check the Pontifex log for details.', 'pontifex' ),
					'confirmDelete' => __( 'Delete this backup? This cannot be undone.', 'pontifex' ),
				),
			)
		);
	}
}
