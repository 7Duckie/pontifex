<?php
/**
 * Behavioural tests for ProtectedDirectory.
 *
 * @package Pontifex\Tests\Unit\Filesystem
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Filesystem;

use PHPUnit\Framework\TestCase;
use Pontifex\Filesystem\ProtectedDirectory;

/**
 * Exercises ProtectedDirectory against a real temporary directory.
 *
 * It has no WordPress coupling — it takes a path and a mode — so these are plain
 * unit tests with no bootstrap. Each test works inside its own unique directory
 * under the system temp path, removed in tear-down.
 */
final class ProtectedDirectoryTest extends TestCase {

	/**
	 * Unique temporary directory for the test in progress.
	 *
	 * @var string
	 */
	private string $temp_dir;

	/**
	 * Reserve a unique working path (not pre-created).
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->temp_dir = sys_get_temp_dir() . '/pontifex-protdir-' . uniqid( '', true );
	}

	/**
	 * Recursively remove anything the test created.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$this->remove_tree( $this->temp_dir );
		parent::tearDown();
	}

	/**
	 * The directory is created and carries both web-access guards.
	 *
	 * @return void
	 */
	public function test_creates_directory_with_both_guards(): void {
		$dir = $this->temp_dir . '/logs';

		$result = ProtectedDirectory::ensure( $dir, 0700 );

		$this->assertTrue( $result );
		$this->assertDirectoryExists( $dir );
		$this->assertFileExists( $dir . '/.htaccess' );
		$this->assertFileExists( $dir . '/index.php' );
		$this->assertStringContainsString( 'Require all denied', $this->read( $dir . '/.htaccess' ) );
		$this->assertStringContainsString( 'Deny from all', $this->read( $dir . '/.htaccess' ) );
	}

	/**
	 * An existing guard file is left untouched (idempotent, non-destructive).
	 *
	 * @return void
	 */
	public function test_does_not_overwrite_an_existing_guard(): void {
		$dir = $this->temp_dir . '/logs';
		$this->make_dir( $dir );
		$this->put( $dir . '/.htaccess', 'custom contents' );

		ProtectedDirectory::ensure( $dir, 0700 );

		$this->assertSame( 'custom contents', $this->read( $dir . '/.htaccess' ) );
	}

	/**
	 * When the parent is the shared "pontifex" directory, it is guarded too — but
	 * never the grandparent (which would be wp-content).
	 *
	 * @return void
	 */
	public function test_guards_the_pontifex_parent_but_not_above(): void {
		$dir = $this->temp_dir . '/pontifex/logs';

		ProtectedDirectory::ensure( $dir, 0700 );

		$this->assertFileExists( $this->temp_dir . '/pontifex/.htaccess', 'The pontifex parent should be guarded.' );
		$this->assertFileExists( $this->temp_dir . '/pontifex/index.php' );
		$this->assertFileDoesNotExist( $this->temp_dir . '/.htaccess', 'The grandparent (wp-content) must never be guarded.' );
	}

	/**
	 * A parent that is not the "pontifex" directory is never guarded.
	 *
	 * @return void
	 */
	public function test_does_not_guard_a_non_pontifex_parent(): void {
		$dir = $this->temp_dir . '/other/logs';

		ProtectedDirectory::ensure( $dir, 0700 );

		$this->assertFileExists( $dir . '/.htaccess' );
		$this->assertFileDoesNotExist( $this->temp_dir . '/other/.htaccess' );
	}

	/**
	 * An unusable path returns false and never throws.
	 *
	 * The parent of the target is a regular file, so the directory can never be
	 * created.
	 *
	 * @return void
	 */
	public function test_never_throws_when_path_is_unusable(): void {
		$this->put( $this->temp_dir, 'blocker' );

		$result = ProtectedDirectory::ensure( $this->temp_dir . '/cannot-exist', 0700 );

		$this->assertFalse( $result );
	}

