<?php
/**
 * Pontifex Schedule command — manage the periodic backup from the shell.
 *
 * @package Pontifex\Cli
 */

declare(strict_types=1);

namespace Pontifex\Cli;

use InvalidArgumentException;
use WP_CLI;
use Pontifex\Schedule\Schedule;
use Pontifex\Schedule\ScheduleStore;
use Pontifex\WordPress\RealWordPressContext;
use Pontifex\WordPress\WordPressContext;

/**
 * `wp pontifex schedule` — turn the periodic backup on, inspect it, or turn it off.
 *
 * The schedule itself ships in v0.6.0's scheduled-exports slice: a recurring
 * WP-Cron event runs a content-only backup unattended and prunes old scheduled
 * backups down to a retention count. This command is the CLI surface over the
 * same {@see ScheduleStore} the admin screen uses, so both surfaces read and
 * write one option and the store keeps the cron event in step on every save.
 *
 * The hour is interpreted in UTC — the clock WP-Cron timestamps use — not the
 * site's display timezone, and every readout says so.
 *
 * ## OPTIONS
 *
 * <action>
 * : What to do with the schedule.
 * ---
 * options:
 *   - set
 *   - show
 *   - off
 * ---
 *
 * [--frequency=<frequency>]
 * : How often to run the backup (required with `set`).
 * ---
 * options:
 *   - daily
 *   - weekly
 * ---
 *
 * [--hour=<hour>]
 * : The hour of day to run at, 0-23, in UTC (required with `set`).
 *
 * [--retention=<retention>]
 * : How many scheduled backups to keep; older ones are pruned after each
 * success. Minimum 1. Defaults to the stored schedule's retention.
 *
 * ## EXAMPLES
 *
 *     wp pontifex schedule set --frequency=daily --hour=3 --retention=3
 *     wp pontifex schedule show
 *     wp pontifex schedule off
 *
 * @when after_wp_load
 */
final class ScheduleCommand {

	/**
	 * The WordPressContext abstraction the schedule store reads and writes through.
	 *
	 * Injected via the constructor so tests can substitute a mock; WP-CLI
	 * registers the command by class name and passes no arguments, so the
	 * parameter defaults to the real implementation.
	 *
	 * @var WordPressContext
	 */
	private WordPressContext $wordpress_context;

	/**
	 * Construct a ScheduleCommand instance.
	 *
	 * @param WordPressContext|null $wordpress_context Optional. Defaults to a fresh RealWordPressContext.
	 */
	public function __construct( ?WordPressContext $wordpress_context = null ) {
		$this->wordpress_context = $wordpress_context ?? new RealWordPressContext();
	}

	/**
	 * The WP-CLI command entry point.
	 *
	 * Dispatches on the positional action — `set`, `show`, or `off` — and
	 * refuses anything else with the usage line.
	 *
	 * @param array<int, string>         $positional_args  Positional arguments; the first is the action.
	 * @param array<string, string|bool> $associative_args Associative arguments for `set`.
	 * @return void
	 */
	public function __invoke( array $positional_args, array $associative_args ): void {
		$action = isset( $positional_args[0] ) ? (string) $positional_args[0] : '';

		switch ( $action ) {
			case 'set':
				$this->set( $associative_args );
				break;
			case 'show':
				$this->show();
				break;
			case 'off':
				$this->off();
				break;
			default:
				WP_CLI::error( __( 'Unknown action. Usage: wp pontifex schedule <set|show|off>.', 'pontifex' ) );
		}
	}

	/**
	 * Enable the schedule from the given flags and register its cron event.
	 *
	 * `--frequency` and `--hour` are required; `--retention` defaults to the
	 * stored schedule's value so tightening one setting never silently resets
	 * another. Validation failures end with WP_CLI::error before anything is
	 * written.
	 *
	 * @param array<string, string|bool> $associative_args The command's flags.
	 * @return void
	 */
	private function set( array $associative_args ): void {
		if ( ! isset( $associative_args['frequency'] ) || ! is_string( $associative_args['frequency'] ) ) {
			WP_CLI::error( __( 'The --frequency flag is required: --frequency=daily or --frequency=weekly.', 'pontifex' ) );
		}
		if ( ! isset( $associative_args['hour'] ) || ! is_numeric( $associative_args['hour'] ) ) {
			WP_CLI::error( __( 'The --hour flag is required: an hour of day from 0 to 23, in UTC.', 'pontifex' ) );
		}

		$store     = $this->store();
		$stored    = $store->load();
		$retention = $stored->retention();
		if ( isset( $associative_args['retention'] ) ) {
			if ( ! is_numeric( $associative_args['retention'] ) ) {
				WP_CLI::error( __( 'The --retention flag must be a whole number of at least 1.', 'pontifex' ) );
			}
			$retention = (int) $associative_args['retention'];
			if ( $retention < Schedule::MIN_RETENTION ) {
				WP_CLI::error(
					sprintf(
						/* translators: %d: the minimum retention count */
						__( 'Retention must be at least %d: pruning may never delete the last backup.', 'pontifex' ),
						Schedule::MIN_RETENTION
					)
				);
			}
		}

		try {
			$schedule = new Schedule( true, (string) $associative_args['frequency'], (int) $associative_args['hour'], $retention );
		} catch ( InvalidArgumentException $invalid ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WP_CLI::error renders the message to the terminal, not HTML; the message is our own.
			WP_CLI::error( $invalid->getMessage() );
		}

		$now = time();
		$store->save( $schedule, $now );

		WP_CLI::log(
			sprintf(
				/* translators: %s: the schedule description, e.g. "daily at 03:00 UTC, keeping 3" */
				__( 'Scheduled backups: on — %s.', 'pontifex' ),
				self::describe( $schedule )
			)
		);
		WP_CLI::log(
			sprintf(
				/* translators: %s: a UTC timestamp, e.g. "2026-07-14 03:00 UTC" */
				__( 'Next run: %s.', 'pontifex' ),
				self::stamp( ScheduleStore::next_occurrence( $schedule, $now ) )
			)
		);
	}

