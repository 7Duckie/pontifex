<?php
/**
 * Unit tests for the EntryReader class.
 *
 * @package Pontifex\Tests\Unit\Archive\Reader
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Archive\Reader;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Pontifex\Archive\Codec\Codec;
use Pontifex\Archive\Codec\CodecException;
use Pontifex\Archive\Codec\CodecId;
use Pontifex\Archive\Codec\CodecRegistry;
use Pontifex\Archive\Codec\CodecUnavailableException;
use Pontifex\Archive\Codec\GzipCodec;
use Pontifex\Archive\Codec\RawCodec;
use Pontifex\Archive\Crypto\Cipher;
use Pontifex\Archive\Crypto\OpensslAesGcmCipher;
use Pontifex\Archive\Format\ByteOrder;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Format\ManifestEntry;
use Pontifex\Archive\Integrity\Sha256;
use Pontifex\Archive\Reader\EntryReader;
use Pontifex\Archive\Reader\EntryReadResult;
use Pontifex\Archive\Writer\EntryWriter;
use Pontifex\Exception\ArchiveNotTrustworthy;
use Pontifex\Exception\BuildCannotComply;
use Pontifex\Exception\HostCannotComply;

/**
 * Tests for {@see EntryReader}.
 *
 * Strategy: write each test fixture using a real EntryWriter so the
 * on-disk bytes are known to be format-correct, build a matching
 * ManifestEntry pointing at those bytes, then call EntryReader and
 * verify the round-trip. Rejection tests corrupt the bytes after
 * the writer produces them.
 */
final class EntryReaderTest extends TestCase {

	/**
	 * Build a fresh EntryReader with the default codec registry.
	 *
	 * @return EntryReader A reader ready to call read_entry on.
	 */
	private static function make_reader(): EntryReader {
		return new EntryReader( CodecRegistry::with_defaults() );
	}

	/**
	 * Build a fresh EntryWriter with the default codec registry.
	 *
	 * @return EntryWriter A writer for setting up test fixtures.
	 */
	private static function make_writer(): EntryWriter {
		return new EntryWriter( CodecRegistry::with_defaults() );
	}

	/**
	 * Open a fresh php://memory stream.
	 *
	 * @param string $contents Optional initial contents to write and rewind.
	 * @return resource A readable, seekable in-memory stream.
	 * @throws RuntimeException If php://memory cannot be opened.
	 */
	private static function memory_stream( string $contents = '' ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://memory is an in-process buffer, not a file.
		$stream = fopen( 'php://memory', 'r+b' );
		if ( false === $stream ) {
			throw new RuntimeException( 'Could not open php://memory.' );
		}
		if ( '' !== $contents ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Operating on a test stream resource, not a filesystem path.
			fwrite( $stream, $contents );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource, not a filesystem path.
			rewind( $stream );
		}
		return $stream;
	}

	/**
	 * Return the all-zero nonce used for unencrypted v0.1.0 entries.
	 *
	 * @return string A NONCE_SIZE-byte string of zero bytes.
	 */
	private static function zero_nonce(): string {
		return str_repeat( "\0", EntryWriter::NONCE_SIZE );
	}

	/**
	 * Read all bytes from a stream, rewinding first.
	 *
	 * @param resource $stream The stream to read.
	 * @return string The full contents.
	 * @throws RuntimeException If the stream cannot be read.
	 */
	private static function read_all( $stream ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource, not a filesystem path.
		rewind( $stream );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_stream_get_contents -- Operating on a test stream resource, not a filesystem path.
		$contents = stream_get_contents( $stream );
		if ( false === $contents ) {
			throw new RuntimeException( 'Could not read test stream.' );
		}
		return $contents;
	}

	/**
	 * Locate the first payload byte inside a written entry record.
	 *
	 * A record is header_length(4B) || header_JSON || codec_id(2B) || nonce(12B)
	 * || payload || hash(32B). Tests that must corrupt the PAYLOAD (never the
	 * header) need this computed from the record's own declared header length,
	 * not guessed at — a guess such as "the record's midpoint" lands inside the
	 * JSON header on a small fixture and so is refused for the wrong reason
	 * ("entry header is malformed") instead of the hash reason the test claims
	 * to cover.
	 *
	 * @param string $record_bytes The full on-disk entry record.
	 * @return int Byte offset of the first payload byte within $record_bytes.
	 */
	private static function payload_offset( string $record_bytes ): int {
		$header_length = ByteOrder::unpack_uint32( substr( $record_bytes, 0, EntryHeader::LENGTH_PREFIX_SIZE ) );
		return EntryHeader::LENGTH_PREFIX_SIZE + $header_length + ByteOrder::UINT16_SIZE + EntryWriter::NONCE_SIZE;
	}

	/**
	 * Flip one byte to a value guaranteed to differ from what it already holds.
	 *
	 * @param string $byte The single byte to flip.
	 * @return string A different single byte.
	 */
	private static function flip_byte( string $byte ): string {
		return "\x00" === $byte ? "\xFF" : "\x00";
	}

	/**
	 * Write a file entry with EntryWriter and return [stream, ManifestEntry] for reading back.
	 *
	 * @param string $path     Relative path on the entry.
	 * @param string $contents The file contents to write as the payload.
	 * @param int    $codec_id Codec id to use (RawCodec::ID or GzipCodec::ID).
	 * @return array{0: resource, 1: ManifestEntry} The archive stream and matching manifest entry.
	 */
	private static function write_file_entry_to_fixture( string $path, string $contents, int $codec_id ): array {
		$dest   = self::memory_stream();
		$source = self::memory_stream( $contents );
		$header = EntryHeader::for_file( $path, strlen( $contents ), 0644, 1690000000, 'application/octet-stream', 0 );
		$result = self::make_writer()->write_entry( $header, $codec_id, self::zero_nonce(), $source, $dest );

		$manifest_entry = ManifestEntry::for_file( 0, 0, $result->total_entry_length(), $path, $codec_id, $result->entry_hash() );

		return array( $dest, $manifest_entry );
	}

	/**
	 * A file entry written with the raw codec must round-trip through EntryReader.
	 *
	 * @return void
	 */
	public function test_round_trip_file_raw_codec(): void {
		$contents = 'hello world from the round-trip test';
		$fixture  = self::write_file_entry_to_fixture( 'test.txt', $contents, RawCodec::ID );

		$result = self::make_reader()->read_entry( $fixture[0], $fixture[1] );

		$this->assertInstanceOf( EntryReadResult::class, $result );
		$this->assertSame( EntryHeader::KIND_FILE, $result->header()->kind() );
		$this->assertSame( 'test.txt', $result->header()->path() );
		// A plain file entry's payload arrives as a stream (ADR 0010).
		$this->assertTrue( $result->is_streamed() );
		$this->assertSame( strlen( $contents ), $result->decoded_size() );
		$this->assertSame( $contents, stream_get_contents( $result->payload_stream() ) );
	}

