<?php
/**
 * Adversarial tests for DatabaseRewriter — the live-database search-replace pass.
 *
 * @package Pontifex\Tests\Unit\Migrate
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Migrate;

use InvalidArgumentException;
use RuntimeException;
use Pontifex\Migrate\DatabaseRewriter;
use Pontifex\Migrate\SerialisedReplacer;
use Pontifex\Tests\TestCase;
use Pontifex\Tests\Unit\Migrate\Fakes\FakeMigrationDatabase;

// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize,WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- This suite builds and verifies serialised fixtures to prove the pass rewrites them safely.

/**
 * Proves the pass rewrites real-shaped rows safely and honours every defence.
 *
 * The replacer's own gadget/round-trip defences are covered in
 * {@see SerialisedReplacerTest}; these tests prove the *walk* wires them
 * correctly — only changed rows are updated, the primary key is never
 * rewritten and is used verbatim in the WHERE, a blocked-object value is
 * left alone and surfaced as skipped, a failed update is not swallowed,
 * and a second pass is a no-op.
 */
final class DatabaseRewriterTest extends TestCase {

	/**
	 * A plain URL in a non-key column is rewritten and the row is updated by its key.
	 *
	 * @return void
	 */
	public function test_rewrites_a_plain_url_and_updates_the_row(): void {
		$db = new FakeMigrationDatabase();
		$db->add_table(
			'wp_options',
			'option_id',
			array(
				array(
					'option_id'    => 1,
					'option_name'  => 'home',
					'option_value' => 'https://old.test',
				),
			)
		);

		$report = ( new DatabaseRewriter( $db, new SerialisedReplacer() ) )->rewrite( 'old.test', 'new.example' );

		$updates = $db->updates();
		$this->assertCount( 1, $updates );
		$this->assertSame( 'wp_options', $updates[0]['table'] );
		$this->assertSame( 'option_id', $updates[0]['primary_key'] );
		$this->assertSame( 1, $updates[0]['primary_key_value'] );
		$this->assertSame( array( 'option_value' => 'https://new.example' ), $updates[0]['columns'] );

		$this->assertSame( 1, $report->rows_changed() );
		$this->assertSame( 1, $report->values_changed() );
		$this->assertSame( 0, $report->skipped_values() );
	}

	/**
	 * A scan previews the change but writes nothing.
	 *
	 * @return void
	 */
	public function test_scan_previews_without_writing(): void {
		$db = new FakeMigrationDatabase();
		$db->add_table(
			'wp_options',
			'option_id',
			array(
				array(
					'option_id'    => 1,
					'option_value' => 'https://old.test',
				),
			)
		);

		$report = ( new DatabaseRewriter( $db, new SerialisedReplacer() ) )->scan( 'old.test', 'new.example' );

		$this->assertSame( array(), $db->updates(), 'A scan must not issue any update.' );
		$this->assertSame( 1, $report->rows_changed(), 'A scan still reports what would change.' );
		$this->assertSame( 1, $report->values_changed() );
	}

	/**
	 * A serialised value's byte length is recomputed through the pass, not left stale.
	 *
	 * @return void
	 */
	public function test_recomputes_serialised_length_through_the_pass(): void {
		$db       = new FakeMigrationDatabase();
		$original = serialize( array( 'home' => 'https://old.test' ) );
		$db->add_table(
			'wp_options',
			'option_id',
			array(
				array(
					'option_id'    => 1,
					'option_value' => $original,
				),
			)
		);

		( new DatabaseRewriter( $db, new SerialisedReplacer() ) )->rewrite( 'old.test', 'a-much-longer-domain.example' );

		$written = $db->updates()[0]['columns']['option_value'];
		$this->assertSame( serialize( array( 'home' => 'https://a-much-longer-domain.example' ) ), $written );
		$this->assertSame(
			array( 'home' => 'https://a-much-longer-domain.example' ),
			unserialize( $written, array( 'allowed_classes' => false ) )
		);
	}

