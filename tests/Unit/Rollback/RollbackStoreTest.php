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
use Pontifex\Rollback\RollbackStore;
use Pontifex\Tests\TestCase;

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
	 * Archives list oldest-first and most_recent returns the newest.
	 *
	 * @return void
	 */
	public function test_archives_are_chronological_and_most_recent_is_the_newest(): void {
		$store = new RollbackStore( $this->base );
		$store->ensure_directory();

		$this->touch_archive( $store, '20260101T000000Z' );
		$this->touch_archive( $store, '20260622T143000Z' );
		$this->touch_archive( $store, '20260301T120000Z' );

		$archives = $store->archives();

		$this->assertCount( 3, $archives );
		$this->assertStringEndsWith( '20260101T000000Z.wpmig', $archives[0] );
		$this->assertStringEndsWith( '20260301T120000Z.wpmig', $archives[1] );
		$this->assertStringEndsWith( '20260622T143000Z.wpmig', $archives[2] );
		$this->assertSame( $archives[2], $store->most_recent() );
	}

	/**
	 * Pruning keeps only the newest N and removes the rest.
	 *
	 * @return void
	 */
	public function test_prune_keeps_only_the_newest_n(): void {
		$store = new RollbackStore( $this->base );
		$store->ensure_directory();

		$this->touch_archive( $store, '20260101T000000Z' );
		$this->touch_archive( $store, '20260301T120000Z' );
		$this->touch_archive( $store, '20260622T143000Z' );

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
		$this->touch_archive( $store, '20260101T000000Z' );

		$store->prune( 5 );

		$this->assertCount( 1, $store->archives() );
	}

	/**
	 * Create an empty file named like a safety archive at the given timestamp.
	 *
	 * @param RollbackStore $store     The store whose directory to write into.
	 * @param string        $timestamp The UTC timestamp segment, e.g. 20260622T143000Z.
	 * @return void
	 */
	private function touch_archive( RollbackStore $store, string $timestamp ): void {
		$path = $store->directory() . '/pre-import-rollback-' . $timestamp . '.wpmig';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Creating an empty fixture archive file in a temp directory for the test.
		touch( $path );
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
