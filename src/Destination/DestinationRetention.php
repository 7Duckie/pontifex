<?php
/**
 * Pontifex destination retention — prunes surplus archives from an offsite destination.
 *
 * @package Pontifex\Destination
 */

declare(strict_types=1);

namespace Pontifex\Destination;

use Pontifex\Archive\ArchiveName;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Prunes an offsite destination down to its configured number of archives.
 *
 * Lists what a destination currently holds, sorts it oldest-first by real
 * modification time (see {@see self::compare_by_age()}), and deletes only the
 * oldest surplus — the archives beyond the configured keep count. The floor
 * guarantee: only the oldest `count( $objects ) - $keep` archives are ever
 * deleted, so at least `$keep` (and never fewer than {@see MIN_RETENTION}) always
 * survive a prune. A retention of 0 means "keep all"; prune() then does no
 * listing and no deleting at all.
 *
 * Deleting is best-effort: one archive's delete failure does not stop the
 * rest of the prune, but it is never silently absorbed either — every failure
 * is both logged and carried home on the returned {@see PruneResult}, so a
 * caller can tell "nothing needed pruning" apart from "every delete was
 * refused", which are otherwise indistinguishable as a bare empty list.
 */
final class DestinationRetention {

	/**
	 * The minimum retention count that allows pruning at all.
	 *
	 * Below this, retention means "keep all" — pruning may never delete the
	 * last surviving archive.
	 *
	 * @var int
	 */
	public const MIN_RETENTION = 1;

	/**
	 * The destination adapter to list and delete through.
	 *
	 * @var DestinationAdapter
	 */
	private DestinationAdapter $adapter;

	/**
	 * How many archives to keep.
	 *
	 * @var int
	 */
	private int $keep;

	/**
	 * Records deletions that could not be carried out.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * Construct a destination-retention pruner.
	 *
	 * @param DestinationAdapter   $adapter The destination to prune.
	 * @param int                  $keep    How many archives to keep; below MIN_RETENTION keeps all.
	 * @param LoggerInterface|null $logger  Optional. Records deletions that could not be carried out; defaults to discarding them. Trailing and optional, so no existing caller changes.
	 */
	public function __construct( DestinationAdapter $adapter, int $keep, ?LoggerInterface $logger = null ) {
		$this->adapter = $adapter;
		$this->keep    = $keep;
		$this->logger  = $logger ?? new NullLogger();
	}

	/**
	 * Delete the oldest surplus archives, keeping the newest $keep.
	 *
	 * @return PruneResult What was deleted and what failed — see that class's
	 *                     docblock for why the two are never collapsed into
	 *                     a single list.
	 * @throws DestinationException If listing the destination fails. An individual delete
	 *                              failure is recorded on the result instead of thrown
	 *                              (best-effort).
	 */
	public function prune(): PruneResult {
		if ( $this->keep < self::MIN_RETENTION ) {
			return new PruneResult( array(), array() );
		}

		// Only ever rotate archives Pontifex named itself. A name-shaped
		// `pontifex-backup-<UTC>.wpmig` is not proof of anything about the file
		// behind it — see {@see self::compare_by_age()} for why the ORDER no
		// longer trusts the name either — but the SFTP adapter lists anything
		// ending `.wpmig`, and `export --destination` uploads under whatever
		// basename --output was given. A file called `before-upgrade.wpmig`
		// therefore joined the set, and without this filter would be pruned
		// alongside genuine backups the moment it happened to sort oldest — the
		// backup taken minutes earlier precisely so it would be there if the
		// upgrade went wrong, reported in the log as pruning an old archive.
		//
		// Anything not matching the generated form is left strictly alone. It is
		// not part of this rotation, so it is not ours to delete; a keep-count is
		// a promise about the backups Pontifex made, not about every file that
		// happens to share the directory.
		$objects = array_values(
			array_filter(
				$this->adapter->list(),
				static fn ( RemoteObject $remote ): bool => ArchiveName::is_generated( $remote->name() )
			)
		);

		// "Now" is read ONCE for the whole sort, not once per comparison — see
		// {@see self::compare_by_age()}'s docblock, and
		// {@see \Pontifex\Admin\BackupStore::backups()}, whose identical
		// reasoning this mirrors.
		$now = time();
		usort(
			$objects,
			static fn ( RemoteObject $a, RemoteObject $b ): int => self::compare_by_age( $a, $b, $now )
		);

		$surplus = count( $objects ) - $this->keep;
		if ( $surplus <= 0 ) {
			return new PruneResult( array(), array() );
		}

		$deleted = array();
		$failed  = array();
		foreach ( array_slice( $objects, 0, $surplus ) as $object ) {
			try {
				$this->adapter->delete( $object->name() );
				$deleted[] = $object->name();
			} catch ( DestinationException $e ) {
				// Best-effort: leave this one in place and keep pruning the rest.
				// Recorded on the result AND logged rather than swallowed — a prune
				// that reports "removed 2" while silently failing to remove three
				// more leaves the operator believing retention is holding when the
				// destination is quietly filling up.
				$failed[ $object->name() ] = $e->getMessage();
				$this->logger->warning(
					'Could not delete an archive from the destination; it remains in place.',
					array(
						'archive' => $object->name(),
						'reason'  => $e->getMessage(),
					)
				);
				continue;
			}
		}

		return new PruneResult( $deleted, $failed );
	}

