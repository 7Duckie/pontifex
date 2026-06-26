<?php
/**
 * Integration test: the cross-prefix table-prefix rewrite over a real database.
 *
 * @package Pontifex\Tests\Integration
 */

declare(strict_types=1);

namespace Pontifex\Tests\Integration;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Reader\EntryReadResult;
use Pontifex\Manifest\WpdbAdapter;
use Pontifex\Restore\DatabaseWriter;

/**
 * Proves the prefix rewrite works against a real MySQL database, not just fakes.
 *
 * Replays two source-prefixed db_chunks (an options table and a usermeta table)
 * through a {@see DatabaseWriter} configured to rewrite from `srcpfx_` to `dstpfx_`,
 * then finalises. It asserts the tables were created under the destination prefix
 * and that the prefix embedded in the key columns was rewritten column-aware: the
 * single `{prefix}user_roles` option, and every usermeta `meta_key` that begins with
 * the source prefix — while a `meta_key` that only looks similar (no literal
 * underscore after the prefix, so excluded by `esc_like`) and an unrelated key are
 * both left untouched. All scratch tables are the test's own and dropped in tear_down.
 */
final class PrefixRewriteTest extends TestCase {

	/**
	 * The scratch tables this test creates, so set_up and tear_down can drop them.
	 *
	 * @var string[]
	 */
	private const SCRATCH_TABLES = array(
		'srcpfx_options',
		'srcpfx_usermeta',
		'dstpfx_options',
		'dstpfx_usermeta',
	);

	/**
	 * Drop any leftover scratch tables before the test.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();
		$this->drop_scratch_tables();
	}

	/**
	 * Drop the scratch tables after the test.
	 *
	 * @return void
	 */
	protected function tear_down(): void {
		$this->drop_scratch_tables();
		parent::tear_down();
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
	 * A cross-prefix restore renames the tables and rewrites the key columns.
	 *
	 * @return void
	 */
	public function test_cross_prefix_restore_rewrites_tables_and_keys(): void {
		global $wpdb;

		$options_sql = "DROP TABLE IF EXISTS `srcpfx_options`;\n"
			. "CREATE TABLE `srcpfx_options` (option_id BIGINT NOT NULL PRIMARY KEY, option_name VARCHAR(191) NOT NULL, option_value LONGTEXT NOT NULL, autoload VARCHAR(20) NOT NULL) DEFAULT CHARSET=utf8mb4;\n"
			. "INSERT INTO `srcpfx_options` (`option_id`, `option_name`, `option_value`, `autoload`) VALUES (1, 'srcpfx_user_roles', 'a:0:{}', 'yes'), (2, 'siteurl', 'https://x.test', 'yes');\n";

		$usermeta_sql = "DROP TABLE IF EXISTS `srcpfx_usermeta`;\n"
			. "CREATE TABLE `srcpfx_usermeta` (umeta_id BIGINT NOT NULL PRIMARY KEY, user_id BIGINT NOT NULL, meta_key VARCHAR(255), meta_value LONGTEXT) DEFAULT CHARSET=utf8mb4;\n"
			. "INSERT INTO `srcpfx_usermeta` (`umeta_id`, `user_id`, `meta_key`, `meta_value`) VALUES (1, 1, 'srcpfx_capabilities', 'a:0:{}'), (2, 1, 'srcpfx_user_level', '10'), (3, 1, 'unrelated_key', 'keep'), (4, 1, 'srcpfxZcollide', 'keep');\n";

		$writer = new DatabaseWriter( new WpdbAdapter( $wpdb ), 'srcpfx_', 'dstpfx_' );
		$writer->write_entry( self::db_chunk( 'srcpfx_options', 3, $options_sql ) );
		$writer->write_entry( self::db_chunk( 'srcpfx_usermeta', 3, $usermeta_sql ) );
		$writer->finalise_prefix_rewrite();

		// The tables were created under the destination prefix, not the source one.
		$this->assertTrue( $this->table_exists( 'dstpfx_options' ), 'The options table should exist under the destination prefix.' );
		$this->assertTrue( $this->table_exists( 'dstpfx_usermeta' ), 'The usermeta table should exist under the destination prefix.' );
		$this->assertFalse( $this->table_exists( 'srcpfx_options' ), 'No table should be left under the source prefix.' );

		// options.option_name: the anchored user_roles key was rewritten; siteurl was not.
		$this->assertSame( 1, $this->option_name_count( 'dstpfx_user_roles' ), 'user_roles must carry the destination prefix.' );
		$this->assertSame( 0, $this->option_name_count( 'srcpfx_user_roles' ), 'No option_name should keep the source prefix.' );
		$this->assertSame( 1, $this->option_name_count( 'siteurl' ), 'An unprefixed option must be untouched.' );

		// usermeta.meta_key: prefix-keyed rows rewritten; an unrelated key and a
		// lookalike (no literal underscore after the prefix) left untouched.
		$this->assertSame( 1, $this->meta_key_count( 'dstpfx_capabilities' ) );
		$this->assertSame( 1, $this->meta_key_count( 'dstpfx_user_level' ) );
		$this->assertSame( 0, $this->meta_key_count( 'srcpfx_capabilities' ), 'No meta_key should keep the source prefix.' );
		$this->assertSame( 1, $this->meta_key_count( 'unrelated_key' ), 'An unrelated meta_key must be untouched.' );
		$this->assertSame( 1, $this->meta_key_count( 'srcpfxZcollide' ), 'A lookalike key (no literal underscore) must be untouched — esc_like keeps the prefix underscore literal.' );
	}

	/**
	 * Build an EntryReadResult for a db_chunk entry.
	 *
	 * @param string $table_name      The source table the chunk belongs to.
	 * @param int    $statement_count The declared statement count.
	 * @param string $sql             The SQL payload.
	 * @return EntryReadResult
	 */
	private static function db_chunk( string $table_name, int $statement_count, string $sql ): EntryReadResult {
		$header = EntryHeader::for_db_chunk( 0, $table_name, $statement_count, strlen( $sql ), 0 );
		return new EntryReadResult( $header, $sql );
	}

	/**
	 * Whether the given table exists in the test database.
	 *
	 * @param string $table_name The table to check.
	 * @return bool
	 */
	private function table_exists( string $table_name ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test: existence check on a scratch table.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) ) );
		return null !== $found;
	}

	/**
	 * Count rows in dstpfx_options whose option_name equals the given value.
	 *
	 * @param string $option_name The option_name to count.
	 * @return int
	 */
	private function option_name_count( string $option_name ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test: read back a rewritten key for assertion.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM `dstpfx_options` WHERE option_name = %s', $option_name ) );
	}

	/**
	 * Count rows in dstpfx_usermeta whose meta_key equals the given value.
	 *
	 * @param string $meta_key The meta_key to count.
	 * @return int
	 */
	private function meta_key_count( string $meta_key ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test: read back a rewritten key for assertion.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM `dstpfx_usermeta` WHERE meta_key = %s', $meta_key ) );
	}
}
