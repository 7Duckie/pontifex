<?php
/**
 * Unit tests for the RestoreRunner class.
 *
 * @package Pontifex\Tests\Unit\Restore
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Restore;

require_once __DIR__ . '/../Manifest/Fakes/FakeDbAdapter.php';

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Pontifex\Archive\Codec\CodecRegistry;
use Pontifex\Archive\Codec\GzipCodec;
use Pontifex\Archive\Codec\RawCodec;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Format\ExporterInfo;
use Pontifex\Archive\Format\Provenance;
use Pontifex\Archive\Reader\ArchiveLimits;
use Pontifex\Archive\Reader\EntryReader;
use Pontifex\Archive\Writer\ArchiveWriter;
use Pontifex\Archive\Writer\EntryPlan;
use Pontifex\Archive\Writer\EntryWriter;
use Pontifex\Archive\Writer\FooterWriter;
use Pontifex\Restore\DatabaseWriter;
use Pontifex\Restore\FileWriter;
use Pontifex\Restore\RestoreRunner;
use Pontifex\Tests\Unit\Manifest\Fakes\FakeDbAdapter;

/**
 * Tests for {@see RestoreRunner}.
 *
 * The end-to-end tests build a real archive via ArchiveWriter,
 * restore it via RestoreRunner, and verify that file contents land
 * on the fixture filesystem and SQL statements arrive at the
 * FakeDbAdapter. Routing tests use a counting wrapper around
 * FakeDbAdapter to verify which entries went where.
 */
final class RestoreRunnerTest extends TestCase {

	/**
	 * Absolute path to the fixture root used for the current test.
	 *
	 * @var string
	 */
	private string $fixture_root;

