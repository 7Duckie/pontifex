<?php
/**
 * Pontifex null progress bar — a silent ProgressReporter for tests and non-interactive use.
 *
 * @package Pontifex\Cli
 */

declare(strict_types=1);

namespace Pontifex\Cli;

/**
 * A ProgressReporter that does nothing at all.
 *
 * Mirrors Psr\Log\NullLogger: a safe, silent default that satisfies
 * the contract without producing any output. Unit tests inject this so
 * ExportCommand never reaches WP-CLI's make_progress_bar(), and any
 * future caller that wants a quiet export can use it too.
 */
final class NullProgressBar implements ProgressReporter {

	/**
	 * Accept the total and label, and show nothing.
	 *
	 * @param int    $total Ignored.
	 * @param string $label Ignored.
	 * @return void
	 */
	public function start( int $total, string $label ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Deliberate no-op reporter; the interface defines these parameters but a silent implementation uses none of them.
		// Intentionally empty: this reporter shows nothing.
	}

	/**
	 * Ignore the advance.
	 *
	 * @return void
	 */
	public function advance(): void {
		// Intentionally empty: this reporter shows nothing.
	}

	/**
	 * Ignore the finish.
	 *
	 * @return void
	 */
	public function finish(): void {
		// Intentionally empty: this reporter shows nothing.
	}
}
