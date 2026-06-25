<?php
/**
 * Tests for BackupStore — the operator-created backups directory and its retrieval gate.
 *
 * @package Pontifex\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Admin;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Pontifex\Admin\BackupStore;

/**
 * Exercises BackupStore against a real temporary directory.
 *
 * The class has no WordPress coupling (filesystem built-ins only), so the tests
 * run with no bootstrap, the same way RollbackStoreTest does. The
 * security-critical method is {@see BackupStore::resolve()}: most of these tests
 * pin down that it admits only a real backup in the directory and refuses every
 * traversal, foreign-name, or missing-file case.
 */
final class BackupStoreTest extends TestCase {

	/**
	 * Temporary content directory the store is rooted at for one test.
	 *
	 * @var string
	 */
	private string $base = '';

	/**
	 * Reserve a unique temp content directory.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->base = sys_get_temp_dir() . '/pontifex-backup-store-' . uniqid( '', true );
	}

	/**
	 * Remove the temp directory tree.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		self::rmtree( $this->base );
		parent::tearDown();
	}

	/**
	 * Creates the owner-only directory and drops the web-access guards.
	 *
	 * @return void
	 */
	public function test_ensure_directory_creates_a_protected_directory(): void {
		$store = new BackupStore( $this->base );
		$store->ensure_directory();

		$this->assertDirectoryExists( $store->directory() );
		$this->assertFileExists( $store->directory() . '/.htaccess', 'The directory must carry the deny-all guard.' );
		$this->assertFileExists( $store->directory() . '/index.php', 'The directory must carry the index guard.' );
	}

	/**
	 * Names a new backup with its UTC timestamp.
	 *
	 * @return void
	 */
	public function test_next_backup_path_uses_utc_naming(): void {
		$store = new BackupStore( $this->base );
		$now   = new DateTimeImmutable( '2026-03-01 09:30:00', new DateTimeZone( 'UTC' ) );

		$path = $store->next_backup_path( $now );

		$this->assertSame( $store->directory() . '/pontifex-backup-20260301T093000Z.wpmig', $path );
	}

	/**
	 * Lists matching files sorted oldest-first, ignoring foreign names.
	 *
	 * @return void
	 */
	public function test_backups_lists_matching_files_sorted(): void {
		$store = new BackupStore( $this->base );
		$store->ensure_directory();
		$this->seed( $store, 'pontifex-backup-20260301T093000Z.wpmig' );
		$this->seed( $store, 'pontifex-backup-20260101T120000Z.wpmig' );
		$this->seed( $store, 'not-a-backup.txt' );

		$backups = $store->backups();

		$this->assertCount( 2, $backups, 'Only the two correctly-named backups should be listed.' );
		$this->assertStringEndsWith( '20260101T120000Z.wpmig', $backups[0], 'Oldest backup should sort first.' );
		$this->assertStringEndsWith( '20260301T093000Z.wpmig', $backups[1] );
	}

	/**
	 * Resolves a genuine backup in the directory to its real path.
	 *
	 * @return void
	 */
	public function test_resolve_accepts_a_real_backup(): void {
		$store = new BackupStore( $this->base );
		$store->ensure_directory();
		$name = 'pontifex-backup-20260101T000000Z.wpmig';
		$this->seed( $store, $name );

		$resolved = $store->resolve( $name );

		$this->assertNotNull( $resolved );
		$this->assertSame( realpath( $store->directory() . '/' . $name ), $resolved );
	}

	/**
	 * Refuses traversal, absolute paths, foreign names, and missing files.
	 *
	 * @return void
	 */
	public function test_resolve_refuses_unsafe_or_unknown_names(): void {
		$store = new BackupStore( $this->base );
		$store->ensure_directory();

		// A real file outside the directory, to prove traversal cannot reach it.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Seeding a fixture outside the store in a temp tree.
		file_put_contents( $this->base . '/secret.txt', 'secret' );

		$this->assertNull( $store->resolve( '../secret.txt' ), 'A traversal payload must not resolve.' );
		$this->assertNull( $store->resolve( '../../etc/passwd' ), 'A deeper traversal must not resolve.' );
		$this->assertNull( $store->resolve( '/etc/passwd' ), 'An absolute path must not resolve.' );
		$this->assertNull( $store->resolve( 'pontifex-backup-20260101T000000Z.wpmig/../../secret.txt' ), 'A path with separators must not resolve.' );
		$this->assertNull( $store->resolve( 'evil.wpmig' ), 'A name not matching the pattern must not resolve.' );
		$this->assertNull( $store->resolve( 'pontifex-backup-20260101T000000Z.wpmig' ), 'A correctly-named but absent backup must not resolve.' );
	}

	/**
	 * Removes a real backup and refuses anything that does not resolve.
	 *
	 * @return void
	 */
	public function test_delete_removes_a_real_backup_and_refuses_others(): void {
		$store = new BackupStore( $this->base );
		$store->ensure_directory();
		$name = 'pontifex-backup-20260101T000000Z.wpmig';
		$this->seed( $store, $name );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Seeding a fixture outside the store in a temp tree.
		file_put_contents( $this->base . '/secret.txt', 'secret' );

		$this->assertFalse( $store->delete( '../secret.txt' ), 'Delete must refuse a traversal payload.' );
		$this->assertFileExists( $this->base . '/secret.txt', 'The outside file must be untouched.' );

		$this->assertTrue( $store->delete( $name ), 'Delete must remove a real backup.' );
		$this->assertFileDoesNotExist( $store->directory() . '/' . $name );
	}

	/**
	 * The cancel sentinel round-trips: requested, observed, then cleared.
	 *
	 * The export polls is_cancel_requested() within one long request, so the read
	 * clears the stat cache; this exercises the write, the read, and the removal.
	 *
	 * @return void
	 */
	public function test_cancel_sentinel_round_trips(): void {
		$store = new BackupStore( $this->base );
		$store->ensure_directory();

		$this->assertFalse( $store->is_cancel_requested(), 'No cancel is requested initially.' );

		$store->request_cancel();
		$this->assertTrue( $store->is_cancel_requested(), 'A requested cancel must be observed.' );

		$store->clear_cancel();
		$this->assertFalse( $store->is_cancel_requested(), 'A cleared cancel must no longer be observed.' );
	}

	/**
	 * Create an empty file with the given name inside the store directory.
	 *
	 * @param BackupStore $store    The store whose directory to seed.
	 * @param string      $filename The filename to create.
	 * @return void
	 */
	private function seed( BackupStore $store, string $filename ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Seeding a fixture backup in a temp directory.
		file_put_contents( $store->directory() . '/' . $filename, 'x' );
	}

	/**
	 * Recursively delete a directory tree.
	 *
	 * @param string $path Absolute path to remove.
	 * @return void
	 */
	private static function rmtree( string $path ): void {
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( scandir( $path ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$full = $path . '/' . $entry;
			if ( is_dir( $full ) ) {
				self::rmtree( $full );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Test fixture teardown.
				@unlink( $full );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.PHP.NoSilencedErrors.Discouraged -- Test fixture teardown.
		@rmdir( $path );
	}
}
