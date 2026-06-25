<?php
/**
 * Tests for VerifyController — the admin-ajax endpoints behind the Verify screen.
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
use Pontifex\Admin\VerifyController;
use Pontifex\Archive\Codec\CodecRegistry;
use Pontifex\Archive\Format\ExporterInfo;
use Pontifex\Archive\Format\Provenance;
use Pontifex\Archive\Writer\ArchiveWriter;
use Pontifex\Archive\Writer\EntryWriter;
use Pontifex\Archive\Writer\FooterWriter;
use Pontifex\Environment\Environment;
use Pontifex\Restore\RestoreRunnerInterface;
use Pontifex\Tests\TestCase;
use Pontifex\WordPress\WordPressContext;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Covers the controller's authorisation gates and the verify flow.
 *
 * The engine is an injected {@see RestoreRunnerInterface} double so the sound and
 * broken verdicts can be exercised without a populated archive; the controller's
 * own encryption pre-check still runs against a real (empty) plain archive on
 * disk, which an injected runner does not bypass. The default plain-archive
 * engine wiring is covered by the dev-site smoke, matching how the Backup
 * screen's default export engine is covered. WordPress functions are stubbed with
 * brain/monkey: wp_send_json_error and wp_die throw, so a refused request halts
 * the way it does in production; wp_send_json_success captures its payload.
 */
final class VerifyControllerTest extends TestCase {

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
		$this->base = sys_get_temp_dir() . '/pontifex-verify-controller-' . uniqid( '', true );
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
	 * Refuses to verify without the managing capability.
	 *
	 * @return void
	 */
	public function test_verify_refuses_without_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->stub_json();

