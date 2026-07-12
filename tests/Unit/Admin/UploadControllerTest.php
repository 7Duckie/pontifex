<?php
/**
 * Tests for UploadController — the admin-ajax endpoint that uploads a backup in chunks.
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
use Pontifex\Admin\UploadController;
use Pontifex\Archive\Codec\CodecRegistry;
use Pontifex\Archive\Format\ExporterInfo;
use Pontifex\Archive\Format\Provenance;
use Pontifex\Archive\Format\Scope;
use Pontifex\Archive\Writer\ArchiveWriter;
use Pontifex\Archive\Writer\EntryWriter;
use Pontifex\Archive\Writer\FooterWriter;
use Pontifex\Tests\TestCase;
use Pontifex\WordPress\WordPressContext;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Covers the controller's authorisation gate, chunk validation, the assemble-then-
 * store flow, and the safety invariant that only a real archive is ever stored.
 *
 * The store is a real BackupStore over a temporary directory, so the assembly and
 * the move into the backups directory are exercised for real; WordPress functions
 * are stubbed with brain/monkey, with wp_send_json_error throwing so a refused
 * request halts as in production and wp_send_json_success capturing its payload.
 */
final class UploadControllerTest extends TestCase {

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
		$this->base = sys_get_temp_dir() . '/pontifex-upload-controller-' . uniqid( '', true );
		$this->json = array();
	}

	/**
	 * Remove the temp directory tree and clear request superglobals.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		self::rmtree( $this->base );
		unset( $_POST['upload_id'], $_POST['offset'], $_POST['total'], $_FILES['chunk'] );
		parent::tearDown();
	}

	/**
	 * Refuses to upload without the managing capability.
	 *
	 * @return void
	 */
	public function test_refuses_without_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->stub_json();

		try {
			$this->controller()->chunk();
			$this->fail( 'chunk() should refuse without the capability.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( 'pontifex-json-halt', $error->getMessage() );
		}

		$this->assertFalse( $this->json['success'] );
		$this->assertSame( 403, $this->json['status'] );
	}

	/**
	 * A single chunk carrying a whole archive is validated and stored as a backup.
	 *
	 * @return void
	 */
	public function test_single_chunk_upload_stores_the_backup(): void {
		$this->prepare_request();
		$bytes = $this->archive_bytes();
		$this->set_request( 'whole123', 0, strlen( $bytes ) );
		$this->set_chunk( $bytes );

		$this->controller()->chunk();

		$this->assertTrue( $this->json['success'] );
		$this->assertTrue( $this->json['data']['done'] );
		$this->assertMatchesRegularExpression( '/^pontifex-backup-\d{8}T\d{6}Z\.wpmig$/', $this->json['data']['filename'] );

		$store = new BackupStore( $this->base );
		$this->assertNotNull( $store->resolve( $this->json['data']['filename'] ), 'The stored upload is a resolvable backup.' );
	}

	/**
	 * Two chunks assemble into one archive: the first reports progress, the second stores.
	 *
	 * @return void
	 */
	public function test_two_chunk_upload_assembles_and_stores(): void {
		$this->prepare_request();
		$bytes = $this->archive_bytes();
		$total = strlen( $bytes );
		$half  = intdiv( $total, 2 );

		$controller = $this->controller();

		$this->set_request( 'split123', 0, $total );
		$this->set_chunk( substr( $bytes, 0, $half ) );
		$controller->chunk();

		$this->assertTrue( $this->json['success'] );
		$this->assertFalse( $this->json['data']['done'], 'The first chunk is not the end.' );
		$this->assertSame( $half, $this->json['data']['received'] );

		$this->set_request( 'split123', $half, $total );
		$this->set_chunk( substr( $bytes, $half ) );
		$controller->chunk();

		$this->assertTrue( $this->json['success'] );
		$this->assertTrue( $this->json['data']['done'], 'The last chunk stores the backup.' );

		$store = new BackupStore( $this->base );
		$this->assertNotNull( $store->resolve( $this->json['data']['filename'] ) );
	}

	/**
	 * A completed upload that is not a Pontifex archive is refused and not stored.
	 *
	 * @return void
	 */
	public function test_refuses_a_non_archive_and_stores_nothing(): void {
		$this->prepare_request();
		$junk = 'this is not a Pontifex archive at all';
		$this->set_request( 'junk1234', 0, strlen( $junk ) );
		$this->set_chunk( $junk );

		try {
			$this->controller()->chunk();
			$this->fail( 'chunk() should refuse a non-archive.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( 'pontifex-json-halt', $error->getMessage() );
		}

		$this->assertFalse( $this->json['success'] );
		$this->assertSame( 422, $this->json['status'] );

		$store = new BackupStore( $this->base );
		$this->assertSame( array(), $store->backups(), 'No backup is stored when the upload is not an archive.' );
	}

	/**
	 * Refuses a chunk larger than the site's upload ceiling.
	 *
	 * @return void
	 */
	public function test_refuses_an_oversize_chunk(): void {
		$this->prepare_request();
		$this->set_request( 'big12345', 0, 100 );
		$this->set_chunk( str_repeat( 'x', 50 ) );
		// Declare a size beyond the 10 MB ceiling the context reports.
		$_FILES['chunk']['size'] = 20 * 1024 * 1024;

		try {
			$this->controller()->chunk();
			$this->fail( 'chunk() should refuse an oversize chunk.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( 'pontifex-json-halt', $error->getMessage() );
		}

		$this->assertFalse( $this->json['success'] );
		$this->assertSame( 400, $this->json['status'] );
	}

	/**
	 * Refuses a chunk whose offset does not match the bytes already received.
	 *
	 * @return void
	 */
	public function test_refuses_an_out_of_step_offset(): void {
		$this->prepare_request();
		// Nothing has been received, so a non-zero offset is out of step.
		$this->set_request( 'step1234', 500, 1000 );
		$this->set_chunk( str_repeat( 'x', 100 ) );

		try {
			$this->controller()->chunk();
			$this->fail( 'chunk() should refuse an out-of-step offset.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( 'pontifex-json-halt', $error->getMessage() );
		}

		$this->assertFalse( $this->json['success'] );
		$this->assertSame( 409, $this->json['status'] );
		$this->assertSame( 0, $this->json['data']['expected_offset'], 'The client is told to resume from the server view.' );
	}

	// -------------------------------------------------------------------------
	// Helpers.
	// -------------------------------------------------------------------------

	/**
	 * Build the controller over a real store and a mocked context.
	 *
	 * @return UploadController
	 */
	private function controller(): UploadController {
		// The genuine-upload guard is seamed so the test's fixture files (which are not
		// real HTTP uploads) pass; everything else runs for real.
		return new UploadController(
			$this->context(),
			new BackupStore( $this->base ),
			new NullLogger(),
			static function (): bool {
				return true;
			}
		);
	}

	/**
	 * A WordPressContext mock reporting a 10 MB upload ceiling and formatting sizes.
	 *
	 * @return WordPressContext&\Mockery\MockInterface
	 */
	private function context() {
		$context = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'max_upload_size' )->andReturn( 10 * 1024 * 1024 );
		$context->shouldReceive( 'format_size' )->andReturnUsing(
			static function ( int $bytes ): string {
				return $bytes . ' B';
			}
		);
		return $context;
	}

	/**
	 * Stub the capability/nonce gates and the request-shaping functions to pass.
	 *
	 * @return void
	 */
	private function prepare_request(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_ajax_referer' )->justReturn( 1 );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		$this->stub_json();
	}

	/**
	 * Set the upload_id, offset and total request fields for one chunk.
	 *
	 * @param string $upload_id The upload id.
	 * @param int    $offset    The byte offset this chunk starts at.
	 * @param int    $total     The declared total archive size.
	 * @return void
	 */
	private function set_request( string $upload_id, int $offset, int $total ): void {
		$_POST['upload_id'] = $upload_id;
		$_POST['offset']    = (string) $offset;
		$_POST['total']     = (string) $total;
	}

	/**
	 * Write the given bytes to a temp file and present it as the posted chunk.
	 *
	 * @param string $bytes The chunk bytes.
	 * @return void
	 */
	private function set_chunk( string $bytes ): void {
		if ( ! is_dir( $this->base ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating a temp fixture directory for the posted chunk.
			mkdir( $this->base, 0700, true );
		}
		$tmp = $this->base . '/upload-tmp-' . uniqid( '', true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing a temp fixture chunk file.
		file_put_contents( $tmp, $bytes );
		$_FILES['chunk'] = array(
			'name'     => 'chunk',
			'type'     => 'application/octet-stream',
			'tmp_name' => $tmp,
			'error'    => UPLOAD_ERR_OK,
			'size'     => strlen( $bytes ),
		);
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
	 * Return the raw bytes of a valid, empty, unencrypted Pontifex archive.
	 *
	 * @return string The archive bytes.
	 */
	private function archive_bytes(): string {
		if ( ! is_dir( $this->base ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating a temp fixture directory for the archive bytes.
			mkdir( $this->base, 0700, true );
		}
		$path = $this->base . '/fixture-' . uniqid( '', true ) . '.wpmig';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Opening a temp fixture archive for writing.
		$dest = fopen( $path, 'w+b' );
		if ( false === $dest ) {
			$this->fail( 'Could not open the fixture archive for writing.' );
		}
		( new ArchiveWriter( new EntryWriter( CodecRegistry::with_defaults() ), new FooterWriter() ) )
			->write_archive( $this->sample_provenance(), array(), $dest );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the temp fixture archive.
		fclose( $dest );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a temp fixture archive's bytes for the test.
		return (string) file_get_contents( $path );
	}

	/**
	 * Build a content-only Provenance with arbitrary but valid field values.
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
			new DateTimeImmutable( '2026-05-23T10:00:00+00:00', new DateTimeZone( 'UTC' ) ),
			null,
			'wp_',
			Scope::content_only( array() )
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