	/**
	 * Compare two remote archives by age, oldest first — the ordering {@see self::prune()} sorts with.
	 *
	 * The identical three-key rule as
	 * {@see \Pontifex\Admin\BackupStore::compare_by_age()}, adapted from a
	 * local path's `filemtime()` to a {@see RemoteObject}'s
	 * {@see RemoteObject::modification_time()}. That method's docblock is the
	 * fuller account of why sorting by NAME was the original defect (a
	 * future-dated file sorts last for ever under a name sort, so it survives
	 * every prune while evicting genuinely current archives in its place) and
	 * why a FUTURE modification time and an UNKNOWN one must not be treated
	 * the same:
	 *
	 *  - A FUTURE modification time is evidence about THIS PARTICULAR archive
	 *    — a clock was wrong when it was written — so that one archive alone
	 *    can safely be treated as the oldest thing in the set and pruned first.
	 *  - An UNKNOWN modification time is a property of what the DESTINATION
	 *    reported, not of the archive: a server that omits `mtime` from one
	 *    listing entry most likely omits it from every entry, so treating
	 *    "unknown" as "oldest" could make everything on that destination look
	 *    prunable at once, which is a far worse failure than the one this
	 *    guards against. It is therefore treated as CURRENT time and TRUSTED,
	 *    never as future-dated — mirroring exactly how an unreadable local
	 *    `filemtime()` is treated in {@see \Pontifex\Admin\BackupStore::age()}.
	 *
	 * Three keys, in order:
	 *
	 *  1. PRIMARY — whether the modification time is in the future. If exactly
	 *     one of the two entries is future-dated, that entry sorts FIRST —
	 *     before every trusted entry, regardless of the times involved.
	 *  2. SECONDARY — otherwise (neither is future-dated, or both are),
	 *     `min( modification_time(), now )` ascending, so among trustworthy
	 *     entries the genuinely older one still sorts first.
	 *  3. TERTIARY — name, ascending, so a tie that reaches this point still
	 *     resolves deterministically.
	 *
	 * $now is supplied by the caller, read once for the whole sort — see
	 * {@see self::prune()} — rather than read again here, so every comparison
	 * within one sort is measured against the identical instant.
	 *
	 * @param RemoteObject $a   One remote archive.
	 * @param RemoteObject $b   Another remote archive.
	 * @param int          $now The current Unix timestamp, shared by every comparison in this sort.
	 * @return int Negative when $a is older, positive when $b is older, zero when equal by every key.
	 */
	private static function compare_by_age( RemoteObject $a, RemoteObject $b, int $now ): int {
		$age_a = self::age( $a, $now );
		$age_b = self::age( $b, $now );

		if ( $age_a['future'] !== $age_b['future'] ) {
			// A future-dated (untrustworthy) entry sorts FIRST — as the oldest.
			return $age_a['future'] ? -1 : 1;
		}
		if ( $age_a['time'] !== $age_b['time'] ) {
			return $age_a['time'] <=> $age_b['time'];
		}
		return strcmp( $a->name(), $b->name() );
	}

	/**
	 * Compute one remote object's sort key: its modification time, clamped to
	 * now, and whether it was genuinely future-dated.
	 *
	 * @param RemoteObject $remote_object The remote archive.
	 * @param int          $now           The current Unix timestamp, read once per sort so every
	 *                                    entry compared in that sort is measured against the same "now".
	 * @return array{time: int, future: bool} The clamped time, and whether the raw modification time was in the future.
	 */
	private static function age( RemoteObject $remote_object, int $now ): array {
		$mtime = $remote_object->modification_time();
		if ( -1 === $mtime ) {
			// Unknown: a destination-wide condition, not evidence against this one
			// archive, so it is trusted as current rather than treated as oldest.
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
}
