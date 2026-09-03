<?php
/**
 * In-memory DatabaseAdapter used by DatabaseScanner unit tests.
 *
 * @package Pontifex\Tests\Unit\Manifest\Fakes
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Manifest\Fakes;

use RuntimeException;
use Pontifex\Manifest\DatabaseAdapter;
use Pontifex\Manifest\RowDumpResult;

/**
 * In-memory implementation of {@see DatabaseAdapter} for tests.
 *
 * Lets DatabaseScanner be exercised without any WordPress / wpdb
 * mocking machinery. Tests register tables via add_table() and the
 * scanner queries them through the standard DatabaseAdapter
 * interface.
 */
final class FakeDbAdapter implements DatabaseAdapter {

	/**
	 * Canned table data: name => [row_count, schema].
	 *
	 * @var array<string, array{row_count: int, schema: string}>
	 */
	private array $tables = array();

	/**
	 * Register a fake table with row count and schema.
	 *
	 * @param string $name      Table name.
	 * @param int    $row_count Row count to return from row_count().
	 * @param string $schema    SQL to return from dump_table_schema().
	 * @return void
	 */
	public function add_table( string $name, int $row_count, string $schema ): void {
		$this->tables[ $name ] = array(
			'row_count' => $row_count,
			'schema'    => $schema,
		);
	}

	/**
	 * Return the registered table names in alphabetical order.
	 *
	 * @return string[]
	 */
	public function list_tables(): array {
		$this->maybe_fail( __FUNCTION__ );
		$names = array_keys( $this->tables );
		sort( $names, SORT_STRING );
		return $names;
	}

	/**
	 * Return the registered row count for the table.
	 *
	 * @param string $table_name Registered table name.
	 * @return int
	 */
	public function row_count( string $table_name ): int {
		$this->maybe_fail( __FUNCTION__ );
		return $this->tables[ $table_name ]['row_count'] ?? 0;
	}

	/**
	 * Return the registered schema string for the table.
	 *
	 * @param string $table_name Registered table name.
	 * @return string
	 */
	public function dump_table_schema( string $table_name ): string {
		$this->maybe_fail( __FUNCTION__ );
		return $this->tables[ $table_name ]['schema'] ?? '';
	}

	/**
	 * Table names for which dump_table_rows() reports a non-null end key.
	 *
	 * Registering a table here mirrors a real primary-key table: calls are
	 * recorded (see {@see self::dump_calls()}) and the result carries an end
	 * key, so a scanner test can verify the cursor chains between chunks and
	 * that the fail-closed guard fires when it does not. Unregistered tables
	 * behave exactly as before this field existed — end key always null,
	 * matching a real no-primary-key table — so every pre-existing test
	 * keeps passing unmodified; only a test that opts in via
	 * {@see self::make_keyed()} exercises keyset behaviour.
	 *
	 * @var array<string, true>
	 */
	private array $keyed_tables = array();

	/**
	 * Register a table as having a primary key, for dump_table_rows()'s end key.
	 *
	 * @param string $table_name The table name, as passed to add_table().
	 * @return void
	 */
	public function make_keyed( string $table_name ): void {
		$this->keyed_tables[ $table_name ] = true;
	}

	/**
	 * Every dump_table_rows() call, in order, as recorded arguments.
	 *
	 * @var array<int, array{table: string, offset: int, limit: int, after_key: array<string, int|string|float|bool>|null}>
	 */
	private array $dump_calls = array();

	/**
	 * Return the recorded dump_table_rows() calls, in order.
	 *
	 * @return array<int, array{table: string, offset: int, limit: int, after_key: array<string, int|string|float|bool>|null}>
	 */
	public function dump_calls(): array {
		return $this->dump_calls;
	}

