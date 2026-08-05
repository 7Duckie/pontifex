<?php
/**
 * The archive itself cannot be trusted.
 *
 * @package Pontifex\Exception
 */

declare(strict_types=1);

namespace Pontifex\Exception;

use RuntimeException;

/**
 * Raised when the archive is malformed, damaged, tampered with, or self-contradictory.
 *
 * The file is the problem, and the correct response is not to restore it. Some
 * of these are ordinary corruption — a truncated download, a failing disk —
 * and some are deliberate: a manifest that disagrees with its own records, a
 * symlink resolving to `wp-config.php`, SQL that does not match a sanctioned
 * shape. Pontifex does not always know which, and the distinction does not
 * change the advice.
 *
 * Extends RuntimeException so every existing `catch ( RuntimeException )`
 * continues to work: this taxonomy adds information and removes none.
 *
 * Where a refusal could be read as either this or {@see HostCannotComply}, this
 * one wins — an archive wrongly suspected costs a re-download, while a host
 * problem wrongly reported as trustworthy costs a restore that should not have
 * been run (ADR 0022).
 */
final class ArchiveNotTrustworthy extends RuntimeException implements PontifexException {
}
