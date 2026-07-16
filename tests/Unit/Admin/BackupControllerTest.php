<?php
/**
 * Tests for BackupController — the admin-ajax endpoints behind the Backup screen.
 *
 * @package Pontifex\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use Mockery;
use Pontifex\Admin\BackupController;
use Pontifex\Admin\BackupStore;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Reader\ArchiveReader;
use Pontifex\Archive\Writer\EntryPlan;
use Pontifex\Archive\Writer\EntryWriter;
use Pontifex\Environment\Environment;
use Pontifex\Job\Job;
use Pontifex\Job\JobStore;
use Pontifex\Lock\OperationLock;
use Pontifex\Manifest\ManifestBuilderInterface;
use Pontifex\Manifest\ManifestStream;
use Pontifex\Tests\TestCase;
use Pontifex\WordPress\WordPressContext;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Covers the controller's authorisation gates and the create/download/delete flows.
 *
 * The export itself runs for real against hand-built in-memory entries (the
 * approach SafetyArchiverTest uses) into a temporary backups directory, so the
 * create flow is exercised end-to-end without scanning a real installation. The
 * WordPress functions the handlers call are stubbed with brain/monkey:
 * wp_send_json_error and wp_die throw, so a refused request halts the way it does
 * in production; wp_send_json_success captures its payload without halting.
 */
final class BackupControllerTest extends TestCase {

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
		$this->base = sys_get_temp_dir() . '/pontifex-backup-controller-' . uniqid( '', true );
		$this->json = array();
		\Brain\Monkey\Functions\when( 'wp_schedule_single_event' )->justReturn( true );
		\Brain\Monkey\Functions\when( 'wp_clear_scheduled_hook' )->justReturn( 0 );
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
	 * Refuses to create a backup without the managing capability.
	 *
	 * @return void
	 */
	public function test_create_refuses_without_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->stub_json();

		try {
			$this->controller( $this->manifest_builder_returning( array() ) )->create();
			$this->fail( 'create() should refuse without the capability.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( 'pontifex-json-halt', $error->getMessage() );
		}

		$this->assertFalse( $this->json['success'] );
		$this->assertSame( 403, $this->json['status'] );
		$this->assertSame( array(), ( new BackupStore( $this->base ) )->backups(), 'No backup may be written when refused.' );
	}

	/**
	 * Writes a backup from the entry list and reports success with its facts.
	 *
	 * @return void
	 */
	public function test_create_writes_a_backup_and_reports_success(): void {
		$this->authorise();
		$this->stub_json();
		$this->stub_transients();

		$plans = array(
			$this->file_plan( 'index.php', "<?php\n// fixture\n" ),
			$this->file_plan( 'wp-content/note.txt', "café ☕\n" ),
		);

		$this->controller( $this->manifest_builder_returning( $plans ) )->create();

		$this->assertTrue( $this->json['success'] );
		$this->assertSame( 2, $this->json['data']['entries'] );
		$this->assertGreaterThan( 0, $this->json['data']['bytes'] );
		$this->assertArrayHasKey( 'source_bytes', $this->json['data'], 'The success response carries the source byte total for the result message.' );

		$backups = ( new BackupStore( $this->base ) )->backups();
		$this->assertCount( 1, $backups, 'Exactly one backup file should have been written.' );
		$this->assertStringStartsWith( 'pontifex-backup-', basename( $backups[0] ) );
		$this->assertSame( 0600, fileperms( $backups[0] ) & 0777, 'A backup must be owner read/write only.' );
	}

