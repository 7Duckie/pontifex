<?php
/**
 * Unit tests for the DatabaseWriter class.
 *
 * @package Pontifex\Tests\Unit\Restore
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Restore;

require_once __DIR__ . '/../Manifest/Fakes/FakeDbAdapter.php';

use InvalidArgumentException;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Pontifex\Archive\Codec\CodecRegistry;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Reader\ArchiveReader;
use Pontifex\Archive\Reader\EntryReadResult;
use Pontifex\Archive\Reader\EntryReader;
use Pontifex\Restore\DatabaseWriter;
use Pontifex\Tests\Unit\Manifest\Fakes\FakeDbAdapter;

/**
 * Tests for {@see DatabaseWriter}.
 *
 * Uses FakeDbAdapter (the same in-memory adapter that backs
 * DatabaseScannerTest) to record statements as they're executed.
 * Each test verifies either that the right statements arrived in
 * the right order, or that the right exception fired.
 */
final class DatabaseWriterTest extends TestCase {

	/**
	 * Build an EntryReadResult for a db_chunk entry.
	 *
	 * @param string $table_name      The source table the chunk belongs to.
	 * @param int    $statement_count The declared number of statements in the chunk.
	 * @param string $sql_payload     The decoded SQL bytes.
	 * @param int    $chunk_index     The chunk's index within its table (defaults to 0).
	 * @return EntryReadResult The bundled header + payload.
	 */
	private static function db_chunk_result( string $table_name, int $statement_count, string $sql_payload, int $chunk_index = 0 ): EntryReadResult {
		$header = EntryHeader::for_db_chunk( $chunk_index, $table_name, $statement_count, strlen( $sql_payload ), 0 );
		return new EntryReadResult( $header, $sql_payload );
	}

	/**
	 * A single-statement chunk must be executed against the adapter exactly once.
	 *
	 * @return void
	 */
	public function test_single_statement_chunk_executes_once(): void {
		$adapter = new FakeDbAdapter();
		$writer  = new DatabaseWriter( $adapter );
		$sql     = "CREATE TABLE `wp_options` (id INT);\n";

		$writer->write_entry( self::db_chunk_result( 'wp_options', 1, $sql ) );

		$executed = $adapter->executed_statements();
		$this->assertCount( 1, $executed );
		$this->assertSame( 'CREATE TABLE `pontifexstg_wp_options` (id INT)', $executed[0] );
	}

	/**
	 * A multi-statement chunk must execute every statement in order.
	 *
	 * @return void
	 */
	public function test_multi_statement_chunk_executes_each_in_order(): void {
		$adapter = new FakeDbAdapter();
		$writer  = new DatabaseWriter( $adapter );
		$sql     = "CREATE TABLE `wp_posts` (id INT);\n"
				. "INSERT INTO `wp_posts` VALUES (1);\n"
				. "INSERT INTO `wp_posts` VALUES (2);\n";

		$writer->write_entry( self::db_chunk_result( 'wp_posts', 3, $sql ) );

		$executed = $adapter->executed_statements();
		$this->assertCount( 3, $executed );
		$this->assertSame( 'CREATE TABLE `pontifexstg_wp_posts` (id INT)', $executed[0] );
		$this->assertSame( 'INSERT INTO `pontifexstg_wp_posts` VALUES (1)', $executed[1] );
		$this->assertSame( 'INSERT INTO `pontifexstg_wp_posts` VALUES (2)', $executed[2] );
	}

	/**
	 * An empty payload with statement_count 0 must execute nothing without error.
	 *
	 * @return void
	 */
	public function test_empty_chunk_executes_nothing(): void {
		$adapter = new FakeDbAdapter();
		$writer  = new DatabaseWriter( $adapter );

		$writer->write_entry( self::db_chunk_result( 'wp_empty', 0, '' ) );

		$this->assertSame( array(), $adapter->executed_statements() );
	}

	/**
	 * A declared statement_count larger than the parsed count must be rejected.
	 *
	 * @return void
	 */
	public function test_statement_count_too_high_rejected(): void {
		$adapter = new FakeDbAdapter();
		$writer  = new DatabaseWriter( $adapter );
		// One real statement, but header claims 5.
		$sql = "CREATE TABLE `t` (id INT);\n";

		$this->expectException( RuntimeException::class );

		$writer->write_entry( self::db_chunk_result( 't', 5, $sql ) );
	}

	/**
	 * A declared statement_count smaller than the parsed count must be rejected.
	 *
	 * @return void
	 */
	public function test_statement_count_too_low_rejected(): void {
		$adapter = new FakeDbAdapter();
		$writer  = new DatabaseWriter( $adapter );
		// Three real statements, but header claims 1.
		$sql = "A;\nB;\nC;\n";

		$this->expectException( RuntimeException::class );

		$writer->write_entry( self::db_chunk_result( 't', 1, $sql ) );
	}

	/**
	 * An adapter failure during execute_sql must propagate as a RuntimeException.
	 *
	 * @return void
	 */
	public function test_adapter_failure_propagates(): void {
		$adapter = new FakeDbAdapter();
		$adapter->fail_next_execute( 'simulated MySQL error' );
		$writer = new DatabaseWriter( $adapter );

		$this->expectException( RuntimeException::class );

		$writer->write_entry( self::db_chunk_result( 't', 1, "CREATE TABLE `t` (id INT);\n" ) );
	}

	/**
	 * A file entry must be rejected; it belongs to FileWriter, not DatabaseWriter.
	 *
	 * @return void
	 */
	public function test_file_entry_rejected(): void {
		$adapter = new FakeDbAdapter();
		$writer  = new DatabaseWriter( $adapter );
		$header  = EntryHeader::for_file( 'a.txt', 4, 0644, 0, 'application/octet-stream', 0 );
		$result  = new EntryReadResult( $header, 'data' );

		$this->expectException( InvalidArgumentException::class );

		$writer->write_entry( $result );
	}

	/**
	 * A directory entry must be rejected.
	 *
	 * @return void
	 */
	public function test_directory_entry_rejected(): void {
		$adapter = new FakeDbAdapter();
		$writer  = new DatabaseWriter( $adapter );
		$header  = EntryHeader::for_directory( 'wp-content/uploads', 0755, 0 );
		$result  = new EntryReadResult( $header, '' );

		$this->expectException( InvalidArgumentException::class );

		$writer->write_entry( $result );
	}

	/**
	 * A symlink entry must be rejected.
	 *
	 * @return void
	 */
	public function test_symlink_entry_rejected(): void {
		$adapter = new FakeDbAdapter();
		$writer  = new DatabaseWriter( $adapter );
		$header  = EntryHeader::for_symlink( 'wp-content/cache', '/tmp/x', 0 );
		$result  = new EntryReadResult( $header, '' );

		$this->expectException( InvalidArgumentException::class );

		$writer->write_entry( $result );
	}

	/**
	 * Trailing whitespace on statements must be stripped before execution.
	 *
	 * @return void
	 */
	public function test_whitespace_around_statements_trimmed(): void {
		$adapter = new FakeDbAdapter();
		$writer  = new DatabaseWriter( $adapter );
		// Add extra whitespace and a blank line between statements.
		$sql = "  CREATE TABLE `t` (id INT);\n  INSERT INTO `t` VALUES (1);\n";

		$writer->write_entry( self::db_chunk_result( 't', 2, $sql ) );

		$executed = $adapter->executed_statements();
		$this->assertSame( 'CREATE TABLE `pontifexstg_t` (id INT)', $executed[0] );
		$this->assertSame( 'INSERT INTO `pontifexstg_t` VALUES (1)', $executed[1] );
	}

	/**
	 * A cross-prefix writer must rewrite the chunk's backtick-quoted table identifier.
	 *
	 * Every occurrence of the source table name as an identifier — in the DROP, the
	 * CREATE, and the INSERT — is swapped for its destination-prefixed form, while the
	 * statement count is unchanged.
	 *
	 * @return void
	 */
	public function test_cross_prefix_rewrites_table_identifier(): void {
		$adapter = new FakeDbAdapter();
		$writer  = new DatabaseWriter( $adapter, 'wp_', 'xyz_' );
		$sql     = "DROP TABLE IF EXISTS `wp_posts`;\n"
				. "CREATE TABLE `wp_posts` (`id` INT);\n"
				. "INSERT INTO `wp_posts` (`id`) VALUES (1);\n";

		$writer->write_entry( self::db_chunk_result( 'wp_posts', 3, $sql ) );

		$executed = $adapter->executed_statements();
		$this->assertCount( 3, $executed );
		$this->assertSame( 'DROP TABLE IF EXISTS `pontifexstg_xyz_posts`', $executed[0] );
		$this->assertSame( 'CREATE TABLE `pontifexstg_xyz_posts` (`id` INT)', $executed[1] );
		$this->assertSame( 'INSERT INTO `pontifexstg_xyz_posts` (`id`) VALUES (1)', $executed[2] );
	}

	/**
	 * A cross-prefix writer must not rewrite a single-quoted value equal to the table name.
	 *
	 * The rewrite matches only the backtick-quoted identifier, so a row value that
	 * happens to be the string "wp_options" is left untouched.
	 *
	 * @return void
	 */
	public function test_cross_prefix_leaves_quoted_values_untouched(): void {
		$adapter = new FakeDbAdapter();
		$writer  = new DatabaseWriter( $adapter, 'wp_', 'xyz_' );
		$sql     = "INSERT INTO `wp_options` (`option_name`, `option_value`) VALUES ('siteurl', 'wp_options');\n";

		$writer->write_entry( self::db_chunk_result( 'wp_options', 1, $sql ) );

		$this->assertSame(
			"INSERT INTO `pontifexstg_xyz_options` (`option_name`, `option_value`) VALUES ('siteurl', 'wp_options')",
			$adapter->executed_statements()[0]
		);
	}

	/**
	 * A same-prefix writer must apply only the staging prefix, no cross-prefix rewrite.
	 *
	 * @return void
	 */
	public function test_same_prefix_applies_only_the_staging_prefix(): void {
		$adapter = new FakeDbAdapter();
		$writer  = new DatabaseWriter( $adapter, 'wp_', 'wp_' );

		$writer->write_entry( self::db_chunk_result( 'wp_posts', 1, "INSERT INTO `wp_posts` VALUES (1);\n" ) );

		$this->assertSame( 'INSERT INTO `pontifexstg_wp_posts` VALUES (1)', $adapter->executed_statements()[0] );
	}

	/**
	 * Finalising must ask the adapter to rewrite the key columns when active.
	 *
	 * @return void
	 */
	public function test_finalise_rewrites_prefix_keys_when_active(): void {
		$adapter = new FakeDbAdapter();
		$writer  = new DatabaseWriter( $adapter, 'wp_', 'xyz_' );

		$writer->finalise_prefix_rewrite();

		$this->assertSame( array( array( 'wp_', 'xyz_', 'pontifexstg_' ) ), $adapter->rewrite_calls() );
	}

	/**
	 * Finalising must do nothing when the prefixes match or none is set.
	 *
	 * @return void
	 */
	public function test_finalise_is_a_no_op_without_a_prefix_change(): void {
		$same = new FakeDbAdapter();
		( new DatabaseWriter( $same, 'wp_', 'wp_' ) )->finalise_prefix_rewrite();
		$this->assertSame( array(), $same->rewrite_calls() );

		$none = new FakeDbAdapter();
		( new DatabaseWriter( $none ) )->finalise_prefix_rewrite();
		$this->assertSame( array(), $none->rewrite_calls() );
	}

	/**
	 * The cut-over must move a live table aside and install a new one, in one RENAME.
	 *
	 * A staged table that exists live is swapped (`T → pontifexold_T,
	 * pontifexstg_T → T`); one new to the destination is simply installed. Both
	 * moves ride the SAME statement — the atomicity the whole design rests on —
	 * and the parked old copy is dropped afterwards.
	 *
	 * @return void
	 */
	public function test_commit_swaps_live_and_new_tables_in_one_rename(): void {
		$adapter = new FakeDbAdapter();
		$adapter->mark_table_existing( 'wp_posts' );
		$writer = new DatabaseWriter( $adapter );

		$writer->write_entry( self::db_chunk_result( 'wp_posts', 1, "CREATE TABLE `wp_posts` (id INT);\n" ) );
		$writer->write_entry( self::db_chunk_result( 'wp_new', 1, "CREATE TABLE `wp_new` (id INT);\n" ) );
		$writer->commit_staged_tables();

		$executed = $adapter->executed_statements();
		$this->assertSame(
			array(
				'CREATE TABLE `pontifexstg_wp_posts` (id INT)',
				'CREATE TABLE `pontifexstg_wp_new` (id INT)',
				'DROP TABLE IF EXISTS `pontifexold_wp_posts`',
				'RENAME TABLE `wp_posts` TO `pontifexold_wp_posts`, `pontifexstg_wp_posts` TO `wp_posts`, `pontifexstg_wp_new` TO `wp_new`',
				'DROP TABLE IF EXISTS `pontifexold_wp_posts`',
			),
			$executed
		);
	}

	/**
	 * Committing with nothing staged must execute nothing.
	 *
	 * @return void
	 */
	public function test_commit_with_nothing_staged_is_a_no_op(): void {
		$adapter = new FakeDbAdapter();
		( new DatabaseWriter( $adapter ) )->commit_staged_tables();

		$this->assertSame( array(), $adapter->executed_statements() );
	}

	/**
	 * A table replayed in several chunks must appear in the cut-over exactly once.
	 *
	 * A large table arrives as a schema chunk plus row chunks; it is still one
	 * staged table, so the RENAME must name it once, not once per chunk.
	 *
	 * @return void
	 */
	public function test_multiple_chunks_of_one_table_stage_it_once(): void {
		$adapter = new FakeDbAdapter();
		$writer  = new DatabaseWriter( $adapter );

		$writer->write_entry( self::db_chunk_result( 'wp_posts', 1, "CREATE TABLE `wp_posts` (id INT);\n" ) );
		$writer->write_entry( self::db_chunk_result( 'wp_posts', 1, "INSERT INTO `wp_posts` VALUES (2);\n", 1 ) );
		$writer->commit_staged_tables();

		$executed = $adapter->executed_statements();
		$this->assertSame( 'RENAME TABLE `pontifexstg_wp_posts` TO `wp_posts`', end( $executed ) );
	}

