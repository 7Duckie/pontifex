<?php
/**
 * Pontifex admin Restore page — restore this site from a backup, or roll back the last restore.
 *
 * @package Pontifex\Admin
 */

declare(strict_types=1);

namespace Pontifex\Admin;

use DateTimeImmutable;
use DateTimeZone;
use Pontifex\Rollback\RollbackStoreInterface;
use Pontifex\WordPress\WordPressContext;

/**
 * Renders the Pontifex Restore screen.
 *
 * The page is static: a short explanation, a list of the backups on disk to
 * choose from, then a typed-action box with a shared progress bar and result
 * line. The work — verifying, taking the safety archive, and writing the restore
 * over the live site — happens over admin-ajax in {@see RestoreController}, so a
 * long restore never blocks the page. This class only renders, capability-gated
 * and with every value escaped at output.
 *
 * Interaction (the deliberately friction-ful model for a destructive screen):
 * the operator selects a backup, then **types** the action — `restore` to
 * overwrite this site with the selected backup, or `rollback` to undo the last
 * restore. The typed word is both the choice and the confirmation, so a
 * destructive action cannot fire from a stray click; the Run button stays
 * disabled until the typed word is a valid action (and, for `restore`, a backup
 * is selected). A backup is chosen by clicking its row — the selected row is
 * outlined in the accent colour; there are deliberately no radios or checkboxes.
 * The script enforcing all this, and the `pontifex_restore` nonce, are enqueued
 * by {@see Menu} on this screen.
 *
 * The list helpers ({@see self::backup_rows()}, {@see self::backup_when()},
 * {@see self::file_size()}) mirror {@see BackupPage}'s and {@see VerifyPage}'s;
 * folding the shared backup-list rendering into one place is a candidate later tidy.
 */
final class RestorePage {

	/**
	 * The format a backup's UTC timestamp is encoded with in its name.
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
	 * The rollback store, consulted to show whether an undo is available.
	 *
	 * @var RollbackStoreInterface
	 */
	private RollbackStoreInterface $rollback_store;

	/**
	 * Construct the Restore page.
	 *
	 * @param WordPressContext       $context        Formats backup sizes.
	 * @param BackupStore            $store          Lists the backups on disk.
	 * @param RollbackStoreInterface $rollback_store Reports whether a safety archive exists to roll back to.
	 */
	public function __construct( WordPressContext $context, BackupStore $store, RollbackStoreInterface $rollback_store ) {
		$this->context        = $context;
		$this->store          = $store;
		$this->rollback_store = $rollback_store;
	}

	/**
	 * Render the Restore screen.
	 *
	 * The WordPress menu callback. Refuses without the managing capability, then
	 * prints the header, the explanation, the list of backups, and the typed-action
	 * region — every dynamic value escaped at the point of output.
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
			esc_html__( 'Restore', 'pontifex' ),
			esc_html__( 'Restore your site from a backup, or roll back the last restore.', 'pontifex' )
		);

		$this->render_explanation();
		$this->render_backups( $this->backup_rows() );
		$this->render_rollback();
		$this->render_action();

		echo '</div>';
	}

	/**
	 * Build the rows for the backups list, newest first.
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
			);
		}
		return array_reverse( $rows );
	}

	/**
	 * Render the explanation of what restore and rollback do.
	 *
	 * @return void
	 */
	private function render_explanation(): void {
		echo '<section class="pontifex-section">';
		printf( '<h2 class="pontifex-section-title">%s</h2>', esc_html__( 'Restore or roll back', 'pontifex' ) );

		printf(
			'<p class="pontifex-lead pontifex-restore-lead">%s</p>',
			wp_kses(
				sprintf(
					/* translators: %1$s: the emphasised command word "Restore"; %2$s: the emphasised command word "Rollback". */
					esc_html__( '%1$s replaces this site\'s content with the backup you select. %2$s returns the site to the safety archive Pontifex takes before your most recent restore. Choose a backup below, then type the action at the bottom to confirm and run it.', 'pontifex' ),
					'<span class="pontifex-emph">' . esc_html__( 'Restore', 'pontifex' ) . '</span>',
					'<span class="pontifex-emph">' . esc_html__( 'Rollback', 'pontifex' ) . '</span>'
				),
				array( 'span' => array( 'class' => array() ) )
			)
		);

		echo '</section>';
	}

