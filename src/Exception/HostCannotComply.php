<?php
/**
 * This host cannot complete the operation.
 *
 * @package Pontifex\Exception
 */

declare(strict_types=1);

namespace Pontifex\Exception;

use RuntimeException;

/**
 * Raised when the environment, not the archive, is what prevents the work.
 *
 * Not enough disk space, `symlink()` unavailable, too little memory to hold the
 * manifest, a directory that will not accept a write, a database that will not
 * answer. The archive may be perfectly sound, and the problem is usually
 * fixable by whoever runs the server — which is exactly why it should not be
 * reported in the same breath as a corrupt backup.
 *
 * Extends RuntimeException so every existing `catch ( RuntimeException )`
 * continues to work.
 */
final class HostCannotComply extends RuntimeException implements PontifexException {
}
