<?php
/**
 * Pontifex entry reader — reads, verifies, and decodes one entry from an archive stream.
 *
 * @package Pontifex\Archive\Reader
 */

declare(strict_types=1);

namespace Pontifex\Archive\Reader;

use InvalidArgumentException;
use RuntimeException;
use Pontifex\Archive\Codec\CodecException;
use Pontifex\Archive\Codec\CodecId;
use Pontifex\Archive\Codec\CodecRegistry;
use Pontifex\Archive\Crypto\Cipher;
use Pontifex\Archive\Crypto\CipherException;
use Pontifex\Archive\Format\ByteOrder;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Format\ManifestEntry;
use Pontifex\Archive\Integrity\Sha256;
use Pontifex\Archive\Writer\EntryWriter;

/**
 * Reads, verifies, and decodes one archive entry record.
 *
 * The mirror of {@see \Pontifex\Archive\Writer\EntryWriter}. Where the
 * writer composes header || codec_id || nonce || payload || hash to
 * disk and computes the hash as bytes flow through, the reader does
 * the reverse: reads the same five fields back, verifies the hash
 * against what the manifest recorded, looks up the codec, and runs
 * the payload through it in reverse to produce the original bytes.
 *
 * Per ARCHIVE-FORMAT.md §6, an on-disk entry record looks like:
 *
 *   header_length (4 B) || header_JSON || codec_id (2 B) || nonce (12 B) || payload || hash (32 B)
 *
 * EntryReader knows nothing about the manifest as a whole, the
 * archive's other entries, or where the entry sits in the broader
 * file. The caller (typically a higher-level restore orchestrator
 * arriving in commit 16+) passes in the source stream and the
 * matching ManifestEntry, which carries the offset, length, codec,
 * and expected hash. EntryReader does the rest.
 *
 * Public API (locked for v0.1.0):
 *
 *  - {@see EntryReader::__construct()} — takes a CodecRegistry so the
 *    reader can look up codecs by id. Stateless after construction;
 *    safe to reuse across many entries.
 *  - {@see EntryReader::read_entry()} — given a seekable source
 *    stream and a ManifestEntry, returns an {@see EntryReadResult}
 *    bundling the parsed header and decoded payload bytes.
 *
 * Verification performed:
 *
 *  1. The bytes from manifest_entry.offset for manifest_entry.length
 *     bytes must be readable from the stream.
 *  2. The header_length prefix must declare a header that fits
 *     inside the entry record.
 *  3. The header JSON must parse as a valid EntryHeader.
 *  4. The codec_id in the on-disk entry must match the
 *     manifest_entry.codec_id (sanity check; tampering with one
 *     should be detected by the other).
 *  5. The codec must be known to the registry.
 *  6. The trailing 32-byte hash must equal SHA-256 of everything
 *     before it; this verifies the entry record was not tampered
 *     with on disk.
 *  7. The computed hash must equal the manifest entry's recorded
 *     hash. Defense in depth — tampering with both the on-disk
 *     hash and the manifest entry would have to be coordinated.
 *
 * Buffering (ADR 0010): the record is read in chunks with an
 * incremental SHA-256, and the stored payload is spooled through a
 * php://temp stream (memory up to the spill threshold, disk beyond),
 * so reading never holds a payload-sized string. Both hashes are
 * verified BEFORE any decode. A plain file entry then decodes
 * stream-to-stream and the result carries a stream; db_chunks decode
 * to strings (DatabaseWriter splits whole chunks, which are
 * budget-bounded at export time), and encrypted entries buffer their
 * stored payload because PHP's AEAD is one-shot — streaming a
 * monolithic GCM message would mean acting on unauthenticated
 * plaintext, the misuse chunked constructions exist to prevent.
 */
final class EntryReader {

	/**
	 * Default ceiling on the decoded size of a single entry (2 GiB).
	 *
	 * Read-orchestrating callers (the restore/verify walk) pass a tighter,
	 * archive-derived budget. This default is the backstop for any caller that
	 * does not — a direct or third-party consumer of the documented format — so
	 * a decompression bomb cannot inflate without bound at the reader layer.
	 * Pass null explicitly only when no limit is genuinely wanted.
	 *
	 * @var int
	 */
	public const DEFAULT_MAX_DECODED_BYTES = 2147483648;

	/**
	 * Chunk size, in bytes, for the streaming entry reads (1 MiB).
	 *
	 * Bounds memory and sets how often {@see self::verify_entry()} and
	 * {@see self::read_record_bytes()} report byte progress; a larger value only
	 * reduces the number of read calls.
	 *
	 * @var int
	 */
	private const READ_CHUNK_SIZE = 1048576;

