<?php
/**
 * Tests for ArchiveScopeReader — the fail-soft "Contains" label for a backup list row.
 *
 * @package Pontifex\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Admin;

use DateTimeImmutable;
use DateTimeZone;
use Pontifex\Admin\ArchiveScopeReader;
use Pontifex\Archive\Codec\CodecRegistry;
use Pontifex\Archive\Format\ExporterInfo;
use Pontifex\Archive\Format\Provenance;
use Pontifex\Archive\Format\Scope;
use Pontifex\Archive\Writer\ArchiveWriter;
use Pontifex\Archive\Writer\EntryWriter;
use Pontifex\Archive\Writer\FooterWriter;
use Pontifex\Tests\TestCase;

/**
 * Covers the scope-to-label mapping and the fail-soft behaviour on a bad archive.
 *
 * Each case seeds a real, empty archive fixture (the label depends only on the
 * provenance block's recorded scope, never on the entries), mirroring
 * VerifyControllerTest's fixture-writer pattern. The fail-soft cases prove a
 * stray or damaged file in the backups directory never turns into an exception.
 */
final class ArchiveScopeReaderTest extends TestCase {

	/**
	 * Temporary directory fixtures are written into for one test.
	 *
	 * @var string
	 */
	private string $base = '';

	/**
	 * Reserve a unique temp directory.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->base = sys_get_temp_dir() . '/pontifex-archive-scope-reader-' . uniqid( '', true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating a temp fixture directory for the test.
		mkdir( $this->base, 0700, true );
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
	 * Labels a content-only archive as "Content and database".
	 *
	 * @return void
	 */
	public function test_label_reports_content_only(): void {
		$path = $this->write_archive( Scope::content_only( array() ) );
		$this->assertSame( 'Content and database', ArchiveScopeReader::label( $path ) );
	}

	/**
	 * Labels a database-only archive as "Database only".
	 *
	 * @return void
	 */
	public function test_label_reports_db_only(): void {
		$path = $this->write_archive( Scope::db_only( array() ) );
		$this->assertSame( 'Database only', ArchiveScopeReader::label( $path ) );
	}

	/**
	 * Labels a files-only archive as "Files only".
	 *
	 * @return void
	 */
	public function test_label_reports_files_only(): void {
		$path = $this->write_archive( Scope::files_only( array() ) );
		$this->assertSame( 'Files only', ArchiveScopeReader::label( $path ) );
	}

	/**
	 * Labels a whole-site archive as "Whole site".
	 *
	 * @return void
	 */
	public function test_label_reports_whole_site(): void {
		$path = $this->write_archive( Scope::whole_site( array() ) );
		$this->assertSame( 'Whole site', ArchiveScopeReader::label( $path ) );
	}

	/**
	 * Labels a legacy archive (no recorded scope block) as "Whole site (legacy)".
	 *
	 * @return void
	 */
	public function test_label_reports_legacy_as_whole_site(): void {
		$path = $this->write_archive( null );
		$this->assertSame( 'Whole site (legacy)', ArchiveScopeReader::label( $path ) );
	}

	/**
	 * Fails soft to "Unknown" for a file that is not an archive at all.
	 *
	 * @return void
	 */
	public function test_label_reports_unknown_for_a_garbage_file(): void {
		$path = $this->base . '/garbage.wpmig';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing a non-archive fixture file for the test.
		file_put_contents( $path, 'this is not a pontifex archive' );

		$this->assertSame( 'Unknown', ArchiveScopeReader::label( $path ) );
	}

	/**
	 * Fails soft to "Unknown" for an archive truncated part-way through.
	 *
	 * @return void
	 */
	public function test_label_reports_unknown_for_a_truncated_archive(): void {
		$path      = $this->write_archive( Scope::content_only( array() ) );
		$truncated = $this->base . '/truncated.wpmig';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a fixture archive back to truncate it for the test.
		$bytes = file_get_contents( $path );
		$this->assertNotFalse( $bytes, 'The fixture archive must have been written.' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing a deliberately truncated fixture archive for the test.
		file_put_contents( $truncated, substr( (string) $bytes, 0, 8 ) );

		$this->assertSame( 'Unknown', ArchiveScopeReader::label( $truncated ) );
	}

	/**
	 * Fails soft to "Unknown" for a path that does not exist.
	 *
	 * @return void
	 */
	public function test_label_reports_unknown_for_a_missing_file(): void {
		$this->assertSame( 'Unknown', ArchiveScopeReader::label( $this->base . '/does-not-exist.wpmig' ) );
	}

	// -------------------------------------------------------------------------
	// Archive fixtures.
	// -------------------------------------------------------------------------

	/**
	 * Write a valid, empty, unencrypted archive with the given scope and return its path.
	 *
	 * @param Scope|null $scope The recorded scope, or null for a legacy scope-less fixture.
	 * @return string Absolute path to the written archive.
	 */
	private function write_archive( ?Scope $scope ): string {
		$path = $this->base . '/pontifex-backup-20260101T000000Z.wpmig';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Opening a temp fixture archive for writing.
		$dest = fopen( $path, 'w+b' );
		if ( false === $dest ) {
			$this->fail( 'Could not open the fixture archive for writing.' );
		}
		( new ArchiveWriter( new EntryWriter( CodecRegistry::with_defaults() ), new FooterWriter() ) )
			->write_archive( $this->sample_provenance( $scope ), array(), $dest );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the temp fixture archive.
		fclose( $dest );
		return $path;
	}

	/**
	 * Build a Provenance with realistic but arbitrary field values for the fixture.
	 *
	 * @param Scope|null $scope Optional recorded scope; null for a legacy scope-less fixture.
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
			null,
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
