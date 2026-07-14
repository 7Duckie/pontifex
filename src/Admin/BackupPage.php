<?php
/**
 * Pontifex admin Backup page — create, list, download, and delete backups.
 *
 * @package Pontifex\Admin
 */

declare(strict_types=1);

namespace Pontifex\Admin;

use DateTimeImmutable;
use DateTimeZone;
use Pontifex\Manifest\ExclusionRules;
use Pontifex\Schedule\Schedule;
use Pontifex\Schedule\ScheduleStore;
use Pontifex\WordPress\WordPressContext;

/**
 * Renders the Pontifex Backup screen.
 *
 * The page itself is static: a "Create backup" control and a table of the
 * backups already on disk. The work — running the export, reporting progress,
 * streaming a download, deleting a file — happens over admin-ajax in
 * {@see BackupController}, so a minute-long backup never blocks the page. This
 * class only renders, capability-gated and with every value escaped at the point
 * of output; the per-backup download links carry a `pontifex_backup` nonce, and
 * the script that drives the button and the delete actions is enqueued by
 * {@see Menu} on this screen.
 *
 * The pure data method {@see self::backup_rows()} is unit-tested directly;
 * {@see self::render()} is exercised only as a capability gate and a smoke test,
 * the same split OverviewPage uses.
 */
final class BackupPage {

	/**
	 * The format a backup's UTC timestamp is encoded with in its name.
	 *
	 * Mirrors {@see BackupStore}'s naming contract (`pontifex-backup-<UTC>.wpmig`).
	 *
	 * @var string
	 */
	private const STAMP_FORMAT = 'Ymd\THis\Z';

	/**
	 * The WordPress context this page formats sizes through.
	 *
	 * @var WordPressContext
	 */
	private WordPressContext $context;

	/**
	 * The store listing the backups on disk.
	 *
	 * @var BackupStore
	 */
	private BackupStore $store;

	/**
	 * Construct the Backup page.
	 *
	 * @param WordPressContext $context Formats backup sizes.
	 * @param BackupStore      $store   Lists the backups on disk.
	 */
	public function __construct( WordPressContext $context, BackupStore $store ) {
		$this->context = $context;
		$this->store   = $store;
	}

	/**
	 * Render the Backup screen.
	 *
	 * The WordPress menu callback. Refuses without the managing capability, then
	 * prints the header, the create control, and the table of existing backups —
	 * every dynamic value escaped at the point of output.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access Pontifex.', 'pontifex' ) );
		}

		echo '<div class="wrap pontifex pontifex-admin">';

		printf(
			'<header class="pontifex-header"><p class="pontifex-eyebrow">%s</p><h1 class="pontifex-title">%s</h1><p class="pontifex-subtitle">%s</p></header>',
			esc_html__( 'Pontifex', 'pontifex' ),
			esc_html__( 'Backup', 'pontifex' ),
			esc_html__( 'Create a full backup of this site and download it.', 'pontifex' )
		);

		$this->render_create();
		$this->render_backups( $this->backup_rows() );
		$this->render_schedule( ( new ScheduleStore( $this->context ) )->load() );

		echo '</div>';
	}

	/**
	 * Build the rows for the backups table, newest first.
	 *
	 * The creation time is parsed from each filename (the store's naming contract);
	 * the size is read from disk and formatted. Pure given the store and context.
	 *
	 * @return array<int, array<string, string>> One row per backup, newest first.
	 */
	public function backup_rows(): array {
		$rows = array();
		foreach ( $this->store->backups() as $path ) {
			$filename = basename( $path );
			$rows[]   = array(
				'filename' => $filename,
				'when'     => $this->backup_when( $filename ),
				'size'     => $this->context->format_size( $this->file_size( $path ) ),
				'contains' => ArchiveScopeReader::label( $path ),
			);
		}
		return array_reverse( $rows );
	}

