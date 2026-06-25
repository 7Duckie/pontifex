<?php
/**
 * Tests for SafetyArchiver — taking a pre-import safety archive.
 *
 * @package Pontifex\Tests\Unit\Rollback
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Rollback;

use Mockery;
use RuntimeException;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Reader\ArchiveReader;
use Pontifex\Archive\Writer\EntryPlan;
use Pontifex\Archive\Writer\EntryWriter;
use Pontifex\Environment\Environment;
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
	 * A created safety archive is well-formed and owner-only; older ones are pruned.
	 *
	 * @return void
	 */
	public function test_create_writes_a_restorable_archive_and_prunes_older(): void {
		$store = new RollbackStore( $this->base );
		$store->ensure_directory();

		// An older safety archive that retention (N=1) must prune.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Seeding an older fixture archive in a temp directory.
		touch( $store->directory() . '/pre-import-rollback-20200101T000000Z.wpmig' );

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

		// The returned archive exists, is owner-only, and is the only one left.
		$this->assertFileExists( $path );
		$this->assertSame( 0600, fileperms( $path ) & 0777, 'A safety archive must be owner read/write only.' );
		$this->assertCount( 1, $store->archives(), 'Retention N=1 should prune the older archive.' );
		$this->assertSame( $path, $store->most_recent() );

		// The archive is well-formed: it reads back with the two entries.
		$this->assertSame( 2, $this->entry_count( $path ), 'The written archive should contain both entries.' );
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
			$this->assertStringContainsString( 'not enough free disk space', $error->getMessage() );
		}

		$this->assertSame( array(), $store->archives(), 'No archive may be written when the preflight refuses.' );
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
