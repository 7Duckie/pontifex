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
	 * @param FakeDbAdapter|null $db                    Optional adapter; if null, a fresh one is created.
	 * @param bool               $allow_unsafe_symlinks Optional. Allow escaping symlink targets (default false).
	 * @param callable|null      $symlink_probe         Optional symlink-capability probe forwarded to FileWriter's fifth constructor parameter; null (default) uses the real, uninjected probe.
	 * @return RestoreRunner Ready to call restore() on.
	 */
	private function make_runner( ?FakeDbAdapter $db = null, bool $allow_unsafe_symlinks = false, ?callable $symlink_probe = null ): RestoreRunner {
		$db = $db ?? new FakeDbAdapter();
		return new RestoreRunner(
			new EntryReader( CodecRegistry::with_defaults() ),
			new FileWriter( $this->fixture_root, $allow_unsafe_symlinks, null, null, $symlink_probe ),
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
	 * Restore must finalise a cross-prefix rewrite after replaying every db_chunk.
	 *
	 * Once the walk is done the database tables exist with the destination prefix, so
	 * the runner asks the DatabaseWriter to rewrite the prefix embedded in the
	 * options/usermeta key columns — recorded here as a single rewrite call.
	 *
	 * @return void
	 */
	public function test_restore_finalises_the_prefix_rewrite(): void {
		$db     = new FakeDbAdapter();
		$runner = new RestoreRunner(
			new EntryReader( CodecRegistry::with_defaults() ),
			new FileWriter( $this->fixture_root ),
			new DatabaseWriter( $db, 'wp_', 'xyz_' )
		);

		$runner->restore(
			self::build_archive_stream(
				array( self::db_chunk_plan( 'wp_options', 1, "INSERT INTO `wp_options` VALUES (1);\n" ) )
			)
		);

		$this->assertSame( array( array( 'wp_', 'xyz_', 'pontifexstg_' ) ), $db->rewrite_calls() );
	}

	/**
	 * Verify must NOT finalise a prefix rewrite — it writes nothing to the database.
	 *
	 * @return void
	 */
	public function test_verify_does_not_finalise_the_prefix_rewrite(): void {
		$db     = new FakeDbAdapter();
		$runner = new RestoreRunner(
			new EntryReader( CodecRegistry::with_defaults() ),
			new FileWriter( $this->fixture_root ),
			new DatabaseWriter( $db, 'wp_', 'xyz_' )
		);

		$runner->verify(
			self::build_archive_stream(
				array( self::db_chunk_plan( 'wp_options', 1, "INSERT INTO `wp_options` VALUES (1);\n" ) )
			)
		);

		$this->assertSame( array(), $db->rewrite_calls(), 'verify() must not rewrite the prefix.' );
		$this->assertSame( array(), $db->executed_statements(), 'verify() must not execute any statement.' );
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
	 * Restore() actually sweeps orphaned temp files an earlier, interrupted restore left behind.
	 *
	 * A leftover temp artefact is placed under the fixture root exactly as a
	 * killed restore would have left one, before the runner is ever touched.
	 * Running a real restore through the same archive-stream harness every
	 * other test in this file uses, then asserting the artefact is gone
	 * afterwards, proves RestoreRunner::restore() actually calls
	 * FileWriter::sweep_orphaned_temp_files() as part of a real restore — not
	 * merely that the method exists and behaves correctly in isolation,
	 * which FileWriterTest already covers on its own.
	 *
	 * @return void
	 */
	public function test_restore_sweeps_orphaned_temp_files_left_by_an_earlier_interrupted_restore(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture setup.
		mkdir( $this->fixture_root . '/wp-content/uploads', 0o755, true );
		$orphan = $this->fixture_root . '/wp-content/uploads/photo.jpg.' . uniqid( 'pontifex-', true ) . '.tmp';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture setup.
		file_put_contents( $orphan, 'half-written bytes from a killed restore' );

		$runner = $this->make_runner();
		$runner->restore( self::build_archive_stream( array( self::file_plan( 'note.txt', 'hello world' ) ) ) );

		$this->assertFileDoesNotExist( $orphan, 'restore() must sweep an orphaned temp file left by an earlier, interrupted restore.' );
	}

	/**
	 * Verify() must NEVER sweep orphaned temp files — it is read-only.
	 *
	 * Adding a call to FileWriter::sweep_orphaned_temp_files() inside
	 * verify() was found, under mutation, to leave the whole suite green: a
	 * read-only verification silently deleting live files on disk is exactly
	 * the regression FileWriter::sweep_orphaned_temp_files()'s own docblock
	 * says never happens, because verify() "writes nothing and so has
	 * nothing to sweep". This places an orphan exactly as
	 * {@see self::test_restore_sweeps_orphaned_temp_files_left_by_an_earlier_interrupted_restore()}
	 * does, but calls verify() instead of restore() through the same real
	 * archive-stream harness every other test in this file uses, and asserts
	 * the orphan is still there afterwards.
	 *
	 * @return void
	 */
	public function test_verify_does_not_sweep_orphaned_temp_files(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture setup.
		mkdir( $this->fixture_root . '/wp-content/uploads', 0o755, true );
		$orphan = $this->fixture_root . '/wp-content/uploads/photo.jpg.' . uniqid( 'pontifex-', true ) . '.tmp';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture setup.
		file_put_contents( $orphan, 'half-written bytes from a killed restore' );

		$runner = $this->make_runner();
		$runner->verify( self::build_archive_stream( array( self::file_plan( 'note.txt', 'hello world' ) ) ) );

		$this->assertFileExists( $orphan, 'verify() must never sweep — it is read-only and must leave every file on disk untouched.' );
	}

	/**
	 * The orphan sweep must run BEFORE the free-space preflight, not after — proven behaviourally.
	 *
	 * A restore that ran out of disk can leave a large temp file behind; the
	 * NEXT restore's free-space preflight must see that space as already
	 * reclaimed, or the leftover from the failed attempt permanently counts
	 * against the retry it is blocking. Asserting call order would not prove
	 * that — a fake could record "sweep, then check" while the real check
	 * still measured a disk that had not actually been freed yet. Instead the
	 * injected free-space reader ({@see FileWriter}'s constructor takes it as
	 * a `Closure(string): (float|false)`) answers according to whether the
	 * orphan is STILL on disk at the moment it is actually asked: an
	 * insufficient figure while the orphan remains, standing in for the space
	 * it still occupies, and ample room once it is gone. A restore that
	 * proceeds and writes its entry therefore proves the sweep had already
	 * run by the time the free-space preflight asked — the same fixture, run
	 * against the ordering this change replaces (free space checked before
	 * the sweep), would instead see the orphan still occupying space and
	 * refuse with HostCannotComply.
	 *
	 * @return void
	 */
	public function test_restore_sweeps_the_orphan_before_the_free_space_preflight_measures_it(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture setup.
		mkdir( $this->fixture_root . '/wp-content/uploads', 0o755, true );
		$orphan = $this->fixture_root . '/wp-content/uploads/photo.jpg.' . uniqid( 'pontifex-', true ) . '.tmp';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture setup: stands in for the space a crashed restore's leftover temp file still occupies.
		file_put_contents( $orphan, 'half-written bytes from a killed restore, standing in for the disk space it still occupies' );

		$file_writer = new FileWriter(
			$this->fixture_root,
			false,
			null,
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Must match the injected Closure(string): (float|false) contract; this fake reader ignores which path is being asked about and instead answers according to whether the orphan is still on disk — insufficient while it remains, ample once it is swept.
			function ( string $path ) use ( $orphan ) {
				return file_exists( $orphan ) ? 1 : PHP_INT_MAX;
			}
		);
		$runner      = new RestoreRunner(
			new EntryReader( CodecRegistry::with_defaults() ),
			$file_writer,
			new DatabaseWriter( new FakeDbAdapter() )
		);

		$runner->restore( self::build_archive_stream( array( self::file_plan( 'note.txt', 'hello world' ) ) ) );

		$this->assertFileDoesNotExist( $orphan, 'The orphan must have been swept.' );
		$this->assertFileExists(
			$this->fixture_root . '/note.txt',
			'The restore must have got past the free-space preflight and written its entry; it could only have passed because the orphan was already gone by the time the preflight asked.'
		);
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
		$plans  = array( self::symlink_plan( 'wp-content/cache', '../uploads' ) );

		$runner->restore( self::build_archive_stream( $plans ) );

		$link = $this->fixture_root . '/wp-content/cache';
		$this->assertTrue( is_link( $link ) );
		$this->assertSame( '../uploads', readlink( $link ) );
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
		$this->assertCount( 3, $executed, 'Two replayed statements plus the atomic cut-over RENAME.' );
		$this->assertSame( 'CREATE TABLE `pontifexstg_wp_options` (id INT)', $executed[0] );
		$this->assertSame( 'INSERT INTO `pontifexstg_wp_options` VALUES (1)', $executed[1] );
		$this->assertSame( 'RENAME TABLE `pontifexstg_wp_options` TO `wp_options`', $executed[2] );
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
		// db_chunk on adapter: the staged replay plus the atomic cut-over RENAME.
		$this->assertCount( 2, $db->executed_statements() );
		$this->assertSame( 'CREATE TABLE `pontifexstg_wp_posts` (id INT)', $db->executed_statements()[0] );
		$this->assertSame( 'RENAME TABLE `pontifexstg_wp_posts` TO `wp_posts`', $db->executed_statements()[1] );
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
	 * The restore() byte callback fires as each entry's record streams through.
	 *
	 * @return void
	 */
	public function test_restore_reports_bytes_read(): void {
		$runner   = $this->make_runner();
		$plans    = array(
			self::file_plan( 'a.txt', 'apple' ),
			self::file_plan( 'b.txt', 'banana banana banana' ),
		);
		$reported = 0;

		$runner->restore(
			self::build_archive_stream( $plans ),
			null,
			static function ( int $bytes ) use ( &$reported ): void {
				$reported += $bytes;
			}
		);

		$this->assertGreaterThan( 0, $reported, 'restore forwards byte progress from each entry read.' );
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
	 * The verify() byte callback fires as each entry's record streams through.
	 *
	 * @return void
	 */
	public function test_verify_reports_bytes_read(): void {
		$runner   = $this->make_runner();
		$plans    = array(
			self::file_plan( 'a.txt', 'apple' ),
			self::file_plan( 'b.txt', 'banana banana banana' ),
		);
		$reported = 0;

		$runner->verify(
			self::build_archive_stream( $plans ),
			null,
			static function ( int $bytes ) use ( &$reported ): void {
				$reported += $bytes;
			}
		);

		$this->assertGreaterThan( 0, $reported, 'verify forwards byte progress from each entry read.' );
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
			// Allow the hostile escaping symlink to be planted, so this test
			// exercises the second-layer defence — a file write must replace the
			// symlink in place rather than follow it — independently of the
			// symlink-target refusal covered in FileWriterTest.
			$runner = $this->make_runner( allow_unsafe_symlinks: true );
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
	 * A host that cannot create symlinks must be refused before any entry is written — the wiring proof.
	 *
	 * Deleting the single `$this->file_writer->assert_symlinks_creatable(...)`
	 * call from RestoreRunner::restore() left the full suite green — nothing
	 * pinned that restore() calls the guard at all. A refusing probe with one
	 * declared symlink must both throw FileWriter's documented refusal and
	 * leave the file entry that follows it in the archive completely
	 * unwritten, which proves the preflight runs before the entry walk
	 * begins rather than merely existing somewhere in the class.
	 *
	 * @return void
	 */
	public function test_restore_refuses_before_any_write_when_host_cannot_create_symlinks(): void {
		$db     = new FakeDbAdapter();
		$runner = $this->make_runner(
			$db,
			false,
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Must match the injected Closure(string): bool contract; this fake probe ignores which directory is being asked about and always refuses.
			static function ( string $directory ): bool {
				return false;
			}
		);
		$plans = array(
			self::symlink_plan( 'link', 'target.txt' ),
			self::file_plan( 'somefile.txt', 'content' ),
		);

		$thrown = null;
		try {
			$runner->restore( self::build_archive_stream( $plans ) );
		} catch ( RuntimeException $error ) {
			$thrown = $error;
		}

		$this->assertInstanceOf( RuntimeException::class, $thrown );
		$this->assertStringContainsString( 'this host could not create a test link', $thrown->getMessage() );
		$this->assertFileDoesNotExist(
			$this->fixture_root . '/somefile.txt',
			'A restore refused for lacking symlink capability must not have written the file entry that follows the symlink in the archive.'
		);
		$this->assertSame( array(), $db->executed_statements() );
	}

	/**
	 * When both symlink preflights would refuse, the capability refusal wins — the ordering proof.
	 *
	 * RestoreRunner::restore() calls
	 * {@see FileWriter::assert_symlinks_creatable()} before
	 * {@see FileWriter::assert_symlink_targets_confined()}: there is no point
	 * judging whether an escaping target is SAFE on a host that could never
	 * create the link in the first place. An escaping target combined with a
	 * refusing probe must surface the "could not create a test symlink"
	 * message, never the "escapes the site" message.
	 *
	 * @return void
	 */
	public function test_restore_capability_refusal_wins_over_confinement_refusal(): void {
		$outside = sys_get_temp_dir() . '/pontifex-capability-order-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture: an out-of-root directory the archive's escaping target points at.
		mkdir( $outside, 0o755, true );

		try {
			$runner = $this->make_runner(
				null,
				false,
				// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Must match the injected Closure(string): bool contract; this fake probe ignores which directory is being asked about and always refuses.
				static function ( string $directory ): bool {
					return false;
				}
			);
			$plans = array( self::symlink_plan( 'breakout', $outside ) );

			$thrown = null;
			try {
				$runner->restore( self::build_archive_stream( $plans ) );
			} catch ( RuntimeException $error ) {
				$thrown = $error;
			}

			$this->assertInstanceOf( RuntimeException::class, $thrown );
			$message = $thrown->getMessage();
			$this->assertStringContainsString( 'this host could not create a test link', $message );
			$this->assertStringNotContainsString( 'escapes the site', $message );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.PHP.NoSilencedErrors.Discouraged -- Test cleanup of the out-of-root target.
			@rmdir( $outside );
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

	/**
	 * Build a RestoreRunner constrained by a runtime memory limit.
	 *
	 * @param int                $memory_limit_bytes The PHP memory limit in bytes (0 for unlimited).
	 * @param FakeDbAdapter|null $db                 Optional adapter; if null, a fresh one is created.
	 * @return RestoreRunner Ready to call restore() / verify() on.
	 */
	private function make_runner_with_memory_limit( int $memory_limit_bytes, ?FakeDbAdapter $db = null ): RestoreRunner {
		$db = $db ?? new FakeDbAdapter();
		return new RestoreRunner(
			new EntryReader( CodecRegistry::with_defaults() ),
			new FileWriter( $this->fixture_root ),
			new DatabaseWriter( $db ),
			null,
			$memory_limit_bytes
		);
	}

	/**
	 * Restoring must refuse a buffered entry whose declared size exceeds the memory budget.
	 *
	 * A 40-byte memory limit gives a 10-byte per-entry budget (a quarter); an 11-byte
	 * db_chunk — a shape the reader must buffer whole — is refused before it is decoded
	 * or dispatched, so a legitimately large chunk fails closed on a memory-constrained
	 * web request rather than OOM-fatalling mid-restore. The destination is untouched.
	 *
	 * @return void
	 */
	public function test_restore_refuses_a_buffered_entry_over_the_memory_budget(): void {
		$db     = new FakeDbAdapter();
		$runner = $this->make_runner_with_memory_limit( 40, $db );
		$plans  = array( self::db_chunk_plan( 'wp_options', 1, "INSERT INTO `wp_options` VALUES (1);\n" ) );

		$this->assert_refused(
			static fn () => $runner->restore( self::build_archive_stream( $plans ) ),
			$db
		);
	}

	/**
	 * A file entry over the memory budget must restore anyway — it streams.
	 *
	 * The memory budget exists to stop payload-sized allocations; a plain file
	 * entry never makes one (it spools and streams to disk, ADR 0010), so the
	 * same 11-byte file a 10-byte budget refused before now restores intact.
	 *
	 * @return void
	 */
	public function test_restore_streams_a_file_entry_over_the_memory_budget(): void {
		$runner = $this->make_runner_with_memory_limit( 40 );
		$plans  = array( self::file_plan( 'note.txt', 'hello world' ) );

		$runner->restore( self::build_archive_stream( $plans ) );

		$path = $this->fixture_root . '/note.txt';
		$this->assertTrue( file_exists( $path ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test assertion against on-disk fixture.
		$this->assertSame( 'hello world', file_get_contents( $path ) );
	}

	/**
	 * Verifying must refuse an over-budget buffered entry too, so the pre-write preview
	 * gate rejects a backup the real restore would refuse — before any restore begins.
	 *
	 * @return void
	 */
	public function test_verify_refuses_a_buffered_entry_over_the_memory_budget(): void {
		$runner = $this->make_runner_with_memory_limit( 40 );
		$plans  = array( self::db_chunk_plan( 'wp_options', 1, "INSERT INTO `wp_options` VALUES (1);\n" ) );

		$this->expectException( RuntimeException::class );

		$runner->verify( self::build_archive_stream( $plans ) );
	}

	/**
	 * Verifying must NOT refuse a file entry over the memory budget — it streams on restore.
	 *
	 * @return void
	 */
	public function test_verify_permits_a_file_entry_over_the_memory_budget(): void {
		$runner = $this->make_runner_with_memory_limit( 40 );
		$plans  = array( self::file_plan( 'note.txt', 'hello world' ) );

		$runner->verify( self::build_archive_stream( $plans ) );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * A restore comfortably within the memory budget must still succeed.
	 *
	 * @return void
	 */
	public function test_restore_within_the_memory_budget_succeeds(): void {
		// 1 MiB memory limit → 256 KiB per-entry budget; an 11-byte file is well within it.
		$runner = $this->make_runner_with_memory_limit( 1048576 );
		$plans  = array( self::file_plan( 'note.txt', 'hello world' ) );

		$runner->restore( self::build_archive_stream( $plans ) );

		$this->assertTrue( file_exists( $this->fixture_root . '/note.txt' ) );
	}

	/**
	 * An unlimited memory limit (0) applies no per-entry cap — the CLI escape hatch.
	 *
	 * The same 11-byte file that a 40-byte limit refuses restores here, because a
	 * CLI run (which reports memory_limit -1 → 0 bytes) is trusted to hold whatever
	 * the restore needs.
	 *
	 * @return void
	 */
	public function test_unlimited_memory_applies_no_per_entry_cap(): void {
		$runner = $this->make_runner_with_memory_limit( 0 );
		$plans  = array( self::file_plan( 'note.txt', 'hello world' ) );

		$runner->restore( self::build_archive_stream( $plans ) );

		$this->assertTrue( file_exists( $this->fixture_root . '/note.txt' ) );
	}

	/**
	 * The archive's charset from provenance must wrap the whole replay.
	 *
	 * @return void
	 */
	public function test_restore_flows_the_archive_charset_through_the_replay(): void {
		$db     = new FakeDbAdapter();
		$runner = $this->make_runner( $db );
		$plans  = array( self::db_chunk_plan( 'wp_options', 1, "CREATE TABLE `wp_options` (id INT);\n" ) );

		$runner->restore( self::build_archive_stream( $plans ) );

		$this->assertSame( array( 'utf8mb4', 'RESTORE' ), $db->charset_calls(), 'The provenance charset must be set before the replay and handed back after it.' );
	}

	/**
	 * A restore that fails mid-replay must abort staging and never cut over.
	 *
	 * The atomicity contract (ADR 0009): a database failure during the replay
	 * leaves the live tables untouched, because every statement ran against
	 * staging tables — which are then dropped. No RENAME may appear in the
	 * executed statements, and the failure propagates to the caller.
	 *
	 * @return void
	 */
	public function test_failed_restore_aborts_staging_and_never_renames(): void {
		$db     = new FakeDbAdapter();
		$runner = $this->make_runner( $db );
		$plans  = array(
			self::db_chunk_plan( 'wp_options', 1, "CREATE TABLE `wp_options` (id INT);\n" ),
			self::db_chunk_plan( 'wp_posts', 1, "CREATE TABLE `wp_posts` (id INT);\n" ),
		);
		// The first chunk replays; the second chunk's statement fails.
		$db->fail_after_executes( 1, 'simulated mid-replay failure' );

		try {
			$runner->restore( self::build_archive_stream( $plans ) );
			$this->fail( 'restore() should propagate the mid-replay failure.' );
		} catch ( RuntimeException $failure ) {
			$this->assertSame( 'simulated mid-replay failure', $failure->getMessage() );
		}

		$executed = $db->executed_statements();
		foreach ( $executed as $statement ) {
			$this->assertStringNotContainsString( 'RENAME TABLE', $statement, 'A failed restore must never reach the cut-over.' );
		}
		$this->assertContains( 'DROP TABLE IF EXISTS `pontifexstg_wp_options`', $executed, 'The staged table must be dropped by the abort.' );
	}

	// -------------------------------------------------------------------
	// The disk-space preflight — restore() consults it, verify() never does
	// -------------------------------------------------------------------

	/**
	 * The restore() call reads free disk space before writing any entry, and the entry is written once that reading permits it.
	 *
	 * FileWriter is final, so this cannot be proven by spying on a mock; instead
	 * the injected free-space reader itself checks, as a side effect of being
	 * called, whether the entry it is about to be asked to permit has already
	 * landed on disk. If RestoreRunner ever called the preflight late — after
	 * dispatching even the first entry — that check would observe the file
	 * already there and this test would fail. The reader then returns ample
	 * space, so the restore proceeds and the entry is confirmed written
	 * afterwards, proving the call is a real, load-bearing part of a working
	 * restore(), not a dead path.
	 *
	 * @return void
	 */
	public function test_restore_consults_disk_space_before_writing_any_entry(): void {
		$note_path              = $this->fixture_root . '/note.txt';
		$file_did_not_exist_yet = null;

		$file_writer = new FileWriter(
			$this->fixture_root,
			false,
			null,
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Must match the injected Closure(string): (float|false) contract; this fake reader ignores which path is being asked about and instead checks the fixture filesystem directly.
			function ( string $path ) use ( $note_path, &$file_did_not_exist_yet ) {
				$file_did_not_exist_yet = ! file_exists( $note_path );
				return PHP_INT_MAX;
			}
		);
		$runner      = new RestoreRunner(
			new EntryReader( CodecRegistry::with_defaults() ),
			$file_writer,
			new DatabaseWriter( new FakeDbAdapter() )
		);

		$runner->restore( self::build_archive_stream( array( self::file_plan( 'note.txt', 'hello world' ) ) ) );

		$this->assertTrue( $file_did_not_exist_yet, 'The free-space reading must be taken before any entry is written.' );
		$this->assertFileExists( $note_path, 'The entry must still be written once the preflight permits it.' );
	}

	/**
	 * The verify() call never consults the disk-space preflight — it writes nothing, so there is nothing to preflight.
	 *
	 * The injected free-space reader would refuse outright if it were ever
	 * called (it reports 0 bytes free), so if verify() incorrectly called
	 * FileWriter::assert_free_space_for(), this call would throw. It is also
	 * tracked directly, so the assertion below is meaningful even if some
	 * future change made a 0-byte reading non-refusing.
	 *
	 * @return void
	 */
	public function test_verify_never_consults_disk_space(): void {
		$disk_space_was_consulted = false;

		$file_writer = new FileWriter(
			$this->fixture_root,
			false,
			null,
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Must match the injected Closure(string): (float|false) contract; this fake reader ignores which path is being asked about and only records that it was called at all.
			function ( string $path ) use ( &$disk_space_was_consulted ) {
				$disk_space_was_consulted = true;
				return 0;
			}
		);
		$runner      = new RestoreRunner(
			new EntryReader( CodecRegistry::with_defaults() ),
			$file_writer,
			new DatabaseWriter( new FakeDbAdapter() )
		);

		$runner->verify( self::build_archive_stream( array( self::file_plan( 'note.txt', 'hello world' ) ) ) );

		$this->assertFalse( $disk_space_was_consulted, 'verify() must never consult the disk-space reader.' );
	}

	// -------------------------------------------------------------------
	// The whole-archive symlink preflight, driven through a real archive
	// -------------------------------------------------------------------

	/**
	 * The entry plans for the proven wp-config.php leak, with an ordinary file in front of them.
	 *
	 * The file entry comes first on purpose. It is what makes "the preflight ran
	 * before the walk" observable: if the symlink decision were still being made
	 * entry by entry, this file would already be on disk by the time the archive
	 * was refused, and the site would be part-restored.
	 *
	 * @return EntryPlan[] The plans, in archive order.
	 */
	private static function hostile_symlink_plans(): array {
		return array(
			self::file_plan( 'wp-content/uploads/innocent.txt', 'written before any refusal?' ),
			self::symlink_plan( 'wp-content/uploads/hop', '..' ),
			self::symlink_plan( 'wp-content/uploads/leak.txt', 'hop/../wp-config.php' ),
		);
	}

	/**
	 * A restore of the hop-pair archive is refused with the destination completely untouched.
	 *
	 * The end-to-end proof of the preflight: a real archive, built by the real
	 * writer, read by the real reader. The refusal must arrive before the walk
	 * starts, so neither the hostile links nor the innocent file that precedes
	 * them in the archive may appear on disk. This test captures the exception
	 * rather than using expectException() because it asserts about the
	 * filesystem AFTER the throw; there is deliberately no self::fail() inside
	 * the try, since PHPUnit's own failure exception would be swallowed by the
	 * catch.
	 *
	 * @return void
	 */
	public function test_restore_refuses_the_hop_attack_before_writing_anything(): void {
		$db     = new FakeDbAdapter();
		$runner = $this->make_runner( $db );
		$thrown = null;

		try {
			$runner->restore( self::build_archive_stream( self::hostile_symlink_plans() ) );
		} catch ( RuntimeException $error ) {
			$thrown = $error;
		}

		$this->assertInstanceOf( RuntimeException::class, $thrown );
		$this->assertStringContainsString( "this site's own wp-config.php", $thrown->getMessage() );
		$this->assertFileDoesNotExist( $this->fixture_root . '/wp-content/uploads/innocent.txt', 'The refusal must come before the walk writes anything at all.' );
		$this->assertFalse( is_link( $this->fixture_root . '/wp-content/uploads/hop' ) );
		$this->assertFalse( is_link( $this->fixture_root . '/wp-content/uploads/leak.txt' ) );
		$this->assertSame( array(), $db->executed_statements(), 'A refused archive must not execute any SQL.' );
	}

	/**
	 * The verify() walk never runs the symlink preflight, because it writes nothing.
	 *
	 * Verify's job is to answer "are these bytes intact", and it creates no
	 * links, so there is no target to confine. Running the preflight there would
	 * turn a read-only integrity check into a refusal, telling an operator their
	 * archive is broken when what it actually is is hostile — a different
	 * question, answered by attempting the restore.
	 *
	 * @return void
	 */
	public function test_verify_does_not_run_the_symlink_preflight(): void {
		$runner = $this->make_runner();

		$runner->verify( self::build_archive_stream( self::hostile_symlink_plans() ) );

		$this->assertFileDoesNotExist( $this->fixture_root . '/wp-content/uploads/innocent.txt', 'verify() must write nothing.' );
		$this->assertFalse( is_link( $this->fixture_root . '/wp-content/uploads/leak.txt' ), 'verify() must create no links.' );
	}

	/**
	 * A Composer-shaped site's own archive restores, links intact, with no flag and no warning.
	 *
	 * The false-refusal gate, end to end. A Composer-managed WordPress keeps its
	 * dependencies beside wp-content and reaches them by link, and the scanner
	 * records those links verbatim — so this is what that site's OWN backup
	 * looks like. Refusing it would make the site's backup unrestorable with no
	 * attacker involved, which is what reverted an earlier attempt at this
	 * guard. The targets deliberately do not exist on the destination, which is
	 * also the migration case: the rule asks where a target resolves, never
	 * whether it is already there.
	 *
	 * @return void
	 */
	public function test_restore_permits_a_composer_shaped_symlink_layout(): void {
		$runner = $this->make_runner();
		$plans  = array(
			self::symlink_plan( 'wp-content/languages', '../languages' ),
			self::symlink_plan( 'wp-content/mu-plugins/autoload.php', '../../vendor/acme/lib/autoload.php' ),
			self::symlink_plan( 'wp-content/uploads/alias', '2026' ),
		);

		$runner->restore( self::build_archive_stream( $plans ) );

		$this->assertSame( '../languages', readlink( $this->fixture_root . '/wp-content/languages' ) );
		$this->assertSame( '../../vendor/acme/lib/autoload.php', readlink( $this->fixture_root . '/wp-content/mu-plugins/autoload.php' ) );
		$this->assertSame( '2026', readlink( $this->fixture_root . '/wp-content/uploads/alias' ) );
	}
}
