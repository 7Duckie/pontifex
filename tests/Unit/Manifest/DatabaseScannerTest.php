<?php
/**
 * Unit tests for the DatabaseScanner class.
 *
 * @package Pontifex\Tests\Unit\Manifest
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Manifest;

require_once __DIR__ . '/Fakes/FakeDbAdapter.php';

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Manifest\DatabaseScanner;
use Pontifex\Manifest\ExclusionRules;
use Pontifex\Manifest\ScannedDbChunk;
use Pontifex\Tests\Unit\Manifest\Fakes\FakeDbAdapter;

/**
 * Tests for {@see DatabaseScanner}.
 *
 * Uses an in-memory {@see FakeDbAdapter} (loaded from the Fakes
 * subdirectory) so the scanner can be tested without any WordPress
 * or wpdb mocking. The fake exposes the same DatabaseAdapter
 * interface as WpdbAdapter but returns canned data configured per
 * test.
 */
final class DatabaseScannerTest extends TestCase {

	/**
	 * Build a scanner with no exclusions and the default chunk size.
	 *
	 * @param FakeDbAdapter $db The adapter to scan against.
	 * @return DatabaseScanner A scanner ready to call scan() on.
	 */
	private static function unfiltered_scanner( FakeDbAdapter $db ): DatabaseScanner {
		return new DatabaseScanner( $db, ExclusionRules::none() );
	}

	/**
	 * Scanning an empty database must return an empty array.
	 *
	 * @return void
	 */
	public function test_scan_empty_database_returns_empty_array(): void {
		$db = new FakeDbAdapter();

		$chunks = self::unfiltered_scanner( $db )->scan();

		$this->assertSame( array(), $chunks );
	}

	/**
	 * A small table must produce a single ScannedDbChunk.
	 *
	 * @return void
	 */
	public function test_small_table_produces_single_chunk(): void {
		$db = new FakeDbAdapter();
		$db->add_table( 'wp_options', 10, "CREATE TABLE `wp_options` (...);\n" );

		$chunks = self::unfiltered_scanner( $db )->scan();

		$this->assertCount( 1, $chunks );
		$this->assertSame( 'wp_options', $chunks[0]->table_name() );
		$this->assertSame( 0, $chunks[0]->chunk_index() );
	}

	/**
	 * A large table must produce multiple ScannedDbChunks.
	 *
	 * @return void
	 */
	public function test_large_table_produces_multiple_chunks(): void {
		// With a small chunk_size_bytes and a large row count, the scanner must split into many chunks.
		$db = new FakeDbAdapter();
		$db->add_table( 'wp_postmeta', 50000, "CREATE TABLE `wp_postmeta` (...);\n" );

		// 4 KiB budget at 1 KiB-per-statement estimate = 4 rows per chunk.
		// 50000 rows / 4 = 12500 chunks. We don't check the exact number, just >1.
		$scanner = new DatabaseScanner( $db, ExclusionRules::none(), 4096 );
		$chunks  = $scanner->scan();

		$this->assertGreaterThan( 1, count( $chunks ) );
		foreach ( $chunks as $chunk ) {
			$this->assertSame( 'wp_postmeta', $chunk->table_name() );
		}
	}

	/**
	 * Multiple-chunk runs must have sequential chunk_index values starting at 0.
	 *
	 * @return void
	 */
	public function test_multiple_chunks_have_sequential_indices(): void {
		$db = new FakeDbAdapter();
		$db->add_table( 'big', 100, "CREATE TABLE `big` (...);\n" );

		// 10 rows per chunk → 10 chunks.
		$scanner = new DatabaseScanner( $db, ExclusionRules::none(), 10 * 1024 );
		$chunks  = $scanner->scan();

		$this->assertCount( 10, $chunks );
		for ( $i = 0; $i < 10; ++$i ) {
			$this->assertSame( $i, $chunks[ $i ]->chunk_index() );
		}
	}

	/**
	 * The first chunk of each table must include the schema in its SQL stream.
	 *
	 * @return void
	 */
	public function test_first_chunk_carries_schema(): void {
		$schema = "DROP TABLE IF EXISTS `t`;\nCREATE TABLE `t` (id INT);\n";
		$db     = new FakeDbAdapter();
		$db->add_table( 't', 5, $schema );

		$chunks = self::unfiltered_scanner( $db )->scan();
		$sql    = self::stream_contents( $chunks[0]->open_sql_stream() );

		$this->assertStringContainsString( 'CREATE TABLE `t`', $sql );
	}

