<?php
/**
 * Pontifex destination retention — prunes surplus archives from an offsite destination.
 *
 * @package Pontifex\Destination
 */

declare(strict_types=1);

namespace Pontifex\Destination;

/**
 * Prunes an offsite destination down to its configured number of archives.
 *
 * Lists what a destination currently holds, sorts it oldest-first by remote
 * name (the export writer stamps each archive with a sortable UTC timestamp,
 * so name order is age order), and deletes only the oldest surplus — the
 * archives beyond the configured keep count. The floor guarantee: only the
 * oldest `count( $objects ) - $keep` archives are ever deleted, so at least
 * `$keep` (and never fewer than {@see MIN_RETENTION}) always survive a
 * prune. A retention of 0 means "keep all"; prune() then does no listing
 * and no deleting at all.
 *
 * Deleting is best-effort: one archive's delete failure is swallowed and the
 * archive is left in place, so a single unreachable object never blocks the
 * rest of the prune.
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
	 * Construct a destination-retention pruner.
	 *
	 * @param DestinationAdapter $adapter The destination to prune.
	 * @param int                $keep    How many archives to keep; below MIN_RETENTION keeps all.
	 */
	public function __construct( DestinationAdapter $adapter, int $keep ) {
		$this->adapter = $adapter;
		$this->keep    = $keep;
	}

	/**
	 * Delete the oldest surplus archives, keeping the newest $keep.
	 *
	 * @return array<int, string> The remote basenames actually deleted, oldest first; empty when nothing was pruned.
	 * @throws DestinationException If listing the destination fails. An individual delete failure is swallowed instead (best-effort).
	 */
	public function prune(): array {
		if ( $this->keep < self::MIN_RETENTION ) {
			return array();
		}

		$objects = $this->adapter->list();

		usort( $objects, static fn ( RemoteObject $a, RemoteObject $b ): int => strcmp( $a->name(), $b->name() ) );

		$surplus = count( $objects ) - $this->keep;
		if ( $surplus <= 0 ) {
			return array();
		}

		$deleted = array();
		foreach ( array_slice( $objects, 0, $surplus ) as $object ) {
			try {
				$this->adapter->delete( $object->name() );
				$deleted[] = $object->name();
			} catch ( DestinationException $e ) {
				// Best-effort: leave this one in place and keep pruning the rest.
				continue;
			}
		}

		return $deleted;
	}
}
