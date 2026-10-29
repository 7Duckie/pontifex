<?php
/**
 * What the last restore or rollback did, read back from the durable transfer history.
 *
 * @package Pontifex\Admin
 */

declare(strict_types=1);

namespace Pontifex\Admin;

use Pontifex\Cli\TransferHistory;
use Pontifex\WordPress\WordPressContext;

/**
 * The recorded outcome of the most recent restore or rollback, and the one sentence that describes it.
 *
 * A restore replaces the users and sessions tables, so the browser that started
 * it is signed out before the request composing the success message can deliver
 * it, and every message the Restore screen builds is unreadable on a same-site
 * restore. So the outcome is written down where nothing that happens during the
 * operation can destroy it — {@see TransferHistory}, recorded after the cache
 * is flushed on success and after recovery has finished on failure — and read
 * back from there. This class is the ONE place that knows how to read that
 * record and how to describe it, so the screens that show it cannot drift
 * apart; a codebase that once carried six copies of one helper does not need a
 * seventh opportunity.
 *
 * An import and a rollback are read as one stream because, to the operator,
 * they answer the same question: what was the last thing that rewrote my site?
 * An export is not part of it — it rewrites nothing.
 *
 * Everything here is tolerant of an old or malformed record, exactly as
 * {@see TransferHistory::recent()} is: a missing or non-array `details`, a
 * missing key or a value of the wrong type degrades to zero, false or an empty
 * string rather than raising. Entries written before `details` existed carry
 * none, and read as an outcome with nothing else to say.
 *
 * The OPERATION_*, RECOVERY_* and GATE_* constants below are aliases. The stored
 * vocabulary is defined once, on {@see TransferHistory}, which owns the stored
 * format and which every writer and reader already depends on; they are re-exposed
 * here only so this reader reads naturally. Each writer maps its own private names
 * (the controller's `RECOVERY_*` and `GATE_*`, the commands' `RECOVERY_*`) onto the
 * TransferHistory constants when it records. Do not "deduplicate" those private
 * sets by making them public — the record would then be coupled to an internal of
 * a controller or a command, and a rename there would silently change what is stored.
 */
final class RestoreOutcome {

	/**
	 * The history operation recorded for a restore. An alias of {@see TransferHistory::OPERATION_IMPORT}.
	 *
	 * @var string
	 */
	public const OPERATION_IMPORT = TransferHistory::OPERATION_IMPORT;

	/**
	 * The history operation recorded for a rollback. An alias of {@see TransferHistory::OPERATION_ROLLBACK}.
	 *
	 * @var string
	 */
	public const OPERATION_ROLLBACK = TransferHistory::OPERATION_ROLLBACK;

	/**
	 * Recovery label: no recovery ran. An alias of {@see TransferHistory::RECOVERY_NONE}.
	 *
	 * @var string
	 */
	public const RECOVERY_NONE = TransferHistory::RECOVERY_NONE;

	/**
	 * Recovery label: a failed restore was undone exactly. An alias of {@see TransferHistory::RECOVERY_PRECISE}.
	 *
	 * @var string
	 */
	public const RECOVERY_PRECISE = TransferHistory::RECOVERY_PRECISE;

	/**
	 * Recovery label: rolled back, but the file cleanup could not be confirmed. An alias of {@see TransferHistory::RECOVERY_MERGE}.
	 *
	 * @var string
	 */
	public const RECOVERY_MERGE = TransferHistory::RECOVERY_MERGE;

	/**
	 * Recovery label: rolled back and cleaned up, but a symbolic link could not be re-created. An alias of {@see TransferHistory::RECOVERY_MERGE_LINKS}.
	 *
	 * @var string
	 */
	public const RECOVERY_MERGE_LINKS = TransferHistory::RECOVERY_MERGE_LINKS;

	/**
	 * Recovery label: recovery was attempted and did not complete. An alias of {@see TransferHistory::RECOVERY_FAILED}.
	 *
	 * @var string
	 */
	public const RECOVERY_FAILED = TransferHistory::RECOVERY_FAILED;

