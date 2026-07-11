<?php
/**
 * Integration test: the staging-table restore is atomic over a real database.
 *
 * @package Pontifex\Tests\Integration
 */

declare(strict_types=1);

namespace Pontifex\Tests\Integration;

require_once __DIR__ . '/Fakes/FailingDatabaseAdapter.php';

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
use Pontifex\Tests\Integration\Fakes\FailingDatabaseAdapter;

/**
 * Proves ADR 0009's atomicity contract against a real MySQL server.
 *
 * The failure this design exists for cannot be shown with fakes: a restore
 * that dies mid-replay must leave every live table byte-identical, because the
 * replay only ever wrote staging tables. Two scenarios run over real MySQL:
 *
 *  - a successful restore replaces the live tables' content and leaves no
 *    `pontifexstg_*` / `pontifexold_*` residue;
 *  - a restore failing on its SECOND table (the first already fully staged)
 *    leaves both live tables untouched and drops the staging tables — the
 *    exact mixed-state incident the old drop-in-place replay caused.
 */
final class AtomicRestoreTest extends TestCase {

	/**
	 * Every scratch table this test can create, dropped in set_up and tear_down.
	 *
	 * @var string[]
	 */
	private const SCRATCH_TABLES = array(
		'pontifextest_alpha',
		'pontifextest_beta',
		'pontifexstg_pontifextest_alpha',
		'pontifexstg_pontifextest_beta',
		'pontifexold_pontifextest_alpha',
		'pontifexold_pontifextest_beta',
	);

	/**
	 * Temp directory FileWriter is rooted at (no file entries are used).
	 *
	 * @var string
	 */
	private string $fixture_root = '';

