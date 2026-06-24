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
 * Buffering: payload bytes are buffered through a php://temp stream
 * before being decoded, matching the writer's strategy. This caps
 * memory at php://temp's spill threshold (2 MiB by default) for any
 * entry size.
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
	 * Seeks to manifest_entry.offset, reads manifest_entry.length
	 * bytes, parses the five fields, runs all verification checks,
	 * and decodes the payload through the codec in reverse. Returns
	 * an EntryReadResult bundling the parsed header and the decoded
	 * payload.
	 *
	 * The source stream's seek position after this call is
	 * unspecified. The stream is owned by the caller; EntryReader
	 * does not close it.
	 *
	 * @param resource      $source            A seekable, readable stream containing the archive.
	 * @param ManifestEntry $manifest_entry    The manifest entry pointing at the on-disk record to read.
	 * @param int|null      $max_decoded_bytes Maximum bytes the decoded payload may produce. Defaults to DEFAULT_MAX_DECODED_BYTES; pass null for no limit.
	 * @return EntryReadResult The parsed header and decoded payload.
	 * @throws InvalidArgumentException If $source is not a valid stream resource or is not seekable.
	 * @throws RuntimeException         If reading fails, the bytes are malformed, hash verification fails, the codec is not registered, or the decoded payload exceeds $max_decoded_bytes.
	 */
	public function read_entry( $source, ManifestEntry $manifest_entry, ?int $max_decoded_bytes = self::DEFAULT_MAX_DECODED_BYTES ): EntryReadResult {
		if ( ! is_resource( $source ) ) {
			throw new InvalidArgumentException( 'EntryReader: $source must be a valid stream resource.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_stream_get_meta_data -- Inspecting an open stream resource; WP_Filesystem has no equivalent.
		$meta = stream_get_meta_data( $source );
		if ( empty( $meta['seekable'] ) ) {
			throw new InvalidArgumentException( 'EntryReader: $source stream must be seekable.' );
		}

		$record_bytes = $this->read_record_bytes( $source, $manifest_entry );
		$record_len   = strlen( $record_bytes );

		// Minimum record size: 4-byte header_length + at least 1 byte of header + 2-byte codec_id + 12-byte nonce + 32-byte hash.
		$min_record_size = EntryHeader::LENGTH_PREFIX_SIZE + 1 + 2 + EntryWriter::NONCE_SIZE + Sha256::DIGEST_SIZE;
		if ( $record_len < $min_record_size ) {
			throw new RuntimeException(
				sprintf( 'EntryReader: entry record at offset %d is too short (%d bytes).', (int) $manifest_entry->offset(), (int) $record_len )
			);
		}

		// Read the header_length prefix.
		$header_length = ByteOrder::unpack_uint32( substr( $record_bytes, 0, EntryHeader::LENGTH_PREFIX_SIZE ) );
		$header_end    = EntryHeader::LENGTH_PREFIX_SIZE + $header_length;
		if ( $header_end + 2 + EntryWriter::NONCE_SIZE + Sha256::DIGEST_SIZE > $record_len ) {
			throw new RuntimeException(
				sprintf( 'EntryReader: declared header length %d does not fit inside entry record of %d bytes.', (int) $header_length, (int) $record_len )
			);
		}

		// Parse the header. EntryHeader::from_bytes expects the length prefix included, so we feed it offset 0..header_end.
		try {
			$header = EntryHeader::from_bytes( substr( $record_bytes, 0, $header_end ) );
		} catch ( InvalidArgumentException $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $e is the underlying parse exception, passed as the previous-exception argument for diagnostic chaining; not HTML output.
			throw new RuntimeException( 'EntryReader: entry header is malformed.', 0, $e );
		}

		// Read codec_id and verify it matches the manifest entry.
		$codec_id = ByteOrder::unpack_uint16( substr( $record_bytes, $header_end, ByteOrder::UINT16_SIZE ) );
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
		$nonce        = substr( $record_bytes, $header_end + ByteOrder::UINT16_SIZE, EntryWriter::NONCE_SIZE );
		$header_bytes = substr( $record_bytes, 0, $header_end );

		// Locate the payload and the trailing hash.
		$payload_start  = $header_end + ByteOrder::UINT16_SIZE + EntryWriter::NONCE_SIZE;
		$payload_end    = $record_len - Sha256::DIGEST_SIZE;
		$payload_length = $payload_end - $payload_start;
		if ( $payload_length < 0 ) {
			throw new RuntimeException( 'EntryReader: entry record layout is inconsistent; payload has negative length.' );
		}

		// Verify the trailing hash: SHA-256 of everything before it. It is computed over
		// the as-stored bytes, so it holds whether or not the payload is encrypted.
		$expected_hash = substr( $record_bytes, $payload_end, Sha256::DIGEST_SIZE );
		$computed_hash = Sha256::of( substr( $record_bytes, 0, $payload_end ) );
		if ( ! hash_equals( $expected_hash, $computed_hash ) ) {
			throw new RuntimeException( 'EntryReader: entry hash does not match the bytes on disk; the entry has been tampered with or is corrupt.' );
		}

		// Verify the on-disk hash matches the manifest's recorded entry_hash.
		if ( ! hash_equals( $manifest_entry->entry_hash(), $expected_hash ) ) {
			throw new RuntimeException( 'EntryReader: on-disk entry hash does not match the manifest entry_hash.' );
		}

		// Decode the stored payload. For an encrypted entry, decrypt first (AES-256-GCM,
		// header bytes as AAD) then decompress; otherwise decompress directly.
		$stored_payload = substr( $record_bytes, $payload_start, $payload_length );
		if ( CodecId::is_encrypted( $codec_id ) ) {
			$decoded_payload = $this->decrypt_then_decompress(
				$compression_codec_id,
				$stored_payload,
				$nonce,
				$header_bytes,
				$manifest_entry,
				$max_decoded_bytes
			);
		} else {
			$decoded_payload = $this->decode_payload( $compression_codec_id, $stored_payload, $max_decoded_bytes );
		}

		return new EntryReadResult( $header, $decoded_payload );
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

		return $this->decode_payload( $compression_codec_id, $compressed, $max_decoded_bytes );
	}

	/**
	 * Read the on-disk entry record bytes for the given manifest entry.
	 *
	 * @param resource      $source         The source stream.
	 * @param ManifestEntry $manifest_entry The entry pointing at the record.
	 * @return string The raw on-disk record bytes.
	 * @throws RuntimeException If the seek fails or fewer bytes are returned than expected.
	 */
	private function read_record_bytes( $source, ManifestEntry $manifest_entry ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek -- Reading from an open stream resource; WP_Filesystem has no equivalent.
		if ( -1 === fseek( $source, $manifest_entry->offset() ) ) {
			throw new RuntimeException(
				sprintf( 'EntryReader: could not seek to entry offset %d.', (int) $manifest_entry->offset() )
			);
		}

		$length = $manifest_entry->length();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Reading from an open stream resource; WP_Filesystem has no equivalent.
		$bytes = fread( $source, $length );
		if ( false === $bytes || strlen( $bytes ) !== $length ) {
			throw new RuntimeException(
				sprintf( 'EntryReader: could not read %d entry bytes at offset %d; stream may be truncated.', (int) $length, (int) $manifest_entry->offset() )
			);
		}
		return $bytes;
	}

	/**
	 * Decode payload bytes through the compression codec registered for the given id.
	 *
	 * Uses a php://temp buffer for the input and another for the decoded
	 * output so the codec can operate on streams, then reads the decoded bytes
	 * back. Mirrors EntryWriter's encoding-via-temp-buffer pattern in reverse.
	 * The id is the compression-family codec id; any encryption has already
	 * been removed by the caller before this runs.
	 *
	 * @param int      $compression_codec_id The compression codec id to decode with.
	 * @param string   $encoded_payload      The (already-decrypted) compressed bytes to decode.
	 * @param int|null $max_decoded_bytes    Maximum decoded bytes to allow, or null for no limit.
	 * @return string The decoded bytes.
	 * @throws RuntimeException If a stream cannot be opened or the codec fails.
	 */
	private function decode_payload( int $compression_codec_id, string $encoded_payload, ?int $max_decoded_bytes ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://temp is an in-process buffer, not a file; WP_Filesystem cannot open it.
		$input = fopen( 'php://temp', 'r+b' );
		if ( false === $input ) {
			throw new RuntimeException( 'EntryReader: could not open php://temp for decode input.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Operating on a php://temp buffer, not a filesystem path.
		fwrite( $input, $encoded_payload );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a php://temp buffer, not a filesystem path.
		rewind( $input );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://temp is an in-process buffer, not a file; WP_Filesystem cannot open it.
		$output = fopen( 'php://temp', 'r+b' );
		if ( false === $output ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Cleanup of php://temp buffer; not a filesystem path.
			fclose( $input );
			throw new RuntimeException( 'EntryReader: could not open php://temp for decode output.' );
		}

		try {
			$this->codec_registry->get( $compression_codec_id )->decode( $input, $output, $max_decoded_bytes );
		} catch ( CodecException $e ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Cleanup of php://temp buffer; not a filesystem path.
			fclose( $input );
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
		fclose( $input );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Cleanup of php://temp buffer; not a filesystem path.
		fclose( $output );

		if ( false === $decoded ) {
			throw new RuntimeException( 'EntryReader: could not read decoded bytes from php://temp output buffer.' );
		}
		return $decoded;
	}
}
