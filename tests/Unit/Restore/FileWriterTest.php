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
use ReflectionClassConstant;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Format\ManifestEntry;
use Pontifex\Archive\Reader\EntryReadResult;
use Pontifex\Exception\ArchiveNotTrustworthy;
use Pontifex\Exception\HostCannotComply;
use Pontifex\Filesystem\TempArtefact;
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

	/**
	 * Build a $decoded_sizes map — path => decoded byte size — matching each entry's own STORED length.
	 *
	 * A convenience for the tests exercising the growth/largest-entry
	 * arithmetic itself, where the distinction between compressed and
	 * decoded bytes is not what is under test: this keeps assert_free_space_for()'s
	 * decoded-size figure identical to its stored length, so those tests keep
	 * meaning exactly what they said before decoded-byte weighing existed. The
	 * headline decoded-vs-compressed tests below deliberately do NOT use this
	 * helper — they build a $decoded_sizes map that disagrees with length() on
	 * purpose.
	 *
	 * @param array<int, ManifestEntry> $entries The entries to build a matching size map for.
	 * @return array<string, int> Each entry's own path mapped to its own length().
	 */
	private static function decoded_sizes_matching_length( array $entries ): array {
		$sizes = array();
		foreach ( $entries as $entry ) {
			$path = $entry->path();
			if ( null !== $path ) {
				$sizes[ $path ] = $entry->length();
			}
		}
		return $sizes;
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
		$this->expectExceptionMessage( 'Could not create directory' );

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
		$this->expectExceptionMessage( 'escapes the site' );

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
		$this->expectExceptionMessage( 'escapes the site' );

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

	/**
	 * The disabled-symlink() refusal is HostCannotComply, not a bare
	 * RuntimeException.
	 *
	 * The refusal branch is only reachable on a host where symlink() has
	 * genuinely been removed (e.g. via disable_functions) — function_exists()
	 * cannot be faked in-process, so this shells out to a real child process
	 * the same way {@see \Pontifex\Tests\Unit\Archive\Reader\ArchiveReaderTest::test_manifest_decode_refused_when_the_host_forbids_raising_the_limit()}
	 * does for ini_set. This is the regression guard for the disabled-symlink()
	 * refusal disagreeing with its sibling guards
	 * ({@see FileWriter::assert_symlinks_creatable()} and the readlink()
	 * guard inside {@see FileWriter::declared_or_on_disk_target()}), which both
	 * already throw HostCannotComply for the identical "this host removed the
	 * function I need" condition.
	 *
	 * @return void
	 */
	public function test_write_symlink_refuses_as_host_cannot_comply_when_symlink_is_unavailable(): void {
		$snippet = 'require getenv( "PONTIFEX_AUTOLOAD" );'
			. ' $writer = new Pontifex\\Restore\\FileWriter( getenv( "PONTIFEX_FIXTURE_ROOT" ) );'
			. ' $header = Pontifex\\Archive\\Format\\EntryHeader::for_symlink( "link", "target.txt", 0 );'
			. ' $result = new Pontifex\\Archive\\Reader\\EntryReadResult( $header, "" );'
			. ' try { $writer->write_entry( $result ); echo "NO-REFUSAL"; }'
			. ' catch ( Throwable $e ) { echo get_class( $e ) . "|" . $e->getMessage(); }';

		$command = sprintf(
			'PONTIFEX_AUTOLOAD=%s PONTIFEX_FIXTURE_ROOT=%s php -d disable_functions=symlink -r %s 2>&1',
			escapeshellarg( dirname( __DIR__, 3 ) . '/vendor/autoload.php' ),
			escapeshellarg( $this->fixture_root ),
			escapeshellarg( $snippet )
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec -- Test-only: the refusal branch is reachable only where symlink() is genuinely unavailable, which cannot be simulated in-process; a real child process is the only honest way to exercise it.
		$output = (string) shell_exec( $command );

		$this->assertStringStartsWith( HostCannotComply::class . '|', $output, 'symlink() unavailable must refuse as HostCannotComply, matching its sibling guards.' );
		$this->assertStringContainsString( 'symlink() is not available on this host', $output );
		$this->assertStringNotContainsString( 'NO-REFUSAL', $output );
	}

	// -------------------------------------------------------------------
	// Whole-archive symlink preflight (assert_symlink_targets_confined())
	//
	// The corpus below is the evidence for the preflight, and it is split
	// deliberately into hostile shapes that MUST refuse and legitimate ones
	// that MUST be permitted. The second half matters as much as the first:
	// an earlier attempt at this guard refused a Composer-managed site's own
	// backup, which makes that site's backup unrestorable with no attacker
	// anywhere, and it had to be reverted. Every legitimate shape here is
	// therefore also WRITTEN after the preflight passes, so the test proves
	// the link really lands rather than merely that no exception was thrown.
	// -------------------------------------------------------------------

	/**
	 * Create a directory inside the fixture root, with parents.
	 *
	 * @param string $relative_path Path relative to the fixture root.
	 * @return string The absolute path created.
	 */
	private function make_fixture_directory( string $relative_path ): string {
		$path = $this->fixture_root . '/' . $relative_path;
		if ( ! is_dir( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture setup.
			mkdir( $path, 0o755, true );
		}
		return $path;
	}

	/**
	 * Plant a wp-config.php in the fixture root with recognisable contents.
	 *
	 * Stands in for the real file a site keeps its database password and
	 * authentication salts in. The contents are a sentinel so a test can prove
	 * a leak by reading them back through a hostile link, rather than merely
	 * asserting that a link exists.
	 *
	 * @return string The sentinel contents written.
	 */
	private function plant_wp_config(): string {
		$secret = "<?php define( 'DB_PASSWORD', 'sentinel-database-password' );";
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture setup.
		file_put_contents( $this->fixture_root . '/wp-config.php', $secret );
		return $secret;
	}

	/**
	 * Force a writer's cached case-sensitivity probe result via reflection.
	 *
	 * {@see FileWriter::$case_sensitive_destination} is a private, once-probed
	 * cache: {@see FileWriter::confinement_fold()} and the preflight in
	 * {@see FileWriter::assert_symlink_targets_confined()} both branch on it.
	 * Leaving it to whatever the real filesystem backing $this->fixture_root
	 * happens to be would make a fold test mean one thing on a case-folding
	 * host (macOS, Windows) and something else entirely on a case-sensitive
	 * one (Linux CI) — every fold test in this file forces the answer instead,
	 * so it means the same thing wherever it runs.
	 *
	 * @param FileWriter $writer         The writer whose cache to set.
	 * @param bool       $case_sensitive The value to force the cache to.
	 * @return void
	 */
	private static function force_case_sensitivity( FileWriter $writer, bool $case_sensitive ): void {
		$property = new ReflectionProperty( FileWriter::class, 'case_sensitive_destination' );
		$property->setValue( $writer, $case_sensitive );
	}

	/**
	 * Invoke the private confinement_fold() through reflection.
	 *
	 * @param FileWriter $writer The writer to call it on.
	 * @param string     $value  An archive-relative path, already normalised.
	 * @return string Whatever confinement_fold() returns.
	 */
	private static function confinement_fold_of( FileWriter $writer, string $value ): string {
		$method = new ReflectionMethod( FileWriter::class, 'confinement_fold' );
		return (string) $method->invoke( $writer, $value );
	}

	/**
	 * The proven attack: an intermediate hop link makes a textual check permit a leak of wp-config.php.
	 *
	 * Entry 1 points at the parent directory; entry 2's target reads, as text,
	 * as though its "hop/.." cancels out and leaves it inside uploads. The
	 * kernel follows "hop" first, so the ".." after it climbs one level HIGHER
	 * than the text suggests, and the link lands on the site's wp-config.php.
	 *
	 * @return void
	 */
	public function test_preflight_refuses_the_proven_hop_attack(): void {
		$writer = new FileWriter( $this->fixture_root );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( "this site's own wp-config.php" );

		$writer->assert_symlink_targets_confined(
			array(
				'wp-content/uploads/hop'      => '..',
				'wp-content/uploads/leak.txt' => 'hop/../wp-config.php',
			)
		);
	}

	/**
	 * The same attack refuses when the consumer link is declared FIRST.
	 *
	 * This is the case that must never be dropped for looking redundant. A
	 * guard that judged each link as it was written would let this one through:
	 * at the moment leak.txt is written, "hop" does not exist yet, so nothing
	 * about it looks wrong — and the kernel joins the two up the moment the
	 * second entry lands. Only a check that reasons over the archive's WHOLE
	 * declared set is independent of the order entries arrive in.
	 *
	 * @return void
	 */
	public function test_preflight_refuses_the_hop_attack_declared_in_the_other_order(): void {
		$writer = new FileWriter( $this->fixture_root );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( "this site's own wp-config.php" );

		$writer->assert_symlink_targets_confined(
			array(
				'wp-content/uploads/leak.txt' => 'hop/../wp-config.php',
				'wp-content/uploads/hop'      => '..',
			)
		);
	}

	/**
	 * A hop spelled in a different case is still recognised as the hop.
	 *
	 * Both macOS and Windows treat "HOP" and "hop" as one file. A lookup that only
	 * matched the exact bytes would miss this — and nothing is on disk during
	 * the preflight, so asking the filesystem would miss it too — leaving the
	 * component to resolve harmlessly on paper while the kernel joins the two
	 * links at write time. This is node-tar's CVE-2021-37701 in miniature, and
	 * the fold is the same fix.
	 *
	 * confinement_fold() is gated on the REAL destination
	 * ({@see self::destination_is_case_sensitive()}), so it folds at all only
	 * where the destination genuinely does — case-sensitivity is therefore
	 * forced by reflection rather than left to whatever filesystem happens to
	 * back this test's tempdir, or this test would mean "the fold works" on a
	 * case-folding host (macOS) and "no attack was even attempted" on a
	 * case-sensitive one (Linux CI).
	 *
	 * @return void
	 */
	public function test_preflight_refuses_a_hop_spelled_in_a_different_case(): void {
		$writer = new FileWriter( $this->fixture_root );
		self::force_case_sensitivity( $writer, false );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( "this site's own wp-config.php" );

		$writer->assert_symlink_targets_confined(
			array(
				'wp-content/uploads/hop'      => '..',
				'wp-content/uploads/leak.txt' => 'HOP/../wp-config.php',
			)
		);
	}

	/**
	 * Two declared links whose paths differ only in case, with different targets, refuse rather than guess.
	 *
	 * On a case-folding destination these two entries are the same file and
	 * only one of them survives; which one depends on write order, so what the
	 * kernel would eventually follow genuinely cannot be established here.
	 * An unknown in a containment guard is refused, never guessed.
	 *
	 * Case-sensitivity forced by reflection for the same reason as the sibling
	 * test above: this scenario only exists at all on a case-folding
	 * destination, and the test must mean that on every host it runs on.
	 *
	 * @return void
	 */
	public function test_preflight_refuses_case_colliding_declarations_with_different_targets(): void {
		$writer = new FileWriter( $this->fixture_root );
		self::force_case_sensitivity( $writer, false );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'differing only in letter case' );

		$writer->assert_symlink_targets_confined(
			array(
				'wp-content/uploads/Hop'      => '..',
				'wp-content/uploads/hOp'      => 'somewhere',
				'wp-content/uploads/leak.txt' => 'HOP/wp-config.php',
			)
		);
	}

	/**
	 * A consumer whose own target contains no parent segment at all is still refused.
	 *
	 * Worth its own case because it defeats any rule phrased as "refuse targets
	 * containing '..'". The whole climb lives in the FIRST link, which is
	 * perfectly legitimate on its own account (it lands on wp-content, a strict
	 * descendant of the site); the second target is a plain, innocent-looking
	 * relative path with no traversal in it anywhere, and it still reaches the
	 * directory holding every backup this site has taken.
	 *
	 * @return void
	 */
	public function test_preflight_refuses_a_consumer_whose_target_has_no_parent_segment(): void {
		$writer = new FileWriter( $this->fixture_root );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( "inside Pontifex's own working directory" );

		$writer->assert_symlink_targets_confined(
			array(
				'wp-content/uploads/hop'        => '..',
				'wp-content/uploads/leak.wpmig' => 'hop/pontifex/rollback/safety.wpmig',
			)
		);
	}

	/**
	 * A chain of three links that only escapes at its very end is refused.
	 *
	 * Each individual link in the chain stays inside the site; the escape is
	 * assembled out of all of them. Nothing short of following the chain the
	 * way the kernel does can see it.
	 *
	 * @return void
	 */
	public function test_preflight_refuses_a_three_link_chain_that_escapes_at_the_end(): void {
		$writer = new FileWriter( $this->fixture_root );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'is not inside the site at' );

		$writer->assert_symlink_targets_confined(
			array(
				'wp-content/uploads/a'    => 'b',
				'wp-content/uploads/b'    => 'c',
				'wp-content/uploads/c'    => '../../..',
				'wp-content/uploads/leak' => 'a/etc/passwd',
			)
		);
	}

	/**
	 * Two links pointing at each other are refused rather than resolved forever.
	 *
	 * Each is individually "inside the site", so a containment rule alone never
	 * fires; without the hop counter the resolver would substitute one for the
	 * other endlessly. A hang on a hostile archive would be a denial of service
	 * on a live site, so terminating is itself a safety property.
	 *
	 * @return void
	 */
	public function test_preflight_refuses_a_mutual_symlink_loop(): void {
		$writer = new FileWriter( $this->fixture_root );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'passed through more than 40 links' );

		$writer->assert_symlink_targets_confined(
			array(
				'wp-content/uploads/a' => 'b',
				'wp-content/uploads/b' => 'a',
			)
		);
	}

	/**
	 * A target that resolves to the site root ITSELF is refused, not merely one above it.
	 *
	 * Containment is strict descent. A link that is the root would redirect
	 * everything beneath it — every path a later entry writes would land
	 * wherever that link pointed instead.
	 *
	 * @return void
	 */
	public function test_preflight_refuses_a_target_that_is_the_site_root_itself(): void {
		$writer = new FileWriter( $this->fixture_root );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'is not inside the site at' );

		$writer->assert_symlink_targets_confined( array( 'wp-content/uploads/link' => '../..' ) );
	}

	/**
	 * A target that climbs above the site root is refused.
	 *
	 * @return void
	 */
	public function test_preflight_refuses_a_target_above_the_site_root(): void {
		$writer = new FileWriter( $this->fixture_root );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'is not inside the site at' );

		$writer->assert_symlink_targets_confined( array( 'wp-content/uploads/link' => '../../../etc/passwd' ) );
	}

	/**
	 * An absolute target is refused, because it is not measured against the site at all.
	 *
	 * @return void
	 */
	public function test_preflight_refuses_an_absolute_target(): void {
		$writer = new FileWriter( $this->fixture_root );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'is an absolute path' );

		$writer->assert_symlink_targets_confined( array( 'wp-content/uploads/link' => '/etc/passwd' ) );
	}

	/**
	 * A plain, direct link to wp-config.php is refused, with no hop involved.
	 *
	 * @return void
	 */
	public function test_preflight_refuses_a_direct_link_to_wp_config(): void {
		$writer = new FileWriter( $this->fixture_root );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( "this site's own wp-config.php" );

		$writer->assert_symlink_targets_confined( array( 'wp-content/uploads/leak.txt' => '../../wp-config.php' ) );
	}

	/**
	 * The wp-config.php refusal fires even when the file is not there yet.
	 *
	 * A migration onto a fresh destination has no wp-config.php at the moment
	 * the archive is checked. The refusal must not depend on the file's
	 * presence, or the very restore that most needs the guard would not get it.
	 *
	 * @return void
	 */
	public function test_preflight_refuses_a_link_to_wp_config_that_does_not_exist_yet(): void {
		$writer = new FileWriter( $this->fixture_root );
		$this->assertFileDoesNotExist( $this->fixture_root . '/wp-config.php', 'precondition: the destination has no wp-config.php yet' );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( "this site's own wp-config.php" );

		$writer->assert_symlink_targets_confined( array( 'wp-content/uploads/leak.txt' => '../../wp-config.php' ) );
	}

	/**
	 * A link into Pontifex's own working directory is refused.
	 *
	 * That directory holds this site's stored backups and safety archives, each
	 * one a copy of the whole database. A link to it from uploads would publish
	 * every backup the site has to anyone who can fetch a URL.
	 *
	 * @return void
	 */
	public function test_preflight_refuses_a_link_into_the_pontifex_working_directory(): void {
		$writer = new FileWriter( $this->fixture_root );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( "inside Pontifex's own working directory" );

		$writer->assert_symlink_targets_confined( array( 'wp-content/uploads/leak.wpmig' => '../pontifex/rollback/safety.wpmig' ) );
	}

	/**
	 * A link that escapes through a symlink ALREADY on the destination is refused.
	 *
	 * The archive index cannot see this one: the intermediate link is not in
	 * the archive at all, it is simply already on the site — left by an earlier
	 * restore, or by the site's own owner. This is the case the live-filesystem
	 * fallback exists for, and it is the cross-archive form recorded in
	 * CVE-2023-37460's advisory.
	 *
	 * @return void
	 */
	public function test_preflight_refuses_a_target_escaping_through_a_link_already_on_disk(): void {
		$writer  = new FileWriter( $this->fixture_root );
		$uploads = $this->make_fixture_directory( 'wp-content/uploads' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_symlink -- Test fixture setup; the pre-existing link is the subject under test.
		symlink( '../..', $uploads . '/existing' );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( "this site's own wp-config.php" );

		$writer->assert_symlink_targets_confined( array( 'wp-content/uploads/leak.txt' => 'existing/wp-config.php' ) );
	}

	/**
	 * A refusal writes nothing at all: the destination is untouched.
	 *
	 * The whole point of deciding before the walk starts is that a hostile
	 * archive leaves the site exactly as it was. This test uses a captured
	 * exception rather than expectException() because it has to assert
	 * something AFTER the throw, which expectException() cannot do; there is
	 * deliberately no self::fail() inside the try, since PHPUnit's own failure
	 * exception would be swallowed by the catch.
	 *
	 * @return void
	 */
	public function test_preflight_writes_nothing_when_it_refuses(): void {
		$writer = new FileWriter( $this->fixture_root );
		$thrown = null;

		try {
			$writer->assert_symlink_targets_confined(
				array(
					'wp-content/uploads/hop'      => '..',
					'wp-content/uploads/leak.txt' => 'hop/../wp-config.php',
				)
			);
		} catch ( RuntimeException $error ) {
			$thrown = $error;
		}

		$this->assertInstanceOf( RuntimeException::class, $thrown );
		$this->assertStringContainsString( "this site's own wp-config.php", $thrown->getMessage() );
		$remaining = array_values( array_diff( scandir( $this->fixture_root ), array( '.', '..' ) ) );
		$this->assertSame( array(), $remaining, 'a refused preflight must leave the destination byte-identical to its pre-restore state' );
	}

	/**
	 * The refusal names the link, its raw target, and the place it actually resolves to.
	 *
	 * An operator reading "resolves to /…/wp-config.php" needs no explanation
	 * of why the restore stopped; "a symlink was refused" would need several.
	 *
	 * @return void
	 */
	public function test_preflight_refusal_reports_the_link_target_and_where_it_resolves(): void {
		$writer = new FileWriter( $this->fixture_root );
		$thrown = null;

		try {
			$writer->assert_symlink_targets_confined(
				array(
					'wp-content/uploads/hop'      => '..',
					'wp-content/uploads/leak.txt' => 'hop/../wp-config.php',
				)
			);
		} catch ( RuntimeException $error ) {
			$thrown = $error;
		}

		$this->assertInstanceOf( RuntimeException::class, $thrown );
		$message = $thrown->getMessage();
		$this->assertStringContainsString( 'wp-content/uploads/leak.txt', $message );
		$this->assertStringContainsString( 'hop/../wp-config.php', $message );
		$this->assertStringContainsString( realpath( $this->fixture_root ) . '/wp-config.php', $message );
	}

	/**
	 * The attack is real: with --allow-unsafe-symlinks the preflight steps aside and the leak happens.
	 *
	 * This is the kernel's own verdict rather than the guard's. Both links are
	 * written, and reading the uploads file returns the planted wp-config.php
	 * contents — proving the corpus above is guarding against something that
	 * genuinely works, not against a theory. It also pins the escape hatch:
	 * the operator override must continue to waive the whole preflight.
	 *
	 * @return void
	 */
	public function test_allow_unsafe_symlinks_bypasses_the_preflight_and_the_kernel_then_leaks(): void {
		$writer = new FileWriter( $this->fixture_root, true );
		$secret = $this->plant_wp_config();
		$links  = array(
			'wp-content/uploads/hop'      => '..',
			'wp-content/uploads/leak.txt' => 'hop/../wp-config.php',
		);

		$writer->assert_symlink_targets_confined( $links );
		foreach ( $links as $path => $target ) {
			$writer->write_entry( self::symlink_result( $path, $target ) );
		}

		$leak = $this->fixture_root . '/wp-content/uploads/leak.txt';
		$this->assertTrue( is_link( $leak ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test assertion against an on-disk fixture; reading THROUGH the link is the point.
		$this->assertSame( $secret, file_get_contents( $leak ), 'the kernel really does follow the hop and hand back wp-config.php' );
	}

	/**
	 * A Composer-managed site's mu-plugins autoloader link is permitted and written.
	 *
	 * Composer keeps a WordPress install's dependencies in a vendor/ directory
	 * beside wp-content, not inside it, and reaches them by link. Refusing this
	 * shape makes that site's own backup unrestorable with no attacker
	 * involved; it is the false refusal that reverted an earlier attempt at
	 * this guard, and it is why containment is rooted at the site rather than
	 * at wp-content.
	 *
	 * @return void
	 */
	public function test_preflight_permits_the_composer_mu_plugins_autoload_link(): void {
		$writer = new FileWriter( $this->fixture_root );
		$link   = 'wp-content/mu-plugins/autoload.php';
		$target = '../../vendor/acme/lib/autoload.php';

		$writer->assert_symlink_targets_confined( array( $link => $target ) );
		$writer->write_entry( self::symlink_result( $link, $target ) );

		$this->assertTrue( is_link( $this->fixture_root . '/' . $link ) );
		$this->assertSame( $target, readlink( $this->fixture_root . '/' . $link ) );
	}

	/**
	 * The wp-content/languages link out to a sibling languages directory is permitted and written.
	 *
	 * @return void
	 */
	public function test_preflight_permits_the_languages_link(): void {
		$writer = new FileWriter( $this->fixture_root );
		$link   = 'wp-content/languages';
		$target = '../languages';

		$writer->assert_symlink_targets_confined( array( $link => $target ) );
		$writer->write_entry( self::symlink_result( $link, $target ) );

		$this->assertTrue( is_link( $this->fixture_root . '/' . $link ) );
		$this->assertSame( $target, readlink( $this->fixture_root . '/' . $link ) );
	}

	/**
	 * A plugin linked to a working copy outside wp-content but inside the site is permitted and written.
	 *
	 * @return void
	 */
	public function test_preflight_permits_a_plugin_linked_to_a_directory_beside_wp_content(): void {
		$writer = new FileWriter( $this->fixture_root );
		$link   = 'wp-content/plugins/myplugin';
		$target = '../../dev/myplugin';

		$writer->assert_symlink_targets_confined( array( $link => $target ) );
		$writer->write_entry( self::symlink_result( $link, $target ) );

		$this->assertTrue( is_link( $this->fixture_root . '/' . $link ) );
		$this->assertSame( $target, readlink( $this->fixture_root . '/' . $link ) );
	}

	/**
	 * Ordinary links that never leave wp-content are permitted and written.
	 *
	 * An uploads alias, a theme reaching into uploads, and a plugin reaching a
	 * sibling — the everyday shapes a real site accumulates.
	 *
	 * @return void
	 */
	public function test_preflight_permits_ordinary_links_inside_wp_content(): void {
		$writer = new FileWriter( $this->fixture_root );
		$links  = array(
			'wp-content/uploads/alias'        => '2026',
			'wp-content/themes/mytheme/img'   => '../../uploads/img',
			'wp-content/plugins/alpha/shared' => '../beta/shared',
		);

		$writer->assert_symlink_targets_confined( $links );
		foreach ( $links as $path => $target ) {
			$writer->write_entry( self::symlink_result( $path, $target ) );
			$this->assertSame( $target, readlink( $this->fixture_root . '/' . $path ) );
		}
	}

	/**
	 * A chain of links that stays inside the site all the way through is permitted.
	 *
	 * The hop machinery must not treat "resolved through another link" as
	 * suspicious in itself — only where the chain finally lands matters.
	 *
	 * @return void
	 */
	public function test_preflight_permits_a_chain_of_links_that_stays_inside(): void {
		$writer = new FileWriter( $this->fixture_root );
		$links  = array(
			'wp-content/uploads/first'  => 'second',
			'wp-content/uploads/second' => '../themes/mytheme',
		);

		$writer->assert_symlink_targets_confined( $links );
		foreach ( $links as $path => $target ) {
			$writer->write_entry( self::symlink_result( $path, $target ) );
			$this->assertSame( $target, readlink( $this->fixture_root . '/' . $path ) );
		}
	}

	/**
	 * A link whose target does not exist yet is permitted.
	 *
	 * A link may legitimately dangle when it is written and be satisfied later
	 * — by a subsequent entry, by a `composer install` after the restore, or
	 * never. The rule asks WHERE a target resolves, never whether it is there,
	 * which is also what makes migration onto an empty destination work at all.
	 *
	 * @return void
	 */
	public function test_preflight_permits_a_target_that_does_not_exist_yet(): void {
		$writer = new FileWriter( $this->fixture_root );
		$link   = 'wp-content/uploads/link';
		$target = 'not-here-yet/file.txt';

		$writer->assert_symlink_targets_confined( array( $link => $target ) );
		$writer->write_entry( self::symlink_result( $link, $target ) );

		$this->assertTrue( is_link( $this->fixture_root . '/' . $link ) );
		$this->assertFileDoesNotExist( $this->fixture_root . '/wp-content/uploads/not-here-yet/file.txt' );
	}

	/**
	 * A link entry whose own PATH is hostile is left to write_entry(), not silently indexed.
	 *
	 * The preflight judges TARGETS. An entry path carrying a parent-directory
	 * segment is a different guard's business, and write_entry() refuses it
	 * with its own message when the walk reaches it — so it is dropped from the
	 * index here rather than being allowed to act as an intermediate hop for
	 * some other link. The assertion below is that the preflight neither
	 * refuses on its account nor lets it redirect anything.
	 *
	 * @return void
	 */
	public function test_preflight_ignores_a_link_whose_own_path_is_refused_elsewhere(): void {
		$writer = new FileWriter( $this->fixture_root );

		$writer->assert_symlink_targets_confined(
			array(
				'wp-content/uploads/../hop'   => '..',
				'wp-content/uploads/safe.txt' => 'hop/notes.txt',
			)
		);

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Refusing the entry path' );

		$writer->write_entry( self::symlink_result( 'wp-content/uploads/../hop', '..' ) );
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

	/**
	 * The name the case-sensitivity probe actually builds is recognised as an orphan.
	 *
	 * The destination_is_case_sensitive() probe file exists on disk for only
	 * the handful of instructions between its own file_put_contents() and its
	 * finally block's unlink() — there is no external vantage point from
	 * which a test could observe the transient name directly while the file
	 * exists, so this cannot be driven by, say, a glob() taken mid-call.
	 * {@see FileWriter::case_probe_basenames()} is the pure, side-effect-free
	 * name-construction logic that method calls to decide what to write and
	 * then remove; driving THAT method through reflection — rather than
	 * retyping the shape by hand into this test — proves this property
	 * against the real code that runs, not against this test's own mental
	 * model of it. See that method's own docblock for why it exists at all.
	 *
	 * Looped, for the same reason
	 * {@see self::test_temp_artefact_suffix_always_matches_the_orphan_pattern()}
	 * loops: a single sample could pass even if the construction were subtly
	 * inconsistent with what {@see \Pontifex\Filesystem\TempArtefact} expects,
	 * because both random_bytes() and TempArtefact::suffix() themselves vary
	 * per call.
	 *
	 * @return void
	 */
	public function test_case_probe_basename_is_recognised_as_an_orphan(): void {
		$method = new ReflectionMethod( FileWriter::class, 'case_probe_basenames' );

		for ( $i = 0; $i < 50; $i++ ) {
			$names = (array) $method->invoke( null );
			$this->assertTrue(
				TempArtefact::is_orphan_name( (string) $names[0] ),
				sprintf( 'The case-sensitivity probe\'s own basename "%s" must be recognised by TempArtefact::is_orphan_name().', $names[0] )
			);
		}
	}

	/**
	 * A probe-shaped orphan left at the installation root by a killed case-sensitivity probe is swept.
	 *
	 * {@see FileWriter::destination_is_case_sensitive()} always writes its
	 * probe file directly at $this->destination_root — never under the
	 * required prefix — so a restore killed between that write and its own
	 * finally's unlink() leaves the orphan exactly where
	 * {@see FileWriter::sweep_orphaned_temp_files()}'s REACH section already
	 * looks on a content-only restore: $this->destination_root's own
	 * immediate children, listed there specifically because a capability
	 * probe of this shape can fall in the installation root rather than
	 * under "wp-content". Before Task 1's fix this orphan carried no
	 * recognisable shape at all and would have survived every restore
	 * indefinitely; this proves it is now removed like any other of this
	 * writer's own temp artefacts.
	 *
	 * @return void
	 */
	public function test_sweep_removes_a_case_probe_orphan_at_the_installation_root_under_a_required_prefix(): void {
		$writer = new FileWriter( $this->fixture_root, false, 'wp-content' );
		$this->make_fixture_directory( 'wp-content' );

		$method = new ReflectionMethod( FileWriter::class, 'case_probe_basenames' );
		$names  = (array) $method->invoke( null );
		$orphan = $this->fixture_root . '/' . $names[0];
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture setup: stands in for a case-sensitivity probe abandoned mid-restore by a kill.
		file_put_contents( $orphan, '' );

		$removed = $writer->sweep_orphaned_temp_files();

		$this->assertSame( 1, $removed );
		$this->assertFileDoesNotExist( $orphan );
	}

	/**
	 * The probe's two derived names still differ only by the case of their letters, never by the newly-shared suffix.
	 *
	 * Proves the invariant the whole probe depends on, directly against
	 * {@see FileWriter::case_probe_basenames()}'s real output rather than a
	 * hand-typed example: strtolower() of one name must equal strtolower()
	 * of the other, so a case-FOLDING filesystem's file_exists() check for
	 * the flipped spelling still resolves to the very file just written —
	 * while the raw byte strings must still differ, so a case-SENSITIVE
	 * filesystem's identical check still reports two distinct files rather
	 * than one. Both properties held before {@see \Pontifex\Filesystem\TempArtefact}'s
	 * shared suffix was appended to each name; this pins that appending the
	 * SAME suffix, unflipped, to both spellings (rather than flipping the
	 * whole name including the suffix) could not have disturbed either one.
	 *
	 * "PontifexCaseProbe" always contributes at least one ASCII letter
	 * regardless of what random_bytes() happens to draw, so the two names
	 * are guaranteed to be byte-distinct on every run — this is not a
	 * property that could pass by luck the way a purely random comparison
	 * might, but the loop still guards against a construction-order mistake
	 * (e.g. flipping the suffix too) that only a particular hex draw would
	 * expose.
	 *
	 * @return void
	 */
	public function test_case_probe_names_differ_only_by_case(): void {
		$method = new ReflectionMethod( FileWriter::class, 'case_probe_basenames' );

		for ( $i = 0; $i < 50; $i++ ) {
			$names = (array) $method->invoke( null );

			$this->assertNotSame(
				$names[0],
				$names[1],
				'the two spellings must be byte-distinct, or a case-sensitive filesystem could never tell them apart'
			);
			$this->assertSame(
				strtolower( (string) $names[0] ),
				strtolower( (string) $names[1] ),
				'the two spellings must fold to the same name, or a case-insensitive filesystem could never tell they are "the same" file'
			);
		}
	}

	// -------------------------------------------------------------------
	// Unicode confinement fold (confinement_fold()) and its own preflight
	//
	// confinement_fold() replaced strtolower(), which folds ASCII only, with
	// Unicode D145 canonical caseless matching (NFD, case-fold, NFD again) —
	// but only on a destination that actually folds case at all
	// ({@see self::destination_is_case_sensitive()}). Every test below forces
	// that cached probe result by reflection rather than trusting whichever
	// filesystem happens to back $this->fixture_root on the machine running
	// it; see {@see self::force_case_sensitivity()}'s own docblock for why.
	//
	// The corpus in the first test below was measured against this build's
	// real ext-intl/mbstring tables with a throwaway script, not assumed —
	// two of the categories named in the brief that motivated this suite
	// (a literal Turkish DOTLESS "ı", and fullwidth Latin letters) do NOT
	// collide under confinement_fold(), and the second test below documents
	// why, with the measurement recorded alongside it rather than silently
	// dropped.
	// -------------------------------------------------------------------

	/**
	 * The Unicode case-folding attack corpus: real filesystem collisions strtolower() missed.
	 *
	 * Every pair below is two DIFFERENT byte strings that a real
	 * case-insensitive filesystem (APFS, NTFS) treats as naming the same
	 * file. Before this fix, {@see FileWriter::assert_symlink_targets_confined()}
	 * indexed declared links under strtolower(), which is ASCII-only, so an
	 * intermediate link named the "other" Unicode spelling of an already-
	 * declared name was invisible to the fold lookup — exactly the CVE-2021-37701
	 * hop-attack shape this class's own preflight defends against, just spelled
	 * with a character strtolower() does not touch. confinement_fold() must
	 * therefore fold every pair here to the same key.
	 *
	 * Each collision was verified against this build's actual ext-intl
	 * (Normalizer) and mbstring (mb_convert_case) tables:
	 *
	 *  - Kelvin sign (U+212A) folds to Latin "k" (Unicode CaseFolding.txt).
	 *  - German sharp s "ß" (U+00DF) folds to "ss" — only under FULL case
	 *    folding, which is what mb_convert_case( ..., MB_CASE_FOLD, ... )
	 *    turned out to apply here.
	 *  - Greek final sigma "ς" (U+03C2) folds to medial sigma "σ" (U+03C3).
	 *  - The Greek "prosgegrammeni" family: a capital vowel carrying an iota
	 *    subscript as ONE precomposed character (e.g. U+1FBC, U+1FCC) folds to
	 *    its lower-case DECOMPOSED form (base letter + combining
	 *    ypogegrammeni, U+1FB3 / U+1FC3) — the exact family
	 *    {@see FileWriter::confinement_fold()}'s own docblock names as
	 *    sensitive to fold/decompose ORDER, which is why it decomposes,
	 *    folds, then decomposes again rather than once.
	 *  - Turkish capital "İ" (LATIN CAPITAL LETTER I WITH DOT ABOVE, U+0130)
	 *    folds, under full folding, to "i" followed by an EXPLICIT combining
	 *    dot above (U+0069 U+0307) — not to a bare "i". This is the one
	 *    corpus entry that does not match the brief's own phrasing ("a
	 *    Turkish dotless ı for a dotted i") once measured: see the sibling
	 *    "does not over-merge" test below for the dotless letter, which has
	 *    no fold mapping at all.
	 *  - Two spellings whose only difference is the ORDER two combining marks
	 *    of different combining classes were written in. Unicode canonical
	 *    reordering — applied by the SECOND Normalizer::normalize() call
	 *    inside confinement_fold() — sorts them into the same sequence
	 *    regardless of the order they arrived in.
	 *
	 * @return void
	 */
	public function test_confinement_fold_unifies_unicode_case_folding_attack_spellings(): void {
		$writer = new FileWriter( $this->fixture_root );
		self::force_case_sensitivity( $writer, false );

		$corpus = array(
			'Kelvin sign vs Latin K'                    => array( "wp-content/uploads/30\u{212A}-report.pdf", 'wp-content/uploads/30K-report.pdf' ),
			'German sharp s (ß) vs "ss"'                => array( 'wp-content/themes/straße/style.css', 'wp-content/themes/strasse/style.css' ),
			'Greek final sigma (ς) vs medial sigma (σ)' => array( "wp-content/uploads/logo\u{03C2}.svg", "wp-content/uploads/logo\u{03C3}.svg" ),
			'Greek ALPHA WITH PROSGEGRAMMENI vs alpha+ypogegrammeni' => array( "wp-content/uploads/\u{1FBC}-notes.txt", "wp-content/uploads/\u{1FB3}-notes.txt" ),
			'Greek ETA WITH PROSGEGRAMMENI vs eta+ypogegrammeni' => array( "wp-content/uploads/\u{1FCC}-notes.txt", "wp-content/uploads/\u{1FC3}-notes.txt" ),
			'Turkish İ (dotted capital) vs "i" + combining dot above' => array( "wp-content/uploads/\u{0130}stanbul", "wp-content/uploads/i\u{0307}stanbul" ),
			'Combining marks in reversed order (diaeresis, cedilla)' => array( "wp-content/uploads/a\u{0308}\u{0327}-file", "wp-content/uploads/a\u{0327}\u{0308}-file" ),
		);

		foreach ( $corpus as $label => $pair ) {
			list( $spelling_a, $spelling_b ) = $pair;

			$this->assertNotSame( $spelling_a, $spelling_b, "fixture bug in \"$label\": the two spellings must be different byte strings to prove anything" );
			$this->assertSame(
				self::confinement_fold_of( $writer, $spelling_a ),
				self::confinement_fold_of( $writer, $spelling_b ),
				"\"$label\": a case-insensitive destination must fold both spellings to the same key"
			);
		}
	}

	/**
	 * Two categories that resemble the attack corpus above must NOT be merged by confinement_fold().
	 *
	 * Measured, not assumed, against this build's real tables:
	 *
	 *  - Fullwidth Latin ("Ｋ", U+FF2B) resembles ASCII "K" but the
	 *    relationship is a COMPATIBILITY equivalence (NFKC/NFKD), not a case
	 *    one. {@see FileWriter::confinement_fold()}'s own docblock explains
	 *    this was deliberately rejected: compatibility normalisation "merges
	 *    pairs — the fullwidth Latin block against ordinary ASCII, for one —
	 *    that the destination filesystem itself keeps apart, which would
	 *    refuse legitimate archives no attacker had any part in." Measured
	 *    directly here: Normalizer::FORM_D, what confinement_fold() actually
	 *    calls, does not touch this pair.
	 *  - The Turkish DOTLESS small "ı" (U+0131) has no case-fold mapping at
	 *    all in the locale-independent Unicode tables PHP's Normalizer and
	 *    mbstring apply here (only a Turkic-LOCALE-specific mapping exists,
	 *    and it is not applied) — it folds to itself, so it never collides
	 *    with a plain "i". This is why the corpus above uses the DOTTED
	 *    capital "İ" for its Turkish entry instead.
	 *
	 * @return void
	 */
	public function test_confinement_fold_does_not_over_merge_fullwidth_or_turkish_dotless_i(): void {
		$writer = new FileWriter( $this->fixture_root );
		self::force_case_sensitivity( $writer, false );

		$pairs = array(
			'Fullwidth Latin K (compatibility form) vs ASCII K' => array( "wp-content/uploads/\u{FF2B}-copy.txt", 'wp-content/uploads/K-copy.txt' ),
			'Turkish dotless ı (no fold mapping) vs ASCII i'    => array( "wp-content/uploads/\u{0131}stanbul", 'wp-content/uploads/istanbul' ),
		);

		foreach ( $pairs as $label => $pair ) {
			list( $spelling_a, $spelling_b ) = $pair;

			$this->assertNotSame(
				self::confinement_fold_of( $writer, $spelling_a ),
				self::confinement_fold_of( $writer, $spelling_b ),
				"\"$label\": confinement_fold() must not merge these — a real case-insensitive filesystem does not, so merging them would refuse a legitimate archive over nothing"
			);
		}
	}

	/**
	 * On a case-SENSITIVE destination, confinement_fold() is the identity for every spelling.
	 *
	 * This is what stops the fix from becoming a false-refusal outage on
	 * Linux, the overwhelming majority of WordPress hosting: the audit that
	 * drove this fix measured an UNGATED fold — one that folded regardless of
	 * the real destination — refusing 18 of 18 real legitimate backups there.
	 * Includes spellings drawn from the attack corpus above, to prove the
	 * SAME two strings that collide on a case-insensitive destination stay
	 * genuinely distinct on a case-sensitive one.
	 *
	 * @return void
	 */
	public function test_confinement_fold_is_the_identity_on_a_case_sensitive_destination(): void {
		$writer = new FileWriter( $this->fixture_root );
		self::force_case_sensitivity( $writer, true );

		$paths = array(
			'wp-content/uploads/Straße/style.css',
			'wp-content/uploads/30K-report.pdf',
			"wp-content/uploads/logo\u{03C2}.svg",
			'wp-content/Uploads',
			'wp-content/uploads',
		);

		foreach ( $paths as $path ) {
			$this->assertSame( $path, self::confinement_fold_of( $writer, $path ), 'On a case-sensitive destination, confinement_fold() must return the value completely unchanged.' );
		}

		$this->assertNotSame(
			self::confinement_fold_of( $writer, 'wp-content/Uploads' ),
			self::confinement_fold_of( $writer, 'wp-content/uploads' ),
			'Two genuinely distinct spellings must not collide on a case-sensitive destination — a false refusal here is worse than the attack this fold defends against.'
		);
	}

	/**
	 * The proven hop attack, spelled with a non-ASCII Unicode case variant instead of an ASCII one.
	 *
	 * Sibling to {@see self::test_preflight_refuses_a_hop_spelled_in_a_different_case()},
	 * which strtolower() ALSO would have caught — ASCII-only folding is
	 * already enough for "HOP" vs "hop". This is the actual gap
	 * confinement_fold() closes: the leak entry names the hop using Greek
	 * final sigma "ς", where the hop itself was declared with medial sigma
	 * "σ". The two are unrelated by ASCII case — strtolower() treats them as
	 * two different names and would have let this straight through — on
	 * exactly the filesystems (APFS, NTFS) that fold them to one, the ones
	 * this whole preflight exists to defend.
	 *
	 * @return void
	 */
	public function test_preflight_refuses_a_hop_spelled_with_a_non_ascii_case_variant(): void {
		$writer = new FileWriter( $this->fixture_root );
		self::force_case_sensitivity( $writer, false );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( "this site's own wp-config.php" );

		$writer->assert_symlink_targets_confined(
			array(
				"wp-content/uploads/hop\u{03C3}" => '..',
				'wp-content/uploads/leak.txt'    => "hop\u{03C2}/../wp-config.php",
			)
		);
	}

	/**
	 * On a case-insensitive destination, a missing host fact refuses the whole restore, naming what is missing.
	 *
	 * This is {@see FileWriter::assert_symlink_targets_confined()}'s OWN
	 * preflight, gated first on whether the destination folds case at all
	 * (see that method's docblock) — forced true by reflection here, since
	 * that is the only branch where a missing extension matters.
	 *
	 * @return void
	 */
	public function test_preflight_refuses_when_destination_folds_case_and_the_host_cannot_fold_unicode(): void {
		$writer = new FileWriter(
			$this->fixture_root,
			false,
			null,
			null,
			null,
			static function (): array {
				return array( 'the "intl" extension, which provides the Normalizer class' );
			}
		);
		self::force_case_sensitivity( $writer, false );

		$this->expectException( HostCannotComply::class );
		$this->expectExceptionMessage( 'the "intl" extension, which provides the Normalizer class' );

		$writer->assert_symlink_targets_confined( array() );
	}

	/**
	 * The extension refusal is HostCannotComply, never ArchiveNotTrustworthy.
	 *
	 * ADR 0022's whole point: this host cannot check a perfectly sound
	 * archive, which is a host limitation, not a verdict on the backup.
	 * Reporting a good backup as broken is the one message capable of
	 * talking someone out of keeping it.
	 *
	 * @return void
	 */
	public function test_preflight_extension_refusal_is_host_cannot_comply_not_archive_not_trustworthy(): void {
		$writer = new FileWriter(
			$this->fixture_root,
			false,
			null,
			null,
			null,
			static function (): array {
				return array( 'the "mbstring" extension' );
			}
		);
		self::force_case_sensitivity( $writer, false );

		$thrown = null;
		try {
			$writer->assert_symlink_targets_confined( array() );
		} catch ( RuntimeException $error ) {
			$thrown = $error;
		}

		$this->assertInstanceOf( HostCannotComply::class, $thrown );
		$this->assertNotInstanceOf( ArchiveNotTrustworthy::class, $thrown );
	}

	/**
	 * On a case-SENSITIVE destination, the same missing-extension report must NOT refuse the restore.
	 *
	 * Confinement_fold() never touches either extension on a destination that
	 * does not fold case, so this host was never exposed to the gap they
	 * close. Refusing it anyway would turn a security fix into an outage for
	 * a Linux site that was already safe — exactly the false-refusal risk the
	 * gate in {@see FileWriter::assert_symlink_targets_confined()} exists to
	 * avoid.
	 *
	 * @return void
	 */
	public function test_preflight_does_not_refuse_for_a_missing_extension_on_a_case_sensitive_destination(): void {
		$writer = new FileWriter(
			$this->fixture_root,
			false,
			null,
			null,
			null,
			static function (): array {
				return array( 'the "intl" extension, which provides the Normalizer class', 'the "mbstring" extension' );
			}
		);
		self::force_case_sensitivity( $writer, true );

		$writer->assert_symlink_targets_confined( array() );

		$this->assertTrue( true, 'assert_symlink_targets_confined() must return normally, without consulting the extension-availability closure at all, on a case-sensitive destination.' );
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
		$this->expectExceptionMessage( 'Refusing the entry path' );

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
		$this->expectExceptionMessage( 'Refusing the entry path' );

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
		$this->expectExceptionMessage( 'Refusing the entry path' );

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
		$this->expectExceptionMessage( 'Refusing the entry path' );

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
	 * A directory the archive records as unwritable still receives its contents.
	 *
	 * Directory entries sort ahead of the files inside them, so applying a
	 * restrictive mode when the directory is written made it unwritable before
	 * those files arrived. A source site with a hardened
	 * `wp-content/uploads/private` at 0555 — a documented WordPress lockdown
	 * step, and what several security plugins apply — exported happily, because
	 * 0555 is readable, and then failed its own restore on the very next entry.
	 *
	 * Nothing preflights directory modes, so the refusal landed mid-walk: the
	 * one place with no recovery, because the file half is a merge with no
	 * per-entry undo. Everything sorting before that directory was already the
	 * archive's content and everything after was still the old site.
	 *
	 * @return void
	 */
	public function test_a_read_only_directory_still_receives_the_files_inside_it(): void {
		$writer = new FileWriter( $this->fixture_root );

		$writer->write_entry( self::directory_result( 'wp-content/uploads/private', 0o555 ) );
		$writer->write_entry( self::file_result( 'wp-content/uploads/private/secret.txt', 'kept' ) );

		$path = $this->fixture_root . '/wp-content/uploads/private/secret.txt';
		$this->assertFileExists( $path, 'A file inside a 0555 directory must still be restored.' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a fixture file from the temp tree; not an HTTP request.
		$this->assertSame( 'kept', file_get_contents( $path ) );
	}

	/**
	 * The recorded mode is applied once the walk finishes, not before.
	 *
	 * The counterweight: holding the mode back must not mean losing it. A
	 * directory the archive says is 0555 has to end up 0555, or the restore has
	 * quietly relaxed the source site's permissions.
	 *
	 * @return void
	 */
	public function test_a_held_back_directory_mode_is_applied_after_the_walk(): void {
		$writer = new FileWriter( $this->fixture_root );

		$writer->write_entry( self::directory_result( 'wp-content/uploads/private', 0o555 ) );
		$writer->write_entry( self::file_result( 'wp-content/uploads/private/secret.txt', 'kept' ) );

		$dir = $this->fixture_root . '/wp-content/uploads/private';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Asserting the real mode on the temp fixture tree is the behaviour under test.
		$this->assertTrue( is_writable( $dir ), 'The directory stays writable while the walk is in progress.' );

		$this->assertSame( array(), $writer->finalise_directory_modes(), 'Every held-back mode applies cleanly here.' );

		clearstatcache( true, $dir );
		$this->assertSame( 0o555, fileperms( $dir ) & 0o777, 'The archive\'s recorded mode is what the directory ends at.' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Test teardown: the fixture tree must be removable.
		chmod( $dir, 0o755 );
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

		$entries = array( self::manifest_file_entry( 'big.iso', $ten_mb ) );
		$writer->assert_free_space_for( $entries, self::decoded_sizes_matching_length( $entries ) );
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

		$entries = array( self::manifest_file_entry( 'medium.bin', $five_mb ) );
		$result  = $writer->assert_free_space_for( $entries, self::decoded_sizes_matching_length( $entries ) );

		$this->assertTrue( $result, 'Ample free space must not refuse the restore, and a real reading was taken.' );
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

		$writer->assert_free_space_for( $entries, self::decoded_sizes_matching_length( $entries ) );
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

		$result = $writer->assert_free_space_for( $entries, self::decoded_sizes_matching_length( $entries ) );

		$this->assertTrue( $result, 'A rollback where every file already matches must be permitted despite the low free space, and a real reading was taken.' );
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

		$writer->assert_free_space_for( $entries, self::decoded_sizes_matching_length( $entries ) );
	}

	/**
	 * An unknown free-space reading (false, e.g. under open_basedir) must never refuse the restore, and reports that no reading was taken.
	 *
	 * Matches the posture {@see \Pontifex\Rollback\SafetyArchiver::preflight_disk_space()}
	 * already takes: an unknown must never become a refusal. The return value
	 * is what this restore-time caller ignores (see RestoreRunner::restore()
	 * and the import dry run) but {@see \Pontifex\Restore\RestorePreflight::read_only_report()}
	 * reads, to tell an operator the check could not be answered rather than
	 * silently calling the destination fine.
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
		$entries = array( self::manifest_file_entry( 'huge.bin', 500 * 1024 * 1024 ) );
		$result  = $writer->assert_free_space_for( $entries, self::decoded_sizes_matching_length( $entries ) );

		$this->assertFalse( $result, 'An unknown free-space reading must not refuse the restore, and must report that no reading was taken.' );
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

		$result = $writer->assert_free_space_for( $entries, self::decoded_sizes_matching_length( $entries ) );
		$this->assertTrue( $result, 'The hostile entry must be skipped, not treated as a disk-space shortfall, and a real reading was taken for the entry that remains.' );

		// write_entry() must still be the one to refuse that same hostile entry,
		// with its own path-safety message, once the walk actually reaches it.
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'parent-directory segment' );
		$writer->write_entry( self::file_result( '../escape.bin', 'forged' ) );
	}

	/**
	 * The headline regression test: the preflight budgets the DECODED total, never the entry's stored/compressed length.
	 *
	 * A manifest entry's length() here is a tiny 1 KB — standing in for a
	 * highly-compressible stored record — while the caller-supplied decoded
	 * size for that same path is a full 50 MB, standing in for what the
	 * restore will actually write once decoded. Only 2 MB is reported free:
	 * comfortably more than the 1 KB stored figure, nowhere near the 50 MB
	 * decoded one. If this method ever went back to weighing length()
	 * instead of $decoded_sizes, "needed" would collapse to 1 KB, 2 MB would
	 * easily cover it, and this test would fail to throw at all — which is
	 * exactly the shape of the defect this method exists to fix (measured on
	 * a real database-heavy archive: a 1.4 MB stored budget against 123 MB
	 * actually written).
	 *
	 * @return void
	 */
	public function test_assert_free_space_for_budgets_the_decoded_total_not_the_compressed_length(): void {
		$stored_length = 1024;
		$decoded_bytes = 50 * 1024 * 1024;
		$two_mb        = 2 * 1024 * 1024;

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
		$this->expectExceptionMessage( 'needs about 50 MB free, and only 2 MB is available' );

		$writer->assert_free_space_for(
			array( self::manifest_file_entry( 'db-heavy.dat.gz', $stored_length ) ),
			array( 'db-heavy.dat.gz' => $decoded_bytes )
		);
	}

	/**
	 * The positive counterpart: a decoded total the free space DOES cover is permitted, even though the manifest's own compressed length disagrees wildly.
	 *
	 * Free space sits between the two figures — comfortably above the
	 * decoded 3 MB this test cares about, and nowhere near what the stored
	 * 200-byte length would suggest is needed if it (wrongly) governed the
	 * comparison in the other direction. Proves the fix is not simply "always
	 * weigh the bigger of the two numbers" — the stored length must play no
	 * part in the arithmetic at all, only the decoded figure the caller supplies.
	 *
	 * @return void
	 */
	public function test_assert_free_space_for_permits_when_decoded_total_fits_despite_tiny_compressed_length(): void {
		$stored_length = 200;
		$decoded_bytes = 3 * 1024 * 1024;
		$ten_mb        = 10 * 1024 * 1024;

		$writer = new FileWriter(
			$this->fixture_root,
			false,
			null,
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Must match the injected Closure(string): (float|false) contract; this fake reader ignores which path is being asked about and always answers with the fixed figure under test.
			static function ( string $path ) use ( $ten_mb ) {
				return $ten_mb;
			}
		);

		$result = $writer->assert_free_space_for(
			array( self::manifest_file_entry( 'options-table.dat.gz', $stored_length ) ),
			array( 'options-table.dat.gz' => $decoded_bytes )
		);

		$this->assertTrue( $result, 'A decoded total the free space covers must be permitted, whatever the compressed length says.' );
	}

	/**
	 * A file entry with no corresponding $decoded_sizes entry is a caller contract violation, refused loudly rather than silently under-measured.
	 *
	 * The caller ({@see \Pontifex\Restore\RestorePreflight::declared_file_sizes()})
	 * always builds a decoded size for every file entry it hands to this
	 * method, so this can only happen if a caller passes a $decoded_sizes map
	 * that does not match $manifest_entries. Falling back to 0 or to length()
	 * would silently reintroduce a fresh way to under-measure — precisely
	 * what this method exists to stop doing — so a missing entry fails loudly
	 * instead.
	 *
	 * @return void
	 */
	public function test_assert_free_space_for_refuses_a_file_entry_missing_from_decoded_sizes(): void {
		$writer = new FileWriter( $this->fixture_root );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'No decoded size was supplied for file entry "note.txt"' );

		$writer->assert_free_space_for( array( self::manifest_file_entry( 'note.txt', 1000 ) ), array() );
	}

	// -------------------------------------------------------------------
	// assert_symlinks_creatable() — the host symlink-capability preflight
	//
	// A separate concern from assert_symlink_targets_confined() above: that
	// method asks whether a declared target is SAFE; this one asks whether
	// this host can create a symlink AT ALL. The corpus below injects a fake
	// probe via the constructor's fifth parameter for the pass/refuse cases
	// (a real host cannot be made to reliably refuse symlink() on demand),
	// and separately exercises the real, uninjected probe to prove it leaves
	// no artefact behind.
	// -------------------------------------------------------------------

	/**
	 * An archive declaring NO symlinks is permitted even when the probe itself would refuse.
	 *
	 * This is the false-refusal guard and the single most important test in
	 * this section: an archive with no symlinks at all must restore
	 * perfectly well on a host that cannot create them, so the probe must
	 * never even run when there is nothing to create. Counting calls rather
	 * than asserting the return value (a void method's assertNull() can never
	 * fail, so it would prove nothing) is what actually pins that: the
	 * injected fake always returns false, which would refuse every OTHER test
	 * in this section, so zero calls is the only honest evidence that an
	 * empty array short-circuits before the probe is ever consulted.
	 *
	 * @return void
	 */
	public function test_assert_symlinks_creatable_permits_empty_declared_links_even_when_probe_would_refuse(): void {
		$probe_calls = 0;
		$writer      = new FileWriter(
			$this->fixture_root,
			false,
			null,
			null,
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Must match the injected Closure(string): bool contract; this fake probe counts calls via the captured $probe_calls reference and never needs to inspect which directory was asked about.
			static function ( string $directory ) use ( &$probe_calls ): bool {
				++$probe_calls;
				return false;
			}
		);

		$writer->assert_symlinks_creatable( array() );

		$this->assertSame( 0, $probe_calls, 'An archive with no declared symlinks must never consult the probe, whatever it would have said.' );
	}

	/**
	 * A refusing probe with at least one declared link refuses the restore, naming what is wrong.
	 *
	 * @return void
	 */
	public function test_assert_symlinks_creatable_refuses_when_probe_refuses(): void {
		$writer = new FileWriter(
			$this->fixture_root,
			false,
			null,
			null,
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Must match the injected Closure(string): bool contract; this fake probe ignores which destination root is being asked about and always answers with the fixed outcome under test.
			static function ( string $destination_root ): bool {
				return false;
			}
		);

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'this host could not create a test link' );

		$writer->assert_symlinks_creatable( array( 'wp-content/uploads/link' => 'target.txt' ) );
	}

	/**
	 * The probe asks about the directory each link is created in, never the destination root.
	 *
	 * This is the test that pins the whole preflight to the right question, and
	 * it exists because the first implementation asked the wrong one. The
	 * destination root is the WordPress installation root: a content-only
	 * restore never writes there at all, because every entry lands under
	 * wp-content and each symlink is created in its own parent directory. So
	 * probing the root gets the answer wrong in BOTH directions, and both were
	 * reproduced on a real filesystem. An installation root at 0o555 with
	 * wp-content at 0o755 — the standard WordPress hardening posture — restores
	 * symlinks under wp-content perfectly well, yet a root-probing preflight
	 * refuses it, and that refusal then cascades: the safety archive has already
	 * been taken, recovery builds another writer on the same root, hits the same
	 * refusal, and the operator is told their site may be partially restored
	 * when nothing whatsoever was touched. The mirror case is worse: a writable
	 * root with an unwritable uploads directory PASSES a root probe, overwrites
	 * live files, and then dies mid-walk on the first symlink — the exact
	 * half-restored site this preflight exists to prevent.
	 *
	 * The two links share a directory deliberately: asserting the probe was
	 * consulted exactly ONCE also pins the deduplication, so an archive with
	 * thousands of links in one directory cannot turn the preflight into
	 * thousands of filesystem writes.
	 *
	 * @return void
	 */
	public function test_assert_symlinks_creatable_probes_the_links_own_directory_not_the_destination_root(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture setup; WP_Filesystem is not available in PHPUnit context.
		mkdir( $this->fixture_root . '/wp-content/uploads', 0o755, true );
		// FileWriter resolves its destination root, so the expected probe path must be
		// resolved too: on macOS /var is a symlink to /private/var, and comparing an
		// unresolved fixture path against a resolved one fails for a reason that has
		// nothing to do with the behaviour under test.
		$link_parent = realpath( $this->fixture_root ) . '/wp-content/uploads';

		$probed = array();
		$writer = new FileWriter(
			$this->fixture_root,
			false,
			null,
			null,
			static function ( string $directory ) use ( &$probed ): bool {
				$probed[] = $directory;
				return true;
			}
		);

		$writer->assert_symlinks_creatable(
			array(
				'wp-content/uploads/first'  => 'a.txt',
				'wp-content/uploads/second' => 'b.txt',
			)
		);

		$this->assertSame(
			array( $link_parent ),
			$probed,
			'The preflight must probe the directory the links are actually created in, exactly once, not the destination root.'
		);
	}

	/**
	 * A link whose own directory does not exist yet is probed at its nearest existing ancestor.
	 *
	 * The walk creates directories as it goes, so a link's parent routinely
	 * does not exist when the preflight runs. Probing a directory that is not
	 * there would fail for a reason that has nothing to do with symlink
	 * capability — a false refusal of a restore that would have succeeded,
	 * which is the failure mode this project has shipped three times and must
	 * not ship again. Walking up to the nearest directory that DOES exist keeps
	 * the answer meaningful: it is the same filesystem and the same permission
	 * regime the new directory will inherit.
	 *
	 * @return void
	 */
	public function test_assert_symlinks_creatable_probes_the_nearest_existing_ancestor_when_the_parent_does_not_exist_yet(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture setup; the uploads/ tree below this is deliberately absent, as it would be mid-restore.
		mkdir( $this->fixture_root . '/wp-content', 0o755, true );
		// Resolved, for the same reason as the sibling test above.
		$existing_ancestor = realpath( $this->fixture_root ) . '/wp-content';

		$probed = array();
		$writer = new FileWriter(
			$this->fixture_root,
			false,
			null,
			null,
			static function ( string $directory ) use ( &$probed ): bool {
				$probed[] = $directory;
				return true;
			}
		);

		$writer->assert_symlinks_creatable( array( 'wp-content/uploads/not/created/yet/link' => 'a.txt' ) );

		$this->assertSame(
			array( $existing_ancestor ),
			$probed,
			'A link whose parent does not exist yet must be probed at the nearest existing ancestor, never at a path that is not there.'
		);
	}

	/**
	 * A directory already sitting where a link is declared does not become the probed directory.
	 *
	 * The link's PARENT is what must be probed, never the link's own path. Every
	 * other test here is blind to the difference, because a link's own path does
	 * not normally exist on disk, so the ancestor walk climbs to the parent
	 * anyway and both readings agree. They diverge exactly when a directory
	 * already occupies the link's path — routine after a linker change, when a
	 * package manager replaces a real directory with a symlink — and there the
	 * wrong reading probes the wrong filesystem location and answers the wrong
	 * question. Giving that directory permissions its parent does not have is
	 * what makes the two readings give opposite verdicts.
	 *
	 * @return void
	 */
	public function test_assert_symlinks_creatable_probes_the_parent_even_when_a_directory_occupies_the_links_own_path(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture setup; a directory standing where the archive declares a symlink.
		mkdir( $this->fixture_root . '/wp-content/uploads/occupied', 0o755, true );
		$expected_parent = realpath( $this->fixture_root ) . '/wp-content/uploads';

		$probed = array();
		$writer = new FileWriter(
			$this->fixture_root,
			false,
			null,
			null,
			static function ( string $directory ) use ( &$probed ): bool {
				$probed[] = $directory;
				return true;
			}
		);

		$writer->assert_symlinks_creatable( array( 'wp-content/uploads/occupied' => 'a.txt' ) );

		$this->assertSame(
			array( $expected_parent ),
			$probed,
			'A directory standing where the link will be created must not be probed in place of the link\'s parent.'
		);
	}

	/**
	 * Several distinct directories comfortably inside the ceiling are each probed individually, never folded together.
	 *
	 * The companion, from below, of
	 * {@see self::test_assert_symlinks_creatable_falls_back_to_a_common_ancestor_beyond_the_probe_ceiling()}
	 * just below, which pins the ceiling from above with 200 directories — a
	 * count that clears any ceiling from 1 up to 199, so on its own it cannot
	 * tell a ceiling of 64 apart from a ceiling of 1. Five distinct,
	 * already-existing directories is the real Composer/pnpm-shaped layout this
	 * preflight's own docblock describes — not a hostile archive — and MUST
	 * still be probed one directory at a time: that is the precise
	 * per-directory capability check ADR 0021 depends on, and the whole reason
	 * the fallback below is a fallback rather than the rule. If the ceiling
	 * were silently lowered to, say, 1, this legitimate five-directory archive
	 * would collapse to the single, weaker deepest-common-ancestor probe
	 * instead — a host can permit symlink creation under one of the five
	 * directories while refusing it under another, and only probing each one
	 * separately can tell them apart.
	 *
	 * @return void
	 */
	public function test_assert_symlinks_creatable_probes_several_directories_individually_within_the_ceiling(): void {
		$expected_directories = array();
		$links                = array();

		for ( $index = 0; $index < 5; $index++ ) {
			$package_directory = 'wp-content/plugins/shop/node_modules/.pnpm/pkg-' . $index;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture setup; every directory exists, as it would when restoring over the site the archive came from.
			mkdir( $this->fixture_root . '/' . $package_directory, 0o755, true );
			$links[ $package_directory . '/link' ] = 'target.txt';
			$expected_directories[]                = realpath( $this->fixture_root ) . '/' . $package_directory;
		}

		$probed = array();
		$writer = new FileWriter(
			$this->fixture_root,
			false,
			null,
			null,
			static function ( string $directory ) use ( &$probed ): bool {
				$probed[] = $directory;
				return true;
			}
		);

		$writer->assert_symlinks_creatable( $links );

		$this->assertCount(
			5,
			$probed,
			'Five distinct directories inside the ceiling must each be probed once; a single, folded-together probe means the fallback fired when it must not have.'
		);
		$this->assertEqualsCanonicalizing(
			$expected_directories,
			$probed,
			'Inside the ceiling, every distinct directory must be probed on its own — the shared-ancestor fallback is reserved for archives that exceed it.'
		);
	}

	/**
	 * Links spread across more directories than the ceiling fall back to a common ancestor, never a refusal.
	 *
	 * Refusing here was tried and was wrong: it broke the one path a user cannot
	 * be turned away from. Because directories that do not exist yet collapse to
	 * a single probe, a link-per-directory archive — a pnpm-shaped tree is
	 * exactly this — restored happily onto a fresh server where nothing existed,
	 * and was refused onto the site it was taken from, where every directory
	 * already existed and nothing collapsed. That is the rollback path. The
	 * fallback keeps the work bounded without ever producing that refusal.
	 *
	 * @return void
	 */
	public function test_assert_symlinks_creatable_falls_back_to_a_common_ancestor_beyond_the_probe_ceiling(): void {
		$links = array();
		$total = 200;

		for ( $index = 0; $index < $total; $index++ ) {
			$package_directory = 'wp-content/plugins/shop/node_modules/.pnpm/pkg-' . $index;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture setup; every directory exists, as it would when restoring over the site the archive came from.
			mkdir( $this->fixture_root . '/' . $package_directory, 0o755, true );
			$links[ $package_directory . '/link' ] = 'target.txt';
		}

		$probed = array();
		$writer = new FileWriter(
			$this->fixture_root,
			false,
			null,
			null,
			static function ( string $directory ) use ( &$probed ): bool {
				$probed[] = $directory;
				return true;
			}
		);

		$writer->assert_symlinks_creatable( $links );

		$this->assertSame(
			array( realpath( $this->fixture_root ) . '/wp-content/plugins/shop/node_modules/.pnpm' ),
			$probed,
			'Beyond the ceiling the preflight must probe one shared ancestor, not refuse and not probe each directory.'
		);
	}

	/**
	 * The refusal says nothing has changed, names the archive's symlink count, and suggests no false remedy.
	 *
	 * Neither raising a limit nor re-running with a flag actually fixes a
	 * host that structurally cannot create symlinks, so the message must not
	 * imply either one is a way forward.
	 *
	 * @return void
	 */
	public function test_assert_symlinks_creatable_refusal_message_names_the_problem_and_no_false_remedy(): void {
		$writer = new FileWriter(
			$this->fixture_root,
			false,
			null,
			null,
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Must match the injected Closure(string): bool contract; this fake probe ignores which destination root is being asked about and always answers with the fixed outcome under test.
			static function ( string $destination_root ): bool {
				return false;
			}
		);
		$thrown = null;

		try {
			$writer->assert_symlinks_creatable(
				array(
					'wp-content/uploads/link'  => 'target.txt',
					'wp-content/uploads/link2' => 'target2.txt',
				)
			);
		} catch ( RuntimeException $error ) {
			$thrown = $error;
		}

		$this->assertInstanceOf( RuntimeException::class, $thrown );
		$message = $thrown->getMessage();
		$this->assertStringContainsString( '2 symbolic link', $message );
		$this->assertStringContainsString( 'Nothing has been changed', $message );
		$this->assertStringNotContainsStringIgnoringCase( '--allow-unsafe-symlinks', $message );
		$this->assertStringNotContainsStringIgnoringCase( 'raise', $message );
		$this->assertStringNotContainsStringIgnoringCase( 'increase', $message );
	}

	/**
	 * A succeeding probe with declared links permits the restore to proceed, having actually been consulted.
	 *
	 * Counting calls rather than asserting the return value proves more than
	 * a void method's assertNull() ever could (that assertion is a
	 * tautology — it can never fail): a single declared link, in one
	 * directory, must consult the probe exactly once, neither skipping it
	 * nor probing it repeatedly.
	 *
	 * @return void
	 */
	public function test_assert_symlinks_creatable_permits_when_probe_succeeds(): void {
		$probe_calls = 0;
		$writer      = new FileWriter(
			$this->fixture_root,
			false,
			null,
			null,
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Must match the injected Closure(string): bool contract; this fake probe counts calls via the captured $probe_calls reference and never needs to inspect which directory was asked about.
			static function ( string $directory ) use ( &$probe_calls ): bool {
				++$probe_calls;
				return true;
			}
		);

		$writer->assert_symlinks_creatable( array( 'wp-content/uploads/link' => 'target.txt' ) );

		$this->assertSame( 1, $probe_calls, 'A single declared link in one directory must consult the probe exactly once.' );
	}

	/**
	 * The real (uninjected) probe leaves the destination root clean after a successful run.
	 *
	 * Exercises FileWriter's own default probe against the real filesystem —
	 * the fake-probe tests above never touch disk at all, so only this test
	 * can catch a leaked ".pontifex-symlink-probe-*" artefact.
	 *
	 * @return void
	 */
	public function test_real_symlink_probe_leaves_no_artefact_behind_on_success(): void {
		$writer = new FileWriter( $this->fixture_root );

		$writer->assert_symlinks_creatable( array( 'wp-content/uploads/link' => 'target.txt' ) );

		$remaining = array_values( array_diff( scandir( $this->fixture_root ), array( '.', '..' ) ) );
		$this->assertSame( array(), $remaining, 'the real probe must remove its own artefact from the destination root' );
	}

	/**
	 * The real probe implementation returns false, and leaves nothing behind, when the symlink() call itself fails.
	 *
	 * Points the probe at a destination fragment that was never created, so
	 * the real symlink() call fails for want of a parent directory —
	 * exercising probe_symlink_creation() directly (it is private and static)
	 * to prove its finally block neither throws nor leaves anything behind
	 * when there was never anything to remove.
	 *
	 * @return void
	 */
	public function test_real_symlink_probe_returns_false_and_leaves_no_artefact_when_symlink_call_fails(): void {
		$missing_directory = $this->fixture_root . '/does-not-exist';
		$this->assertDirectoryDoesNotExist( $missing_directory );

		$reflection = new ReflectionMethod( FileWriter::class, 'probe_symlink_creation' );
		$result     = $reflection->invoke( null, $missing_directory );

		$this->assertFalse( $result );
		$this->assertDirectoryDoesNotExist( $missing_directory, 'the probe must not have created the missing parent directory, or left anything inside it' );
	}

	// -------------------------------------------------------------------
	// sweep_orphaned_temp_files()
	// -------------------------------------------------------------------

	/**
	 * Build a filename shaped exactly like a temp artefact this writer's own
	 * producers create — the very shape sweep_orphaned_temp_files() is built
	 * to recognise.
	 *
	 * Deliberately calls uniqid() itself rather than embedding a hand-typed
	 * hex string: a hand-typed sample risks an accidental non-hex character
	 * slipping in (an 'h' or a 'g'), which would silently test the wrong
	 * thing. Going through the real generator, the same one production code
	 * uses, keeps every fixture built by this helper genuinely representative.
	 *
	 * @param string $stem The part of the name before the temp suffix, e.g. "photo.jpg" or ".symlink-probe".
	 * @return string $stem with a fresh, uniquely-suffixed temp shape appended.
	 */
	private static function orphaned_temp_name( string $stem ): string {
		return $stem . '.' . uniqid( 'pontifex-', true ) . '.tmp';
	}

	/**
	 * A file orphan a killed restore left behind is removed.
	 *
	 * Stands in for {@see FileWriter::write_file()}'s own sibling temp,
	 * abandoned by a restore that died between the write and the rename that
	 * would otherwise have completed it.
	 *
	 * @return void
	 */
	public function test_sweep_removes_a_file_orphan_left_by_a_killed_restore(): void {
		$writer = new FileWriter( $this->fixture_root );
		$dir    = $this->make_fixture_directory( 'wp-content/uploads' );
		$orphan = $dir . '/' . self::orphaned_temp_name( 'photo.jpg' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture setup.
		file_put_contents( $orphan, 'half-written bytes' );

		$removed = $writer->sweep_orphaned_temp_files();

		$this->assertSame( 1, $removed );
		$this->assertFileDoesNotExist( $orphan );
	}

	/**
	 * A dangling-symlink probe orphan is removed.
	 *
	 * {@see FileWriter::probe_symlink_creation()}'s own artefact is a symlink
	 * whose target is chosen to never exist, so is_file() reports false for
	 * it — this is the shape the isLink()-before-isFile() ordering in
	 * sweep_orphaned_temp_files() exists to catch.
	 *
	 * @return void
	 */
	public function test_sweep_removes_a_dangling_symlink_probe_orphan(): void {
		$writer = new FileWriter( $this->fixture_root );
		$dir    = $this->make_fixture_directory( 'wp-content/uploads' );
		$orphan = $dir . '/' . self::orphaned_temp_name( '.symlink-probe' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_symlink -- Test fixture setup; a dangling probe symlink is the subject under test.
		symlink( 'this-target-does-not-exist.tmp', $orphan );
		$this->assertTrue( is_link( $orphan ), 'precondition: the orphan must exist as a symlink' );
		$this->assertFalse( file_exists( $orphan ), 'precondition: the link must be dangling (its target does not exist)' );

		$removed = $writer->sweep_orphaned_temp_files();

		$this->assertSame( 1, $removed );
		$this->assertFalse( is_link( $orphan ), 'the dangling link itself must be gone' );
	}

	/**
	 * A resumable export's .part file is left alone.
	 *
	 * A `.part` file is live state a still-running export is writing to, not
	 * a restore artefact; deleting one would destroy real, unrecoverable work.
	 *
	 * Placed under wp-content/uploads, NOT wp-content/pontifex — mutation
	 * testing found that a fixture placed under wp-content/pontifex passes
	 * this test even when {@see \Pontifex\Filesystem\TempArtefact}'s orphan
	 * pattern is mutated to also match ".part", because
	 * {@see self::test_sweep_skips_pontifexs_own_working_directory()}'s
	 * prune removes that whole directory from the walk before the pattern is
	 * ever consulted. Placing it under uploads, which the sweep does walk,
	 * means this test actually exercises the pattern's own refusal to match
	 * ".part", rather than passing for the unrelated reason that the
	 * directory was never entered at all.
	 *
	 * @return void
	 */
	public function test_sweep_leaves_a_resumable_exports_part_file_alone(): void {
		$writer = new FileWriter( $this->fixture_root );
		$dir    = $this->make_fixture_directory( 'wp-content/uploads' );
		$part   = $dir . '/site-export.wpmig.part';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture setup.
		file_put_contents( $part, 'in-progress export state' );

		$removed = $writer->sweep_orphaned_temp_files();

		$this->assertSame( 0, $removed );
		$this->assertFileExists( $part );
	}

	/**
	 * A user's own similarly-named file is left alone.
	 *
	 * "notes.pontifex-backup.tmp" and "data.pontifex.tmp" have no uniqid()
	 * shape at all (no hex run followed by a dot and a decimal run), so
	 * neither is close to matching. "archive.pontifex-2024.01.tmp" and
	 * "db.pontifex-1.2.tmp" are closer — "2024" and "1" are legal hex runs,
	 * and both are followed by a dot and a decimal run — but neither reaches
	 * the eight-character floor {@see \Pontifex\Filesystem\TempArtefact}'s
	 * orphan pattern requires, which is exactly the false positive that floor exists to
	 * rule out; a real uniqid()-shaped hex run is always fourteen characters,
	 * so the floor never excludes a genuine artefact.
	 *
	 * @return void
	 */
	public function test_sweep_leaves_a_users_own_similarly_named_files_alone(): void {
		$writer = new FileWriter( $this->fixture_root );
		$dir    = $this->make_fixture_directory( 'wp-content/uploads' );
		$one    = $dir . '/notes.pontifex-backup.tmp';
		$two    = $dir . '/data.pontifex.tmp';
		$three  = $dir . '/archive.pontifex-2024.01.tmp';
		$four   = $dir . '/db.pontifex-1.2.tmp';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture setup.
		file_put_contents( $one, 'a' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture setup.
		file_put_contents( $two, 'b' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture setup.
		file_put_contents( $three, 'c' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture setup.
		file_put_contents( $four, 'd' );

		$removed = $writer->sweep_orphaned_temp_files();

		$this->assertSame( 0, $removed );
		$this->assertFileExists( $one );
		$this->assertFileExists( $two );
		$this->assertFileExists( $three );
		$this->assertFileExists( $four );
	}

	/**
	 * The sweep never descends through a symlink.
	 *
	 * The catastrophic-if-wrong case: a matching orphan sits under a real
	 * directory that is a SIBLING of the sweep root, reachable only through a
	 * symlink placed inside the sweep root. A sweep that followed the link
	 * would find and remove it; the correct sweep must not even look.
	 *
	 * Confined to the "wp-content" prefix deliberately, so the file under
	 * "outside/" is not also directly reachable by ordinary, non-symlinked
	 * traversal — the symlink is the ONLY path to it, which is what makes
	 * this test actually exercise the guard rather than passing for an
	 * unrelated reason (prefix confinement alone).
	 *
	 * @return void
	 */
	public function test_sweep_never_descends_through_a_symlink(): void {
		$writer = new FileWriter( $this->fixture_root, false, 'wp-content' );

		$outside = $this->make_fixture_directory( 'outside' );
		$orphan  = $outside . '/' . self::orphaned_temp_name( 'leftover' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture setup.
		file_put_contents( $orphan, 'must survive' );

		$content_dir = $this->make_fixture_directory( 'wp-content' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_symlink -- Test fixture setup; the sweep must never follow this link.
		symlink( '../outside', $content_dir . '/link' );

		$removed = $writer->sweep_orphaned_temp_files();

		$this->assertSame( 0, $removed, 'nothing reachable only through a symlink may ever be counted' );
		$this->assertFileExists( $orphan, 'a file reachable only through a symlink must never be swept' );
	}

	/**
	 * A symlinked sweep root itself is refused, not merely descended into carefully.
	 *
	 * {@see self::test_sweep_never_descends_through_a_symlink()} above places
	 * its symlink INSIDE the sweep root and proves the walk does not follow
	 * an interior link — a different case entirely, already protected by
	 * {@see RecursiveIteratorIterator}'s own `hasChildren( $allowLinks = false )`
	 * default. This test covers the case a follow-up adversarial mutation
	 * audit found completely unpinned: deleting the `is_link( $sweep_root )`
	 * guard from {@see FileWriter::sweep_orphaned_temp_files()} left all 1862
	 * tests in this project green, because no existing test ever made
	 * "wp-content" — the sweep root itself — a symlink.
	 *
	 * That guard exists because is_dir() FOLLOWS a symlink to ask whether its
	 * TARGET is a directory, so, without it, `is_dir( $sweep_root )` would
	 * report true for a symlinked "wp-content" and
	 * {@see RecursiveDirectoryIterator}'s own constructor would then open
	 * whatever the link resolves to just as readily as it opens a real
	 * directory — `hasChildren( $allowLinks = false )` only governs a symlink
	 * the walk MEETS while descending, never what it was HANDED as its own
	 * starting point. And {@see FileWriter::assert_no_symlinked_ancestor()}
	 * refuses every entry {@see FileWriter::write_entry()} is asked to write
	 * through a symlinked "wp-content", so in this exact layout the writer's
	 * own reach is zero, while the sweep's — without this guard — would have
	 * been the link's whole foreign target tree.
	 *
	 * @return void
	 */
	public function test_sweep_refuses_a_symlinked_sweep_root(): void {
		$writer = new FileWriter( $this->fixture_root, false, 'wp-content' );

		$outside = $this->make_fixture_directory( 'outside' );
		$orphan  = $outside . '/' . self::orphaned_temp_name( 'leftover' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture setup.
		file_put_contents( $orphan, 'must survive' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_symlink -- Test fixture setup; "wp-content" — the sweep root itself — is the symlink under test.
		symlink( 'outside', $this->fixture_root . '/wp-content' );

		$removed = $writer->sweep_orphaned_temp_files();

		$this->assertSame( 0, $removed, 'a symlinked sweep root must refuse the whole sweep' );
		$this->assertFileExists( $orphan, 'a file behind a symlinked sweep root must never be swept' );
	}

	/**
	 * A sweep root that RESOLVES outside the destination root, via a symlinked intermediate component, is refused — independently of the is_link() guard above.
	 *
	 * The realpath() block in {@see FileWriter::sweep_orphaned_temp_files()}
	 * is a SECOND, independent confinement guard, and the same mutation audit
	 * found it just as unpinned as the is_link() guard above: deleting the
	 * whole realpath() block also left all 1862 tests green, because
	 * {@see self::test_sweep_refuses_a_symlinked_sweep_root()} above trips
	 * `is_link( $sweep_root )` first and never reaches this code at all — no
	 * existing test exercised the realpath() block on its own.
	 *
	 * So this fixture is built deliberately to avoid that overlap: the sweep
	 * root's own FINAL path component ("wp-content") is a genuine directory,
	 * never itself a symlink, so `is_link( $sweep_root )` reads false and the
	 * first guard cannot be what refuses this case — proven below by an
	 * explicit precondition assertion, not merely assumed. What sends the
	 * sweep root somewhere outside the destination root instead is an
	 * INTERMEDIATE path component: required_prefix is set to
	 * "link/wp-content", and "link" is a symlink pointing at an entirely
	 * separate temporary directory that also happens to contain a
	 * "wp-content" child. is_dir( $sweep_root ) still reports true — the
	 * directory really is there, on the far side of the link — so the walk
	 * would otherwise proceed unhindered; only realpath() resolving the
	 * whole path the way the kernel actually would, and finding it lands
	 * outside $this->destination_root, is what refuses it.
	 *
	 * @return void
	 */
	public function test_sweep_refuses_a_sweep_root_that_resolves_outside_the_destination_root(): void {
		$outside_root    = sys_get_temp_dir() . '/pontifex-filewriter-test-outside-' . bin2hex( random_bytes( 8 ) );
		$outside_content = $outside_root . '/wp-content';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture setup; a real directory entirely outside the destination root, reached only via the symlinked "link" component below.
		mkdir( $outside_content, 0o755, true );
		$orphan = $outside_content . '/' . self::orphaned_temp_name( 'leftover' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture setup.
		file_put_contents( $orphan, 'must survive' );

		try {
			$writer = new FileWriter( $this->fixture_root, false, 'link/wp-content' );

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_symlink -- Test fixture setup; the INTERMEDIATE component "link" is the symlink under test, not the sweep root's own final component.
			symlink( $outside_root, $this->fixture_root . '/link' );

			$sweep_root = $this->fixture_root . '/link/wp-content';
			$this->assertFalse( is_link( $sweep_root ), 'precondition: the sweep root itself must not be a symlink, so the is_link() guard cannot be what refuses this case' );
			$this->assertTrue( is_dir( $sweep_root ), 'precondition: the sweep root must genuinely resolve to a real, existing directory, so is_dir() cannot be what refuses this case' );

			$removed = $writer->sweep_orphaned_temp_files();

			$this->assertSame( 0, $removed, 'a sweep root resolving outside the destination root must refuse the whole sweep' );
			$this->assertFileExists( $orphan, 'a file behind a sweep root that resolves outside the destination root must never be swept' );
		} finally {
			self::rmtree( $outside_root );
		}
	}

	/**
	 * Pontifex's own working directory is skipped.
	 *
	 * A matching name under wp-content/pontifex/jobs survives — the same
	 * guard {@see FileWriter::assert_not_pontifex_working_path()} enforces
	 * for entries being WRITTEN applies here to entries being SWEPT.
	 *
	 * @return void
	 */
	public function test_sweep_skips_pontifexs_own_working_directory(): void {
		$writer = new FileWriter( $this->fixture_root );
		$jobs   = $this->make_fixture_directory( 'wp-content/pontifex/jobs' );
		$orphan = $jobs . '/' . self::orphaned_temp_name( 'leftover' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture setup.
		file_put_contents( $orphan, 'must survive' );

		$removed = $writer->sweep_orphaned_temp_files();

		$this->assertSame( 0, $removed );
		$this->assertFileExists( $orphan );
	}

	/**
	 * Pontifex's own working directory is skipped under a REQUIRED PREFIX too.
	 *
	 * The test above proves this with $required_prefix null, where the sweep
	 * root and the destination root are the same directory, so the callback
	 * filter's relative-path slice — `substr( ..., strlen( $destination_root ) + 1 )`
	 * — and a hypothetical slice measured from the (narrower) sweep root
	 * instead would cut the string in exactly the same place; mutation
	 * testing found that swapping `strlen( $destination_root ) + 1` for
	 * `strlen( $sweep_root ) + 1` left that test, and the whole suite, green.
	 * Every restore this plugin actually ships runs with $required_prefix set
	 * to "wp-content" (the default, content-only mode), where the two lengths
	 * differ: sliced relative to the sweep root, this orphan's path would
	 * read as "pontifex/jobs/…", which
	 * {@see FileWriter::is_pontifex_working_path()} does not recognise (it
	 * expects "wp-content/pontifex/…"), so that mutation would walk straight
	 * into Pontifex's own jobs directory and delete a live
	 * JobStore/JobProgressLog temp file mid-write, on every content-only
	 * restore. Proving the guard holds under exactly the mode this plugin
	 * actually runs closes that gap.
	 *
	 * @return void
	 */
	public function test_sweep_skips_pontifexs_own_working_directory_under_a_required_prefix(): void {
		$writer = new FileWriter( $this->fixture_root, false, 'wp-content' );
		$jobs   = $this->make_fixture_directory( 'wp-content/pontifex/jobs' );
		$orphan = $jobs . '/' . self::orphaned_temp_name( 'leftover' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture setup.
		file_put_contents( $orphan, 'must survive' );

		$removed = $writer->sweep_orphaned_temp_files();

		$this->assertSame( 0, $removed );
		$this->assertFileExists( $orphan );
	}

	/**
	 * A required_prefix of "wp-content" confines the RECURSIVE walk to that subtree — but the installation root is still reached separately.
	 *
	 * This test used to plant its "outside the prefix" orphan directly at the
	 * destination root and assert it survived, on the theory that
	 * {@see FileWriter::assert_within_required_prefix()} refuses every WRITE
	 * outside that boundary, so nothing outside it could ever hold one of
	 * this writer's own temp files. An adversarial audit demonstrated that
	 * theory false: the capability probe behind
	 * {@see FileWriter::assert_symlinks_creatable()} can itself leave its
	 * dangling-symlink orphan directly at the installation root (see REACH in
	 * {@see FileWriter::sweep_orphaned_temp_files()}'s own docblock for the
	 * two real cases), so that orphan is now correctly swept too — this test
	 * proves the fix rather than the false assumption it used to encode. An
	 * orphan nested a level deeper still, outside "wp-content" entirely, sits
	 * somewhere neither the recursive walk nor the non-recursive
	 * installation-root scan ever reaches, and survives.
	 *
	 * @return void
	 */
	public function test_sweep_confines_the_recursive_walk_but_still_reaches_the_installation_root(): void {
		$writer = new FileWriter( $this->fixture_root, false, 'wp-content' );

		$root_orphan = $this->fixture_root . '/' . self::orphaned_temp_name( 'orphan' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture setup.
		file_put_contents( $root_orphan, 'at the installation root, e.g. a symlink-capability probe orphan' );

		$genuinely_outside_dir    = $this->make_fixture_directory( 'other' );
		$genuinely_outside_orphan = $genuinely_outside_dir . '/' . self::orphaned_temp_name( 'orphan' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture setup.
		file_put_contents( $genuinely_outside_orphan, 'nested outside both wp-content and the installation-root scan' );

		$content_dir   = $this->make_fixture_directory( 'wp-content' );
		$inside_orphan = $content_dir . '/' . self::orphaned_temp_name( 'leftover' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture setup.
		file_put_contents( $inside_orphan, 'inside the prefix' );

		$removed = $writer->sweep_orphaned_temp_files();

		$this->assertSame( 2, $removed );
		$this->assertFileDoesNotExist( $root_orphan, 'a probe orphan at the installation root must be swept too' );
		$this->assertFileExists( $genuinely_outside_orphan, 'an orphan nested outside both the prefix and the installation root must survive' );
		$this->assertFileDoesNotExist( $inside_orphan );
	}

	/**
	 * A directory named like a temp artefact survives and is not counted.
	 *
	 * This proves only the observable OUTCOME: a directory whose name happens
	 * to match the orphan pattern, and everything inside it, is left
	 * untouched and does not contribute to the returned count. It does NOT
	 * prove that isFile() is what produces that outcome — unlink() refuses a
	 * directory on every platform regardless of that guard, so this test
	 * cannot isolate isFile() from unlink()'s own refusal, and does not claim
	 * to.
	 *
	 * @return void
	 */
	public function test_sweep_leaves_a_directory_named_like_a_temp_file_alone(): void {
		$writer          = new FileWriter( $this->fixture_root );
		$uploads         = $this->make_fixture_directory( 'wp-content/uploads' );
		$temp_shaped_dir = $uploads . '/' . self::orphaned_temp_name( 'leftover' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture setup.
		mkdir( $temp_shaped_dir, 0o755, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture setup.
		file_put_contents( $temp_shaped_dir . '/inner.txt', 'still here' );

		$removed = $writer->sweep_orphaned_temp_files();

		$this->assertSame( 0, $removed );
		$this->assertDirectoryExists( $temp_shaped_dir );
		$this->assertFileExists( $temp_shaped_dir . '/inner.txt' );
	}

	/**
	 * The returned count is the number of files actually removed.
	 *
	 * Three orphans are placed; the count must be exactly 3 — describing what
	 * was actually done, never merely how many names matched.
	 *
	 * @return void
	 */
	public function test_sweep_returns_the_count_of_files_actually_removed(): void {
		$writer  = new FileWriter( $this->fixture_root );
		$dir     = $this->make_fixture_directory( 'wp-content/uploads' );
		$orphans = array(
			$dir . '/' . self::orphaned_temp_name( 'a.jpg' ),
			$dir . '/' . self::orphaned_temp_name( 'b.jpg' ),
			$dir . '/' . self::orphaned_temp_name( 'c.jpg' ),
		);
		foreach ( $orphans as $orphan ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture setup.
			file_put_contents( $orphan, 'x' );
		}

		$removed = $writer->sweep_orphaned_temp_files();

		$this->assertSame( 3, $removed );
		foreach ( $orphans as $orphan ) {
			$this->assertFileDoesNotExist( $orphan );
		}
	}

	/**
	 * The returned count reflects only unlink() calls that actually succeeded.
	 *
	 * A matching orphan sits inside a directory this process cannot write to
	 * (0o555: read and execute, no write) — unlink() needs write permission
	 * on the PARENT directory, not the file itself, so the removal attempt
	 * fails. Mutating the removal loop to `@unlink( $path ); ++$removed;`
	 * unconditionally (dropping the surrounding `if`) left every other sweep
	 * test in this suite green, because every fixture those tests plant sits
	 * somewhere genuinely removable; only a fixture the sweep cannot actually
	 * delete can catch that mutation, which is why this test exists on its
	 * own.
	 *
	 * Skips whenever this process turns out not to actually be constrained
	 * by the 0o555 mode just set — proven EMPIRICALLY, by attempting to
	 * create a probe file inside the directory right after the chmod,
	 * rather than inferred from identity. `getmyuid()` was tried first and
	 * was wrong: it reports who owns the SCRIPT FILE on disk, not what this
	 * process can actually do, and inside a container where the repository
	 * is bind-mounted from the host, the files are host-owned while PHP
	 * itself runs as root — so `0 === getmyuid()` reads false even though
	 * root is about to unlink straight through the 0o555 mode regardless.
	 * A capability probe must measure the thing it is about to rely on,
	 * never infer it from identity. The mode is restored in a `finally` so
	 * tearDown() can still remove the fixture, following
	 * {@see self::test_a_failed_write_leaves_the_original_intact_and_no_temp()}'s
	 * existing pattern in this file.
	 *
	 * @return void
	 */
	public function test_sweep_does_not_count_an_orphan_it_could_not_actually_remove(): void {
		$writer = new FileWriter( $this->fixture_root );
		$dir    = $this->make_fixture_directory( 'wp-content/uploads' );
		$orphan = $dir . '/' . self::orphaned_temp_name( 'stuck' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture setup.
		file_put_contents( $orphan, 'cannot actually be removed' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Making the DIRECTORY unwritable so the removal attempt fails, the condition under test.
		chmod( $dir, 0o555 );

		try {
			$write_probe_path = $dir . '/.pontifex-write-probe-' . bin2hex( random_bytes( 8 ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents,WordPress.PHP.NoSilencedErrors.Discouraged -- Capability probe: proves whether THIS process is actually blocked by the 0o555 mode just set, rather than inferring it from uid.
			$can_still_write = false !== @file_put_contents( $write_probe_path, '' );
			if ( $can_still_write ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Cleanup of the capability probe's own artefact before skipping.
				@unlink( $write_probe_path );
				$this->markTestSkipped( 'This process can still write into a 0o555 directory (commonly root inside a container over a host-owned bind mount), so the condition this test needs cannot be produced here.' );
			}

			$removed = $writer->sweep_orphaned_temp_files();
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Restoring the fixture directory so tearDown can clean it.
			chmod( $dir, 0o755 );
		}

		$this->assertSame( 0, $removed, 'a name that matched but failed to unlink must not be counted as removed' );
		$this->assertFileExists( $orphan );
	}

	/**
	 * The sweep never throws, even when it cannot open a directory inside it — and it still removes what it CAN reach.
	 *
	 * {@see RecursiveIteratorIterator::CATCH_GET_CHILD} is what stops the
	 * UnexpectedValueException raised when the walker tries to open a
	 * directory it cannot read from escaping the walk entirely. Removing
	 * that flag left every other sweep test in this suite green, because
	 * none of them ever plants a directory the walker cannot even open.
	 * Several locked, unreadable directories are planted (created BEFORE the
	 * one reachable orphan, so a filesystem that returns directory entries
	 * in roughly creation order encounters every one of them first) so that,
	 * whichever order the real filesystem happens to return entries in, it
	 * is overwhelmingly likely that at least one locked directory is reached
	 * before the reachable orphan is: without CATCH_GET_CHILD, the first
	 * such encounter throws and the whole walk stops right there, so the
	 * reachable orphan is never removed. With CATCH_GET_CHILD, each locked
	 * directory is skipped and the walk carries on regardless, so the
	 * reachable orphan is removed every time. The assertion is therefore
	 * twofold: the call does not throw, and the reachable orphan is removed.
	 *
	 * Skips whenever this process turns out not to actually be constrained
	 * by the 0o000 mode just set — proven EMPIRICALLY, by attempting to
	 * list one of the locked directories right after the chmod, rather than
	 * inferred from identity. `getmyuid()` was tried first and was wrong in
	 * BOTH directions here: it answers who owns the SCRIPT FILE on disk,
	 * not what this process can actually do, and root reads a 0o000
	 * directory perfectly well regardless of what any uid check reports —
	 * so a test guarded only by identity would report as neither correctly
	 * skipped nor genuinely exercised, depending on what a bind mount
	 * happens to make `getmyuid()` say, while silently proving nothing
	 * either way. A capability probe must measure the thing it is about to
	 * rely on, never infer it from identity. The mode is restored in a
	 * `finally` so tearDown() can still remove the fixture, following
	 * {@see self::test_a_failed_write_leaves_the_original_intact_and_no_temp()}'s
	 * existing pattern in this file.
	 *
	 * @return void
	 */
	public function test_sweep_never_throws_on_an_unreadable_directory_and_still_removes_a_reachable_orphan(): void {
		$writer      = new FileWriter( $this->fixture_root );
		$locked_dirs = array();
		for ( $i = 0; $i < 8; $i++ ) {
			$locked_dirs[] = $this->make_fixture_directory( 'wp-content/locked-' . $i );
		}
		$reachable_dir = $this->make_fixture_directory( 'wp-content/uploads' );
		$orphan        = $reachable_dir . '/' . self::orphaned_temp_name( 'reachable' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture setup.
		file_put_contents( $orphan, 'must still be removed' );

		foreach ( $locked_dirs as $locked_dir ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Making the directory unreadable so the walker cannot open it, the condition under test.
			chmod( $locked_dir, 0o000 );
		}

		try {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Capability probe: proves whether THIS process is actually blocked from listing a directory just chmod'd to 0o000, rather than inferring it from uid.
			$can_still_read = false !== @scandir( $locked_dirs[0] );
			if ( $can_still_read ) {
				$this->markTestSkipped( 'This process can still list a 0o000 directory (commonly root inside a container), so the condition this test needs cannot be produced here.' );
			}

			$removed = $writer->sweep_orphaned_temp_files();
		} finally {
			foreach ( $locked_dirs as $locked_dir ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Restoring the fixture directory so tearDown can clean it.
				chmod( $locked_dir, 0o755 );
			}
		}

		$this->assertSame( 1, $removed, 'the sweep must still remove a reachable orphan despite unreadable directories elsewhere in the walk' );
		$this->assertFileDoesNotExist( $orphan );
	}

	/**
	 * Every name TempArtefact::suffix() (and, through it, FileWriter's own
	 * private temp_sibling_path()) produces is recognised by
	 * TempArtefact::is_orphan_name(), over many generated names.
	 *
	 * Re-pointed at the new home rather than deleted: FileWriter no longer
	 * builds or recognises this shape itself — both halves moved to
	 * {@see \Pontifex\Filesystem\TempArtefact} (see that class's own
	 * docblock for why two independent copies of a security-relevant
	 * pattern, one per deleter, would be a drift hazard). temp_sibling_path()
	 * stays private and stays in this class — it is one of
	 * TempArtefact::suffix()'s real callers — so this test keeps proving the
	 * same anti-drift property against the new home: it is still driven by
	 * reflection rather than duplicating a regex here, so this asserts the
	 * two are consistent WITH EACH OTHER instead of merely asserting the
	 * test author's own copy of a pattern matches itself.
	 * {@see \Pontifex\Tests\Unit\Filesystem\TempArtefactTest} separately
	 * covers TempArtefact::suffix() and TempArtefact::is_orphan_name()
	 * against each other directly, with no FileWriter involved at all.
	 *
	 * uniqid()'s random component means a single sample could pass even if
	 * the two were subtly inconsistent (wrong digit counts, a stray anchor);
	 * looping asserts it holds for the generator's actual output
	 * distribution, not one lucky draw.
	 *
	 * @return void
	 */
	public function test_temp_artefact_suffix_always_matches_the_orphan_pattern(): void {
		$sibling_method = new ReflectionMethod( FileWriter::class, 'temp_sibling_path' );

		for ( $i = 0; $i < 50; $i++ ) {
			$suffix = TempArtefact::suffix();
			$this->assertTrue(
				TempArtefact::is_orphan_name( $suffix ),
				sprintf( 'TempArtefact::suffix() output "%s" must be recognised by TempArtefact::is_orphan_name().', $suffix )
			);

			$sibling      = (string) $sibling_method->invoke( null, '/some/target/path/note.txt' );
			$sibling_name = basename( $sibling );
			$this->assertTrue(
				TempArtefact::is_orphan_name( $sibling_name ),
				sprintf( 'FileWriter::temp_sibling_path() output "%s" must be recognised as an orphan.', $sibling_name )
			);
		}
	}

	/**
	 * Sweeping a destination that contains no orphans returns 0 and removes nothing.
	 *
	 * @return void
	 */
	public function test_sweep_of_a_clean_destination_returns_zero_and_removes_nothing(): void {
		$writer = new FileWriter( $this->fixture_root );
		$dir    = $this->make_fixture_directory( 'wp-content/uploads' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture setup.
		file_put_contents( $dir . '/photo.jpg', 'ordinary content' );

		$removed = $writer->sweep_orphaned_temp_files();

		$this->assertSame( 0, $removed );
		$this->assertFileExists( $dir . '/photo.jpg' );
	}

	// -------------------------------------------------------------------
	// Creation ledger invariants (ADR 0024 follow-up: fixing the verdict
	// that was not true for an entry's own implicit intermediate
	// directories, and pinning four behaviours that survived mutation
	// with no test noticing).
	// -------------------------------------------------------------------

	/**
	 * Overwriting something already on the site — a file (buffered and
	 * streamed), a directory, and a symlink — must never add it to the
	 * creation ledger, so recovery can never delete something the failed
	 * import did not introduce.
	 *
	 * Every write_*() method captures $existed_before BEFORE it changes the
	 * filesystem and gates its own record_created_path() call on
	 * `! $existed_before`. Removing that guard from any ONE of the four
	 * sites — write_file(), write_file_from_stream(), write_directory(), or
	 * write_symlink() — would make its kind of entry, once merely
	 * OVERWRITTEN rather than created, look identical in the ledger to a
	 * genuine creation: recovery would then delete a file, directory, or
	 * symlink that was on the site before the import ever ran. This seeds
	 * all four kinds, restores an entry over each, and proves none of the
	 * four ends up in the ledger by running the real cleanup and finding
	 * every one of them still there afterwards, unchanged in kind.
	 *
	 * @return void
	 */
	public function test_overwriting_pre_existing_entries_of_every_kind_never_adds_them_to_the_ledger(): void {
		$writer = new FileWriter( $this->fixture_root );

		// A pre-existing file, overwritten through the buffered write path.
		$buffered_file = $this->fixture_root . '/wp-content/buffered.txt';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture setup.
		mkdir( dirname( $buffered_file ), 0o755, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture setup: seeding the pre-existing file the buffered path overwrites.
		file_put_contents( $buffered_file, 'ORIGINAL BUFFERED' );

		// A pre-existing file, overwritten through the streamed write path.
		$streamed_file = $this->fixture_root . '/wp-content/streamed.txt';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture setup: seeding the pre-existing file the streamed path overwrites.
		file_put_contents( $streamed_file, 'ORIGINAL STREAMED' );

		// A pre-existing directory, "overwritten" (its mode updated in place).
		$existing_dir = $this->fixture_root . '/wp-content/existing-dir';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture setup: seeding the pre-existing directory the restore overwrites.
		mkdir( $existing_dir, 0o755, true );

		// A pre-existing symlink, overwritten with a new target.
		$existing_link = $this->fixture_root . '/wp-content/existing-link';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_symlink -- Test fixture setup: seeding the pre-existing symlink the restore overwrites.
		symlink( '/old/target', $existing_link );

		$writer->write_entry( self::file_result( 'wp-content/buffered.txt', 'NEW BUFFERED' ) );

		$stream_contents = 'NEW STREAMED';
		$stream_header   = EntryHeader::for_file( 'wp-content/streamed.txt', strlen( $stream_contents ), 0o644, 1690000000, 'application/octet-stream', 0 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://memory is an in-process buffer, not a file.
		$stream = fopen( 'php://memory', 'r+b' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Operating on a test stream resource.
		fwrite( $stream, $stream_contents );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource.
		rewind( $stream );
		$writer->write_entry( EntryReadResult::for_stream( $stream_header, $stream, strlen( $stream_contents ) ) );

		$writer->write_entry( self::directory_result( 'wp-content/existing-dir', 0o700 ) );
		$writer->write_entry( self::symlink_result( 'wp-content/existing-link', 'new-target' ) );

		// Sanity check: every write actually landed as the new content, mode,
		// or target — proving this test exercises real overwrites, not no-ops
		// that would pass trivially regardless of the guard under test.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading the test's own fixture back.
		$this->assertSame( 'NEW BUFFERED', file_get_contents( $buffered_file ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading the test's own fixture back.
		$this->assertSame( 'NEW STREAMED', file_get_contents( $streamed_file ) );
		clearstatcache( true, $existing_dir );
		$this->assertSame( 0o700, fileperms( $existing_dir ) & 0o7777 );
		$this->assertSame( 'new-target', readlink( $existing_link ) );

		$report = $writer->remove_created_paths( array() );

		$this->assertSame( array(), $report->removed_paths(), 'nothing this run merely overwrote should ever be considered "removed" during cleanup' );
		$this->assertSame( array(), $report->failed_paths() );
		$this->assertTrue( $report->is_precise_revert() );
		$this->assertFileExists( $buffered_file, 'a pre-existing file overwritten via the buffered path must survive cleanup' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading the test's own fixture back.
		$this->assertSame( 'NEW BUFFERED', file_get_contents( $buffered_file ) );
		$this->assertFileExists( $streamed_file, 'a pre-existing file overwritten via the streamed path must survive cleanup' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading the test's own fixture back.
		$this->assertSame( 'NEW STREAMED', file_get_contents( $streamed_file ) );
		$this->assertDirectoryExists( $existing_dir, 'a pre-existing directory must survive cleanup' );
		$this->assertTrue( is_link( $existing_link ), 'a pre-existing symlink must survive cleanup' );
		$this->assertSame( 'new-target', readlink( $existing_link ) );
	}

	/**
	 * The ledger's record_created_path() call must run only AFTER
	 * finalise_temp() has actually succeeded — never merely after the temp
	 * file itself has landed.
	 *
	 * ADR 0024 ("The ordering that makes it safe") names this the one thing
	 * standing between the ledger and a bug worse than the one it fixes:
	 * record a path before the write that creates it has truly landed, and an
	 * abort between the two leaves a ledger entry for a path that was never
	 * really created — recovery would then delete something it never
	 * touched. The tempting-looking mutation this pins against is moving
	 * write_file_from_stream()'s record_created_path() call to right after
	 * the temp file is copied — "it's basically done at that point" — rather
	 * than after finalise_temp() (chmod, touch, RENAME) has actually
	 * returned.
	 *
	 * Reaching that window for real, rather than merely asserting it in
	 * prose, needs the temp file to have genuinely landed while the FINAL
	 * rename() still fails. FileWriter's own temp filename is built from
	 * uniqid() (via {@see \Pontifex\Filesystem\TempArtefact::suffix()}), so
	 * it cannot be predicted from outside the class and pre-seeded — and a
	 * directory's write permission gates creating a new file in it and
	 * renaming a file within it identically, so chmodding the directory
	 * unwritable before write_entry() is called blocks the temp file from
	 * ever being written at all (proving nothing about ordering), while
	 * chmodding it only after write_entry() returns is too late to affect
	 * anything that happened inside that same call.
	 *
	 * A PHP stream filter is the way through both problems. Appended to the
	 * payload stream write_file_from_stream() reads from, its filter()
	 * method runs exactly when stream_copy_to_stream() asks that stream for
	 * its first chunk — which happens AFTER write_file_from_stream() has
	 * already called fopen($temp_path, 'wb'), and opening a file for writing
	 * creates its directory entry immediately, before a single byte is
	 * copied. Chmodding the directory at that moment therefore lands in
	 * exactly the window where the temp file already exists but
	 * finalise_temp()'s rename() has not yet run: the data write that
	 * follows goes to an already-open file descriptor (no directory
	 * permission needed for that), finalise_temp()'s chmod()/touch() calls
	 * on the temp file need only ownership of it (not directory permission
	 * either), and rename() is the one operation left that genuinely needs
	 * the directory to be writable — so it, and only it, fails. Proven
	 * directly against this exact mechanism before being relied on here (a
	 * plain script reproducing the same fopen/chmod-mid-copy/rename sequence
	 * outside PHPUnit) rather than assumed.
	 *
	 * Gated on an empirical capability probe, never on identity: `getmyuid()`
	 * reports who owns the SCRIPT FILE on disk, not what this process can
	 * actually do, and is wrong in exactly this codebase under a
	 * bind-mounted container (root process, host-owned files) — see
	 * {@see self::test_sweep_does_not_count_an_orphan_it_could_not_actually_remove()}
	 * for the same lesson already learned once in this file. The probe
	 * attempts the REAL mechanism this test depends on — renaming a file
	 * within a directory just chmod'd unwritable — and skips only if that
	 * attempt actually succeeds, i.e. this process is not constrained by the
	 * mode.
	 *
	 * @return void
	 */
	public function test_a_created_path_is_never_recorded_before_finalise_temp_actually_succeeds(): void {
		$probe_dir = $this->fixture_root . '/probe';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture setup for the capability probe below.
		mkdir( $probe_dir, 0o755, true );
		$probe_source = $probe_dir . '/source';
		$probe_target = $probe_dir . '/target';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture setup for the capability probe below.
		file_put_contents( $probe_source, 'x' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Making the probe directory unwritable, the condition the real test below depends on.
		chmod( $probe_dir, 0o555 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename,WordPress.PHP.NoSilencedErrors.Discouraged -- Capability probe: proves whether THIS process is actually blocked from renaming within a directory just chmod'd to 0o555, rather than inferring it from uid.
		$probe_rename_worked = @rename( $probe_source, $probe_target );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Restoring the probe directory so it, and any leftovers inside it, can be cleaned up.
		chmod( $probe_dir, 0o755 );
		if ( $probe_rename_worked ) {
			$this->markTestSkipped( 'This process can still rename within a directory just chmod\'d to 0o555 (commonly root inside a container over a host-owned bind mount), so the condition this test needs cannot be produced here.' );
		}

		$filter_name = 'pontifex-filewritertest-chmod-on-first-read';
		if ( ! in_array( $filter_name, stream_get_filters(), true ) ) {
			// An anonymous class, registered by its dynamically-obtained name,
			// rather than a dedicated fixture class: this file is the sole owner
			// of this test, and the filter has no state beyond one boolean that
			// resets naturally with each stream_filter_append() call (a fresh
			// instance per append), so a shared, named class would buy nothing a
			// second file elsewhere in the tree would need to know about.
			$filter = new class() extends \php_user_filter {
				/**
				 * Whether this filter instance has already fired its chmod side effect.
				 *
				 * @var bool
				 */
				private bool $already_fired = false;

				/**
				 * Pass every byte through unchanged; chmod the configured directory before the FIRST chunk.
				 *
				 * $this->params carries the ['directory' => ..., 'mode' => ...] pair
				 * passed to stream_filter_append()'s fourth argument — PHP's own
				 * php_user_filter base class populates it automatically, so no
				 * static state is needed to get configuration into this instance.
				 *
				 * @param resource $in       Input bucket brigade.
				 * @param resource $out      Output bucket brigade.
				 * @param int      $consumed Bytes consumed, updated by reference.
				 * @param bool     $closing  Whether the stream is closing.
				 * @return int One of the PSFS_* constants.
				 */
				public function filter( $in, $out, &$consumed, bool $closing ): int {
					if ( ! $this->already_fired ) {
						$this->already_fired = true;
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod,WordPress.PHP.NoSilencedErrors.Discouraged -- Test-only timing hook: this chmod IS the condition under test, fired mid-copy so the destination temp file has already landed before its directory becomes unwritable.
						@chmod( $this->params['directory'], $this->params['mode'] );
					}
					// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- Standard PHP stream-filter idiom: stream_bucket_make_writeable() returns null once the bucket brigade is exhausted, so the assignment IS the loop's own termination test.
					while ( $bucket = stream_bucket_make_writeable( $in ) ) {
						$consumed += $bucket->datalen;
						stream_bucket_append( $out, $bucket );
					}
					return PSFS_PASS_ON;
				}
			};
			stream_filter_register( $filter_name, get_class( $filter ) );
		}

		$area = $this->fixture_root . '/wp-content/newarea';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture setup: the directory the stream filter will chmod mid-copy.
		mkdir( $area, 0o755, true );

		$contents = 'this file must never be recorded';
		$header   = EntryHeader::for_file( 'wp-content/newarea/newfile.txt', strlen( $contents ), 0o644, 1690000000, 'application/octet-stream', 0 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://memory is an in-process buffer, not a file.
		$stream = fopen( 'php://memory', 'r+b' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Operating on a test stream resource.
		fwrite( $stream, $contents );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource.
		rewind( $stream );
		stream_filter_append(
			$stream,
			$filter_name,
			STREAM_FILTER_READ,
			array(
				'directory' => $area,
				'mode'      => 0o555,
			)
		);

		$writer = new FileWriter( $this->fixture_root );
		$thrown = null;
		try {
			$writer->write_entry( EntryReadResult::for_stream( $header, $stream, strlen( $contents ) ) );
		} catch ( RuntimeException $error ) {
			$thrown = $error;
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Restoring the fixture directory so tearDown can clean it.
			chmod( $area, 0o755 );
		}

		$this->assertInstanceOf( RuntimeException::class, $thrown, 'the final rename must actually fail for this test to prove anything about ordering' );
		$this->assertStringContainsString( 'Could not move file into place', $thrown->getMessage() );
		$this->assertFileDoesNotExist( $area . '/newfile.txt', 'the rename never landed, so the target must not exist' );

		$created_paths = ( new ReflectionProperty( FileWriter::class, 'created_paths' ) )->getValue( $writer );
		$this->assertSame( array(), $created_paths, 'a write whose finalise_temp() step failed must leave no ledger entry at all, however far the write had otherwise progressed' );
	}

	/**
	 * A created, empty directory is removed with rmdir(), not unlink() —
	 * and is reported as REMOVED, never as failed.
	 *
	 * The unlink() function cannot remove a directory on any platform this
	 * plugin supports; if remove_one_created_path() ever called it for a
	 * LEDGER_KIND_DIRECTORY entry instead of rmdir(), the removal attempt
	 * would fail outright, the directory would survive on disk, and the
	 * cleanup report would misclassify a directory as "could not be removed"
	 * rather than removing it. A top-level directory name is used
	 * deliberately, so the only ledger entry in play is the one this test is
	 * about — a nested path would also record an intermediate ancestor (see
	 * {@see self::test_removing_created_paths_deletes_intermediate_directories_a_file_entry_implicitly_created()}),
	 * which would make this assertion about exactly which paths were removed
	 * ambiguous.
	 *
	 * @return void
	 */
	public function test_a_created_empty_directory_is_removed_with_rmdir_not_unlink(): void {
		$writer = new FileWriter( $this->fixture_root );
		$writer->write_entry( self::directory_result( 'newdir', 0o755 ) );

		$path = $this->fixture_root . '/newdir';
		$this->assertDirectoryExists( $path, 'Sanity check: the directory was actually created.' );

		$report = $writer->remove_created_paths( array() );

		$this->assertDirectoryDoesNotExist( $path );
		$this->assertSame( array( 'newdir' ), $report->removed_paths() );
		$this->assertSame( array(), $report->failed_paths() );
		$this->assertTrue( $report->is_precise_revert() );
	}

	/**
	 * Cleanup removes the intermediate directories a single file entry
	 * implicitly created — and leaves alone a directory that already existed
	 * before the restore.
	 *
	 * Both {@see FileWriter::ensure_parent_directory()} and
	 * {@see FileWriter::write_directory()} used to hand their target straight
	 * to a single RECURSIVE mkdir(). Every intermediate level PHP silently
	 * created that way was never passed to record_created_path(): a single
	 * file entry "wp-content/plugins/intruder/evil.php", restored where
	 * neither "wp-content/plugins" nor "wp-content/plugins/intruder" yet
	 * existed, left BOTH of those directories on disk with no ledger entry
	 * for either — recovery would neither remove them nor report them as
	 * failures, and {@see CreationLedgerCleanupReport::is_precise_revert()}
	 * would still (wrongly) answer true. This is the scenario from the brief,
	 * reproduced exactly: "wp-content" is seeded so it exists BEFORE the
	 * restore, "plugins" and "intruder" do not, and the file lands three
	 * levels below the destination root.
	 *
	 * @return void
	 */
	public function test_removing_created_paths_deletes_intermediate_directories_a_file_entry_implicitly_created(): void {
		$writer = new FileWriter( $this->fixture_root );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture setup: a directory that exists BEFORE the restore runs, and so must survive cleanup.
		mkdir( $this->fixture_root . '/wp-content', 0o755, true );

		$writer->write_entry( self::file_result( 'wp-content/plugins/intruder/evil.php', '<?php /* intruder */' ) );

		$plugins  = $this->fixture_root . '/wp-content/plugins';
		$intruder = $plugins . '/intruder';
		$evil     = $intruder . '/evil.php';
		$this->assertFileExists( $evil, 'Sanity check: the file actually landed.' );
		$this->assertDirectoryExists( $plugins, 'Sanity check: the implicit intermediate directory was actually created.' );
		$this->assertDirectoryExists( $intruder, 'Sanity check: the implicit intermediate directory was actually created.' );

		$report = $writer->remove_created_paths( array() );

		$this->assertFileDoesNotExist( $evil );
		$this->assertDirectoryDoesNotExist( $intruder, 'the intermediate directory the file entry implicitly created must be removed' );
		$this->assertDirectoryDoesNotExist( $plugins, 'the OTHER intermediate directory the file entry implicitly created must also be removed' );
		$this->assertDirectoryExists( $this->fixture_root . '/wp-content', 'a directory that existed BEFORE the restore must survive cleanup' );
		$this->assertTrue( $report->is_precise_revert(), 'every path this run created — the file and both intermediate directories — was accounted for and removed' );
	}

	/**
	 * An intermediate directory the new level-by-level helper creates carries
	 * the exact same mode a plain recursive mkdir() with the same mode
	 * argument would have produced.
	 *
	 * {@see FileWriter::create_directory_recording_intermediates()}'s own
	 * docblock states that it changes what gets RECORDED, never what gets
	 * CREATED. This pins the second half of that claim directly against a
	 * CONTROL directory built with a plain recursive mkdir() in the same
	 * process (so both are subject to the identical umask), rather than
	 * merely trusting the reasoning in prose.
	 *
	 * @return void
	 */
	public function test_intermediate_directories_carry_the_same_mode_a_recursive_mkdir_would_have_produced(): void {
		$writer = new FileWriter( $this->fixture_root );
		$writer->write_entry( self::file_result( 'wp-content/plugins/intruder/evil.php', 'x' ) );

		$intermediate = $this->fixture_root . '/wp-content/plugins';
		clearstatcache( true, $intermediate );
		$actual_mode = fileperms( $intermediate ) & 0o7777;

		$parent_dir_mode = (int) ( new ReflectionClassConstant( FileWriter::class, 'PARENT_DIR_MODE' ) )->getValue();
		$control_root    = sys_get_temp_dir() . '/pontifex-filewriter-mode-control-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Building a CONTROL directory with a plain recursive mkdir(), for a same-process, same-umask mode comparison against the value under test.
		mkdir( $control_root . '/a/b', $parent_dir_mode, true );
		clearstatcache( true, $control_root . '/a' );
		$control_mode = fileperms( $control_root . '/a' ) & 0o7777;
		self::rmtree( $control_root );

		$this->assertSame( $control_mode, $actual_mode, 'an intermediate directory created by the new helper must carry the same mode a recursive mkdir() with the same mode argument would have produced' );
	}

	/**
	 * A directory this run created is skipped by cleanup — counted in
	 * NEITHER removed_paths() NOR failed_paths() — when a path deliberately
	 * preserved still lives inside it, and is_precise_revert() stays true.
	 *
	 * B1 made the ledger record "wp-content" itself (an implicit intermediate
	 * directory) alongside "wp-content/keep.txt" here, since neither existed
	 * before this write. Preserving "wp-content/keep.txt" — telling cleanup
	 * "this one belongs to the site's prior state, leave it" — necessarily
	 * leaves "wp-content" non-empty, so rmdir() on it refuses on its own
	 * merits. Without {@see \Pontifex\Restore\FileWriter::preserved_ancestor_directories()},
	 * that refusal would be counted as a cleanup FAILURE, which is incoherent:
	 * a directory that survives purely because the caller asked to KEEP
	 * something inside it is not a failure of anything, and must not stop
	 * this from being reported as a precise revert. This is the "one more
	 * test" pinning that rule directly, at the same level the other four
	 * ledger tests in this file work at (real write_entry() calls, real
	 * remove_created_paths(), no RestoreRunner involved) — contrast
	 * {@see \Pontifex\Tests\Unit\Restore\RecoveryCreationLedgerTest::test_a_path_the_safety_archive_also_declares_is_never_removed()},
	 * which proves the identical rule through the full restore engine.
	 *
	 * @return void
	 */
	public function test_a_directory_containing_a_preserved_path_is_skipped_not_reported_as_failed(): void {
		$writer = new FileWriter( $this->fixture_root );
		$writer->write_entry( self::file_result( 'wp-content/keep.txt', 'kept content' ) );

		$this->assertFileExists( $this->fixture_root . '/wp-content/keep.txt', 'Sanity check: the file was actually written.' );
		$this->assertDirectoryExists( $this->fixture_root . '/wp-content', 'Sanity check: the implicit intermediate directory was actually created.' );

		$report = $writer->remove_created_paths( array( 'wp-content/keep.txt' ) );

		$this->assertFileExists( $this->fixture_root . '/wp-content/keep.txt', 'The preserved file must survive.' );
		$this->assertDirectoryExists( $this->fixture_root . '/wp-content', 'The directory that merely contains a preserved path must survive too.' );
		$this->assertSame( array(), $report->removed_paths(), 'a directory kept only because something inside it was preserved is not a removal' );
		$this->assertSame( array(), $report->failed_paths(), 'a directory kept only because something inside it was preserved is not a FAILED removal either' );
		$this->assertTrue( $report->is_precise_revert(), 'a directory surviving purely because of a deliberate preservation must not stop this from being a precise revert' );
	}
}
