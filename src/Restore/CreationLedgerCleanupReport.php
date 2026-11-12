<?php
/**
 * Pontifex creation-ledger cleanup outcome — what a failed restore's recovery removed.
 *
 * @package Pontifex\Restore
 */

declare(strict_types=1);

namespace Pontifex\Restore;

/**
 * Reports what {@see FileWriter::remove_created_paths()} actually did.
 *
 * Built once a failed restore's safety archive has already been replayed, so
 * the caller — ImportCommand's and RestoreController's own recovery handlers —
 * can tell an operator the difference between two things that both look like
 * "the site is back" but are not the same claim:
 *
 *  - A PRECISE revert: every path this run's FileWriter newly created is
 *    accounted for — the creation ledger never hit its cap, and every removal
 *    it attempted succeeded. The file tree is back to what it was before the
 *    failed restore touched it.
 *  - A MERGE: either the ledger stopped recording (a restore that creates more
 *    paths than the ledger's cap allows — a fresh-server restore is the
 *    common case) or at least one removal failed (a permission problem, or
 *    something else now holding the path open). Either way, a file the failed
 *    restore introduced may still be present alongside the recovered original
 *    content, and the honest thing to say is "merged", not "reverted".
 *
 * Immutable: built once by {@see FileWriter::remove_created_paths()}.
 */
final class CreationLedgerCleanupReport {

	/**
	 * Relative paths actually removed.
	 *
	 * @var array<int, string>
	 */
	private array $removed_paths;

	/**
	 * Relative paths this run created but could not remove, best-effort.
	 *
	 * Never thrown over — see {@see FileWriter::remove_created_paths()} for why a
	 * cleanup failure must never mask the import failure that led to it.
	 *
	 * @var array<int, string>
	 */
	private array $failed_paths;

	/**
	 * Whether the creation ledger recorded every path this run created.
	 *
	 * False once the ledger's cap was reached; from that point on a real
	 * creation happened that this cleanup could not have known about, so
	 * "everything not in the ledger is gone" can no longer be claimed.
	 *
	 * @var bool
	 */
	private bool $ledger_was_complete;

	/**
	 * Construct a cleanup report.
	 *
	 * @param array<int, string> $removed_paths       Relative paths actually removed.
	 * @param array<int, string> $failed_paths        Relative paths that could not be removed.
	 * @param bool               $ledger_was_complete Whether the creation ledger recorded every path this run created.
	 */
	public function __construct( array $removed_paths, array $failed_paths, bool $ledger_was_complete ) {
		$this->removed_paths       = array_values( $removed_paths );
		$this->failed_paths        = array_values( $failed_paths );
		$this->ledger_was_complete = $ledger_was_complete;
	}

	/**
	 * Relative paths actually removed.
	 *
	 * @return array<int, string>
	 */
	public function removed_paths(): array {
		return $this->removed_paths;
	}

	/**
	 * Relative paths this run created but could not remove.
	 *
	 * @return array<int, string>
	 */
	public function failed_paths(): array {
		return $this->failed_paths;
	}

	/**
	 * Whether the creation ledger recorded every path this run created.
	 *
	 * @return bool
	 */
	public function ledger_was_complete(): bool {
		return $this->ledger_was_complete;
	}

	/**
	 * Whether the site's file tree can honestly be called a precise revert.
	 *
	 * True only when the ledger never hit its cap AND every removal it
	 * attempted succeeded — see this class's own docblock for what a false
	 * answer means in practice.
	 *
	 * @return bool
	 */
	public function is_precise_revert(): bool {
		return $this->ledger_was_complete && array() === $this->failed_paths;
	}
}
