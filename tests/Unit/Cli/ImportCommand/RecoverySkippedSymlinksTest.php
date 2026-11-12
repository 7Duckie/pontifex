<?php
/**
 * Tests for ImportCommand's recovery outcome when a safety-archive replay skips a symlink.
 *
 * @package Pontifex\Tests\Unit\Cli\ImportCommand
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Cli\ImportCommand;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;
use ReflectionMethod;
use RuntimeException;
use Pontifex\Archive\Codec\CodecRegistry;
use Pontifex\Archive\Codec\RawCodec;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Format\ExporterInfo;
use Pontifex\Archive\Format\Provenance;
use Pontifex\Archive\Writer\ArchiveWriter;
use Pontifex\Archive\Writer\EntryPlan;
use Pontifex\Archive\Writer\EntryWriter;
use Pontifex\Archive\Writer\FooterWriter;
use Pontifex\Cli\ImportCommand;
use Pontifex\Restore\DatabaseWriter;
use Pontifex\Restore\FileWriter;
use Pontifex\Restore\RestoreRunner;
use Pontifex\Archive\Reader\EntryReader;
use Pontifex\Tests\Unit\Manifest\Fakes\FakeDbAdapter;
use Psr\Log\NullLogger;

/**
 * Proves ImportCommand's own `recover_from_safety_archive()` downgrades an
 * otherwise-precise revert when the replay skips an absolute-target symlink,
 * and only then — never when nothing was skipped — and that it says WHICH
 * downgrade it was: RECOVERY_MERGE_LINKS when the file cleanup was provably
 * complete and the link is the only gap, RECOVERY_MERGE when the cleanup could
 * not be confirmed. It also proves the out-parameter hands the caller the
 * skipped links themselves, so the terminal can name them.
 *
 * Exercised directly via reflection on the private method, with two REAL
 * {@see RestoreRunner} instances (real FileWriter, real EntryReader; a
 * {@see FakeDbAdapter} for the database half, exactly as
 * {@see \Pontifex\Tests\Unit\Restore\RecoveryCreationLedgerTest} does) rather
 * than through the full `__invoke()` ajax-style flow: injecting the REAL
 * recovery runner via the constructor's $restore_runner parameter bypasses
 * `build_default_restore_runner()` entirely, so no WordPress wiring is needed,
 * while still exercising the genuine FileWriter/RestoreRunner collaboration
 * `recover_from_safety_archive()` depends on. The forward runner is a second,
 * untouched RestoreRunner whose creation ledger is trivially empty, so its own
 * cleanup is always a precise revert — isolating the outcome under test to the
 * one fact that changes between those tests: whether a symlink was skipped. The
 * one test that needs an IMPRECISE cleanup builds it from public behaviour only:
 * its forward runner restores a directory, and the test then puts a file inside
 * it, which the cleanup cannot remove because the directory is no longer empty.
 */
final class RecoverySkippedSymlinksTest extends TestCase {

	/**
	 * Absolute path to a fresh temp file to use as the safety archive, per test.
	 *
	 * @var string
	 */
	private string $safety_path = '';

	/**
	 * Absolute paths to the temp restore roots created by the current test, for teardown.
	 *
	 * @var array<int, string>
	 */
	private array $restore_roots = array();

