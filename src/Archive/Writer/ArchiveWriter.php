<?php
/**
 * Pontifex archive writer — composes header, provenance, entries, manifest, and footer into a complete .wpmig file.
 *
 * @package Pontifex\Archive\Writer
 */

declare(strict_types=1);

namespace Pontifex\Archive\Writer;

use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Pontifex\Archive\Codec\CodecId;
use Pontifex\Archive\Crypto\EncryptionContext;
use Pontifex\Archive\Format\ArchiveManifest;
use Pontifex\Archive\Format\ByteOrder;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Format\Footer;
use Pontifex\Archive\Format\Header;
use Pontifex\Archive\Format\ManifestEntry;
use Pontifex\Archive\Format\Provenance;
use Pontifex\Archive\Integrity\Sha256;

/**
 * Writes a complete Pontifex archive (.wpmig) to a destination stream.
 *
 * On-disk layout produced (ARCHIVE-FORMAT.md §3):
 *
 *   [0]                     Header        (16 bytes)
 *   [16]                    Provenance    (variable; HEADER_SIZE + JSON)
 *   [...]                   Entry records (variable; written by EntryWriter)
 *   [manifest_offset]       Manifest      (4 length + 32 hash + JSON)
 *   [total - 64]            Footer        (64 bytes)
 *
 * For each EntryPlan supplied by the caller, ArchiveWriter delegates
 * the actual entry-writing to {@see EntryWriter}, then constructs a
 * matching {@see ManifestEntry} from the offset where the entry
 * started, the total entry-record length returned by EntryWriter,
 * the entry hash returned by EntryWriter, and the kind-specific
 * identifier (path or chunk_index) carried by the entry's header.
 * The accumulated ManifestEntry instances become the archive's
 * manifest. An empty entry-plan array produces a valid empty
 * archive (no entries) — the commit-8 skeleton behaviour is the
 * empty case of this code path.
 *
 * Hash plumbing: ArchiveManifest::to_bytes() already computes the
 * SHA-256 of the manifest JSON payload and embeds it in the
 * manifest block. The Footer's manifest_hash field is the same
 * digest, so ArchiveWriter reuses it via substr() rather than
 * recomputing — the bytes are right there.
 *
 * An archive is encrypted when an EncryptionContext is supplied to
 * write_archive(): the header's encrypted flag is set, each entry is
 * encrypted with a unique per-entry nonce, and the random salt is written
 * into the footer. Without a context the archive is unencrypted and the
 * footer's argon2id_salt slot carries Footer::ZERO_SALT (sixteen zero bytes).
 *
 * Threading and reuse: ArchiveWriter is stateless after
 * construction. The write_archive() method is safe to call any
 * number of times. Each call reads from each plan's source stream
 * starting at the source's current seek position; sources that
 * have already been consumed are not safely reusable across calls
 * to write_archive().
 */
final class ArchiveWriter {

	/**
	 * Writer responsible for emitting each entry record.
	 *
	 * @var EntryWriter
	 */
	private EntryWriter $entry_writer;

	/**
	 * Writer responsible for emitting the 64-byte footer.
	 *
	 * @var FooterWriter
	 */
	private FooterWriter $footer_writer;

	/**
	 * Construct an ArchiveWriter with its injected dependencies.
	 *
	 * @param EntryWriter  $entry_writer  Writer used to emit each entry record.
	 * @param FooterWriter $footer_writer Writer used to emit the trailing 64-byte footer.
	 */
	public function __construct( EntryWriter $entry_writer, FooterWriter $footer_writer ) {
		$this->entry_writer  = $entry_writer;
		$this->footer_writer = $footer_writer;
	}

