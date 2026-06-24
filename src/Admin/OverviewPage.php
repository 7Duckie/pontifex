<?php
/**
 * Pontifex admin Overview page — a read-only status summary.
 *
 * @package Pontifex\Admin
 */

declare(strict_types=1);

namespace Pontifex\Admin;

use DateTimeImmutable;
use DateTimeZone;
use Pontifex\Cli\TransferHistory;
use Pontifex\Rollback\RollbackStoreInterface;
use Pontifex\WordPress\WordPressContext;

/**
 * Renders the Pontifex Overview screen.
 *
 * The read-only summary an operator sees first: which plugin version is running,
 * the local transfer counters (how many backups/restores have run and whether
 * they worked), the most recent transfers, and the pre-import safety archives on
 * disk. It performs no destructive action and writes nothing — it only reads the
 * state the CLI already records (the wp_options counters and {@see TransferHistory})
 * and the rollback directory ({@see RollbackStoreInterface}).
 *
 * The data-gathering methods ({@see self::stats_rows()}, {@see self::history_rows()},
 * {@see self::archive_rows()}) are pure given a context and store, so they are
 * unit-tested directly; {@see self::render()} adds the capability gate and the
 * escaped HTML. Following the design language, the markup is plain and
 * typographic — the visual hierarchy lives in the stylesheet, not in the tags.
 */
final class OverviewPage {

	/**
	 * The wp_options key holding the export counters (mirrors ExportCommand).
	 *
	 * @var string
	 */
	private const EXPORT_STATS_OPTION = 'pontifex_export_stats';

	/**
	 * The wp_options key holding the import counters (mirrors ImportCommand).
	 *
	 * @var string
	 */
	private const IMPORT_STATS_OPTION = 'pontifex_import_stats';

	/**
	 * The format a safety archive's UTC timestamp is encoded with in its name.
	 *
	 * Mirrors {@see \Pontifex\Rollback\RollbackStore}'s naming contract
	 * (`pre-import-rollback-<UTC>.wpmig`).
	 *
	 * @var string
	 */
	private const ARCHIVE_STAMP_FORMAT = 'Ymd\THis\Z';

	/**
	 * The WordPress context this page reads option and formatting data through.
	 *
	 * @var WordPressContext
	 */
	private WordPressContext $context;

	/**
	 * The store listing the pre-import safety archives on disk.
	 *
	 * @var RollbackStoreInterface
	 */
	private RollbackStoreInterface $rollback_store;

	/**
	 * The running plugin version, shown in the header.
	 *
	 * @var string
	 */
	private string $plugin_version;

	/**
	 * Construct the Overview page.
	 *
	 * @param WordPressContext       $context        Reads counters and formats sizes.
	 * @param RollbackStoreInterface $rollback_store Lists the safety archives on disk.
	 * @param string                 $plugin_version The running plugin version.
	 */
	public function __construct( WordPressContext $context, RollbackStoreInterface $rollback_store, string $plugin_version ) {
		$this->context        = $context;
		$this->rollback_store = $rollback_store;
		$this->plugin_version = $plugin_version;
	}