	/**
	 * Aborting must drop every staged table and then forget them.
	 *
	 * @return void
	 */
	public function test_abort_drops_staged_tables_once(): void {
		$adapter = new FakeDbAdapter();
		$writer  = new DatabaseWriter( $adapter );

		$writer->write_entry( self::db_chunk_result( 'wp_posts', 1, "CREATE TABLE `wp_posts` (id INT);\n" ) );
		$writer->write_entry( self::db_chunk_result( 'wp_options', 1, "CREATE TABLE `wp_options` (id INT);\n" ) );
		$writer->abort_staging();
		$writer->abort_staging();

		$executed = $adapter->executed_statements();
		$this->assertSame(
			array(
				'CREATE TABLE `pontifexstg_wp_posts` (id INT)',
				'CREATE TABLE `pontifexstg_wp_options` (id INT)',
				'DROP TABLE IF EXISTS `pontifexstg_wp_posts`',
				'DROP TABLE IF EXISTS `pontifexstg_wp_options`',
			),
			$executed,
			'The second abort must be a no-op: staged bookkeeping is cleared by the first.'
		);
	}

	/**
	 * Beginning a restore must sweep leftover staging and parked tables.
	 *
	 * A crashed earlier run can abandon `pontifexstg_*` / `pontifexold_*` tables;
	 * they would collide with this run's staging names, so begin_staging() drops
	 * them before any replay.
	 *
	 * @return void
	 */
	public function test_begin_staging_sweeps_leftover_tables(): void {
		$adapter = new FakeDbAdapter();
		$adapter->mark_table_existing( 'pontifexstg_wp_posts' );
		$adapter->mark_table_existing( 'pontifexold_wp_options' );
		$adapter->mark_table_existing( 'wp_posts' );

		( new DatabaseWriter( $adapter ) )->begin_staging();

		$this->assertSame(
			array(
				'DROP TABLE IF EXISTS `pontifexstg_wp_posts`',
				'DROP TABLE IF EXISTS `pontifexold_wp_options`',
			),
			$adapter->executed_statements(),
			'Only Pontifex-prefixed leftovers are swept; live tables are untouched.'
		);
	}

	/**
	 * A failed cut-over RENAME must leave the staged bookkeeping for abort to clean.
	 *
	 * MySQL makes no changes when a RENAME TABLE fails, so the live database is
	 * intact; the writer must keep knowing what it staged so abort_staging() can
	 * remove the staging tables afterwards.
	 *
	 * @return void
	 * @throws AssertionFailedError Rethrown immediately if commit_staged_tables() failed to throw (see the catch block below); never swallowed.
	 */
	public function test_failed_rename_leaves_staging_for_abort(): void {
		$adapter = new FakeDbAdapter();
		$writer  = new DatabaseWriter( $adapter );

		$writer->write_entry( self::db_chunk_result( 'wp_posts', 1, "CREATE TABLE `wp_posts` (id INT);\n" ) );
		$adapter->fail_next_execute( 'simulated RENAME failure' );

		try {
			$writer->commit_staged_tables();
			$this->fail( 'commit_staged_tables() should propagate the RENAME failure.' );
		} catch ( AssertionFailedError $bug ) {
			// self::fail()'s own AssertionFailedError extends RuntimeException, so it
			// would otherwise be swallowed by the catch below — rethrow it before that
			// catch ever sees it, so a missing refusal fails this test for real.
			throw $bug;
		} catch ( RuntimeException $failure ) {
			$this->assertSame( 'simulated RENAME failure', $failure->getMessage() );
		}

		$writer->abort_staging();

		$executed = $adapter->executed_statements();
		$this->assertSame( 'DROP TABLE IF EXISTS `pontifexstg_wp_posts`', end( $executed ), 'Abort after a failed cut-over must drop the staging table.' );
	}

	/**
	 * The replay charset is switched on begin and handed back on commit.
	 *
	 * The replayed SQL's bytes were captured under the archive's charset, so
	 * the connection speaks it for the replay's duration and no longer.
	 *
	 * @return void
	 */
	public function test_replay_charset_is_set_on_begin_and_restored_on_commit(): void {
		$adapter = new FakeDbAdapter();
		$writer  = new DatabaseWriter( $adapter );

		$writer->begin_staging( 'utf8mb4' );
		$writer->write_entry( self::db_chunk_result( 'wp_posts', 1, "CREATE TABLE `wp_posts` (id INT);\n" ) );
		$writer->commit_staged_tables();

		$this->assertSame( array( 'utf8mb4', 'RESTORE' ), $adapter->charset_calls() );
	}

	/**
	 * The replay charset is handed back on abort, and on a database-less commit.
	 *
	 * @return void
	 */
	public function test_replay_charset_is_restored_on_abort_and_on_an_empty_commit(): void {
		$aborted = new FakeDbAdapter();
		$writer  = new DatabaseWriter( $aborted );
		$writer->begin_staging( 'utf8mb4' );
		$writer->abort_staging();
		$this->assertSame( array( 'utf8mb4', 'RESTORE' ), $aborted->charset_calls() );

		$empty  = new FakeDbAdapter();
		$writer = new DatabaseWriter( $empty );
		$writer->begin_staging( 'latin1' );
		$writer->commit_staged_tables();
		$this->assertSame( array( 'latin1', 'RESTORE' ), $empty->charset_calls(), 'A database-less archive must still hand the charset back.' );
	}

	/**
	 * A malformed archive charset refuses the restore before any write.
	 *
	 * The charset comes from the archive and is untrusted; junk means a
	 * corrupt or hostile provenance, and proceeding would interpolate it
	 * into SQL.
	 *
	 * @return void
	 * @throws AssertionFailedError Rethrown immediately if begin_staging() failed to throw (see the catch block below); never swallowed.
	 */
	public function test_malformed_replay_charset_is_refused_before_any_write(): void {
		$adapter = new FakeDbAdapter();
		$writer  = new DatabaseWriter( $adapter );

		try {
			$writer->begin_staging( "utf8'; DROP TABLE x --" );
			$this->fail( 'begin_staging() should refuse a malformed charset.' );
		} catch ( AssertionFailedError $bug ) {
			// See test_failed_rename_leaves_staging_for_abort() above for why this
			// rethrow must run before the RuntimeException catch below.
			throw $bug;
		} catch ( RuntimeException $refusal ) {
			$this->assertStringContainsString( 'character set', $refusal->getMessage() );
		}

		$this->assertSame( array(), $adapter->charset_calls(), 'The malformed charset must never reach the adapter.' );
		$this->assertSame( array(), $adapter->executed_statements(), 'The refusal must land before any statement executes.' );
	}

	/**
	 * An empty charset skips the switch entirely.
	 *
	 * @return void
	 */
	public function test_empty_replay_charset_skips_the_switch(): void {
		$adapter = new FakeDbAdapter();
		$writer  = new DatabaseWriter( $adapter );

		$writer->begin_staging();
		$writer->commit_staged_tables();

		$this->assertSame( array(), $adapter->charset_calls() );
	}

	/**
	 * A table whose staged name would exceed MySQL's 64-character limit is refused.
	 *
	 * Refused before any statement executes, with the table named — rather than
	 * failing later inside CREATE or RENAME with an opaque server error.
	 *
	 * @return void
	 * @throws AssertionFailedError Rethrown immediately if write_entry() failed to throw (see the catch block below); never swallowed.
	 */
	public function test_over_long_table_name_refused_before_any_write(): void {
		$adapter = new FakeDbAdapter();
		$writer  = new DatabaseWriter( $adapter );
		// 53 characters: with the 12-character staging prefix the staged name is 65.
		$long_name = str_repeat( 'a', 53 );

		try {
			$writer->write_entry( self::db_chunk_result( $long_name, 1, "CREATE TABLE `{$long_name}` (id INT);\n" ) );
			$this->fail( 'write_entry() should refuse an over-long staged name.' );
		} catch ( AssertionFailedError $bug ) {
			// See test_failed_rename_leaves_staging_for_abort() above for why this
			// rethrow must run before the RuntimeException catch below.
			throw $bug;
		} catch ( RuntimeException $refusal ) {
			$this->assertStringContainsString( $long_name, $refusal->getMessage() );
		}

		$this->assertSame( array(), $adapter->executed_statements(), 'The refusal must land before any statement executes.' );
	}

	// -----------------------------------------------------------------
	// Statement shape containment (the db_chunk SQL-injection guard).
	// -----------------------------------------------------------------

	/**
	 * Assert that a single-statement chunk is refused before anything executes.
	 *
	 * Builds a one-statement chunk against table "t" (staged as
	 * "pontifexstg_t") from the given SQL, expects write_entry() to throw a
	 * RuntimeException, and asserts nothing reached the adapter — the
	 * whole-payload-up-front guarantee.
	 *
	 * @param string $sql The single SQL statement, terminated with ";\n".
	 * @return void
	 * @throws AssertionFailedError Rethrown immediately if write_entry() failed to throw (see the catch block below); never swallowed.
	 */
	private static function assert_single_statement_chunk_refused( string $sql ): void {
		$adapter = new FakeDbAdapter();
		$writer  = new DatabaseWriter( $adapter );

		try {
			$writer->write_entry( self::db_chunk_result( 't', 1, $sql ) );
			self::fail( 'write_entry() should have refused this statement: ' . $sql );
		} catch ( AssertionFailedError $bug ) {
			// See test_failed_rename_leaves_staging_for_abort() above for why this
			// rethrow must run before the RuntimeException catch below.
			throw $bug;
		} catch ( RuntimeException $refusal ) {
			unset( $refusal ); // The refusal is expected; only its effect matters here.
		}

		self::assertSame( array(), $adapter->executed_statements(), 'A refused chunk must execute nothing.' );
	}

	/**
	 * A leading /* *\/ comment before the verb must be refused.
	 *
	 * @return void
	 */
	public function test_leading_block_comment_before_verb_refused(): void {
		self::assert_single_statement_chunk_refused( "/* comment */DROP TABLE IF EXISTS `pontifexstg_t`;\n" );
	}

	/**
	 * A leading -- comment before the verb must be refused.
	 *
	 * @return void
	 */
	public function test_leading_line_comment_before_verb_refused(): void {
		self::assert_single_statement_chunk_refused( "-- comment\nDROP TABLE IF EXISTS `pontifexstg_t`;\n" );
	}

	/**
	 * A leading # comment before the verb must be refused.
	 *
	 * @return void
	 */
	public function test_leading_hash_comment_before_verb_refused(): void {
		self::assert_single_statement_chunk_refused( "# comment\nDROP TABLE IF EXISTS `pontifexstg_t`;\n" );
	}

	/**
	 * A MySQL conditional-execution comment wrapping the whole statement must be refused.
	 *
	 * /*!40101 ... *\/ comments EXECUTE their contents on the server, so a naive
	 * check that strips comments before inspecting the verb would still run this.
	 *
	 * @return void
	 */
	public function test_executable_comment_wrapping_statement_refused(): void {
		self::assert_single_statement_chunk_refused( "/*!40101 DROP TABLE IF EXISTS `pontifexstg_t` */;\n" );
	}

	/**
	 * A MySQL conditional-execution comment wrapping only the verb must be refused.
	 *
	 * @return void
	 */
	public function test_executable_comment_wrapping_verb_refused(): void {
		self::assert_single_statement_chunk_refused( "/*!40101 DROP*/ TABLE IF EXISTS `pontifexstg_t`;\n" );
	}

	/**
	 * A form feed before the verb must be refused.
	 *
	 * \x0c is not in PHP's default trim() charlist, so it survives statement
	 * splitting; a naive extractor treating it as ordinary whitespace would
	 * still find and permit the verb underneath it.
	 *
	 * @return void
	 */
	public function test_form_feed_before_verb_refused(): void {
		self::assert_single_statement_chunk_refused( "\x0cDROP TABLE IF EXISTS `pontifexstg_t`;\n" );
	}

	/**
	 * A lowercase verb must be refused.
	 *
	 * The shape check is byte-exact and case-sensitive by design: on a server
	 * running with lower_case_table_names=0 a case variant is a different
	 * table, so case-folding the check would be its own hole.
	 *
	 * @return void
	 */
	public function test_lowercase_verb_refused(): void {
		self::assert_single_statement_chunk_refused( "drop table if exists `pontifexstg_t`;\n" );
	}

	/**
	 * Whitespace-like bytes before the verb that trim() does not strip must be refused.
	 *
	 * A non-breaking space (U+00A0) is not in PHP's default trim() charlist,
	 * so — like the form feed case — it survives statement splitting intact.
	 *
	 * @return void
	 */
	public function test_non_stripped_whitespace_before_verb_refused(): void {
		self::assert_single_statement_chunk_refused( "\xC2\xA0DROP TABLE IF EXISTS `pontifexstg_t`;\n" );
	}

	/**
	 * Ordinary leading whitespace, a newline, and a tab before a hostile verb must still be refused.
	 *
	 * Unlike the form feed and non-breaking space above, these bytes ARE in
	 * PHP's default trim() charlist, so split_statements() strips them
	 * before the shape check ever runs — that normalisation is not a
	 * loophole: once stripped, the statement is an ordinary UPDATE, which
	 * fails every sanctioned shape on its own merits.
	 *
	 * @return void
	 */
	public function test_leading_ordinary_whitespace_newline_and_tab_before_hostile_verb_refused(): void {
		self::assert_single_statement_chunk_refused( "\n\t  UPDATE `pontifexstg_t` SET x = 1;\n" );
	}

	/**
	 * A database-qualified target must be refused.
	 *
	 * The staged identifier the writer built is unqualified; a `db`.`table`
	 * form is a different (and unexpected) target even when the table part
	 * matches.
	 *
	 * @return void
	 */
	public function test_database_qualified_target_refused(): void {
		self::assert_single_statement_chunk_refused( "DROP TABLE IF EXISTS `otherdb`.`pontifexstg_t`;\n" );
	}

	/**
	 * An unquoted target must be refused.
	 *
	 * @return void
	 */
	public function test_unquoted_target_refused(): void {
		self::assert_single_statement_chunk_refused( "DROP TABLE IF EXISTS pontifexstg_t;\n" );
	}

	/**
	 * A case-variant target must be refused.
	 *
	 * @return void
	 */
	public function test_case_variant_target_refused(): void {
		self::assert_single_statement_chunk_refused( "DROP TABLE IF EXISTS `PONTIFEXSTG_t`;\n" );
	}

