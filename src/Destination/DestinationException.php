<?php
/**
 * Pontifex destination exception — a transfer to or from an offsite destination failed.
 *
 * @package Pontifex\Destination
 */

declare(strict_types=1);

namespace Pontifex\Destination;

use RuntimeException;

/**
 * Raised when an offsite destination operation cannot be completed.
 *
 * Covers a misconfigured destination (a missing credential environment
 * variable, an unpinned host key), a connection or authentication failure, and
 * a failed upload, download, listing, or delete. The message is safe to show a
 * user — adapters never place a secret in it.
 */
final class DestinationException extends RuntimeException {
}
