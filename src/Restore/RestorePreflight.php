<?php
/**
 * The checks a restore settles before it writes anything — in one place, for every caller.
 *
 * @package Pontifex\Restore
 */

declare(strict_types=1);

namespace Pontifex\Restore;

use Pontifex\Archive\Format\ArchiveManifest;
use Pontifex\Archive\Format\Scope;
use Pontifex\Archive\Reader\ArchiveLimits;
use Pontifex\Archive\Reader\EntryReader;
use Pontifex\Exception\ArchiveNotTrustworthy;
use Throwable;

/**
 * Everything a restore establishes before the first byte is written.
 *
 * These four checks used to live inside {@see RestoreRunner::restore()}, which
 * meant only a real restore could ever run them. {@see RestoreRunner::verify()}
 * writes nothing, so it ran none of them — and that is the whole of the problem
 * this class exists to fix. An operator who verified a backup was told it was
 * SOUND, and then found the restore refusing the very same file. Verify had made
 * a promise the restore did not keep.
 *
 * The fix is not to make verify do a restore. It is to notice that these checks
 * fall into two groups, and that the grouping is what decides who can run them:
 *
 *  - **Three are read-only.** The scope-versus-manifest contradiction, the
 *    symbolic-link confinement resolution, and the free-space estimate all
 *    observe and calculate; none of them change anything. Any caller can run
 *    them, including one that promises to write nothing.
 *  - **One writes.** {@see FileWriter::assert_symlinks_creatable()} finds out
 *    whether this host can create a symbolic link the only way that can be
 *    known for certain — by creating one and removing it again. That is a real
 *    write, however brief, so it belongs only to callers that were going to
 *    write anyway: a restore, and a dry run that is explicitly rehearsing one.
 *
 * Hence the two entry points. {@see self::read_only_report()} runs the first
 * group and REPORTS, so verify can describe everything it found rather than
 * stopping at the first thing. {@see self::assert_host_can_write()} runs the
 * second and THROWS, because by the time a caller asks that question it has
 * already decided to write.
 *
 * {@see RestoreRunner::restore()} calls straight through to the same methods in
 * the same order it always did, so the restore path's behaviour is unchanged —
 * same checks, same sequence, same messages, same exception types. What changed
 * is only that other callers can now reach them too.
 */
final class RestorePreflight {

	/**
	 * Name of the scope-versus-manifest check, as it appears in a report.
	 *
	 * @var string
	 */
	public const CHECK_SCOPE = 'scope';

	/**
	 * Name of the symbolic-link confinement check, as it appears in a report.
	 *
	 * @var string
	 */
	public const CHECK_SYMLINK_CONFINEMENT = 'symlink_confinement';

	/**
	 * Name of the free-disk-space check, as it appears in a report.
	 *
	 * @var string
	 */
	public const CHECK_FREE_SPACE = 'free_space';

	/**
	 * Name of the host symbolic-link capability check, as it appears in a report.
	 *
	 * @var string
	 */
	public const CHECK_SYMLINKS_CREATABLE = 'symlinks_creatable';

	/**
	 * The decoder used to read each declared symlink entry's header.
	 *
	 * @var EntryReader
	 */
	private EntryReader $entry_reader;

	/**
	 * The writer that owns the destination, and so owns every check about it.
	 *
	 * @var FileWriter
	 */
	private FileWriter $file_writer;

	/**
	 * Defensive limits enforced while reading a symlink entry.
	 *
	 * @var ArchiveLimits
	 */
	private ArchiveLimits $limits;

	/**
	 * The per-entry decoded-byte budget, or 0 for no memory-derived cap.
	 *
	 * @var int
	 */
	private int $entry_memory_budget;

