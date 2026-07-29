<?php
/**
 * Pontifex manifest-too-large exception — an export was refused before writing any byte.
 *
 * @package Pontifex\Export
 */

declare(strict_types=1);

namespace Pontifex\Export;

use RuntimeException;

/**
 * Raised when the entries queued for an export would produce a manifest this
 * installation's own reader will later refuse to open.
 *
 * Thrown by {@see ExportRunner::export()} and
 * {@see ResumableExportRunner::tick()} before the destination (or the job's
 * temp archive) is ever opened — projected from the real entries' paths, so
 * the refusal happens before a single byte of a doomed export is written,
 * rather than after a multi-hour run produces an archive nobody can read
 * back. A distinct type so an admin surface (see
 * {@see \Pontifex\Admin\BackupController}) can catch it ahead of its generic
 * failure handler and show the operator this exception's own specific
 * message, instead of the generic "check the log" sentence every other
 * export failure gets.
 */
final class ManifestTooLargeException extends RuntimeException {
}
