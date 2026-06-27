<?php
/**
 * Tests for RestoreController — the admin-ajax endpoints behind the Restore screen.
 *
 * @package Pontifex\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use DateTimeImmutable;
use DateTimeZone;
use Mockery;
use Pontifex\Admin\BackupStore;
use Pontifex\Admin\RestoreController;
use Pontifex\Archive\Codec\CodecRegistry;
use Pontifex\Archive\Format\ExporterInfo;
use Pontifex\Archive\Format\Provenance;
use Pontifex\Archive\Format\Scope;
use Pontifex\Archive\Writer\ArchiveWriter;
use Pontifex\Archive\Writer\EntryWriter;
use Pontifex\Archive\Writer\FooterWriter;
use Pontifex\Environment\Environment;
use Pontifex\Restore\RestoreRunnerInterface;
use Pontifex\Rollback\RollbackStoreInterface;
use Pontifex\Rollback\SafetyArchiverInterface;
use Pontifex\Tests\TestCase;
use Pontifex\WordPress\WordPressContext;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Covers the controller's authorisation gates, the restore and rollback flows,
 * and the safety invariant that a broken backup is never written.
 *
 * The restore engine and the safety archiver are injected doubles, so the
 * handler is exercised without ever writing to the filesystem or the database —
 * the real wiring is covered by the dev-site browser test and the CLI round-trip,
 * matching how the Backup and Verify screens' default engines are covered. The
 * controller's own encryption pre-check still runs against a real (empty) plain
 * archive on disk. WordPress functions are stubbed with brain/monkey:
 * wp_send_json_error and wp_die throw, so a refused request halts as in
 * production; wp_send_json_success captures its payload.
 */
final class RestoreControllerTest extends TestCase {

	/**
	 * Temporary content directory the store is rooted at for one test.
	 *
	 * @var string
	 */
	private string $base = '';

	/**
	 * The most recent JSON response captured from the stubbed responders.
	 *
	 * @var array<string, mixed>
	 */
	private array $json = array();

	/**
	 * Reserve a unique temp content directory and reset the capture.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->base = sys_get_temp_dir() . '/pontifex-restore-controller-' . uniqid( '', true );
		$this->json = array();
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
	 * Refuses to restore without the managing capability.
	 *
	 * @return void
	 */
	public function test_restore_refuses_without_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->stub_json();

		try {
			$this->controller()->restore();
			$this->fail( 'restore() should refuse without the capability.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( 'pontifex-json-halt', $error->getMessage() );
		}

		$this->assertFalse( $this->json['success'] );
		$this->assertSame( 403, $this->json['status'] );
	}

