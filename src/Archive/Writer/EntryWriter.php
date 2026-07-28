<?php
/**
 * Pontifex archive entry writer — emits one entry to a stream per spec §6.
 *
 * @package Pontifex\Archive\Writer
 */

declare(strict_types=1);

namespace Pontifex\Archive\Writer;

use InvalidArgumentException;
use RuntimeException;
use Pontifex\Archive\Codec\CodecId;
use Pontifex\Archive\Codec\CodecRegistry;
use Pontifex\Archive\Crypto\Cipher;
use Pontifex\Archive\Format\ByteOrder;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Integrity\HashingStream;
use Pontifex\Archive\Integrity\Sha256;

/**
 * Writes one archive entry record to a destination stream.
 *
 * Per ARCHIVE-FORMAT.md §6, an entry record on disk has the layout:
 *
 *   header_length (4 B) || header_JSON || codec_id (2 B) || nonce (12 B) || payload || hash (32 B)
 *
 * The trailing 32-byte hash is SHA-256 of everything before it (per
 * spec §6 — "header_length || header || codec_id || nonce ||
 * payload"). EntryWriter computes this hash while writing, so the
 * final stored hash matches exactly.
 *
 * EntryWriter knows nothing about offsets, the manifest, or other
 * entries. It writes one record from the destination stream's current
 * seek position and reports back via {@see EntryWriteResult}. The
 * ArchiveWriter (a later commit) owns the broader archive structure
 * and composes EntryWriter calls into the full archive.
 *
 * Algorithm:
 *
 *  1. For a file entry whose header has no media_type yet, sniff one
 *     from the source's underlying path and build a corrected header
 *     via with_media_type() — before anything else, simply because
 *     this is where the header is finalised. The sniff opens its own
 *     independent handle via finfo_file() on the recovered path; it
 *     never reads from the source stream itself, so the source's seek
 *     position is untouched by this step. A header that already
 *     carries a media_type (a caller-supplied, authoritative value) is
 *     left alone and not re-sniffed. See {@see self::sniff_media_type()}.
 *
 *  2. Pre-encode the source payload to a php://temp buffer via the
 *     codec. This is what tells us the encoded payload byte count,
 *     which the EntryHeader needs as `size_compressed` BEFORE the
 *     header is serialised to disk (the header sits in front of the
 *     payload on disk; the writer has to know payload length to
 *     declare it in the header).
 *
 *  3. Build a corrected EntryHeader using with_size_compressed() —
 *     the immutable update method on EntryHeader. The caller passes
 *     in a "draft" header with any size_compressed value (typically
 *     0); we replace it with the actual encoded byte count.
 *
 *  4. Serialise the corrected header to bytes.
 *
 *  5. Stream the record to disk in spec order: header bytes,
 *     codec_id (2 B big-endian), nonce (12 B), payload (copied from
 *     the temp buffer), hash (32 B).
 *
 *  6. As bytes are written, feed them to a HashingStream so the
 *     accumulated SHA-256 covers everything except the trailing
 *     hash itself.
 *
 * Buffering: the payload buffers through `php://temp`, which spills
 * from memory to disk past 2 MiB by default. This doubles the disk
 * I/O for large entries but keeps memory bounded and avoids PHP's
 * stream-filter API, which is awkward to use programmatically. The
 * trade-off can be revisited if profiling shows it matters.
 *
 * Threading and reuse: EntryWriter is stateless after construction.
 * Each call to write_entry() creates its own HashingStream and
 * php://temp buffer. Safe to reuse the same EntryWriter for many
 * entries.
 *
 * Stream positioning: the destination stream's seek position is the
 * caller's concern. EntryWriter writes from wherever the destination
 * is and advances it by the entry's total length.
 */
final class EntryWriter {

	/**
	 * Length of the per-entry nonce field on disk, in bytes (spec §6).
	 *
	 * @var int
	 */
	public const NONCE_SIZE = 12;

	/**
	 * Codec registry used to look up codecs by id.
	 *
	 * @var CodecRegistry
	 */
	private CodecRegistry $codec_registry;