	/**
	 * Reserve a unique safety-archive path before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->safety_path   = sys_get_temp_dir() . '/pontifex-import-recovery-safety-' . bin2hex( random_bytes( 8 ) ) . '.wpmig';
		$this->restore_roots = array();
	}

	/**
	 * Remove the safety archive and every restore root this test created.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Test fixture teardown.
		@unlink( $this->safety_path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Test fixture teardown.
		@unlink( $this->safety_path . '.forward' );
		foreach ( $this->restore_roots as $root ) {
			self::rmtree( $root );
		}
		parent::tearDown();
	}

	/**
	 * A skipped absolute-target symlink on an otherwise-clean recovery reports the links outcome.
	 *
	 * The forward runner here has written nothing, so its file cleanup is provably
	 * complete: the skipped link is the ONLY thing standing between this recovery
	 * and a precise revert. That outcome used to be RECOVERY_MERGE, whose message
	 * asserts a cleanup that "could not be confirmed complete" — false here, and it
	 * sent the operator to the log looking for stray paths that never existed. The
	 * expectation changed to RECOVERY_MERGE_LINKS deliberately, with that message
	 * split; it is not a test bent to fit the code.
	 *
	 * @return void
	 */
	public function test_recovery_reports_merge_links_when_only_a_symlink_is_skipped(): void {
		self::write_archive_to(
			$this->safety_path,
			array( self::symlink_plan( 'wp-content/uploads/media', '/mnt/shared/media' ) )
		);

		$outcome = $this->invoke_recovery();

		$this->assertSame( self::recovery_merge_links(), $outcome, 'A skipped symlink on a recovery whose cleanup was complete must be reported as the links outcome, never a precise revert and never the unconfirmed-cleanup merge.' );
	}

	/**
	 * A replay that skips nothing still reports a precise revert (false-positive guard).
	 *
	 * @return void
	 */
	public function test_recovery_still_reports_precise_when_nothing_is_skipped(): void {
		self::write_archive_to(
			$this->safety_path,
			array( self::file_plan( 'wp-content/original.txt', 'ORIGINAL CONTENT' ) )
		);

		$outcome = $this->invoke_recovery();

		$this->assertSame( self::recovery_precise(), $outcome, 'A clean replay that skipped nothing must not be downgraded to a merge.' );
	}

	/**
	 * The out-parameter carries the skipped link's path and its raw recorded target.
	 *
	 * This is what lets the caller name the link in the terminal. Both halves
	 * matter, so the whole array is compared: the key is the entry's path inside the
	 * site, the value is the target exactly as the archive recorded it.
	 *
	 * @return void
	 */
	public function test_the_out_parameter_carries_the_skipped_links_path_and_raw_target(): void {
		self::write_archive_to(
			$this->safety_path,
			array( self::symlink_plan( 'wp-content/uploads/media', '/mnt/shared/media' ) )
		);

		$skipped = array();
		$this->invoke_recovery( null, $skipped );

		$this->assertSame( array( 'wp-content/uploads/media' => '/mnt/shared/media' ), $skipped );
	}

	/**
	 * The out-parameter is empty after a clean replay, even if the caller handed in a stale value.
	 *
	 * @return void
	 */
	public function test_the_out_parameter_is_empty_after_a_clean_replay(): void {
		self::write_archive_to(
			$this->safety_path,
			array( self::file_plan( 'wp-content/original.txt', 'ORIGINAL CONTENT' ) )
		);

		$skipped = array( 'wp-content/stale-link' => '/left/over/from/an/earlier/call' );
		$this->invoke_recovery( null, $skipped );

		$this->assertSame( array(), $skipped );
	}

	/**
	 * The out-parameter is reset, not left stale, when recovery fails before the replay even starts.
	 *
	 * The safety archive is never written, so it cannot be opened and recovery
	 * returns RECOVERY_FAILED at its first step. A caller reusing a variable must
	 * not be handed the previous call's links as though they described this one.
	 *
	 * @return void
	 */
	public function test_the_out_parameter_is_reset_when_recovery_fails_before_the_replay(): void {
		$skipped = array( 'wp-content/stale-link' => '/left/over/from/an/earlier/call' );
		$outcome = $this->invoke_recovery( null, $skipped );

		$this->assertSame( self::recovery_failed(), $outcome, 'Sanity check: an unopenable safety archive must fail recovery outright.' );
		$this->assertSame( array(), $skipped );
	}

