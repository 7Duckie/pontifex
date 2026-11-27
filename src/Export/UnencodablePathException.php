<?php
/**
 * Pontifex unencodable-path exception — an export was refused before writing any byte.
 *
 * @package Pontifex\Export
 */

declare(strict_types=1);

namespace Pontifex\Export;

use Pontifex\Exception\PontifexException;

use RuntimeException;

/**
 * Raised when an entry queued for an export has a name, or a symbolic-link
 * target, that is not valid UTF-8 and so cannot be recorded in the archive.
 *
 * Thrown by {@see ExportRunner::export()} and
 * {@see ResumableExportRunner::tick()} before the destination (or the job's
 * temp archive) is ever opened, so nothing is written. A distinct type so the
 * admin Backup screen (see {@see \Pontifex\Admin\BackupController}) and the
 * safety archiver (see {@see \Pontifex\Rollback\SafetyArchiver}) can each
 * catch it ahead of their generic handlers and show the operator this
 * exception's own specific message, which names the offending entries.
 *
 * Deliberately not one of ADR 0022's three kinds. HostCannotComply would make
 * the CLI print "The problem is this server — usually disk space, memory, or
 * permissions", which is the wrong remedy: the site holds a name the format
 * cannot record, and no change to the host fixes that. InvalidRequest would
 * imply the operator's command was wrong, and it was not.
 * {@see ManifestTooLargeException} set this precedent for the same reason.
 */
final class UnencodablePathException extends RuntimeException implements PontifexException {
}
