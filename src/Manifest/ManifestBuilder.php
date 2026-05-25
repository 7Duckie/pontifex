<?php
/**
 * Pontifex manifest builder — orchestrates scanners into a unified EntryPlan list.
 *
 * @package Pontifex\Manifest
 */

declare(strict_types=1);

namespace Pontifex\Manifest;

use InvalidArgumentException;
use RuntimeException;
use Pontifex\Archive\Codec\GzipCodec;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Writer\EntryPlan;
use Pontifex\Archive\Writer\EntryWriter;

/**
 * Orchestrates FileScanner and DatabaseScanner into a unified EntryPlan list.
 *
 * The final piece of the writer-side manifest layer. ManifestBuilder
 * is responsible for two things:
 *
 *  1. Calling FileScanner against the WordPress root and converting
 *     each ScannedEntry into an EntryPlan with an opened source stream.
 *  2. Calling DatabaseScanner and converting each ScannedDbChunk into
 *     an EntryPlan whose source stream is the chunk's lazily-produced
 *     SQL bytes.
 *
 * The returned list is the input to {@see \Pontifex\Archive\Writer\ArchiveWriter::write_archive()},
 * making this class the bridge between manifest enumeration and
 * archive writing.
 *
 * Public API (locked for v0.1.0):
 *
 *  - {@see ManifestBuilder::__construct()} — takes the two scanners.
 *    Exclusion rules are already baked into the scanners themselves;
 *    ManifestBuilder is purely a wiring layer.
 *  - {@see ManifestBuilder::build()} — produces the EntryPlan list
 *    for the given WordPress root.
 *
 * Internal choices (implementation details; may change between
 * versions without breaking the public API):
 *
 *  - Codec: gzip for every entry. Simple, predictable, well-tested.
 *    Future versions may choose codecs per entry kind or per file
 *    extension (e.g. skip gzip on already-compressed JPEGs).
 *  - Nonce: 12 zero bytes for every entry. v0.1.0 archives are
 *    unencrypted; non-zero nonces become meaningful when encryption
 *    lands in a later phase.
 *  - Ordering: file entries first (alphabetical by relative_path),
 *    db_chunk entries second (alphabetical by table_name, then by
 *    chunk_index). Deterministic and easy to reason about.
 *  - Source streams: files use fopen($absolute_path, 'rb'); directory
 *    and symlink entries get empty php://memory streams (their
 *    meaningful data lives in the EntryHeader, not the payload).
 */
final class ManifestBuilder {

	/**
	 * Codec id used for every entry in v0.1.0.
	 *
	 * @var int
	 */
	private const DEFAULT_CODEC_ID = GzipCodec::ID;

	/**
	 * Scanner that walks the filesystem.
	 *
	 * @var FileScanner
	 */
	private FileScanner $file_scanner;

	/**
	 * Scanner that walks the database.
	 *
	 * @var DatabaseScanner
	 */
	private DatabaseScanner $database_scanner;

	/**
	 * Construct a ManifestBuilder with the two scanner dependencies.
	 *
	 * Exclusion rules are baked into the scanners themselves at their
	 * own construction time; ManifestBuilder does not see them
	 * separately.
	 *
	 * @param FileScanner     $file_scanner     Walker for the filesystem.
	 * @param DatabaseScanner $database_scanner Walker for the database.
	 */
	public function __construct( FileScanner $file_scanner, DatabaseScanner $database_scanner ) {
		$this->file_scanner     = $file_scanner;
		$this->database_scanner = $database_scanner;
	}

	/**
	 * Build a complete EntryPlan list for the given WordPress installation.
	 *
	 * The returned list is ready to feed to ArchiveWriter::write_archive().
	 * Each EntryPlan carries an opened source stream; the caller is
	 * responsible for closing the streams (typically by passing the
	 * EntryPlans through ArchiveWriter, which closes them as it
	 * consumes them).
	 *
	 * @param string $wordpress_root Absolute filesystem path of the WP installation.
	 * @return EntryPlan[] All entries that should be archived, in deterministic order.
	 * @throws InvalidArgumentException If $wordpress_root is empty or not a directory.
	 * @throws RuntimeException         If a file source stream cannot be opened.
	 */
	public function build( string $wordpress_root ): array {
		$plans = array();

		// Filesystem entries first, then database chunks.
		// Both scanners already return their output sorted; we preserve that order.
		foreach ( $this->file_scanner->scan( $wordpress_root ) as $scanned_entry ) {
			$plans[] = self::plan_for_scanned_entry( $scanned_entry );
		}
		foreach ( $this->database_scanner->scan() as $scanned_chunk ) {
			$plans[] = self::plan_for_scanned_db_chunk( $scanned_chunk );
		}

		return $plans;
	}

