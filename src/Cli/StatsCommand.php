<?php
/**
 * Pontifex Stats command — a local readout of transfer activity.
 *
 * @package Pontifex\Cli
 */

declare(strict_types=1);

namespace Pontifex\Cli;

use WP_CLI;
use WP_CLI\Formatter;
use Pontifex\WordPress\RealWordPressContext;
use Pontifex\WordPress\WordPressContext;

/**
 * `wp pontifex stats` — show this site's Pontifex transfer activity.
 *
 * A read-only readout of the export and import counters Pontifex keeps in
 * `wp_options` (how many transfers were attempted, succeeded, and failed, and
 * how many bytes moved). It answers the questions a user asks their backup
 * plugin — "have I run a backup, did the last one work, how big was it?" —
 * entirely locally: nothing is uploaded, and the data is the operator's to read
 * or delete.
 *
 * Output is one row per operation (export, import), rendered as a human table
 * by default; `--format=json` gives machine-readable output for pasting into a
 * bug report. No mutations, no network.
 *
 * ## OPTIONS
 *
 * [--format=<format>]
 * : Render format.
 * ---
 * default: table
 * options:
 *   - table
 *   - json
 *   - csv
 *   - yaml
 * ---
 *
 * ## EXAMPLES
 *
 *     wp pontifex stats
 *     wp pontifex stats --format=json
 *
 * @when after_wp_load
 */
final class StatsCommand {

	/**
	 * The wp_options key under which export counters are stored.
	 *
	 * Mirrors ExportCommand's STATS_OPTION; the option name is the stable
	 * contract between the two commands.
	 *
	 * @var string
	 */
	private const EXPORT_STATS_OPTION = 'pontifex_export_stats';

	/**
	 * The wp_options key under which import counters are stored.
	 *
	 * Mirrors ImportCommand's STATS_OPTION.
	 *
	 * @var string
	 */
	private const IMPORT_STATS_OPTION = 'pontifex_import_stats';

	/**
	 * The WordPressContext abstraction this command queries.
	 *
	 * Injected via the constructor so tests can substitute a mock that returns
	 * deterministic option values and size formatting. When null, a fresh
	 * RealWordPressContext is used.
	 *
	 * @var WordPressContext
	 */
	private WordPressContext $wordpress_context;

	/**
	 * Construct a StatsCommand instance.
	 *
	 * WP-CLI registers the command via its class name and does not pass
	 * constructor arguments, so the parameter is optional and defaults to the
	 * real implementation. Tests pass a mock explicitly.
	 *
	 * @param WordPressContext|null $wordpress_context Optional. Defaults to a fresh RealWordPressContext.
	 */
	public function __construct( ?WordPressContext $wordpress_context = null ) {
		$this->wordpress_context = $wordpress_context ?? new RealWordPressContext();
	}

	/**
	 * The WP-CLI command entry point.
	 *
	 * Reads the export and import counters, renders one row per operation, and
	 * prints a friendly note when nothing has been recorded yet (table view
	 * only, so machine formats stay clean).
	 *
	 * @param array<int, string>         $positional_args  Positional arguments. Unused for `stats`.
	 * @param array<string, string|bool> $associative_args Associative arguments; consumed by the formatter.
	 * @return void
	 */
	public function __invoke( array $positional_args, array $associative_args ): void {
		$export_stats = $this->read_stats( self::EXPORT_STATS_OPTION );
		$import_stats = $this->read_stats( self::IMPORT_STATS_OPTION );

		$rows           = $this->build_rows( $export_stats, $import_stats );
		$default_fields = array( 'operation', 'attempted', 'succeeded', 'failed', 'size' );

		$formatter = new Formatter( $associative_args, $default_fields );
		$formatter->display_items( $rows );

		if ( self::no_activity( $export_stats, $import_stats ) && ! self::is_machine_format( $associative_args ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'No transfers recorded yet. Run `wp pontifex export` or `wp pontifex import` to start the tally.' );
		}
	}

	/**
	 * Build the display rows: one per operation, in a uniform shape.
	 *
	 * Pure: given the same stored counters it returns the same rows, so it can
	 * be unit-tested without the WP-CLI formatter. The `size` column is the
	 * human-readable byte total; the raw counters are tolerated through
	 * counter_int, so a missing or corrupt option degrades to zeros.
	 *
	 * @param array<array-key, mixed> $export_stats The stored export counters.
	 * @param array<array-key, mixed> $import_stats The stored import counters.
	 * @return array<int, array<string, int|string>> One row per operation.
	 */
	private function build_rows( array $export_stats, array $import_stats ): array {
		return array(
			$this->operation_row( 'export', $export_stats, 'bytes_exported' ),
			$this->operation_row( 'import', $import_stats, 'bytes_imported' ),
		);
	}

	/**
	 * Build one operation's row.
	 *
	 * @param string                  $operation The operation label ('export' or 'import').
	 * @param array<array-key, mixed> $stats     The stored counters for that operation.
	 * @param string                  $bytes_key The counter key holding the byte total.
	 * @return array<string, int|string> The row.
	 */
	private function operation_row( string $operation, array $stats, string $bytes_key ): array {
		return array(
			'operation' => $operation,
			'attempted' => self::counter_int( $stats, 'attempted' ),
			'succeeded' => self::counter_int( $stats, 'succeeded' ),
			'failed'    => self::counter_int( $stats, 'failed' ),
			'size'      => $this->wordpress_context->format_size( self::counter_int( $stats, $bytes_key ) ),
		);
	}

	/**
	 * Read a counters option as an array, defaulting to empty.
	 *
	 * @param string $option The wp_options key.
	 * @return array<array-key, mixed> The stored counters, or an empty array.
	 */
	private function read_stats( string $option ): array {
		$value = $this->wordpress_context->option_value( $option, array() );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Whether no transfer has ever been attempted (export or import).
	 *
	 * @param array<array-key, mixed> $export_stats The stored export counters.
	 * @param array<array-key, mixed> $import_stats The stored import counters.
	 * @return bool True if both attempted counters are zero.
	 */
	private static function no_activity( array $export_stats, array $import_stats ): bool {
		return 0 === self::counter_int( $export_stats, 'attempted' )
			&& 0 === self::counter_int( $import_stats, 'attempted' );
	}

	/**
	 * Whether the requested format is a machine format (not the human table).
	 *
	 * Used to suppress the human "no transfers" note so json/csv/yaml output
	 * stays clean for tooling.
	 *
	 * @param array<string, string|bool> $associative_args The CLI's associative args.
	 * @return bool True when --format is something other than table.
	 */
	private static function is_machine_format( array $associative_args ): bool {
		$format = isset( $associative_args['format'] ) ? (string) $associative_args['format'] : 'table';
		return 'table' !== $format;
	}

	/**
	 * Read one counter from an array as a non-negative-safe integer.
	 *
	 * Returns 0 when the key is absent or its value is non-numeric, so a corrupt
	 * stored option degrades to zero rather than a type error — the same
	 * tolerance ExportCommand and ImportCommand apply when writing.
	 *
	 * @param array<array-key, mixed> $values The array to read from.
	 * @param string                  $key    The counter key.
	 * @return int The value as an int, or 0.
	 */
	private static function counter_int( array $values, string $key ): int {
		return isset( $values[ $key ] ) && is_numeric( $values[ $key ] ) ? (int) $values[ $key ] : 0;
	}
}
