<?php
/**
 * Tests for VerifyPage — the admin Verify screen renderer.
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
use Pontifex\Admin\VerifyPage;
use Pontifex\Archive\Codec\CodecRegistry;
use Pontifex\Archive\Format\ExporterInfo;
use Pontifex\Archive\Format\Provenance;
use Pontifex\Archive\Format\Scope;
use Pontifex\Archive\Writer\ArchiveWriter;
use Pontifex\Archive\Writer\EntryWriter;
use Pontifex\Archive\Writer\FooterWriter;
use Pontifex\Tests\TestCase;
use Pontifex\WordPress\WordPressContext;
use RuntimeException;

/**
 * Covers the capability gate, the backup-row data, and a render smoke test.
 *
 * The pure data method {@see VerifyPage::backup_rows()} is asserted directly;
 * {@see VerifyPage::render()} is exercised as a capability gate and a smoke test,
 * the same split BackupPage uses. wp_date is stubbed to UTC gmdate so the
 * formatted time is deterministic.
 */
final class VerifyPageTest extends TestCase {

	/**
	 * Temporary content directory the store is rooted at for one test.
	 *
	 * @var string
	 */
	private string $base = '';

	/**
	 * Reserve a unique temp content directory and stub wp_date to UTC.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->base = sys_get_temp_dir() . '/pontifex-verify-page-' . uniqid( '', true );
		Functions\when( 'wp_date' )->alias(
			static function ( string $format, ?int $timestamp = null ): string {
				return gmdate( $format, $timestamp ?? 0 );
			}
		);
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
	 * Refuses a user without the managing capability.
	 *
	 * @return void
	 */
	public function test_render_denies_without_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'wp_die' )->alias(
			static function (): void {
				throw new RuntimeException( 'pontifex-die' );
			}
		);

		$this->expectException( RuntimeException::class );
		( new VerifyPage( $this->context(), new BackupStore( $this->base ) ) )->render();
	}

	/**
	 * Lists the backups newest first, with a colon-formatted local time.
	 *
	 * @return void
	 */
	public function test_backup_rows_lists_backups_newest_first(): void {
		$store = new BackupStore( $this->base );
		$store->ensure_directory();
		$this->seed( $store, 'pontifex-backup-20260101T090000Z.wpmig' );
		$this->seed( $store, 'pontifex-backup-20260301T120000Z.wpmig' );

		$rows = ( new VerifyPage( $this->context(), $store ) )->backup_rows();

		$this->assertCount( 2, $rows );
		$this->assertSame( 'pontifex-backup-20260301T120000Z.wpmig', $rows[0]['filename'], 'Newest backup comes first.' );
		$this->assertSame( 'pontifex-backup-20260101T090000Z.wpmig', $rows[1]['filename'] );
		$this->assertSame( '12:00 on 01-03-2026', $rows[0]['when'] );
	}

	/**
	 * Renders the backups as click-to-select rows, with no radio, plus one Verify button.
	 *
	 * @return void
	 */
	public function test_render_lists_backups_with_a_verify_action(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$store = new BackupStore( $this->base );
		$store->ensure_directory();
		$name = 'pontifex-backup-20260101T120000Z.wpmig';
		$this->seed( $store, $name );

		ob_start();
		( new VerifyPage( $this->context(), $store ) )->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( $name, $html );
		$this->assertStringContainsString( 'class="pontifex-restore-row"', $html, 'Each backup is a selectable row.' );
		$this->assertStringContainsString( 'data-file="' . $name . '"', $html, 'The row carries its filename for selection.' );
		$this->assertStringContainsString( 'role="radiogroup"', $html, 'The chooser is an accessible radio group.' );
		$this->assertStringContainsString( 'tabindex="0"', $html, 'The first row is the radio group\'s single Tab stop (roving tabindex).' );
		$this->assertStringNotContainsString( 'type="radio"', $html, 'There are no radio inputs — the selected row is outlined instead.' );
		$this->assertStringContainsString( 'aria-label="Verification progress"', $html, 'The progressbar carries an accessible name.' );
		$this->assertStringContainsString( 'id="pontifex-verify-run"', $html, 'A single Verify button drives the selected backup.' );
		$this->assertStringContainsString( 'pontifex-verify-timing', $html );
	}

	/**
	 * Renders a persistent, hidden proof panel container the script fills in.
	 *
	 * @return void
	 */
	public function test_render_includes_a_hidden_proof_panel(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$store = new BackupStore( $this->base );
		$store->ensure_directory();

		ob_start();
		( new VerifyPage( $this->context(), $store ) )->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="pontifex-verify-proof"', $html, 'The proof panel container is present for the script to fill in.' );
		$this->assertStringContainsString( 'class="pontifex-proof"', $html );
		$this->assertMatchesRegularExpression(
			'/id="pontifex-verify-proof"[^>]*\shidden/',
			$html,
			'The proof panel starts hidden until a sound verify fills and reveals it.'
		);

		// A cheap no-colour tripwire: the design language forbids a status-colour
		// class for the verdict, so no such token may ever appear in this markup.
		foreach ( array( 'is-error', 'is-success', 'red', 'green', 'amber' ) as $forbidden ) {
			$this->assertStringNotContainsString( $forbidden, $html, "The Verify screen must never carry a status-colour token such as \"{$forbidden}\"." );
		}
	}

	/**
	 * Reports each row's recorded scope as its "Contains" label, and "Unknown" for a corrupt one.
	 *
	 * @return void
	 */
	public function test_backup_rows_report_the_contains_label(): void {
		$store = new BackupStore( $this->base );
		$store->ensure_directory();
		$this->write_scoped_archive( $store, 'pontifex-backup-20260101T000000Z.wpmig', Scope::db_only( array() ) );
		$this->seed( $store, 'pontifex-backup-20260301T000000Z.wpmig' );

		$rows        = ( new VerifyPage( $this->context(), $store ) )->backup_rows();
		$by_filename = array();
		foreach ( $rows as $row ) {
			$by_filename[ $row['filename'] ] = $row;
		}

		$this->assertSame( 'Database only', $by_filename['pontifex-backup-20260101T000000Z.wpmig']['contains'] );
		$this->assertSame( 'Unknown', $by_filename['pontifex-backup-20260301T000000Z.wpmig']['contains'], 'A corrupt archive fails soft to Unknown, never an exception.' );
	}

	/**
	 * Renders each backup row with its "Contains" scope label.
	 *
	 * @return void
	 */
	public function test_render_shows_the_contains_label_per_row(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$store = new BackupStore( $this->base );
		$store->ensure_directory();
		$this->write_scoped_archive( $store, 'pontifex-backup-20260101T000000Z.wpmig', Scope::db_only( array() ) );

		ob_start();
		( new VerifyPage( $this->context(), $store ) )->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<span>Contains</span>', $html, 'The chooser head names the Contains column.' );
		$this->assertStringContainsString( 'class="pontifex-restore-contains">Database only</span>', $html, 'The row states the archive\'s recorded scope.' );
	}

	// -------------------------------------------------------------------------
	// Collaborators and fixtures.
	// -------------------------------------------------------------------------

	/**
	 * A WordPressContext mock that formats sizes as "<bytes> B".
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
		return $context;
	}

	/**
	 * Create an empty file with the given name inside the store directory.
	 *
	 * @param BackupStore $store    The store whose directory to seed.
	 * @param string      $filename The filename to create.
	 * @return void
	 */
	private function seed( BackupStore $store, string $filename ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Seeding a fixture backup in a temp directory.
		file_put_contents( $store->directory() . '/' . $filename, 'x' );
	}

	/**
	 * Write a valid, empty, unencrypted archive with the given scope into the store.
	 *
	 * @param BackupStore $store    The store whose directory to write into.
	 * @param string      $filename The filename to write.
	 * @param Scope|null  $scope    The recorded scope; null for a legacy scope-less fixture.
	 * @return void
	 */
	private function write_scoped_archive( BackupStore $store, string $filename, ?Scope $scope ): void {
		$path = $store->directory() . '/' . $filename;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Opening a temp fixture archive for writing.
		$dest = fopen( $path, 'w+b' );
		if ( false === $dest ) {
			$this->fail( 'Could not open the fixture archive for writing.' );
		}
		$provenance = new Provenance(
			'6.6.1',
			'8.2.10',
			'https://example.test',
			'utf8mb4',
			'utf8mb4_unicode_520_ci',
			new ExporterInfo( 'pontifex', '0.1.0' ),
			new DateTimeImmutable( '2026-05-23T10:00:00+00:00', new DateTimeZone( 'UTC' ) ),
			null,
			null,
			$scope
		);
		( new ArchiveWriter( new EntryWriter( CodecRegistry::with_defaults() ), new FooterWriter() ) )
			->write_archive( $provenance, array(), $dest );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the temp fixture archive.
		fclose( $dest );
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
