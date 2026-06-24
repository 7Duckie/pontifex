<?php
/**
 * Unit tests for the FileWriter class.
 *
 * @package Pontifex\Tests\Unit\Restore
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Restore;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Reader\EntryReadResult;
use Pontifex\Restore\FileWriter;

/**
 * Behavioural tests for {@see FileWriter}.
 *
 * Each test builds an ephemeral fixture root under sys_get_temp_dir()
 * and exercises one FileWriter behaviour. The fixture root is NOT
 * pre-created in setUp — several constructor tests verify that
 * FileWriter creates the root when it doesn't already exist, and
 * those tests need to start from a non-existent path. Tests that
 * want the root pre-created either let the constructor handle it or
 * mkdir() it explicitly.
 *
 * Test strategy: real filesystem operations under a fresh tempdir,
 * no mocks. FileWriter's job IS filesystem behaviour, so mocking the
 * filesystem would test nothing of value. The fixture pattern (and
 * the rmtree teardown helper) follows the same shape as
 * FileScannerTest, including the chmod-restore step before recursive
 * deletion to handle modes lower than 0o755 that some tests set.
 *
 * Two FileWriter defences are intentionally NOT exercised here
 * because they cannot be reached through the public EntryHeader API:
 *
 *  - The "empty path" branch in resolve_safe_path() — EntryHeader's
 *    for_file, for_directory, and for_symlink factories all reject
 *    empty paths at construction. No valid EntryReadResult can carry
 *    an empty path. Same applies to the "empty target" branch in
 *    EntryHeader::for_symlink versus the equivalent check.
 *  - The "unsupported entry kind" branch at the end of write_entry()
 *    — EntryHeader's from_canonical_data and the four kind-specific
 *    factories enforce that kind is one of exactly four values, and
 *    each has a matching predicate (is_file, is_directory,
 *    is_symlink, is_db_chunk).
 *
 * Both branches are defence-in-depth and could be reached only via
 * reflection that bypasses EntryHeader's constructor. A test built
 * that way would couple tightly to EntryHeader's internal layout and
 * would have to change every time EntryHeader is touched; the safer
 * answer is to leave the branches in the source (in case EntryHeader's
 * validation ever weakens) and not test them through the public API.
 */
final class FileWriterTest extends TestCase {

	/**
	 * Absolute path to the fixture root for the current test.
	 *
	 * Generated in setUp but NOT created on disk — the directory is
	 * built either by FileWriter's constructor (most tests) or by
	 * the test itself when it needs a specific pre-state.
	 *
	 * @var string
	 */
	private string $fixture_root;