	/**
	 * Print the stored schedule, its next occurrence, and the cron event's state.
	 *
	 * The store keeps the option and the cron event in step on every save, but
	 * the event lives in WP-Cron's own storage and can be cleared behind our
	 * back (a cron-cleaning plugin, a hand-edit), so the readout checks it
	 * independently and warns on any disagreement rather than assuming.
	 *
	 * @return void
	 */
	private function show(): void {
		$schedule = $this->store()->load();
		$pending  = wp_next_scheduled( ScheduleStore::CRON_HOOK );

		if ( ! $schedule->is_enabled() ) {
			WP_CLI::log( __( 'Scheduled backups: off.', 'pontifex' ) );
			if ( false !== $pending ) {
				WP_CLI::warning( __( 'A scheduled-export cron event is still pending although the schedule is off. Run `wp pontifex schedule off` to clear it.', 'pontifex' ) );
			}
			return;
		}

		WP_CLI::log(
			sprintf(
				/* translators: %s: the schedule description, e.g. "daily at 03:00 UTC, keeping 3" */
				__( 'Scheduled backups: on — %s.', 'pontifex' ),
				self::describe( $schedule )
			)
		);
		WP_CLI::log(
			sprintf(
				/* translators: %s: a UTC timestamp, e.g. "2026-07-14 03:00 UTC" */
				__( 'Next run: %s.', 'pontifex' ),
				self::stamp( ScheduleStore::next_occurrence( $schedule, time() ) )
			)
		);

		if ( false === $pending ) {
			WP_CLI::warning( __( 'No pending WP-Cron event was found for the schedule. Run `wp pontifex schedule set` again to re-register it.', 'pontifex' ) );
		} else {
			WP_CLI::log(
				sprintf(
					/* translators: %s: a UTC timestamp, e.g. "2026-07-14 03:00 UTC" */
					__( 'WP-Cron event: pending at %s.', 'pontifex' ),
					self::stamp( (int) $pending )
				)
			);
		}
	}

	/**
	 * Turn the schedule off, clearing its cron event and keeping its settings.
	 *
	 * The frequency, hour, and retention are preserved so turning the schedule
	 * back on later starts from what the operator had, not from defaults.
	 *
	 * @return void
	 */
	private function off(): void {
		$store  = $this->store();
		$stored = $store->load();

		$store->save( new Schedule( false, $stored->frequency(), $stored->hour(), $stored->retention() ), time() );

		WP_CLI::log( __( 'Scheduled backups turned off. The settings are kept; `wp pontifex schedule set` turns them back on.', 'pontifex' ) );
	}

	/**
	 * The schedule store over this command's context.
	 *
	 * @return ScheduleStore The store.
	 */
	private function store(): ScheduleStore {
		return new ScheduleStore( $this->wordpress_context );
	}

	/**
	 * Describe a schedule in one human-readable clause.
	 *
	 * @param Schedule $schedule The schedule to describe.
	 * @return string E.g. "daily at 03:00 UTC, keeping 3".
	 */
	private static function describe( Schedule $schedule ): string {
		return sprintf( '%s at %02d:00 UTC, keeping %d', $schedule->frequency(), $schedule->hour(), $schedule->retention() );
	}

	/**
	 * Format a Unix timestamp as a UTC readout.
	 *
	 * @param int $timestamp The Unix timestamp.
	 * @return string E.g. "2026-07-14 03:00 UTC".
	 */
	private static function stamp( int $timestamp ): string {
		return gmdate( 'Y-m-d H:i', $timestamp ) . ' UTC';
	}
}