	/**
	 * Return one batched multi-row INSERT for the requested range.
	 *
	 * Mirrors {@see \Pontifex\Manifest\WpdbAdapter::dump_table_rows()}, which
	 * packs every row of a chunk into a single INSERT INTO ... VALUES (...),
	 * (...), ...; statement — NOT one INSERT per row. Tests rely on this
	 * fidelity so the scanner's predicted statement_count is checked against
	 * the shape the real emitter produces. Row content is always synthesised
	 * from $offset/$limit (this fake has no real row data to filter by a
	 * key), so $after_key is recorded for assertions but does not change
	 * which rows are returned — only whether an end key is reported (see
	 * {@see self::make_keyed()}).
	 *
	 * @param string                                    $table_name Registered table name.
	 * @param int                                       $offset     Starting row offset.
	 * @param int                                       $limit      Maximum number of rows.
	 * @param array<string, int|string|float|bool>|null $after_key  Recorded for assertions; does not affect which rows are synthesised.
	 * @return RowDumpResult SQL bytes (empty when the range yields no rows), plus an end key for a table registered via make_keyed().
	 */
	public function dump_table_rows( string $table_name, int $offset, int $limit, ?array $after_key = null ): RowDumpResult {
		$this->maybe_fail( __FUNCTION__ );
		$this->dump_calls[] = array(
			'table'     => $table_name,
			'offset'    => $offset,
			'limit'     => $limit,
			'after_key' => $after_key,
		);

		$row_count = $this->row_count( $table_name );
		$end       = min( $offset + $limit, $row_count );
		if ( $offset >= $end ) {
			return new RowDumpResult( '', null );
		}
		$tuples = array();
		for ( $i = $offset; $i < $end; ++$i ) {
			$tuples[] = "({$i})";
		}
		$sql = "INSERT INTO `{$table_name}` VALUES " . implode( ', ', $tuples ) . ";\n";

		$end_key = isset( $this->keyed_tables[ $table_name ] ) ? array( 'id' => $end - 1 ) : null;

		return new RowDumpResult( $sql, $end_key );
	}

	/**
	 * Statements passed to execute_sql, in order.
	 *
	 * Tests inspect this array to verify which SQL the writer ran.
	 *
	 * @var string[]
	 */
	private array $executed_statements = array();

	/**
	 * If non-null, the next execute_sql call throws this message.
	 *
	 * Set via {@see FakeDbAdapter::fail_next_execute()} to simulate
	 * a database error in tests.
	 *
	 * @var string|null
	 */
	private ?string $next_failure = null;

	/**
	 * Configure the next execute_sql call to throw a RuntimeException.
	 *
	 * @param string $message The error message the simulated failure carries.
	 * @return void
	 */
	public function fail_next_execute( string $message ): void {
		$this->next_failure = $message;
	}

	/**
	 * Number of successful execute_sql calls after which one call throws, or -1 for never.
	 *
	 * @var int
	 */
	private int $fail_after = -1;

	/**
	 * The error message the deferred failure carries.
	 *
	 * @var string
	 */
	private string $fail_after_message = '';

	/**
	 * Configure execute_sql to throw once the given number of calls have succeeded.
	 *
	 * Lets a test place a failure mid-replay: the first $successes statements
	 * record normally, the next call throws, and calls after that succeed again
	 * (so cleanup statements are still observable).
	 *
	 * @param int    $successes How many calls succeed before the failure fires.
	 * @param string $message   The error message the simulated failure carries.
	 * @return void
	 */
	public function fail_after_executes( int $successes, string $message ): void {
		$this->fail_after         = $successes;
		$this->fail_after_message = $message;
	}

	/**
	 * Return the SQL statements passed to execute_sql, in order.
	 *
	 * @return string[] The recorded statements.
	 */
	public function executed_statements(): array {
		return $this->executed_statements;
	}

	/**
	 * Record the SQL statement, or simulate a configured failure.
	 *
	 * @param string $sql The SQL to execute.
	 * @throws RuntimeException If $sql is empty or fail_next_execute() was called.
	 */
	public function execute_sql( string $sql ): void {
		if ( '' === $sql ) {
			throw new RuntimeException( 'FakeDbAdapter::execute_sql: sql must not be empty.' );
		}
		if ( null !== $this->next_failure ) {
			$message            = $this->next_failure;
			$this->next_failure = null;
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $message is test-controlled simulated-failure text; exception path, not HTML output.
			throw new RuntimeException( $message );
		}
		if ( $this->fail_after >= 0 && count( $this->executed_statements ) >= $this->fail_after ) {
			$this->fail_after = -1;
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $fail_after_message is test-controlled simulated-failure text; exception path, not HTML output.
			throw new RuntimeException( $this->fail_after_message );
		}
		$this->executed_statements[] = $sql;
	}

