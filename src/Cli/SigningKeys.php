<?php
/**
 * CLI-side helpers for reading and writing Ed25519 signing key files.
 *
 * @package Pontifex\Cli
 */

declare(strict_types=1);

namespace Pontifex\Cli;

use RuntimeException;
use Pontifex\Archive\Crypto\SigningContext;
use Pontifex\Archive\Crypto\SigningKeypair;

/**
 * Reads and writes Pontifex's Ed25519 key files and builds a signing context.
 *
 * A key file is two lines of text: a human comment naming the key kind and its
 * key id, then the base64 of the raw key. For example:
 *
 *     pontifex ed25519 public key (key id 9f86d0...)
 *     TbVh...base64...=
 *
 * The format is a CLI/tool convenience, not part of the archive format — an
 * archive stores only the key id and signature, never a key. The two halves
 * are kept in separate files so the secret can be locked down (0600) while the
 * public key is freely shareable; nothing distinguishes them structurally, so a
 * file is validated by the length of the key it decodes to.
 *
 * All static; not instantiable. The secret key is sensitive — callers should
 * scrub it once the signing context is built.
 */
final class SigningKeys {

	/**
	 * The leading words shared by every key file's comment line.
	 *
	 * @var string
	 */
	private const COMMENT_PREFIX = 'pontifex ed25519';

	/**
	 * Prevent instantiation; this class exposes only static helpers.
	 */
	private function __construct() {
	}

	/**
	 * Write a keypair to two files: the public key (0644) and the secret key (0600).
	 *
	 * Refuses to overwrite either path if it already exists, so an existing key
	 * is never clobbered. Both paths are checked before either is written.
	 *
	 * @param SigningKeypair $keypair     The keypair to write.
	 * @param string         $secret_path Destination path for the secret key.
	 * @param string         $public_path Destination path for the public key.
	 * @return void
	 * @throws RuntimeException If either path already exists or a write fails.
	 */
	public static function write_keypair( SigningKeypair $keypair, string $secret_path, string $public_path ): void {
		self::refuse_if_exists( $secret_path );
		self::refuse_if_exists( $public_path );

		$key_id_hex = bin2hex( $keypair->key_id() );
		self::write_file( $public_path, self::format_key( 'public', $key_id_hex, $keypair->public_key() ), 0644 );
		self::write_file( $secret_path, self::format_key( 'secret', $key_id_hex, $keypair->secret_key() ), 0600 );
	}

	/**
	 * Read and decode the secret key from a key file.
	 *
	 * @param string $path Path to the secret-key file.
	 * @return string The 64-byte secret key.
	 * @throws RuntimeException If the file cannot be read, is malformed, or does not decode to a secret key.
	 */
	public static function load_secret_key( string $path ): string {
		return self::load_key( $path, SigningKeypair::SECRET_KEY_SIZE, 'secret' );
	}

	/**
	 * Read and decode the public key from a key file.
	 *
	 * @param string $path Path to the public-key file.
	 * @return string The 32-byte public key.
	 * @throws RuntimeException If the file cannot be read, is malformed, or does not decode to a public key.
	 */
	public static function load_public_key( string $path ): string {
		return self::load_key( $path, SigningKeypair::PUBLIC_KEY_SIZE, 'public' );
	}

	/**
	 * Build the writer's signing context from a raw secret key.
	 *
	 * The public key (and key id) are derived from the secret key, so a stored
	 * secret-key file alone is enough to sign.
	 *
	 * @param string $secret_key The raw 64-byte secret key.
	 * @return SigningContext The context the archive writer signs with.
	 * @throws \Pontifex\Archive\Crypto\SignatureException If the secret key is the wrong length or ext-sodium is unavailable.
	 */
	public static function signing_context( string $secret_key ): SigningContext {
		return SigningContext::from_keypair( SigningKeypair::from_secret_key( $secret_key ) );
	}

	/**
	 * Format one key file's two-line text body.
	 *
	 * @param string $kind       'secret' or 'public'.
	 * @param string $key_id_hex The key id, hex-encoded, for the comment line.
	 * @param string $raw        The raw key bytes.
	 * @return string The file body: a comment line, then a base64 line, each newline-terminated.
	 */
	private static function format_key( string $kind, string $key_id_hex, string $raw ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding raw key bytes as text for the key file; not code obfuscation.
		return sprintf( "%s %s key (key id %s)\n%s\n", self::COMMENT_PREFIX, $kind, $key_id_hex, base64_encode( $raw ) );
	}

