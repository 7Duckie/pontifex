<?php
/**
 * Pontifex temp-artefact shape — the shared name format for a write-then-rename temp file.
 *
 * @package Pontifex\Filesystem
 */

declare(strict_types=1);

namespace Pontifex\Filesystem;

/**
 * Builds, and recognises, the one temp-artefact name shape every Pontifex
 * write-then-rename producer uses.
 *
 * Four producers independently built this identical shape before this class
 * existed: {@see \Pontifex\Restore\FileWriter::temp_sibling_path()} and
 * {@see \Pontifex\Restore\FileWriter::probe_symlink_creation()} (a file's
 * sibling temp, and a create-then-remove symlink capability probe, both
 * renamed or removed once their real work completes),
 * {@see \Pontifex\Export\ExportRunner::temp_destination_path()},
 * {@see \Pontifex\Job\JobStore::save()}, and
 * {@see \Pontifex\Job\JobProgressLog::truncate_to()}. Every one of them now
 * calls {@see self::suffix()} rather than formatting its own uniqid() call,
 * so the shape a producer WRITES cannot silently drift from the shape a
 * sweep RECOGNISES.
 *
 * That last part matters more than it might look. Two callers now DELETE
 * files on the strength of this shape:
 * {@see \Pontifex\Restore\FileWriter::sweep_orphaned_temp_files()}, which
 * sweeps a restore destination of temps a killed restore left behind, and
 * {@see \Pontifex\Rollback\SafetyArchiver::sweep_orphaned_archive_temps()},
 * which sweeps the rollback directory of temps a killed safety-archive write
 * left behind. Before this class existed each deleter carried its own
 * private copy of the recognising pattern — two independent copies of a
 * security-relevant regular expression are a drift hazard in their own
 * right, because nothing stops a future edit to one from silently leaving
 * the other behind, at which point the two sweeps could disagree about
 * which files on disk are safe to remove. Extracting the shape to one place,
 * BEFORE the second deleter was added, closes that hazard rather than
 * merely documenting it.
 *
 * This class is deliberately the ONLY place in the plugin, not merely the
 * only place within one class, that knows this shape — an earlier version
 * of this reasoning, when it lived inside FileWriter alone, overstated that
 * claim; moving it here is what makes it true.
 * {@see \Pontifex\Export\ResumableExportRunner} is the one deliberate
 * exception: its `uniqid( 'pontifex-job-', true )` plus `.part` is a
 * DIFFERENT shape on purpose, because a `.part` file is live resumable
 * state a still-running export is writing to, not a write-then-rename temp
 * — see {@see self::is_orphan_name()} for why neither this class nor
 * anything built on it may ever recognise one.
 *
 * All static; the class holds no state and is never instantiated — the same
 * pattern {@see ProtectedDirectory} already uses elsewhere in this
 * namespace.
 */
final class TempArtefact {

	/**
	 * The uniqid() prefix embedded in every temp artefact {@see self::suffix()} produces.
	 *
	 * @var string
	 */
	private const PREFIX = 'pontifex-';

	/**
	 * The fixed extension appended to every temp artefact {@see self::suffix()} produces.
	 *
	 * @var string
	 */
	private const EXTENSION = '.tmp';

