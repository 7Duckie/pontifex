<?php
/**
 * The request itself was not valid.
 *
 * @package Pontifex\Exception
 */

declare(strict_types=1);

namespace Pontifex\Exception;

use InvalidArgumentException;

/**
 * Raised when the invocation is wrong, rather than the archive or the host.
 *
 * A path that is not absolute, two mutually exclusive flags, a retention below
 * the floor, an entry path that is not a bare filename. Nothing is damaged and
 * nothing is missing; the caller asked for something that does not make sense,
 * and correcting the request is the whole remedy.
 *
 * Extends InvalidArgumentException — what these cases already mean and already
 * throw — so existing catches keep working and the SPL parent still says the
 * right thing to anyone reading the signature.
 */
final class InvalidRequest extends InvalidArgumentException implements PontifexException {
}
