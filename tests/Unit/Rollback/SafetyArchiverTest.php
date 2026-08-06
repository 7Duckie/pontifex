<?php
/**
 * Tests for SafetyArchiver — taking a pre-import safety archive.
 *
 * @package Pontifex\Tests\Unit\Rollback
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Rollback;

use Mockery;
use ReflectionMethod;
use RuntimeException;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Format\Provenance;
use Pontifex\Archive\Reader\ArchiveReader;
use Pontifex\Archive\Writer\EntryPlan;
use Pontifex\Archive\Writer\EntryWriter;
use Pontifex\Environment\Environment;
use Pontifex\Filesystem\TempArtefact;
use Pontifex\Manifest\ManifestBuilderInterface;
use Pontifex\Rollback\RollbackStore;
use Pontifex\Rollback\SafetyArchiver;
use Pontifex\Tests\TestCase;
use Pontifex\WordPress\WordPressContext;

/**
 * Exercises create() with a real archive writer and a fake manifest builder.
 *
 * The manifest builder is injected so no real installation is scanned; the
 * entry plans are hand-built from in-memory bytes (the same approach
 * RoundTripTest uses). That makes this a sociable test: a real RollbackStore in
 * a temp directory and a real ArchiveWriter produce a genuine archive, which is
 * then read back to prove it is well-formed. The preflight is covered with a
 * mocked free-space reading below the estimate.
 */
final class SafetyArchiverTest extends TestCase {


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
		$this->base = sys_get_temp_dir() . '/pontifex-safety-archiver-' . uniqid( '', true );
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
	 * A created safety archive is well-formed and owner-only; the oldest beyond the floor is pruned.
	 *
	 * The retention floor is 2 (ADR 0005 as amended): the archive just written
	 * plus the previous one — the undo the auto-rollback and `wp pontifex
	 * rollback` depend on — survive, and only older archives are pruned.
	 *
	 * @return void
	 */
	public function test_create_writes_a_restorable_archive_and_prunes_beyond_the_floor(): void {
		$store = new RollbackStore( $this->base );
		$store->ensure_directory();

		// Two older safety archives: the newer must survive (it is the previous
		// restore's undo), the older must be pruned.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Seeding an older fixture archive in a temp directory.
		touch( $store->directory() . '/pre-import-rollback-20200101T000000Z.wpmig' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Seeding an older fixture archive in a temp directory.
		touch( $store->directory() . '/pre-import-rollback-20200102T000000Z.wpmig' );

		$plans = array(
			$this->file_plan( 'index.php', "<?php\n// fixture\n" ),
			$this->file_plan( 'wp-content/note.txt', "café ☕\n" ),
		);

		$archiver = new SafetyArchiver(
			$this->environment_with_free_space( (float) ( 1024 * 1024 * 1024 ) ),
			$this->wordpress_context_mock(),
			$store,
			$this->manifest_builder_returning( $plans )
		);

		$path = $archiver->create( '/var/www/html' );

		// The returned archive exists, is owner-only, and only the floor remains.
		$this->assertFileExists( $path );
		$this->assertSame( 0600, fileperms( $path ) & 0777, 'A safety archive must be owner read/write only.' );
		$this->assertCount( 2, $store->archives(), 'Retention must keep the new archive plus the previous one, and prune the rest.' );
		$this->assertFileDoesNotExist( $store->directory() . '/pre-import-rollback-20200101T000000Z.wpmig', 'The archive beyond the floor must be pruned.' );
		$this->assertFileExists( $store->directory() . '/pre-import-rollback-20200102T000000Z.wpmig', 'The previous archive — the standing undo — must survive.' );
		$this->assertSame( $path, $store->most_recent() );

		// The archive is well-formed: it reads back with the two entries.
		$this->assertSame( 2, $this->entry_count( $path ), 'The written archive should contain both entries.' );
	}

	/**
	 * A safety archive whose file listing is too large to read back must stop
	 * the restore, not proceed with a phantom undo.
	 *
	 * ArchiveManifest::MAX_PAYLOAD_SIZE is a structural cap enforced by
	 * ArchiveReader::read_manifest() before memory is ever considered — no
	 * memory_limit, however large, opens an over-cap manifest. create() never
	 * reads back what it wrote, so a silently-unreadable safety archive would
	 * leave a destructive restore believing rollback is available when it is
	 * not. Proven with the same deliberately-oversized single entry
	 * {@see \Pontifex\Tests\Unit\Export\ExportRunnerTest} uses to prove the
	 * refusal fires for an ordinary export — this test asserts create() turns
	 * that refusal into a restore-stopping RuntimeException, not that a file
	 * merely appeared on disk.
	 *
	 * @return void
	 */
	public function test_create_stops_the_restore_when_the_manifest_is_too_large_to_read_back(): void {
		$store = new RollbackStore( $this->base );
		$plans = array( $this->file_plan( str_repeat( 'a', 17000000 ), 'x' ) );

		$archiver = new SafetyArchiver(
			$this->environment_with_free_space( (float) ( 1024 * 1024 * 1024 ) ),
			$this->wordpress_context_mock(),
			$store,
			$this->manifest_builder_returning( $plans )
		);

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'A safety archive could not be taken for this site because its file listing (1 entries) is too large for Pontifex to read back; the restore has been stopped because it could not be undone.' );

		$archiver->create( '/var/www/html' );
	}