	/**
	 * Construct an EntryWriter against a codec registry.
	 *
	 * The registry must already contain the codecs that callers will
	 * request via codec_id. Typically built once at archive-write time
	 * (e.g. via CodecRegistry::with_defaults()) and reused across all
	 * entries.
	 *
	 * @param CodecRegistry $codec_registry Source of codec implementations by id.
	 */
	public function __construct( CodecRegistry $codec_registry ) {
		$this->codec_registry = $codec_registry;
	}

	/**
	 * Write one entry record to the destination stream.
	 *
	 * The codec id's low byte selects the compression codec; its high byte
	 * selects encryption (0x0100 = AES-256-GCM). When the high byte is set, a
	 * cipher and key must be supplied: the payload is compressed and then
	 * encrypted, with the entry header bytes as the additional authenticated
	 * data (AAD), and the stored payload is the ciphertext with the 16-byte GCM
	 * tag appended.
	 *
	 * @param EntryHeader   $header      Entry metadata. The size_compressed field is
	 *                                   overwritten by the writer once the codec has run —
	 *                                   it records the compression output, NOT the
	 *                                   post-encryption stored size — so callers may pass a
	 *                                   draft header with any value (typically 0). For a file
	 *                                   entry, media_type may be left null; the writer sniffs
	 *                                   it from the source before writing. A non-null
	 *                                   media_type is trusted as-is and never re-sniffed.
	 * @param int           $codec_id    Codec id for the payload. Low byte = compression
	 *                                   codec (must be registered); high byte = encryption.
	 * @param string        $nonce       Per-entry nonce; must be exactly NONCE_SIZE bytes.
	 *                                   Spec §8.3 for the construction rules; for unencrypted
	 *                                   entries it is present but unused and should be
	 *                                   zero-filled.
	 * @param resource      $source      Readable stream resource. Read from the current seek
	 *                                   position until EOF. The caller controls positioning.
	 * @param resource      $destination Writable stream resource. Bytes are appended at the
	 *                                   destination's current seek position and it is
	 *                                   advanced by total_entry_length bytes.
	 * @param Cipher|null   $cipher      Cipher to encrypt with; required when the codec id's
	 *                                   encryption byte is set, unused otherwise.
	 * @param string|null   $key         Encryption key; required when the codec id's
	 *                                   encryption byte is set, unused otherwise.
	 * @param callable|null $on_bytes_read Optional byte-progress callback forwarded to the codec, called as `( int $bytes ): void` with each chunk's raw source byte count as the payload streams.
	 * @return EntryWriteResult Stored payload length, total entry record length, and the
	 *                          SHA-256 hash that was written to disk. For a file entry whose
	 *                          source yielded a different byte count than the header declared
	 *                          (the file changed between the caller's scan and this write),
	 *                          the header is written with the actual captured size and the
	 *                          result reports the discrepancy. For a file entry whose
	 *                          media_type had to be sniffed here, the result also reports
	 *                          whether the sniff genuinely resolved one — see
	 *                          {@see self::sniff_media_type()}.
	 * @throws InvalidArgumentException If the encryption family is unknown, the compression
	 *                                  codec is not registered, an encrypted codec is used
	 *                                  without a cipher and key, the nonce is the wrong
	 *                                  length, or source/destination are not resources.
	 * @throws RuntimeException         On any I/O failure or if encryption fails.
	 */
	public function write_entry(
		EntryHeader $header,
		int $codec_id,
		string $nonce,
		$source,
		$destination,
		?Cipher $cipher = null,
		?string $key = null,
		?callable $on_bytes_read = null
	): EntryWriteResult {
		$compression_codec_id = CodecId::compression( $codec_id );
		$encryption_family    = CodecId::encryption_family( $codec_id );
		$is_encrypted         = CodecId::is_encrypted( $codec_id );

		if ( $is_encrypted && CodecId::ENCRYPTION_AES_GCM !== $encryption_family ) {
			throw new InvalidArgumentException(
				sprintf( 'EntryWriter: unknown encryption family 0x%04X in codec id 0x%04X.', (int) $encryption_family, (int) $codec_id )
			);
		}
		if ( ! $this->codec_registry->has( $compression_codec_id ) ) {
			throw new InvalidArgumentException(
				sprintf( 'EntryWriter: compression codec 0x%04X (from codec id 0x%04X) is not registered with the codec registry.', (int) $compression_codec_id, (int) $codec_id )
			);
		}
		if ( $is_encrypted && ( null === $cipher || null === $key ) ) {
			throw new InvalidArgumentException(
				sprintf( 'EntryWriter: codec id 0x%04X is encrypted but no cipher and key were supplied.', (int) $codec_id )
			);
		}
		if ( self::NONCE_SIZE !== strlen( $nonce ) ) {
			throw new InvalidArgumentException(
				sprintf(
					'EntryWriter: nonce must be exactly %d bytes, got %d.',
					(int) self::NONCE_SIZE,
					(int) strlen( $nonce )
				)
			);
		}
		if ( ! is_resource( $source ) ) {
			throw new InvalidArgumentException( 'EntryWriter: $source must be a valid stream resource.' );
		}
		if ( ! is_resource( $destination ) ) {
			throw new InvalidArgumentException( 'EntryWriter: $destination must be a valid stream resource.' );
		}

		// A file entry with no media_type yet gets one sniffed here, from the
		// source's underlying path. A header that already carries a media_type
		// (set by a caller that already knows it) is trusted as-is and left
		// alone — that also keeps a from-disk-adopted or hand-built header's
		// value authoritative and avoids a pointless read. Only an entry that
		// actually reaches the sniff can be counted as resolved or unresolved;
		// a trusted header counts as neither.
		$media_type_unresolved = false;
		if ( $header->is_file() && null === $header->media_type() ) {
			$sniffed               = self::sniff_media_type( $source );
			$header                = $header->with_media_type( $sniffed['media_type'] );
			$media_type_unresolved = ! $sniffed['resolved'];
		}

		$codec = $this->codec_registry->get( $compression_codec_id );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://temp is an in-process buffer, not a file; WP_Filesystem has no equivalent abstraction and would be the wrong tool here.
		$temp = fopen( 'php://temp', 'r+b' );
		if ( false === $temp ) {
			throw new RuntimeException( 'EntryWriter: could not open php://temp buffer for the encoded payload.' );
		}

		try {
			// Compress the source payload into the temp buffer. The codec returns the
			// compressed byte count, which becomes size_compressed in the header — the
			// compression output, NOT the post-encryption stored size (the 16-byte GCM
			// tag is encryption framing, counted only in the stored payload and lengths).
			// The wrapper around the progress callback counts the raw source bytes the
			// codec actually read, so a file that shrank or grew between the caller's
			// scan and this write is caught below rather than silently recorded at its
			// stale scan-time size.
			$raw_bytes_read = 0;
			$counting_read  = static function ( int $bytes ) use ( &$raw_bytes_read, $on_bytes_read ): void {
				$raw_bytes_read += $bytes;
				if ( null !== $on_bytes_read ) {
					$on_bytes_read( $bytes );
				}
			};

			$compressed_length = $codec->encode( $source, $temp, $counting_read );

			// A file entry whose content no longer matches its declared size changed
			// between scan and write. Record the truth: the header is corrected to the
			// byte count actually captured, and the discrepancy is reported to the
			// caller so it can warn the user. Without this, the archive would declare
			// the stale size over different content — a backup that verifies clean
			// while having silently lost data.
			$declared_size = null;
			$actual_size   = null;
			if ( $header->is_file() && null !== $header->size() && $header->size() !== $raw_bytes_read ) {
				$declared_size = $header->size();
				$actual_size   = $raw_bytes_read;
				$header        = $header->with_size( $raw_bytes_read );
			}

			// Build the corrected header now that the compressed byte count is known.
			// Finalising it here also lets it serve as the AES-GCM additional
			// authenticated data (AAD) before encryption runs.
			$corrected_header = $header->with_size_compressed( $compressed_length );
			$header_bytes     = $corrected_header->to_bytes();

			// For an encrypted entry the stored payload is ciphertext||tag, produced by a
			// one-shot AES-GCM call over the whole compressed buffer (PHP exposes no
			// streaming AEAD), bound to the header bytes as AAD. For an unencrypted entry
			// the stored payload streams straight from the temp buffer.
			$sealed_payload = null;
			if ( $is_encrypted && null !== $cipher && null !== $key ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a php://temp stream resource, not a filesystem path.
				rewind( $temp );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_stream_get_contents -- Operating on a php://temp stream resource, not a filesystem path.
				$compressed = stream_get_contents( $temp );
				if ( false === $compressed ) {
					throw new RuntimeException( 'EntryWriter: could not read the compressed payload from the temp buffer for encryption.' );
				}
				$sealed_payload = $cipher->encrypt( $compressed, $nonce, $header_bytes, $key );
			}

			// HashingStream accumulates SHA-256 over everything except the trailing hash.
			$hasher = new HashingStream();

			// Header bytes.
			self::write_all( $destination, $header_bytes );
			$hasher->update( $header_bytes );

			// codec_id: 2 big-endian bytes.
			$codec_id_bytes = ByteOrder::pack_uint16( $codec_id );
			self::write_all( $destination, $codec_id_bytes );
			$hasher->update( $codec_id_bytes );

			// nonce: 12 bytes verbatim.
			self::write_all( $destination, $nonce );
			$hasher->update( $nonce );

			// Payload: the encrypted bytes for an encrypted entry, or the compressed bytes
			// streamed from the temp buffer otherwise.
			if ( null !== $sealed_payload ) {
				self::write_all( $destination, $sealed_payload );
				$hasher->update( $sealed_payload );
				$payload_length = strlen( $sealed_payload );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a php://temp stream resource, not a filesystem path.
				rewind( $temp );
				$hasher->copy( $temp, $destination );
				$payload_length = $compressed_length;
			}

			// Finalise the hash and write it directly (it cannot cover itself).
			$entry_hash = $hasher->digest();
			self::write_all( $destination, $entry_hash );

			$total_entry_length = strlen( $header_bytes )
				+ ByteOrder::UINT16_SIZE
				+ self::NONCE_SIZE
				+ $payload_length
				+ Sha256::DIGEST_SIZE;

			return new EntryWriteResult( $payload_length, $total_entry_length, $entry_hash, $declared_size, $actual_size, $media_type_unresolved );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the php://temp resource opened in this method; not a WP_Filesystem operation.
			fclose( $temp );
		}
	}