	/**
	 * Refuses a filename that does not resolve to a real backup.
	 *
	 * @return void
	 */
	public function test_restore_refuses_an_unresolved_file(): void {
		$this->authorise();
		$this->stub_json();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_file_name' )->returnArg();
		$_POST['file'] = '../secret.txt';

		try {
			$this->controller()->restore();
			$this->fail( 'restore() should refuse a name that does not resolve.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( 'pontifex-json-halt', $error->getMessage() );
		} finally {
			unset( $_POST['file'] );
		}

		$this->assertFalse( $this->json['success'] );
		$this->assertSame( 404, $this->json['status'] );
	}

	/**
	 * Restores a sound backup: verifies, takes the safety archive, then restores.
	 *
	 * @return void
	 */
	public function test_restore_reports_a_successful_restore(): void {
		$this->authorise();
		$this->stub_json();
		$this->stub_transients();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_file_name' )->returnArg();

		$store = new BackupStore( $this->base );
		$store->ensure_directory();
		$name = 'pontifex-backup-20260101T000000Z.wpmig';
		$this->write_plain_archive( $store->directory() . '/' . $name );
		$_POST['file'] = $name;

		$runner = Mockery::mock( RestoreRunnerInterface::class );
		$runner->shouldReceive( 'verify' )->once();
		$runner->shouldReceive( 'restore' )->once()->andReturnUsing(
			static function ( $source, ?callable $on_entry, ?callable $on_bytes ): void {
				unset( $source, $on_bytes );
				if ( null !== $on_entry ) {
					$on_entry( 3, 3 );
				}
			}
		);
		$archiver = $this->safety_archiver_double();

		try {
			$this->controller( $runner, $archiver )->restore();
		} finally {
			unset( $_POST['file'] );
		}

		$this->assertTrue( $this->json['success'] );
		$this->assertTrue( $this->json['data']['restored'] );
		$this->assertSame( 3, $this->json['data']['entries'] );
	}

	/**
	 * A successful restore flushes the stale option cache BEFORE recording, and
	 * records the attempt as well as the success.
	 *
	 * The restore replays the database with raw SQL, leaving WordPress's option
	 * cache holding pre-restore values; without a flush the post-restore counter
	 * write reads and writes stale state and is silently lost. This pins the
	 * flush-before-record ordering and that `attempted` is re-applied (it was
	 * wiped along with the replaced wp_options).
	 *
	 * @return void
	 */
	public function test_successful_restore_flushes_cache_before_recording_counters(): void {
		$this->authorise();
		$this->stub_json();
		$this->stub_transients();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_file_name' )->returnArg();

		$store = new BackupStore( $this->base );
		$store->ensure_directory();
		$name = 'pontifex-backup-20260101T000000Z.wpmig';
		$this->write_plain_archive( $store->directory() . '/' . $name );
		$_POST['file'] = $name;

		$runner = Mockery::mock( RestoreRunnerInterface::class );
		$runner->shouldReceive( 'verify' )->once();
		$runner->shouldReceive( 'restore' )->once()->andReturnUsing(
			static function ( $source, ?callable $on_entry, ?callable $on_bytes ): void {
				unset( $source, $on_bytes );
				if ( null !== $on_entry ) {
					$on_entry( 1, 1 );
				}
			}
		);

		$flushed       = false;
		$flushed_first = null;
		$stats         = null;
		$context       = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'format_size' )->andReturnUsing(
			static function ( int $bytes ): string {
				return $bytes . ' B';
			}
		);
		$context->shouldReceive( 'option_value' )->andReturnUsing(
			static function ( string $name, $fallback = false ) {
				unset( $name );
				return $fallback;
			}
		);
		$context->shouldReceive( 'flush_cache' )->once()->andReturnUsing(
			static function () use ( &$flushed ): void {
				$flushed = true;
			}
		);
		$context->shouldReceive( 'save_option' )->andReturnUsing(
			static function ( string $name, $value ) use ( &$flushed, &$flushed_first, &$stats ): void {
				if ( 'pontifex_import_stats' === $name ) {
					$flushed_first = $flushed;
					$stats         = $value;
				}
			}
		);

		$controller = new RestoreController(
			$this->environment(),
			$context,
			new BackupStore( $this->base ),
			Mockery::mock( RollbackStoreInterface::class ),
			new NullLogger(),
			$runner,
			$this->safety_archiver_double()
		);

		try {
			$controller->restore();
		} finally {
			unset( $_POST['file'] );
		}

		$this->assertTrue( $flushed, 'The cache must be flushed after the replay.' );
		$this->assertTrue( $flushed_first, 'Counters must be written AFTER the cache flush, not before.' );
		$this->assertIsArray( $stats );
		$this->assertSame( 1, $stats['attempted'], 'A successful restore must record the attempt.' );
		$this->assertSame( 1, $stats['succeeded'] );
		$this->assertSame( 0, $stats['failed'] );
		$this->assertArrayHasKey( 'bytes_imported', $stats );
	}