	/**
	 * Construct a preflight over the collaborators that own the destination.
	 *
	 * @param EntryReader        $entry_reader        Reads each declared symlink entry's header.
	 * @param FileWriter         $file_writer         Owns the destination, and every check about it.
	 * @param ArchiveLimits|null $limits              Defensive limits; null applies the conservative defaults.
	 * @param int|null           $entry_memory_budget The per-entry decoded-byte budget; 0 or null applies no memory-derived cap.
	 */
	public function __construct( EntryReader $entry_reader, FileWriter $file_writer, ?ArchiveLimits $limits = null, ?int $entry_memory_budget = null ) {
		$this->entry_reader        = $entry_reader;
		$this->file_writer         = $file_writer;
		$this->limits              = $limits ?? ArchiveLimits::defaults();
		$this->entry_memory_budget = ( null !== $entry_memory_budget && $entry_memory_budget > 0 ) ? $entry_memory_budget : 0;
	}

	/**
	 * Run every check that changes nothing, and report all of them.
	 *
	 * Deliberately does NOT stop at the first finding. A restore must stop, because
	 * it is about to overwrite a site; a caller that writes nothing is better off
	 * knowing everything that is wrong in one pass, so an operator fixes one thing
	 * and is not immediately told about the next.
	 *
	 * The host capability probe is not here, and must not be added: it creates a
	 * test symbolic link, which would break the promise that verify touches nothing.
	 * Callers that are going to write call {@see self::assert_host_can_write()} as
	 * well.
	 *
	 * @param resource        $archive_source A seekable, readable stream containing the archive.
	 * @param ArchiveManifest $manifest       The archive's already-decoded manifest.
	 * @param Scope|null      $scope          The archive's recorded scope, or null for a legacy archive that records none.
	 * @return PreflightReport What each check found; empty when a restore would not be refused.
	 */
	public function read_only_report( $archive_source, ArchiveManifest $manifest, ?Scope $scope ): PreflightReport {
		$checks_run       = array();
		$archive_findings = array();
		$host_findings    = array();
		$inconclusive     = array();

		// Only a refusal one of the checks DECIDED counts as a finding. Anything
		// else that goes wrong on the way — a stream that will not seek, a
		// collaborator that cannot be built — means the question was never
		// answered, and an unanswered question must not be reported as a refusal.
		// Getting that wrong would tell somebody a good backup was hostile, which
		// is a far worse error than staying silent: a restore still runs these
		// same checks and still fails closed, so this report is advice, never the
		// last line of defence.
		$record = function ( string $check, Throwable $refusal ) use ( &$archive_findings, &$host_findings, &$inconclusive ): void {
			if ( $refusal instanceof ArchiveNotTrustworthy ) {
				$archive_findings[ $check ] = $refusal->getMessage();
				return;
			}
			if ( PreflightReport::is_host_finding( $refusal ) ) {
				$host_findings[ $check ] = $refusal->getMessage();
				return;
			}
			$inconclusive[ $check ] = $refusal->getMessage();
		};

		$checks_run[] = self::CHECK_SCOPE;
		try {
			$this->assert_scope_consistent_with_manifest( $scope, $manifest );
		} catch ( Throwable $refusal ) {
			$record( self::CHECK_SCOPE, $refusal );
		}

		$checks_run[] = self::CHECK_SYMLINK_CONFINEMENT;
		try {
			$this->file_writer->assert_symlink_targets_confined(
				$this->declared_symlink_targets( $archive_source, $manifest )
			);
		} catch ( Throwable $refusal ) {
			$record( self::CHECK_SYMLINK_CONFINEMENT, $refusal );
		}

		$checks_run[] = self::CHECK_FREE_SPACE;
		try {
			$measured = $this->file_writer->assert_free_space_for(
				$manifest->entries(),
				$this->declared_file_sizes( $archive_source, $manifest )
			);
			if ( ! $measured ) {
				// Not a refusal of any kind — the reading itself could not be taken
				// (e.g. disk_free_space() restricted or disabled on this host), so
				// the check has nothing to report either way. Written to the bucket
				// directly, in the same shape $record() produces above, rather than
				// manufacturing a throw: nothing here decided the archive is bad or
				// that this host cannot comply, only that the question could not be
				// answered.
				$inconclusive[ self::CHECK_FREE_SPACE ] = 'Whether the destination has enough free disk space could not be established on this host.';
			}
		} catch ( Throwable $refusal ) {
			$record( self::CHECK_FREE_SPACE, $refusal );
		}

		return new PreflightReport( $checks_run, $archive_findings, $host_findings, $inconclusive );
	}