	/**
	 * A prefix-sibling target must be refused.
	 *
	 * The expected shape includes the closing backtick, so
	 * "pontifexstg_t" cannot also match "pontifexstg_t_evil".
	 *
	 * @return void
	 */
	public function test_prefix_sibling_target_refused(): void {
		self::assert_single_statement_chunk_refused( "DROP TABLE IF EXISTS `pontifexstg_t_evil`;\n" );
	}

	/**
	 * A CREATE VIEW naming the staged table must be refused, with its own message.
	 *
	 * A view could later be written through by an ordinary-looking INSERT,
	 * writing to the live table it names; Pontifex never restores views.
	 *
	 * @return void
	 * @throws AssertionFailedError Rethrown immediately if write_entry() failed to throw (see the catch block below); never swallowed.
	 */
	public function test_create_view_refused_with_its_own_message(): void {
		$adapter = new FakeDbAdapter();
		$writer  = new DatabaseWriter( $adapter );
		$sql     = "CREATE VIEW `pontifexstg_t` AS SELECT * FROM `wp_users`;\n";

		try {
			$writer->write_entry( self::db_chunk_result( 't', 1, $sql ) );
			// Deliberately does not spell out "CREATE VIEW": the catch block below
			// asserts the real refusal message contains exactly that phrase, and a
			// fail() message containing it too would let the assertion match its
			// own failure text instead of the writer's — see
			// test_failed_rename_leaves_staging_for_abort() above for the general
			// pattern and the AssertionFailedError rethrow that closes this either way.
			$this->fail( 'write_entry() should have refused a view-declaring statement.' );
		} catch ( AssertionFailedError $bug ) {
			throw $bug;
		} catch ( RuntimeException $refusal ) {
			$this->assertStringContainsString( 'CREATE VIEW', $refusal->getMessage() );
			$this->assertStringNotContainsString( 'SELECT', $refusal->getMessage(), 'The refusal message must never contain the statement bytes.' );
		}

		$this->assertSame( array(), $adapter->executed_statements() );
	}

	/**
	 * An UPDATE must be refused.
	 *
	 * @return void
	 */
	public function test_update_refused(): void {
		self::assert_single_statement_chunk_refused( "UPDATE `pontifexstg_t` SET x = 1;\n" );
	}

	/**
	 * A DELETE must be refused.
	 *
	 * @return void
	 */
	public function test_delete_refused(): void {
		self::assert_single_statement_chunk_refused( "DELETE FROM `pontifexstg_t`;\n" );
	}

	/**
	 * A TRUNCATE must be refused.
	 *
	 * @return void
	 */
	public function test_truncate_refused(): void {
		self::assert_single_statement_chunk_refused( "TRUNCATE TABLE `pontifexstg_t`;\n" );
	}

	/**
	 * A GRANT must be refused.
	 *
	 * @return void
	 */
	public function test_grant_refused(): void {
		self::assert_single_statement_chunk_refused( "GRANT ALL PRIVILEGES ON *.* TO 'evil'@'%';\n" );
	}

	/**
	 * A SELECT ... INTO OUTFILE must be refused.
	 *
	 * @return void
	 */
	public function test_select_into_outfile_refused(): void {
		self::assert_single_statement_chunk_refused( "SELECT * FROM `pontifexstg_t` INTO OUTFILE '/tmp/pontifex-exfil';\n" );
	}

	/**
	 * An INSERT ... SELECT must be refused.
	 *
	 * Only a literal "VALUES (" may follow the target; a SELECT would let
	 * the INSERT read from an arbitrary source.
	 *
	 * @return void
	 */
	public function test_insert_select_refused(): void {
		self::assert_single_statement_chunk_refused( "INSERT INTO `pontifexstg_t` SELECT * FROM `wp_users`;\n" );
	}

	/**
	 * An INSERT ... SET must be refused.
	 *
	 * @return void
	 */
	public function test_insert_set_refused(): void {
		self::assert_single_statement_chunk_refused( "INSERT INTO `pontifexstg_t` SET id = 1;\n" );
	}

	/**
	 * A trailing stacked statement after a legitimate-looking prefix must refuse the whole chunk.
	 *
	 * The first statement is a perfectly ordinary INSERT into the staged
	 * table; the second, properly delimited, statement targets a live table
	 * outright. Every statement is checked before any one of them executes,
	 * so the benign first statement buys the hostile second one no cover —
	 * and nothing from EITHER statement reaches the adapter.
	 *
	 * @return void
	 * @throws AssertionFailedError Rethrown immediately if write_entry() failed to throw (see the catch block below); never swallowed.
	 */
	public function test_trailing_stacked_statement_refuses_whole_chunk(): void {
		$adapter = new FakeDbAdapter();
		$writer  = new DatabaseWriter( $adapter );
		$sql     = "INSERT INTO `pontifexstg_t` VALUES (1);\n"
				. "UPDATE `wp_users` SET user_pass = 'x' WHERE ID = 1;\n";

		try {
			$writer->write_entry( self::db_chunk_result( 't', 2, $sql ) );
			$this->fail( 'write_entry() should refuse a chunk carrying a trailing stacked statement.' );
		} catch ( AssertionFailedError $bug ) {
			// See test_failed_rename_leaves_staging_for_abort() above for why this
			// rethrow must run before the RuntimeException catch below.
			throw $bug;
		} catch ( RuntimeException $refusal ) {
			unset( $refusal ); // The refusal is expected; only its effect matters here.
		}

		$this->assertSame( array(), $adapter->executed_statements(), 'Neither statement may execute — including the legitimate-looking first one.' );
	}

	/**
	 * The separators a stacked statement can hide behind without being split by ";\n".
	 *
	 * Each one starts with a semicolon but is not the exact two-byte sequence
	 * split_statements() splits on, so a payload built with one of these
	 * arrives at refuse_unsanctioned_statements() as a SINGLE parsed
	 * statement carrying an embedded, unquoted semicolon — the shape
	 * has_executable_semicolon() exists to catch.
	 *
	 * @return string[] Four separators, each beginning with ";" but none equal to ";\n".
	 */
	private static function stacked_statement_separators(): array {
		return array( '; ', ";\t", ";\r\n", ';' );
	}

	/**
	 * A stacked statement smuggled behind a legitimate-looking DROP prefix must be refused,
	 * for every separator that survives the ";\n" statement split.
	 *
	 * DROP's shape check is exact equality, so a trailing byte already breaks
	 * it on its own here — but has_executable_semicolon() runs first, so it
	 * is the check that actually fires. Included for completeness alongside
	 * the CREATE and INSERT cases below, where the shape check is
	 * prefix-only and has_executable_semicolon() is the ONLY thing standing
	 * between a smuggled continuation and the database.
	 *
	 * @return void
	 */
	public function test_stacked_statement_after_drop_prefix_refused_for_every_separator(): void {
		$prefix  = 'DROP TABLE IF EXISTS `pontifexstg_t`';
		$hostile = "UPDATE `wp_users` SET user_pass='x' WHERE ID=1";
		foreach ( self::stacked_statement_separators() as $separator ) {
			self::assert_single_statement_chunk_refused( $prefix . $separator . $hostile . ";\n" );
		}
	}

	/**
	 * A stacked statement smuggled behind a legitimate-looking CREATE prefix must be refused,
	 * for every separator that survives the ";\n" statement split.
	 *
	 * CREATE's shape check is str_starts_with(), a PREFIX match with no end
	 * anchor: without has_executable_semicolon(), a payload built this way
	 * would sail straight past it. This is the exact case the guard exists
	 * to close.
	 *
	 * @return void
	 */
	public function test_stacked_statement_after_create_prefix_refused_for_every_separator(): void {
		$prefix  = 'CREATE TABLE `pontifexstg_t` (id INT)';
		$hostile = "UPDATE `wp_users` SET user_pass='x' WHERE ID=1";
		foreach ( self::stacked_statement_separators() as $separator ) {
			self::assert_single_statement_chunk_refused( $prefix . $separator . $hostile . ";\n" );
		}
	}

	/**
	 * A stacked statement smuggled behind a legitimate-looking column-list INSERT prefix
	 * must be refused, for every separator that survives the ";\n" statement split.
	 *
	 * The column-list shape is matched by a regex anchored only at \A, with
	 * no end anchor — like CREATE, it accepts any suffix, so only
	 * has_executable_semicolon() refuses the smuggled continuation.
	 *
	 * @return void
	 */
	public function test_stacked_statement_after_column_list_insert_prefix_refused_for_every_separator(): void {
		$prefix  = 'INSERT INTO `pontifexstg_t` (`id`) VALUES (1)';
		$hostile = "UPDATE `wp_users` SET user_pass='x' WHERE ID=1";
		foreach ( self::stacked_statement_separators() as $separator ) {
			self::assert_single_statement_chunk_refused( $prefix . $separator . $hostile . ";\n" );
		}
	}

	/**
	 * A stacked statement smuggled behind a legitimate-looking column-less INSERT prefix
	 * must be refused, for every separator that survives the ";\n" statement split.
	 *
	 * @return void
	 */
	public function test_stacked_statement_after_column_less_insert_prefix_refused_for_every_separator(): void {
		$prefix  = 'INSERT INTO `pontifexstg_t` VALUES (1)';
		$hostile = "UPDATE `wp_users` SET user_pass='x' WHERE ID=1";
		foreach ( self::stacked_statement_separators() as $separator ) {
			self::assert_single_statement_chunk_refused( $prefix . $separator . $hostile . ";\n" );
		}
	}

	// -----------------------------------------------------------------
	// Mutation backlog (db-chunk-containment): guards a mutation run found
	// unprotected. Each test below fails when its named mutation is re-applied.
	// -----------------------------------------------------------------

	/**
	 * A DROP naming a second, comma-separated table alongside the staged one must
	 * be refused — a multi-table DROP smuggled in a single statement, no embedded
	 * semicolon involved at all.
	 *
	 * Pins DROP's EXACT-EQUALITY shape check (item 1): weakening it to a mere
	 * str_starts_with() would accept this, because the sanctioned DROP text is a
	 * literal PREFIX of it.
	 *
	 * @return void
	 */
	public function test_drop_naming_a_second_comma_separated_table_refused(): void {
		self::assert_single_statement_chunk_refused( "DROP TABLE IF EXISTS `pontifexstg_t`, `wp_users`;\n" );
	}

	/**
	 * A CREATE TABLE ... SELECT, with no column-list "(" at all, must be refused.
	 *
	 * Pins CREATE's mandatory " (" anchor (item 2): without it, this shape — which
	 * lets the CREATE populate itself by reading an arbitrary source — passes the
	 * shape check on the table name alone.
	 *
	 * @return void
	 */
	public function test_create_table_select_refused(): void {
		self::assert_single_statement_chunk_refused( "CREATE TABLE `pontifexstg_t` SELECT * FROM `wp_users`;\n" );
	}

	/**
	 * A CREATE TABLE ... LIKE, with no column-list "(" at all, must be refused.
	 *
	 * The sibling of the SELECT form above for the same anchor (item 2): LIKE
	 * copies another table's structure, again with no "(" the mandatory-anchor
	 * check would otherwise require.
	 *
	 * @return void
	 */
	public function test_create_table_like_refused(): void {
		self::assert_single_statement_chunk_refused( "CREATE TABLE `pontifexstg_t` LIKE `wp_users`;\n" );
	}

	/**
	 * An INSERT with an explicit column list, immediately followed by SELECT
	 * rather than "VALUES (", must be refused.
	 *
	 * Pins the column-list INSERT regex's ") VALUES (" anchor (item 3): only the
	 * column-LESS form (a literal " VALUES (" right after the table name) is
	 * covered by {@see self::test_insert_select_refused()}; without this anchor,
	 * the column-list form would let an INSERT read an arbitrary column
	 * (`user_pass`) from an arbitrary table.
	 *
	 * @return void
	 */
	public function test_insert_with_column_list_select_refused(): void {
		self::assert_single_statement_chunk_refused( "INSERT INTO `pontifexstg_t` (`a`) SELECT user_pass FROM `wp_users`;\n" );
	}

	/**
	 * An UPDATE against a live table, whose quoted VALUE happens to CONTAIN text
	 * shaped exactly like a sanctioned column-list INSERT, must be refused.
	 *
	 * Pins the column-list INSERT regex's \A anchor (item 4): without it,
	 * preg_match() finds the INSERT-shaped text anywhere in the statement — even
	 * inside a quoted string literal — and would treat this UPDATE as a
	 * sanctioned INSERT because the pattern matches midway through it.
	 *
	 * @return void
	 */
	public function test_update_containing_insert_shaped_substring_refused(): void {
		self::assert_single_statement_chunk_refused(
			"UPDATE `wp_users` SET note = 'INSERT INTO `pontifexstg_t` (`a`) VALUES (' WHERE ID = 1;\n"
		);
	}

	/**
	 * An archive-declared table name carrying an embedded backtick, comma, and a
	 * second table name must not let escape_identifier()'s backtick-doubling be
	 * bypassed to smuggle a real multi-table DROP past even the EXACT-EQUALITY
	 * check.
	 *
	 * Pins escape_identifier() doubling backticks (item 5): the declared table
	 * name is "t`, `wp_users" — chosen so that, if escape_identifier() did NOT
	 * double the embedded backticks, the shape strings this writer builds for
	 * its OWN staged identifier would themselves collapse into the literal text
	 * "DROP TABLE IF EXISTS `pontifexstg_t`, `wp_users`" — an EXACT match against
	 * a hand-crafted payload carrying that same text, defeating item 1's
	 * exact-equality guard from the inside, at construction time, rather than by
	 * weakening the comparison. With escape_identifier() doubling correctly, the
	 * shape becomes one single (harmless) backtick-quoted identifier containing
	 * a literal backtick and comma, which this payload does not match.
	 *
	 * @return void
	 * @throws AssertionFailedError Rethrown immediately if write_entry() failed to throw (see the catch block below); never swallowed.
	 */
	public function test_declared_table_name_with_embedded_backtick_cannot_smuggle_a_second_table(): void {
		$adapter = new FakeDbAdapter();
		$writer  = new DatabaseWriter( $adapter );
		$sql     = "DROP TABLE IF EXISTS `pontifexstg_t`, `wp_users`;\n";

		try {
			$writer->write_entry( self::db_chunk_result( 't`, `wp_users', 1, $sql ) );
			self::fail( 'write_entry() should have refused this statement.' );
		} catch ( AssertionFailedError $bug ) {
			// See test_failed_rename_leaves_staging_for_abort() above for why this
			// rethrow must run before the RuntimeException catch below.
			throw $bug;
		} catch ( RuntimeException $refusal ) {
			unset( $refusal ); // The refusal is expected; only its effect matters here.
		}

		self::assertSame( array(), $adapter->executed_statements(), 'A refused chunk must execute nothing.' );
	}