	/**
	 * An ordinary-sized safety archive is unaffected by removing the
	 * exemption: create() still writes it, and it still reads back cleanly.
	 *
	 * Proves the refusal added above is scoped to the pathological
	 * oversized-manifest case, not a regression that stops every safety
	 * archive.
	 *
	 * @return void
	 */
	public function test_create_still_writes_an_ordinary_sized_archive(): void {
		$store = new RollbackStore( $this->base );
		$plans = array(
			$this->file_plan( 'index.php', "<?php\n// fixture\n" ),
			$this->file_plan( 'wp-content/note.txt', "café ☕\n" ),
		);

		$archiver = new SafetyArchiver(
			$this->environment_with_free_space( (float) ( 1024 * 1024 * 1024 ) ),
			$this->wordpress_context_mock(),
			$store,
			$this->manifest_builder_returning( $plans )
		);

		$path = $archiver->create( '/var/www/html' );

		$this->assertSame( 2, $this->entry_count( $path ), 'An ordinary-sized safety archive must still be written and read back cleanly.' );
	}

	/**
	 * A retention below the floor is clamped up to 2, never honoured.
	 *
	 * A second restore's safety archive must never prune the first restore's —
	 * that archive is the only undo for the state the second restore overwrites,
	 * and both the auto-rollback and the manual rollback depend on it. The floor
	 * lives in the constructor so no call site can reintroduce the loss.
	 *
	 * @return void
	 */
	public function test_retention_below_the_floor_is_clamped_to_two(): void {
		$store = new RollbackStore( $this->base );
		$store->ensure_directory();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Seeding an older fixture archive in a temp directory.
		touch( $store->directory() . '/pre-import-rollback-20200102T000000Z.wpmig' );

		$plans    = array( $this->file_plan( 'wp-content/note.txt', "content\n" ) );
		$archiver = new SafetyArchiver(
			$this->environment_with_free_space( (float) ( 1024 * 1024 * 1024 ) ),
			$this->wordpress_context_mock(),
			$store,
			$this->manifest_builder_returning( $plans ),
			1
		);

		$archiver->create( '/var/www/html' );

		$this->assertCount( 2, $store->archives(), 'A requested retention of 1 must be clamped to the floor of 2.' );
	}