	/**
	 * Gate label: not stopped at the verify gate. An alias of {@see TransferHistory::GATE_NONE}.
	 *
	 * @var string
	 */
	public const GATE_NONE = TransferHistory::GATE_NONE;

	/**
	 * Gate label: the archive was found damaged. An alias of {@see TransferHistory::GATE_BROKEN}.
	 *
	 * @var string
	 */
	public const GATE_BROKEN = TransferHistory::GATE_BROKEN;

	/**
	 * Gate label: the archive is intact but a restore will not accept it. An alias of {@see TransferHistory::GATE_REFUSED}.
	 *
	 * @var string
	 */
	public const GATE_REFUSED = TransferHistory::GATE_REFUSED;

	/**
	 * Gate label: this host stopped verification before any verdict. An alias of {@see TransferHistory::GATE_COULD_NOT_CHECK}.
	 *
	 * @var string
	 */
	public const GATE_COULD_NOT_CHECK = TransferHistory::GATE_COULD_NOT_CHECK;

	/**
	 * The outcome value a successful operation is recorded with.
	 *
	 * @var string
	 */
	private const OUTCOME_SUCCEEDED = 'succeeded';

	/**
	 * The outcome value a failed operation is recorded with.
	 *
	 * @var string
	 */
	private const OUTCOME_FAILED = 'failed';

	/**
	 * Which operation was recorded: one of the OPERATION_* constants.
	 *
	 * @var string
	 */
	private string $operation;

	/**
	 * Whether it 'succeeded' or 'failed', as recorded.
	 *
	 * @var string
	 */
	private string $outcome;

	/**
	 * When it happened, as the ISO-8601 string that was recorded.
	 *
	 * @var string
	 */
	private string $occurred_at;

	/**
	 * Entries written; zero on failure or when the record carries no count.
	 *
	 * @var int
	 */
	private int $entries;

	/**
	 * Whether a URL rewrite ran.
	 *
	 * @var bool
	 */
	private bool $relinked;

	/**
	 * Database rows the rewrite changed; zero when it did not run.
	 *
	 * @var int
	 */
	private int $rows_rewritten;

	/**
	 * Percent signs put back by the placeholder-marker repair.
	 *
	 * @var int
	 */
	private int $repaired_markers;

	/**
	 * Symbolic links the replay skipped.
	 *
	 * @var int
	 */
	private int $skipped_links;

	/**
	 * Which recovery verdict was recorded: one of the RECOVERY_* constants.
	 *
	 * @var string
	 */
	private string $recovery;

	/**
	 * Which verify-gate verdict stopped the operation: one of the GATE_* constants.
	 *
	 * Set only by a writer that can prove the operation was stopped at the gate,
	 * which runs before anything is written — so a gate label also means nothing was
	 * changed. {@see self::GATE_NONE} means "not known to have been stopped there",
	 * never "something was written".
	 *
	 * @var string
	 */
	private string $gate;

	/**
	 * Build one outcome from already-validated values; see {@see self::from_entry()}.
	 *
	 * @param string $operation        One of the OPERATION_* constants.
	 * @param string $outcome          'succeeded' or 'failed' as recorded.
	 * @param string $occurred_at      The recorded ISO-8601 timestamp.
	 * @param int    $entries          Entries written.
	 * @param bool   $relinked         Whether a URL rewrite ran.
	 * @param int    $rows_rewritten   Rows the rewrite changed.
	 * @param int    $repaired_markers Percent signs put back.
	 * @param int    $skipped_links    Symbolic links skipped.
	 * @param string $recovery         One of the RECOVERY_* constants.
	 * @param string $gate             One of the GATE_* constants.
	 */
	private function __construct(
		string $operation,
		string $outcome,
		string $occurred_at,
		int $entries,
		bool $relinked,
		int $rows_rewritten,
		int $repaired_markers,
		int $skipped_links,
		string $recovery,
		string $gate
	) {
		$this->operation        = $operation;
		$this->outcome          = $outcome;
		$this->occurred_at      = $occurred_at;
		$this->entries          = $entries;
		$this->relinked         = $relinked;
		$this->rows_rewritten   = $rows_rewritten;
		$this->repaired_markers = $repaired_markers;
		$this->skipped_links    = $skipped_links;
		$this->recovery         = $recovery;
		$this->gate             = $gate;
	}