	/**
	 * Establish that this host can create the symbolic links the archive declares.
	 *
	 * The one preflight that writes: it creates a test symbolic link and removes it
	 * again, because that is the only way to know for certain, and knowing for
	 * certain is the point — a host with "symlink" in disable_functions would
	 * otherwise be discovered only once the walk reached the archive's first link,
	 * by which time every file entry ahead of it has already overwritten the site.
	 *
	 * Throws rather than reports: every caller of this has already decided to write.
	 *
	 * @param resource        $archive_source A seekable, readable stream containing the archive.
	 * @param ArchiveManifest $manifest       The archive's already-decoded manifest.
	 * @return array<string, string> The declared links, so a caller that needs them again does not re-read them.
	 * @throws \Pontifex\Exception\HostCannotComply If this host cannot create a symbolic link where the archive needs one.
	 * @throws \RuntimeException                    If a symlink entry cannot be read or fails hash verification.
	 */
	public function assert_host_can_write( $archive_source, ArchiveManifest $manifest ): array {
		$declared_links = $this->declared_symlink_targets( $archive_source, $manifest );
		$this->file_writer->assert_symlinks_creatable( $declared_links );
		return $declared_links;
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
	 * @throws ArchiveNotTrustworthy If the archive's recorded scope contradicts the entries it carries.
	 */
	public function assert_scope_consistent_with_manifest( ?Scope $scope, ArchiveManifest $manifest ): void {
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
			throw new ArchiveNotTrustworthy( 'This backup says it contains files only, but it carries database content as well. A backup that contradicts its own description is refused.' );
		}
		if ( ! $scope->includes_files() && $has_files ) {
			throw new ArchiveNotTrustworthy( 'This backup says it contains the database only, but it carries files as well. A backup that contradicts its own description is refused.' );
		}
	}

	/**
	 * Resolve and confine every symbolic link the archive declares.
	 *
	 * Judged over the whole set, because the attack it closes needs two links
	 * co-operating and so no single entry looks wrong on its own (ADR 0021).
	 *
	 * @param array<string, string> $declared_links Every symlink the archive declares, as entry path => raw target.
	 * @return void
	 * @throws \Pontifex\Exception\ArchiveNotTrustworthy If a declared target would resolve outside the site.
	 */
	public function assert_symlink_targets_confined( array $declared_links ): void {
		$this->file_writer->assert_symlink_targets_confined( $declared_links );
	}

	/**
	 * Establish the destination has room for this restore.
	 *
	 * Reads every file entry's DECODED size from the archive first (see
	 * {@see self::declared_file_sizes()}) and weighs that, not the entry's
	 * stored/compressed size, against the free space this host reports.
	 * The bool {@see FileWriter::assert_free_space_for()} returns is
	 * deliberately ignored here, exactly as before that return value existed:
	 * every caller of this method has already decided to write, so an
	 * unmeasurable reading is not this method's business to report — it
	 * proceeds either way, the same posture {@see RestoreRunner::restore()}
	 * and the import dry run have always taken. {@see self::read_only_report()}
	 * is the caller that reads the bool, because it can afford to say
	 * "I don't know" instead of writing anyway.
	 *
	 * @param resource        $archive_source A seekable, readable stream containing the archive.
	 * @param ArchiveManifest $manifest       The archive's manifest.
	 * @return void
	 * @throws \Pontifex\Exception\HostCannotComply If the destination lacks the free space this restore needs.
	 */
	public function assert_free_space_for( $archive_source, ArchiveManifest $manifest ): void {
		$this->file_writer->assert_free_space_for(
			$manifest->entries(),
			$this->declared_file_sizes( $archive_source, $manifest )
		);
	}

