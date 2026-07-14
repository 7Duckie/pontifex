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
 * The page is static: a short explanation, a list of the backups on disk to
 * choose from, and a Verify button with a shared progress bar and result line.
 * The operator selects a backup (clicking a row, which is then outlined — there
 * are no radios) and clicks Verify; the work — reading the archive and checking
 * every hash — happens over admin-ajax in {@see VerifyController}, so a long
 * check never blocks the page. This class only renders, capability-gated and
 * with every value escaped at output; the script that drives the
 * select-then-verify flow is enqueued by {@see Menu} on this screen, and carries
 * the `pontifex_verify` nonce.
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
	 * The published archive format specification, linked from a sound verify's proof panel.
	 *
	 * @var string
	 */
	public const FORMAT_SPEC_URL = 'https://github.com/7Duckie/pontifex/blob/main/docs/archive-format.md';

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
	 * prints the header, the explanation, the list of backups, and the Verify
	 * control — every dynamic value escaped at the point of output.
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
			esc_html__( 'Verify', 'pontifex' ),
			esc_html__( 'Check a backup\'s integrity without restoring it.', 'pontifex' )
		);

		$this->render_explanation();
		$this->render_backups( $this->backup_rows() );
		$this->render_action();

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
				'contains' => ArchiveScopeReader::label( $path ),
				'when'     => $this->backup_when( $filename ),
				'size'     => $this->context->format_size( $this->file_size( $path ) ),
			);
		}
		return array_reverse( $rows );
	}

	/**
	 * Render the explanation of what verifying does.
	 *
	 * @return void
	 */
	private function render_explanation(): void {
		echo '<section class="pontifex-section">';
		printf( '<h2 class="pontifex-section-title">%s</h2>', esc_html__( 'Verify a backup', 'pontifex' ) );
		printf(
			'<p class="pontifex-lead">%s</p>',
			esc_html__( 'Verifying re-reads a backup and checks every hash, without changing your site or its database. Choose a backup below, then verify it; its result appears at the bottom. Encrypted backups can only be verified with the WP-CLI command.', 'pontifex' )
		);
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

		printf(
			'<div class="pontifex-restore-list" role="radiogroup" aria-label="%s">',
			esc_attr__( 'Choose a backup to verify', 'pontifex' )
		);

		printf(
			'<div class="pontifex-restore-head"><span>%s</span><span>%s</span><span>%s</span><span>%s</span></div>',
			esc_html__( 'Backup', 'pontifex' ),
			esc_html__( 'Created', 'pontifex' ),
			esc_html__( 'Size', 'pontifex' ),
			esc_html__( 'Contains', 'pontifex' )
		);

		// Roving tabindex (ARIA radio-group pattern): with nothing selected yet, the
		// first row is the group's single Tab stop; the script moves the stop with
		// the selection thereafter.
		$row_index = 0;
		foreach ( $rows as $row ) {
			printf(
				'<button type="button" class="pontifex-restore-row" role="radio" aria-checked="false" tabindex="%1$s" data-file="%2$s">'
				. '<span class="pontifex-restore-name">%3$s</span>'
				. '<span class="pontifex-restore-when">%5$s</span>'
				. '<span class="pontifex-restore-size">%6$s</span>'
				. '<span class="pontifex-restore-contains">%4$s</span>'
				. '</button>',
				0 === $row_index ? '0' : '-1',
				esc_attr( $row['filename'] ),
				esc_html( $row['filename'] ),
				esc_html( $row['contains'] ),
				esc_html( $row['when'] ),
				esc_html( $row['size'] )
			);
			++$row_index;
		}

		echo '</div>';
		echo '</section>';
	}

	/**
	 * Render the Verify button and the shared progress and result regions.
	 *
	 * The button stays disabled until a backup is selected; the script enforces that
	 * and drives the verify over admin-ajax, the same select-then-act flow as Restore.
	 *
	 * @return void
	 */
	private function render_action(): void {
		echo '<section class="pontifex-section">';
		printf(
			'<button type="button" class="pontifex-button" id="pontifex-verify-run" disabled>%s</button>',
			esc_html__( 'Verify', 'pontifex' )
		);
		printf(
			'<div class="pontifex-progress-track" id="pontifex-verify-track" role="progressbar" aria-label="%s" aria-describedby="pontifex-verify-progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" hidden><span class="pontifex-progress-fill" id="pontifex-verify-bar"></span></div>',
			esc_attr__( 'Verification progress', 'pontifex' )
		);
		echo '<p class="pontifex-progress" id="pontifex-verify-progress" aria-live="polite"></p>';
		echo '<p class="pontifex-timing" id="pontifex-verify-timing" aria-live="polite"></p>';
		echo '<p class="pontifex-notice" id="pontifex-verify-result" aria-live="polite"></p>';
		echo '<div class="pontifex-proof" id="pontifex-verify-proof" aria-live="polite" hidden></div>';
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