	/**
	 * A partitioned table's CREATE, whose real content spans lines after the closing paren, must be permitted.
	 *
	 * SHOW CREATE TABLE output for a partitioned table trails a PARTITION BY
	 * clause after the column list's closing paren; the shape check only
	 * anchors the opening, so this real shape is not refused.
	 *
	 * @return void
	 */
	public function test_partitioned_table_create_permitted(): void {
		$adapter = new FakeDbAdapter();
		$writer  = new DatabaseWriter( $adapter );
		$sql     = "CREATE TABLE `t` (\n  `id` int NOT NULL\n) ENGINE=InnoDB\nPARTITION BY RANGE (`id`) (\n  PARTITION p0 VALUES LESS THAN (100)\n);\n";

		$writer->write_entry( self::db_chunk_result( 't', 1, $sql ) );

		$this->assertCount( 1, $adapter->executed_statements() );
		$this->assertStringStartsWith( 'CREATE TABLE `pontifexstg_t` (', $adapter->executed_statements()[0] );
	}

	/**
	 * A generated column and a CHECK constraint must be permitted.
	 *
	 * @return void
	 */
	public function test_generated_column_and_check_constraint_permitted(): void {
		$adapter = new FakeDbAdapter();
		$writer  = new DatabaseWriter( $adapter );
		$sql     = "CREATE TABLE `t` (\n  `id` int,\n  `double_id` int GENERATED ALWAYS AS (`id` * 2) STORED,\n  CHECK (`id` > 0)\n);\n";

		$writer->write_entry( self::db_chunk_result( 't', 1, $sql ) );

		$this->assertCount( 1, $adapter->executed_statements() );
	}

	/**
	 * A CREATE carrying a FOREIGN KEY reference to another table must be permitted.
	 *
	 * The reference lives inside the body, which the shape check never
	 * inspects — only the opening "CREATE TABLE `staged` (" is anchored.
	 *
	 * @return void
	 */
	public function test_foreign_key_reference_to_another_table_permitted(): void {
		$adapter = new FakeDbAdapter();
		$writer  = new DatabaseWriter( $adapter );
		$sql     = "CREATE TABLE `t` (\n  `id` int,\n  `parent_id` int,\n  FOREIGN KEY (`parent_id`) REFERENCES `wp_posts` (`ID`)\n);\n";

		$writer->write_entry( self::db_chunk_result( 't', 1, $sql ) );

		$this->assertCount( 1, $adapter->executed_statements() );
	}

	/**
	 * A table COMMENT containing awkward characters must be permitted.
	 *
	 * @return void
	 */
	public function test_table_comment_with_awkward_characters_permitted(): void {
		$adapter = new FakeDbAdapter();
		$writer  = new DatabaseWriter( $adapter );
		$sql     = "CREATE TABLE `t` (`id` int) COMMENT='it''s \"weird\"; DROP TABLE x -- ';\n";

		$writer->write_entry( self::db_chunk_result( 't', 1, $sql ) );

		$this->assertCount( 1, $adapter->executed_statements() );
	}

	/**
	 * A table COMMENT containing the literal text ") VALUES (", a semicolon, and a quote must be permitted.
	 *
	 * The shape check only anchors "CREATE TABLE `staged` (" — it never
	 * inspects the body, so a comment that happens to read like the middle
	 * of an INSERT statement, with a semicolon and a doubled quote embedded
	 * in it, changes nothing.
	 *
	 * @return void
	 */
	public function test_table_comment_containing_values_clause_text_permitted(): void {
		$adapter = new FakeDbAdapter();
		$writer  = new DatabaseWriter( $adapter );
		$sql     = "CREATE TABLE `t` (`id` int) COMMENT='it''s \") VALUES (\" and more; done';\n";

		$writer->write_entry( self::db_chunk_result( 't', 1, $sql ) );

		$this->assertCount( 1, $adapter->executed_statements() );
	}

	/**
	 * A column whose name contains a doubled backtick must be permitted.
	 *
	 * A real column named "wei`rd" is written as `` inside the identifier;
	 * the column pattern must match it rather than terminate early.
	 *
	 * @return void
	 */
	public function test_column_with_doubled_backtick_permitted(): void {
		$adapter = new FakeDbAdapter();
		$writer  = new DatabaseWriter( $adapter );
		$sql     = "INSERT INTO `t` (`normal`, `wei``rd`) VALUES (1, 2);\n";

		$writer->write_entry( self::db_chunk_result( 't', 1, $sql ) );

		$this->assertSame( 'INSERT INTO `pontifexstg_t` (`normal`, `wei``rd`) VALUES (1, 2)', $adapter->executed_statements()[0] );
	}

	/**
	 * A table name containing a space and a non-ASCII character must be permitted.
	 *
	 * Containment comes from byte-exact construction, never from a
	 * character allow-list on the table name — such a table is legal,
	 * exportable and restorable.
	 *
	 * @return void
	 */
	public function test_table_name_with_space_and_non_ascii_character_permitted(): void {
		$adapter    = new FakeDbAdapter();
		$writer     = new DatabaseWriter( $adapter );
		$table_name = "wp caf\xC3\xA9"; // "wp café" in UTF-8.
		$sql        = "CREATE TABLE `{$table_name}` (`id` int);\n";

		$writer->write_entry( self::db_chunk_result( $table_name, 1, $sql ) );

		$this->assertSame( "CREATE TABLE `pontifexstg_{$table_name}` (`id` int)", $adapter->executed_statements()[0] );
	}

	/**
	 * An INSERT whose VALUES contain semicolons, a newline escape, quotes and a backtick-quoted table name AS DATA must be permitted.
	 *
	 * Real row content can legitimately read like an injection attempt; the
	 * shape check never inspects past "VALUES (", so properly SQL-escaped
	 * data of any shape is left alone.
	 *
	 * @return void
	 */
	public function test_insert_with_sql_like_row_data_permitted(): void {
		$adapter = new FakeDbAdapter();
		$writer  = new DatabaseWriter( $adapter );
		$value   = "stacked: '); UPDATE `wp_users` SET user_pass='x' WHERE ID=1; --\\n";
		$escaped = str_replace( "'", "''", $value );
		$sql     = "INSERT INTO `t` (`id`, `note`) VALUES (1, '{$escaped}');\n";

		$writer->write_entry( self::db_chunk_result( 't', 1, $sql ) );

		$this->assertCount( 1, $adapter->executed_statements() );
		$this->assertStringStartsWith( 'INSERT INTO `pontifexstg_t` (`id`, `note`) VALUES (', $adapter->executed_statements()[0] );
	}

	/**
	 * A backslash-escaped quote immediately followed by a semicolon, inside a value, must be permitted.
	 *
	 * WordPress's own wpdb escaping can produce this shape. has_executable_semicolon()
	 * must recognise the backslash as escaping the quote that follows it, so
	 * the string is never treated as closed early — the semicolon right
	 * after it must stay inside the literal.
	 *
	 * @return void
	 */
	public function test_backslash_escaped_quote_before_semicolon_in_value_permitted(): void {
		$adapter = new FakeDbAdapter();
		$writer  = new DatabaseWriter( $adapter );
		$sql     = "INSERT INTO `t` (`id`, `note`) VALUES (1, 'a\\'; b');\n";

		$writer->write_entry( self::db_chunk_result( 't', 1, $sql ) );

		$this->assertCount( 1, $adapter->executed_statements() );
	}

	/**
	 * A doubled single quote immediately followed by a semicolon, inside a value, must be permitted.
	 *
	 * The doubled quote closes and immediately reopens the literal with
	 * nothing in between, so the semicolon that follows is still inside it —
	 * a different escaping convention from the backslash form above, and
	 * exercised separately here.
	 *
	 * @return void
	 */
	public function test_doubled_quote_before_semicolon_in_value_permitted(): void {
		$adapter = new FakeDbAdapter();
		$writer  = new DatabaseWriter( $adapter );
		$sql     = "INSERT INTO `t` (`id`, `note`) VALUES (1, 'it''; more');\n";

		$writer->write_entry( self::db_chunk_result( 't', 1, $sql ) );

		$this->assertCount( 1, $adapter->executed_statements() );
	}

	/**
	 * A value that is literally an UPDATE-then-DROP-TABLE attack string must be permitted.
	 *
	 * Properly SQL-escaped, this is indistinguishable from any other row
	 * content; the shape check never looks past "VALUES (", so the exact
	 * bytes an attacker would want to inject are, as data, completely inert.
	 *
	 * @return void
	 */
	public function test_literal_stacked_statement_text_as_value_permitted(): void {
		$adapter = new FakeDbAdapter();
		$writer  = new DatabaseWriter( $adapter );
		$value   = "UPDATE `wp_users` SET user_pass='x'; DROP TABLE `wp_posts`;";
		$escaped = str_replace( "'", "''", $value );
		$sql     = "INSERT INTO `t` (`id`, `note`) VALUES (1, '{$escaped}');\n";

		$writer->write_entry( self::db_chunk_result( 't', 1, $sql ) );

		$this->assertCount( 1, $adapter->executed_statements() );
	}

	/**
	 * A multi-megabyte INSERT must be permitted, and validated quickly.
	 *
	 * The shape check is anchored at the statement's start and never scans
	 * its body, so a large statement costs no more to validate than a
	 * small one.
	 *
	 * @return void
	 */
	public function test_multi_megabyte_insert_permitted(): void {
		$adapter = new FakeDbAdapter();
		$writer  = new DatabaseWriter( $adapter );
		$blob    = str_repeat( 'A', 2 * 1024 * 1024 );
		$sql     = "INSERT INTO `t` (`id`, `blob`) VALUES (1, '{$blob}');\n";

		$writer->write_entry( self::db_chunk_result( 't', 1, $sql ) );

		$this->assertCount( 1, $adapter->executed_statements() );
	}

	/**
	 * A wide column list — several hundred columns, comfortably past 8,100 bytes of
	 * identifier text — must be permitted.
	 *
	 * This is the regression test for the JIT defect COLUMN_IDENTIFIER_PATTERN's
	 * docblock describes: the previous per-character alternation cost the
	 * regex engine one backtrackable stack frame per matched character, so a
	 * table with a wide column list — which a plugin table with a few
	 * hundred columns reaches easily — exhausted the engine's default budget
	 * and made preg_match() fail, condemning a perfectly valid archive. The
	 * multi-megabyte INSERT test above does not cover this: it uses only two
	 * columns, so its cost is entirely in the ROW data the shape check never
	 * scans. This one is about the width of the column LIST itself, which
	 * the check does inspect.
	 *
	 * @return void
	 */
	public function test_wide_column_list_permitted(): void {
		$adapter = new FakeDbAdapter();
		$writer  = new DatabaseWriter( $adapter );
		$columns = array();
		for ( $i = 0; $i < 300; $i++ ) {
			$columns[] = '`' . str_repeat( 'a', 60 ) . $i . '`';
		}
		$sql = 'INSERT INTO `t` (' . implode( ', ', $columns ) . ") VALUES (1);\n";

		$writer->write_entry( self::db_chunk_result( 't', 1, $sql ) );

		$this->assertCount( 1, $adapter->executed_statements() );
	}

	/**
	 * A continuation chunk consisting of one INSERT and nothing else must be permitted.
	 *
	 * A large table's row data arrives across several chunks; a later chunk
	 * (non-zero chunk_index) carries only INSERTs, no schema statements.
	 *
	 * @return void
	 */
	public function test_continuation_chunk_of_one_insert_permitted(): void {
		$adapter = new FakeDbAdapter();
		$writer  = new DatabaseWriter( $adapter );

		$writer->write_entry( self::db_chunk_result( 't', 1, "INSERT INTO `t` VALUES (2);\n", 1 ) );

		$this->assertSame( array( 'INSERT INTO `pontifexstg_t` VALUES (2)' ), $adapter->executed_statements() );
	}

	/**
	 * A schema-only chunk of DROP + CREATE, with no INSERT, must be permitted.
	 *
	 * @return void
	 */
	public function test_schema_only_chunk_permitted(): void {
		$adapter = new FakeDbAdapter();
		$writer  = new DatabaseWriter( $adapter );
		$sql     = "DROP TABLE IF EXISTS `t`;\nCREATE TABLE `t` (`id` int);\n";

		$writer->write_entry( self::db_chunk_result( 't', 2, $sql ) );

		$this->assertSame(
			array(
				'DROP TABLE IF EXISTS `pontifexstg_t`',
				'CREATE TABLE `pontifexstg_t` (`id` int)',
			),
			$adapter->executed_statements()
		);
	}

	/**
	 * The column-less INSERT form must be permitted.
	 *
	 * This is the format spec's normative conformance vector
	 * (tests/Fixtures/conformance-v1_1.wpmig) and the shape most existing
	 * tests in this file already use.
	 *
	 * @return void
	 */
	public function test_column_less_insert_form_permitted(): void {
		$adapter = new FakeDbAdapter();
		$writer  = new DatabaseWriter( $adapter );

		$writer->write_entry( self::db_chunk_result( 't', 1, "INSERT INTO `t` VALUES (1);\n" ) );

		$this->assertSame( array( 'INSERT INTO `pontifexstg_t` VALUES (1)' ), $adapter->executed_statements() );
	}

	/**
	 * A statement carrying a trailing semicolon at the very end of the payload must be permitted.
	 *
	 * The splitter strips the ";\n" delimiter it splits on, but a payload
	 * whose last statement has no trailing newline after its final ";"
	 * leaves that semicolon in place. has_executable_semicolon() tolerates a
	 * single trailing semicolon with nothing after it — this proves that
	 * tolerance against the real writer, not just its docblock.
	 *
	 * @return void
	 */
	public function test_trailing_semicolon_at_end_of_statement_permitted(): void {
		$adapter = new FakeDbAdapter();
		$writer  = new DatabaseWriter( $adapter );
		// Deliberately no trailing "\n": the payload ends exactly on the ";".
		$sql = 'INSERT INTO `t` VALUES (1);';

		$writer->write_entry( self::db_chunk_result( 't', 1, $sql ) );

		$this->assertSame( array( 'INSERT INTO `pontifexstg_t` VALUES (1);' ), $adapter->executed_statements() );
	}

