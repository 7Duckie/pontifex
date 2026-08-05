<?php
/**
 * The persisted export counters — one master for the option, its keys, and the merge.
 *
 * @package Pontifex\Export
 */

declare(strict_types=1);

namespace Pontifex\Export;

/**
 * The running totals every export path adds to, and the only place they are defined.
 *
 * Four separate writers used to keep their own copy of this schema — the CLI
 * export, the admin backup controller, the cron ticker and the scheduled
 * exporter — each rebuilding the stored array from its own hardcoded list of
 * six keys, with the option name appearing as a bare string literal in two of
 * them and as a private constant in two more. Six copies of one fact, and two
 * further key lists on the reading side.
 *
 * That is worse than untidy, because each writer WHITELISTS: it reads the
 * option, keeps only the keys it knows about, and writes the result back. So a
 * key one writer knew and another did not was not merely ignored by the second
 * — it was deleted from the option the moment that second path ran. Adding a
 * counter meant finding all four writers, and missing one meant the new counter
 * silently vanished whenever a backup happened to run through the path that had
 * not been updated. Nothing would fail; the number would just be wrong.
 *
 * The whitelist itself is deliberate and stays: these counters are shown to the
 * operator and included in a support bundle, so an unrecognised key is not
 * carried along on trust. Keeping the list in one place is what makes that
 * safe rather than lossy.
 */
final class ExportCounters {

	/**
	 * The option holding the running export totals.
	 *
	 * @var string
	 */
	public const OPTION = 'pontifex_export_stats';

	/**
	 * Every counter this option carries, in display order.
	 *
	 * A key absent from this list is dropped on the next write, by design — see
	 * the class docblock. Add one here and every writer picks it up at once,
	 * which is the whole reason this list has a single home.
	 *
	 * @var array<int, string>
	 */
	public const KEYS = array(
		'attempted',
		'succeeded',
		'failed',
		'bytes_exported',
		'files_changed',
		'media_type_unresolved',
	);

	/**
	 * Add a delta to the stored totals and return the array to save.
	 *
	 * Tolerant of whatever is actually in the option: a missing key, a key
	 * holding a string, or a stored value that is not an array at all all read
	 * as zero rather than throwing. These are counters on somebody's live site,
	 * and a corrupt total is not worth failing a backup over.
	 *
	 * @param mixed                    $stored The option's current value, whatever it happens to be.
	 * @param array<string, int|float> $delta  The increments to apply, keyed by counter name.
	 * @return array<string, int> The complete merged totals, ready to save.
	 */
	public static function merge( $stored, array $delta ): array {
		$current = is_array( $stored ) ? $stored : array();
		$merged  = array();

		foreach ( self::KEYS as $key ) {
			$previous       = isset( $current[ $key ] ) && is_numeric( $current[ $key ] ) ? (int) $current[ $key ] : 0;
			$merged[ $key ] = $previous + (int) ( $delta[ $key ] ?? 0 );
		}

		return $merged;
	}
}
