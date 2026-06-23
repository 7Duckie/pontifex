<?php
/**
 * Contract for collecting an encryption passphrase from the operator.
 *
 * @package Pontifex\Cli
 */

declare(strict_types=1);

namespace Pontifex\Cli;

/**
 * Source of the operator's encryption passphrase.
 *
 * Extracted as a seam so the commands can be tested without a real terminal
 * or a piped STDIN: production wires {@see CliPassphraseSource} (a hidden
 * terminal prompt and a STDIN reader); tests inject a fake that returns a
 * fixed passphrase. The passphrase is a secret — implementations must never
 * echo it or write it anywhere.
 */
interface PassphraseSource {

	/**
	 * Read a passphrase as a single line from standard input.
	 *
	 * The path taken for --passphrase-stdin, so a script can pipe a passphrase
	 * in without it ever appearing on the command line.
	 *
	 * @return string The passphrase, with any trailing newline removed.
	 */
	public function from_stdin(): string;

	/**
	 * Prompt the operator for a passphrase with terminal echo disabled.
	 *
	 * @param string $label The prompt label shown to the operator.
	 * @return string The passphrase the operator typed.
	 */
	public function prompt_hidden( string $label ): string;
}
