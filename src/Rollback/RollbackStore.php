<?php
/**
 * Pontifex rollback store — the on-disk directory of pre-import safety archives.
 *
 * @package Pontifex\Rollback
 */

declare(strict_types=1);

namespace Pontifex\Rollback;

use DateTimeImmutable;
use DateTimeZone;
use Pontifex\Filesystem\ProtectedDirectory;
use RuntimeException;

/**
 * Manages `wp-content/pontifex/rollback/` and the safety archives within it.
 *
 * Implements {@see RollbackStoreInterface}. The policy it enforces is ADR 0005:
 *
 *  - **Location:** a `pontifex/rollback` subdirectory of the WordPress content
 *    directory, created not world-readable (mode 0700) because a safety archive
 *    is a full copy of the database.
 *  - **Naming:** `pre-import-rollback-<UTC>.wpmig`, the time formatted in UTC so
 *    the newest archive is the lexicographically last.
 *  - **Listing / retention:** archives are matched by that name pattern, then
 *    sorted by modification time — not by name; see
 *    {@see self::compare_by_age()} for why — and pruned to the newest N on
 *    request.
 *
 * Filesystem work uses PHP's built-ins directly (the Environment seam does not
 * cover directory creation, globbing or deletion); the class is exercised
 * against a real temporary directory in its tests, the same way FileWriter and
 * FileLogger are.
 */
final class RollbackStore implements RollbackStoreInterface {

	/**
	 * Subdirectory, under the content directory, where safety archives live.
	 *
	 * @var string
	 */
	private const SUBDIRECTORY = 'pontifex/rollback';

	/**
	 * Filename prefix shared by every safety archive.
	 *
	 * @var string
	 */
	private const NAME_PREFIX = 'pre-import-rollback-';

	/**
	 * Filename extension shared by every safety archive.
	 *
	 * @var string
	 */
	private const NAME_EXTENSION = '.wpmig';

	/**
	 * Mode the rollback directory is created with: owner-only (rwx------).
	 *
	 * @var int
	 */
	private const DIRECTORY_MODE = 0700;

	/**
	 * Absolute path of the rollback directory.
	 *
	 * @var string
	 */
	private string $directory;

	/**
	 * Construct a store rooted at the given content directory.
	 *
	 * @param string $content_dir Absolute path of the WordPress content directory (WP_CONTENT_DIR).
	 */
	public function __construct( string $content_dir ) {
		$this->directory = rtrim( $content_dir, '/' ) . '/' . self::SUBDIRECTORY;
	}

	/**
	 * Return the absolute path of the rollback directory.
	 *
	 * @return string The absolute directory path.
	 */
	public function directory(): string {
		return $this->directory;
	}