	/**
	 * Read every file entry's DECODED byte size, so the free-space check weighs what a restore actually writes.
	 *
	 * {@see FileWriter} has no archive stream of its own — it only ever sees
	 * manifest entries, and {@see ManifestEntry::length()} is the entry's
	 * STORED size (the compressed payload plus its own record overhead), not
	 * the size a restore will actually write to disk. Only the caller, which
	 * holds both the archive stream and an {@see EntryReader}, can answer
	 * "how many bytes will this entry actually be once restored" — the same
	 * reasoning that already put {@see self::declared_symlink_targets()} here
	 * rather than in FileWriter.
	 *
	 * Reads only each file entry's HEADER, via {@see EntryReader::peek_header()}
	 * — a seek plus a small, fixed read, never the entry's payload — and takes
	 * {@see \Pontifex\Archive\Format\EntryHeader::estimated_bytes()} from it,
	 * the same accessor the safety-archive preflight already uses for the
	 * same purpose. db_chunk and directory/symlink entries are skipped: the
	 * free-space check only ever weighs file entries (a db_chunk goes to
	 * MySQL, possibly on a different volume, and directories/symlinks cost
	 * nothing worth measuring), so their sizes are never read.
	 *
	 * @param resource        $archive_source A seekable, readable stream containing the archive.
	 * @param ArchiveManifest $manifest       The archive's already-decoded manifest.
	 * @return array<string, int> Each file entry's decoded byte size, keyed by its manifest path.
	 * @throws \Pontifex\Exception\ArchiveNotTrustworthy If a file entry's header cannot be read or is malformed.
	 */
	private function declared_file_sizes( $archive_source, ArchiveManifest $manifest ): array {
		$sizes = array();

		foreach ( $manifest->entries() as $manifest_entry ) {
			if ( ! $manifest_entry->is_file() ) {
				continue;
			}

			$path = $manifest_entry->path();
			if ( null === $path ) {
				continue;
			}

			$header         = $this->entry_reader->peek_header( $archive_source, $manifest_entry );
			$sizes[ $path ] = $header->estimated_bytes();
		}

		return $sizes;
	}

	/**
	 * Collect every symlink the archive declares, as entry path => raw target.
	 *
	 * The manifest is already fully decoded by the time this runs, and it records
	 * each entry's kind and byte offset — so this seeks straight to the symlink
	 * entries and reads only those. A symlink entry's payload is empty (the target
	 * lives in its header), which makes each read a seek and a few dozen bytes.
	 * A real site has tens of thousands of files and a handful of links, so the
	 * whole pass costs nothing measurable against a restore that is about to write
	 * the site.
	 *
	 * Reading the entry rather than trusting the manifest is deliberate: the
	 * manifest records an entry's path but not a symlink's target, and the target
	 * is the field being judged. The read verifies the entry's hash on the way
	 * past, exactly as the walk will, so a corrupt symlink entry now fails here
	 * instead of part-way through the restore.
	 *
	 * @param resource        $archive_source A seekable, readable stream containing the archive.
	 * @param ArchiveManifest $manifest       The archive's already-decoded manifest.
	 * @return array<string, string> Each symlink's entry path mapped to its raw target.
	 * @throws \RuntimeException If a symlink entry cannot be read or fails hash verification.
	 */
	public function declared_symlink_targets( $archive_source, ArchiveManifest $manifest ): array {
		$declared_links = array();

		foreach ( $manifest->entries() as $manifest_entry ) {
			if ( ! $manifest_entry->is_symlink() ) {
				continue;
			}

			$result = $this->entry_reader->read_entry(
				$archive_source,
				$manifest_entry,
				$this->limits->max_entry_bytes(),
				null,
				$this->entry_memory_budget > 0 ? $this->entry_memory_budget : null
			);

			$header = $result->header();
			$path   = $header->path();
			$target = $header->target();
			if ( null === $path || null === $target ) {
				continue;
			}

			$declared_links[ $path ] = $target;
		}

		return $declared_links;
	}
}