	/**
	 * Convert a ScannedEntry into an EntryPlan.
	 *
	 * Files get an fopen() on their absolute path; directories and
	 * symlinks get an empty in-memory stream because their meaningful
	 * data lives entirely in the EntryHeader.
	 *
	 * @param ScannedEntry $entry The filesystem scan result.
	 * @return EntryPlan A plan ready for ArchiveWriter.
	 * @throws RuntimeException If the file's source stream cannot be opened.
	 */
	private static function plan_for_scanned_entry( ScannedEntry $entry ): EntryPlan {
		$header = self::header_for_scanned_entry( $entry );
		$source = self::open_source_for_scanned_entry( $entry );
		return new EntryPlan( $header, self::DEFAULT_CODEC_ID, self::zero_nonce(), $source );
	}

	/**
	 * Construct the draft EntryHeader for a ScannedEntry, dispatching on kind.
	 *
	 * @param ScannedEntry $entry The scan result.
	 * @return EntryHeader The matching header (size_compressed=0; corrected at write time).
	 * @throws RuntimeException If the entry kind is unrecognised.
	 */
	private static function header_for_scanned_entry( ScannedEntry $entry ): EntryHeader {
		switch ( $entry->kind() ) {
			case EntryHeader::KIND_FILE:
				return EntryHeader::for_file(
					$entry->relative_path(),
					$entry->size(),
					$entry->mode(),
					$entry->mtime(),
					0
				);
			case EntryHeader::KIND_DIRECTORY:
				return EntryHeader::for_directory( $entry->relative_path(), $entry->mode(), 0 );
			case EntryHeader::KIND_SYMLINK:
				return EntryHeader::for_symlink( $entry->relative_path(), (string) $entry->target(), 0 );
			default:
				throw new RuntimeException(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $entry->kind() is a validated KIND_* constant; reported verbatim for diagnostic context, exception path.
					sprintf( 'ManifestBuilder: unsupported ScannedEntry kind "%s".', $entry->kind() )
				);
		}
	}

	/**
	 * Open a readable source stream for a ScannedEntry.
	 *
	 * Files: fopen the absolute path in binary read mode. Directories
	 * and symlinks: an empty php://memory stream, since the entry's
	 * meaningful data is in the header (mode/target) not the payload.
	 *
	 * @param ScannedEntry $entry The scan result.
	 * @return resource A readable stream resource positioned at offset 0.
	 * @throws RuntimeException If a file cannot be opened.
	 */
	private static function open_source_for_scanned_entry( ScannedEntry $entry ) {
		if ( EntryHeader::KIND_FILE === $entry->kind() ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Filesystem read for archive payload; WP_Filesystem cannot return a stream resource.
			$stream = fopen( $entry->absolute_path(), 'rb' );
			if ( false === $stream ) {
				throw new RuntimeException(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $entry->absolute_path() reported verbatim for diagnostic context; exception path, not HTML output.
					sprintf( 'ManifestBuilder: could not open file "%s" for reading.', $entry->absolute_path() )
				);
			}
			return $stream;
		}

		return self::open_empty_memory_stream();
	}

	/**
	 * Convert a ScannedDbChunk into an EntryPlan.
	 *
	 * The chunk's lazily-materialised SQL stream becomes the
	 * EntryPlan's source. Statement and byte counts come straight
	 * from the chunk.
	 *
	 * @param ScannedDbChunk $chunk The database scan result.
	 * @return EntryPlan A plan ready for ArchiveWriter.
	 */
	private static function plan_for_scanned_db_chunk( ScannedDbChunk $chunk ): EntryPlan {
		$header = EntryHeader::for_db_chunk(
			$chunk->chunk_index(),
			$chunk->table_name(),
			$chunk->statement_count(),
			$chunk->byte_count(),
			0
		);
		$source = $chunk->open_sql_stream();
		return new EntryPlan( $header, self::DEFAULT_CODEC_ID, self::zero_nonce(), $source );
	}

	/**
	 * Return the all-zero nonce used for every v0.1.0 entry.
	 *
	 * Encryption arrives in a later phase. For now nonces are
	 * uniformly zero; the field exists in the format so future
	 * encryption upgrades don't require a format-level change.
	 *
	 * @return string A NONCE_SIZE-byte string of NUL bytes.
	 */
	private static function zero_nonce(): string {
		return str_repeat( "\0", EntryWriter::NONCE_SIZE );
	}

	/**
	 * Open an empty in-memory stream suitable for directory and symlink payloads.
	 *
	 * Directories and symlinks carry no payload bytes in v0.1.0;
	 * their meaningful data lives entirely in the EntryHeader. We
	 * still need a readable stream to satisfy EntryPlan's contract,
	 * so we hand back an empty php://memory buffer.
	 *
	 * @return resource A readable, empty in-memory stream positioned at offset 0.
	 * @throws RuntimeException If php://memory cannot be opened.
	 */
	private static function open_empty_memory_stream() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://memory is an in-process buffer, not a file.
		$stream = fopen( 'php://memory', 'r+b' );
		if ( false === $stream ) {
			throw new RuntimeException( 'ManifestBuilder: could not open php://memory for empty payload.' );
		}
		return $stream;
	}
}