	/**
	 * An unconfirmed cleanup reports the plain merge, not the links outcome, even when a link was also skipped.
	 *
	 * Both causes apply here, and the cleanup one is the one whose message sends
	 * the operator to the log for stray paths — so it must win, because it is true.
	 * The link must still reach the caller through the out-parameter, so the
	 * terminal names it too.
	 *
	 * @return void
	 */
	public function test_an_unconfirmed_cleanup_reports_merge_even_when_a_link_was_also_skipped(): void {
		self::write_archive_to(
			$this->safety_path,
			array( self::symlink_plan( 'wp-content/uploads/media', '/mnt/shared/media' ) )
		);

		$skipped = array();
		$outcome = $this->invoke_recovery( $this->forward_runner_with_an_undeletable_directory(), $skipped );

		$this->assertSame( self::recovery_merge(), $outcome, 'A cleanup that could not be confirmed must report the plain merge, whatever else was skipped.' );
		$this->assertSame( array( 'wp-content/uploads/media' => '/mnt/shared/media' ), $skipped, 'The skipped link must still reach the caller.' );
	}

	// -------------------------------------------------------------------------
	// Helpers.
	// -------------------------------------------------------------------------

	/**
	 * Build an ImportCommand wired with a real recovery runner, and invoke recover_from_safety_archive() on it.
	 *
	 * The recovery runner's FileWriter is built with $replaying_safety_archive
	 * true — matching production's own `build_default_restore_runner( ..., true )`
	 * call for this exact leg — and injected via the constructor so
	 * `build_default_restore_runner()` itself is never reached, needing no
	 * WordPress wiring. The forward runner is a second, freshly built
	 * RestoreRunner that has written nothing, so `remove_created_paths()` on it
	 * is always a precise revert on its own — isolating the outcome to whether
	 * the replay skipped a symlink.
	 *
	 * @param RestoreRunner|null    $forward_runner   The forward runner to consult for the file cleanup, or null for a freshly built one that has written nothing.
	 * @param array<string, string> $skipped_symlinks Filled in by reference with the links the replay skipped, exactly as the caller of the real method receives them.
	 * @return string One of ImportCommand's own RECOVERY_* constants.
	 */
	private function invoke_recovery( ?RestoreRunner $forward_runner = null, array &$skipped_symlinks = array() ): string {
		$recovery_runner  = new RestoreRunner(
			new EntryReader( CodecRegistry::with_defaults() ),
			new FileWriter( $this->fresh_restore_root(), false, null, null, null, null, true ),
			new DatabaseWriter( new FakeDbAdapter() )
		);
		$forward_runner ??= new RestoreRunner(
			new EntryReader( CodecRegistry::with_defaults() ),
			new FileWriter( $this->fresh_restore_root() ),
			new DatabaseWriter( new FakeDbAdapter() )
		);

		$command = new ImportCommand( null, null, $recovery_runner, new NullLogger() );

		// invokeArgs() with a reference in the array is how a by-reference parameter
		// of a private method is driven through reflection.
		$method = new ReflectionMethod( ImportCommand::class, 'recover_from_safety_archive' );
		$args   = array( $this->safety_path, false, $forward_runner, &$skipped_symlinks );
		return (string) $method->invokeArgs( $command, $args );
	}

