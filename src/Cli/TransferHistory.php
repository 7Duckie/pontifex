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
 * when each happened, whether it was an export, an import or a rollback, whether
 * it succeeded, and how big it was. It never records any content: a timestamp, a
 * kind, an outcome and a byte count, plus — for an import or rollback — a small
 * `details` array of counts and flags (see the `$details` parameter of
 * {@see self::record()}). Never a path, a URL, a table name, a link target, an
 * option name or any other site content, so the history is privacy-respecting and
 * the option stays small.
 *
 * Stored as a JSON array under one `wp_options` key (idea-bank Idea 002's
 * wp_options shape, sufficient for a rolling window of this size; a custom table
 * is a later concern if growth ever demands it), capped at {@see self::MAX_ENTRIES}
 * with the oldest dropped first. All static; not instantiable.
 *
 * The words a stored entry uses — the operation kinds, the recovery labels and
 * the verify-gate labels — are defined here, as the `OPERATION_*`, `RECOVERY_*`
 * and `GATE_*` constants. This class owns the stored format, and every surface
 * that writes or reads it already depends on it, so the vocabulary belongs with
 * it: a writer maps its own private names onto these, a reader compares against
 * these, and nobody has to depend on anybody else's namespace to do either.
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
	 * Operation kind: an export (a backup).
	 *
	 * @var string
	 */
	public const OPERATION_EXPORT = 'export';

	/**
	 * Operation kind: an import (a restore).
	 *
	 * @var string
	 */
	public const OPERATION_IMPORT = 'import';

	/**
	 * Operation kind: a rollback to the most recent safety archive.
	 *
	 * @var string
	 */
	public const OPERATION_ROLLBACK = 'rollback';

	/**
	 * Recovery label: no recovery ran (the operation succeeded, failed before anything changed, or has no recovery at all).
	 *
	 * @var string
	 */
	public const RECOVERY_NONE = '';

	/**
	 * Recovery label: a failed restore was undone exactly — every path it created was accounted for and removed.
	 *
	 * @var string
	 */
	public const RECOVERY_PRECISE = 'precise';

	/**
	 * Recovery label: a failed restore was rolled back, but the file cleanup could not be confirmed complete.
	 *
	 * @var string
	 */
	public const RECOVERY_MERGE = 'merge';

	/**
	 * Recovery label: a failed restore was rolled back and the file cleanup was complete, but a symbolic link could not be re-created.
	 *
	 * @var string
	 */
	public const RECOVERY_MERGE_LINKS = 'merge_links';

	/**
	 * Recovery label: recovery was attempted after a failed restore and did not complete.
	 *
	 * @var string
	 */
	public const RECOVERY_FAILED = 'failed';

	/**
	 * Gate label: the operation was not stopped at the verify gate.
	 *
	 * @var string
	 */
	public const GATE_NONE = '';

	/**
	 * Gate label: the verify gate found the archive damaged — a hash mismatch or a malformed structure.
	 *
	 * @var string
	 */
	public const GATE_BROKEN = 'broken';

	/**
	 * Gate label: the archive is intact, but a restore will not accept it.
	 *
	 * @var string
	 */
	public const GATE_REFUSED = 'refused';

	/**
	 * Gate label: this host stopped verification before it reached any verdict about the archive.
	 *
	 * @var string
	 */
	public const GATE_COULD_NOT_CHECK = 'could_not_check';

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
	 * @param WordPressContext               $wordpress_context The context to read and write the option through.
	 * @param string                         $operation         One of the `OPERATION_*` constants.
	 * @param string                         $outcome           'succeeded' or 'failed'.
	 * @param int                            $bytes             The archive size in bytes (0 for a failure).
	 * @param string                         $occurred_at       An ISO-8601 timestamp for when the transfer happened.
	 * @param array<string, bool|int|string> $details           Optional. Counts and flags describing what the transfer did, stored under a `details` key and left out of the entry entirely when empty, so every caller that passes none writes exactly the entry it always did. COUNTS AND FLAGS ONLY: never a path, a URL, a table name, a link target, an option name or any other site content — that is what keeps this history safe to show on a screen or paste into a support request, and what keeps the option small. A string value is allowed only where it is one fixed label from a closed list this class defines (the `recovery` and `gate` keys), never a value read from the site. The keys an import or rollback writes, all optional and all tolerated as absent by the reader: `entries` (int, entries written; 0 on failure), `relinked` (bool, whether a URL rewrite ran), `rows_rewritten` (int, rows the rewrite changed), `repaired_markers` (int, percent signs put back), `skipped_links` (int, symbolic links the replay skipped), `recovery` (string, one of the `RECOVERY_*` labels; absent when no recovery ran) and `gate` (string, one of the `GATE_*` labels; set only when the operation was stopped at the verify gate, which runs before anything is written, so a gate label also means nothing was changed; absent everywhere else and never inferred).
	 * @return void
	 */
	public static function record( WordPressContext $wordpress_context, string $operation, string $outcome, int $bytes, string $occurred_at, array $details = array() ): void {
		$entry = array(
			'at'        => $occurred_at,
			'operation' => $operation,
			'outcome'   => $outcome,
			'bytes'     => $bytes,
		);

		if ( array() !== $details ) {
			$entry['details'] = $details;
		}

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
