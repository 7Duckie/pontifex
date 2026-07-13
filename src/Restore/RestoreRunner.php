<?php
/**
 * Pontifex restore runner — orchestrates the full restore from archive stream to filesystem and database.
 *
 * @package Pontifex\Restore
 */

declare(strict_types=1);

namespace Pontifex\Restore;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use Pontifex\Archive\Format\ArchiveManifest;
use Pontifex\Archive\Format\ManifestEntry;
use Pontifex\Archive\Format\Scope;
use Pontifex\Archive\Reader\ArchiveLimits;
use Pontifex\Archive\Reader\ArchiveReader;
use Pontifex\Archive\Reader\EntryReader;
use Pontifex\Archive\Reader\EntryReadResult;

/**
 * Drives a full restore from a Pontifex archive stream.
 *
 * Implements {@see RestoreRunnerInterface} so callers (the CLI layer)
 * can depend on the contract rather than on this final class.
 *
 * The mirror of {@see \Pontifex\Manifest\ManifestBuilder}. Where the
 * builder orchestrated FileScanner and DatabaseScanner into an
 * EntryPlan list for the writer, RestoreRunner orchestrates
 * ArchiveReader, EntryReader, FileWriter, and DatabaseWriter into a
 * full restore from archive bytes to filesystem and database.
 *
 * Public API (locked for v0.1.0):
 *
 *  - {@see RestoreRunner::__construct()} — takes the three
 *    collaborators that do the actual work, plus an optional set of
 *    defensive limits (defaulting to the conservative ArchiveLimits)
 *    and an optional runtime memory limit (0/null for unlimited) that
 *    refuses an entry too large to decode within the request's memory.
 *    Stateless after construction; safe to reuse across many archives.
 *  - {@see RestoreRunner::restore()} — given a seekable readable
 *    stream containing a Pontifex archive, read, verify, and write
 *    every entry in manifest order. Accepts an optional per-entry
 *    progress callback.
 *  - {@see RestoreRunner::verify()} — the same read-and-verify walk
 *    as restore(), but writes nothing; the engine behind a dry-run
 *    import. Also accepts the optional progress callback.
 *
 * Behaviour:
 *
 *  1. Open the source stream with ArchiveReader (validates header
 *     and footer; throws if either is malformed).
 *  2. Read the manifest (validates the manifest's internal hash
 *     against the footer's recorded hash; throws on mismatch).
 *  3. For each ManifestEntry, in the order the manifest records:
 *     a. Decode via EntryReader (verifies the entry's on-disk hash
 *        and decodes the payload through the codec).
 *     b. Route to FileWriter or DatabaseWriter based on the
 *        entry's kind.
 *  4. If any step throws, the restore halts immediately. Database
 *     changes never reach the live tables: every db_chunk replays into
 *     staging tables that are cut over atomically only after the whole
 *     walk succeeds (ADR 0009), and a failure drops the staging tables.
 *     Files already written stay on disk; the safety-archive recovery
 *     layer covers the file half.
 *
 * Internal choices (implementation details; may change without
 * breaking the public API):
 *
 *  - Entries are restored in manifest order. The scanner-writer
 *    pair produces files first (alphabetical) then db_chunks
 *    (alphabetical by table, then by chunk_index); RestoreRunner
 *    preserves that ordering on the read side.
 *  - Routing is by entry kind, not by codec or any other field.
 *    Files, directories, and symlinks all go to FileWriter; only
 *    db_chunks go to DatabaseWriter.
 *  - No transaction wrapping and no parallelism; these belong in
 *    higher layers (Phase 4 CLI). Progress is surfaced only through
 *    an optional per-entry callback that the CLI layer drives; the
 *    runner itself stays unaware of any progress UI.
 *  - Stateless after construction. Safe to call restore() multiple
 *    times with different archive sources.
 */
final class RestoreRunner implements RestoreRunnerInterface {

	/**
	 * The decoder that reads and verifies individual entry records.
	 *
	 * @var EntryReader
	 */
	private EntryReader $entry_reader;

	/**
	 * The writer that places file/directory/symlink entries on disk.
	 *
	 * @var FileWriter
	 */
	private FileWriter $file_writer;

	/**
	 * The writer that replays db_chunk entries into the destination database.
	 *
	 * @var DatabaseWriter
	 */
	private DatabaseWriter $database_writer;

	/**
	 * Defensive limits enforced while reading the archive.
	 *
	 * @var ArchiveLimits
	 */
	private ArchiveLimits $limits;

	/**
	 * The per-entry decoded-byte budget derived from the runtime memory limit, or 0
	 * for no memory-derived cap.
	 *
	 * @var int
	 */
	private int $entry_memory_budget;

