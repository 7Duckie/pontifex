<?php
/**
 * Exception raised when a codec's required PHP extension is not loaded.
 *
 * @package Pontifex\Archive\Codec
 */

declare(strict_types=1);

namespace Pontifex\Archive\Codec;

/**
 * Raised when a codec cannot run because this host lacks the extension it needs.
 *
 * The narrower sibling {@see CodecException} anticipated in its own docblock:
 * every other codec failure means the bytes are malformed, truncated, or
 * otherwise untrustworthy, but this one means the exact opposite. The bytes
 * are fine. This host simply cannot decode them, because an optional PHP
 * extension the archive's codec depends on (ext-zstd, for codec 0x0002) is
 * not loaded here. The identical archive decodes cleanly on a host that has
 * the extension — which is precisely the migrate-to-a-new-server scenario a
 * backup tool exists to serve, so this is a fact about this installation,
 * never evidence against the archive.
 *
 * A caller that catches this specifically (rather than the broader
 * {@see CodecException}) can therefore route it to
 * {@see \Pontifex\Exception\HostCannotComply} instead of
 * {@see \Pontifex\Exception\ArchiveNotTrustworthy} — see
 * {@see \Pontifex\Archive\Reader\EntryReader}'s two decode-failure catches.
 *
 * Thrown from exactly one place today: {@see ZstdCodec}'s own availability
 * guard. Every other `CodecException` a codec raises — malformed input, a
 * read or write failure, a decompression-bomb refusal — is a genuine failure
 * of the bytes themselves or of the stream carrying them, and must stay a
 * plain `CodecException`, not this subtype.
 */
final class CodecUnavailableException extends CodecException {
}