	/**
	 * Generate a fresh fixture root path before each test.
	 *
	 * The directory is not actually created here. setUp's job is to
	 * make sure each test starts with a unique, unused path.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->fixture_root = sys_get_temp_dir() . '/pontifex-filewriter-test-' . bin2hex( random_bytes( 8 ) );
	}

	/**
	 * Remove the fixture root recursively after each test, if it exists.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ( is_dir( $this->fixture_root ) || is_link( $this->fixture_root ) ) {
			self::rmtree( $this->fixture_root );
		}
		parent::tearDown();
	}

	/**
	 * Recursively delete a directory and its contents.
	 *
	 * Restores readable-and-writable mode on each child before
	 * descending, so that tests which set restrictive modes don't
	 * leave undeletable trees behind.
	 *
	 * @param string $path Absolute path to remove.
	 * @return void
	 */
	private static function rmtree( string $path ): void {
		if ( is_link( $path ) || ! is_dir( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Test fixture teardown; WP_Filesystem is not available in PHPUnit context, and the silenced error is intentional best-effort cleanup.
			@unlink( $path );
			return;
		}
		foreach ( scandir( $path ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$child = $path . '/' . $entry;
			if ( ! is_link( $child ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Test teardown best-effort cleanup; failure is non-fatal.
				@chmod( $child, 0o755 );
			}
			self::rmtree( $child );
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test teardown best-effort cleanup.
		@rmdir( $path );
	}

	/**
	 * Build an EntryReadResult for a file entry with the given path and contents.
	 *
	 * @param string $path     Relative path inside the archive.
	 * @param string $contents File contents.
	 * @param int    $mode     POSIX mode for the restored file.
	 * @param int    $mtime    Modification timestamp for the restored file.
	 * @return EntryReadResult Ready to feed to FileWriter::write_entry.
	 */
	private static function file_result( string $path, string $contents, int $mode = 0o644, int $mtime = 1690000000 ): EntryReadResult {
		$header = EntryHeader::for_file( $path, strlen( $contents ), $mode, $mtime, 'application/octet-stream', 0 );
		return new EntryReadResult( $header, $contents );
	}

	/**
	 * Build an EntryReadResult for a directory entry.
	 *
	 * @param string $path Relative path inside the archive.
	 * @param int    $mode POSIX mode for the restored directory.
	 * @return EntryReadResult Ready to feed to FileWriter::write_entry.
	 */
	private static function directory_result( string $path, int $mode = 0o755 ): EntryReadResult {
		$header = EntryHeader::for_directory( $path, $mode, 0 );
		return new EntryReadResult( $header, '' );
	}

	/**
	 * Build an EntryReadResult for a symlink entry.
	 *
	 * @param string $path   Relative path of the link inside the archive.
	 * @param string $target The string the link should point at; stored verbatim.
	 * @return EntryReadResult Ready to feed to FileWriter::write_entry.
	 */
	private static function symlink_result( string $path, string $target ): EntryReadResult {
		$header = EntryHeader::for_symlink( $path, $target, 0 );
		return new EntryReadResult( $header, '' );
	}

	/**
	 * Build an EntryReadResult for a db_chunk entry.
	 *
	 * Used only by the db_chunk rejection test; FileWriter must
	 * refuse this kind because db_chunks go through DatabaseWriter.
	 *
	 * @param string $table_name      Source table name.
	 * @param int    $statement_count Number of statements in the chunk.
	 * @param string $sql             SQL bytes.
	 * @return EntryReadResult Ready to feed to FileWriter::write_entry.
	 */
	private static function db_chunk_result( string $table_name, int $statement_count, string $sql ): EntryReadResult {
		$header = EntryHeader::for_db_chunk( 0, $table_name, $statement_count, strlen( $sql ), 0 );
		return new EntryReadResult( $header, $sql );
	}

	// -------------------------------------------------------------------
	// Constructor tests
	// -------------------------------------------------------------------

	/**
	 * Constructor rejects an empty destination_root.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_empty_destination_root(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'non-empty' );

		new FileWriter( '' );
	}

	/**
	 * Constructor rejects a relative destination_root.
	 *
	 * Restore-time path safety relies on the destination being an
	 * absolute path so the joined target path is unambiguous.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_relative_destination_root(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'absolute' );

		new FileWriter( 'relative/path/that/does/not/exist' );
	}

	/**
	 * Constructor creates the destination_root if it doesn't yet exist.
	 *
	 * @return void
	 */
	public function test_constructor_creates_destination_root_when_missing(): void {
		$this->assertDirectoryDoesNotExist( $this->fixture_root );

		new FileWriter( $this->fixture_root );

		$this->assertDirectoryExists( $this->fixture_root );
	}

	/**
	 * Constructor accepts a pre-existing destination_root, including with a trailing slash.
	 *
	 * Verifies that (a) the constructor doesn't throw when the
	 * directory already exists, and (b) passing a trailing slash on
	 * the destination doesn't break subsequent write operations. The
	 * actual internal normalisation (rtrim of the stored path) is not
	 * directly observable from outside the class — POSIX treats // and
	 * / as equivalent, so the joined path resolves to the right file
	 * either way — but verifying the writer remains usable is the
	 * contract that matters to callers.
	 *
	 * @return void
	 */
	public function test_constructor_accepts_pre_existing_destination_with_trailing_slash(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture setup; WP_Filesystem is not available in PHPUnit context.
		mkdir( $this->fixture_root, 0o755, true );

		$writer = new FileWriter( $this->fixture_root . '/' );

		// Verify the writer is usable after a trailing-slash destination:
		// writing an entry succeeds and lands at the expected path.
		$writer->write_entry( self::file_result( 'note.txt', 'data' ) );
		$this->assertFileExists( $this->fixture_root . '/note.txt' );
	}

	// -------------------------------------------------------------------
	// write_entry dispatch
	// -------------------------------------------------------------------

	/**
	 * Rejects db_chunk entries — those go through DatabaseWriter.
	 *
	 * @return void
	 */
	public function test_write_entry_rejects_db_chunk(): void {
		$writer = new FileWriter( $this->fixture_root );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'DatabaseWriter' );

		$writer->write_entry( self::db_chunk_result( 'wp_options', 1, 'CREATE TABLE `wp_options` (id INT);' ) );
	}

	// -------------------------------------------------------------------
	// Path-traversal defence
	// -------------------------------------------------------------------

	/**
	 * Path with a null byte is rejected.
	 *
	 * Null bytes can confuse PHP's filesystem layer in C-string
	 * boundaries; the defence rejects them outright.
	 *
	 * @return void
	 */
	public function test_write_entry_rejects_path_with_null_byte(): void {
		$writer = new FileWriter( $this->fixture_root );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'null byte' );

		$writer->write_entry( self::file_result( "foo\0bar.txt", 'data' ) );
	}

	/**
	 * POSIX-absolute path is rejected.
	 *
	 * A correctness archive always carries relative paths from its
	 * own root; an absolute path here indicates either a crafted
	 * malicious archive or a writer bug.
	 *
	 * @return void
	 */
	public function test_write_entry_rejects_posix_absolute_path(): void {
		$writer = new FileWriter( $this->fixture_root );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'relative' );

		$writer->write_entry( self::file_result( '/etc/passwd', 'malicious' ) );
	}

	/**
	 * Windows-style absolute path (drive letter form) is rejected.
	 *
	 * Defence-in-depth even on POSIX hosts: a cross-host archive
	 * carrying Windows paths must not be able to write outside the
	 * destination root regardless of the host filesystem.
	 *
	 * @return void
	 */
	public function test_write_entry_rejects_windows_absolute_path(): void {
		$writer = new FileWriter( $this->fixture_root );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'relative' );

		$writer->write_entry( self::file_result( 'C:\\Windows\\System32\\foo.dll', 'malicious' ) );
	}

