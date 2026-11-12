<?php
/**
 * Integration test: a hostile db_chunk statement is refused before it can touch the live database.
 *
 * @package Pontifex\Tests\Integration
 */

declare(strict_types=1);

namespace Pontifex\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\AssertionFailedError;
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
use Pontifex\Manifest\DatabaseScanner;
use Pontifex\Manifest\ExclusionRules;
use Pontifex\Manifest\WpdbAdapter;
use Pontifex\Restore\DatabaseWriter;
use Pontifex\Restore\FileWriter;
use Pontifex\Restore\RestoreRunner;

/**
 * Proves the db_chunk statement-shape guard against a real MySQL server.
 *
 * A hostile archive can declare an entirely ordinary-looking table for its
 * db_chunk while smuggling a second, properly-delimited statement in the
 * same chunk's payload that targets a live table outright — the exact shape
 * of the real incident this guard closes (a chunk declaring a benign table
 * whose payload also carried an UPDATE against the live wp_users table).
 * The unit suite proves the shape check in isolation; this test proves the
 * full restore path refuses the chunk before ANY of its statements reach a
 * real database, that the canary row a hostile UPDATE would have touched is
 * byte-unchanged, and that no pontifexstg_/pontifexold_ residue is left
 * behind.
 */
final class DbChunkStatementContainmentTest extends TestCase {

	/**
	 * Prefix a bare scratch-table basename with the live database's own
	 * table prefix, derived from $wpdb->prefix at runtime — never
	 * hardcoded to "wp_" — so DatabaseWriter's cross-site guard accepts
	 * every fixture table this file creates as belonging to this site. The
	 * "pontifextest_" element every basename carries is kept, so the
	 * scratch namespace stays obviously not a real WordPress table.
	 *
	 * The single place every other reference to a scratch table name in
	 * this file goes through, so the live prefix is introduced once rather
	 * than scattered across dozens of literal strings.
	 *
	 * @param string $basename The bare basename, e.g. "pontifextest_canary".
	 * @return string The live-prefixed table name.
	 */
	private static function scratch_table( string $basename ): string {
		global $wpdb;
		return $wpdb->prefix . $basename;
	}

	/**
	 * Every scratch table this test can create, dropped in set_up and tear_down.
	 *
	 * Computed at runtime, via {@see self::scratch_table()}, rather than a
	 * compile-time constant — a class constant cannot read $wpdb->prefix.
	 *
	 * @return string[]
	 */
	private static function scratch_tables(): array {
		$basenames = array(
			'pontifextest_canary',
			'pontifextest_alpha',
			'pontifextest_beta',
			'pontifextest_loot',
			'pontifextest_myisam_canary',
			'pontifextest_sysver',
			'pontifextest_partitioned',
		);

		$tables = array();
		foreach ( $basenames as $basename ) {
			$table    = self::scratch_table( $basename );
			$tables[] = $table;
			$tables[] = 'pontifexstg_' . $table;
			$tables[] = 'pontifexold_' . $table;
		}
		return $tables;
	}

	/**
	 * The canary row's value before the restore attempt, for the byte-unchanged assertion.
	 *
	 * @var string
	 */
	private const CANARY_VALUE = 'original-password-hash';

	/**
	 * The MyISAM canary row's value before the restore attempt, for the byte-unchanged assertion.
	 *
	 * @var string
	 */
	private const MYISAM_CANARY_VALUE = 'original-myisam-value';

