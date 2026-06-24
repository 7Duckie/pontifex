<?php
/**
 * Per-archive signing inputs bundled for the archive writer.
 *
 * @package Pontifex\Archive\Crypto
 */

declare(strict_types=1);

namespace Pontifex\Archive\Crypto;

use InvalidArgumentException;
use LogicException;

/**
 * Immutable bundle of the inputs a signed archive needs.
 *
 * Signing is driven by three values that travel together: the
 * {@see Ed25519Signer} that performs the signature, the 64-byte secret key it
 * signs with, and the 32-byte key id (the SHA-256 of the public key) written
 * into the signature block so a reader can tell which key the signature claims
 * to be from. The public key itself is not needed at write time — only its key
 * id — so it is not carried here.
 *
 * Mirrors {@see EncryptionContext}: where that carries the cipher into
 * {@see \Pontifex\Archive\Writer\ArchiveWriter::write_archive()}, this carries
 * the signer, so the writer needs no extra constructor dependency.
 * {@see self::from_keypair()} is the usual way to build one. The secret key is
 * sensitive: this object never logs it, and callers should scrub their copy
 * once finished.
 */
final class SigningContext {

	/**
	 * The signer that produces the Ed25519 signature.
	 *
	 * @var Ed25519Signer
	 */
	private Ed25519Signer $signer;

	/**
	 * The 64-byte Ed25519 secret key, or null once wiped.
	 *
	 * @var string|null
	 */
	private ?string $secret_key;

	/**
	 * The 32-byte key id (SHA-256 of the public key) recorded in the signature block.
	 *
	 * @var string
	 */
	private string $key_id;

	/**
	 * Construct a signing context.
	 *
	 * @param Ed25519Signer $signer     The signer to use.
	 * @param string        $secret_key The secret key; must be exactly SigningKeypair::SECRET_KEY_SIZE bytes.
	 * @param string        $key_id     The key id; must be exactly SigningKeypair::KEY_ID_SIZE bytes.
	 * @throws InvalidArgumentException If the secret key or key id is the wrong length.
	 */
	public function __construct( Ed25519Signer $signer, string $secret_key, string $key_id ) {
		if ( SigningKeypair::SECRET_KEY_SIZE !== strlen( $secret_key ) ) {
			throw new InvalidArgumentException(
				sprintf( 'SigningContext: secret key must be exactly %d bytes, got %d.', (int) SigningKeypair::SECRET_KEY_SIZE, (int) strlen( $secret_key ) )
			);
		}
		if ( SigningKeypair::KEY_ID_SIZE !== strlen( $key_id ) ) {
			throw new InvalidArgumentException(
				sprintf( 'SigningContext: key id must be exactly %d bytes, got %d.', (int) SigningKeypair::KEY_ID_SIZE, (int) strlen( $key_id ) )
			);
		}

		$this->signer     = $signer;
		$this->secret_key = $secret_key;
		$this->key_id     = $key_id;
	}

	/**
	 * Wipe the secret key from memory when the context is destroyed.
	 *
	 * Defence-in-depth: the CLI scrubs its own copy of the secret key once this
	 * context holds it, but the copy this object keeps for its lifetime is
	 * zeroed here too so it does not linger until garbage collection.
	 * Best-effort — only when ext-sodium is available.
	 */
	public function __destruct() {
		if ( null !== $this->secret_key && function_exists( 'sodium_memzero' ) ) {
			sodium_memzero( $this->secret_key );
		}
	}

	/**
	 * Build a signing context from a keypair, defaulting the signer.
	 *
	 * @param SigningKeypair     $keypair The keypair whose secret key signs and whose key id is recorded.
	 * @param Ed25519Signer|null $signer Optional signer; a fresh Ed25519Signer is used when null.
	 * @return self A signing context ready for the writer.
	 */
	public static function from_keypair( SigningKeypair $keypair, ?Ed25519Signer $signer = null ): self {
		return new self( $signer ?? new Ed25519Signer(), $keypair->secret_key(), $keypair->key_id() );
	}

	/**
	 * Return the signer.
	 *
	 * @return Ed25519Signer The signer.
	 */
	public function signer(): Ed25519Signer {
		return $this->signer;
	}

	/**
	 * Return the secret key.
	 *
	 * @return string The 64-byte secret key.
	 * @throws LogicException If the secret key has already been wiped from memory.
	 */
	public function secret_key(): string {
		if ( null === $this->secret_key ) {
			throw new LogicException( 'SigningContext: the secret key has been wiped from memory and is no longer available.' );
		}
		return $this->secret_key;
	}

	/**
	 * Return the key id.
	 *
	 * @return string The 32-byte key id.
	 */
	public function key_id(): string {
		return $this->key_id;
	}
}