	/**
	 * Drop scratch tables and reserve a fixture root.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();
		$this->drop_scratch_tables();
		$this->fixture_root = sys_get_temp_dir() . '/pontifex-atomic-restore-' . bin2hex( random_bytes( 8 ) );
	}

	/**
	 * Drop scratch tables and remove the fixture root.
	 *
	 * @return void
	 */
	protected function tear_down(): void {
		$this->drop_scratch_tables();
		if ( is_dir( $this->fixture_root ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.PHP.NoSilencedErrors.Discouraged -- Test fixture teardown; the directory is empty (no file entries are restored).
			@rmdir( $this->fixture_root );
		}
		parent::tear_down();
	}

	/**
	 * A successful restore replaces the live tables and leaves no residue.
	 *
	 * @return void
	 */
	public function test_successful_restore_replaces_live_tables_atomically(): void {
		global $wpdb;
		$this->create_live_table( 'pontifextest_alpha', 'live-alpha' );
		$this->create_live_table( 'pontifextest_beta', 'live-beta' );

		$runner = new RestoreRunner(
			new EntryReader( CodecRegistry::with_defaults() ),
			new FileWriter( $this->fixture_root ),
			new DatabaseWriter( new WpdbAdapter( $wpdb ) )
		);

		$runner->restore( $this->archive_with_both_tables() );

		$this->assertSame( 'restored-alpha', $this->value_in( 'pontifextest_alpha' ) );
		$this->assertSame( 'restored-beta', $this->value_in( 'pontifextest_beta' ) );
		$this->assertSame( array(), $this->leftover_pontifex_tables(), 'A clean restore must leave no staging or parked tables.' );
	}

	/**
	 * A restore failing mid-replay leaves every live table byte-identical.
	 *
	 * The failure lands on the second table's CREATE, after the first table has
	 * fully staged — the shape of the real incident. With the old drop-in-place
	 * replay, alpha would now hold restored content and beta would be a dropped
	 * ruin; with staging, both live tables must be exactly as they were.
	 *
	 * @return void
	 */
	public function test_failed_restore_leaves_live_tables_untouched(): void {
		global $wpdb;
		$this->create_live_table( 'pontifextest_alpha', 'live-alpha' );
		$this->create_live_table( 'pontifextest_beta', 'live-beta' );

		// Statement ordinals during restore: alpha DROP(1), CREATE(2), INSERT(3),
		// beta DROP(4), CREATE(5) — the 5th call fails, before any cut-over.
		$adapter = new FailingDatabaseAdapter( new WpdbAdapter( $wpdb ), 5 );
		$runner  = new RestoreRunner(
			new EntryReader( CodecRegistry::with_defaults() ),
			new FileWriter( $this->fixture_root ),
			new DatabaseWriter( $adapter )
		);

		try {
			$runner->restore( $this->archive_with_both_tables() );
			$this->fail( 'restore() should propagate the mid-replay failure.' );
		} catch ( RuntimeException $failure ) {
			$this->assertStringContainsString( 'simulated mid-replay failure', $failure->getMessage() );
		}

		$this->assertSame( 'live-alpha', $this->value_in( 'pontifextest_alpha' ), 'The live alpha table must be untouched by the failed restore.' );
		$this->assertSame( 'live-beta', $this->value_in( 'pontifextest_beta' ), 'The live beta table must be untouched by the failed restore.' );
		$this->assertSame( array(), $this->leftover_pontifex_tables(), 'The aborted staging tables must be dropped.' );
	}

	/**
	 * Build an in-memory archive holding db_chunks for both scratch tables.
	 *
	 * @return resource A readable, seekable stream containing the archive bytes.
	 */
	private function archive_with_both_tables() {
		$alpha_sql = "DROP TABLE IF EXISTS `pontifextest_alpha`;\n"
			. "CREATE TABLE `pontifextest_alpha` (id INT NOT NULL PRIMARY KEY, val VARCHAR(50)) DEFAULT CHARSET=utf8mb4;\n"
			. "INSERT INTO `pontifextest_alpha` (`id`, `val`) VALUES (1, 'restored-alpha');\n";
		$beta_sql  = "DROP TABLE IF EXISTS `pontifextest_beta`;\n"
			. "CREATE TABLE `pontifextest_beta` (id INT NOT NULL PRIMARY KEY, val VARCHAR(50)) DEFAULT CHARSET=utf8mb4;\n"
			. "INSERT INTO `pontifextest_beta` (`id`, `val`) VALUES (1, 'restored-beta');\n";

		$plans = array(
			self::db_chunk_plan( 'pontifextest_alpha', 3, $alpha_sql ),
			self::db_chunk_plan( 'pontifextest_beta', 3, $beta_sql ),
		);

		$writer = new ArchiveWriter( new EntryWriter( CodecRegistry::with_defaults() ), new FooterWriter() );
		$dest   = self::memory_stream();
		$writer->write_archive( self::sample_provenance(), $plans, $dest );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource, not a filesystem path.
		rewind( $dest );
		return $dest;
	}

	/**
	 * Create a live scratch table holding one known row.
	 *
	 * @param string $table The table name.
	 * @param string $value The value the row carries.
	 * @return void
	 */
	private function create_live_table( string $table, string $value ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test fixture: create a scratch table.
		$wpdb->query( $wpdb->prepare( 'CREATE TABLE %i (id INT NOT NULL PRIMARY KEY, val VARCHAR(50)) DEFAULT CHARSET=utf8mb4', $table ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test fixture: seed the scratch table.
		$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (id, val) VALUES (1, %s)', $table, $value ) );
	}

	/**
	 * Read the single row's value from a scratch table.
	 *
	 * @param string $table The table name.
	 * @return string|null The value, or null when the table or row is missing.
	 */
	private function value_in( string $table ): ?string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test assertion: read back the scratch row.
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT val FROM %i WHERE id = 1', $table ) );
		return null === $value ? null : (string) $value;
	}

	/**
	 * List any pontifexstg_/pontifexold_ tables left for this test's scratch names.
	 *
	 * @return string[] Leftover table names; empty when the cleanup held.
	 */
	private function leftover_pontifex_tables(): array {
		global $wpdb;
		$leftovers = array();
		foreach ( array( 'pontifexstg_pontifextest_%', 'pontifexold_pontifextest_%' ) as $pattern ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test assertion: list leftover scratch tables.
			$found = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pattern ) );
			foreach ( $found as $table ) {
				$leftovers[] = (string) $table;
			}
		}
		sort( $leftovers, SORT_STRING );
		return $leftovers;
	}

	/**
	 * Drop every scratch table this test may have created.
	 *
	 * @return void
	 */
	private function drop_scratch_tables(): void {
		global $wpdb;
		foreach ( self::SCRATCH_TABLES as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test cleanup: drop a scratch table.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}
	}

	/**
	 * Build an EntryPlan for a db_chunk entry.
	 *
	 * @param string $table_name      Source table name.
	 * @param int    $statement_count Number of statements in the chunk.
	 * @param string $sql             SQL bytes (semicolon-newline terminated).
	 * @return EntryPlan A plan ready to feed to ArchiveWriter.
	 */
	private static function db_chunk_plan( string $table_name, int $statement_count, string $sql ): EntryPlan {
		$header = EntryHeader::for_db_chunk( 0, $table_name, $statement_count, strlen( $sql ), 0 );
		return new EntryPlan( $header, RawCodec::ID, str_repeat( "\0", EntryWriter::NONCE_SIZE ), self::memory_stream( $sql ) );
	}

	/**
	 * Open a php://memory stream.
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
	 * Build a sample Provenance for archive construction.
	 *
	 * @return Provenance A valid provenance instance.
	 */
	private static function sample_provenance(): Provenance {
		return new Provenance(
			'6.6.1',
			'8.2.10',
			'https://example.test',
			'utf8mb4',
			'utf8mb4_unicode_520_ci',
			new ExporterInfo( 'pontifex', '0.1.0' ),
			new DateTimeImmutable( '2026-07-11T10:00:00+00:00', new DateTimeZone( 'UTC' ) )
		);
	}
}