	/**
	 * Codec registry used to look up codecs by id.
	 *
	 * @var CodecRegistry
	 */
	private CodecRegistry $codec_registry;

	/**
	 * Cipher used to decrypt encrypted entries, or null when no key is held.
	 *
	 * @var Cipher|null
	 */
	private ?Cipher $cipher;

	/**
	 * Encryption key for decrypting encrypted entries, or null when none is held.
	 *
	 * @var string|null
	 */
	private ?string $key;

	/**
	 * Construct an EntryReader with a codec registry and optional decryption key.
	 *
	 * The registry must contain every compression codec id that might appear in
	 * the archives the reader will handle; RawCodec, GzipCodec and ZstdCodec are
	 * all registered by {@see CodecRegistry::with_defaults()}.
	 *
	 * To read an encrypted archive, supply the cipher and derived key as well.
	 * Without them the reader still verifies hashes, but refuses to decode an
	 * encrypted entry, raising a clear "a passphrase is required" error.
	 *
	 * @param CodecRegistry $codec_registry The registry to consult on decode.
	 * @param Cipher|null   $cipher         Cipher for decrypting encrypted entries, or null.
	 * @param string|null   $key            Derived key for decryption, or null.
	 */
	public function __construct( CodecRegistry $codec_registry, ?Cipher $cipher = null, ?string $key = null ) {
		$this->codec_registry = $codec_registry;
		$this->cipher         = $cipher;
		$this->key            = $key;
	}