	/**
	 * Create the rollback directory (mode 0700) if it does not already exist.
	 *
	 * @return void
	 * @throws RuntimeException If the directory cannot be created.
	 */
	public function ensure_directory(): void {
		// Create the not-world-readable directory and lock it against direct web
		// access (a safety archive is a full site backup). ProtectedDirectory is
		// best-effort, so the hard guarantee — the directory exists — is asserted
		// here, where the caller expects an exception on failure.
		if ( ! ProtectedDirectory::ensure( $this->directory, self::DIRECTORY_MODE ) ) {
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message only; the path is plugin-derived, not web output.
				sprintf( 'Could not create the rollback directory: %s', $this->directory )
			);
		}
	}

	/**
	 * Return the absolute path a new safety archive should be written to.
	 *
	 * @param DateTimeImmutable $now The moment the archive is being taken.
	 * @return string The absolute archive path.
	 */
	public function next_archive_path( DateTimeImmutable $now ): string {
		$utc = $now->setTimezone( new DateTimeZone( 'UTC' ) );
		return $this->directory . '/' . self::NAME_PREFIX . $utc->format( 'Ymd\THis\Z' ) . self::NAME_EXTENSION;
	}

	/**
	 * Return every safety archive in the directory, oldest first.
	 *
	 * @return array<int, string> Absolute archive paths, oldest to newest.
	 */
	public function archives(): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_glob -- Listing the plugin-owned rollback directory; WP_Filesystem is unavailable in CLI/test contexts.
		$matches = glob( $this->directory . '/' . self::NAME_PREFIX . '*' . self::NAME_EXTENSION );
		if ( false === $matches ) {
			return array();
		}

		// "Now" is read ONCE for the whole sort, not once per comparison: a
		// comparator usort() relies on must be consistent across every pair it
		// is asked about, and re-reading a live clock inside the comparator
		// could hand back a different "now" to two different pairwise calls
		// within the very same sort, which is not a well-defined order at all.
		$now = time();
		usort(
			$matches,
			static fn ( string $a, string $b ): int => self::compare_by_age( $a, $b, $now )
		);
		return $matches;
	}

	/**
	 * Compare two safety archives by age, oldest first — the ordering {@see self::archives()} sorts with.
	 *
	 * Sorting by NAME was the original defect, shared with {@see \Pontifex\Admin\BackupStore},
	 * whose {@see \Pontifex\Admin\BackupStore::compare_by_age()} carries the full
	 * measured account. In short: a name is untrusted, self-reported data — every
	 * field of a stamp like `20991231T235959Z` is calendar-valid, so no stricter
	 * naming pattern can refuse it, and parsing the date out of the name instead
	 * of comparing the string only moves the same trust-the-string problem behind
	 * a parser. A single future-dated archive would sort last for ever under a
	 * name sort, so every genuinely current archive would look "oldest" and be
	 * pruned in its place, while the future-dated one permanently occupied a
	 * retention slot.
	 *
	 * A first attempt keyed the tie-break on "clamped" (future OR unreadable)
	 * rather than on "future" alone, and that mixes together two untrustworthy
	 * cases that are not the same and must not be treated the same:
	 *
	 *  - A FUTURE modification time is evidence that THIS PARTICULAR archive's
	 *    timestamp is wrong. It is a positive signal about one file, so that
	 *    one file can safely be treated as the oldest thing in the set.
	 *  - An UNREADABLE modification time is a property of the HOST, affecting
	 *    every file equally. Treating an unreadable read as "oldest" could
	 *    make everything on a host that cannot read mtimes look prunable at
	 *    once, which is a far worse failure than the one being guarded
	 *    against.
	 *
	 * Clamping a future timestamp to "now" (so it cannot claim to be newer
	 * than everything else by an arbitrary margin) is not enough on its own:
	 * clamping only ties a future entry with a genuinely fresh one taken at
	 * the same instant, and an ordinary tie-break by name lets the
	 * future-dated file win that tie and survive at the fresh archive's
	 * expense — the original bug, reintroduced one level down, because an
	 * exact same-second tie is the only case a clamp-then-sort rule actually
	 * catches. Two keys, in order:
	 *
	 *  1. PRIMARY — whether the modification time is in the future. If
	 *     exactly one of the two entries is future-dated, that entry sorts
	 *     FIRST — before every trusted entry, regardless of the times
	 *     involved — because it is untrustworthy and untrustworthy is treated
	 *     as oldest. A safety archive written under a clock skewed into the
	 *     future is consequently now the first thing retention removes; that
	 *     is a deliberate trade-off, because the measured alternative is that
	 *     one such file evicts a genuine archive on every cycle, for ever.
	 *     One file lost once beats a real archive destroyed every night.
	 *  2. SECONDARY — otherwise (neither is future-dated, or both are),
	 *     `min( filemtime(), now )` ascending, so among trustworthy entries
	 *     the genuinely older one still sorts first.
	 *  3. TERTIARY — name, ascending, so a tie that reaches this point still
	 *     resolves deterministically.
	 *
	 * A modification time that cannot be read — `filemtime()` unavailable
	 * (commonly `disable_functions`, which raises a fatal `Error` on PHP 8
	 * rather than a suppressible warning) or the read itself failing — is
	 * treated as CURRENT time and TRUSTED (not future-dated): an entry whose
	 * age cannot be established must never sort as the oldest, because
	 * "oldest" is what gets deleted.
	 *
	 * $now is supplied by the caller, read once for the whole sort — see
	 * {@see self::archives()} — rather than read again here, so every
	 * comparison within one sort is measured against the identical instant.
	 *
	 * @param string $a   One absolute archive path.
	 * @param string $b   Another absolute archive path.
	 * @param int    $now The current Unix timestamp, shared by every comparison in this sort.
	 * @return int Negative when $a is older, positive when $b is older, zero when equal by every key.
	 */
	private static function compare_by_age( string $a, string $b, int $now ): int {
		$age_a = self::age( $a, $now );
		$age_b = self::age( $b, $now );

		if ( $age_a['future'] !== $age_b['future'] ) {
			// A future-dated (untrustworthy) entry sorts FIRST — as the oldest.
			return $age_a['future'] ? -1 : 1;
		}
		if ( $age_a['time'] !== $age_b['time'] ) {
			return $age_a['time'] <=> $age_b['time'];
		}
		return strcmp( $a, $b );
	}

	/**
	 * Compute one path's sort key: its modification time, clamped to now, and
	 * whether it was genuinely future-dated.
	 *
	 * @param string $path The absolute archive path.
	 * @param int    $now  The current Unix timestamp, read once per sort so every
	 *                     entry compared in that sort is measured against the same "now".
	 * @return array{time: int, future: bool} The clamped time, and whether the raw modification time was in the future.
	 */
	private static function age( string $path, int $now ): array {
		$mtime = self::modification_time( $path );
		if ( false === $mtime ) {
			// Unreadable: a host-wide condition, not evidence against this one
			// file, so it is trusted as current rather than treated as oldest.
			return array(
				'time'   => $now,
				'future' => false,
			);
		}
		return array(
			'time'   => min( $mtime, $now ),
			'future' => $mtime > $now,
		);
	}

	/**
	 * Read a path's modification time, tolerating a host that has removed filemtime().
	 *
	 * Guarded with function_exists() because a disabled function raises a fatal
	 * Error on PHP 8 rather than a suppressible warning — the same defect fixed
	 * across five call sites in this codebase already. A raw read failure (a
	 * TOCTOU race after glob(), or a host restriction such as open_basedir)
	 * degrades to false the same way, so the caller treats both identically.
	 *
	 * @param string $path The absolute archive path.
	 * @return int|false The modification time, or false when it cannot be read.
	 */
	private static function modification_time( string $path ): int|false {
		if ( ! function_exists( 'filemtime' ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filemtime,WordPress.PHP.NoSilencedErrors.Discouraged -- Reading a plugin-owned archive's age for retention ordering; best-effort, guarded by function_exists(), and a read failure degrades to "current time, clamped" rather than aborting the listing.
		return @filemtime( $path );
	}

	/**
	 * Return the most recent safety archive, or null when there is none.
	 *
	 * @return string|null The newest archive's absolute path, or null.
	 */
	public function most_recent(): ?string {
		$archives = $this->archives();
		if ( array() === $archives ) {
			return null;
		}
		return $archives[ count( $archives ) - 1 ];
	}

	/**
	 * Delete all but the newest $keep safety archives (best-effort).
	 *
	 * @param int $keep How many of the newest archives to retain.
	 * @return void
	 */
	public function prune( int $keep ): void {
		if ( 0 > $keep ) {
			$keep = 0;
		}

		$archives = $this->archives();
		$remove   = count( $archives ) - $keep;
		if ( 0 >= $remove ) {
			return;
		}

		for ( $index = 0; $index < $remove; $index++ ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort retention of a plugin-owned archive; a file that cannot be removed is intentionally left rather than aborting an import.
			@unlink( $archives[ $index ] );
		}
	}
}