	/**
	 * Prefix-key rewrite calls, in order, as [source_prefix, dest_prefix, staging_prefix] triples.
	 *
	 * @var array<int, array{0: string, 1: string, 2: string}>
	 */
	private array $rewrite_calls = array();

	/**
	 * Record a prefix-key rewrite call so a test can assert it happened.
	 *
	 * @param string $source_prefix  The prefix recorded in the archive.
	 * @param string $dest_prefix    The destination site's prefix.
	 * @param string $staging_prefix Physical prefix on the tables being rewritten, or ''.
	 * @return void
	 */
	public function rewrite_prefix_keys( string $source_prefix, string $dest_prefix, string $staging_prefix = '' ): void {
		$this->rewrite_calls[] = array( $source_prefix, $dest_prefix, $staging_prefix );
	}

	/**
	 * Return the recorded prefix-key rewrite calls, in order.
	 *
	 * @return array<int, array{0: string, 1: string, 2: string}> Each entry is [source_prefix, dest_prefix, staging_prefix].
	 */
	public function rewrite_calls(): array {
		return $this->rewrite_calls;
	}

	/**
	 * Table names table_exists() reports as present.
	 *
	 * Registered tables (add_table) count as existing too, so scanner-focused
	 * tests keep working; writer-focused tests can mark extra live tables here.
	 *
	 * @var array<string, true>
	 */
	private array $existing_tables = array();

	/**
	 * Mark a table as existing for table_exists(), without registering scan data.
	 *
	 * @param string $name The table name to report as present.
	 * @return void
	 */
	public function mark_table_existing( string $name ): void {
		$this->existing_tables[ $name ] = true;
	}

	/**
	 * Whether the table was registered via add_table() or mark_table_existing().
	 *
	 * @param string $table_name The exact table name to look for.
	 * @return bool True when the table is known to the fake.
	 */
	public function table_exists( string $table_name ): bool {
		return isset( $this->tables[ $table_name ] ) || isset( $this->existing_tables[ $table_name ] );
	}

	/**
	 * Canned average row widths, keyed by table name.
	 *
	 * @var array<string, int>
	 */
	private array $average_row_bytes = array();

	/**
	 * Register a canned average row width for a table.
	 *
	 * @param string $name  Table name.
	 * @param int    $bytes Average bytes per row to report.
	 * @return void
	 */
	public function set_average_row_bytes( string $name, int $bytes ): void {
		$this->average_row_bytes[ $name ] = $bytes;
	}

	/**
	 * Return the canned average row width, or 0 when none was registered.
	 *
	 * Mirrors WpdbAdapter's unknown-answer contract: 0 tells the scanner to
	 * fall back to its fixed estimate.
	 *
	 * @param string $table_name Table name.
	 * @return int Average bytes per row; 0 when unknown.
	 */
	public function average_row_bytes( string $table_name ): int {
		$this->maybe_fail( __FUNCTION__ );
		return $this->average_row_bytes[ $table_name ] ?? 0;
	}

	/**
	 * Charset calls, in order: the charset for set, or the literal 'RESTORE' for restore.
	 *
	 * @var string[]
	 */
	private array $charset_calls = array();

	/**
	 * Record a session-charset switch so a test can assert it happened.
	 *
	 * @param string $charset The archive's character set.
	 * @return void
	 */
	public function set_session_charset( string $charset ): void {
		$this->charset_calls[] = $charset;
	}

	/**
	 * Record the hand-back of the connection's own charset.
	 *
	 * @return void
	 */
	public function restore_session_charset(): void {
		$this->charset_calls[] = 'RESTORE';
	}

	/**
	 * Return the recorded charset calls, in order.
	 *
	 * @return string[] Charsets passed to set_session_charset, with 'RESTORE' marking each restore call.
	 */
	public function charset_calls(): array {
		return $this->charset_calls;
	}

	/**
	 * Failure messages queued per method name; consumed on the next call.
	 *
	 * @var array<string, string>
	 */
	private array $queued_failures = array();

