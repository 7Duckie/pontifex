<?php
/**
 * Pontifex progress-reporter contract — drives a progress indicator during long CLI operations.
 *
 * @package Pontifex\Cli
 */

declare(strict_types=1);

namespace Pontifex\Cli;

/**
 * A minimal contract for reporting progress through a long operation.
 *
 * ExportCommand creates one of these, tells it the total amount of
 * work up front via start(), nudges it forward once per unit of work
 * via advance(), and closes it via finish(). The interface exists so
 * the command can be unit-tested with a silent NullProgressBar while
 * the production WpCliProgressBar drives WP-CLI's native bar.
 *
 * The archive layer never sees this interface — it is handed a plain
 * callable instead — so the layering stays clean: only the CLI layer
 * knows what a progress bar is.
 */
interface ProgressReporter {

	/**
	 * Begin reporting progress towards a known total.
	 *
	 * @param int    $total The total number of units of work expected. Zero or fewer shows nothing.
	 * @param string $label A short human-readable label, e.g. "Writing archive".
	 * @return void
	 */
	public function start( int $total, string $label ): void;

	/**
	 * Advance the reported progress by one unit of work.
	 *
	 * Safe to call before start() or after finish(); implementations
	 * treat such calls as no-ops.
	 *
	 * @return void
	 */
	public function advance(): void;

	/**
	 * Finish reporting and release any underlying indicator.
	 *
	 * @return void
	 */
	public function finish(): void;
}
