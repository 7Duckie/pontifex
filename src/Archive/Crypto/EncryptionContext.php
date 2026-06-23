<?php
/**
 * Per-archive encryption inputs bundled for the archive writer.
 *
 * @package Pontifex\Archive\Crypto
 */

declare(strict_types=1);

namespace Pontifex\Archive\Crypto;

use InvalidArgumentException;

/**
 * Immutable bundle of the three inputs an encrypted archive needs.
 *
 * Encryption is driven by three values that always travel together: the
 * {@see Cipher} that performs AES-256-GCM, the 32-byte derived key, and the
 * 16-byte per-archive salt. The salt is the public half of key derivation —
 * it is written into the footer in the clear so a reader can re-derive the
 * key from the operator's passphrase; the key is the secret half.
 *
 * {@see \Pontifex\Archive\Writer\ArchiveWriter::write_archive()} takes one of
 * these to produce an encrypted archive, and none (null) to produce an
 * unencrypted one. Callers are responsible for keeping the key out of logs
 * and scrubbing it from memory (e.g. via sodium_memzero()) once finished.
 */
final class EncryptionContext {

	/**
	 * The cipher that performs AES-256-GCM.
	 *
	 * @var Cipher
	 */
	private Cipher $cipher;

	/**
	 * The 32-byte AES-256 key derived from the passphrase.
	 *
	 * @var string
	 */
	private string $key;

	/**
	 * The 16-byte per-archive salt, stored in the footer.
	 *
	 * @var string
	 */
	private string $salt;

	/**
	 * Construct an encryption context.
	 *
	 * @param Cipher $cipher The AES-256-GCM cipher to use.
	 * @param string $key    The derived key; must be exactly Cipher::KEY_SIZE bytes.
	 * @param string $salt   The per-archive salt; must be exactly Argon2idKdf::SALT_SIZE bytes.
	 * @throws InvalidArgumentException If the key or salt is the wrong length.
	 */
	public function __construct( Cipher $cipher, string $key, string $salt ) {
		if ( Cipher::KEY_SIZE !== strlen( $key ) ) {
			throw new InvalidArgumentException(
				sprintf( 'EncryptionContext: key must be exactly %d bytes, got %d.', (int) Cipher::KEY_SIZE, (int) strlen( $key ) )
			);
		}
		if ( Argon2idKdf::SALT_SIZE !== strlen( $salt ) ) {
			throw new InvalidArgumentException(
				sprintf( 'EncryptionContext: salt must be exactly %d bytes, got %d.', (int) Argon2idKdf::SALT_SIZE, (int) strlen( $salt ) )
			);
		}

		$this->cipher = $cipher;
		$this->key    = $key;
		$this->salt   = $salt;
	}

	/**
	 * Return the cipher.
	 *
	 * @return Cipher The AES-256-GCM cipher.
	 */
	public function cipher(): Cipher {
		return $this->cipher;
	}

	/**
	 * Return the derived key.
	 *
	 * @return string The 32-byte key.
	 */
	public function key(): string {
		return $this->key;
	}

	/**
	 * Return the per-archive salt.
	 *
	 * @return string The 16-byte salt.
	 */
	public function salt(): string {
		return $this->salt;
	}
}
