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
use Pontifex\Archive\Format\Footer;
use Pontifex\Archive\Format\Header;

/**
 * Opens a Pontifex archive and exposes its high-level structure.
 *
 * This is the entry point for reading archives. It is intentionally
 * small: it parses the fixed-size Header at offset 0 and the
 * fixed-size Footer at the end of the stream, then exposes both
 * plus convenience accessors for the manifest's location. Parsing
 * the manifest itself, iterating entries, and verifying hashes
 * arrive in later commits.
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
 *
 * Internal choices (implementation details; may change without
 * breaking the public API):
 *
 *  - Eager parsing of Header and Footer at construction time. Both
 *    are tiny (16 and 64 bytes respectively) and reading them up
 *    front lets the caller rely on the accessors never failing.
 *    Manifest parsing is NOT eager because the manifest can be
 *    megabytes; deferring it respects laziness.
 *  - The source stream's seek position is changed by this class.
 *    Callers should not assume the position is preserved after
 *    construction.
 *  - Stream ownership: the caller owns the stream. ArchiveReader
 *    does not close it on destruction.
 */
final class ArchiveReader {

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
		if ( $end < Header::SIZE + Footer::SIZE ) {
			throw new RuntimeException(
				sprintf(
					'ArchiveReader: stream length %d is shorter than the minimum header (%d) + footer (%d) size.',
					(int) $end,
					(int) Header::SIZE,
					(int) Footer::SIZE
				)
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek -- Reading from an open stream resource; WP_Filesystem has no equivalent.
		if ( -1 === fseek( $this->source, $end - Footer::SIZE ) ) {
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
}
