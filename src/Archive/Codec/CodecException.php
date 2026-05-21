<?php
/**
 * Exception type raised by Pontifex codecs.
 *
 * @package Pontifex\Archive\Codec
 */

declare(strict_types=1);

namespace Pontifex\Archive\Codec;

use RuntimeException;

/**
 * Raised when a codec cannot complete an encode or decode operation.
 *
 * Distinguished from a generic RuntimeException so that callers can
 * catch codec-specific failures (e.g. malformed compressed input,
 * mid-stream I/O failure, unreadable input resource) separately from
 * other runtime conditions, and react differently when appropriate
 * (e.g. fall back to a different codec, surface a specific error to
 * the user, abort cleanly without rolling back further).
 *
 * Not marked `final`; specific codecs may introduce subclasses
 * (e.g. MalformedInputCodecException) when their callers benefit from
 * finer-grained distinctions.
 */
class CodecException extends RuntimeException {
}
