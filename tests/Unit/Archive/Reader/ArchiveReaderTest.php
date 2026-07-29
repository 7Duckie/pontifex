<?php
/**
 * Unit tests for the ArchiveReader class.
 *
 * @package Pontifex\Tests\Unit\Archive\Reader
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Archive\Reader;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Pontifex\Archive\Codec\CodecRegistry;
use Pontifex\Archive\Codec\RawCodec;
use Pontifex\Archive\Format\ArchiveManifest;
use Pontifex\Archive\Format\ByteOrder;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Format\ExporterInfo;
use Pontifex\Archive\Format\Footer;
use Pontifex\Archive\Format\Header;
use Pontifex\Archive\Format\ManifestEntry;
use Pontifex\Archive\Format\Provenance;
use Pontifex\Archive\Integrity\Sha256;
use Pontifex\Archive\Reader\ArchiveLimits;
use Pontifex\Archive\Reader\ArchiveReader;
use Pontifex\Archive\Writer\ArchiveWriter;
use Pontifex\Archive\Writer\EntryPlan;
use Pontifex\Archive\Writer\EntryWriter;
use Pontifex\Archive\Writer\FooterWriter;

/**
 * Tests for {@see ArchiveReader}.
 *
 * Exercises the skeleton reader: validation of the input stream,
 * eager parsing of Header and Footer, and the four public
 * accessors. Round-trip tests build a real archive with
 * ArchiveWriter and verify ArchiveReader recovers the matching
 * Header and Footer values.
 */
final class ArchiveReaderTest extends TestCase {

	/**
	 * Build a sample Provenance for archive construction in tests.
	 *
	 * @return Provenance A valid Provenance.
	 */
	private static function sample_provenance(): Provenance {
		return new Provenance(
			'6.6.1',
			'8.2.10',
			'https://example.test',
			'utf8mb4',
			'utf8mb4_unicode_520_ci',
			new ExporterInfo( 'pontifex', '0.1.0' ),
			new DateTimeImmutable( '2026-05-23T10:00:00+00:00', new DateTimeZone( 'UTC' ) )
		);
	}

	/**
	 * Build an ArchiveWriter wired with real default codec registry.
	 *
	 * @return ArchiveWriter A fresh writer.
	 */
	private static function make_writer(): ArchiveWriter {
		return new ArchiveWriter( new EntryWriter( CodecRegistry::with_defaults() ), new FooterWriter() );
	}

