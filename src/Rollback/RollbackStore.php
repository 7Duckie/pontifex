<?php
/**
 * Pontifex rollback store — the on-disk directory of pre-import safety archives.
 *
 * @package Pontifex\Rollback
 */

declare(strict_types=1);

namespace Pontifex\Rollback;

use DateTimeImmutable;
use DateTimeZone;
use Pontifex\Filesystem\ProtectedDirectory;
use RuntimeException;

/**
 * Manages `wp-content/pontifex/rollback/` and the safety archives within it.
 *
 * Implements {@see RollbackStoreInterface}. The policy it enforces is ADR 0005:
 *
 *  - **Location:** a `pontifex/rollback` subdirectory of the WordPress content
 *    directory, created not world-readable (mode 0700) because a safety archive
 *    is a full copy of the database.
 *  - **Naming:** `pre-import-rollback-<UTC>.wpmig`, the time formatted in UTC so
 *    the newest archive is the lexicographically last.
 *  - **Listing / retention:** archives are matched by that name pattern, sorted,
 *    and pruned to the newest N on request.
 *
 * Filesystem work uses PHP's built-ins directly (the Environment seam does not
 * cover directory creation, globbing or deletion); the class is exercised
 * against a real temporary directory in its tests, the same way FileWriter and
 * FileLogger are.
 */
final class RollbackStore implements RollbackStoreInterface {

	/**
	 * Subdirectory, under the content directory, where safety archives live.
	 *
	 * @var string
	 */
	private const SUBDIRECTORY = 'pontifex/rollback';

	/**
	 * Filename prefix shared by every safety archive.
	 *
	 * @var string
	 */
	private const NAME_PREFIX = 'pre-import-rollback-';

	/**
	 * Filename extension shared by every safety archive.
	 *
	 * @var string
	 */
	private const NAME_EXTENSION = '.wpmig';

	/**
	 * Mode the rollback directory is created with: owner-only (rwx------).
	 *
	 * @var int
	 */
	private const DIRECTORY_MODE = 0700;

	/**
	 * Absolute path of the rollback directory.
	 *
	 * @var string
	 */
	private string $directory;

	/**
	 * Construct a store rooted at the given content directory.
	 *
	 * @param string $content_dir Absolute path of the WordPress content directory (WP_CONTENT_DIR).
	 */
	public function __construct( string $content_dir ) {
		$this->directory = rtrim( $content_dir, '/' ) . '/' . self::SUBDIRECTORY;
	}

	/**
	 * Return the absolute path of the rollback directory.
	 *
	 * @return string The absolute directory path.
	 */
	public function directory(): string {
		return $this->directory;
	}

	/**
	 * Create the rollback directory (mode 0700) if it does not already exist.
	 *
	 * @return void
	 * @throws RuntimeException If the directory cannot be created.
	 */
	public function ensure_directory(): void {
		// Create the not-world-readable directory and lock it against direct web
		// access (a safety archive is a full site backup). ProtectedDirectory is
		// best-effort, so the hard guarantee — the directory exists — is asserted
		// here, where the caller expects an exception on failure.
		if ( ! ProtectedDirectory::ensure( $this->directory, self::DIRECTORY_MODE ) ) {
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message only; the path is plugin-derived, not web output.
				sprintf( 'RollbackStore: could not create the rollback directory: %s', $this->directory )
			);
		}
	}

	/**
	 * Return the absolute path a new safety archive should be written to.
	 *
	 * @param DateTimeImmutable $now The moment the archive is being taken.
	 * @return string The absolute archive path.
	 */
	public function next_archive_path( DateTimeImmutable $now ): string {
		$utc = $now->setTimezone( new DateTimeZone( 'UTC' ) );
		return $this->directory . '/' . self::NAME_PREFIX . $utc->format( 'Ymd\THis\Z' ) . self::NAME_EXTENSION;
	}

	/**
	 * Return every safety archive in the directory, oldest first.
	 *
	 * @return array<int, string> Absolute archive paths, oldest to newest.
	 */
	public function archives(): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_glob -- Listing the plugin-owned rollback directory; WP_Filesystem is unavailable in CLI/test contexts.
		$matches = glob( $this->directory . '/' . self::NAME_PREFIX . '*' . self::NAME_EXTENSION );
		if ( false === $matches ) {
			return array();
		}
		sort( $matches );
		return $matches;
	}

	/**
	 * Return the most recent safety archive, or null when there is none.
	 *
	 * @return string|null The newest archive's absolute path, or null.
	 */
	public function most_recent(): ?string {
		$archives = $this->archives();
		if ( array() === $archives ) {
			return null;
		}
		return $archives[ count( $archives ) - 1 ];
	}

	/**
	 * Delete all but the newest $keep safety archives (best-effort).
	 *
	 * @param int $keep How many of the newest archives to retain.
	 * @return void
	 */
	public function prune( int $keep ): void {
		if ( 0 > $keep ) {
			$keep = 0;
		}

		$archives = $this->archives();
		$remove   = count( $archives ) - $keep;
		if ( 0 >= $remove ) {
			return;
		}

		for ( $index = 0; $index < $remove; $index++ ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort retention of a plugin-owned archive; a file that cannot be removed is intentionally left rather than aborting an import.
			@unlink( $archives[ $index ] );
		}
	}
}
