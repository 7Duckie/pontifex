<?php
/**
 * Tests for RollbackStore — the safety-archive directory.
 *
 * @package Pontifex\Tests\Unit\Rollback
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Rollback;

use DateTimeImmutable;
use DateTimeZone;
use Patchwork\CodeManipulation\Stream as PatchworkStream;
use Pontifex\Rollback\RollbackStore;
use Pontifex\Tests\TestCase;
use ReflectionMethod;

/**
 * Exercises the store against a real temporary directory.
 *
 * The store does filesystem work that the Environment seam does not cover, so —
 * like FileWriter and FileLogger — it is tested against a real temp directory
 * rather than a mock. Covers the policy from ADR 0005: location, UTC naming,
 * chronological ordering, most-recent selection, the not-world-readable
 * directory, and N-retention pruning.
 */
final class RollbackStoreTest extends TestCase {


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
		$this->base = sys_get_temp_dir() . '/pontifex-rollback-store-' . uniqid( '', true );
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
	 * The directory is pontifex/rollback under the content directory.
	 *
	 * @return void
	 */
	public function test_directory_is_under_content_pontifex_rollback(): void {
		$store = new RollbackStore( $this->base );
		$this->assertSame( $this->base . '/pontifex/rollback', $store->directory() );
	}

	/**
	 * A trailing slash on the content directory is normalised away.
	 *
	 * @return void
	 */
	public function test_directory_trims_a_trailing_slash(): void {
		$store = new RollbackStore( $this->base . '/' );
		$this->assertSame( $this->base . '/pontifex/rollback', $store->directory() );
	}

	/**
	 * Created directories are owner-only — no group or world access.
	 *
	 * The group/other permission bits must be zero: we request mode 0700 and a
	 * umask can only clear bits, never add them, so the result is reliably
	 * owner-only regardless of the host umask.
	 *
	 * @return void
	 */
	public function test_ensure_directory_creates_an_owner_only_directory(): void {
		$store = new RollbackStore( $this->base );
		$this->assertDirectoryDoesNotExist( $store->directory() );

		$store->ensure_directory();

		$this->assertDirectoryExists( $store->directory() );
		$this->assertSame(
			0,
			fileperms( $store->directory() ) & 0077,
			'The rollback directory must not be group- or world-accessible.'
		);
	}

	/**
	 * The archive path encodes the time as UTC, so most-recent sorts last.
	 *
	 * @return void
	 */
	public function test_next_archive_path_encodes_utc_time(): void {
		$store = new RollbackStore( $this->base );
		$now   = new DateTimeImmutable( '2026-06-22T14:30:00', new DateTimeZone( 'UTC' ) );

		$this->assertSame(
			$store->directory() . '/pre-import-rollback-20260622T143000Z.wpmig',
			$store->next_archive_path( $now )
		);
	}

	/**
	 * A non-UTC time is converted to UTC in the filename.
	 *
	 * @return void
	 */
	public function test_next_archive_path_converts_to_utc(): void {
		$store = new RollbackStore( $this->base );
		$now   = new DateTimeImmutable( '2026-06-22T14:30:00', new DateTimeZone( '+02:00' ) );

		$this->assertSame(
			$store->directory() . '/pre-import-rollback-20260622T123000Z.wpmig',
			$store->next_archive_path( $now )
		);
	}

	/**
	 * The most-recent archive is null when there are none.
	 *
	 * @return void
	 */
	public function test_most_recent_is_null_when_there_are_no_archives(): void {
		$store = new RollbackStore( $this->base );
		$this->assertNull( $store->most_recent() );
	}

	/**
	 * Archives list oldest-first BY MODIFICATION TIME and most_recent returns
	 * the newest. Modification times are set explicitly via
	 * {@see self::touch_archive()} so the order does not depend on wall-clock
	 * timing.
	 *
	 * @return void
	 */
	public function test_archives_are_chronological_and_most_recent_is_the_newest(): void {
		$store = new RollbackStore( $this->base );
		$store->ensure_directory();
		$now = time();

		$this->touch_archive( $store, '20260101T000000Z', $now - 300 );
		$this->touch_archive( $store, '20260622T143000Z', $now - 100 );
		$this->touch_archive( $store, '20260301T120000Z', $now - 200 );

		$archives = $store->archives();

		$this->assertCount( 3, $archives );
		$this->assertStringEndsWith( '20260101T000000Z.wpmig', $archives[0] );
		$this->assertStringEndsWith( '20260301T120000Z.wpmig', $archives[1] );
		$this->assertStringEndsWith( '20260622T143000Z.wpmig', $archives[2] );
		$this->assertSame( $archives[2], $store->most_recent() );
	}

