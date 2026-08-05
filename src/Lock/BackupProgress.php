<?php
/**
 * The contract between a backup that reports progress and the lock that reads it.
 *
 * @package Pontifex\Lock
 */

declare(strict_types=1);

namespace Pontifex\Lock;

/**
 * Where a running backup publishes progress, and how fresh that has to be.
 *
 * {@see OperationLock} decides whether a backup is genuinely live partly by
 * reading this transient: a job-backed export between cron ticks has released
 * the database-level named lock but is still running, and a synchronous run is
 * live while it is still writing here. That makes these two values a safety
 * predicate — get either wrong and the lock either refuses operations forever
 * or lets a second writer start on a site a backup is halfway through.
 *
 * Both were previously declared independently in each class that touched them:
 * the key in {@see OperationLock} and again in the admin backup controller, the
 * staleness floor in three separate places. Nothing tied the copies together
 * and no test asserted they matched, so renaming the transient on the writing
 * side would have left the lock reading a key nobody writes — a lock that
 * silently stops seeing live backups, which is the failure it exists to
 * prevent. OperationLock's own docblock conceded the coupling in prose.
 *
 * One master, referenced by every party. This class deliberately has no
 * behaviour: it is the shared fact, nothing more.
 */
final class BackupProgress {

	/**
	 * The transient a running backup writes its progress into.
	 *
	 * Read by {@see OperationLock::backup_is_live()}, written by the admin
	 * backup controller. Renaming it here changes both together, which is the
	 * point.
	 *
	 * @var string
	 */
	public const TRANSIENT_KEY = 'pontifex_backup_progress';

	/**
	 * How many seconds a progress write stays trustworthy.
	 *
	 * Beyond this the reporter is treated as dead rather than merely quiet. Ten
	 * seconds is comfortably longer than the interval between writes during any
	 * real operation, and short enough that an abandoned run does not hold the
	 * lock for meaningfully longer than it must.
	 *
	 * @var int
	 */
	public const STALE_SECONDS = 10;
}
