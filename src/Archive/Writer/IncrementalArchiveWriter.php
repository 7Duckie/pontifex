<?php
/**
 * Pontifex incremental archive writer — writes a .wpmig one entry at a time, across requests.
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
 * Writes a complete archive in separable phases: begin, N × append, finish.
 *
 * The one-shot {@see ArchiveWriter} is a thin loop over this class — the
 * write path exists exactly once. What this class adds is the property
 * v0.6.0's resumable exports need (ADR 0014): the phases do not have to
 * happen in one PHP request. A ticker can begin the archive, append
 * entries until its time budget runs out, record what it appended, and a
 * LATER request can adopt the half-written file and keep appending —
 * because the format writes its manifest and footer last, a partial
 * archive is simply a prefix of entry records, and appending is all that
 * resuming requires.
 *
 * State adoption ({@see self::adopt()}) trusts its caller to have
 * verified the destination's bytes against the recorded entries (the
 * resume contract lives in the export layer, which holds the progress
 * log); this class only asserts the arithmetic it can see — the stream's
 * length must equal the claimed bytes written.
 *
 * Encryption is deliberately begin-only: an encrypted archive's key
 * exists in memory for one request and is never persisted, so an
 * encrypted write cannot be adopted by a later request. Signing is
 * finish-only (the signature covers every byte through the footer), so
 * it composes with resume naturally.
 */
final class IncrementalArchiveWriter {

	/**
	 * Bytes read per chunk when streaming the archive to compute the signing digest.
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
	 * The destination stream, held between begin/adopt and finish.
	 *
	 * @var resource|null
	 */
	private $destination = null;

	/**
	 * Total bytes written to the archive so far (including adopted bytes).
	 *
	 * @var int
	 */
	private int $bytes_written = 0;

	/**
	 * The 0-based index the next appended entry will receive.
	 *
	 * @var int
	 */
	private int $next_index = 0;

	/**
	 * Manifest entries accumulated (or adopted) so far, in index order.
	 *
	 * @var ManifestEntry[]
	 */
	private array $manifest_entries = array();

	/**
	 * Encryption inputs for this write, or null for an unencrypted archive.
	 *
	 * @var EncryptionContext|null
	 */
	private ?EncryptionContext $encryption = null;

	/**
	 * Signing inputs for this write, or null for an unsigned archive.
	 *
	 * @var SigningContext|null
	 */
	private ?SigningContext $signing = null;

	/**
	 * Whether begin() or adopt() has run.
	 *
	 * @var bool
	 */
	private bool $started = false;

	/**
	 * Whether finish() has run.
	 *
	 * @var bool
	 */
	private bool $finished = false;

	/**
	 * Construct an IncrementalArchiveWriter with its injected dependencies.
	 *
	 * @param EntryWriter  $entry_writer  Writer used to emit each entry record.
	 * @param FooterWriter $footer_writer Writer used to emit the trailing 64-byte footer.
	 */
	public function __construct( EntryWriter $entry_writer, FooterWriter $footer_writer ) {
		$this->entry_writer  = $entry_writer;
		$this->footer_writer = $footer_writer;
	}

	/**
	 * Start a fresh archive: write the header and provenance blocks.
	 *
	 * @param resource               $destination Writable stream at offset 0; kept until finish().
	 * @param Provenance             $provenance  Source-site context to embed.
	 * @param EncryptionContext|null $encryption  Encryption inputs; sets the header flag and encrypts every appended entry. Null for plaintext.
	 * @param SigningContext|null    $signing     Signing inputs; sets the header flag now, appends the signature at finish(). Null for unsigned.
	 * @return void
	 * @throws InvalidArgumentException If the destination is not a stream resource, or signing was requested on a destination that is not seekable and readable.
	 * @throws RuntimeException         If a block fails to serialise or a write fails.
	 */
	public function begin( $destination, Provenance $provenance, ?EncryptionContext $encryption = null, ?SigningContext $signing = null ): void {
		$this->assert_not_started();
		if ( ! is_resource( $destination ) ) {
			throw new InvalidArgumentException( 'IncrementalArchiveWriter: $destination must be a valid stream resource.' );
		}
		if ( null !== $signing ) {
			self::assert_signable_destination( $destination );
		}
		if ( null !== $encryption ) {
			// One context per archive: refuse a reused context, whose deterministic
			// index-prefixed nonces would collide across archives under the same key.
			$encryption->consume();
		}

		try {
			$provenance_bytes = $provenance->to_bytes();
		} catch ( JsonException $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message embedded for diagnostic context; not HTML output.
			throw new RuntimeException( 'IncrementalArchiveWriter: failed to serialise the provenance block: ' . $e->getMessage() );
		}

		// The signed flag must be set in the header so it is part of the bytes the
		// signature later covers; flags of 0 reproduce Header::current_version().
		$flags = 0;
		if ( null !== $encryption ) {
			$flags |= Header::FLAG_ENCRYPTED;
		}
		if ( null !== $signing ) {
			$flags |= Header::FLAG_SIGNED;
		}
		$header = new Header( Header::FORMAT_MAJOR_V1, Header::FORMAT_MINOR_V1_1, $flags );

		$this->destination = $destination;
		$this->encryption  = $encryption;
		$this->signing     = $signing;
		$this->started     = true;

		$this->bytes_written += self::write_bytes( $destination, $header->to_bytes() );
		$this->bytes_written += self::write_bytes( $destination, $provenance_bytes );
	}