	/**
	 * Read, verify, and decode one entry from the source stream.
	 *
	 * Streams the record from manifest_entry.offset in chunks: the head fields
	 * (header, codec id, nonce) are parsed as they arrive, the stored payload is
	 * spooled through a php://temp buffer, and an incremental SHA-256 runs over
	 * everything — so no payload-sized string is ever held. Both hashes (the
	 * record's trailing hash and the manifest's) are verified BEFORE any decode.
	 * A plain file entry then decodes stream-to-stream and the result carries a
	 * stream; every other shape decodes to a string as before (ADR 0010).
	 *
	 * The source stream's seek position after this call is
	 * unspecified. The stream is owned by the caller; EntryReader
	 * does not close it.
	 *
	 * @param resource      $source            A seekable, readable stream containing the archive.
	 * @param ManifestEntry $manifest_entry    The manifest entry pointing at the on-disk record to read.
	 * @param int|null      $max_decoded_bytes Maximum bytes the decoded payload may produce (the decompression-bomb ceiling; applies to every entry). Defaults to DEFAULT_MAX_DECODED_BYTES; pass null for no limit.
	 * @param callable|null $on_bytes          Optional byte-progress callback, called as `( int $bytes ): void` with each chunk's byte count as the record is read, so a caller can report progress within a large entry.
	 * @param int|null      $memory_budget     Optional memory-derived per-entry budget. Applies only to entries the reader must buffer whole (encrypted entries and db_chunks); a plain file entry streams through chunk-sized memory, so no memory refusal applies to it. Null enforces no memory budget.
	 * @return EntryReadResult The parsed header and decoded payload (string- or stream-shaped).
	 * @throws InvalidArgumentException If $source is not a valid stream resource or is not seekable.
	 * @throws RuntimeException         If reading fails, the bytes are malformed, hash verification fails, the codec is not registered, the decoded payload exceeds a budget, or a file entry's decoded byte count differs from its declared size.
	 */
	public function read_entry( $source, ManifestEntry $manifest_entry, ?int $max_decoded_bytes = self::DEFAULT_MAX_DECODED_BYTES, ?callable $on_bytes = null, ?int $memory_budget = null ): EntryReadResult {
		if ( ! is_resource( $source ) ) {
			throw new InvalidArgumentException( 'EntryReader: $source must be a valid stream resource.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_stream_get_meta_data -- Inspecting an open stream resource; WP_Filesystem has no equivalent.
		$meta = stream_get_meta_data( $source );
		if ( empty( $meta['seekable'] ) ) {
			throw new InvalidArgumentException( 'EntryReader: $source stream must be seekable.' );
		}

		$record_len = $manifest_entry->length();

		// Minimum record size: 4-byte header_length + at least 1 byte of header + 2-byte codec_id + 12-byte nonce + 32-byte hash.
		$min_record_size = EntryHeader::LENGTH_PREFIX_SIZE + 1 + 2 + EntryWriter::NONCE_SIZE + Sha256::DIGEST_SIZE;
		if ( $record_len < $min_record_size ) {
			throw new RuntimeException(
				sprintf( 'EntryReader: entry record at offset %d is too short (%d bytes).', (int) $manifest_entry->offset(), (int) $record_len )
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek -- Reading from an open stream resource; WP_Filesystem has no equivalent.
		if ( -1 === fseek( $source, $manifest_entry->offset() ) ) {
			throw new RuntimeException(
				sprintf( 'EntryReader: could not seek to entry offset %d.', (int) $manifest_entry->offset() )
			);
		}

		// Everything before the trailing digest feeds the incremental hash as it
		// is read, so the record is verified without ever being held whole.
		$hash_context = hash_init( 'sha256' );

		// --- The head: length prefix, header, codec id, nonce — all small reads.
		$prefix        = $this->read_exactly( $source, EntryHeader::LENGTH_PREFIX_SIZE, $manifest_entry, $hash_context, $on_bytes );
		$header_length = ByteOrder::unpack_uint32( $prefix );
		$header_end    = EntryHeader::LENGTH_PREFIX_SIZE + $header_length;
		if ( $header_end + 2 + EntryWriter::NONCE_SIZE + Sha256::DIGEST_SIZE > $record_len ) {
			throw new RuntimeException(
				sprintf( 'EntryReader: declared header length %d does not fit inside entry record of %d bytes.', (int) $header_length, (int) $record_len )
			);
		}

		// Parse the header. EntryHeader::from_bytes expects the length prefix included.
		$header_bytes = $prefix . $this->read_exactly( $source, $header_length, $manifest_entry, $hash_context, $on_bytes );
		try {
			$header = EntryHeader::from_bytes( $header_bytes );
		} catch ( InvalidArgumentException $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $e is the underlying parse exception, passed as the previous-exception argument for diagnostic chaining; not HTML output.
			throw new RuntimeException( 'EntryReader: entry header is malformed.', 0, $e );
		}

		// Read codec_id and verify it matches the manifest entry.
		$codec_id = ByteOrder::unpack_uint16( $this->read_exactly( $source, ByteOrder::UINT16_SIZE, $manifest_entry, $hash_context, $on_bytes ) );
		if ( $codec_id !== $manifest_entry->codec_id() ) {
			throw new RuntimeException(
				sprintf( 'EntryReader: codec_id mismatch — on-disk %d, manifest %d.', (int) $codec_id, (int) $manifest_entry->codec_id() )
			);
		}

		// The codec id's low byte selects the compression codec; its high byte selects
		// encryption (0x0100 = AES-256-GCM).
		$compression_codec_id = CodecId::compression( $codec_id );
		if ( CodecId::is_encrypted( $codec_id ) && CodecId::ENCRYPTION_AES_GCM !== CodecId::encryption_family( $codec_id ) ) {
			throw new RuntimeException(
				sprintf( 'EntryReader: unknown encryption family 0x%04X in codec id 0x%04X.', (int) CodecId::encryption_family( $codec_id ), (int) $codec_id )
			);
		}
		if ( ! $this->codec_registry->has( $compression_codec_id ) ) {
			throw new RuntimeException(
				sprintf( 'EntryReader: compression codec 0x%04X (from codec id 0x%04X) is not registered.', (int) $compression_codec_id, (int) $codec_id )
			);
		}

		// The nonce and the plaintext header bytes are inputs to decryption: the nonce
		// sits between codec_id and the payload, and the header bytes (with their length
		// prefix) are the AES-GCM additional authenticated data (AAD).
		$nonce = $this->read_exactly( $source, EntryWriter::NONCE_SIZE, $manifest_entry, $hash_context, $on_bytes );

		// Budget refusals, before the payload is even spooled. The decoded-byte
		// ceiling (the decompression-bomb defence) applies to every entry; the
		// memory-derived budget applies only to shapes the reader must buffer whole
		// — a plain file entry streams through chunk-sized memory (ADR 0010). The
		// header's declared size is trusted only to refuse, never to allocate; the
		// decode still enforces the same ceiling as it runs.
		$streams_out = $this->streams_decoded_payload( $header, $codec_id );
		$this->refuse_if_over_budget( $header, $max_decoded_bytes );
		if ( ! $streams_out ) {
			$this->refuse_if_over_budget( $header, $memory_budget );
		}

		// --- The stored payload: spooled to php://temp while the hash runs.
		$payload_length = $record_len - $header_end - ByteOrder::UINT16_SIZE - EntryWriter::NONCE_SIZE - Sha256::DIGEST_SIZE;
		$spool          = $this->open_spool();
		$remaining      = $payload_length;
		while ( $remaining > 0 ) {
			$want = (int) min( self::READ_CHUNK_SIZE, $remaining );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Reading from an open stream resource; WP_Filesystem has no equivalent.
			$chunk = fread( $source, $want );
			if ( false === $chunk || '' === $chunk ) {
				throw new RuntimeException(
					sprintf( 'EntryReader: could not read %d entry bytes at offset %d; stream may be truncated.', (int) $record_len, (int) $manifest_entry->offset() )
				);
			}
			hash_update( $hash_context, $chunk );
			$this->write_all( $spool, $chunk );
			$remaining -= strlen( $chunk );
			if ( null !== $on_bytes ) {
				$on_bytes( strlen( $chunk ) );
			}
		}

		// --- The trailing hash: verified before ANY decode touches the payload.
		$expected_hash = $this->read_exactly( $source, Sha256::DIGEST_SIZE, $manifest_entry, null, $on_bytes );
		$computed_hash = hash_final( $hash_context, true );
		if ( ! hash_equals( $expected_hash, $computed_hash ) ) {
			throw new RuntimeException( 'EntryReader: entry hash does not match the bytes on disk; the entry has been tampered with or is corrupt.' );
		}

		// Verify the on-disk hash matches the manifest's recorded entry_hash.
		if ( ! hash_equals( $manifest_entry->entry_hash(), $expected_hash ) ) {
			throw new RuntimeException( 'EntryReader: on-disk entry hash does not match the manifest entry_hash.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a php://temp spool, not a filesystem path.
		rewind( $spool );

		// Decode the verified payload. For an encrypted entry, buffer and decrypt
		// first (AES-256-GCM is one-shot in PHP, and its tag must verify before the
		// plaintext is used) then decompress; a buffered plain entry (a db_chunk)
		// decodes to a string under the tighter of the two budgets; a plain file
		// entry decodes stream-to-stream and never occupies payload-sized memory.
		if ( CodecId::is_encrypted( $codec_id ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_stream_get_contents -- Draining a php://temp spool, not a filesystem path.
			$stored_payload = (string) stream_get_contents( $spool );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Cleanup of a php://temp spool; not a filesystem path.
			fclose( $spool );
			$decoded_payload = $this->decrypt_then_decompress(
				$compression_codec_id,
				$stored_payload,
				$nonce,
				$header_bytes,
				$manifest_entry,
				self::tighter_limit( $max_decoded_bytes, $memory_budget )
			);
			$this->refuse_size_mismatch( $header, strlen( $decoded_payload ), $manifest_entry );
			return new EntryReadResult( $header, $decoded_payload );
		}

		if ( ! $streams_out ) {
			$decoded_payload = $this->decode_spool_to_string( $compression_codec_id, $spool, self::tighter_limit( $max_decoded_bytes, $memory_budget ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Cleanup of a php://temp spool; not a filesystem path.
			fclose( $spool );
			$this->refuse_size_mismatch( $header, strlen( $decoded_payload ), $manifest_entry );
			return new EntryReadResult( $header, $decoded_payload );
		}

		$output = $this->open_spool();
		try {
			$decoded_bytes = $this->codec_registry->get( $compression_codec_id )->decode( $spool, $output, $max_decoded_bytes );
		} catch ( CodecException $e ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Cleanup of a php://temp spool; not a filesystem path.
			fclose( $spool );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Cleanup of a php://temp spool; not a filesystem path.
			fclose( $output );
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $e is the underlying codec exception, passed as the previous-exception argument for diagnostic chaining; not HTML output.
			throw new RuntimeException( 'EntryReader: codec failed to decode entry payload.', 0, $e );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Cleanup of a php://temp spool; not a filesystem path.
		fclose( $spool );
		try {
			$this->refuse_size_mismatch( $header, $decoded_bytes, $manifest_entry );
		} catch ( RuntimeException $e ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Cleanup of a php://temp spool; not a filesystem path.
			fclose( $output );
			throw $e;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a php://temp spool, not a filesystem path.
		rewind( $output );
		return EntryReadResult::for_stream( $header, $output, $decoded_bytes );
	}

	/**
	 * Refuse a file entry whose decoded byte count contradicts its declared size.
	 *
	 * The second guard behind the writer's own write-time size correction
	 * (defence in depth): a conforming writer always records the byte count it
	 * actually captured, so a mismatch here means the archive was produced by a
	 * writer that hit the scan-to-write race without correcting for it — the
	 * file changed while it was being backed up and the archive holds different
	 * content than it declares. Restoring such an entry would silently write a
	 * truncated or inflated file, so the reader fails closed instead. Applies
	 * to file entries only: a db_chunk's byte_count is a sizing estimate, and
	 * directories and symlinks carry no payload.
	 *
	 * @param EntryHeader   $header         The parsed entry header.
	 * @param int           $decoded_size   The byte count the payload actually decoded to.
	 * @param ManifestEntry $manifest_entry The entry being read, for diagnostic context.
	 * @return void
	 * @throws RuntimeException If a file entry's decoded byte count differs from its declared size.
	 */
	private function refuse_size_mismatch( EntryHeader $header, int $decoded_size, ManifestEntry $manifest_entry ): void {
		if ( ! $header->is_file() || null === $header->size() ) {
			return;
		}
		if ( $header->size() === $decoded_size ) {
			return;
		}
		throw new RuntimeException(
			sprintf(
				'EntryReader: entry %d ("%s") declares %d bytes but its payload decoded to %d. The file changed while it was being backed up and this archive does not hold the content it claims; refusing to restore it silently wrong.',
				(int) $manifest_entry->index(),
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $header->path() is reported verbatim for diagnostic context; exception path, not HTML output.
				(string) $header->path(),
				(int) $header->size(),
				(int) $decoded_size
			)
		);
	}

	/**
	 * Read and hash-verify one entry from the source, without decoding it.
	 *
	 * The integrity twin of {@see self::read_entry()} for the verify path. It
	 * streams the entry record from the source in chunks, hashing as it reads, and
	 * checks the computed SHA-256 against both the record's trailing hash and the
	 * manifest's recorded entry_hash — the same integrity guarantee read_entry
	 * gives. It never buffers the whole entry, decrypts, or decompresses, because
	 * a verification only needs to know the stored bytes are intact: the hash is
	 * computed over the as-stored bytes, so it holds whether the payload is
	 * compressed or encrypted. This keeps memory flat regardless of entry size and
	 * reports progress mid-entry through the optional callback, so a single large
	 * entry no longer blocks the walk.
	 *
	 * @param resource      $source            A seekable, readable stream containing the archive.
	 * @param ManifestEntry $manifest_entry    The manifest entry pointing at the on-disk record.
	 * @param callable|null $on_bytes          Optional progress callback, called as `( int $bytes ): void` with each chunk's byte count as the record streams.
	 * @param int|null      $max_decoded_bytes Optional decoded-size budget; when given, an entry whose header declares more decoded bytes than this is refused before the walk continues. Null enforces no such budget.
	 * @return void
	 * @throws InvalidArgumentException If $source is not a valid stream resource.
	 * @throws RuntimeException         If the record is truncated, its declared decoded size exceeds the budget, or its hash does not match the bytes on disk or the manifest.
	 */
	public function verify_entry( $source, ManifestEntry $manifest_entry, ?callable $on_bytes = null, ?int $max_decoded_bytes = null ): void {
		if ( ! is_resource( $source ) ) {
			throw new InvalidArgumentException( 'EntryReader: $source must be a valid stream resource.' );
		}

		$length = $manifest_entry->length();
		if ( $length < Sha256::DIGEST_SIZE ) {
			throw new RuntimeException(
				sprintf( 'EntryReader: entry record at offset %d is too short (%d bytes).', (int) $manifest_entry->offset(), (int) $length )
			);
		}

		// When a decoded-byte budget is given, refuse an over-budget entry here — before
		// any restore begins — so the browser's pre-write verify gate rejects a backup the
		// real restore would refuse mid-way, rather than starting and failing part-written.
		// Only the small header is read to learn the declared size; nothing is decoded.
		// Mirrors read_entry(): the budget applies only to shapes a restore must buffer
		// whole (encrypted entries and db_chunks) — a plain file entry streams, so no
		// memory refusal applies to it (ADR 0010).
		if ( null !== $max_decoded_bytes ) {
			$header = $this->peek_header( $source, $manifest_entry );
			if ( ! $this->streams_decoded_payload( $header, $manifest_entry->codec_id() ) ) {
				$this->refuse_if_over_budget( $header, $max_decoded_bytes );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek -- Reading from an open stream resource; WP_Filesystem has no equivalent.
		if ( -1 === fseek( $source, $manifest_entry->offset() ) ) {
			throw new RuntimeException(
				sprintf( 'EntryReader: could not seek to entry offset %d.', (int) $manifest_entry->offset() )
			);
		}

		// Hash everything up to the trailing 32-byte stored hash, streamed in chunks.
		$context   = hash_init( 'sha256' );
		$remaining = $length - Sha256::DIGEST_SIZE;
		while ( $remaining > 0 ) {
			$want = (int) min( self::READ_CHUNK_SIZE, $remaining );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Reading from an open stream resource; WP_Filesystem has no equivalent.
			$chunk = fread( $source, $want );
			if ( false === $chunk || '' === $chunk ) {
				throw new RuntimeException(
					sprintf( 'EntryReader: could not read entry bytes at offset %d; stream may be truncated.', (int) $manifest_entry->offset() )
				);
			}
			hash_update( $context, $chunk );
			$remaining -= strlen( $chunk );
			if ( null !== $on_bytes ) {
				$on_bytes( strlen( $chunk ) );
			}
		}
		$computed_hash = hash_final( $context, true );

		// Read the trailing stored hash.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Reading from an open stream resource; WP_Filesystem has no equivalent.
		$stored_hash = fread( $source, Sha256::DIGEST_SIZE );
		if ( false === $stored_hash || strlen( $stored_hash ) !== Sha256::DIGEST_SIZE ) {
			throw new RuntimeException(
				sprintf( 'EntryReader: could not read the entry hash at offset %d; stream may be truncated.', (int) $manifest_entry->offset() )
			);
		}
		if ( null !== $on_bytes ) {
			$on_bytes( Sha256::DIGEST_SIZE );
		}

		if ( ! hash_equals( $stored_hash, $computed_hash ) ) {
			throw new RuntimeException( 'EntryReader: entry hash does not match the bytes on disk; the entry has been tampered with or is corrupt.' );
		}
		if ( ! hash_equals( $manifest_entry->entry_hash(), $computed_hash ) ) {
			throw new RuntimeException( 'EntryReader: on-disk entry hash does not match the manifest entry_hash.' );
		}
	}

	/**
	 * Refuse an entry whose declared decoded size exceeds the given budget.
	 *
	 * The shared guard for both {@see self::read_entry()} (before it decodes) and
	 * {@see self::verify_entry()} (before any restore begins), using the header's
	 * declared decoded size ({@see EntryHeader::estimated_bytes()}). A null budget
	 * means no limit. The declared size is trusted only to fail closed, never to size
	 * an allocation, and the decode still enforces the same ceiling as it runs.
	 *
	 * @param EntryHeader $header            The parsed entry header.
	 * @param int|null    $max_decoded_bytes The maximum decoded bytes permitted, or null for no limit.
	 * @return void
	 * @throws RuntimeException If the declared decoded size exceeds the budget.
	 */
	private function refuse_if_over_budget( EntryHeader $header, ?int $max_decoded_bytes ): void {
		if ( null === $max_decoded_bytes ) {
			return;
		}
		$declared = $header->estimated_bytes();
		if ( $declared > $max_decoded_bytes ) {
			throw new RuntimeException(
				sprintf(
					'EntryReader: entry declares %d decoded bytes, exceeding the %d-byte budget for this restore.',
					(int) $declared,
					(int) $max_decoded_bytes
				)
			);
		}
	}

	/**
	 * Read and parse only an entry's header from the source stream.
	 *
	 * A light alternative to {@see self::read_entry()} for a caller that needs the
	 * header's declared metadata without decoding the payload — the verify path's
	 * budget check. Seeks to the entry, reads the length-prefixed header block, and
	 * parses it; the stream position afterwards is inside the record, so the caller
	 * re-seeks before its own read.
	 *
	 * @param resource      $source         The source stream.
	 * @param ManifestEntry $manifest_entry The entry pointing at the record.
	 * @return EntryHeader The parsed header.
	 * @throws RuntimeException If the seek or read fails, or the header is malformed.
	 */
	private function peek_header( $source, ManifestEntry $manifest_entry ): EntryHeader {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek -- Reading from an open stream resource; WP_Filesystem has no equivalent.
		if ( -1 === fseek( $source, $manifest_entry->offset() ) ) {
			throw new RuntimeException(
				sprintf( 'EntryReader: could not seek to entry offset %d.', (int) $manifest_entry->offset() )
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Reading from an open stream resource; WP_Filesystem has no equivalent.
		$prefix = fread( $source, EntryHeader::LENGTH_PREFIX_SIZE );
		if ( false === $prefix || strlen( $prefix ) !== EntryHeader::LENGTH_PREFIX_SIZE ) {
			throw new RuntimeException(
				sprintf( 'EntryReader: could not read the entry header length at offset %d; stream may be truncated.', (int) $manifest_entry->offset() )
			);
		}

		$header_length = ByteOrder::unpack_uint32( $prefix );
		if ( EntryHeader::LENGTH_PREFIX_SIZE + $header_length > $manifest_entry->length() ) {
			throw new RuntimeException(
				sprintf( 'EntryReader: declared header length %d does not fit inside entry record of %d bytes.', (int) $header_length, (int) $manifest_entry->length() )
			);
		}

		$header_bytes = '';
		$remaining    = $header_length;
		while ( $remaining > 0 ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Reading from an open stream resource; WP_Filesystem has no equivalent.
			$chunk = fread( $source, (int) min( self::READ_CHUNK_SIZE, $remaining ) );
			if ( false === $chunk || '' === $chunk ) {
				throw new RuntimeException(
					sprintf( 'EntryReader: could not read the entry header at offset %d; stream may be truncated.', (int) $manifest_entry->offset() )
				);
			}
			$header_bytes .= $chunk;
			$remaining    -= strlen( $chunk );
		}

		try {
			return EntryHeader::from_bytes( $prefix . $header_bytes );
		} catch ( InvalidArgumentException $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $e is the underlying parse exception, passed as the previous-exception argument for diagnostic chaining; not HTML output.
			throw new RuntimeException( 'EntryReader: entry header is malformed.', 0, $e );
		}
	}

	/**
	 * Decrypt an encrypted stored payload, then decompress it.
	 *
	 * Requires the cipher and key supplied at construction; without them the
	 * entry cannot be read and a clear "a passphrase is required" error is
	 * raised (the reader still verified the hash before reaching here, so the
	 * archive's integrity can be checked without the key). A decryption failure
	 * — wrong passphrase, or ciphertext/tag/nonce/AAD tampering — surfaces as a
	 * clear error too.
	 *
	 * @param int           $compression_codec_id The low-byte compression codec to decompress with.
	 * @param string        $stored_payload       The ciphertext with the 16-byte GCM tag appended.
	 * @param string        $nonce                The per-entry nonce read from the record.
	 * @param string        $aad                  The plaintext header bytes used as AAD.
	 * @param ManifestEntry $manifest_entry       The entry being read, for diagnostic context.
	 * @param int|null      $max_decoded_bytes    Maximum decompressed bytes to allow, or null.
	 * @return string The decompressed plaintext payload.
	 * @throws RuntimeException If no key is held, decryption fails, or decompression fails or overflows.
	 */
	private function decrypt_then_decompress(
		int $compression_codec_id,
		string $stored_payload,
		string $nonce,
		string $aad,
		ManifestEntry $manifest_entry,
		?int $max_decoded_bytes
	): string {
		if ( null === $this->cipher || null === $this->key ) {
			throw new RuntimeException(
				sprintf( 'EntryReader: entry %d is encrypted; a passphrase is required to read it.', (int) $manifest_entry->index() )
			);
		}

		try {
			$compressed = $this->cipher->decrypt( $stored_payload, $nonce, $aad, $this->key );
		} catch ( CipherException $e ) {
			$message = sprintf( 'EntryReader: entry %d failed to decrypt; the passphrase is wrong or the entry has been tampered with.', (int) $manifest_entry->index() );
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $e is the underlying cipher exception, chained as the previous exception for diagnostics; not HTML output.
			throw new RuntimeException( $message, 0, $e );
		}

		// Decompress the decrypted bytes through the same spool-based decode the
		// plain path uses; the plaintext is already authenticated by the GCM tag.
		$plaintext_spool = $this->open_spool();
		$this->write_all( $plaintext_spool, $compressed );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a php://temp spool, not a filesystem path.
		rewind( $plaintext_spool );
		try {
			return $this->decode_spool_to_string( $compression_codec_id, $plaintext_spool, $max_decoded_bytes );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Cleanup of a php://temp spool; not a filesystem path.
			fclose( $plaintext_spool );
		}
	}

	/**
	 * Read exactly $length bytes from the source, optionally feeding the running hash.
	 *
	 * The workhorse for the streamed record read: head fields and the trailing
	 * digest are small exact reads, each reported to the progress callback and —
	 * except the digest itself — folded into the incremental record hash.
	 *
	 * @param resource          $source         The source stream.
	 * @param int               $length         Exactly how many bytes to read; zero returns an empty string.
	 * @param ManifestEntry     $manifest_entry The entry being read, for diagnostic context.
	 * @param \HashContext|null $hash_context   Incremental hash to feed with the bytes, or null for the trailing digest (which the hash must not cover).
	 * @param callable|null     $on_bytes       Optional byte-progress callback, called as `( int $bytes ): void`.
	 * @return string The bytes read.
	 * @throws RuntimeException If fewer bytes are available than requested.
	 */
	private function read_exactly( $source, int $length, ManifestEntry $manifest_entry, ?\HashContext $hash_context, ?callable $on_bytes ): string {
		$bytes     = '';
		$remaining = $length;
		while ( $remaining > 0 ) {
			$want = (int) min( self::READ_CHUNK_SIZE, $remaining );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Reading from an open stream resource; WP_Filesystem has no equivalent.
			$chunk = fread( $source, $want );
			if ( false === $chunk || '' === $chunk ) {
				throw new RuntimeException(
					sprintf( 'EntryReader: could not read %d entry bytes at offset %d; stream may be truncated.', (int) $length, (int) $manifest_entry->offset() )
				);
			}
			$bytes     .= $chunk;
			$remaining -= strlen( $chunk );
			if ( null !== $hash_context ) {
				hash_update( $hash_context, $chunk );
			}
			if ( null !== $on_bytes ) {
				$on_bytes( strlen( $chunk ) );
			}
		}
		return $bytes;
	}

	/**
	 * Open a php://temp spool for payload bytes.
	 *
	 * In memory up to PHP's spill threshold (2 MiB by default), transparently on
	 * disk beyond it — the property that lets an arbitrarily large payload pass
	 * through without payload-sized memory.
	 *
	 * @return resource A read-write spool stream.
	 * @throws RuntimeException If php://temp cannot be opened.
	 */
	private function open_spool() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://temp is an in-process buffer, not a file; WP_Filesystem cannot open it.
		$spool = fopen( 'php://temp', 'r+b' );
		if ( false === $spool ) {
			throw new RuntimeException( 'EntryReader: could not open a php://temp spool for the entry payload.' );
		}
		return $spool;
	}

	/**
	 * Write the whole chunk to the spool, failing loudly on a short write.
	 *
	 * @param resource $spool The spool stream.
	 * @param string   $chunk The bytes to write.
	 * @return void
	 * @throws RuntimeException If the spool cannot accept the bytes (e.g. disk full under a spilled spool).
	 */
	private function write_all( $spool, string $chunk ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Operating on a php://temp spool, not a filesystem path.
		$written = fwrite( $spool, $chunk );
		if ( false === $written || strlen( $chunk ) !== $written ) {
			throw new RuntimeException( 'EntryReader: could not spool entry payload bytes; the temporary buffer may be out of space.' );
		}
	}

	/**
	 * Whether an entry's decoded payload leaves the reader as a stream.
	 *
	 * True only for a plain (unencrypted) file entry: its destination is the
	 * filesystem, so it never needs to be a string. Encrypted entries must
	 * buffer (PHP's AEAD is one-shot and the tag must verify before use), and a
	 * db_chunk must be a whole string for statement splitting (ADR 0010).
	 *
	 * @param EntryHeader $header   The parsed entry header.
	 * @param int         $codec_id The entry's full codec id, including any encryption bit.
	 * @return bool True when the decoded payload streams.
	 */
	private function streams_decoded_payload( EntryHeader $header, int $codec_id ): bool {
		return ! CodecId::is_encrypted( $codec_id ) && $header->is_file();
	}

	/**
	 * The tighter of two optional byte limits.
	 *
	 * @param int|null $first  One limit, or null for none.
	 * @param int|null $second The other limit, or null for none.
	 * @return int|null The smaller of the two, or null when neither is set.
	 */
	private static function tighter_limit( ?int $first, ?int $second ): ?int {
		if ( null === $first ) {
			return $second;
		}
		if ( null === $second ) {
			return $first;
		}
		return min( $first, $second );
	}

	/**
	 * Decode a spooled payload through the compression codec into a string.
	 *
	 * The buffered decode for shapes whose consumers need a string (db_chunks;
	 * the decrypted bytes of an encrypted entry take the sibling path through
	 * {@see self::decrypt_then_decompress()}). The id is the compression-family
	 * codec id; any encryption has already been removed before this runs.
	 *
	 * @param int      $compression_codec_id The compression codec id to decode with.
	 * @param resource $spool                The spooled stored payload, positioned at its start.
	 * @param int|null $max_decoded_bytes    Maximum decoded bytes to allow, or null for no limit.
	 * @return string The decoded bytes.
	 * @throws RuntimeException If a stream cannot be opened or the codec fails.
	 */
	private function decode_spool_to_string( int $compression_codec_id, $spool, ?int $max_decoded_bytes ): string {
		$output = $this->open_spool();

		try {
			$this->codec_registry->get( $compression_codec_id )->decode( $spool, $output, $max_decoded_bytes );
		} catch ( CodecException $e ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Cleanup of php://temp buffer; not a filesystem path.
			fclose( $output );
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $e is the underlying codec exception, passed as the previous-exception argument for diagnostic chaining; not HTML output.
			throw new RuntimeException( 'EntryReader: codec failed to decode entry payload.', 0, $e );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a php://temp buffer, not a filesystem path.
		rewind( $output );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_stream_get_contents -- Operating on a php://temp buffer, not a filesystem path.
		$decoded = stream_get_contents( $output );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Cleanup of php://temp buffer; not a filesystem path.
		fclose( $output );

		if ( false === $decoded ) {
			throw new RuntimeException( 'EntryReader: could not read decoded bytes from php://temp output buffer.' );
		}
		return $decoded;
	}
}
