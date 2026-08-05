<?php
/**
 * Integration test: verify and restore must agree about which archives are acceptable.
 *
 * @package Pontifex\Tests\Integration
 */

declare(strict_types=1);

namespace Pontifex\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use Pontifex\Archive\Codec\CodecRegistry;
use Pontifex\Archive\Codec\RawCodec;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Format\ExporterInfo;
use Pontifex\Archive\Format\Provenance;
use Pontifex\Archive\Format\Scope;
use Pontifex\Archive\Reader\ArchiveReader;
use Pontifex\Archive\Reader\EntryReader;
use Pontifex\Archive\Writer\ArchiveWriter;
use Pontifex\Archive\Writer\EntryPlan;
use Pontifex\Archive\Writer\EntryWriter;
use Pontifex\Archive\Writer\FooterWriter;
use Pontifex\Manifest\WpdbAdapter;
use Pontifex\Restore\DatabaseWriter;
use Pontifex\Restore\FileWriter;
use Pontifex\Restore\RestoreRunner;

/**
 * Proves verify no longer vouches for archives a restore then refuses.
 *
 * This is the regression test for a specific, reported gap. `verify()` checked
 * every SHA-256 hash and reported SOUND; `restore()` additionally settled four
 * questions verify never asked, so an archive could pass verification and be
 * refused moments later by the restore that verification was supposed to
 * vouch for. An operator who checks their backups — exactly the diligent
 * behaviour a backup tool should reward — was the one misled by it.
 *
 * The archives here are built to be hostile in ways that leave every hash
 * valid, which is the whole difficulty: nothing about integrity checking can
 * detect them, because nothing about them is corrupt. They are simply archives
 * no restore will accept.
 *
 * Runs against the real engine over a temporary restore root; nothing outside
 * that root is touched, and no database table is written.
 */
final class VerifyRestoreAgreementTest extends TestCase {

	/**
	 * Absolute path to this test's temporary restore root.
	 *
	 * @var string
	 */
	private string $restore_root = '';

	/**
	 * Create a unique restore root.
	 *
	 * @return void
	 */
	protected function set_up(): void { // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- PHPUnit Polyfills fixture method name.
		parent::set_up();
		$this->restore_root = sys_get_temp_dir() . '/pontifex-agreement-' . bin2hex( random_bytes( 8 ) );
	}

	/**
	 * Remove the restore root.
	 *
	 * @return void
	 */
	protected function tear_down(): void { // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- PHPUnit Polyfills fixture method name.
		self::remove_tree( $this->restore_root );
		parent::tear_down();
	}

	/**
	 * An archive whose symlink escapes the site is refused by verify AND by restore.
	 *
	 * Before this change the first assertion failed: every hash matched, so
	 * verify walked the whole archive without complaint and reported it sound,
	 * while the restore refused the identical bytes. The two now agree.
	 *
	 * @return void
	 */
	public function test_an_escaping_symlink_is_refused_by_both_verify_and_restore(): void {
		$archive = $this->archive_with( array( self::symlink_plan( 'wp-content/leak', '../../../../etc/passwd' ) ) );

		$this->assertSame(
			'refused',
			$this->verify_outcome( $archive ),
			'Verify must not vouch for an archive whose symlink escapes the site.'
		);
		$this->assertSame( 'refused', $this->restore_outcome( $archive ) );
	}

	/**
	 * An archive whose scope contradicts its manifest is refused by both.
	 *
	 * @return void
	 */
	public function test_a_scope_contradicting_the_manifest_is_refused_by_both(): void {
		$archive = $this->archive_with(
			array( self::db_chunk_plan( 'wp_options', 1, "INSERT INTO `wp_options` VALUES (1);\n" ) ),
			Scope::files_only( array() )
		);

		$this->assertSame( 'refused', $this->verify_outcome( $archive ) );
		$this->assertSame( 'refused', $this->restore_outcome( $archive ) );
	}