	/**
	 * Fraction of the runtime memory limit a single entry's decoded payload may use.
	 *
	 * A quarter: reading an entry peaks at several coexisting copies (the buffered
	 * record, a substr, and the decoded payload string), so an entry is refused well
	 * before the request runs out of memory rather than OOM-fatalling mid-restore.
	 *
	 * @var int
	 */
	private const MEMORY_BUDGET_DIVISOR = 4;

	/**
	 * Construct a RestoreRunner with its collaborators and optional limits.
	 *
	 * @param EntryReader        $entry_reader      Decodes individual entry records.
	 * @param FileWriter         $file_writer       Writes filesystem entries to disk.
	 * @param DatabaseWriter     $database_writer   Replays db_chunk entries into the database.
	 * @param ArchiveLimits|null $limits            Defensive limits to enforce; null applies the conservative defaults.
	 * @param int|null           $memory_limit_bytes The runtime PHP memory limit in bytes (0 or null for unlimited); an entry whose decoded size would exceed a fraction of it is refused before it can exhaust the request. Unlimited (a CLI run) applies no memory-derived cap.
	 */
	public function __construct( EntryReader $entry_reader, FileWriter $file_writer, DatabaseWriter $database_writer, ?ArchiveLimits $limits = null, ?int $memory_limit_bytes = null ) {
		$this->entry_reader        = $entry_reader;
		$this->file_writer         = $file_writer;
		$this->database_writer     = $database_writer;
		$this->limits              = $limits ?? ArchiveLimits::defaults();
		$this->entry_memory_budget = ( null !== $memory_limit_bytes && $memory_limit_bytes > 0 )
			? intdiv( $memory_limit_bytes, self::MEMORY_BUDGET_DIVISOR )
			: 0;
	}

	/**
	 * Read, verify, and write every entry from the archive stream.
	 *
	 * Opens an ArchiveReader around the source (which eagerly validates
	 * header and footer), reads the manifest, then walks each
	 * ManifestEntry in order: decode and verify via EntryReader, then
	 * route to FileWriter or DatabaseWriter by kind. When a callback is
	 * supplied it is invoked once per entry, after that entry is written,
	 * as `( int $done, int $total ): void`.
	 *
	 * @param resource      $archive_source    A seekable, readable stream containing a Pontifex archive.
	 * @param callable|null $on_entry_restored Optional per-entry progress callback, called as `( int $done, int $total ): void`.
	 * @param callable|null $on_bytes          Optional byte-progress callback forwarded to each entry's read, called as `( int $bytes ): void`.
	 * @throws InvalidArgumentException If $archive_source is not a valid stream resource or is not seekable.
	 * @throws RuntimeException         If the archive is malformed, hash verification fails, or any worker fails.
	 */
	public function restore( $archive_source, ?callable $on_entry_restored = null, ?callable $on_bytes = null ): void {
		// Reset the writer's staging state and sweep leftovers a crashed earlier
		// run may have abandoned (ADR 0009). The archive's database character set
		// rides along from provenance: the replayed SQL's bytes were captured
		// under it, so the connection must speak it for the replay's duration or
		// multibyte content is silently transcoded. Provenance is validated by
		// the reader; the charset string itself is validated by the writer.
		$reader     = new ArchiveReader( $archive_source );
		$provenance = $reader->provenance();

		// Fail closed on an archive that lies about its own scope: one whose
		// recorded scope declares a half absent while the manifest actually
		// carries it (ADR 0016). Pontifex's own exports never contradict their
		// scope, so this only catches a corrupt or hand-forged archive — refuse
		// it rather than restore contents the scope says are not there.
		$this->assert_scope_consistent_with_manifest( $provenance->scope(), $reader->manifest() );

		$this->database_writer->begin_staging( (string) $provenance->db_charset() );

		try {
			$this->walk(
				$archive_source,
				$on_entry_restored,
				function ( ManifestEntry $manifest_entry, EntryReadResult $result ): void {
					$this->dispatch( $manifest_entry, $result );
				},
				$on_bytes
			);

			// Every db_chunk has now been replayed into its staging table. Finalise
			// any cross-prefix restore by rewriting the prefix embedded in the
			// options/usermeta key columns of the STAGED copies (a no-op otherwise),
			// then cut the staged tables over to their live names in one atomic
			// RENAME. Until that rename the live tables have not been written; after
			// it the database is entirely the restored one. This runs only on
			// restore(), never verify(), which writes nothing.
			$this->database_writer->finalise_prefix_rewrite();
			$this->database_writer->commit_staged_tables();
		} catch ( Throwable $error ) {
			// The cut-over never happened (or failed atomically), so the live
			// tables are untouched; remove the half-built staging tables. Cleanup
			// is best-effort and must never mask the original failure.
			try {
				$this->database_writer->abort_staging();
			} catch ( Throwable $cleanup_failure ) {
				unset( $cleanup_failure );
			}
			throw $error;
		}
	}

