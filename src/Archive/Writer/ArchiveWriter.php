<?php
/**
 * Pontifex archive writer — composes header, provenance, entries, manifest, and footer into a complete .wpmig file.
 *
 * @package Pontifex\Archive\Writer
 */

declare(strict_types=1);

namespace Pontifex\Archive\Writer;

use InvalidArgumentException;
use RuntimeException;
use Pontifex\Archive\Crypto\EncryptionContext;
use Pontifex\Archive\Crypto\SigningContext;
use Pontifex\Archive\Format\Provenance;

/**
 * Writes a complete Pontifex archive (.wpmig) to a destination stream, in one call.
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
 * Since v0.6.0 the write path itself lives in
 * {@see IncrementalArchiveWriter}, whose begin/append/finish phases can
 * span PHP requests (the resumable-export engine, ADR 0014). This class
 * is the one-shot composition of those phases — validate, begin, append
 * every supplied plan in order, finish — and keeps the public signature
 * every existing caller and test was written against. There is exactly
 * one write loop in the codebase; this is a thin driver over it.
 *
 * An archive is encrypted when an EncryptionContext is supplied and
 * signed when a SigningContext is supplied; either, both, or neither.
 * Signing needs a seekable, readable destination because the signature
 * is computed by re-reading the just-written bytes within the memory
 * budget.
 *
 * Threading and reuse: ArchiveWriter is stateless after construction;
 * write_archive() is safe to call any number of times (each call uses a
 * fresh IncrementalArchiveWriter internally). Sources already consumed
 * by a previous call are not safely reusable.
 */
final class ArchiveWriter {

	/**
	 * Writer responsible for emitting each entry record.
	 *
	 * @var EntryWriter
	 */
	private EntryWriter $entry_writer;

	/**
	 * Writer responsible for emitting the trailing 64-byte footer.
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
	 * Writes the header, provenance, every supplied entry in order, the
	 * manifest, and the footer. Returns the total byte count written. Each
	 * EntryPlan's source stream is read from its current seek position to EOF.
	 *
	 * @param Provenance             $provenance       Source-site context to embed in the provenance block.
	 * @param iterable<EntryPlan>    $entry_plans      Entries to write, in archive order; a plain array or a Countable ManifestStream. May be empty.
	 * @param resource               $destination      Writable stream resource; bytes are written from its current seek position and it is advanced by the returned byte count.
	 * @param callable|null          $on_entry_written Optional callback run once after each entry, as function(int $done, int $total): void; lets a caller drive a progress indicator while the archive layer stays UI-agnostic.
	 * @param EncryptionContext|null $encryption       Encryption inputs (cipher, key, salt); when supplied the header's encrypted flag is set, every entry is encrypted with a per-entry nonce, and the salt is written into the footer; null produces an unencrypted archive.
	 * @param SigningContext|null    $signing          Signing inputs (signer, secret key, key id); when supplied the header's signed flag is set and a 100-byte Ed25519 signature block over the SHA-256 of every byte through the footer is appended. Requires a seekable, readable destination. null produces an unsigned archive.
	 * @param callable|null          $on_bytes_read    Optional byte-progress callback forwarded to each entry's codec, called as `( int $bytes ): void` with each chunk's raw source byte count, so a caller can report progress within a large entry as well as between entries.
	 * @param callable|null          $on_file_changed  Optional callback run when a file entry's source yielded a different byte count than its header declared (the file changed between the caller's scan and the write), called as `( string $path, int $declared_size, int $actual_size ): void`. The entry is written with the actual captured size; this callback lets the caller warn the user.
	 * @return int Total bytes written to the destination during this call. A RuntimeException
	 *             propagates from the incremental writer if any block fails to serialise, any
	 *             write fails, a nonce cannot be generated, or signing fails.
	 * @throws InvalidArgumentException If $destination is not a stream resource, any element
	 *                                  of $entry_plans is not an EntryPlan, or signing was requested
	 *                                  but the destination is not seekable and readable.
	 */
	public function write_archive( Provenance $provenance, iterable $entry_plans, $destination, ?callable $on_entry_written = null, ?EncryptionContext $encryption = null, ?SigningContext $signing = null, ?callable $on_bytes_read = null, ?callable $on_file_changed = null ): int {
		// phpcs:enable Squiz.Commenting.FunctionComment.IncorrectTypeHint
		if ( ! is_resource( $destination ) ) {
			throw new InvalidArgumentException( 'ArchiveWriter: $destination must be a valid stream resource.' );
		}

		// The entry list may be a lazily-streamed ManifestStream as well as a plain
		// array, so each plan is validated inside the write loop (which pulls one at
		// a time) rather than in an up-front pass that would consume a stream. The
		// progress total comes from Countable, which both an array and a
		// ManifestStream satisfy in O(1).
		$total = is_countable( $entry_plans ) ? count( $entry_plans ) : 0;

		$writer = new IncrementalArchiveWriter( $this->entry_writer, $this->footer_writer );
		$writer->begin( $destination, $provenance, $encryption, $signing );

		$index = 0;
		foreach ( $entry_plans as $plan ) {
			if ( ! $plan instanceof EntryPlan ) {
				throw new InvalidArgumentException(
					sprintf( 'ArchiveWriter: $entry_plans[%d] must be an EntryPlan instance.', (int) $index )
				);
			}

			$writer->append_entry( $plan, $on_bytes_read, $on_file_changed );

			if ( null !== $on_entry_written ) {
				$on_entry_written( $index + 1, $total );
			}

			++$index;
		}

		return $writer->finish();
	}
}
