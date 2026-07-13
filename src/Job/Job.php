<?php
/**
 * Pontifex job — the persisted state of one long-running operation.
 *
 * @package Pontifex\Job
 */

declare(strict_types=1);

namespace Pontifex\Job;

use InvalidArgumentException;

/**
 * The state of one long-running operation, persisted between requests.
 *
 * A job is what makes an operation outlive the PHP request that started
 * it (ADR 0014): any ticker — the CLI's own loop, an admin polling
 * request, a WP-Cron event — can load the job, run a bounded step of
 * work, save the updated state, and stop. The kind-specific progress
 * detail (output paths, entry cursors) lives in the payload array; the
 * per-entry manifest-so-far, which grows with the site, lives in the
 * sidecar {@see JobProgressLog} so saving a job stays cheap.
 *
 * Status is a small, validated state machine: pending → running →
 * done | failed | cancelled, with running → pending allowed (a step
 * completed, more remain, nobody is mid-step). Terminal states never
 * transition again — a finished job cannot be revived, only deleted.
 */
final class Job {

	/**
	 * Job kind: an export producing a .wpmig archive.
	 *
	 * @var string
	 */
	public const KIND_EXPORT = 'export';

	/**
	 * Every kind this class recognises.
	 *
	 * @var string[]
	 */
	public const ALL_KINDS = array( self::KIND_EXPORT );

	/**
	 * Status: created, no step run yet, or between steps.
	 *
	 * @var string
	 */
	public const STATUS_PENDING = 'pending';

	/**
	 * Status: a ticker is executing a step right now.
	 *
	 * @var string
	 */
	public const STATUS_RUNNING = 'running';

	/**
	 * Status: the operation completed successfully. Terminal.
	 *
	 * @var string
	 */
	public const STATUS_DONE = 'done';

	/**
	 * Status: the operation failed. Terminal.
	 *
	 * @var string
	 */
	public const STATUS_FAILED = 'failed';

	/**
	 * Status: the operator cancelled the operation. Terminal.
	 *
	 * @var string
	 */
	public const STATUS_CANCELLED = 'cancelled';

	/**
	 * Every status this class recognises.
	 *
	 * @var string[]
	 */
	public const ALL_STATUSES = array(
		self::STATUS_PENDING,
		self::STATUS_RUNNING,
		self::STATUS_DONE,
		self::STATUS_FAILED,
		self::STATUS_CANCELLED,
	);

	/**
	 * The transitions the state machine permits, per current status.
	 *
	 * @var array<string, string[]>
	 */
	private const ALLOWED_TRANSITIONS = array(
		self::STATUS_PENDING   => array( self::STATUS_RUNNING, self::STATUS_FAILED, self::STATUS_CANCELLED ),
		self::STATUS_RUNNING   => array( self::STATUS_PENDING, self::STATUS_DONE, self::STATUS_FAILED, self::STATUS_CANCELLED ),
		self::STATUS_DONE      => array(),
		self::STATUS_FAILED    => array(),
		self::STATUS_CANCELLED => array(),
	);

	/**
	 * Pattern a job id must match: 16 lowercase hex characters.
	 *
	 * @var string
	 */
	public const ID_PATTERN = '/^[a-f0-9]{16}$/';

	/**
	 * The job's opaque identifier.
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * The job kind; one of the KIND_* constants.
	 *
	 * @var string
	 */
	private string $kind;

	/**
	 * The job status; one of the STATUS_* constants.
	 *
	 * @var string
	 */
	private string $status;

	/**
	 * Kind-specific progress state.
	 *
	 * @var array<string, mixed>
	 */
	private array $payload;

	/**
	 * Unix timestamp the job was created at.
	 *
	 * @var int
	 */
	private int $created_at;

	/**
	 * Unix timestamp the job was last saved at.
	 *
	 * @var int
	 */
	private int $updated_at;