	/**
	 * Queue the next call to the named method to throw a RuntimeException.
	 *
	 * Mirrors the real adapter's contract — every read throws on a $wpdb
	 * failure — so orchestration can be unit-tested against a failing
	 * database without WordPress mocking.
	 *
	 * @param string $method  The method name, e.g. "row_count".
	 * @param string $message The error message the simulated failure carries.
	 * @return void
	 */
	public function fail_next( string $method, string $message ): void {
		$this->queued_failures[ $method ] = $message;
	}

	/**
	 * Throw the queued failure for the method, if one is armed.
	 *
	 * @param string $method The method name being invoked.
	 * @return void
	 * @throws RuntimeException When a failure was queued for the method.
	 */
	private function maybe_fail( string $method ): void {
		if ( isset( $this->queued_failures[ $method ] ) ) {
			$message = $this->queued_failures[ $method ];
			unset( $this->queued_failures[ $method ] );
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $message is test-controlled simulated-failure text; exception path, not HTML output.
			throw new RuntimeException( $message );
		}
	}

	/**
	 * Canned storage facts, keyed by table name; overrides the default answer.
	 *
	 * A registered null means "report the table as not found"; an array
	 * means "report exactly these facts". A table name with no entry here
	 * falls back to the ordinary-InnoDB-base-table default in
	 * {@see self::table_storage_facts()}.
	 *
	 * @var array<string, array{engine: string, create_options: string, table_type: string}|null>
	 */
	private array $storage_facts = array();

	/**
	 * Register the storage facts table_storage_facts() must report for a table name.
	 *
	 * @param string $name           Table name, as passed to table_storage_facts().
	 * @param string $engine         The reported ENGINE value.
	 * @param string $create_options The reported CREATE_OPTIONS value.
	 * @param string $table_type     Optional. The reported TABLE_TYPE value; default 'BASE TABLE'.
	 * @return void
	 */
	public function set_table_storage_facts( string $name, string $engine, string $create_options, string $table_type = 'BASE TABLE' ): void {
		$this->storage_facts[ $name ] = array(
			'engine'         => $engine,
			'create_options' => $create_options,
			'table_type'     => $table_type,
		);
	}

	/**
	 * Register that table_storage_facts() must report the table as not found.
	 *
	 * @param string $name Table name.
	 * @return void
	 */
	public function deny_table_storage_facts( string $name ): void {
		$this->storage_facts[ $name ] = null;
	}

	/**
	 * Report canned storage facts for a table.
	 *
	 * Defaults to an ordinary InnoDB base table for any name not registered
	 * via {@see self::set_table_storage_facts()} or
	 * {@see self::deny_table_storage_facts()}, so every existing DatabaseWriter
	 * test — none of which cares about storage-engine containment — keeps
	 * passing unmodified; only a test that opts in exercises a refusal.
	 *
	 * @param string $table_name The table name to look up.
	 * @return array{engine: string, create_options: string, table_type: string}|null
	 */
	public function table_storage_facts( string $table_name ): ?array {
		$this->maybe_fail( __FUNCTION__ );
		if ( array_key_exists( $table_name, $this->storage_facts ) ) {
			return $this->storage_facts[ $table_name ];
		}
		return array(
			'engine'         => 'InnoDB',
			'create_options' => '',
			'table_type'     => 'BASE TABLE',
		);
	}

	/**
	 * Canned row-count answers, keyed by table name; overrides the default of 0.
	 *
	 * @var array<string, int>
	 */
	private array $row_counts = array();

	/**
	 * Register the row count table_row_count() must report for a table name.
	 *
	 * @param string $name  Table name, as passed to table_row_count().
	 * @param int    $count The row count to report.
	 * @return void
	 */
	public function set_table_row_count( string $name, int $count ): void {
		$this->row_counts[ $name ] = $count;
	}

	/**
	 * Report the canned row count for a table, defaulting to 0 — an ordinary
	 * CREATE builds an empty table — so every existing DatabaseWriter test
	 * keeps passing unmodified; only a test that opts in exercises a refusal.
	 *
	 * @param string $table_name The table name to look up.
	 * @return int
	 */
	public function table_row_count( string $table_name ): int {
		$this->maybe_fail( __FUNCTION__ );
		return $this->row_counts[ $table_name ] ?? 0;
	}

