<?php
/**
 * An Ed25519 signing keypair and the key id derived from its public half.
 *
 * @package Pontifex\Archive\Crypto
 */

declare(strict_types=1);

namespace Pontifex\Archive\Crypto;

use InvalidArgumentException;
use LogicException;
use SodiumException;

/**
 * Immutable Ed25519 keypair: a 32-byte public key and a 64-byte secret key.
 *
 * The secret key signs an archive; the public key (and the key id derived from
 * it) lets anyone who trusts that key verify the signature. The two always
 * travel together when generated, but the verifying side normally holds only
 * the public key — hence {@see self::key_id_of()} is static, so a key id can
 * be computed from a bare public key without a full keypair.
 *
 * The **key id** is the SHA-256 of the public key, stored raw (32 bytes) in
 * the archive's signature block (`ARCHIVE-FORMAT.md` §11, §13.2.2). It is a
 * stable fingerprint that lets a reader tell which key a signature claims to
 * be from before it spends effort verifying.
 *
 * Key sizes are libsodium's Ed25519 constants — public
 * SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES (32), secret
 * SODIUM_CRYPTO_SIGN_SECRETKEYBYTES (64) — pinned here as literals so the
 * value object validates without requiring ext-sodium to be loaded merely to
 * construct one. The secret key is sensitive: this object never logs it, and
 * callers should keep it out of logs and scrub copies when finished.
 */
final class SigningKeypair {

	/**
	 * Length of an Ed25519 public key in bytes (32).
	 *
	 * @var int
	 */
	public const PUBLIC_KEY_SIZE = 32;

	/**
	 * Length of an Ed25519 secret key in bytes (64).
	 *
	 * @var int
	 */
	public const SECRET_KEY_SIZE = 64;

	/**
	 * Length of a key id in bytes (32 = SHA-256 output).
	 *
	 * @var int
	 */
	public const KEY_ID_SIZE = 32;

	/**
	 * The 32-byte Ed25519 public key.
	 *
	 * @var string
	 */
	private string $public_key;

	/**
	 * The 64-byte Ed25519 secret key, or null once wiped.
	 *
	 * @var string|null
	 */
	private ?string $secret_key;

	/**
	 * Construct a keypair from raw public and secret key bytes.
	 *
	 * @param string $public_key The public key; must be exactly PUBLIC_KEY_SIZE bytes.
	 * @param string $secret_key The secret key; must be exactly SECRET_KEY_SIZE bytes.
	 * @throws InvalidArgumentException If either key is the wrong length.
	 */
	public function __construct( string $public_key, string $secret_key ) {
		if ( self::PUBLIC_KEY_SIZE !== strlen( $public_key ) ) {
			throw new InvalidArgumentException(
				sprintf( 'SigningKeypair: public key must be exactly %d bytes, got %d.', (int) self::PUBLIC_KEY_SIZE, (int) strlen( $public_key ) )
			);
		}
		if ( self::SECRET_KEY_SIZE !== strlen( $secret_key ) ) {
			throw new InvalidArgumentException(
				sprintf( 'SigningKeypair: secret key must be exactly %d bytes, got %d.', (int) self::SECRET_KEY_SIZE, (int) strlen( $secret_key ) )
			);
		}

		$this->public_key = $public_key;
		$this->secret_key = $secret_key;
	}

	/**
	 * Wipe the secret key from memory when the keypair is destroyed.
	 *
	 * Defence-in-depth: the secret key this object holds is zeroed when the
	 * keypair goes out of scope so it does not linger until garbage collection.
	 * Best-effort — only when ext-sodium is available. The public key is not
	 * secret and is left as is.
	 */
	public function __destruct() {
		if ( null !== $this->secret_key && function_exists( 'sodium_memzero' ) ) {
			sodium_memzero( $this->secret_key );
		}
	}

