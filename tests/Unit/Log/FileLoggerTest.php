<?php
/**
 * Behavioural tests for the FileLogger class.
 *
 * @package Pontifex\Tests\Unit\Log
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Log;

use PHPUnit\Framework\TestCase;
use Pontifex\Log\FileLogger;
use RuntimeException;

/**
 * Exercises FileLogger against a real temporary directory.
 *
 * FileLogger has no WordPress coupling — it takes a directory path
 * and a boolean — so these are plain unit tests with no brain/monkey
 * and no WordPress bootstrap. Each test works inside its own unique
 * directory under the system temp path, removed in tear-down, so the
 * tests neither collide nor leave anything behind.
 *
 * The class is final and is tested directly rather than mocked; the
 * seam callers depend on is the PSR-3 LoggerInterface, not this
 * concrete class.
 */
final class FileLoggerTest extends TestCase {

	/**
	 * Unique temporary directory for the test in progress.
	 *
	 * @var string
	 */
	private string $temp_dir;

	/**
	 * Create a unique, empty working directory for the test.
	 *
	 * The directory itself is not pre-created; several tests need to
	 * prove FileLogger creates it, so only the unique path is reserved.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->temp_dir = sys_get_temp_dir() . '/pontifex-logger-' . uniqid( '', true );
	}

	/**
	 * Recursively remove anything the test created.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$this->remove_tree( $this->temp_dir );
		parent::tearDown();
	}

	/**
	 * A logged message lands in the file with its level.
	 *
	 * @return void
	 */
	public function test_writes_a_line_with_message_and_level(): void {
		$logger = new FileLogger( $this->temp_dir, false );

		$logger->info( 'Export started.' );

		$body = $this->read_log();
		$this->assertStringContainsString( 'Export started.', $body );
		$this->assertStringContainsString( ' INFO: ', $body );
	}

