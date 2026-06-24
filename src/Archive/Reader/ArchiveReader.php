<?php
/**
 * Pontifex archive reader — parses Header and Footer from an open archive stream.
 *
 * @package Pontifex\Archive\Reader
 */

declare(strict_types=1);

namespace Pontifex\Archive\Reader;

use InvalidArgumentException;
use RuntimeException;
use Pontifex\Archive\Crypto\Ed25519Verifier;
use Pontifex\Archive\Format\ArchiveManifest;
use Pontifex\Archive\Format\ArchiveSignature;
use Pontifex\Archive\Format\ByteOrder;
use Pontifex\Archive\Format\Footer;
use Pontifex\Archive\Format\Header;
use Pontifex\Archive\Format\Provenance;
use Pontifex\Archive\Integrity\Sha256;

/**
 * Opens a Pontifex archive and exposes its high-level structure.
 *
 * This is the entry point for reading archives. It parses the
 * fixed-size Header at offset 0 and the fixed-size Footer at the
 * end of the stream eagerly at construction time; the variable-size
 * manifest block is parsed lazily on first access via
 * {@see ArchiveReader::manifest()}, then cached.
 *
 * Symmetric with {@see \Pontifex\Archive\Writer\ArchiveWriter}: every
 * piece of information ArchiveWriter writes, ArchiveReader knows
 * how to find again.
 *
 * Public API (locked for v0.1.0):
 *
 *  - {@see ArchiveReader::__construct()} — takes a seekable readable
 *    stream resource; parses Header and Footer eagerly so the
 *    constructor either succeeds with a fully-validated reader or
 *    throws.
 *  - {@see ArchiveReader::header()} — the parsed Header.
 *  - {@see ArchiveReader::footer()} — the parsed Footer.
 *  - {@see ArchiveReader::manifest_offset()} — byte offset where the
 *    manifest block begins, from the Footer.
 *  - {@see ArchiveReader::manifest_length()} — declared length of
 *    the manifest block in bytes, from the Footer.
 *  - {@see ArchiveReader::manifest()} — the parsed ArchiveManifest,
 *    read and cached on first access. Verifies the manifest's
 *    internal hash matches the Footer's recorded hash; throws if
 *    they disagree.
 *  - {@see ArchiveReader::provenance()} — the parsed Provenance block
 *    (source-site facts, including the source URL used by cross-URL
 *    migration), read and cached on first access; bounds-checked and
 *    hash-verified, so a corrupt block is refused.
 *  - {@see ArchiveReader::signature()} — the parsed signature block, or
 *    null when the archive is unsigned (header signed flag clear). Read
 *    eagerly at construction for a signed archive, so the footer is then
 *    located 64 bytes before it rather than at end of file.
 *  - {@see ArchiveReader::verify_signature()} — verify the Ed25519
 *    signature against a trusted public key; false means unsigned, wrong
 *    key, or tampered.
 *
 * Internal choices (implementation details; may change without
 * breaking the public API):
 *
 *  - Eager parsing of Header and Footer at construction time. Both
 *    are tiny (16 and 64 bytes respectively) and reading them up
 *    front lets the caller rely on the accessors never failing.
 *  - Lazy parsing of the manifest. The manifest can be megabytes;
 *    parsing only happens on first call to manifest(). The result
 *    is cached so subsequent calls are O(1).
 *  - Double hash check on the manifest. ArchiveManifest::from_bytes
 *    already verifies its own internal hash; ArchiveReader
 *    additionally verifies that internal hash equals the Footer's
 *    manifest_hash. Defense in depth against tampering with just
 *    one of the two recorded copies.
 *  - The source stream's seek position is changed by this class.
 *    Callers should not assume the position is preserved.
 *  - Stream ownership: the caller owns the stream. ArchiveReader
 *    does not close it on destruction.
 */
final class ArchiveReader {

	/**
	 * Bytes read per chunk when streaming the archive to recompute the signed digest.
	 *
	 * Sized well under the reader's memory budget; a larger value only reduces the
	 * number of read calls.
	 *
	 * @var int
	 */
	private const SIGNED_DIGEST_CHUNK_SIZE = 1048576;

	/**
	 * The readable, seekable stream the archive is read from.
	 *
	 * @var resource
	 */
	private $source;

	/**
	 * The parsed Header, populated eagerly at construction time.
	 *
	 * @var Header
	 */
	private Header $header;

	/**
	 * The parsed Footer, populated eagerly at construction time.
	 *
	 * @var Footer
	 */
	private Footer $footer;

