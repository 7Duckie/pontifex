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
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Format\ManifestEntry;
use Pontifex\Archive\Reader\EntryReadResult;
use Pontifex\Manifest\FileScanner;
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
 *  - The "empty path" branch in resolve_safe_path() that catches a RAW
 *    empty string — EntryHeader's for_file, for_directory, and
 *    for_symlink factories all reject empty paths at construction, and
 *    normalise_entry_path() independently refuses a raw empty path
 *    before resolve_safe_path() is ever reached from write_entry(). No
 *    valid EntryReadResult can carry a raw-empty path. Same applies to
 *    the "empty target" branch in EntryHeader::for_symlink versus the
 *    equivalent check. (This is distinct from a path that COLLAPSES to
 *    empty, such as "." or "./" — that IS reachable through the public
 *    API and IS exercised; see the "A path normalising to empty"
 *    section below.)
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

	/**
	 * Build a ManifestEntry for a file entry with the given path and stored length.
	 *
	 * Unlike {@see self::file_result()}, this goes through
	 * {@see ManifestEntry::for_file()} directly rather than EntryHeader, and
	 * ManifestEntry validates only that the path is non-empty — so, deliberately,
	 * this can build an entry carrying a hostile path (a "../" segment, say) that
	 * EntryHeader's own factories never would. That gap is exactly what the
	 * disk-space preflight tests below need: a real manifest can carry such an
	 * entry (it is untrusted input read off the archive), and the preflight must
	 * cope with it without mistaking a path problem for a disk-space one.
	 *
	 * @param string $path   Relative path recorded on the entry.
	 * @param int    $length The entry's STORED length, as ManifestEntry::length() reports it.
	 * @return ManifestEntry A file-kind manifest entry ready to feed to assert_free_space_for().
	 */
	private static function manifest_file_entry( string $path, int $length ): ManifestEntry {
		return ManifestEntry::for_file( 0, 0, $length, $path, 0, str_repeat( "\0", 32 ) );
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
	// Case-sensitivity probe (destination_is_case_sensitive())
	// -------------------------------------------------------------------

	/**
	 * The probe's answer agrees with an independent, direct check against the real filesystem.
	 *
	 * Writes two files whose names differ only by case directly, bypassing
	 * FileWriter's own probe entirely, and counts how many distinct
	 * directory entries result — the ground truth for whether THIS
	 * filesystem folds case. That ground truth must match what the probe
	 * itself reports for the very same destination_root, whatever the
	 * answer turns out to be on the host actually running this test.
	 *
	 * @return void
	 */
	public function test_case_sensitivity_probe_agrees_with_the_real_filesystem(): void {
		$writer = new FileWriter( $this->fixture_root );
		$method = new ReflectionMethod( FileWriter::class, 'destination_is_case_sensitive' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Independent ground-truth probe, deliberately bypassing FileWriter's own probe under test.
		file_put_contents( $this->fixture_root . '/groundtruth', 'a' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Independent ground-truth probe, deliberately bypassing FileWriter's own probe under test.
		file_put_contents( $this->fixture_root . '/GROUNDTRUTH', 'b' );

		$matching_entries             = array_filter(
			(array) scandir( $this->fixture_root ),
			static function ( string $entry ): bool {
				return 0 === strcasecmp( $entry, 'groundtruth' );
			}
		);
		$filesystem_is_case_sensitive = 2 === count( $matching_entries );

		$probed = (bool) $method->invoke( $writer );

		$this->assertSame( $filesystem_is_case_sensitive, $probed, 'The probe must agree with a direct, independent check of the same filesystem.' );
	}

	/**
	 * The probe leaves no file behind in the destination root, on either branch.
	 *
	 * @return void
	 */
	public function test_case_sensitivity_probe_leaves_no_file_behind(): void {
		$writer = new FileWriter( $this->fixture_root );
		$method = new ReflectionMethod( FileWriter::class, 'destination_is_case_sensitive' );

		$method->invoke( $writer );

		$this->assertSame( array(), glob( $this->fixture_root . '/.*CaseProbe*' ), 'The probe file, in any case spelling, must always be removed.' );
	}

	/**
	 * The probe's result is cached rather than re-probed on every call.
	 *
	 * A known value is seeded directly into the cache property by
	 * reflection (bypassing a real probe entirely, so the test does not
	 * depend on what this host's real answer happens to be), then the
	 * destination_root is made unwritable. A second call that actually
	 * re-probed would either error or fall back to the safe default
	 * (case-insensitive, i.e. false) because its own write would fail
	 * against the now read-only root — which would differ from the seeded
	 * `true`. Getting the seeded `true` back proves the cache, not a fresh
	 * probe, answered the second call.
	 *
	 * @return void
	 */
	public function test_case_sensitivity_probe_result_is_cached(): void {
		$writer = new FileWriter( $this->fixture_root );

		$property = new ReflectionProperty( FileWriter::class, 'case_sensitive_destination' );
		$property->setValue( $writer, true );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Test-only: breaking writability so a re-probe (if one happened) could not succeed.
		chmod( $this->fixture_root, 0o500 );
		try {
			$method = new ReflectionMethod( FileWriter::class, 'destination_is_case_sensitive' );
			$result = (bool) $method->invoke( $writer );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Restoring writability so tearDown can clean up the fixture.
			chmod( $this->fixture_root, 0o755 );
		}

		$this->assertTrue( $result, 'The cached value must be returned unchanged; a re-probe against an unwritable root could not have produced true.' );
	}

	// -------------------------------------------------------------------
	// Pontifex working-directory refusal
	// -------------------------------------------------------------------

	/**
	 * An entry at wp-content/pontifex itself is refused on a content-only restore.
	 *
	 * @return void
	 */
	public function test_pontifex_working_directory_refused_with_required_prefix(): void {
		$writer = new FileWriter( $this->fixture_root, false, 'wp-content' );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'own working directory' );

		$writer->write_entry( self::directory_result( 'wp-content/pontifex' ) );
	}

	/**
	 * An entry at wp-content/pontifex itself is refused even on a whole-site restore.
	 *
	 * The guard is structural, not scope-dependent: it must fire even when
	 * assert_within_required_prefix() is a no-op because no prefix is set.
	 *
	 * @return void
	 */
	public function test_pontifex_working_directory_refused_without_required_prefix(): void {
		$writer = new FileWriter( $this->fixture_root, false, null );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'own working directory' );

		$writer->write_entry( self::directory_result( 'wp-content/pontifex' ) );
	}

	/**
	 * A file nested inside wp-content/pontifex/ is refused.
	 *
	 * Uses a realistic path — a safety archive left behind by a previous
	 * rollback — to ground the abstract guard in the concrete asset it
	 * protects.
	 *
	 * @return void
	 */
	public function test_pontifex_working_directory_nested_file_refused(): void {
		$writer = new FileWriter( $this->fixture_root, false, null );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'own working directory' );

		$writer->write_entry( self::file_result( 'wp-content/pontifex/rollback/pre-import-rollback-99991231T235959Z.wpmig', 'forged' ) );
	}

	/**
	 * A sibling directory that merely starts with "pontifex" is NOT refused.
	 *
	 * The off-by-one FileScanner's own docblock calls out: "wp-content/pontifex-foo"
	 * is a different directory and must be written normally.
	 *
	 * @return void
	 */
	public function test_pontifex_lookalike_sibling_is_permitted(): void {
		$writer = new FileWriter( $this->fixture_root, false, null );

		$writer->write_entry( self::file_result( 'wp-content/pontifex-foo/file.txt', 'data' ) );

		$path = $this->fixture_root . '/wp-content/pontifex-foo/file.txt';
		$this->assertFileExists( $path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test assertion against on-disk fixture.
		$this->assertSame( 'data', file_get_contents( $path ) );
	}

	/**
	 * Every path FileScanner prunes from a scan, FileWriter's guard also refuses — in both filesystem-case branches.
	 *
	 * FileWriter and FileScanner are independent implementations by design
	 * (FileWriter must not depend on the Manifest namespace at runtime).
	 * is_pontifex_working_path() now takes the destination's case
	 * sensitivity as an explicit parameter (see its docblock), so this test
	 * drives BOTH branches directly by reflection rather than depending on
	 * whichever filesystem happens to run the suite:
	 *
	 *  - case-sensitive: FileWriter is byte-exact, identical to FileScanner
	 *    — for these fixture paths the two sets are exactly equal.
	 *  - case-insensitive: FileWriter refuses MORE than FileScanner prunes,
	 *    because it also catches case-varied paths the scanner — byte-exact
	 *    by design — would never itself emit.
	 *
	 * In both branches the one direction that matters for safety always
	 * holds, and is what this test asserts: scanner-pruned paths are a
	 * subset of writer-refused paths. FileScanner controls what a
	 * legitimate archive can ever contain, so nothing it prunes should ever
	 * slip past the writer's guard on the way back in. The reverse (the
	 * writer refusing something the scanner would have kept) is fine on a
	 * case-insensitive destination, and is exercised separately by
	 * {@see self::test_case_sensitive_branch_permits_case_variants()} and
	 * {@see self::test_case_insensitive_branch_refuses_case_variants()}.
	 *
	 * Both methods are private, so reflection is used to invoke each
	 * directly without needing a full write_entry()/scan() round trip.
	 *
	 * @return void
	 */
	public function test_writer_refuses_everything_the_scanner_prunes(): void {
		$scanner_pruned_paths = array(
			'wp-content/pontifex',
			'wp-content/pontifex/logs',
			'wp-content/pontifex/exports/x',
		);
		$scanner_kept_paths   = array(
			'wp-content/pontifex-foo',
			'wp-content',
			'wp-content/uploads/a.jpg',
			'pontifex',
		);

		$writer_method  = new ReflectionMethod( FileWriter::class, 'is_pontifex_working_path' );
		$scanner_method = new ReflectionMethod( FileScanner::class, 'is_pontifex_working_path' );

		foreach ( array( true, false ) as $case_sensitive ) {
			foreach ( $scanner_pruned_paths as $path ) {
				$this->assertTrue( (bool) $scanner_method->invoke( null, $path ), sprintf( 'precondition: FileScanner must prune "%s".', $path ) );
				$this->assertTrue(
					(bool) $writer_method->invoke( null, $path, $case_sensitive ),
					sprintf( 'FileWriter (case_sensitive=%s) must refuse everything FileScanner prunes, but accepted "%s".', $case_sensitive ? 'true' : 'false', $path )
				);
			}

			foreach ( $scanner_kept_paths as $path ) {
				$this->assertFalse( (bool) $scanner_method->invoke( null, $path ), sprintf( 'precondition: FileScanner must keep "%s".', $path ) );
				$this->assertFalse(
					(bool) $writer_method->invoke( null, $path, $case_sensitive ),
					sprintf( 'FileWriter (case_sensitive=%s) must not refuse an ordinary path FileScanner keeps: "%s".', $case_sensitive ? 'true' : 'false', $path )
				);
			}
		}
	}

	// -------------------------------------------------------------------
	// is_pontifex_working_path() — case-sensitivity branch matrix (by reflection)
	// -------------------------------------------------------------------

	/**
	 * On a case-sensitive destination, only the byte-exact "pontifex" is refused; case variants are PERMITTED.
	 *
	 * This is the false-refusal fix itself, pinned directly. Before this
	 * fix, is_pontifex_working_path() folded case UNCONDITIONALLY, so a
	 * genuine "wp-content/Pontifex/" directory — scanned faithfully into a
	 * legitimate archive by the byte-exact FileScanner, which never prunes
	 * it — was refused on restore to a case-sensitive destination (the
	 * common case on Linux/ext4 hosting), making that backup unrestorable
	 * with no attacker involved. Driven by reflection with
	 * case_sensitive_filesystem=true so this is proven deterministically
	 * regardless of which filesystem actually runs the suite.
	 *
	 * @return void
	 */
	public function test_case_sensitive_branch_permits_case_variants(): void {
		$method = new ReflectionMethod( FileWriter::class, 'is_pontifex_working_path' );

		$this->assertTrue( (bool) $method->invoke( null, 'wp-content/pontifex', true ), 'The byte-exact path must still be refused.' );
		$this->assertFalse( (bool) $method->invoke( null, 'wp-content/Pontifex', true ), 'A case-varied path must be PERMITTED on a case-sensitive destination -- the false-refusal fix.' );
		$this->assertFalse( (bool) $method->invoke( null, 'wp-content/PONTIFEX', true ), 'A case-varied path must be PERMITTED on a case-sensitive destination -- the false-refusal fix.' );
	}

	/**
	 * On a case-insensitive destination, the byte-exact path and every case variant are all refused.
	 *
	 * Unchanged from before this fix: on APFS/NTFS, "wp-content/PONTIFEX/…"
	 * and "wp-content/pontifex/…" name the same on-disk directory, so a
	 * forged archive spelling its way past a byte-exact check would still
	 * land inside the real working directory.
	 *
	 * @return void
	 */
	public function test_case_insensitive_branch_refuses_case_variants(): void {
		$method = new ReflectionMethod( FileWriter::class, 'is_pontifex_working_path' );

		$this->assertTrue( (bool) $method->invoke( null, 'wp-content/pontifex', false ) );
		$this->assertTrue( (bool) $method->invoke( null, 'wp-content/Pontifex', false ) );
		$this->assertTrue( (bool) $method->invoke( null, 'wp-content/PONTIFEX', false ) );
	}

	/**
	 * A lookalike sibling that merely starts with "pontifex" is permitted in both branches.
	 *
	 * Guards against a regression that turns either branch's comparison
	 * into a bare prefix/substring match with no slash boundary.
	 *
	 * @return void
	 */
	public function test_lookalike_sibling_permitted_in_both_case_sensitivity_branches(): void {
		$method = new ReflectionMethod( FileWriter::class, 'is_pontifex_working_path' );

		foreach ( array( true, false ) as $case_sensitive ) {
			$this->assertFalse( (bool) $method->invoke( null, 'wp-content/pontifex-foo', $case_sensitive ), sprintf( 'case_sensitive=%s', $case_sensitive ? 'true' : 'false' ) );
			$this->assertFalse( (bool) $method->invoke( null, 'wp-content/PONTIFEX-FOO', $case_sensitive ), sprintf( 'case_sensitive=%s', $case_sensitive ? 'true' : 'false' ) );
		}
	}

	// -------------------------------------------------------------------
	// Case-insensitive working-directory refusal (APFS / NTFS bypass)
	// -------------------------------------------------------------------
	//
	// The case-VARIED end-to-end scenarios formerly here (an upper-cased
	// "PONTIFEX", a mixed-case "Pontifex/.htaccess", each combined with a
	// "." or doubled "/") assumed is_pontifex_working_path() folded case
	// UNCONDITIONALLY. Since the case-sensitivity probe, whether such a
	// path is refused depends on the REAL destination filesystem — on a
	// case-sensitive host (ext4, the common Linux/hosting case) it is
	// correctly PERMITTED, so an unconditional "always refused" assertion
	// would be flaky at best and simply wrong at worst on such a host. That
	// behaviour is now pinned deterministically, on any host, by the
	// reflection-driven branch matrix above
	// ({@see self::test_case_sensitive_branch_permits_case_variants()} and
	// {@see self::test_case_insensitive_branch_refuses_case_variants()}).
	// What remains here, end-to-end, is only what is refused on EVERY
	// host regardless of case sensitivity: the byte-exact lower-case path
	// (below, and throughout the surrounding sections) and the lookalike
	// siblings that must never be refused in either branch.

	/**
	 * A ".." segment that would climb back into a lower-case "pontifex" via an upper-case detour stays refused.
	 *
	 * "wp-content/pontifex/../pontifex/x" contains a parent-directory
	 * segment and must be refused by that rule regardless of case, before
	 * the working-directory guard is even reached.
	 *
	 * @return void
	 */
	public function test_parent_segment_through_pontifex_stays_refused_regardless_of_case(): void {
		$writer = new FileWriter( $this->fixture_root, false, 'wp-content' );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'normalise_entry_path refuses entry path' );

		$writer->write_entry( self::file_result( 'wp-content/pontifex/../pontifex/x', 'forged' ) );
	}

	/**
	 * The same ".."-through-"pontifex" path stays refused with no required prefix too.
	 *
	 * @return void
	 */
	public function test_parent_segment_through_pontifex_stays_refused_regardless_of_case_without_prefix(): void {
		$writer = new FileWriter( $this->fixture_root, false, null );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'normalise_entry_path refuses entry path' );

		$writer->write_entry( self::file_result( 'wp-content/pontifex/../pontifex/x', 'forged' ) );
	}

	/**
	 * An upper-cased lookalike sibling, "PONTIFEX-FOO", is still permitted.
	 *
	 * The case-insensitivity fix must not turn into over-matching: only the
	 * exact "pontifex" directory name (in any case) is refused, not any
	 * directory that happens to start with it.
	 *
	 * @return void
	 */
	public function test_upper_case_lookalike_sibling_is_permitted(): void {
		$writer = new FileWriter( $this->fixture_root, false, null );

		$writer->write_entry( self::file_result( 'wp-content/PONTIFEX-FOO/notes.txt', 'data' ) );

		$path = $this->fixture_root . '/wp-content/PONTIFEX-FOO/notes.txt';
		$this->assertFileExists( $path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test assertion against on-disk fixture.
		$this->assertSame( 'data', file_get_contents( $path ) );
	}

	/**
	 * A lower-case lookalike sibling, "pontifex-foo", is still permitted.
	 *
	 * Pinned alongside the upper-case sibling above so a regression that
	 * makes the comparison over-match (e.g. a bare case-insensitive
	 * substring check with no slash boundary) would be caught either way.
	 *
	 * @return void
	 */
	public function test_lower_case_lookalike_sibling_is_permitted(): void {
		$writer = new FileWriter( $this->fixture_root, false, null );

		$writer->write_entry( self::file_result( 'wp-content/pontifex-foo/notes.txt', 'data' ) );

		$path = $this->fixture_root . '/wp-content/pontifex-foo/notes.txt';
		$this->assertFileExists( $path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test assertion against on-disk fixture.
		$this->assertSame( 'data', file_get_contents( $path ) );
	}

	// -------------------------------------------------------------------
	// Path normalisation — closing the "." / doubled-"/" bypass
	// -------------------------------------------------------------------

	/**
	 * A "." segment placed just before "pontifex" does not defeat the working-directory guard.
	 *
	 * The exact bypass this guard closes: a literal strncmp() against
	 * "wp-content/pontifex/" would not match "wp-content/./pontifex/…" as
	 * text, even though the filesystem resolves the two identically. Without
	 * normalisation the entry would be written straight into the site's
	 * own rollback archive directory.
	 *
	 * @return void
	 */
	public function test_dot_segment_before_pontifex_does_not_bypass_the_working_directory_guard(): void {
		$writer = new FileWriter( $this->fixture_root, false, null );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'own working directory' );

		$writer->write_entry( self::file_result( 'wp-content/./pontifex/rollback/evil.wpmig', 'forged' ) );
	}

	/**
	 * A doubled "/" before "pontifex" does not defeat the working-directory guard.
	 *
	 * The doubled-slash sibling of the previous test: "wp-content//pontifex/…"
	 * does not textually match "wp-content/pontifex/" either, but resolves
	 * to the same place on disk.
	 *
	 * @return void
	 */
	public function test_doubled_slash_before_pontifex_does_not_bypass_the_working_directory_guard(): void {
		$writer = new FileWriter( $this->fixture_root, false, null );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'own working directory' );

		$writer->write_entry( self::file_result( 'wp-content//pontifex/rollback/evil.wpmig', 'forged' ) );
	}

	/**
	 * A "." segment before "pontifex" does not allow overwriting its .htaccess.
	 *
	 * The concrete, highest-stakes instance of the bypass: pontifex/.htaccess
	 * is what keeps the whole-database safety archives out of web access.
	 * Overwriting it through this gap would be the primitive that exposes
	 * them.
	 *
	 * @return void
	 */
	public function test_dot_segment_before_pontifex_does_not_permit_overwriting_htaccess(): void {
		$writer = new FileWriter( $this->fixture_root, false, null );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'own working directory' );

		$writer->write_entry( self::file_result( 'wp-content/./pontifex/.htaccess', 'Allow from all' ) );
	}

	/**
	 * A doubled "/" before "pontifex" does not permit overwriting its .htaccess.
	 *
	 * @return void
	 */
	public function test_doubled_slash_before_pontifex_does_not_permit_overwriting_htaccess(): void {
		$writer = new FileWriter( $this->fixture_root, false, null );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'own working directory' );

		$writer->write_entry( self::file_result( 'wp-content//pontifex/.htaccess', 'Allow from all' ) );
	}

	/**
	 * A "." segment placed AFTER "pontifex" was already refused before this fix; pin it.
	 *
	 * "wp-content/pontifex/./rollback/evil.wpmig" already begins with the
	 * literal prefix "wp-content/pontifex/", so the unnormalised guard
	 * already caught this shape. This test exists purely to guard against a
	 * future regression in normalise_entry_path() accidentally un-refusing
	 * it.
	 *
	 * @return void
	 */
	public function test_dot_segment_after_pontifex_stays_refused(): void {
		$writer = new FileWriter( $this->fixture_root, false, null );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'own working directory' );

		$writer->write_entry( self::file_result( 'wp-content/pontifex/./rollback/evil.wpmig', 'forged' ) );
	}

	/**
	 * A "." segment in an ordinary, legitimate path is collapsed away by normalise_entry_path().
	 *
	 * Proves normalisation is not just a refusal engine: a harmless "."
	 * segment in an otherwise-fine path is collapsed away, leaving the path
	 * it would have been without the "." present.
	 *
	 * Asserted by reflection on the STRING normalise_entry_path() returns,
	 * not by writing the entry and checking the file lands in the right
	 * place. A file-existence assertion here would prove nothing: the
	 * kernel resolves a "." path segment itself, so "wp-content/./uploads/photo.jpg"
	 * and "wp-content/uploads/photo.jpg" name the identical file to
	 * fopen()/file_put_contents() regardless of whether normalise_entry_path()
	 * ever ran. A version of this test built that way passed even with
	 * normalise_entry_path() gutted to `return $relative_path;` — it killed
	 * zero mutants. Checking the returned string directly is the only way
	 * to actually pin the collapsing behaviour.
	 *
	 * @return void
	 */
	public function test_dot_segment_in_ordinary_path_is_permitted_and_normalised(): void {
		$writer = new FileWriter( $this->fixture_root, false, null );
		$method = new ReflectionMethod( FileWriter::class, 'normalise_entry_path' );

		$normalised = $method->invoke( $writer, 'wp-content/./uploads/photo.jpg' );

		$this->assertSame( 'wp-content/uploads/photo.jpg', $normalised );
	}

	/**
	 * A doubled "/" in an ordinary, legitimate path is collapsed to one by normalise_entry_path().
	 *
	 * See {@see self::test_dot_segment_in_ordinary_path_is_permitted_and_normalised()}
	 * for why this is asserted on the returned string by reflection, not by
	 * writing the entry and checking where the file lands: the kernel
	 * collapses a doubled "/" itself, so a file-existence assertion cannot
	 * tell whether normalise_entry_path() actually did anything.
	 *
	 * @return void
	 */
	public function test_doubled_slash_in_ordinary_path_is_permitted_and_normalised(): void {
		$writer = new FileWriter( $this->fixture_root, false, null );
		$method = new ReflectionMethod( FileWriter::class, 'normalise_entry_path' );

		$normalised = $method->invoke( $writer, 'wp-content//uploads/photo.jpg' );

		$this->assertSame( 'wp-content/uploads/photo.jpg', $normalised );
	}

	/**
	 * A ".." segment that would climb from uploads into pontifex is refused by normalise_entry_path() itself.
	 *
	 * Normalisation collapses "." and doubled "/", but must never collapse
	 * ".." — this path is refused for containing a parent-directory
	 * segment, the same as any other traversal attempt, well before the
	 * Pontifex-working-path guard would even get a chance to run.
	 *
	 * The exception message asserted here is normalise_entry_path()'s own
	 * distinct wording, not resolve_safe_path()'s byte-identical
	 * "…contains a parent-directory segment." fallback message. The two
	 * methods make the same refusal for defence-in-depth (resolve_safe_path()
	 * is a deliberate second guard), so asserting only the shared substring
	 * would let a bug that deletes normalise_entry_path()'s own ".." check
	 * pass this test silently — resolve_safe_path() would still catch the
	 * escape and the test would still go green, having proven nothing about
	 * the method it claims to pin.
	 *
	 * @return void
	 */
	public function test_parent_segment_climbing_into_pontifex_is_refused(): void {
		$writer = new FileWriter( $this->fixture_root, false, null );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'normalise_entry_path refuses entry path' );

		$writer->write_entry( self::file_result( 'wp-content/uploads/../pontifex/evil.wpmig', 'forged' ) );
	}

	/**
	 * A ".." chain that would climb all the way to wp-config.php is refused by normalise_entry_path() itself.
	 *
	 * See {@see self::test_parent_segment_climbing_into_pontifex_is_refused()}
	 * for why the distinct message, not the shared "parent-directory
	 * segment" substring, is what this test asserts.
	 *
	 * @return void
	 */
	public function test_parent_segment_chain_climbing_to_wp_config_is_refused(): void {
		$writer = new FileWriter( $this->fixture_root, false, null );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'normalise_entry_path refuses entry path' );

		$writer->write_entry( self::file_result( 'wp-content/uploads/../../wp-config.php', 'malicious' ) );
	}

	// -------------------------------------------------------------------
	// resolve_safe_path() receives the normalised value, not the raw header path
	// -------------------------------------------------------------------

	/**
	 * The write_entry() method feeds resolve_safe_path() the NORMALISED path, not the raw header path.
	 *
	 * Pins the guarantee stated on write_entry()'s own docblock: every
	 * guard, including resolve_safe_path(), evaluates the same normalised
	 * value that becomes the write target. A trailing "/" makes this
	 * observable in a way a "." or doubled "/" cannot: normalise_entry_path()'s
	 * segment-split-and-filter strips a trailing slash as a side effect (an
	 * empty final segment is dropped the same as a "." segment — see its
	 * docblock), but a LITERAL trailing slash carried through to a real
	 * filesystem call asserts "this must be a directory" — POSIX refuses to
	 * open a path ending in "/" as a regular file. If write_entry() were
	 * ever changed to feed resolve_safe_path() the raw header path
	 * ("notes.txt/") instead of the already-normalised one ("notes.txt"),
	 * this write would fail with "could not write file" instead of
	 * succeeding. Unlike "." or doubled "/" (see the reflection-based
	 * normalisation tests above), the kernel does NOT resolve this
	 * particular difference away, so it is a genuine, portable pin — not
	 * something that happens to pass because path resolution absorbs it.
	 *
	 * @return void
	 */
	public function test_write_entry_feeds_resolve_safe_path_the_normalised_value(): void {
		$writer = new FileWriter( $this->fixture_root );

		$writer->write_entry( self::file_result( 'notes.txt/', 'trailing-slash-header-path' ) );

		$path = $this->fixture_root . '/notes.txt';
		$this->assertFileExists( $path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test assertion against on-disk fixture.
		$this->assertSame( 'trailing-slash-header-path', file_get_contents( $path ) );
	}

	// -------------------------------------------------------------------
	// A path normalising to empty ("." / "./" / a run of such segments) is refused
	// -------------------------------------------------------------------

	/**
	 * A raw path of exactly "." normalises to empty and is refused, for a file entry.
	 *
	 * Closes the gap resolve_safe_path()'s docblock now describes: "."
	 * is non-empty on input, so it is not caught by the raw-input empty
	 * check, but collapses to "" once its "." segment is dropped — and an
	 * empty relative path joined onto destination_root resolves to
	 * destination_root ITSELF.
	 *
	 * @return void
	 */
	public function test_dot_path_normalising_to_empty_is_refused_for_file(): void {
		$writer = new FileWriter( $this->fixture_root, false, null );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'normalises to an empty path' );

		$writer->write_entry( self::file_result( '.', 'forged' ) );
	}

	/**
	 * A raw path of "./" (trailing slash) normalises to empty and is refused, for a file entry.
	 *
	 * @return void
	 */
	public function test_dot_slash_path_normalising_to_empty_is_refused_for_file(): void {
		$writer = new FileWriter( $this->fixture_root, false, null );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'normalises to an empty path' );

		$writer->write_entry( self::file_result( './', 'forged' ) );
	}

	/**
	 * A run of dot-and-slash segments (".//./.") normalises to empty and is refused, for a file entry.
	 *
	 * @return void
	 */
	public function test_multiple_dot_segments_normalising_to_empty_is_refused_for_file(): void {
		$writer = new FileWriter( $this->fixture_root, false, null );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'normalises to an empty path' );

		$writer->write_entry( self::file_result( './/./.', 'forged' ) );
	}

	/**
	 * A raw path of exactly "." normalises to empty and is refused, for a DIRECTORY entry.
	 *
	 * The concrete, demonstrated failure mode this guard closes: without
	 * it, a directory entry at the collapsed-empty path would apply the
	 * archive's mode to destination_root ITSELF — chmod'ing the WordPress
	 * root to whatever the archive specifies (0000 has been demonstrated).
	 *
	 * @return void
	 */
	public function test_dot_path_normalising_to_empty_is_refused_for_directory(): void {
		$writer = new FileWriter( $this->fixture_root, false, null );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'normalises to an empty path' );

		$writer->write_entry( self::directory_result( '.', 0o000 ) );
	}

	/**
	 * A run of dot-and-slash segments (".//./.") normalises to empty and is refused, for a DIRECTORY entry.
	 *
	 * @return void
	 */
	public function test_multiple_dot_segments_normalising_to_empty_is_refused_for_directory(): void {
		$writer = new FileWriter( $this->fixture_root, false, null );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'normalises to an empty path' );

		$writer->write_entry( self::directory_result( './/./.', 0o000 ) );
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

	/**
	 * A content-only writer must write an entry inside its required prefix.
	 *
	 * The restriction allows the prefix tree through unchanged, so a normal
	 * content-only restore writes wp-content as usual.
	 *
	 * @return void
	 */
	public function test_required_prefix_allows_entry_within_prefix(): void {
		$writer = new FileWriter( $this->fixture_root, false, 'wp-content' );

		$writer->write_entry( self::directory_result( 'wp-content' ) );
		$writer->write_entry( self::file_result( 'wp-content/plugins/akismet/akismet.php', 'plugin' ) );

		$path = $this->fixture_root . '/wp-content/plugins/akismet/akismet.php';
		$this->assertFileExists( $path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test assertion against on-disk fixture.
		$this->assertSame( 'plugin', file_get_contents( $path ) );
	}

	/**
	 * A content-only writer must refuse a WordPress core file.
	 *
	 * The write-boundary backstop behind the import scope gate: even if a
	 * mislabelled content-only archive carried a core path, it is refused and never
	 * written.
	 *
	 * @return void
	 */
	public function test_required_prefix_refuses_core_path(): void {
		$writer = new FileWriter( $this->fixture_root, false, 'wp-content' );

		$this->expectException( InvalidArgumentException::class );

		$writer->write_entry( self::file_result( 'wp-includes/version.php', 'core' ) );
	}

	/**
	 * A content-only writer must refuse wp-config.php at the site root.
	 *
	 * @return void
	 */
	public function test_required_prefix_refuses_wp_config(): void {
		$writer = new FileWriter( $this->fixture_root, false, 'wp-content' );

		$this->expectException( InvalidArgumentException::class );

		$writer->write_entry( self::file_result( 'wp-config.php', '<?php' ) );
	}

	/**
	 * A content-only writer must NOT refuse a sibling directory whose name merely starts with the prefix.
	 *
	 * Defends against a prefix check that uses a bare string-prefix without a slash
	 * boundary and wrongly admits "wp-content-old".
	 *
	 * @return void
	 */
	public function test_required_prefix_refuses_lookalike_sibling(): void {
		$writer = new FileWriter( $this->fixture_root, false, 'wp-content' );

		$this->expectException( InvalidArgumentException::class );

		$writer->write_entry( self::file_result( 'wp-content-old/note.txt', 'data' ) );
	}

	/**
	 * With no required prefix, the writer writes a core path unrestricted.
	 *
	 * Confirms the default (whole-site) restore is unchanged — a null prefix imposes
	 * no path restriction.
	 *
	 * @return void
	 */
	public function test_no_required_prefix_writes_core_path(): void {
		$writer = new FileWriter( $this->fixture_root, false, null );

		$writer->write_entry( self::file_result( 'wp-includes/version.php', 'core' ) );

		$this->assertFileExists( $this->fixture_root . '/wp-includes/version.php' );
	}

	/**
	 * A read-only destination file must be replaceable.
	 *
	 * Git object and pack files are read-only by design, and an fopen-for-write
	 * on one aborted the restore AND its auto-recovery. The write now lands in a
	 * sibling temp renamed over the target, and POSIX rename() needs write
	 * permission on the directory, not the target file — so a read-only file is
	 * replaced cleanly with the archive's content and mode.
	 *
	 * @return void
	 */
	public function test_write_replaces_a_read_only_destination_file(): void {
		$target = $this->fixture_root . '/wp-content/readonly.pack';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating the test fixture directory.
		mkdir( dirname( $target ), 0o755, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Seeding the pre-existing read-only file the test replaces.
		file_put_contents( $target, 'old read-only content' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Making the seeded file read-only, the condition under test.
		chmod( $target, 0o444 );

		$writer = new FileWriter( $this->fixture_root );
		$writer->write_entry( self::file_result( 'wp-content/readonly.pack', 'restored content' ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading the test's own fixture back.
		$this->assertSame( 'restored content', file_get_contents( $target ), 'The read-only file must be replaced with the archive content.' );
		$this->assertSame( 0o644, fileperms( $target ) & 0o7777, 'The replaced file must carry the archive mode, not the old read-only bits.' );
	}

	/**
	 * The streamed write path must replace a read-only destination too.
	 *
	 * @return void
	 */
	public function test_streamed_write_replaces_a_read_only_destination_file(): void {
		$target = $this->fixture_root . '/wp-content/readonly-streamed.pack';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating the test fixture directory.
		mkdir( dirname( $target ), 0o755, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Seeding the pre-existing read-only file the test replaces.
		file_put_contents( $target, 'old' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Making the seeded file read-only, the condition under test.
		chmod( $target, 0o444 );

		$contents = 'streamed restored content';
		$header   = EntryHeader::for_file( 'wp-content/readonly-streamed.pack', strlen( $contents ), 0o644, 1690000000, 'application/octet-stream', 0 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://memory is an in-process buffer, not a file.
		$stream = fopen( 'php://memory', 'r+b' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Operating on a test stream resource.
		fwrite( $stream, $contents );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource.
		rewind( $stream );

		$writer = new FileWriter( $this->fixture_root );
		$writer->write_entry( EntryReadResult::for_stream( $header, $stream, strlen( $contents ) ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading the test's own fixture back.
		$this->assertSame( $contents, file_get_contents( $target ) );
	}

	/**
	 * A failed write must leave the original file intact and no temp behind.
	 *
	 * The per-file crash-atomicity property: the temp write fails (unwritable
	 * directory), the error is loud, the pre-existing file is untouched, and no
	 * orphaned .tmp sibling remains.
	 *
	 * @return void
	 */
	public function test_a_failed_write_leaves_the_original_intact_and_no_temp(): void {
		$dir    = $this->fixture_root . '/wp-content/locked';
		$target = $dir . '/precious.txt';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating the test fixture directory.
		mkdir( $dir, 0o755, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Seeding the pre-existing file the failed write must not touch.
		file_put_contents( $target, 'original' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Making the DIRECTORY unwritable so the temp write fails, the condition under test.
		chmod( $dir, 0o555 );

		$writer = new FileWriter( $this->fixture_root );
		$thrown = null;
		try {
			$writer->write_entry( self::file_result( 'wp-content/locked/precious.txt', 'clobber attempt' ) );
		} catch ( RuntimeException $e ) {
			$thrown = $e;
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Restoring the fixture directory so tearDown can clean it.
			chmod( $dir, 0o755 );
		}

		$this->assertInstanceOf( RuntimeException::class, $thrown, 'A write into an unwritable directory must fail loudly.' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading the test's own fixture back.
		$this->assertSame( 'original', file_get_contents( $target ), 'A failed write must leave the original untouched.' );
		$this->assertSame( array(), glob( $dir . '/*.tmp' ), 'A failed write must leave no orphaned temp file.' );
	}

	// -------------------------------------------------------------------
	// assert_free_space_for() — the pre-restore disk-space preflight
	// -------------------------------------------------------------------

	/**
	 * A restore is refused when the destination has less free space than it needs, and the message names the megabytes.
	 *
	 * A single 10 MB file entry with nothing already at its destination path:
	 * both figures (largest single file, total growth) come out at 10 MB, so
	 * "needed" is unambiguous. Only 2 MB is reported free, so the preflight
	 * must refuse — and the message must be readable in human terms (megabytes),
	 * not the raw byte counts SafetyArchiver's own preflight message uses.
	 *
	 * @return void
	 */
	public function test_assert_free_space_for_refuses_when_space_is_short(): void {
		$ten_mb = 10 * 1024 * 1024;
		$two_mb = 2 * 1024 * 1024;
		$writer = new FileWriter(
			$this->fixture_root,
			false,
			null,
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Must match the injected Closure(string): (float|false) contract; this fake reader ignores which path is being asked about and always answers with the fixed figure under test.
			static function ( string $path ) use ( $two_mb ) {
				return $two_mb;
			}
		);

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'needs about 10 MB free, and only 2 MB is available' );

		$writer->assert_free_space_for( array( self::manifest_file_entry( 'big.iso', $ten_mb ) ) );
	}

	/**
	 * A restore is permitted when there is ample free space for it.
	 *
	 * @return void
	 */
	public function test_assert_free_space_for_permits_ample_space(): void {
		$five_mb    = 5 * 1024 * 1024;
		$hundred_mb = 100 * 1024 * 1024;
		$writer     = new FileWriter(
			$this->fixture_root,
			false,
			null,
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Must match the injected Closure(string): (float|false) contract; this fake reader ignores which path is being asked about and always answers with the fixed figure under test.
			static function ( string $path ) use ( $hundred_mb ) {
				return $hundred_mb;
			}
		);

		$result = $writer->assert_free_space_for( array( self::manifest_file_entry( 'medium.bin', $five_mb ) ) );

		$this->assertNull( $result, 'Ample free space must not refuse the restore.' );
	}

	/**
	 * Even with zero growth, there must be room for the largest single file.
	 *
	 * The other half of the pair, and the half a growth-only calculation misses
	 * entirely. FileWriter writes each file to a temporary name and renames it
	 * into place, so replacing a 10 MB file needs 10 MB free for the moment both
	 * copies exist — even though the net change is nothing. A restore onto a
	 * nearly-full disk therefore fails on the very first large file while a
	 * growth-only sum happily reports that nothing is needed.
	 *
	 * Every entry here already exists at exactly its incoming size, so growth is
	 * zero and the largest-entry figure is the only thing that can refuse this.
	 *
	 * @return void
	 */
	public function test_assert_free_space_for_refuses_when_the_largest_file_alone_will_not_fit(): void {
		$entry_length = 100000;
		$free_space   = 40000;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture setup; every entry below shares this one destination directory.
		mkdir( $this->fixture_root . '/wp-content/uploads', 0o755, true );

		$entries = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$path = sprintf( 'wp-content/uploads/existing-%d.bin', $i );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Seeding a destination file matching the incoming entry exactly, so growth is zero.
			file_put_contents( $this->fixture_root . '/' . $path, str_repeat( 'a', $entry_length ) );
			$entries[] = self::manifest_file_entry( $path, $entry_length );
		}

		$writer = new FileWriter(
			$this->fixture_root,
			false,
			null,
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Must match the injected Closure(string): (float|false) contract; this fake reader ignores which path is being asked about and always answers with the fixed figure under test.
			static function ( string $path ) use ( $free_space ) {
				return $free_space;
			}
		);

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'stopped before changing anything' );

		$writer->assert_free_space_for( $entries );
	}

	/**
	 * The most important test: a rollback-shaped restore, where every file already exists at the same size, must be
	 * PERMITTED even when free space sits far below the archive's total size.
	 *
	 * This is the exact false refusal this guard exists to avoid. A rollback (or
	 * any same-site restore) rewrites files that are already there, so growth —
	 * "how much bigger is the new file than what is already at that path" — is
	 * zero for every entry: five 100,000-byte files whose destinations already
	 * hold 100,000 bytes each sum to zero growth, even though the archive's
	 * total size is 500,000 bytes. Only the largest single entry (100,000
	 * bytes, for the temp-then-rename write) is a real requirement, so 150,000
	 * bytes free — comfortably above that, but well below the 500,000-byte
	 * total — must be enough. A naive implementation that instead demanded
	 * room for the archive's whole total would wrongly refuse this restore,
	 * locking someone out of their own recovery with no attacker involved.
	 *
	 * @return void
	 */
	public function test_assert_free_space_for_permits_rollback_shape_despite_low_free_space(): void {
		$entry_length = 100000;
		$entry_count  = 5;
		$free_space   = 150000;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture setup; every entry below shares this one destination directory.
		mkdir( $this->fixture_root . '/wp-content/uploads', 0o755, true );

		$entries = array();
		for ( $i = 0; $i < $entry_count; $i++ ) {
			$path = sprintf( 'wp-content/uploads/existing-%d.bin', $i );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Seeding a destination file that already matches the incoming entry's size, so growth is zero for it.
			file_put_contents( $this->fixture_root . '/' . $path, str_repeat( 'a', $entry_length ) );
			$entries[] = self::manifest_file_entry( $path, $entry_length );
		}
		// Archive total is 500,000 bytes (5 x 100,000) -- well above the 150,000
		// bytes free granted below; only growth (zero here) and the largest
		// single entry (100,000) are meant to matter.

		$writer = new FileWriter(
			$this->fixture_root,
			false,
			null,
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Must match the injected Closure(string): (float|false) contract; this fake reader ignores which path is being asked about and always answers with the fixed figure under test.
			static function ( string $path ) use ( $free_space ) {
				return $free_space;
			}
		);

		$result = $writer->assert_free_space_for( $entries );

		$this->assertNull( $result, 'A rollback where every file already matches must be permitted despite the low free space.' );
	}

	/**
	 * A "growth" shape — files are much larger than what already exists at their destinations — is refused when free
	 * space is short, even though no single file is individually too big.
	 *
	 * Five 200,000-byte entries with nothing at their destinations sum to a
	 * 1,000,000-byte total growth. 500,000 bytes free comfortably covers the
	 * 200,000-byte largest single entry but not the summed growth, so the
	 * restore must be refused — proving the growth figure, not just the
	 * largest-entry figure, is enforced.
	 *
	 * @return void
	 */
	public function test_assert_free_space_for_refuses_growth_shape_when_space_is_short(): void {
		$entry_length = 200000;
		$entry_count  = 5;
		$free_space   = 500000;

		$entries = array();
		for ( $i = 0; $i < $entry_count; $i++ ) {
			// Nothing exists yet at any of these destinations, so each entry's full
			// length counts as growth.
			$entries[] = self::manifest_file_entry( sprintf( 'wp-content/uploads/new-%d.bin', $i ), $entry_length );
		}

		$writer = new FileWriter(
			$this->fixture_root,
			false,
			null,
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Must match the injected Closure(string): (float|false) contract; this fake reader ignores which path is being asked about and always answers with the fixed figure under test.
			static function ( string $path ) use ( $free_space ) {
				return $free_space;
			}
		);

		$this->expectException( RuntimeException::class );

		$writer->assert_free_space_for( $entries );
	}

	/**
	 * An unknown free-space reading (false, e.g. under open_basedir) must never refuse the restore.
	 *
	 * Matches the posture {@see \Pontifex\Rollback\SafetyArchiver::preflight_disk_space()}
	 * already takes: an unknown must never become a refusal.
	 *
	 * @return void
	 */
	public function test_assert_free_space_for_does_not_refuse_on_unknown_free_space(): void {
		$writer = new FileWriter(
			$this->fixture_root,
			false,
			null,
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Must match the injected Closure(string): (float|false) contract; this fake reader ignores which path is being asked about and always answers with the fixed figure under test.
			static function ( string $path ) {
				return false;
			}
		);

		// An enormous entry that would certainly be refused if any real free-space
		// figure were compared against it.
		$result = $writer->assert_free_space_for( array( self::manifest_file_entry( 'huge.bin', 500 * 1024 * 1024 ) ) );

		$this->assertNull( $result, 'An unknown free-space reading must not refuse the restore.' );
	}

	/**
	 * An entry whose path cannot be normalised is skipped by the preflight, not treated as a disk-space refusal.
	 *
	 * ManifestEntry (unlike EntryHeader) does not itself validate a path, so a
	 * real manifest read off an archive can carry a hostile entry — here, one
	 * with a "../" segment and a fabricated 500 MB length. If the preflight
	 * mistakenly counted it, the tiny free-space figure below would refuse the
	 * whole restore over a path problem. Instead it must be skipped here and
	 * left for write_entry() to refuse in its own right, with its own message,
	 * when the walk actually reaches it.
	 *
	 * @return void
	 */
	public function test_assert_free_space_for_skips_an_entry_with_an_unnormalisable_path(): void {
		$writer = new FileWriter(
			$this->fixture_root,
			false,
			null,
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Must match the injected Closure(string): (float|false) contract; this fake reader ignores which path is being asked about and always answers with the fixed figure under test.
			static function ( string $path ) {
				// Just enough for the one legitimate entry below, nowhere near
				// enough for the hostile entry's fabricated 500 MB if it were
				// wrongly counted.
				return 5000;
			}
		);

		$entries = array(
			self::manifest_file_entry( 'note.txt', 1000 ),
			self::manifest_file_entry( '../escape.bin', 500 * 1024 * 1024 ),
		);

		$result = $writer->assert_free_space_for( $entries );
		$this->assertNull( $result, 'The hostile entry must be skipped, not treated as a disk-space shortfall.' );

		// write_entry() must still be the one to refuse that same hostile entry,
		// with its own path-safety message, once the walk actually reaches it.
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'parent-directory segment' );
		$writer->write_entry( self::file_result( '../escape.bin', 'forged' ) );
	}
}
