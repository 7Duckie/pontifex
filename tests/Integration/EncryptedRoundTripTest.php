<?php
/**
 * Same-URL encrypted round-trip integration test.
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
use Pontifex\Archive\Crypto\EncryptionContext;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Format\ExporterInfo;
use Pontifex\Archive\Format\Provenance;
use Pontifex\Archive\Reader\ArchiveReader;
use Pontifex\Archive\Writer\ArchiveWriter;
use Pontifex\Archive\Writer\EntryPlan;
use Pontifex\Archive\Writer\EntryWriter;
use Pontifex\Archive\Writer\FooterWriter;
use Pontifex\Cli\Encryption;
use Pontifex\Manifest\WpdbAdapter;
use Pontifex\Restore\DatabaseWriter;
use Pontifex\Restore\FileWriter;
use Pontifex\Restore\RestoreRunner;

/**
 * Proves the encrypted round trip against a real WordPress database.
 *
 * The plain round trip ({@see RoundTripTest}) proves the writer and reader meet
 * over an unencrypted archive; this proves they meet over an encrypted one
 * along the exact path the CLI uses. An archive is written with the encryption
 * context {@see Encryption::context} produces from a passphrase, then read back
 * through the keyed {@see \Pontifex\Archive\Reader\EntryReader} that
 * {@see Encryption::entry_reader} derives from the archive's stored salt — so
 * the key that locked the archive and the key that opens it are derived
 * independently, as they would be on two different hosts.
 *
 * It is deliberately non-destructive, in the same way as the plain round trip:
 * files land in a temporary restore root, never the live install, and the
 * database half uses a scratch, prefixed table the test creates and drops. The
 * second test pins down the safety promise that matters most for encryption —
 * a wrong passphrase fails closed before any SQL is replayed, so the scratch
 * table is left exactly as it was.
 */
final class EncryptedRoundTripTest extends TestCase {

	/**
	 * The passphrase the archive is encrypted under.
	 *
	 * @var string
	 */
	private const PASSPHRASE = 'integration-passphrase';

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
	 * The table is prefixed like a real WordPress table but is the test's
	 * own — dropped in tear_down. Two rows carry multibyte content to prove
	 * charset fidelity survives the encrypted round trip.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		global $wpdb;

