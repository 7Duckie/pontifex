<?php
/**
 * Pontifex resumable export runner — drives an export as budgeted steps across requests.
 *
 * @package Pontifex\Export
 */

declare(strict_types=1);

namespace Pontifex\Export;

use DateTimeImmutable;
use RuntimeException;
use Throwable;
use Pontifex\Archive\Codec\CodecRegistry;
use Pontifex\Archive\Crypto\SigningContext;
use Pontifex\Archive\Format\ExporterInfo;
use Pontifex\Archive\Format\ManifestEntry;
use Pontifex\Archive\Format\Provenance;
use Pontifex\Archive\Format\Scope;
use Pontifex\Archive\Integrity\Sha256;
use Pontifex\Archive\Writer\EntryPlan;
use Pontifex\Archive\Writer\EntryWriter;
use Pontifex\Archive\Writer\FooterWriter;
use Pontifex\Archive\Writer\IncrementalArchiveWriter;
use Pontifex\Environment\Environment;
use Pontifex\Job\Job;
use Pontifex\Job\JobStore;
use Pontifex\Manifest\ExclusionRules;
use Pontifex\WordPress\WordPressContext;

/**
 * Runs an export as a sequence of bounded steps that survive request death.
 *
 * The resumable counterpart of {@see ExportRunner} (ADR 0014, ADR 0015).
 * start() records a {@see Job}; each tick() loads the job, appends file
 * entries to the archive until its time budget runs out, records every
 * appended entry in the job's progress log, and stops — ready for ANY
 * later request (a CLI loop iteration, an admin poll, a WP-Cron event)
 * to run the next tick. The final tick dumps every database chunk in one
 * go — inside one consistent snapshot, preserving ADR 0011's guarantee,
 * which cannot span requests because the connection dies with the
 * request — then writes the manifest and footer and renames the archive
 * into place atomically.
 *
 * The resume contract (what makes adopting a half-written file safe):
 *
 *  1. The progress log is the truth. Each line is one appended entry's
 *     canonical manifest data. The job payload carries only derived
 *     cursors and is advisory.
 *  2. On every tick the partial file's tail is verified: bytes beyond
 *     the last logged entry's end are truncated (a ticker died between
 *     writing bytes and logging), and the last logged entry's record is
 *     re-hashed against its logged hash — a mismatch drops that log
 *     record and truncates its bytes too, stepping back to the last
 *     provably-good prefix.
 *  3. The fresh scan must match the logged prefix positionally (same
 *     path at every completed index). Files changing CONTENT is fine —
 *     ADR 0013 already records the truth per entry — but a file added
 *     or removed EARLIER in the scan order would shift every index and
 *     silently skip or duplicate entries, so drift is refused loudly
 *     and the operator restarts.
 *
 * Encrypted exports are refused at start(): the derived key exists in
 * memory for one request and persisting it would defeat the passphrase.
 * Signed exports work: the caller re-supplies the SigningContext on the
 * final tick (rebuilt from the on-disk key file), and the signature is
 * computed at finish over every byte.
 */
final class ResumableExportRunner {

	/**
	 * How many entries between advisory job-payload saves during a tick.
	 *
	 * The progress log is appended for EVERY entry (it is the truth); the
	 * job record is a cheap cursor cache for screens, refreshed at this
	 * cadence and always at tick end.
	 *
	 * @var int
	 */
	private const JOB_SAVE_CADENCE = 25;

	/**
	 * The Environment abstraction (PHP version and constant reads).
	 *
	 * @var Environment
	 */
	private Environment $environment;

	/**
	 * The WordPressContext abstraction (site facts, database connections).
	 *
	 * @var WordPressContext
	 */
	private WordPressContext $wordpress_context;

	/**
	 * The store the job's state persists through.
	 *
	 * @var JobStore
	 */
	private JobStore $job_store;

	/**
	 * Factory building the manifest builder each tick, or null for the default wiring.
	 *
	 * Called as `( ExclusionRules $rules, string $path_prefix ): ManifestBuilderInterface`.
	 * The seam exists for testability: the default wiring scans the real
	 * filesystem and the real database (inside a consistent snapshot, ADR
	 * 0011), which a unit test cannot host.
	 *
	 * @var callable|null
	 */
	private $manifest_builder_factory;