	/**
	 * Write a complete archive to the destination stream.
	 *
	 * Writes the header, provenance, every supplied entry in order,
	 * the manifest, and the footer. Returns the total byte count
	 * written. Each EntryPlan's source stream is read from its
	 * current seek position to EOF.
	 *
	 * @param Provenance             $provenance       Source-site context to embed in the provenance block.
	 * @param array<EntryPlan>       $entry_plans      Entries to write, in archive order. May be empty.
	 * @param resource               $destination      Writable stream resource; bytes are written from its current seek position and it is advanced by the returned byte count.
	 * @param callable|null          $on_entry_written Optional callback run once after each entry, as function(int $done, int $total): void; lets a caller drive a progress indicator while the archive layer stays UI-agnostic.
	 * @param EncryptionContext|null $encryption       Encryption inputs (cipher, key, salt); when supplied the header's encrypted flag is set, every entry is encrypted with a per-entry nonce, and the salt is written into the footer; null produces an unencrypted archive.
	 * @return int Total bytes written to the destination during this call.
	 * @throws InvalidArgumentException If $destination is not a stream resource or any element
	 *                                  of $entry_plans is not an EntryPlan.
	 * @throws RuntimeException         If any block fails to serialise, any write fails, or a nonce cannot be generated.
	 */
	public function write_archive( Provenance $provenance, array $entry_plans, $destination, ?callable $on_entry_written = null, ?EncryptionContext $encryption = null ): int {
		if ( ! is_resource( $destination ) ) {
			throw new InvalidArgumentException( 'ArchiveWriter: $destination must be a valid stream resource.' );
		}
		foreach ( $entry_plans as $i => $plan ) {
			if ( ! $plan instanceof EntryPlan ) {
				throw new InvalidArgumentException(
					sprintf( 'ArchiveWriter: $entry_plans[%d] must be an EntryPlan instance.', (int) $i )
				);
			}
		}

		$total = count( $entry_plans );

		// Serialise the variable-length blocks that we can build up front.
		// Provenance can throw JsonException in principle.
		// Catch and rewrap so callers only see RuntimeException for serialisation failures.
		try {
			$provenance_bytes = $provenance->to_bytes();
		} catch ( JsonException $e ) {
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message embedded for diagnostic context; not HTML output.
				'ArchiveWriter: failed to serialise the provenance block: ' . $e->getMessage()
			);
		}

		// An encrypted archive sets the encrypted flag in the header; an unencrypted one
		// uses the default (no flags). The salt and per-entry encryption are applied below.
		$header = null !== $encryption
			? new Header( Header::FORMAT_MAJOR_V1, Header::FORMAT_MINOR_V1_0, Header::FLAG_ENCRYPTED )
			: Header::current_version();

		$header_bytes  = $header->to_bytes();
		$bytes_written = 0;

		// Header.
		$bytes_written += self::write_bytes( $destination, $header_bytes );

		// Provenance.
		$bytes_written += self::write_bytes( $destination, $provenance_bytes );

		// Entries: for each plan, record the offset where the entry starts.
		// Delegate to EntryWriter, then build a ManifestEntry from the result.
		$manifest_entries = array();
		foreach ( $entry_plans as $i => $plan ) {
			$offset_before = $bytes_written;

			// For an encrypted archive, upgrade the codec id to its encrypted variant,
			// derive a unique per-entry nonce, and pass the cipher and key to the entry
			// writer. For an unencrypted archive these stay at the plan's values.
			$codec_id = $plan->codec_id();
			$nonce    = $plan->nonce();
			$cipher   = null;
			$key      = null;
			if ( null !== $encryption ) {
				$codec_id = CodecId::with_aes_gcm( $codec_id );
				$nonce    = self::encryption_nonce( $i );
				$cipher   = $encryption->cipher();
				$key      = $encryption->key();
			}

			$result = $this->entry_writer->write_entry(
				$plan->header(),
				$codec_id,
				$nonce,
				$plan->source(),
				$destination,
				$cipher,
				$key
			);

			$bytes_written     += $result->total_entry_length();
			$manifest_entries[] = self::build_manifest_entry(
				$i,
				$offset_before,
				$result->total_entry_length(),
				$result->entry_hash(),
				$plan,
				$codec_id
			);

			if ( null !== $on_entry_written ) {
				$on_entry_written( $i + 1, $total );
			}
		}