		try {
			$this->controller()->verify();
			$this->fail( 'verify() should refuse without the capability.' );
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
	public function test_verify_refuses_an_unresolved_file(): void {
		$this->authorise();
		$this->stub_json();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_file_name' )->returnArg();
		$_POST['file'] = '../secret.txt';

		try {
			$this->controller()->verify();
			$this->fail( 'verify() should refuse a name that does not resolve.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( 'pontifex-json-halt', $error->getMessage() );
		} finally {
			unset( $_POST['file'] );
		}

		$this->assertFalse( $this->json['success'] );
		$this->assertSame( 404, $this->json['status'] );
	}

	/**
	 * Reports a sound verdict when the engine verifies every entry.
	 *
	 * @return void
	 */
	public function test_verify_reports_a_sound_archive(): void {
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
		$runner->shouldReceive( 'verify' )->once()->andReturnUsing(
			static function ( $source, ?callable $callback ): void {
				if ( null !== $callback ) {
					$callback( 3, 3 );
				}
			}
		);

		try {
			$this->controller( $runner )->verify();
		} finally {
			unset( $_POST['file'] );
		}

		$this->assertTrue( $this->json['success'] );
		$this->assertTrue( $this->json['data']['sound'] );
		$this->assertSame( 3, $this->json['data']['entries'] );
	}

	/**
	 * Reports byte progress against the archive size as the engine reads.
	 *
	 * @return void
	 */
	public function test_verify_reports_byte_progress(): void {
		$this->authorise();
		$this->stub_json();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_file_name' )->returnArg();

		$writes = array();
		Functions\when( 'set_transient' )->alias(
			static function ( string $key, $value ) use ( &$writes ): bool {
				if ( 'pontifex_verify_progress' === $key ) {
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
		$runner->shouldReceive( 'verify' )->once()->andReturnUsing(
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
			$this->controller( $runner )->verify();
		} finally {
			unset( $_POST['file'] );
		}

		$verifying = array_values(
			array_filter(
				$writes,
				static function ( $write ): bool {
					return is_array( $write ) && isset( $write['phase'] ) && 'verifying' === $write['phase'];
				}
			)
		);

		$this->assertNotEmpty( $verifying, 'The verify phase must write byte progress.' );
		$this->assertGreaterThan( 0, (int) $verifying[0]['bytes_total'], 'bytes_total is the archive size.' );
		$max_done = 0;
		foreach ( $verifying as $write ) {
			$max_done = max( $max_done, (int) $write['bytes_done'] );
		}
		$this->assertGreaterThan( 0, $max_done, 'The byte callback must report archive bytes read.' );
	}

	/**
	 * Reports a broken verdict, and logs it, when the engine refuses an entry.
	 *
	 * A broken archive is a successful verification with a negative result, so it
	 * comes back as a JSON success carrying sound=false — not an error response.
	 *
	 * @return void
	 */
	public function test_verify_reports_a_broken_archive(): void {
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

		$logger = Mockery::mock( LoggerInterface::class );
		$logger->shouldReceive( 'error' )->once();

		$runner = Mockery::mock( RestoreRunnerInterface::class );
		$runner->shouldReceive( 'verify' )->once()->andThrow( new RuntimeException( 'entry 2 hash mismatch' ) );

		try {
			$this->controller( $runner, $logger )->verify();
		} finally {
			unset( $_POST['file'] );
		}

		$this->assertTrue( $this->json['success'], 'A broken verdict is reported as a JSON success.' );
		$this->assertFalse( $this->json['data']['sound'] );
	}

	/**
	 * Refuses a second verification while one is already running.
	 *
	 * The single-runner lock stops two concurrent checks fighting over the shared
	 * progress transient. The lock is taken before the archive is opened, so a
	 * placeholder file is enough to get past name resolution.
	 *
	 * @return void
	 */
	public function test_verify_refuses_when_a_verification_is_already_running(): void {
		$this->authorise();
		$this->stub_json();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_file_name' )->returnArg();
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'get_transient' )->alias(
			static function ( string $key ) {
				return 'pontifex_verify_lock' === $key ? time() : false;
			}
		);

		$store = new BackupStore( $this->base );
		$store->ensure_directory();
		$name = 'pontifex-backup-20260101T000000Z.wpmig';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Seeding a placeholder backup in a temp directory; the lock is checked before it is opened.
		file_put_contents( $store->directory() . '/' . $name, 'x' );
		$_POST['file'] = $name;

		try {
			$this->controller()->verify();
			$this->fail( 'verify() should refuse while a verification is already running.' );
		} catch ( RuntimeException $halt ) {
			$this->assertSame( 'pontifex-json-halt', $halt->getMessage() );
		} finally {
			unset( $_POST['file'] );
		}

		$this->assertFalse( $this->json['success'] );
		$this->assertSame( 409, $this->json['status'] );
	}

	// -------------------------------------------------------------------------
	// Collaborator builders and stubs.
	// -------------------------------------------------------------------------

	/**
	 * Build a controller around bare collaborator mocks and a real store on the temp dir.
	 *
	 * The Environment mock is only touched by the default engine wiring, which
	 * these tests never reach (they inject a runner or refuse early). The
	 * WordPressContext formats the verified size for the sound verdict, so it is
	 * given a simple format_size stub.
	 *
	 * @param RestoreRunnerInterface|null $runner Optional injected engine.
	 * @param LoggerInterface|null        $logger Optional injected logger; a NullLogger by default.
	 * @return VerifyController
	 */
	private function controller( ?RestoreRunnerInterface $runner = null, ?LoggerInterface $logger = null ): VerifyController {
		$context = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'format_size' )->andReturnUsing(
			static function ( int $bytes ): string {
				return $bytes . ' B';
			}
		);

		return new VerifyController(
			Mockery::mock( Environment::class ),
			$context,
			new BackupStore( $this->base ),
			$logger ?? new NullLogger(),
			$runner
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
	 * injected engine stands in for the entry walk.
	 *
	 * @param string $path Absolute path to write the archive to.
	 * @return void
	 */
	private function write_plain_archive( string $path ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Opening a temp fixture archive for writing.
		$dest = fopen( $path, 'w+b' );
		if ( false === $dest ) {
			$this->fail( 'Could not open the fixture archive for writing.' );
		}
		( new ArchiveWriter( new EntryWriter( CodecRegistry::with_defaults() ), new FooterWriter() ) )
			->write_archive( $this->sample_provenance(), array(), $dest );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the temp fixture archive.
		fclose( $dest );
	}

	/**
	 * Build a Provenance with realistic but arbitrary field values for the fixture.
	 *
	 * @return Provenance
	 */
	private function sample_provenance(): Provenance {
		return new Provenance(
			'6.6.1',
			'8.2.10',
			'https://example.test',
			'utf8mb4',
			'utf8mb4_unicode_520_ci',
			new ExporterInfo( 'pontifex', '0.1.0' ),
			new DateTimeImmutable( '2026-05-23T10:00:00+00:00', new DateTimeZone( 'UTC' ) )
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