	/**
	 * A retention above the floor is honoured as given.
	 *
	 * @return void
	 */
	public function test_retention_above_the_floor_is_honoured(): void {
		$store = new RollbackStore( $this->base );
		$store->ensure_directory();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Seeding an older fixture archive in a temp directory.
		touch( $store->directory() . '/pre-import-rollback-20200101T000000Z.wpmig' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Seeding an older fixture archive in a temp directory.
		touch( $store->directory() . '/pre-import-rollback-20200102T000000Z.wpmig' );

		$plans    = array( $this->file_plan( 'wp-content/note.txt', "content\n" ) );
		$archiver = new SafetyArchiver(
			$this->environment_with_free_space( (float) ( 1024 * 1024 * 1024 ) ),
			$this->wordpress_context_mock(),
			$store,
			$this->manifest_builder_returning( $plans ),
			3
		);

		$archiver->create( '/var/www/html' );

		$this->assertCount( 3, $store->archives(), 'A retention above the floor must be honoured as given.' );
	}

	/**
	 * A content-only safety archive records a content-only scope and the table prefix.
	 *
	 * The pre-import safety archive follows the restore's scope (ADR 0008): a
	 * content-only restore takes a content-only safety archive, so its provenance
	 * carries a content-only scope and the source table prefix the destination needs.
	 *
	 * @return void
	 */
	public function test_create_content_only_records_a_content_only_scope(): void {
		$store = new RollbackStore( $this->base );
		$plans = array( $this->file_plan( 'wp-content/note.txt', "content\n" ) );

		$archiver = new SafetyArchiver(
			$this->environment_with_free_space( (float) ( 1024 * 1024 * 1024 ) ),
			$this->wordpress_context_mock(),
			$store,
			$this->manifest_builder_returning( $plans ),
			2,
			true
		);

		$path = $archiver->create( '/var/www/html/wp-content' );

		$provenance = $this->read_provenance( $path );
		$scope      = $provenance->scope();
		$this->assertNotNull( $scope, 'A content-only safety archive should record a scope.' );
		$this->assertTrue( $scope->is_content_only() );
		$this->assertSame( 'wp-content', $scope->content_root() );
		$this->assertSame( 'wp_', $provenance->table_prefix() );
	}

	/**
	 * A whole-site safety archive records a whole-site scope.
	 *
	 * The default mode: a --whole-site restore takes a whole-site safety archive,
	 * whose provenance records a whole-site scope rooted at the site root.
	 *
	 * @return void
	 */
	public function test_create_whole_site_records_a_whole_site_scope(): void {
		$store = new RollbackStore( $this->base );
		$plans = array( $this->file_plan( 'wp-config.php', "<?php\n" ) );

		$archiver = new SafetyArchiver(
			$this->environment_with_free_space( (float) ( 1024 * 1024 * 1024 ) ),
			$this->wordpress_context_mock(),
			$store,
			$this->manifest_builder_returning( $plans )
		);

		$path = $archiver->create( '/var/www/html' );

		$scope = $this->read_provenance( $path )->scope();
		$this->assertNotNull( $scope, 'A whole-site safety archive should record a scope.' );
		$this->assertFalse( $scope->is_content_only() );
		$this->assertSame( '', $scope->content_root() );
	}

	/**
	 * The preflight refuses, and writes nothing, when free space is too low.
	 *
	 * @return void
	 */
	public function test_create_refuses_when_free_space_is_below_the_estimate(): void {
		$store = new RollbackStore( $this->base );

		$plans = array(
			$this->file_plan( 'big.bin', str_repeat( 'x', 4096 ) ),
		);

		$archiver = new SafetyArchiver(
			$this->environment_with_free_space( 1.0 ),
			$this->wordpress_context_mock(),
			$store,
			$this->manifest_builder_returning( $plans )
		);

		try {
			$archiver->create( '/var/www/html' );
			$this->fail( 'create() should have refused: free space is below the estimate.' );
		} catch ( RuntimeException $error ) {
			$this->assertStringContainsString( 'Not enough free disk space', $error->getMessage() );
		}

		$this->assertSame( array(), $store->archives(), 'No archive may be written when the preflight refuses.' );
	}

	/**
	 * Forwards byte progress from the export to the caller's callback.
	 *
	 * @return void
	 */
	public function test_create_forwards_byte_progress(): void {
		$store = new RollbackStore( $this->base );
		$store->ensure_directory();

		$plans = array(
			$this->file_plan( 'index.php', "<?php\n// fixture\n" ),
			$this->file_plan( 'wp-content/note.txt', "café ☕\n" ),
		);

		$archiver = new SafetyArchiver(
			$this->environment_with_free_space( (float) ( 1024 * 1024 * 1024 ) ),
			$this->wordpress_context_mock(),
			$store,
			$this->manifest_builder_returning( $plans )
		);

		$reported = 0;
		$archiver->create(
			'/var/www/html',
			null,
			static function ( int $bytes ) use ( &$reported ): void {
				$reported += $bytes;
			}
		);

		$this->assertGreaterThan( 0, $reported, 'create() forwards byte progress from the export pipeline.' );
	}

	// -------------------------------------------------------------------------
	// sweep_orphaned_archive_temps()
	// -------------------------------------------------------------------------

	/**
	 * An orphaned archive temp left by a killed safety-archive write is removed.
	 *
	 * Stands in for {@see \Pontifex\Export\ExportRunner::temp_destination_path()}'s
	 * own sibling temp, abandoned by an import that died between the write
	 * and the rename that would otherwise have completed it.
	 *
	 * @return void
	 */
	public function test_sweep_removes_an_orphaned_archive_temp(): void {
		$store = new RollbackStore( $this->base );
		$store->ensure_directory();

		$orphan = $store->directory() . '/pre-import-rollback-20260101T000000Z.wpmig' . TempArtefact::suffix();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture setup.
		file_put_contents( $orphan, 'an abandoned safety-archive write' );

		$removed = $this->invoke_sweep( $this->archiver_for_sweep_tests( $store ) );

		$this->assertSame( 1, $removed );
		$this->assertFileDoesNotExist( $orphan );
	}

	/**
	 * A real safety archive is left untouched.
	 *
	 * The most important sweep test in this suite: the sweep must never eat
	 * an operator's actual undo. A real archive is named
	 * `pre-import-rollback-<UTC>.wpmig` — no uniqid()-shaped temp suffix at
	 * all — so {@see \Pontifex\Filesystem\TempArtefact::is_orphan_name()}
	 * must never match it.
	 *
	 * @return void
	 */
	public function test_sweep_leaves_a_real_safety_archive_untouched(): void {
		$store = new RollbackStore( $this->base );
		$store->ensure_directory();

		$real = $store->directory() . '/pre-import-rollback-20260101T000000Z.wpmig';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture setup.
		file_put_contents( $real, 'a real safety archive' );

		$removed = $this->invoke_sweep( $this->archiver_for_sweep_tests( $store ) );

		$this->assertSame( 0, $removed );
		$this->assertFileExists( $real );
	}

	/**
	 * A resumable export's `.part` file is left untouched.
	 *
	 * A `.part` file is live state a still-running export is writing to —
	 * see {@see \Pontifex\Export\ResumableExportRunner} — and deleting one
	 * would destroy real, unrecoverable work. This directory could never
	 * legitimately hold one (admin and scheduled backups write `.part` files
	 * through ResumableExportRunner, a different caller entirely, never
	 * through this archiver), but the sweep must still refuse to match the
	 * shape unconditionally, exactly as
	 * {@see \Pontifex\Filesystem\TempArtefact::is_orphan_name()} does.
	 *
	 * @return void
	 */
	public function test_sweep_leaves_a_part_file_untouched(): void {
		$store = new RollbackStore( $this->base );
		$store->ensure_directory();

		$part = $store->directory() . '/pre-import-rollback-20260101T000000Z.wpmig.' . uniqid( 'pontifex-job-', true ) . '.part';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture setup.
		file_put_contents( $part, 'live resumable state' );

		$removed = $this->invoke_sweep( $this->archiver_for_sweep_tests( $store ) );

		$this->assertSame( 0, $removed );
		$this->assertFileExists( $part );
	}

	/**
	 * A directory named like a temp artefact is left alone and not counted.
	 *
	 * Never produced by anything in this plugin, but not this method's
	 * business to assume impossible — see
	 * {@see \Pontifex\Rollback\SafetyArchiver::sweep_orphaned_archive_temps()}'s
	 * own docblock (REMOVAL).
	 *
	 * @return void
	 */
	public function test_sweep_leaves_a_directory_named_like_a_temp_artefact_alone(): void {
		$store = new RollbackStore( $this->base );
		$store->ensure_directory();

		$temp_shaped_dir = $store->directory() . '/pre-import-rollback-20260101T000000Z.wpmig' . TempArtefact::suffix();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture setup.
		mkdir( $temp_shaped_dir );

		$removed = $this->invoke_sweep( $this->archiver_for_sweep_tests( $store ) );

		$this->assertSame( 0, $removed );
		$this->assertDirectoryExists( $temp_shaped_dir );
	}

	/**
	 * The returned count reflects only files actually removed.
	 *
	 * Three orphans are placed; the count must be exactly 3 — describing
	 * what was actually done, never merely how many matching names were
	 * found.
	 *
	 * @return void
	 */
	public function test_sweep_returns_the_count_of_temps_actually_removed(): void {
		$store = new RollbackStore( $this->base );
		$store->ensure_directory();

		$orphans = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$orphan = $store->directory() . '/pre-import-rollback-2026010' . $i . 'T000000Z.wpmig' . TempArtefact::suffix();
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture setup.
			file_put_contents( $orphan, 'x' );
			$orphans[] = $orphan;
		}

		$removed = $this->invoke_sweep( $this->archiver_for_sweep_tests( $store ) );

		$this->assertSame( 3, $removed );
		foreach ( $orphans as $orphan ) {
			$this->assertFileDoesNotExist( $orphan );
		}
	}

