<?php
/**
 * Contract for Pontifex authenticated-encryption ciphers.
 *
 * @package Pontifex\Archive\Crypto
 */

declare(strict_types=1);

namespace Pontifex\Archive\Crypto;

/**
 * Cipher contract for per-entry authenticated encryption.
 *
 * Pontifex encrypts each archive entry's payload with AES-256-GCM — an
 * AEAD (Authenticated Encryption with Associated Data) cipher, meaning it
 * both encrypts the payload and produces a 16-byte authentication tag that
 * detects any later modification. AES-256-GCM is the only encryption mode
 * the v1 format permits (`ARCHIVE-FORMAT.md` §8, §13.2.2).
 *
 * A Cipher works on whole byte strings, not streams. PHP exposes
 * AES-256-GCM only as a one-shot operation — there is no incremental API
 * as there is for gzip (deflate_add) or zstd (zstd_compress_add) — so the
 * caller hands over the complete payload and receives the complete result.
 * For Pontifex that payload is the already-compressed bytes of a single
 * entry; the archive as a whole still streams entry by entry.
 *
 * The on-disk payload of an encrypted entry is the ciphertext with the
 * 16-byte GCM tag appended (§8.2). Implementations speak that same combined
 * form: {@see Cipher::encrypt()} returns ciphertext-followed-by-tag, and
 * {@see Cipher::decrypt()} expects it.
 *
 * Two implementations exist — {@see SodiumAesGcmCipher} over ext-sodium and
 * {@see OpensslAesGcmCipher} over ext-openssl — and they are byte-for-byte
 * interoperable: a payload encrypted by one decrypts correctly under the
 * other, so an archive written on a host with hardware AES support opens on
 * a host without it. {@see CipherFactory} selects the right one for the
 * current host.
 */
interface Cipher {

	/**
	 * Length of the AES-256 key in bytes (32 = 256 bits).
	 *
	 * @var int
	 */
	public const KEY_SIZE = 32;

	/**
	 * Length of the AES-256-GCM nonce in bytes (12).
	 *
	 * Matches both AES-256-GCM's standard 96-bit nonce and the per-entry
	 * nonce field width in the archive format (`ARCHIVE-FORMAT.md` §6,
	 * mirrored by {@see \Pontifex\Archive\Writer\EntryWriter::NONCE_SIZE}).
	 *
	 * @var int
	 */
	public const NONCE_SIZE = 12;

	/**
	 * Length of the AES-256-GCM authentication tag in bytes (16).
	 *
	 * @var int
	 */
	public const TAG_SIZE = 16;

	/**
	 * Encrypt a payload, returning the ciphertext with the 16-byte GCM tag appended.
	 *
	 * @param string $plaintext The bytes to encrypt (the compressed entry payload); may be empty.
	 * @param string $nonce     The per-entry nonce; must be exactly NONCE_SIZE bytes and must never be reused under the same key.
	 * @param string $aad       Additional authenticated data: bytes authenticated but not encrypted (Pontifex binds the entry header here); may be empty.
	 * @param string $key       The AES-256 key; must be exactly KEY_SIZE bytes.
	 * @return string The ciphertext followed by the 16-byte authentication tag.
	 * @throws CipherException If the key or nonce is the wrong length, the implementation is unavailable on this host, or the underlying cipher fails.
	 */
	public function encrypt( string $plaintext, string $nonce, string $aad, string $key ): string;

	/**
	 * Decrypt a ciphertext-and-tag, verifying the tag, and return the plaintext.
	 *
	 * Decryption fails — raising CipherException — if any byte of the
	 * ciphertext, tag, nonce, AAD, or key differs from what was used to
	 * encrypt. No plaintext is returned on failure.
	 *
	 * @param string $ciphertext_and_tag The ciphertext with the 16-byte GCM tag appended; must be at least TAG_SIZE bytes.
	 * @param string $nonce              The per-entry nonce used to encrypt; must be exactly NONCE_SIZE bytes.
	 * @param string $aad                The additional authenticated data used to encrypt; may be empty.
	 * @param string $key                The AES-256 key; must be exactly KEY_SIZE bytes.
	 * @return string The decrypted plaintext.
	 * @throws CipherException If a size is wrong, the implementation is unavailable, or authentication fails (wrong key/nonce/AAD, or tampered or truncated input).
	 */
	public function decrypt( string $ciphertext_and_tag, string $nonce, string $aad, string $key ): string;
}
