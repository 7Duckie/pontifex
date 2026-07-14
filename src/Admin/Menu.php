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
 * Version 0.5.0 builds the admin UI screen by screen: the Overview, Backup,
 * Verify and Restore screens sit under a top-level menu. The menu and every page
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
	 * The Verify page controller.
	 *
	 * @var VerifyPage
	 */
	private VerifyPage $verify;

	/**
	 * The Restore page controller.
	 *
	 * @var RestorePage
	 */
	private RestorePage $restore;

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
	 * The hook suffix of the Backup screen — one of the screens that load a script.
	 *
	 * @var string
	 */
	private string $backup_hook = '';

	/**
	 * The hook suffix of the Verify screen — another screen that loads a script.
	 *
	 * @var string
	 */
	private string $verify_hook = '';

	/**
	 * The hook suffix of the Restore screen — another screen that loads a script.
	 *
	 * @var string
	 */
	private string $restore_hook = '';

	/**
	 * Construct the menu around its page controllers.
	 *
	 * @param OverviewPage $overview The Overview page controller.
	 * @param BackupPage   $backup   The Backup page controller.
	 * @param VerifyPage   $verify   The Verify page controller.
	 * @param RestorePage  $restore  The Restore page controller.
	 */
	public function __construct( OverviewPage $overview, BackupPage $backup, VerifyPage $verify, RestorePage $restore ) {
		$this->overview = $overview;
		$this->backup   = $backup;
		$this->verify   = $verify;
		$this->restore  = $restore;
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

		$verify_hook = add_submenu_page(
			self::SLUG,
			__( 'Pontifex — Verify', 'pontifex' ),
			__( 'Verify', 'pontifex' ),
			self::CAPABILITY,
			self::SLUG . '-verify',
			array( $this->verify, 'render' )
		);

		$restore_hook = add_submenu_page(
			self::SLUG,
			__( 'Pontifex — Restore', 'pontifex' ),
			__( 'Restore', 'pontifex' ),
			self::CAPABILITY,
			self::SLUG . '-restore',
			array( $this->restore, 'render' )
		);

		if ( is_string( $overview_hook ) && '' !== $overview_hook ) {
			$this->page_hooks[] = $overview_hook;
		}

		if ( is_string( $backup_hook ) && '' !== $backup_hook ) {
			$this->page_hooks[] = $backup_hook;
			$this->backup_hook  = $backup_hook;
		}

		if ( is_string( $verify_hook ) && '' !== $verify_hook ) {
			$this->page_hooks[] = $verify_hook;
			$this->verify_hook  = $verify_hook;
		}

		if ( is_string( $restore_hook ) && '' !== $restore_hook ) {
			$this->page_hooks[] = $restore_hook;
			$this->restore_hook = $restore_hook;
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
		$version = $this->assets_version();

		wp_enqueue_style(
			'pontifex-admin',
			$base . 'assets/admin/pontifex-admin.css',
			array(),
			$version
		);

		if ( '' !== $this->backup_hook && $hook_suffix === $this->backup_hook ) {
			$this->enqueue_backup_script( $base, $version );
		}

		if ( '' !== $this->verify_hook && $hook_suffix === $this->verify_hook ) {
			$this->enqueue_verify_script( $base, $version );
		}

		if ( '' !== $this->restore_hook && $hook_suffix === $this->restore_hook ) {
			$this->enqueue_restore_script( $base, $version );
			$this->enqueue_upload_script( $base, $version );
		}
	}

	/**
	 * Version the admin assets by their newest modification time.
	 *
	 * The query-string version on the enqueued CSS and JS is what tells the browser
	 * whether to refetch them. The plugin version alone is not enough: during
	 * development the same version ships repeatedly with changed assets, so the
	 * browser would serve a stale, cached script against fresh markup. Using the
	 * newest asset mtime means any edit to a stylesheet or script busts the cache
	 * for the whole admin UI. Falls back to the plugin version when the files (or
	 * the plugin directory) cannot be read.
	 *
	 * @return string|false The version string, or false when none can be determined.
	 */
	private function assets_version(): string|false {
		$latest = 0;

		if ( defined( 'PONTIFEX_PLUGIN_DIR' ) ) {
			$dir   = (string) constant( 'PONTIFEX_PLUGIN_DIR' );
			$files = array(
				'assets/admin/pontifex-admin.css',
				'assets/admin/pontifex-backup.js',
				'assets/admin/pontifex-verify.js',
				'assets/admin/pontifex-restore.js',
				'assets/admin/pontifex-upload.js',
			);
			foreach ( $files as $relative_path ) {
				$path = $dir . $relative_path;
				if ( ! file_exists( $path ) ) {
					continue;
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filemtime -- Reading the modification time of a plugin-owned asset to version it for cache-busting; WP_Filesystem is unavailable in CLI/test contexts.
				$mtime = filemtime( $path );
				if ( false !== $mtime && $mtime > $latest ) {
					$latest = $mtime;
				}
			}
		}

		if ( $latest > 0 ) {
			return (string) $latest;
		}

		return defined( 'PONTIFEX_VERSION' ) ? (string) constant( 'PONTIFEX_VERSION' ) : false;
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
					'starting'          => __( 'Starting backup…', 'pontifex' ),
					/* translators: %s: number of files found so far */
					'scanning'          => __( 'Scanning files… %s', 'pontifex' ),
					/* translators: 1: bytes processed so far, 2: total bytes, both as human-readable sizes */
					'progress'          => __( '%1$s of %2$s', 'pontifex' ),
					/* translators: %s: elapsed time, e.g. 0:48 */
					'elapsed'           => __( 'Time elapsed - %s', 'pontifex' ),
					/* translators: 1: elapsed time, 2: estimated time remaining */
					'timing'            => __( 'Time elapsed - %1$s with about %2$s left', 'pontifex' ),
					/* translators: 1: the finished backup's size, 2: the source data size it was compressed from */
					'created'           => __( 'Backup created — %1$s (compressed from %2$s)', 'pontifex' ),
					'createdPlain'      => __( 'Backup created.', 'pontifex' ),
					'reattached'        => __( 'A backup is running — re-attached to its progress.', 'pontifex' ),
					/* translators: shown after re-attaching to a backup that then finished */
					'finishedElsewhere' => __( 'The running backup finished.', 'pontifex' ),
					'cancel'            => __( 'Cancel backup', 'pontifex' ),
					'cancelling'        => __( 'Cancelling…', 'pontifex' ),
					'cancelled'         => __( 'Backup cancelled.', 'pontifex' ),
					'confirmCancel'     => __( 'Cancel this backup? The progress so far will be lost.', 'pontifex' ),
					'failed'            => __( 'The backup could not be completed. Check the Pontifex log for details.', 'pontifex' ),
					'confirmDelete'     => __( 'Delete this backup? This cannot be undone.', 'pontifex' ),
					/* translators: %s: the next scheduled run time, e.g. "2026-07-14 03:00 UTC" */
					'scheduleSaved'     => __( 'Schedule saved. Next backup at %s.', 'pontifex' ),
					'scheduleSavedOff'  => __( 'Schedule saved. Scheduled backups are off.', 'pontifex' ),
					'scheduleFailed'    => __( 'The schedule could not be saved. Reload the page and try again.', 'pontifex' ),
				),
			)
		);
	}

	/**
	 * Enqueue and configure the Verify screen's script.
	 *
	 * Drives the per-backup Verify actions over admin-ajax. Localised with the
	 * ajax URL, a `pontifex_verify` nonce, and the translated strings it shows; the
	 * server re-checks the capability and nonce on every action.
	 *
	 * @param string       $base    The plugin's base URL.
	 * @param string|false $version The asset version, or false when undefined.
	 * @return void
	 */
	private function enqueue_verify_script( string $base, string|false $version ): void {
		wp_enqueue_script(
			'pontifex-verify',
			$base . 'assets/admin/pontifex-verify.js',
			array(),
			$version,
			true
		);

		wp_localize_script(
			'pontifex-verify',
			'pontifexVerify',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( VerifyController::NONCE_ACTION ),
				'strings' => array(
					'starting' => __( 'Verifying…', 'pontifex' ),
					/* translators: 1: bytes processed so far, 2: total bytes, both as human-readable sizes */
					'progress' => __( '%1$s of %2$s', 'pontifex' ),
					/* translators: %s: elapsed time, e.g. 0:48 */
					'elapsed'  => __( 'Time elapsed - %s', 'pontifex' ),
					'failed'   => __( 'The verification could not be completed. Check the Pontifex log for details.', 'pontifex' ),
				),
			)
		);
	}

	/**
	 * Enqueue and configure the Restore screen's script.
	 *
	 * Drives the typed-action box (restore / rollback) over admin-ajax. Localised
	 * with the ajax URL, a `pontifex_restore` nonce, and the translated strings it
	 * shows; the server re-checks the capability and nonce on every action.
	 *
	 * @param string       $base    The plugin's base URL.
	 * @param string|false $version The asset version, or false when undefined.
	 * @return void
	 */
	private function enqueue_restore_script( string $base, string|false $version ): void {
		wp_enqueue_script(
			'pontifex-restore',
			$base . 'assets/admin/pontifex-restore.js',
			array(),
			$version,
			true
		);

		wp_localize_script(
			'pontifex-restore',
			'pontifexRestore',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( RestoreController::NONCE_ACTION ),
				'loginUrl' => wp_login_url(),
				'strings'  => array(
					'starting'           => __( 'Starting…', 'pontifex' ),
					'verifying'          => __( 'Verifying the backup…', 'pontifex' ),
					'backingUp'          => __( 'Backing up your content…', 'pontifex' ),
					'restoring'          => __( 'Restoring…', 'pontifex' ),
					'rollingBack'        => __( 'Rolling back…', 'pontifex' ),
					/* translators: 1: bytes processed so far, 2: total bytes, both as human-readable sizes */
					'progress'           => __( '%1$s of %2$s', 'pontifex' ),
					/* translators: %s: elapsed time, e.g. 0:48 */
					'elapsed'            => __( 'Time elapsed - %s', 'pontifex' ),
					'failed'             => __( 'The restore could not be completed. Check the Pontifex log for details.', 'pontifex' ),
					'failedUnknown'      => __( 'The connection was lost, so the result is unknown — the operation may have completed or may still be running. Wait a moment, then reload this page to check; if the site looks wrong, run a rollback. Check the Pontifex log for details.', 'pontifex' ),
					'sessionUnknown'     => __( 'If pages ask you to log in again, your session was reset by the restore.', 'pontifex' ),
					/* translators: shown after re-attaching to an operation that then finished; the verdict went to the request that started it */
					'reattachedFinished' => __( 'The running operation finished. Reload this page to see the result, and check the Overview screen or the Pontifex log for its outcome.', 'pontifex' ),
					'signedOutTitle'     => __( 'Restore complete', 'pontifex' ),
					'signedOut'          => __( 'Your site\'s users were restored, so you\'ve been signed out. Please log in again.', 'pontifex' ),
					'loginLink'          => __( 'Log in', 'pontifex' ),
				),
			)
		);
	}

	/**
	 * Enqueue and configure the foreign-backup upload script.
	 *
	 * Loaded alongside the Restore screen's own script, since uploading a backup
	 * taken on another server is how a foreign archive joins this site's restore
	 * list. Localised with the ajax URL, a `pontifex_upload` nonce, a chunk size
	 * derived from the site's upload ceiling (with headroom for the request's other
	 * fields), and the translated strings it shows; the server re-checks the
	 * capability and the nonce on every chunk.
	 *
	 * @param string       $base    The plugin's base URL.
	 * @param string|false $version The asset version, or false when undefined.
	 * @return void
	 */
	private function enqueue_upload_script( string $base, string|false $version ): void {
		wp_enqueue_script(
			'pontifex-upload',
			$base . 'assets/admin/pontifex-upload.js',
			array(),
			$version,
			true
		);

		// The whole request body is the chunk plus a handful of small fields (the nonce,
		// the id, the offset, the total, the name), so the chunk is kept below the upload
		// ceiling by a margin large enough for those fields.
		$ceiling    = (int) wp_max_upload_size();
		$headroom   = 32 * 1024;
		$chunk_size = $ceiling > $headroom ? $ceiling - $headroom : $ceiling;

		wp_localize_script(
			'pontifex-upload',
			'pontifexUpload',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( UploadController::NONCE_ACTION ),
				'chunkSize' => $chunk_size,
				'strings'   => array(
					'starting' => __( 'Uploading…', 'pontifex' ),
					/* translators: 1: bytes processed so far, 2: total bytes, both as human-readable sizes */
					'progress' => __( '%1$s of %2$s', 'pontifex' ),
					'done'     => __( 'Upload complete.', 'pontifex' ),
					'failed'   => __( 'The upload could not be completed. Check the Pontifex log for details.', 'pontifex' ),
					'noFile'   => __( 'No file chosen', 'pontifex' ),
				),
			)
		);
	}
}