	/**
	 * The sweep runs BEFORE the free-space preflight, not after.
	 *
	 * Proves the ordering documented on {@see SafetyArchiver::create()}: an
	 * orphan is placed, the free-space reading is stubbed low enough that
	 * {@see SafetyArchiver::preflight_disk_space()} refuses, and create() is
	 * called end-to-end (not the private sweep method directly). If the
	 * sweep ran after the preflight — or not at all ahead of it — the thrown
	 * RuntimeException would leave the orphan in place; because it survives
	 * neither, this proves the sweep already ran by the time the preflight
	 * had its chance to refuse.
	 *
	 * @return void
	 */
	public function test_sweep_runs_before_the_free_space_preflight_refuses(): void {
		$store = new RollbackStore( $this->base );
		$store->ensure_directory();

		$orphan = $store->directory() . '/pre-import-rollback-20260101T000000Z.wpmig' . TempArtefact::suffix();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture setup.
		file_put_contents( $orphan, 'an abandoned safety-archive write' );

		$plans = array( $this->file_plan( 'wp-content/note.txt', "content\n" ) );

		$archiver = new SafetyArchiver(
			$this->environment_with_free_space( 1.0 ),
			$this->wordpress_context_mock(),
			$store,
			$this->manifest_builder_returning( $plans )
		);

		try {
			$archiver->create( '/var/www/html' );
			$this->fail( 'create() should have refused: free space is below the estimate.' );
		} catch ( RuntimeException $error ) {
			$this->assertStringContainsString( 'Not enough free disk space', $error->getMessage() );
		}

		$this->assertFileDoesNotExist(
			$orphan,
			'The sweep must run before the free-space preflight refuses, so the orphan is gone even though create() threw.'
		);
	}