	/**
	 * A file entry written with the gzip codec must round-trip through EntryReader.
	 *
	 * @return void
	 */
	public function test_round_trip_file_gzip_codec(): void {
		// Repetitive content so gzip actually compresses noticeably; not strictly required for correctness.
		$contents = str_repeat( 'compress me ', 100 );
		$fixture  = self::write_file_entry_to_fixture( 'compressible.txt', $contents, GzipCodec::ID );

		$result = self::make_reader()->read_entry( $fixture[0], $fixture[1] );

		$this->assertTrue( $result->is_streamed() );
		$this->assertSame( $contents, stream_get_contents( $result->payload_stream() ) );
		$this->assertSame( strlen( $contents ), $result->decoded_size(), 'decoded_size() must report the decompressed byte count.' );
		$this->assertSame( 'compressible.txt', $result->header()->path() );
	}

	/**
	 * A db_chunk entry must round-trip through EntryReader.
	 *
	 * @return void
	 */
	public function test_round_trip_db_chunk(): void {
		$sql_bytes = "CREATE TABLE `wp_options` (id INT);\nINSERT INTO `wp_options` VALUES (1);\n";
		$dest      = self::memory_stream();
		$source    = self::memory_stream( $sql_bytes );
		$header    = EntryHeader::for_db_chunk( 0, 'wp_options', 2, strlen( $sql_bytes ), 0 );
		$result    = self::make_writer()->write_entry( $header, RawCodec::ID, self::zero_nonce(), $source, $dest );

		$manifest_entry = ManifestEntry::for_db_chunk( 0, 0, $result->total_entry_length(), 0, RawCodec::ID, $result->entry_hash() );

		$read_result = self::make_reader()->read_entry( $dest, $manifest_entry );

		$this->assertSame( EntryHeader::KIND_DB_CHUNK, $read_result->header()->kind() );
		$this->assertSame( 'wp_options', $read_result->header()->table_name() );
		$this->assertSame( $sql_bytes, $read_result->payload() );
	}

	/**
	 * A directory entry must round-trip with an empty payload.
	 *
	 * @return void
	 */
	public function test_round_trip_directory(): void {
		$dest   = self::memory_stream();
		$source = self::memory_stream();
		$header = EntryHeader::for_directory( 'wp-content/uploads', 0755, 0 );
		$result = self::make_writer()->write_entry( $header, RawCodec::ID, self::zero_nonce(), $source, $dest );

		$manifest_entry = ManifestEntry::for_directory( 0, 0, $result->total_entry_length(), 'wp-content/uploads', RawCodec::ID, $result->entry_hash() );

		$read_result = self::make_reader()->read_entry( $dest, $manifest_entry );

		$this->assertSame( EntryHeader::KIND_DIRECTORY, $read_result->header()->kind() );
		$this->assertSame( 'wp-content/uploads', $read_result->header()->path() );
		$this->assertSame( '', $read_result->payload() );
	}

	/**
	 * A symlink entry must round-trip and preserve its target.
	 *
	 * @return void
	 */
	public function test_round_trip_symlink(): void {
		$dest   = self::memory_stream();
		$source = self::memory_stream();
		$header = EntryHeader::for_symlink( 'wp-content/cache', '/tmp/wp-cache', 0 );
		$result = self::make_writer()->write_entry( $header, RawCodec::ID, self::zero_nonce(), $source, $dest );

		$manifest_entry = ManifestEntry::for_symlink( 0, 0, $result->total_entry_length(), 'wp-content/cache', RawCodec::ID, $result->entry_hash() );

		$read_result = self::make_reader()->read_entry( $dest, $manifest_entry );

		$this->assertSame( EntryHeader::KIND_SYMLINK, $read_result->header()->kind() );
		$this->assertSame( '/tmp/wp-cache', $read_result->header()->target() );
	}

	/**
	 * An entry whose manifest kind disagrees with its record kind is refused.
	 *
	 * The manifest and the record each carry the kind, and different parts of a
	 * restore read different copies: the whole-archive symlink confinement
	 * preflight (ADR 0021) picks which entries to judge from the MANIFEST, while
	 * FileWriter decides what to create from the RECORD. While nothing compared
	 * them, an archive could describe a symlink record as a file in the manifest
	 * alone — the record and its SHA-256 untouched, so every integrity check
	 * still passed — and the link was never shown to the preflight, was routed
	 * as a file, and was created as a symlink regardless. Driving the real
	 * restore proved it: the escaping link landed on disk, the restore reported
	 * success, and verify() called the archive sound. Two bytes of manifest
	 * reopened the guarantee v0.9.5 was cut to make.
	 *
	 * The record here is a genuine symlink written by the real writer, with the
	 * real hash — only the manifest lies. That is the whole point: no integrity
	 * check can catch this, so an explicit comparison has to.
	 *
	 * @return void
	 */
	public function test_a_manifest_kind_that_contradicts_the_record_is_refused(): void {
		$dest   = self::memory_stream();
		$source = self::memory_stream();
		$header = EntryHeader::for_symlink( 'wp-content/uploads/leak', '../../../wp-config.php', 0 );
		$result = self::make_writer()->write_entry( $header, RawCodec::ID, self::zero_nonce(), $source, $dest );

		// The manifest calls the very same record a file. Offset, length,
		// codec_id and entry hash are all the writer's own, so nothing else
		// about this archive is inconsistent.
		$lying_entry = ManifestEntry::for_file(
			0,
			0,
			$result->total_entry_length(),
			'wp-content/uploads/leak',
			RawCodec::ID,
			$result->entry_hash()
		);

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Entry kind mismatch' );

		self::make_reader()->read_entry( $dest, $lying_entry );
	}

