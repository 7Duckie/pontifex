<?php
/**
 * Pontifex admin Verify page — check a backup's integrity without restoring it.
 *
 * @package Pontifex\Admin
 */

declare(strict_types=1);

namespace Pontifex\Admin;

use DateTimeImmutable;
use DateTimeZone;
use Pontifex\WordPress\WordPressContext;

/**
 * Renders the Pontifex Verify screen.
 *
 * The page is static: a short explanation, a shared progress bar and result
 * line, and a table of the backups on disk, each with a "Verify" action. The
 * work — reading the archive and checking every hash — happens over admin-ajax
 * in {@see VerifyController}, so a long check never blocks the page. This class
 * only renders, capability-gated and with every value escaped at output; the
 * script that drives the Verify actions is enqueued by {@see Menu} on this
 * screen, and carries the `pontifex_verify` nonce.
 *
 * The list helpers ({@see self::backup_rows()}, {@see self::backup_when()},
 * {@see self::file_size()}) mirror {@see BackupPage}'s; folding the shared
 * backup-list rendering into one place is a candidate later tidy.
 */
final class VerifyPage {

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
	 * Construct the Verify page.
	 *
	 * @param WordPressContext $context Formats backup sizes.
	 * @param BackupStore      $store   Lists the backups on disk.
	 */
	public function __construct( WordPressContext $context, BackupStore $store ) {
		$this->context = $context;
		$this->store   = $store;
	}

	/**
	 * Render the Verify screen.
	 *
	 * The WordPress menu callback. Refuses without the managing capability, then
	 * prints the header, the verify control region, and the table of backups —
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
			'<header class="pontifex-header"><h1 class="pontifex-title">%s</h1><p class="pontifex-subtitle">%s</p></header>',
			esc_html__( 'Pontifex — Verify', 'pontifex' ),
			esc_html__( 'Check a backup\'s integrity without restoring it.', 'pontifex' )
		);

		$this->render_intro();
		$this->render_backups( $this->backup_rows() );

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
			);
		}
		return array_reverse( $rows );
	}

	/**
	 * Render the explanation and the shared progress and result regions.
	 *
	 * @return void
	 */
	private function render_intro(): void {
		echo '<section class="pontifex-section">';
		printf( '<h2 class="pontifex-section-title">%s</h2>', esc_html__( 'Verify a backup', 'pontifex' ) );
		printf(
			'<p class="pontifex-lead">%s</p>',
			esc_html__( 'Verifying re-reads a backup and checks every hash, without changing your site or its database. Pick a backup below; its result appears here. Encrypted backups can only be verified with the WP-CLI command.', 'pontifex' )
		);
		echo '<div class="pontifex-progress-track" id="pontifex-verify-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" hidden><span class="pontifex-progress-fill" id="pontifex-verify-bar"></span></div>';
		echo '<p class="pontifex-progress" id="pontifex-verify-progress" aria-live="polite"></p>';
		echo '<p class="pontifex-timing" id="pontifex-verify-timing" aria-live="polite"></p>';
		echo '<p class="pontifex-notice" id="pontifex-verify-result" aria-live="polite"></p>';
		echo '</section>';
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
			printf( '<p class="pontifex-empty">%s</p>', esc_html__( 'No backups yet. Create one on the Backup screen and it will appear here to verify.', 'pontifex' ) );
			echo '</section>';
			return;
		}

		echo '<table class="pontifex-table"><thead><tr>';
		printf(
			'<th>%s</th><th>%s</th><th>%s</th><th>%s</th>',
			esc_html__( 'Backup', 'pontifex' ),
			esc_html__( 'Created', 'pontifex' ),
			esc_html__( 'Size', 'pontifex' ),
			esc_html__( 'Actions', 'pontifex' )
		);
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td>'
				. '<td class="pontifex-actions"><button type="button" class="pontifex-verify-backup" data-file="%s">%s</button></td></tr>',
				esc_html( $row['filename'] ),
				esc_html( $row['when'] ),
				esc_html( $row['size'] ),
				esc_attr( $row['filename'] ),
				esc_html__( 'Verify', 'pontifex' )
			);
		}
		echo '</tbody></table>';
		echo '</section>';
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