	/**
	 * Render the Overview screen.
	 *
	 * The WordPress menu callback. Refuses without the managing capability, then
	 * prints the header, the transfer-activity summary, the recent transfers, and
	 * the safety archives — every dynamic value escaped at the point of output.
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
			esc_html__( 'Pontifex', 'pontifex' ),
			esc_html(
				sprintf(
					/* translators: %s: plugin version */
					__( 'Backup and migration · version %s', 'pontifex' ),
					$this->plugin_version
				)
			)
		);

		echo '<section class="pontifex-section">';
		printf( '<h2 class="pontifex-section-title">%s</h2>', esc_html__( 'Transfer activity', 'pontifex' ) );
		$this->render_activity( $this->stats_rows() );
		echo '</section>';

		echo '<section class="pontifex-section">';
		printf( '<h2 class="pontifex-section-title">%s</h2>', esc_html__( 'Recent transfers', 'pontifex' ) );
		$this->render_recent( $this->history_rows() );
		echo '</section>';

		echo '<section class="pontifex-section">';
		printf( '<h2 class="pontifex-section-title">%s</h2>', esc_html__( 'Safety archives', 'pontifex' ) );
		$this->render_archives( $this->archive_rows() );
		echo '</section>';

		echo '</div>';
	}

	/**
	 * Build the two transfer-activity rows (export and import).
	 *
	 * @return array<int, array<string, int|string>> One row per operation.
	 */
	public function stats_rows(): array {
		return array(
			$this->operation_row( __( 'Backups (export)', 'pontifex' ), self::EXPORT_STATS_OPTION, 'bytes_exported' ),
			$this->operation_row( __( 'Restores (import)', 'pontifex' ), self::IMPORT_STATS_OPTION, 'bytes_imported' ),
		);
	}

	/**
	 * Build the recent-transfers rows, newest first.
	 *
	 * @return array<int, array<string, string>> One row per recorded transfer.
	 */
	public function history_rows(): array {
		$rows = array();
		foreach ( array_reverse( TransferHistory::recent( $this->context ) ) as $entry ) {
			$rows[] = array(
				'when'      => $this->entry_string( $entry, 'at' ),
				'operation' => $this->entry_string( $entry, 'operation' ),
				'outcome'   => $this->entry_string( $entry, 'outcome' ),
				'size'      => $this->context->format_size( $this->counter_int( $entry, 'bytes' ) ),
			);
		}
		return $rows;
	}

	/**
	 * Build the safety-archive rows, newest first.
	 *
	 * Reads only the filenames the store reports; the creation time is parsed from
	 * the name (the store's naming contract) rather than the filesystem, so the
	 * method is pure and touches no disk beyond the directory listing the store
	 * already performed.
	 *
	 * @return array<int, array<string, string>> One row per safety archive.
	 */
	public function archive_rows(): array {
		$rows = array();
		foreach ( $this->rollback_store->archives() as $path ) {
			$filename = basename( $path );
			$rows[]   = array(
				'filename' => $filename,
				'when'     => $this->archive_when( $filename ),
			);
		}
		return array_reverse( $rows );
	}

	/**
	 * Build one transfer-activity row from a counters option.
	 *
	 * @param string $label     Human label for the operation.
	 * @param string $option    The wp_options key holding the counters.
	 * @param string $bytes_key The counter key holding the byte total.
	 * @return array<string, int|string> The row.
	 */
	private function operation_row( string $label, string $option, string $bytes_key ): array {
		$stats = $this->read_counters( $option );
		return array(
			'operation' => $label,
			'attempted' => $this->counter_int( $stats, 'attempted' ),
			'succeeded' => $this->counter_int( $stats, 'succeeded' ),
			'failed'    => $this->counter_int( $stats, 'failed' ),
			'size'      => $this->context->format_size( $this->counter_int( $stats, $bytes_key ) ),
		);
	}

	/**
	 * Render the transfer-activity rows as typographic figures.
	 *
	 * @param array<int, array<string, int|string>> $rows The activity rows.
	 * @return void
	 */
	private function render_activity( array $rows ): void {
		echo '<div class="pontifex-stat-grid">';
		foreach ( $rows as $row ) {
			printf(
				'<div class="pontifex-stat"><span class="pontifex-stat-operation">%s</span>'
				. '<span class="pontifex-stat-line"><span class="pontifex-figure">%s</span> %s &middot; '
				. '<span class="pontifex-figure">%s</span> %s &middot; '
				. '<span class="pontifex-figure">%s</span> %s</span>'
				. '<span class="pontifex-stat-size">%s</span></div>',
				esc_html( (string) $row['operation'] ),
				esc_html( (string) $row['succeeded'] ),
				esc_html__( 'succeeded', 'pontifex' ),
				esc_html( (string) $row['failed'] ),
				esc_html__( 'failed', 'pontifex' ),
				esc_html( (string) $row['attempted'] ),
				esc_html__( 'attempted', 'pontifex' ),
				esc_html(
					sprintf(
						/* translators: %s: human-readable byte total moved */
						__( '%s moved', 'pontifex' ),
						(string) $row['size']
					)
				)
			);
		}
		echo '</div>';
	}

	/**
	 * Render the recent-transfers table, or an empty state.
	 *
	 * @param array<int, array<string, string>> $rows The recent-transfer rows.
	 * @return void
	 */
	private function render_recent( array $rows ): void {
		if ( array() === $rows ) {
			printf( '<p class="pontifex-empty">%s</p>', esc_html__( 'No transfers recorded yet. Run a backup to start the log.', 'pontifex' ) );
			return;
		}

		echo '<table class="pontifex-table"><thead><tr>';
		printf(
			'<th>%s</th><th>%s</th><th>%s</th><th>%s</th>',
			esc_html__( 'When', 'pontifex' ),
			esc_html__( 'Operation', 'pontifex' ),
			esc_html__( 'Outcome', 'pontifex' ),
			esc_html__( 'Size', 'pontifex' )
		);
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( $row['when'] ),
				esc_html( $row['operation'] ),
				esc_html( $row['outcome'] ),
				esc_html( $row['size'] )
			);
		}
		echo '</tbody></table>';
	}

	/**
	 * Render the safety-archives table, or an empty state.
	 *
	 * @param array<int, array<string, string>> $rows The safety-archive rows.
	 * @return void
	 */
	private function render_archives( array $rows ): void {
		if ( array() === $rows ) {
			printf( '<p class="pontifex-empty">%s</p>', esc_html__( 'No safety archives yet. One is taken automatically before each restore, so a restore can be rolled back.', 'pontifex' ) );
			return;
		}

		echo '<table class="pontifex-table"><thead><tr>';
		printf( '<th>%s</th><th>%s</th>', esc_html__( 'Archive', 'pontifex' ), esc_html__( 'Created', 'pontifex' ) );
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			printf(
				'<tr><td>%s</td><td>%s</td></tr>',
				esc_html( $row['filename'] ),
				esc_html( $row['when'] )
			);
		}
		echo '</tbody></table>';
	}

	/**
	 * Read a counters option as an array, tolerating a missing or corrupt value.
	 *
	 * @param string $option The wp_options key.
	 * @return array<array-key, mixed> The stored counters, or an empty array.
	 */
	private function read_counters( string $option ): array {
		$value = $this->context->option_value( $option, array() );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Read one counter as a non-negative-safe integer, defaulting to zero.
	 *
	 * @param array<array-key, mixed> $values The array to read from.
	 * @param string                  $key    The counter key.
	 * @return int The value as an int, or 0 when absent or non-numeric.
	 */
	private function counter_int( array $values, string $key ): int {
		return isset( $values[ $key ] ) && is_numeric( $values[ $key ] ) ? (int) $values[ $key ] : 0;
	}

	/**
	 * Read one history-entry field as a string, defaulting to a placeholder.
	 *
	 * @param array<array-key, mixed> $entry The history entry.
	 * @param string                  $key   The field name.
	 * @return string The field as a string, or '(unknown)' when absent or non-string.
	 */
	private function entry_string( array $entry, string $key ): string {
		return isset( $entry[ $key ] ) && is_string( $entry[ $key ] ) ? $entry[ $key ] : '(unknown)';
	}

	/**
	 * Format a safety archive's creation time from its filename.
	 *
	 * @param string $filename The archive basename.
	 * @return string A readable UTC datetime, or '(unknown)' if the name does not match.
	 */
	private function archive_when( string $filename ): string {
		if ( 1 === preg_match( '/pre-import-rollback-(\d{8}T\d{6}Z)\./', $filename, $matches ) ) {
			$parsed = DateTimeImmutable::createFromFormat( self::ARCHIVE_STAMP_FORMAT, $matches[1], new DateTimeZone( 'UTC' ) );
			if ( false !== $parsed ) {
				return $parsed->format( 'Y-m-d H:i' ) . ' UTC';
			}
		}
		return '(unknown)';
	}
}