	/**
	 * Construct a Job with every field validated.
	 *
	 * @param string               $id         The job id; must match ID_PATTERN.
	 * @param string               $kind       One of the KIND_* constants.
	 * @param string               $status     One of the STATUS_* constants.
	 * @param array<string, mixed> $payload    Kind-specific progress state.
	 * @param int                  $created_at Unix creation timestamp; non-negative.
	 * @param int                  $updated_at Unix last-update timestamp; non-negative.
	 * @throws InvalidArgumentException If any field is out of range.
	 */
	public function __construct( string $id, string $kind, string $status, array $payload, int $created_at, int $updated_at ) {
		if ( 1 !== preg_match( self::ID_PATTERN, $id ) ) {
			throw new InvalidArgumentException( 'Job: id must be 16 lowercase hex characters.' );
		}
		if ( ! in_array( $kind, self::ALL_KINDS, true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $kind is reported verbatim for diagnostic context; exception path, not HTML output.
			throw new InvalidArgumentException( sprintf( 'Job: unknown kind "%s".', $kind ) );
		}
		if ( ! in_array( $status, self::ALL_STATUSES, true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $status is reported verbatim for diagnostic context; exception path, not HTML output.
			throw new InvalidArgumentException( sprintf( 'Job: unknown status "%s".', $status ) );
		}
		if ( $created_at < 0 || $updated_at < 0 ) {
			throw new InvalidArgumentException( 'Job: timestamps must be non-negative.' );
		}

		$this->id         = $id;
		$this->kind       = $kind;
		$this->status     = $status;
		$this->payload    = $payload;
		$this->created_at = $created_at;
		$this->updated_at = $updated_at;
	}

	/**
	 * Return the job id.
	 *
	 * @return string The 16-hex-character id.
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Return the job kind.
	 *
	 * @return string One of the KIND_* constants.
	 */
	public function kind(): string {
		return $this->kind;
	}

	/**
	 * Return the job status.
	 *
	 * @return string One of the STATUS_* constants.
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * Return the kind-specific progress state.
	 *
	 * @return array<string, mixed> The payload.
	 */
	public function payload(): array {
		return $this->payload;
	}

	/**
	 * Return the creation timestamp.
	 *
	 * @return int Unix seconds.
	 */
	public function created_at(): int {
		return $this->created_at;
	}

	/**
	 * Return the last-update timestamp.
	 *
	 * @return int Unix seconds.
	 */
	public function updated_at(): int {
		return $this->updated_at;
	}

	/**
	 * Whether the job is still live (pending or running).
	 *
	 * @return bool True for pending and running; false for the terminal states.
	 */
	public function is_active(): bool {
		return self::STATUS_PENDING === $this->status || self::STATUS_RUNNING === $this->status;
	}

	/**
	 * Replace the kind-specific progress state.
	 *
	 * @param array<string, mixed> $payload The new payload.
	 * @return void
	 */
	public function set_payload( array $payload ): void {
		$this->payload = $payload;
	}

	/**
	 * Transition the job to a new status, enforcing the state machine.
	 *
	 * @param string $status One of the STATUS_* constants.
	 * @param int    $now    Unix timestamp to record as the update time.
	 * @return void
	 * @throws InvalidArgumentException If the transition is not permitted (terminal states never transition).
	 */
	public function mark( string $status, int $now ): void {
		if ( ! in_array( $status, self::ALLOWED_TRANSITIONS[ $this->status ] ?? array(), true ) ) {
			throw new InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Both values are validated STATUS_* constants; exception path, not HTML output.
				sprintf( 'Job: cannot transition from "%s" to "%s".', $this->status, $status )
			);
		}
		$this->status     = $status;
		$this->updated_at = $now;
	}

	/**
	 * Record that the job was saved, without changing status.
	 *
	 * @param int $now Unix timestamp to record as the update time.
	 * @return void
	 */
	public function touch( int $now ): void {
		$this->updated_at = $now;
	}

	/**
	 * Serialise the job for persistence.
	 *
	 * @return array<string, mixed> A JSON-encodable array.
	 */
	public function to_array(): array {
		return array(
			'id'         => $this->id,
			'kind'       => $this->kind,
			'status'     => $this->status,
			'payload'    => $this->payload,
			'created_at' => $this->created_at,
			'updated_at' => $this->updated_at,
		);
	}

	/**
	 * Rebuild a job from persisted data, validating every field.
	 *
	 * The data comes from a file on disk, which may have been truncated or
	 * hand-edited; the constructor's validation refuses anything malformed
	 * rather than resurrecting a half-broken job.
	 *
	 * @param array<string, mixed> $data The decoded persisted array.
	 * @return self The reconstructed job.
	 * @throws InvalidArgumentException If any field is missing or malformed.
	 */
	public static function from_array( array $data ): self {
		foreach ( array( 'id', 'kind', 'status', 'created_at', 'updated_at' ) as $key ) {
			if ( ! isset( $data[ $key ] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $key is a literal from the list above; exception path, not HTML output.
				throw new InvalidArgumentException( sprintf( 'Job: persisted job is missing "%s".', $key ) );
			}
		}
		$payload = $data['payload'] ?? array();
		if ( ! is_array( $payload ) ) {
			throw new InvalidArgumentException( 'Job: persisted payload must be an array.' );
		}
		if ( ! is_string( $data['id'] ) || ! is_string( $data['kind'] ) || ! is_string( $data['status'] ) ) {
			throw new InvalidArgumentException( 'Job: persisted id, kind, and status must be strings.' );
		}
		if ( ! is_int( $data['created_at'] ) || ! is_int( $data['updated_at'] ) ) {
			throw new InvalidArgumentException( 'Job: persisted timestamps must be integers.' );
		}

		return new self( $data['id'], $data['kind'], $data['status'], $payload, $data['created_at'], $data['updated_at'] );
	}
}