	/**
	 * Refuses a broken backup and writes nothing: no safety archive, no restore.
	 *
	 * The preview gate's whole purpose — a backup that fails verification must be
	 * refused before the safety archive or any write.
	 *
	 * @return void
	 */
	public function test_restore_refuses_a_broken_backup_without_writing(): void {
		$this->authorise();
		$this->stub_json();
		$this->stub_transients();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_file_name' )->returnArg();

		$store = new BackupStore( $this->base );
		$store->ensure_directory();
		$name = 'pontifex-backup-20260101T000000Z.wpmig';
		$this->write_plain_archive( $store->directory() . '/' . $name );
		$_POST['file'] = $name;

		$runner = Mockery::mock( RestoreRunnerInterface::class );
		$runner->shouldReceive( 'verify' )->once()->andThrow( new RuntimeException( 'entry 2 hash mismatch' ) );
		$runner->shouldReceive( 'restore' )->never();

		// The safety archive must never be taken for a broken backup.
		$archiver = Mockery::mock( SafetyArchiverInterface::class );
		$archiver->shouldReceive( 'create' )->never();

		try {
			$this->controller( $runner, $archiver )->restore();
		} finally {
			unset( $_POST['file'] );
		}

		$this->assertTrue( $this->json['success'], 'A broken verdict is reported as a JSON success.' );
		$this->assertFalse( $this->json['data']['restored'] );
	}

	/**
	 * Refuses a whole-site backup: the admin restore is content-only.
	 *
	 * A whole-site backup (one that includes WordPress core and wp-config.php) has no
	 * --whole-site path in the admin, so it is refused before any write — neither
	 * verified, backed up, nor restored.
	 *
	 * @return void
	 */
	public function test_restore_refuses_a_whole_site_backup(): void {
		$this->authorise();
		$this->stub_json();
		$this->stub_transients();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_file_name' )->returnArg();

		$store = new BackupStore( $this->base );
		$store->ensure_directory();
		$name = 'pontifex-backup-20260101T000000Z.wpmig';
		$this->write_whole_site_archive( $store->directory() . '/' . $name );
		$_POST['file'] = $name;

		$runner = Mockery::mock( RestoreRunnerInterface::class );
		$runner->shouldReceive( 'verify' )->never();
		$runner->shouldReceive( 'restore' )->never();

		$archiver = Mockery::mock( SafetyArchiverInterface::class );
		$archiver->shouldReceive( 'create' )->never();

		try {
			$this->controller( $runner, $archiver )->restore();
			$this->fail( 'restore() should refuse a whole-site backup.' );
		} catch ( RuntimeException $halt ) {
			$this->assertSame( 'pontifex-json-halt', $halt->getMessage() );
		} finally {
			unset( $_POST['file'] );
		}

		$this->assertFalse( $this->json['success'], 'A whole-site backup is refused, not restored.' );
	}

	/**
	 * Refuses a legacy (no-scope) backup the same way as a whole-site one.
	 *
	 * A backup that records no scope predates the content-only format and is treated
	 * as whole-site, so it is refused before any write.
	 *
	 * @return void
	 */
	public function test_restore_refuses_a_legacy_backup(): void {
		$this->authorise();
		$this->stub_json();
		$this->stub_transients();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_file_name' )->returnArg();

		$store = new BackupStore( $this->base );
		$store->ensure_directory();
		$name = 'pontifex-backup-20260101T000000Z.wpmig';
		$this->write_legacy_archive( $store->directory() . '/' . $name );
		$_POST['file'] = $name;

		$runner = Mockery::mock( RestoreRunnerInterface::class );
		$runner->shouldReceive( 'restore' )->never();

		$archiver = Mockery::mock( SafetyArchiverInterface::class );
		$archiver->shouldReceive( 'create' )->never();

		try {
			$this->controller( $runner, $archiver )->restore();
			$this->fail( 'restore() should refuse a legacy backup.' );
		} catch ( RuntimeException $halt ) {
			$this->assertSame( 'pontifex-json-halt', $halt->getMessage() );
		} finally {
			unset( $_POST['file'] );
		}

		$this->assertFalse( $this->json['success'], 'A legacy backup is refused, not restored.' );
	}