	/**
	 * Refuse an archive whose recorded scope contradicts the entries it carries.
	 *
	 * A files-only archive must carry no database chunks; a db-only archive must
	 * carry no file entries. If the scope declares a half absent but the manifest
	 * has it, the archive is corrupt or forged — restoring it would write data the
	 * scope claims is not there — so it is refused. A legacy archive with no scope
	 * block imposes no such contract and passes.
	 *
	 * @param Scope|null      $scope    The recorded scope, or null for a legacy archive.
	 * @param ArchiveManifest $manifest The archive's manifest.
	 * @return void
	 * @throws RuntimeException If the scope declares a half absent that the manifest carries.
	 */
	private function assert_scope_consistent_with_manifest( ?Scope $scope, ArchiveManifest $manifest ): void {
		if ( null === $scope ) {
			return;
		}

		$has_files = false;
		$has_db    = false;
		foreach ( $manifest->entries() as $entry ) {
			if ( $entry->is_db_chunk() ) {
				$has_db = true;
			} else {
				$has_files = true;
			}
			if ( $has_files && $has_db ) {
				break;
			}
		}

		if ( ! $scope->includes_database() && $has_db ) {
			throw new RuntimeException( 'RestoreRunner: the archive records a files-only scope but carries database chunks. Refusing this inconsistent archive.' );
		}
		if ( ! $scope->includes_files() && $has_files ) {
			throw new RuntimeException( 'RestoreRunner: the archive records a database-only scope but carries file entries. Refusing this inconsistent archive.' );
		}
	}

	/**
	 * Read and hash-verify every entry from the archive stream, writing nothing.
	 *
	 * Opens the archive, reads the manifest, and streams each entry through
	 * {@see EntryReader::verify_entry()} — hashing the stored bytes and checking
	 * them against the manifest, without decoding or buffering whole entries.
	 * Nothing is written to the destination, so this is the engine behind both the
	 * Verify screen and a dry-run import. Unlike {@see self::restore()} it does not
	 * decode payloads: a verification only needs the stored bytes to be intact, and
	 * skipping the decode keeps memory flat and lets a large entry report progress.
	 *
	 * @param resource      $archive_source    A seekable, readable stream containing a Pontifex archive.
	 * @param callable|null $on_entry_verified Optional per-entry progress callback, called as `( int $done, int $total ): void`.
	 * @param callable|null $on_bytes          Optional byte-progress callback forwarded to each entry's verify read, called as `( int $bytes ): void`.
	 * @throws RuntimeException If the archive is malformed, declares too many entries, or hash verification fails.
	 */
	public function verify( $archive_source, ?callable $on_entry_verified = null, ?callable $on_bytes = null ): void {
		$reader   = new ArchiveReader( $archive_source );
		$manifest = $reader->manifest();
		$entries  = $manifest->entries();
		$total    = count( $entries );

		if ( $total > $this->limits->max_entry_count() ) {
			throw new RuntimeException(
				sprintf(
					'RestoreRunner: archive declares %d entries, exceeding the maximum of %d.',
					(int) $total,
					(int) $this->limits->max_entry_count()
				)
			);
		}

		$done = 0;
		foreach ( $entries as $manifest_entry ) {
			$this->entry_reader->verify_entry(
				$archive_source,
				$manifest_entry,
				$on_bytes,
				$this->entry_memory_budget > 0 ? $this->entry_memory_budget : null
			);
			++$done;
			if ( null !== $on_entry_verified ) {
				$on_entry_verified( $done, $total );
			}
		}
	}

