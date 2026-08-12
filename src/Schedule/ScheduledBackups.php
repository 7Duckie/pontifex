<?php
/**
 * Pontifex scheduled-backups ledger — which on-disk backups the scheduler itself wrote.
 *
 * @package Pontifex\Schedule
 */

declare(strict_types=1);

namespace Pontifex\Schedule;

use Pontifex\WordPress\WordPressContext;

/**
 * Persists the filenames of backups {@see JobTicker} itself created, in one wp_options row.
 *
 * Retention pruning must remove only backups the schedule wrote — never a
 * hand-made one, whether taken from the admin Backup screen or
 * `wp pontifex export` — and there is no way to tell them apart from the
 * file alone: both use the same generated `pontifex-backup-<UTC>.wpmig`
 * name, and that name must never change (it is load-bearing for
 * {@see \Pontifex\Admin\BackupStore::resolve()}, its naming pattern,
 * uniqueness, sorting and path safety). So the distinction is recorded here
 * instead, the moment a scheduled run finishes.
 *
 * A small, deliberately filesystem-ignorant store: it wraps one option
 * through the injected {@see WordPressContext} seam, the same way
 * {@see ScheduleStore} does, and never touches disk itself. Self-healing
 * against what is really on disk is the caller's job — see
 * {@see self::recorded()} — because the caller (JobTicker, via
 * {@see \Pontifex\Admin\BackupStore::backups()}) already has that listing
 * and this class has no reason to duplicate it.
 *
 * A backup recorded before this ledger existed is not in it, and never will
 * be: nothing seeds the option from what is already on disk, because doing
 * so cannot tell a pre-existing hand-made backup from a pre-existing
 * scheduled one either, and treating every pre-existing hand-made backup as
 * scheduled would delete it. That is the safe direction to fail — an
 * operator sees old backups accumulate once, rather than a fix that deletes
 * something it cannot ask the operator about.
 */
final class ScheduledBackups {

	/**
	 * The wp_options key the recorded filenames live under.
	 *
	 * @var string
	 */
	public const OPTION = 'pontifex_scheduled_backups';

	/**
	 * The WordPressContext seam the option travels through.
	 *
	 * @var WordPressContext
	 */
	private WordPressContext $wordpress_context;

	/**
	 * Construct a ScheduledBackups ledger over the context seam.
	 *
	 * @param WordPressContext $wordpress_context The seam for option reads and writes.
	 */
	public function __construct( WordPressContext $wordpress_context ) {
		$this->wordpress_context = $wordpress_context;
	}

	/**
	 * Record that the scheduler itself created the given backup filename.
	 *
	 * A no-op when the filename is already recorded, so a caller never has to
	 * check first.
	 *
	 * @param string $filename The bare backup filename (no directory component).
	 * @return void
	 */
	public function record( string $filename ): void {
		$stored = $this->stored();
		if ( in_array( $filename, $stored, true ) ) {
			return;
		}
		$stored[] = $filename;
		$this->wordpress_context->save_option( self::OPTION, $stored );
	}

	/**
	 * Return the recorded filenames that are still real backups, self-healing the ledger.
	 *
	 * A recorded name whose file is gone — pruned by an earlier retention run,
	 * or removed by hand — is dropped from what is returned AND from the
	 * stored option, so the ledger cannot grow for ever as backups come and
	 * go. The caller supplies what is really on disk (normally the basenames
	 * of {@see \Pontifex\Admin\BackupStore::backups()}) rather than this class
	 * reading the filesystem itself, so a store that is otherwise pure
	 * wp_options plumbing never needs to know where backups live.
	 *
	 * @param string[] $on_disk_filenames The backup filenames that genuinely exist right now.
	 * @return string[] The recorded filenames confirmed still on disk.
	 */
	public function recorded( array $on_disk_filenames ): array {
		$stored  = $this->stored();
		$present = array_values( array_intersect( $stored, $on_disk_filenames ) );

		if ( count( $present ) !== count( $stored ) ) {
			$this->wordpress_context->save_option( self::OPTION, $present );
		}

		return $present;
	}

	/**
	 * Read the raw stored list, degrading to empty on garbage.
	 *
	 * A stored option survives plugin upgrades and hand-edits, so a malformed
	 * value must not fatal the cron run — it reads as an empty ledger.
	 *
	 * @return string[] The stored filenames.
	 */
	private function stored(): array {
		$stored = $this->wordpress_context->option_value( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		return array_values( array_filter( $stored, 'is_string' ) );
	}
}
