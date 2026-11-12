<?php
/**
 * Unit tests for CliPassphraseSource's hidden prompt and STDIN reader.
 *
 * @package Pontifex\Tests\Unit\Cli
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Cli;

use Brain\Monkey\Functions;
use RuntimeException;
use Pontifex\Cli\CliPassphraseSource;
use Pontifex\Tests\TestCase;

/**
 * Behavioural coverage of {@see CliPassphraseSource}, the production
 * {@see \Pontifex\Cli\PassphraseSource} that reads the operator's real
 * encryption passphrase.
 *
 * `php-cli-tools` is not installed in this suite (only a PHPStan stub ships;
 * see stubs/cli-prompt.stub), so `\cli\prompt()` does not exist at test time.
 * brain/monkey DEFINES it, which lets `prompt_hidden()` be exercised for
 * real rather than skipped. `from_stdin()` calls the unqualified `fgets()`
 * from inside the `Pontifex\Cli` namespace, which PHP resolves to
 * `Pontifex\Cli\fgets()` first if such a function exists — brain/monkey
 * defines that shim too. `CliPassphraseSource` is the only class in that
 * namespace calling `fgets()`, so the shim cannot bleed into any other
 * class under test.
 */
final class CliPassphraseSourceTest extends TestCase {

	/**
	 * `prompt_hidden()` calls `\cli\prompt()` with echo disabled.
	 *
	 * The fourth argument, `true`, is the ENTIRE security property of this
	 * method: it tells php-cli-tools not to echo the typed characters back to
	 * the terminal (or into shell scrollback/history capture). Nothing else
	 * in the system observes this — there is no return value or side effect
	 * that differs whether echo is on or off — so a silent regression here
	 * (the argument flipped to `false`, or the argument order changed) would
	 * leak the operator's encryption passphrase in plain sight with every
	 * test in the suite still green except this one.
	 *
	 * @return void
	 */
	public function test_prompt_hidden_disables_terminal_echo(): void {
		Functions\expect( 'cli\prompt' )
			->once()
			->with( 'Passphrase', false, ': ', true )
			->andReturn( 's3cret' );

		$source = new CliPassphraseSource();

		$this->assertSame( 's3cret', $source->prompt_hidden( 'Passphrase' ) );
	}

	/**
	 * `prompt_hidden()` casts `\cli\prompt()`'s return value to a string,
	 * because the underlying library can return `false` on end-of-input.
	 *
	 * @return void
	 */
	public function test_prompt_hidden_casts_a_false_return_to_an_empty_string(): void {
		Functions\expect( 'cli\prompt' )->once()->andReturn( false );

		$source = new CliPassphraseSource();

		$this->assertSame( '', $source->prompt_hidden( 'Passphrase' ) );
	}

	/**
	 * A trailing carriage-return-then-newline (a Windows-edited or
	 * Windows-piped input) is stripped in full, leaving only the passphrase.
	 *
	 * @return void
	 */
	public function test_from_stdin_strips_a_windows_style_trailing_newline(): void {
		Functions\when( 'Pontifex\Cli\fgets' )->justReturn( "s3cret\r\n" );

		$source = new CliPassphraseSource();

		$this->assertSame( 's3cret', $source->from_stdin() );
	}

	/**
	 * A trailing bare newline (a Unix-piped input) is stripped.
	 *
	 * @return void
	 */
	public function test_from_stdin_strips_a_unix_style_trailing_newline(): void {
		Functions\when( 'Pontifex\Cli\fgets' )->justReturn( "s3cret\n" );

		$source = new CliPassphraseSource();

		$this->assertSame( 's3cret', $source->from_stdin() );
	}

	/**
	 * A legitimate trailing space in the passphrase itself survives.
	 *
	 * `from_stdin()` must strip only the line terminator, via `rtrim()` with
	 * an explicit `"\r\n"` character list — never a bare `trim()`, which
	 * would also eat this space and silently change the operator's
	 * passphrase to one they did not type.
	 *
	 * @return void
	 */
	public function test_from_stdin_preserves_a_legitimate_trailing_space(): void {
		Functions\when( 'Pontifex\Cli\fgets' )->justReturn( "pass \n" );

		$source = new CliPassphraseSource();

		$this->assertSame( 'pass ', $source->from_stdin() );
	}

	/**
	 * Spaces and punctuation in the middle of the passphrase are left
	 * completely untouched.
	 *
	 * @return void
	 */
	public function test_from_stdin_leaves_internal_spaces_and_punctuation_untouched(): void {
		Functions\when( 'Pontifex\Cli\fgets' )->justReturn( "correct horse battery staple!\n" );

		$source = new CliPassphraseSource();

		$this->assertSame( 'correct horse battery staple!', $source->from_stdin() );
	}

	/**
	 * A line with no trailing newline at all (the final line of a file
	 * missing its terminator) is returned exactly as read.
	 *
	 * @return void
	 */
	public function test_from_stdin_returns_a_line_with_no_trailing_newline_as_is(): void {
		Functions\when( 'Pontifex\Cli\fgets' )->justReturn( 's3cret' );

		$source = new CliPassphraseSource();

		$this->assertSame( 's3cret', $source->from_stdin() );
	}

	/**
	 * A failed read throws rather than silently becoming an empty passphrase.
	 *
	 * `fgets()` returns `false` on a closed or unreadable stream; if
	 * `from_stdin()` swallowed that into `''`, an operator's typo'd pipe or a
	 * closed STDIN would silently become the empty string used as the
	 * encryption key rather than a loud failure.
	 *
	 * @return void
	 */
	public function test_from_stdin_throws_when_the_read_fails(): void {
		Functions\when( 'Pontifex\Cli\fgets' )->justReturn( false );

		$source = new CliPassphraseSource();

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Could not read a passphrase from standard input.' );

		$source->from_stdin();
	}
}