	/**
	 * The same contradiction is refused on the verify path, which never decodes.
	 *
	 * Verifying is what an operator runs to decide whether to trust an archive.
	 * If it passed a forged archive that restore would then act on, the check
	 * would be reporting soundness it had not established. Pinned to the
	 * specific {@see ArchiveNotTrustworthy} type (not merely its RuntimeException
	 * parent): {@see \Pontifex\Archive\Reader\EntryReader::assert_kind_agrees()}
	 * used to throw a bare RuntimeException, the only structural check in the
	 * reader outside the project's exception taxonomy, so nothing could branch
	 * on it specifically.
	 *
	 * @return void
	 */
	public function test_verify_also_refuses_a_manifest_kind_that_contradicts_the_record(): void {
		$dest   = self::memory_stream();
		$source = self::memory_stream();
		$header = EntryHeader::for_symlink( 'wp-content/uploads/leak', '../../../wp-config.php', 0 );
		$result = self::make_writer()->write_entry( $header, RawCodec::ID, self::zero_nonce(), $source, $dest );

		$lying_entry = ManifestEntry::for_file(
			0,
			0,
			$result->total_entry_length(),
			'wp-content/uploads/leak',
			RawCodec::ID,
			$result->entry_hash()
		);

		$this->expectException( ArchiveNotTrustworthy::class );
		$this->expectExceptionMessage( 'Entry kind mismatch' );

		self::make_reader()->verify_entry( $dest, $lying_entry );
	}

	/**
	 * Passes a sound entry and reports every record byte to the callback.
	 *
	 * @return void
	 */
	public function test_verify_entry_passes_a_sound_entry_and_reports_bytes(): void {
		$contents = str_repeat( 'verify me ', 200 );
		$fixture  = self::write_file_entry_to_fixture( 'note.txt', $contents, RawCodec::ID );
		$reported = 0;

		self::make_reader()->verify_entry(
			$fixture[0],
			$fixture[1],
			static function ( int $bytes ) use ( &$reported ): void {
				$reported += $bytes;
			}
		);

		$this->assertSame( $fixture[1]->length(), $reported, 'verify_entry reports every byte of the record.' );
	}

	/**
	 * Rejects an entry whose payload no longer matches its own trailing hash.
	 *
	 * This is the first of verify_entry()'s two independent hash checks: the
	 * record's trailing 32-byte digest against the bytes actually read back —
	 * the on-disk corruption defence. The manifest's recorded entry_hash is
	 * left exactly as the writer produced it, so only the first comparison can
	 * be the one that refuses; {@see
	 * self::test_verify_entry_rejects_when_computed_hash_disagrees_with_manifest_entry_hash()}
	 * pins the second, independent comparison. The tampered byte is computed
	 * from the record's own header length so it is positively inside the
	 * payload, not guessed at the record's midpoint (which on this small
	 * fixture would land inside the JSON header and be refused for the wrong
	 * reason).
	 *
	 * @return void
	 */
	public function test_verify_entry_rejects_a_payload_tampered_against_its_own_trailing_hash(): void {
		$fixture = self::write_file_entry_to_fixture( 'note.txt', 'integrity matters', RawCodec::ID );
		$bytes   = self::read_all( $fixture[0] );
		$offset  = self::payload_offset( $bytes );
		$corrupt = substr_replace( $bytes, self::flip_byte( $bytes[ $offset ] ), $offset, 1 );
		$stream  = self::memory_stream( $corrupt );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Entry hash does not match the bytes on disk' );

		self::make_reader()->verify_entry( $stream, $fixture[1] );
	}

	/**
	 * Rejects an entry whose computed hash agrees with its own trailing hash
	 * but disagrees with the manifest's recorded entry_hash.
	 *
	 * The second of verify_entry()'s two independent hash checks — defence in
	 * depth against coordinated tampering where both the payload and its
	 * trailing hash were rewritten consistently but the manifest was not
	 * updated to match. The on-disk record here is completely untouched (the
	 * writer's own bytes, unmodified); only the manifest's copy of the hash is
	 * wrong, so the first comparison must pass before the second is ever
	 * reached and this test can tell the two apart.
	 *
	 * @return void
	 */
	public function test_verify_entry_rejects_when_computed_hash_disagrees_with_manifest_entry_hash(): void {
		$fixture             = self::write_file_entry_to_fixture( 'note.txt', 'integrity matters', RawCodec::ID );
		$stream              = $fixture[0];
		$real_manifest_entry = $fixture[1];

		$real_hash    = $real_manifest_entry->entry_hash();
		$forged_entry = ManifestEntry::for_file(
			$real_manifest_entry->index(),
			$real_manifest_entry->offset(),
			$real_manifest_entry->length(),
			'note.txt',
			RawCodec::ID,
			self::flip_byte( $real_hash[0] ) . substr( $real_hash, 1 )
		);

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'On-disk entry hash does not match the manifest entry_hash' );

