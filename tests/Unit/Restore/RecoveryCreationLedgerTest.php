<?php
/**
 * Unit tests for the failed-restore recovery creation ledger.
 *
 * @package Pontifex\Tests\Unit\Restore
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Restore;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Pontifex\Archive\Codec\CodecRegistry;
use Pontifex\Archive\Codec\RawCodec;
use Pontifex\Archive\Format\ArchiveManifest;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Format\ExporterInfo;
use Pontifex\Archive\Format\Provenance;
use Pontifex\Archive\Reader\ArchiveReader;
use Pontifex\Archive\Reader\EntryReader;
use Pontifex\Archive\Writer\ArchiveWriter;
use Pontifex\Archive\Writer\EntryPlan;
use Pontifex\Archive\Writer\EntryWriter;
use Pontifex\Archive\Writer\FooterWriter;
use Pontifex\Restore\DatabaseWriter;
use Pontifex\Restore\FileWriter;
use Pontifex\Restore\RestoreRunner;
use Pontifex\Tests\Unit\Manifest\Fakes\FakeDbAdapter;

/**
 * Proves the "recovery is a real revert" contract at the real-engine level.
 *
 * A restore is purely additive (see
 * {@see \Pontifex\Tests\Integration\PartialScopeRestoreTest} for that contract
 * proven against a real database), so replaying a pre-import safety archive
 * alone leaves every file a FAILED import created still on disk. FileWriter's
 * creation ledger, and {@see RestoreRunner::remove_created_paths()}, are what
 * closes that: a second call, made only after the safety archive has already
 * been replayed, that deletes exactly what the failed run's own FileWriter
 * created and nothing else.
 *
 * No mocking library is used for the restore engine itself: two REAL
 * RestoreRunner instances (real FileWriter over a temp directory, real
 * EntryReader) do the actual reading, writing, and hashing, exactly as
 * production does — only the database half is a {@see FakeDbAdapter}, so
 * these tests run without MySQL. Every archive here carries file entries
 * only; DatabaseWriter's own staging/cut-over is a proven no-op with nothing
 * staged (see its own docblocks), so the fake adapter never needs to do more
 * than answer the handful of housekeeping calls begin_staging() makes.
 */
final class RecoveryCreationLedgerTest extends TestCase {

	/**
	 * Absolute path to the temporary restore root for the current test.
	 *
	 * @var string
	 */
	private string $restore_root = '';

