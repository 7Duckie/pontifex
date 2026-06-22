<?php
/**
 * Pontifex rollback-store contract — manages the on-disk safety-archive directory.
 *
 * @package Pontifex\Rollback
 */

declare(strict_types=1);

namespace Pontifex\Rollback;

use DateTimeImmutable;
use RuntimeException;

/**
 * Contract for the directory that holds pre-import safety archives.
 *
 * Extracted from {@see RollbackStore} so the CLI and the safety archiver can
 * depend on the behaviour rather than on the final concrete class — the same
 * interface-around-final pattern the rest of the codebase uses. A consumer
 * holds a RollbackStoreInterface; production wires the concrete RollbackStore;
 * unit tests substitute a fake.
 *
 * The store owns three facts about safety archives: where they live
 * (`wp-content/pontifex/rollback/`, per ADR 0005), how they are named
 * (`pre-import-rollback-<UTC>.wpmig`, so the most recent sorts last), and how
 * many are kept (retention is applied by the caller through {@see prune()}).
 */
interface RollbackStoreInterface {

	/**
	 * Return the absolute path of the rollback directory.
	 *
	 * The directory is not guaranteed to exist; call {@see ensure_directory()}
	 * before writing into it.
	 *
	 * @return string The absolute directory path.
	 */
	public function directory(): string;

	/**
	 * Create the rollback directory if it does not already exist.
	 *
	 * Created not world-readable (mode 0700), because a safety archive contains
	 * the entire database (C-ARCHIVE-SENSITIVE, ADR 0005). A no-op when the
	 * directory already exists.
	 *
	 * @return void
	 * @throws RuntimeException If the directory cannot be created.
	 */
	public function ensure_directory(): void;

	/**
	 * Return the absolute path a new safety archive should be written to.
	 *
	 * The name encodes the time in UTC so the most recent archive is the
	 * lexicographically last: `pre-import-rollback-<UTC>.wpmig`.
	 *
	 * @param DateTimeImmutable $now The moment the archive is being taken.
	 * @return string The absolute archive path (the file is not created here).
	 */
	public function next_archive_path( DateTimeImmutable $now ): string;

	/**
	 * Return every safety archive in the directory, oldest first.
	 *
	 * Ordered lexicographically, which — given the UTC naming — is also
	 * chronological. Empty when the directory is absent or holds none.
	 *
	 * @return array<int, string> Absolute archive paths, oldest to newest.
	 */
	public function archives(): array;

	/**
	 * Return the most recent safety archive, or null when there is none.
	 *
	 * @return string|null The newest archive's absolute path, or null.
	 */
	public function most_recent(): ?string;

	/**
	 * Delete all but the newest $keep safety archives.
	 *
	 * Best-effort: a file that cannot be removed is left in place rather than
	 * aborting the caller (retention is housekeeping, not safety). $keep is
	 * clamped to zero or more.
	 *
	 * @param int $keep How many of the newest archives to retain.
	 * @return void
	 */
	public function prune( int $keep ): void;
}
