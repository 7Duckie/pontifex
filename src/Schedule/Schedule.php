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
	 * Extra exclusion patterns a scheduled backup applies on top of the defaults.
	 *
	 * Scoped to files, directories, and symlinks only — never a database
	 * table — the same way {@see \Pontifex\Cli\ExportCommand}'s `--exclude`
	 * and `--exclude-file` are. A schedule stored before this distinction
	 * existed had one flat list; that list becomes this one (see
	 * {@see self::from_stored()}), which is the safe direction: a pattern
	 * that was silently excluding a table stops doing so, and that table
	 * returns to the backup.
	 *
	 * @var string[]
	 */
	private array $exclusions;

	/**
	 * Extra table-exclusion patterns a scheduled backup applies on top of the defaults.
	 *
	 * Scoped to bare table names only — never a file, directory, or symlink
	 * — the same way {@see \Pontifex\Cli\ExportCommand}'s `--exclude-table`
	 * is. Added alongside {@see self::$exclusions} so a scheduled backup can
	 * keep the two kinds of pattern apart the same way the CLI does.
	 *
	 * @var string[]
	 */
	private array $table_exclusions;

	/**
	 * Construct a Schedule with every field validated.
	 *
	 * @param bool     $enabled          Whether the schedule is on.
	 * @param string   $frequency        One of the FREQUENCY_* constants.
	 * @param int      $hour             Hour of day, 0–23.
	 * @param int      $retention        How many backups to keep; clamped up to MIN_RETENTION.
	 * @param string[] $exclusions       Extra file-scoped exclusion patterns a scheduled backup applies on top of the curated defaults.
	 * @param string[] $table_exclusions Extra table-scoped exclusion patterns (bare table names) a scheduled backup applies on top of the curated defaults.
	 * @throws InvalidArgumentException If the frequency or hour is out of range.
	 */
	public function __construct( bool $enabled, string $frequency, int $hour, int $retention, array $exclusions = array(), array $table_exclusions = array() ) {
		if ( ! in_array( $frequency, self::ALL_FREQUENCIES, true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $frequency is reported verbatim for diagnostic context; exception path, not HTML output.
			throw new InvalidArgumentException( sprintf( 'Unknown frequency "%s".', $frequency ) );
		}
		if ( $hour < 0 || $hour > 23 ) {
			throw new InvalidArgumentException( sprintf( 'Hour %d must be between 0 and 23.', (int) $hour ) );
		}

		$this->enabled          = $enabled;
		$this->frequency        = $frequency;
		$this->hour             = $hour;
		$this->retention        = max( self::MIN_RETENTION, $retention );
		$this->exclusions       = self::filtered_patterns( $exclusions );
		$this->table_exclusions = self::filtered_patterns( $table_exclusions );
	}

	/**
	 * Filter a raw pattern list down to non-empty strings, in order.
	 *
	 * @param array<mixed> $patterns The raw pattern list.
	 * @return string[] The non-empty string patterns, re-indexed.
	 */
	private static function filtered_patterns( array $patterns ): array {
		return array_values(
			array_filter(
				$patterns,
				static function ( $pattern ): bool {
					return is_string( $pattern ) && '' !== $pattern;
				}
			)
		);
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
	 * Return the extra file-scoped exclusion patterns a scheduled backup applies.
	 *
	 * @return string[] The patterns, on top of the curated defaults.
	 */
	public function exclusions(): array {
		return $this->exclusions;
	}

	/**
	 * Return the extra table-scoped exclusion patterns a scheduled backup applies.
	 *
	 * @return string[] The bare table-name patterns, on top of the curated defaults.
	 */
	public function table_exclusions(): array {
		return $this->table_exclusions;
	}

	/**
	 * Serialise for the options table.
	 *
	 * @return array<string, mixed> A JSON-encodable array.
	 */
	public function to_array(): array {
		return array(
			'enabled'          => $this->enabled,
			'frequency'        => $this->frequency,
			'hour'             => $this->hour,
			'retention'        => $this->retention,
			'exclusions'       => $this->exclusions,
			'table_exclusions' => $this->table_exclusions,
		);
	}

	/**
	 * Rebuild from stored option data, degrading to the disabled default on garbage.
	 *
	 * Stored options survive plugin upgrades and hand-edits, so a malformed
	 * value must not fatal the admin or the cron run — it reads as disabled.
	 * A schedule stored before `table_exclusions` existed has no such key;
	 * it degrades to an empty list rather than failing, and its one flat
	 * `exclusions` list is read as file-scoped patterns — the safe
	 * direction, since a pattern that was silently excluding a table stops
	 * doing so, and that table returns to the backup.
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
				is_numeric( $data['retention'] ?? null ) ? (int) $data['retention'] : 3,
				is_array( $data['exclusions'] ?? null ) ? array_map( 'strval', $data['exclusions'] ) : array(),
				is_array( $data['table_exclusions'] ?? null ) ? array_map( 'strval', $data['table_exclusions'] ) : array()
			);
		} catch ( InvalidArgumentException $e ) {
			return self::disabled();
		}
	}
}
