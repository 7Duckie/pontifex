<?php
/**
 * Collects the encryption passphrase from the WP-CLI terminal or STDIN.
 *
 * @package Pontifex\Cli
 */

declare(strict_types=1);

namespace Pontifex\Cli;

use RuntimeException;

/**
 * Production {@see PassphraseSource}: a hidden terminal prompt and a STDIN reader.
 *
 * The hidden prompt uses php-cli-tools' `cli\prompt()` with echo disabled (the
 * library WP-CLI bundles), so the typed passphrase is never shown. STDIN input
 * is read one line at a time for the `--passphrase-stdin` path, letting a
 * script pipe a passphrase in without it appearing on the command line.
 * Neither route echoes or stores the passphrase.
 */
final class CliPassphraseSource implements PassphraseSource {

	/**
	 * Read a passphrase as a single line from standard input.
	 *
	 * @return string The passphrase, with any trailing newline removed.
	 * @throws RuntimeException If standard input cannot be read.
	 */
	public function from_stdin(): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets -- Reading one line from the STDIN stream for --passphrase-stdin; WP_Filesystem has no STDIN abstraction.
		$line = fgets( STDIN );
		if ( false === $line ) {
			throw new RuntimeException( 'CliPassphraseSource: could not read a passphrase from standard input.' );
		}
		return rtrim( $line, "\r\n" );
	}

	/**
	 * Prompt the operator for a passphrase with terminal echo disabled.
	 *
	 * Delegates to php-cli-tools' `cli\prompt( $question, $default, $marker, $hide )`
	 * with `$hide = true`, the bundled WP-CLI helper that reads a line without
	 * echoing it.
	 *
	 * @param string $label The prompt label shown to the operator.
	 * @return string The passphrase the operator typed.
	 */
	public function prompt_hidden( string $label ): string {
		return (string) \cli\prompt( $label, false, ': ', true );
	}
}
