<?php
/**
 * The outcome of the restore preflights, as a report rather than a thrown refusal.
 *
 * @package Pontifex\Restore
 */

declare(strict_types=1);

namespace Pontifex\Restore;

use Pontifex\Exception\HostCannotComply;
use Throwable;

/**
 * What the restore preflights found, without deciding what to do about it.
 *
 * A restore wants the preflights to THROW: it is about to overwrite a site, so
 * the first refusal must stop it. Verify and a dry run want the same checks to
 * REPORT: neither writes anything, so both can afford to run every check and
 * describe all of them, which is far more useful than stopping at the first.
 *
 * This value object is the reporting half. It records, per check, whether it
 * ran and what it found, and it keeps the two kinds of finding apart, because
 * they mean completely different things to the person reading them:
 *
 *  - **The archive is not trustworthy** — its recorded scope contradicts its
 *    own manifest, or a symbolic link inside it would land outside the site.
 *    Nothing about this host will change that answer. The archive is the
 *    problem, and no amount of freeing disk space or changing hosts fixes it.
 *  - **This host cannot comply** — not enough free disk space, or symbolic
 *    links cannot be created here. The archive is fine. Free some space, or
 *    restore it somewhere else, and the same file works.
 *
 * Collapsing those two into one "failed" would reproduce the exact confusion
 * ADR 0022 exists to remove — and, worse, would tell somebody their backup is
 * damaged when their disk is merely full.
 *
 * Immutable: built once by {@see RestorePreflight}, read by the surfaces.
 */
final class PreflightReport {

	/**
	 * Findings that make the ARCHIVE untrustworthy, as check name => message.
	 *
	 * @var array<string, string>
	 */
	private array $archive_findings;

	/**
	 * Findings that make THIS HOST unable to restore, as check name => message.
	 *
	 * @var array<string, string>
	 */
	private array $host_findings;

	/**
	 * The checks that were actually run, so a caller can say what it did not check.
	 *
	 * @var array<int, string>
	 */
	private array $checks_run;

	/**
	 * Construct a report from what the preflights found.
	 *
	 * @param array<int, string>    $checks_run       Names of the checks that ran.
	 * @param array<string, string> $archive_findings Check name => message, for findings that condemn the archive.
	 * @param array<string, string> $host_findings    Check name => message, for findings that condemn only this host, right now.
	 */
	public function __construct( array $checks_run, array $archive_findings = array(), array $host_findings = array() ) {
		$this->checks_run       = array_values( $checks_run );
		$this->archive_findings = $archive_findings;
		$this->host_findings    = $host_findings;
	}

	/**
	 * True when nothing at all was found — the archive is sound AND this host could restore it.
	 *
	 * @return bool
	 */
	public function is_clear(): bool {
		return array() === $this->archive_findings && array() === $this->host_findings;
	}

	/**
	 * True when the archive itself is at fault, whatever host it is taken to.
	 *
	 * This is the question a verdict should turn on. A host finding must never
	 * change a verdict: a full disk is not a damaged backup, and telling somebody
	 * it is could talk them out of a backup that was their only copy.
	 *
	 * @return bool
	 */
	public function archive_is_refused(): bool {
		return array() !== $this->archive_findings;
	}

	/**
	 * True when the archive is fine but this host, right now, could not restore it.
	 *
	 * @return bool
	 */
	public function host_cannot_restore(): bool {
		return array() !== $this->host_findings;
	}

	/**
	 * Every finding that condemns the archive, as check name => message.
	 *
	 * @return array<string, string>
	 */
	public function archive_findings(): array {
		return $this->archive_findings;
	}

	/**
	 * Every finding that condemns only this host, as check name => message.
	 *
	 * @return array<string, string>
	 */
	public function host_findings(): array {
		return $this->host_findings;
	}

	/**
	 * The names of the checks that ran.
	 *
	 * @return array<int, string>
	 */
	public function checks_run(): array {
		return $this->checks_run;
	}

	/**
	 * Every message, archive findings first, as one flat list.
	 *
	 * Archive findings lead because they are the ones that cannot be worked
	 * around, so they are what a reader most needs to see first.
	 *
	 * @return array<int, string>
	 */
	public function messages(): array {
		return array_values( array_merge( $this->archive_findings, $this->host_findings ) );
	}

	/**
	 * Sort one caught refusal into the right half of a report under construction.
	 *
	 * The preflights already throw the ADR 0022 kinds, so the exception's own type
	 * decides which half it belongs in and nothing has to re-derive it from the
	 * message text. Anything else — a malformed archive surfacing from the entry
	 * read, say — is treated as an archive finding, which fails closed: an
	 * unrecognised problem reported against the archive is a refusal, whereas one
	 * reported against the host would be dismissed as a local inconvenience.
	 *
	 * @param Throwable $refusal The refusal a preflight threw.
	 * @return bool True when it condemns the host rather than the archive.
	 */
	public static function is_host_finding( Throwable $refusal ): bool {
		return $refusal instanceof HostCannotComply;
	}
}