	/**
	 * Walk every manifest entry once: read-and-verify it, then hand it to $handle.
	 *
	 * The shared core of {@see self::restore()} and {@see self::verify()}.
	 * Opens the ArchiveReader (which validates header and footer), reads
	 * the manifest, then for each entry in manifest order reads and
	 * verifies the entry via EntryReader and passes it to $handle. After
	 * each entry the optional progress callback is invoked with the
	 * running count and the total.
	 *
	 * Defensive limits are enforced here: the entry count is checked up
	 * front, each entry is decoded under a per-entry byte budget drawn
	 * from the archive's total budget, and the walk is refused if the
	 * running total of decoded bytes exceeds that budget.
	 *
	 * @param resource      $archive_source A seekable, readable stream containing a Pontifex archive.
	 * @param callable|null $on_entry       Optional per-entry progress callback, called as `( int $done, int $total ): void`.
	 * @param callable      $handle         Receives each decoded entry as `( ManifestEntry $entry, EntryReadResult $result ): void`.
	 * @param callable|null $on_bytes       Optional byte-progress callback forwarded to each entry's read, called as `( int $bytes ): void`.
	 * @throws RuntimeException If the archive is malformed, hash verification fails, a defensive limit is exceeded, or $handle fails.
	 */
	private function walk( $archive_source, ?callable $on_entry, callable $handle, ?callable $on_bytes = null ): void {
		$reader   = new ArchiveReader( $archive_source );
		$manifest = $reader->manifest();

		$entries = $manifest->entries();
		$total   = count( $entries );
		$done    = 0;

		if ( $total > $this->limits->max_entry_count() ) {
			throw new RuntimeException(
				sprintf(
					'RestoreRunner: archive declares %d entries, exceeding the maximum of %d.',
					(int) $total,
					(int) $this->limits->max_entry_count()
				)
			);
		}

		$total_budget   = $this->limits->max_total_for_archive( $this->stream_size( $archive_source ) );
		$decoded_so_far = 0;

		foreach ( $entries as $manifest_entry ) {
			// The bomb ceiling (per-entry and archive-total decoded bytes) applies to
			// every entry; the memory-derived budget is passed separately, because it
			// applies only to entries the reader must buffer whole — a plain file
			// entry streams through chunk-sized memory to a spool (ADR 0010).
			$remaining   = $total_budget - $decoded_so_far;
			$entry_limit = $this->limits->max_entry_bytes() < $remaining ? $this->limits->max_entry_bytes() : $remaining;

			$result = $this->entry_reader->read_entry(
				$archive_source,
				$manifest_entry,
				$entry_limit,
				$on_bytes,
				$this->entry_memory_budget > 0 ? $this->entry_memory_budget : null
			);

			$decoded_so_far += $result->decoded_size();
			if ( $decoded_so_far > $total_budget ) {
				throw new RuntimeException(
					sprintf(
						'RestoreRunner: restored data exceeds the maximum of %d bytes permitted for this archive.',
						(int) $total_budget
					)
				);
			}

			$handle( $manifest_entry, $result );

			++$done;
			if ( null !== $on_entry ) {
				$on_entry( $done, $total );
			}
		}
	}

	/**
	 * Route one decoded entry to the matching writer based on its kind.
	 *
	 * Files, directories, and symlinks go to FileWriter. db_chunks
	 * go to DatabaseWriter. Any other kind is a bug in the format
	 * or in EntryReader and surfaces as a RuntimeException.
	 *
	 * @param ManifestEntry   $manifest_entry The manifest entry for diagnostic context.
	 * @param EntryReadResult $result         The decoded entry to dispatch.
	 * @throws RuntimeException If the entry's kind is unrecognised.
	 */
	private function dispatch( ManifestEntry $manifest_entry, EntryReadResult $result ): void {
		if ( $manifest_entry->is_file() || $manifest_entry->is_directory() || $manifest_entry->is_symlink() ) {
			$this->file_writer->write_entry( $result );
			return;
		}
		if ( $manifest_entry->is_db_chunk() ) {
			$this->database_writer->write_entry( $result );
			return;
		}

		throw new RuntimeException(
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $manifest_entry->kind() is a validated KIND_* constant; reported verbatim for diagnostic context; exception path, not HTML output.
			sprintf( 'RestoreRunner: unsupported entry kind "%s" at manifest index %d.', $manifest_entry->kind(), (int) $manifest_entry->index() )
		);
	}
	/**
	 * Measure the on-disk size of the archive stream, in bytes.
	 *
	 * Used to derive the total decoded-byte budget. Seeks to the end and
	 * reports the position; the caller does not rely on the position
	 * being preserved, since each entry read re-seeks to its own offset.
	 *
	 * @param resource $archive_source A seekable stream containing the archive.
	 * @return int The stream's total size in bytes.
	 * @throws RuntimeException If the size cannot be determined.
	 */
	private function stream_size( $archive_source ): int {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek -- Measuring an open archive stream resource; WP_Filesystem has no equivalent.
		if ( -1 === fseek( $archive_source, 0, SEEK_END ) ) {
			throw new RuntimeException( 'RestoreRunner: could not seek to the end of the archive to measure its size.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_ftell -- Measuring an open archive stream resource; WP_Filesystem has no equivalent.
		$size = ftell( $archive_source );
		if ( false === $size ) {
			throw new RuntimeException( 'RestoreRunner: could not determine the archive size.' );
		}
		return $size;
	}
}
