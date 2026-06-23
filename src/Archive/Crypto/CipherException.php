<?php
/**
 * Exception type raised by Pontifex cipher and key-derivation operations.
 *
 * @package Pontifex\Archive\Crypto
 */

declare(strict_types=1);

namespace Pontifex\Archive\Crypto;

use RuntimeException;

/**
 * Raised when a cipher or key-derivation operation cannot complete.
 *
 * Distinguished from a generic RuntimeException so callers can catch
 * encryption-specific failures separately and react appropriately. The
 * most important case is a failed decryption: AES-256-GCM rejects any
 * ciphertext, authentication tag, nonce, key, or additional-authenticated-
 * data (AAD) that does not match what was used to encrypt. A rejection
 * means the bytes were tampered with, the archive was truncated, or the
 * wrong passphrase was supplied — the reader surfaces that as a clear
 * "wrong passphrase or corrupt archive" message rather than a cryptic
 * primitive-level error.
 *
 * Not marked `final`, mirroring {@see \Pontifex\Archive\Codec\CodecException}:
 * a caller that benefits from a finer-grained distinction may subclass it.
 */
class CipherException extends RuntimeException {
}