	/**
	 * Path containing a parent-directory segment is rejected.
	 *
	 * The canonical form of an archive-escape attempt.
	 *
	 * @return void
	 */
	public function test_write_entry_rejects_parent_directory_segment(): void {
		$writer = new FileWriter( $this->fixture_root );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'parent-directory segment' );

		$writer->write_entry( self::file_result( '../escape.txt', 'malicious' ) );
	}

	/**
	 * Backslash-disguised parent-directory segment is also rejected.
	 *
	 * The defence normalises backslashes to forward slashes before
	 * the ".." segment check, so a Windows-shaped path like
	 * "foo\..\escape.txt" doesn't slip past on a POSIX host.
	 *
	 * @return void
	 */
	public function test_write_entry_rejects_backslash_disguised_parent_segment(): void {
		$writer = new FileWriter( $this->fixture_root );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'parent-directory segment' );

		$writer->write_entry( self::file_result( 'foo\\..\\escape.txt', 'malicious' ) );
	}

	// -------------------------------------------------------------------
	// File entry behaviour
	// -------------------------------------------------------------------

	/**
	 * File entries are written with the correct contents.
	 *
	 * @return void
	 */
	public function test_file_entry_written_with_correct_contents(): void {
		$writer = new FileWriter( $this->fixture_root );

		$writer->write_entry( self::file_result( 'note.txt', 'hello world' ) );

		$this->assertFileExists( $this->fixture_root . '/note.txt' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test assertion against on-disk fixture.
		$this->assertSame( 'hello world', file_get_contents( $this->fixture_root . '/note.txt' ) );
	}

	/**
	 * File entries have their POSIX mode applied after writing.
	 *
	 * Uses 0o600 so the assertion is visibly distinct from common
	 * defaults (0o644 / 0o664).
	 *
	 * @return void
	 */
	public function test_file_entry_written_with_correct_mode(): void {
		$writer = new FileWriter( $this->fixture_root );

		$writer->write_entry( self::file_result( 'secret.txt', 'data', 0o600 ) );

		$path = $this->fixture_root . '/secret.txt';
		clearstatcache( true, $path );
		$mode = fileperms( $path ) & 0o7777;
		$this->assertSame( 0o600, $mode );
	}

	/**
	 * File entries have their mtime applied after writing.
	 *
	 * The mtime is set last in the write sequence (write → chmod →
	 * touch) because file_put_contents updates mtime as a side effect.
	 *
	 * Asserts the resulting filemtime is within 1 second of the
	 * requested value rather than strictly equal. Filesystem mtime
	 * precision varies between operating systems and filesystem types
	 * (APFS, HFS+, ext4, NTFS); some round to the next tick when an
	 * integer second is passed through the nanosecond-precision mtime
	 * field. The point of the test is that touch() actually ran with
	 * the requested mtime — a "now" timestamp would indicate the
	 * touch was bypassed — not that the filesystem stored the value
	 * with bit-exact fidelity. ±1 second proves the touch ran.
	 *
	 * @return void
	 */
	public function test_file_entry_written_with_correct_mtime(): void {
		$writer = new FileWriter( $this->fixture_root );
		$mtime  = 1690000000;

		$writer->write_entry( self::file_result( 'time.txt', 'data', 0o644, $mtime ) );

		$path = $this->fixture_root . '/time.txt';
		clearstatcache( true, $path );
		$actual = filemtime( $path );

		// Filesystem mtime precision varies; allow ±1 second tolerance.
		// A "now"-ish timestamp would indicate touch() didn't run.
		$this->assertGreaterThanOrEqual( $mtime, $actual );
		$this->assertLessThanOrEqual( $mtime + 1, $actual );
	}

	/**
	 * A file entry's mode is clamped: setuid and world-write are stripped.
	 *
	 * An archive is attacker-controlled on the import trust boundary, so a mode
	 * like 0o4666 (setuid + world-writable) must not be applied verbatim. The
	 * special bits and the world-write bit are stripped; owner/group bits survive,
	 * so 0o4666 becomes 0o0664.
	 *
	 * @return void
	 */
	public function test_file_entry_mode_is_clamped(): void {
		$writer = new FileWriter( $this->fixture_root );

		$writer->write_entry( self::file_result( 'danger.txt', 'data', 0o4666 ) );

		$path = $this->fixture_root . '/danger.txt';
		clearstatcache( true, $path );
		$this->assertSame( 0o0664, fileperms( $path ) & 0o7777, 'setuid and world-write must be stripped' );
	}

	/**
	 * Writing a file entry to a path that already exists replaces the file.
	 *
	 * @return void
	 */
	public function test_file_entry_overwrites_existing_file(): void {
		$writer = new FileWriter( $this->fixture_root );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture setup.
		file_put_contents( $this->fixture_root . '/note.txt', 'old content' );

		$writer->write_entry( self::file_result( 'note.txt', 'new content' ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test assertion against on-disk fixture.
		$this->assertSame( 'new content', file_get_contents( $this->fixture_root . '/note.txt' ) );
	}

	// -------------------------------------------------------------------
	// Directory entry behaviour
	// -------------------------------------------------------------------

	/**
	 * Directory entries are created with the requested mode.
	 *
	 * @return void
	 */
	public function test_directory_entry_created_with_correct_mode(): void {
		$writer = new FileWriter( $this->fixture_root );

		$writer->write_entry( self::directory_result( 'subdir', 0o700 ) );

		$path = $this->fixture_root . '/subdir';
		$this->assertDirectoryExists( $path );
		clearstatcache( true, $path );
		$mode = fileperms( $path ) & 0o7777;
		$this->assertSame( 0o700, $mode );
	}

	/**
	 * A directory entry's mode is clamped: setgid and world-write are stripped.
	 *
	 * 0o2777 (setgid + world-writable) becomes 0o0775.
	 *
	 * @return void
	 */
	public function test_directory_entry_mode_is_clamped(): void {
		$writer = new FileWriter( $this->fixture_root );

		$writer->write_entry( self::directory_result( 'shared', 0o2777 ) );

		$path = $this->fixture_root . '/shared';
		$this->assertDirectoryExists( $path );
		clearstatcache( true, $path );
		$this->assertSame( 0o0775, fileperms( $path ) & 0o7777, 'setgid and world-write must be stripped' );
	}

	/**
	 * A directory entry whose path already exists as a directory updates the mode.
	 *
	 * Class docblock states: "Idempotent: if the directory already
	 * exists, its mode is updated to match." The chmod call runs
	 * unconditionally after the is_dir check.
	 *
	 * @return void
	 */
	public function test_pre_existing_directory_has_mode_updated(): void {
		$writer = new FileWriter( $this->fixture_root );
		$path   = $this->fixture_root . '/existing';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture setup.
		mkdir( $path, 0o755, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Test fixture setup; explicit mode required because mkdir is umask-affected.
		chmod( $path, 0o700 );
		clearstatcache( true, $path );
		$this->assertSame( 0o700, fileperms( $path ) & 0o7777, 'precondition: directory should start at 0o700' );

		$writer->write_entry( self::directory_result( 'existing', 0o755 ) );

		clearstatcache( true, $path );
		$this->assertSame( 0o755, fileperms( $path ) & 0o7777 );
	}

	/**
	 * A directory entry whose path is already occupied by a file fails cleanly.
	 *
	 * The mkdir call fails because the file blocks creation; the
	 * second is_dir check stays false; the writer throws "could not
	 * create directory". Critically, FileWriter does NOT proceed to
	 * chmod the file, which would be a confused-deputy bug.
	 *
	 * @return void
	 */
	public function test_directory_entry_refuses_when_path_is_a_file(): void {
		$writer = new FileWriter( $this->fixture_root );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture setup.
		file_put_contents( $this->fixture_root . '/conflict', 'i am a file, not a directory' );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'could not create directory' );

		$writer->write_entry( self::directory_result( 'conflict', 0o755 ) );
	}

	// -------------------------------------------------------------------
	// Symlink entry behaviour
	// -------------------------------------------------------------------

	/**
	 * With --allow-unsafe-symlinks, an absolute target is stored verbatim.
	 *
	 * The override restores the old behaviour: the target is written as-is from
	 * the archive, escaping or not.
	 *
	 * @return void
	 */
	public function test_symlink_entry_created_with_verbatim_target_when_unsafe_allowed(): void {
		$writer = new FileWriter( $this->fixture_root, true );
		$target = '/some/absolute/path/that/may/not/exist';

		$writer->write_entry( self::symlink_result( 'link', $target ) );

		$link = $this->fixture_root . '/link';
		$this->assertTrue( is_link( $link ) );
		$this->assertSame( $target, readlink( $link ) );
	}

	/**
	 * A safe relative target (staying inside the root) is created by default.
	 *
	 * @return void
	 */
	public function test_safe_relative_symlink_created_by_default(): void {
		$writer = new FileWriter( $this->fixture_root );

		$writer->write_entry( self::symlink_result( 'a/link', '../b/target.txt' ) );

		$link = $this->fixture_root . '/a/link';
		$this->assertTrue( is_link( $link ) );
		$this->assertSame( '../b/target.txt', readlink( $link ) );
	}

	/**
	 * An absolute symlink target is refused by default.
	 *
	 * @return void
	 */
	public function test_absolute_symlink_target_refused_by_default(): void {
		$writer = new FileWriter( $this->fixture_root );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'escapes the restore root' );

		$writer->write_entry( self::symlink_result( 'link', '/etc/passwd' ) );
	}

	/**
	 * A relative symlink target that escapes the root is refused by default.
	 *
	 * @return void
	 */
	public function test_escaping_relative_symlink_target_refused_by_default(): void {
		$writer = new FileWriter( $this->fixture_root );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'escapes the restore root' );

		$writer->write_entry( self::symlink_result( 'uploads/link', '../../../../etc/passwd' ) );
	}

	/**
	 * The escaping target is allowed when --allow-unsafe-symlinks is set.
	 *
	 * @return void
	 */
	public function test_escaping_symlink_target_allowed_with_override(): void {
		$writer = new FileWriter( $this->fixture_root, true );

		$writer->write_entry( self::symlink_result( 'link', '/etc/passwd' ) );

		$this->assertTrue( is_link( $this->fixture_root . '/link' ) );
	}

	/**
	 * Symlink entries overwrite a pre-existing file at the link path.
	 *
	 * @return void
	 */
	public function test_symlink_overwrites_pre_existing_file(): void {
		$writer   = new FileWriter( $this->fixture_root );
		$conflict = $this->fixture_root . '/conflict';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture setup.
		file_put_contents( $conflict, 'i am a file' );
		$this->assertFileExists( $conflict );
		$this->assertFalse( is_link( $conflict ), 'precondition: conflict should start as a regular file, not a link' );

		$writer->write_entry( self::symlink_result( 'conflict', 'elsewhere' ) );

		$this->assertTrue( is_link( $conflict ) );
		$this->assertSame( 'elsewhere', readlink( $conflict ) );
	}

	/**
	 * Symlink entries overwrite a pre-existing symlink at the link path.
	 *
	 * @return void
	 */
	public function test_symlink_overwrites_pre_existing_symlink(): void {
		$writer = new FileWriter( $this->fixture_root );
		$link   = $this->fixture_root . '/link';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_symlink -- Test fixture setup; symlink behaviour is the subject under test.
		symlink( '/old/target', $link );
		$this->assertSame( '/old/target', readlink( $link ), 'precondition: link should start pointing at /old/target' );

		$writer->write_entry( self::symlink_result( 'link', 'new-target' ) );

		$this->assertTrue( is_link( $link ) );
		$this->assertSame( 'new-target', readlink( $link ) );
	}

	// -------------------------------------------------------------------
	// Parent-directory creation
	// -------------------------------------------------------------------

	/**
	 * Parent directories are created automatically for nested entry paths.
	 *
	 * @return void
	 */
	public function test_parent_directories_created_automatically(): void {
		$writer = new FileWriter( $this->fixture_root );

		$writer->write_entry( self::file_result( 'a/b/c/deep.txt', 'nested' ) );

		$this->assertDirectoryExists( $this->fixture_root . '/a' );
		$this->assertDirectoryExists( $this->fixture_root . '/a/b' );
		$this->assertDirectoryExists( $this->fixture_root . '/a/b/c' );
		$this->assertFileExists( $this->fixture_root . '/a/b/c/deep.txt' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test assertion against on-disk fixture.
		$this->assertSame( 'nested', file_get_contents( $this->fixture_root . '/a/b/c/deep.txt' ) );
	}

	/**
	 * Pre-existing parent directories are NOT chmod'd by parent-directory creation.
	 *
	 * The ensure_parent_directory() helper returns early when the
	 * parent already exists. The chmod-the-pre-existing-dir
	 * behaviour is specific to a directory ENTRY being written; it
	 * does not apply to parents created opportunistically on behalf
	 * of a file entry.
	 *
	 * @return void
	 */
	public function test_pre_existing_parent_directory_mode_is_not_changed(): void {
		$writer = new FileWriter( $this->fixture_root );
		$parent = $this->fixture_root . '/parent';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture setup.
		mkdir( $parent, 0o755, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Test fixture setup; explicit mode required because mkdir is umask-affected.
		chmod( $parent, 0o750 );
		clearstatcache( true, $parent );
		$this->assertSame( 0o750, fileperms( $parent ) & 0o7777, 'precondition: parent should start at 0o750' );

		// Write a file inside the parent; the writer needs the parent
		// but should not modify it because it already exists.
		$writer->write_entry( self::file_result( 'parent/child.txt', 'data' ) );

		clearstatcache( true, $parent );
		$this->assertSame( 0o750, fileperms( $parent ) & 0o7777 );
	}

	// -------------------------------------------------------------------
	// Cross-defence composition
	// -------------------------------------------------------------------

	/**
	 * A file entry whose parent doesn't exist gets the parent created and the file written.
	 *
	 * Verifies that resolve_safe_path, ensure_parent_directory, and
	 * write_file compose correctly for the common case of a deeply
	 * nested archive entry landing in a fresh destination root.
	 *
	 * @return void
	 */
	public function test_file_with_missing_parents_is_written_correctly(): void {
		$writer = new FileWriter( $this->fixture_root );

		$writer->write_entry( self::file_result( 'wp-content/uploads/2026/05/photo.jpg', 'image-bytes' ) );

		$path = $this->fixture_root . '/wp-content/uploads/2026/05/photo.jpg';
		$this->assertFileExists( $path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test assertion against on-disk fixture.
		$this->assertSame( 'image-bytes', file_get_contents( $path ) );
	}
}
