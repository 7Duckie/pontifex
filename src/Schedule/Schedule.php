<?php
/**
 * Pontifex schedule — the validated settings of the periodic backup.
 *
 * @package Pontifex\Schedule
 */

declare(strict_types=1);

namespace Pontifex\Schedule;

use InvalidArgumentException;

/**
 * The periodic-backup settings: whether, how often, when, and how many to keep.
 *
 * A small validated value object so the rest of the schedule layer never
 * handles loose option-array data. Retention has a floor of one for the
 * same reason the safety archive has a floor of two (ADR 0005's lesson):
 * a pruning rule must never be configurable into deleting everything.
 */
final class Schedule {

	/**
	 * Frequency: one backup per day.
	 *
	 * @var string
	 */
	public const FREQUENCY_DAILY = 'daily';

	/**
	 * Frequency: one backup per week.
	 *
	 * @var string
	 */
	public const FREQUENCY_WEEKLY = 'weekly';

	/**
	 * Every frequency this class recognises; the values double as WP-Cron recurrences.
	 *
	 * @var string[]
	 */
	public const ALL_FREQUENCIES = array( self::FREQUENCY_DAILY, self::FREQUENCY_WEEKLY );

	/**
	 * The lowest permitted retention: pruning may never delete the last backup.
	 *
	 * @var int
	 */
	public const MIN_RETENTION = 1;

	/**
	 * Whether the schedule is on.
	 *
	 * @var bool
	 */
	private bool $enabled;

	/**
	 * How often the backup runs; one of the FREQUENCY_* constants.
	 *
	 * @var string
	 */
	private string $frequency;

	/**
	 * The hour of day (0–23, site time) the backup should run at.
	 *
	 * @var int
	 */
	private int $hour;

	/**
	 * How many scheduled backups to keep; older ones are pruned after a success.
	 *
	 * @var int
	 */
	private int $retention;

	/**
	 * Construct a Schedule with every field validated.
	 *
	 * @param bool   $enabled   Whether the schedule is on.
	 * @param string $frequency One of the FREQUENCY_* constants.
	 * @param int    $hour      Hour of day, 0–23.
	 * @param int    $retention How many backups to keep; clamped up to MIN_RETENTION.
	 * @throws InvalidArgumentException If the frequency or hour is out of range.
	 */
	public function __construct( bool $enabled, string $frequency, int $hour, int $retention ) {
		if ( ! in_array( $frequency, self::ALL_FREQUENCIES, true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $frequency is reported verbatim for diagnostic context; exception path, not HTML output.
			throw new InvalidArgumentException( sprintf( 'Schedule: unknown frequency "%s".', $frequency ) );
		}
		if ( $hour < 0 || $hour > 23 ) {
			throw new InvalidArgumentException( sprintf( 'Schedule: hour %d must be between 0 and 23.', (int) $hour ) );
		}

		$this->enabled   = $enabled;
		$this->frequency = $frequency;
		$this->hour      = $hour;
		$this->retention = max( self::MIN_RETENTION, $retention );
	}

	/**
	 * The disabled default: off, daily at 03:00, keep three.
	 *
	 * @return self The default schedule.
	 */
	public static function disabled(): self {
		return new self( false, self::FREQUENCY_DAILY, 3, 3 );
	}

	/**
	 * Whether the schedule is on.
	 *
	 * @return bool True when enabled.
	 */
	public function is_enabled(): bool {
		return $this->enabled;
	}

	/**
	 * Return the frequency.
	 *
	 * @return string One of the FREQUENCY_* constants; doubles as the WP-Cron recurrence name.
	 */
	public function frequency(): string {
		return $this->frequency;
	}

	/**
	 * Return the hour of day the backup runs at.
	 *
	 * @return int The hour, 0–23.
	 */
	public function hour(): int {
		return $this->hour;
	}

	/**
	 * Return how many scheduled backups to keep.
	 *
	 * @return int The retention count, at least MIN_RETENTION.
	 */
	public function retention(): int {
		return $this->retention;
	}

	/**
	 * Serialise for the options table.
	 *
	 * @return array<string, mixed> A JSON-encodable array.
	 */
	public function to_array(): array {
		return array(
			'enabled'   => $this->enabled,
			'frequency' => $this->frequency,
			'hour'      => $this->hour,
			'retention' => $this->retention,
		);
	}

	/**
	 * Rebuild from stored option data, degrading to the disabled default on garbage.
	 *
	 * Stored options survive plugin upgrades and hand-edits, so a malformed
	 * value must not fatal the admin or the cron run — it reads as disabled.
	 *
	 * @param mixed $data The stored option value.
	 * @return self The reconstructed schedule, or the disabled default.
	 */
	public static function from_stored( $data ): self {
		if ( ! is_array( $data ) ) {
			return self::disabled();
		}
		try {
			return new self(
				(bool) ( $data['enabled'] ?? false ),
				is_string( $data['frequency'] ?? null ) ? $data['frequency'] : self::FREQUENCY_DAILY,
				is_numeric( $data['hour'] ?? null ) ? (int) $data['hour'] : 3,
				is_numeric( $data['retention'] ?? null ) ? (int) $data['retention'] : 3
			);
		} catch ( InvalidArgumentException $e ) {
			return self::disabled();
		}
	}
}
