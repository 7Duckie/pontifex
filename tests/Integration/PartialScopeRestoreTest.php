<?php
/**
 * Partial-scope (files-only / db-only) restore integration test.
 *
 * @package Pontifex\Tests\Integration
 */

declare(strict_types=1);

namespace Pontifex\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use Pontifex\Archive\Codec\CodecRegistry;
use Pontifex\Archive\Codec\RawCodec;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Format\ExporterInfo;
use Pontifex\Archive\Format\Provenance;
use Pontifex\Archive\Format\Scope;
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
 * Proves a partial backup restores as a merge and lies are refused (ADR 0016).
 *
 * A files-only archive must restore its files and leave the live database
 * untouched; a db-only archive must restore its tables and leave existing
 * files untouched — restore is additive/overwrite-only, so the absent half of
 * a partial archive is never deleted. And an archive whose recorded scope
 * contradicts its contents (a files-only scope carrying database chunks) must
 * be refused rather than restored.
 *
 * Non-destructive: files round-trip into a temporary restore root, and the
 * scratch table is the test's own, dropped in tear_down.
 */
final class PartialScopeRestoreTest extends TestCase {

	/**
	 * Absolute path to the temporary restore root for the current test.
	 *
	 * @var string
	 */
	private string $restore_root = '';

	/**
	 * Fully-prefixed name of the scratch table the test round-trips.
	 *
	 * @var string
	 */
	private string $scratch_table = '';