	/**
	 * A regex-engine failure while checking a column-list INSERT must throw its own distinct
	 * exception, never be reported as a shape refusal.
	 *
	 * Forced deterministically with a pcre.backtrack_limit of 1 — confirmed
	 * by manual testing to make preg_match() return false here regardless of
	 * whether the PCRE JIT is even compiled in, so the test does not depend
	 * on a particular input size happening to exceed some default budget.
	 * The point under test is the CODE PATH that distinguishes an engine
	 * failure (false) from an honest no-match (0); see the docblock on
	 * refuse_unsanctioned_statements(). The ini setting is restored in
	 * finally so no other test observes it.
	 *
	 * @return void
	 * @throws AssertionFailedError Rethrown immediately if write_entry() failed to throw (see the catch block below); never swallowed.
	 */
	public function test_pcre_engine_failure_produces_a_distinct_exception(): void {
		$original = ini_get( 'pcre.backtrack_limit' );
		// phpcs:ignore WordPress.PHP.IniSet.Risky -- Deliberately forcing a PCRE engine failure to prove the guard's distinct-exception path; restored in finally below.
		ini_set( 'pcre.backtrack_limit', '1' );

		try {
			$adapter = new FakeDbAdapter();
			$writer  = new DatabaseWriter( $adapter );
			$sql     = "INSERT INTO `t` (`id`) VALUES (1);\n";

			try {
				$writer->write_entry( self::db_chunk_result( 't', 1, $sql ) );
				$this->fail( 'write_entry() should have refused this statement once the regex engine failed.' );
			} catch ( AssertionFailedError $bug ) {
				// See test_failed_rename_leaves_staging_for_abort() above for why this
				// rethrow must run before the RuntimeException catch below.
				throw $bug;
			} catch ( RuntimeException $refusal ) {
				$this->assertStringContainsString( 'regular-expression engine failed', $refusal->getMessage() );
				$this->assertStringNotContainsString( 'does not match a sanctioned shape', $refusal->getMessage(), 'An engine failure must never be reported as a shape refusal.' );
			}

			$this->assertSame( array(), $adapter->executed_statements(), 'Nothing may execute when the engine itself could not be trusted.' );
		} finally {
			if ( false !== $original ) {
				// phpcs:ignore WordPress.PHP.IniSet.Risky -- Restoring the setting this test deliberately changed above.
				ini_set( 'pcre.backtrack_limit', $original );
			}
		}
	}

	/**
	 * Every db_chunk statement in the committed golden archive must pass validation unrefused.
	 *
	 * The guard against a future tightening bricking real archives: the
	 * fixture is the format specification's own normative conformance
	 * vector (tests/Fixtures/conformance-v1_1.wpmig), so it must always
	 * replay clean.
	 *
	 * @return void
	 */
	public function test_golden_archive_db_chunks_are_never_refused(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading the committed test fixture.
		$bytes  = (string) file_get_contents( dirname( __DIR__, 2 ) . '/Fixtures/conformance-v1_1.wpmig' );
		$source = self::memory_stream( $bytes );
		$reader = new ArchiveReader( $source );

		$entry_reader = new EntryReader( CodecRegistry::with_defaults() );
		$adapter      = new FakeDbAdapter();
		$writer       = new DatabaseWriter( $adapter );

		$executed_before = 0;
		foreach ( $reader->manifest()->entries() as $entry ) {
			if ( 'db_chunk' !== $entry->kind() ) {
				continue;
			}
			$result = $entry_reader->read_entry( $source, $entry );
			$writer->write_entry( $result );
			$executed_after = count( $adapter->executed_statements() );
			$this->assertGreaterThan( $executed_before, $executed_after, 'Every golden db_chunk must execute at least one statement.' );
			$executed_before = $executed_after;
		}
	}

	// -----------------------------------------------------------------
	// Storage-engine containment: the post-CREATE server-fact check (ADR 0019).
	// -----------------------------------------------------------------

	/**
	 * Configure the fake adapter to report given storage facts for "pontifexstg_t",
	 * assert a one-statement CREATE chunk against table "t" is refused, and
	 * assert the refusal message contains $expected_message_fragment and never
	 * the statement's own bytes.
	 *
	 * The CREATE itself must have already reached the adapter (the check runs
	 * only after it executes, per the class docblock's Verification point 5),
	 * so — unlike {@see self::assert_single_statement_chunk_refused()}, which
	 * asserts NOTHING executed — this asserts exactly the CREATE executed and
	 * nothing after it.
	 *
	 * @param string $engine                   The ENGINE fact to report.
	 * @param string $create_options            The CREATE_OPTIONS fact to report.
	 * @param string $table_type                The TABLE_TYPE fact to report.
	 * @param string $expected_message_fragment Text the refusal message must contain.
	 * @return void
	 * @throws AssertionFailedError Rethrown immediately if write_entry() failed to throw (see the catch block below); never swallowed.
	 */
	private static function assert_create_with_storage_facts_refused( string $engine, string $create_options, string $table_type, string $expected_message_fragment ): void {
		$adapter = new FakeDbAdapter();
		$adapter->set_table_storage_facts( 'pontifexstg_t', $engine, $create_options, $table_type );
		$writer = new DatabaseWriter( $adapter );

		try {
			$writer->write_entry( self::db_chunk_result( 't', 1, "CREATE TABLE `t` (id INT);\n" ) );
			self::fail( 'write_entry() should have refused this table\'s storage facts.' );
		} catch ( AssertionFailedError $bug ) {
			// See test_failed_rename_leaves_staging_for_abort() above for why this
			// rethrow must run before the RuntimeException catch below.
			throw $bug;
		} catch ( RuntimeException $refusal ) {
			self::assertStringContainsString( $expected_message_fragment, $refusal->getMessage() );
			self::assertStringNotContainsString( 'id INT', $refusal->getMessage(), 'The refusal message must never contain the statement bytes, only server-reported facts.' );
		}

		self::assertSame(
			array( 'CREATE TABLE `pontifexstg_t` (id INT)' ),
			$adapter->executed_statements(),
			'The CREATE itself must have executed against staging before the containment check refuses further progress.'
		);
	}

	/**
	 * A CREATE that built a MERGE table (MRG_MyISAM) must be refused, naming the engine.
	 *
	 * The write-through attack this check exists to close: a MERGE table's
	 * body-only UNION clause the shape check never inspects turns the staged
	 * identifier into a writable, readable alias for a live table.
	 *
	 * @return void
	 */
	public function test_create_with_merge_engine_refused(): void {
		self::assert_create_with_storage_facts_refused( 'MRG_MyISAM', '', 'BASE TABLE', 'MRG_MyISAM' );
	}

	/**
	 * A CREATE that built a FEDERATED table must be refused, naming the engine.
	 *
	 * @return void
	 */
	public function test_create_with_federated_engine_refused(): void {
		self::assert_create_with_storage_facts_refused( 'FEDERATED', "CONNECTION='mysql://user@host/db/table'", 'BASE TABLE', 'FEDERATED' );
	}

	/**
	 * A CREATE that built a CONNECT table must be refused, naming the engine.
	 *
	 * @return void
	 */
	public function test_create_with_connect_engine_refused(): void {
		self::assert_create_with_storage_facts_refused( 'CONNECT', "TABLE_TYPE=XML FILE_NAME='/etc/hostname'", 'BASE TABLE', 'CONNECT' );
	}

	/**
	 * A CREATE that built a CSV table must be refused, naming the engine.
	 *
	 * @return void
	 */
	public function test_create_with_csv_engine_refused(): void {
		self::assert_create_with_storage_facts_refused( 'CSV', '', 'BASE TABLE', 'CSV' );
	}

	/**
	 * A CREATE that built a BLACKHOLE table must be refused, naming the engine.
	 *
	 * @return void
	 */
	public function test_create_with_blackhole_engine_refused(): void {
		self::assert_create_with_storage_facts_refused( 'BLACKHOLE', '', 'BASE TABLE', 'BLACKHOLE' );
	}

	/**
	 * A CREATE whose CREATE_OPTIONS carries a DATA DIRECTORY clause must be refused,
	 * even on an otherwise allow-listed engine.
	 *
	 * Reproduces the exact form (upper case, a literal space, a quoted path
	 * with a trailing slash) confirmed empirically against a live MariaDB
	 * server — see the class docblock. MyISAM accepts DATA DIRECTORY and is
	 * itself on the engine allow-list, so this check is the only thing that
	 * refuses it.
	 *
	 * @return void
	 */
	public function test_create_with_data_directory_create_option_refused(): void {
		self::assert_create_with_storage_facts_refused( 'MyISAM', "DATA DIRECTORY='/tmp/'", 'BASE TABLE', 'table options that point outside its own local storage' );
	}

	/**
	 * A CREATE whose CREATE_OPTIONS carries an INDEX DIRECTORY clause must be refused.
	 *
	 * @return void
	 */
	public function test_create_with_index_directory_create_option_refused(): void {
		self::assert_create_with_storage_facts_refused( 'MyISAM', "INDEX DIRECTORY='/tmp/'", 'BASE TABLE', 'table options that point outside its own local storage' );
	}

	/**
	 * A lower-case, underscored DATA DIRECTORY form must also be refused.
	 *
	 * Proves normalise_create_options() folds case and underscores before
	 * matching, so the check does not depend on which spelling a particular
	 * server or version happens to report.
	 *
	 * @return void
	 */
	public function test_create_with_lowercase_underscored_data_directory_create_option_refused(): void {
		self::assert_create_with_storage_facts_refused( 'InnoDB', 'data_directory=/tmp', 'BASE TABLE', 'table options that point outside its own local storage' );
	}

	/**
	 * A CREATE_OPTIONS carrying a unioned-table clause must be refused, on any engine.
	 *
	 * Kept as defence in depth even though the live MariaDB server this codebase
	 * tested against leaves CREATE_OPTIONS empty for a MERGE table (the engine
	 * allow-list is what actually refuses MRG_MyISAM there) — see the class
	 * docblock.
	 *
	 * @return void
	 */
	public function test_create_with_union_create_option_refused(): void {
		self::assert_create_with_storage_facts_refused( 'MyISAM', 'union=(`wp_users`) insert_method=LAST', 'BASE TABLE', 'table options that point outside its own local storage' );
	}

	/**
	 * A CREATE_OPTIONS carrying a CONNECTION clause must be refused, even on an
	 * otherwise allow-listed engine.
	 *
	 * Pins "connection" staying in FORBIDDEN_CREATE_OPTION_FRAGMENTS (item 12):
	 * dropping it would let an allow-listed engine (InnoDB, here) report a
	 * CONNECTION option — the FEDERATED/CONNECT remote-server route — and pass
	 * unrefused.
	 *
	 * @return void
	 */
	public function test_create_with_connection_create_option_refused(): void {
		self::assert_create_with_storage_facts_refused( 'InnoDB', "CONNECTION='mysql://u@h/d/t'", 'BASE TABLE', 'table options that point outside its own local storage' );
	}

	/**
	 * A CREATE whose object is reported as a VIEW, not a base table, must be refused.
	 *
	 * Closes views by the same server-fact route as the engine/CREATE_OPTIONS
	 * checks, rather than by string-matching a "CREATE VIEW" statement — no
	 * real CREATE TABLE statement can build a view, so this is defence in
	 * depth on top of the shape check, not a currently-reachable route.
	 *
	 * @return void
	 */
	public function test_create_reported_as_a_view_refused(): void {
		self::assert_create_with_storage_facts_refused( '', '', 'VIEW', 'VIEW' );
	}

	/**
	 * A staged table whose storage facts could not be read at all must be refused.
	 *
	 * Cannot verify safety, so must not proceed — the same fail-closed posture
	 * as every other check in this class.
	 *
	 * @return void
	 * @throws AssertionFailedError Rethrown immediately if write_entry() failed to throw (see the catch block below); never swallowed.
	 */
	public function test_create_whose_storage_facts_could_not_be_read_refused(): void {
		$adapter = new FakeDbAdapter();
		$adapter->deny_table_storage_facts( 'pontifexstg_t' );
		$writer = new DatabaseWriter( $adapter );

		try {
			$writer->write_entry( self::db_chunk_result( 't', 1, "CREATE TABLE `t` (id INT);\n" ) );
			self::fail( 'write_entry() should refuse a table whose storage facts could not be read.' );
		} catch ( AssertionFailedError $bug ) {
			// See test_failed_rename_leaves_staging_for_abort() above for why this
			// rethrow must run before the RuntimeException catch below.
			throw $bug;
		} catch ( RuntimeException $refusal ) {
			self::assertStringContainsString( 'could not read the storage facts', $refusal->getMessage() );
		}
	}

	/**
	 * An ordinary InnoDB CREATE must be permitted.
	 *
	 * @return void
	 */
	public function test_create_with_ordinary_innodb_engine_permitted(): void {
		$adapter = new FakeDbAdapter();
		$adapter->set_table_storage_facts( 'pontifexstg_t', 'InnoDB', '', 'BASE TABLE' );
		$writer = new DatabaseWriter( $adapter );

		$writer->write_entry( self::db_chunk_result( 't', 1, "CREATE TABLE `t` (id INT) ENGINE=InnoDB;\n" ) );

		$this->assertSame( array( 'CREATE TABLE `pontifexstg_t` (id INT) ENGINE=InnoDB' ), $adapter->executed_statements() );
	}

	/**
	 * A disallowed engine must refuse the whole chunk BEFORE an INSERT later in the
	 * same chunk ever reaches the adapter.
	 *
	 * The exact shape of the proven attack: chunk A's CREATE builds a MERGE
	 * alias for a live table, chunk (here: statement) B's INSERT plants a row
	 * through it. Proves the check runs between the two statements, not only
	 * after the whole chunk.
	 *
	 * @return void
	 * @throws AssertionFailedError Rethrown immediately if write_entry() failed to throw (see the catch block below); never swallowed.
	 */
	public function test_disallowed_engine_refused_before_subsequent_insert_in_same_chunk(): void {
		$adapter = new FakeDbAdapter();
		$adapter->set_table_storage_facts( 'pontifexstg_t', 'MRG_MyISAM', '', 'BASE TABLE' );
		$writer = new DatabaseWriter( $adapter );
		$sql    = "CREATE TABLE `t` (`id` INT);\nINSERT INTO `t` (`id`) VALUES (99);\n";

		try {
			$writer->write_entry( self::db_chunk_result( 't', 2, $sql ) );
			self::fail( 'write_entry() should refuse before the INSERT in the same chunk ever executes.' );
		} catch ( AssertionFailedError $bug ) {
			// See test_failed_rename_leaves_staging_for_abort() above for why this
			// rethrow must run before the RuntimeException catch below.
			throw $bug;
		} catch ( RuntimeException $refusal ) {
			self::assertStringContainsString( 'MRG_MyISAM', $refusal->getMessage() );
		}

		$this->assertSame(
			array( 'CREATE TABLE `pontifexstg_t` (`id` INT)' ),
			$adapter->executed_statements(),
			'The INSERT must never reach the adapter once the CREATE it depends on is refused.'
		);
	}

