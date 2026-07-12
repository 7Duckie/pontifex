<?php
/**
 * Pontifex admin backup cancellation signal.
 *
 * @package Pontifex\Admin
 */

declare(strict_types=1);

namespace Pontifex\Admin;

use RuntimeException;

/**
 * Thrown from a running backup's progress callback when the operator asked to cancel.
 *
 * Not an error condition: it is the cooperative-cancellation signal the export
 * uses to unwind. {@see BackupController::create()} catches it ahead of the
 * general Throwable handler, removes the partial archive, releases the lock, and
 * reports the backup as cancelled rather than failed.
 */
final class BackupCancelled extends RuntimeException {
}