	/**
	 * Reserve a fresh, not-yet-created restore root.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->restore_root = sys_get_temp_dir() . '/pontifex-recovery-ledger-' . bin2hex( random_bytes( 8 ) );
	}

	/**
	 * Remove the restore root recursively, if it exists.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ( is_dir( $this->restore_root ) ) {
			self::rmtree( $this->restore_root );
		}
		parent::tearDown();
	}

	/**
	 * The proof from the brief: a failed import's cleanup leaves a precise revert.
	 *
	 * Mirrors the real wp-env reproduction exactly:
	 *
	 *     BEFORE            original.txt = ORIGINAL CONTENT      intruder absent
	 *     FAILED IMPORT     original.txt = OVERWRITTEN…          intruder PRESENT
	 *     RECOVERY (ok)     original.txt = ORIGINAL CONTENT ✓    intruder GONE ✓
	 *
	 * The forward restore's third entry targets Pontifex's own working
	 * directory — an existing, unrelated FileWriter refusal — purely as a
	 * deterministic way to abort the walk after two real entries have
	 * genuinely landed on disk, without forging a hash or a database failure.
	 *
	 * @return void
	 */
	public function test_recovery_removes_only_what_the_failed_restore_created(): void {
		self::write_fixture_file( $this->restore_root, 'wp-content/original.txt', 'ORIGINAL CONTENT' );

		$forward_runner = $this->build_runner();

		try {
			$forward_runner->restore(
				self::build_archive(
					array(
						self::file_plan( 'wp-content/original.txt', 'OVERWRITTEN CONTENT' ),
						self::file_plan( 'wp-content/intruder.txt', 'INTRUDER CONTENT' ),
						self::file_plan( 'wp-content/pontifex/x', 'poison' ),
					)
				)
			);
			$this->fail( 'The forward restore must fail on its poison-pill entry.' );
		} catch ( InvalidArgumentException $expected_refusal ) {
			unset( $expected_refusal );
		}

		// The proven bug, reproduced: additive replay alone would stop here.
		$this->assertSame(
			'OVERWRITTEN CONTENT',
			self::read_fixture_file( $this->restore_root, 'wp-content/original.txt' ),
			'Sanity check: the failed import really did overwrite the existing file.'
		);
		$this->assertFileExists( $this->restore_root . '/wp-content/intruder.txt', 'Sanity check: the failed import really did create the intruder file.' );

		// Recovery: replay the safety archive through a SEPARATE runner — never the
		// forward writer — exactly as ImportCommand and RestoreController do.
		$safety_archive = self::build_archive( array( self::file_plan( 'wp-content/original.txt', 'ORIGINAL CONTENT' ) ) );
		$this->build_runner()->restore( $safety_archive );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Rewinding a test stream resource, not a filesystem path.
		rewind( $safety_archive );
		$preserved_paths = self::declared_paths( ( new ArchiveReader( $safety_archive ) )->manifest() );

		$report = $forward_runner->remove_created_paths( $preserved_paths );

		$this->assertSame(
			'ORIGINAL CONTENT',
			self::read_fixture_file( $this->restore_root, 'wp-content/original.txt' ),
			'The pre-existing file is back to its original content.'
		);
		$this->assertFileDoesNotExist( $this->restore_root . '/wp-content/intruder.txt', 'The file the failed import created is gone.' );
		$this->assertSame( array( 'wp-content/intruder.txt' ), $report->removed_paths() );
		$this->assertSame( array(), $report->failed_paths() );
		$this->assertTrue( $report->is_precise_revert() );
	}

	/**
	 * A file the live site wrote during the restore (not by the restore) survives cleanup.
	 *
	 * Never having passed through FileWriter::write_entry(), it is simply absent
	 * from the creation ledger — proving rule 1 (only paths THIS run created,
	 * never a set difference against the live filesystem).
	 *
	 * @return void
	 */
	public function test_a_file_the_live_site_wrote_during_the_restore_survives_cleanup(): void {
		$forward_runner = $this->build_runner();

		try {
			$forward_runner->restore(
				self::build_archive(
					array(
						self::file_plan( 'wp-content/intruder.txt', 'INTRUDER CONTENT' ),
						self::file_plan( 'wp-content/pontifex/x', 'poison' ),
					)
				)
			);
			$this->fail( 'The forward restore must fail on its poison-pill entry.' );
		} catch ( InvalidArgumentException $expected_refusal ) {
			unset( $expected_refusal );
		}

		// The live site — not this restore — writes something of its own into the
		// tree, the way an upload, a cache file, or a session file would.
		self::write_fixture_file( $this->restore_root, 'wp-content/uploads/session-abc.tmp', 'live session data' );

		$report = $forward_runner->remove_created_paths( array() );

		$this->assertFileExists( $this->restore_root . '/wp-content/uploads/session-abc.tmp' );
		$this->assertSame( 'live session data', self::read_fixture_file( $this->restore_root, 'wp-content/uploads/session-abc.tmp' ) );
		$this->assertNotContains( 'wp-content/uploads/session-abc.tmp', $report->removed_paths() );
	}

	/**
	 * A path the safety archive also declares is never removed, even though this run created it.
	 *
	 * Proves rule 2 in isolation: the ledger alone is not sufficient reason to
	 * delete something — the caller's preserved-paths set always wins.
	 *
	 * @return void
	 */
	public function test_a_path_the_safety_archive_also_declares_is_never_removed(): void {
		$runner = $this->build_runner();
		$runner->restore( self::build_archive( array( self::file_plan( 'wp-content/shared.txt', 'new content' ) ) ) );

		$this->assertFileExists( $this->restore_root . '/wp-content/shared.txt', 'Sanity check: the file was actually written.' );

		$report = $runner->remove_created_paths( array( 'wp-content/shared.txt' ) );

		$this->assertFileExists( $this->restore_root . '/wp-content/shared.txt', 'A path the safety archive also declares must survive.' );
		$this->assertSame( array(), $report->removed_paths() );
		$this->assertTrue( $report->is_precise_revert(), 'Preserving a path on purpose is not the same as failing to remove one.' );
	}