	/**
	 * Ordering follows modification time even when a file's NAME disagrees
	 * with it — proving the sort is not secretly still keyed on the name.
	 *
	 * @return void
	 */
	public function test_archives_sort_by_modification_time_even_when_name_disagrees(): void {
		$store = new RollbackStore( $this->base );
		$store->ensure_directory();
		$now = time();
		// Name-wise this looks OLDER (an earlier encoded date) than the other,
		// but its real modification time is the NEWER of the two.
		$this->touch_archive( $store, '20260101T000000Z', $now - 50 );
		$this->touch_archive( $store, '20260601T000000Z', $now - 500 );

		$archives = $store->archives();

		$this->assertStringEndsWith(
			'20260601T000000Z.wpmig',
			$archives[0],
			'The archive with the OLDER modification time sorts first, even though its name looks newer.'
		);
		$this->assertStringEndsWith(
			'20260101T000000Z.wpmig',
			$archives[1],
			'The archive with the NEWER modification time sorts last, even though its name looks older.'
		);
	}

	/**
	 * A future-dated NAME whose real modification time is genuinely the
	 * oldest of the set sorts first, so pruning removes it — not the
	 * genuinely current archives it used to survive at the expense of.
	 *
	 * @return void
	 */
	public function test_a_future_named_archive_with_a_genuinely_old_modification_time_sorts_first(): void {
		$store = new RollbackStore( $this->base );
		$store->ensure_directory();
		$now = time();
		$this->touch_archive( $store, '20991231T235959Z', $now - 10000 );
		$this->touch_archive( $store, '20260101T000000Z', $now - 500 );
		$this->touch_archive( $store, '20260102T000000Z', $now - 100 );

		$archives = $store->archives();

		$this->assertStringEndsWith(
			'20991231T235959Z.wpmig',
			$archives[0],
			'A future-dated NAME with a genuinely old modification time must sort FIRST, not last, so it is the one pruning removes.'
		);
		$this->assertStringEndsWith( '20260101T000000Z.wpmig', $archives[1] );
		$this->assertStringEndsWith( '20260102T000000Z.wpmig', $archives[2] );
	}

	/**
	 * A modification time genuinely in the future is untrustworthy about that
	 * one file, so it sorts FIRST — as the oldest, ahead of every trustworthy
	 * entry — rather than being clamped to "now" and left to tie with an
	 * archive that is actually current. This is the PRIMARY rule from
	 * {@see \Pontifex\Rollback\RollbackStore::compare_by_age()}, not a
	 * tie-break: the two entries here land on the same clamped instant (one
	 * genuinely future, one genuinely now) precisely to prove the
	 * future-dated one still sorts first even though clamping alone would
	 * make them tie. Proving that against a live clock from outside the
	 * class would mean racing archives()'s own internal `time()` call, which
	 * is exactly the kind of wall-clock dependency these tests must not have
	 * — so this one instead invokes the private comparator directly, via
	 * Reflection (the same technique used elsewhere in this suite, e.g.
	 * StatsCommandTest), with an explicit, fixed "now" that has no relation
	 * to the real clock at all.
	 *
	 * Goes through {@see self::compare_by_age_without_patchwork_stream_wrapper()}
	 * rather than invoking the reflection call directly: this class's own
	 * setUp() calls `Monkey\setUp()`, which registers Patchwork's global
	 * `file://` stream wrapper, and while it is active `filemtime()` on a file
	 * that was `touch()`-ed to an explicit timestamp reads back one second
	 * high — invisible to every other ordering test in this suite (they only
	 * ever compare modification times against each other or against a wide
	 * real-clock margin) but corrupts a comparison pinned to an exact
	 * one-second boundary, as this one is.
	 *
	 * @return void
	 */
	public function test_a_future_modification_time_does_not_outrank_a_genuinely_fresh_archive(): void {
		$store = new RollbackStore( $this->base );
		$store->ensure_directory();

		// An arbitrary fixed reference instant; any value works, since neither
		// the comparator nor this test consults the real clock at all.
		$now = 2000000000;
		$this->touch_archive( $store, '20990101T000000Z', $now + 1000000 );
		$this->touch_archive( $store, '20260101T000000Z', $now );

		$forged = $store->directory() . '/pre-import-rollback-20990101T000000Z.wpmig';
		$fresh  = $store->directory() . '/pre-import-rollback-20260101T000000Z.wpmig';

		$result = $this->compare_by_age_without_patchwork_stream_wrapper( $forged, $fresh, $now );

		$this->assertLessThan(
			0,
			$result,
			'A clamped (untrusted, future) modification time must sort before an unclamped (trusted) one — it must never survive at the fresh archive\'s expense.'
		);
	}

	/**
	 * Two archives sharing the exact same modification time still resolve to
	 * a stable, deterministic order — by name, ascending.
	 *
	 * @return void
	 */
	public function test_ties_at_the_same_modification_time_break_by_name(): void {
		$store = new RollbackStore( $this->base );
		$store->ensure_directory();
		$tied = time() - 500;
		$this->touch_archive( $store, '20260301T000000Z', $tied );
		$this->touch_archive( $store, '20260101T000000Z', $tied );

		$archives = $store->archives();

		$this->assertStringEndsWith( '20260101T000000Z.wpmig', $archives[0], 'Equal modification times must resolve by name, ascending.' );
		$this->assertStringEndsWith( '20260301T000000Z.wpmig', $archives[1] );
	}