		// Manifest.
		try {
			$manifest_bytes = ( new ArchiveManifest( $manifest_entries ) )->to_bytes();
		} catch ( JsonException $e ) {
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message embedded for diagnostic context; not HTML output.
				'ArchiveWriter: failed to serialise the manifest block: ' . $e->getMessage()
			);
		}

		$manifest_offset = $bytes_written;
		$bytes_written  += self::write_bytes( $destination, $manifest_bytes );

		// Footer reuses the manifest's internal SHA-256, at offset 4 within the manifest block.
		// The bytes are identical, so a substr is correct and cheaper than recomputing.
		$manifest_hash  = substr( $manifest_bytes, ArchiveManifest::LENGTH_PREFIX_SIZE, Sha256::DIGEST_SIZE );
		$salt           = null !== $encryption ? $encryption->salt() : Footer::ZERO_SALT;
		$footer         = new Footer(
			$manifest_offset,
			strlen( $manifest_bytes ),
			$manifest_hash,
			$salt
		);
		$bytes_written += $this->footer_writer->write_footer( $footer, $destination );

		return $bytes_written;
	}

	/**
	 * Construct a ManifestEntry for one written entry, dispatching on kind.
	 *
	 * The four ManifestEntry factories share the same first three
	 * parameters (index, offset, length) and differ only in their
	 * kind-specific identifier slot (path for file/directory/symlink,
	 * chunk_index for db_chunk). This helper centralises the
	 * dispatch so the per-entry loop in write_archive() stays
	 * focused on byte-counting.
	 *
	 * @param int       $index              The 0-based position of this entry.
	 * @param int       $offset             Offset in the archive where the entry record started.
	 * @param int       $total_entry_length Total length of the written entry record on disk.
	 * @param string    $entry_hash         32 raw bytes — the SHA-256 of the entry record.
	 * @param EntryPlan $plan               The plan that produced this entry.
	 * @param int       $codec_id           The codec id the payload was written with (the encrypted variant when encrypting).
	 * @return ManifestEntry A ManifestEntry of the appropriate kind.
	 * @throws RuntimeException If the entry's header kind is not one of EntryHeader::ALL_KINDS.
	 */
	private static function build_manifest_entry(
		int $index,
		int $offset,
		int $total_entry_length,
		string $entry_hash,
		EntryPlan $plan,
		int $codec_id
	): ManifestEntry {
		$header = $plan->header();

		switch ( $header->kind() ) {
			case EntryHeader::KIND_FILE:
				return ManifestEntry::for_file(
					$index,
					$offset,
					$total_entry_length,
					(string) $header->path(),
					$codec_id,
					$entry_hash
				);
			case EntryHeader::KIND_DB_CHUNK:
				return ManifestEntry::for_db_chunk(
					$index,
					$offset,
					$total_entry_length,
					(int) $header->chunk_index(),
					$codec_id,
					$entry_hash
				);
			case EntryHeader::KIND_DIRECTORY:
				return ManifestEntry::for_directory(
					$index,
					$offset,
					$total_entry_length,
					(string) $header->path(),
					$codec_id,
					$entry_hash
				);
			case EntryHeader::KIND_SYMLINK:
				return ManifestEntry::for_symlink(
					$index,
					$offset,
					$total_entry_length,
					(string) $header->path(),
					$codec_id,
					$entry_hash
				);
			default:
				throw new RuntimeException(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $header->kind() is a validated KIND_* constant from EntryHeader; this branch should be unreachable. Exception path, not HTML output.
					sprintf( 'ArchiveWriter: entry %d has unknown header kind "%s"; expected one of: %s.', (int) $index, $header->kind(), implode( ', ', EntryHeader::ALL_KINDS ) )
				);
		}
	}

	/**
	 * Build a unique per-entry nonce: the entry index, then random bytes.
	 *
	 * Per spec §8.3 a nonce is the 0-based entry index as four big-endian bytes
	 * followed by eight random bytes. The index half guarantees uniqueness
	 * within the archive (no two entries collide), and the random half guards
	 * against accidental reuse across different archives that share a key — the
	 * reuse that is catastrophic for AES-GCM.
	 *
	 * @param int $index The 0-based entry index.
	 * @return string A NONCE_SIZE-byte nonce.
	 * @throws RuntimeException If the system source of randomness fails.
	 */
	private static function encryption_nonce( int $index ): string {
		try {
			$random = random_bytes( EntryWriter::NONCE_SIZE - ByteOrder::UINT32_SIZE );
		} catch ( \Exception $e ) {
			// random_bytes() throws Random\RandomException (a subclass of Exception) when the
			// system CSPRNG fails; catching Exception keeps this portable across PHP versions.
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $e is the underlying randomness-source exception, chained as the previous exception for diagnostics; not HTML output.
			throw new RuntimeException( 'ArchiveWriter: could not generate a random nonce for an encrypted entry.', 0, $e );
		}
		return ByteOrder::pack_uint32( $index ) . $random;
	}

	/**
	 * Write bytes to a stream with partial-write detection.
	 *
	 * Mirrors the pattern used in EntryWriter. Partial writes do not
	 * occur on the streams Pontifex uses (php://memory, php://temp,
	 * regular file handles), but checking is cheap and prevents silent
	 * corruption when something unexpected happens.
	 *
	 * @param resource $destination A writable stream resource.
	 * @param string   $bytes       The exact bytes to write.
	 * @return int The number of bytes written (equal to strlen($bytes)).
	 * @throws RuntimeException If fwrite() fails or returns fewer bytes than requested.
	 */
	private static function write_bytes( $destination, string $bytes ): int {
		$length = strlen( $bytes );
		if ( 0 === $length ) {
			return 0;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- ArchiveWriter operates on arbitrary stream resources from the archive layer; WP_Filesystem has no streaming API.
		$written = fwrite( $destination, $bytes );
		if ( false === $written ) {
			throw new RuntimeException( 'ArchiveWriter: fwrite() failed on destination stream.' );
		}
		if ( $written !== $length ) {
			throw new RuntimeException(
				sprintf(
					'ArchiveWriter: partial write detected (%d of %d bytes); aborting to preserve archive integrity.',
					(int) $written,
					(int) $length
				)
			);
		}

		return $length;
	}
}