		self::make_reader()->verify_entry( $stream, $forged_entry );
	}

	/**
	 * The verify_entry method must reject an entry whose codec_id disagrees with the manifest entry.
	 *
	 * The verify-path counterpart of {@see self::test_read_entry_rejects_codec_id_mismatch()}.
	 * Before this job, verify_entry() never performed this cross-check at
	 * all — it only hashed the record's bytes, so a tampered codec id was
	 * invisible until read_entry() eventually tried to decode it.
	 *
	 * @return void
	 */
	public function test_verify_entry_rejects_codec_id_mismatch(): void {
		$fixture             = self::write_file_entry_to_fixture( 'a.txt', 'data', RawCodec::ID );
		$stream              = $fixture[0];
		$real_manifest_entry = $fixture[1];

		// Construct a manifest entry claiming GzipCodec for the same on-disk bytes (which used RawCodec).
		$tampered_entry = ManifestEntry::for_file(
			$real_manifest_entry->index(),
			$real_manifest_entry->offset(),
			$real_manifest_entry->length(),
			'a.txt',
			GzipCodec::ID,
			$real_manifest_entry->entry_hash()
		);

		$this->expectException( ArchiveNotTrustworthy::class );
		$this->expectExceptionMessage( 'codec_id mismatch' );

		self::make_reader()->verify_entry( $stream, $tampered_entry );
	}

	/**
	 * The verify_entry method must reject an entry that uses a codec id not in the registry.
	 *
	 * The verify-path counterpart of {@see self::test_read_entry_rejects_unknown_codec()}.
	 *
	 * @return void
	 */
	public function test_verify_entry_rejects_unknown_codec(): void {
		$empty_registry = new CodecRegistry();
		$reader         = new EntryReader( $empty_registry );

		$fixture        = self::write_file_entry_to_fixture( 'a.txt', 'data', RawCodec::ID );
		$stream         = $fixture[0];
		$manifest_entry = $fixture[1];

		$this->expectException( ArchiveNotTrustworthy::class );
		$this->expectExceptionMessage( 'is not registered' );

		$reader->verify_entry( $stream, $manifest_entry );
	}

	/**
	 * The verify_entry method must refuse a plain unencrypted file entry over
	 * the fixed decoded-byte ceiling — the headline regression this job closes.
	 *
	 * Before this fix, verify_entry()'s only budget parameter carried the
	 * MEMORY-derived budget, gated on `! streams_decoded_payload(...)` — the
	 * condition {@see \Pontifex\Archive\Reader\EntryReader::read_entry()} uses
	 * for THAT budget, not this one. `streams_decoded_payload()` is true for
	 * every plain unencrypted file entry, so the gate was never satisfied for
	 * the common case and this exact refusal could never fire: an oversized
	 * plain file was reported SOUND by verify() and only then refused by
	 * read_entry() once a restore actually tried to decode it.
	 * $max_decoded_bytes now carries the same meaning it carries in
	 * read_entry() — this build's compiled-in ceiling, applied to every entry
	 * regardless of shape — so this mirrors
	 * {@see self::test_read_entry_refuses_as_build_cannot_comply_when_over_the_fixed_decoded_byte_ceiling()}
	 * exactly, on the verify path.
	 *
	 * @return void
	 */
	public function test_verify_entry_refuses_a_plain_file_entry_over_the_fixed_decoded_byte_ceiling(): void {
		$fixture = self::write_file_entry_to_fixture( 'wp-content/uploads/2024/huge-video.mov', str_repeat( 'A', 100 ), RawCodec::ID );

		try {
			self::make_reader()->verify_entry( $fixture[0], $fixture[1], null, 10 );
			$this->fail( 'verify_entry() should have raised BuildCannotComply.' );
		} catch ( BuildCannotComply $error ) {
			$this->assertStringContainsString(
				'wp-content/uploads/2024/huge-video.mov',
				$error->getMessage(),
				'The refusal must name the entry an operator can act on.'
			);
			$this->assertStringContainsString( '100 decoded bytes', $error->getMessage() );
			$this->assertStringContainsString( '10-byte budget', $error->getMessage() );
		}
	}

	/**
	 * The verify_entry method must NOT refuse a plain file entry over the
	 * memory-derived budget alone — it streams on restore (ADR 0010), so no
	 * memory refusal applies to it, exactly as read_entry() treats it.
	 *
	 * The regression guard the test above needs: telling the two budgets apart
	 * must not turn into refusing a plain file under a small memory budget it
	 * was never meant to be judged against.
	 *
	 * @return void
	 */
	public function test_verify_entry_permits_a_plain_file_entry_over_the_memory_budget(): void {
		$fixture = self::write_file_entry_to_fixture( 'note.txt', str_repeat( 'A', 100 ), RawCodec::ID );

		// A generous decoded-byte ceiling (this build's own), but a tiny
		// memory budget the file's 100 declared bytes comfortably exceed.
		self::make_reader()->verify_entry( $fixture[0], $fixture[1], null, 1000, 10 );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Reads a sound entry and reports every record byte to the callback.
	 *
	 * @return void
	 */
	public function test_read_entry_reports_bytes(): void {
		$contents = str_repeat( 'restore me ', 200 );
		$fixture  = self::write_file_entry_to_fixture( 'note.txt', $contents, RawCodec::ID );
		$reported = 0;

		self::make_reader()->read_entry(
			$fixture[0],
			$fixture[1],
			EntryReader::DEFAULT_MAX_DECODED_BYTES,
			static function ( int $bytes ) use ( &$reported ): void {
				$reported += $bytes;
			}
		);

		$this->assertSame( $fixture[1]->length(), $reported, 'read_entry reports every byte of the record.' );
	}

	/**
	 * The read_entry method must reject a non-resource source.
	 *
	 * @return void
	 */
	public function test_read_entry_rejects_non_resource_source(): void {
		$manifest_entry = ManifestEntry::for_file( 0, 0, 100, 'a.txt', 0, str_repeat( "\0", Sha256::DIGEST_SIZE ) );

		$this->expectException( InvalidArgumentException::class );

		// @phpstan-ignore-next-line — intentionally passing wrong type to verify validation.
		self::make_reader()->read_entry( 'not a resource', $manifest_entry );
	}

	/**
	 * The read_entry method must reject an unseekable source stream.
	 *
	 * @return void
	 */
	public function test_read_entry_rejects_unseekable_source(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://output is an in-process pseudo-stream, not a file.
		$stream         = fopen( 'php://output', 'w' );
		$manifest_entry = ManifestEntry::for_file( 0, 0, 100, 'a.txt', 0, str_repeat( "\0", Sha256::DIGEST_SIZE ) );

		$this->expectException( InvalidArgumentException::class );

		try {
			self::make_reader()->read_entry( $stream, $manifest_entry );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Cleanup of test stream resource.
			fclose( $stream );
		}
	}

	/**
	 * The read_entry method must reject a stream too short to contain the declared entry record.
	 *
	 * @return void
	 */
	public function test_read_entry_rejects_truncated_stream(): void {
		$stream         = self::memory_stream( "\x00\x00\x00" );
		$manifest_entry = ManifestEntry::for_file( 0, 0, 1000, 'a.txt', 0, str_repeat( "\0", Sha256::DIGEST_SIZE ) );

		$this->expectException( RuntimeException::class );

		self::make_reader()->read_entry( $stream, $manifest_entry );
	}

	/**
	 * The read_entry method must reject an entry whose codec_id disagrees with the manifest entry.
	 *
	 * @return void
	 */
	public function test_read_entry_rejects_codec_id_mismatch(): void {
		$fixture             = self::write_file_entry_to_fixture( 'a.txt', 'data', RawCodec::ID );
		$stream              = $fixture[0];
		$real_manifest_entry = $fixture[1];

		// Construct a manifest entry claiming GzipCodec for the same on-disk bytes (which used RawCodec).
		$tampered_entry = ManifestEntry::for_file(
			$real_manifest_entry->index(),
			$real_manifest_entry->offset(),
			$real_manifest_entry->length(),
			'a.txt',
			GzipCodec::ID,
			$real_manifest_entry->entry_hash()
		);

		$this->expectException( RuntimeException::class );

		self::make_reader()->read_entry( $stream, $tampered_entry );
	}

	/**
	 * The read_entry method must reject a record whose payload no longer
	 * matches its own trailing hash.
	 *
	 * This is the first of read_entry()'s two independent hash checks: the
	 * record's trailing 32-byte digest against the bytes actually read back —
	 * the on-disk corruption defence. The manifest's recorded entry_hash is
	 * left exactly as the writer produced it, so only the first comparison can
	 * be the one that refuses; {@see
	 * self::test_read_entry_rejects_when_computed_hash_disagrees_with_manifest_entry_hash()}
	 * pins the second, independent comparison. The tampered byte is computed
	 * from the record's own header length so it is positively inside the
	 * payload — a guess such as "the record's midpoint" lands inside the JSON
	 * header on this small fixture and would be refused for the wrong reason
	 * ("entry header is malformed"), not the hash reason this test claims.
	 *
	 * @return void
	 */
	public function test_read_entry_rejects_a_payload_tampered_against_its_own_trailing_hash(): void {
		$fixture             = self::write_file_entry_to_fixture( 'a.txt', 'data', RawCodec::ID );
		$stream              = $fixture[0];
		$real_manifest_entry = $fixture[1];

		$bytes    = self::read_all( $stream );
		$offset   = self::payload_offset( $bytes );
		$tampered = substr_replace( $bytes, self::flip_byte( $bytes[ $offset ] ), $offset, 1 );

		$tampered_stream = self::memory_stream( $tampered );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Entry hash does not match the bytes on disk' );

		self::make_reader()->read_entry( $tampered_stream, $real_manifest_entry );
	}

	/**
	 * The read_entry method must reject an entry whose computed hash agrees
	 * with its own trailing hash but disagrees with the manifest's recorded
	 * entry_hash.
	 *
	 * The second of read_entry()'s two independent hash checks — defence in
	 * depth against coordinated tampering where both the payload and its
	 * trailing hash were rewritten consistently but the manifest was not
	 * updated to match. The on-disk record here is completely untouched (the
	 * writer's own bytes, unmodified); only the manifest's copy of the hash is
	 * wrong, so the first comparison must pass before the second is ever
	 * reached and this test can tell the two apart.
	 *
	 * @return void
	 */
	public function test_read_entry_rejects_when_computed_hash_disagrees_with_manifest_entry_hash(): void {
		$fixture             = self::write_file_entry_to_fixture( 'a.txt', 'data', RawCodec::ID );
		$stream              = $fixture[0];
		$real_manifest_entry = $fixture[1];

		$real_hash    = $real_manifest_entry->entry_hash();
		$forged_entry = ManifestEntry::for_file(
			$real_manifest_entry->index(),
			$real_manifest_entry->offset(),
			$real_manifest_entry->length(),
			'a.txt',
			RawCodec::ID,
			self::flip_byte( $real_hash[0] ) . substr( $real_hash, 1 )
		);

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'On-disk entry hash does not match the manifest entry_hash' );

		self::make_reader()->read_entry( $stream, $forged_entry );
	}

	/**
	 * The read_entry method must reject an entry that uses a codec id not in the registry.
	 *
	 * @return void
	 */
	public function test_read_entry_rejects_unknown_codec(): void {
		// Write a valid entry with codec 0 (raw), then construct an entry pointing at it with a fake codec id.
		// To trip the "not registered" check before the codec_id-mismatch check, we need both on-disk and manifest to agree on a fake id.
		// Easiest: build a registry without RawCodec, then read an archive that uses RawCodec.
		$empty_registry = new CodecRegistry();
		$reader         = new EntryReader( $empty_registry );

		$fixture        = self::write_file_entry_to_fixture( 'a.txt', 'data', RawCodec::ID );
		$stream         = $fixture[0];
		$manifest_entry = $fixture[1];

		$this->expectException( RuntimeException::class );

		$reader->read_entry( $stream, $manifest_entry );
	}

	/**
	 * Reading an entry must refuse to decode beyond the supplied byte cap.
	 *
	 * The cap is threaded through to the codec, so a payload larger than
	 * the cap surfaces as a RuntimeException rather than decoding in
	 * full. This is the per-entry half of the decompression-bomb guard.
	 *
	 * @return void
	 */
	public function test_read_entry_enforces_decoded_byte_cap(): void {
		$fixture = self::write_file_entry_to_fixture( 'big.txt', str_repeat( 'A', 1000 ), RawCodec::ID );

		$this->expectException( RuntimeException::class );

		self::make_reader()->read_entry( $fixture[0], $fixture[1], 100 );
	}

	/**
	 * Hand-compose an entry record whose header declares a size its payload does not have.
	 *
	 * A conforming writer always records the byte count it actually captured,
	 * so a size/content mismatch can only be forged by composing the record
	 * manually — which is exactly what an archive from a pre-correction writer
	 * that hit the scan-to-write race looks like. The record's hashes are
	 * valid; only the size claim lies.
	 *
	 * @param string $payload       The raw payload bytes the record actually holds.
	 * @param int    $declared_size The (untrue) size the header claims.
	 * @return array{0: resource, 1: ManifestEntry} The archive stream and matching manifest entry.
	 */
	private static function forge_lying_file_entry( string $payload, int $declared_size ): array {
		$header_bytes = EntryHeader::for_file( 'lying.txt', $declared_size, 0644, 1690000000, 'application/octet-stream', strlen( $payload ) )->to_bytes();
		$record       = $header_bytes . ByteOrder::pack_uint16( RawCodec::ID ) . self::zero_nonce() . $payload;
		$hash         = hash( 'sha256', $record, true );
		$record      .= $hash;

		$manifest_entry = ManifestEntry::for_file( 0, 0, strlen( $record ), 'lying.txt', RawCodec::ID, $hash );

		return array( self::memory_stream( $record ), $manifest_entry );
	}

	/**
	 * A file entry whose decoded size contradicts its declared size must be refused.
	 *
	 * The read-side half of the changed-during-backup defence: such an entry
	 * can only come from a writer that hit the scan-to-write race without
	 * correcting for it, so the archive does not hold the content it claims.
	 * Restoring it would silently write a wrong-sized file; the reader must
	 * fail closed instead.
	 *
	 * @return void
	 */
	public function test_read_entry_refuses_a_file_entry_whose_decoded_size_contradicts_its_header(): void {
		$fixture = self::forge_lying_file_entry( 'only forty bytes of content, not 5000...', 5000 );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'declares 5000 bytes' );

		self::make_reader()->read_entry( $fixture[0], $fixture[1] );
	}

	/**
	 * The size-mismatch refusal must also cover the buffered (encrypted) decode path.
	 *
	 * An encrypted file entry decodes to a string rather than a stream
	 * (ADR 0010), so the reconciliation runs on a different branch; forge the
	 * same lie under encryption and prove that branch refuses too.
	 *
	 * @return void
	 */
	public function test_read_entry_refuses_an_encrypted_file_entry_whose_decoded_size_contradicts_its_header(): void {
		$payload  = 'a short secret';
		$key      = str_repeat( 'k', Cipher::KEY_SIZE );
		$cipher   = new OpensslAesGcmCipher();
		$codec_id = CodecId::with_aes_gcm( RawCodec::ID );

		$header_bytes = EntryHeader::for_file( 'lying.enc', 9000, 0644, 1690000000, 'application/octet-stream', strlen( $payload ) )->to_bytes();
		$sealed       = $cipher->encrypt( $payload, self::zero_nonce(), $header_bytes, $key );
		$record       = $header_bytes . ByteOrder::pack_uint16( $codec_id ) . self::zero_nonce() . $sealed;
		$hash         = hash( 'sha256', $record, true );
		$record      .= $hash;

		$manifest_entry = ManifestEntry::for_file( 0, 0, strlen( $record ), 'lying.enc', $codec_id, $hash );
		$reader         = new EntryReader( CodecRegistry::with_defaults(), $cipher, $key );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'declares 9000 bytes' );

		$reader->read_entry( self::memory_stream( $record ), $manifest_entry );
	}

	/**
	 * A db_chunk whose byte_count drifted from its payload must still read.
	 *
	 * A db_chunk's byte_count is a sizing estimate, not a content claim; the
	 * size reconciliation applies to file entries only, so a drifting chunk
	 * must decode normally rather than being refused.
	 *
	 * @return void
	 */
	public function test_read_entry_accepts_a_db_chunk_whose_byte_count_drifted(): void {
		$sql    = "INSERT INTO `wp_options` VALUES (1);\n";
		$dest   = self::memory_stream();
		$source = self::memory_stream( $sql );
		// byte_count deliberately overstates the payload.
		$header = EntryHeader::for_db_chunk( 0, 'wp_options', 1, 5000, 0 );
		$result = self::make_writer()->write_entry( $header, RawCodec::ID, self::zero_nonce(), $source, $dest );

		$manifest_entry = ManifestEntry::for_db_chunk( 0, 0, $result->total_entry_length(), 0, RawCodec::ID, $result->entry_hash() );

		$read_result = self::make_reader()->read_entry( $dest, $manifest_entry );

		$this->assertSame( $sql, $read_result->payload() );
	}

	/**
	 * A Codec stub that always fails to decode with a caller-chosen exception class and message.
	 *
	 * Registered in place of a real codec so a fixture written successfully
	 * (with a working codec) can be read back through a registry that fails
	 * at decode time — the only way to reach the decode catch without a real
	 * missing extension. Parameterised by exception class so the same stub
	 * drives both branches EntryReader now distinguishes: a plain
	 * `CodecException` (a genuinely corrupt payload) and the narrower
	 * `CodecUnavailableException` (this host lacks the extension the codec
	 * needs) — the only two classes {@see ZstdCodec} itself ever throws.
	 *
	 * @param int    $id             The codec ID this stub reports, matching the ID the fixture was written with.
	 * @param string $message        The message the stub's decode() throws with.
	 * @param string $exception_class The exception class to throw — CodecException::class or CodecUnavailableException::class.
	 * @return Codec A codec that fails every decode() with $exception_class carrying $message.
	 */
	private static function failing_codec( int $id, string $message, string $exception_class = CodecException::class ): Codec {
		return new class( $id, $message, $exception_class ) implements Codec {
			/**
			 * The codec ID this stub reports.
			 *
			 * @var int
			 */
			private int $stub_id;

			/**
			 * The message decode() throws with.
			 *
			 * @var string
			 */
			private string $message;

			/**
			 * The exception class decode() throws.
			 *
			 * @var class-string<CodecException>
			 */
			private string $exception_class;

			/**
			 * Construct a stub codec reporting the given ID and decode failure.
			 *
			 * @param int    $id              The codec ID to report.
			 * @param string $message         The message decode() throws with.
			 * @param string $exception_class The exception class decode() throws (CodecException::class or CodecUnavailableException::class).
			 */
			public function __construct( int $id, string $message, string $exception_class ) {
				$this->stub_id         = $id;
				$this->message         = $message;
				$this->exception_class = $exception_class;
			}

			/**
			 * Return the stub's codec ID.
			 *
			 * @return int The configured stub ID.
			 */
			public function id(): int {
				return $this->stub_id;
			}

			/**
			 * No-op encode for stub purposes.
			 *
			 * @param resource      $input   A readable stream resource.
			 * @param resource      $output  A writable stream resource.
			 * @param callable|null $on_read Ignored by this stub.
			 * @return int Always zero.
			 */
			public function encode( $input, $output, ?callable $on_read = null ): int {
				return 0;
			}

			/**
			 * Always fail, as a missing extension or a genuinely corrupt payload would.
			 *
			 * Declared `never`, not `int`: this stub never returns, only
			 * throws. PHP allows `never` as a covariant override of any
			 * parent return type, so this still satisfies {@see Codec::decode()}'s
			 * `int` signature. Branches on the configured class with two
			 * literal `throw` statements, rather than `throw new $class(...)`,
			 * so both are ordinary, statically-visible throws.
			 *
			 * @param resource $input            A readable stream resource.
			 * @param resource $output           A writable stream resource.
			 * @param int|null $max_output_bytes Ignored by this stub.
			 * @throws CodecUnavailableException If this stub was configured with that class.
			 * @throws CodecException            Otherwise, carrying this stub's configured message.
			 */
			public function decode( $input, $output, ?int $max_output_bytes = null ): never {
				if ( CodecUnavailableException::class === $this->exception_class ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $this->message is this test double's own configured fixture message, not HTML output.
					throw new CodecUnavailableException( $this->message );
				}
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $this->message is this test double's own configured fixture message, not HTML output.
				throw new CodecException( $this->message );
			}
		};
	}

	/**
	 * A genuinely corrupt payload decoding a streamed file entry surfaces the
	 * underlying message and stays ArchiveNotTrustworthy.
	 *
	 * A plain file entry decodes stream-to-stream through
	 * {@see EntryReader::read_entry()}'s own catch of `CodecException` — the
	 * site the audit found discarding the one sentence that actually explains
	 * a decode failure behind a generic "Codec failed to decode entry
	 * payload." This pins that the underlying message now survives, AND —
	 * the regression guard the taxonomy split needs — that a genuinely
	 * corrupt payload (any `CodecException` that is not the narrower
	 * `CodecUnavailableException`) still raises `ArchiveNotTrustworthy`, not
	 * `HostCannotComply`: real corruption must never be reported as this
	 * host's fault.
	 *
	 * @return void
	 */
	public function test_read_entry_surfaces_the_underlying_codec_message_for_a_streamed_file(): void {
		$fixture = self::write_file_entry_to_fixture( 'big.txt', str_repeat( 'A', 1000 ), RawCodec::ID );

		$registry = new CodecRegistry();
		$registry->register( self::failing_codec( RawCodec::ID, 'zstd_uncompress_add() failed; input may be malformed or truncated.' ) );
		$reader = new EntryReader( $registry );

		try {
			$reader->read_entry( $fixture[0], $fixture[1] );
			$this->fail( 'read_entry() should have raised ArchiveNotTrustworthy.' );
		} catch ( ArchiveNotTrustworthy $error ) {
			$this->assertStringContainsString(
				'zstd_uncompress_add() failed; input may be malformed or truncated.',
				$error->getMessage(),
				'The underlying codec message must survive, not just live in $previous.'
			);
		}
	}

	/**
	 * A genuinely corrupt payload decoding a buffered db_chunk entry surfaces
	 * the underlying message and stays ArchiveNotTrustworthy.
	 *
	 * The buffered counterpart of the streamed-file test above:
	 * {@see EntryReader::decode_spool_to_string()} used to throw a bare
	 * RuntimeException carrying none of the CodecException's own message.
	 * This pins that it now surfaces that message too, and — the two sites
	 * agreeing — raises the same ArchiveNotTrustworthy type as the streamed
	 * path for a genuinely corrupt payload.
	 *
	 * @return void
	 */
	public function test_read_entry_surfaces_the_underlying_codec_message_for_a_buffered_db_chunk(): void {
		$sql_bytes = "CREATE TABLE `wp_options` (id INT);\n";
		$dest      = self::memory_stream();
		$source    = self::memory_stream( $sql_bytes );
		$header    = EntryHeader::for_db_chunk( 0, 'wp_options', 1, strlen( $sql_bytes ), 0 );
		$result    = self::make_writer()->write_entry( $header, RawCodec::ID, self::zero_nonce(), $source, $dest );

		$manifest_entry = ManifestEntry::for_db_chunk( 0, 0, $result->total_entry_length(), 0, RawCodec::ID, $result->entry_hash() );

		$registry = new CodecRegistry();
		$registry->register( self::failing_codec( RawCodec::ID, 'zstd_uncompress_add() failed; input may be malformed or truncated.' ) );
		$reader = new EntryReader( $registry );

		try {
			$reader->read_entry( $dest, $manifest_entry );
			$this->fail( 'read_entry() should have raised ArchiveNotTrustworthy.' );
		} catch ( ArchiveNotTrustworthy $error ) {
			$this->assertStringContainsString(
				'zstd_uncompress_add() failed; input may be malformed or truncated.',
				$error->getMessage(),
				'The underlying codec message must survive, not just live in $previous.'
			);
		}
	}

	/**
	 * A missing extension decoding a streamed file entry is HostCannotComply, not ArchiveNotTrustworthy.
	 *
	 * The case the audit called the sharpest, because it is the real
	 * migrate-to-a-new-server story: a backup made on a host with ext-zstd,
	 * restored on a host without it. The bytes are fine — this host simply
	 * cannot decode them — so this must be reported as a host problem
	 * (routed onward by the CLI/admin surfaces to the "could not check"
	 * outcome, exit 2), never as an untrustworthy archive.
	 *
	 * @return void
	 */
	public function test_read_entry_maps_a_missing_extension_to_host_cannot_comply_for_a_streamed_file(): void {
		$fixture = self::write_file_entry_to_fixture( 'big.txt', str_repeat( 'A', 1000 ), RawCodec::ID );

		$registry = new CodecRegistry();
		$registry->register(
			self::failing_codec(
				RawCodec::ID,
				'The zstd PHP extension (ext-zstd) is required for codec 0x0002 but is not loaded.',
				CodecUnavailableException::class
			)
		);
		$reader = new EntryReader( $registry );

		try {
			$reader->read_entry( $fixture[0], $fixture[1] );
			$this->fail( 'read_entry() should have raised HostCannotComply.' );
		} catch ( HostCannotComply $error ) {
			$this->assertStringContainsString(
				'The zstd PHP extension (ext-zstd) is required for codec 0x0002 but is not loaded.',
				$error->getMessage(),
				'The underlying codec message must survive, not just live in $previous.'
			);
		}
	}

	/**
	 * A missing extension decoding a buffered db_chunk entry is HostCannotComply, not ArchiveNotTrustworthy.
	 *
	 * The buffered counterpart of the streamed-file test above, proving
	 * {@see EntryReader::decode_spool_to_string()} makes the identical
	 * distinction as {@see EntryReader::read_entry()}'s own decode catch.
	 *
	 * @return void
	 */
	public function test_read_entry_maps_a_missing_extension_to_host_cannot_comply_for_a_buffered_db_chunk(): void {
		$sql_bytes = "CREATE TABLE `wp_options` (id INT);\n";
		$dest      = self::memory_stream();
		$source    = self::memory_stream( $sql_bytes );
		$header    = EntryHeader::for_db_chunk( 0, 'wp_options', 1, strlen( $sql_bytes ), 0 );
		$result    = self::make_writer()->write_entry( $header, RawCodec::ID, self::zero_nonce(), $source, $dest );

		$manifest_entry = ManifestEntry::for_db_chunk( 0, 0, $result->total_entry_length(), 0, RawCodec::ID, $result->entry_hash() );

		$registry = new CodecRegistry();
		$registry->register(
			self::failing_codec(
				RawCodec::ID,
				'The zstd PHP extension (ext-zstd) is required for codec 0x0002 but is not loaded.',
				CodecUnavailableException::class
			)
		);
		$reader = new EntryReader( $registry );

		try {
			$reader->read_entry( $dest, $manifest_entry );
			$this->fail( 'read_entry() should have raised HostCannotComply.' );
		} catch ( HostCannotComply $error ) {
			$this->assertStringContainsString(
				'The zstd PHP extension (ext-zstd) is required for codec 0x0002 but is not loaded.',
				$error->getMessage(),
				'The underlying codec message must survive, not just live in $previous.'
			);
		}
	}

	/**
	 * An entry over the archive's own decompression-bomb ceiling is
	 * BuildCannotComply, not HostCannotComply or ArchiveNotTrustworthy — and
	 * the refusal names the entry.
	 *
	 * $max_decoded_bytes (mirrored by {@see \Pontifex\Archive\Reader\ArchiveLimits::DEFAULT_MAX_ENTRY_BYTES})
	 * is compiled into every build and identical on every host, so an entry
	 * declaring more decoded bytes than it permits is a fact about neither the
	 * archive nor this host — this build simply will not process an entry that
	 * large ({@see BuildCannotComply}). It is not HostCannotComply, because no
	 * server setting moves this number the way a bigger memory_limit moves the
	 * memory-derived budget in
	 * {@see self::test_read_entry_still_refuses_as_host_cannot_comply_when_over_the_memory_derived_budget()}
	 * below. And it is not ArchiveNotTrustworthy: unlike a genuine
	 * decompression bomb ({@see self::test_read_entry_refuses_a_genuine_decompression_bomb_as_archive_not_trustworthy()}),
	 * this entry is not lying about anything — its header honestly declares
	 * its own size, which happens to be larger than the ceiling. Before this
	 * fix the refusal was HostCannotComply regardless of which budget fired,
	 * which sent an operator to fix a server that was never broken, over a
	 * limit no server setting can change — and the message never said which
	 * entry was the problem, so there was nothing to act on even once the
	 * wrong advice was set aside.
	 *
	 * @return void
	 */
	public function test_read_entry_refuses_as_build_cannot_comply_when_over_the_fixed_decoded_byte_ceiling(): void {
		$fixture = self::write_file_entry_to_fixture( 'wp-content/uploads/2024/huge-video.mov', str_repeat( 'A', 100 ), RawCodec::ID );

		try {
			self::make_reader()->read_entry( $fixture[0], $fixture[1], 10 );
			$this->fail( 'read_entry() should have raised BuildCannotComply.' );
		} catch ( BuildCannotComply $error ) {
			$this->assertStringContainsString(
				'wp-content/uploads/2024/huge-video.mov',
				$error->getMessage(),
				'The refusal must name the entry an operator can act on.'
			);
			$this->assertStringContainsString( '100 decoded bytes', $error->getMessage() );
			$this->assertStringContainsString( '10-byte budget', $error->getMessage() );
		}
	}

	/**
	 * An entry over THIS HOST's own memory-derived budget still refuses as
	 * HostCannotComply.
	 *
	 * The regression guard the type split above needs: telling the
	 * archive's compiled-in ceiling apart from a host's memory budget must
	 * not silently reclassify a genuine host failure as an archive problem
	 * too. $memory_budget only ever reaches this guard for a shape the
	 * reader must buffer whole (a db_chunk here; ADR 0010) — a plain file
	 * entry streams and so is never judged against it, which is why this
	 * uses a db_chunk fixture rather than the file fixture the sibling test
	 * above uses.
	 *
	 * @return void
	 */
	public function test_read_entry_still_refuses_as_host_cannot_comply_when_over_the_memory_derived_budget(): void {
		$sql_bytes = "CREATE TABLE `wp_options` (id INT);\n";
		$dest      = self::memory_stream();
		$source    = self::memory_stream( $sql_bytes );
		$header    = EntryHeader::for_db_chunk( 0, 'wp_options', 1, strlen( $sql_bytes ), 0 );
		$result    = self::make_writer()->write_entry( $header, RawCodec::ID, self::zero_nonce(), $source, $dest );

		$manifest_entry = ManifestEntry::for_db_chunk( 0, 0, $result->total_entry_length(), 0, RawCodec::ID, $result->entry_hash() );

		try {
			self::make_reader()->read_entry( $dest, $manifest_entry, EntryReader::DEFAULT_MAX_DECODED_BYTES, null, 10 );
			$this->fail( 'read_entry() should have raised HostCannotComply.' );
		} catch ( HostCannotComply $error ) {
			$this->assertStringContainsString(
				'wp_options',
				$error->getMessage(),
				'A db_chunk has no path; the table name must still name the entry.'
			);
			$this->assertStringContainsString( '10-byte budget', $error->getMessage() );
		}
	}

	/**
	 * Hand-compose a file entry whose header lies small but whose gzip payload decodes huge.
	 *
	 * The genuine decompression-bomb shape, distinct from a forged declared-size
	 * mismatch ({@see self::forge_lying_file_entry()}): a real writer never
	 * produces this, because {@see \Pontifex\Archive\Writer\EntryWriter} always
	 * records the byte count it actually captured. Composing it by hand is the
	 * only way to exercise the codec's own runaway-output guard rather than the
	 * header check that runs before any decode starts.
	 *
	 * @param string $plaintext Highly compressible content to compress and bury inside the record.
	 * @return array{0: resource, 1: ManifestEntry} The archive stream and matching manifest entry.
	 */
	private static function forge_gzip_bomb_entry( string $plaintext ): array {
		$plain_source = self::memory_stream( $plaintext );
		$compressed   = self::memory_stream();
		( new GzipCodec() )->encode( $plain_source, $compressed );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource, not a filesystem path.
		rewind( $compressed );
		$compressed_bytes = self::read_all( $compressed );

		// The header's declared size is a deliberate lie — small enough to sail
		// past the pre-decode budget check — while the gzip payload it sits
		// beside actually decompresses to strlen( $plaintext ) bytes.
		$header_bytes = EntryHeader::for_file( 'bomb.txt', 10, 0644, 1690000000, 'application/octet-stream', strlen( $compressed_bytes ) )->to_bytes();
		$record       = $header_bytes . ByteOrder::pack_uint16( GzipCodec::ID ) . self::zero_nonce() . $compressed_bytes;
		$hash         = hash( 'sha256', $record, true );
		$record      .= $hash;

		$manifest_entry = ManifestEntry::for_file( 0, 0, strlen( $record ), 'bomb.txt', GzipCodec::ID, $hash );

		return array( self::memory_stream( $record ), $manifest_entry );
	}

	/**
	 * A genuine decompression bomb — an honest-looking small header hiding a
	 * payload that decodes far past the budget — still refuses as
	 * ArchiveNotTrustworthy, not BuildCannotComply.
	 *
	 * The second regression guard the type split needs, the counterpart to
	 * {@see self::test_read_entry_still_refuses_as_host_cannot_comply_when_over_the_memory_derived_budget()}
	 * above. A bomb is a hostile payload whose decoded size runs away DURING
	 * decode, discoverable only by attempting it — genuinely a fact about the
	 * archive's bytes, caught by the codec's own runaway-output guard
	 * ({@see \Pontifex\Archive\Codec\GzipCodec::decode()}) rather than by
	 * {@see \Pontifex\Archive\Reader\EntryReader}'s pre-decode header check,
	 * which this bomb sails straight past on a declared size of 10 bytes.
	 * Confusing this with the fixed decoded-byte ceiling — an honest file
	 * simply larger than a number compiled into the plugin — is exactly the
	 * mistake that went wrong last round.
	 *
	 * @return void
	 */
	public function test_read_entry_refuses_a_genuine_decompression_bomb_as_archive_not_trustworthy(): void {
		$fixture = self::forge_gzip_bomb_entry( str_repeat( 'A', 200000 ) );

		try {
			self::make_reader()->read_entry( $fixture[0], $fixture[1], 100 );
			$this->fail( 'read_entry() should have raised ArchiveNotTrustworthy.' );
		} catch ( ArchiveNotTrustworthy $error ) {
			$this->assertStringContainsString(
				'Decoded output exceeded the maximum of 100 bytes',
				$error->getMessage(),
				'The codec-level runaway-output guard must be what caught this, not the header check.'
			);
		}
	}
}
