<?php
/**
 * Pontifex restore runner — orchestrates the full restore from archive stream to filesystem and database.
 *
 * @package Pontifex\Restore
 */

declare(strict_types=1);

namespace Pontifex\Restore;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use Pontifex\Archive\Format\ManifestEntry;
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
 *  3. Settle the preflights, all of which now live in
 *     {@see RestorePreflight} so that verify and a dry run can run the
 *     same checks against the same destination rather than promising
 *     something a restore then refuses. restore() runs all four, in the
 *     order below, and stops at the first refusal:
 *     a. Whether the archive's recorded scope contradicts the entries it
 *        actually carries (ADR 0016).
 *     b. Whether this host can create a symlink at all — a host with
 *        "symlink" in disable_functions (common on shared hosting) would
 *        otherwise walk the whole tree, overwriting files, and only then
 *        die on the archive's first symlink entry. This is the ONE
 *        preflight that writes (a test symlink, removed again), which is
 *        why verify cannot run it and a dry run explicitly can. Runs
 *        before (c) because there is no point judging whether a target is
 *        SAFE on a host that could never create the link at all.
 *     c. Whether every symlink the archive declares resolves inside the
 *        site (ADR 0021), judged over the whole set because the attack it
 *        closes needs two links to co-operate.
 *     d. Whether the destination has room — a full disk part-way through
 *        is the most likely failure a restore can hit, needing no
 *        attacker, just an ordinary full disk.
 *  4. For each ManifestEntry, in the order the manifest records:
 *     a. Decode via EntryReader (verifies the entry's on-disk hash
 *        and decodes the payload through the codec).
 *     b. Route to FileWriter or DatabaseWriter based on the
 *        entry's kind.
 *  5. If any step throws, the restore halts immediately. Database
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
	 * The checks settled before the first byte is written.
	 *
	 * Held as a collaborator rather than inlined so that verify() and a dry run —
	 * neither of which can call restore() — can run the same checks against the
	 * same destination, which is the whole point of the class. See
	 * {@see RestorePreflight} for which of them write and which do not.
	 *
	 * @var RestorePreflight
	 */
	private RestorePreflight $preflight;

	/**
	 * Records what a completed restore should still tell the operator.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * The per-entry decoded-byte budget derived from the runtime memory limit, or 0
	 * for no memory-derived cap.
	 *
	 * @var int
	 */
	private int $entry_memory_budget;

	/**
	 * The runtime memory limit in bytes, or 0 for unlimited.
	 *
	 * Held undivided (unlike {@see self::$entry_memory_budget}) and handed to
	 * every {@see ArchiveReader} this runner opens, so the reader can refuse a
	 * manifest whose decode would not fit rather than letting it fatal.
	 *
	 * @var int
	 */
	private int $memory_limit_bytes;

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
	 * @param EntryReader          $entry_reader      Decodes individual entry records.
	 * @param FileWriter           $file_writer       Writes filesystem entries to disk.
	 * @param DatabaseWriter       $database_writer   Replays db_chunk entries into the database.
	 * @param ArchiveLimits|null   $limits            Defensive limits to enforce; null applies the conservative defaults.
	 * @param int|null             $memory_limit_bytes The runtime PHP memory limit in bytes (0 or null for unlimited); an entry whose decoded size would exceed a fraction of it is refused before it can exhaust the request. Unlimited (a CLI run) applies no memory-derived cap.
	 * @param LoggerInterface|null $logger           Optional. Records the few things a completed restore should still mention — a directory left more permissive than the archive recorded, or a count of leftover temp artefacts an earlier, interrupted restore left behind and this one swept away. Defaults to discarding them; trailing and optional, so no existing caller changes.
	 */
	public function __construct( EntryReader $entry_reader, FileWriter $file_writer, DatabaseWriter $database_writer, ?ArchiveLimits $limits = null, ?int $memory_limit_bytes = null, ?LoggerInterface $logger = null ) {
		$this->logger              = $logger ?? new NullLogger();
		$this->entry_reader        = $entry_reader;
		$this->file_writer         = $file_writer;
		$this->database_writer     = $database_writer;
		$this->limits              = $limits ?? ArchiveLimits::defaults();
		$this->memory_limit_bytes  = ( null !== $memory_limit_bytes && $memory_limit_bytes > 0 ) ? $memory_limit_bytes : 0;
		$this->entry_memory_budget = ( null !== $memory_limit_bytes && $memory_limit_bytes > 0 )
			? intdiv( $memory_limit_bytes, self::MEMORY_BUDGET_DIVISOR )
			: 0;

		// Built here rather than injected: it is derived entirely from collaborators
		// this constructor already has, so taking it as a parameter would let a
		// caller hand a runner a preflight pointed at a DIFFERENT destination than
		// the FileWriter that will do the writing — a disagreement nothing would
		// catch. Deriving it keeps the two answering for the same directory, and
		// keeps the constructor signature frozen at v1.0.0 unchanged.
		$this->preflight = new RestorePreflight(
			$entry_reader,
			$file_writer,
			$this->limits,
			$this->entry_memory_budget
		);
	}

	/**
	 * The preflight this runner settles a restore against.
	 *
	 * Exposed so a caller that already has a wired runner — verify, a dry run, the
	 * admin restore preview — can run the same checks against the same destination
	 * without rebuilding the collaborators and risking a mismatch. Deliberately
	 * NOT on {@see RestoreRunnerInterface}: that contract is part of the public API
	 * frozen at v1.0.0, and adding a method to an interface breaks every
	 * implementer. Callers therefore build their own preflight when they hold only
	 * the interface, which is why {@see RestorePreflight} is cheap to construct.
	 *
	 * @return RestorePreflight
	 */
	public function preflight(): RestorePreflight {
		return $this->preflight;
	}

	/**
	 * Remove every path this run's FileWriter newly created, except any $preserved_paths still declares.
	 *
	 * Exposed so a caller that already has a wired runner — a failed import's own
	 * recovery handler, in ImportCommand and RestoreController — can undo exactly
	 * what THIS run created after replaying the pre-import safety archive, without
	 * rebuilding the FileWriter and risking a mismatch with the one that actually
	 * did the writing. Deliberately NOT on {@see RestoreRunnerInterface}: that
	 * contract is part of the public API frozen at v1.0.0, and adding a method to
	 * an interface breaks every implementer. A caller holding only the interface
	 * (a test fake, most obviously) has no ledger to consult and no cleanup to run
	 * — see each call site's own `instanceof RestoreRunner` guard.
	 *
	 * See {@see FileWriter::remove_created_paths()} for what "newly created" means,
	 * the three rules deletion obeys, and why a directory only ever disappears once
	 * it is genuinely empty.
	 *
	 * @param array<int, string> $preserved_paths Relative paths the safety archive also declares; never removed even when this run's writer created them.
	 * @return CreationLedgerCleanupReport What was removed, what could not be, and whether the ledger recorded every creation.
	 */
	public function remove_created_paths( array $preserved_paths ): CreationLedgerCleanupReport {
		return $this->file_writer->remove_created_paths( $preserved_paths );
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
	 * Before any of that — before the database staging even begins, let
	 * alone the entry walk — the {@see RestorePreflight} checks are settled.
	 * The archive's recorded scope is held against the entries it carries;
	 * this host is asked whether it can create a symlink at all, because a
	 * host with symlinks disabled cannot be trusted to finish the walk once
	 * it reaches the archive's first symlink entry; every symlink the archive
	 * declares is resolved and confined, so a hostile archive is refused with
	 * the site untouched rather than part written; and the destination is
	 * asked whether it has room, so a disk that fills part-way through fails
	 * closed up front rather than leaving the site half old, half new.
	 *
	 * Three of those four change nothing, so verify and a dry run run them too
	 * — reported rather than thrown — which is what stopped verify calling an
	 * archive sound that a restore would refuse.
	 *
	 * @param resource      $archive_source    A seekable, readable stream containing a Pontifex archive.
	 * @param callable|null $on_entry_restored Optional per-entry progress callback, called as `( int $done, int $total ): void`.
	 * @param callable|null $on_bytes          Optional byte-progress callback forwarded to each entry's read, called as `( int $bytes ): void`.
	 * @throws InvalidArgumentException If $archive_source is not a valid stream resource or is not seekable.
	 * @throws RuntimeException         If the archive is malformed, hash verification fails, this host cannot create the symlinks the archive declares, a declared symlink's target would reach out of the site, the destination lacks the free disk space this restore needs, or any worker fails.
	 */
	public function restore( $archive_source, ?callable $on_entry_restored = null, ?callable $on_bytes = null ): void {
		// Reset the writer's staging state and sweep leftovers a crashed earlier
		// run may have abandoned (ADR 0009). The archive's database character set
		// rides along from provenance: the replayed SQL's bytes were captured
		// under it, so the connection must speak it for the replay's duration or
		// multibyte content is silently transcoded. Provenance is validated by
		// the reader; the charset string itself is validated by the writer.
		$reader     = new ArchiveReader( $archive_source, $this->memory_limit_bytes );
		$provenance = $reader->provenance();
		$manifest   = $reader->manifest();

		// Fail closed on an archive that lies about its own scope: one whose
		// recorded scope declares a half absent while the manifest actually
		// carries it (ADR 0016). Pontifex's own exports never contradict their
		// scope, so this only catches a corrupt or hand-forged archive — refuse
		// it rather than restore contents the scope says are not there.
		$this->preflight->assert_scope_consistent_with_manifest( $provenance->scope(), $manifest );

		// Establish the host CAN create a symlink at all before spending any
		// work deciding whether the declared targets are SAFE. A host with
		// "symlink" disabled would otherwise be discovered only once the walk
		// below reaches the archive's first symlink entry — by which point
		// every file entry ahead of it has already overwritten the live site.
		// See FileWriter::assert_symlinks_creatable() for the full reasoning.
		// This is the one preflight that writes (a test symlink, removed again),
		// which is why verify() cannot run it and a dry run explicitly can.
		// It returns the declared links it read, so the confinement check below
		// judges the same set without a second pass over the archive.
		$declared_symlink_targets = $this->preflight->assert_host_can_write( $archive_source, $manifest );

		// Decide every symlink the archive declares BEFORE the first byte is
		// written. A symlink's target is the one archive field that says "go and
		// read that other file instead", so a hostile archive can use it to point
		// a web-readable file in uploads at wp-config.php — and the trick that
		// does it needs two links working together, which means no single entry
		// looks wrong on its own. Judging the whole set up front is therefore the
		// only sound moment, and it is also the only SAFE moment: the walk below
		// has no per-entry recovery, so a refusal part-way would leave a site that
		// is neither the old one nor the archive's. See
		// FileWriter::assert_symlink_targets_confined() for the attack and the
		// resolution rule. This check changes nothing, so verify() now runs it too
		// and refuses the same archive rather than calling it sound.
		$this->preflight->assert_symlink_targets_confined( $declared_symlink_targets );

		// Sweep leftover temp artefacts a crashed earlier restore abandoned on
		// the filesystem — the file-side twin of the leftover-table sweep
		// DatabaseWriter::begin_staging() performs a little further below
		// (ADR 0009). Runs after every OTHER preflight above has already had
		// its chance to refuse — this one is not itself among them, and it is
		// not side-effect-free: it writes a one-off case-sensitivity probe
		// file of its own, removed again before it reasons about an orphan.
		// See FileWriter::sweep_orphaned_temp_files() for why this is
		// best-effort and can never itself gate a restore.
		//
		// It runs BEFORE the free-space preflight immediately below,
		// deliberately: a leftover from a crashed earlier restore can be as
		// large as however much of that attempt had been written before it
		// died, and removing it is exactly what can let the free-space
		// preflight pass on the retry it would otherwise wrongly refuse.
		// SafetyArchiver::create() reached the same conclusion for the same
		// reason ahead of its own free-space preflight — see the comment
		// there, next to {@see \Pontifex\Rollback\SafetyArchiver::sweep_orphaned_archive_temps()}
		// — so the two now agree.
		$swept = $this->file_writer->sweep_orphaned_temp_files();
		if ( $swept > 0 ) {
			$this->logger->info(
				'Removed temporary files an interrupted earlier restore left behind.',
				array( 'count' => $swept )
			);
		}

		// Refuse before anything is touched — filesystem or database — when the
		// destination cannot hold this restore. FileWriter owns the destination
		// directory, so it owns this estimate; see its own docblock for why the
		// figure leans low rather than risk refusing a restore that would have
		// succeeded.
		$this->preflight->assert_free_space_for( $manifest );

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

			// Apply the directory modes FileWriter held back during the walk. A
			// directory the archive records as unwritable — a hardened uploads
			// tree, say — cannot be made unwritable while its own contents are
			// still being written, so its mode waits until here. Deliberately
			// after the database cut-over and inside the try: the restore is
			// complete either way, and a mode that will not apply is reported
			// rather than thrown, so a finished restore is never announced as a
			// failure over a permission bit.
			$unapplied = $this->file_writer->finalise_directory_modes();
			foreach ( $unapplied as $path ) {
				$this->logger->warning(
					'Restored, but this directory kept a more permissive mode than the backup recorded.',
					array( 'path' => $path )
				);
			}
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
	 * The defensive budgets are computed exactly as {@see self::walk()} computes
	 * them, so a hostile or oversized archive is refused here on terms at least
	 * as strict as restore()'s — but the running archive-total accumulation is
	 * deliberately NOT symmetric with walk(), and that asymmetry is the point,
	 * not a gap. walk() accumulates each entry's ACTUAL decoded size
	 * ({@see \Pontifex\Archive\Reader\EntryReadResult::decoded_size()}), because
	 * restore() decodes every entry as it writes it. This method never decodes
	 * anything (ADR 0010), so the only size it has for an entry is the header's
	 * DECLARED size ({@see \Pontifex\Archive\Format\EntryHeader::estimated_bytes()})
	 * — and for a file entry that declared size IS trustworthy, because
	 * {@see \Pontifex\Archive\Writer\EntryWriter} corrects it at write time and
	 * {@see \Pontifex\Archive\Reader\EntryReader} enforces declared == actual on
	 * both decode paths. A db_chunk's declared size is not: it is a PREDICTION
	 * — {@see \Pontifex\Manifest\DatabaseScanner} sizes a chunk as
	 * `( $limit * $row_bytes ) + …`, rows-per-chunk times MySQL's own
	 * `Avg_row_length` — and the last chunk of every table under-fills that
	 * estimate, sometimes by several times over. Only file entries' declared
	 * sizes are therefore accumulated into the running total below; a
	 * db_chunk's declared size is still checked against the per-entry ceiling
	 * (every entry is, regardless of kind), just never added to the running
	 * archive-total. Counting it would have made verify() STRICTER than the
	 * restore it is meant to vouch for — a real stock-WordPress database-only
	 * archive measured declared db_chunk totals up to 3.8x the actual decoded
	 * total — so verify() could refuse an archive restore() would accept, the
	 * opposite of what it exists to guard against. Excluding db_chunks makes
	 * the running total a LOWER bound on the true total: verify() can now only
	 * under-refuse relative to restore(), never over-refuse, which is the safe
	 * direction. A hostile db_chunk declaring an enormous size is still caught
	 * by the per-entry ceiling; one that inflates only at decode time is still
	 * caught by restore()'s own walk(), which sums real sizes. Separately, a
	 * FILE header that understates its true decoded size still passes
	 * verification and is only caught when restore() actually decodes it —
	 * that narrower gap is accepted, because verify's job is to catch what can
	 * be known without decoding, not to close it by making verification decode.
	 *
	 * @param resource      $archive_source    A seekable, readable stream containing a Pontifex archive.
	 * @param callable|null $on_entry_verified Optional per-entry progress callback, called as `( int $done, int $total ): void`.
	 * @param callable|null $on_bytes          Optional byte-progress callback forwarded to each entry's verify read, called as `( int $bytes ): void`.
	 * @throws RuntimeException If the archive is malformed, declares too many entries, the running declared total of its file entries exceeds the archive's decoded-byte budget, or hash verification fails.
	 */
	public function verify( $archive_source, ?callable $on_entry_verified = null, ?callable $on_bytes = null ): void {
		$reader   = new ArchiveReader( $archive_source, $this->memory_limit_bytes );
		$manifest = $reader->manifest();
		$entries  = $manifest->entries();
		$total    = count( $entries );

		// The entry count is checked here, after the decode, because it cannot
		// be checked before one: a count derived from the manifest's declared
		// byte length either sits above the format's own structural cap (and
		// so never fires) or falsely refuses legitimate archives whose entries
		// have long paths. What ArchiveReader does check before decoding is
		// whether the decode will fit in memory at all — a different question,
		// and the one that actually protects the request.
		if ( $total > $this->limits->max_entry_count() ) {
			throw new RuntimeException(
				sprintf(
					'Archive declares %d entries, exceeding the maximum of %d.',
					(int) $total,
					(int) $this->limits->max_entry_count()
				)
			);
		}

		$total_budget   = $this->limits->max_total_for_archive( $this->stream_size( $archive_source ) );
		$decoded_so_far = 0;

		$done = 0;
		foreach ( $entries as $manifest_entry ) {
			// The bomb ceiling (per-entry and archive-total decoded bytes) applies to
			// every entry; the memory-derived budget is passed separately, because it
			// applies only to entries the reader must buffer whole — a plain file
			// entry streams through chunk-sized memory to a spool (ADR 0010). Mirrors
			// walk()'s own per-entry limit exactly.
			$remaining   = $total_budget - $decoded_so_far;
			$entry_limit = $this->limits->max_entry_bytes() < $remaining ? $this->limits->max_entry_bytes() : $remaining;

			$declared_size = $this->entry_reader->verify_entry(
				$archive_source,
				$manifest_entry,
				$on_bytes,
				$entry_limit,
				$this->entry_memory_budget > 0 ? $this->entry_memory_budget : null
			);

			// A db_chunk's declared size is a prediction (DatabaseScanner's
			// rows-per-chunk * average-row-length estimate), not a measurement, and
			// routinely overstates the real total by several times over — see this
			// method's own docblock. Only a file entry's declared size (corrected at
			// write time, and enforced against its actual decoded size on every read
			// path) is trustworthy enough to accumulate here; a db_chunk still meets
			// the per-entry ceiling check above, it just never inflates this running
			// total, so verify() can only under-refuse relative to restore(), never
			// over-refuse it.
			if ( ! $manifest_entry->is_db_chunk() ) {
				$decoded_so_far += $declared_size;
			}
			if ( $decoded_so_far > $total_budget ) {
				throw new RuntimeException(
					sprintf(
						'Restored data exceeds the maximum of %d bytes permitted for this archive.',
						(int) $total_budget
					)
				);
			}

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
		$reader   = new ArchiveReader( $archive_source, $this->memory_limit_bytes );
		$manifest = $reader->manifest();

		$entries = $manifest->entries();
		$total   = count( $entries );
		$done    = 0;

		// The entry count is checked here, after the decode, because it cannot
		// be checked before one: a count derived from the manifest's declared
		// byte length either sits above the format's own structural cap (and
		// so never fires) or falsely refuses legitimate archives whose entries
		// have long paths. What ArchiveReader does check before decoding is
		// whether the decode will fit in memory at all — a different question,
		// and the one that actually protects the request.
		if ( $total > $this->limits->max_entry_count() ) {
			throw new RuntimeException(
				sprintf(
					'Archive declares %d entries, exceeding the maximum of %d.',
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
						'Restored data exceeds the maximum of %d bytes permitted for this archive.',
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
			sprintf( 'Unsupported entry kind "%s" at manifest index %d.', $manifest_entry->kind(), (int) $manifest_entry->index() )
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
			throw new RuntimeException( 'Could not seek to the end of the archive to measure its size.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_ftell -- Measuring an open archive stream resource; WP_Filesystem has no equivalent.
		$size = ftell( $archive_source );
		if ( false === $size ) {
			throw new RuntimeException( 'Could not determine the archive size.' );
		}
		return $size;
	}
}
