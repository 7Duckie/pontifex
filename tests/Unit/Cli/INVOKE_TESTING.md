# Behavioural `__invoke` testing for WP-CLI commands

## Context

Every Pontifex WP-CLI command is a single-method class with an
`__invoke(array $args, array $assoc_args): void` entry point. WP-CLI
dispatches to that method when the user runs the registered command.

Two layers of test coverage live in this codebase for each command:

- **Structural tests** (e.g. `DoctorCommandTest.php`,
  `ExportCommandTest.php`) — assertions about the class shape: it
  exists, it is `final`, it exposes `__invoke`, the signature returns
  `void`. Cheap, fast, no dependencies stubbed.
- **Helper tests** (e.g. `ExportCommand/HelperMethodsTest.php`) —
  behavioural assertions on pure helper methods extracted from the
  command, exercising edge cases without touching WP-CLI or
  WordPress.

This document covers the **third** layer: behavioural `__invoke`
tests. They exercise the orchestration logic inside the `__invoke`
method end-to-end with mocked dependencies, catching bugs that the
structural and helper tests cannot see — wrong call order, missing
argument plumbing, malformed output, mishandled WP-CLI control flow
like `confirm` / `error` / `halt`.

## When to write an `__invoke` test

Write one for every realistic execution path through `__invoke`:

- Happy-path success.
- Each early-exit branch (invalid argument, file not found, user
  declined confirmation, etc.).
- Each failure-handling branch (pipeline exception caught,
  graceful-degradation fallback applied).
- Each output-mode variation if the command supports
  `--format=json` or similar (per branch tested).

Do **not** write `__invoke` tests for thin orchestrators whose
behaviour reduces entirely to "delegate to other classes that are
already tested." `DoctorCommand::__invoke` is the canonical example:
it calls `collect_all_checks()` (tested per-check elsewhere), pipes
through WP-CLI's `Formatter`, and prints a summary whose counting
logic is tested separately. There is no orchestration logic in
`__invoke` worth re-exercising — the structural tests are sufficient.

`ExportCommand::__invoke`, by contrast, has real orchestration: it
validates args, prompts the user, configures multiple collaborators,
wires them together, and reports outcomes. It needs `__invoke` tests.

## The pattern

Behavioural `__invoke` tests extend `Pontifex\Tests\TestCase` (the
brain/monkey + Mockery lifecycle base class). They:

1. Mock every collaborator the command receives through its
   constructor (Environment, WordPressContext, ManifestBuilder, etc.)
   using `Mockery::mock( SomeInterface::class )`.
2. Alias-mock `WP_CLI` to capture its static-method calls:
   `$wp_cli = Mockery::mock( 'alias:WP_CLI' );` followed by
   `shouldReceive(...)` for each method the test path is expected to
   call.
3. Build the command-under-test with the mocked collaborators.
4. Invoke it directly: `$command( $args, $assoc_args );`.
5. Assert on the calls Mockery captured and any side effects.

## Skeleton

```php
<?php
declare(strict_types=1);

namespace Pontifex\Tests\Unit\Cli\ExportCommand;

use Mockery;
use Pontifex\Cli\ExportCommand;
use Pontifex\Environment\Environment;
use Pontifex\Manifest\ManifestBuilder;
use Pontifex\Tests\TestCase;
use Pontifex\WordPress\WordPressContext;

final class InvokeTest extends TestCase {

	public function test_happy_path_exports_with_default_exclusions(): void {
		$environment       = Mockery::mock( Environment::class );
		$wordpress_context = Mockery::mock( WordPressContext::class );
		$manifest_builder  = Mockery::mock( ManifestBuilder::class );

		// Set up expectations on the collaborators here…

		// Capture WP_CLI static-method calls.
		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->atLeast()->once();
		$wp_cli->shouldReceive( 'error' )->never();

		$command = new ExportCommand(
			$environment,
			$wordpress_context,
			$manifest_builder
		);

		$command( [], array( 'output' => '/tmp/site.wpmig', 'yes' => true ) );

		// Assertions on side effects are implicit in Mockery's
		// shouldReceive — Mockery::close() in tearDown will fail the
		// test if an expected call was missing.
	}
}
```

## Mockery and alias mocks: one important pitfall

`Mockery::mock( 'alias:WP_CLI' )` creates a class named `WP_CLI` in
PHP's class table if one does not already exist, and routes its
static methods through Mockery's expectation system. Two consequences:

1. **One alias mock per `WP_CLI` per test, max.** A second
   `Mockery::mock( 'alias:WP_CLI' )` in the same test method
   overrides the first. If a test needs to assert on multiple
   methods, register them all on the same mock instance:

   ```php
   $wp_cli = Mockery::mock( 'alias:WP_CLI' );
   $wp_cli->shouldReceive( 'log' )->with( 'Exporting…' )->once();
   $wp_cli->shouldReceive( 'error' )->never();
   $wp_cli->shouldReceive( 'halt' )->never();
   ```

2. **The alias persists between tests if not cleaned up.**
   `Pontifex\Tests\TestCase::tearDown()` calls `Mockery::close()`
   precisely to release it. Do not bypass the base class for
   `__invoke` tests, or the next test in the run will see a stale
   `WP_CLI` and produce confusing failures.

## What about `WP_CLI::halt()` and `WP_CLI::error()`?

In production these call `exit()` internally. Mockery's stub
replaces them with no-op spies by default, which is what you want
for a test — the assertion is "the command tried to halt" rather
than "PHP actually exited."

If the production code expects `error()` or `halt()` to interrupt
control flow and tests need the command to stop processing after,
have the mock throw a sentinel exception:

```php
$wp_cli->shouldReceive( 'halt' )->andThrow( new \RuntimeException( 'halt' ) );
$this->expectException( \RuntimeException::class );
$this->expectExceptionMessage( 'halt' );

$command( $args, $assoc_args );
```

The test then asserts both the halt was reached and any
preconditions for it. This pattern is verbose but explicit; use it
sparingly.