	/**
	 * A CREATE that built a table MariaDB reports as TABLE_TYPE "SYSTEM VERSIONED" must be
	 * PERMITTED, not refused.
	 *
	 * Confirmed empirically against a live MariaDB server (12.3.2): a table
	 * created `WITH SYSTEM VERSIONING` reports TABLE_TYPE "SYSTEM VERSIONED",
	 * never "BASE TABLE". Before this widened allow-list, every such table
	 * aborted the WHOLE restore (restore is all-or-nothing), so a site could
	 * not restore its own backup — a false refusal worse than the
	 * vulnerability the check defends against.
	 *
	 * @return void
	 */
	public function test_create_with_system_versioned_table_type_permitted(): void {
		$adapter = new FakeDbAdapter();
		$adapter->set_table_storage_facts( 'pontifexstg_t', 'InnoDB', '', 'SYSTEM VERSIONED' );
		$writer = new DatabaseWriter( $adapter );

		$writer->write_entry( self::db_chunk_result( 't', 1, "CREATE TABLE `t` (id INT) ENGINE=InnoDB WITH SYSTEM VERSIONING;\n" ) );

		$this->assertSame(
			array( 'CREATE TABLE `pontifexstg_t` (id INT) ENGINE=InnoDB WITH SYSTEM VERSIONING' ),
			$adapter->executed_statements()
		);
	}

	/**
	 * A CREATE that built a table MariaDB reports as TABLE_TYPE "SEQUENCE" must be
	 * PERMITTED, not refused.
	 *
	 * Confirmed empirically against a live MariaDB server (12.3.2): `CREATE
	 * SEQUENCE x START WITH 1 INCREMENT BY 1` reads TABLE_TYPE "SEQUENCE",
	 * ENGINE "InnoDB" (already allow-listed), CREATE_OPTIONS "". Pontifex's
	 * own DatabaseScanner exports a sequence as an ordinary db_chunk — the
	 * same defect class as SYSTEM VERSIONED above, and the sibling that was
	 * missed the first time: before this widened allow-list, one sequence
	 * anywhere on a site made the WHOLE restore fail (restore is
	 * all-or-nothing), so a site could not restore its own backup at all.
	 *
	 * @return void
	 */
	public function test_create_with_mariadb_sequence_table_type_permitted(): void {
		$adapter = new FakeDbAdapter();
		$adapter->set_table_storage_facts( 'pontifexstg_t', 'InnoDB', '', 'SEQUENCE' );
		$writer = new DatabaseWriter( $adapter );

		$writer->write_entry( self::db_chunk_result( 't', 1, "CREATE TABLE `t` (`next_not_cached_value` bigint(21) NOT NULL) ENGINE=InnoDB SEQUENCE=1;\n" ) );

		$this->assertSame(
			array( 'CREATE TABLE `pontifexstg_t` (`next_not_cached_value` bigint(21) NOT NULL) ENGINE=InnoDB SEQUENCE=1' ),
			$adapter->executed_statements()
		);
	}

	/**
	 * A CREATE that built a table MyRocks/RocksDB reports as ENGINE "RocksDB" must be permitted.
	 *
	 * A site running MyRocks as its storage engine must be able to restore its
	 * own backup; refusing it, with no route forward, would be a false
	 * refusal worse than the vulnerability the allow-list defends against.
	 *
	 * @return void
	 */
	public function test_create_with_rocksdb_engine_permitted(): void {
		$adapter = new FakeDbAdapter();
		$adapter->set_table_storage_facts( 'pontifexstg_t', 'RocksDB', '', 'BASE TABLE' );
		$writer = new DatabaseWriter( $adapter );

		$writer->write_entry( self::db_chunk_result( 't', 1, "CREATE TABLE `t` (id INT) ENGINE=RocksDB;\n" ) );

		$this->assertSame( array( 'CREATE TABLE `pontifexstg_t` (id INT) ENGINE=RocksDB' ), $adapter->executed_statements() );
	}

	/**
	 * A CREATE that built a table TokuDB reports as ENGINE "TokuDB" must be permitted.
	 *
	 * @return void
	 */
	public function test_create_with_tokudb_engine_permitted(): void {
		$adapter = new FakeDbAdapter();
		$adapter->set_table_storage_facts( 'pontifexstg_t', 'TokuDB', '', 'BASE TABLE' );
		$writer = new DatabaseWriter( $adapter );

		$writer->write_entry( self::db_chunk_result( 't', 1, "CREATE TABLE `t` (id INT) ENGINE=TokuDB;\n" ) );

		$this->assertSame( array( 'CREATE TABLE `pontifexstg_t` (id INT) ENGINE=TokuDB' ), $adapter->executed_statements() );
	}

	/**
	 * A CREATE that built a table ColumnStore reports as ENGINE "ColumnStore" must be permitted.
	 *
	 * @return void
	 */
	public function test_create_with_columnstore_engine_permitted(): void {
		$adapter = new FakeDbAdapter();
		$adapter->set_table_storage_facts( 'pontifexstg_t', 'ColumnStore', '', 'BASE TABLE' );
		$writer = new DatabaseWriter( $adapter );

		$writer->write_entry( self::db_chunk_result( 't', 1, "CREATE TABLE `t` (id INT) ENGINE=ColumnStore;\n" ) );

		$this->assertSame( array( 'CREATE TABLE `pontifexstg_t` (id INT) ENGINE=ColumnStore' ), $adapter->executed_statements() );
	}

	/**
	 * A disallowed engine's refusal message must name the engine AND state plainly
	 * that Pontifex restores only ordinary local tables.
	 *
	 * @return void
	 * @throws AssertionFailedError Rethrown immediately if write_entry() failed to throw (see the catch block below); never swallowed.
	 */
	public function test_disallowed_engine_refusal_message_names_engine_and_explains(): void {
		$adapter = new FakeDbAdapter();
		$adapter->set_table_storage_facts( 'pontifexstg_t', 'SPIDER', '', 'BASE TABLE' );
		$writer = new DatabaseWriter( $adapter );

		try {
			$writer->write_entry( self::db_chunk_result( 't', 1, "CREATE TABLE `t` (id INT);\n" ) );
			// Deliberately does not name the engine under test: the catch block
			// asserts the real refusal message names it, and a fail() message
			// naming it too would let the assertion match its own failure text
			// instead of the writer's — see
			// test_failed_rename_leaves_staging_for_abort() above for the general
			// pattern and the AssertionFailedError rethrow that closes this either way.
			self::fail( 'write_entry() should have refused this disallowed storage engine.' );
		} catch ( AssertionFailedError $bug ) {
			throw $bug;
		} catch ( RuntimeException $refusal ) {
			self::assertStringContainsString( 'SPIDER', $refusal->getMessage(), 'The refusal message must name the engine.' );
			self::assertStringContainsString( 'restores only ordinary local tables', $refusal->getMessage(), 'The refusal message must say plainly what Pontifex will and will not restore.' );
		}
	}

	// -----------------------------------------------------------------
	// Partition-level DATA/INDEX DIRECTORY: the second server-fact check (ADR 0019).
	// -----------------------------------------------------------------

	/**
	 * A partitioned table whose own definition names a DATA DIRECTORY on one of its
	 * partitions must be refused, even though CREATE_OPTIONS reports only "partitioned"
	 * with no such detail.
	 *
	 * The exact write-through the table-level check cannot see: a table-level
	 * DATA/INDEX DIRECTORY is visible in CREATE_OPTIONS directly, but the same
	 * clause written on an individual PARTITION is not — proven against a
	 * live MariaDB server, where CREATE_OPTIONS for such a table reads
	 * exactly "partitioned" and nothing more.
	 *
	 * @return void
	 * @throws AssertionFailedError Rethrown immediately if write_entry() failed to throw (see the catch block below); never swallowed.
	 */
	public function test_partitioned_table_naming_partition_data_directory_refused(): void {
		$adapter = new FakeDbAdapter();
		$adapter->set_table_storage_facts( 'pontifexstg_t', 'MyISAM', 'partitioned', 'BASE TABLE' );
		$adapter->set_partition_storage_directory_present( 'pontifexstg_t', true );
		$writer = new DatabaseWriter( $adapter );

		try {
			$writer->write_entry( self::db_chunk_result( 't', 1, "CREATE TABLE `t` (id INT) ENGINE=MyISAM PARTITION BY RANGE (id) (PARTITION p0 VALUES LESS THAN (100));\n" ) );
			// Deliberately avoids the word "partition": the catch block asserts the
			// real refusal message contains it, and a fail() message containing it
			// too would let the assertion match its own failure text instead of the
			// writer's — see test_failed_rename_leaves_staging_for_abort() above for
			// the general pattern and the AssertionFailedError rethrow that closes
			// this either way.
			self::fail( 'write_entry() should have refused this storage-directory clause.' );
		} catch ( AssertionFailedError $bug ) {
			throw $bug;
		} catch ( RuntimeException $refusal ) {
			self::assertStringContainsString( 'partition', $refusal->getMessage() );
		}

		$this->assertSame(
			array( 'CREATE TABLE `pontifexstg_t` (id INT) ENGINE=MyISAM PARTITION BY RANGE (id) (PARTITION p0 VALUES LESS THAN (100))' ),
			$adapter->executed_statements(),
			'The CREATE itself must have executed against staging before the containment check refuses further progress.'
		);
	}

	/**
	 * A partitioned table whose partitions name no storage directory at all must be
	 * PERMITTED — proves the partition check does not refuse every partitioned table,
	 * only ones that actually redirect storage.
	 *
	 * @return void
	 */
	public function test_partitioned_table_naming_no_partition_directory_permitted(): void {
		$adapter = new FakeDbAdapter();
		$adapter->set_table_storage_facts( 'pontifexstg_t', 'InnoDB', 'partitioned', 'BASE TABLE' );
		$adapter->set_partition_storage_directory_present( 'pontifexstg_t', false );
		$writer = new DatabaseWriter( $adapter );

		$sql = "CREATE TABLE `t` (\n  `id` int NOT NULL\n) ENGINE=InnoDB\nPARTITION BY RANGE (`id`) (\n  PARTITION p0 VALUES LESS THAN (100)\n);\n";
		$writer->write_entry( self::db_chunk_result( 't', 1, $sql ) );

		$this->assertCount( 1, $adapter->executed_statements() );
	}

	/**
	 * An unpartitioned table must never trigger the partition-directory check at all —
	 * proves it is gated on CREATE_OPTIONS reporting "partitioned", not run for every table.
	 *
	 * @return void
	 */
	public function test_unpartitioned_table_never_queries_partition_directory_check(): void {
		$adapter = new FakeDbAdapter();
		$adapter->set_table_storage_facts( 'pontifexstg_t', 'InnoDB', '', 'BASE TABLE' );
		// A query error here would only surface if the check ran; it must not for an
		// unpartitioned table.
		$adapter->fail_next( 'partition_storage_directory_present', 'must not be called for an unpartitioned table' );
		$writer = new DatabaseWriter( $adapter );

		$writer->write_entry( self::db_chunk_result( 't', 1, "CREATE TABLE `t` (id INT) ENGINE=InnoDB;\n" ) );

		$this->assertCount( 1, $adapter->executed_statements() );
	}

	// -----------------------------------------------------------------
	// Comment-aware semicolon scanning: closing the comment-desync gap (ADR 0019).
	// -----------------------------------------------------------------

	/**
	 * A block comment hiding an unmatched apostrophe, before a stacked UPDATE after
	 * an otherwise ordinary CREATE, must refuse the whole chunk.
	 *
	 * Before comment-awareness, the apostrophe inside "/* don't *\/" opened a
	 * quote that never closed (nothing later in the statement carries another
	 * apostrophe), so the scanner believed it remained inside a literal for the
	 * rest of the statement and never saw the real semicolon that follows —
	 * proven against real MariaDB (ADR 0019).
	 *
	 * @return void
	 */
	public function test_block_comment_hiding_apostrophe_before_stacked_update_refused(): void {
		self::assert_single_statement_chunk_refused(
			"CREATE TABLE `pontifexstg_t` (id INT) /* don't */; UPDATE `wp_users` SET a=1;\n"
		);
	}

	/**
	 * The same block-comment desync, after an ordinary INSERT, before a stacked DROP.
	 *
	 * @return void
	 */
	public function test_block_comment_hiding_apostrophe_before_stacked_drop_refused(): void {
		self::assert_single_statement_chunk_refused(
			"INSERT INTO `pontifexstg_t` (`a`) VALUES (1) /* it's */; DROP TABLE `wp_users`;\n"
		);
	}

	/**
	 * The same block-comment desync, hiding only a lone quote, before a stacked GRANT.
	 *
	 * @return void
	 */
	public function test_block_comment_hiding_lone_quote_before_stacked_grant_refused(): void {
		self::assert_single_statement_chunk_refused(
			"CREATE TABLE `pontifexstg_t` (id INT) /* ' */; GRANT ALL ON *.* TO 'evil'@'%';\n"
		);
	}

	/**
	 * A "-- " line comment hiding an unmatched apostrophe before a stacked UPDATE
	 * must refuse the whole chunk.
	 *
	 * @return void
	 */
	public function test_line_dash_comment_hiding_apostrophe_before_stacked_update_refused(): void {
		self::assert_single_statement_chunk_refused(
			"CREATE TABLE `pontifexstg_t` (id INT) -- don't\n; UPDATE `wp_users` SET a=1;\n"
		);
	}

	/**
	 * A "#" line comment hiding an unmatched apostrophe before a stacked UPDATE
	 * must refuse the whole chunk.
	 *
	 * @return void
	 */
	public function test_hash_comment_hiding_apostrophe_before_stacked_update_refused(): void {
		self::assert_single_statement_chunk_refused(
			"CREATE TABLE `pontifexstg_t` (id INT) # don't\n; UPDATE `wp_users` SET a=1;\n"
		);
	}