	/**
	 * Construct a ResumableExportRunner.
	 *
	 * @param Environment      $environment              PHP-runtime and constant reads.
	 * @param WordPressContext $wordpress_context        WordPress-specific facts and connections.
	 * @param JobStore         $job_store                Persistence for the job and its progress log.
	 * @param callable|null    $manifest_builder_factory Optional builder factory, `( ExclusionRules, string ): ManifestBuilderInterface`; null uses the default scanner wiring.
	 */
	public function __construct( Environment $environment, WordPressContext $wordpress_context, JobStore $job_store, ?callable $manifest_builder_factory = null ) {
		$this->environment              = $environment;
		$this->wordpress_context        = $wordpress_context;
		$this->job_store                = $job_store;
		$this->manifest_builder_factory = $manifest_builder_factory;
	}

	/**
	 * Create the job for a new resumable export.
	 *
	 * @param ExportOptions $options    Where to write, plus signing and the unencrypted-archive reason. Encryption is refused.
	 * @param string        $scan_root  Absolute path the file scan starts from.
	 * @param string        $path_prefix Prefix for recorded paths ('' whole-site, 'wp-content' content-only).
	 * @param string[]      $exclusions The exclusion patterns in force.
	 * @param int           $now        Unix timestamp of creation.
	 * @return Job The persisted pending job.
	 * @throws RuntimeException If the export is encrypted (not resumable by design) or a job is already active.
	 */
	public function start( ExportOptions $options, string $scan_root, string $path_prefix, array $exclusions, int $now ): Job {
		if ( null !== $options->encryption() ) {
			throw new RuntimeException( 'ResumableExportRunner: an encrypted export cannot be resumable — the derived key exists for one request and is never persisted. Run it without --resumable, or without --passphrase.' );
		}

		$payload = array(
			'output'        => $options->output_path(),
			'temp'          => $options->output_path() . '.' . uniqid( 'pontifex-job-', true ) . '.part',
			'scan_root'     => $scan_root,
			'path_prefix'   => $path_prefix,
			'exclusions'    => array_values( $exclusions ),
			'signed'        => null !== $options->signing(),
			'reason'        => $options->encryption_disabled_reason(),
			'scope'         => null !== $options->scope() ? $options->scope()->to_array() : null,
			'phase'         => 'files',
			'bytes_written' => 0,
			'files_changed' => 0,
		);

		return $this->job_store->create( Job::KIND_EXPORT, $payload, $now );
	}

	/**
	 * Run one bounded step of the export; returns true when the archive is complete.
	 *
	 * @param Job                 $job            The active export job.
	 * @param float               $budget_seconds Wall-clock budget for this tick's file entries.
	 * @param SigningContext|null $signing        Signing inputs, re-supplied by the caller when the job was started signed; must be null otherwise.
	 * @param callable|null       $clock          Monotonic-ish clock returning float seconds; defaults to microtime(true). Injectable for tests.
	 * @param callable|null       $on_entry       Optional per-entry progress callback, `( int $done, int $total ): void`.
	 * @return bool True when the export finished and the archive was renamed into place.
	 * @throws RuntimeException If the job is not an active export or the signing inputs contradict the job.
	 * @throws Throwable        Whatever the tick body raised (drift refusal, verification or write failure), re-thrown after the job is marked failed.
	 */
	public function tick( Job $job, float $budget_seconds, ?SigningContext $signing = null, ?callable $clock = null, ?callable $on_entry = null ): bool {
		$clock = $clock ?? static function (): float {
			return microtime( true );
		};

		if ( Job::KIND_EXPORT !== $job->kind() || ! $job->is_active() ) {
			throw new RuntimeException( 'ResumableExportRunner: tick() needs an active export job.' );
		}
		$payload = $job->payload();
		if ( (bool) ( $payload['signed'] ?? false ) !== ( null !== $signing ) ) {
			throw new RuntimeException( 'ResumableExportRunner: the signing inputs must match how the job was started (a signed job needs its key on every tick; an unsigned job takes none).' );
		}

		$job->mark( Job::STATUS_RUNNING, (int) $clock() );
		$this->job_store->save( $job );

		try {
			return $this->run_tick( $job, $budget_seconds, $signing, $clock, $on_entry );
		} catch ( Throwable $error ) {
			$job->mark( Job::STATUS_FAILED, (int) $clock() );
			$this->job_store->save( $job );
			throw $error;
		}
	}

