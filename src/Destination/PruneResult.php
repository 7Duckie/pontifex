<?php
/**
 * Pontifex prune result — what a retention prune actually did at a destination.
 *
 * @package Pontifex\Destination
 */

declare(strict_types=1);

namespace Pontifex\Destination;

/**
 * Immutable value object describing what {@see DestinationRetention::prune()} did.
 *
 * A bare list of deleted names cannot distinguish "nothing needed pruning"
 * from "every delete was attempted and refused" — both are an empty list.
 * That collapse is what let a destination silently fill up while retention
 * kept reporting success: five archives sat against a keep-count of two, every
 * delete failed, and the caller had no way to tell that apart from the
 * ordinary case where there was simply nothing surplus to remove. This object
 * carries both outcomes separately, so a caller can tell the two apart and
 * report honestly rather than assuming an empty {@see self::deleted()} means
 * nothing went wrong.
 */
final class PruneResult {

	/**
	 * The remote basenames actually deleted, oldest first.
	 *
	 * @var array<int, string>
	 */
	private array $deleted;

	/**
	 * Remote basenames whose delete was attempted and failed, each keyed by the
	 * reason it failed.
	 *
	 * @var array<string, string>
	 */
	private array $failed;

	/**
	 * Construct a prune result.
	 *
	 * @param array<int, string>    $deleted The remote basenames actually deleted, oldest first.
	 * @param array<string, string> $failed  Remote basenames whose delete failed, keyed by the reason.
	 */
	public function __construct( array $deleted, array $failed ) {
		$this->deleted = $deleted;
		$this->failed  = $failed;
	}

	/**
	 * The remote basenames actually deleted, oldest first; empty when none were.
	 *
	 * @return array<int, string>
	 */
	public function deleted(): array {
		return $this->deleted;
	}

	/**
	 * Remote basenames whose delete failed, keyed by the reason; empty when
	 * every attempted delete succeeded (or none was attempted at all).
	 *
	 * @return array<string, string>
	 */
	public function failed(): array {
		return $this->failed;
	}
}