	/**
	 * A legitimate archive is accepted by both, so the new refusals are not blanket.
	 *
	 * The other half of the guarantee, and the more important one to keep
	 * passing: a check that refuses everything agrees with the restore trivially
	 * and is useless. A backup with an ordinary relative link inside the tree —
	 * which real sites have — must still verify sound and still restore.
	 *
	 * @return void
	 */
	public function test_an_ordinary_archive_is_accepted_by_both(): void {
		$archive = $this->archive_with(
			array(
				self::file_plan( 'wp-content/uploads/photo.jpg', 'binary-ish bytes' ),
				self::symlink_plan( 'wp-content/uploads/latest.jpg', 'photo.jpg' ),
			)
		);

		$this->assertSame( 'accepted', $this->verify_outcome( $archive ) );
		$this->assertSame( 'accepted', $this->restore_outcome( $archive ) );
		$this->assertFileExists( $this->restore_root . '/wp-content/uploads/photo.jpg' );
	}

	/**
	 * Verify places no entry from the archive on disk, refusal or not.
	 *
	 * The distinction that matters between verify and restore is whether the
	 * archive's CONTENTS reach the filesystem. Nothing here may appear, whether
	 * the archive is accepted or refused, and whether or not it contains links.
	 *
	 * Note what this deliberately does not assert. Constructing a FileWriter
	 * creates its destination root, so the root directory itself can come into
	 * existence during a verification — a no-op in production, where the root is
	 * ABSPATH and has obviously always existed, but not literally "touches
	 * nothing". Asserting on the root would be asserting on that quirk rather
	 * than on the behaviour anybody depends on. That the host capability probe
	 * (the one preflight that writes) never runs on this path is pinned directly,
	 * by RestorePreflightTest, which is the level it can actually be observed at.
	 *
	 * @return void
	 */
	public function test_verify_puts_no_archive_entry_on_disk_even_when_it_refuses(): void {
		$archive = $this->archive_with(
			array(
				self::file_plan( 'wp-content/should-not-appear.txt', 'contents' ),
				self::symlink_plan( 'wp-content/leak', '/etc/passwd' ),
			)
		);

		$this->assertSame( 'refused', $this->verify_outcome( $archive ) );

		$this->assertFileDoesNotExist( $this->restore_root . '/wp-content/should-not-appear.txt' );
		$this->assertFalse( is_link( $this->restore_root . '/wp-content/leak' ) );
		$this->assertSame(
			array(),
			is_dir( $this->restore_root ) ? array_values( array_diff( (array) scandir( $this->restore_root ), array( '.', '..' ) ) ) : array(),
			'Verify must leave the destination root empty.'
		);
	}

	// -------------------------------------------------------------------------
	// Harness.
	// -------------------------------------------------------------------------