	/**
	 * Canned partition storage-directory answers, keyed by table name.
	 *
	 * A table name with no entry here defaults to false — no partition names a
	 * DATA DIRECTORY or INDEX DIRECTORY — so every existing DatabaseWriter test
	 * keeps passing unmodified; only a test that opts in exercises a refusal.
	 *
	 * @var array<string, bool>
	 */
	private array $partition_storage_directory = array();

	/**
	 * Register the answer partition_storage_directory_present() must report for a table name.
	 *
	 * @param string $name    Table name, as passed to partition_storage_directory_present().
	 * @param bool   $present Whether a partition should be reported as naming a storage directory.
	 * @return void
	 */
	public function set_partition_storage_directory_present( string $name, bool $present ): void {
		$this->partition_storage_directory[ $name ] = $present;
	}

	/**
	 * Report the canned partition storage-directory answer for a table, defaulting to false.
	 *
	 * @param string $table_name The table name to look up.
	 * @return bool
	 */
	public function partition_storage_directory_present( string $table_name ): bool {
		$this->maybe_fail( __FUNCTION__ );
		return $this->partition_storage_directory[ $table_name ] ?? false;
	}

	/**
	 * The canned SESSION sql_mode; null denies it (reports "could not be read").
	 *
	 * Defaults to '' — the ordinary MySQL/MariaDB default, no special modes set
	 * — so every existing test keeps behaving as before backslash-escape
	 * handling became sql_mode-aware; only a test that opts in exercises
	 * NO_BACKSLASH_ESCAPES or an unreadable sql_mode.
	 *
	 * @var string|null
	 */
	private ?string $sql_mode = '';

	/**
	 * Register the SESSION sql_mode sql_mode() must report.
	 *
	 * @param string $mode The sql_mode string to report, e.g. "NO_BACKSLASH_ESCAPES".
	 * @return void
	 */
	public function set_sql_mode( string $mode ): void {
		$this->sql_mode = $mode;
	}

	/**
	 * Register that sql_mode() must report the mode as unreadable (null).
	 *
	 * @return void
	 */
	public function deny_sql_mode(): void {
		$this->sql_mode = null;
	}

	/**
	 * Report the canned SESSION sql_mode.
	 *
	 * @return string|null
	 */
	public function sql_mode(): ?string {
		return $this->sql_mode;
	}

	/**
	 * List known tables (registered or marked existing) beginning with the prefix.
	 *
	 * @param string $prefix The literal name prefix to match; must not be empty.
	 * @return string[] Matching table names in alphabetical order.
	 * @throws RuntimeException If $prefix is empty, mirroring WpdbAdapter.
	 */
	public function list_tables_by_prefix( string $prefix ): array {
		if ( '' === $prefix ) {
			throw new RuntimeException( 'FakeDbAdapter::list_tables_by_prefix: prefix must not be empty.' );
		}
		$names = array();
		foreach ( array_merge( array_keys( $this->tables ), array_keys( $this->existing_tables ) ) as $name ) {
			if ( str_starts_with( $name, $prefix ) ) {
				$names[ $name ] = true;
			}
		}
		$names = array_keys( $names );
		sort( $names, SORT_STRING );
		return $names;
	}

	/**
	 * The canned WordPress table prefix; defaults to '' — the value
	 * DatabaseWriter's cross-site guard treats as "no scope to confine to"
	 * and therefore skips, mirroring WpdbAdapter's identical skip for an
	 * unconfigured $wpdb. Kept empty by default so every pre-existing
	 * DatabaseWriter test — the great majority of which register table names
	 * such as "t" or "wp_posts" without regard to any prefix — keeps passing
	 * unmodified; only a test that opts in via {@see self::set_table_prefix()}
	 * exercises the guard.
	 *
	 * @var string
	 */
	private string $table_prefix = '';

	/**
	 * Register the WordPress table prefix table_prefix() must report.
	 *
	 * @param string $prefix The prefix to report.
	 * @return void
	 */
	public function set_table_prefix( string $prefix ): void {
		$this->table_prefix = $prefix;
	}

	/**
	 * Report the canned WordPress table prefix, defaulting to ''.
	 *
	 * @return string
	 */
	public function table_prefix(): string {
		return $this->table_prefix;
	}
}
