<?php
/**
 * Exception type raised by Pontifex Ed25519 signing and verification operations.
 *
 * @package Pontifex\Archive\Crypto
 */

declare(strict_types=1);

namespace Pontifex\Archive\Crypto;

use RuntimeException;

/**
 * Raised when an Ed25519 sign, verify, or keypair operation cannot complete.
 *
 * Distinguished from a generic RuntimeException so callers can catch
 * signature-specific failures separately. It signals a structural or
 * environmental problem — ext-sodium missing, a key or signature of the
 * wrong length, or a libsodium primitive error — NOT a signature that simply
 * does not match. A genuine mismatch (wrong key, or bytes altered after
 * signing) is a normal, expected outcome reported as a `false` return from
 * {@see Ed25519Verifier::verify()}, never an exception; the reader surfaces
 * that as an untrusted-or-tampered archive rather than a crash.
 *
 * Not marked `final`, mirroring {@see CipherException} and
 * {@see \Pontifex\Archive\Codec\CodecException}: a caller that benefits from a
 * finer-grained distinction may subclass it.
 */
class SignatureException extends RuntimeException {
}
