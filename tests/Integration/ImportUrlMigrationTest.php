<?php
/**
 * Integration test: cross-URL migration via UrlMigrator over a real database.
 *
 * @package Pontifex\Tests\Integration
 */

declare(strict_types=1);

namespace Pontifex\Tests\Integration;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use Pontifex\Migrate\UrlMigrator;
use Pontifex\Migrate\WpdbMigrationDatabase;
use Pontifex\WordPress\RealWordPressContext;

// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Seeds serialised fixtures in a real database to prove the migration rewrites them safely.

/**
 * Proves cross-URL migration end to end against real WordPress and MySQL.
 *
 * Drives {@see UrlMigrator::migrate()} over a scratch table seeded with the
 * value shapes a migration meets — a plain URL, a serialised array, utf8mb4
 * content. The migration database is **scoped to the scratch table**, so the
 * live wp_options is never touched; the test asserts this by checking the real
 * siteurl option is unchanged. The pass is also shown to be idempotent.
 */
final class ImportUrlMigrationTest extends TestCase {

	/**
	 * Fully-prefixed name of the scratch table the test migrates.
	 *
	 * @var string
	 */
	private string $scratch_table = '';

	/**
	 * Create and seed the scratch table with old-URL content.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();
		global $wpdb;

		$this->scratch_table = $wpdb->prefix . 'pontifex_url_migration_test';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test: drop any leftover scratch table.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $this->scratch_table ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test: create the isolated scratch table.
		$wpdb->query( $wpdb->prepare( 'CREATE TABLE %i ( id INT PRIMARY KEY, body LONGTEXT ) DEFAULT CHARSET=utf8mb4', $this->scratch_table ) );

		$rows = array(
			1 => 'home: https://old-site.test',
			2 => serialize(
				array(
					'siteurl' => 'https://old-site.test',
					'home'    => 'https://old-site.test/blog',
				)
			),
			3 => 'café ☕ at https://old-site.test',
		);
		foreach ( $rows as $id => $body ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test: seed the scratch table.
			$wpdb->query( $wpdb->prepare( 'INSERT INTO %i ( id, body ) VALUES ( %d, %s )', $this->scratch_table, $id, $body ) );
		}
	}

	/**
	 * Drop the scratch table.
	 *
	 * @return void
	 */
	protected function tear_down(): void {
		global $wpdb;
		if ( '' !== $this->scratch_table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test cleanup: drop the scratch table.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $this->scratch_table ) );
		}
		parent::tear_down();
	}

	/**
	 * A scoped migration rewrites the scratch table and leaves the live site options untouched.
	 *
	 * @return void
	 */
	public function test_migrate_rewrites_the_scratch_table_and_leaves_site_options_untouched(): void {
		$siteurl_before = get_option( 'siteurl' );

		$report = $this->scoped_migrator()->migrate( 'https://old-site.test', 'https://new-site.example' );

		$this->assertSame( 3, $report->rows_changed() );

		$this->assertSame( 'home: https://new-site.example', $this->body( 1 ) );
		$this->assertSame(
			serialize(
				array(
					'siteurl' => 'https://new-site.example',
					'home'    => 'https://new-site.example/blog',
				)
			),
			$this->body( 2 ),
			'The serialised value must re-serialise with correct byte lengths.'
		);
		$this->assertSame( 'café ☕ at https://new-site.example', $this->body( 3 ), 'utf8mb4 content must survive the migration.' );

		$this->assertSame( $siteurl_before, get_option( 'siteurl' ), 'A scoped migration must not touch the live siteurl option.' );
	}

	/**
	 * A second migration over already-rewritten data changes nothing.
	 *
	 * @return void
	 */
	public function test_migrate_is_idempotent(): void {
		$migrator = $this->scoped_migrator();

		$migrator->migrate( 'https://old-site.test', 'https://new-site.example' );
		$second = $migrator->migrate( 'https://old-site.test', 'https://new-site.example' );

		$this->assertSame( 0, $second->rows_changed(), 'A second migration must change nothing.' );
	}

	/**
	 * Build a UrlMigrator scoped to the scratch table only.
	 *
	 * @return UrlMigrator
	 */
	private function scoped_migrator(): UrlMigrator {
		global $wpdb;
		return new UrlMigrator(
			new RealWordPressContext(),
			new WpdbMigrationDatabase( $wpdb, array( $this->scratch_table ) )
		);
	}

	/**
	 * Read one row's body straight from the database.
	 *
	 * @param int $id The row id.
	 * @return string The stored body.
	 */
	private function body( int $id ): string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Integration test: read a row back for assertion.
		return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT body FROM %i WHERE id = %d', $this->scratch_table, $id ) );
	}
}