	/**
	 * Throw if a path already exists, so a key file is never overwritten.
	 *
	 * @param string $path The path to check.
	 * @return void
	 * @throws RuntimeException If the path exists.
	 */
	private static function refuse_if_exists( string $path ): void {
		if ( file_exists( $path ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message naming the file path for diagnostics; surfaced on the CLI, not HTML output.
			throw new RuntimeException( sprintf( 'SigningKeys: refusing to overwrite an existing file: %s', $path ) );
		}
	}

	/**
	 * Write a key file and restrict its permissions.
	 *
	 * @param string $path     Destination path.
	 * @param string $contents The file body.
	 * @param int    $mode     The octal permission mode to apply.
	 * @return void
	 * @throws RuntimeException If the write fails.
	 */
	private static function write_file( string $path, string $contents, int $mode ): void {
		// Create the file exclusively ('xb' = O_CREAT|O_EXCL): this fails if the
		// path already exists or is a symlink, so a pre-placed link cannot redirect
		// the write. The caller (write_keypair) has already refused existing paths.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- Exclusive create of a key file at an operator-chosen path; @ traps the warning that becomes the exception below. WP_Filesystem is not loaded in a WP-CLI command.
		$handle = @fopen( $path, 'xb' );
		if ( false === $handle ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message naming the file path for diagnostics; surfaced on the CLI, not HTML output.
			throw new RuntimeException( sprintf( 'SigningKeys: could not create the key file: %s', $path ) );
		}

		// Tighten the mode BEFORE writing any bytes, so a secret key never exists
		// in a world-readable file even momentarily.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod,WordPress.PHP.NoSilencedErrors.Discouraged -- Tightening key-file permissions before the write; @ traps a chmod warning on filesystems that do not support it.
		@chmod( $path, $mode );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite,WordPress.PHP.NoSilencedErrors.Discouraged -- Writing the key body to the handle opened above.
		$written = @fwrite( $handle, $contents );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the handle opened above.
		fclose( $handle );

		if ( false === $written || strlen( $contents ) !== $written ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message naming the file path for diagnostics; surfaced on the CLI, not HTML output.
			throw new RuntimeException( sprintf( 'SigningKeys: could not write the key file: %s', $path ) );
		}

		self::assert_mode( $path, $mode );
	}

	/**
	 * Verify a key file ended up at the intended mode, failing loudly otherwise.
	 *
	 * A silent chmod failure must not leave a secret key more permissive than
	 * intended. Skipped on Windows, where POSIX modes are not meaningful.
	 *
	 * @param string $path The file to check.
	 * @param int    $mode The mode it must have.
	 * @return void
	 * @throws RuntimeException If the file's mode does not match on a POSIX host.
	 */
	private static function assert_mode( string $path, int $mode ): void {
		if ( '/' !== DIRECTORY_SEPARATOR ) {
			return;
		}
		clearstatcache( true, $path );
		$actual = fileperms( $path );
		if ( false === $actual || ( $actual & 0o777 ) !== $mode ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message naming the file path for diagnostics; surfaced on the CLI, not HTML output.
			throw new RuntimeException( sprintf( 'SigningKeys: could not secure the key file %s to mode %o.', $path, $mode ) );
		}
	}

	/**
	 * Read a key file, decode its base64 line, and check the key length.
	 *
	 * @param string $path          Path to the key file.
	 * @param int    $expected_size The expected raw key length in bytes.
	 * @param string $kind          'secret' or 'public', for error messages.
	 * @return string The raw key bytes.
	 * @throws RuntimeException If the file cannot be read, is malformed, or the key is the wrong length.
	 */
	private static function load_key( string $path, int $expected_size, string $kind ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.PHP.NoSilencedErrors.Discouraged -- Reading an operator-supplied key file; @ traps an unreadable-file warning that becomes the exception below.
		$contents = @file_get_contents( $path );
		if ( false === $contents ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message naming the file path for diagnostics; surfaced on the CLI, not HTML output.
			throw new RuntimeException( sprintf( 'SigningKeys: could not read the %s key file: %s', $kind, $path ) );
		}

		$lines = preg_split( '/\r\n|\r|\n/', trim( $contents ) );
		if ( false === $lines || count( $lines ) < 2 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message naming the file path for diagnostics; surfaced on the CLI, not HTML output.
			throw new RuntimeException( sprintf( 'SigningKeys: malformed %s key file (expected a comment line then a base64 line): %s', $kind, $path ) );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding the key file's base64 line back to raw key bytes; not code obfuscation.
		$raw = base64_decode( trim( $lines[1] ), true );
		if ( false === $raw ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message naming the file path for diagnostics; surfaced on the CLI, not HTML output.
			throw new RuntimeException( sprintf( 'SigningKeys: the %s key file does not contain valid base64: %s', $kind, $path ) );
		}
		if ( strlen( $raw ) !== $expected_size ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message naming the file path for diagnostics; surfaced on the CLI, not HTML output.
			throw new RuntimeException( sprintf( 'SigningKeys: the %s key must be %d bytes, got %d (is this the right key file?): %s', $kind, (int) $expected_size, (int) strlen( $raw ), $path ) );
		}

		return $raw;
	}
}
