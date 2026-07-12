<?php
/**
 * Pontifex manifest stream — a memory-bounded, countable sequence of EntryPlans.
 *
 * @package Pontifex\Manifest
 */

declare(strict_types=1);

namespace Pontifex\Manifest;

use Countable;
use Generator;
use InvalidArgumentException;
use IteratorAggregate;
use Pontifex\Archive\Writer\EntryPlan;

/**
 * A lazily-built, countable sequence of {@see EntryPlan}s for one export.
 *
 * Replaces the plain EntryPlan[] array that ManifestBuilder::build() used to
 * return. That array held every entry's EntryPlan (and its EntryHeader) in
 * memory at once — on a ~28,000-file site that is ~100 MB of objects before a
 * single byte is written. ManifestStream instead holds only the lightweight
 * scan results (the ScannedEntry and ScannedDbChunk value objects, which carry
 * paths and sizes but never file contents) and builds each EntryPlan on demand
 * as the writer pulls it, letting PHP free each plan before the next is built.
 * Peak memory then no longer grows with the number of entries.
 *
 * It is both:
 *
 *  - Countable — count() and {@see estimated_bytes()} answer in O(1) from totals
 *    computed once at construction, so the export progress total and the
 *    safety-archive disk preflight never have to walk (and so rebuild) the
 *    entries; and
 *  - IteratorAggregate — {@see getIterator()} yields one freshly-built EntryPlan
 *    at a time, so a foreach over the stream is the memory-bounded write path.
 *
 * A bare generator could do neither the counting nor a second iteration, which
 * is why this is a small object rather than a generator function.
 *
 * @implements IteratorAggregate<int, EntryPlan>
 */
final class ManifestStream implements Countable, IteratorAggregate {

	/**
	 * Source items, each realised into an EntryPlan on demand by the factory.
	 *
	 * For the real export path these are ScannedEntry and ScannedDbChunk value
	 * objects; for {@see from_plans()} they are the ready EntryPlans themselves.
	 *
	 * @var array<int, mixed>
	 */
	private array $items;

	/**
	 * Factory that turns one source item into an EntryPlan when it is pulled.
	 *
	 * @var callable(mixed): EntryPlan
	 */
	private $factory;

	/**
	 * Total entry count, computed once at construction.
	 *
	 * @var int
	 */
	private int $count;

	/**
	 * Sum of the entries' estimated original byte sizes, computed once.
	 *
	 * @var int
	 */
	private int $estimated_bytes;

	/**
	 * Construct a stream over source items and the factory that realises them.
	 *
	 * @param array<int, mixed>          $items           Source items in archive order (e.g. ScannedEntry then ScannedDbChunk).
	 * @param callable(mixed): EntryPlan $factory         Builds one EntryPlan from a source item when it is pulled.
	 * @param int                        $estimated_bytes Sum of the entries' estimated original sizes, for the disk preflight.
	 */
	public function __construct( array $items, callable $factory, int $estimated_bytes ) {
		$this->items           = array_values( $items );
		$this->factory         = $factory;
		$this->count           = count( $this->items );
		$this->estimated_bytes = $estimated_bytes;
	}

	/**
	 * Wrap an already-built list of EntryPlans as a stream.
	 *
	 * The convenience path for tests and any caller that already holds built
	 * plans: the factory is the identity, and the estimated byte total is summed
	 * from the plans' headers so it matches the figure the real scan path
	 * produces.
	 *
	 * @param array<int, EntryPlan> $plans The already-built plans, in archive order.
	 * @return self A stream that yields the given plans unchanged.
	 */
	public static function from_plans( array $plans ): self {
		$estimated_bytes = 0;
		foreach ( $plans as $plan ) {
			$estimated_bytes += $plan->header()->estimated_bytes();
		}

		$identity = static function ( $plan ): EntryPlan {
			if ( ! $plan instanceof EntryPlan ) {
				throw new InvalidArgumentException( 'ManifestStream::from_plans(): every item must be an EntryPlan instance.' );
			}
			return $plan;
		};

		return new self( $plans, $identity, $estimated_bytes );
	}

	/**
	 * Return the total number of entries in the stream.
	 *
	 * O(1): the count is computed once at construction, so this never builds an
	 * EntryPlan. Satisfying Countable lets count() drive the export progress
	 * total without materialising the entries.
	 *
	 * @return int The number of entries.
	 */
	public function count(): int {
		return $this->count;
	}

	/**
	 * Return the sum of the entries' estimated original byte sizes.
	 *
	 * O(1): summed once at construction. Used by the safety-archive disk
	 * preflight, which would otherwise have to walk every plan — rebuilding the
	 * whole stream — just to add up sizes. Equals the previous per-plan sum of
	 * EntryHeader::estimated_bytes().
	 *
	 * @return int The estimated total original byte size.
	 */
	public function estimated_bytes(): int {
		return $this->estimated_bytes;
	}

	/**
	 * Yield each EntryPlan in turn, building it only when it is pulled.
	 *
	 * The memory-bounded heart of the stream: each source item is realised into
	 * an EntryPlan exactly when the consumer asks for it, so only one plan is
	 * alive at a time regardless of how many entries the export holds. Iterating
	 * again builds fresh plans, so the stream is reusable.
	 *
	 * @return Generator<int, EntryPlan> The entries, in archive order.
	 */
	public function getIterator(): Generator {
		foreach ( $this->items as $item ) {
			yield ( $this->factory )( $item );
		}
	}
}