	/**
	 * Open a php://memory stream for tests.
	 *
	 * @return resource A readable and writable php://memory stream.
	 * @throws RuntimeException If php://memory cannot be opened.
	 */
	private static function memory_stream() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://memory is an in-process buffer, not a file; WP_Filesystem cannot open it.
		$stream = fopen( 'php://memory', 'r+b' );
		if ( false === $stream ) {
			throw new RuntimeException( 'Could not open php://memory for test.' );
		}
		return $stream;
	}

	/**
	 * Build a complete sample archive in memory and return a stream positioned at offset 0.
	 *
	 * The returned stream is the archive bytes, ready to feed to ArchiveReader.
	 *
	 * @return resource A readable, seekable stream containing a valid archive.
	 */
	private static function build_sample_archive_stream() {
		$dest = self::memory_stream();
		self::make_writer()->write_archive( self::sample_provenance(), array(), $dest );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource, not a filesystem path.
		rewind( $dest );
		return $dest;
	}

	/**
	 * Wrap arbitrary bytes in a seekable in-memory stream.
	 *
	 * @param string $bytes The bytes to wrap.
	 * @return resource A readable, seekable stream containing $bytes.
	 */
	private static function bytes_to_stream( string $bytes ) {
		$stream = self::memory_stream();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Operating on a test stream resource, not a filesystem path.
		fwrite( $stream, $bytes );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource, not a filesystem path.
		rewind( $stream );
		return $stream;
	}

	/**
	 * Read all bytes from a stream (rewind first), so we can build derived test fixtures.
	 *
	 * @param resource $stream The stream to read.
	 * @return string The full stream contents.
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
	 * The constructor must accept a valid archive stream.
	 *
	 * @return void
	 */
	public function test_construct_accepts_valid_archive(): void {
		$stream = self::build_sample_archive_stream();
		$reader = new ArchiveReader( $stream );

		$this->assertInstanceOf( ArchiveReader::class, $reader );
	}

	/**
	 * The constructor must reject a non-resource argument.
	 *
	 * @return void
	 */
	public function test_construct_rejects_non_resource(): void {
		$this->expectException( InvalidArgumentException::class );

		// @phpstan-ignore-next-line — intentionally passing wrong type to verify validation.
		new ArchiveReader( 'not a resource' );
	}

	/**
	 * The constructor must reject a stream that is not seekable.
	 *
	 * @return void
	 */
	public function test_construct_rejects_unseekable_stream(): void {
		// php://output is a write-only, non-seekable stream.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://output is an in-process pseudo-stream, not a file.
		$stream = fopen( 'php://output', 'w' );

		$this->expectException( InvalidArgumentException::class );

		try {
			new ArchiveReader( $stream );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Cleanup of test stream resource.
			fclose( $stream );
		}
	}

	/**
	 * The constructor must reject a stream too short to contain a header + footer.
	 *
	 * @return void
	 */
	public function test_construct_rejects_truncated_stream(): void {
		$stream = self::bytes_to_stream( "\x00" );

		$this->expectException( RuntimeException::class );

		new ArchiveReader( $stream );
	}

	/**
	 * The constructor must reject a stream whose header bytes do not parse.
	 *
	 * @return void
	 */
	public function test_construct_rejects_corrupt_header(): void {
		// Start with a real archive, then overwrite the first 16 bytes with junk.
		$bytes   = self::read_all( self::build_sample_archive_stream() );
		$corrupt = str_repeat( "\x00", Header::SIZE ) . substr( $bytes, Header::SIZE );
		$stream  = self::bytes_to_stream( $corrupt );

		$this->expectException( RuntimeException::class );

		new ArchiveReader( $stream );
	}

	/**
	 * The header() accessor must return a Header instance reflecting the on-disk bytes.
	 *
	 * @return void
	 */
	public function test_header_accessor_returns_parsed_header(): void {
		$reader = new ArchiveReader( self::build_sample_archive_stream() );
		$header = $reader->header();

		$this->assertInstanceOf( Header::class, $header );
		$this->assertSame( Header::FORMAT_MAJOR_V1, $header->major() );
		$this->assertSame( Header::FORMAT_MINOR_V1_1, $header->minor() );
	}

	/**
	 * The footer() accessor must return a Footer instance reflecting the on-disk bytes.
	 *
	 * @return void
	 */
	public function test_footer_accessor_returns_parsed_footer(): void {
		$reader = new ArchiveReader( self::build_sample_archive_stream() );
		$footer = $reader->footer();

		$this->assertInstanceOf( Footer::class, $footer );
		// Manifest offset must be non-negative and the manifest must fit inside the archive.
		$this->assertGreaterThanOrEqual( Header::SIZE, $footer->manifest_offset() );
		$this->assertGreaterThanOrEqual( 0, $footer->manifest_length() );
	}

	/**
	 * The manifest_offset() convenience accessor must match the footer's recorded offset.
	 *
	 * @return void
	 */
	public function test_manifest_offset_matches_footer(): void {
		$reader = new ArchiveReader( self::build_sample_archive_stream() );

		$this->assertSame( $reader->footer()->manifest_offset(), $reader->manifest_offset() );
	}

	/**
	 * The manifest_length() convenience accessor must match the footer's recorded length.
	 *
	 * @return void
	 */
	public function test_manifest_length_matches_footer(): void {
		$reader = new ArchiveReader( self::build_sample_archive_stream() );

		$this->assertSame( $reader->footer()->manifest_length(), $reader->manifest_length() );
	}

	/**
	 * The constructor must succeed even when the source stream is positioned mid-file at call time.
	 *
	 * Defends against a regression where the reader assumed the caller had positioned
	 * the stream at offset 0.
	 *
	 * @return void
	 */
	public function test_construct_works_when_stream_not_at_offset_zero(): void {
		$stream = self::build_sample_archive_stream();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek -- Positioning a test stream resource, not a filesystem path.
		fseek( $stream, 5 );

		$reader = new ArchiveReader( $stream );

		$this->assertInstanceOf( Header::class, $reader->header() );
	}

	/**
	 * Build an archive containing the given EntryPlan list and return a stream of its bytes.
	 *
	 * @param array<int, EntryPlan> $plans The entry plans to write.
	 * @return resource A readable, seekable stream containing the resulting archive.
	 */
	private static function build_archive_stream_with_entries( array $plans ) {
		$dest = self::memory_stream();
		self::make_writer()->write_archive( self::sample_provenance(), $plans, $dest );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource, not a filesystem path.
		rewind( $dest );
		return $dest;
	}

	/**
	 * Build a single file-entry plan for use in archive-construction tests.
	 *
	 * @param string $path     Relative path inside the archive.
	 * @param string $contents File contents.
	 * @return EntryPlan A plan that writes a raw-codec file entry.
	 */
	private static function plan_for_file( string $path, string $contents ): EntryPlan {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://memory is an in-process buffer, not a file.
		$src = fopen( 'php://memory', 'r+b' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Operating on a test stream resource, not a filesystem path.
		fwrite( $src, $contents );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource, not a filesystem path.
		rewind( $src );

		return new EntryPlan(
			EntryHeader::for_file( $path, strlen( $contents ), 0644, 1690000000, 'application/octet-stream', 0 ),
			RawCodec::ID,
			str_repeat( "\0", EntryWriter::NONCE_SIZE ),
			$src
		);
	}

	/**
	 * The manifest() method must return an ArchiveManifest instance for a valid archive with no entries.
	 *
	 * @return void
	 */
	public function test_manifest_accessor_returns_empty_manifest_for_archive_with_no_entries(): void {
		$reader = new ArchiveReader( self::build_sample_archive_stream() );

		$manifest = $reader->manifest();

		$this->assertInstanceOf( ArchiveManifest::class, $manifest );
		$this->assertSame( 0, $manifest->entry_count() );
	}

	/**
	 * The manifest() method must return all entries written, in order, when the archive has multiple entries.
	 *
	 * @return void
	 */
	public function test_manifest_accessor_returns_all_entries_for_archive_with_entries(): void {
		$plans  = array(
			self::plan_for_file( 'a.txt', 'apple' ),
			self::plan_for_file( 'b.txt', 'banana' ),
			self::plan_for_file( 'c.txt', 'cherry' ),
		);
		$reader = new ArchiveReader( self::build_archive_stream_with_entries( $plans ) );

		$manifest = $reader->manifest();
		$entries  = $manifest->entries();

		$this->assertSame( 3, $manifest->entry_count() );
		$this->assertSame( 'a.txt', $entries[0]->path() );
		$this->assertSame( 'b.txt', $entries[1]->path() );
		$this->assertSame( 'c.txt', $entries[2]->path() );
	}

	/**
	 * The manifest() method must return the same instance on repeated calls (caching).
	 *
	 * @return void
	 */
	public function test_manifest_accessor_is_cached(): void {
		$reader = new ArchiveReader( self::build_sample_archive_stream() );

		$first  = $reader->manifest();
		$second = $reader->manifest();

		$this->assertSame( $first, $second );
	}

	/**
	 * The manifest() method must reject a stream where the Footer's manifest_hash disagrees with the manifest's internal hash.
	 *
	 * Defense in depth: ArchiveManifest already verifies its own payload
	 * against its embedded hash; ArchiveReader additionally cross-checks
	 * that the embedded hash matches the Footer's recorded hash.
	 *
	 * @return void
	 */
	public function test_manifest_rejects_footer_hash_mismatch(): void {
		$bytes = self::read_all( self::build_sample_archive_stream() );

		// Corrupt the Footer's manifest_hash field (32 bytes starting 48 bytes from the end of the file).
		$footer_start = strlen( $bytes ) - Footer::SIZE;
		// Footer layout: manifest_offset (8) + manifest_length (8) + manifest_hash (32) + argon2id_salt (16) = 64.
		$hash_offset = $footer_start + 16;
		$tampered    = substr( $bytes, 0, $hash_offset ) . str_repeat( "\xFF", Sha256::DIGEST_SIZE ) . substr( $bytes, $hash_offset + Sha256::DIGEST_SIZE );

		$reader = new ArchiveReader( self::bytes_to_stream( $tampered ) );

		$this->expectException( RuntimeException::class );
		$reader->manifest();
	}

	/**
	 * The manifest() method must reject a manifest declared at an offset before the Header ends.
	 *
	 * @return void
	 */
	public function test_manifest_rejects_offset_inside_header(): void {
		$bytes = self::read_all( self::build_sample_archive_stream() );

		// Corrupt the Footer's manifest_offset (first 8 bytes of the footer) to point at offset 0.
		$footer_start = strlen( $bytes ) - Footer::SIZE;
		$tampered     = substr( $bytes, 0, $footer_start ) . str_repeat( "\x00", 8 ) . substr( $bytes, $footer_start + 8 );

		$reader = new ArchiveReader( self::bytes_to_stream( $tampered ) );

		$this->expectException( RuntimeException::class );
		$reader->manifest();
	}

	/**
	 * The manifest() method must reject a manifest whose declared length pushes past the Footer's start.
	 *
	 * @return void
	 */
	public function test_manifest_rejects_length_overflows_into_footer(): void {
		$bytes = self::read_all( self::build_sample_archive_stream() );

		// Corrupt the Footer's manifest_length to a huge value (bytes 8-16 of the footer).
		$footer_start = strlen( $bytes ) - Footer::SIZE;
		$huge_length  = "\x00\x00\x00\x00\xFF\xFF\xFF\xFF";
		$tampered     = substr( $bytes, 0, $footer_start + 8 ) . $huge_length . substr( $bytes, $footer_start + 16 );

		$reader = new ArchiveReader( self::bytes_to_stream( $tampered ) );

		$this->expectException( RuntimeException::class );
		$reader->manifest();
	}

	/**
	 * The manifest() method must reject malformed manifest payload bytes.
	 *
	 * Corrupts a byte inside the manifest JSON payload (mid-archive)
	 * so ArchiveManifest::from_bytes fails its internal hash check.
	 * The reader wraps that failure as RuntimeException. Uses an
	 * archive with entries so the manifest payload is non-empty.
	 *
	 * @return void
	 */
	public function test_manifest_rejects_malformed_payload(): void {
		$plans = array( self::plan_for_file( 'a.txt', 'apple' ) );
		$bytes = self::read_all( self::build_archive_stream_with_entries( $plans ) );

		// Read the original footer to know where the manifest payload starts.
		$footer_bytes  = substr( $bytes, strlen( $bytes ) - Footer::SIZE, Footer::SIZE );
		$footer        = Footer::from_bytes( $footer_bytes );
		$payload_start = $footer->manifest_offset() + ArchiveManifest::LENGTH_PREFIX_SIZE + Sha256::DIGEST_SIZE;

		// Flip a byte inside the JSON payload — internal hash will no longer match.
		$tampered = substr( $bytes, 0, $payload_start ) . "\xFF" . substr( $bytes, $payload_start + 1 );

		$reader = new ArchiveReader( self::bytes_to_stream( $tampered ) );

		$this->expectException( RuntimeException::class );
		$reader->manifest();
	}

	/**
	 * The provenance() accessor must return the source provenance, including the source URL.
	 *
	 * @return void
	 */
	public function test_provenance_accessor_returns_the_source_provenance(): void {
		$reader     = new ArchiveReader( self::build_sample_archive_stream() );
		$provenance = $reader->provenance();

		$this->assertInstanceOf( Provenance::class, $provenance );
		$this->assertSame( 'https://example.test', $provenance->url() );
		$this->assertSame( '6.6.1', $provenance->wp_version() );
	}

	/**
	 * The provenance() method must return the same instance on repeated calls (caching).
	 *
	 * @return void
	 */
	public function test_provenance_is_cached(): void {
		$reader = new ArchiveReader( self::build_sample_archive_stream() );

		$this->assertSame( $reader->provenance(), $reader->provenance() );
	}

	/**
	 * A custom source URL must round-trip through provenance() intact.
	 *
	 * @return void
	 */
	public function test_provenance_round_trips_a_custom_source_url(): void {
		$provenance = new Provenance(
			'6.7',
			'8.3.0',
			'https://migrate-source.example',
			'utf8mb4',
			'utf8mb4_general_ci',
			new ExporterInfo( 'pontifex', '0.3.0' ),
			new DateTimeImmutable( '2026-06-23T00:00:00+00:00', new DateTimeZone( 'UTC' ) )
		);

		$dest = self::memory_stream();
		self::make_writer()->write_archive( $provenance, array(), $dest );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource, not a filesystem path.
		rewind( $dest );

		$this->assertSame( 'https://migrate-source.example', ( new ArchiveReader( $dest ) )->provenance()->url() );
	}

	/**
	 * A corrupt provenance payload must be refused by provenance().
	 *
	 * @return void
	 */
	public function test_provenance_rejects_a_corrupt_block(): void {
		$bytes = self::read_all( self::build_sample_archive_stream() );

		// The provenance JSON payload starts after the header, its 4-byte length prefix, and its 32-byte hash.
		$payload_start = Header::SIZE + Provenance::LENGTH_PREFIX_SIZE + Sha256::DIGEST_SIZE;
		$tampered      = substr( $bytes, 0, $payload_start ) . "\xFF" . substr( $bytes, $payload_start + 1 );

		$reader = new ArchiveReader( self::bytes_to_stream( $tampered ) );

		$this->expectException( RuntimeException::class );
		$reader->provenance();
	}

	/**
	 * Build an archive stream whose footer declares a manifest length larger
	 * than the actual (tiny) manifest content, padded so the declared length
	 * genuinely fits between the header and the footer (satisfying the
	 * bounds check) without the padding bytes ever needing to be valid
	 * manifest JSON — the pre-decode guard must refuse before anything past
	 * the length field is ever read.
	 *
	 * @param string $path            Absolute path to write the fixture to.
	 * @param int    $declared_length The manifest length to declare in the footer.
	 * @return void
	 */
	private static function write_hostile_declared_length_fixture( string $path, int $declared_length ): void {
		$dest = self::memory_stream();
		self::make_writer()->write_archive( self::sample_provenance(), array(), $dest );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource, not a filesystem path.
		rewind( $dest );
		$bytes = self::read_all( $dest );

		$footer_start    = strlen( $bytes ) - Footer::SIZE;
		$manifest_offset = ByteOrder::unpack_uint64( substr( $bytes, $footer_start, 8 ) );
		$prefix          = substr( $bytes, 0, $manifest_offset );
		$footer          = substr( $bytes, $footer_start );
		// Patch only the manifest_length field (bytes 8-16 of the footer); offset,
		// hash, and salt are left as-is — never verified, because the guard
		// refuses before either is read.
		$patched_footer = substr( $footer, 0, 8 ) . ByteOrder::pack_uint64( $declared_length ) . substr( $footer, 16 );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Writing a test fixture to a real file so it can be sparsely padded without holding it in memory.
		$out = fopen( $path, 'w+b' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Writing a test fixture to a real file.
		fwrite( $out, $prefix );
		// Pad the file out to manifest_offset + declared_length with a single
		// sparse write at the target position, so building even a many-megabyte
		// fixture costs no real memory or disk I/O for the padding itself.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek -- Positioning at the end of the padded region to create a sparse file.
		fseek( $out, $manifest_offset + $declared_length - 1 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Sparse single-byte write; see above.
		fwrite( $out, "\0" );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Writing a test fixture to a real file.
		fwrite( $out, $patched_footer );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the test fixture file after writing it.
		fclose( $out );
	}

	/**
	 * A manifest whose decode cannot fit must be refused where the host
	 * forbids raising the limit.
	 *
	 * The refusal branch is only reachable on a host that will not let the
	 * request raise its own ceiling — where ini_set succeeds, the guard raises
	 * and proceeds, which is the behaviour we want. Simulating that honestly
	 * means a real child process with ini_set disabled, so this shells out
	 * rather than pretending in-process.
	 *
	 * The declared length sits just UNDER MAX_PAYLOAD_SIZE so the existing
	 * allocation cap cannot be what refuses it — otherwise this test would
	 * pass for the wrong reason. That a catchable RuntimeException comes back
	 * at all is itself proof no allocation happened: reading 16,000,000 bytes
	 * under an 8 MiB ceiling would be an uncatchable fatal.
	 *
	 * @return void
	 */
	public function test_manifest_decode_refused_when_the_host_forbids_raising_the_limit(): void {
		$path = tempnam( sys_get_temp_dir(), 'pontifex-oom-' );
		self::write_hostile_declared_length_fixture( $path, 16000000 );

		// Paths travel as environment variables rather than being interpolated
		// into the snippet: nothing from this test then has to survive two
		// levels of quoting, which is where scaffolding like this usually breaks.
		$snippet = 'require getenv( "PONTIFEX_AUTOLOAD" );'
			. ' $s = fopen( getenv( "PONTIFEX_FIXTURE" ), "rb" );'
			. ' $r = new Pontifex\\Archive\\Reader\\ArchiveReader( $s, 8388608 );'
			. ' try { $r->manifest(); echo "NO-REFUSAL"; } catch ( Throwable $e ) { echo $e->getMessage(); }';

		$command = sprintf(
			'PONTIFEX_AUTOLOAD=%s PONTIFEX_FIXTURE=%s php -d memory_limit=8M -d disable_functions=ini_set -r %s 2>&1',
			escapeshellarg( dirname( __DIR__, 4 ) . '/vendor/autoload.php' ),
			escapeshellarg( $path ),
			escapeshellarg( $snippet )
		);

		try {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec -- Test-only: the refusal branch is reachable only where ini_set is disabled, which cannot be simulated in-process; a real child process is the only honest way to exercise it.
			$output = (string) shell_exec( $command );

			$this->assertStringContainsString( 'Raise memory_limit on this server', $output );
			$this->assertStringNotContainsString( 'NO-REFUSAL', $output );
			$this->assertStringNotContainsString( 'Allowed memory size', $output, 'The guard must refuse cleanly, never fatal.' );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Test fixture teardown; best-effort.
			@unlink( $path );
		}
	}

	/**
	 * The memory guard must not refuse an archive that fits.
	 *
	 * The must-permit half, and the more important of the pair: a guard that
	 * refuses too eagerly locks an operator out of their own recovery, which
	 * is a worse failure for a backup tool than the one it defends against.
	 * A real fixture archive read under a generous ceiling must open exactly
	 * as it does with no limit supplied at all.
	 *
	 * @return void
	 */
	public function test_memory_guard_permits_an_archive_that_fits(): void {
		$reader = new ArchiveReader( self::build_sample_archive_stream(), 268435456 );

		$this->assertSame( 0, $reader->manifest()->entry_count() );
	}

	/**
	 * A decode that fits the refusal threshold but not the real cost must still
	 * raise the limit.
	 *
	 * The regression test for a real defect. The guard originally decided whether
	 * to raise using the REFUSAL threshold, so a decode needing eight times its
	 * declared length sailed past the optimistic check with room to spare, no
	 * raise was attempted, and it then died on the uncatchable fatal this whole
	 * method exists to prevent. The two thresholds answer different questions:
	 * "should I ask for more headroom?" must use the pessimistic figure.
	 *
	 * Chooses a declared length whose refusal estimate fits the starting limit
	 * comfortably while the raise target does not, so the only way the assertion
	 * can pass is if the raise decision ignores the refusal threshold.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	public function test_a_decode_within_the_refusal_threshold_still_raises_the_limit(): void {
		// phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- Test-only, in the isolated child process #[RunInSeparateProcess] provides; there is no WordPress runtime here for wp_raise_memory_limit() to hook into.
		ini_set( 'memory_limit', '64M' );
		$limit = 67108864;

		// 3x this is 24 MB and fits inside 64 MB; 12x is 96 MB and does not.
		$declared = 8388608;

		$reader = new ArchiveReader( self::build_sample_archive_stream(), $limit );
		$guard  = new \ReflectionMethod( ArchiveReader::class, 'assert_manifest_decode_fits_in_memory' );
		$guard->invoke( $reader, $declared );

		$applied = (int) $this->parse_shorthand_bytes( (string) ini_get( 'memory_limit' ) );

		$this->assertGreaterThan(
			$limit,
			$applied,
			'The guard must raise on the raise threshold, not skip the raise because the refusal threshold happened to fit.'
		);
	}

	/**
	 * Convert PHP's memory shorthand to bytes, for assertions.
	 *
	 * @param string $value A memory_limit value such as "96M".
	 * @return int The value in bytes.
	 */
	private function parse_shorthand_bytes( string $value ): int {
		$number = (int) $value;
		switch ( strtolower( substr( trim( $value ), -1 ) ) ) {
			case 'g':
				return $number * 1073741824;
			case 'm':
				return $number * 1048576;
			case 'k':
				return $number * 1024;
			default:
				return $number;
		}
	}

	/**
	 * The raise target must always clear the refusal threshold.
	 *
	 * The algorithm only terminates sensibly if a SUCCESSFUL raise is
	 * guaranteed to satisfy the very check that triggered it. Were the
	 * refusal factor ever set at or above the raise factor, the guard would
	 * raise the limit and then refuse anyway — doing the work and still
	 * failing. Pins the relationship rather than either number, because it is
	 * the relationship that carries the correctness.
	 *
	 * @return void
	 */
	public function test_raise_factor_clears_the_refusal_threshold(): void {
		$refusal = new \ReflectionClassConstant( ArchiveReader::class, 'REFUSAL_MEMORY_FACTOR' );
		$raise   = new \ReflectionClassConstant( ArchiveReader::class, 'RAISE_MEMORY_FACTOR' );

		$this->assertGreaterThan(
			$refusal->getValue(),
			$raise->getValue(),
			'A successful raise must leave more headroom than the refusal threshold demands.'
		);
	}

	/**
	 * An unlimited memory limit must switch the guard off entirely.
	 *
	 * A CLI run reports memory_limit as -1, which the callers normalise to a
	 * non-positive value; the reader must then skip the check rather than
	 * compute headroom against a meaningless ceiling.
	 *
	 * @return void
	 */
	public function test_memory_guard_skipped_when_memory_is_unlimited(): void {
		$reader = new ArchiveReader( self::build_sample_archive_stream(), -1 );

		$this->assertSame( 0, $reader->manifest()->entry_count() );
	}
}
