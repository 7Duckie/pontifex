<?php
/**
 * Pontifex schedule store — persists the schedule and keeps WP-Cron in step with it.
 *
 * @package Pontifex\Schedule
 */

declare(strict_types=1);

namespace Pontifex\Schedule;

use Pontifex\WordPress\WordPressContext;

/**
 * Persists the {@see Schedule} in one option and syncs the cron event to it.
 *
 * Saving is the single choke point where the recurring WP-Cron event is
 * cleared and re-registered, so the stored settings and the scheduler can
 * never drift apart: an enabled schedule always has exactly one pending
 * `pontifex_scheduled_export` event at its next occurrence, and a disabled
 * one has none. The option itself travels through the WordPressContext
 * seam; the cron calls are WordPress-boundary functions used directly,
 * the same posture as the admin controllers.
 */
final class ScheduleStore {

	/**
	 * The wp_options key the schedule lives under.
	 *
	 * @var string
	 */
	public const OPTION = 'pontifex_schedule';

	/**
	 * The recurring cron hook a scheduled export fires on.
	 *
	 * @var string
	 */
	public const CRON_HOOK = 'pontifex_scheduled_export';

	/**
	 * The WordPressContext seam options travel through.
	 *
	 * @var WordPressContext
	 */
	private WordPressContext $wordpress_context;

	/**
	 * Construct a ScheduleStore over the context seam.
	 *
	 * @param WordPressContext $wordpress_context The seam for option reads and writes.
	 */
	public function __construct( WordPressContext $wordpress_context ) {
		$this->wordpress_context = $wordpress_context;
	}

	/**
	 * Load the stored schedule, degrading to the disabled default on garbage.
	 *
	 * @return Schedule The stored schedule.
	 */
	public function load(): Schedule {
		return Schedule::from_stored( $this->wordpress_context->option_value( self::OPTION, array() ) );
	}

	/**
	 * Persist a schedule and re-sync the recurring cron event to it.
	 *
	 * @param Schedule $schedule The schedule to store.
	 * @param int      $now      Unix timestamp used to compute the next occurrence.
	 * @return void
	 */
	public function save( Schedule $schedule, int $now ): void {
		$this->wordpress_context->save_option( self::OPTION, $schedule->to_array() );

		wp_clear_scheduled_hook( self::CRON_HOOK );
		if ( $schedule->is_enabled() ) {
			wp_schedule_event( self::next_occurrence( $schedule, $now ), $schedule->frequency(), self::CRON_HOOK );
		}
	}

	/**
	 * Compute the next Unix timestamp the schedule's hour comes around.
	 *
	 * Site-local time is deliberately not consulted: the hour is interpreted
	 * in UTC, which is what WP-Cron timestamps use. The surfaces present it
	 * as such.
	 *
	 * @param Schedule $schedule The schedule.
	 * @param int      $now      The current Unix timestamp.
	 * @return int The next occurrence, strictly in the future.
	 */
	public static function next_occurrence( Schedule $schedule, int $now ): int {
		$today_at_hour = (int) gmmktime( $schedule->hour(), 0, 0, (int) gmdate( 'n', $now ), (int) gmdate( 'j', $now ), (int) gmdate( 'Y', $now ) );
		if ( $today_at_hour > $now ) {
			return $today_at_hour;
		}
		return $today_at_hour + 86400;
	}
}