	/**
	 * Refuses a second restore while one is already running.
	 *
	 * @return void
	 */
	public function test_restore_refuses_when_a_restore_is_already_running(): void {
		$this->authorise();
		$this->stub_json();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_file_name' )->returnArg();
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'get_transient' )->alias(
			static function ( string $key ) {
				return 'pontifex_restore_lock' === $key ? time() : false;
			}
		);

		$store = new BackupStore( $this->base );
		$store->ensure_directory();
		$name = 'pontifex-backup-20260101T000000Z.wpmig';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Seeding a placeholder backup; the lock is checked before it is opened.
		file_put_contents( $store->directory() . '/' . $name, 'x' );
		$_POST['file'] = $name;

		try {
			$this->controller()->restore();
			$this->fail( 'restore() should refuse while a restore is already running.' );
		} catch ( RuntimeException $halt ) {
			$this->assertSame( 'pontifex-json-halt', $halt->getMessage() );
		} finally {
			unset( $_POST['file'] );
		}

		$this->assertFalse( $this->json['success'] );
		$this->assertSame( 409, $this->json['status'] );
	}

	/**
	 * Reports byte progress against the archive size as the restore reads.
	 *
	 * @return void
	 */
	public function test_restore_reports_byte_progress(): void {
		$this->authorise();
		$this->stub_json();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_file_name' )->returnArg();

		$writes = array();
		Functions\when( 'set_transient' )->alias(
			static function ( string $key, $value ) use ( &$writes ): bool {
				if ( 'pontifex_restore_progress' === $key ) {
					$writes[] = $value;
				}
				return true;
			}
		);
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'delete_transient' )->justReturn( true );

		$store = new BackupStore( $this->base );
		$store->ensure_directory();
		$name = 'pontifex-backup-20260101T000000Z.wpmig';
		$this->write_plain_archive( $store->directory() . '/' . $name );
		$_POST['file'] = $name;

		$runner = Mockery::mock( RestoreRunnerInterface::class );
		$runner->shouldReceive( 'verify' )->once();
		$runner->shouldReceive( 'restore' )->once()->andReturnUsing(
			static function ( $source, ?callable $on_entry, ?callable $on_bytes ): void {
				unset( $source );
				if ( null !== $on_entry ) {
					$on_entry( 1, 1 );
				}
				if ( null !== $on_bytes ) {
					$on_bytes( 500 );
				}
			}
		);

		try {
			$this->controller( $runner, $this->safety_archiver_double() )->restore();
		} finally {
			unset( $_POST['file'] );
		}

		$restoring = array_values(
			array_filter(
				$writes,
				static function ( $write ): bool {
					return is_array( $write ) && isset( $write['phase'] ) && 'restoring' === $write['phase'];
				}
			)
		);

		$this->assertNotEmpty( $restoring, 'The restore phase must write byte progress.' );
		$max_done = 0;
		foreach ( $restoring as $write ) {
			$max_done = max( $max_done, (int) $write['bytes_done'] );
		}
		$this->assertGreaterThan( 0, $max_done, 'The byte callback must report archive bytes read during the restore.' );
	}

	/**
	 * Rolls back the most recent safety archive after verifying it.
	 *
	 * @return void
	 */
	public function test_rollback_reports_a_successful_rollback(): void {
		$this->authorise();
		$this->stub_json();
		$this->stub_transients();

		$archive_path = $this->base . '/pre-import-rollback-20260101T000000Z.wpmig';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Seeding a temp directory for the placeholder safety archive.
		mkdir( $this->base, 0o755, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Seeding a placeholder safety archive; the injected engine stands in for reading it.
		file_put_contents( $archive_path, 'x' );

		$rollback = Mockery::mock( RollbackStoreInterface::class );
		$rollback->shouldReceive( 'most_recent' )->once()->andReturn( $archive_path );

		$runner = Mockery::mock( RestoreRunnerInterface::class );
		$runner->shouldReceive( 'verify' )->once();
		$runner->shouldReceive( 'restore' )->once()->andReturnUsing(
			static function ( $source, ?callable $on_entry, ?callable $on_bytes ): void {
				unset( $source, $on_bytes );
				if ( null !== $on_entry ) {
					$on_entry( 2, 2 );
				}
			}
		);

		$this->controller( $runner, null, $rollback )->rollback();

		$this->assertTrue( $this->json['success'] );
		$this->assertTrue( $this->json['data']['rolled_back'] );
		$this->assertSame( 2, $this->json['data']['entries'] );
	}

	/**
	 * Refuses to roll back when there is no safety archive.
	 *
	 * @return void
	 */
	public function test_rollback_refuses_when_there_is_no_safety_archive(): void {
		$this->authorise();
		$this->stub_json();

		$rollback = Mockery::mock( RollbackStoreInterface::class );
		$rollback->shouldReceive( 'most_recent' )->once()->andReturn( null );

		try {
			$this->controller( null, null, $rollback )->rollback();
			$this->fail( 'rollback() should refuse when there is nothing to roll back to.' );
		} catch ( RuntimeException $halt ) {
			$this->assertSame( 'pontifex-json-halt', $halt->getMessage() );
		}

		$this->assertFalse( $this->json['success'] );
		$this->assertSame( 404, $this->json['status'] );
	}

	// -------------------------------------------------------------------------
	// Collaborator builders and stubs.
	// -------------------------------------------------------------------------

	/**
	 * Build a controller around mocks and a real store on the temp dir.
	 *
	 * The restore runner and safety archiver are injected so no write ever
	 * reaches the filesystem or database. The Environment reports ABSPATH for the
	 * safety archiver's root; the WordPressContext formats sizes and reads/writes
	 * the counters and transfer history through option_value/save_option.
	 *
	 * @param RestoreRunnerInterface|null  $runner   Optional injected engine.
	 * @param SafetyArchiverInterface|null $archiver Optional injected archiver.
	 * @param RollbackStoreInterface|null  $rollback Optional injected rollback store.
	 * @param LoggerInterface|null         $logger   Optional injected logger; a NullLogger by default.
	 * @return RestoreController
	 */
	private function controller(
		?RestoreRunnerInterface $runner = null,
		?SafetyArchiverInterface $archiver = null,
		?RollbackStoreInterface $rollback = null,
		?LoggerInterface $logger = null
	): RestoreController {
		return new RestoreController(
			$this->environment(),
			$this->context(),
			new BackupStore( $this->base ),
			$rollback ?? Mockery::mock( RollbackStoreInterface::class ),
			$logger ?? new NullLogger(),
			$runner,
			$archiver
		);
	}

	/**
	 * An Environment mock reporting a WordPress root for the safety archiver.
	 *
	 * @return Environment&\Mockery\MockInterface
	 */
	private function environment() {
		$environment = Mockery::mock( Environment::class );
		$environment->shouldReceive( 'is_constant_defined' )->andReturn( true );
		$environment->shouldReceive( 'constant_value' )->andReturn( '/var/www/html' );
		return $environment;
	}

	/**
	 * A WordPressContext mock that formats sizes and stores counters/history.
	 *
	 * @return WordPressContext&\Mockery\MockInterface
	 */
	private function context() {
		$context = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'format_size' )->andReturnUsing(
			static function ( int $bytes ): string {
				return $bytes . ' B';
			}
		);
		$context->shouldReceive( 'option_value' )->andReturnUsing(
			static function ( string $name, $fallback = false ) {
				unset( $name );
				return $fallback;
			}
		);
		$context->shouldReceive( 'save_option' );
		$context->shouldReceive( 'flush_cache' );
		return $context;
	}

	/**
	 * A SafetyArchiver double whose create() reports progress and returns a path.
	 *
	 * @return SafetyArchiverInterface&\Mockery\MockInterface
	 */
	private function safety_archiver_double() {
		$archiver = Mockery::mock( SafetyArchiverInterface::class );
		$archiver->shouldReceive( 'create' )->once()->andReturnUsing(
			static function ( string $root, ?callable $on_entry, ?callable $on_bytes ): string {
				unset( $root, $on_entry );
				if ( null !== $on_bytes ) {
					$on_bytes( 500 );
				}
				return '/tmp/pontifex-safety.wpmig';
			}
		);
		return $archiver;
	}

	/**
	 * Stub the capability and nonce checks to pass.
	 *
	 * @return void
	 */
	private function authorise(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_ajax_referer' )->justReturn( 1 );
	}

	/**
	 * Stub the JSON responders: success captures, error captures and halts.
	 *
	 * @return void
	 */
	private function stub_json(): void {
		Functions\when( 'wp_send_json_success' )->alias(
			function ( $data = null ): void {
				$this->json = array(
					'success' => true,
					'data'    => $data,
				);
			}
		);
		Functions\when( 'wp_send_json_error' )->alias(
			function ( $data = null, $status = null ): void {
				$this->json = array(
					'success' => false,
					'data'    => $data,
					'status'  => $status,
				);
				throw new RuntimeException( 'pontifex-json-halt' );
			}
		);
	}

	/**
	 * Stub the transient functions used to report progress and hold the lock.
	 *
	 * @return void
	 */
	private function stub_transients(): void {
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'delete_transient' )->justReturn( true );
	}

	// -------------------------------------------------------------------------
	// Archive fixtures.
	// -------------------------------------------------------------------------

	/**
	 * Write a valid, empty, unencrypted archive to the given path.
	 *
	 * Enough for the controller's encryption pre-check to read a plain header; the
	 * injected engine stands in for the verify and restore walks.
	 *
	 * @param string $path Absolute path to write the archive to.
	 * @return void
	 */
	private function write_plain_archive( string $path ): void {
		$this->write_archive_with_scope( $path, Scope::content_only( array() ) );
	}

	/**
	 * Write a valid, empty, unencrypted whole-site archive to the given path.
	 *
	 * @param string $path Absolute path to write the archive to.
	 * @return void
	 */
	private function write_whole_site_archive( string $path ): void {
		$this->write_archive_with_scope( $path, Scope::whole_site( array() ) );
	}

	/**
	 * Write a valid, empty, unencrypted legacy (no-scope) archive to the given path.
	 *
	 * @param string $path Absolute path to write the archive to.
	 * @return void
	 */
	private function write_legacy_archive( string $path ): void {
		$this->write_archive_with_scope( $path, null );
	}

	/**
	 * Write a valid, empty, unencrypted archive recording the given scope.
	 *
	 * @param string     $path  Absolute path to write the archive to.
	 * @param Scope|null $scope The scope to record, or null for a legacy archive.
	 * @return void
	 */
	private function write_archive_with_scope( string $path, ?Scope $scope ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Opening a temp fixture archive for writing.
		$dest = fopen( $path, 'w+b' );
		if ( false === $dest ) {
			$this->fail( 'Could not open the fixture archive for writing.' );
		}
		( new ArchiveWriter( new EntryWriter( CodecRegistry::with_defaults() ), new FooterWriter() ) )
			->write_archive( $this->sample_provenance( $scope ), array(), $dest );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the temp fixture archive.
		fclose( $dest );
	}

	/**
	 * Build a Provenance with realistic but arbitrary field values for the fixture.
	 *
	 * @param Scope|null $scope The scope to record, or null for a legacy archive.
	 * @return Provenance
	 */
	private function sample_provenance( ?Scope $scope ): Provenance {
		return new Provenance(
			'6.6.1',
			'8.2.10',
			'https://example.test',
			'utf8mb4',
			'utf8mb4_unicode_520_ci',
			new ExporterInfo( 'pontifex', '0.1.0' ),
			new DateTimeImmutable( '2026-05-23T10:00:00+00:00', new DateTimeZone( 'UTC' ) ),
			null,
			'wp_',
			$scope
		);
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
