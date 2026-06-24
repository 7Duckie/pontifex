<?php
/**
 * CLI-side encryption helpers: passphrase policy and key wiring.
 *
 * @package Pontifex\Cli
 */

declare(strict_types=1);

namespace Pontifex\Cli;

use Exception;
use RuntimeException;
use Pontifex\Archive\Codec\CodecRegistry;
use Pontifex\Archive\Crypto\Argon2idKdf;
use Pontifex\Archive\Crypto\CipherFactory;
use Pontifex\Archive\Crypto\EncryptionContext;
use Pontifex\Archive\Reader\ArchiveReader;
use Pontifex\Archive\Reader\EntryReader;

/**
 * Glue between the CLI commands and the archive encryption layer.
 *
 * Three jobs: collect the operator's passphrase (enforcing the minimum length
 * and, on export, double-entry confirmation); turn a passphrase into the
 * writer's {@see EncryptionContext} (a fresh random salt, an Argon2id-derived
 * key, and the host cipher); and build the reader's keyed {@see EntryReader}
 * from an archive's stored salt. The passphrase and derived key are secrets:
 * this class never logs them, and callers should scrub the passphrase once the
 * key is derived.
 *
 * All static; not instantiable.
 */
final class Encryption {

	/**
	 * Minimum passphrase length in characters (`ARCHIVE-FORMAT.md` §8.4).
	 *
	 * @var int
	 */
	public const MIN_PASSPHRASE_LENGTH = 10;

	/**
	 * Prevent instantiation; this class exposes only static helpers.
	 */
	private function __construct() {
	}

	/**
	 * Collect and validate a passphrase for a new encrypted archive.
	 *
	 * From STDIN for `--passphrase-stdin`, or an interactive hidden prompt
	 * entered twice and confirmed equal. Refuses a passphrase shorter than
	 * MIN_PASSPHRASE_LENGTH.
	 *
	 * @param PassphraseSource $source     Where to read the passphrase from.
	 * @param bool             $from_stdin True to read one line from STDIN; false to prompt.
	 * @return string The validated passphrase.
	 * @throws RuntimeException If the two prompts disagree or the passphrase is too short.
	 */
	public static function collect_for_export( PassphraseSource $source, bool $from_stdin ): string {
		if ( $from_stdin ) {
			$passphrase = $source->from_stdin();
		} else {
			$passphrase = $source->prompt_hidden( 'Passphrase' );
			$confirm    = $source->prompt_hidden( 'Confirm passphrase' );
			try {
				if ( ! hash_equals( $passphrase, $confirm ) ) {
					throw new RuntimeException( 'Encryption: the passphrases did not match.' );
				}
			} finally {
				// Scrub the confirmation copy whether or not it matched.
				if ( function_exists( 'sodium_memzero' ) ) {
					sodium_memzero( $confirm );
				}
			}
		}

		if ( self::character_length( $passphrase ) < self::MIN_PASSPHRASE_LENGTH ) {
			throw new RuntimeException(
				sprintf( 'Encryption: the passphrase must be at least %d characters.', (int) self::MIN_PASSPHRASE_LENGTH )
			);
		}

		return $passphrase;
	}

	/**
	 * Count the characters in a passphrase, preferring multibyte-aware counting.
	 *
	 * The minimum length is specified in characters (`ARCHIVE-FORMAT.md` §8.4), so a
	 * multibyte passphrase is measured by code points where ext-mbstring is
	 * available, falling back to a byte count otherwise.
	 *
	 * @param string $value The passphrase to measure.
	 * @return int The character count, or the byte count when ext-mbstring is absent.
	 */
	private static function character_length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
	}

	/**
	 * Collect a passphrase to read an existing encrypted archive.
	 *
	 * From STDIN for `--passphrase-stdin`, or a single hidden prompt. No minimum
	 * is enforced on read — a wrong passphrase simply fails decryption.
	 *
	 * @param PassphraseSource $source     Where to read the passphrase from.
	 * @param bool             $from_stdin True to read one line from STDIN; false to prompt.
	 * @return string The passphrase.
	 */
	public static function collect_for_import( PassphraseSource $source, bool $from_stdin ): string {
		return $from_stdin ? $source->from_stdin() : $source->prompt_hidden( 'Passphrase' );
	}

	/**
	 * Build the encryption context for a new archive from a passphrase.
	 *
	 * Generates a fresh random salt, derives the key with Argon2id, and selects
	 * the host's AES-256-GCM cipher.
	 *
	 * @param string $passphrase The operator's passphrase.
	 * @return EncryptionContext The cipher, key and salt for the writer.
	 * @throws RuntimeException If the system source of randomness fails.
	 */
	public static function context( string $passphrase ): EncryptionContext {
		try {
			$salt = random_bytes( Argon2idKdf::SALT_SIZE );
		} catch ( Exception $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $e is the underlying randomness-source exception, chained as the previous exception for diagnostics; not HTML output.
			throw new RuntimeException( 'Encryption: could not generate a random salt for the archive.', 0, $e );
		}

		$key    = ( new Argon2idKdf() )->derive( $passphrase, $salt );
		$cipher = ( new CipherFactory() )->for_host();

		return new EncryptionContext( $cipher, $key, $salt );
	}

	/**
	 * Build the EntryReader for an archive: keyed when encrypted, plain otherwise.
	 *
	 * For an unencrypted archive the passphrase is ignored and a plain reader is
	 * returned. For an encrypted one a passphrase is required; the key is derived
	 * from the archive's stored salt.
	 *
	 * @param ArchiveReader $reader     A reader already open on the archive (for its header and footer).
	 * @param CodecRegistry $registry   The codec registry the EntryReader decodes with.
	 * @param string|null   $passphrase The operator's passphrase, or null if none was supplied.
	 * @return EntryReader A reader that can decode the archive's entries.
	 * @throws RuntimeException If the archive is encrypted but no passphrase was supplied.
	 */
	public static function entry_reader( ArchiveReader $reader, CodecRegistry $registry, ?string $passphrase ): EntryReader {
		if ( ! $reader->header()->is_encrypted() ) {
			return new EntryReader( $registry );
		}
		if ( null === $passphrase ) {
			throw new RuntimeException( 'Encryption: the archive is encrypted; a passphrase is required to read it.' );
		}

		$key    = ( new Argon2idKdf() )->derive( $passphrase, $reader->footer()->argon2id_salt() );
		$cipher = ( new CipherFactory() )->for_host();

		return new EntryReader( $registry, $cipher, $key );
	}
}
