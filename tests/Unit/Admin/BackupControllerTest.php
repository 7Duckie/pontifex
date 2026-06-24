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
use Pontifex\Archive\Writer\EntryPlan;
use Pontifex\Archive\Writer\EntryWriter;
use Pontifex\Environment\Environment;
use Pontifex\Manifest\ManifestBuilderInterface;
use Pontifex\Manifest\ManifestStream;
use Pontifex\Tests\TestCase;
use Pontifex\WordPress\WordPressContext;
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

		$backups = ( new BackupStore( $this->base ) )->backups();
		$this->assertCount( 1, $backups, 'Exactly one backup file should have been written.' );
		$this->assertStringStartsWith( 'pontifex-backup-', basename( $backups[0] ) );
		$this->assertSame( 0600, fileperms( $backups[0] ) & 0777, 'A backup must be owner read/write only.' );
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
	 * @return BackupController
	 */
	private function controller( ?ManifestBuilderInterface $builder = null ): BackupController {
		return new BackupController(
			$this->environment_mock(),
			$this->wordpress_context_mock(),
			new BackupStore( $this->base ),
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
