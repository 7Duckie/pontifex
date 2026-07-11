<?php
/**
 * A DatabaseAdapter decorator that fails on the Nth executed statement.
 *
 * @package Pontifex\Tests\Integration\Fakes
 */

declare(strict_types=1);

namespace Pontifex\Tests\Integration\Fakes;

use RuntimeException;
use Pontifex\Manifest\DatabaseAdapter;

/**
 * Delegates every call to a real adapter, but throws on one chosen execute.
 *
 * Lets an integration test place a deterministic failure mid-replay against a
 * real database: every statement up to the chosen ordinal executes for real,
 * the chosen one throws before reaching the database, and later statements
 * (a cleanup path's DROPs) execute for real again.
 */
final class FailingDatabaseAdapter implements DatabaseAdapter {

	/**
	 * The real adapter every call is delegated to.
	 *
	 * @var DatabaseAdapter
	 */
	private DatabaseAdapter $inner;

	/**
	 * The 1-based execute_sql() ordinal that throws instead of executing.
	 *
	 * @var int
	 */
	private int $fail_at;

	/**
	 * How many execute_sql() calls have been made so far.
	 *
	 * @var int
	 */
	private int $calls = 0;

	/**
	 * Construct the decorator.
	 *
	 * @param DatabaseAdapter $inner   The real adapter to delegate to.
	 * @param int             $fail_at The 1-based execute_sql() call that must fail.
	 */
	public function __construct( DatabaseAdapter $inner, int $fail_at ) {
		$this->inner   = $inner;
		$this->fail_at = $fail_at;
	}

	/**
	 * Delegate to the real adapter.
	 *
	 * @return string[] Table names.
	 */
	public function list_tables(): array {
		return $this->inner->list_tables();
	}

	/**
	 * Delegate to the real adapter.
	 *
	 * @param string $table_name Fully prefixed table name.
	 * @return int Row count.
	 */
	public function row_count( string $table_name ): int {
		return $this->inner->row_count( $table_name );
	}

	/**
	 * Delegate to the real adapter.
	 *
	 * @param string $table_name Fully prefixed table name.
	 * @return string Schema SQL.
	 */
	public function dump_table_schema( string $table_name ): string {
		return $this->inner->dump_table_schema( $table_name );
	}

	/**
	 * Delegate to the real adapter.
	 *
	 * @param string $table_name Fully prefixed table name.
	 * @param int    $offset     0-based starting row.
	 * @param int    $limit      Maximum number of rows.
	 * @return string Row SQL.
	 */
	public function dump_table_rows( string $table_name, int $offset, int $limit ): string {
		return $this->inner->dump_table_rows( $table_name, $offset, $limit );
	}

	/**
	 * Execute the statement for real, unless this is the chosen failing call.
	 *
	 * @param string $sql The SQL statement to execute.
	 * @return void
	 * @throws RuntimeException On the configured call ordinal, before the statement reaches the database.
	 */
	public function execute_sql( string $sql ): void {
		++$this->calls;
		if ( $this->calls === $this->fail_at ) {
			throw new RuntimeException( 'FailingDatabaseAdapter: simulated mid-replay failure.' );
		}
		$this->inner->execute_sql( $sql );
	}

	/**
	 * Delegate to the real adapter.
	 *
	 * @param string $source_prefix  The prefix recorded in the archive.
	 * @param string $dest_prefix    The destination site's prefix.
	 * @param string $staging_prefix Physical prefix on the tables being rewritten, or ''.
	 * @return void
	 */
	public function rewrite_prefix_keys( string $source_prefix, string $dest_prefix, string $staging_prefix = '' ): void {
		$this->inner->rewrite_prefix_keys( $source_prefix, $dest_prefix, $staging_prefix );
	}

	/**
	 * Delegate to the real adapter.
	 *
	 * @param string $table_name The exact table name to look for.
	 * @return bool True when the table exists.
	 */
	public function table_exists( string $table_name ): bool {
		return $this->inner->table_exists( $table_name );
	}

	/**
	 * Delegate to the real adapter.
	 *
	 * @param string $prefix The literal name prefix to match.
	 * @return string[] Matching table names.
	 */
	public function list_tables_by_prefix( string $prefix ): array {
		return $this->inner->list_tables_by_prefix( $prefix );
	}
}
