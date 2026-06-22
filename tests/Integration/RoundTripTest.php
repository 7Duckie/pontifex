<?php
/**
 * Same-URL round-trip integration test.
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
 * Proves the defining behaviour: pack a site, unpack it, get the same site back.
 *
 * This is BUILD-1's payoff — the first time the writer and the reader meet
 * over a real archive against a real WordPress database. It runs at the
 * engine level (ArchiveWriter -> .wpmig -> ArchiveReader/RestoreRunner)
 * with a real WpdbAdapter and a real FileWriter; the thin WP-CLI command
 * wrappers are unit-tested separately and need the wp binary to run.
 *
 * It is deliberately non-destructive. Files round-trip into a temporary
 * restore root, never the live WordPress install. The database half uses
 * a scratch, prefixed table that the test creates, round-trips, and drops
 * — no real wp_* table is ever dropped or rebuilt. There is no
 * transactional rollback here (the suite uses the Polyfills TestCase, not
 * WP_UnitTestCase, and restore's DDL would defeat a transaction anyway),
 * so tear_down cleans up by hand.
 *
 * The entry plans are hand-assembled (real file bytes; a db_chunk whose
 * SQL is the real WpdbAdapter dump of the scratch table) rather than built
 * by the full ManifestBuilder, which would pull the entire installation
 * and every prefixed table. The scanners have their own unit tests; this
 * proves the format-and-adapter round trip.
 */
final class RoundTripTest extends TestCase {


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
	 * Seed a scratch table with multibyte rows and reserve a restore root.
	 *
	 * The table is prefixed like a real WordPress table so WpdbAdapter
	 * treats it as a valid target, but it is the test's own — dropped in
	 * tear_down. Two rows carry multibyte content to prove charset
	 * fidelity through the round trip.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		global $wpdb;

