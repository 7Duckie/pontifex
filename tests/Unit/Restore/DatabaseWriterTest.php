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
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Reader\EntryReadResult;
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
		$this->assertSame( 'CREATE TABLE `wp_options` (id INT)', $executed[0] );
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
		$this->assertSame( 'CREATE TABLE `wp_posts` (id INT)', $executed[0] );
		$this->assertSame( 'INSERT INTO `wp_posts` VALUES (1)', $executed[1] );
		$this->assertSame( 'INSERT INTO `wp_posts` VALUES (2)', $executed[2] );
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
		$this->assertSame( 'CREATE TABLE `t` (id INT)', $executed[0] );
		$this->assertSame( 'INSERT INTO `t` VALUES (1)', $executed[1] );
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
		$this->assertSame( 'DROP TABLE IF EXISTS `xyz_posts`', $executed[0] );
		$this->assertSame( 'CREATE TABLE `xyz_posts` (`id` INT)', $executed[1] );
		$this->assertSame( 'INSERT INTO `xyz_posts` (`id`) VALUES (1)', $executed[2] );
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
			"INSERT INTO `xyz_options` (`option_name`, `option_value`) VALUES ('siteurl', 'wp_options')",
			$adapter->executed_statements()[0]
		);
	}

	/**
	 * A same-prefix writer must replay the SQL verbatim, with no identifier rewrite.
	 *
	 * @return void
	 */
	public function test_same_prefix_replays_verbatim(): void {
		$adapter = new FakeDbAdapter();
		$writer  = new DatabaseWriter( $adapter, 'wp_', 'wp_' );

		$writer->write_entry( self::db_chunk_result( 'wp_posts', 1, "INSERT INTO `wp_posts` VALUES (1);\n" ) );

		$this->assertSame( 'INSERT INTO `wp_posts` VALUES (1)', $adapter->executed_statements()[0] );
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

		$this->assertSame( array( array( 'wp_', 'xyz_' ) ), $adapter->rewrite_calls() );
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
}