	/**
	 * The newest history entry for an import or a rollback, or null when there is none.
	 *
	 * "Newest" is by position in the history, which is chronological (oldest
	 * first), not by comparing timestamps — the timestamps are only ever read
	 * back for {@see self::is_recent()}. An export is skipped over: it does not
	 * rewrite the site, so it is not what this answers.
	 *
	 * @param WordPressContext $wordpress_context The context the history is read through.
	 * @return self|null The most recent restore or rollback, or null when none has been recorded.
	 */
	public static function latest( WordPressContext $wordpress_context ): ?self {
		foreach ( array_reverse( TransferHistory::recent( $wordpress_context ) ) as $entry ) {
			$operation = $entry['operation'] ?? null;
			if ( self::OPERATION_IMPORT === $operation || self::OPERATION_ROLLBACK === $operation ) {
				return self::from_entry( $operation, $entry );
			}
		}

		return null;
	}

	/**
	 * Which operation this was: one of {@see self::OPERATION_IMPORT} or {@see self::OPERATION_ROLLBACK}.
	 *
	 * @return string
	 */
	public function operation(): string {
		return $this->operation;
	}

	/**
	 * Whether the operation 'succeeded' or 'failed', exactly as recorded.
	 *
	 * @return string
	 */
	public function outcome(): string {
		return $this->outcome;
	}

	/**
	 * When it happened, as the ISO-8601 timestamp that was recorded.
	 *
	 * @return string The recorded string, or '' when the entry carried none.
	 */
	public function occurred_at(): string {
		return $this->occurred_at;
	}

	/**
	 * How many entries were written; zero on a failure.
	 *
	 * @return int
	 */
	public function entries(): int {
		return $this->entries;
	}

	/**
	 * Whether a URL rewrite ran.
	 *
	 * @return bool
	 */
	public function relinked(): bool {
		return $this->relinked;
	}

	/**
	 * How many database rows the rewrite changed; zero when it did not run.
	 *
	 * @return int
	 */
	public function rows_rewritten(): int {
		return $this->rows_rewritten;
	}

	/**
	 * How many percent signs the placeholder-marker repair put back.
	 *
	 * @return int
	 */
	public function repaired_markers(): int {
		return $this->repaired_markers;
	}

	/**
	 * How many symbolic links the replay skipped.
	 *
	 * @return int
	 */
	public function skipped_links(): int {
		return $this->skipped_links;
	}

	/**
	 * The recovery verdict: one of the RECOVERY_* constants, {@see self::RECOVERY_NONE} when no recovery ran.
	 *
	 * @return string
	 */
	public function recovery(): string {
		return $this->recovery;
	}

	/**
	 * Which verify-gate verdict stopped the operation, or {@see self::GATE_NONE}.
	 *
	 * A gate label is recorded only where the writer can PROVE the operation was
	 * stopped at the gate, which precedes every write, so it also means nothing was
	 * changed. {@see self::GATE_NONE} means "not known to have been stopped there",
	 * never "something was written": most failures are recorded without a gate
	 * precisely because they cannot show it.
	 *
	 * @return string One of the GATE_* constants.
	 */
	public function gate(): string {
		return $this->gate;
	}

