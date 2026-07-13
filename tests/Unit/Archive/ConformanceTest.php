<?php
/**
 * Conformance tests: the writer's bytes match the committed golden vector, byte for byte.
 *
 * @package Pontifex\Tests\Unit\Archive
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Archive;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Pontifex\Archive\Codec\CodecRegistry;
use Pontifex\Archive\Codec\RawCodec;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Format\ExporterInfo;
use Pontifex\Archive\Format\Header;
use Pontifex\Archive\Format\Provenance;
use Pontifex\Archive\Reader\ArchiveReader;
use Pontifex\Archive\Reader\EntryReader;
use Pontifex\Archive\Writer\ArchiveWriter;
use Pontifex\Archive\Writer\EntryPlan;
use Pontifex\Archive\Writer\EntryWriter;
use Pontifex\Archive\Writer\FooterWriter;

/**
 * Enforces the format's headline promise: byte-identical output from identical inputs.
 *
 * The specification (docs/archive-format.md) declares itself the contract and
 * says any party may build an implementation; two implementations producing
 * byte-identical archives from identical inputs is the project's working
 * definition of "the specification is correct". These tests make that promise
 * enforceable: a canonical archive built from fixed inputs (raw codec, zero
 * nonces, pinned provenance) is committed as tests/Fixtures/conformance-v1_1.wpmig,
 * the writer must reproduce it byte for byte on every PHP version CI runs, and
 * the reader must consume the committed bytes exactly as Appendix A documents
 * them. Any format change that would silently break a third-party
 * implementation breaks these tests first.
 */
final class ConformanceTest extends TestCase {

	/**
	 * SHA-256 of the committed golden archive, as documented in Appendix A.
	 *
	 * @var string
	 */
	private const GOLDEN_SHA256 = 'bb6cdc326fd715ec83986992639ab1e30e2d6e202a43ea2bb5da95e84d039cb4';

	/**
	 * The golden archive's 16 header bytes, hex-encoded (magic, v1.1, no flags).
	 *
	 * @var string
	 */
	private const GOLDEN_HEADER_HEX = '57504d49470000010001000100000000';

	/**
	 * The golden file entry's contents.
	 *
	 * @var string
	 */
	private const FILE_CONTENTS = "hello wpmig\n";

	/**
	 * The golden db_chunk's SQL payload.
	 *
	 * @var string
	 */
	private const CHUNK_SQL = "DROP TABLE IF EXISTS `wp_options`;\nCREATE TABLE `wp_options` (id INT);\nINSERT INTO `wp_options` VALUES (1);\n";

	/**
	 * Absolute path of the committed golden fixture.
	 *
	 * @return string The fixture path.
	 */
	private static function fixture_path(): string {
		return dirname( __DIR__, 2 ) . '/Fixtures/conformance-v1_1.wpmig';
	}

	/**
	 * Build the golden archive's bytes from the canonical fixed inputs.
	 *
	 * @return string The archive bytes.
	 */
	private static function build_golden_archive(): string {
		$provenance = new Provenance(
			'6.6.1',
			'8.2.0',
			'https://conformance.example',
			'utf8mb4',
			'utf8mb4_unicode_520_ci',
			new ExporterInfo( 'pontifex', '1.0.0' ),
			new DateTimeImmutable( '2026-01-01T00:00:00+00:00', new DateTimeZone( 'UTC' ) )
		);

		$plans = array(
			new EntryPlan( EntryHeader::for_file( 'wp-content/hello.txt', strlen( self::FILE_CONTENTS ), 0o644, 1767225600, 'text/plain', 0 ), RawCodec::ID, str_repeat( "\0", EntryWriter::NONCE_SIZE ), self::memory_stream( self::FILE_CONTENTS ) ),
			new EntryPlan( EntryHeader::for_directory( 'wp-content/uploads', 0o755, 0 ), RawCodec::ID, str_repeat( "\0", EntryWriter::NONCE_SIZE ), self::memory_stream() ),
			new EntryPlan( EntryHeader::for_symlink( 'wp-content/link', '../hello.txt', 0 ), RawCodec::ID, str_repeat( "\0", EntryWriter::NONCE_SIZE ), self::memory_stream() ),
			new EntryPlan( EntryHeader::for_db_chunk( 0, 'wp_options', 3, strlen( self::CHUNK_SQL ), 0 ), RawCodec::ID, str_repeat( "\0", EntryWriter::NONCE_SIZE ), self::memory_stream( self::CHUNK_SQL ) ),
		);

		$dest = self::memory_stream();
		( new ArchiveWriter( new EntryWriter( CodecRegistry::with_defaults() ), new FooterWriter() ) )->write_archive( $provenance, $plans, $dest );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on an in-memory test stream, not a filesystem path.
		rewind( $dest );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_stream_get_contents -- Operating on an in-memory test stream, not a filesystem path.
		return (string) stream_get_contents( $dest );
	}