	/**
	 * An admin backup records a content-only scope and the source table prefix.
	 *
	 * The admin Backup screen is always content-only (ADR 0008): a whole-site clone
	 * stays a CLI-only operation. The written archive's provenance must reflect that,
	 * and carry the source table prefix the destination restore needs.
	 *
	 * @return void
	 */
	public function test_create_writes_a_content_only_scope(): void {
		$this->authorise();
		$this->stub_json();
		$this->stub_transients();

		$plans = array(
			$this->file_plan( 'wp-content/note.txt', "content\n" ),
		);

		$this->controller( $this->manifest_builder_returning( $plans ) )->create();

		$backups = ( new BackupStore( $this->base ) )->backups();
		$this->assertCount( 1, $backups, 'Exactly one backup file should have been written.' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Reading the just-written backup's provenance back in a unit test.
		$source = fopen( $backups[0], 'rb' );
		if ( false === $source ) {
			$this->fail( 'Could not open the written backup.' );
		}
		try {
			$provenance = ( new ArchiveReader( $source ) )->provenance();
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the archive stream opened above.
			fclose( $source );
		}

		$scope = $provenance->scope();
		$this->assertNotNull( $scope, 'An admin backup should record a scope.' );
		$this->assertTrue( $scope->is_content_only() );
		$this->assertSame( 'wp-content', $scope->content_root() );
		$this->assertSame( 'wp_', $provenance->table_prefix(), 'The source table prefix should be recorded.' );
	}

	/**
	 * The copy phase reports byte progress against the entries' total size.
	 *
	 * Captures the progress transient the running export writes and asserts a
	 * copying-phase entry carries the total source bytes as its denominator and a
	 * non-zero bytes-copied count — the byte callback the determinate bar rides on.
	 *
	 * @return void
	 */
	public function test_create_reports_byte_progress_in_the_copy_phase(): void {
		$this->authorise();
		$this->stub_json();

		$writes = array();
		Functions\when( 'set_transient' )->alias(
			static function ( string $key, $value ) use ( &$writes ): bool {
				if ( 'pontifex_backup_progress' === $key ) {
					$writes[] = $value;
				}
				return true;
			}
		);
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'delete_transient' )->justReturn( true );

		$alpha          = "alpha alpha alpha\n";
		$beta           = "beta\n";
		$expected_total = strlen( $alpha ) + strlen( $beta );
		$plans          = array(
			$this->file_plan( 'a.txt', $alpha ),
			$this->file_plan( 'b.txt', $beta ),
		);

		$this->controller( $this->manifest_builder_returning( $plans ) )->create();

		$copy = array_values(
			array_filter(
				$writes,
				static function ( $write ): bool {
					return is_array( $write ) && isset( $write['phase'] ) && 'copying' === $write['phase'];
				}
			)
		);

		$this->assertNotEmpty( $copy, 'The copy phase must write byte progress.' );
		$this->assertSame( $expected_total, (int) $copy[0]['bytes_total'], 'bytes_total must equal the entries\' source size.' );

		$max_done = 0;
		foreach ( $copy as $write ) {
			$max_done = max( $max_done, (int) $write['bytes_done'] );
		}
		$this->assertGreaterThan( 0, $max_done, 'The byte callback must report bytes copied during the export.' );
	}

	/**
	 * A failed backup is logged and leaves no partial archive behind.
	 *
	 * @return void
	 */
	public function test_create_logs_and_removes_partial_backup_on_failure(): void {
		$this->authorise();
		$this->stub_json();
		$this->stub_transients();

		$logger = Mockery::mock( LoggerInterface::class );
		$logger->shouldReceive( 'error' )->once();

		$builder = $this->manifest_builder_returning( array( $this->throwing_plan( 'index.php' ) ) );

		try {
			$this->controller( $builder, $logger )->create();
			$this->fail( 'create() should have halted via wp_send_json_error.' );
		} catch ( RuntimeException $halt ) {
			$this->assertSame( 'pontifex-json-halt', $halt->getMessage() );
		}

		$this->assertFalse( $this->json['success'] );
		$this->assertSame(
			array(),
			( new BackupStore( $this->base ) )->backups(),
			'A failed backup must leave no partial archive in the store.'
		);
	}

	/**
	 * The shutdown handler does nothing when no backup is in progress.
	 *
	 * Guards against it deleting a file or logging on an ordinary request's
	 * shutdown; it acts only when a fatal interrupted a running backup.
	 *
	 * @return void
	 */
	public function test_handle_shutdown_ignores_requests_with_no_active_backup(): void {
		$logger = Mockery::mock( LoggerInterface::class );
		$logger->shouldNotReceive( 'error' );

		$this->controller( null, $logger )->handle_shutdown();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * A second backup is refused while one is already running.
	 *
	 * The single-runner lock stops two concurrent exports fighting over the shared
	 * progress transient (the cause of the oscillating bar). The refused request
	 * returns 409 and writes nothing.
	 *
	 * @return void
	 */
	public function test_create_refuses_when_a_backup_is_already_running(): void {
		$this->authorise();
		$this->stub_json();
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		// The holder transient alone no longer proves a live run (a dead
		// holder is reclaimed — see OperationLock::is_reclaimable()); a
		// fresh, non-idle progress transient is the live signal (the R1
		// guard) that keeps this "already running", independent of whatever
		// the holder transient says.
		Functions\when( 'get_transient' )->alias(
			static function ( string $key ) {
				if ( OperationLock::LOCK_NAME === $key ) {
					return array(
						'kind' => OperationLock::OP_BACKUP,
						'at'   => time(),
					);
				}
				if ( 'pontifex_backup_progress' === $key ) {
					return array(
						'phase' => 'copying',
						'at'    => time(),
					);
				}
				return false;
			}
		);

		try {
			$this->controller()->create();
			$this->fail( 'create() should refuse while a backup is already running.' );
		} catch ( RuntimeException $halt ) {
			$this->assertSame( 'pontifex-json-halt', $halt->getMessage() );
		}

		$this->assertFalse( $this->json['success'] );
		$this->assertSame( 409, $this->json['status'] );
		$this->assertSame( array(), ( new BackupStore( $this->base ) )->backups(), 'A refused backup must write nothing.' );
	}

	/**
	 * A backup is refused while a job-backed export is active between cron ticks.
	 *
	 * A resumable export releases the database-level named lock between ticks but
	 * is still running; its lock transient persists and its job stays active. The
	 * active export job — not the transient — is the liveness signal that must
	 * keep a second backup out, so the reclaim path never tears down a live
	 * job-backed run.
	 *
	 * @return void
	 */
	public function test_create_refuses_while_a_job_backed_backup_is_active(): void {
		$this->authorise();
		$this->stub_json();
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		// The holder transient persists across ticks; the named lock is free
		// between them, so the active export job is what proves the run is
		// still live (the R1 guard) — checked before the holder transient is
		// even consulted.
		Functions\when( 'get_transient' )->alias(
			static function ( string $key ) {
				return OperationLock::LOCK_NAME === $key
					? array(
						'kind' => OperationLock::OP_BACKUP,
						'at'   => time(),
					)
					: false;
			}
		);

		$jobs = new JobStore( $this->base );
		$jobs->create( Job::KIND_EXPORT, array(), time() );

		try {
			$this->controller()->create();
			$this->fail( 'create() should refuse while a job-backed backup is active.' );
		} catch ( RuntimeException $halt ) {
			$this->assertSame( 'pontifex-json-halt', $halt->getMessage() );
		}

		$this->assertFalse( $this->json['success'] );
		$this->assertSame( 409, $this->json['status'] );
		$this->assertSame( array(), ( new BackupStore( $this->base ) )->backups(), 'A refused backup must write nothing.' );
	}

	/**
	 * A backup is refused when the named database lock is held elsewhere.
	 *
	 * The named lock is the primary single-runner guard: the database grants it
	 * to exactly one connection, atomically, so two simultaneous create()
	 * requests can never both pass — the check-then-set race the transient
	 * guard alone cannot close. A request that failed to acquire must not
	 * release the lock either, or it would free the running backup's lock.
	 *
	 * @return void
	 */
	public function test_create_refuses_when_the_named_lock_is_held_elsewhere(): void {
		$this->authorise();
		$this->stub_json();
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );

		$context = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'acquire_named_lock' )->once()->with( OperationLock::LOCK_NAME )->andReturn( false );
		$context->shouldNotReceive( 'release_named_lock' );