	/**
	 * A forward runner whose file cleanup cannot be confirmed complete, built from public behaviour only.
	 *
	 * It really restores a directory into its own root, then the test puts a file
	 * inside that directory — standing in for something the live site wrote there
	 * while the restore ran. The directory is in the runner's creation ledger, so
	 * cleanup tries to remove it, but it is no longer empty, so the removal fails
	 * and is reported as such. No production seam is involved.
	 *
	 * @return RestoreRunner
	 * @throws RuntimeException If the forward archive cannot be opened for reading.
	 */
	private function forward_runner_with_an_undeletable_directory(): RestoreRunner {
		$root   = $this->fresh_restore_root();
		$runner = new RestoreRunner(
			new EntryReader( CodecRegistry::with_defaults() ),
			new FileWriter( $root ),
			new DatabaseWriter( new FakeDbAdapter() )
		);

		$forward_path = $this->safety_path . '.forward';
		self::write_archive_to( $forward_path, array( self::directory_plan( 'wp-content/uploads/newdir' ) ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Reading back the fixture archive just written.
		$forward = fopen( $forward_path, 'rb' );
		if ( false === $forward ) {
			throw new RuntimeException( 'Could not open the fixture forward archive for reading.' );
		}
		$runner->restore( $forward );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the fixture archive just read.
		fclose( $forward );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Seeding a test fixture file.
		file_put_contents( $root . '/wp-content/uploads/newdir/still-here.txt', 'written by the live site' );

		return $runner;
	}

	/**
	 * ImportCommand::RECOVERY_MERGE, read via reflection (a private constant).
	 *
	 * @return string
	 */
	private static function recovery_merge(): string {
		return (string) ( new ReflectionClassConstant( ImportCommand::class, 'RECOVERY_MERGE' ) )->getValue();
	}

	/**
	 * ImportCommand::RECOVERY_PRECISE, read via reflection (a private constant).
	 *
	 * @return string
	 */
	private static function recovery_precise(): string {
		return (string) ( new ReflectionClassConstant( ImportCommand::class, 'RECOVERY_PRECISE' ) )->getValue();
	}

	/**
	 * ImportCommand::RECOVERY_MERGE_LINKS, read via reflection (a private constant).
	 *
	 * @return string
	 */
	private static function recovery_merge_links(): string {
		return (string) ( new ReflectionClassConstant( ImportCommand::class, 'RECOVERY_MERGE_LINKS' ) )->getValue();
	}

	/**
	 * ImportCommand::RECOVERY_FAILED, read via reflection (a private constant).
	 *
	 * @return string
	 */
	private static function recovery_failed(): string {
		return (string) ( new ReflectionClassConstant( ImportCommand::class, 'RECOVERY_FAILED' ) )->getValue();
	}

	/**
	 * Reserve and record a fresh, not-yet-created restore root for teardown.
	 *
	 * @return string
	 */
	private function fresh_restore_root(): string {
		$root                  = sys_get_temp_dir() . '/pontifex-import-recovery-root-' . bin2hex( random_bytes( 8 ) );
		$this->restore_roots[] = $root;
		return $root;
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
	 * Build a symlink EntryPlan.
	 *
	 * @param string $path   Relative path of the link inside the archive.
	 * @param string $target The target string recorded verbatim.
	 * @return EntryPlan
	 */
	private static function symlink_plan( string $path, string $target ): EntryPlan {
		$header = EntryHeader::for_symlink( $path, $target, 0 );
		return new EntryPlan( $header, RawCodec::ID, str_repeat( "\0", EntryWriter::NONCE_SIZE ), self::memory_stream() );
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
	 * Open a php://memory stream, optionally pre-filled and rewound.
	 *
	 * @param string $contents Optional initial contents.
	 * @return resource
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
	 * Write the given plans as a real, content-only, unencrypted archive to $path.
	 *
	 * @param string      $path  Absolute destination path.
	 * @param EntryPlan[] $plans The plans to include.
	 * @return void
	 * @throws RuntimeException If the destination cannot be opened for writing.
	 */
	private static function write_archive_to( string $path, array $plans ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Writing a real temp fixture archive.
		$dest = fopen( $path, 'w+b' );
		if ( false === $dest ) {
			throw new RuntimeException( 'Could not open the fixture safety archive for writing.' );
		}
		$provenance = new Provenance(
			'6.6.1',
			'8.2.10',
			'https://example.test',
			'utf8mb4',
			'utf8mb4_unicode_520_ci',
			new ExporterInfo( 'pontifex', '1.1.0' ),
			new DateTimeImmutable( '2026-09-03T10:00:00+00:00', new DateTimeZone( 'UTC' ) )
		);
		( new ArchiveWriter( new EntryWriter( CodecRegistry::with_defaults() ), new FooterWriter() ) )->write_archive( $provenance, $plans, $dest );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the fixture archive just written.
		fclose( $dest );
	}

	/**
	 * Recursively delete a directory tree, if it exists.
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
}
