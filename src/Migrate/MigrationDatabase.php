<?php
/**
 * Pontifex migration database seam — the row read/update surface the rewrite pass needs.
 *
 * @package Pontifex\Migrate
 */

declare(strict_types=1);

namespace Pontifex\Migrate;

use RuntimeException;

/**
 * The live-database operations the cross-URL rewrite pass needs.
 *
 * Where {@see \Pontifex\Manifest\DatabaseAdapter} reads the database as
 * SQL bytes for archival, this seam reads it as *structured rows* and
 * writes individual rows back — the surface a `wp search-replace`-style
 * pass requires. It is deliberately a separate interface: the rewrite
 * pass has no use for schema dumps or statement replay, and the scanner
 * has no use for row updates, so neither carries the other's methods.
 *
 * The interface exists so {@see DatabaseRewriter} can be unit-tested
 * without any WordPress / wpdb mocking: tests inject an in-memory fake,
 * production injects {@see WpdbMigrationDatabase}. All knowledge of
 * WordPress's `$wpdb` is concentrated in that one adapter.
 *
 * Failure contract: the real `$wpdb` returns `false` (it does not throw)
 * on a failed query — exactly the trap a backup/restore tool must not
 * fall into, because a silently dropped result becomes a silently
 * skipped or mis-written row. Every method here therefore throws a
 * {@see RuntimeException} on a query error rather than returning a falsy
 * value, so a failure stops the pass loudly instead of corrupting the
 * migration.
 */
interface MigrationDatabase {

	/**
	 * List the tables the rewrite pass should walk.
	 *
	 * Production returns every WordPress-prefixed table (the
	 * `wp search-replace` default), so a URL living in a custom plugin's
	 * table is rewritten too, not silently missed.
	 *
	 * @return string[] Fully-prefixed table names.
	 * @throws RuntimeException If the table list cannot be retrieved.
	 */
	public function list_tables(): array;

	/**
	 * The single-column primary key of a table, or null when there is none usable.
	 *
	 * The pass keys every UPDATE on the primary key so it rewrites
	 * exactly one row at a time. A table with no primary key, or a
	 * composite (multi-column) one, returns null and the caller skips it
	 * rather than risk an UPDATE that matches more rows than intended.
	 * Every WordPress core table has a single-column key.
	 *
	 * @param string $table Fully-prefixed table name.
	 * @return string|null The primary-key column name, or null if absent or composite.
	 * @throws RuntimeException If the table's keys cannot be inspected.
	 */
	public function primary_key( string $table ): ?string;

	/**
	 * Read a batch of rows from a table as associative arrays.
	 *
	 * Returns the rows in the range [$offset, $offset + $limit) as
	 * column => value maps, including the primary-key column. Column
	 * values are returned as WordPress's `$wpdb` returns them: strings,
	 * or null for SQL NULL. An empty array means the range yielded no
	 * rows — the caller's signal that the table is exhausted.
	 *
	 * @param string $table  Fully-prefixed table name.
	 * @param int    $offset 0-based starting row; must be non-negative.
	 * @param int    $limit  Maximum rows to read; must be positive.
	 * @return array<int, array<string, mixed>> The rows, each a column => value map.
	 * @throws RuntimeException If the rows cannot be read.
	 */
	public function read_rows( string $table, int $offset, int $limit ): array;

	/**
	 * Update the given columns of one row, matched by its primary key.
	 *
	 * Writes only the changed columns, with a WHERE on the primary key
	 * so exactly one row is affected. The values come from the rewrite
	 * pass and are stored verbatim, so the serialised byte lengths the
	 * replacer already recomputed are preserved.
	 *
	 * @param string                $table             Fully-prefixed table name.
	 * @param string                $primary_key       Primary-key column name.
	 * @param int|string            $primary_key_value The row's primary-key value.
	 * @param array<string, string> $columns           Changed columns as column => new value; must be non-empty.
	 * @return void
	 * @throws RuntimeException If the update fails (including the `$wpdb`-returns-false path).
	 */
	public function update_row( string $table, string $primary_key, int|string $primary_key_value, array $columns ): void;

	/**
	 * The table's average stored row width, in bytes, or 0 when unknown.
	 *
	 * Used by {@see DatabaseRewriter} to size its row batches per table, so a
	 * wide-row table is read a handful of rows at a time instead of a fixed
	 * thousand of them — bounding the pass's memory the same way the export's
	 * chunker bounds its own. A sizing hint, never a correctness input:
	 * implementations report the storage engine's own estimate and return 0
	 * when it cannot be read, in which case the rewriter falls back to its
	 * fixed estimate.
	 *
	 * @param string $table Fully-prefixed table name.
	 * @return int Average bytes per row; 0 when unknown.
	 */
	public function average_row_bytes( string $table ): int;
}