	/**
	 * Generate a fresh random Ed25519 keypair.
	 *
	 * @return self A new keypair backed by libsodium's CSPRNG.
	 * @throws SignatureException If ext-sodium is unavailable or keypair generation fails.
	 */
	public static function generate(): self {
		if ( ! function_exists( 'sodium_crypto_sign_keypair' ) ) {
			throw new SignatureException(
				'SigningKeypair: ext-sodium is required to generate an Ed25519 keypair but is not available.'
			);
		}

		try {
			$keypair    = sodium_crypto_sign_keypair();
			$public_key = sodium_crypto_sign_publickey( $keypair );
			$secret_key = sodium_crypto_sign_secretkey( $keypair );
		} catch ( SodiumException $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $e is the underlying libsodium exception, chained as the previous exception for diagnostics; not HTML output.
			throw new SignatureException( 'SigningKeypair: Ed25519 keypair generation failed.', 0, $e );
		}

		return new self( $public_key, $secret_key );
	}

	/**
	 * Reconstruct a keypair from a secret key by deriving its public half.
	 *
	 * An Ed25519 secret key embeds the public key, so the public key (and hence
	 * the key id) can be recovered from the secret key alone. This lets the CLI
	 * sign from just a stored secret-key file, without a separate public-key file.
	 *
	 * @param string $secret_key The secret key; must be exactly SECRET_KEY_SIZE bytes.
	 * @return self A keypair with the derived public key.
	 * @throws SignatureException If ext-sodium is unavailable, the secret key is the wrong length, or derivation fails.
	 */
	public static function from_secret_key( string $secret_key ): self {
		if ( ! function_exists( 'sodium_crypto_sign_publickey_from_secretkey' ) ) {
			throw new SignatureException(
				'SigningKeypair: ext-sodium is required to derive a public key from a secret key but is not available.'
			);
		}
		if ( self::SECRET_KEY_SIZE !== strlen( $secret_key ) ) {
			throw new SignatureException(
				sprintf( 'SigningKeypair: secret key must be exactly %d bytes to derive a public key, got %d.', (int) self::SECRET_KEY_SIZE, (int) strlen( $secret_key ) )
			);
		}

		try {
			$public_key = sodium_crypto_sign_publickey_from_secretkey( $secret_key );
		} catch ( SodiumException $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $e is the underlying libsodium exception, chained as the previous exception for diagnostics; not HTML output.
			throw new SignatureException( 'SigningKeypair: could not derive the public key from the secret key.', 0, $e );
		}

		return new self( $public_key, $secret_key );
	}

	/**
	 * Return the public key.
	 *
	 * @return string The 32-byte public key.
	 */
	public function public_key(): string {
		return $this->public_key;
	}

	/**
	 * Return the secret key.
	 *
	 * @return string The 64-byte secret key.
	 * @throws LogicException If the secret key has already been wiped from memory.
	 */
	public function secret_key(): string {
		if ( null === $this->secret_key ) {
			throw new LogicException( 'SigningKeypair: the secret key has been wiped from memory and is no longer available.' );
		}
		return $this->secret_key;
	}

	/**
	 * Return this keypair's key id: the SHA-256 of its public key.
	 *
	 * @return string The 32-byte raw key id.
	 */
	public function key_id(): string {
		return self::key_id_of( $this->public_key );
	}

	/**
	 * Compute the key id for a bare public key: its SHA-256, raw (32 bytes).
	 *
	 * Static so the verifying side, which holds only a public key, can derive
	 * the same fingerprint the writer stored.
	 *
	 * @param string $public_key The public key; must be exactly PUBLIC_KEY_SIZE bytes.
	 * @return string The 32-byte raw key id.
	 * @throws InvalidArgumentException If the public key is the wrong length.
	 */
	public static function key_id_of( string $public_key ): string {
		if ( self::PUBLIC_KEY_SIZE !== strlen( $public_key ) ) {
			throw new InvalidArgumentException(
				sprintf( 'SigningKeypair: public key must be exactly %d bytes to compute a key id, got %d.', (int) self::PUBLIC_KEY_SIZE, (int) strlen( $public_key ) )
			);
		}

		return hash( 'sha256', $public_key, true );
	}
}