	/**
	 * The tick body: verify, adopt, append within budget, finish when done.
	 *
	 * @param Job                 $job            The running export job.
	 * @param float               $budget_seconds Wall-clock budget for file entries.
	 * @param SigningContext|null $signing        Signing inputs or null.
	 * @param callable            $clock          The tick's clock.
	 * @param callable|null       $on_entry       Optional per-entry progress callback.
	 * @return bool True when the archive completed.
	 * @throws RuntimeException On drift, verification failure, or write failure.
	 */
	private function run_tick( Job $job, float $budget_seconds, ?SigningContext $signing, callable $clock, ?callable $on_entry ): bool {
		$payload = $job->payload();
		$log     = $this->job_store->progress_log( $job->id() );

		// Rebuild the manifest plan exactly as start() saw it. The scan is
		// deterministic (sorted), so an unchanged tree yields the same sequence.
		$rules   = ExclusionRules::from_array( array_map( 'strval', (array) $payload['exclusions'] ) );
		$builder = null !== $this->manifest_builder_factory
			? ( $this->manifest_builder_factory )( $rules, (string) $payload['path_prefix'] )
			: ExportRunner::default_manifest_builder( $this->wordpress_context, $rules, (string) $payload['path_prefix'] );
		$stream  = $builder->build( (string) $payload['scan_root'] );
		$total   = count( $stream );

		// The log is the truth: every completed entry, in index order.
		$completed_records = $log->read_all();

		$temp        = (string) $payload['temp'];
		$destination = $this->open_temp( $temp, array() === $completed_records && 0 === (int) $payload['bytes_written'] );

		try {
			// Verify the partial file's tail against the log (resume contract rule
			// 2), stepping back to the last provably-good prefix.
			$adopted = $this->verify_partial( $destination, $log, $completed_records );

			$writer = new IncrementalArchiveWriter( new EntryWriter( CodecRegistry::with_defaults() ), new FooterWriter() );
			if ( 0 === count( $adopted['entries'] ) && 0 === $adopted['bytes'] ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek -- Positioning the fresh temp archive at its start; WP_Filesystem has no streaming API.
				fseek( $destination, 0 );
				$writer->begin( $destination, $this->build_provenance( $payload ), null, $signing );
			} else {
				$writer->adopt( $destination, $adopted['bytes'], $adopted['entries'], $signing );
			}

			$done          = $writer->next_index();
			$files_changed = (int) ( $payload['files_changed'] ?? 0 );
			$deadline      = (float) $clock() + $budget_seconds;
			$index         = 0;
			$finished      = false;
			$saw_db        = false;

			foreach ( $stream as $plan ) {
				if ( $index < $done ) {
					// Already captured: the scan must still agree on what sits at
					// this position (resume contract rule 3).
					$this->refuse_drift( $plan, $completed_records, $index );
					++$index;
					continue;
				}

				$is_db = $plan->header()->is_db_chunk();
				if ( $is_db && ! $saw_db ) {
					$saw_db = true;
					// The database phase runs whole in a fresh tick, so its one
					// consistent snapshot (ADR 0011) never has to span requests. If
					// this tick already spent budget on files, stop here; the next
					// tick starts at the database with its full budget.
					if ( 'files' === $payload['phase'] ) {
						if ( $writer->next_index() > $done || $index > 0 ) {
							$payload['phase'] = 'database';
							break;
						}
						$payload['phase'] = 'database';
					}
				}

				$manifest_entry = $writer->append_entry(
					$plan,
					null,
					// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- The closure must match the on_file_changed callback contract; only the tally is needed here.
					static function ( string $path, int $declared, int $actual ) use ( &$files_changed ): void {
						++$files_changed;
					}
				);
				$log->append( $manifest_entry->to_canonical_data() );
				++$index;

				if ( null !== $on_entry ) {
					$on_entry( $writer->next_index(), $total );
				}
				if ( 0 === $writer->next_index() % self::JOB_SAVE_CADENCE ) {
					$this->save_progress( $job, $payload, $writer->bytes_written(), $files_changed, (int) $clock() );
				}
				// File phase honours the budget; the database phase, once begun,
				// runs to the end so the snapshot stays within this request.
				if ( ! $saw_db && (float) $clock() >= $deadline ) {
					break;
				}
			}

			if ( $index >= $total && $writer->next_index() >= $total ) {
				$writer->finish();
				$finished = true;
			}

			$this->save_progress( $job, $payload, $writer->bytes_written(), $files_changed, (int) $clock() );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the temp archive stream between ticks; not a WP_Filesystem operation.
			fclose( $destination );
		}

		if ( $finished ) {
			$this->move_into_place( $temp, (string) $payload['output'] );
			$job->mark( Job::STATUS_DONE, (int) $clock() );
			$this->job_store->save( $job );
			return true;
		}

		$job->mark( Job::STATUS_PENDING, (int) $clock() );
		$this->job_store->save( $job );
		return false;
	}

