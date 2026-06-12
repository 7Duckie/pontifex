<?php
/**
 * Pontifex WP-CLI progress bar — drives WP-CLI's native progress indicator.
 *
 * @package Pontifex\Cli
 */

declare(strict_types=1);

namespace Pontifex\Cli;

/**
 * A ProgressReporter backed by WP-CLI's native progress bar.
 *
 * Wraps \WP_CLI\Utils\make_progress_bar(), which returns a live
 * terminal bar in an interactive session and a silent no-op object
 * when output is not a TTY. The bar is created lazily in start() — not
 * in the constructor — so simply constructing this class never touches
 * WP-CLI. That keeps the unit tests, which inject a NullProgressBar
 * instead, well clear of WP-CLI entirely.
 *
 * The empty case lives here: when start() is told the total is zero,
 * it creates no bar at all, so advance() and finish() become harmless
 * no-ops. Callers therefore never have to special-case an empty run.
 */
final class WpCliProgressBar implements ProgressReporter {

	/**
	 * The underlying WP-CLI progress bar, or null when none is active.
	 *
	 * WP-CLI's make_progress_bar() returns a \cli\progress\Bar in a TTY
	 * and a \WP_CLI\NoOp otherwise; both expose tick() and finish(). The
	 * property is null before start(), after finish(), and whenever the
	 * total was zero.
	 *
	 * @var \cli\progress\Bar|\WP_CLI\NoOp|null
	 */
	private \cli\progress\Bar|\WP_CLI\NoOp|null $bar = null;

	/**
	 * Begin a WP-CLI progress bar for a known number of units.
	 *
	 * @param int    $total The total number of entries to be written. Zero or fewer shows no bar.
	 * @param string $label A short label shown to the left of the bar.
	 * @return void
	 */
	public function start( int $total, string $label ): void {
		if ( $total > 0 ) {
			$this->bar = \WP_CLI\Utils\make_progress_bar( $label, $total );
		}
	}

	/**
	 * Advance the bar by one tick, if a bar is active.
	 *
	 * @return void
	 */
	public function advance(): void {
		if ( null !== $this->bar ) {
			$this->bar->tick();
		}
	}

	/**
	 * Finish and clear the bar, if one is active.
	 *
	 * @return void
	 */
	public function finish(): void {
		if ( null !== $this->bar ) {
			$this->bar->finish();
			$this->bar = null;
		}
	}
}