		$controller = new BackupController(
			$this->environment_mock(),
			$context,
			new BackupStore( $this->base ),
			new NullLogger()
		);

		try {
			$controller->create();
			$this->fail( 'create() should refuse while the named lock is held elsewhere.' );
		} catch ( RuntimeException $halt ) {
			$this->assertSame( 'pontifex-json-halt', $halt->getMessage() );
		}

		$this->assertFalse( $this->json['success'] );
		$this->assertSame( 409, $this->json['status'] );
		$this->assertSame( array(), ( new BackupStore( $this->base ) )->backups(), 'A refused backup must write nothing.' );
	}

	/**
	 * The named lock is handed back when a restore holds the shared lock.
	 *
	 * The holder transient is the secondary guard, checked only while the named
	 * lock is held. A restore holder is never reclaimed (unlike a dead backup's),
	 * so a backup attempt is refused here purely on the holder-transient check —
	 * with no active job and no live progress, the R1 guard has already passed —
	 * and the named lock just taken must be released again; under a persistent
	 * database connection it would otherwise linger and block every later run.
	 * This is the unified lock's cross-operation refusal: a restore running
	 * anywhere (admin or CLI) now keeps a new backup out too.
	 *
	 * @return void
	 */
	public function test_create_hands_back_the_named_lock_when_a_restore_holds_it(): void {
		$this->authorise();
		$this->stub_json();
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		// No active job and no live progress, so the R1 guard passes; the refusal
		// must come from the holder transient itself recording a restore.
		Functions\when( 'get_transient' )->alias(
			static function ( string $key ) {
				return OperationLock::LOCK_NAME === $key
					? array(
						'kind' => OperationLock::OP_RESTORE,
						'at'   => time(),
					)
					: false;
			}
		);

		$context = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'acquire_named_lock' )->once()->with( OperationLock::LOCK_NAME )->andReturn( true );
		$context->shouldReceive( 'release_named_lock' )->once()->with( OperationLock::LOCK_NAME );

		$controller = new BackupController(
			$this->environment_mock(),
			$context,
			new BackupStore( $this->base ),
			new NullLogger()
		);

		try {
			$controller->create();
			$this->fail( 'create() should refuse while a restore holds the shared lock.' );
		} catch ( RuntimeException $halt ) {
			$this->assertSame( 'pontifex-json-halt', $halt->getMessage() );
		}

		$this->assertFalse( $this->json['success'] );
		$this->assertSame( 409, $this->json['status'] );
	}

	/**
	 * Refuses a cancel request without the managing capability.
	 *
	 * @return void
	 */
	public function test_cancel_refuses_without_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->stub_json();

		try {
			$this->controller()->cancel();
			$this->fail( 'cancel() should refuse without the capability.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( 'pontifex-json-halt', $error->getMessage() );
		}

		$this->assertFalse( $this->json['success'] );
		$this->assertSame( 403, $this->json['status'] );
	}

	/**
	 * A cancel request writes the sentinel the running export polls for.
	 *
	 * @return void
	 */
	public function test_cancel_requests_a_stop_and_reports_success(): void {
		$this->authorise();
		$this->stub_json();

		$this->controller()->cancel();

		$this->assertTrue( $this->json['success'] );
		$this->assertTrue(
			( new BackupStore( $this->base ) )->is_cancel_requested(),
			'cancel() must write the sentinel the export polls for.'
		);
	}

	/**
	 * A cancel during the scan stops the backup and writes nothing.
	 *
	 * @return void
	 */
	public function test_create_honours_a_cancel_during_the_scan(): void {
		$this->authorise();
		$this->stub_json();
		$this->stub_transients();

		$store = new BackupStore( $this->base );
		$store->ensure_directory();

		$builder = Mockery::mock( ManifestBuilderInterface::class );
		$builder->shouldReceive( 'build' )->andReturnUsing(
			static function ( string $root, ?callable $on_scan ) use ( $store ) {
				$store->request_cancel();
				if ( null !== $on_scan ) {
					$on_scan( 1 );
				}
				return ManifestStream::from_plans( array() );
			}
		);

		$this->controller( $builder )->create();

		$this->assertTrue( $this->json['success'] );
		$this->assertArrayHasKey( 'cancelled', $this->json['data'], 'A cancelled backup must report cancelled.' );
		$this->assertTrue( $this->json['data']['cancelled'] );
		$this->assertSame( array(), $store->backups(), 'A cancel during the scan writes no backup.' );
		$this->assertFalse( $store->is_cancel_requested(), 'The sentinel must be cleared after a cancel.' );
	}

	/**
	 * A cancel during the copy stops the backup and removes the partial archive.
	 *
	 * @return void
	 */
	public function test_create_honours_a_cancel_during_the_copy(): void {
		$this->authorise();
		$this->stub_json();
		$this->stub_transients();

		$store = new BackupStore( $this->base );
		$store->ensure_directory();

		$plans = array(
			$this->cancelling_plan( 'a.txt', $store ),
			$this->file_plan( 'b.txt', "second entry\n" ),
		);

		$this->controller( $this->manifest_builder_returning( $plans ) )->create();

		$this->assertTrue( $this->json['success'] );
		$this->assertArrayHasKey( 'cancelled', $this->json['data'], 'A cancelled backup must report cancelled.' );
		$this->assertTrue( $this->json['data']['cancelled'] );
		$this->assertSame( array(), $store->backups(), 'A cancel during the copy must remove the partial archive.' );
		$this->assertFalse( $store->is_cancel_requested(), 'The sentinel must be cleared after a cancel.' );
	}

	/**
	 * A stale cancel sentinel is cleared at the start, so a fresh backup completes.
	 *
	 * @return void
	 */
	public function test_create_clears_a_stale_cancel_sentinel_and_completes(): void {
		$this->authorise();
		$this->stub_json();
		$this->stub_transients();

		$store = new BackupStore( $this->base );
		$store->ensure_directory();
		$store->request_cancel();

		$plans = array(
			$this->file_plan( 'index.php', "<?php\n" ),
			$this->file_plan( 'note.txt', "note\n" ),
		);

		$this->controller( $this->manifest_builder_returning( $plans ) )->create();

		$this->assertTrue( $this->json['success'] );
		$this->assertArrayNotHasKey( 'cancelled', $this->json['data'], 'A stale sentinel must not cancel a fresh backup.' );
		$this->assertCount( 1, $store->backups(), 'The completed backup must be written.' );
		$this->assertFalse( $store->is_cancel_requested(), 'The stale sentinel must be cleared.' );
	}

	/**
	 * Refuses a download without the managing capability.
	 *
	 * @return void
	 */
	public function test_download_refuses_without_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'check_ajax_referer' )->justReturn( 1 );
		$this->stub_die();

		$this->expectException( RuntimeException::class );
		$this->controller()->download();
	}

	/**
	 * Refuses a download whose filename does not resolve to a real backup.
	 *
	 * @return void
	 */
	public function test_download_refuses_an_unresolved_file(): void {
		$this->authorise();
		$this->stub_die();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_file_name' )->returnArg();
		$_GET['file'] = '../secret.txt';

		try {
			$this->controller()->download();
			$this->fail( 'download() should refuse a name that does not resolve.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( 'pontifex-die', $error->getMessage() );
		} finally {
			unset( $_GET['file'] );
		}
	}

	/**
	 * Removes a real backup the operator chose to delete.
	 *
	 * @return void
	 */
	public function test_delete_removes_a_real_backup(): void {
		$this->authorise();
		$this->stub_json();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_file_name' )->returnArg();

		$store = new BackupStore( $this->base );
		$store->ensure_directory();
		$name = 'pontifex-backup-20260101T000000Z.wpmig';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Seeding a fixture backup in a temp directory.
		file_put_contents( $store->directory() . '/' . $name, 'x' );
		$_POST['file'] = $name;

		try {
			$this->controller()->delete();
		} finally {
			unset( $_POST['file'] );
		}

		$this->assertTrue( $this->json['success'] );
		$this->assertFileDoesNotExist( $store->directory() . '/' . $name );
	}

	/**
	 * Refuses to delete a name that escapes the backups directory.
	 *
	 * @return void
	 */
	public function test_delete_refuses_a_traversal_name(): void {
		$this->authorise();
		$this->stub_json();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_file_name' )->returnArg();
		( new BackupStore( $this->base ) )->ensure_directory();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A real file outside the store, to prove the traversal cannot reach it.
		file_put_contents( $this->base . '/secret.txt', 'secret' );
		$_POST['file'] = '../secret.txt';

		try {
			$this->controller()->delete();
			$this->fail( 'delete() should refuse a traversal name.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( 'pontifex-json-halt', $error->getMessage() );
		} finally {
			unset( $_POST['file'] );
		}

		$this->assertFalse( $this->json['success'] );
		$this->assertFileExists( $this->base . '/secret.txt', 'The outside file must be untouched.' );
	}

	/**
	 * A files-only backup scans exactly once: the tick's own scan, no pre-scan.
	 *
	 * The browser gate measured a large backup paying the filesystem walk
	 * roughly nine times — one duplicate pre-scan for the progress total plus
	 * one per short tick. The total now comes from the first tick's scan via
	 * the job payload, so a second walk before the first byte is a regression.
	 *
	 * @return void
	 */
	public function test_create_scans_exactly_once_for_a_single_tick_backup(): void {
		$this->authorise();
		$this->stub_json();
		$this->stub_transients();

		$plans   = array(
			$this->file_plan( 'wp-content/a.txt', "alpha\n" ),
			$this->file_plan( 'wp-content/b.txt', "beta\n" ),
		);
		$builder = Mockery::mock( ManifestBuilderInterface::class );
		$builder->shouldReceive( 'build' )->once()->andReturn( ManifestStream::from_plans( $plans ) );

		$this->controller( $builder )->create();

		$this->assertTrue( $this->json['success'] );
		$this->assertGreaterThan( 0, $this->json['data']['source_bytes'], 'The source total must survive the pre-scan removal, fed from the tick\'s own scan.' );
	}

	// -------------------------------------------------------------------------
	// User exclusions.
	// -------------------------------------------------------------------------

	/**
	 * The operator's extra patterns are applied and recorded in the archive scope.
	 *
	 * The submitted patterns are appended to the curated defaults and travel into
	 * the content-only scope, so a destination reading the archive's provenance
	 * can see exactly what the operator left out.
	 *
	 * @return void
	 */
	public function test_create_applies_user_exclusions(): void {
		$this->authorise();
		$this->stub_json();
		$this->stub_transients();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();

		$_POST['exclusions'] = "custom-thing/**\n# a comment\nwp_myplugin_log";

		try {
			$this->controller( $this->manifest_builder_returning( array( $this->file_plan( 'wp-content/note.txt', "x\n" ) ) ) )->create();
		} finally {
			unset( $_POST['exclusions'] );
		}

		$this->assertTrue( $this->json['success'] );

		$backups = ( new BackupStore( $this->base ) )->backups();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Reading the just-written backup's provenance back in a unit test.
		$source = fopen( $backups[0], 'rb' );
		try {
			$scope = ( new ArchiveReader( $source ) )->provenance()->scope();
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the archive stream opened above.
			fclose( $source );
		}

		$this->assertNotNull( $scope );
		$excluded = $scope->excluded_paths();
		$this->assertContains( 'custom-thing/**', $excluded, 'The user file pattern is recorded in the scope.' );
		$this->assertContains( 'wp_myplugin_log', $excluded, 'The user table pattern is recorded in the scope.' );
		$this->assertContains( 'wp-content/pontifex/**', $excluded, 'The curated defaults still apply alongside the user patterns.' );
		$this->assertNotContains( '# a comment', $excluded, 'Comment lines are dropped, not treated as patterns.' );
	}

	/**
	 * A malformed regex pattern is refused at the submit boundary, before any work.
	 *
	 * A bad regex would otherwise only throw deep inside a tick's scan and fail the
	 * backup partway; it must be caught at the click with nothing written.
	 *
	 * @return void
	 */
	public function test_create_refuses_an_invalid_exclusion_pattern(): void {
		$this->authorise();
		$this->stub_json();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();

		$_POST['exclusions'] = '/[unclosed(class/';

		try {
			$this->controller( $this->manifest_builder_returning( array() ) )->create();
			$this->fail( 'create() should refuse a malformed regex pattern.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( 'pontifex-json-halt', $error->getMessage() );
		} finally {
			unset( $_POST['exclusions'] );
		}

		$this->assertFalse( $this->json['success'] );
		$this->assertSame( 400, $this->json['status'] );
		$this->assertSame( array(), ( new BackupStore( $this->base ) )->backups(), 'No backup may be written when a pattern is refused.' );
	}

	// -------------------------------------------------------------------------
	// Progress honesty around a live job.
	// -------------------------------------------------------------------------

	/**
	 * A transient that stopped refreshing while a job is live is not served.
	 *
	 * The stuck-bar failure from the browser gate: the request writing the
	 * transient died, and its last value would otherwise be reported as live
	 * progress for the rest of the transient's TTL. With an active job on
	 * disk, the job's persisted source-byte cursors must answer instead, in
	 * the same units as the live bar, with the job's start time attached.
	 *
	 * @return void
	 */
	public function test_progress_ignores_a_stale_transient_when_a_job_is_live(): void {
		$this->authorise();
		$this->stub_json();
		Functions\when( 'get_transient' )->justReturn(
			array(
				'phase'       => 'copying',
				'bytes_done'  => 441279062,
				'bytes_total' => 941568495,
				'at'          => time() - 60,
			)
		);

		$jobs = new \Pontifex\Job\JobStore( $this->base );
		$job  = $jobs->create(
			\Pontifex\Job\Job::KIND_EXPORT,
			array(
				'source_bytes_done' => 500000000,
				'total_bytes'       => 941568495,
			),
			time() - 120
		);

		$this->controller()->progress();

		$this->assertTrue( $this->json['success'] );
		$this->assertSame( 500000000, $this->json['data']['bytes_done'], 'The job cursor answers, not the dead request\'s last transient.' );
		$this->assertSame( 941568495, $this->json['data']['bytes_total'] );
		$this->assertSame( $job->created_at(), $this->json['data']['started_at'], 'The job start time rides along for the elapsed timer.' );
	}

	/**
	 * A freshly-refreshed transient is served as-is, with the start time attached.
	 *
	 * @return void
	 */
	public function test_progress_serves_a_fresh_transient_with_the_job_start_time(): void {
		$this->authorise();
		$this->stub_json();
		Functions\when( 'get_transient' )->justReturn(
			array(
				'phase'       => 'copying',
				'bytes_done'  => 5,
				'bytes_total' => 10,
				'at'          => time(),
			)
		);

		$jobs = new \Pontifex\Job\JobStore( $this->base );
		$job  = $jobs->create( \Pontifex\Job\Job::KIND_EXPORT, array(), time() - 30 );

		$this->controller()->progress();

		$this->assertTrue( $this->json['success'] );
		$this->assertSame( 5, $this->json['data']['bytes_done'], 'A live transient is trusted while it is being refreshed.' );
		$this->assertSame( $job->created_at(), $this->json['data']['started_at'] );
	}

	/**
	 * A stale transient with no active job is reported as idle, not as running.
	 *
	 * A fatal that kills a backup leaves the transient behind at its last phase
	 * (handle_shutdown() releases the lock and marks the job failed, but a stale
	 * transient with nothing left running must not be served as live progress —
	 * the screen would otherwise re-attach to a backup that is already over.
	 *
	 * @return void
	 */
	public function test_progress_reports_idle_for_a_stale_transient_with_no_active_job(): void {
		$this->authorise();
		$this->stub_json();
		Functions\when( 'get_transient' )->justReturn(
			array(
				'phase' => 'scanning',
				'done'  => 42,
				'at'    => time() - 60,
			)
		);

		$this->controller()->progress();

		$this->assertTrue( $this->json['success'] );
		$this->assertSame( 'idle', $this->json['data']['phase'], 'A dead run\'s stale transient must not be reported as still running.' );
		$this->assertArrayNotHasKey( 'started_at', $this->json['data'], 'No job is live, so no start time should ride along.' );
	}

	/**
	 * A fresh transient with no active job is still served as live, not downgraded.
	 *
	 * Only a STALE transient with nothing running is downgraded to idle; a
	 * transient still being actively refreshed must be trusted even before a job
	 * record exists for it (e.g. the brief window before the job is created).
	 *
	 * @return void
	 */
	public function test_progress_serves_a_fresh_transient_with_no_active_job(): void {
		$this->authorise();
		$this->stub_json();
		Functions\when( 'get_transient' )->justReturn(
			array(
				'phase' => 'scanning',
				'done'  => 7,
				'at'    => time(),
			)
		);

		$this->controller()->progress();

		$this->assertTrue( $this->json['success'] );
		$this->assertSame( 'scanning', $this->json['data']['phase'], 'A fresh transient must not be downgraded to idle.' );
		$this->assertSame( 7, $this->json['data']['done'] );
	}

	/**
	 * A dead run's stale lock is reclaimed, so the next backup is not blocked.
	 *
	 * Before this fix a crashed run's lock transient blocked every backup for
	 * its full TTL. With no active job and no live progress, the lock is dead
	 * and acquire_lock() must reclaim it rather than refuse with 409.
	 *
	 * @return void
	 */
	public function test_create_reclaims_a_dead_runs_stale_lock(): void {
		$this->authorise();
		$this->stub_json();
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		// The holder transient is set (a crashed run left it behind), but neither
		// an active job nor a live progress transient exists — nothing is actually
		// running, so the lock must be reclaimed rather than refused.
		Functions\when( 'get_transient' )->alias(
			static function ( string $key ) {
				return OperationLock::LOCK_NAME === $key
					? array(
						'kind' => OperationLock::OP_BACKUP,
						'at'   => time() - 500,
					)
					: false;
			}
		);

		$plans = array( $this->file_plan( 'wp-content/note.txt', "content\n" ) );

		$this->controller( $this->manifest_builder_returning( $plans ) )->create();

		$this->assertTrue( $this->json['success'], 'A dead run\'s lock must be reclaimed, letting a fresh backup proceed.' );
		$this->assertCount( 1, ( new BackupStore( $this->base ) )->backups(), 'The reclaimed lock must let the backup actually run.' );
	}

	/**
	 * The shutdown handler clears the progress transient after a fatal.
	 *
	 * Without this, a fatal-killed backup leaves the transient frozen at its
	 * last phase, and progress() would report the dead run as still running
	 * until the transient's TTL expired.
	 *
	 * @return void
	 */
	public function test_handle_shutdown_clears_the_progress_transient_on_a_fatal(): void {
		$this->authorise();
		$this->stub_json();
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'error_get_last' )->justReturn(
			array(
				'type'    => E_ERROR,
				'message' => 'simulated fatal',
			)
		);

		$deleted = array();
		Functions\when( 'delete_transient' )->alias(
			static function ( string $key ) use ( &$deleted ): bool {
				$deleted[] = $key;
				return true;
			}
		);

		$logger = Mockery::mock( LoggerInterface::class );
		$logger->shouldReceive( 'error' )->atLeast()->once();

		$controller = $this->controller( null, $logger );

		// Acquire the shared lock as create() would at the start of a run, so
		// handle_shutdown() treats this request as one mid-backup when the
		// simulated fatal struck. The lock is now a collaborator object
		// (OperationLock) rather than a private method on the controller, so
		// it is reached via the controller's own private $lock property.
		$lock_property = new \ReflectionProperty( BackupController::class, 'lock' );
		$lock_property->getValue( $controller )->acquire( OperationLock::OP_BACKUP );

		$controller->handle_shutdown();

		$this->assertContains( 'pontifex_backup_progress', $deleted, 'A fatal-killed backup must not leave the progress transient behind.' );
	}

	// -------------------------------------------------------------------------
	// Schedule saving.
	// -------------------------------------------------------------------------

	/**
	 * Refuses to save the schedule without the managing capability.
	 *
	 * @return void
	 */
	public function test_save_schedule_refuses_without_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->stub_json();

		try {
			$this->controller()->save_schedule();
			$this->fail( 'save_schedule() should refuse without the capability.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( 'pontifex-json-halt', $error->getMessage() );
		}

		$this->assertFalse( $this->json['success'] );
		$this->assertSame( 403, $this->json['status'] );
	}

	/**
	 * Saves a valid schedule through the store and reports the next run time.
	 *
	 * The saved option must carry exactly the submitted fields, and the store's
	 * save is the choke point that re-registers the cron event — asserted here
	 * through the stubbed cron functions carrying the schedule's frequency.
	 *
	 * @return void
	 */
	public function test_save_schedule_saves_and_reports_the_next_run(): void {
		$this->authorise();
		$this->stub_json();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		// wp_clear_scheduled_hook is already stubbed for the whole file in setUp
		// (its catch-all would swallow an expect() here); the clear-then-register
		// sync itself is pinned by ScheduleTest, so only the register is asserted.
		Functions\expect( 'wp_schedule_event' )->once()->with( Mockery::type( 'int' ), 'daily', \Pontifex\Schedule\ScheduleStore::CRON_HOOK );

		// A minimal context mock rather than the shared helper: the helper's
		// catch-all save_option expectation would swallow the exact-arguments
		// assertion this test exists to make.
		$context = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'save_option' )
			->once()
			->with(
				\Pontifex\Schedule\ScheduleStore::OPTION,
				array(
					'enabled'    => true,
					'frequency'  => 'daily',
					'hour'       => 3,
					'retention'  => 2,
					'exclusions' => array(),
				)
			);
		$controller = new BackupController( $this->environment_mock(), $context, new BackupStore( $this->base ), new NullLogger() );

		$_POST['enabled']   = '1';
		$_POST['frequency'] = 'daily';
		$_POST['hour']      = '3';
		$_POST['retention'] = '2';

		try {
			$controller->save_schedule();
		} finally {
			unset( $_POST['enabled'], $_POST['frequency'], $_POST['hour'], $_POST['retention'] );
		}

		$this->assertTrue( $this->json['success'] );
		$this->assertTrue( $this->json['data']['enabled'] );
		$this->assertStringEndsWith( ' UTC', $this->json['data']['next_run'], 'The next run is reported as a UTC readout.' );
	}

	/**
	 * Refuses a crafted request carrying an unknown frequency, writing nothing.
	 *
	 * The screen's select can only submit daily or weekly, so an unknown value
	 * is a forged request; the value object refuses it and the option and cron
	 * event must be untouched.
	 *
	 * @return void
	 */
	public function test_save_schedule_refuses_an_invalid_frequency(): void {
		$this->authorise();
		$this->stub_json();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();

		$context = Mockery::mock( WordPressContext::class );
		$context->shouldNotReceive( 'save_option' );
		$controller = new BackupController( $this->environment_mock(), $context, new BackupStore( $this->base ), new NullLogger() );

		$_POST['enabled']   = '1';
		$_POST['frequency'] = 'hourly';
		$_POST['hour']      = '3';
		$_POST['retention'] = '2';

		try {
			$controller->save_schedule();
			$this->fail( 'save_schedule() should refuse an unknown frequency.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( 'pontifex-json-halt', $error->getMessage() );
		} finally {
			unset( $_POST['enabled'], $_POST['frequency'], $_POST['hour'], $_POST['retention'] );
		}

		$this->assertFalse( $this->json['success'] );
		$this->assertSame( 400, $this->json['status'] );
	}

	// -------------------------------------------------------------------------
	// Collaborator builders and stubs.
	// -------------------------------------------------------------------------

	/**
	 * Build a controller around mocked collaborators and a real store on the temp dir.
	 *
	 * @param ManifestBuilderInterface|null $builder Optional injected manifest builder.
	 * @param LoggerInterface|null          $logger  Optional injected logger; a NullLogger by default.
	 * @return BackupController
	 */
	private function controller( ?ManifestBuilderInterface $builder = null, ?LoggerInterface $logger = null ): BackupController {
		return new BackupController(
			$this->environment_mock(),
			$this->wordpress_context_mock(),
			new BackupStore( $this->base ),
			$logger ?? new NullLogger(),
			$builder
		);
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
	 * Stub the transient functions used to report progress.
	 *
	 * @return void
	 */
	private function stub_transients(): void {
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'delete_transient' )->justReturn( true );
	}

	/**
	 * Stub status_header and wp_die so a denied download halts in tests.
	 *
	 * @return void
	 */
	private function stub_die(): void {
		Functions\when( 'status_header' )->justReturn( null );
		Functions\when( 'wp_die' )->alias(
			static function (): void {
				throw new RuntimeException( 'pontifex-die' );
			}
		);
	}

	/**
	 * An Environment mock answering the constant and version reads create() makes.
	 *
	 * @return Environment&\Mockery\MockInterface
	 */
	private function environment_mock() {
		$environment = Mockery::mock( Environment::class );
		$environment->shouldReceive( 'is_constant_defined' )->with( 'ABSPATH' )->andReturn( true );
		$environment->shouldReceive( 'constant_value' )->with( 'ABSPATH' )->andReturn( '/tmp/wp/' );
		$environment->shouldReceive( 'is_constant_defined' )->with( 'WP_CONTENT_DIR' )->andReturn( true );
		$environment->shouldReceive( 'constant_value' )->with( 'WP_CONTENT_DIR' )->andReturn( '/tmp/wp/wp-content' );
		$environment->shouldReceive( 'is_constant_defined' )->with( 'PONTIFEX_VERSION' )->andReturn( false );
		$environment->shouldReceive( 'php_version' )->andReturn( '8.2.0' );
		return $environment;
	}

	/**
	 * A WordPressContext mock supplying provenance facts, counters, and formatting.
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
		$context->shouldReceive( 'format_size' )->andReturnUsing(
			static function ( int $bytes ): string {
				return $bytes . ' B';
			}
		);
		$context->shouldReceive( 'option_value' )->andReturnUsing(
			static function ( string $name, $fallback = false ) {
				return $fallback;
			}
		);
		$context->shouldReceive( 'save_option' );
		$context->shouldReceive( 'acquire_named_lock' )->andReturn( true );
		$context->shouldReceive( 'release_named_lock' );
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
		$builder->shouldReceive( 'build' )->andReturn( ManifestStream::from_plans( $plans ) );
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
	 * Build a file EntryPlan whose source stream cannot be opened.
	 *
	 * The deferred source factory throws when the writer pulls it, so the export
	 * fails partway — after the destination file has been created — which is the
	 * situation the partial-file cleanup must handle.
	 *
	 * @param string $path Relative path inside the archive.
	 * @return EntryPlan
	 */
	private function throwing_plan( string $path ): EntryPlan {
		$header = EntryHeader::for_file( $path, 4, 0o644, 1690000000, 'application/octet-stream', 0 );
		return new EntryPlan(
			$header,
			0,
			str_repeat( "\0", EntryWriter::NONCE_SIZE ),
			static function () {
				throw new RuntimeException( 'simulated source failure' );
			}
		);
	}

	/**
	 * Build a file EntryPlan whose source requests a cancel as it begins to stream.
	 *
	 * The deferred source writes the cancel sentinel just before returning the
	 * stream, so the copy progress callback observes the cancel partway through
	 * writing this entry — the situation the mid-copy cleanup must handle.
	 *
	 * @param string      $path  Relative path inside the archive.
	 * @param BackupStore $store The store whose cancel sentinel the source writes.
	 * @return EntryPlan
	 */
	private function cancelling_plan( string $path, BackupStore $store ): EntryPlan {
		$contents = "first entry payload\n";
		$header   = EntryHeader::for_file( $path, strlen( $contents ), 0o644, 1690000000, 'application/octet-stream', 0 );
		return new EntryPlan(
			$header,
			0,
			str_repeat( "\0", EntryWriter::NONCE_SIZE ),
			static function () use ( $contents, $store ) {
				$store->request_cancel();
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://memory in-process buffer for the test source.
				$stream = fopen( 'php://memory', 'r+b' );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Operating on a test stream resource, not a filesystem path.
				fwrite( $stream, $contents );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource, not a filesystem path.
				rewind( $stream );
				return $stream;
			}
		);
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