	/**
	 * An empty `.htaccess` — the shape a full disk leaves behind on first
	 * creation — is repaired to the full guard.
	 *
	 * @return void
	 */
	public function test_repairs_an_empty_htaccess(): void {
		$dir = $this->temp_dir . '/logs';
		$this->make_dir( $dir );
		$this->put( $dir . '/.htaccess', '' );

		ProtectedDirectory::ensure( $dir, 0700 );

		$this->assertStringContainsString( 'Require all denied', $this->read( $dir . '/.htaccess' ) );
		$this->assertStringContainsString( 'Deny from all', $this->read( $dir . '/.htaccess' ) );
	}

	/**
	 * An empty `index.php` is repaired to the full guard.
	 *
	 * @return void
	 */
	public function test_repairs_an_empty_index_php(): void {
		$dir = $this->temp_dir . '/logs';
		$this->make_dir( $dir );
		$this->put( $dir . '/index.php', '' );

		ProtectedDirectory::ensure( $dir, 0700 );

		$this->assertStringContainsString( 'Silence is golden', $this->read( $dir . '/index.php' ) );
	}

	/**
	 * A `.htaccess` truncated part-way through — the shape a write cut short
	 * by a full disk leaves behind once some bytes did land — is repaired to
	 * the full guard.
	 *
	 * The truncated fixture is built from a fresh `ensure()` call in another
	 * directory, so the expected bytes cannot drift from the real constant.
	 *
	 * @return void
	 */
	public function test_repairs_a_truncated_htaccess(): void {
		$reference_dir = $this->temp_dir . '/reference';
		ProtectedDirectory::ensure( $reference_dir, 0700 );
		$full_guard = $this->read( $reference_dir . '/.htaccess' );
		$truncated  = substr( $full_guard, 0, 40 );

		$dir = $this->temp_dir . '/logs';
		$this->make_dir( $dir );
		$this->put( $dir . '/.htaccess', $truncated );

		ProtectedDirectory::ensure( $dir, 0700 );

		$this->assertSame( $full_guard, $this->read( $dir . '/.htaccess' ) );
	}

	/**
	 * A guard file longer than the real one is left untouched: it cannot be a
	 * prefix of our content, so it is not a partial write of ours to repair.
	 *
	 * @return void
	 */
	public function test_does_not_touch_a_guard_longer_than_the_real_one(): void {
		$dir = $this->temp_dir . '/logs';
		$this->make_dir( $dir );
		$longer = str_repeat( 'x', 10000 );
		$this->put( $dir . '/.htaccess', $longer );

		ProtectedDirectory::ensure( $dir, 0700 );

		$this->assertSame( $longer, $this->read( $dir . '/.htaccess' ) );
	}

	/**
	 * Repair is idempotent: calling `ensure()` a second time over an already
	 * -repaired guard leaves it unchanged.
	 *
	 * @return void
	 */
	public function test_repair_is_idempotent(): void {
		$dir = $this->temp_dir . '/logs';
		$this->make_dir( $dir );
		$this->put( $dir . '/.htaccess', '' );

		ProtectedDirectory::ensure( $dir, 0700 );
		$after_first = $this->read( $dir . '/.htaccess' );
		ProtectedDirectory::ensure( $dir, 0700 );
		$after_second = $this->read( $dir . '/.htaccess' );

		$this->assertStringContainsString( 'Require all denied', $after_first );
		$this->assertSame( $after_first, $after_second );
	}

	/**
	 * All four guard files in the `pontifex`/`logs` pair are repaired by one
	 * `ensure()` call: the target directory's two guards and its `pontifex`
	 * parent's two guards.
	 *
	 * @return void
	 */
	public function test_repairs_all_four_guards(): void {
		$dir = $this->temp_dir . '/pontifex/logs';
		$this->make_dir( $dir );
		$this->put( $dir . '/.htaccess', '' );
		$this->put( $dir . '/index.php', '' );
		$this->put( $this->temp_dir . '/pontifex/.htaccess', '' );
		$this->put( $this->temp_dir . '/pontifex/index.php', '' );

		ProtectedDirectory::ensure( $dir, 0700 );

		$this->assertStringContainsString( 'Require all denied', $this->read( $dir . '/.htaccess' ) );
		$this->assertStringContainsString( 'Silence is golden', $this->read( $dir . '/index.php' ) );
		$this->assertStringContainsString( 'Require all denied', $this->read( $this->temp_dir . '/pontifex/.htaccess' ) );
		$this->assertStringContainsString( 'Silence is golden', $this->read( $this->temp_dir . '/pontifex/index.php' ) );
	}

