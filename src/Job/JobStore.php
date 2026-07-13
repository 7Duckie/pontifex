<?php
/**
 * Pontifex job store — persists jobs in the plugin's protected working directory.
 *
 * @package Pontifex\Job
 */

declare(strict_types=1);

namespace Pontifex\Job;

use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Pontifex\Filesystem\ProtectedDirectory;

/**
 * Filesystem-backed persistence for {@see Job} records.
 *
 * One JSON file per job under `wp-content/pontifex/jobs/`, the same
 * protected-directory posture as the backup store: not world-readable,
 * locked against direct web access. Saves are atomic (sibling temp +
 * rename), so a crash mid-save leaves the previous complete record,
 * never a torn one.
 *
 * The store is the second guard on the single-runner invariant: at most
 * one ACTIVE job exists at a time, enforced at creation. The named lock
 * (PR #90) remains the primary, race-proof guard; this one makes the
 * invariant visible in the persisted state itself, so a ticker or a
 * screen can ask "what is running?" and get at most one answer.
 *
 * Stale handling is two distinct behaviours (ADR 0014): an ACTIVE job
 * whose last save is older than the abandonment threshold is marked
 * failed — its ticker died without a terminal transition — and TERMINAL
 * jobs older than the retention window are deleted along with their
 * progress sidecars.
 */
final class JobStore {

	/**
	 * Subdirectory under wp-content where job records live.
	 *
	 * @var string
	 */
	private const SUBDIRECTORY = 'pontifex/jobs';

	/**
	 * Mode for the jobs directory: owner-only.
	 *
	 * @var int
	 */
	private const DIRECTORY_MODE = 0700;

	/**
	 * Absolute path of the jobs directory.
	 *
	 * @var string
	 */
	private string $directory;

	/**
	 * Construct a JobStore rooted under the given wp-content directory.
	 *
	 * @param string $content_dir Absolute path of the site's wp-content directory.
	 * @throws InvalidArgumentException If $content_dir is empty.
	 */
	public function __construct( string $content_dir ) {
		if ( '' === $content_dir ) {
			throw new InvalidArgumentException( 'JobStore: content_dir must be non-empty.' );
		}
		$this->directory = rtrim( $content_dir, '/' ) . '/' . self::SUBDIRECTORY;
	}

	/**
	 * Return the absolute path of the jobs directory.
	 *
	 * @return string The directory path.
	 */
	public function directory(): string {
		return $this->directory;
	}