	/**
	 * Chunks beyond the first must NOT include the schema.
	 *
	 * @return void
	 */
	public function test_later_chunks_do_not_carry_schema(): void {
		$schema = "CREATE TABLE `t` (id INT);\n";
		$db     = new FakeDbAdapter();
		$db->add_table( 't', 50, $schema );

		// 5 rows per chunk → 10 chunks; second chunk is index 1.
		$scanner = new DatabaseScanner( $db, ExclusionRules::none(), 5 * 1024 );
		$chunks  = $scanner->scan();
		$sql     = self::stream_contents( $chunks[1]->open_sql_stream() );

		$this->assertStringNotContainsString( 'CREATE TABLE', $sql );
	}

	/**
	 * An empty table must still produce one schema-only chunk so import recreates the table.
	 *
	 * @return void
	 */
	public function test_empty_table_produces_single_schema_chunk(): void {
		$schema = "CREATE TABLE `empty_table` (id INT);\n";
		$db     = new FakeDbAdapter();
		$db->add_table( 'empty_table', 0, $schema );

		$chunks = self::unfiltered_scanner( $db )->scan();

		$this->assertCount( 1, $chunks );
		$this->assertSame( 0, $chunks[0]->chunk_index() );
		$this->assertStringContainsString( 'CREATE TABLE `empty_table`', self::stream_contents( $chunks[0]->open_sql_stream() ) );
	}

	/**
	 * Excluded tables must be skipped entirely.
	 *
	 * @return void
	 */
	public function test_excluded_tables_are_skipped(): void {
		$db = new FakeDbAdapter();
		$db->add_table( 'wp_options', 10, "CREATE TABLE `wp_options` (...);\n" );
		$db->add_table( 'wp_postmeta', 10, "CREATE TABLE `wp_postmeta` (...);\n" );

		$rules  = new ExclusionRules( array( 'wp_postmeta' ) );
		$chunks = ( new DatabaseScanner( $db, $rules ) )->scan();
		$tables = array_map( static fn( ScannedDbChunk $c ): string => $c->table_name(), $chunks );

		$this->assertContains( 'wp_options', $tables );
		$this->assertNotContains( 'wp_postmeta', $tables );
	}

	/**
	 * Chunks must be returned in alphabetical order by table name, then chunk_index.
	 *
	 * @return void
	 */
	public function test_chunks_are_returned_in_deterministic_order(): void {
		$db = new FakeDbAdapter();
		// Add in non-alphabetical order to verify sorting.
		$db->add_table( 'zebra', 1, "CREATE TABLE `zebra` (...);\n" );
		$db->add_table( 'apple', 1, "CREATE TABLE `apple` (...);\n" );
		$db->add_table( 'mango', 1, "CREATE TABLE `mango` (...);\n" );

		$chunks = self::unfiltered_scanner( $db )->scan();
		$names  = array_map( static fn( ScannedDbChunk $c ): string => $c->table_name(), $chunks );

		$this->assertSame( array( 'apple', 'mango', 'zebra' ), $names );
	}

	/**
	 * The constructor must reject a non-positive chunk_size_bytes.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_zero_chunk_size(): void {
		$this->expectException( InvalidArgumentException::class );

		new DatabaseScanner( new FakeDbAdapter(), ExclusionRules::none(), 0 );
	}

	/**
	 * The constructor must reject a negative chunk_size_bytes.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_negative_chunk_size(): void {
		$this->expectException( InvalidArgumentException::class );

		new DatabaseScanner( new FakeDbAdapter(), ExclusionRules::none(), -1 );
	}

	/**
	 * The DEFAULT_CHUNK_SIZE_BYTES constant must equal 4 MiB.
	 *
	 * @return void
	 */
	public function test_default_chunk_size_constant(): void {
		$this->assertSame( 4 * 1024 * 1024, DatabaseScanner::DEFAULT_CHUNK_SIZE_BYTES );
	}

	/**
	 * Read a stream resource to completion and return its bytes.
	 *
	 * @param resource $stream The stream to read.
	 * @return string The full stream contents.
	 */
	private static function stream_contents( $stream ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_stream_get_contents -- Operating on a test stream resource.
		return (string) stream_get_contents( $stream );
	}
}
