<?php
/**
 * Pontifex admin UI bootstrap — wires the admin screens into WordPress.
 *
 * @package Pontifex\Admin
 */

declare(strict_types=1);

namespace Pontifex\Admin;

use Pontifex\Environment\RealEnvironment;
use Pontifex\Log\FileLogger;
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
 * the WordPress hooks, including the Backup screen's admin-ajax actions. Tests
 * construct the class with {@see Menu} and controller doubles.
 */
final class AdminBootstrap {

	/**
	 * The admin menu registrar.
	 *
	 * @var Menu
	 */
	private Menu $menu;

	/**
	 * The controller behind the Backup screen's admin-ajax actions.
	 *
	 * @var BackupController
	 */
	private BackupController $backup_controller;

	/**
	 * The controller behind the Verify screen's admin-ajax actions.
	 *
	 * @var VerifyController
	 */
	private VerifyController $verify_controller;

	/**
	 * The controller behind the Restore screen's admin-ajax actions.
	 *
	 * @var RestoreController
	 */
	private RestoreController $restore_controller;

	/**
	 * Construct the bootstrap around the menu registrar and the controllers.
	 *
	 * @param Menu              $menu               The menu registrar to hook into WordPress.
	 * @param BackupController  $backup_controller  The controller serving the Backup screen's actions.
	 * @param VerifyController  $verify_controller  The controller serving the Verify screen's actions.
	 * @param RestoreController $restore_controller The controller serving the Restore screen's actions.
	 */
	public function __construct( Menu $menu, BackupController $backup_controller, VerifyController $verify_controller, RestoreController $restore_controller ) {
		$this->menu               = $menu;
		$this->backup_controller  = $backup_controller;
		$this->verify_controller  = $verify_controller;
		$this->restore_controller = $restore_controller;
	}

	/**
	 * Build the default admin object graph from the real environment.
	 *
	 * The composition root: it reads the content directory and plugin version
	 * from the constants pontifex.php defines, and wires the real WordPress
	 * context, environment, and stores into the Overview and Backup pages and the
	 * backup controller. Kept separate from the constructor so tests inject
	 * doubles instead of touching global state.
	 *
	 * @return self A bootstrap wired to the real WordPress environment.
	 */
	public static function create(): self {
		$context        = new RealWordPressContext();
		$environment    = new RealEnvironment();
		$content_dir    = defined( 'WP_CONTENT_DIR' ) ? (string) constant( 'WP_CONTENT_DIR' ) : '';
		$plugin_version = defined( 'PONTIFEX_VERSION' ) ? (string) constant( 'PONTIFEX_VERSION' ) : '';

		$rollback_store = new RollbackStore( $content_dir );
		$backup_store   = new BackupStore( $content_dir );

		$overview = new OverviewPage( $context, $rollback_store, $plugin_version );
		$backup   = new BackupPage( $context, $backup_store );
		$verify   = new VerifyPage( $context, $backup_store );
		$restore  = new RestorePage( $context, $backup_store, $rollback_store );

		$logger             = new FileLogger( $content_dir . '/pontifex/logs', self::debug_enabled(), protect_directory: true );
		$backup_controller  = new BackupController( $environment, $context, $backup_store, $logger );
		$verify_controller  = new VerifyController( $environment, $context, $backup_store, $logger );
		$restore_controller = new RestoreController( $environment, $context, $backup_store, $rollback_store, $logger );

		return new self( new Menu( $overview, $backup, $verify, $restore ), $backup_controller, $verify_controller, $restore_controller );
	}

	/**
	 * Whether WordPress debug logging is on, read from the WP_DEBUG constant.
	 *
	 * Mirrors the verbosity floor the WP-CLI commands use, so the admin Backup's
	 * log lines follow the same debug setting as the rest of Pontifex.
	 *
	 * @return bool True when WP_DEBUG is defined and truthy.
	 */
	private static function debug_enabled(): bool {
		return defined( 'WP_DEBUG' ) && (bool) constant( 'WP_DEBUG' );
	}

	/**
	 * Attach the admin hooks.
	 *
	 * Registers the menu on `admin_menu`, the page assets on
	 * `admin_enqueue_scripts`, and the Backup and Verify screens' admin-ajax
	 * actions. The menu and asset callbacks are inert on non-Pontifex screens, and
	 * each ajax action re-checks the capability and nonce, so calling this
	 * unconditionally from an `is_admin()` block is safe.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this->menu, 'register_pages' ) );
		add_action( 'admin_enqueue_scripts', array( $this->menu, 'enqueue_assets' ) );

		add_action( 'wp_ajax_pontifex_create_backup', array( $this->backup_controller, 'create' ) );
		add_action( 'wp_ajax_pontifex_backup_progress', array( $this->backup_controller, 'progress' ) );
		add_action( 'wp_ajax_pontifex_cancel_backup', array( $this->backup_controller, 'cancel' ) );
		add_action( 'wp_ajax_pontifex_download_backup', array( $this->backup_controller, 'download' ) );
		add_action( 'wp_ajax_pontifex_delete_backup', array( $this->backup_controller, 'delete' ) );

		add_action( 'wp_ajax_pontifex_verify', array( $this->verify_controller, 'verify' ) );
		add_action( 'wp_ajax_pontifex_verify_progress', array( $this->verify_controller, 'progress' ) );

		add_action( 'wp_ajax_pontifex_restore', array( $this->restore_controller, 'restore' ) );
		add_action( 'wp_ajax_pontifex_rollback', array( $this->restore_controller, 'rollback' ) );
		add_action( 'wp_ajax_pontifex_restore_progress', array( $this->restore_controller, 'progress' ) );
	}
}
