<?php
/**
 * Integration test: the replay speaks the archive's charset, whatever the connection had.
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
 * Proves the replay-charset fix against a real MySQL server.
 *
 * The connection charset governs how the server interprets the bytes of every
 * statement. This test deliberately switches the connection to latin1 — the
 * mismatch that silently transcodes multibyte content to mojibake — then
 * replays a chunk holding emoji and CJK content through a writer told the
 * archive's charset is utf8mb4. The content must come back byte-identical,
 * and the connection must be handed back afterwards.
 */
final class ReplayCharsetTest extends TestCase {

	/**
	 * Scratch tables, dropped in set_up and tear_down.
	 *
	 * @var string[]
	 */
	private const SCRATCH_TABLES = array(
		'wp_pontifexcharset',
		'pontifexstg_wp_pontifexcharset',
		'pontifexold_wp_pontifexcharset',
	);

	/**
	 * Drop scratch tables before the test.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();
		$this->drop_scratch_tables();
	}

	/**
	 * Restore the connection charset and drop scratch tables.
	 *
	 * @return void
	 */
	protected function tear_down(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test cleanup: put the shared connection back to the site's charset whatever the test did.
		$wpdb->query( "SET NAMES 'utf8mb4'" );
		$this->drop_scratch_tables();
		parent::tear_down();
	}

	/**
	 * Multibyte content survives a replay over a mismatched connection.
	 *
	 * @return void
	 */
	public function test_multibyte_content_survives_a_mismatched_connection(): void {
		global $wpdb;

		$content = "café ☕ 絵文字 \u{1F600}";

		$sql = "DROP TABLE IF EXISTS `wp_pontifexcharset`;\n"
			. "CREATE TABLE `wp_pontifexcharset` (id INT NOT NULL PRIMARY KEY, val TEXT) DEFAULT CHARSET=utf8mb4;\n"
			. "INSERT INTO `wp_pontifexcharset` (`id`, `val`) VALUES (1, '" . $content . "');\n";

		// The mismatch under test: the connection is speaking latin1 when the
		// replay begins — without the fix, the utf8mb4 bytes above would be
		// transcoded on the way in and the content silently corrupted.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test fixture: the deliberate charset mismatch.
		$wpdb->query( "SET NAMES 'latin1'" );

		$writer = new DatabaseWriter( new WpdbAdapter( $wpdb ) );
		$writer->begin_staging( 'utf8mb4' );
		$header = EntryHeader::for_db_chunk( 0, 'wp_pontifexcharset', 3, strlen( $sql ), 0 );
		$writer->write_entry( new EntryReadResult( $header, $sql ) );
		$writer->commit_staged_tables();

		// The connection was handed back its own charset by the commit, so this
		// read interprets the stored bytes correctly.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test assertion: read back the multibyte row.
		$restored = $wpdb->get_var( 'SELECT val FROM `wp_pontifexcharset` WHERE id = 1' );
		$this->assertSame( $content, $restored, 'Multibyte content must survive a replay over a mismatched connection byte-identically.' );

		// And the connection is back on the site's own charset.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test assertion: confirm the hand-back.
		$client_charset = (string) $wpdb->get_var( 'SELECT @@session.character_set_client' );
		$this->assertStringStartsWith( 'utf8', $client_charset, 'The connection must be handed back its own charset after the replay.' );
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
}
