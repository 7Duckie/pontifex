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
use Patchwork\CodeManipulation\Stream as PatchworkStream;
use PHPUnit\Framework\TestCase;
use Pontifex\Admin\BackupStore;
use ReflectionMethod;
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
	 * Lists matching files sorted oldest-first by MODIFICATION TIME, ignoring
	 * foreign names.
	 *
	 * Modification times are set explicitly with touch() so the order does
	 * not depend on how fast the test runs relative to the wall clock.
	 *
	 * @return void
	 */
	public function test_backups_lists_matching_files_sorted(): void {
		$store = new BackupStore( $this->base );
		$store->ensure_directory();
		$now = time();
		$this->seed_with_mtime( $store, 'pontifex-backup-20260301T093000Z.wpmig', $now - 100 );
		$this->seed_with_mtime( $store, 'pontifex-backup-20260101T120000Z.wpmig', $now - 200 );
		$this->seed( $store, 'not-a-backup.txt' );

		$backups = $store->backups();

		$this->assertCount( 2, $backups, 'Only the two correctly-named backups should be listed.' );
		$this->assertStringEndsWith( '20260101T120000Z.wpmig', $backups[0], 'The OLDER modification time sorts first.' );
		$this->assertStringEndsWith( '20260301T093000Z.wpmig', $backups[1] );
	}

	/**
	 * Ordering follows modification time even when a file's NAME disagrees
	 * with it — proving the sort is not secretly still keyed on the name.
	 *
	 * @return void
	 */
	public function test_backups_sort_by_modification_time_even_when_name_disagrees(): void {
		$store = new BackupStore( $this->base );
		$store->ensure_directory();
		$now = time();
		// Name-wise this looks OLDER (an earlier encoded date) than the other,
		// but its real modification time is the NEWER of the two.
		$this->seed_with_mtime( $store, 'pontifex-backup-20260101T000000Z.wpmig', $now - 50 );
		$this->seed_with_mtime( $store, 'pontifex-backup-20260601T000000Z.wpmig', $now - 500 );

		$backups = $store->backups();

		$this->assertStringEndsWith(
			'20260601T000000Z.wpmig',
			$backups[0],
			'The file with the OLDER modification time sorts first, even though its name looks newer.'
		);
		$this->assertStringEndsWith(
			'20260101T000000Z.wpmig',
			$backups[1],
			'The file with the NEWER modification time sorts last, even though its name looks older.'
		);
	}

	/**
	 * The measured production defect: a future-dated NAME whose real
	 * modification time is genuinely the oldest of the set sorts first, so a
	 * retention prune removes it — not the genuinely current backups it used
	 * to survive at the expense of.
	 *
	 * @return void
	 */
	public function test_a_future_named_file_with_a_genuinely_old_modification_time_sorts_first(): void {
		$store = new BackupStore( $this->base );
		$store->ensure_directory();
		$now = time();
		$this->seed_with_mtime( $store, 'pontifex-backup-20991231T235959Z.wpmig', $now - 10000 );
		$this->seed_with_mtime( $store, 'pontifex-backup-20260101T000000Z.wpmig', $now - 500 );
		$this->seed_with_mtime( $store, 'pontifex-backup-20260102T000000Z.wpmig', $now - 100 );

		$backups = $store->backups();

		$this->assertStringEndsWith(
			'20991231T235959Z.wpmig',
			$backups[0],
			'A future-dated NAME with a genuinely old modification time must sort FIRST, not last, so it is the one a prune removes.'
		);
		$this->assertStringEndsWith( '20260101T000000Z.wpmig', $backups[1] );
		$this->assertStringEndsWith( '20260102T000000Z.wpmig', $backups[2] );
	}

	/**
	 * A modification time genuinely in the future is untrustworthy about that
	 * one file, so it sorts FIRST — as the oldest, ahead of every trustworthy
	 * entry — rather than being clamped to "now" and left to tie with a
	 * backup that is actually current. This is the PRIMARY rule from
	 * {@see \Pontifex\Admin\BackupStore::compare_by_age()}, not a tie-break:
	 * the two entries here land on the same clamped instant (one genuinely
	 * future, one genuinely now) precisely to prove the future-dated one
	 * still sorts first even though clamping alone would make them tie.
	 * Proving that against a live clock from outside the class would mean
	 * racing backups()'s own internal `time()` call, which is exactly the
	 * kind of wall-clock dependency these tests must not have — so this one
	 * instead invokes the private comparator directly, via Reflection (the
	 * same technique used elsewhere in this suite, e.g. StatsCommandTest),
	 * with an explicit, fixed "now" that has no relation to the real clock at
	 * all.
	 *
	 * Goes through {@see self::compare_by_age_without_patchwork_stream_wrapper()}
	 * rather than invoking the reflection call directly: once any brain/monkey
	 * test has run earlier in the suite's shared process, Patchwork's global
	 * `file://` stream-wrapper registration reads a touch()-set modification
	 * time back one second high — invisible to every other test in this suite,
	 * which only ever compares modification times against each other or
	 * against a wide real-clock margin, but fatal to this one, which pins an
	 * exact "now" against an exact one-second-future boundary.
	 *
	 * @return void
	 */
	public function test_a_future_modification_time_does_not_outrank_a_genuinely_fresh_backup(): void {
		$store = new BackupStore( $this->base );
		$store->ensure_directory();

		// An arbitrary fixed reference instant; any value works, since neither
		// the comparator nor this test consults the real clock at all.
		$now = 2000000000;
		$this->seed_with_mtime( $store, 'pontifex-backup-20990101T000000Z.wpmig', $now + 1000000 );
		$this->seed_with_mtime( $store, 'pontifex-backup-20260101T000000Z.wpmig', $now );

		$forged = $store->directory() . '/pontifex-backup-20990101T000000Z.wpmig';
		$fresh  = $store->directory() . '/pontifex-backup-20260101T000000Z.wpmig';

		$result = $this->compare_by_age_without_patchwork_stream_wrapper( $forged, $fresh, $now );

		$this->assertLessThan(
			0,
			$result,
			'A clamped (untrusted, future) modification time must sort before an unclamped (trusted) one — it must never survive at the fresh backup\'s expense.'
		);
	}

	/**
	 * Two backups sharing the exact same modification time still resolve to
	 * a stable, deterministic order — by name, ascending.
	 *
	 * @return void
	 */
	public function test_ties_at_the_same_modification_time_break_by_name(): void {
		$store = new BackupStore( $this->base );
		$store->ensure_directory();
		$tied = time() - 500;
		$this->seed_with_mtime( $store, 'pontifex-backup-20260301T000000Z.wpmig', $tied );
		$this->seed_with_mtime( $store, 'pontifex-backup-20260101T000000Z.wpmig', $tied );

		$backups = $store->backups();

		$this->assertStringEndsWith( '20260101T000000Z.wpmig', $backups[0], 'Equal modification times must resolve by name, ascending.' );
		$this->assertStringEndsWith( '20260301T000000Z.wpmig', $backups[1] );
	}

	/**
	 * A backup whose modification time cannot be read sorts as CURRENT time,
	 * never as the oldest — because "oldest" is what a prune removes.
	 *
	 * A dangling symlink is how this is produced deterministically: filemtime()
	 * follows the link, the target does not exist, and the read fails — the
	 * same failure shape as the function being entirely removed via
	 * disable_functions, without needing to alter the host's configuration.
	 *
	 * @return void
	 */
	public function test_an_unreadable_modification_time_sorts_as_current_not_oldest(): void {
		$store = new BackupStore( $this->base );
		$store->ensure_directory();
		$this->seed_with_mtime( $store, 'pontifex-backup-20260101T000000Z.wpmig', time() - 100000 );

		$broken = $store->directory() . '/pontifex-backup-20260601T000000Z.wpmig';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_symlink -- Test fixture setup; a dangling symlink is how an unreadable modification time is produced without touching host configuration.
		symlink( 'this-target-does-not-exist.wpmig', $broken );

		$backups = $store->backups();

		$this->assertCount( 2, $backups );
		$this->assertStringEndsWith(
			'20260601T000000Z.wpmig',
			$backups[1],
			'An unreadable modification time must be treated as CURRENT time — never as the oldest, which is what a prune removes first.'
		);
	}

	/**
	 * A file the loose glob catches but the strict pattern refuses is not listed,
	 * so every listed backup can always be verified, downloaded, or deleted.
	 *
	 * @return void
	 */
	public function test_backups_excludes_a_glob_match_that_resolve_would_refuse(): void {
		$store = new BackupStore( $this->base );
		$store->ensure_directory();
		$this->seed( $store, 'pontifex-backup-20260101T000000Z.wpmig' );
		$this->seed( $store, 'pontifex-backup-corrupt.wpmig' );

		$backups = $store->backups();

		$this->assertCount( 1, $backups, 'A backup-prefixed file without a valid timestamp must not be listed.' );
		$this->assertStringEndsWith( '20260101T000000Z.wpmig', $backups[0] );
		$this->assertNull( $store->resolve( 'pontifex-backup-corrupt.wpmig' ), 'The non-conforming file must not resolve either, so list and actions agree.' );
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
	 * The confinement check does not depend on the platform's path separator.
	 *
	 * `$this->directory` is built with forward slashes, but `realpath()` answers
	 * in the platform's own separator — a backslash on Windows. The guard used a
	 * hard-coded `/`, which can never prefix a real Windows path, so `resolve()`
	 * returned null for every legitimate backup. This method is the single gate
	 * behind Download, Delete, Verify, Restore and Preview, so on Windows all
	 * five answered "that backup could not be found" for backups listed on the
	 * screen in front of the operator. Creating one still worked, because that
	 * path never calls `resolve()` — so the site produced backups it then
	 * refused to touch.
	 *
	 * The separator logic is asserted directly rather than through the
	 * filesystem, because a separator defect is invisible on the platform that
	 * does not have the separator: this suite runs where DIRECTORY_SEPARATOR is
	 * already `/`, so only a Windows-shaped pair of paths can show it.
	 *
	 * @return void
	 */
	public function test_confinement_holds_for_windows_shaped_paths(): void {
		$directory = 'C:\\inetpub\\wwwroot\\wp-content\\pontifex\\backups';
		$inside    = $directory . '\\pontifex-backup-20260101T000000Z.wpmig';
		$sibling   = 'C:\\inetpub\\wwwroot\\wp-content\\pontifex\\backups-other\\stolen.wpmig';

		$normalise = static fn ( string $path ): string => str_replace( '\\', '/', $path );

		$this->assertSame(
			0,
			strpos( $normalise( $inside ), $normalise( $directory ) . '/' ),
			'A backup inside the directory must confine on a Windows-shaped path.'
		);

		$this->assertNotSame(
			0,
			strpos( $normalise( $sibling ), $normalise( $directory ) . '/' ),
			'A sibling directory whose name merely starts the same must still be refused.'
		);
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
	 * Create an empty file with the given name and an explicit modification
	 * time inside the store directory, so ordering tests do not depend on
	 * wall-clock timing.
	 *
	 * @param BackupStore $store    The store whose directory to seed.
	 * @param string      $filename The filename to create.
	 * @param int         $mtime    The Unix modification time to stamp the file with.
	 * @return void
	 */
	private function seed_with_mtime( BackupStore $store, string $filename, int $mtime ): void {
		$this->seed( $store, $filename );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Backdating or forward-dating a fixture backup's mtime so ordering tests are deterministic.
		touch( $store->directory() . '/' . $filename, $mtime );
	}

	/**
	 * Invoke {@see BackupStore::compare_by_age()} with Brain Monkey's Patchwork
	 * stream-wrapper override briefly disabled, when it is active.
	 *
	 * This class does not itself use Brain Monkey, but PHPUnit runs every test
	 * class in one shared process, and once ANY earlier test elsewhere in the
	 * suite has called `Monkey\setUp()`, Patchwork's `file://` stream wrapper
	 * stays registered for the rest of that process — it is not undone by that
	 * other test's own tearDown(). While it is active, `filemtime()` on a file
	 * that was `touch()`-ed to an explicit timestamp reads back one second
	 * high, which is invisible to every other ordering test in this suite
	 * (they only ever compare modification times against each other or against
	 * a wide real-clock margin) but corrupts a comparison pinned to an exact
	 * one-second boundary, as this one is. Unwrapping only around the read —
	 * not the earlier `touch()` writes, which land on disk correctly regardless
	 * — is enough: {@see \Pontifex\Tests\Unit\Cli\DiagnosticsCommand\InvokeTest}
	 * uses the same `Stream::wrap()`/`unwrap()` toggle for an unrelated
	 * Patchwork collision. The `class_exists()` guard is needed here (unlike
	 * that test, which extends the Brain-Monkey-enabled base class and so is
	 * always guaranteed Patchwork is loaded): this class extends plain
	 * PHPUnit\Framework\TestCase, so running this file alone never loads
	 * Patchwork at all, and referencing `PatchworkStream::unwrap()` when the
	 * class has never been loaded would itself fatal.
	 *
	 * @param string $a   One absolute backup path.
	 * @param string $b   Another absolute backup path.
	 * @param int    $now The reference "now" to compare against.
	 * @return int The comparator's result.
	 */
	private function compare_by_age_without_patchwork_stream_wrapper( string $a, string $b, int $now ): int {
		$patchwork_loaded = class_exists( PatchworkStream::class );
		if ( $patchwork_loaded ) {
			PatchworkStream::unwrap();
		}
		try {
			return ( new ReflectionMethod( BackupStore::class, 'compare_by_age' ) )->invoke( null, $a, $b, $now );
		} finally {
			if ( $patchwork_loaded ) {
				PatchworkStream::wrap();
			}
		}
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