	/**
	 * Verify the partial archive against the progress log; return the adopted state.
	 *
	 * Truncates bytes beyond the last logged entry (written but never logged),
	 * and drops the last logged entry itself when its bytes do not re-hash to
	 * the logged value (logged but never fully flushed), stepping back to the
	 * last prefix that provably matches.
	 *
	 * @param resource                         $destination The open temp archive.
	 * @param \Pontifex\Job\JobProgressLog     $log         The job's progress log.
	 * @param array<int, array<string, mixed>> $records     The log's records, as read this tick.
	 * @return array{bytes: int, entries: ManifestEntry[]} The verified adopted state.
	 * @throws RuntimeException If the file is shorter than the log claims even after stepping back, or truncation fails.
	 */
	private function verify_partial( $destination, $log, array $records ): array {
		if ( array() === $records ) {
			return array(
				'bytes'   => 0,
				'entries' => array(),
			);
		}

		$entries = array();
		foreach ( $records as $record ) {
			$entries[] = ManifestEntry::from_canonical_data( $record );
		}

		$stat = fstat( $destination );
		$size = false !== $stat ? (int) $stat['size'] : 0;

		while ( array() !== $entries ) {
			$last = $entries[ count( $entries ) - 1 ];
			$end  = $last->offset() + $last->length();

			if ( $size < $end ) {
				// The file is shorter than this entry claims: it was logged but its
				// bytes never fully reached disk. Step back one entry.
				array_pop( $entries );
				continue;
			}

			// Re-hash the last entry's record bytes against the logged hash — the
			// cheap, bounded spot-check that the adopted prefix is real.
			if ( ! $this->entry_bytes_match( $destination, $last ) ) {
				array_pop( $entries );
				$size = min( $size, $last->offset() );
				continue;
			}

			// The prefix is good: cut any bytes past it (written, never logged).
			if ( $size > $end ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_ftruncate -- Cutting an unlogged tail off the partial archive per the resume contract; WP_Filesystem has no streaming API.
				if ( ! ftruncate( $destination, $end ) ) {
					throw new RuntimeException( 'ResumableExportRunner: could not truncate the partial archive to its verified length.' );
				}
			}
			$log->truncate_to( count( $entries ) );
			return array(
				'bytes'   => $end,
				'entries' => $entries,
			);
		}

		// Nothing in the log survived verification: start the entries over. The
		// header and provenance bytes cannot be reconstructed without the original
		// timestamps, so the whole temp restarts from zero.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_ftruncate -- Resetting an unverifiable partial archive per the resume contract; WP_Filesystem has no streaming API.
		if ( ! ftruncate( $destination, 0 ) ) {
			throw new RuntimeException( 'ResumableExportRunner: could not reset the unverifiable partial archive.' );
		}
		$log->truncate_to( 0 );
		return array(
			'bytes'   => 0,
			'entries' => array(),
		);
	}

	/**
	 * Whether an entry's on-disk record bytes hash to the manifest's recorded value.
	 *
	 * @param resource      $destination The open archive stream.
	 * @param ManifestEntry $entry       The entry to check.
	 * @return bool True when the stored record hashes to the logged entry_hash.
	 */
	private function entry_bytes_match( $destination, ManifestEntry $entry ): bool {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek -- Reading the partial archive back for verification; WP_Filesystem has no streaming API.
		if ( -1 === fseek( $destination, $entry->offset() ) ) {
			return false;
		}
		$context   = hash_init( 'sha256' );
		$remaining = $entry->length() - Sha256::DIGEST_SIZE;
		while ( $remaining > 0 ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Reading the partial archive back for verification; WP_Filesystem has no streaming API.
			$chunk = fread( $destination, (int) min( 1048576, $remaining ) );
			if ( false === $chunk || '' === $chunk ) {
				return false;
			}
			hash_update( $context, $chunk );
			$remaining -= strlen( $chunk );
		}
		return hash_equals( $entry->entry_hash(), hash_final( $context, true ) );
	}