	// -------------------------------------------------------------------------
	// Sweep-test helpers.
	// -------------------------------------------------------------------------

	/**
	 * A SafetyArchiver wired against a real store, for driving the private
	 * sweep method directly (never through create()).
	 *
	 * No manifest builder is passed: {@see SafetyArchiver::sweep_orphaned_archive_temps()}
	 * never touches it, and {@see self::manifest_builder_returning()}'s mock
	 * expects exactly one build() call, which a test that never calls
	 * create() would leave unmet.
	 *
	 * @param RollbackStore $store The store the archiver is rooted at.
	 * @return SafetyArchiver
	 */
	private function archiver_for_sweep_tests( RollbackStore $store ): SafetyArchiver {
		return new SafetyArchiver(
			$this->environment_with_free_space( (float) ( 1024 * 1024 * 1024 ) ),
			$this->wordpress_context_mock(),
			$store
		);
	}

	/**
	 * Invoke the private sweep_orphaned_archive_temps() method by reflection.
	 *
	 * @param SafetyArchiver $archiver The archiver to invoke it on.
	 * @return int The number of temp artefacts actually removed.
	 */
	private function invoke_sweep( SafetyArchiver $archiver ): int {
		$method = new ReflectionMethod( SafetyArchiver::class, 'sweep_orphaned_archive_temps' );
		return (int) $method->invoke( $archiver );
	}

	// -------------------------------------------------------------------------
	// Collaborator builders.
	// -------------------------------------------------------------------------