	/**
	 * Sniff the MIME type of a file entry's source via finfo.
	 *
	 * Moved here from FileScanner (formerly sniff_media_type() there): the
	 * scanner used to sniff every file it walked, which meant opening and
	 * reading the head of every file on every scan — including a resumable
	 * export's final tick, which writes no entries at all. Doing it here
	 * instead means each file is sniffed once per backup, only for the
	 * entry actually being written, and never for a tick that writes
	 * nothing. This is the one implementation; nothing else in Pontifex
	 * sniffs a media type.
	 *
	 * EntryWriter only ever receives an already-open source stream, never a
	 * path, so the underlying filesystem path is recovered from the
	 * stream's own metadata rather than reopened by the caller. That
	 * recovery is trusted only when the reported uri actually resolves to
	 * an existing, readable regular file — checked with is_file() and
	 * is_readable() on the uri itself, through the same wrapper
	 * resolution finfo_file() will use. A stream whose uri is empty, or
	 * is a non-empty identifier that does not resolve to a real file (a
	 * php://temp or php://memory buffer reports "php://temp" /
	 * "php://memory" as its uri, for instance — a string, but not a
	 * filesystem path anything can open), falls through to the fallback
	 * instead of being handed to finfo_file(): finfo_file() opens
	 * whatever string it is given as a brand-new, unrelated stream, so a
	 * uri that merely looks path-shaped would not fail — it would sniff
	 * and confidently return a media type describing something other
	 * than the payload actually being written. This method never throws.
	 *
	 * There are four ways the sniff can fail to genuinely determine a media
	 * type — the fileinfo extension is not loaded on this host, the source's
	 * uri does not resolve to a real readable file, finfo_open() itself
	 * fails (e.g. an unavailable magic database), or finfo_file() fails or
	 * returns nothing — and every one of them falls back to the same
	 * 'application/octet-stream' string a genuinely unidentifiable file
	 * (a real .DS_Store, say) legitimately sniffs as too. Without a
	 * separate signal, a caller cannot tell "correctly identified as
	 * unknown" from "we failed and gave up" — and a systemic failure (the
	 * fileinfo extension missing on a host, for instance) would silently
	 * record every file in every archive as raw bytes with nothing
	 * anywhere saying so. The 'resolved' flag exists purely to carry that
	 * distinction back to the caller, which tallies it into a counter the
	 * operator can see.
	 *
	 * @param resource $source The entry's payload source stream.
	 * @return array{media_type: string, resolved: bool} The sniffed (or fallback)
	 *                MIME-type string, and whether it was genuinely determined by
	 *                finfo (true) rather than reached via one of the four fallback
	 *                paths above (false).
	 */
	private static function sniff_media_type( $source ): array {
		$fallback = 'application/octet-stream';

		if ( ! function_exists( 'finfo_open' ) ) {
			// The fileinfo extension is not loaded on this host.
			return array(
				'media_type' => $fallback,
				'resolved'   => false,
			);
		}

		$meta          = stream_get_meta_data( $source );
		$absolute_path = isset( $meta['uri'] ) ? (string) $meta['uri'] : '';
		if ( '' === $absolute_path || ! is_file( $absolute_path ) || ! is_readable( $absolute_path ) ) {
			// The source's reported uri does not resolve to a real, readable file.
			return array(
				'media_type' => $fallback,
				'resolved'   => false,
			);
		}

		// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged,WordPress.PHP.NoSilencedErrors.Discouraged -- finfo_open emits a warning when the magic database is unavailable; the warning is informational and we already handle the false return.
		$handle = @finfo_open( FILEINFO_MIME_TYPE );
		if ( false === $handle ) {
			// The fileinfo magic database could not be opened.
			return array(
				'media_type' => $fallback,
				'resolved'   => false,
			);
		}

		// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged,WordPress.PHP.NoSilencedErrors.Discouraged -- finfo_file emits a warning on unreadable files; the warning is informational and we already handle the false return.
		$detected = @finfo_file( $handle, $absolute_path );
		// finfo_close() was deprecated in PHP 8.5; $handle is cleaned up by garbage collection when it goes out of scope at the end of this method.

		if ( false === $detected || '' === $detected ) {
			// finfo_file() itself failed, or returned nothing.
			return array(
				'media_type' => $fallback,
				'resolved'   => false,
			);
		}

		return array(
			'media_type' => $detected,
			'resolved'   => true,
		);
	}

	/**
	 * Write bytes to a stream, throwing on partial-write.
	 *
	 * The destination streams Pontifex uses (php://memory, php://temp,
	 * regular file handles) do not partial-write in practice, but
	 * checking is cheap and prevents silent corruption when something
	 * unexpected happens (a near-full disk, a pipe with backpressure,
	 * a custom stream wrapper).
	 *
	 * @param resource $destination A writable stream resource.
	 * @param string   $bytes       The exact bytes to write.
	 * @return void
	 * @throws RuntimeException If fwrite() fails or returns fewer bytes than requested.
	 */
	private static function write_all( $destination, string $bytes ): void {
		$length = strlen( $bytes );
		if ( 0 === $length ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- EntryWriter operates on arbitrary stream resources from the archive layer; WP_Filesystem has no streaming API.
		$written = fwrite( $destination, $bytes );
		if ( false === $written ) {
			throw new RuntimeException( 'EntryWriter: fwrite() failed on destination stream.' );
		}
		if ( $written !== $length ) {
			throw new RuntimeException(
				sprintf(
					'EntryWriter: partial write detected (%d of %d bytes); aborting to preserve entry integrity.',
					(int) $written,
					(int) $length
				)
			);
		}
	}
}