	/**
	 * A custom filename writes to that file, leaving the default name untouched.
	 *
	 * @return void
	 */
	public function test_custom_filename_writes_to_that_file(): void {
		$logger = new FileLogger( $this->temp_dir, false, 'site.wpmig.log' );

		$logger->info( 'Export started.' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading back a fixture log file the test wrote in the system temp path.
		$body = (string) file_get_contents( $this->temp_dir . '/site.wpmig.log' );
		$this->assertStringContainsString( 'Export started.', $body );
		$this->assertFileDoesNotExist( $this->temp_dir . '/pontifex.log' );
	}

	/**
	 * A non-empty context array is appended as compact JSON.
	 *
	 * @return void
	 */
	public function test_appends_context_as_json(): void {
		$logger = new FileLogger( $this->temp_dir, false );

		$logger->info(
			'Export complete.',
			array(
				'entries' => 12,
				'bytes'   => 2048,
			)
		);

		$this->assertStringContainsString( '{"entries":12,"bytes":2048}', $this->read_log() );
	}

	/**
	 * The timestamp is a UTC ISO-8601 stamp in brackets.
	 *
	 * @return void
	 */
	public function test_timestamp_is_utc_iso8601(): void {
		$logger = new FileLogger( $this->temp_dir, false );

		$logger->info( 'tick' );

		$this->assertMatchesRegularExpression(
			'/^\[\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00\] /',
			$this->read_log()
		);
	}

	/**
	 * With debug disabled, debug lines are dropped but info is kept.
	 *
	 * @return void
	 */
	public function test_debug_dropped_but_info_kept_when_debug_disabled(): void {
		$logger = new FileLogger( $this->temp_dir, false );

		$logger->debug( 'a debug breadcrumb' );
		$logger->info( 'an info line' );

		$body = $this->read_log();
		$this->assertStringNotContainsString( 'debug breadcrumb', $body );
		$this->assertStringContainsString( 'an info line', $body );
	}

	/**
	 * With debug enabled, debug lines are written.
	 *
	 * @return void
	 */
	public function test_debug_kept_when_debug_enabled(): void {
		$logger = new FileLogger( $this->temp_dir, true );

		$logger->debug( 'a debug breadcrumb' );

		$this->assertStringContainsString( 'debug breadcrumb', $this->read_log() );
	}

	/**
	 * An 'exception' context value renders as class and message, not a JSON dump.
	 *
	 * @return void
	 */
	public function test_exception_context_rendered_as_class_and_message(): void {
		$logger = new FileLogger( $this->temp_dir, false );

		$logger->error( 'Export failed.', array( 'exception' => new RuntimeException( 'disk full' ) ) );

		$body = $this->read_log();
		$this->assertStringContainsString( 'RuntimeException: disk full', $body );
		$this->assertStringNotContainsString( '{"exception"', $body );
	}

	/**
	 * PSR-3 {placeholder} tokens are interpolated from context.
	 *
	 * @return void
	 */
	public function test_interpolates_placeholders(): void {
		$logger = new FileLogger( $this->temp_dir, false );

		$logger->info( 'Wrote {count} entries', array( 'count' => 42 ) );

		$this->assertStringContainsString( 'Wrote 42 entries', $this->read_log() );
	}

	/**
	 * A missing (and nested) log directory is created on first write.
	 *
	 * @return void
	 */
	public function test_creates_missing_directory(): void {
		$nested = $this->temp_dir . '/nested/deeper';
		$logger = new FileLogger( $nested, false );

		$logger->info( 'made the path' );

		$this->assertDirectoryExists( $nested );
		$this->assertFileExists( $nested . '/pontifex.log' );
	}

	/**
	 * An unusable path disables the logger silently — no throw, no file.
	 *
	 * The parent of the target directory is a regular file, so the
	 * directory can never be created. The logger must absorb this.
	 *
	 * @return void
	 */
	public function test_never_throws_when_path_is_unusable(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture: create a file to block directory creation beneath it.
		file_put_contents( $this->temp_dir, 'blocker' );
		$unusable = $this->temp_dir . '/cannot-exist';

		$logger = new FileLogger( $unusable, false );
		$logger->error( 'this must not throw' );

		$this->assertDirectoryDoesNotExist( $unusable );
	}

	/**
	 * A live log past the size cap rotates to .1 and starts fresh.
	 *
	 * @return void
	 */
	public function test_rotates_when_over_size(): void {
		$this->make_dir( $this->temp_dir );
		$live = $this->temp_dir . '/pontifex.log';
		$this->put( $live, str_repeat( 'x', ( 2 * 1024 * 1024 ) + 10 ) );

		$logger = new FileLogger( $this->temp_dir, false );
		$logger->info( 'first line after rotation' );

		$this->assertFileExists( $this->temp_dir . '/pontifex.log.1' );
		$this->assertLessThan( 1024, (int) filesize( $live ) );
	}

	/**
	 * Rotation keeps at most four backups; the oldest is dropped.
	 *
	 * @return void
	 */
	public function test_keeps_at_most_four_backups(): void {
		$this->make_dir( $this->temp_dir );
		$base = $this->temp_dir . '/pontifex.log';
		$this->put( $base, str_repeat( 'L', ( 2 * 1024 * 1024 ) + 10 ) );
		$this->put( $base . '.1', 'one' );
		$this->put( $base . '.2', 'two' );
		$this->put( $base . '.3', 'three' );
		$this->put( $base . '.4', 'four-oldest' );

		$logger = new FileLogger( $this->temp_dir, false );
		$logger->info( 'trigger rotation' );

		$this->assertFileDoesNotExist( $base . '.5' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a fixture backup file the test wrote in the system temp path.
		$this->assertSame( 'three', file_get_contents( $base . '.4' ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a fixture backup file the test wrote in the system temp path.
		$this->assertStringStartsWith( 'LLL', (string) file_get_contents( $base . '.1' ) );
	}

	// -------------------------------------------------------------------------
	// Test helpers.
	// -------------------------------------------------------------------------

	/**
	 * Read the live log file's contents.
	 *
	 * @return string
	 */
	private function read_log(): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading back a fixture log file the test wrote in the system temp path.
		return (string) file_get_contents( $this->temp_dir . '/pontifex.log' );
	}

	/**
	 * Create a directory for fixture setup.
	 *
	 * @param string $path The directory to create.
	 * @return void
	 */
	private function make_dir( string $path ): void {
		if ( ! is_dir( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture directory in the system temp path.
			mkdir( $path, 0755, true );
		}
	}

	/**
	 * Write fixture bytes to a path.
	 *
	 * @param string $path  The file path.
	 * @param string $bytes The contents.
	 * @return void
	 */
	private function put( string $path, string $bytes ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture write in the system temp path.
		file_put_contents( $path, $bytes );
	}

	/**
	 * Recursively delete a file or directory tree.
	 *
	 * @param string $path The path to remove.
	 * @return void
	 */
	private function remove_tree( string $path ): void {
		if ( is_file( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test cleanup of a fixture the test created in the system temp path.
			unlink( $path );
			return;
		}

		if ( ! is_dir( $path ) ) {
			return;
		}

		$entries = (array) scandir( $path );
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$this->remove_tree( $path . '/' . $entry );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test cleanup of a fixture directory in the system temp path.
		rmdir( $path );
	}
}
