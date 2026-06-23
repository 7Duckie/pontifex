<?php
/**
 * Deterministic PassphraseSource for CLI encryption tests.
 *
 * @package Pontifex\Tests\Unit\Cli\Fakes
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Cli\Fakes;

use RuntimeException;
use Pontifex\Cli\PassphraseSource;

/**
 * In-memory {@see PassphraseSource} that returns scripted passphrases.
 *
 * Lets the encryption helpers and the commands' encryption wiring be exercised
 * without a real terminal or a piped STDIN. Hidden prompts are answered from a
 * queue, so a test can script a matching pair or a deliberate mismatch; the
 * STDIN read returns a single fixed line. Nothing is ever echoed.
 */
final class FakePassphraseSource implements PassphraseSource {

	/**
	 * Answers for successive prompt_hidden() calls, dequeued in order.
	 *
	 * @var string[]
	 */
	private array $prompt_answers;

	/**
	 * The line from_stdin() returns.
	 *
	 * @var string
	 */
	private string $stdin_line;

	/**
	 * Construct the fake with scripted prompt answers and a STDIN line.
	 *
	 * @param string[] $prompt_answers Answers dequeued one per prompt_hidden() call.
	 * @param string   $stdin_line     The line from_stdin() returns.
	 */
	public function __construct( array $prompt_answers = array(), string $stdin_line = '' ) {
		$this->prompt_answers = $prompt_answers;
		$this->stdin_line     = $stdin_line;
	}

	/**
	 * Return the scripted STDIN line.
	 *
	 * @return string The passphrase line.
	 */
	public function from_stdin(): string {
		return $this->stdin_line;
	}

	/**
	 * Return the next queued prompt answer.
	 *
	 * @param string $label The prompt label (ignored; retained for the interface).
	 * @return string The next scripted answer.
	 * @throws RuntimeException If the queue is exhausted (the test asked for more prompts than it scripted).
	 */
	public function prompt_hidden( string $label ): string {
		if ( empty( $this->prompt_answers ) ) {
			throw new RuntimeException( 'FakePassphraseSource: no more prompt answers queued.' );
		}
		return (string) array_shift( $this->prompt_answers );
	}
}