	/**
	 * Run the verify path and report whether it accepted or refused the archive.
	 *
	 * Mirrors what the surfaces do: hash-walk first, then the read-only
	 * preflights, and a finding against the ARCHIVE is a refusal.
	 *
	 * @param string $archive_path The archive to verify.
	 * @return string 'accepted', 'refused', or 'broken'.
	 */
	private function verify_outcome( string $archive_path ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Reading a test-created archive; WP_Filesystem is not the right tool for a stream.
		$source = fopen( $archive_path, 'rb' );
		$runner = $this->make_runner();

		try {
			$runner->verify( $source );
		} catch ( Throwable $broken ) {
			unset( $broken );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the test's own handle.
			fclose( $source );
			return 'broken';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Rewinding the test's own stream resource.
		rewind( $source );
		$reader   = new ArchiveReader( $source );
		$manifest = $reader->manifest();
		$scope    = $reader->provenance()->scope();
		$report   = $runner->preflight()->read_only_report( $source, $manifest, $scope );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the test's own handle.
		fclose( $source );

		return $report->archive_is_refused() ? 'refused' : 'accepted';
	}

	/**
	 * Run the real restore and report whether it accepted or refused the archive.
	 *
	 * @param string $archive_path The archive to restore.
	 * @return string 'accepted' or 'refused'.
	 */
	private function restore_outcome( string $archive_path ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Reading a test-created archive; WP_Filesystem is not the right tool for a stream.
		$source = fopen( $archive_path, 'rb' );

		try {
			$this->make_runner()->restore( $source );
			return 'accepted';
		} catch ( Throwable $refusal ) {
			unset( $refusal );
			return 'refused';
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the test's own handle.
			fclose( $source );
		}
	}

	/**
	 * A restore runner rooted at this test's temporary root, over the real database seam.
	 *
	 * @return RestoreRunner
	 */
	private function make_runner(): RestoreRunner {
		global $wpdb;

		return new RestoreRunner(
			new EntryReader( CodecRegistry::with_defaults() ),
			new FileWriter( $this->restore_root ),
			new DatabaseWriter( new WpdbAdapter( $wpdb ) )
		);
	}

	/**
	 * Write an archive containing the given entries and return its path.
	 *
	 * @param EntryPlan[] $plans The entries to include.
	 * @param Scope|null  $scope Optional recorded scope.
	 * @return string The archive path.
	 */
	private function archive_with( array $plans, ?Scope $scope = null ): string {
		$path = sys_get_temp_dir() . '/pontifex-agreement-' . bin2hex( random_bytes( 8 ) ) . '.wpmig';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Writing the test's own archive fixture.
		$destination = fopen( $path, 'w+b' );
		$writer      = new ArchiveWriter( new EntryWriter( CodecRegistry::with_defaults() ), new FooterWriter() );
		$writer->write_archive( self::provenance( $scope ), $plans, $destination );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the test's own handle.
		fclose( $destination );

		return $path;
	}

	/**
	 * Provenance for a test archive.
	 *
	 * @param Scope|null $scope Optional recorded scope.
	 * @return Provenance
	 */
	private static function provenance( ?Scope $scope = null ): Provenance {
		return new Provenance(
			'6.6.1',
			'8.2.10',
			'https://example.test',
			'utf8mb4',
			'utf8mb4_unicode_520_ci',
			new ExporterInfo( 'pontifex', '1.0.3' ),
			new DateTimeImmutable( '2026-08-05T10:00:00+00:00', new DateTimeZone( 'UTC' ) ),
			null,
			null,
			$scope
		);
	}

	/**
	 * Build a file entry plan.
	 *
	 * @param string $path     Relative path in the archive.
	 * @param string $contents The file's bytes.
	 * @return EntryPlan
	 */
	private static function file_plan( string $path, string $contents ): EntryPlan {
		$header = EntryHeader::for_file( $path, strlen( $contents ), 0o644, 1690000000, 'application/octet-stream', 0 );
		return new EntryPlan( $header, RawCodec::ID, str_repeat( "\0", EntryWriter::NONCE_SIZE ), self::stream( $contents ) );
	}

	/**
	 * Build a symlink entry plan.
	 *
	 * @param string $path   Relative path in the archive.
	 * @param string $target The link target.
	 * @return EntryPlan
	 */
	private static function symlink_plan( string $path, string $target ): EntryPlan {
		$header = EntryHeader::for_symlink( $path, $target, 0 );
		return new EntryPlan( $header, RawCodec::ID, str_repeat( "\0", EntryWriter::NONCE_SIZE ), self::stream() );
	}

	/**
	 * Build a db_chunk entry plan.
	 *
	 * @param string $table           The source table name.
	 * @param int    $statement_count Statements in the chunk.
	 * @param string $sql             The SQL bytes.
	 * @return EntryPlan
	 */
	private static function db_chunk_plan( string $table, int $statement_count, string $sql ): EntryPlan {
		$header = EntryHeader::for_db_chunk( 0, $table, $statement_count, strlen( $sql ), 0 );
		return new EntryPlan( $header, RawCodec::ID, str_repeat( "\0", EntryWriter::NONCE_SIZE ), self::stream( $sql ) );
	}

	/**
	 * An in-memory stream, optionally pre-filled.
	 *
	 * @param string $contents Optional contents.
	 * @return resource
	 */
	private static function stream( string $contents = '' ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- In-memory test stream, not a filesystem path.
		$stream = fopen( 'php://memory', 'r+b' );
		if ( '' !== $contents ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Writing to an in-memory test stream.
			fwrite( $stream, $contents );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Test stream resource.
			rewind( $stream );
		}
		return $stream;
	}

	/**
	 * Recursively remove a directory tree the test created.
	 *
	 * @param string $path The tree to remove.
	 * @return void
	 */
	private static function remove_tree( string $path ): void {
		if ( '' === $path || ! is_dir( $path ) ) {
			return;
		}
		foreach ( array_diff( (array) scandir( $path ), array( '.', '..' ) ) as $entry ) {
			$child = $path . '/' . $entry;
			if ( is_link( $child ) || is_file( $child ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test-only cleanup.
				unlink( $child );
				continue;
			}
			self::remove_tree( $child );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test-only cleanup.
		rmdir( $path );
	}
}