	/**
	 * An Environment mock reporting the given free space and a fixed PHP version.
	 *
	 * @param float $free_space Bytes the disk_free_space seam should report.
	 * @return Environment&\Mockery\MockInterface
	 */
	private function environment_with_free_space( float $free_space ) {
		$environment = Mockery::mock( Environment::class );
		$environment->shouldReceive( 'disk_free_space' )->andReturn( $free_space );
		$environment->shouldReceive( 'php_version' )->andReturn( '8.2.0' );
		$environment->shouldReceive( 'is_constant_defined' )->with( 'PONTIFEX_VERSION' )->andReturn( false );
		return $environment;
	}

	/**
	 * A WordPressContext mock supplying the provenance facts only.
	 *
	 * The wpdb_instance() seam is never called, because a manifest builder is injected.
	 *
	 * @return WordPressContext&\Mockery\MockInterface
	 */
	private function wordpress_context_mock() {
		$context = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'wp_version' )->andReturn( '6.6.0' );
		$context->shouldReceive( 'site_url' )->andReturn( 'https://example.test' );
		$context->shouldReceive( 'wpdb_charset' )->andReturn( 'utf8mb4' );
		$context->shouldReceive( 'wpdb_collation' )->andReturn( 'utf8mb4_unicode_520_ci' );
		$context->shouldReceive( 'wpdb_prefix' )->andReturn( 'wp_' );
		return $context;
	}

	/**
	 * A ManifestBuilder mock whose build() returns the given plans.
	 *
	 * @param array<int, EntryPlan> $plans The entry plans to return.
	 * @return ManifestBuilderInterface&\Mockery\MockInterface
	 */
	private function manifest_builder_returning( array $plans ) {
		$builder = Mockery::mock( ManifestBuilderInterface::class );
		$builder->shouldReceive( 'build' )->once()->andReturn( \Pontifex\Manifest\ManifestStream::from_plans( $plans ) );
		return $builder;
	}

	// -------------------------------------------------------------------------
	// Archive helpers.
	// -------------------------------------------------------------------------

	/**
	 * Build a file EntryPlan with the given path and contents.
	 *
	 * @param string $path     Relative path inside the archive.
	 * @param string $contents File contents.
	 * @return EntryPlan
	 */
	private function file_plan( string $path, string $contents ): EntryPlan {
		$header = EntryHeader::for_file( $path, strlen( $contents ), 0o644, 1690000000, 'application/octet-stream', 0 );
		return new EntryPlan( $header, 0, str_repeat( "\0", EntryWriter::NONCE_SIZE ), $this->memory_stream( $contents ) );
	}

	/**
	 * Open a php://memory stream seeded with the given bytes.
	 *
	 * @param string $contents Initial contents.
	 * @return resource
	 */
	private function memory_stream( string $contents ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://memory is an in-process buffer, not a file.
		$stream = fopen( 'php://memory', 'r+b' );
		if ( false === $stream ) {
			$this->fail( 'Could not open php://memory.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Operating on a test stream resource, not a filesystem path.
		fwrite( $stream, $contents );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource, not a filesystem path.
		rewind( $stream );
		return $stream;
	}

	/**
	 * Open a written archive and return how many entries its manifest declares.
	 *
	 * @param string $path Absolute path to the archive.
	 * @return int
	 */
	private function entry_count( string $path ): int {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Opening the just-written archive to read its manifest back.
		$source = fopen( $path, 'rb' );
		if ( false === $source ) {
			$this->fail( 'Could not open the written archive.' );
		}
		try {
			$reader = new ArchiveReader( $source );
			return $reader->manifest()->entry_count();
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the archive stream opened in this helper.
			fclose( $source );
		}
	}

	/**
	 * Open a written archive and return its parsed provenance block.
	 *
	 * @param string $path Absolute path to the archive.
	 * @return Provenance
	 */
	private function read_provenance( string $path ): Provenance {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Opening the just-written archive to read its provenance back.
		$source = fopen( $path, 'rb' );
		if ( false === $source ) {
			$this->fail( 'Could not open the written archive.' );
		}
		try {
			return ( new ArchiveReader( $source ) )->provenance();
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the archive stream opened in this helper.
			fclose( $source );
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
