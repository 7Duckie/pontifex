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
use RuntimeException;

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
	 * Creates the owner-only, web-blocked uploads directory.
	 *
	 * @return void
	 */
	public function test_ensure_uploads_directory_creates_a_protected_directory(): void {
		$store = new BackupStore( $this->base );
		$store->ensure_uploads_directory();

		$this->assertDirectoryExists( $store->uploads_directory() );
		$this->assertStringEndsWith( 'pontifex/uploads', $store->uploads_directory() );
		$this->assertFileExists( $store->uploads_directory() . '/.htaccess', 'The uploads directory must carry the deny-all guard.' );
	}

	/**
	 * Appends chunks into one part file and reports the assembled size.
	 *
	 * @return void
	 */
	public function test_append_chunk_assembles_sequential_chunks(): void {
		$store = new BackupStore( $this->base );
		$store->ensure_uploads_directory();
		$id = 'abc12345';

		$first  = $store->append_chunk( $id, $this->temp_file( 'hello ' ), true );
		$second = $store->append_chunk( $id, $this->temp_file( 'world' ), false );

		$this->assertSame( 6, $first, 'The first chunk reports its own length.' );
		$this->assertSame( 11, $second, 'The second chunk reports the running total.' );
		$this->assertSame( 11, $store->upload_size( $id ) );

		$stream = $store->open_upload( $id );
		$this->assertIsResource( $stream );
		$this->assertSame( 'hello world', stream_get_contents( $stream ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the read stream the store handed back; test assertion.
		fclose( $stream );
	}

	/**
	 * The first chunk truncates, so a re-used id starts fresh rather than appending.
	 *
	 * @return void
	 */
	public function test_first_chunk_truncates_a_reused_id(): void {
		$store = new BackupStore( $this->base );
		$store->ensure_uploads_directory();
		$id = 'reuse123';

		$store->append_chunk( $id, $this->temp_file( 'stale-bytes' ), true );
		$size = $store->append_chunk( $id, $this->temp_file( 'fresh' ), true );

		$this->assertSame( 5, $size, 'A truncating first chunk discards the earlier part file.' );
	}

	/**
	 * A malformed upload id is refused before any filesystem write.
	 *
	 * @return void
	 */
	public function test_append_chunk_refuses_a_bad_upload_id(): void {
		$store = new BackupStore( $this->base );
		$store->ensure_uploads_directory();

		$this->expectException( RuntimeException::class );
		$store->append_chunk( '../escape', $this->temp_file( 'x' ), true );
	}

	/**
	 * A bad id never resolves to a part file, so its size is zero and open is null.
	 *
	 * @return void
	 */
	public function test_bad_upload_id_has_no_part_file(): void {
		$store = new BackupStore( $this->base );
		$store->ensure_uploads_directory();

		$this->assertSame( 0, $store->upload_size( 'bad/../id' ) );
		$this->assertNull( $store->open_upload( 'bad/../id' ) );
	}

	/**
	 * Finalising moves the part file into the backups directory under a backup name.
	 *
	 * @return void
	 */
	public function test_finalise_upload_stores_under_a_backup_name(): void {
		$store = new BackupStore( $this->base );
		$store->ensure_uploads_directory();
		$id = 'finalise1';
		$store->append_chunk( $id, $this->temp_file( 'archive-bytes' ), true );

		$path = $store->finalise_upload( $id, new DateTimeImmutable( '2026-01-02T03:04:05+00:00' ) );

		$this->assertSame( 'pontifex-backup-20260102T030405Z.wpmig', basename( $path ) );
		$this->assertFileExists( $path );
		$this->assertSame( 0, $store->upload_size( $id ), 'The part file is gone once finalised.' );
		$this->assertNotNull( $store->resolve( basename( $path ) ), 'The stored upload is a resolvable backup.' );
	}

	/**
	 * Finalising bumps the timestamp rather than overwriting an existing backup.
	 *
	 * @return void
	 */
	public function test_finalise_upload_avoids_a_name_collision(): void {
		$store = new BackupStore( $this->base );
		$store->ensure_uploads_directory();
		$when = new DateTimeImmutable( '2026-01-02T03:04:05+00:00' );

		$store->append_chunk( 'first123', $this->temp_file( 'one' ), true );
		$first = $store->finalise_upload( 'first123', $when );
		$store->append_chunk( 'second12', $this->temp_file( 'two' ), true );
		$second = $store->finalise_upload( 'second12', $when );

		$this->assertNotSame( basename( $first ), basename( $second ), 'A collision is resolved, not overwritten.' );
		$this->assertSame( 'pontifex-backup-20260102T030406Z.wpmig', basename( $second ) );
		$this->assertFileExists( $first );
	}

	/**
	 * Discarding removes an in-progress part file.
	 *
	 * @return void
	 */
	public function test_discard_upload_removes_the_part_file(): void {
		$store = new BackupStore( $this->base );
		$store->ensure_uploads_directory();
		$store->append_chunk( 'discard1', $this->temp_file( 'data' ), true );

		$store->discard_upload( 'discard1' );

		$this->assertSame( 0, $store->upload_size( 'discard1' ) );
	}

	/**
	 * Sweeping removes part files older than the cutoff and keeps fresh ones.
	 *
	 * @return void
	 */
	public function test_sweep_stale_uploads_removes_only_old_parts(): void {
		$store = new BackupStore( $this->base );
		$store->ensure_uploads_directory();
		$store->append_chunk( 'fresh123', $this->temp_file( 'fresh' ), true );
		$store->append_chunk( 'stale123', $this->temp_file( 'stale' ), true );

		// Backdate the stale part's mtime well past any sweep cutoff.
		$stale = $store->uploads_directory() . '/stale123.part';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Backdating a fixture part file's mtime to exercise the sweep.
		touch( $stale, time() - 100000 );

		$store->sweep_stale_uploads( 86400 );

		$this->assertSame( 0, $store->upload_size( 'stale123' ), 'The old part is swept.' );
		$this->assertSame( 5, $store->upload_size( 'fresh123' ), 'A fresh part is kept.' );
	}

	/**
	 * Write a temporary file holding the given bytes and return its path.
	 *
	 * Stands in for the uploaded chunk's temp file the controller hands to the store.
	 *
	 * @param string $content The bytes to write.
	 * @return string The absolute path of the written temp file.
	 */
	private function temp_file( string $content ): string {
		if ( ! is_dir( $this->base ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating a temp fixture directory for a chunk file.
			mkdir( $this->base, 0700, true );
		}
		$path = $this->base . '/chunk-' . uniqid( '', true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing a temp fixture chunk file.
		file_put_contents( $path, $content );
		return $path;
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
