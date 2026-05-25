<?php
/**
 * Unit tests for the ManifestBuilder class.
 *
 * @package Pontifex\Tests\Unit\Manifest
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Manifest;

require_once __DIR__ . '/Fakes/FakeDbAdapter.php';

use PHPUnit\Framework\TestCase;
use Pontifex\Archive\Codec\GzipCodec;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Writer\EntryPlan;
use Pontifex\Archive\Writer\EntryWriter;
use Pontifex\Manifest\DatabaseScanner;
use Pontifex\Manifest\ExclusionRules;
use Pontifex\Manifest\FileScanner;
use Pontifex\Manifest\ManifestBuilder;
use Pontifex\Tests\Unit\Manifest\Fakes\FakeDbAdapter;

/**
 * Tests for {@see ManifestBuilder}.
 *
 * Exercises the bridge between scanners and ArchiveWriter: builds a
 * fixture tree, configures a FakeDbAdapter, runs build(), and checks
 * that the resulting EntryPlan list is well-formed and ordered as
 * expected.
 */
final class ManifestBuilderTest extends TestCase {

	/**
	 * Absolute path to the fixture root for the current test.
	 *
	 * @var string
	 */
	private string $fixture_root;

	/**
	 * Create a fresh fixture root before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->fixture_root = sys_get_temp_dir() . '/pontifex-manifest-test-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture setup under sys_get_temp_dir.
		mkdir( $this->fixture_root, 0o755, true );
	}

	/**
	 * Remove the fixture root recursively after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ( is_dir( $this->fixture_root ) ) {
			self::rmtree( $this->fixture_root );
		}
		parent::tearDown();
	}

	/**
	 * Recursively delete a directory and its contents.
	 *
	 * @param string $path Absolute path to remove.
	 * @return void
	 */
	private static function rmtree( string $path ): void {
		if ( is_link( $path ) || ! is_dir( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture teardown; wp_delete_file unavailable in PHPUnit context.
			unlink( $path );
			return;
		}
		foreach ( scandir( $path ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			self::rmtree( $path . '/' . $entry );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture teardown.
		rmdir( $path );
	}

	/**
	 * Write a file at the given relative path within the fixture root.
	 *
	 * @param string $relative Relative path under the fixture root.
	 * @param string $contents File contents.
	 * @return string The absolute path written.
	 */
	private function write_file( string $relative, string $contents = 'data' ): string {
		$absolute = $this->fixture_root . '/' . $relative;
		$dir      = dirname( $absolute );
		if ( ! is_dir( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture setup.
			mkdir( $dir, 0o755, true );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture setup.
		file_put_contents( $absolute, $contents );
		return $absolute;
	}

	/**
	 * Build a ManifestBuilder wired to scanners with no exclusions and an empty database.
	 *
	 * @param FakeDbAdapter|null $db Optional adapter; if null, an empty one is used.
	 * @return ManifestBuilder A builder ready to call build() on.
	 */
	private static function default_builder( ?FakeDbAdapter $db = null ): ManifestBuilder {
		$db               = $db ?? new FakeDbAdapter();
		$file_scanner     = new FileScanner( ExclusionRules::none() );
		$database_scanner = new DatabaseScanner( $db, ExclusionRules::none() );
		return new ManifestBuilder( $file_scanner, $database_scanner );
	}

	/**
	 * Building against an empty WordPress root and empty database must return an empty array.
	 *
	 * @return void
	 */
	public function test_build_empty_inputs_returns_empty_array(): void {
		$plans = self::default_builder()->build( $this->fixture_root );

		$this->assertSame( array(), $plans );
	}

	/**
	 * Every produced EntryPlan must use the gzip codec.
	 *
	 * @return void
	 */
	public function test_every_plan_uses_gzip_codec(): void {
		$this->write_file( 'wp-config.php', '<?php ?>' );
		$db = new FakeDbAdapter();
		$db->add_table( 'wp_options', 1, "CREATE TABLE `wp_options` (id INT);\n" );

		$plans = self::default_builder( $db )->build( $this->fixture_root );

		$this->assertNotEmpty( $plans );
		foreach ( $plans as $plan ) {
			$this->assertSame( GzipCodec::ID, $plan->codec_id() );
		}
	}

	/**
	 * Every produced EntryPlan must use a NONCE_SIZE-byte zero nonce.
	 *
	 * @return void
	 */
	public function test_every_plan_uses_zero_nonce(): void {
		$this->write_file( 'wp-config.php' );
		$expected_nonce = str_repeat( "\0", EntryWriter::NONCE_SIZE );

		$plans = self::default_builder()->build( $this->fixture_root );

		$this->assertNotEmpty( $plans );
		foreach ( $plans as $plan ) {
			$this->assertSame( $expected_nonce, $plan->nonce() );
		}
	}

	/**
	 * File plans must come before db_chunk plans in the returned list.
	 *
	 * @return void
	 */
	public function test_file_plans_come_before_db_plans(): void {
		$this->write_file( 'wp-config.php' );
		$this->write_file( 'index.php' );

		$db = new FakeDbAdapter();
		$db->add_table( 'wp_options', 5, "CREATE TABLE `wp_options` (id INT);\n" );

		$plans = self::default_builder( $db )->build( $this->fixture_root );

		// Find the last file plan's index and the first db_chunk's index.
		$last_file_index = null;
		$first_db_index  = null;
		foreach ( $plans as $index => $plan ) {
			$kind = $plan->header()->kind();
			if ( EntryHeader::KIND_DB_CHUNK !== $kind && null !== $kind ) {
				$last_file_index = $index;
			} elseif ( null === $first_db_index && EntryHeader::KIND_DB_CHUNK === $kind ) {
				$first_db_index = $index;
			}
		}

		$this->assertNotNull( $last_file_index );
		$this->assertNotNull( $first_db_index );
		$this->assertLessThan( $first_db_index, $last_file_index );
	}

	/**
	 * A regular file's EntryPlan must carry an EntryHeader of kind KIND_FILE.
	 *
	 * @return void
	 */
	public function test_file_plan_has_file_header(): void {
		$this->write_file( 'wp-config.php', 'data' );

		$plans = self::default_builder()->build( $this->fixture_root );

		$this->assertCount( 1, $plans );
		$header = $plans[0]->header();
		$this->assertSame( EntryHeader::KIND_FILE, $header->kind() );
		$this->assertSame( 'wp-config.php', $header->path() );
	}

	/**
	 * A directory must produce an EntryPlan of kind KIND_DIRECTORY.
	 *
	 * @return void
	 */
	public function test_directory_plan_has_directory_header(): void {
		$this->write_file( 'wp-content/themes/twentytwentyfour/style.css' );

		$plans = self::default_builder()->build( $this->fixture_root );
		$kinds = array_map( static fn( EntryPlan $p ): string => (string) $p->header()->kind(), $plans );

		$this->assertContains( EntryHeader::KIND_DIRECTORY, $kinds );
	}

	/**
	 * A db_chunk plan must carry an EntryHeader of kind KIND_DB_CHUNK.
	 *
	 * @return void
	 */
	public function test_db_chunk_plan_has_db_chunk_header(): void {
		$db = new FakeDbAdapter();
		$db->add_table( 'wp_options', 1, "CREATE TABLE `wp_options` (id INT);\n" );

		$plans = self::default_builder( $db )->build( $this->fixture_root );

		$this->assertCount( 1, $plans );
		$header = $plans[0]->header();
		$this->assertSame( EntryHeader::KIND_DB_CHUNK, $header->kind() );
		$this->assertSame( 'wp_options', $header->table_name() );
	}

	/**
	 * Every EntryPlan must have an opened readable source stream.
	 *
	 * @return void
	 */
	public function test_every_plan_has_readable_source(): void {
		$this->write_file( 'wp-config.php', 'data' );
		$db = new FakeDbAdapter();
		$db->add_table( 'wp_options', 1, "CREATE TABLE `wp_options` (id INT);\n" );

		$plans = self::default_builder( $db )->build( $this->fixture_root );

		foreach ( $plans as $plan ) {
			$this->assertIsResource( $plan->source() );
		}
	}

	/**
	 * A file plan's source stream must yield the file's bytes when read.
	 *
	 * @return void
	 */
	public function test_file_plan_source_yields_file_contents(): void {
		$this->write_file( 'note.txt', 'pontifex was here' );

		$plans = self::default_builder()->build( $this->fixture_root );

		$this->assertCount( 1, $plans );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_stream_get_contents -- Reading a test stream resource.
		$bytes = stream_get_contents( $plans[0]->source() );
		$this->assertSame( 'pontifex was here', $bytes );
	}

	/**
	 * A directory plan's source stream must be empty (the payload is empty for directories).
	 *
	 * @return void
	 */
	public function test_directory_plan_source_is_empty(): void {
		$this->write_file( 'wp-content/themes/x/style.css' );

		$plans = self::default_builder()->build( $this->fixture_root );

		foreach ( $plans as $plan ) {
			if ( EntryHeader::KIND_DIRECTORY === $plan->header()->kind() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_stream_get_contents -- Reading a test stream resource.
				$bytes = stream_get_contents( $plan->source() );
				$this->assertSame( '', $bytes );
			}
		}
	}

	/**
	 * Exclusions configured on the scanners must propagate through to the build output.
	 *
	 * @return void
	 */
	public function test_scanner_exclusions_propagate_to_build(): void {
		$this->write_file( 'keep.txt' );
		$this->write_file( 'drop.txt' );

		$file_scanner     = new FileScanner( new ExclusionRules( array( 'drop.txt' ) ) );
		$database_scanner = new DatabaseScanner( new FakeDbAdapter(), ExclusionRules::none() );
		$builder          = new ManifestBuilder( $file_scanner, $database_scanner );

		$plans = $builder->build( $this->fixture_root );
		$paths = array();
		foreach ( $plans as $plan ) {
			if ( EntryHeader::KIND_FILE === $plan->header()->kind() ) {
				$paths[] = $plan->header()->path();
			}
		}

		$this->assertContains( 'keep.txt', $paths );
		$this->assertNotContains( 'drop.txt', $paths );
	}

	/**
	 * The media_type captured by FileScanner must propagate through ManifestBuilder into the resulting EntryHeader.
	 *
	 * @return void
	 */
	public function test_media_type_propagates_through_to_entry_header(): void {
		$this->write_file( 'note.txt', 'plain text content' );

		$plans = self::default_builder()->build( $this->fixture_root );

		$file_plans = array();
		foreach ( $plans as $plan ) {
			if ( EntryHeader::KIND_FILE === $plan->header()->kind() ) {
				$file_plans[] = $plan;
			}
		}

		$this->assertCount( 1, $file_plans );
		$this->assertIsString( $file_plans[0]->header()->media_type() );
		$this->assertNotSame( '', $file_plans[0]->header()->media_type() );
	}
}
