<?php
/**
 * Unit tests for the FileScanner class.
 *
 * @package Pontifex\Tests\Unit\Manifest
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Manifest;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Manifest\ExclusionRules;
use Pontifex\Manifest\FileScanner;

/**
 * Tests for {@see FileScanner}.
 *
 * Each test builds an ephemeral fixture tree under sys_get_temp_dir()
 * and scans it. setUp creates a clean temp directory; tearDown
 * removes it recursively. Tests that need specific structures build
 * their own files/directories within the fixture root.
 */
final class FileScannerTest extends TestCase {

	/**
	 * Absolute path to the fixture root for the current test.
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
		$this->fixture_root = sys_get_temp_dir() . '/pontifex-scanner-test-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture setup under sys_get_temp_dir; WP_Filesystem is not available in PHPUnit context.
		mkdir( $this->fixture_root, 0o755, true );
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
	 * Recursively delete a directory and its contents.
	 *
	 * @param string $path Absolute path to remove.
	 * @return void
	 */
	private static function rmtree( string $path ): void {
		if ( is_link( $path ) || ! is_dir( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture teardown; WP_Filesystem and wp_delete_file are not available in PHPUnit context.
			unlink( $path );
			return;
		}
		foreach ( scandir( $path ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			// Restore readability for anything chmod()'d during the test, so teardown can clean up.
			$child = $path . '/' . $entry;
			if ( ! is_link( $child ) ) {
				// chmod() may legitimately fail for paths whose parent is unreadable.
				// We ignore the result and let the subsequent rmtree call surface a more useful error if it cannot proceed.
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Test teardown best-effort cleanup; WP_Filesystem is not available in PHPUnit context, and silencing is intentional because failure is non-fatal.
				@chmod( $child, 0o755 );
			}
			self::rmtree( $child );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture teardown.
		rmdir( $path );
	}

	/**
	 * Write a file at the given relative path within the fixture root.
	 *
	 * @param string $relative Relative path under the fixture root.
	 * @param string $contents File contents.
	 * @return string The absolute path written.
	 */
	private function write_file( string $relative, string $contents = 'data' ): string {
		$absolute = $this->fixture_root . '/' . $relative;
		$dir      = dirname( $absolute );
		if ( ! is_dir( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture setup.
			mkdir( $dir, 0o755, true );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture setup.
		file_put_contents( $absolute, $contents );
		return $absolute;
	}

	/**
	 * Create a directory at the given relative path within the fixture root.
	 *
	 * @param string $relative Relative path under the fixture root.
	 * @return string The absolute path created.
	 */
	private function make_dir( string $relative ): string {
		$absolute = $this->fixture_root . '/' . $relative;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture setup.
		mkdir( $absolute, 0o755, true );
		return $absolute;
	}

	/**
	 * Build a scanner with no exclusions (the common case for these tests).
	 *
	 * @return FileScanner A scanner that emits every entry it finds.
	 */
	private static function unfiltered_scanner(): FileScanner {
		return new FileScanner( ExclusionRules::none() );
	}

	/**
	 * Scanning an empty directory must return an empty array.
	 *
	 * @return void
	 */
	public function test_scan_empty_directory_returns_empty_array(): void {
		$entries = self::unfiltered_scanner()->scan( $this->fixture_root );

		$this->assertSame( array(), $entries );
	}

	/**
	 * Scanning a directory containing one file must return one file entry.
	 *
	 * @return void
	 */
	public function test_scan_single_file_returns_one_entry(): void {
		$this->write_file( 'hello.txt', 'hello' );

		$entries = self::unfiltered_scanner()->scan( $this->fixture_root );

		$this->assertCount( 1, $entries );
		$this->assertSame( EntryHeader::KIND_FILE, $entries[0]->kind() );
		$this->assertSame( 'hello.txt', $entries[0]->relative_path() );
		$this->assertSame( 5, $entries[0]->size() );
	}

	/**
	 * Scanned entries must include the absolute filesystem path.
	 *
	 * @return void
	 */
	public function test_scanned_entry_includes_absolute_path(): void {
		$abs = $this->write_file( 'a.txt', 'data' );

		$entries = self::unfiltered_scanner()->scan( $this->fixture_root );

		$this->assertSame( $abs, $entries[0]->absolute_path() );
	}

	/**
	 * Scanning nested directories must visit every level.
	 *
	 * @return void
	 */
	public function test_scan_nested_directories(): void {
		$this->write_file( 'a.txt' );
		$this->write_file( 'sub/b.txt' );
		$this->write_file( 'sub/deeper/c.txt' );

		$entries = self::unfiltered_scanner()->scan( $this->fixture_root );

		$paths = array_map( static fn( $e ) => $e->relative_path(), $entries );

		$this->assertContains( 'a.txt', $paths );
		$this->assertContains( 'sub', $paths );
		$this->assertContains( 'sub/b.txt', $paths );
		$this->assertContains( 'sub/deeper', $paths );
		$this->assertContains( 'sub/deeper/c.txt', $paths );
	}

	/**
	 * Files and directories must be classified with the correct EntryHeader kind.
	 *
	 * @return void
	 */
	public function test_files_and_directories_are_correctly_classified(): void {
		$this->write_file( 'a.txt' );
		$this->make_dir( 'subdir' );
		$this->write_file( 'subdir/b.txt' );

		$entries = self::unfiltered_scanner()->scan( $this->fixture_root );
		$by_path = array();
		foreach ( $entries as $entry ) {
			$by_path[ $entry->relative_path() ] = $entry;
		}

		$this->assertSame( EntryHeader::KIND_FILE, $by_path['a.txt']->kind() );
		$this->assertSame( EntryHeader::KIND_DIRECTORY, $by_path['subdir']->kind() );
		$this->assertSame( EntryHeader::KIND_FILE, $by_path['subdir/b.txt']->kind() );
	}

	/**
	 * Scanned entries must be sorted in stable lexicographic order by relative_path.
	 *
	 * @return void
	 */
	public function test_entries_are_sorted_lexicographically(): void {
		$this->write_file( 'zebra.txt' );
		$this->write_file( 'apple.txt' );
		$this->write_file( 'middle/file.txt' );
		$this->write_file( 'mango.txt' );

		$entries = self::unfiltered_scanner()->scan( $this->fixture_root );
		$paths   = array_map( static fn( $e ) => $e->relative_path(), $entries );

		$sorted = $paths;
		sort( $sorted, SORT_STRING );

		$this->assertSame( $sorted, $paths );
	}

	/**
	 * Symlinks must be enumerated as KIND_SYMLINK with the target captured.
	 *
	 * @return void
	 */
	public function test_symlinks_are_enumerated_with_target(): void {
		$target_abs = $this->write_file( 'target.txt', 'real content' );
		$link_abs   = $this->fixture_root . '/link.txt';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_symlink -- Test fixture; symlink behaviour is the subject under test.
		symlink( $target_abs, $link_abs );

		$entries = self::unfiltered_scanner()->scan( $this->fixture_root );

		$link = null;
		foreach ( $entries as $entry ) {
			if ( 'link.txt' === $entry->relative_path() ) {
				$link = $entry;
				break;
			}
		}

		$this->assertNotNull( $link );
		$this->assertSame( EntryHeader::KIND_SYMLINK, $link->kind() );
		$this->assertSame( $target_abs, $link->target() );
	}

	/**
	 * Symlinks must not be followed; the scanner must not enter the linked tree.
	 *
	 * @return void
	 */
	public function test_symlinks_are_not_followed(): void {
		// Create an "external" tree.
		$external = sys_get_temp_dir() . '/pontifex-scanner-test-external-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture setup.
		mkdir( $external, 0o755, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture setup.
		file_put_contents( $external . '/secret.txt', 'should not appear' );

		// Symlink it into our fixture.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_symlink -- Test fixture; symlink behaviour is the subject under test.
		symlink( $external, $this->fixture_root . '/link-to-external' );

		try {
			$entries = self::unfiltered_scanner()->scan( $this->fixture_root );

			$paths = array_map( static fn( $e ) => $e->relative_path(), $entries );

			$this->assertContains( 'link-to-external', $paths );
			$this->assertNotContains( 'link-to-external/secret.txt', $paths );
		} finally {
			self::rmtree( $external );
		}
	}

	/**
	 * Scanned file entries must carry the file's mtime and mode.
	 *
	 * @return void
	 */
	public function test_file_carries_mode_and_mtime(): void {
		$abs = $this->write_file( 'a.txt' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Test fixture; mode behaviour is the subject under test.
		chmod( $abs, 0o600 );

		$entries = self::unfiltered_scanner()->scan( $this->fixture_root );

		$this->assertSame( 0o600, $entries[0]->mode() );
		$this->assertGreaterThan( 0, $entries[0]->mtime() );
	}

	/**
	 * Excluded paths must be omitted from the output.
	 *
	 * @return void
	 */
	public function test_excluded_paths_are_omitted(): void {
		$this->write_file( 'keep.txt' );
		$this->write_file( 'drop.txt' );

		$exclusions = new ExclusionRules( array( 'drop.txt' ) );

		$entries = ( new FileScanner( $exclusions ) )->scan( $this->fixture_root );
		$paths   = array_map( static fn( $e ) => $e->relative_path(), $entries );

		$this->assertContains( 'keep.txt', $paths );
		$this->assertNotContains( 'drop.txt', $paths );
	}

	/**
	 * Pontifex's own working directory must be excluded regardless of ExclusionRules.
	 *
	 * This is the structural recursion-prevention invariant: even when
	 * the caller passes ExclusionRules::none(), FileScanner refuses to
	 * emit entries for wp-content/pontifex/ or anything beneath it.
	 *
	 * @return void
	 */
	public function test_pontifex_working_directory_is_always_excluded(): void {
		$this->make_dir( 'wp-content/pontifex' );
		$this->write_file( 'wp-content/pontifex/log.txt', 'old-pontifex-log' );
		$this->write_file( 'wp-content/pontifex/exports/archive.bin', 'old-archive' );
		$this->write_file( 'wp-content/uploads/safe.txt', 'site-content' );

		$entries = ( new FileScanner( ExclusionRules::none() ) )->scan( $this->fixture_root );
		$paths   = array_map( static fn( $e ) => $e->relative_path(), $entries );

		$this->assertContains( 'wp-content/uploads/safe.txt', $paths );
		$this->assertNotContains( 'wp-content/pontifex', $paths );
		$this->assertNotContains( 'wp-content/pontifex/log.txt', $paths );
		$this->assertNotContains( 'wp-content/pontifex/exports/archive.bin', $paths );
	}

	/**
	 * Similarly-named sibling directories must NOT be caught by the recursion-prevention invariant.
	 *
	 * Defends against a regression where the invariant uses substring
	 * comparison without a slash boundary and accidentally excludes
	 * directories like "wp-content/pontifex-staging" or
	 * "wp-content/pontifex2".
	 *
	 * @return void
	 */
	public function test_pontifex_lookalike_directories_are_not_excluded(): void {
		$this->write_file( 'wp-content/pontifex-staging/note.txt', 'should be kept' );
		$this->write_file( 'wp-content/pontifex2/note.txt', 'should be kept' );

		$entries = ( new FileScanner( ExclusionRules::none() ) )->scan( $this->fixture_root );
		$paths   = array_map( static fn( $e ) => $e->relative_path(), $entries );

		$this->assertContains( 'wp-content/pontifex-staging/note.txt', $paths );
		$this->assertContains( 'wp-content/pontifex2/note.txt', $paths );
	}

	/**
	 * A non-empty path prefix must be prepended to every emitted relative path.
	 *
	 * Models the content-only scan, where the walk is rooted at WP_CONTENT_DIR but
	 * the recorded paths must stay WordPress-root-relative, so a "wp-content" prefix
	 * re-roots them. Every emitted path — files and the intermediate directories
	 * alike — carries the prefix.
	 *
	 * @return void
	 */
	public function test_path_prefix_is_prepended_to_relative_paths(): void {
		$this->write_file( 'plugins/akismet/akismet.php' );
		$this->write_file( 'uploads/2026/05/image.jpg' );

		$entries = ( new FileScanner( ExclusionRules::none(), 'wp-content' ) )->scan( $this->fixture_root );
		$paths   = array_map( static fn( $e ) => $e->relative_path(), $entries );

		$this->assertContains( 'wp-content/plugins', $paths );
		$this->assertContains( 'wp-content/plugins/akismet/akismet.php', $paths );
		$this->assertContains( 'wp-content/uploads/2026/05/image.jpg', $paths );
		foreach ( $paths as $path ) {
			$this->assertStringStartsWith( 'wp-content/', $path, 'Every scanned path should carry the configured prefix.' );
		}
	}

	/**
	 * The recursion guard must still fire when scanning a content root under a prefix.
	 *
	 * This is the content-only trap. The walk is rooted at WP_CONTENT_DIR, so a
	 * stale Pontifex export sits at "pontifex/..." relative to the root, while the
	 * guard is keyed on the WordPress-root-relative "wp-content/pontifex". Only
	 * because the "wp-content" prefix is prepended *before* the guard runs does the
	 * stale export stay excluded; a regression that prefixed after the guard (or not
	 * at all) would recursively re-include the previous archive.
	 *
	 * @return void
	 */
	public function test_path_prefix_keeps_pontifex_working_dir_excluded(): void {
		$this->make_dir( 'pontifex' );
		$this->write_file( 'pontifex/exports/old.wpmig', 'previous-archive' );
		$this->write_file( 'uploads/keep.txt', 'site-content' );

		$entries = ( new FileScanner( ExclusionRules::none(), 'wp-content' ) )->scan( $this->fixture_root );
		$paths   = array_map( static fn( $e ) => $e->relative_path(), $entries );

		$this->assertContains( 'wp-content/uploads/keep.txt', $paths );
		$this->assertNotContains( 'wp-content/pontifex', $paths );
		$this->assertNotContains( 'wp-content/pontifex/exports/old.wpmig', $paths );
	}

	/**
	 * Scanning an empty string root must throw InvalidArgumentException.
	 *
	 * @return void
	 */
	public function test_scan_rejects_empty_root(): void {
		$this->expectException( InvalidArgumentException::class );

		self::unfiltered_scanner()->scan( '' );
	}

	/**
	 * Scanning a non-existent root must throw InvalidArgumentException.
	 *
	 * @return void
	 */
	public function test_scan_rejects_nonexistent_root(): void {
		$missing = $this->fixture_root . '/does-not-exist';

		$this->expectException( InvalidArgumentException::class );

		self::unfiltered_scanner()->scan( $missing );
	}

	/**
	 * Relative paths must use forward slashes regardless of host OS.
	 *
	 * @return void
	 */
	public function test_relative_paths_use_forward_slashes(): void {
		$this->write_file( 'sub/nested/file.txt' );

		$entries = self::unfiltered_scanner()->scan( $this->fixture_root );

		foreach ( $entries as $entry ) {
			$this->assertStringNotContainsString( '\\', $entry->relative_path() );
		}
	}

	/**
	 * Scanning an unreadable file must throw RuntimeException.
	 *
	 * Skipped when running as a privileged user (CI sometimes runs as root,
	 * for whom chmod 0000 does not block reads).
	 *
	 * @return void
	 */
	public function test_unreadable_file_throws_runtime_exception(): void {
		if ( 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'Cannot test unreadable files when running as root (chmod is not enforced).' );
		}

		$abs = $this->write_file( 'unreadable.txt', 'data' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Test fixture; unreadability behaviour is the subject under test.
		chmod( $abs, 0o000 );

		try {
			$this->expectException( RuntimeException::class );
			self::unfiltered_scanner()->scan( $this->fixture_root );
		} finally {
			// Restore readability so teardown can clean up.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Test fixture cleanup.
			chmod( $abs, 0o644 );
		}
	}

	/**
	 * Scanning an unreadable directory must throw a clear RuntimeException.
	 *
	 * An unreadable sub-directory is the documented "fail loudly, never silently
	 * skip" case. It is normally caught by the per-entry readability guard; the
	 * `UnexpectedValueException`-to-`RuntimeException` translation added alongside
	 * this test is the backstop for the residual case where the recursive iterator
	 * fails to open a directory that passed the readability check (a race, or a
	 * stat-readable-but-unopenable path). Either way the guarantee is the same: a
	 * clear RuntimeException, not PHP's opaque message and not a silent skip.
	 * Skipped as root, for whom chmod 0000 does not block reads.
	 *
	 * @return void
	 */
	public function test_unreadable_directory_throws_runtime_exception(): void {
		if ( 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'Cannot test unreadable directories when running as root (chmod is not enforced).' );
		}

		$this->write_file( 'locked/inside.txt', 'data' );
		$locked = $this->fixture_root . '/locked';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Test fixture; unreadability behaviour is the subject under test.
		chmod( $locked, 0o000 );

		try {
			$this->expectException( RuntimeException::class );
			self::unfiltered_scanner()->scan( $this->fixture_root );
		} finally {
			// Restore readability so teardown can clean up.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Test fixture cleanup.
			chmod( $locked, 0o755 );
		}
	}

	/**
	 * Scanned file entries must carry a non-empty media_type.
	 *
	 * The exact value depends on the host's finfo magic database, so
	 * this test asserts only the structural invariant: every file
	 * entry has a non-null, non-empty media_type string.
	 *
	 * @return void
	 */
	public function test_scanned_files_carry_non_empty_media_type(): void {
		$this->write_file( 'note.txt', 'plain text content' );
		$this->write_file( 'wp-config.php', '<?php echo 1; ?>' );

		$entries = self::unfiltered_scanner()->scan( $this->fixture_root );

		foreach ( $entries as $entry ) {
			if ( EntryHeader::KIND_FILE === $entry->kind() ) {
				$this->assertIsString( $entry->media_type() );
				$this->assertNotSame( '', $entry->media_type() );
			}
		}
	}

	/**
	 * Scanned directory entries must carry null media_type.
	 *
	 * Directories have no MIME type by definition; FileScanner must
	 * not populate the field on directory entries.
	 *
	 * @return void
	 */
	public function test_scanned_directories_carry_null_media_type(): void {
		$this->write_file( 'wp-content/themes/x/style.css' );

		$entries = self::unfiltered_scanner()->scan( $this->fixture_root );

		foreach ( $entries as $entry ) {
			if ( EntryHeader::KIND_DIRECTORY === $entry->kind() ) {
				$this->assertNull( $entry->media_type() );
			}
		}
	}
}