	/**
	 * Adopt a partial archive written by an earlier request, to keep appending.
	 *
	 * The caller — the export layer holding the progress log — is responsible
	 * for having VERIFIED the partial file against its recorded entries (tail
	 * truncation, last-entry hash) before adopting; this method asserts only
	 * the arithmetic it can see: the stream's current length must equal the
	 * claimed byte count. Encryption cannot be adopted (the key is never
	 * persisted); signing may be supplied here because the signature is
	 * computed entirely at finish() — but only if the original begin() set the
	 * signed header flag, which the caller guarantees by reconstructing the
	 * same options it began with.
	 *
	 * @param resource            $destination      Read-write stream of the partial archive, seekable.
	 * @param int                 $bytes_written    Verified byte length of the partial archive.
	 * @param ManifestEntry[]     $manifest_entries The entries the partial archive holds, in index order.
	 * @param SigningContext|null $signing          Signing inputs matching the original begin(), or null.
	 * @return void
	 * @throws InvalidArgumentException If the destination is invalid or the entry list is not index-ordered.
	 * @throws RuntimeException         If the stream's length contradicts $bytes_written or the seek fails.
	 */
	public function adopt( $destination, int $bytes_written, array $manifest_entries, ?SigningContext $signing = null ): void {
		$this->assert_not_started();
		if ( ! is_resource( $destination ) ) {
			throw new InvalidArgumentException( 'IncrementalArchiveWriter: $destination must be a valid stream resource.' );
		}
		if ( null !== $signing ) {
			self::assert_signable_destination( $destination );
		}

		$expected_index = 0;
		foreach ( $manifest_entries as $entry ) {
			if ( ! $entry instanceof ManifestEntry || $entry->index() !== $expected_index ) {
				throw new InvalidArgumentException( 'IncrementalArchiveWriter: adopted manifest entries must be ManifestEntry instances in contiguous index order from 0.' );
			}
			++$expected_index;
		}

		$stat = fstat( $destination );
		if ( false === $stat || ! isset( $stat['size'] ) || (int) $stat['size'] !== $bytes_written ) {
			throw new RuntimeException(
				sprintf(
					'IncrementalArchiveWriter: the partial archive is %d bytes but %d were claimed; refusing to append to an unverified file.',
					false !== $stat && isset( $stat['size'] ) ? (int) $stat['size'] : -1,
					(int) $bytes_written
				)
			);
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek -- Positioning the archive stream to append; WP_Filesystem has no streaming API.
		if ( -1 === fseek( $destination, $bytes_written ) ) {
			throw new RuntimeException( 'IncrementalArchiveWriter: could not seek to the end of the partial archive.' );
		}

		$this->destination      = $destination;
		$this->signing          = $signing;
		$this->bytes_written    = $bytes_written;
		$this->manifest_entries = array_values( $manifest_entries );
		$this->next_index       = count( $this->manifest_entries );
		$this->started          = true;
	}

	/**
	 * Append one entry record and return its manifest entry.
	 *
	 * @param EntryPlan     $plan            The entry to write.
	 * @param callable|null $on_bytes_read   Optional byte-progress callback forwarded to the codec.
	 * @param callable|null $on_file_changed Optional callback for a file whose size changed between scan and write (ADR 0013), called as `( string $path, int $declared_size, int $actual_size ): void`.
	 * @return ManifestEntry The recorded entry, also retained for finish().
	 * @throws RuntimeException If called before begin()/adopt() or after finish(), a nonce cannot be generated, or the write fails.
	 */
	public function append_entry( EntryPlan $plan, ?callable $on_bytes_read = null, ?callable $on_file_changed = null ): ManifestEntry {
		$this->assert_writable();

		$offset_before = $this->bytes_written;
		$index         = $this->next_index;

		// For an encrypted archive, upgrade the codec id to its encrypted variant,
		// derive a unique per-entry nonce, and pass the cipher and key to the entry
		// writer. For an unencrypted archive these stay at the plan's values.
		$codec_id = $plan->codec_id();
		$nonce    = $plan->nonce();
		$cipher   = null;
		$key      = null;
		if ( null !== $this->encryption ) {
			$codec_id = CodecId::with_aes_gcm( $codec_id );
			$nonce    = self::encryption_nonce( $index );
			$cipher   = $this->encryption->cipher();
			$key      = $this->encryption->key();
		}

		// Open this entry's source only now, and close it the moment the entry is
		// written, so exactly one source is open at a time — memory stays flat no
		// matter how many entries the archive holds.
		$source = $plan->source();
		try {
			$result = $this->entry_writer->write_entry(
				$plan->header(),
				$codec_id,
				$nonce,
				$source,
				$this->destination,
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

		// Report a file whose content changed between the caller's scan and this
		// write (ADR 0013); the entry itself was already written with the actual
		// captured size by EntryWriter.
		if ( $result->size_was_corrected() && null !== $on_file_changed ) {
			$on_file_changed( (string) $plan->header()->path(), (int) $result->declared_size(), (int) $result->actual_size() );
		}

		$manifest_entry = self::build_manifest_entry(
			$index,
			$offset_before,
			$result->total_entry_length(),
			$result->entry_hash(),
			$plan,
			$codec_id
		);

		$this->bytes_written     += $result->total_entry_length();
		$this->manifest_entries[] = $manifest_entry;
		++$this->next_index;

		return $manifest_entry;
	}

	/**
	 * Write the manifest, the footer, and (when signing) the signature block.
	 *
	 * @return int Total bytes the completed archive holds, including adopted bytes.
	 * @throws RuntimeException If called before begin()/adopt() or twice, serialisation fails, or a write fails.
	 */
	public function finish(): int {
		$this->assert_writable();
		$this->finished = true;

		try {
			$manifest_bytes = ( new ArchiveManifest( $this->manifest_entries ) )->to_bytes();
		} catch ( JsonException $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception message embedded for diagnostic context; not HTML output.
			throw new RuntimeException( 'IncrementalArchiveWriter: failed to serialise the manifest block: ' . $e->getMessage() );
		}

		$manifest_offset      = $this->bytes_written;
		$this->bytes_written += self::write_bytes( $this->destination, $manifest_bytes );

		// Footer reuses the manifest's internal SHA-256, at a fixed offset within the
		// manifest block. The bytes are identical, so a substr is correct and cheaper
		// than recomputing.
		$manifest_hash        = substr( $manifest_bytes, ArchiveManifest::LENGTH_PREFIX_SIZE, Sha256::DIGEST_SIZE );
		$salt                 = null !== $this->encryption ? $this->encryption->salt() : Footer::ZERO_SALT;
		$footer               = new Footer(
			$manifest_offset,
			strlen( $manifest_bytes ),
			$manifest_hash,
			$salt
		);
		$this->bytes_written += $this->footer_writer->write_footer( $footer, $this->destination );

		// Sign last: the signature covers every byte written so far (header through
		// footer), streamed so memory stays bounded.
		if ( null !== $this->signing ) {
			$this->bytes_written += self::sign_and_append( $this->destination, $this->bytes_written, $this->signing );
		}

		return $this->bytes_written;
	}

	/**
	 * Return the total bytes written so far (including adopted bytes).
	 *
	 * @return int The byte count.
	 */
	public function bytes_written(): int {
		return $this->bytes_written;
	}

	/**
	 * Return the index the next appended entry will receive.
	 *
	 * @return int The 0-based index.
	 */
	public function next_index(): int {
		return $this->next_index;
	}

	/**
	 * Refuse use before begin()/adopt() or after finish().
	 *
	 * @return void
	 * @throws RuntimeException If the writer is not in a writable state.
	 */
	private function assert_writable(): void {
		if ( ! $this->started || $this->finished || ! is_resource( $this->destination ) ) {
			throw new RuntimeException( 'IncrementalArchiveWriter: the writer must be started (begin or adopt) and not finished.' );
		}
	}

	/**
	 * Refuse a second begin()/adopt() on the same instance.
	 *
	 * @return void
	 * @throws RuntimeException If the writer was already started.
	 */
	private function assert_not_started(): void {
		if ( $this->started ) {
			throw new RuntimeException( 'IncrementalArchiveWriter: already started; use one instance per archive.' );
		}
	}

	/**
	 * Construct a ManifestEntry for one written entry, dispatching on kind.
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
				return ManifestEntry::for_file( $index, $offset, $total_entry_length, (string) $header->path(), $codec_id, $entry_hash );
			case EntryHeader::KIND_DB_CHUNK:
				return ManifestEntry::for_db_chunk( $index, $offset, $total_entry_length, (int) $header->chunk_index(), $codec_id, $entry_hash );
			case EntryHeader::KIND_DIRECTORY:
				return ManifestEntry::for_directory( $index, $offset, $total_entry_length, (string) $header->path(), $codec_id, $entry_hash );
			case EntryHeader::KIND_SYMLINK:
				return ManifestEntry::for_symlink( $index, $offset, $total_entry_length, (string) $header->path(), $codec_id, $entry_hash );
			default:
				throw new RuntimeException(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $header->kind() is a validated KIND_* constant from EntryHeader; this branch should be unreachable. Exception path, not HTML output.
					sprintf( 'IncrementalArchiveWriter: entry %d has unknown header kind "%s"; expected one of: %s.', (int) $index, $header->kind(), implode( ', ', EntryHeader::ALL_KINDS ) )
				);
		}
	}

	/**
	 * Build a unique per-entry nonce: the entry index, then random bytes.
	 *
	 * Per spec §8.3 a nonce is the 0-based entry index as four big-endian bytes
	 * followed by eight random bytes; the index half guarantees uniqueness within
	 * the archive, the random half guards against reuse across archives sharing
	 * a key.
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
			throw new RuntimeException( 'IncrementalArchiveWriter: could not generate a random nonce for an encrypted entry.', 0, $e );
		}
		return ByteOrder::pack_uint32( $index ) . $random;
	}

	/**
	 * Sign the bytes already written and append the 100-byte signature block.
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
	 * @param resource $destination A seekable, readable stream.
	 * @param int      $length      Number of bytes from offset 0 to hash.
	 * @return string The raw 32-byte SHA-256 digest.
	 * @throws RuntimeException If a seek or read fails, or the stream is shorter than $length.
	 */
	private static function digest_stream_prefix( $destination, int $length ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek -- Seeking within the archive stream to re-read it for the signing digest; WP_Filesystem has no streaming API.
		if ( -1 === fseek( $destination, 0 ) ) {
			throw new RuntimeException( 'IncrementalArchiveWriter: could not seek to offset 0 to compute the signing digest.' );
		}

		$context   = hash_init( 'sha256' );
		$remaining = $length;
		while ( $remaining > 0 ) {
			$want = (int) min( self::SIGNATURE_HASH_CHUNK_SIZE, $remaining );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Reading the archive stream back to compute the signing digest; WP_Filesystem has no streaming API.
			$chunk = fread( $destination, $want );
			if ( false === $chunk || '' === $chunk ) {
				throw new RuntimeException( 'IncrementalArchiveWriter: could not re-read the archive to compute the signing digest; stream may be truncated.' );
			}
			hash_update( $context, $chunk );
			$remaining -= strlen( $chunk );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek -- Returning to the end of the stream so the signature block appends after the footer.
		if ( -1 === fseek( $destination, 0, SEEK_END ) ) {
			throw new RuntimeException( 'IncrementalArchiveWriter: could not seek back to the end of the stream after computing the signing digest.' );
		}

		return hash_final( $context, true );
	}

	/**
	 * Assert a destination is usable for signing: seekable and readable.
	 *
	 * @param resource $destination The stream to check.
	 * @return void
	 * @throws InvalidArgumentException If the stream is not seekable or not readable.
	 */
	private static function assert_signable_destination( $destination ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_stream_get_meta_data -- Inspecting the destination stream's capabilities before signing; WP_Filesystem has no equivalent.
		$meta = stream_get_meta_data( $destination );
		if ( empty( $meta['seekable'] ) ) {
			throw new InvalidArgumentException( 'IncrementalArchiveWriter: signing requires a seekable destination stream.' );
		}
		if ( false === strpbrk( (string) $meta['mode'], 'r+' ) ) {
			throw new InvalidArgumentException( 'IncrementalArchiveWriter: signing requires a readable destination stream; open it with a mode that permits reading, e.g. "w+b".' );
		}
	}

	/**
	 * Write bytes to a stream with partial-write detection.
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

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- IncrementalArchiveWriter operates on arbitrary stream resources from the archive layer; WP_Filesystem has no streaming API.
		$written = fwrite( $destination, $bytes );
		if ( false === $written ) {
			throw new RuntimeException( 'IncrementalArchiveWriter: fwrite() failed on destination stream.' );
		}
		if ( $written !== $length ) {
			throw new RuntimeException(
				sprintf( 'IncrementalArchiveWriter: partial write detected (%d of %d bytes); aborting to preserve archive integrity.', (int) $written, (int) $length )
			);
		}

		return $length;
	}
}