	/**
	 * The writer must reproduce the committed golden archive byte for byte.
	 *
	 * This is the "byte-identical output from identical inputs" promise made
	 * enforceable. CI runs it on every supported PHP version, so it also proves
	 * the writer is deterministic across runtimes.
	 *
	 * @return void
	 */
	public function test_writer_reproduces_the_golden_archive_byte_for_byte(): void {
		$built = self::build_golden_archive();

		$this->assertSame( self::GOLDEN_SHA256, hash( 'sha256', $built ), 'The built archive must hash exactly as Appendix A documents.' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading the committed test fixture.
		$this->assertSame( (string) file_get_contents( self::fixture_path() ), $built, 'The writer must reproduce the committed fixture byte for byte.' );
	}

	/**
	 * The committed golden archive's head matches the documented header bytes.
	 *
	 * @return void
	 */
	public function test_golden_header_bytes_match_the_specification(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading the committed test fixture.
		$bytes = (string) file_get_contents( self::fixture_path() );

		$this->assertSame( self::GOLDEN_HEADER_HEX, bin2hex( substr( $bytes, 0, Header::SIZE ) ), 'Magic + version 1.1 + zero flags, big-endian, as Appendix A documents.' );
	}

	/**
	 * The reader consumes the committed fixture exactly as the specification describes.
	 *
	 * Pins the shipped truth the specification now documents: manifest shape
	 * with per-kind fields, the symlink target in the header JSON (empty
	 * payload), and the db_chunk's four required fields.
	 *
	 * @return void
	 */
	public function test_reader_consumes_the_golden_archive_as_documented(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading the committed test fixture.
		$source = self::memory_stream( (string) file_get_contents( self::fixture_path() ) );
		$reader = new ArchiveReader( $source );

		$this->assertSame( 'https://conformance.example', $reader->provenance()->url() );

		$entries = $reader->manifest()->entries();
		$this->assertCount( 4, $entries );
		$this->assertSame( array( 'file', 'directory', 'symlink', 'db_chunk' ), array_map( static fn ( $e ) => $e->kind(), $entries ) );

		$entry_reader = new EntryReader( CodecRegistry::with_defaults() );

		$file = $entry_reader->read_entry( $source, $entries[0] );
		$this->assertSame( self::FILE_CONTENTS, stream_get_contents( $file->payload_stream() ), 'The file entry must round-trip its payload.' );
		$this->assertSame( 0o644, $file->header()->mode(), 'mode is an integer field, as the specification documents.' );
		$this->assertSame( 1767225600, $file->header()->mtime(), 'mtime is a Unix-timestamp integer, as the specification documents.' );

		$symlink = $entry_reader->read_entry( $source, $entries[2] );
		$this->assertSame( '../hello.txt', $symlink->header()->target(), 'The symlink target lives in the header JSON.' );
		$this->assertSame( '', $symlink->payload(), 'A symlink entry has an empty payload.' );

		$chunk = $entry_reader->read_entry( $source, $entries[3] );
		$this->assertSame( self::CHUNK_SQL, $chunk->payload() );
		$this->assertSame( 'wp_options', $chunk->header()->table_name() );
		$this->assertSame( 3, $chunk->header()->statement_count() );
	}

	/**
	 * An archive from a higher major version is refused at open.
	 *
	 * The compatibility contract the specification promises third parties:
	 * a higher MAJOR means structural changes this reader cannot interpret,
	 * so it refuses rather than misreads.
	 *
	 * @return void
	 */
	public function test_a_higher_major_version_is_refused(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading the committed test fixture.
		$bytes = (string) file_get_contents( self::fixture_path() );
		// The major version is the uint16 at offset 8; raise it to 2.
		$bytes[8] = "\x00";
		$bytes[9] = "\x02";

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'major version' );

		new ArchiveReader( self::memory_stream( $bytes ) );
	}

	/**
	 * Open a php://memory stream.
	 *
	 * @param string $contents Optional initial contents.
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
}