	/**
	 * Render the "Create a backup" control and its live regions.
	 *
	 * @return void
	 */
	private function render_create(): void {
		echo '<section class="pontifex-section">';
		printf( '<h2 class="pontifex-section-title">%s</h2>', esc_html__( 'Create a backup', 'pontifex' ) );
		printf(
			'<p class="pontifex-lead">%s</p>',
			esc_html__( 'A backup packs every file and the whole database into one .wpmig archive, written to a protected folder that is never exposed on the web. It can take a while on a large site; the progress is shown below, and the finished backup appears in the list to download.', 'pontifex' )
		);

		$this->render_scope_summary();

		printf( '<label class="pontifex-action-label" for="pontifex-backup-exclusions">%s</label>', esc_html__( 'Also leave out (one pattern per line)', 'pontifex' ) );
		printf(
			'<textarea id="pontifex-backup-exclusions" class="pontifex-action-input" rows="3" spellcheck="false" placeholder="%s"></textarea>',
			esc_attr__( "wp-content/uploads/large-video.mp4\nwp-content/backups/**\nwp_actionscheduler_logs", 'pontifex' )
		);
		printf(
			'<p class="pontifex-lead">%s</p>',
			esc_html__( 'A file pattern (exact path, glob like *.log, or a folder tree like path/**) leaves out files; a bare table name (exact or glob like wp_actionscheduler_*) leaves out that table. Leaving out a table makes its restore partial, so only exclude tables the destination can rebuild.', 'pontifex' )
		);

		printf(
			'<p><button type="button" class="pontifex-button" id="pontifex-create-backup">%s</button>'
			. '<button type="button" class="pontifex-button" id="pontifex-cancel-backup" hidden>%s</button></p>',
			esc_html__( 'Create backup', 'pontifex' ),
			esc_html__( 'Cancel backup', 'pontifex' )
		);
		printf(
			'<div class="pontifex-progress-track" id="pontifex-backup-track" role="progressbar" aria-label="%s" aria-describedby="pontifex-backup-progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" hidden><span class="pontifex-progress-fill" id="pontifex-backup-bar"></span></div>',
			esc_attr__( 'Backup progress', 'pontifex' )
		);
		echo '<p class="pontifex-progress" id="pontifex-backup-progress" aria-live="polite"></p>';
		echo '<p class="pontifex-timing" id="pontifex-backup-timing" aria-live="polite"></p>';
		echo '<p class="pontifex-notice" id="pontifex-backup-result" aria-live="polite"></p>';
		echo '</section>';
	}

	/**
	 * Render the effective scope and the always-applied default exclusions.
	 *
	 * The admin backup is always content-only (ADR 0008), and Pontifex always
	 * leaves out its own working directory and the ephemeral cache. Showing
	 * both before the operator acts satisfies the "defaults are visible" rule
	 * the CLI already honours; the admin surface did not, until now.
	 *
	 * @return void
	 */
	private function render_scope_summary(): void {
		printf(
			'<p class="pontifex-lead">%s</p>',
			esc_html__( 'This backup covers your content — everything in wp-content — and the whole database. (Whole-site backups, including WordPress core, are a command-line operation.)', 'pontifex' )
		);

		$defaults = ExclusionRules::default_v010()->patterns();
		if ( array() === $defaults ) {
			return;
		}

		printf( '<p class="pontifex-lead">%s</p>', esc_html__( 'Always left out:', 'pontifex' ) );
		echo '<ul class="pontifex-list">';
		foreach ( $defaults as $pattern ) {
			printf( '<li><code>%s</code></li>', esc_html( (string) $pattern ) );
		}
		echo '</ul>';
	}