	/**
	 * Refuse the tick when the fresh scan disagrees with a completed entry's identity.
	 *
	 * @param EntryPlan                        $plan    The plan the fresh scan yields at this index.
	 * @param array<int, array<string, mixed>> $records The progress log's records.
	 * @param int                              $index   The completed index being re-checked.
	 * @return void
	 * @throws RuntimeException If the identities differ — the source tree changed shape and every later index would shift.
	 */
	private function refuse_drift( EntryPlan $plan, array $records, int $index ): void {
		$logged = $records[ $index ] ?? null;
		if ( null === $logged ) {
			return;
		}
		$header     = $plan->header();
		$scanned_id = $header->is_db_chunk() ? 'db:' . (int) $header->chunk_index() : (string) $header->path();
		$logged_id  = isset( $logged['chunk_index'] ) && null !== $logged['chunk_index'] ? 'db:' . (int) $logged['chunk_index'] : (string) ( $logged['path'] ?? '' );
		if ( $scanned_id !== $logged_id ) {
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Paths are reported verbatim for diagnostic context; exception path, not HTML output.
				sprintf( 'ResumableExportRunner: the source changed shape since this export started — position %d was "%s" and is now "%s", so every later entry would shift. Delete the partial export and start again.', (int) $index, $logged_id, $scanned_id )
			);
		}
	}

	/**
	 * Persist the advisory cursors onto the job record.
	 *
	 * @param Job                  $job           The running job.
	 * @param array<string, mixed> $payload       The payload being maintained (phase, cursors).
	 * @param int                  $bytes_written Bytes the archive holds so far.
	 * @param int                  $files_changed Changed-file tally so far.
	 * @param int                  $now           Unix timestamp.
	 * @return void
	 */
	private function save_progress( Job $job, array &$payload, int $bytes_written, int $files_changed, int $now ): void {
		$payload['bytes_written'] = $bytes_written;
		$payload['files_changed'] = $files_changed;
		$job->set_payload( $payload );
		$job->touch( $now );
		$this->job_store->save( $job );
	}

	/**
	 * Open the job's temp archive: fresh on the first tick, read-write after.
	 *
	 * @param string $temp  The temp path.
	 * @param bool   $fresh Whether this is the first tick (no completed entries).
	 * @return resource The open stream.
	 * @throws RuntimeException If the file cannot be opened.
	 */
	private function open_temp( string $temp, bool $fresh ) {
		$mode = $fresh && ! is_file( $temp ) ? 'w+b' : 'r+b';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- Opening the job's temp archive as a stream; @ traps an unopenable-file warning converted to the exception below.
		$destination = @fopen( $temp, $mode );
		if ( false === $destination ) {
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The path is plugin-derived; exception path, not HTML output.
				sprintf( 'ResumableExportRunner: could not open the partial archive: %s', $temp )
			);
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek -- Appends happen at verified offsets; start at the end for safety.
		fseek( $destination, 0, SEEK_END );
		return $destination;
	}

	/**
	 * Build the provenance block, written once on the first tick.
	 *
	 * @param array<string, mixed> $payload The job payload (scope facts, reason).
	 * @return Provenance The provenance value object.
	 */
	private function build_provenance( array $payload ): Provenance {
		$pontifex_version = $this->environment->is_constant_defined( 'PONTIFEX_VERSION' )
			? (string) $this->environment->constant_value( 'PONTIFEX_VERSION' )
			: '0.0.0-dev';

		$scope        = null !== ( $payload['scope'] ?? null ) ? Scope::from_array( (array) $payload['scope'] ) : null;
		$table_prefix = null !== $scope ? $this->wordpress_context->wpdb_prefix() : null;

		return new Provenance(
			$this->wordpress_context->wp_version(),
			$this->environment->php_version(),
			$this->wordpress_context->site_url(),
			$this->wordpress_context->wpdb_charset(),
			$this->wordpress_context->wpdb_collation(),
			new ExporterInfo( 'pontifex', $pontifex_version ),
			new DateTimeImmutable(),
			null !== ( $payload['reason'] ?? null ) ? (string) $payload['reason'] : null,
			$table_prefix,
			$scope
		);
	}

	/**
	 * Move the completed temp archive onto the output path atomically.
	 *
	 * @param string $temp   The completed temp archive.
	 * @param string $output The final path.
	 * @return void
	 * @throws RuntimeException If the rename fails; the temp is preserved for inspection.
	 */
	private function move_into_place( string $temp, string $output ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename,WordPress.PHP.NoSilencedErrors.Discouraged -- Atomically moving the completed archive into place (a same-directory move); WP_Filesystem is unavailable in CLI/cron contexts.
		if ( ! @rename( $temp, $output ) ) {
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The path is plugin-derived; exception path, not HTML output.
				sprintf( 'ResumableExportRunner: could not move the completed archive into place: %s', $output )
			);
		}
	}
}