	/**
	 * A preserved path spelled differently from the ledger's own (but naming the same file) is still honoured.
	 *
	 * The ledger stores a path only AFTER {@see \Pontifex\Restore\FileWriter::write_entry()}
	 * has normalised it — so a plain "wp-content/keep.txt" is what actually sits in the
	 * ledger. A safety archive's manifest entry is never run through that same
	 * normalisation before it reaches {@see \Pontifex\Restore\RestoreRunner::remove_created_paths()},
	 * so "./wp-content/keep.txt" (a leading "./" segment, one of several shapes that
	 * collapse to the same path) must still be recognised as naming the identical file —
	 * a byte-exact comparison would miss it and delete a path the safety archive
	 * genuinely declares, which is precisely what rule 2 exists to forbid.
	 *
	 * @return void
	 */
	public function test_a_differently_spelled_preserved_path_naming_the_same_file_is_honoured(): void {
		$runner = $this->build_runner();
		$runner->restore( self::build_archive( array( self::file_plan( 'wp-content/keep.txt', 'new content' ) ) ) );

		$this->assertFileExists( $this->restore_root . '/wp-content/keep.txt', 'Sanity check: the file was actually written.' );

		// The ledger holds the NORMALISED "wp-content/keep.txt"; this preserved path
		// names the same file with a leading "./" segment that normalisation collapses.
		$report = $runner->remove_created_paths( array( './wp-content/keep.txt' ) );

		$this->assertFileExists( $this->restore_root . '/wp-content/keep.txt', 'A differently-spelled preserved path naming the same file must still be honoured.' );
		$this->assertSame( array(), $report->removed_paths() );
		$this->assertTrue( $report->is_precise_revert() );
	}

	/**
	 * A directory that is not empty by cleanup time is reported, never thrown, and left in place.
	 *
	 * The live site puts a file inside a directory the restore created, so
	 * rmdir() refuses it on its own — proving rule 3 (a directory disappears
	 * only once genuinely empty) and the "reported, not thrown" contract in
	 * the same test.
	 *
	 * @return void
	 */
	public function test_an_undeletable_directory_is_reported_not_thrown(): void {
		$runner = $this->build_runner();
		$runner->restore( self::build_archive( array( self::directory_plan( 'wp-content/uploads/newdir' ) ) ) );

		$this->assertDirectoryExists( $this->restore_root . '/wp-content/uploads/newdir', 'Sanity check: the directory was actually created.' );

		// The live site — not this restore — puts something inside it afterwards.
		self::write_fixture_file( $this->restore_root, 'wp-content/uploads/newdir/still-here.txt', 'kept' );

		$report = $runner->remove_created_paths( array() );

		$this->assertDirectoryExists( $this->restore_root . '/wp-content/uploads/newdir', 'A non-empty directory must survive.' );
		$this->assertFileExists( $this->restore_root . '/wp-content/uploads/newdir/still-here.txt' );
		$this->assertSame( array( 'wp-content/uploads/newdir' ), $report->failed_paths() );
		$this->assertSame( array(), $report->removed_paths() );
		$this->assertFalse( $report->is_precise_revert() );
	}