	/**
	 * An archive whose modification time cannot be read sorts as CURRENT
	 * time, never as the oldest — because "oldest" is what pruning removes.
	 *
	 * A dangling symlink is how this is produced deterministically:
	 * filemtime() follows the link, the target does not exist, and the read
	 * fails — the same failure shape as the function being entirely removed
	 * via disable_functions, without needing to alter the host's
	 * configuration.
	 *
	 * @return void
	 */
	public function test_an_unreadable_modification_time_sorts_as_current_not_oldest(): void {
		$store = new RollbackStore( $this->base );
		$store->ensure_directory();
		$this->touch_archive( $store, '20260101T000000Z', time() - 100000 );

		$broken = $store->directory() . '/pre-import-rollback-20260601T000000Z.wpmig';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_symlink -- Test fixture setup; a dangling symlink is how an unreadable modification time is produced without touching host configuration.
		symlink( 'this-target-does-not-exist.wpmig', $broken );

		$archives = $store->archives();

		$this->assertCount( 2, $archives );
		$this->assertStringEndsWith(
			'20260601T000000Z.wpmig',
			$archives[1],
			'An unreadable modification time must be treated as CURRENT time — never as the oldest, which is what pruning removes first.'
		);
	}

	/**
	 * Pruning keeps only the newest N and removes the rest.
	 *
	 * @return void
	 */
	public function test_prune_keeps_only_the_newest_n(): void {
		$store = new RollbackStore( $this->base );
		$store->ensure_directory();
		$now = time();

		$this->touch_archive( $store, '20260101T000000Z', $now - 300 );
		$this->touch_archive( $store, '20260301T120000Z', $now - 200 );
		$this->touch_archive( $store, '20260622T143000Z', $now - 100 );

		$store->prune( 1 );

		$remaining = $store->archives();
		$this->assertCount( 1, $remaining );
		$this->assertStringEndsWith( '20260622T143000Z.wpmig', $remaining[0] );
	}

	/**
	 * Pruning is a no-op when there are fewer archives than the keep count.
	 *
	 * @return void
	 */
	public function test_prune_keeps_all_when_keep_exceeds_count(): void {
		$store = new RollbackStore( $this->base );
		$store->ensure_directory();
		$this->touch_archive( $store, '20260101T000000Z', time() - 300 );

		$store->prune( 5 );

		$this->assertCount( 1, $store->archives() );
	}

	/**
	 * Create an empty file named like a safety archive at the given timestamp,
	 * with an explicit modification time so ordering tests do not depend on
	 * wall-clock timing.
	 *
	 * @param RollbackStore $store     The store whose directory to write into.
	 * @param string        $timestamp The UTC timestamp segment, e.g. 20260622T143000Z.
	 * @param int           $mtime     The Unix modification time to stamp the file with.
	 * @return void
	 */
	private function touch_archive( RollbackStore $store, string $timestamp, int $mtime ): void {
		$path = $store->directory() . '/pre-import-rollback-' . $timestamp . '.wpmig';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Creating an empty fixture archive file, at an explicit modification time, in a temp directory for the test.
		touch( $path, $mtime );
	}

	/**
	 * Invoke {@see RollbackStore::compare_by_age()} with Brain Monkey's Patchwork
	 * stream-wrapper override briefly disabled, when it is active.
	 *
	 * This class's own setUp() calls `Monkey\setUp()` (via the parent
	 * {@see \Pontifex\Tests\TestCase}), which registers Patchwork's `file://`
	 * stream wrapper for the rest of the shared PHPUnit process — it is not
	 * undone by tearDown(). While it is active, `filemtime()` on a file that
	 * was `touch()`-ed to an explicit timestamp reads back one second high,
	 * which is invisible to every other ordering test in this suite (they
	 * only ever compare modification times against each other or against a
	 * wide real-clock margin) but corrupts a comparison pinned to an exact
	 * one-second boundary, as this one is. Unwrapping only around the read —
	 * not the earlier `touch()` writes, which land on disk correctly
	 * regardless — is enough:
	 * {@see \Pontifex\Tests\Unit\Cli\DiagnosticsCommand\InvokeTest} uses the
	 * same `Stream::wrap()`/`unwrap()` toggle for an unrelated Patchwork
	 * collision. `class_exists()` guards the toggle in case a future change
	 * calls this before Brain Monkey has run for the process.
	 *
	 * @param string $a   One absolute archive path.
	 * @param string $b   Another absolute archive path.
	 * @param int    $now The reference "now" to compare against.
	 * @return int The comparator's result.
	 */
	private function compare_by_age_without_patchwork_stream_wrapper( string $a, string $b, int $now ): int {
		$patchwork_loaded = class_exists( PatchworkStream::class );
		if ( $patchwork_loaded ) {
			PatchworkStream::unwrap();
		}
		try {
			return ( new ReflectionMethod( RollbackStore::class, 'compare_by_age' ) )->invoke( null, $a, $b, $now );
		} finally {
			if ( $patchwork_loaded ) {
				PatchworkStream::wrap();
			}
		}
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
