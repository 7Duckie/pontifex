<?php
/**
 * A rolling history of recent Pontifex transfers, stored in wp_options.
 *
 * @package Pontifex\Cli
 */

declare(strict_types=1);

namespace Pontifex\Cli;

use Pontifex\WordPress\WordPressContext;

/**
 * Records and reads the last few transfers a Pontifex site has performed.
 *
 * Where the export and import counters (read by {@see StatsCommand}) keep
 * running totals, this keeps a short rolling window of individual transfers —
 * when each happened, whether it was an export or import, whether it succeeded,
 * and how big it was. It never records any content: just a timestamp, a kind, an
 * outcome, and a byte count, so the history is privacy-respecting and the option
 * stays small.
 *
 * Stored as a JSON array under one `wp_options` key (idea-bank Idea 002's
 * wp_options shape, sufficient for a rolling window of this size; a custom table
 * is a later concern if growth ever demands it), capped at {@see self::MAX_ENTRIES}
 * with the oldest dropped first. All static; not instantiable.
 */
final class TransferHistory {

	/**
	 * The wp_options key the rolling history is stored under.
	 *
	 * @var string
	 */
	public const OPTION = 'pontifex_transfer_history';

	/**
	 * How many recent transfers to retain.
	 *
	 * @var int
	 */
	public const MAX_ENTRIES = 20;

	/**
	 * Prevent instantiation; this class exposes only static helpers.
	 */
	private function __construct() {
	}

	/**
	 * Record one transfer, dropping the oldest if the window is full.
	 *
	 * The timestamp is passed in rather than read from the clock, so this is
	 * deterministic and testable; callers supply an ISO-8601 string.
	 *
	 * @param WordPressContext $wordpress_context The context to read and write the option through.
	 * @param string           $operation         'export' or 'import'.
	 * @param string           $outcome           'succeeded' or 'failed'.
	 * @param int              $bytes             The archive size in bytes (0 for a failure).
	 * @param string           $occurred_at       An ISO-8601 timestamp for when the transfer happened.
	 * @return void
	 */
	public static function record( WordPressContext $wordpress_context, string $operation, string $outcome, int $bytes, string $occurred_at ): void {
		$entry = array(
			'at'        => $occurred_at,
			'operation' => $operation,
			'outcome'   => $outcome,
			'bytes'     => $bytes,
		);

		$history = self::append_capped( self::recent( $wordpress_context ), $entry, self::MAX_ENTRIES );
		$wordpress_context->save_option( self::OPTION, $history );
	}

	/**
	 * Read the stored history, oldest first.
	 *
	 * Tolerant of a missing or corrupt option: a non-array value, or non-array
	 * elements within it, degrade to an empty list rather than a type error.
	 *
	 * @param WordPressContext $wordpress_context The context to read the option through.
	 * @return array<int, array<string, mixed>> The stored entries, oldest first.
	 */
	public static function recent( WordPressContext $wordpress_context ): array {
		$value = $wordpress_context->option_value( self::OPTION, array() );
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_values( array_filter( $value, 'is_array' ) );
	}

	/**
	 * Append an entry to a history list and cap it to the most recent $max.
	 *
	 * Pure: no I/O. The list is chronological (oldest first), so the cap drops
	 * from the front.
	 *
	 * @param array<int, array<string, mixed>> $history The current history, oldest first.
	 * @param array<string, mixed>             $entry   The entry to append.
	 * @param int                              $max     The maximum number of entries to keep.
	 * @return array<int, array<string, mixed>> The capped history, oldest first.
	 */
	public static function append_capped( array $history, array $entry, int $max ): array {
		$history[] = $entry;

		$overflow = count( $history ) - $max;
		if ( $overflow > 0 ) {
			$history = array_slice( $history, $overflow );
		}

		return $history;
	}
}