	/**
	 * Render the backups table, or an empty state.
	 *
	 * @param array<int, array<string, string>> $rows The backup rows.
	 * @return void
	 */
	private function render_backups( array $rows ): void {
		echo '<section class="pontifex-section">';
		printf( '<h2 class="pontifex-section-title">%s</h2>', esc_html__( 'Your backups', 'pontifex' ) );

		if ( array() === $rows ) {
			printf( '<p class="pontifex-empty">%s</p>', esc_html__( 'No backups yet. Create one above and it will appear here to download.', 'pontifex' ) );
			echo '</section>';
			return;
		}

		$nonce = wp_create_nonce( BackupController::NONCE_ACTION );

		echo '<table class="pontifex-table"><thead><tr>';
		printf(
			'<th>%s</th><th>%s</th><th>%s</th><th>%s</th><th>%s</th>',
			esc_html__( 'Backup', 'pontifex' ),
			esc_html__( 'Created', 'pontifex' ),
			esc_html__( 'Size', 'pontifex' ),
			esc_html__( 'Contains', 'pontifex' ),
			esc_html__( 'Actions', 'pontifex' )
		);
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$download_url = add_query_arg(
				array(
					'action'   => 'pontifex_download_backup',
					'file'     => $row['filename'],
					'_wpnonce' => $nonce,
				),
				admin_url( 'admin-ajax.php' )
			);
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td>'
				. '<td class="pontifex-actions"><a class="pontifex-link" href="%s">%s</a>'
				. ' <button type="button" class="pontifex-delete-backup" data-file="%s">%s</button></td></tr>',
				esc_html( $row['filename'] ),
				esc_html( $row['when'] ),
				esc_html( $row['size'] ),
				esc_html( $row['contains'] ),
				esc_url( $download_url ),
				esc_html__( 'Download', 'pontifex' ),
				esc_attr( $row['filename'] ),
				esc_html__( 'Delete', 'pontifex' )
			);
		}
		echo '</tbody></table>';
		echo '</section>';
	}

	/**
	 * Render the "Scheduled backups" section: the periodic-backup settings form.
	 *
	 * A static form pre-filled from the stored schedule; the save happens over
	 * admin-ajax in {@see BackupController::save_schedule()}, driven by the
	 * screen's script. The hour is presented and interpreted in UTC — the clock
	 * WP-Cron timestamps use — and the copy says so rather than implying site
	 * time. The controls reuse the action-form vocabulary the Restore screen
	 * established (label, input, toggle), so the design language stays one
	 * system across screens.
	 *
	 * @param Schedule $schedule The stored schedule the form starts from.
	 * @return void
	 */
	private function render_schedule( Schedule $schedule ): void {
		echo '<section class="pontifex-section">';
		printf( '<h2 class="pontifex-section-title">%s</h2>', esc_html__( 'Scheduled backups', 'pontifex' ) );
		printf(
			'<p class="pontifex-lead">%s</p>',
			esc_html__( 'Back up this site automatically on a schedule. Scheduled backups appear in the list above, and older ones are pruned so only the newest are kept. The time is in UTC, not your site\'s display timezone.', 'pontifex' )
		);

		$this->render_schedule_status( $schedule );

		printf(
			'<label class="pontifex-action-toggle" for="pontifex-schedule-enabled"><input type="checkbox" id="pontifex-schedule-enabled"%s> %s</label>',
			$schedule->is_enabled() ? ' checked' : '',
			esc_html__( 'Back up automatically', 'pontifex' )
		);

		printf( '<label class="pontifex-action-label" for="pontifex-schedule-frequency">%s</label>', esc_html__( 'Frequency', 'pontifex' ) );
		echo '<select id="pontifex-schedule-frequency" class="pontifex-action-input">';
		foreach ( array(
			Schedule::FREQUENCY_DAILY  => __( 'Daily', 'pontifex' ),
			Schedule::FREQUENCY_WEEKLY => __( 'Weekly', 'pontifex' ),
		) as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				$schedule->frequency() === $value ? ' selected' : '',
				esc_html( $label )
			);
		}
		echo '</select>';

		printf( '<label class="pontifex-action-label" for="pontifex-schedule-hour">%s</label>', esc_html__( 'Time (UTC)', 'pontifex' ) );
		echo '<select id="pontifex-schedule-hour" class="pontifex-action-input">';
		for ( $hour = 0; $hour < 24; $hour++ ) {
			printf(
				'<option value="%d"%s>%s</option>',
				(int) $hour,
				$schedule->hour() === $hour ? ' selected' : '',
				esc_html( sprintf( '%02d:00', $hour ) )
			);
		}
		echo '</select>';

		printf( '<label class="pontifex-action-label" for="pontifex-schedule-retention">%s</label>', esc_html__( 'Backups to keep', 'pontifex' ) );
		printf(
			'<input type="number" id="pontifex-schedule-retention" class="pontifex-action-input" min="%d" step="1" value="%d">',
			(int) Schedule::MIN_RETENTION,
			(int) $schedule->retention()
		);

		printf( '<label class="pontifex-action-label" for="pontifex-schedule-exclusions">%s</label>', esc_html__( 'Also leave out (one pattern per line)', 'pontifex' ) );
		printf(
			'<textarea id="pontifex-schedule-exclusions" class="pontifex-action-input" rows="3" spellcheck="false">%s</textarea>',
			esc_textarea( implode( "\n", $schedule->exclusions() ) )
		);

		printf(
			'<p><button type="button" class="pontifex-button" id="pontifex-schedule-save">%s</button></p>',
			esc_html__( 'Save schedule', 'pontifex' )
		);
		echo '<p class="pontifex-notice" id="pontifex-schedule-result" aria-live="polite"></p>';
		echo '</section>';
	}

	/**
	 * Render the live status of the saved schedule: next run, or a health warning.
	 *
	 * This describes the schedule WordPress will actually act on — the stored
	 * settings and the real pending cron event — not the (possibly unsaved) form
	 * below. It gives the admin-only operator the liveness check the CLI `show`
	 * already provides: a schedule switched on whose cron event has been cleared
	 * behind its back (a cron-cleaning plugin, a hand-edit) is a silently dead
	 * schedule, and this is where that is caught. A disabled schedule shows
	 * nothing here — the toggle already states it is off. Times are UTC, the
	 * clock WP-Cron uses and the rest of the section presents.
	 *
	 * @param Schedule $schedule The stored schedule.
	 * @return void
	 */
	private function render_schedule_status( Schedule $schedule ): void {
		if ( ! $schedule->is_enabled() ) {
			return;
		}

		$pending = wp_next_scheduled( ScheduleStore::CRON_HOOK );
		if ( false === $pending ) {
			printf(
				'<p class="pontifex-notice pontifex-notice-warning">%s</p>',
				esc_html__( 'This schedule is switched on, but WordPress has no pending event for it — the automatic backup will not run until you save the schedule again. A plugin that clears scheduled events, or a manual change, can cause this.', 'pontifex' )
			);
			return;
		}

		printf(
			'<p class="pontifex-lead">%s</p>',
			sprintf(
				/* translators: %s: the next scheduled run time in UTC, e.g. "2026-07-14 03:00 UTC" */
				esc_html__( 'Next automatic backup: %s.', 'pontifex' ),
				esc_html( gmdate( 'Y-m-d H:i', (int) $pending ) . ' UTC' )
			)
		);
	}

	/**
	 * Format a backup's creation time from its filename.
	 *
	 * @param string $filename The backup basename.
	 * @return string A readable creation time in the site's timezone, or '(unknown)' if the name does not match.
	 */
	private function backup_when( string $filename ): string {
		if ( 1 === preg_match( '/pontifex-backup-(\d{8}T\d{6}Z)\./', $filename, $matches ) ) {
			$parsed = DateTimeImmutable::createFromFormat( self::STAMP_FORMAT, $matches[1], new DateTimeZone( 'UTC' ) );
			if ( false !== $parsed ) {
				// Render in the site's configured timezone (Settings -> General), not UTC,
				// so operators see local time; the format reads "08:45 on 25-06-2026".
				$formatted = wp_date( 'H:i \o\n d-m-Y', $parsed->getTimestamp() );
				if ( false !== $formatted ) {
					return $formatted;
				}
			}
		}
		return '(unknown)';
	}

	/**
	 * Read a backup's size in bytes, tolerating a stat failure.
	 *
	 * @param string $path Absolute path to the backup.
	 * @return int The size in bytes, or 0 if it cannot be read.
	 */
	private function file_size( string $path ): int {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filesize -- Reading the size of a plugin-owned backup for display; WP_Filesystem is unavailable in CLI/test contexts.
		$size = filesize( $path );
		return false !== $size ? $size : 0;
	}
}