	/**
	 * A row holding a blocked object is left unchanged, counted skipped, and never wakes the gadget.
	 *
	 * @return void
	 */
	public function test_keeps_a_blocked_object_value_unchanged_and_counts_it_skipped(): void {
		$probe      = new GadgetProbe();
		$probe->url = 'https://old.test';
		$db         = new FakeMigrationDatabase();
		$db->add_table(
			'wp_options',
			'option_id',
			array(
				array(
					'option_id'    => 1,
					'option_value' => serialize(
						array(
							'gadget' => $probe,
							'site'   => 'https://old.test',
						)
					),
				),
			)
		);

		GadgetProbe::$awoken = false;

		$report = ( new DatabaseRewriter( $db, new SerialisedReplacer() ) )->rewrite( 'old.test', 'new.example' );

		$this->assertFalse( GadgetProbe::$awoken, 'The gadget must never wake while the pass walks the rows.' );
		$this->assertSame( array(), $db->updates(), 'A value holding a blocked object must not be rewritten.' );
		$this->assertSame( 0, $report->rows_changed() );
		$this->assertSame( 1, $report->skipped_values() );
	}

	/**
	 * A corrupt serialised value that holds the term is kept unchanged and counted skipped.
	 *
	 * @return void
	 */
	public function test_keeps_a_corrupt_serialised_value_unchanged(): void {
		$db = new FakeMigrationDatabase();
		$db->add_table(
			'wp_options',
			'option_id',
			array(
				// Declares 100 bytes but carries far fewer — unserialise fails.
				array(
					'option_id'    => 1,
					'option_value' => 's:100:"https://old.test";',
				),
			)
		);

		$report = ( new DatabaseRewriter( $db, new SerialisedReplacer() ) )->rewrite( 'old.test', 'new.example' );

		$this->assertSame( array(), $db->updates() );
		$this->assertSame( 0, $report->rows_changed() );
		$this->assertSame( 1, $report->skipped_values() );
	}

	/**
	 * Rows without the search term are not touched.
	 *
	 * @return void
	 */
	public function test_leaves_rows_without_the_term_untouched(): void {
		$db = new FakeMigrationDatabase();
		$db->add_table(
			'wp_options',
			'option_id',
			array(
				array(
					'option_id'    => 1,
					'option_value' => 'nothing to rewrite here',
				),
			)
		);

		$report = ( new DatabaseRewriter( $db, new SerialisedReplacer() ) )->rewrite( 'old.test', 'new.example' );

		$this->assertSame( array(), $db->updates() );
		$this->assertSame( 0, $report->rows_changed() );
		$this->assertSame( 0, $report->values_changed() );
		$this->assertSame( 0, $report->skipped_values() );
	}

	/**
	 * A failed row read aborts the pass loudly — never a silently half-rewritten table.
	 *
	 * @return void
	 */
	public function test_a_failed_row_read_is_not_swallowed(): void {
		$db = new FakeMigrationDatabase();
		$db->add_table(
			'wp_options',
			'option_id',
			array(
				array(
					'option_id'    => '1',
					'option_value' => 'https://old.test',
				),
			)
		);
		$db->fail_next( 'read_rows', 'simulated read failure' );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'simulated read failure' );