	/**
	 * A successful restore never calls the cleanup at all, so it deletes nothing.
	 *
	 * The ledger is a purely internal bookkeeping detail of a SUCCESSFUL run;
	 * this proves adding it changed no write behaviour, by restoring both an
	 * overwrite and a brand-new file and finding both exactly as the archive
	 * declared once the restore has finished — nothing this test does calls
	 * remove_created_paths() at all, matching production, where only a failed
	 * import's recovery ever does.
	 *
	 * @return void
	 */
	public function test_a_successful_restore_deletes_nothing(): void {
		self::write_fixture_file( $this->restore_root, 'wp-content/original.txt', 'ORIGINAL CONTENT' );

		$runner = $this->build_runner();
		$runner->restore(
			self::build_archive(
				array(
					self::file_plan( 'wp-content/original.txt', 'OVERWRITTEN CONTENT' ),
					self::file_plan( 'wp-content/brand-new.txt', 'BRAND NEW CONTENT' ),
				)
			)
		);

		$this->assertSame( 'OVERWRITTEN CONTENT', self::read_fixture_file( $this->restore_root, 'wp-content/original.txt' ) );
		$this->assertSame( 'BRAND NEW CONTENT', self::read_fixture_file( $this->restore_root, 'wp-content/brand-new.txt' ) );
	}

	/**
	 * A capped ledger reports a merge, never a precise revert — even when nothing failed.
	 *
	 * Driven entirely through reflection: reaching CREATION_LEDGER_CAP for real
	 * would mean tens of thousands of real filesystem writes for a fact that is
	 * pure bookkeeping (record_created_path() touches no disk itself). Calling
	 * the private method directly proves the SAME cap logic production runs,
	 * without paying for the I/O a full-scale restore would need to trigger it.
	 *
	 * @return void
	 */
	public function test_a_capped_ledger_reports_a_merge_not_a_precise_revert(): void {
		$writer = new FileWriter( $this->restore_root );

		$cap = (int) ( new ReflectionClassConstant( FileWriter::class, 'CREATION_LEDGER_CAP' ) )->getValue();

		$record = new ReflectionMethod( FileWriter::class, 'record_created_path' );
		for ( $i = 0; $i <= $cap; $i++ ) {
			$record->invoke( $writer, "wp-content/generated-{$i}.txt", 'file' );
		}

		$incomplete = new ReflectionProperty( FileWriter::class, 'creation_ledger_incomplete' );
		$this->assertTrue( (bool) $incomplete->getValue( $writer ), 'The ledger must mark itself incomplete once the cap is reached.' );

		$created = new ReflectionProperty( FileWriter::class, 'created_paths' );
		$this->assertCount( $cap, (array) $created->getValue( $writer ), 'Recording must stop exactly at the cap, not merely somewhere past it.' );

		$report = $writer->remove_created_paths( array() );
		$this->assertFalse( $report->ledger_was_complete() );
		$this->assertFalse( $report->is_precise_revert(), 'A capped ledger can never honestly claim a precise revert, whatever else happened.' );
	}

	// -------------------------------------------------------------------------
	// Helpers.
	// -------------------------------------------------------------------------

	/**
	 * Build a RestoreRunner rooted at this test's restore root, with a fake DB half.
	 *
	 * @return RestoreRunner
	 */
	private function build_runner(): RestoreRunner {
		return new RestoreRunner(
			new EntryReader( CodecRegistry::with_defaults() ),
			new FileWriter( $this->restore_root ),
			new DatabaseWriter( new FakeDbAdapter() )
		);
	}

	/**
	 * Every file/directory/symlink path an already-decoded manifest declares.
	 *
	 * Mirrors exactly what ImportCommand's and RestoreController's own recovery
	 * handlers do with the replayed safety archive's manifest, so this test
	 * proves the real extraction shape, not a hand-typed stand-in for it.
	 *
	 * @param ArchiveManifest $manifest The archive's already-decoded manifest.
	 * @return array<int, string>
	 */
	private static function declared_paths( ArchiveManifest $manifest ): array {
		$paths = array();
		foreach ( $manifest->entries() as $entry ) {
			if ( ! $entry->is_file() && ! $entry->is_directory() && ! $entry->is_symlink() ) {
				continue;
			}
			$path = $entry->path();
			if ( null !== $path ) {
				$paths[] = $path;
			}
		}
		return $paths;
	}