		$suffix              = bin2hex( random_bytes( 8 ) );
		$this->restore_root  = sys_get_temp_dir() . '/pontifex-encrypted-roundtrip-' . $suffix;
		$this->scratch_table = $wpdb->prefix . 'pontifex_encrypted_roundtrip';

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
	 * Encrypt files and a database table, decrypt them, and prove they match.
	 *
	 * Files round-trip byte-for-byte into the temporary restore root; the
	 * scratch table round-trips back into the real database with the same row
	 * count and an identical row dump (multibyte content intact), all through
	 * the encrypt-then-decrypt path the CLI uses.
	 *
	 * @return void
	 */
	public function test_encrypted_round_trip_reproduces_files_and_database(): void {
		global $wpdb;

		$adapter = new WpdbAdapter( $wpdb );

		$db_sql      = $adapter->dump_table_schema( $this->scratch_table ) . $adapter->dump_table_rows( $this->scratch_table, 0, 100 );
		$before_rows = $adapter->dump_table_rows( $this->scratch_table, 0, 100 );

		// Pack into an encrypted archive, using the context the CLI derives from a passphrase.
		$context       = Encryption::context( self::PASSPHRASE );
		$archive_bytes = self::build_encrypted_archive_bytes( $this->build_site_plans( $db_sql ), $context );

		// Open the archive, derive the read key from its stored salt, and restore.
		$this->restore_encrypted( $archive_bytes, self::PASSPHRASE );

		// Files came back byte-for-byte.
		foreach ( self::fixture_files() as $path => $contents ) {
			$restored = $this->restore_root . '/' . $path;
			$this->assertFileExists( $restored, sprintf( 'Restored file missing: %s', $path ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a restored fixture file from the temp root for assertion.
			$this->assertSame( $contents, file_get_contents( $restored ), sprintf( 'Restored file content differs: %s', $path ) );
		}

		// Database came back: same row count, identical row dump (multibyte intact).
		$this->assertSame( 2, $adapter->row_count( $this->scratch_table ), 'Scratch table row count should survive the encrypted round trip.' );
		$this->assertSame( $before_rows, $adapter->dump_table_rows( $this->scratch_table, 0, 100 ), 'Scratch table rows should be byte-identical after the encrypted round trip.' );
	}

	/**
	 * A wrong passphrase must fail closed before any SQL statement is replayed.
	 *
	 * The archive carries a destructive db_chunk (it drops and recreates the
	 * scratch table). With the wrong key the first entry fails to decrypt and
	 * the restore aborts, so the db_chunk is never reached and the seeded
	 * scratch table is left exactly as set_up left it.
	 *
	 * @return void
	 */
	public function test_wrong_passphrase_fails_closed_without_replaying_sql(): void {
		global $wpdb;

		$adapter = new WpdbAdapter( $wpdb );
		$db_sql  = $adapter->dump_table_schema( $this->scratch_table ) . $adapter->dump_table_rows( $this->scratch_table, 0, 100 );

		$context       = Encryption::context( self::PASSPHRASE );
		$archive_bytes = self::build_encrypted_archive_bytes( $this->build_site_plans( $db_sql ), $context );

		$failed = false;
		try {
			$this->restore_encrypted( $archive_bytes, 'the-wrong-passphrase' );
		} catch ( RuntimeException $e ) {
			$failed = true;
		}

		$this->assertTrue( $failed, 'A wrong passphrase must make the restore fail closed.' );
		$this->assertSame( 2, $adapter->row_count( $this->scratch_table ), 'No SQL may be replayed after a decryption failure — the seeded rows must survive.' );
	}

	// -------------------------------------------------------------------------
	// Encryption and restore helpers.
	// -------------------------------------------------------------------------

	/**
	 * Restore an encrypted archive through the keyed reader derived from its stored salt.
	 *
	 * Mirrors the CLI import path: open the archive to read its header and
	 * footer, derive the read key from the stored salt and the given
	 * passphrase, rewind the stream, then restore files to the temporary root
	 * and the db_chunk to the real database.
	 *
	 * @param string $archive_bytes The complete archive bytes.
	 * @param string $passphrase    The passphrase to derive the read key from.
	 * @return void
	 */
	private function restore_encrypted( string $archive_bytes, string $passphrase ): void {
		global $wpdb;

		$source       = self::memory_stream_with( $archive_bytes );
		$reader       = new ArchiveReader( $source );
		$entry_reader = Encryption::entry_reader( $reader, CodecRegistry::with_defaults(), $passphrase );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Rewinding the in-memory archive stream so the runner re-reads from the start.
		rewind( $source );

		$runner = new RestoreRunner(
			$entry_reader,
			new FileWriter( $this->restore_root ),
			new DatabaseWriter( new WpdbAdapter( $wpdb ) )
		);
		$runner->restore( $source );
	}

	/**
	 * Write the given plans to an encrypted in-memory archive and return its bytes.
	 *
	 * @param EntryPlan[]       $plans   The entry plans to pack.
	 * @param EncryptionContext $context The encryption context to write under.
	 * @return string The complete archive bytes.
	 */
	private static function build_encrypted_archive_bytes( array $plans, EncryptionContext $context ): string {
		$dest   = self::memory_stream();
		$writer = new ArchiveWriter( new EntryWriter( CodecRegistry::with_defaults() ), new FooterWriter() );
		$writer->write_archive( self::sample_provenance(), $plans, $dest, null, $context );
		return self::read_all( $dest );
	}

	/**
	 * Build the standard set of entry plans: two directories, two files and the db chunk.
	 *
	 * Fresh each call because write_archive() consumes each plan's source stream.
	 *
	 * @param string $db_sql The db_chunk SQL (schema + rows of the scratch table).
	 * @return EntryPlan[] The plans, in archive order.
	 */
	private function build_site_plans( string $db_sql ): array {
		$plans = array(
			self::directory_plan( 'wp-content' ),
			self::directory_plan( 'wp-content/uploads' ),
		);
		foreach ( self::fixture_files() as $path => $contents ) {
			$plans[] = self::file_plan( $path, $contents );
		}
		$plans[] = self::db_chunk_plan( $this->scratch_table, self::count_statements( $db_sql ), $db_sql );
		return $plans;
	}

	/**
	 * The fixture files packed into the archive, by relative path.
	 *
	 * @return array<string, string> Path => contents.
	 */
	private static function fixture_files(): array {
		return array(
			'index.php'                   => "<?php\n// fixture root file\n",
			'wp-content/uploads/note.txt' => "utf8 content: café ☕ 日本語\n",
		);
	}

	// -------------------------------------------------------------------------
	// Fixture and stream helpers (shared shape with RoundTripTest).
	// -------------------------------------------------------------------------

	/**
	 * Open a php://memory stream pre-populated with bytes, cursor rewound.
	 *
	 * @param string $contents Bytes to write before rewinding.
	 * @return resource A readable php://memory stream at offset 0.
	 * @throws RuntimeException If php://memory cannot be opened.
	 */
	private static function memory_stream_with( string $contents ) {
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
	 * Open an empty php://memory stream.
	 *
	 * @return resource A readable and writable php://memory stream.
	 * @throws RuntimeException If php://memory cannot be opened.
	 */
	private static function memory_stream() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://memory is an in-process buffer, not a file.
		$stream = fopen( 'php://memory', 'r+b' );
		if ( false === $stream ) {
			throw new RuntimeException( 'Could not open php://memory.' );
		}
		return $stream;
	}

	/**
	 * Rewind a stream and return all of its contents.
	 *
	 * @param resource $stream The stream to read.
	 * @return string The full contents.
	 * @throws RuntimeException If the stream cannot be read.
	 */
	private static function read_all( $stream ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource, not a filesystem path.
		rewind( $stream );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_stream_get_contents -- Operating on a test stream resource, not a filesystem path.
		$contents = stream_get_contents( $stream );
		if ( false === $contents ) {
			throw new RuntimeException( 'Could not read test stream.' );
		}
		return $contents;
	}

	/**
	 * Build a sample Provenance for archive construction.
	 *
	 * @return Provenance A valid provenance block.
	 */
	private static function sample_provenance(): Provenance {
		return new Provenance(
			'6.6.1',
			'8.2.10',
			'https://example.test',
			'utf8mb4',
			'utf8mb4_unicode_520_ci',
			new ExporterInfo( 'pontifex', '0.3.0' ),
			new DateTimeImmutable( '2026-06-23T10:00:00+00:00', new DateTimeZone( 'UTC' ) )
		);
	}

	/**
	 * Build a file EntryPlan with the given contents.
	 *
	 * @param string $path     Relative path inside the archive.
	 * @param string $contents File contents.
	 * @return EntryPlan The file plan.
	 */
	private static function file_plan( string $path, string $contents ): EntryPlan {
		$header = EntryHeader::for_file( $path, strlen( $contents ), 0o644, 1690000000, 'application/octet-stream', 0 );
		return new EntryPlan( $header, RawCodec::ID, str_repeat( "\0", EntryWriter::NONCE_SIZE ), self::memory_stream_with( $contents ) );
	}

	/**
	 * Build a directory EntryPlan.
	 *
	 * @param string $path Relative path inside the archive.
	 * @return EntryPlan The directory plan.
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
	 * @return EntryPlan The db_chunk plan.
	 */
	private static function db_chunk_plan( string $table_name, int $statement_count, string $sql ): EntryPlan {
		$header = EntryHeader::for_db_chunk( 0, $table_name, $statement_count, strlen( $sql ), 0 );
		return new EntryPlan( $header, RawCodec::ID, str_repeat( "\0", EntryWriter::NONCE_SIZE ), self::memory_stream_with( $sql ) );
	}

	/**
	 * Count statements in a chunk: each is terminated by a semicolon-newline.
	 *
	 * @param string $sql The chunk SQL.
	 * @return int The statement count.
	 */
	private static function count_statements( string $sql ): int {
		return substr_count( $sql, ";\n" );
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
