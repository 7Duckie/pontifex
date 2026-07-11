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
		Functions\when( 'get_transient' )->alias(
			static function ( string $key ) {
				return 'pontifex_backup_lock' === $key ? time() : false;
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
		$context->shouldReceive( 'acquire_named_lock' )->once()->with( 'pontifex_backup_lock' )->andReturn( false );
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
	 * The named lock is handed back when the transient guard refuses a backup.
	 *
	 * The transient is the secondary guard, checked only while the named lock is
	 * held. When it refuses — a crashed run's transient still inside its TTL —
	 * the named lock just taken must be released again; under a persistent
	 * database connection it would otherwise linger and block every later run.
	 *
	 * @return void
	 */
	public function test_create_hands_back_the_named_lock_when_the_transient_guard_refuses(): void {
		$this->authorise();
		$this->stub_json();
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'get_transient' )->alias(
			static function ( string $key ) {
				return 'pontifex_backup_lock' === $key ? time() : false;
			}
		);

		$context = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'acquire_named_lock' )->once()->with( 'pontifex_backup_lock' )->andReturn( true );
		$context->shouldReceive( 'release_named_lock' )->once()->with( 'pontifex_backup_lock' );

		$controller = new BackupController(
			$this->environment_mock(),
			$context,
			new BackupStore( $this->base ),
			new NullLogger()
		);

		try {
			$controller->create();
			$this->fail( 'create() should refuse while the lock transient is set.' );
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
