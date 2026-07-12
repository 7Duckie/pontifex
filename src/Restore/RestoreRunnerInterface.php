<?php
/**
 * Pontifex restore-runner contract — the seam through which a command drives a restore.
 *
 * @package Pontifex\Restore
 */

declare(strict_types=1);

namespace Pontifex\Restore;

use InvalidArgumentException;
use RuntimeException;

/**
 * Contract for a full restore from a Pontifex archive stream.
 *
 * Extracted from {@see RestoreRunner} so the CLI layer can depend on
 * the behaviour rather than on the final concrete class — the same
 * interface-around-final pattern ExportCommand uses with
 * {@see \Pontifex\Manifest\ManifestBuilderInterface}. A command holds a
 * RestoreRunnerInterface; production wires the concrete RestoreRunner;
 * unit tests substitute a fake that can be made to throw.
 *
 * Two operations share the same read-and-verify walk:
 *
 *  - {@see RestoreRunnerInterface::restore()} — read, verify, AND write
 *    every entry to the destination filesystem and database.
 *  - {@see RestoreRunnerInterface::verify()} — read and verify every
 *    entry but write NOTHING; the engine behind a dry-run import and the
 *    future `wp pontifex verify` command.
 *
 * Both accept an optional progress callback invoked once per entry as
 * `$on_entry( int $done, int $total )`, mirroring the per-entry hook on
 * {@see \Pontifex\Archive\Writer\ArchiveWriter::write_archive()}. The
 * callback is optional so non-CLI callers and tests can ignore progress.
 */
interface RestoreRunnerInterface {

	/**
	 * Read, verify, and write every entry from the archive stream.
	 *
	 * Opens the archive, reads the manifest, then for each entry in
	 * manifest order decodes and verifies it and writes it to the
	 * destination (filesystem or database). Fail-fast: the first failure
	 * halts the restore, and anything already written stays.
	 *
	 * @param resource      $archive_source    A seekable, readable stream containing a Pontifex archive.
	 * @param callable|null $on_entry_restored Optional per-entry progress callback, called as `( int $done, int $total ): void`.
	 * @param callable|null $on_bytes          Optional byte-progress callback called as `( int $bytes ): void` with each chunk's byte count as an entry is read, so a caller can report progress within a large entry.
	 * @throws InvalidArgumentException If $archive_source is not a valid, seekable stream resource.
	 * @throws RuntimeException         If the archive is malformed, hash verification fails, or any writer fails.
	 */
	public function restore( $archive_source, ?callable $on_entry_restored = null, ?callable $on_bytes = null ): void;

	/**
	 * Read and hash-verify every entry from the archive stream, writing nothing.
	 *
	 * Opens the archive, reads the manifest, and hash-verifies each entry's stored
	 * bytes against the manifest — without decoding or buffering whole entries and
	 * without routing anything to the filesystem or database writers. Touches
	 * nothing on the destination; the engine behind both the Verify screen and a
	 * dry-run import.
	 *
	 * @param resource      $archive_source    A seekable, readable stream containing a Pontifex archive.
	 * @param callable|null $on_entry_verified Optional per-entry progress callback, called as `( int $done, int $total ): void`.
	 * @param callable|null $on_bytes          Optional byte-progress callback called as `( int $bytes ): void` with each chunk's byte count as an entry streams, so a caller can report progress within a large entry.
	 * @throws InvalidArgumentException If $archive_source is not a valid stream resource.
	 * @throws RuntimeException         If the archive is malformed, declares too many entries, or hash verification fails.
	 */
	public function verify( $archive_source, ?callable $on_entry_verified = null, ?callable $on_bytes = null ): void;
}
