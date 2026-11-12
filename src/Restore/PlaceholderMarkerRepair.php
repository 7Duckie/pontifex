<?php
/**
 * Pontifex placeholder-marker repair — undoes the percent-sign corruption older exports froze into an archive's SQL.
 *
 * @package Pontifex\Restore
 */

declare(strict_types=1);

namespace Pontifex\Restore;

use RuntimeException;

/**
 * Turns the marker an affected export wrote in place of each `%` back into a `%`.
 *
 * WordPress's `wpdb::_real_escape()` finishes by calling `add_placeholder_escape()`,
 * which replaces every `%` in the escaped value with a marker: an opening brace,
 * exactly 64 lowercase hexadecimal characters, a closing brace. The marker is
 * generated per request and is only ever meant to live in memory — `wpdb::query()`
 * strips it again as the statement runs, so inside one request the pair cancels
 * out and nothing is lost. Versions of Pontifex up to and including
 * {@see self::LAST_AFFECTED_VERSION} escaped every row value with that method and
 * then wrote the finished statement into the archive file, so the marker was
 * frozen into the backup. At restore a different request strips a different
 * marker, the frozen one matches nothing, and it lands in the database as literal
 * text: a permalink such as `/%postname%/` breaks, and a serialised setting
 * silently reverts because its recorded `s:NN:` byte length no longer describes
 * the value. The same archive imported twice in two separate requests produced
 * the identical marker both times, which is how it was shown to be baked into
 * the file rather than added at restore.
 *
 * Fixing the export only cleans archives written from then on. Every archive an
 * affected version has already written — backups, offsite copies, and the safety
 * archives and rollback archives taken before an import — still carries the
 * marker, so the repair has to happen at replay, where the original bytes can
 * still be recovered: replacing each marker with a single `%` reproduces exactly
 * what `_real_escape()` returned before it swapped the percent signs out.
 *
 * Deliberately pure: no WordPress runtime, no collaborators, no state, so the
 * three places that restore data ({@see \Pontifex\Cli\ImportCommand},
 * {@see \Pontifex\Cli\RollbackCommand} and {@see \Pontifex\Admin\RestoreController})
 * share one decision rather than each carrying a copy that could drift.
 *
 * Only INSERT statements are repaired — see {@see self::REPAIRED_STATEMENT_PREFIX}
 * for why that is the complete set, and what would make it incomplete.
 *
 * The trade-off, stated honestly: a value that legitimately consists of a brace,
 * 64 lowercase hexadecimal characters and a closing brace is indistinguishable
 * from the marker, and would be turned into a `%`. That is a deliberate, recorded
 * trade. The corruption is certain — every `%` in every affected archive is
 * damaged, and it breaks sites — while this false positive is speculative and
 * touches only one exact shape. Two bounds keep it that small: the version gate
 * in {@see self::is_needed_for()} means an archive written after the export was
 * fixed is never touched, and the statement gate means that even in an affected
 * archive only the values of INSERT statements are ever read, never a table's
 * schema text.
 */
final class PlaceholderMarkerRepair {

	/**
	 * The shape of the marker: a `{`, exactly 64 lowercase hexadecimal characters, a `}`.
	 *
	 * Lowercase and exactly 64 because WordPress builds the marker as
	 * `'{' . hash_hmac( 'sha256', ... ) . '}'`, and `hash_hmac()` renders its
	 * result as lowercase hexadecimal — so a SHA-256 one is always 64 characters.
	 * Anything near that shape but not exactly it — 63 or 65 characters,
	 * uppercase, a non-hexadecimal character — is not a marker and is left alone.
	 *
	 * @var string
	 */
	public const MARKER_PATTERN = '/\{[0-9a-f]{64}\}/';

