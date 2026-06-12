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
use Pontifex\Archive\Format\ManifestEntry;
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
 *    collaborators that do the actual work. Stateless after
 *    construction; safe to reuse across many archives.
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
 *  4. If any step throws, the restore halts immediately. v0.1.0 is
 *     fail-fast: anything already written stays on disk / in the
 *     database. Partial-restore cleanup is a Phase 4 (CLI) concern.
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
	 * Construct a RestoreRunner with its three collaborators.
	 *
	 * @param EntryReader    $entry_reader    Decodes individual entry records.
	 * @param FileWriter     $file_writer     Writes filesystem entries to disk.
	 * @param DatabaseWriter $database_writer Replays db_chunk entries into the database.
	 */
	public function __construct( EntryReader $entry_reader, FileWriter $file_writer, DatabaseWriter $database_writer ) {
		$this->entry_reader    = $entry_reader;
		$this->file_writer     = $file_writer;
		$this->database_writer = $database_writer;
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
	 * @throws InvalidArgumentException If $archive_source is not a valid stream resource or is not seekable.
	 * @throws RuntimeException         If the archive is malformed, hash verification fails, or any worker fails.
	 */
	public function restore( $archive_source, ?callable $on_entry_restored = null ): void {
		$this->walk(
			$archive_source,
			$on_entry_restored,
			function ( ManifestEntry $manifest_entry, EntryReadResult $result ): void {
				$this->dispatch( $manifest_entry, $result );
			}
		);
	}

	/**
	 * Read and verify every entry from the archive stream, writing nothing.
	 *
	 * Runs the identical read-and-verify walk as {@see self::restore()} —
	 * opening the archive, reading the manifest, decoding and
	 * hash-verifying each entry — but never routes an entry to a writer,
	 * so the destination filesystem and database are left untouched. The
	 * engine behind a dry-run import.
	 *
	 * @param resource      $archive_source    A seekable, readable stream containing a Pontifex archive.
	 * @param callable|null $on_entry_verified Optional per-entry progress callback, called as `( int $done, int $total ): void`.
	 * @throws InvalidArgumentException If $archive_source is not a valid stream resource or is not seekable.
	 * @throws RuntimeException         If the archive is malformed or hash verification fails.
	 */
	public function verify( $archive_source, ?callable $on_entry_verified = null ): void {
		$this->walk(
			$archive_source,
			$on_entry_verified,
			static function (): void {
				// Read and verify only: nothing is written to the destination.
			}
		);
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
	 * @param resource      $archive_source A seekable, readable stream containing a Pontifex archive.
	 * @param callable|null $on_entry       Optional per-entry progress callback, called as `( int $done, int $total ): void`.
	 * @param callable      $handle         Receives each decoded entry as `( ManifestEntry $entry, EntryReadResult $result ): void`.
	 * @throws InvalidArgumentException If $archive_source is not a valid stream resource or is not seekable.
	 * @throws RuntimeException         If the archive is malformed, hash verification fails, or $handle fails.
	 */
	private function walk( $archive_source, ?callable $on_entry, callable $handle ): void {
		$reader   = new ArchiveReader( $archive_source );
		$manifest = $reader->manifest();

		$entries = $manifest->entries();
		$total   = count( $entries );
		$done    = 0;

		foreach ( $entries as $manifest_entry ) {
			$result = $this->entry_reader->read_entry( $archive_source, $manifest_entry );
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
}