	/**
	 * Create the protected jobs directory, failing loudly if it cannot exist.
	 *
	 * @return void
	 * @throws RuntimeException If the directory cannot be created.
	 */
	public function ensure_directory(): void {
		if ( ! ProtectedDirectory::ensure( $this->directory, self::DIRECTORY_MODE ) ) {
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message only; the path is plugin-derived, not web output.
				sprintf( 'JobStore: could not create the jobs directory: %s', $this->directory )
			);
		}
	}

	/**
	 * Create and persist a new pending job, enforcing at most one active job.
	 *
	 * @param string               $kind    One of Job::ALL_KINDS.
	 * @param array<string, mixed> $payload Kind-specific initial state.
	 * @param int                  $now     Unix timestamp of creation.
	 * @return Job The persisted pending job.
	 * @throws RuntimeException If an active job already exists, randomness fails, or the save fails.
	 */
	public function create( string $kind, array $payload, int $now ): Job {
		$this->ensure_directory();

		$active = $this->active_job();
		if ( null !== $active ) {
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The id is 16 hex characters from random_bytes; exception path, not HTML output.
				sprintf( 'JobStore: an active job already exists (%s); one operation runs at a time.', $active->id() )
			);
		}

		try {
			$id = bin2hex( random_bytes( 8 ) );
		} catch ( \Exception $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $e is the underlying randomness-source exception, chained for diagnostics; not HTML output.
			throw new RuntimeException( 'JobStore: could not generate a job id.', 0, $e );
		}

		$job = new Job( $id, $kind, Job::STATUS_PENDING, $payload, $now, $now );
		$this->save( $job );
		return $job;
	}

	/**
	 * Persist a job atomically.
	 *
	 * @param Job $job The job to save.
	 * @return void
	 * @throws RuntimeException If encoding or the filesystem write fails.
	 */
	public function save( Job $job ): void {
		$this->ensure_directory();

		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Plugin-internal persistence encode with JSON_THROW_ON_ERROR; wp_json_encode adds nothing needed and depends on WordPress being loaded.
			$json = json_encode( $job->to_array(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
		} catch ( JsonException $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $e is the underlying encode exception, chained for diagnostics; not HTML output.
			throw new RuntimeException( 'JobStore: could not encode the job for saving.', 0, $e );
		}

		$path = $this->job_path( $job->id() );
		$temp = $path . '.' . uniqid( 'pontifex-', true ) . '.tmp';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents,WordPress.PHP.NoSilencedErrors.Discouraged -- Job persistence in the plugin's protected working directory; WP_Filesystem is unavailable in CLI/cron contexts where this runs.
		if ( false === @file_put_contents( $temp, $json ) ) {
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The path is plugin-derived; exception path, not HTML output.
				sprintf( 'JobStore: could not write the job record: %s', $path )
			);
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename,WordPress.PHP.NoSilencedErrors.Discouraged -- Atomic same-directory move of the completed record; WP_Filesystem is unavailable in CLI/cron contexts.
		if ( ! @rename( $temp, $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort cleanup; its failure must not mask the rename failure.
			@unlink( $temp );
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The path is plugin-derived; exception path, not HTML output.
				sprintf( 'JobStore: could not move the job record into place: %s', $path )
			);
		}
	}

	/**
	 * Load a job by id.
	 *
	 * @param string $id The job id.
	 * @return Job|null The job, or null when no such record exists.
	 * @throws RuntimeException If the record exists but is unreadable or malformed.
	 */
	public function get( string $id ): ?Job {
		if ( 1 !== preg_match( Job::ID_PATTERN, $id ) ) {
			return null;
		}
		$path = $this->job_path( $id );
		if ( ! is_file( $path ) ) {
			return null;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.PHP.NoSilencedErrors.Discouraged -- Reading the plugin's own job record; WP_Filesystem is unavailable in CLI/cron contexts.
		$json = @file_get_contents( $path );
		if ( false === $json ) {
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The path is plugin-derived; exception path, not HTML output.
				sprintf( 'JobStore: could not read the job record: %s', $path )
			);
		}
		try {
			$data = json_decode( $json, true, 32, JSON_THROW_ON_ERROR );
		} catch ( JsonException $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The path is plugin-derived; $e chained for diagnostics; not HTML output.
			throw new RuntimeException( sprintf( 'JobStore: the job record is corrupt: %s', $path ), 0, $e );
		}
		if ( ! is_array( $data ) ) {
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The path is plugin-derived; exception path, not HTML output.
				sprintf( 'JobStore: the job record is not an object: %s', $path )
			);
		}
		return Job::from_array( $data );
	}

	/**
	 * Return the single active job, or null when nothing is live.
	 *
	 * @return Job|null The pending or running job, newest first if several exist (which the create guard prevents).
	 */
	public function active_job(): ?Job {
		$active = array_values(
			array_filter(
				$this->all_jobs(),
				static function ( Job $job ): bool {
					return $job->is_active();
				}
			)
		);
		if ( array() === $active ) {
			return null;
		}
		usort(
			$active,
			static function ( Job $a, Job $b ): int {
				return $b->updated_at() <=> $a->updated_at();
			}
		);
		return $active[0];
	}

	/**
	 * Delete a job record and its progress sidecar.
	 *
	 * @param string $id The job id.
	 * @return void
	 */
	public function delete( string $id ): void {
		if ( 1 !== preg_match( Job::ID_PATTERN, $id ) ) {
			return;
		}
		foreach ( array( $this->job_path( $id ), $this->progress_path( $id ) ) as $path ) {
			if ( is_file( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Removing the plugin's own job records; best-effort by design.
				@unlink( $path );
			}
		}
	}

	/**
	 * Open the append-only progress log for a job.
	 *
	 * @param string $id The job id.
	 * @return JobProgressLog The sidecar log.
	 * @throws InvalidArgumentException If the id is malformed.
	 */
	public function progress_log( string $id ): JobProgressLog {
		if ( 1 !== preg_match( Job::ID_PATTERN, $id ) ) {
			throw new InvalidArgumentException( 'JobStore: malformed job id.' );
		}
		return new JobProgressLog( $this->progress_path( $id ) );
	}

	/**
	 * Fail abandoned active jobs and delete old terminal ones.
	 *
	 * An active job whose last save is older than $abandoned_after died with
	 * its ticker (a fatal, a killed container) — it is marked failed so the
	 * single-active-job guard does not wedge forever. Terminal jobs older
	 * than $retain_terminal_for are deleted with their sidecars.
	 *
	 * @param int $now                 Unix timestamp of the sweep.
	 * @param int $abandoned_after     Seconds of silence after which an active job counts as dead.
	 * @param int $retain_terminal_for Seconds a finished job is kept for inspection before deletion.
	 * @return int How many records were changed or removed.
	 */
	public function cleanup( int $now, int $abandoned_after = 3600, int $retain_terminal_for = 604800 ): int {
		$swept = 0;
		foreach ( $this->all_jobs() as $job ) {
			if ( $job->is_active() && ( $now - $job->updated_at() ) > $abandoned_after ) {
				$job->mark( Job::STATUS_FAILED, $now );
				$this->save( $job );
				++$swept;
				continue;
			}
			if ( ! $job->is_active() && ( $now - $job->updated_at() ) > $retain_terminal_for ) {
				$this->delete( $job->id() );
				++$swept;
			}
		}
		return $swept;
	}

	/**
	 * Load every parseable job record in the store.
	 *
	 * A corrupt record is skipped rather than blocking the sweep or the
	 * active-job lookup — it can only make the store refuse everything
	 * forever, and the record it shadows is unrecoverable anyway.
	 *
	 * @return Job[] Every readable job.
	 */
	private function all_jobs(): array {
		if ( ! is_dir( $this->directory ) ) {
			return array();
		}
		$jobs  = array();
		$paths = glob( $this->directory . '/*.json' );
		foreach ( false === $paths ? array() : $paths as $path ) {
			$id = basename( $path, '.json' );
			try {
				$job = $this->get( $id );
			} catch ( RuntimeException $e ) {
				continue;
			}
			if ( null !== $job ) {
				$jobs[] = $job;
			}
		}
		return $jobs;
	}

	/**
	 * Absolute path of a job's JSON record.
	 *
	 * @param string $id The job id.
	 * @return string The record path.
	 */
	private function job_path( string $id ): string {
		return $this->directory . '/' . $id . '.json';
	}

	/**
	 * Absolute path of a job's progress sidecar.
	 *
	 * @param string $id The job id.
	 * @return string The sidecar path.
	 */
	private function progress_path( string $id ): string {
		return $this->directory . '/' . $id . '.progress.jsonl';
	}
}