	/**
	 * A guard path that is a symbolic link is never written through: the link
	 * target is left exactly as it was.
	 *
	 * @return void
	 */
	public function test_does_not_write_through_a_symlink(): void {
		if ( ! function_exists( 'symlink' ) ) {
			self::markTestSkipped( 'symlink() is not available in this environment.' );
		}

		$dir = $this->temp_dir . '/logs';
		$this->make_dir( $dir );
		$canary = $this->temp_dir . '/canary.txt';
		$this->put( $canary, 'canary contents' );

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Environments that refuse symlink creation (e.g. no privilege on Windows) are handled by the skip check below.
		$linked = @symlink( $canary, $dir . '/.htaccess' );
		if ( ! $linked ) {
			self::markTestSkipped( 'This environment cannot create symlinks.' );
		}

		ProtectedDirectory::ensure( $dir, 0700 );

		$this->assertSame( 'canary contents', $this->read( $canary ) );
	}

	/**
	 * A guard path that is a dangling symbolic link (its target does not
	 * exist) is never written through either: the target is not created.
	 *
	 * @return void
	 */
	public function test_does_not_create_the_target_of_a_dangling_symlink(): void {
		if ( ! function_exists( 'symlink' ) ) {
			self::markTestSkipped( 'symlink() is not available in this environment.' );
		}

		$dir = $this->temp_dir . '/logs';
		$this->make_dir( $dir );
		$target = $this->temp_dir . '/nowhere.txt';

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Environments that refuse symlink creation (e.g. no privilege on Windows) are handled by the skip check below.
		$linked = @symlink( $target, $dir . '/.htaccess' );
		if ( ! $linked ) {
			self::markTestSkipped( 'This environment cannot create symlinks.' );
		}

		ProtectedDirectory::ensure( $dir, 0700 );

		$this->assertFileDoesNotExist( $target );
	}

	// -------------------------------------------------------------------------
	// Test helpers.
	// -------------------------------------------------------------------------

	/**
	 * Read a file's contents.
	 *
	 * @param string $path The path.
	 * @return string
	 */
	private function read( string $path ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading back a fixture file the test wrote in the system temp path.
		return (string) file_get_contents( $path );
	}

	/**
	 * Create a directory for fixture setup.
	 *
	 * @param string $path The directory to create.
	 * @return void
	 */
	private function make_dir( string $path ): void {
		if ( ! is_dir( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture directory in the system temp path.
			mkdir( $path, 0755, true );
		}
	}

	/**
	 * Write fixture bytes to a path.
	 *
	 * @param string $path  The file path.
	 * @param string $bytes The contents.
	 * @return void
	 */
	private function put( string $path, string $bytes ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture write in the system temp path.
		file_put_contents( $path, $bytes );
	}

	/**
	 * Recursively delete a file or directory tree.
	 *
	 * @param string $path The path to remove.
	 * @return void
	 */
	private function remove_tree( string $path ): void {
		if ( is_link( $path ) ) {
			// Checked ahead of is_file()/is_dir(): both are false for a broken
			// symlink (one whose target does not exist), so without this check
			// such a link would fall through untouched and the rmdir() below
			// would then fail on its non-empty parent. unlink() removes the link
			// entry itself, never its target, so this still only ever removes
			// paths inside the unique directory setUp() reserved.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test cleanup of a fixture the test created in the system temp path.
			unlink( $path );
			return;
		}

		if ( is_file( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test cleanup of a fixture the test created in the system temp path.
			unlink( $path );
			return;
		}

		if ( ! is_dir( $path ) ) {
			return;
		}

		$entries = (array) scandir( $path );
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$this->remove_tree( $path . '/' . $entry );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test cleanup of a fixture directory in the system temp path.
		rmdir( $path );
	}
}