	/**
	 * The row value seeded into the system-versioned fixture table, for the round-trip assertion.
	 *
	 * @var string
	 */
	private const SYSVER_VALUE = 'system-versioned-value';

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
		$this->drop_sequence_scratch_tables();
		$this->fixture_root = sys_get_temp_dir() . '/pontifex-db-chunk-containment-' . bin2hex( random_bytes( 8 ) );
	}

	/**
	 * Drop scratch tables and remove the fixture root.
	 *
	 * @return void
	 */
	protected function tear_down(): void {
		$this->drop_scratch_tables();
		$this->drop_sequence_scratch_tables();
		if ( is_dir( $this->fixture_root ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.PHP.NoSilencedErrors.Discouraged -- Test fixture teardown; the directory is empty (no file entries are restored).
			@rmdir( $this->fixture_root );
		}
		parent::tear_down();
	}

	/**
	 * A db_chunk smuggling a trailing stacked statement is refused, and the live database is untouched.
	 *
	 * @return void
	 * @throws AssertionFailedError Rethrown immediately if restore() failed to throw (see the catch block below); never swallowed.
	 */
	public function test_hostile_db_chunk_is_refused_and_live_database_is_unchanged(): void {
		global $wpdb;
		$this->create_canary_table();

		$runner = new RestoreRunner(
			new EntryReader( CodecRegistry::with_defaults() ),
			new FileWriter( $this->fixture_root ),
			new DatabaseWriter( new WpdbAdapter( $wpdb ) )
		);

		try {
			$runner->restore( $this->hostile_archive() );
			$this->fail( 'restore() should refuse the hostile db_chunk.' );
		} catch ( AssertionFailedError $bug ) {
			// self::fail()'s own AssertionFailedError extends RuntimeException, so it
			// would otherwise be swallowed by the catch below — rethrow it before that
			// catch ever sees it, so a missing refusal fails this test for real.
			throw $bug;
		} catch ( RuntimeException $refusal ) {
			$this->assertStringContainsString( 'refusing to replay it against the live database', $refusal->getMessage() );
		}

		$this->assertSame( self::CANARY_VALUE, $this->canary_value(), 'The canary row must be byte-unchanged: the hostile UPDATE must never have executed.' );
		$this->assertSame( array(), $this->leftover_pontifex_tables(), 'A refused restore must leave no staging or parked residue.' );
	}

	/**
	 * A db_chunk whose hostile continuation hides behind a separator the ";\n" splitter does not
	 * recognise is refused, and the live database is untouched.
	 *
	 * The companion to {@see self::test_hostile_db_chunk_is_refused_and_live_database_is_unchanged()}
	 * above. There, the hostile statement is delimited by the writer's own
	 * ";\n" and so arrives as its own parsed statement — refused on its own
	 * shape, the way a chunk-count mismatch or an outright UPDATE always
	 * was. Here the hostile UPDATE is joined to a legitimate-looking CREATE
	 * with a bare "; " instead, so it survives the split as part of the SAME
	 * parsed statement; CREATE's shape check is a PREFIX match
	 * (str_starts_with) with no end anchor, so only
	 * DatabaseWriter::has_executable_semicolon() stands between this
	 * payload and the live database. This proves that check against a real
	 * restore and a real MySQL server, not just the unit suite's fakes.
	 *
	 * @return void
	 * @throws AssertionFailedError Rethrown immediately if restore() failed to throw (see the catch block below); never swallowed.
	 */
	public function test_hostile_embedded_semicolon_is_refused_and_live_database_is_unchanged(): void {
		global $wpdb;
		$this->create_canary_table();

		$runner = new RestoreRunner(
			new EntryReader( CodecRegistry::with_defaults() ),
			new FileWriter( $this->fixture_root ),
			new DatabaseWriter( new WpdbAdapter( $wpdb ) )
		);

		try {
			$runner->restore( $this->hostile_embedded_semicolon_archive() );
			$this->fail( 'restore() should refuse the hostile db_chunk.' );
		} catch ( AssertionFailedError $bug ) {
			// See test_hostile_db_chunk_is_refused_and_live_database_is_unchanged()
			// above for why this rethrow must run before the RuntimeException catch below.
			throw $bug;
		} catch ( RuntimeException $refusal ) {
			$this->assertStringContainsString( 'carries more than one statement', $refusal->getMessage() );
		}

		$this->assertSame( self::CANARY_VALUE, $this->canary_value(), 'The canary row must be byte-unchanged: the hostile UPDATE must never have executed.' );
		$this->assertSame( array(), $this->leftover_pontifex_tables(), 'A refused restore must leave no staging or parked residue.' );
	}

	/**
	 * A `CREATE TABLE (cols) SELECT ...` — a genuine column-list open paren,
	 * satisfying the CREATE shape check's mandatory anchor, whose body then
	 * populates the table from a live table's own data in the very same
	 * statement — must be refused, and the live source table must be untouched.
	 *
	 * The exact bug this test reproduces end-to-end (ADR 0019): the CREATE
	 * shape check anchors only the statement's opening bytes up to and
	 * including the mandatory `" ("`, and the object the CREATE builds is an
	 * entirely ordinary InnoDB base table, so neither the shape check nor the
	 * storage-facts check alone sees anything wrong with it. Before this fix,
	 * this exact statement shape executed clean against a real MariaDB server
	 * and the staged table survived cut-over holding the source table's data.
	 *
	 * @return void
	 * @throws AssertionFailedError Rethrown immediately if restore() failed to throw (see the catch block below); never swallowed.
	 */
	public function test_create_table_select_with_column_list_is_refused_and_live_table_is_unchanged(): void {
		global $wpdb;
		$this->create_canary_table();

		$runner = new RestoreRunner(
			new EntryReader( CodecRegistry::with_defaults() ),
			new FileWriter( $this->fixture_root ),
			new DatabaseWriter( new WpdbAdapter( $wpdb ) )
		);

		try {
			$runner->restore( $this->create_table_select_archive( false ) );
			$this->fail( 'restore() should refuse a CREATE ... SELECT that produced rows.' );
		} catch ( AssertionFailedError $bug ) {
			// self::fail()'s own AssertionFailedError extends RuntimeException, so it
			// would otherwise be swallowed by the catch below — rethrow it before that
			// catch ever sees it, so a missing refusal fails this test for real.
			throw $bug;
		} catch ( RuntimeException $refusal ) {
			$this->assertStringContainsString( 'produced rows', $refusal->getMessage() );
			$this->assertStringContainsString( 'pontifexstg_' . self::scratch_table( 'pontifextest_loot' ), $refusal->getMessage() );
			$this->assertStringNotContainsString( self::CANARY_VALUE, $refusal->getMessage(), 'The refusal message must never contain the statement bytes or the exfiltrated data.' );
		}

		$this->assertSame( self::CANARY_VALUE, $this->canary_value(), 'The live source table must be byte-unchanged: it was only ever read from, never written to.' );
		$this->assertSame( array(), $this->leftover_pontifex_tables(), 'A refused restore must leave no staging or parked residue.' );
	}

	/**
	 * The `AS SELECT` sibling of the above — the `AS` keyword changes nothing
	 * the shape check or the storage-facts check inspect, so it must be
	 * refused the same way, proven end-to-end against a real MariaDB server.
	 *
	 * @return void
	 * @throws AssertionFailedError Rethrown immediately if restore() failed to throw (see the catch block below); never swallowed.
	 */
	public function test_create_table_as_select_with_column_list_is_refused_and_live_table_is_unchanged(): void {
		global $wpdb;
		$this->create_canary_table();

		$runner = new RestoreRunner(
			new EntryReader( CodecRegistry::with_defaults() ),
			new FileWriter( $this->fixture_root ),
			new DatabaseWriter( new WpdbAdapter( $wpdb ) )
		);

		try {
			$runner->restore( $this->create_table_select_archive( true ) );
			$this->fail( 'restore() should refuse a CREATE ... AS SELECT that produced rows.' );
		} catch ( AssertionFailedError $bug ) {
			// See test_create_table_select_with_column_list_is_refused_and_live_table_is_unchanged()
			// above for why this rethrow must run before the RuntimeException catch below.
			throw $bug;
		} catch ( RuntimeException $refusal ) {
			$this->assertStringContainsString( 'produced rows', $refusal->getMessage() );
			$this->assertStringContainsString( 'pontifexstg_' . self::scratch_table( 'pontifextest_loot' ), $refusal->getMessage() );
			$this->assertStringNotContainsString( self::CANARY_VALUE, $refusal->getMessage(), 'The refusal message must never contain the statement bytes or the exfiltrated data.' );
		}

		$this->assertSame( self::CANARY_VALUE, $this->canary_value(), 'The live source table must be byte-unchanged: it was only ever read from, never written to.' );
		$this->assertSame( array(), $this->leftover_pontifex_tables(), 'A refused restore must leave no staging or parked residue.' );
	}

	/**
	 * Build an in-memory archive whose one db_chunk CREATEs a table with a genuine
	 * column-list open paren, then either SELECTs or AS SELECTs the live canary
	 * table's own data into it in the very same statement — no executable
	 * semicolon anywhere, and the object built is an entirely ordinary table.
	 *
	 * @param bool $with_as_keyword Whether to include the AS keyword before SELECT.
	 * @return resource A readable, seekable stream containing the archive bytes.
	 */
	private function create_table_select_archive( bool $with_as_keyword ) {
		$as     = $with_as_keyword ? 'AS ' : '';
		$loot   = self::scratch_table( 'pontifextest_loot' );
		$canary = self::scratch_table( 'pontifextest_canary' );
		$sql    = "CREATE TABLE `{$loot}` (`marker` INT) {$as}SELECT id, val FROM `{$canary}`;\n";

		$header = EntryHeader::for_db_chunk( 0, $loot, 1, strlen( $sql ), 0 );
		$plans  = array( new EntryPlan( $header, RawCodec::ID, str_repeat( "\0", EntryWriter::NONCE_SIZE ), self::memory_stream( $sql ) ) );

		$writer = new ArchiveWriter( new EntryWriter( CodecRegistry::with_defaults() ), new FooterWriter() );
		$dest   = self::memory_stream();
		$writer->write_archive( self::sample_provenance(), $plans, $dest );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource, not a filesystem path.
		rewind( $dest );
		return $dest;
	}

	/**
	 * A two-chunk archive that builds a MERGE table over a live MyISAM table, then
	 * plants a row through it, must be refused before the plant ever happens — and
	 * the live table must be untouched.
	 *
	 * The exact write-through attack ADR 0019's server-fact check exists to close:
	 * a chunk's CREATE names MySQL's built-in MRG_MyISAM engine and UNIONs a live
	 * table by name in the body — bytes the CREATE shape check deliberately never
	 * inspects — turning the "staged" table into a writable, readable alias for
	 * the live one. A second chunk's ordinary-looking INSERT (shape-perfect on its
	 * own merits) would then write straight through the alias into the live table.
	 * Proves the check runs against a REAL MariaDB server, through the REAL
	 * RestoreRunner, not just the unit suite's fakes: refuses the CREATE before
	 * the INSERT chunk is ever reached, leaves the live MyISAM table
	 * byte-unchanged, and leaves no staging or parked residue.
	 *
	 * @return void
	 * @throws AssertionFailedError Rethrown immediately if restore() failed to throw (see the catch block below); never swallowed.
	 */
	public function test_merge_table_write_through_is_refused_and_live_myisam_table_is_unchanged(): void {
		global $wpdb;
		$this->create_myisam_canary_table();

		$runner = new RestoreRunner(
			new EntryReader( CodecRegistry::with_defaults() ),
			new FileWriter( $this->fixture_root ),
			new DatabaseWriter( new WpdbAdapter( $wpdb ) )
		);

		try {
			$runner->restore( $this->merge_write_through_archive() );
			$this->fail( 'restore() should refuse the CREATE that builds a MERGE table over a live one.' );
		} catch ( AssertionFailedError $bug ) {
			// See test_hostile_db_chunk_is_refused_and_live_database_is_unchanged()
			// above for why this rethrow must run before the RuntimeException catch below.
			throw $bug;
		} catch ( RuntimeException $refusal ) {
			$this->assertStringContainsString( 'MRG_MyISAM', $refusal->getMessage() );
			$this->assertStringNotContainsString( 'ATTACKER-PLANTED', $refusal->getMessage(), 'The refusal message must never contain the statement bytes, only the server-reported engine.' );
		}

		$this->assertSame( 1, $this->myisam_canary_row_count(), 'The attacker-planted row must never have been written to the live table.' );
		$this->assertSame( self::MYISAM_CANARY_VALUE, $this->myisam_canary_value(), 'The canary row must be byte-unchanged: the write-through INSERT must never have executed.' );
		$this->assertSame( array(), $this->leftover_pontifex_tables(), 'A refused restore must leave no staging or parked residue — including the MERGE table itself, whose DROP never touches the live table it unioned.' );
	}

	/**
	 * A `WITH SYSTEM VERSIONING` table must round-trip through a real restore (ADR 0019, defect 1).
	 *
	 * MariaDB reports such a table's TABLE_TYPE as "SYSTEM VERSIONED", not
	 * "BASE TABLE" — confirmed empirically against a live MariaDB server
	 * (12.3.2). Restore is all-or-nothing, so before the table-type
	 * allow-list was widened this ONE table aborted the WHOLE restore: a
	 * site could not restore its own, entirely legitimate backup. Proven
	 * here against a real MariaDB server, through the real RestoreRunner,
	 * with the table's own real `SHOW CREATE TABLE` definition (captured
	 * from the fixture table itself, not hand-typed) replayed verbatim.
	 *
	 * @return void
	 */
	public function test_system_versioned_table_round_trips_through_a_real_restore(): void {
		global $wpdb;
		$create_sql = $this->create_system_versioned_table();

		$runner = new RestoreRunner(
			new EntryReader( CodecRegistry::with_defaults() ),
			new FileWriter( $this->fixture_root ),
			new DatabaseWriter( new WpdbAdapter( $wpdb ) )
		);

		$runner->restore( $this->system_versioned_archive( $create_sql ) );

		$this->assertSame( self::SYSVER_VALUE, $this->sysver_value(), 'The row must survive the restore of a system-versioned table.' );
		$this->assertSame( array(), $this->leftover_pontifex_tables(), 'A successful restore must leave no staging or parked residue.' );
	}

	/**
	 * A partitioned table whose CREATE names a DATA DIRECTORY on one of its
	 * partitions must be refused, and must leave no file behind at the
	 * attacker-chosen path (ADR 0019, defect 2).
	 *
	 * Table-level `DATA DIRECTORY` is already refused by the existing
	 * CREATE_OPTIONS check, but the per-partition form is invisible there —
	 * `information_schema.TABLES.CREATE_OPTIONS` for a table built this way
	 * reports only the single word "partitioned", confirmed empirically
	 * against a live MariaDB server. Before this second check, the restore
	 * COMPLETED and the server wrote a `.MYD` file straight into `/tmp/` —
	 * an arbitrary-path file write by the database process.
	 *
	 * `LOAD_FILE()` cannot prove the file is gone: `@@secure_file_priv` is
	 * NULL on the test server, which disables file-reading SQL functions
	 * outright rather than merely restricting their directory, so
	 * `LOAD_FILE()` returns NULL unconditionally regardless of whether the
	 * file exists — confirmed empirically, and the reason the assertion this
	 * test used to make was inert. The PHPUnit process and mysqld also run in
	 * separate containers under wp-env with no shared filesystem (see the
	 * class docblock), so this test cannot list `/tmp/` directly either.
	 * Instead {@see self::assert_no_orphaned_partition_files_at()}
	 * ATTEMPTS TO RECREATE a table at the exact same physical DATA DIRECTORY
	 * path the refused restore would have used: MyISAM refuses to overwrite
	 * an existing `.MYD` file there with "Errcode: 17 (File exists)" —
	 * confirmed empirically, by leaving a stray file at that exact path and
	 * observing the real error — so a successful recreate is real,
	 * server-reported proof the file is gone, not an inert read. The CREATE
	 * necessarily executes before the containment check can see what it
	 * built (see the class docblock's Verification point 5), so this proves
	 * {@see \Pontifex\Restore\DatabaseWriter::abort_staging()}'s cleanup DROP
	 * removes a MyISAM partition's relocated file, wherever DATA DIRECTORY
	 * sent it, not only its catalogue entry.
	 *
	 * @return void
	 * @throws AssertionFailedError Rethrown immediately if restore() failed to throw (see the catch block below); never swallowed.
	 */
	public function test_partition_level_data_directory_only_is_refused_and_leaves_no_file_behind(): void {
		global $wpdb;

		$runner = new RestoreRunner(
			new EntryReader( CodecRegistry::with_defaults() ),
			new FileWriter( $this->fixture_root ),
			new DatabaseWriter( new WpdbAdapter( $wpdb ) )
		);

		try {
			$runner->restore( $this->partition_directory_archive( true, false ) );
			// Deliberately avoids the word "partition": the catch block below
			// asserts the real refusal message contains it, and a fail() message
			// containing it too would let the assertion match its own failure text
			// instead of the writer's.
			$this->fail( 'restore() should have refused this storage-directory clause.' );
		} catch ( AssertionFailedError $bug ) {
			// self::fail()'s own AssertionFailedError extends RuntimeException, so it
			// would otherwise be swallowed by the catch below — rethrow it before that
			// catch ever sees it, so a missing refusal fails this test for real.
			throw $bug;
		} catch ( RuntimeException $refusal ) {
			$this->assertStringContainsString( 'partition', $refusal->getMessage() );
		}

		$this->assert_no_orphaned_partition_files_at( true, false );
		$this->assertSame( array(), $this->leftover_pontifex_tables(), 'A refused restore must leave no staging or parked residue.' );
	}

	/**
	 * The same proof as {@see self::test_partition_level_data_directory_only_is_refused_and_leaves_no_file_behind()},
	 * for a partition naming only an INDEX DIRECTORY (no DATA DIRECTORY on the
	 * same partition) — proves the check catches each clause independently,
	 * not only the pair together.
	 *
	 * @return void
	 * @throws AssertionFailedError Rethrown immediately if restore() failed to throw (see the catch block below); never swallowed.
	 */
	public function test_partition_level_index_directory_only_is_refused_and_leaves_no_file_behind(): void {
		global $wpdb;

		$runner = new RestoreRunner(
			new EntryReader( CodecRegistry::with_defaults() ),
			new FileWriter( $this->fixture_root ),
			new DatabaseWriter( new WpdbAdapter( $wpdb ) )
		);

		try {
			$runner->restore( $this->partition_directory_archive( false, true ) );
			$this->fail( 'restore() should have refused this storage-directory clause.' );
		} catch ( AssertionFailedError $bug ) {
			// See test_partition_level_data_directory_only_is_refused_and_leaves_no_file_behind()
			// above for why this rethrow must run before the RuntimeException catch below.
			throw $bug;
		} catch ( RuntimeException $refusal ) {
			$this->assertStringContainsString( 'partition', $refusal->getMessage() );
		}

		$this->assert_no_orphaned_partition_files_at( false, true );
		$this->assertSame( array(), $this->leftover_pontifex_tables(), 'A refused restore must leave no staging or parked residue.' );
	}

	/**
	 * A partition-level COMMENT containing an apostrophe, on an EARLIER partition
	 * than the one naming a DATA DIRECTORY, must still be refused — proven end to
	 * end against a real MariaDB server (12.3.2, the escaping-model unification
	 * fix in {@see \Pontifex\Manifest\SqlSpanScanner}).
	 *
	 * Confirmed empirically against this exact server: MariaDB re-serialises a
	 * partition's `COMMENT` value with BACKSLASH escaping — `SHOW CREATE TABLE`
	 * echoes back `COMMENT = 'it\'s'` even though the CREATE statement below
	 * supplies the doubled form (`COMMENT = 'it''s'`) — while an ordinary table
	 * or column `COMMENT` in the SAME session, under the SAME sql_mode, is
	 * echoed back with DOUBLED-QUOTE escaping instead. Before the fix, this
	 * class's own `SHOW CREATE TABLE` scan recognised only the doubled form: it
	 * closed the quoted span one byte early, at the escaped apostrophe, which
	 * re-opened a bogus quoted span over the text that followed and swallowed
	 * the later, genuinely structural `DATA DIRECTORY = ` clause into it —
	 * `refused` came back `false`, the table restored, and mysqld wrote a real
	 * `.MYD` file at `/tmp`. The two scanners in this codebase (this one, and
	 * {@see \Pontifex\Restore\DatabaseWriter}'s own statement scan) used
	 * different escaping models; unifying them onto one shared, escaping-aware
	 * scanner is what closes this.
	 *
	 * @return void
	 * @throws AssertionFailedError Rethrown immediately if restore() failed to throw (see the catch block below); never swallowed.
	 */
	public function test_partition_comment_apostrophe_hiding_later_data_directory_is_refused_and_leaves_no_file_behind(): void {
		global $wpdb;

		$runner = new RestoreRunner(
			new EntryReader( CodecRegistry::with_defaults() ),
			new FileWriter( $this->fixture_root ),
			new DatabaseWriter( new WpdbAdapter( $wpdb ) )
		);

		try {
			$runner->restore( $this->partition_comment_apostrophe_archive() );
			// Deliberately avoids the word "partition": the catch block below
			// asserts the real refusal message contains it, and a fail() message
			// containing it too would let the assertion match its own failure text
			// instead of the writer's.
			$this->fail( 'restore() should have refused this storage-directory clause.' );
		} catch ( AssertionFailedError $bug ) {
			// See test_partition_level_data_directory_only_is_refused_and_leaves_no_file_behind()
			// above for why this rethrow must run before the RuntimeException catch below.
			throw $bug;
		} catch ( RuntimeException $refusal ) {
			$this->assertStringContainsString( 'partition', $refusal->getMessage() );
		}

		$this->assert_no_orphaned_partition_p1_file_at_tmp();
		$this->assertSame( array(), $this->leftover_pontifex_tables(), 'A refused restore must leave no staging or parked residue.' );
	}

	/**
	 * A legitimately partitioned table — no DATA DIRECTORY or INDEX DIRECTORY on any
	 * partition — must still restore normally; the new check must not refuse every
	 * partitioned table, only the ones that actually redirect storage.
	 *
	 * @return void
	 */
	public function test_partitioned_table_without_a_storage_directory_round_trips(): void {
		global $wpdb;

		$runner = new RestoreRunner(
			new EntryReader( CodecRegistry::with_defaults() ),
			new FileWriter( $this->fixture_root ),
			new DatabaseWriter( new WpdbAdapter( $wpdb ) )
		);

		$runner->restore( $this->ordinary_partitioned_archive() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test assertion: confirm the legitimately partitioned table actually exists after the restore.
		$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::scratch_table( 'pontifextest_partitioned' ) ) );
		$this->assertSame( '1', (string) $count, 'A legitimately partitioned table must restore its row.' );
		$this->assertSame( array(), $this->leftover_pontifex_tables(), 'A successful restore must leave no staging or parked residue.' );
	}

	// -----------------------------------------------------------------
	// Must-permit: a partitioned table that merely MENTIONS "DATA DIRECTORY" or
	// "INDEX DIRECTORY" inside a quoted value or identifier, not as a real clause
	// (ADR 0019, defect 3 — the false-refusal regression closed by matching only
	// the definition's structural SQL syntax, never a quoted span).
	// -----------------------------------------------------------------

	/**
	 * A table COMMENT that mentions "DATA DIRECTORY" as ordinary text must round-trip.
	 *
	 * @return void
	 */
	public function test_table_comment_mentioning_data_directory_round_trips(): void {
		$this->assert_partition_mention_round_trips(
			"CREATE TABLE `%1\$s` (`id` INT NOT NULL) ENGINE=InnoDB COMMENT='old DATA DIRECTORY = /mnt no really' "
				. 'PARTITION BY RANGE (`id`) (PARTITION p0 VALUES LESS THAN (100));'
		);
	}

	/**
	 * A column COMMENT that mentions "DATA DIRECTORY" as ordinary text must round-trip.
	 *
	 * @return void
	 */
	public function test_column_comment_mentioning_data_directory_round_trips(): void {
		$this->assert_partition_mention_round_trips(
			"CREATE TABLE `%1\$s` (`id` INT NOT NULL COMMENT 'old DATA DIRECTORY = /mnt') ENGINE=InnoDB "
				. 'PARTITION BY RANGE (`id`) (PARTITION p0 VALUES LESS THAN (100));'
		);
	}

	/**
	 * A partition COMMENT that mentions "INDEX DIRECTORY" as ordinary text must round-trip.
	 *
	 * @return void
	 */
	public function test_partition_comment_mentioning_index_directory_round_trips(): void {
		$this->assert_partition_mention_round_trips(
			'CREATE TABLE `%1$s` (`id` INT NOT NULL) ENGINE=MyISAM '
				. "PARTITION BY RANGE (`id`) (PARTITION p0 VALUES LESS THAN (100) COMMENT 'old INDEX DIRECTORY = /mnt');"
		);
	}

	/**
	 * A column DEFAULT string that mentions "DATA DIRECTORY" as ordinary text must round-trip.
	 *
	 * @return void
	 */
	public function test_column_default_mentioning_data_directory_round_trips(): void {
		$this->assert_partition_mention_round_trips(
			"CREATE TABLE `%1\$s` (`id` INT NOT NULL, `note` VARCHAR(64) DEFAULT 'DATA DIRECTORY = /mnt') ENGINE=InnoDB "
				. 'PARTITION BY RANGE (`id`) (PARTITION p0 VALUES LESS THAN (100));'
		);
	}

	/**
	 * A column literally NAMED "DATA DIRECTORY =" must round-trip.
	 *
	 * @return void
	 */
	public function test_column_named_data_directory_round_trips(): void {
		$this->assert_partition_mention_round_trips(
			'CREATE TABLE `%1$s` (`id` INT NOT NULL, `DATA DIRECTORY =` INT NOT NULL) ENGINE=InnoDB '
				. 'PARTITION BY RANGE (`id`) (PARTITION p0 VALUES LESS THAN (100));'
		);
	}

	/**
	 * Restore a partitioned table built from $create_sql_template through the REAL
	 * RestoreRunner and a real MariaDB server, and assert it round-trips (is not
	 * refused) and leaves no residue — the shared body of the five must-permit
	 * tests above.
	 *
	 * $create_sql_template is a sprintf() template carrying a single `%1$s`
	 * placeholder for the live-prefixed table name, rather than the table name
	 * baked in by each of the five callers — the one place this method (and,
	 * through it, every caller) gets the live prefix, so it is never scattered
	 * across the five call sites individually.
	 *
	 * @param string $create_sql_template The table's CREATE statement (no leading DROP, no trailing INSERT), with `%1$s` standing in for the table name.
	 * @return void
	 */
	private function assert_partition_mention_round_trips( string $create_sql_template ): void {
		global $wpdb;

		$table      = self::scratch_table( 'pontifextest_partitioned' );
		$create_sql = sprintf( $create_sql_template, $table );

		$sql = "DROP TABLE IF EXISTS `{$table}`;\n"
			. $create_sql . "\n"
			. "INSERT INTO `{$table}` (`id`) VALUES (1);\n";

		$header = EntryHeader::for_db_chunk( 0, $table, 3, strlen( $sql ), 0 );
		$plans  = array( new EntryPlan( $header, RawCodec::ID, str_repeat( "\0", EntryWriter::NONCE_SIZE ), self::memory_stream( $sql ) ) );

		$writer  = new ArchiveWriter( new EntryWriter( CodecRegistry::with_defaults() ), new FooterWriter() );
		$archive = self::memory_stream();
		$writer->write_archive( self::sample_provenance(), $plans, $archive );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource, not a filesystem path.
		rewind( $archive );

		$runner = new RestoreRunner(
			new EntryReader( CodecRegistry::with_defaults() ),
			new FileWriter( $this->fixture_root ),
			new DatabaseWriter( new WpdbAdapter( $wpdb ) )
		);
		$runner->restore( $archive );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test assertion: confirm the table actually exists and carries its row after the restore.
		$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
		$this->assertSame( '1', (string) $count, 'A partitioned table that merely mentions the words must still restore its row.' );
		$this->assertSame( array(), $this->leftover_pontifex_tables(), 'A successful restore must leave no staging or parked residue.' );
	}

	/**
	 * A MariaDB SEQUENCE, exported by the REAL DatabaseScanner and restored through the
	 * REAL RestoreRunner against a real server, must round-trip (ADR 0019, defect 4).
	 *
	 * Confirmed empirically against a live MariaDB server (12.3.2): `CREATE
	 * SEQUENCE` reads `information_schema.TABLES.TABLE_TYPE = 'SEQUENCE'`,
	 * `ENGINE = 'InnoDB'` (already allow-listed), `CREATE_OPTIONS = ''` — and
	 * `SHOW TABLES` lists a sequence alongside ordinary tables, so Pontifex's
	 * own scanner exports one as an entirely ordinary db_chunk without any
	 * special-casing. Before this widened allow-list, {@see \Pontifex\Restore\DatabaseWriter}
	 * refused the resulting CREATE outright — and because restore is
	 * all-or-nothing, one sequence anywhere on a site made the WHOLE restore
	 * fail, with no route forward for the operator. Proven here with the REAL
	 * scanner (not a hand-built chunk) producing the archive, and a real
	 * `SELECT NEXTVAL(...)` proving the restored object is a genuinely working
	 * sequence, not merely a table that exists.
	 *
	 * @return void
	 */
	public function test_mariadb_sequence_round_trips_through_a_real_export_and_restore(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'pontifextest_seq';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test fixture: create a real MariaDB sequence, prefixed like a real WordPress table so the real scanner's SHOW TABLES LIKE picks it up.
		$wpdb->query( $wpdb->prepare( 'CREATE SEQUENCE %i START WITH 1 INCREMENT BY 1', $table ) );

		$adapter = new WpdbAdapter( $wpdb );
		$chunks  = ( new DatabaseScanner( $adapter, ExclusionRules::none() ) )->scan();
		$chunk   = null;
		foreach ( $chunks as $candidate ) {
			if ( $candidate->table_name() === $table ) {
				$chunk = $candidate;
				break;
			}
		}
		$this->assertNotNull( $chunk, 'The real scanner must produce a chunk for the sequence, exported like an ordinary table.' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_stream_get_contents -- Operating on the scanner's in-memory SQL stream, not a filesystem path.
		$sql = (string) stream_get_contents( $chunk->open_sql_stream() );

		$header = EntryHeader::for_db_chunk( 0, $table, $chunk->statement_count(), strlen( $sql ), 0 );
		$plans  = array( new EntryPlan( $header, RawCodec::ID, str_repeat( "\0", EntryWriter::NONCE_SIZE ), self::memory_stream( $sql ) ) );

		$writer  = new ArchiveWriter( new EntryWriter( CodecRegistry::with_defaults() ), new FooterWriter() );
		$archive = self::memory_stream();
		$writer->write_archive( self::sample_provenance(), $plans, $archive );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource, not a filesystem path.
		rewind( $archive );

		// The live sequence is dropped so the archive's own DROP + CREATE rebuilds it via
		// staging, exactly as a real restore over an existing site would.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test fixture: drop the live sequence now that its export has been captured.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $table ) );

		$runner = new RestoreRunner(
			new EntryReader( CodecRegistry::with_defaults() ),
			new FileWriter( $this->fixture_root ),
			new DatabaseWriter( new WpdbAdapter( $wpdb ) )
		);
		$runner->restore( $archive );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test assertion: prove the restored object is a genuinely working sequence, not merely a table that happens to exist.
		$next = $wpdb->get_var( $wpdb->prepare( 'SELECT NEXTVAL(%i)', $table ) );
		$this->assertSame( '1', (string) $next, 'The restored sequence must produce the same next value a freshly created one would.' );

		$leftover_staged = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( 'pontifexstg_' . $table ) ) );
		$leftover_parked = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( 'pontifexold_' . $table ) ) );
		$this->assertSame( array(), $leftover_staged, 'A successful restore must leave no staging residue.' );
		$this->assertSame( array(), $leftover_parked, 'A successful restore must leave no parked residue.' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test cleanup: drop the restored sequence.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
	}

	// -----------------------------------------------------------------
	// Mutation backlog (db-chunk-containment), items 6 and 7: guards only a
	// real server, with a real second schema and a real session, can pin.
	// -----------------------------------------------------------------

	/**
	 * A table that exists ONLY in a DIFFERENT schema, sharing the exact name a
	 * staged identifier would use, must never answer for it — proven against a
	 * real second database on the real server.
	 *
	 * Pins WpdbAdapter::table_storage_facts()'s `TABLE_SCHEMA = DATABASE()`
	 * filter (item 6): information_schema.TABLES is server-wide, so a query
	 * naming only TABLE_NAME can match a same-named table in ANY schema. This
	 * probe table is built with the ARCHIVE engine — one this codebase refuses
	 * outright — precisely so a leak from the wrong schema would be
	 * unmistakable: without the schema filter, this call would report the
	 * OTHER schema's engine instead of correctly reporting "not found" in the
	 * current one.
	 *
	 * @return void
	 */
	public function test_table_storage_facts_ignores_a_same_named_table_in_another_schema(): void {
		global $wpdb;

		$other_schema = 'pontifextest_other_schema';
		$table        = 'pontifexstg_pontifextest_schema_guard_probe';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Integration test fixture: create a scratch schema outside the connection's own database; $other_schema is a fixed, test-controlled literal, never external input.
		$wpdb->query( "CREATE DATABASE IF NOT EXISTS `{$other_schema}`" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Integration test fixture: a same-named table in that schema, using a refused engine so a leak is unmistakable; both interpolated values are fixed, test-controlled literals.
		$wpdb->query( "CREATE TABLE `{$other_schema}`.`{$table}` (`id` INT NOT NULL) ENGINE=CSV" );

		try {
			$adapter = new WpdbAdapter( $wpdb );
			$facts   = $adapter->table_storage_facts( $table );

			$this->assertNull( $facts, 'A table that exists only in a DIFFERENT schema must be reported as not found in the current one.' );
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Integration test cleanup: drop the scratch schema this test created; $other_schema is a fixed, test-controlled literal.
			$wpdb->query( "DROP DATABASE IF EXISTS `{$other_schema}`" );
		}
	}

	/**
	 * WpdbAdapter::sql_mode() must read the real connection's own SESSION
	 * sql_mode, not a canned or unconditional answer — proven by setting a
	 * distinctive mode on the real session and reading it back.
	 *
	 * Pins item 7: this method had no test at all before — every existing test
	 * of sql_mode-driven behaviour goes through FakeDbAdapter, so forcing the
	 * real WpdbAdapter::sql_mode() to always return null (or any other fixed
	 * value) left the whole suite green.
	 *
	 * @return void
	 */
	public function test_sql_mode_reads_the_real_connections_session_mode(): void {
		global $wpdb;

		// Captured, not assumed: WordPress's own wpdb::set_sql_mode() strips
		// STRICT_TRANS_TABLES and other modes at connection time for
		// compatibility, so the session's real starting point is NOT the
		// server's GLOBAL default — "SET SESSION sql_mode = DEFAULT" would
		// restore the wrong (stricter) value and corrupt every other
		// integration test that runs later in this process, under
		// phpunit-integration.xml.dist's randomised execution order.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test fixture: capture the connection's real starting sql_mode before changing it.
		$original_mode = (string) $wpdb->get_var( 'SELECT @@SESSION.sql_mode' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test fixture: set a distinctive SESSION sql_mode to read back.
		$wpdb->query( "SET SESSION sql_mode = 'NO_BACKSLASH_ESCAPES'" );

		try {
			$adapter = new WpdbAdapter( $wpdb );
			$mode    = $adapter->sql_mode();

			$this->assertIsString( $mode, 'sql_mode() must read a real string from the connection, not report unreadable.' );
			$this->assertStringContainsString( 'NO_BACKSLASH_ESCAPES', (string) $mode, 'sql_mode() must report the REAL session value just set, not a canned or unconditional answer.' );
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test cleanup: hand the session's sql_mode back to the exact value captured above, not the server's global default.
			$wpdb->query( $wpdb->prepare( 'SET SESSION sql_mode = %s', $original_mode ) );
		}
	}

	/**
	 * Create a real WITH SYSTEM VERSIONING fixture table, seed it, and return the
	 * server's own SHOW CREATE TABLE definition for it.
	 *
	 * @return string The server's own CREATE TABLE definition, confirmed to carry WITH SYSTEM VERSIONING.
	 */
	private function create_system_versioned_table(): string {
		global $wpdb;
		$table = self::scratch_table( 'pontifextest_sysver' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test fixture: create a WITH SYSTEM VERSIONING table.
		$wpdb->query( $wpdb->prepare( 'CREATE TABLE %i (id INT NOT NULL PRIMARY KEY, val VARCHAR(64)) WITH SYSTEM VERSIONING', $table ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test fixture: seed the row that must survive the restore.
		$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (id, val) VALUES (1, %s)', $table, self::SYSVER_VALUE ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test fixture: capture the server's own CREATE TABLE definition, exactly as SHOW CREATE TABLE reports it.
		$row = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $table ), ARRAY_N );
		$this->assertIsArray( $row, 'SHOW CREATE TABLE must succeed for the fixture table.' );
		$create_sql = (string) $row[1];
		$this->assertStringContainsString( 'WITH SYSTEM VERSIONING', $create_sql, 'The fixture table must genuinely be system-versioned.' );

		// The fixture table is dropped so the archive's own DROP + CREATE replays
		// cleanly against staging without colliding with the live one.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test fixture: drop the live table now that its definition has been captured, so the restore's own DROP/CREATE builds it back via staging.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $table ) );

		return $create_sql;
	}

	/**
	 * Build an in-memory archive whose one db_chunk restores a system-versioned table.
	 *
	 * @param string $create_sql The server's own CREATE TABLE definition, from {@see self::create_system_versioned_table()}.
	 * @return resource A readable, seekable stream containing the archive bytes.
	 */
	private function system_versioned_archive( string $create_sql ) {
		$table = self::scratch_table( 'pontifextest_sysver' );
		$sql   = "DROP TABLE IF EXISTS `{$table}`;\n"
			. "{$create_sql};\n"
			. "INSERT INTO `{$table}` (`id`, `val`) VALUES (1, '" . self::SYSVER_VALUE . "');\n";

		$header = EntryHeader::for_db_chunk( 0, $table, 3, strlen( $sql ), 0 );
		$plans  = array( new EntryPlan( $header, RawCodec::ID, str_repeat( "\0", EntryWriter::NONCE_SIZE ), self::memory_stream( $sql ) ) );

		$writer = new ArchiveWriter( new EntryWriter( CodecRegistry::with_defaults() ), new FooterWriter() );
		$dest   = self::memory_stream();
		$writer->write_archive( self::sample_provenance(), $plans, $dest );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource, not a filesystem path.
		rewind( $dest );
		return $dest;
	}

	/**
	 * Read back the system-versioned fixture row's current value.
	 *
	 * @return string|null The value, or null when the table or row is missing.
	 */
	private function sysver_value(): ?string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test assertion: read back the system-versioned fixture row.
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT val FROM %i WHERE id = 1', self::scratch_table( 'pontifextest_sysver' ) ) );
		return null === $value ? null : (string) $value;
	}

	/**
	 * Build an in-memory archive whose one db_chunk CREATEs a MyISAM partitioned table
	 * naming a DATA DIRECTORY and/or an INDEX DIRECTORY on one of its partitions.
	 *
	 * `/tmp` is used directly as the target — it always exists in the database
	 * server's own container, unlike a bespoke path this test would have no way to
	 * pre-create there (the PHPUnit process and mysqld run in separate containers
	 * under wp-env, with no shared filesystem) — and is the exact path this
	 * vulnerability was proven against.
	 *
	 * @param bool $data_directory  Whether the partition clause names a DATA DIRECTORY.
	 * @param bool $index_directory Whether the partition clause names an INDEX DIRECTORY.
	 * @return resource A readable, seekable stream containing the archive bytes.
	 */
	private function partition_directory_archive( bool $data_directory, bool $index_directory ) {
		$clauses = array();
		if ( $data_directory ) {
			$clauses[] = "DATA DIRECTORY='/tmp'";
		}
		if ( $index_directory ) {
			$clauses[] = "INDEX DIRECTORY='/tmp'";
		}

		$table = self::scratch_table( 'pontifextest_partitioned' );
		$sql   = "DROP TABLE IF EXISTS `{$table}`;\n"
			. "CREATE TABLE `{$table}` (`id` INT NOT NULL) ENGINE=MyISAM "
			. 'PARTITION BY RANGE (`id`) (PARTITION p0 VALUES LESS THAN (100) ' . implode( ' ', $clauses ) . ");\n";

		$header = EntryHeader::for_db_chunk( 0, $table, 2, strlen( $sql ), 0 );
		$plans  = array( new EntryPlan( $header, RawCodec::ID, str_repeat( "\0", EntryWriter::NONCE_SIZE ), self::memory_stream( $sql ) ) );

		$writer = new ArchiveWriter( new EntryWriter( CodecRegistry::with_defaults() ), new FooterWriter() );
		$dest   = self::memory_stream();
		$writer->write_archive( self::sample_provenance(), $plans, $dest );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource, not a filesystem path.
		rewind( $dest );
		return $dest;
	}

	/**
	 * Prove no `.MYD`/`.MYI` file was left at `/tmp` for the staged partitioned-table
	 * fixture, by attempting to recreate a table at the exact same physical path.
	 *
	 * See {@see self::test_partition_level_data_directory_only_is_refused_and_leaves_no_file_behind()}'s
	 * docblock for why this is the real, server-reported proof available under
	 * wp-env's container layout, and how the "File exists" failure mode was
	 * confirmed empirically. The probe table is dropped again immediately, so a
	 * passing test leaves no trace either way.
	 *
	 * @param bool $data_directory  Whether the original clause named a DATA DIRECTORY.
	 * @param bool $index_directory Whether the original clause named an INDEX DIRECTORY.
	 * @return void
	 */
	private function assert_no_orphaned_partition_files_at( bool $data_directory, bool $index_directory ): void {
		global $wpdb;

		$clauses = array();
		if ( $data_directory ) {
			$clauses[] = "DATA DIRECTORY='/tmp'";
		}
		if ( $index_directory ) {
			$clauses[] = "INDEX DIRECTORY='/tmp'";
		}

		$probe = 'pontifexstg_' . self::scratch_table( 'pontifextest_partitioned' );
		$sql   = "CREATE TABLE `{$probe}` (`id` INT NOT NULL) ENGINE=MyISAM "
			. 'PARTITION BY RANGE (`id`) (PARTITION p0 VALUES LESS THAN (100) ' . implode( ' ', $clauses ) . ')';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test assertion: recreate a fixed, test-controlled table at the exact physical DATA/INDEX DIRECTORY path the refused restore used, to prove via a real server error (not an inert LOAD_FILE() read) that no file was left behind.
		$wpdb->query( $sql );
		$this->assertSame(
			'',
			(string) $wpdb->last_error,
			'Recreating a table at the same physical DATA/INDEX DIRECTORY path must succeed — a "File exists" error here would mean the refused restore left a real file behind.'
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test cleanup: drop the probe table created immediately above.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $probe ) );
	}

	/**
	 * Build an in-memory archive whose one db_chunk CREATEs a MyISAM partitioned table
	 * with TWO partitions — an earlier one, "p0", carrying a COMMENT containing a
	 * literal apostrophe, and a later one, "p1", naming a DATA DIRECTORY — the
	 * exact shape proven against real MariaDB (12.3.2) to defeat a scanner that
	 * does not recognise backslash escaping in the server's own `SHOW CREATE
	 * TABLE` re-serialisation of the COMMENT.
	 *
	 * @return resource A readable, seekable stream containing the archive bytes.
	 */
	private function partition_comment_apostrophe_archive() {
		$table = self::scratch_table( 'pontifextest_partitioned' );
		$sql   = "DROP TABLE IF EXISTS `{$table}`;\n"
			. "CREATE TABLE `{$table}` (`id` INT NOT NULL) ENGINE=MyISAM PARTITION BY RANGE (`id`) "
			. "(PARTITION p0 VALUES LESS THAN (100) COMMENT = 'it''s', PARTITION p1 VALUES LESS THAN MAXVALUE DATA DIRECTORY='/tmp');\n";

		$header = EntryHeader::for_db_chunk( 0, $table, 2, strlen( $sql ), 0 );
		$plans  = array( new EntryPlan( $header, RawCodec::ID, str_repeat( "\0", EntryWriter::NONCE_SIZE ), self::memory_stream( $sql ) ) );

		$writer = new ArchiveWriter( new EntryWriter( CodecRegistry::with_defaults() ), new FooterWriter() );
		$dest   = self::memory_stream();
		$writer->write_archive( self::sample_provenance(), $plans, $dest );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource, not a filesystem path.
		rewind( $dest );
		return $dest;
	}

	/**
	 * Prove no `.MYD`/`.MYI` file was left at `/tmp` for partition "p1" of the
	 * {@see self::partition_comment_apostrophe_archive()} fixture, by attempting
	 * to recreate the identical two-partition shape at the exact same physical
	 * path.
	 *
	 * A different helper from {@see self::assert_no_orphaned_partition_files_at()}
	 * because that one recreates only a single partition, "p0", carrying the
	 * DATA/INDEX DIRECTORY clause directly — MySQL/MariaDB's per-partition file
	 * naming embeds the PARTITION NAME itself, so proving no file was left for
	 * THIS fixture's "p1" requires recreating a table whose partitions are named
	 * "p0" then "p1" in the same order, not a single differently-shaped "p0".
	 * The probe table is dropped again immediately, so a passing test leaves no
	 * trace either way.
	 *
	 * @return void
	 */
	private function assert_no_orphaned_partition_p1_file_at_tmp(): void {
		global $wpdb;

		$probe = 'pontifexstg_' . self::scratch_table( 'pontifextest_partitioned' );
		$sql   = "CREATE TABLE `{$probe}` (`id` INT NOT NULL) ENGINE=MyISAM PARTITION BY RANGE (`id`) "
			. "(PARTITION p0 VALUES LESS THAN (100) COMMENT = 'it''s', PARTITION p1 VALUES LESS THAN MAXVALUE DATA DIRECTORY='/tmp')";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test assertion: recreate a fixed, test-controlled table at the exact physical DATA DIRECTORY path partition "p1" of the refused restore would have used, to prove via a real server error (not an inert LOAD_FILE() read) that no file was left behind.
		$wpdb->query( $sql );
		$this->assertSame(
			'',
			(string) $wpdb->last_error,
			'Recreating the same two-partition shape at the same physical DATA DIRECTORY path must succeed — a "File exists" error here would mean the refused restore left a real file behind.'
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test cleanup: drop the probe table created immediately above.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $probe ) );
	}

	/**
	 * Build an in-memory archive whose one db_chunk CREATEs and populates a legitimately
	 * partitioned table — no partition names a storage directory at all.
	 *
	 * @return resource A readable, seekable stream containing the archive bytes.
	 */
	private function ordinary_partitioned_archive() {
		$table = self::scratch_table( 'pontifextest_partitioned' );
		$sql   = "DROP TABLE IF EXISTS `{$table}`;\n"
			. "CREATE TABLE `{$table}` (`id` INT NOT NULL) ENGINE=InnoDB "
			. "PARTITION BY RANGE (`id`) (PARTITION p0 VALUES LESS THAN (100), PARTITION p1 VALUES LESS THAN MAXVALUE);\n"
			. "INSERT INTO `{$table}` (`id`) VALUES (1);\n";

		$header = EntryHeader::for_db_chunk( 0, $table, 3, strlen( $sql ), 0 );
		$plans  = array( new EntryPlan( $header, RawCodec::ID, str_repeat( "\0", EntryWriter::NONCE_SIZE ), self::memory_stream( $sql ) ) );

		$writer = new ArchiveWriter( new EntryWriter( CodecRegistry::with_defaults() ), new FooterWriter() );
		$dest   = self::memory_stream();
		$writer->write_archive( self::sample_provenance(), $plans, $dest );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource, not a filesystem path.
		rewind( $dest );
		return $dest;
	}

	/**
	 * Build an in-memory archive whose first db_chunk CREATEs a MERGE table over a
	 * live MyISAM table, and whose second db_chunk INSERTs through it.
	 *
	 * Both chunks declare the same table name ("pontifextest_beta"), so
	 * DatabaseWriter's own identifier rewrite stages each into
	 * `pontifexstg_pontifextest_beta` exactly as it would for a legitimate
	 * multi-chunk table — the only hostile part is the CREATE's body, which the
	 * shape check never inspects, and which names the live canary table directly.
	 *
	 * @return resource A readable, seekable stream containing the archive bytes.
	 */
	private function merge_write_through_archive() {
		$beta          = self::scratch_table( 'pontifextest_beta' );
		$myisam_canary = self::scratch_table( 'pontifextest_myisam_canary' );

		$create_sql = "CREATE TABLE `{$beta}` (`ID` INT NOT NULL, `user_pass` VARCHAR(64) NOT NULL, PRIMARY KEY (`ID`)) "
			. "ENGINE=MRG_MyISAM UNION=(`{$myisam_canary}`) INSERT_METHOD=LAST;\n";
		$insert_sql = "INSERT INTO `{$beta}` (`ID`, `user_pass`) VALUES (99, 'ATTACKER-PLANTED');\n";

		$create_header = EntryHeader::for_db_chunk( 0, $beta, 1, strlen( $create_sql ), 0 );
		$insert_header = EntryHeader::for_db_chunk( 1, $beta, 1, strlen( $insert_sql ), 0 );

		$plans = array(
			new EntryPlan( $create_header, RawCodec::ID, str_repeat( "\0", EntryWriter::NONCE_SIZE ), self::memory_stream( $create_sql ) ),
			new EntryPlan( $insert_header, RawCodec::ID, str_repeat( "\0", EntryWriter::NONCE_SIZE ), self::memory_stream( $insert_sql ) ),
		);

		$writer = new ArchiveWriter( new EntryWriter( CodecRegistry::with_defaults() ), new FooterWriter() );
		$dest   = self::memory_stream();
		$writer->write_archive( self::sample_provenance(), $plans, $dest );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource, not a filesystem path.
		rewind( $dest );
		return $dest;
	}

	/**
	 * Create the live MyISAM table a MERGE write-through would union and plant a row into.
	 *
	 * MyISAM specifically: MRG_MyISAM can only union MyISAM tables (Aria and
	 * InnoDB fail the CREATE outright with "Unable to open underlying table"),
	 * so this is a dedicated table separate from {@see self::create_canary_table()}'s
	 * InnoDB-default one. Its column and index definitions match the MERGE
	 * table {@see self::merge_write_through_archive()} declares EXACTLY —
	 * confirmed empirically that MariaDB's MRG_MyISAM refuses to open a union
	 * over a table that is "differently defined", which would report a NULL
	 * (not "MRG_MyISAM") engine and mask what this test is proving: that the
	 * containment check refuses a MERGE that WOULD have worked, not one that
	 * was already broken on its own.
	 *
	 * @return void
	 */
	private function create_myisam_canary_table(): void {
		global $wpdb;
		$table = self::scratch_table( 'pontifextest_myisam_canary' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test fixture: create the MyISAM canary table.
		$wpdb->query( $wpdb->prepare( 'CREATE TABLE %i (ID INT NOT NULL, user_pass VARCHAR(64) NOT NULL, PRIMARY KEY (ID)) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4', $table ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test fixture: seed the MyISAM canary row.
		$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (ID, user_pass) VALUES (1, %s)', $table, self::MYISAM_CANARY_VALUE ) );
	}

	/**
	 * Read the MyISAM canary row's current value.
	 *
	 * @return string|null The value, or null when the table or row is missing.
	 */
	private function myisam_canary_value(): ?string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test assertion: read back the MyISAM canary row.
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT user_pass FROM %i WHERE ID = 1', self::scratch_table( 'pontifextest_myisam_canary' ) ) );
		return null === $value ? null : (string) $value;
	}

	/**
	 * Count the rows currently in the MyISAM canary table.
	 *
	 * A write-through INSERT, if it executed, would ADD a new row (ID 99)
	 * rather than modify the existing one, so the byte-unchanged value
	 * assertion alone would not catch it — this catches an added row directly.
	 *
	 * @return int The row count.
	 */
	private function myisam_canary_row_count(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test assertion: count rows in the MyISAM canary table.
		$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::scratch_table( 'pontifextest_myisam_canary' ) ) );
		return null === $count ? 0 : (int) $count;
	}

	/**
	 * Build an in-memory archive whose one db_chunk hides a hostile UPDATE behind a bare "; " inside a single statement.
	 *
	 * Declares statement_count 1: split_statements() finds no ";\n" before
	 * the payload's own final terminator, so the CREATE and the smuggled
	 * UPDATE parse as one statement, exactly as a hostile archive crafted
	 * against this specific gap would declare it.
	 *
	 * @return resource A readable, seekable stream containing the archive bytes.
	 */
	private function hostile_embedded_semicolon_archive() {
		$alpha  = self::scratch_table( 'pontifextest_alpha' );
		$canary = self::scratch_table( 'pontifextest_canary' );
		$sql    = "CREATE TABLE `{$alpha}` (id INT NOT NULL PRIMARY KEY, val VARCHAR(50)) DEFAULT CHARSET=utf8mb4; "
			. "UPDATE `{$canary}` SET val = 'HACKED' WHERE id = 1;\n";

		$header = EntryHeader::for_db_chunk( 0, $alpha, 1, strlen( $sql ), 0 );
		$plans  = array( new EntryPlan( $header, RawCodec::ID, str_repeat( "\0", EntryWriter::NONCE_SIZE ), self::memory_stream( $sql ) ) );

		$writer = new ArchiveWriter( new EntryWriter( CodecRegistry::with_defaults() ), new FooterWriter() );
		$dest   = self::memory_stream();
		$writer->write_archive( self::sample_provenance(), $plans, $dest );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource, not a filesystem path.
		rewind( $dest );
		return $dest;
	}

	/**
	 * Build an in-memory archive whose one db_chunk declares an ordinary table but smuggles a hostile UPDATE.
	 *
	 * The chunk declares "pontifextest_alpha" — an entirely unremarkable
	 * table — but its payload's third, properly ";\n"-delimited statement
	 * targets the live canary table directly. The shape guard must refuse
	 * the whole chunk on that third statement, before the first two
	 * (a legitimate DROP and CREATE) ever reach the adapter.
	 *
	 * @return resource A readable, seekable stream containing the archive bytes.
	 */
	private function hostile_archive() {
		$alpha  = self::scratch_table( 'pontifextest_alpha' );
		$canary = self::scratch_table( 'pontifextest_canary' );
		$sql    = "DROP TABLE IF EXISTS `{$alpha}`;\n"
			. "CREATE TABLE `{$alpha}` (id INT NOT NULL PRIMARY KEY, val VARCHAR(50)) DEFAULT CHARSET=utf8mb4;\n"
			. "UPDATE `{$canary}` SET val = 'HACKED' WHERE id = 1;\n";

		$header = EntryHeader::for_db_chunk( 0, $alpha, 3, strlen( $sql ), 0 );
		$plans  = array( new EntryPlan( $header, RawCodec::ID, str_repeat( "\0", EntryWriter::NONCE_SIZE ), self::memory_stream( $sql ) ) );

		$writer = new ArchiveWriter( new EntryWriter( CodecRegistry::with_defaults() ), new FooterWriter() );
		$dest   = self::memory_stream();
		$writer->write_archive( self::sample_provenance(), $plans, $dest );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource, not a filesystem path.
		rewind( $dest );
		return $dest;
	}

	/**
	 * Create the live canary table an UPDATE would touch if the guard failed.
	 *
	 * @return void
	 */
	private function create_canary_table(): void {
		global $wpdb;
		$table = self::scratch_table( 'pontifextest_canary' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test fixture: create the canary table.
		$wpdb->query( $wpdb->prepare( 'CREATE TABLE %i (id INT NOT NULL PRIMARY KEY, val VARCHAR(64)) DEFAULT CHARSET=utf8mb4', $table ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test fixture: seed the canary row.
		$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (id, val) VALUES (1, %s)', $table, self::CANARY_VALUE ) );
	}

	/**
	 * Read the canary row's current value.
	 *
	 * @return string|null The value, or null when the table or row is missing.
	 */
	private function canary_value(): ?string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test assertion: read back the canary row.
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT val FROM %i WHERE id = 1', self::scratch_table( 'pontifextest_canary' ) ) );
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
		foreach ( array( 'pontifexstg_' . $wpdb->prefix . 'pontifextest_%', 'pontifexold_' . $wpdb->prefix . 'pontifextest_%' ) as $pattern ) {
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
		foreach ( self::scratch_tables() as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test cleanup: drop a scratch table.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}
	}

	/**
	 * Drop the WordPress-prefixed sequence scratch table and its staging/parked forms.
	 *
	 * A separate sweep from {@see self::drop_scratch_tables()}: the sequence
	 * fixture in {@see self::test_mariadb_sequence_round_trips_through_a_real_export_and_restore()}
	 * is deliberately prefixed with the live `$wpdb->prefix` (so the real
	 * DatabaseScanner's `SHOW TABLES LIKE` picks it up), which is not known at
	 * {@see self::SCRATCH_TABLES}'s compile time.
	 *
	 * @return void
	 */
	private function drop_sequence_scratch_tables(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'pontifextest_seq';
		foreach ( array( $table, 'pontifexstg_' . $table, 'pontifexold_' . $table ) as $name ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test cleanup: drop a scratch table (DROP TABLE also removes a MariaDB sequence, confirmed empirically).
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $name ) );
		}
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