	/**
	 * A MySQL conditional-execution comment carrying an unbalanced quote must refuse
	 * the whole chunk.
	 *
	 * Unlike an ordinary comment, a conditional-execution comment's contents ARE
	 * executed, so the apostrophe inside "/*!40101 don't *\/" is a REAL,
	 * unmatched quote — nothing later in the statement closes it — which must
	 * itself be refused (an unterminated literal's true extent cannot be
	 * determined), not silently treated as still "inside" a comment.
	 *
	 * @return void
	 */
	public function test_conditional_comment_with_unbalanced_quote_refused(): void {
		self::assert_single_statement_chunk_refused(
			"CREATE TABLE `pontifexstg_t` (id INT) /*!40101 don't */; UPDATE `wp_users` SET a=1;\n"
		);
	}

	/**
	 * A block comment hiding an unmatched double quote before a stacked UPDATE
	 * must refuse the whole chunk.
	 *
	 * @return void
	 */
	public function test_block_comment_hiding_double_quote_before_stacked_update_refused(): void {
		self::assert_single_statement_chunk_refused(
			"CREATE TABLE `pontifexstg_t` (id INT) /* say \"hi */; UPDATE `wp_users` SET a=1;\n"
		);
	}

	/**
	 * A block comment hiding an unmatched backtick before a stacked UPDATE
	 * must refuse the whole chunk.
	 *
	 * @return void
	 */
	public function test_block_comment_hiding_backtick_before_stacked_update_refused(): void {
		self::assert_single_statement_chunk_refused(
			"CREATE TABLE `pontifexstg_t` (id INT) /* `x */; UPDATE `wp_users` SET a=1;\n"
		);
	}

	/**
	 * A semicolon INSIDE a conditional-execution comment is itself executable and
	 * must refuse the whole chunk.
	 *
	 * Conditional-execution comment content is real, executed SQL, so a
	 * semicolon inside one ends a statement exactly as it would anywhere else —
	 * proven separately from the seven cases above, none of which puts the
	 * hostile semicolon inside the comment itself.
	 *
	 * @return void
	 */
	public function test_conditional_comment_containing_its_own_semicolon_refused(): void {
		self::assert_single_statement_chunk_refused(
			"CREATE TABLE `pontifexstg_t` (id INT) /*!40101 SET @a=1; SET @b=2 */;\n"
		);
	}

	/**
	 * An ordinary block comment left unterminated at the end of a statement must
	 * refuse the whole chunk.
	 *
	 * A sibling of the unbalanced-quote case above, closing the same class of
	 * gap: a legitimate Pontifex-produced statement never leaves a comment
	 * open, so its true extent — and so whatever bytes trail it — cannot be
	 * determined and must be refused rather than silently treated as safe.
	 *
	 * @return void
	 */
	public function test_unterminated_block_comment_refused(): void {
		self::assert_single_statement_chunk_refused(
			"CREATE TABLE `pontifexstg_t` (id INT) /* never closed ; UPDATE `wp_users` SET a=1;\n"
		);
	}

	/**
	 * A quoted literal left unterminated at the end of a statement, with no comment
	 * involved at all, must refuse the whole chunk.
	 *
	 * The general sibling case both comment-based refusals above reduce to: any
	 * literal left open at the end of a statement, comment or not, must be
	 * refused.
	 *
	 * @return void
	 */
	public function test_unterminated_top_level_quote_refused(): void {
		self::assert_single_statement_chunk_refused(
			"INSERT INTO `pontifexstg_t` (`note`) VALUES ('never closed ; UPDATE `wp_users` SET a=1;\n"
		);
	}

	/**
	 * A partitioned table's CREATE carrying a real "/*!50100 PARTITION BY ... *\/"
	 * conditional comment, as the real exporter emits it, must be permitted.
	 *
	 * @return void
	 */
	public function test_partitioned_table_create_with_conditional_comment_permitted(): void {
		$adapter = new FakeDbAdapter();
		$writer  = new DatabaseWriter( $adapter );
		$sql     = "CREATE TABLE `t` (\n  `id` int NOT NULL\n) ENGINE=InnoDB\n/*!50100 PARTITION BY RANGE (`id`)\n(PARTITION p0 VALUES LESS THAN (100))*/;\n";

		$writer->write_entry( self::db_chunk_result( 't', 1, $sql ) );

		$this->assertCount( 1, $adapter->executed_statements() );
	}

	/**
	 * A column carrying a real "/*!80000 INVISIBLE *\/" conditional comment, as the
	 * real exporter emits it, must be permitted.
	 *
	 * @return void
	 */
	public function test_invisible_column_conditional_comment_permitted(): void {
		$adapter = new FakeDbAdapter();
		$writer  = new DatabaseWriter( $adapter );
		$sql     = "CREATE TABLE `t` (\n  `id` int NOT NULL,\n  KEY `k` (`id`) /*!80000 INVISIBLE */\n);\n";

		$writer->write_entry( self::db_chunk_result( 't', 1, $sql ) );

		$this->assertCount( 1, $adapter->executed_statements() );
	}

	/**
	 * A table COMMENT containing every comment introducer this scan recognises
	 * — block, dash-line, hash-line — plus a quote and a semicolon, all as data
	 * inside a properly quoted and escaped value, must be permitted.
	 *
	 * @return void
	 */
	public function test_table_comment_containing_every_comment_introducer_permitted(): void {
		$adapter = new FakeDbAdapter();
		$writer  = new DatabaseWriter( $adapter );
		$value   = "has /* block */ and -- line and # hash and ' and ;";
		$escaped = str_replace( "'", "''", $value );
		$sql     = "CREATE TABLE `t` (`id` int) COMMENT='{$escaped}';\n";

		$writer->write_entry( self::db_chunk_result( 't', 1, $sql ) );

		$this->assertCount( 1, $adapter->executed_statements() );
	}

	/**
	 * An INSERT value containing block-comment bytes and a semicolon, inside a
	 * quoted literal, must be permitted.
	 *
	 * @return void
	 */
	public function test_insert_value_containing_block_comment_bytes_and_semicolon_permitted(): void {
		$adapter = new FakeDbAdapter();
		$writer  = new DatabaseWriter( $adapter );
		$sql     = "INSERT INTO `t` (`id`, `note`) VALUES (1, 'contains /* not a comment */ and ; not executable');\n";

		$writer->write_entry( self::db_chunk_result( 't', 1, $sql ) );

		$this->assertCount( 1, $adapter->executed_statements() );
	}

	/**
	 * MariaDB's "/*M! ... *\/" conditional-execution comment syntax is also executable
	 * content on the server, exactly like the standard "/*! ... *\/" form — confirmed
	 * empirically against a live MariaDB server (SELECT 1 /*M!100000 +1 *\/ returns 2)
	 * — so a semicolon inside one must be treated as executable and refuse the whole
	 * chunk.
	 *
	 * A modelling gap, not a currently-exploitable hole: on MariaDB 12.3.2 a bare ";"
	 * inside either conditional-comment form is itself a syntax error, so this
	 * specific shape is not reachable via a real statement today — but the scanner's
	 * MODEL of what the server executes must still be correct, and this closes the
	 * gap between the code (which previously recognised only "/*!") and what the
	 * server actually executes.
	 *
	 * @return void
	 */
	public function test_mariadb_conditional_comment_containing_its_own_semicolon_refused(): void {
		self::assert_single_statement_chunk_refused(
			"CREATE TABLE `pontifexstg_t` (id INT) /*M!100000 SET @a=1; SET @b=2 */;\n"
		);
	}

	// -----------------------------------------------------------------
	// The "--" follow-byte requirement (ADR 0019, defect 5).
	// -----------------------------------------------------------------

	/**
	 * A "--x" byte sequence — two dashes with no qualifying whitespace/control byte
	 * after them — is not a comment introducer at all under standard SQL, so the
	 * semicolon that follows must still be treated as executable, refusing the whole
	 * chunk.
	 *
	 * Pins the "--" follow-byte requirement itself: replacing it with an
	 * unconditional true (treating a bare "--" as a comment introducer on its own,
	 * with no byte-after check) leaves the whole suite green with no other test
	 * catching the regression — this is that test.
	 *
	 * @return void
	 */
	public function test_dash_dash_with_no_follow_byte_is_not_a_comment_and_refuses(): void {
		self::assert_single_statement_chunk_refused(
			"CREATE TABLE `pontifexstg_t` (id INT) --x; UPDATE `wp_users` SET a=1;\n"
		);
	}

	/**
	 * A "-- x" byte sequence — two dashes followed by a qualifying space — IS a real
	 * line comment; the semicolon inside it is inert, and the statement must be
	 * permitted.
	 *
	 * The companion of the "--x" case above: proves the follow-byte check does not
	 * merely refuse everything starting "--", only the shapes that are not
	 * genuinely comments.
	 *
	 * @return void
	 */
	public function test_dash_dash_with_space_follow_byte_is_a_real_comment_and_permits(): void {
		$adapter = new FakeDbAdapter();
		$writer  = new DatabaseWriter( $adapter );
		$sql     = "CREATE TABLE `pontifexstg_t` (id INT) -- x; inert\n;\n";

		$writer->write_entry( self::db_chunk_result( 't', 1, $sql ) );

		$this->assertCount( 1, $adapter->executed_statements() );
	}

	// -----------------------------------------------------------------
	// sql_mode-aware backslash escaping: NO_BACKSLASH_ESCAPES (ADR 0019, defect 3).
	// -----------------------------------------------------------------

	/**
	 * Assert a single-statement chunk against table "t" is refused once begin_staging()
	 * has read the given sql_mode from the adapter.
	 *
	 * The sql_mode-aware sibling of {@see self::assert_single_statement_chunk_refused()}
	 * — that helper never calls begin_staging(), so it always exercises the default
	 * (backslash-is-escape) property value; this one proves the SAME containment
	 * holds once a real sql_mode has actually been read and cached.
	 *
	 * @param string $sql      The single SQL statement, terminated with ";\n".
	 * @param string $sql_mode The sql_mode the adapter must report to begin_staging().
	 * @return void
	 * @throws AssertionFailedError Rethrown immediately if write_entry() failed to throw (see the catch block below); never swallowed.
	 */
	private static function assert_single_statement_chunk_refused_under_sql_mode( string $sql, string $sql_mode ): void {
		$adapter = new FakeDbAdapter();
		$adapter->set_sql_mode( $sql_mode );
		$writer = new DatabaseWriter( $adapter );
		$writer->begin_staging();

		try {
			$writer->write_entry( self::db_chunk_result( 't', 1, $sql ) );
			self::fail( 'write_entry() should have refused this statement under sql_mode "' . $sql_mode . '": ' . $sql );
		} catch ( AssertionFailedError $bug ) {
			// See test_failed_rename_leaves_staging_for_abort() above for why this
			// rethrow must run before the RuntimeException catch below.
			throw $bug;
		} catch ( RuntimeException $refusal ) {
			unset( $refusal ); // The refusal is expected; only its effect matters here.
		}

		self::assertSame( array(), $adapter->executed_statements(), 'A refused chunk must execute nothing.' );
	}

	/**
	 * A value ending in a single, unescaped backslash — the shape wpdb itself emits
	 * under sql_mode=NO_BACKSLASH_ESCAPES, since the server does not treat a
	 * backslash as special there — must be PERMITTED once begin_staging() has read
	 * that sql_mode from the adapter.
	 *
	 * Before this fix, has_executable_semicolon() always treated a backslash as an
	 * escape character, so it read the backslash here as escaping the closing quote,
	 * never found a real closing quote for the rest of the statement, and refused a
	 * perfectly ordinary, legitimate statement — proven: this exact shape "replayed
	 * fine before" the unterminated-literal backstop was added, and was only refused
	 * once that backstop started catching this false case too.
	 *
	 * @return void
	 */
	public function test_value_ending_in_backslash_permitted_under_no_backslash_escapes_sql_mode(): void {
		$adapter = new FakeDbAdapter();
		$adapter->set_sql_mode( 'NO_BACKSLASH_ESCAPES' );
		$writer = new DatabaseWriter( $adapter );
		$writer->begin_staging();

		$sql = "INSERT INTO `t` (`id`, `path`) VALUES (1, 'C:\\');\n";
		$writer->write_entry( self::db_chunk_result( 't', 1, $sql ) );

		$this->assertCount( 1, $adapter->executed_statements() );
	}

	/**
	 * The SAME value ending in a single backslash must also be permitted when
	 * NO_BACKSLASH_ESCAPES is only one of several modes in a realistic, comma-joined
	 * sql_mode string — proves the check is a substring match, not an exact-equality
	 * one, against the server's real reporting shape.
	 *
	 * @return void
	 */
	public function test_value_ending_in_backslash_permitted_under_multi_mode_no_backslash_escapes_sql_mode(): void {
		$adapter = new FakeDbAdapter();
		$adapter->set_sql_mode( 'STRICT_TRANS_TABLES,NO_BACKSLASH_ESCAPES,NO_ZERO_DATE' );
		$writer = new DatabaseWriter( $adapter );
		$writer->begin_staging();

		$sql = "INSERT INTO `t` (`id`, `path`) VALUES (1, 'C:\\');\n";
		$writer->write_entry( self::db_chunk_result( 't', 1, $sql ) );

		$this->assertCount( 1, $adapter->executed_statements() );
	}

	/**
	 * A value ending in a DOUBLED backslash — the shape wpdb emits under the
	 * ORDINARY (escape-enabled) sql_mode to represent one literal trailing backslash
	 * — must be permitted once begin_staging() has read that ordinary mode from the
	 * adapter.
	 *
	 * The escape-mode counterpart of the NO_BACKSLASH_ESCAPES case above: proves
	 * begin_staging() reading an ordinary sql_mode (no NO_BACKSLASH_ESCAPES token)
	 * keeps backslash-is-escape behaviour intact, not merely the untouched property
	 * default.
	 *
	 * @return void
	 */
	public function test_value_ending_in_doubled_backslash_permitted_under_ordinary_sql_mode(): void {
		$adapter = new FakeDbAdapter();
		$adapter->set_sql_mode( 'STRICT_TRANS_TABLES' );
		$writer = new DatabaseWriter( $adapter );
		$writer->begin_staging();

		$sql = "INSERT INTO `t` (`id`, `path`) VALUES (1, 'C:\\\\');\n";
		$writer->write_entry( self::db_chunk_result( 't', 1, $sql ) );

		$this->assertCount( 1, $adapter->executed_statements() );
	}