	/**
	 * The only statements the repair touches: those opening, at byte offset 0, with this exact text.
	 *
	 * Anchored and byte-exact, the way the restore's containment check reads a
	 * statement's opening — never a search through the statement for a verb. A
	 * statement that does not begin with exactly these bytes is returned as it
	 * came: `insert into`, a leading space, `INSERT IGNORE INTO` and `REPLACE INTO`
	 * are all left alone.
	 *
	 * That narrowing is complete only because of one fact about the exporter, and
	 * it must be re-checked if that fact ever changes: the marker comes from
	 * `wpdb::_real_escape()`, and `WpdbAdapter::encode_value()` — which builds the
	 * row values of every INSERT the exporter writes — is the one place the
	 * exporter escapes anything with it. Every other statement the exporter emits
	 * is built from identifiers escaped by doubling backticks (the DROP, the
	 * INSERT's table and column names) or copied verbatim from the server (the
	 * CREATE, straight from `SHOW CREATE TABLE`), so none of them can carry a
	 * marker. Were a second call that escapes a value added to the export path,
	 * its output could land in a statement shape this gate does not cover, and a
	 * restore of such an archive would silently leave the markers in. Whoever adds
	 * one must widen this gate, or the corruption comes back for those values.
	 *
	 * @var string
	 */
	public const REPAIRED_STATEMENT_PREFIX = 'INSERT INTO ';

	/**
	 * The last Pontifex version that wrote archives carrying this defect.
	 *
	 * This is the version gate for the repair: an archive recording a Pontifex
	 * exporter at or below this version is repaired on replay, and one written by a
	 * later version needs no repair and is never touched. It must not be bumped
	 * casually. Raising it would start rewriting data in archives that were never
	 * affected, and lowering it would leave affected archives broken with nothing
	 * reporting it. It moves only if a still-later release is found to have written
	 * the defect.
	 *
	 * @var string
	 */
	public const LAST_AFFECTED_VERSION = '1.1.0';

	/**
	 * Whether an archive written by this exporter needs its markers repaired.
	 *
	 * True only when the exporter name is exactly `pontifex` and its version is at
	 * or below {@see self::LAST_AFFECTED_VERSION}. An archive from a different
	 * writer is never repaired: the marker shape is a Pontifex defect, not a feature
	 * of the archive format, so data of that shape from a third-party writer would
	 * be real data.
	 *
	 * A version string PHP cannot read as a version is compared as PHP's own
	 * `version_compare()` compares it, which sorts unreadable text below any
	 * numbered release — so an unreadable version on an archive naming Pontifex as
	 * its writer is repaired, not skipped. That direction is deliberate: skipping
	 * would leave certain corruption in place, repairing risks only the one exact
	 * shape described on the class.
	 *
	 * @param string $exporter_name    The exporter name recorded in the archive's provenance.
	 * @param string $exporter_version The exporter version recorded in the archive's provenance.
	 * @return bool True when the archive's SQL should be repaired as it is replayed.
	 */
	public static function is_needed_for( string $exporter_name, string $exporter_version ): bool {
		return 'pontifex' === $exporter_name
			&& version_compare( $exporter_version, self::LAST_AFFECTED_VERSION, '<=' );
	}

	/**
	 * Replace every marker in one INSERT statement with a single `%`.
	 *
	 * A statement that does not open with {@see self::REPAIRED_STATEMENT_PREFIX} is
	 * returned unchanged, with no pattern run and the count untouched: only a row
	 * value can carry a marker, and a marker-shaped run of text in a table's
	 * schema (a column default, a comment) is deliberately left alone.
	 *
	 * Fails closed. If the regular-expression engine itself fails, `preg_replace()`
	 * returns null rather than the statement, and passing the statement through
	 * unrepaired would restore the very damage the caller has just promised to
	 * repair, with nothing saying so. It throws instead, naming the failure.
	 *
	 * @param string $statement One SQL statement from a decoded db_chunk.
	 * @param int    $count     In and out. The number of markers replaced in this statement is added to it, so a caller can total the repairs across statements and chunks. Note that it counts markers, which is percent signs: one value holding three of them adds three.
	 * @return string The statement with every marker replaced by a `%`; the statement itself, unchanged, when it is not an INSERT or holds none.
	 * @throws RuntimeException If the regular-expression engine fails, so the statement cannot be known to be repaired.
	 */
	public static function repair( string $statement, int &$count ): string {
		if ( ! str_starts_with( $statement, self::REPAIRED_STATEMENT_PREFIX ) ) {
			return $statement;
		}

		$repaired = preg_replace( self::MARKER_PATTERN, '%', $statement, -1, $replaced );

		if ( null === $repaired ) {
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The regex engine's own error text is reported for diagnostic context, never the statement's own bytes; exception path, not HTML output.
				sprintf( 'Could not repair the percent signs in a backed-up statement because the regular-expression engine failed (%s); refusing to restore damaged values unrepaired.', preg_last_error_msg() )
			);
		}

		$count += $replaced;

		return $repaired;
	}
}
