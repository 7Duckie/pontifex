<?php
/**
 * Tests for ArchiveFactsReader — the fail-soft identity read for a backup list row.
 *
 * @package Pontifex\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use DateTimeImmutable;
use DateTimeZone;
use Pontifex\Admin\ArchiveFactsReader;
use Pontifex\Archive\Codec\CodecRegistry;
use Pontifex\Archive\Format\ExporterInfo;
use Pontifex\Archive\Format\Provenance;
use Pontifex\Archive\Format\Scope;
use Pontifex\Archive\Writer\ArchiveWriter;
use Pontifex\Archive\Writer\EntryWriter;
use Pontifex\Archive\Writer\FooterWriter;
use Pontifex\Tests\TestCase;

/**
 * Covers the scope-to-label mapping, the source/created round-trip from real
 * provenance, and the fail-soft behaviour on a bad archive.
 *
 * Each case seeds a real, empty archive fixture (the facts depend only on the
 * provenance block, never on the entries), mirroring VerifyControllerTest's
 * fixture-writer pattern. The fail-soft cases prove a stray or damaged file in
 * the backups directory never turns into an exception.
 */
final class ArchiveFactsReaderTest extends TestCase {

	/**
	 * Temporary directory fixtures are written into for one test.
	 *
	 * @var string
	 */
	private string $base = '';

	/**
	 * Reserve a unique temp directory and stub wp_date to UTC.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->base = sys_get_temp_dir() . '/pontifex-archive-facts-reader-' . uniqid( '', true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating a temp fixture directory for the test.
		mkdir( $this->base, 0700, true );
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
	 * Labels a content-only archive as "Content and database".
	 *
	 * @return void
	 */
	public function test_facts_reports_content_only_scope(): void {
		$path = $this->write_archive( Scope::content_only( array() ) );
		$this->assertSame( 'Content and database', ArchiveFactsReader::facts( $path )->scope_label() );
	}

	/**
	 * Labels a database-only archive as "Database only".
	 *
	 * @return void
	 */
	public function test_facts_reports_db_only_scope(): void {
		$path = $this->write_archive( Scope::db_only( array() ) );
		$this->assertSame( 'Database only', ArchiveFactsReader::facts( $path )->scope_label() );
	}

	/**
	 * Labels a files-only archive as "Files only".
	 *
	 * @return void
	 */
	public function test_facts_reports_files_only_scope(): void {
		$path = $this->write_archive( Scope::files_only( array() ) );
		$this->assertSame( 'Files only', ArchiveFactsReader::facts( $path )->scope_label() );
	}

	/**
	 * Labels a whole-site archive as "Whole site".
	 *
	 * @return void
	 */
	public function test_facts_reports_whole_site_scope(): void {
		$path = $this->write_archive( Scope::whole_site( array() ) );
		$this->assertSame( 'Whole site', ArchiveFactsReader::facts( $path )->scope_label() );
	}

	/**
	 * Labels a legacy archive (no recorded scope block) as "Whole site (legacy)".
	 *
	 * @return void
	 */
	public function test_facts_reports_legacy_scope_as_whole_site(): void {
		$path = $this->write_archive( null );
		$this->assertSame( 'Whole site (legacy)', ArchiveFactsReader::facts( $path )->scope_label() );
	}

	/**
	 * Reads the recorded source URL and creation time from a real archive's provenance.
	 *
	 * This is the round-trip the whole slice depends on: the facts must come from
	 * what the archive itself recorded, never from its filename or the moment it
	 * was read.
	 *
	 * @return void
	 */
	public function test_facts_reads_the_recorded_source_and_created_time(): void {
		$path  = $this->write_archive( Scope::content_only( array() ) );
		$facts = ArchiveFactsReader::facts( $path );

		$this->assertSame( 'https://example.test', $facts->source_url() );
		$this->assertSame( '10:00 on 23-05-2026', $facts->created_label() );
	}

	/**
	 * Fails soft to "Unknown" for a file that is not an archive at all.
	 *
	 * @return void
	 */
	public function test_facts_reports_unknown_for_a_garbage_file(): void {
		$path = $this->base . '/garbage.wpmig';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing a non-archive fixture file for the test.
		file_put_contents( $path, 'this is not a pontifex archive' );

		$facts = ArchiveFactsReader::facts( $path );
		$this->assertSame( 'Unknown', $facts->scope_label() );
		$this->assertNull( $facts->source_url() );
		$this->assertSame( 'Unknown', $facts->created_label() );
	}

	/**
	 * Fails soft to "Unknown" for an archive truncated part-way through.
	 *
	 * @return void
	 */
	public function test_facts_reports_unknown_for_a_truncated_archive(): void {
		$path      = $this->write_archive( Scope::content_only( array() ) );
		$truncated = $this->base . '/truncated.wpmig';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a fixture archive back to truncate it for the test.
		$bytes = file_get_contents( $path );
		$this->assertNotFalse( $bytes, 'The fixture archive must have been written.' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing a deliberately truncated fixture archive for the test.
		file_put_contents( $truncated, substr( (string) $bytes, 0, 8 ) );

		$facts = ArchiveFactsReader::facts( $truncated );
		$this->assertSame( 'Unknown', $facts->scope_label() );
		$this->assertNull( $facts->source_url() );
		$this->assertSame( 'Unknown', $facts->created_label() );
	}

	/**
	 * Fails soft to "Unknown" for a path that does not exist.
	 *
	 * @return void
	 */
	public function test_facts_reports_unknown_for_a_missing_file(): void {
		$facts = ArchiveFactsReader::facts( $this->base . '/does-not-exist.wpmig' );
		$this->assertSame( 'Unknown', $facts->scope_label() );
		$this->assertNull( $facts->source_url() );
		$this->assertSame( 'Unknown', $facts->created_label() );
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
