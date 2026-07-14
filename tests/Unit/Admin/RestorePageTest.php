<?php
/**
 * Tests for RestorePage — the admin Restore screen renderer.
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
use Pontifex\Admin\RestorePage;
use Pontifex\Archive\Codec\CodecRegistry;
use Pontifex\Archive\Format\ExporterInfo;
use Pontifex\Archive\Format\Provenance;
use Pontifex\Archive\Format\Scope;
use Pontifex\Archive\Writer\ArchiveWriter;
use Pontifex\Archive\Writer\EntryWriter;
use Pontifex\Archive\Writer\FooterWriter;
use Pontifex\Rollback\RollbackStoreInterface;
use Pontifex\Tests\TestCase;
use Pontifex\WordPress\WordPressContext;
use RuntimeException;

/**
 * Covers the capability gate, the backup-row data, and a render smoke test.
 *
 * The pure data method {@see RestorePage::backup_rows()} is asserted directly;
 * {@see RestorePage::render()} is exercised as a capability gate and a smoke test,
 * the same split the other admin pages use. wp_date is stubbed to UTC gmdate so
 * the formatted time is deterministic.
 */
final class RestorePageTest extends TestCase {

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
		$this->base = sys_get_temp_dir() . '/pontifex-restore-page-' . uniqid( '', true );
		Functions\when( 'wp_date' )->alias(
			static function ( string $format, ?int $timestamp = null ): string {
				return gmdate( $format, $timestamp ?? 0 );
			}
		);
		Functions\when( 'wp_kses' )->alias(
			static function ( string $content ): string {
				return $content;
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
		( new RestorePage( $this->context(), new BackupStore( $this->base ), $this->rollback_store() ) )->render();
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

		$rows = ( new RestorePage( $this->context(), $store, $this->rollback_store() ) )->backup_rows();

		$this->assertCount( 2, $rows );
		$this->assertSame( 'pontifex-backup-20260301T120000Z.wpmig', $rows[0]['filename'], 'Newest backup comes first.' );
		$this->assertSame( 'pontifex-backup-20260101T090000Z.wpmig', $rows[1]['filename'] );
		$this->assertSame( '12:00 on 01-03-2026', $rows[0]['when'] );
	}

	/**
	 * Renders the backups list as click-to-select rows, with no radio, plus the action box.
	 *
	 * @return void
	 */
	public function test_render_lists_backups_with_a_selectable_action(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$store = new BackupStore( $this->base );
		$store->ensure_directory();
		$name = 'pontifex-backup-20260101T120000Z.wpmig';
		$this->seed( $store, $name );

		ob_start();
		( new RestorePage( $this->context(), $store, $this->rollback_store() ) )->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( $name, $html );
		$this->assertStringContainsString( 'class="pontifex-restore-row"', $html, 'Each backup is a selectable row.' );
		$this->assertStringContainsString( 'data-file="' . $name . '"', $html, 'The row carries its filename for selection.' );
		$this->assertStringContainsString( 'role="radiogroup"', $html, 'The chooser is an accessible radio group.' );
		$this->assertStringContainsString( 'tabindex="0"', $html, 'The first row is the radio group\'s single Tab stop (roving tabindex).' );
		$this->assertStringNotContainsString( 'type="radio"', $html, 'There are no radio inputs — the selected row is outlined instead.' );
		$this->assertStringContainsString( 'aria-label="Restore progress"', $html, 'The restore progressbar carries an accessible name.' );
		$this->assertStringContainsString( 'aria-label="Upload progress"', $html, 'The upload progressbar carries an accessible name.' );
		$this->assertStringContainsString( 'id="pontifex-restore-action"', $html, 'The typed-action box is present.' );
		$this->assertStringContainsString( 'id="pontifex-restore-run"', $html, 'The Run button is present.' );
		$this->assertStringContainsString( 'id="pontifex-restore-migrate"', $html, 'The opt-in link-rewrite checkbox is present.' );
		$this->assertStringContainsString( 'type="checkbox"', $html, 'The link-rewrite opt-in is a checkbox.' );
		$this->assertStringContainsString( 'id="pontifex-upload-file"', $html, 'The upload file picker is present.' );
		$this->assertStringContainsString( 'id="pontifex-upload-run"', $html, 'The upload button is present.' );
		$this->assertStringContainsString( 'accept=".wpmig"', $html, 'The picker accepts only .wpmig files.' );
	}

	/**
	 * Lists the available safety archive with its date and the rollback guidance.
	 *
	 * @return void
	 */
	public function test_render_shows_the_safety_archive_for_rollback(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$store = new BackupStore( $this->base );
		$store->ensure_directory();

		$rollback = Mockery::mock( RollbackStoreInterface::class );
		$rollback->shouldReceive( 'most_recent' )->andReturn( '/x/pontifex/rollback/pre-import-rollback-20260301T084500Z.wpmig' );

		ob_start();
		( new RestorePage( $this->context(), $store, $rollback ) )->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Roll back', $html, 'The rollback section is shown.' );
		$this->assertStringContainsString( 'pre-import-rollback-20260301T084500Z.wpmig', $html, 'The safety archive is listed.' );
		$this->assertStringContainsString( '08:45 on 01-03-2026', $html, 'Its date is shown in the table.' );
		$this->assertStringContainsString( 'download that backup before rolling back', $html, 'The rollback guidance is shown.' );
		$this->assertStringNotContainsString( 'No safety archive', $html, 'The empty message is hidden when an archive exists.' );
	}

	/**
	 * Hides the safety-archive table and shows the empty message when none exists.
	 *
	 * @return void
	 */
	public function test_render_hides_rollback_when_no_safety_archive(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$store = new BackupStore( $this->base );
		$store->ensure_directory();

		ob_start();
		( new RestorePage( $this->context(), $store, $this->rollback_store() ) )->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'No safety archive is available to roll back to', $html );
		$this->assertStringNotContainsString( 'Safety archive', $html, 'No archive table header when there is none.' );
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

		$rows        = ( new RestorePage( $this->context(), $store, $this->rollback_store() ) )->backup_rows();
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
		( new RestorePage( $this->context(), $store, $this->rollback_store() ) )->render();
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
	 * A RollbackStore mock reporting no safety archive available.
	 *
	 * @return RollbackStoreInterface&\Mockery\MockInterface
	 */
	private function rollback_store() {
		$store = Mockery::mock( RollbackStoreInterface::class );
		$store->shouldReceive( 'most_recent' )->andReturn( null );
		return $store;
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