	/**
	 * Whether this record is no older than the given window.
	 *
	 * "Now" is a parameter, never read from the clock, so the answer is
	 * deterministic and a test needs no clock stub; {@see TransferHistory::record()}
	 * takes its timestamp in for the same reason. A timestamp that cannot be
	 * parsed is NOT recent — a screen should not present a record whose age it
	 * cannot establish as something that has just happened. A timestamp that is
	 * slightly in the future (a clock stepped backwards between the write and the
	 * read) IS recent, because hiding the result of a restore that finished
	 * seconds ago would be the worse error.
	 *
	 * @param int $now_unix       The current time as a Unix timestamp.
	 * @param int $window_seconds How far back, in seconds, still counts as recent.
	 * @return bool
	 */
	public function is_recent( int $now_unix, int $window_seconds ): bool {
		$recorded = strtotime( $this->occurred_at );
		if ( false === $recorded ) {
			return false;
		}

		return ( $now_unix - $recorded ) <= $window_seconds;
	}

	/**
	 * One translated sentence describing what happened.
	 *
	 * Composed here, not in each screen, which is the point of the class. The
	 * pieces are in a fixed order and each is included only when it applies:
	 * the headline for the operation and its outcome (an operation stopped at
	 * the verify gate has a headline per verdict, not the failure one), then the relink,
	 * the repaired percent signs, the skipped links and, last, what recovery did.
	 *
	 * Returns an empty string when the recorded outcome is neither 'succeeded'
	 * nor 'failed'. The plugin only ever writes those two, so anything else is
	 * a damaged record, and a sentence claiming the operation failed (or
	 * worked) on the strength of a value nobody can vouch for would be a verdict
	 * asserted without being checked.
	 *
	 * @return string The sentence, or '' when the record cannot be described.
	 */
	public function summary(): string {
		$summary = $this->headline();
		if ( '' === $summary ) {
			return '';
		}

		if ( $this->relinked ) {
			$summary .= ' ' . sprintf(
				/* translators: %d: the number of database rows the link rewrite changed */
				_n(
					'The backup\'s links were rewritten across %d database row.',
					'The backup\'s links were rewritten across %d database rows.',
					$this->rows_rewritten,
					'pontifex'
				),
				$this->rows_rewritten
			);
		}

		if ( $this->repaired_markers > 0 ) {
			$summary .= ' ' . sprintf(
				/* translators: %d: the number of percent signs put back */
				_n(
					'Repaired %d percent sign that an older version of Pontifex had written as a long marker.',
					'Repaired %d percent signs that an older version of Pontifex had written as a long marker.',
					$this->repaired_markers,
					'pontifex'
				),
				$this->repaired_markers
			);
		}

		if ( $this->skipped_links > 0 ) {
			$summary .= ' ' . sprintf(
				/* translators: %d: the number of symbolic links that were skipped */
				_n(
					'%d symbolic link could not be re-created and was skipped.',
					'%d symbolic links could not be re-created and were skipped.',
					$this->skipped_links,
					'pontifex'
				),
				$this->skipped_links
			);
		}

		if ( self::RECOVERY_PRECISE === $this->recovery ) {
			$summary .= ' ' . __( 'Your site was put back to its state before the restore.', 'pontifex' );
		} elseif ( self::RECOVERY_MERGE === $this->recovery ) {
			$summary .= ' ' . __( 'Your site was merged back rather than put back exactly — check the Pontifex log.', 'pontifex' );
		} elseif ( self::RECOVERY_MERGE_LINKS === $this->recovery ) {
			$summary .= ' ' . __( 'Your site\'s database was put back and the file cleanup completed, but a symbolic link could not be re-created, so your site cannot be confirmed to be exactly as it was.', 'pontifex' );
		} elseif ( self::RECOVERY_FAILED === $this->recovery ) {
			$summary .= ' ' . __( 'Automatic recovery also failed; your site may be partially restored.', 'pontifex' );
		}

		return $summary;
	}

