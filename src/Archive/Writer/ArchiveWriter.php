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
use Pontifex\Archive\Crypto\SigningContext;
use Pontifex\Archive\Format\ArchiveManifest;
use Pontifex\Archive\Format\ArchiveSignature;
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
 *   [before signature]      Footer        (64 bytes)
 *   [end - 100]             Signature     (100 bytes; only when the archive is signed)
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
 * An archive is signed when a SigningContext is supplied to write_archive():
 * the header's signed flag is set and, once the footer is written, an Ed25519
 * signature over the SHA-256 of every byte through the footer is appended as a
 * 100-byte ArchiveSignature block. The digest is computed by streaming the
 * just-written bytes, so signing stays within the writer's memory budget — but
 * it therefore needs a seekable, readable destination. Signing is independent
 * of encryption: either, both, or neither may be requested.
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
	 * Bytes read per chunk when streaming the archive to compute the signing digest.
	 *
	 * Sized well under the writer's memory budget; a larger value only reduces the
	 * number of read calls.
	 *
	 * @var int
	 */
	private const SIGNATURE_HASH_CHUNK_SIZE = 1048576;

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

	// phpcs:disable Squiz.Commenting.FunctionComment.IncorrectTypeHint -- $entry_plans is documented as iterable<EntryPlan> because PHPStan level 6 requires the value type; this sniff cannot reduce an iterable<> generic to its base iterable hint the way it reduces array<> to array.
	/**
	 * Write a complete archive to the destination stream.
	 *
	 * Writes the header, provenance, every supplied entry in order,
	 * the manifest, and the footer. Returns the total byte count
	 * written. Each EntryPlan's source stream is read from its
	 * current seek position to EOF.
	 *
	 * @param Provenance             $provenance       Source-site context to embed in the provenance block.
	 * @param iterable<EntryPlan>    $entry_plans      Entries to write, in archive order; a plain array or a Countable ManifestStream. May be empty.
	 * @param resource               $destination      Writable stream resource; bytes are written from its current seek position and it is advanced by the returned byte count.
	 * @param callable|null          $on_entry_written Optional callback run once after each entry, as function(int $done, int $total): void; lets a caller drive a progress indicator while the archive layer stays UI-agnostic.
	 * @param EncryptionContext|null $encryption       Encryption inputs (cipher, key, salt); when supplied the header's encrypted flag is set, every entry is encrypted with a per-entry nonce, and the salt is written into the footer; null produces an unencrypted archive.
	 * @param SigningContext|null    $signing          Signing inputs (signer, secret key, key id); when supplied the header's signed flag is set and a 100-byte Ed25519 signature block over the SHA-256 of every byte through the footer is appended. Requires a seekable, readable destination. null produces an unsigned archive.
	 * @param callable|null          $on_bytes_read    Optional byte-progress callback forwarded to each entry's codec, called as `( int $bytes ): void` with each chunk's raw source byte count, so a caller can report progress within a large entry as well as between entries.
	 * @return int Total bytes written to the destination during this call.
	 * @throws InvalidArgumentException If $destination is not a stream resource, any element
	 *                                  of $entry_plans is not an EntryPlan, or signing was requested
	 *                                  but the destination is not seekable and readable.
	 * @throws RuntimeException         If any block fails to serialise, any write fails, a nonce cannot be generated, or signing fails.
	 */
	public function write_archive( Provenance $provenance, iterable $entry_plans, $destination, ?callable $on_entry_written = null, ?EncryptionContext $encryption = null, ?SigningContext $signing = null, ?callable $on_bytes_read = null ): int {
		// phpcs:enable Squiz.Commenting.FunctionComment.IncorrectTypeHint
		if ( ! is_resource( $destination ) ) {
			throw new InvalidArgumentException( 'ArchiveWriter: $destination must be a valid stream resource.' );
		}
		if ( null !== $signing ) {
			self::assert_signable_destination( $destination );
		}
		if ( null !== $encryption ) {
			// One context per archive: refuse a reused context, whose deterministic
			// index-prefixed nonces would collide across archives under the same key.
			$encryption->consume();
		}

		// The entry list may be a lazily-streamed ManifestStream as well as a plain
		// array, so each plan is validated inside the write loop (which pulls one at
		// a time) rather than in an up-front pass that would consume a stream. The
		// progress total comes from Countable, which both an array and a
		// ManifestStream satisfy in O(1).
		$total = is_countable( $entry_plans ) ? count( $entry_plans ) : 0;

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

		// Set the header flags: encrypted (salt and per-entry encryption applied below)
		// and/or signed (the signature appended after the footer below). The signed flag
		// must be set here so it is part of the bytes the signature covers. Flags of 0
		// reproduce Header::current_version().
		$flags = 0;
		if ( null !== $encryption ) {
			$flags |= Header::FLAG_ENCRYPTED;
		}
		if ( null !== $signing ) {
			$flags |= Header::FLAG_SIGNED;
		}
		$header = new Header( Header::FORMAT_MAJOR_V1, Header::FORMAT_MINOR_V1_0, $flags );

		$header_bytes  = $header->to_bytes();
		$bytes_written = 0;

		// Header.
		$bytes_written += self::write_bytes( $destination, $header_bytes );

		// Provenance.
		$bytes_written += self::write_bytes( $destination, $provenance_bytes );

		// Entries: for each plan, record the offset where the entry starts.
		// Delegate to EntryWriter, then build a ManifestEntry from the result.
		$manifest_entries = array();
		$index            = 0;
		foreach ( $entry_plans as $plan ) {
			// Validate here, one at a time, so a lazy stream is not consumed by an
			// up-front pass. $index is tracked explicitly rather than taken from the
			// foreach key, so the entry index (used for the nonce and the manifest)
			// is correct for any iterable, not only a 0..N-1 keyed array.
			if ( ! $plan instanceof EntryPlan ) {
				throw new InvalidArgumentException(
					sprintf( 'ArchiveWriter: $entry_plans[%d] must be an EntryPlan instance.', (int) $index )
				);
			}

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
				$nonce    = self::encryption_nonce( $index );
				$cipher   = $encryption->cipher();
				$key      = $encryption->key();
			}

			// Open this entry's source only now, and close it the moment the entry
			// is written, so exactly one source is open at a time — the export's
			// memory then stays flat no matter how many entries the archive holds.
			$source = $plan->source();
			try {
				$result = $this->entry_writer->write_entry(
					$plan->header(),
					$codec_id,
					$nonce,
					$source,
					$destination,
					$cipher,
					$key,
					$on_bytes_read
				);
			} finally {
				if ( is_resource( $source ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the per-entry payload source opened just above; bounds open handles to one regardless of entry count.
					fclose( $source );
				}
			}

			$bytes_written     += $result->total_entry_length();
			$manifest_entries[] = self::build_manifest_entry(
				$index,
				$offset_before,
				$result->total_entry_length(),
				$result->entry_hash(),
				$plan,
				$codec_id
			);

			if ( null !== $on_entry_written ) {
				$on_entry_written( $index + 1, $total );
			}

			++$index;
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

		// Sign last: the signature covers every byte written so far (header through
		// footer). The digest is streamed from the just-written bytes so memory stays
		// bounded, and the 100-byte block is appended after the footer.
		if ( null !== $signing ) {
			$bytes_written += self::sign_and_append( $destination, $bytes_written, $signing );
		}

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
	 * Sign the bytes already written and append the 100-byte signature block.
	 *
	 * Streams the destination from offset 0 through $signed_length to compute the
	 * SHA-256 the signature is taken over, signs that digest, and writes the
	 * resulting ArchiveSignature block after the footer.
	 *
	 * @param resource       $destination   The archive stream, already written through the footer.
	 * @param int            $signed_length Number of bytes (header through footer) the signature covers.
	 * @param SigningContext $signing       The signer, secret key and key id.
	 * @return int The number of bytes the signature block added.
	 * @throws RuntimeException If the stream cannot be re-read or signing fails.
	 */
	private static function sign_and_append( $destination, int $signed_length, SigningContext $signing ): int {
		$digest    = self::digest_stream_prefix( $destination, $signed_length );
		$signature = $signing->signer()->sign( $digest, $signing->secret_key() );
		$block     = new ArchiveSignature( $signing->key_id(), $signature );
		return self::write_bytes( $destination, $block->to_bytes() );
	}

	/**
	 * Compute the SHA-256 of the first $length bytes of a stream, by streaming.
	 *
	 * Reads from offset 0 in bounded chunks so memory stays within budget no matter
	 * how large the archive is, then seeks back to the end so the caller can append.
	 *
	 * @param resource $destination A seekable, readable stream.
	 * @param int      $length      Number of bytes from offset 0 to hash.
	 * @return string The raw 32-byte SHA-256 digest.
	 * @throws RuntimeException If a seek or read fails, or the stream is shorter than $length.
	 */
	private static function digest_stream_prefix( $destination, int $length ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek -- Seeking within the archive stream to re-read it for the signing digest; WP_Filesystem has no streaming API.
		if ( -1 === fseek( $destination, 0 ) ) {
			throw new RuntimeException( 'ArchiveWriter: could not seek to offset 0 to compute the signing digest.' );
		}

		$context   = hash_init( 'sha256' );
		$remaining = $length;
		while ( $remaining > 0 ) {
			$want = (int) min( self::SIGNATURE_HASH_CHUNK_SIZE, $remaining );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Reading the archive stream back to compute the signing digest; WP_Filesystem has no streaming API.
			$chunk = fread( $destination, $want );
			if ( false === $chunk || '' === $chunk ) {
				throw new RuntimeException( 'ArchiveWriter: could not re-read the archive to compute the signing digest; stream may be truncated.' );
			}
			hash_update( $context, $chunk );
			$remaining -= strlen( $chunk );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek -- Returning to the end of the stream so the signature block appends after the footer.
		if ( -1 === fseek( $destination, 0, SEEK_END ) ) {
			throw new RuntimeException( 'ArchiveWriter: could not seek back to the end of the stream after computing the signing digest.' );
		}

		return hash_final( $context, true );
	}

	/**
	 * Assert a destination is usable for signing: seekable and readable.
	 *
	 * Signing re-reads the written archive to compute its digest, so the stream must
	 * be seekable and opened in a mode that permits reading (e.g. "w+b"). A
	 * write-only stream would fail mid-write; this fails fast instead.
	 *
	 * @param resource $destination The stream to check.
	 * @return void
	 * @throws InvalidArgumentException If the stream is not seekable or not readable.
	 */
	private static function assert_signable_destination( $destination ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_stream_get_meta_data -- Inspecting the destination stream's capabilities before signing; WP_Filesystem has no equivalent.
		$meta = stream_get_meta_data( $destination );
		if ( empty( $meta['seekable'] ) ) {
			throw new InvalidArgumentException( 'ArchiveWriter: signing requires a seekable destination stream.' );
		}
		if ( false === strpbrk( (string) $meta['mode'], 'r+' ) ) {
			throw new InvalidArgumentException( 'ArchiveWriter: signing requires a readable destination stream; open it with a mode that permits reading, e.g. "w+b".' );
		}
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