	/**
	 * Write a fixture file under an arbitrary root, creating parent directories.
	 *
	 * @param string $root     Absolute root directory.
	 * @param string $relative Relative path beneath the root.
	 * @param string $contents File contents.
	 * @return void
	 */
	private static function write_fixture_file( string $root, string $relative, string $contents ): void {
		$absolute = $root . '/' . $relative;
		$dir      = dirname( $absolute );
		if ( ! is_dir( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating a test fixture directory.
			mkdir( $dir, 0o755, true );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Seeding a test fixture file.
		file_put_contents( $absolute, $contents );
	}

	/**
	 * Read a fixture file's contents back for assertion.
	 *
	 * @param string $root     Absolute root directory.
	 * @param string $relative Relative path beneath the root.
	 * @return string The file's contents.
	 */
	private static function read_fixture_file( string $root, string $relative ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a test fixture file for assertion.
		return (string) file_get_contents( $root . '/' . $relative );
	}

	/**
	 * Recursively delete a directory tree.
	 *
	 * @param string $path Absolute path to remove.
	 * @return void
	 */
	private static function rmtree( string $path ): void {
		if ( is_link( $path ) || ! is_dir( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Test fixture teardown.
			@unlink( $path );
			return;
		}
		foreach ( scandir( $path ) as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				self::rmtree( $path . '/' . $entry );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.PHP.NoSilencedErrors.Discouraged -- Test fixture teardown.
		@rmdir( $path );
	}

	/**
	 * Open a php://memory stream, optionally pre-filled and rewound.
	 *
	 * @param string $contents Optional initial contents.
	 * @return resource A readable, seekable in-memory stream.
	 * @throws RuntimeException If php://memory cannot be opened.
	 */
	private static function memory_stream( string $contents = '' ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://memory is an in-process buffer, not a file.
		$stream = fopen( 'php://memory', 'r+b' );
		if ( false === $stream ) {
			throw new RuntimeException( 'Could not open php://memory.' );
		}
		if ( '' !== $contents ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Operating on a test stream resource, not a filesystem path.
			fwrite( $stream, $contents );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource, not a filesystem path.
			rewind( $stream );
		}
		return $stream;
	}

	/**
	 * Build a file EntryPlan.
	 *
	 * @param string $path     Relative path inside the archive.
	 * @param string $contents File contents.
	 * @return EntryPlan
	 */
	private static function file_plan( string $path, string $contents ): EntryPlan {
		$header = EntryHeader::for_file( $path, strlen( $contents ), 0o644, 1690000000, 'application/octet-stream', 0 );
		return new EntryPlan( $header, RawCodec::ID, str_repeat( "\0", EntryWriter::NONCE_SIZE ), self::memory_stream( $contents ) );
	}

	/**
	 * Build a directory EntryPlan.
	 *
	 * @param string $path Relative path inside the archive.
	 * @return EntryPlan
	 */
	private static function directory_plan( string $path ): EntryPlan {
		$header = EntryHeader::for_directory( $path, 0o755, 0 );
		return new EntryPlan( $header, RawCodec::ID, str_repeat( "\0", EntryWriter::NONCE_SIZE ), self::memory_stream() );
	}

	/**
	 * Write the given plans to an in-memory archive; return a rewound stream.
	 *
	 * @param EntryPlan[] $plans The plans to include.
	 * @return resource
	 */
	private static function build_archive( array $plans ) {
		$provenance = new Provenance(
			'6.6.1',
			'8.2.10',
			'https://example.test',
			'utf8mb4',
			'utf8mb4_unicode_520_ci',
			new ExporterInfo( 'pontifex', '1.1.0' ),
			new DateTimeImmutable( '2026-08-06T10:00:00+00:00', new DateTimeZone( 'UTC' ) )
		);
		$dest       = self::memory_stream();
		( new ArchiveWriter( new EntryWriter( CodecRegistry::with_defaults() ), new FooterWriter() ) )->write_archive( $provenance, $plans, $dest );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource, not a filesystem path.
		rewind( $dest );
		return $dest;
	}
}