	/**
	 * The opening clause of the summary: the right verb for the operation and its outcome.
	 *
	 * @return string The clause, or '' when the outcome is neither 'succeeded' nor 'failed'.
	 */
	private function headline(): string {
		$is_rollback = self::OPERATION_ROLLBACK === $this->operation;

		if ( self::OUTCOME_SUCCEEDED === $this->outcome ) {
			if ( $is_rollback ) {
				return sprintf(
					/* translators: %d: the number of entries restored from the safety archive */
					_n( 'Rolled back %d entry.', 'Rolled back %d entries.', $this->entries, 'pontifex' ),
					$this->entries
				);
			}
			return sprintf(
				/* translators: %d: the number of entries restored */
				_n( 'Restored %d entry.', 'Restored %d entries.', $this->entries, 'pontifex' ),
				$this->entries
			);
		}

		if ( self::OUTCOME_FAILED === $this->outcome ) {
			// A gate verdict stopped the operation before anything was written, which is not
			// a failure that may have left the site half-changed, and the three verdicts are
			// not interchangeable either: damaged, refused and could-not-check call for three
			// different responses (ADR 0023), so each says its own cause and the reassurance.
			if ( self::GATE_BROKEN === $this->gate ) {
				return $is_rollback
					? __( 'The safety archive is damaged, so nothing was changed.', 'pontifex' )
					: __( 'The backup is damaged, so nothing was changed.', 'pontifex' );
			}
			if ( self::GATE_REFUSED === $this->gate ) {
				return $is_rollback
					? __( 'The safety archive was refused before anything was changed.', 'pontifex' )
					: __( 'The backup was refused before anything was changed.', 'pontifex' );
			}
			if ( self::GATE_COULD_NOT_CHECK === $this->gate ) {
				return $is_rollback
					? __( 'The safety archive could not be checked here, so nothing was changed.', 'pontifex' )
					: __( 'The backup could not be checked here, so nothing was changed.', 'pontifex' );
			}
			return $is_rollback
				? __( 'The rollback failed.', 'pontifex' )
				: __( 'The restore failed.', 'pontifex' );
		}

		return '';
	}

	/**
	 * Read one history entry into an outcome, tolerating anything that is missing or the wrong type.
	 *
	 * @param string               $operation One of the OPERATION_* constants, already established by the caller.
	 * @param array<string, mixed> $entry     The stored history entry.
	 * @return self
	 */
	private static function from_entry( string $operation, array $entry ): self {
		$details = isset( $entry['details'] ) && is_array( $entry['details'] ) ? $entry['details'] : array();

		$recovery = $details['recovery'] ?? self::RECOVERY_NONE;
		if ( ! in_array( $recovery, array( self::RECOVERY_PRECISE, self::RECOVERY_MERGE, self::RECOVERY_MERGE_LINKS, self::RECOVERY_FAILED ), true ) ) {
			$recovery = self::RECOVERY_NONE;
		}

		$gate = $details['gate'] ?? self::GATE_NONE;
		if ( ! in_array( $gate, array( self::GATE_BROKEN, self::GATE_REFUSED, self::GATE_COULD_NOT_CHECK ), true ) ) {
			$gate = self::GATE_NONE;
		}

		return new self(
			$operation,
			isset( $entry['outcome'] ) && is_string( $entry['outcome'] ) ? $entry['outcome'] : '',
			isset( $entry['at'] ) && is_string( $entry['at'] ) ? $entry['at'] : '',
			self::count_from( $details, 'entries' ),
			true === ( $details['relinked'] ?? false ),
			self::count_from( $details, 'rows_rewritten' ),
			self::count_from( $details, 'repaired_markers' ),
			self::count_from( $details, 'skipped_links' ),
			$recovery,
			$gate
		);
	}

	/**
	 * Read one non-negative count from a details array, defaulting to zero.
	 *
	 * @param array<array-key, mixed> $details The recorded details.
	 * @param string                  $key     The key to read.
	 * @return int The count, or 0 when the key is absent, non-numeric or negative.
	 */
	private static function count_from( array $details, string $key ): int {
		if ( ! isset( $details[ $key ] ) || ! is_numeric( $details[ $key ] ) ) {
			return 0;
		}

		return max( 0, (int) $details[ $key ] );
	}
}