		$suffix              = bin2hex( random_bytes( 8 ) );
		$this->restore_root  = sys_get_temp_dir() . '/pontifex-roundtrip-' . $suffix;
		$this->scratch_table = $wpdb->prefix . 'pontifex_roundtrip';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test: dropping any leftover scratch table in the test database.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $this->scratch_table ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test: creating the isolated scratch table.
		$wpdb->query( $wpdb->prepare( 'CREATE TABLE %i ( id INT PRIMARY KEY, label VARCHAR(255) ) DEFAULT CHARSET=utf8mb4', $this->scratch_table ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test: seeding the scratch table.
		$wpdb->query( $wpdb->prepare( 'INSERT INTO %i ( id, label ) VALUES ( %d, %s )', $this->scratch_table, 1, 'café ☕' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test: seeding the scratch table.
		$wpdb->query( $wpdb->prepare( 'INSERT INTO %i ( id, label ) VALUES ( %d, %s )', $this->scratch_table, 2, '日本語' ) );
	}

	/**
	 * Drop the scratch table and remove the temporary restore root.
	 *
	 * @return void
	 */
	protected function tear_down(): void {
		global $wpdb;

		if ( '' !== $this->scratch_table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test cleanup: dropping the scratch table.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $this->scratch_table ) );
		}

		if ( '' !== $this->restore_root && is_dir( $this->restore_root ) ) {
			self::rmtree( $this->restore_root );
		}

		parent::tear_down();
	}

	/**
	 * Pack files and a database table into an archive, unpack it, and prove it matches.
	 *
	 * Files round-trip byte-for-byte into the temporary restore root; the
	 * scratch table round-trips back into the real database with the same
	 * row count and an identical row dump (multibyte content intact).
	 *
	 * @return void
	 */
	public function test_round_trip_reproduces_files_and_database(): void {
		global $wpdb;

		$adapter = new WpdbAdapter( $wpdb );

		// Source files, as in-memory bytes (a root file and a nested file).
		$files = array(
			'index.php'                   => "<?php\n// fixture root file\n",
			'wp-content/uploads/note.txt' => "utf8 content: café ☕ 日本語\n",
		);

		// Source database: the real dump of the seeded scratch table.
		$db_sql      = $adapter->dump_table_schema( $this->scratch_table ) . $adapter->dump_table_rows( $this->scratch_table, 0, 100 );
		$before_rows = $adapter->dump_table_rows( $this->scratch_table, 0, 100 );

		// Assemble entry plans: directories, then files, then the db chunk.
		$plans = array(
			self::directory_plan( 'wp-content' ),
			self::directory_plan( 'wp-content/uploads' ),
		);
		foreach ( $files as $path => $contents ) {
			$plans[] = self::file_plan( $path, $contents );
		}
		$plans[] = self::db_chunk_plan( $this->scratch_table, self::count_statements( $db_sql ), $db_sql );

		// Pack, then unpack: files to the temp root, the db chunk to the real database.
		$archive = self::build_archive_stream( $plans );

		$runner = new RestoreRunner(
			new EntryReader( CodecRegistry::with_defaults() ),
			new FileWriter( $this->restore_root ),
			new DatabaseWriter( new WpdbAdapter( $wpdb ) )
		);
		$runner->restore( $archive );

		// Files came back byte-for-byte.
		foreach ( $files as $path => $contents ) {
			$restored = $this->restore_root . '/' . $path;
			$this->assertFileExists( $restored, sprintf( 'Restored file missing: %s', $path ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a restored fixture file from the temp root for assertion.
			$this->assertSame( $contents, file_get_contents( $restored ), sprintf( 'Restored file content differs: %s', $path ) );
		}

		// Database came back: same row count, identical row dump (multibyte intact).
		$this->assertSame( 2, $adapter->row_count( $this->scratch_table ), 'Scratch table row count should survive the round trip.' );
		$this->assertSame( $before_rows, $adapter->dump_table_rows( $this->scratch_table, 0, 100 ), 'Scratch table rows should be byte-identical after the round trip.' );
	}


	// -------------------------------------------------------------------------
	// Fixture and archive helpers.
	// -------------------------------------------------------------------------

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
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Operating on a test stream resource, not a filesystem path.
			fwrite( $stream, $contents );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource, not a filesystem path.
			rewind( $stream );
		}
		return $stream;
	}

	/**
	 * Build a sample Provenance for archive construction.
	 *
	 * @return Provenance
	 */
	private static function sample_provenance(): Provenance {
		return new Provenance(
			'6.6.1',
			'8.2.10',
			'https://example.test',
			'utf8mb4',
			'utf8mb4_unicode_520_ci',
			new ExporterInfo( 'pontifex', '0.1.0' ),
			new DateTimeImmutable( '2026-05-23T10:00:00+00:00', new DateTimeZone( 'UTC' ) )
		);
	}

	/**
	 * Build a file EntryPlan with the given contents.
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
	 * Build a db_chunk EntryPlan from pre-formatted SQL.
	 *
	 * @param string $table_name      Source table name.
	 * @param int    $statement_count Number of statements in the chunk.
	 * @param string $sql             SQL bytes (semicolon-newline terminated).
	 * @return EntryPlan
	 */
	private static function db_chunk_plan( string $table_name, int $statement_count, string $sql ): EntryPlan {
		$header = EntryHeader::for_db_chunk( 0, $table_name, $statement_count, strlen( $sql ), 0 );
		return new EntryPlan( $header, RawCodec::ID, str_repeat( "\0", EntryWriter::NONCE_SIZE ), self::memory_stream( $sql ) );
	}

	/**
	 * Count statements in a chunk: each is terminated by a semicolon-newline.
	 *
	 * Matches DatabaseWriter's splitting contract, so the db_chunk header's
	 * declared statement count agrees with what the writer will replay.
	 *
	 * @param string $sql The chunk SQL.
	 * @return int
	 */
	private static function count_statements( string $sql ): int {
		return substr_count( $sql, ";\n" );
	}

	/**
	 * Build an ArchiveWriter wired with the default codec registry.
	 *
	 * @return ArchiveWriter
	 */
	private static function make_archive_writer(): ArchiveWriter {
		return new ArchiveWriter( new EntryWriter( CodecRegistry::with_defaults() ), new FooterWriter() );
	}

	/**
	 * Write the given plans to an in-memory archive and return a rewound stream.
	 *
	 * @param EntryPlan[] $plans The plans to include.
	 * @return resource
	 */
	private static function build_archive_stream( array $plans ) {
		$dest = self::memory_stream();
		self::make_archive_writer()->write_archive( self::sample_provenance(), $plans, $dest );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource, not a filesystem path.
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
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			self::rmtree( $path . '/' . $entry );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.PHP.NoSilencedErrors.Discouraged -- Test fixture teardown.
		@rmdir( $path );
	}
}