	/**
	 * The parsed signature block, populated eagerly when the header's signed flag is set; null otherwise.
	 *
	 * @var ArchiveSignature|null
	 */
	private ?ArchiveSignature $signature = null;

	/**
	 * The parsed ArchiveManifest, populated lazily on first access via manifest().
	 *
	 * Null until the first manifest() call; cached thereafter.
	 *
	 * @var ArchiveManifest|null
	 */
	private ?ArchiveManifest $manifest = null;

	/**
	 * The parsed Provenance block, populated lazily on first access via provenance().
	 *
	 * Null until the first provenance() call; cached thereafter.
	 *
	 * @var Provenance|null
	 */
	private ?Provenance $provenance = null;

	/**
	 * Open an ArchiveReader around an existing archive stream.
	 *
	 * The stream must be readable and seekable. The constructor
	 * parses the Header at offset 0 and the Footer at the end of
	 * the stream; if either is missing, truncated, or malformed,
	 * an exception is thrown and the reader is not constructed.
	 *
	 * The stream's seek position after construction is unspecified.
	 *
	 * May propagate a RuntimeException from the internal Header/Footer
	 * readers when the stream is too short, when a seek fails, or when
	 * the bytes do not parse as a valid Header or Footer.
	 *
	 * @param resource $source A readable, seekable stream resource.
	 * @throws InvalidArgumentException If $source is not a valid stream resource or is not seekable.
	 */
	public function __construct( $source ) {
		if ( ! is_resource( $source ) ) {
			throw new InvalidArgumentException( 'ArchiveReader: $source must be a valid stream resource.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_stream_get_meta_data -- Inspecting an open stream resource; WP_Filesystem has no equivalent.
		$meta = stream_get_meta_data( $source );
		if ( empty( $meta['seekable'] ) ) {
			throw new InvalidArgumentException( 'ArchiveReader: $source stream must be seekable.' );
		}

		$this->source = $source;
		$this->header = $this->read_header();
		// A signed archive ends with a 100-byte signature block; read it first so
		// the footer is then located 64 bytes before it rather than at end of file.
		if ( $this->header->is_signed() ) {
			$this->signature = $this->read_signature();
		}
		$this->footer = $this->read_footer();
	}

	/**
	 * Return the parsed archive Header.
	 *
	 * @return Header The Header parsed from offset 0 at construction time.
	 */
	public function header(): Header {
		return $this->header;
	}

	/**
	 * Return the parsed archive Footer.
	 *
	 * @return Footer The Footer parsed from the end of the stream at construction time.
	 */
	public function footer(): Footer {
		return $this->footer;
	}

	/**
	 * Return the byte offset where the manifest block begins.
	 *
	 * Convenience accessor; equivalent to footer()->manifest_offset().
	 *
	 * @return int A non-negative byte offset into the archive stream.
	 */
	public function manifest_offset(): int {
		return $this->footer->manifest_offset();
	}

	/**
	 * Return the declared length of the manifest block in bytes.
	 *
	 * Convenience accessor; equivalent to footer()->manifest_length().
	 *
	 * @return int A non-negative byte count.
	 */
	public function manifest_length(): int {
		return $this->footer->manifest_length();
	}

	/**
	 * Return the parsed ArchiveManifest, reading it from the stream on first access.
	 *
	 * The manifest is parsed lazily on the first call and cached;
	 * subsequent calls return the cached instance. The read uses the
	 * manifest_offset and manifest_length recorded in the Footer.
	 *
	 * Verification performed on first read:
	 *  - The declared manifest_offset plus manifest_length must fit
	 *    inside the stream (no reading past EOF).
	 *  - ArchiveManifest::from_bytes verifies the manifest's own
	 *    internal hash matches the manifest payload.
	 *  - The manifest's internal hash must equal the Footer's
	 *    manifest_hash. Defense in depth against tampering that
	 *    might modify only one of the two recorded copies.
	 *
	 * @return ArchiveManifest The parsed manifest.
	 * @throws RuntimeException If the manifest cannot be read, parsed, or fails hash verification.
	 */
	public function manifest(): ArchiveManifest {
		if ( null === $this->manifest ) {
			$this->manifest = $this->read_manifest();
		}
		return $this->manifest;
	}

	/**
	 * Return the archive's provenance block, reading it from offset Header::SIZE on first access.
	 *
	 * The provenance block records the source site's facts at export — the
	 * WordPress and PHP versions, the **source-site URL** (the search term for
	 * a cross-URL migration), the database charset and collation, the exporter,
	 * and the export time. It sits immediately after the header (offset
	 * {@see Header::SIZE}) and is self-describing: a 4-byte length prefix, a
	 * 32-byte payload hash, then the JSON payload.
	 *
	 * Read lazily and cached, mirroring {@see self::manifest()}. The declared
	 * payload length is capped at {@see Provenance::MAX_PAYLOAD_SIZE} and the
	 * block is bounds-checked against the manifest offset before reading;
	 * {@see Provenance::from_bytes()} then re-verifies the length and hash, so a
	 * corrupt or tampered block is refused rather than trusted.
	 *
	 * @return Provenance The parsed provenance block.
	 * @throws RuntimeException If the block cannot be read, is out of bounds, or fails verification.
	 */
	public function provenance(): Provenance {
		if ( null === $this->provenance ) {
			$this->provenance = $this->read_provenance();
		}
		return $this->provenance;
	}

	/**
	 * Return the parsed signature block, or null if the archive is unsigned.
	 *
	 * Populated eagerly at construction when the header's signed flag is set. The
	 * key id it carries is a hint — it is not itself covered by the signature — so
	 * the authoritative check is {@see self::verify_signature()} against a public
	 * key the operator trusts.
	 *
	 * @return ArchiveSignature|null The signature block, or null when unsigned.
	 */
	public function signature(): ?ArchiveSignature {
		return $this->signature;
	}

	/**
	 * Verify the archive's Ed25519 signature against a trusted public key.
	 *
	 * Recomputes the SHA-256 over every byte through the footer (streamed, so
	 * memory stays bounded) and checks the stored signature against it and the
	 * supplied public key. Returns false for an unsigned archive or a signature
	 * that does not verify (wrong key, or any byte altered), so a caller can treat
	 * false as "untrusted or tampered". The stored key id is deliberately not
	 * consulted: the operator's trusted key is the authority, not a field that
	 * sits outside the signed range.
	 *
	 * @param string               $public_key The trusted Ed25519 public key (SigningKeypair::PUBLIC_KEY_SIZE bytes).
	 * @param Ed25519Verifier|null $verifier   Optional verifier; a fresh Ed25519Verifier is used when null.
	 * @return bool True if the archive is signed and the signature verifies against $public_key.
	 * @throws \Pontifex\Archive\Crypto\SignatureException If ext-sodium is unavailable or the public key is the wrong length.
	 * @throws RuntimeException If the stream cannot be re-read to recompute the digest.
	 */
	public function verify_signature( string $public_key, ?Ed25519Verifier $verifier = null ): bool {
		if ( null === $this->signature ) {
			return false;
		}
		$verifier = $verifier ?? new Ed25519Verifier();
		return $verifier->verify( $this->signed_digest(), $this->signature->signature(), $public_key );
	}

	/**
	 * Read and parse the Header from offset 0 of the source stream.
	 *
	 * @return Header The parsed Header.
	 * @throws RuntimeException If the stream is too short or the bytes do not parse as a valid Header.
	 */
	private function read_header(): Header {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek -- Reading from an open stream resource; WP_Filesystem has no equivalent.
		if ( -1 === fseek( $this->source, 0 ) ) {
			throw new RuntimeException( 'ArchiveReader: could not seek to offset 0 to read the header.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Reading from an open stream resource; WP_Filesystem has no equivalent.
		$bytes = fread( $this->source, Header::SIZE );
		if ( false === $bytes || strlen( $bytes ) !== Header::SIZE ) {
			throw new RuntimeException(
				sprintf(
					'ArchiveReader: could not read %d header bytes; stream may be truncated.',
					(int) Header::SIZE
				)
			);
		}

		try {
			return Header::from_bytes( $bytes );
		} catch ( InvalidArgumentException $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $e is the underlying parse exception, passed as the previous-exception argument for diagnostic chaining; not HTML output.
			throw new RuntimeException( 'ArchiveReader: archive header is malformed or not a Pontifex archive.', 0, $e );
		}
	}

	/**
	 * Read and parse the Footer from the end of the source stream.
	 *
	 * @return Footer The parsed Footer.
	 * @throws RuntimeException If the stream is too short, the seek fails, or the bytes do not parse as a valid Footer.
	 */
	private function read_footer(): Footer {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek -- Reading from an open stream resource; WP_Filesystem has no equivalent.
		if ( -1 === fseek( $this->source, 0, SEEK_END ) ) {
			throw new RuntimeException( 'ArchiveReader: could not seek to end of stream to read the footer.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_ftell -- Reading from an open stream resource; WP_Filesystem has no equivalent.
		$end = ftell( $this->source );
		if ( false === $end ) {
			throw new RuntimeException( 'ArchiveReader: could not determine stream length.' );
		}
		$tail = $this->signature_tail_size();
		if ( $end < Header::SIZE + Footer::SIZE + $tail ) {
			throw new RuntimeException(
				sprintf(
					'ArchiveReader: stream length %d is shorter than the minimum header (%d) + footer (%d) + signature (%d) size.',
					(int) $end,
					(int) Header::SIZE,
					(int) Footer::SIZE,
					(int) $tail
				)
			);
		}

		// The footer sits at the very end of an unsigned archive, or immediately
		// before the trailing signature block of a signed one.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek -- Reading from an open stream resource; WP_Filesystem has no equivalent.
		if ( -1 === fseek( $this->source, $end - $tail - Footer::SIZE ) ) {
			throw new RuntimeException( 'ArchiveReader: could not seek to footer position.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Reading from an open stream resource; WP_Filesystem has no equivalent.
		$bytes = fread( $this->source, Footer::SIZE );
		if ( false === $bytes || strlen( $bytes ) !== Footer::SIZE ) {
			throw new RuntimeException(
				sprintf(
					'ArchiveReader: could not read %d footer bytes; stream may be truncated.',
					(int) Footer::SIZE
				)
			);
		}

		try {
			return Footer::from_bytes( $bytes );
		} catch ( InvalidArgumentException $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $e is the underlying parse exception, passed as the previous-exception argument for diagnostic chaining; not HTML output.
			throw new RuntimeException( 'ArchiveReader: archive footer is malformed.', 0, $e );
		}
	}

	/**
	 * Read and parse the manifest block from the position recorded in the Footer.
	 *
	 * Bounds-checks the offset and length against the stream's total
	 * size before reading so a malformed footer cannot trick us into
	 * reading past EOF or allocating a huge buffer. Then defers to
	 * ArchiveManifest::from_bytes for the parse, and finally
	 * cross-checks that the manifest's internal hash equals the
	 * Footer's manifest_hash.
	 *
	 * @return ArchiveManifest The parsed manifest.
	 * @throws RuntimeException If the manifest cannot be read, parses to bytes that fail their internal hash check, or whose hash disagrees with the Footer.
	 */
	private function read_manifest(): ArchiveManifest {
		$offset = $this->footer->manifest_offset();
		$length = $this->footer->manifest_length();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek -- Reading from an open stream resource; WP_Filesystem has no equivalent.
		if ( -1 === fseek( $this->source, 0, SEEK_END ) ) {
			throw new RuntimeException( 'ArchiveReader: could not seek to end of stream to bounds-check the manifest.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_ftell -- Reading from an open stream resource; WP_Filesystem has no equivalent.
		$stream_length = ftell( $this->source );
		if ( false === $stream_length ) {
			throw new RuntimeException( 'ArchiveReader: could not determine stream length for manifest bounds check.' );
		}

		// The manifest must sit entirely between the Header and the Footer (which is
		// itself before the trailing signature block, when the archive is signed).
		// Anything else means the Footer's recorded offset/length is inconsistent with the stream.
		$footer_start = $stream_length - $this->signature_tail_size() - Footer::SIZE;
		if ( $offset < Header::SIZE || $offset + $length > $footer_start ) {
			throw new RuntimeException(
				sprintf(
					'ArchiveReader: manifest at offset %d length %d does not fit between header (%d) and footer (start at %d).',
					(int) $offset,
					(int) $length,
					(int) Header::SIZE,
					(int) $footer_start
				)
			);
		}

		// Cap the declared length before allocating: the manifest block is a
		// length-prefix plus a payload (itself capped at MAX_PAYLOAD_SIZE) plus a
		// 32-byte hash. Without this, an archive padded to its file size could
		// force a single multi-gigabyte fread. The +1024 covers prefix and hash.
		if ( $length > ArchiveManifest::MAX_PAYLOAD_SIZE + 1024 ) {
			throw new RuntimeException(
				sprintf(
					'ArchiveReader: declared manifest length %d exceeds the maximum %d; refusing to allocate.',
					(int) $length,
					(int) ArchiveManifest::MAX_PAYLOAD_SIZE
				)
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek -- Reading from an open stream resource; WP_Filesystem has no equivalent.
		if ( -1 === fseek( $this->source, $offset ) ) {
			throw new RuntimeException( 'ArchiveReader: could not seek to manifest offset.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Reading from an open stream resource; WP_Filesystem has no equivalent.
		$bytes = fread( $this->source, $length );
		if ( false === $bytes || strlen( $bytes ) !== $length ) {
			throw new RuntimeException(
				sprintf( 'ArchiveReader: could not read %d manifest bytes; stream may be truncated.', (int) $length )
			);
		}

		try {
			$manifest = ArchiveManifest::from_bytes( $bytes );
		} catch ( InvalidArgumentException $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $e is the underlying parse exception, passed as the previous-exception argument for diagnostic chaining; not HTML output.
			throw new RuntimeException( 'ArchiveReader: archive manifest is malformed or its internal hash check failed.', 0, $e );
		}

		// Cross-check: the manifest payload's hash (embedded inside the manifest block) must equal the Footer's recorded hash.
		// ArchiveManifest::from_bytes already verified the payload-versus-internal-hash match;
		// here we verify the internal hash equals what the Footer says it should be.
		$manifest_internal_hash = substr( $bytes, ArchiveManifest::LENGTH_PREFIX_SIZE, Sha256::DIGEST_SIZE );
		if ( ! hash_equals( $this->footer->manifest_hash(), $manifest_internal_hash ) ) {
			throw new RuntimeException( 'ArchiveReader: manifest hash recorded in footer does not match the hash embedded in the manifest block.' );
		}

		return $manifest;
	}

	/**
	 * Read and parse the provenance block from offset Header::SIZE.
	 *
	 * Reads the 4-byte length prefix, caps it at Provenance::MAX_PAYLOAD_SIZE,
	 * checks the block fits between the header and the manifest, then reads the
	 * whole block (length prefix + hash + payload) and defers to
	 * Provenance::from_bytes, which re-verifies the length and payload hash.
	 *
	 * @return Provenance The parsed provenance block.
	 * @throws RuntimeException If the block cannot be read, is out of bounds, or fails verification.
	 */
	private function read_provenance(): Provenance {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek -- Reading from an open stream resource; WP_Filesystem has no equivalent.
		if ( -1 === fseek( $this->source, Header::SIZE ) ) {
			throw new RuntimeException( 'ArchiveReader: could not seek to the provenance block.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Reading from an open stream resource; WP_Filesystem has no equivalent.
		$length_bytes = fread( $this->source, Provenance::LENGTH_PREFIX_SIZE );
		if ( false === $length_bytes || strlen( $length_bytes ) !== Provenance::LENGTH_PREFIX_SIZE ) {
			throw new RuntimeException( 'ArchiveReader: could not read the provenance length prefix; stream may be truncated.' );
		}

		$length = ByteOrder::unpack_uint32( $length_bytes );
		if ( $length > Provenance::MAX_PAYLOAD_SIZE ) {
			throw new RuntimeException(
				sprintf(
					'ArchiveReader: provenance payload length %d exceeds the maximum of %d bytes.',
					(int) $length,
					(int) Provenance::MAX_PAYLOAD_SIZE
				)
			);
		}

		$total = Provenance::HEADER_SIZE + $length;

		// The provenance block sits between the header and the manifest; it must not overrun the manifest offset.
		if ( Header::SIZE + $total > $this->footer->manifest_offset() ) {
			throw new RuntimeException(
				sprintf(
					'ArchiveReader: provenance block of %d bytes overruns the manifest offset %d.',
					(int) $total,
					(int) $this->footer->manifest_offset()
				)
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek -- Reading from an open stream resource; WP_Filesystem has no equivalent.
		if ( -1 === fseek( $this->source, Header::SIZE ) ) {
			throw new RuntimeException( 'ArchiveReader: could not seek to the provenance block to read it.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Reading from an open stream resource; WP_Filesystem has no equivalent.
		$bytes = fread( $this->source, $total );
		if ( false === $bytes || strlen( $bytes ) !== $total ) {
			throw new RuntimeException(
				sprintf( 'ArchiveReader: could not read %d provenance bytes; stream may be truncated.', (int) $total )
			);
		}

		try {
			return Provenance::from_bytes( $bytes );
		} catch ( InvalidArgumentException $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $e is the underlying parse exception, passed as the previous-exception argument for diagnostic chaining; not HTML output.
			throw new RuntimeException( 'ArchiveReader: archive provenance block is malformed.', 0, $e );
		}
	}

	/**
	 * Read and parse the 100-byte signature block from the end of the source stream.
	 *
	 * Called only when the header's signed flag is set. The block is the last
	 * ArchiveSignature::SIZE bytes of the stream.
	 *
	 * @return ArchiveSignature The parsed signature block.
	 * @throws RuntimeException If the stream is too short, a seek or read fails, or the block is malformed.
	 */
	private function read_signature(): ArchiveSignature {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek -- Reading from an open stream resource; WP_Filesystem has no equivalent.
		if ( -1 === fseek( $this->source, 0, SEEK_END ) ) {
			throw new RuntimeException( 'ArchiveReader: could not seek to end of stream to read the signature block.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_ftell -- Reading from an open stream resource; WP_Filesystem has no equivalent.
		$end = ftell( $this->source );
		if ( false === $end ) {
			throw new RuntimeException( 'ArchiveReader: could not determine stream length to read the signature block.' );
		}
		if ( $end < Header::SIZE + Footer::SIZE + ArchiveSignature::SIZE ) {
			throw new RuntimeException(
				sprintf(
					'ArchiveReader: stream length %d is shorter than the minimum for a signed archive (header %d + footer %d + signature %d).',
					(int) $end,
					(int) Header::SIZE,
					(int) Footer::SIZE,
					(int) ArchiveSignature::SIZE
				)
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek -- Reading from an open stream resource; WP_Filesystem has no equivalent.
		if ( -1 === fseek( $this->source, $end - ArchiveSignature::SIZE ) ) {
			throw new RuntimeException( 'ArchiveReader: could not seek to the signature block position.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Reading from an open stream resource; WP_Filesystem has no equivalent.
		$bytes = fread( $this->source, ArchiveSignature::SIZE );
		if ( false === $bytes || strlen( $bytes ) !== ArchiveSignature::SIZE ) {
			throw new RuntimeException(
				sprintf( 'ArchiveReader: could not read %d signature bytes; stream may be truncated.', (int) ArchiveSignature::SIZE )
			);
		}

		try {
			return ArchiveSignature::from_bytes( $bytes );
		} catch ( InvalidArgumentException $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $e is the underlying parse exception, passed as the previous-exception argument for diagnostic chaining; not HTML output.
			throw new RuntimeException( 'ArchiveReader: archive signature block is malformed.', 0, $e );
		}
	}

	/**
	 * Return the byte length of the trailing signature block.
	 *
	 * ArchiveSignature::SIZE when the archive is signed, 0 otherwise. Used to place
	 * the footer and bound the manifest.
	 *
	 * @return int The trailing signature block size in bytes.
	 */
	private function signature_tail_size(): int {
		return null !== $this->signature ? ArchiveSignature::SIZE : 0;
	}

	/**
	 * Stream the signed byte range (offset 0 through the footer) and return its SHA-256.
	 *
	 * Reads in bounded chunks so memory stays within budget regardless of archive
	 * size. The signed range excludes the trailing signature block itself.
	 *
	 * @return string The raw 32-byte SHA-256 digest.
	 * @throws RuntimeException If a seek or read fails.
	 */
	private function signed_digest(): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek -- Reading from an open stream resource; WP_Filesystem has no equivalent.
		if ( -1 === fseek( $this->source, 0, SEEK_END ) ) {
			throw new RuntimeException( 'ArchiveReader: could not seek to end of stream to compute the signed digest.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_ftell -- Reading from an open stream resource; WP_Filesystem has no equivalent.
		$end = ftell( $this->source );
		if ( false === $end ) {
			throw new RuntimeException( 'ArchiveReader: could not determine stream length to compute the signed digest.' );
		}
		$signed_length = $end - $this->signature_tail_size();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek -- Reading from an open stream resource; WP_Filesystem has no equivalent.
		if ( -1 === fseek( $this->source, 0 ) ) {
			throw new RuntimeException( 'ArchiveReader: could not seek to offset 0 to compute the signed digest.' );
		}
		$context   = hash_init( 'sha256' );
		$remaining = $signed_length;
		while ( $remaining > 0 ) {
			$want = (int) min( self::SIGNED_DIGEST_CHUNK_SIZE, $remaining );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Reading from an open stream resource; WP_Filesystem has no equivalent.
			$chunk = fread( $this->source, $want );
			if ( false === $chunk || '' === $chunk ) {
				throw new RuntimeException( 'ArchiveReader: could not read the archive to compute the signed digest; stream may be truncated.' );
			}
			hash_update( $context, $chunk );
			$remaining -= strlen( $chunk );
		}

		return hash_final( $context, true );
	}
}