	/**
	 * Render the backups list as a click-to-select chooser, or an empty state.
	 *
	 * Each row is a button carrying the backup's filename in data-file; clicking it
	 * selects the backup (the row is outlined in the accent colour). The group is an
	 * ARIA radiogroup so the keyboard and screen readers can choose a backup, with
	 * no visible radio or checkbox.
	 *
	 * @param array<int, array<string, string>> $rows The backup rows.
	 * @return void
	 */
	private function render_backups( array $rows ): void {
		echo '<section class="pontifex-section">';
		printf( '<h2 class="pontifex-section-title">%s</h2>', esc_html__( 'Your backups', 'pontifex' ) );

		if ( array() === $rows ) {
			printf( '<p class="pontifex-empty">%s</p>', esc_html__( 'No backups yet. Create one on the Backup screen and it will appear here to restore.', 'pontifex' ) );
			echo '</section>';
			return;
		}

		printf(
			'<div class="pontifex-restore-list" role="radiogroup" aria-label="%s">',
			esc_attr__( 'Choose a backup to restore', 'pontifex' )
		);

		printf(
			'<div class="pontifex-restore-head"><span>%s</span><span>%s</span><span>%s</span></div>',
			esc_html__( 'Backup', 'pontifex' ),
			esc_html__( 'Created', 'pontifex' ),
			esc_html__( 'Size', 'pontifex' )
		);

		foreach ( $rows as $row ) {
			printf(
				'<button type="button" class="pontifex-restore-row" role="radio" aria-checked="false" data-file="%1$s">'
				. '<span class="pontifex-restore-name">%2$s</span>'
				. '<span class="pontifex-restore-when">%3$s</span>'
				. '<span class="pontifex-restore-size">%4$s</span>'
				. '</button>',
				esc_attr( $row['filename'] ),
				esc_html( $row['filename'] ),
				esc_html( $row['when'] ),
				esc_html( $row['size'] )
			);
		}

		echo '</div>';
		echo '</section>';
	}

	/**
	 * Render the rollback hint, the typed-action box, and the shared progress regions.
	 *
	 * @return void
	 */
	private function render_action(): void {
		echo '<section class="pontifex-section pontifex-restore-action">';

		printf(
			'<label class="pontifex-action-label" for="pontifex-restore-action">%s</label>',
			esc_html__( 'Type an action', 'pontifex' )
		);
		printf(
			'<input type="text" id="pontifex-restore-action" class="pontifex-action-input" autocomplete="off" spellcheck="false" placeholder="%s">',
			esc_attr__( 'restore or rollback', 'pontifex' )
		);
		printf(
			'<button type="button" class="pontifex-button" id="pontifex-restore-run" disabled>%s</button>',
			esc_html__( 'Run', 'pontifex' )
		);

		echo '<div class="pontifex-progress-track" id="pontifex-restore-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" hidden><span class="pontifex-progress-fill" id="pontifex-restore-bar"></span></div>';
		echo '<p class="pontifex-progress" id="pontifex-restore-progress" aria-live="polite"></p>';
		echo '<p class="pontifex-timing" id="pontifex-restore-timing" aria-live="polite"></p>';
		echo '<p class="pontifex-notice" id="pontifex-restore-result" aria-live="polite"></p>';

		echo '</section>';
	}

	/**
	 * Render whether a safety archive is available to roll back to.
	 *
	 * @return void
	 */
	private function render_rollback(): void {
		echo '<section class="pontifex-section">';
		printf( '<h2 class="pontifex-section-title">%s</h2>', esc_html__( 'Roll back', 'pontifex' ) );

		$archive = $this->rollback_store->most_recent();
		if ( null === $archive ) {
			printf(
				'<p class="pontifex-empty">%s</p>',
				esc_html__( 'No safety archive is available to roll back to. Pontifex writes one automatically before each restore, so a restore can always be undone.', 'pontifex' )
			);
			echo '</section>';
			return;
		}

		$filename = basename( $archive );
		$when     = $this->archive_when( $filename );

		echo '<table class="pontifex-table"><thead><tr>';
		printf( '<th>%s</th><th>%s</th>', esc_html__( 'Safety archive', 'pontifex' ), esc_html__( 'Taken', 'pontifex' ) );
		echo '</tr></thead><tbody>';
		printf( '<tr><td>%s</td><td>%s</td></tr>', esc_html( $filename ), esc_html( $when ) );
		echo '</tbody></table>';

		printf(
			'<p class="pontifex-hint">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: the date and time the safety archive was taken, e.g. "08:45 on 25-06-2026" */
					__( 'The safety archive above is the one available to roll back to — it was taken at %s, just before your most recent restore. To undo that restore, type rollback below. Confirm that date is the point you mean to return to; if you are unsure, back up your site and download that backup before rolling back.', 'pontifex' ),
					$when
				)
			)
		);

		echo '</section>';
	}

	/**
	 * Format a safety archive's creation time from its filename.
	 *
	 * @param string $filename The safety archive basename (pre-import-rollback-<UTC>.wpmig).
	 * @return string A readable time in the site's timezone, or '(unknown)' if the name does not match.
	 */
	private function archive_when( string $filename ): string {
		if ( 1 === preg_match( '/pre-import-rollback-(\d{8}T\d{6}Z)\./', $filename, $matches ) ) {
			$parsed = DateTimeImmutable::createFromFormat( self::STAMP_FORMAT, $matches[1], new DateTimeZone( 'UTC' ) );
			if ( false !== $parsed ) {
				$formatted = wp_date( 'H:i \o\n d-m-Y', $parsed->getTimestamp() );
				if ( false !== $formatted ) {
					return $formatted;
				}
			}
		}
		return '(unknown)';
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