	/**
	 * A value containing a BACKSLASH-ESCAPED apostrophe — the shape wpdb's own
	 * escaping produces under the server's ordinary sql_mode — must be permitted
	 * once begin_staging() has actually read that ordinary mode from the adapter.
	 *
	 * Pins begin_staging() assigning $backslash_is_escape FROM the server's
	 * reported sql_mode (item 8), in the direction none of the existing
	 * begin_staging()-driven tests distinguish: a value ending in a bare or
	 * doubled trailing backslash closes at the same real quote whether or not
	 * the flag is actually honoured (confirmed empirically), so those tests stay
	 * green even if begin_staging() were mutated to unconditionally set the
	 * property false. A backslash-escaped APOSTROPHE does not have that
	 * coincidence: forcing the property false here reads the escaped apostrophe
	 * as the real closing quote, leaves the rest of the statement unterminated,
	 * and wrongly refuses this entirely legitimate value.
	 *
	 * @return void
	 */
	public function test_backslash_escaped_apostrophe_permitted_once_begin_staging_reads_ordinary_sql_mode(): void {
		$adapter = new FakeDbAdapter();
		$adapter->set_sql_mode( 'STRICT_TRANS_TABLES' );
		$writer = new DatabaseWriter( $adapter );
		$writer->begin_staging();

		$sql = "INSERT INTO `t` (`id`, `note`) VALUES (1, 'it\\'s a note');\n";
		$writer->write_entry( self::db_chunk_result( 't', 1, $sql ) );

		$this->assertCount( 1, $adapter->executed_statements() );
	}

	/**
	 * A stacked statement smuggled behind a legitimate-looking CREATE prefix must still
	 * be refused under sql_mode=NO_BACKSLASH_ESCAPES — proves the containment scan is
	 * not weakened by treating backslash as an ordinary character.
	 *
	 * @return void
	 */
	public function test_stacked_statement_refused_under_no_backslash_escapes_sql_mode(): void {
		self::assert_single_statement_chunk_refused_under_sql_mode(
			"CREATE TABLE `pontifexstg_t` (id INT); UPDATE `wp_users` SET user_pass='x' WHERE ID=1;\n",
			'NO_BACKSLASH_ESCAPES'
		);
	}

	/**
	 * A stacked statement smuggled behind a legitimate-looking CREATE prefix must still
	 * be refused under the ORDINARY sql_mode, once actually read via begin_staging()
	 * rather than only relying on the property default.
	 *
	 * @return void
	 */
	public function test_stacked_statement_refused_under_ordinary_sql_mode(): void {
		self::assert_single_statement_chunk_refused_under_sql_mode(
			"CREATE TABLE `pontifexstg_t` (id INT); UPDATE `wp_users` SET user_pass='x' WHERE ID=1;\n",
			''
		);
	}

	/**
	 * When the destination server's sql_mode cannot be read at all, begin_staging()
	 * must choose the SAFE (strict, no-escape) interpretation — proven here by the
	 * same backslash-ending value the NO_BACKSLASH_ESCAPES case above permits: the
	 * safe fallback treats backslash exactly the way NO_BACKSLASH_ESCAPES does, so
	 * this must also be permitted, not refused.
	 *
	 * @return void
	 */
	public function test_value_ending_in_backslash_permitted_when_sql_mode_unreadable(): void {
		$adapter = new FakeDbAdapter();
		$adapter->deny_sql_mode();
		$writer = new DatabaseWriter( $adapter );
		$writer->begin_staging();

		$sql = "INSERT INTO `t` (`id`, `path`) VALUES (1, 'C:\\');\n";
		$writer->write_entry( self::db_chunk_result( 't', 1, $sql ) );

		$this->assertCount( 1, $adapter->executed_statements() );
	}

	/**
	 * A stacked statement must still be refused when the destination server's
	 * sql_mode cannot be read at all — the safe fallback must never weaken
	 * containment.
	 *
	 * @return void
	 * @throws AssertionFailedError Rethrown immediately if write_entry() failed to throw (see the catch block below); never swallowed.
	 */
	public function test_stacked_statement_refused_when_sql_mode_unreadable(): void {
		$adapter = new FakeDbAdapter();
		$adapter->deny_sql_mode();
		$writer = new DatabaseWriter( $adapter );
		$writer->begin_staging();

		try {
			$writer->write_entry( self::db_chunk_result( 't', 1, "CREATE TABLE `pontifexstg_t` (id INT); UPDATE `wp_users` SET user_pass='x' WHERE ID=1;\n" ) );
			self::fail( 'write_entry() should have refused this statement when sql_mode is unreadable.' );
		} catch ( AssertionFailedError $bug ) {
			// See test_failed_rename_leaves_staging_for_abort() above for why this
			// rethrow must run before the RuntimeException catch below.
			throw $bug;
		} catch ( RuntimeException $refusal ) {
			unset( $refusal ); // The refusal is expected; only its effect matters here.
		}

		$this->assertSame( array(), $adapter->executed_statements(), 'A refused chunk must execute nothing.' );
	}

	// -----------------------------------------------------------------
	// CREATE ... SELECT / CREATE ... AS SELECT row-count containment (ADR 0019):
	// the CREATE shape check's mandatory " (" anchor requires a column list, but
	// says nothing about what follows the list's closing paren, and a
	// `CREATE TABLE `<staged>` (`c` INT) SELECT ...` populates the table from an
	// arbitrary source in the very same statement — no executable semicolon
	// involved, and the object built is an entirely ordinary InnoDB base table,
	// so neither the shape check nor the storage-facts check sees anything
	// wrong. Proven end-to-end against a real MariaDB server: the CREATE
	// SUCCEEDED and the staged table survived cut-over holding the live site's
	// password hashes. Closed by reading the table's actual row count
	// immediately after the CREATE has executed.
	// -----------------------------------------------------------------

	/**
	 * Configure the fake adapter to report $row_count rows for "pontifexstg_t",
	 * assert a one-statement CREATE chunk built from $sql is refused, and assert
	 * the refusal names the staged table and says the CREATE produced rows,
	 * never the statement's own bytes.
	 *
	 * @param string $sql       The CREATE statement, terminated with ";\n", already written against the staged identifier "pontifexstg_t".
	 * @param int    $row_count The row count the fake adapter must report for "pontifexstg_t".
	 * @return void
	 * @throws AssertionFailedError Rethrown immediately if write_entry() failed to throw (see the catch block below); never swallowed.
	 */
	private static function assert_create_with_row_count_refused( string $sql, int $row_count ): void {
		$adapter = new FakeDbAdapter();
		$adapter->set_table_row_count( 'pontifexstg_t', $row_count );
		$writer = new DatabaseWriter( $adapter );

		try {
			$writer->write_entry( self::db_chunk_result( 't', 1, $sql ) );
			self::fail( 'write_entry() should have refused this CREATE for producing rows.' );
		} catch ( AssertionFailedError $bug ) {
			// See test_failed_rename_leaves_staging_for_abort() above for why this
			// rethrow must run before the RuntimeException catch below.
			throw $bug;
		} catch ( RuntimeException $refusal ) {
			self::assertStringContainsString( 'pontifexstg_t', $refusal->getMessage() );
			self::assertStringContainsString( 'produced rows', $refusal->getMessage() );
			self::assertStringNotContainsString( 'wp_users', $refusal->getMessage(), 'The refusal message must never contain the statement bytes.' );
		}
	}

	/**
	 * A `CREATE TABLE (cols) SELECT ...`, with a genuine column-list open paren, must
	 * be refused when the table it built holds rows — the exact bypass this check
	 * closes: the mandatory `" ("` anchor is satisfied, so the shape check alone
	 * would let this through, and the object built is an ordinary InnoDB base
	 * table, so the storage-facts check alone would too.
	 *
	 * @return void
	 */
	public function test_create_table_select_with_column_list_refused(): void {
		self::assert_create_with_row_count_refused(
			"CREATE TABLE `pontifexstg_t` (`c` INT) SELECT id, user_login, user_pass FROM `wp_users`;\n",
			3
		);
	}

	/**
	 * The `AS SELECT` sibling of the above — the `AS` keyword changes nothing the
	 * shape check or the storage-facts check inspect, so it must be refused the
	 * same way.
	 *
	 * @return void
	 */
	public function test_create_table_as_select_with_column_list_refused(): void {
		self::assert_create_with_row_count_refused(
			"CREATE TABLE `pontifexstg_t` (`c` INT) AS SELECT id, user_login, user_pass FROM `wp_users`;\n",
			3
		);
	}

	/**
	 * A CREATE reported as having produced rows must be refused even when its own
	 * statement text carries no SELECT at all — this check asks the server a fact
	 * about the object it built; it is not a text search for the word "SELECT".
	 *
	 * @return void
	 */
	public function test_create_reporting_a_populated_table_without_a_select_refused(): void {
		self::assert_create_with_row_count_refused( "CREATE TABLE `pontifexstg_t` (id INT) ENGINE=InnoDB;\n", 1 );
	}

	/**
	 * A `CREATE TABLE (cols) SELECT ...` that produced rows must be refused BEFORE a
	 * later statement in the same chunk (an INSERT that would compound the damage)
	 * ever reaches the adapter — the same ordering guarantee already proven for the
	 * storage-facts checks.
	 *
	 * @return void
	 * @throws AssertionFailedError Rethrown immediately if write_entry() failed to throw (see the catch block below); never swallowed.
	 */
	public function test_create_table_select_refused_before_subsequent_statement_in_same_chunk(): void {
		$adapter = new FakeDbAdapter();
		$adapter->set_table_row_count( 'pontifexstg_t', 3 );
		$writer = new DatabaseWriter( $adapter );
		$sql    = "CREATE TABLE `pontifexstg_t` (`c` INT) SELECT id FROM `wp_users`;\n"
			. "INSERT INTO `pontifexstg_t` (`c`) VALUES (99);\n";

		try {
			$writer->write_entry( self::db_chunk_result( 't', 2, $sql ) );
			self::fail( 'write_entry() should refuse before the INSERT in the same chunk ever executes.' );
		} catch ( AssertionFailedError $bug ) {
			// See test_failed_rename_leaves_staging_for_abort() above for why this
			// rethrow must run before the RuntimeException catch below.
			throw $bug;
		} catch ( RuntimeException $refusal ) {
			self::assertStringContainsString( 'produced rows', $refusal->getMessage() );
		}

		$this->assertSame(
			array( 'CREATE TABLE `pontifexstg_t` (`c` INT) SELECT id FROM `wp_users`' ),
			$adapter->executed_statements(),
			'The INSERT must never reach the adapter once the CREATE it depends on is refused.'
		);
	}

	/**
	 * A MariaDB SEQUENCE reporting MORE than its own single, self-seeded state row
	 * must be refused — the emptiness check's SEQUENCE exception is capped at
	 * exactly one row, not an unconditional exemption for the whole table_type.
	 *
	 * @return void
	 * @throws AssertionFailedError Rethrown immediately if write_entry() failed to throw (see the catch block below); never swallowed.
	 */
	public function test_mariadb_sequence_reporting_more_than_one_row_refused(): void {
		$adapter = new FakeDbAdapter();
		$adapter->set_table_storage_facts( 'pontifexstg_t', 'InnoDB', '', 'SEQUENCE' );
		$adapter->set_table_row_count( 'pontifexstg_t', 2 );
		$writer = new DatabaseWriter( $adapter );

		try {
			$writer->write_entry( self::db_chunk_result( 't', 1, "CREATE TABLE `pontifexstg_t` (`next_not_cached_value` bigint(21) NOT NULL) ENGINE=InnoDB SEQUENCE=1;\n" ) );
			self::fail( 'write_entry() should refuse a SEQUENCE reporting more than its own single state row.' );
		} catch ( AssertionFailedError $bug ) {
			// See test_failed_rename_leaves_staging_for_abort() above for why this
			// rethrow must run before the RuntimeException catch below.
			throw $bug;
		} catch ( RuntimeException $refusal ) {
			self::assertStringContainsString( 'produced rows', $refusal->getMessage() );
		}
	}

	/**
	 * A MariaDB SEQUENCE reporting EXACTLY its own single, self-seeded state row —
	 * confirmed empirically against a live MariaDB server (12.3.2): a bare
	 * `CREATE ... SEQUENCE=1` seeds that one row as an intrinsic part of the
	 * CREATE itself, before any INSERT runs — must be PERMITTED, not refused.
	 *
	 * @return void
	 */
	public function test_mariadb_sequence_reporting_exactly_one_row_permitted(): void {
		$adapter = new FakeDbAdapter();
		$adapter->set_table_storage_facts( 'pontifexstg_t', 'InnoDB', '', 'SEQUENCE' );
		$adapter->set_table_row_count( 'pontifexstg_t', 1 );
		$writer = new DatabaseWriter( $adapter );

		$writer->write_entry( self::db_chunk_result( 't', 1, "CREATE TABLE `pontifexstg_t` (`next_not_cached_value` bigint(21) NOT NULL) ENGINE=InnoDB SEQUENCE=1;\n" ) );

		$this->assertCount( 1, $adapter->executed_statements() );
	}

	/**
	 * A staged table whose row count could not be read at all must be refused.
	 *
	 * Cannot verify the table is empty, so must not proceed — the same
	 * fail-closed posture as every other check in this class.
	 *
	 * @return void
	 */
	public function test_row_count_that_could_not_be_read_refused(): void {
		$adapter = new FakeDbAdapter();
		$adapter->fail_next( 'table_row_count', 'simulated row-count read failure' );
		$writer = new DatabaseWriter( $adapter );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'simulated row-count read failure' );

		$writer->write_entry( self::db_chunk_result( 't', 1, "CREATE TABLE `t` (id INT);\n" ) );
	}

	/**
	 * Open a php://memory stream holding the given bytes.
	 *
	 * @param string $contents The bytes the stream should contain.
	 * @return resource A readable, seekable in-memory stream.
	 * @throws RuntimeException If php://memory cannot be opened.
	 */
	private static function memory_stream( string $contents ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://memory is an in-process buffer, not a file.
		$stream = fopen( 'php://memory', 'r+b' );
		if ( false === $stream ) {
			throw new RuntimeException( 'Could not open php://memory.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Operating on a test stream resource, not a filesystem path.
		fwrite( $stream, $contents );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource, not a filesystem path.
		rewind( $stream );
		return $stream;
	}
}