	/**
	 * Create a fresh fixture root before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->fixture_root = sys_get_temp_dir() . '/pontifex-restore-runner-test-' . bin2hex( random_bytes( 8 ) );
	}

	/**
	 * Remove the fixture root recursively after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ( is_dir( $this->fixture_root ) ) {
			self::rmtree( $this->fixture_root );
		}
		parent::tearDown();
	}

	/**
	 * Recursively delete a directory tree.
	 *
	 * @param string $path Absolute path to remove.
	 * @return void
	 */
	private static function rmtree( string $path ): void {
		if ( is_link( $path ) || ! is_dir( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Test fixture teardown.
			@unlink( $path );
			return;
		}
		foreach ( scandir( $path ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			self::rmtree( $path . '/' . $entry );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.PHP.NoSilencedErrors.Discouraged -- Test fixture teardown.
		@rmdir( $path );
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

	/**
	 * Build a sample Provenance for archive construction.
	 *
	 * @return Provenance A valid provenance instance.
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
	 * Build an ArchiveWriter wired with the default codec registry.
	 *
	 * @return ArchiveWriter A fresh writer.
	 */
	private static function make_archive_writer(): ArchiveWriter {
		return new ArchiveWriter( new EntryWriter( CodecRegistry::with_defaults() ), new FooterWriter() );
	}

	/**
	 * Build a RestoreRunner wired with a FileWriter rooted at the fixture and a fresh DatabaseWriter.
	 *
	 * @param FakeDbAdapter|null $db Optional adapter; if null, a fresh one is created.
	 * @return RestoreRunner Ready to call restore() on.
	 */
	private function make_runner( ?FakeDbAdapter $db = null ): RestoreRunner {
		$db = $db ?? new FakeDbAdapter();
		return new RestoreRunner(
			new EntryReader( CodecRegistry::with_defaults() ),
			new FileWriter( $this->fixture_root ),
			new DatabaseWriter( $db )
		);
	}

	/**
	 * Build an EntryPlan for a file entry with the given contents.
	 *
	 * @param string $path     Relative path inside the archive.
	 * @param string $contents File contents.
	 * @return EntryPlan A plan ready to feed to ArchiveWriter.
	 */
	private static function file_plan( string $path, string $contents ): EntryPlan {
		$header = EntryHeader::for_file( $path, strlen( $contents ), 0o644, 1690000000, 'application/octet-stream', 0 );
		return new EntryPlan( $header, RawCodec::ID, str_repeat( "\0", EntryWriter::NONCE_SIZE ), self::memory_stream( $contents ) );
	}

	/**
	 * Build an EntryPlan for a directory entry.
	 *
	 * @param string $path Relative path inside the archive.
	 * @return EntryPlan A plan ready to feed to ArchiveWriter.
	 */
	private static function directory_plan( string $path ): EntryPlan {
		$header = EntryHeader::for_directory( $path, 0o755, 0 );
		return new EntryPlan( $header, RawCodec::ID, str_repeat( "\0", EntryWriter::NONCE_SIZE ), self::memory_stream() );
	}

	/**
	 * Build an EntryPlan for a symlink entry.
	 *
	 * @param string $path   Relative path inside the archive.
	 * @param string $target The link target string.
	 * @return EntryPlan A plan ready to feed to ArchiveWriter.
	 */
	private static function symlink_plan( string $path, string $target ): EntryPlan {
		$header = EntryHeader::for_symlink( $path, $target, 0 );
		return new EntryPlan( $header, RawCodec::ID, str_repeat( "\0", EntryWriter::NONCE_SIZE ), self::memory_stream() );
	}

	/**
	 * Build an EntryPlan for a db_chunk entry.
	 *
	 * @param string $table_name      Source table name.
	 * @param int    $statement_count Number of statements in the chunk.
	 * @param string $sql             SQL bytes (semicolon-newline terminated).
	 * @return EntryPlan A plan ready to feed to ArchiveWriter.
	 */
	private static function db_chunk_plan( string $table_name, int $statement_count, string $sql ): EntryPlan {
		$header = EntryHeader::for_db_chunk( 0, $table_name, $statement_count, strlen( $sql ), 0 );
		return new EntryPlan( $header, RawCodec::ID, str_repeat( "\0", EntryWriter::NONCE_SIZE ), self::memory_stream( $sql ) );
	}

	/**
	 * Build an archive in memory from the given EntryPlan list and return a stream of its bytes.
	 *
	 * @param EntryPlan[] $plans The plans to include.
	 * @return resource A readable, seekable stream containing the archive bytes.
	 */
	private static function build_archive_stream( array $plans ) {
		$dest = self::memory_stream();
		self::make_archive_writer()->write_archive( self::sample_provenance(), $plans, $dest );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource, not a filesystem path.
		rewind( $dest );
		return $dest;
	}

	/**
	 * Restoring an archive with no entries must complete without writing anything.
	 *
	 * @return void
	 */
	public function test_restore_empty_archive_completes_without_writes(): void {
		$db     = new FakeDbAdapter();
		$runner = $this->make_runner( $db );

		$runner->restore( self::build_archive_stream( array() ) );

		$this->assertSame( array(), $db->executed_statements() );
		// Fixture root was created by FileWriter constructor; should be empty beyond that.
		$entries = array_diff( scandir( $this->fixture_root ), array( '.', '..' ) );
		$this->assertSame( array(), array_values( $entries ) );
	}

	/**
	 * A file entry must be restored to the destination filesystem.
	 *
	 * @return void
	 */
	public function test_restore_writes_file_entry(): void {
		$runner = $this->make_runner();
		$plans  = array( self::file_plan( 'note.txt', 'hello world' ) );

		$runner->restore( self::build_archive_stream( $plans ) );

		$path = $this->fixture_root . '/note.txt';
		$this->assertTrue( file_exists( $path ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test assertion against on-disk fixture.
		$this->assertSame( 'hello world', file_get_contents( $path ) );
	}

	/**
	 * A directory entry must be restored to the destination filesystem.
	 *
	 * @return void
	 */
	public function test_restore_writes_directory_entry(): void {
		$runner = $this->make_runner();
		$plans  = array( self::directory_plan( 'wp-content/uploads' ) );

		$runner->restore( self::build_archive_stream( $plans ) );

		$this->assertTrue( is_dir( $this->fixture_root . '/wp-content/uploads' ) );
	}

	/**
	 * A symlink entry must be restored to the destination filesystem.
	 *
	 * @return void
	 */
	public function test_restore_writes_symlink_entry(): void {
		$runner = $this->make_runner();
		$plans  = array( self::symlink_plan( 'wp-content/cache', '/tmp/wp-cache' ) );

		$runner->restore( self::build_archive_stream( $plans ) );

		$link = $this->fixture_root . '/wp-content/cache';
		$this->assertTrue( is_link( $link ) );
		$this->assertSame( '/tmp/wp-cache', readlink( $link ) );
	}

	/**
	 * A db_chunk entry must be replayed into the destination database.
	 *
	 * @return void
	 */
	public function test_restore_replays_db_chunk_entry(): void {
		$db     = new FakeDbAdapter();
		$runner = $this->make_runner( $db );
		$sql    = "CREATE TABLE `wp_options` (id INT);\nINSERT INTO `wp_options` VALUES (1);\n";
		$plans  = array( self::db_chunk_plan( 'wp_options', 2, $sql ) );

		$runner->restore( self::build_archive_stream( $plans ) );

		$executed = $db->executed_statements();
		$this->assertCount( 2, $executed );
		$this->assertSame( 'CREATE TABLE `wp_options` (id INT)', $executed[0] );
		$this->assertSame( 'INSERT INTO `wp_options` VALUES (1)', $executed[1] );
	}

	/**
	 * A mixed archive (files plus db_chunks) must route every entry to the correct writer.
	 *
	 * Files and directories land on disk; db_chunks reach the adapter.
	 * Ordering: files first, then db_chunks — matching the writer's
	 * deterministic emit order.
	 *
	 * @return void
	 */
	public function test_restore_routes_mixed_entries_correctly(): void {
		$db     = new FakeDbAdapter();
		$runner = $this->make_runner( $db );
		$plans  = array(
			self::file_plan( 'a.txt', 'apple' ),
			self::file_plan( 'b.txt', 'banana' ),
			self::db_chunk_plan( 'wp_posts', 1, "CREATE TABLE `wp_posts` (id INT);\n" ),
		);

		$runner->restore( self::build_archive_stream( $plans ) );

		// Files on disk.
		$this->assertTrue( file_exists( $this->fixture_root . '/a.txt' ) );
		$this->assertTrue( file_exists( $this->fixture_root . '/b.txt' ) );
		// db_chunk on adapter.
		$this->assertCount( 1, $db->executed_statements() );
		$this->assertSame( 'CREATE TABLE `wp_posts` (id INT)', $db->executed_statements()[0] );
	}

	/**
	 * Failures in the FileWriter must propagate out of restore().
	 *
	 * Triggered by including an entry whose path violates the
	 * path-traversal defense; FileWriter throws InvalidArgumentException
	 * and the runner surfaces it.
	 *
	 * @return void
	 */
	public function test_restore_halts_on_file_writer_failure(): void {
		$runner = $this->make_runner();
		// A path with a ".." segment causes FileWriter to reject the entry.
		$plans = array( self::file_plan( '../escape.txt', 'malicious' ) );

		$this->expectException( InvalidArgumentException::class );

		$runner->restore( self::build_archive_stream( $plans ) );
	}

	/**
	 * Failures in the DatabaseWriter must propagate out of restore().
	 *
	 * Configures FakeDbAdapter to throw on the next execute_sql call.
	 *
	 * @return void
	 */
	public function test_restore_halts_on_database_writer_failure(): void {
		$db = new FakeDbAdapter();
		$db->fail_next_execute( 'simulated MySQL error' );
		$runner = $this->make_runner( $db );

		$plans = array( self::db_chunk_plan( 't', 1, "CREATE TABLE `t` (id INT);\n" ) );

		$this->expectException( RuntimeException::class );

		$runner->restore( self::build_archive_stream( $plans ) );
	}

	/**
	 * Restoring is idempotent: running restore() twice with the same archive produces the same final state.
	 *
	 * FileWriter overwrites existing files; the runner's stateless
	 * design makes a second call equivalent to the first.
	 *
	 * @return void
	 */
	public function test_restore_is_idempotent(): void {
		$runner   = $this->make_runner();
		$plans1   = array( self::file_plan( 'note.txt', 'first' ) );
		$archive1 = self::build_archive_stream( $plans1 );

		$runner->restore( $archive1 );

		$plans2   = array( self::file_plan( 'note.txt', 'first' ) );
		$archive2 = self::build_archive_stream( $plans2 );
		$runner->restore( $archive2 );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test assertion against on-disk fixture.
		$this->assertSame( 'first', file_get_contents( $this->fixture_root . '/note.txt' ) );
	}

	/**
	 * The restore() callback fires once per entry as (done, total).
	 *
	 * Mirrors the per-entry callback contract on ArchiveWriter: three
	 * entries yield (1, 3), (2, 3), (3, 3).
	 *
	 * @return void
	 */
	public function test_restore_invokes_progress_callback_per_entry(): void {
		$runner = $this->make_runner();
		$plans  = array(
			self::file_plan( 'a.txt', 'apple' ),
			self::file_plan( 'b.txt', 'banana' ),
			self::file_plan( 'c.txt', 'cherry' ),
		);
		$calls  = array();

		$runner->restore(
			self::build_archive_stream( $plans ),
			static function ( int $done, int $total ) use ( &$calls ): void {
				$calls[] = array( $done, $total );
			}
		);

		$this->assertSame(
			array( array( 1, 3 ), array( 2, 3 ), array( 3, 3 ) ),
			$calls
		);
	}

	/**
	 * An empty archive never invokes the progress callback.
	 *
	 * @return void
	 */
	public function test_restore_empty_archive_does_not_invoke_callback(): void {
		$runner = $this->make_runner();
		$calls  = array();

		$runner->restore(
			self::build_archive_stream( array() ),
			static function ( int $done, int $total ) use ( &$calls ): void {
				$calls[] = array( $done, $total );
			}
		);

		$this->assertSame( array(), $calls );
	}

	/**
	 * The verify() walk reads and checks every entry but writes nothing.
	 *
	 * A mixed archive (a file and a db_chunk) is verified; afterwards the
	 * destination filesystem is empty and the adapter executed no SQL.
	 *
	 * @return void
	 */
	public function test_verify_reads_without_writing(): void {
		$db     = new FakeDbAdapter();
		$runner = $this->make_runner( $db );
		$plans  = array(
			self::file_plan( 'note.txt', 'hello world' ),
			self::db_chunk_plan( 'wp_options', 1, "CREATE TABLE `wp_options` (id INT);\n" ),
		);

		$runner->verify( self::build_archive_stream( $plans ) );

		$entries = array_diff( scandir( $this->fixture_root ), array( '.', '..' ) );
		$this->assertSame( array(), array_values( $entries ) );
		$this->assertSame( array(), $db->executed_statements() );
	}

	/**
	 * The verify() callback fires once per entry as (done, total).
	 *
	 * @return void
	 */
	public function test_verify_invokes_progress_callback_per_entry(): void {
		$runner = $this->make_runner();
		$plans  = array(
			self::file_plan( 'a.txt', 'apple' ),
			self::file_plan( 'b.txt', 'banana' ),
		);
		$calls  = array();

		$runner->verify(
			self::build_archive_stream( $plans ),
			static function ( int $done, int $total ) use ( &$calls ): void {
				$calls[] = array( $done, $total );
			}
		);

		$this->assertSame( array( array( 1, 2 ), array( 2, 2 ) ), $calls );
	}

	/**
	 * Build a RestoreRunner with explicit defensive limits.
	 *
	 * @param ArchiveLimits      $limits The limits to enforce.
	 * @param FakeDbAdapter|null $db     Optional adapter; if null, a fresh one is created.
	 * @return RestoreRunner Ready to call restore() on.
	 */
	private function make_runner_with_limits( ArchiveLimits $limits, ?FakeDbAdapter $db = null ): RestoreRunner {
		$db = $db ?? new FakeDbAdapter();
		return new RestoreRunner(
			new EntryReader( CodecRegistry::with_defaults() ),
			new FileWriter( $this->fixture_root ),
			new DatabaseWriter( $db ),
			$limits
		);
	}

	/**
	 * Restoring must refuse an archive that declares more entries than allowed.
	 *
	 * @return void
	 */
	public function test_restore_rejects_too_many_entries(): void {
		$limits = new ArchiveLimits( 2, 2147483648, 100, 1099511627776 );
		$db     = new FakeDbAdapter();
		$runner = $this->make_runner_with_limits( $limits, $db );
		$plans  = array(
			self::file_plan( 'a.txt', 'apple' ),
			self::file_plan( 'b.txt', 'banana' ),
			self::file_plan( 'c.txt', 'cherry' ),
		);

		// The entry-count ceiling is checked up front, before any entry is
		// read or written, so the destination must be left untouched.
		$this->assert_refused(
			static fn () => $runner->restore( self::build_archive_stream( $plans ) ),
			$db
		);
	}

	/**
	 * Restoring must refuse once the running decoded total exceeds the budget.
	 *
	 * A tiny absolute ceiling forces the shared budget to bite partway
	 * through: the first entries fit, a later one pushes the running
	 * total over and is refused.
	 *
	 * @return void
	 */
	public function test_restore_rejects_total_exceeding_budget(): void {
		$limits = new ArchiveLimits( 50000, 2147483648, 100, 15 );
		$runner = $this->make_runner_with_limits( $limits );
		$plans  = array(
			self::file_plan( 'a.txt', 'apple' ),
			self::file_plan( 'b.txt', 'banana' ),
			self::file_plan( 'c.txt', 'cherry' ),
		);

		$this->expectException( RuntimeException::class );

		$runner->restore( self::build_archive_stream( $plans ) );
	}

	/**
	 * A restore comfortably within explicit limits must still succeed.
	 *
	 * @return void
	 */
	public function test_restore_within_limits_succeeds(): void {
		$limits = new ArchiveLimits( 100, 1048576, 100, 10485760 );
		$runner = $this->make_runner_with_limits( $limits );
		$plans  = array( self::file_plan( 'note.txt', 'hello world' ) );

		$runner->restore( self::build_archive_stream( $plans ) );

		$this->assertTrue( file_exists( $this->fixture_root . '/note.txt' ) );
	}

	/**
	 * Restoring must refuse a single entry that decodes larger than the per-entry ceiling.
	 *
	 * A raw entry of five bytes is fed under a three-byte per-entry limit. The
	 * reader refuses it while decoding — before the entry is ever dispatched to
	 * a writer — so the destination is left untouched.
	 *
	 * @return void
	 */
	public function test_restore_rejects_oversized_entry(): void {
		$limits = new ArchiveLimits( 50000, 3, 100, 1099511627776 );
		$db     = new FakeDbAdapter();
		$runner = $this->make_runner_with_limits( $limits, $db );
		$plans  = array( self::file_plan( 'big.txt', 'apple' ) );

		$this->assert_refused(
			static fn () => $runner->restore( self::build_archive_stream( $plans ) ),
			$db
		);
	}

	/**
	 * Restoring must refuse a decompression bomb before it can exhaust memory or disk.
	 *
	 * A hundred thousand identical bytes compress to a few hundred bytes on
	 * disk, so against this tiny archive the decompression-ratio bound is blown
	 * long before the payload is fully inflated. The gzip codec aborts
	 * mid-stream — overshooting by at most one chunk — and nothing is written.
	 *
	 * @return void
	 */
	public function test_restore_rejects_decompression_bomb(): void {
		$limits = new ArchiveLimits( 50000, 2147483648, 2, 1099511627776 );
		$db     = new FakeDbAdapter();
		$runner = $this->make_runner_with_limits( $limits, $db );
		$plans  = array( self::gzip_file_plan( 'bomb.txt', str_repeat( 'A', 100000 ) ) );

		$this->assert_refused(
			static fn () => $runner->restore( self::build_archive_stream( $plans ) ),
			$db
		);
	}

	/**
	 * Build a gzip-compressed file EntryPlan from raw (compressible) contents.
	 *
	 * The ArchiveWriter compresses the raw stream through the gzip codec, so a
	 * highly repetitive payload yields a tiny archive that decodes back to the
	 * full size — the shape of a decompression bomb.
	 *
	 * @param string $path     Relative path inside the archive.
	 * @param string $contents Raw (uncompressed) file contents.
	 * @return EntryPlan A plan ready to feed to ArchiveWriter.
	 */
	private static function gzip_file_plan( string $path, string $contents ): EntryPlan {
		$header = EntryHeader::for_file( $path, strlen( $contents ), 0o644, 1690000000, 'application/octet-stream', 0 );
		return new EntryPlan( $header, GzipCodec::ID, str_repeat( "\0", EntryWriter::NONCE_SIZE ), self::memory_stream( $contents ) );
	}

	/**
	 * Assert that a restore action is refused and leaves the destination untouched.
	 *
	 * Runs $restore, requires it to throw a RuntimeException (the refusal), then
	 * asserts that no file landed under the fixture root and that the database
	 * adapter executed no statements.
	 *
	 * @param callable      $restore The restore call expected to be refused.
	 * @param FakeDbAdapter $db      The adapter that must have executed nothing.
	 * @return void
	 */
	private function assert_refused( callable $restore, FakeDbAdapter $db ): void {
		$refused = false;
		try {
			$restore();
		} catch ( RuntimeException $e ) {
			$refused = true;
		}

		$this->assertTrue( $refused, 'Expected the hostile archive to be refused with a RuntimeException.' );

		$entries = array_diff( scandir( $this->fixture_root ), array( '.', '..' ) );
		$this->assertSame( array(), array_values( $entries ), 'A refused archive must not write any files.' );
		$this->assertSame( array(), $db->executed_statements(), 'A refused archive must not execute any SQL.' );
	}

	/**
	 * A file must never escape the destination root through a restored symlink.
	 *
	 * A hostile archive places a symlink pointing outside the root, then a file
	 * whose path traverses that symlink. If the writer follows the link it
	 * writes outside the root — the Zip-Slip-via-symlink class (cf. the Bower
	 * archive-extraction CVE). The restore must refuse, and nothing may appear
	 * at the symlink's target.
	 *
	 * @return void
	 */
	public function test_restore_refuses_to_write_through_an_escaping_symlink(): void {
		$outside = sys_get_temp_dir() . '/pontifex-escape-target-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture: an out-of-root directory the hostile archive tries to write into.
		mkdir( $outside, 0o755, true );

		try {
			$runner = $this->make_runner();
			$plans  = array(
				self::symlink_plan( 'breakout', $outside ),
				self::file_plan( 'breakout/escaped.txt', 'PWNED' ),
			);

			$refused = false;
			try {
				$runner->restore( self::build_archive_stream( $plans ) );
			} catch ( InvalidArgumentException | RuntimeException $e ) {
				$refused = true;
			}

			$this->assertFileDoesNotExist(
				$outside . '/escaped.txt',
				'A file must never be written outside the destination root through a symlink.'
			);
			$this->assertTrue( $refused, 'Writing a file through an escaping symlink must be refused.' );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Test cleanup of the out-of-root target.
			@unlink( $outside . '/escaped.txt' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.PHP.NoSilencedErrors.Discouraged -- Test cleanup of the out-of-root target.
			@rmdir( $outside );
		}
	}

	/**
	 * A file entry must not clobber an out-of-root file by reusing a symlink's path.
	 *
	 * A hostile archive places a symlink pointing at a sensitive out-of-root
	 * file, then a file entry at the same path. Writing the file must replace
	 * the symlink in place — never follow it and overwrite the target.
	 *
	 * @return void
	 */
	public function test_restore_does_not_overwrite_a_file_through_a_symlink(): void {
		$outside = sys_get_temp_dir() . '/pontifex-symlink-target-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture: a sensitive out-of-root file the hostile archive tries to clobber.
		file_put_contents( $outside, 'ORIGINAL' );

		try {
			$runner = $this->make_runner();
			$plans  = array(
				self::symlink_plan( 'victim', $outside ),
				self::file_plan( 'victim', 'OVERWRITTEN' ),
			);

			$runner->restore( self::build_archive_stream( $plans ) );

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test assertion that the out-of-root file is untouched.
			$this->assertSame( 'ORIGINAL', file_get_contents( $outside ), 'A file write must not follow a symlink out of the root.' );
			$in_root = $this->fixture_root . '/victim';
			$this->assertFalse( is_link( $in_root ), 'The conflicting symlink must be replaced by a real file.' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test assertion against the in-root file.
			$this->assertSame( 'OVERWRITTEN', file_get_contents( $in_root ) );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Test cleanup of the out-of-root file.
			@unlink( $outside );
		}
	}

	/**
	 * Restoring must refuse an absolute entry path.
	 *
	 * @return void
	 */
	public function test_restore_rejects_an_absolute_entry_path(): void {
		$runner = $this->make_runner();
		$plans  = array( self::file_plan( '/etc/pontifex-evil', 'nope' ) );

		$this->expectException( InvalidArgumentException::class );

		$runner->restore( self::build_archive_stream( $plans ) );
	}

	/**
	 * Restoring must refuse a backslash-style traversal path, even on non-Windows hosts.
	 *
	 * FileWriter normalises backslashes before scanning for ".." segments, so
	 * "..\\..\\evil.txt" is caught on Linux CI just as "../../evil.txt" would be.
	 *
	 * @return void
	 */
	public function test_restore_rejects_a_backslash_traversal_path(): void {
		$runner = $this->make_runner();
		$plans  = array( self::file_plan( '..\\..\\evil.txt', 'nope' ) );

		$this->expectException( InvalidArgumentException::class );

		$runner->restore( self::build_archive_stream( $plans ) );
	}
}