	/**
	 * Matches the basename of any temp artefact {@see self::suffix()} can produce.
	 *
	 * Consulted only by {@see self::is_orphan_name()}, which every sweep in the
	 * plugin calls to recognise a temp file (or, for
	 * {@see \Pontifex\Restore\FileWriter::probe_symlink_creation()}, a dangling
	 * probe symlink) an earlier, interrupted run left behind. Anchored at the
	 * END of the basename with `$`, and matched against the basename alone
	 * (never the full path), so a legitimate file that merely happens to sit
	 * inside a path segment shaped like this is never mistaken for one — this
	 * pattern is consulted only by a sweep, never by any archive-write path
	 * guard, so no archive-supplied path is ever checked against it.
	 *
	 * Deliberately narrow, not a loose `*.tmp` glob:
	 *
	 *  - It must NOT match a resumable export's `*.part` file — that is live
	 *    state a still-running export is writing to, and deleting one would
	 *    destroy real, unrecoverable work. `.part` never appears in this
	 *    pattern at all.
	 *  - It must NOT match an ordinary user file that merely happens to carry
	 *    "pontifex" or ".tmp" in its name, such as "notes.pontifex-backup.tmp",
	 *    "data.pontifex.tmp", "archive.pontifex-2024.01.tmp", or
	 *    "db.pontifex-1.2.tmp" — none has the uniqid() shape this pattern
	 *    requires (a run of at least eight hex digits, a literal dot, a run of
	 *    decimal digits), so none matches.
	 *
	 * The hex/decimal split mirrors uniqid()'s own output shape with the
	 * `$more_entropy` argument true, which {@see self::suffix()} passes: a
	 * hexadecimal timestamp-and-counter (an 8-digit seconds component
	 * immediately followed by a 5-digit microseconds component — 13 hex
	 * digits with no separator between them), then a literal ".", then a
	 * pseudorandom value from PHP's combined LCG (`php_combined_lcg()`)
	 * scaled and formatted as `%.8F` — not a fraction of a microsecond, a
	 * claim an earlier version of this reasoning made and which does not
	 * hold. That formatted value's own single leading integer digit (0-9) is
	 * itself a valid hex character, so it is silently absorbed into what
	 * this pattern reads as the hex run rather than starting the decimal
	 * run — which is why the hex run in every one of uniqid()'s real outputs
	 * is 14 characters, not 13, and why the eight-digit FLOOR below is a
	 * floor and not an exact count: pinning it at exactly 14 would be one
	 * accident of the current implementation away from silently no longer
	 * recognising this plugin's own artefacts, whereas eight is comfortably
	 * below every real output while still ruling out a short, human-typed
	 * number such as the "2024" or "1" in the two false positives above.
	 * This pattern does not pin the digit COUNT after the dot at all, only
	 * the shape (hex run, dot, decimal run), so it keeps matching even if a
	 * future PHP release ever changes uniqid()'s precision.
	 *
	 * @var string
	 */
	private const ORPHAN_NAME_PATTERN = '/\.pontifex-[0-9a-f]{8,}\.[0-9]+\.tmp$/';

	/**
	 * Prevent instantiation; this class exposes only static helpers.
	 */
	private function __construct() {
	}

	/**
	 * Build the shared suffix appended to every temp artefact a Pontifex
	 * write-then-rename producer creates before it is renamed (or, for a
	 * capability probe, removed) again.
	 *
	 * PHP's uniqid() with `$more_entropy` true is what supplies the actual
	 * uniqueness (a seconds-and-microseconds hex timestamp, plus a
	 * pseudorandom value from PHP's combined LCG — see
	 * {@see self::ORPHAN_NAME_PATTERN}'s docblock for the exact shape), so
	 * two concurrent writers — or two producers racing moments apart — never
	 * collide on one temp name. Every caller of this method routes its own
	 * uniqid() call through here rather than formatting one independently,
	 * which is what keeps the shape this method WRITES and the shape
	 * {@see self::is_orphan_name()} RECOGNISES from ever drifting apart; see
	 * this class's own docblock for why that guarantee is the whole reason
	 * this class exists.
	 *
	 * @return string A leading-dot suffix, e.g. ".pontifex-6a743b0b47cff2.47524803.tmp".
	 */
	public static function suffix(): string {
		return '.' . uniqid( self::PREFIX, true ) . self::EXTENSION;
	}

	/**
	 * Whether $basename is shaped like a temp artefact {@see self::suffix()} can produce.
	 *
	 * Takes a basename (never a full path) and matches it against
	 * {@see self::ORPHAN_NAME_PATTERN} — see that constant's docblock for the
	 * full shape and the two demonstrated false positives it deliberately
	 * excludes. Every sweep in the plugin
	 * ({@see \Pontifex\Restore\FileWriter::sweep_orphaned_temp_files()} and
	 * {@see \Pontifex\Rollback\SafetyArchiver::sweep_orphaned_archive_temps()})
	 * calls this method rather than matching its own copy of the pattern, for
	 * the same anti-drift reason {@see self::suffix()} is the single producer.
	 *
	 * This method does not itself decide what a caller does with a "true"
	 * answer — in particular it says nothing about whether the entry is a
	 * file, a directory, or a dangling symlink, or about ordering isLink()
	 * before isFile() when removing one. Each sweep makes those decisions
	 * itself, against its own filesystem.
	 *
	 * @param string $basename A filename (not a full path) to test.
	 * @return bool True if $basename ends with the temp-artefact shape this class produces.
	 */
	public static function is_orphan_name( string $basename ): bool {
		return 1 === preg_match( self::ORPHAN_NAME_PATTERN, $basename );
	}
}