		( new DatabaseRewriter( $db, new SerialisedReplacer() ) )->rewrite( 'old.test', 'new.example' );
	}

	/**
	 * A failed key inspection aborts, and is never conflated with "no key, skip".
	 *
	 * The adapter returns null for a table with no usable key (the walk skips
	 * it, reported) and THROWS when the keys cannot be inspected at all; the
	 * pass must preserve that distinction — treating an inspection failure as
	 * a skip would silently leave a whole table unmigrated.
	 *
	 * @return void
	 */
	public function test_a_failed_key_inspection_aborts_rather_than_skips(): void {
		$db = new FakeMigrationDatabase();
		$db->add_table(
			'wp_options',
			'option_id',
			array(
				array(
					'option_id'    => '1',
					'option_value' => 'https://old.test',
				),
			)
		);
		$db->fail_next( 'primary_key', 'simulated key-inspection failure' );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'simulated key-inspection failure' );

		( new DatabaseRewriter( $db, new SerialisedReplacer() ) )->rewrite( 'old.test', 'new.example' );
	}

	/**
	 * A wide-row table is read a few rows at a time; narrow tables keep the ceiling.
	 *
	 * A batch is held in memory whole, so its size must be bounded by bytes:
	 * a 1 MiB average row (doubled for overhead) under the 4 MiB budget gives
	 * two rows per window, and the walk still covers every row across the
	 * smaller windows. A table with no sizing answer keeps the configured
	 * batch size as its ceiling.
	 *
	 * @return void
	 */
	public function test_batches_are_sized_per_table_from_the_real_row_width(): void {
		$db   = new FakeMigrationDatabase();
		$rows = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$rows[] = array(
				'id'   => (string) $i,
				'body' => 'see https://old.test/page-' . $i,
			);
		}
		$db->add_table( 'wp_wide', 'id', $rows );
		$db->set_average_row_bytes( 'wp_wide', 1048576 );

		$report = ( new DatabaseRewriter( $db, new SerialisedReplacer(), 1000 ) )->rewrite( 'old.test', 'new.example' );

		$limits = array_map(
			static fn ( array $read ): int => $read['limit'],
			$db->reads()
		);
		$this->assertSame( array( 2, 2, 2 ), $limits, 'A 1 MiB average row under the 4 MiB budget must read two rows per window.' );
		$this->assertSame( 5, $report->rows_scanned(), 'Every row must still be covered across the smaller windows.' );
		$this->assertSame( 5, $report->rows_changed() );
	}

	/**
	 * An unknown row width keeps the configured batch size as the ceiling.
	 *
	 * @return void
	 */
	public function test_unknown_row_width_keeps_the_configured_batch_ceiling(): void {
		$db = new FakeMigrationDatabase();
		$db->add_table(
			'wp_narrow',
			'id',
			array(
				array(
					'id'   => '1',
					'body' => 'no url',
				),
			)
		);

		( new DatabaseRewriter( $db, new SerialisedReplacer(), 7 ) )->rewrite( 'old.test', 'new.example' );

		$this->assertSame( 7, $db->reads()[0]['limit'], 'With no sizing answer the configured batch size is the window.' );
	}

	/**
	 * A guid column is never rewritten — WordPress treats it as permanent identity.
	 *
	 * Feed readers use the guid to decide whether a post is new, so it must
	 * survive a URL migration unchanged even though it holds the old URL; the
	 * report tallies it as deliberately skipped rather than silently ignored.
	 *
	 * @return void
	 */
	public function test_never_rewrites_a_guid_column(): void {
		$db = new FakeMigrationDatabase();
		$db->add_table(
			'wp_posts',
			'ID',
			array(
				array(
					'ID'           => '1',
					'guid'         => 'https://old.test/?p=1',
					'post_content' => 'see https://old.test/page',
				),
			)
		);

		$report = ( new DatabaseRewriter( $db, new SerialisedReplacer() ) )->rewrite( 'https://old.test', 'https://new.example' );

		$update = $db->updates()[0];
		$this->assertArrayHasKey( 'post_content', $update['columns'], 'Ordinary columns must still rewrite.' );
		$this->assertArrayNotHasKey( 'guid', $update['columns'], 'A guid is permanent identity and must never be rewritten.' );
		$this->assertSame( 1, $report->skipped_values(), 'A guid holding the old URL must be tallied as deliberately skipped.' );
	}

	/**
	 * The primary-key column is never rewritten and is used verbatim in the WHERE.
	 *
	 * Even when the key's own value contains the search term, it is left
	 * alone, so the UPDATE matches the original row.
	 *
	 * @return void
	 */
	public function test_never_rewrites_the_primary_key_column(): void {
		$db = new FakeMigrationDatabase();
		$db->add_table(
			'wp_keyed',
			'name',
			array(
				array(
					'name'  => 'old.test_key',
					'value' => 'see https://old.test',
				),
			)
		);

		( new DatabaseRewriter( $db, new SerialisedReplacer() ) )->rewrite( 'old.test', 'new.example' );

		$update = $db->updates()[0];
		$this->assertArrayHasKey( 'value', $update['columns'] );
		$this->assertArrayNotHasKey( 'name', $update['columns'], 'The primary-key column must never be rewritten.' );
		$this->assertSame( 'old.test_key', $update['primary_key_value'], 'The WHERE must use the original, un-rewritten key.' );
	}

	/**
	 * A second pass over already-rewritten data changes nothing.
	 *
	 * @return void
	 */
	public function test_is_idempotent(): void {
		$db = new FakeMigrationDatabase();
		$db->add_table(
			'wp_options',
			'option_id',
			array(
				array(
					'option_id'    => 1,
					'option_value' => 'https://old.test',
				),
			)
		);

		$rewriter = new DatabaseRewriter( $db, new SerialisedReplacer() );
		$rewriter->rewrite( 'old.test', 'new.example' );
		$after_first = count( $db->updates() );

		$report = $rewriter->rewrite( 'old.test', 'new.example' );

		$this->assertSame( $after_first, count( $db->updates() ), 'A second pass must issue no further updates.' );
		$this->assertSame( 0, $report->rows_changed() );
	}

	/**
	 * A table with no single-column primary key is skipped and named, never rewritten.
	 *
	 * @return void
	 */
	public function test_skips_a_table_without_a_primary_key(): void {
		$db = new FakeMigrationDatabase();
		$db->add_table(
			'wp_no_key',
			null,
			array(
				array( 'value' => 'https://old.test' ),
			)
		);

		$report = ( new DatabaseRewriter( $db, new SerialisedReplacer() ) )->rewrite( 'old.test', 'new.example' );

		$this->assertSame( array(), $db->updates(), 'A keyless table must not be rewritten.' );
		$this->assertSame( array( 'wp_no_key' ), $report->skipped_tables() );
		$this->assertSame( 0, $report->tables_scanned() );
	}

	/**
	 * A failed update is not swallowed — the pass throws (the $wpdb-returns-false path).
	 *
	 * @return void
	 */
	public function test_a_failed_update_is_not_swallowed(): void {
		$db = new FakeMigrationDatabase();
		$db->add_table(
			'wp_options',
			'option_id',
			array(
				array(
					'option_id'    => 1,
					'option_value' => 'https://old.test',
				),
			)
		);
		$db->fail_next_update( 'simulated database error' );

		$this->expectException( RuntimeException::class );

		( new DatabaseRewriter( $db, new SerialisedReplacer() ) )->rewrite( 'old.test', 'new.example' );
	}

	/**
	 * Every matching row across multiple batches is rewritten.
	 *
	 * @return void
	 */
	public function test_walks_every_row_across_batches(): void {
		$rows = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$rows[] = array(
				'option_id'    => $i,
				'option_value' => 'https://old.test/' . $i,
			);
		}
		$db = new FakeMigrationDatabase();
		$db->add_table( 'wp_options', 'option_id', $rows );

		$report = ( new DatabaseRewriter( $db, new SerialisedReplacer(), 2 ) )->rewrite( 'old.test', 'new.example' );

		$this->assertCount( 5, $db->updates(), 'A batch size smaller than the table must still visit every row.' );
		$this->assertSame( 5, $report->rows_scanned() );
		$this->assertSame( 5, $report->rows_changed() );
	}

	/**
	 * An empty search term is rejected.
	 *
	 * @return void
	 */
	public function test_rejects_an_empty_search(): void {
		$this->expectException( InvalidArgumentException::class );

		( new DatabaseRewriter( new FakeMigrationDatabase(), new SerialisedReplacer() ) )->scan( '', 'new.example' );
	}

	/**
	 * A non-positive batch size is rejected at construction.
	 *
	 * @return void
	 */
	public function test_rejects_a_non_positive_batch_size(): void {
		$this->expectException( InvalidArgumentException::class );

		new DatabaseRewriter( new FakeMigrationDatabase(), new SerialisedReplacer(), 0 );
	}
}