	/**
	 * Seed a scratch table and reserve a restore root.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		global $wpdb;

		$suffix              = bin2hex( random_bytes( 8 ) );
		$this->restore_root  = sys_get_temp_dir() . '/pontifex-partial-' . $suffix;
		$this->scratch_table = $wpdb->prefix . 'pontifex_partial';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test: drop any leftover scratch table.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $this->scratch_table ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test: create the isolated scratch table.
		$wpdb->query( $wpdb->prepare( 'CREATE TABLE %i ( id INT PRIMARY KEY, label VARCHAR(255) ) DEFAULT CHARSET=utf8mb4', $this->scratch_table ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test: seed the scratch table.
		$wpdb->query( $wpdb->prepare( 'INSERT INTO %i ( id, label ) VALUES ( %d, %s )', $this->scratch_table, 1, 'original' ) );
	}

	/**
	 * Drop the scratch table and remove the restore root.
	 *
	 * @return void
	 */
	protected function tear_down(): void {
		global $wpdb;

		if ( '' !== $this->scratch_table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test cleanup.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $this->scratch_table ) );
		}
		if ( '' !== $this->restore_root && is_dir( $this->restore_root ) ) {
			self::rmtree( $this->restore_root );
		}

		parent::tear_down();
	}

	/**
	 * A files-only archive restores its files and leaves the database untouched.
	 *
	 * @return void
	 */
	public function test_files_only_restore_leaves_the_database_untouched(): void {
		global $wpdb;

		$adapter     = new WpdbAdapter( $wpdb );
		$before_rows = $adapter->dump_table_rows( $this->scratch_table, 0, 100 );

		$plans   = array( self::file_plan( 'wp-content/uploads/note.txt', "files-only content\n" ) );
		$archive = self::build_partial_archive( $plans, Scope::files_only( array() ) );

		$this->runner()->restore( $archive );

		$restored = $this->restore_root . '/wp-content/uploads/note.txt';
		$this->assertFileExists( $restored, 'The files-only archive restored its file.' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a restored fixture file for assertion.
		$this->assertSame( "files-only content\n", file_get_contents( $restored ) );

		// The database half was absent, so it must be exactly as it was.
		$this->assertSame( 1, $adapter->row_count( $this->scratch_table ), 'A files-only restore must not touch the database.' );
		$this->assertSame( $before_rows, $adapter->dump_table_rows( $this->scratch_table, 0, 100 ), 'The live table is byte-identical after a files-only restore.' );
	}

	/**
	 * A db-only archive restores its tables and leaves existing files untouched.
	 *
	 * @return void
	 */
	public function test_db_only_restore_leaves_files_untouched(): void {
		global $wpdb;

		// Seed a file that must survive a db-only restore untouched.
		$this->write_file( 'wp-content/uploads/keep.txt', "must survive\n" );

		$adapter = new WpdbAdapter( $wpdb );
		// Change the live row so the archive's replay is observable.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test: mutate before restore to prove the replay.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET label = %s WHERE id = %d', $this->scratch_table, 'changed', 1 ) );

		$sql   = $adapter->dump_table_schema( $this->scratch_table ) . $adapter->dump_table_rows( $this->scratch_table, 0, 100 );
		$plans = array( self::db_chunk_plan( $this->scratch_table, substr_count( $sql, ";\n" ), $sql ) );
		// Re-dump AFTER building the chunk so the archive carries the 'changed' row;
		// then set the live row back so the restore visibly re-applies 'changed'.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test: reset the live row before restore.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET label = %s WHERE id = %d', $this->scratch_table, 'original', 1 ) );

		$archive = self::build_partial_archive( $plans, Scope::db_only( array() ) );
		$this->runner()->restore( $archive );

		// The database half was restored: the row is back to 'changed'.
		$rows = $adapter->dump_table_rows( $this->scratch_table, 0, 100 );
		$this->assertStringContainsString( 'changed', $rows, 'A db-only restore replayed the archived table.' );

		// The file half was absent, so the pre-existing file must survive untouched.
		$kept = $this->restore_root . '/wp-content/uploads/keep.txt';
		$this->assertFileExists( $kept, 'A db-only restore must not delete existing files.' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a fixture file for assertion.
		$this->assertSame( "must survive\n", file_get_contents( $kept ), 'The existing file is untouched by a db-only restore.' );
	}

	/**
	 * An archive whose scope lies about its contents is refused.
	 *
	 * A files-only scope must not carry database chunks; if it does, the archive
	 * is corrupt or forged and the restore fails closed rather than writing the
	 * database its scope denies.
	 *
	 * @return void
	 */
	public function test_a_scope_contradicting_its_contents_is_refused(): void {
		global $wpdb;

		$adapter = new WpdbAdapter( $wpdb );
		$sql     = $adapter->dump_table_schema( $this->scratch_table ) . $adapter->dump_table_rows( $this->scratch_table, 0, 100 );
		// A files-only scope, but the manifest carries a database chunk — a lie.
		$plans   = array( self::db_chunk_plan( $this->scratch_table, substr_count( $sql, ";\n" ), $sql ) );
		$archive = self::build_partial_archive( $plans, Scope::files_only( array() ) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/inconsistent archive/' );
		$this->runner()->restore( $archive );
	}

	/**
	 * The other contradiction direction: a db-only scope carrying file entries is refused.
	 *
	 * @return void
	 */
	public function test_a_db_only_scope_carrying_files_is_refused(): void {
		$plans   = array( self::file_plan( 'wp-content/uploads/stray.txt', "should not be here\n" ) );
		$archive = self::build_partial_archive( $plans, Scope::db_only( array() ) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/inconsistent archive/' );
		$this->runner()->restore( $archive );

		$this->assertFileDoesNotExist( $this->restore_root . '/wp-content/uploads/stray.txt', 'A refused archive writes nothing.' );
	}

	// -------------------------------------------------------------------------
	// Helpers.
	// -------------------------------------------------------------------------

	/**
	 * Build a RestoreRunner over the temp restore root and the real database.
	 *
	 * @return RestoreRunner
	 */
	private function runner(): RestoreRunner {
		global $wpdb;
		return new RestoreRunner(
			new EntryReader( CodecRegistry::with_defaults() ),
			new FileWriter( $this->restore_root ),
			new DatabaseWriter( new WpdbAdapter( $wpdb ) )
		);
	}

	/**
	 * Write a fixture file under the restore root, creating parents.
	 *
	 * @param string $relative Relative path.
	 * @param string $contents File contents.
	 * @return void
	 */
	private function write_file( string $relative, string $contents ): void {
		$absolute = $this->restore_root . '/' . $relative;
		$dir      = dirname( $absolute );
		if ( ! is_dir( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating a fixture directory.
			mkdir( $dir, 0o755, true );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Seeding a fixture file.
		file_put_contents( $absolute, $contents );
	}

	/**
	 * Open a php://memory stream.
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
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Operating on a test stream resource.
			fwrite( $stream, $contents );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource.
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
	 * Build a db_chunk EntryPlan from pre-formatted SQL.
	 *
	 * @param string $table_name      Source table name.
	 * @param int    $statement_count Number of statements in the chunk.
	 * @param string $sql             SQL bytes.
	 * @return EntryPlan
	 */
	private static function db_chunk_plan( string $table_name, int $statement_count, string $sql ): EntryPlan {
		$header = EntryHeader::for_db_chunk( 0, $table_name, $statement_count, strlen( $sql ), 0 );
		return new EntryPlan( $header, RawCodec::ID, str_repeat( "\0", EntryWriter::NONCE_SIZE ), self::memory_stream( $sql ) );
	}

	/**
	 * Write the given plans and scope to an in-memory archive; return a rewound stream.
	 *
	 * @param EntryPlan[] $plans The plans to include.
	 * @param Scope       $scope The scope to record in provenance.
	 * @return resource
	 */
	private static function build_partial_archive( array $plans, Scope $scope ) {
		$provenance = new Provenance(
			'6.6.1',
			'8.2.10',
			'https://example.test',
			'utf8mb4',
			'utf8mb4_unicode_520_ci',
			new ExporterInfo( 'pontifex', '0.7.0' ),
			new DateTimeImmutable( '2026-07-13T10:00:00+00:00', new DateTimeZone( 'UTC' ) ),
			null,
			'wp_',
			$scope
		);
		$dest       = self::memory_stream();
		( new ArchiveWriter( new EntryWriter( CodecRegistry::with_defaults() ), new FooterWriter() ) )->write_archive( $provenance, $plans, $dest );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource.
		rewind( $dest );
		return $dest;
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
}
