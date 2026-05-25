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
 * Tests for {@see FileWriter}.
 *
 * Each test creates its own fixture root under sys_get_temp_dir and
 * cleans up in tearDown. Tests verify that decoded EntryReadResults
 * land on disk with correct contents, mode, mtime, and target.
 */
final class FileWriterTest extends TestCase {

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
		$this->fixture_root = sys_get_temp_dir() . '/pontifex-file-writer-test-' . bin2hex( random_bytes( 8 ) );
	}

	/**
	 * Remove the fixture root recursively after each test.
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
	 * Recursively delete a path (file, symlink, or directory tree).
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
	 * Build an EntryReadResult for a file entry with the given path and payload.
	 *
	 * @param string $path     Relative path inside the archive.
	 * @param string $payload  Decoded file contents.
	 * @param int    $mode     POSIX mode (defaults to 0644).
	 * @param int    $mtime    Unix modification timestamp (defaults to a fixed test value).
	 * @return EntryReadResult The bundled header + payload.
	 */
	private static function file_result( string $path, string $payload, int $mode = 0o644, int $mtime = 1690000000 ): EntryReadResult {
		$header = EntryHeader::for_file( $path, strlen( $payload ), $mode, $mtime, 'application/octet-stream', 0 );
		return new EntryReadResult( $header, $payload );
	}

	/**
	 * Build an EntryReadResult for a directory entry.
	 *
	 * @param string $path Relative path.
	 * @param int    $mode POSIX mode (defaults to 0755).
	 * @return EntryReadResult The bundled header + empty payload.
	 */
	private static function directory_result( string $path, int $mode = 0o755 ): EntryReadResult {
		$header = EntryHeader::for_directory( $path, $mode, 0 );
		return new EntryReadResult( $header, '' );
	}

	/**
	 * Build an EntryReadResult for a symlink entry.
	 *
	 * @param string $path   Relative path of the symlink.
	 * @param string $target Target the link points at.
	 * @return EntryReadResult The bundled header + empty payload.
	 */
	private static function symlink_result( string $path, string $target ): EntryReadResult {
		$header = EntryHeader::for_symlink( $path, $target, 0 );
		return new EntryReadResult( $header, '' );
	}

	/**
	 * The constructor must reject an empty destination_root.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_empty_destination(): void {
		$this->expectException( InvalidArgumentException::class );

		new FileWriter( '' );
	}

	/**
	 * The constructor must reject a relative destination_root.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_relative_destination(): void {
		$this->expectException( InvalidArgumentException::class );

		new FileWriter( 'relative/path' );
	}

	/**
	 * The constructor must create the destination_root if it does not exist.
	 *
	 * @return void
	 */
	public function test_constructor_creates_destination_if_missing(): void {
		$this->assertFalse( is_dir( $this->fixture_root ) );

		new FileWriter( $this->fixture_root );

		$this->assertTrue( is_dir( $this->fixture_root ) );
	}

	/**
	 * A file entry must be written with the correct contents.
	 *
	 * @return void
	 */
	public function test_write_file_creates_file_with_correct_contents(): void {
		$writer  = new FileWriter( $this->fixture_root );
		$payload = 'hello world';

		$writer->write_entry( self::file_result( 'note.txt', $payload ) );

		$path = $this->fixture_root . '/note.txt';
		$this->assertTrue( file_exists( $path ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test assertion against on-disk fixture.
		$this->assertSame( $payload, file_get_contents( $path ) );
	}

	/**
	 * A file entry's POSIX mode must be preserved.
	 *
	 * @return void
	 */
	public function test_write_file_preserves_mode(): void {
		$writer = new FileWriter( $this->fixture_root );

		$writer->write_entry( self::file_result( 'note.txt', 'hi', 0o600 ) );

		$path   = $this->fixture_root . '/note.txt';
		$actual = fileperms( $path ) & 0o7777;
		$this->assertSame( 0o600, $actual );
	}

	/**
	 * A file entry's mtime must be preserved.
	 *
	 * @return void
	 */
	public function test_write_file_preserves_mtime(): void {
		$writer   = new FileWriter( $this->fixture_root );
		$expected = 1700000000;

		$writer->write_entry( self::file_result( 'note.txt', 'hi', 0o644, $expected ) );

		$path = $this->fixture_root . '/note.txt';
		clearstatcache( true, $path );
		// Tolerance of 1 second accommodates platform-specific filesystem timestamp precision.
		// macOS APFS stores nanosecond-precision timestamps and PHP's touch() can land on a
		// fractional nanosecond that rounds up when read back as a whole-second mtime.
		// A 1-second tolerance still catches the bug-case where mtime falls back to "now" (millions of seconds off).
		$this->assertEqualsWithDelta( $expected, filemtime( $path ), 1 );
	}

	/**
	 * Parent directories must be created automatically when the entry path is nested.
	 *
	 * @return void
	 */
	public function test_write_file_creates_parent_directories(): void {
		$writer = new FileWriter( $this->fixture_root );

		$writer->write_entry( self::file_result( 'wp-content/themes/x/style.css', 'body{}' ) );

		$path = $this->fixture_root . '/wp-content/themes/x/style.css';
		$this->assertTrue( file_exists( $path ) );
	}

	/**
	 * An empty file must be written correctly (zero-byte file exists).
	 *
	 * @return void
	 */
	public function test_write_file_handles_empty_payload(): void {
		$writer = new FileWriter( $this->fixture_root );

		$writer->write_entry( self::file_result( 'empty.txt', '' ) );

		$path = $this->fixture_root . '/empty.txt';
		$this->assertTrue( file_exists( $path ) );
		$this->assertSame( 0, filesize( $path ) );
	}

	/**
	 * A directory entry must create the directory with the recorded mode.
	 *
	 * @return void
	 */
	public function test_write_directory_creates_with_correct_mode(): void {
		$writer = new FileWriter( $this->fixture_root );

		$writer->write_entry( self::directory_result( 'wp-content/uploads', 0o750 ) );

		$path = $this->fixture_root . '/wp-content/uploads';
		$this->assertTrue( is_dir( $path ) );
		$this->assertSame( 0o750, fileperms( $path ) & 0o7777 );
	}

	/**
	 * A symlink entry must create the link with the recorded target.
	 *
	 * @return void
	 */
	public function test_write_symlink_creates_with_correct_target(): void {
		$writer = new FileWriter( $this->fixture_root );

		$writer->write_entry( self::symlink_result( 'wp-content/cache', '/tmp/wp-cache' ) );

		$path = $this->fixture_root . '/wp-content/cache';
		$this->assertTrue( is_link( $path ) );
		$this->assertSame( '/tmp/wp-cache', readlink( $path ) );
	}

	/**
	 * A second write to an existing file path must overwrite the previous contents.
	 *
	 * @return void
	 */
	public function test_write_file_overwrites_existing(): void {
		$writer = new FileWriter( $this->fixture_root );

		$writer->write_entry( self::file_result( 'note.txt', 'first' ) );
		$writer->write_entry( self::file_result( 'note.txt', 'second' ) );

		$path = $this->fixture_root . '/note.txt';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test assertion against on-disk fixture.
		$this->assertSame( 'second', file_get_contents( $path ) );
	}

	/**
	 * An absolute path in the entry must be rejected.
	 *
	 * @return void
	 */
	public function test_write_entry_rejects_absolute_path(): void {
		// Build the header manually because EntryHeader::for_file doesn't reject absolute paths;
		// path validity is the writer's responsibility (the archive could contain hostile data).
		$writer = new FileWriter( $this->fixture_root );

		$this->expectException( InvalidArgumentException::class );

		$writer->write_entry( self::file_result( '/etc/passwd', 'rooted' ) );
	}

	/**
	 * A path containing ".." segments must be rejected.
	 *
	 * @return void
	 */
	public function test_write_entry_rejects_parent_directory_segment(): void {
		$writer = new FileWriter( $this->fixture_root );

		$this->expectException( InvalidArgumentException::class );

		$writer->write_entry( self::file_result( '../escape.txt', 'malicious' ) );
	}

	/**
	 * A path with a ".." segment in the middle must be rejected.
	 *
	 * @return void
	 */
	public function test_write_entry_rejects_parent_segment_anywhere_in_path(): void {
		$writer = new FileWriter( $this->fixture_root );

		$this->expectException( InvalidArgumentException::class );

		$writer->write_entry( self::file_result( 'wp-content/../../../etc/passwd', 'malicious' ) );
	}

	/**
	 * A path containing a null byte must be rejected.
	 *
	 * @return void
	 */
	public function test_write_entry_rejects_null_byte_in_path(): void {
		$writer = new FileWriter( $this->fixture_root );

		$this->expectException( InvalidArgumentException::class );

		$writer->write_entry( self::file_result( "note.txt\0evil", 'malicious' ) );
	}

	/**
	 * A db_chunk entry must be rejected; it belongs to DatabaseWriter, not FileWriter.
	 *
	 * @return void
	 */
	public function test_write_entry_rejects_db_chunk(): void {
		$writer = new FileWriter( $this->fixture_root );
		$header = EntryHeader::for_db_chunk( 0, 'wp_posts', 1, 50, 0 );
		$result = new EntryReadResult( $header, 'SELECT 1;' );

		$this->expectException( InvalidArgumentException::class );

		$writer->write_entry( $result );
	}
}
