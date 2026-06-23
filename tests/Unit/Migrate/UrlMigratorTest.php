<?php
/**
 * Tests for UrlMigrator — source-URL read + live-database rewrite.
 *
 * @package Pontifex\Tests\Unit\Migrate
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Migrate;

use DateTimeImmutable;
use DateTimeZone;
use Mockery;
use RuntimeException;
use stdClass;
use Pontifex\Archive\Codec\CodecRegistry;
use Pontifex\Archive\Format\ExporterInfo;
use Pontifex\Archive\Format\Provenance;
use Pontifex\Archive\Writer\ArchiveWriter;
use Pontifex\Archive\Writer\EntryWriter;
use Pontifex\Archive\Writer\FooterWriter;
use Pontifex\Migrate\UrlMigrator;
use Pontifex\Tests\TestCase;
use Pontifex\Tests\Unit\Migrate\Fakes\FakeMigrationDatabase;
use Pontifex\WordPress\WordPressContext;

// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize,WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Builds and verifies serialised fixtures to prove the allowlist reaches the rewrite.

/**
 * Confirms the migrator reads the source URL from the archive and rewrites through the database.
 */
final class UrlMigratorTest extends TestCase {

	/**
	 * The source_url method returns the source-site URL from the archive's provenance.
	 *
	 * @return void
	 */
	public function test_source_url_returns_the_archive_provenance_url(): void {
		$migrator = new UrlMigrator( $this->context_with_allowlist( array() ), new FakeMigrationDatabase() );

		$this->assertSame(
			'https://migrate-source.example',
			$migrator->source_url( self::archive_with_url( 'https://migrate-source.example' ) )
		);
	}

	/**
	 * The migrate method rewrites matching rows through the injected database and reports the change.
	 *
	 * @return void
	 */
	public function test_migrate_rewrites_matching_rows_via_the_database(): void {
		$database = new FakeMigrationDatabase();
		$database->add_table(
			'wp_options',
			'option_id',
			array(
				array(
					'option_id'    => 1,
					'option_value' => 'https://old.test',
				),
			)
		);

		$report = ( new UrlMigrator( $this->context_with_allowlist( array() ), $database ) )->migrate( 'old.test', 'new.example' );

		$this->assertSame( 1, $report->rows_changed() );
		$this->assertSame( 'https://new.example', $database->updates()[0]['columns']['option_value'] );
	}

	/**
	 * The migrate method honours the class allowlist resolved from the WordPress context.
	 *
	 * With stdClass opted in, a serialised array holding a stdClass is no longer
	 * blocked, so its sibling URL string is rewritten — proving the
	 * pontifex_serialized_classes allowlist flows from the context into the
	 * replacer the migrator builds.
	 *
	 * @return void
	 */
	public function test_migrate_honours_the_serialised_classes_allowlist(): void {
		$object       = new stdClass();
		$object->note = 'https://old.test';
		$database     = new FakeMigrationDatabase();
		$database->add_table(
			'wp_options',
			'option_id',
			array(
				array(
					'option_id'    => 1,
					'option_value' => serialize(
						array(
							'url'  => 'https://old.test',
							'meta' => $object,
						)
					),
				),
			)
		);

		$report = ( new UrlMigrator( $this->context_with_allowlist( array( 'stdClass' ) ), $database ) )->migrate( 'old.test', 'new.example' );

		$this->assertSame( 1, $report->rows_changed(), 'An allowlisted class must let the sibling URL be rewritten.' );

		$decoded = unserialize( $database->updates()[0]['columns']['option_value'], array( 'allowed_classes' => array( 'stdClass' ) ) );
		$this->assertIsArray( $decoded );
		$this->assertSame( 'https://new.example', $decoded['url'] );
	}

	/**
	 * Build a WordPressContext mock that resolves the serialised-classes allowlist.
	 *
	 * @param array<int, string> $allowlist The allowlist the context should return.
	 * @return WordPressContext&\Mockery\MockInterface
	 */
	private function context_with_allowlist( array $allowlist ) {
		$mock = Mockery::mock( WordPressContext::class );
		$mock->shouldReceive( 'serialised_classes_allowlist' )->andReturn( $allowlist );
		return $mock;
	}

	/**
	 * Build an in-memory archive whose provenance records the given source URL.
	 *
	 * @param string $url The source URL to embed in the provenance.
	 * @return resource A readable, seekable stream containing the archive.
	 * @throws RuntimeException If php://memory cannot be opened.
	 */
	private static function archive_with_url( string $url ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://memory is an in-process buffer, not a file.
		$dest = fopen( 'php://memory', 'r+b' );
		if ( false === $dest ) {
			throw new RuntimeException( 'Could not open php://memory for test.' );
		}

		$provenance = new Provenance(
			'6.6.1',
			'8.2.10',
			$url,
			'utf8mb4',
			'utf8mb4_general_ci',
			new ExporterInfo( 'pontifex', '0.3.0' ),
			new DateTimeImmutable( '2026-06-23T00:00:00+00:00', new DateTimeZone( 'UTC' ) )
		);

		( new ArchiveWriter( new EntryWriter( CodecRegistry::with_defaults() ), new FooterWriter() ) )->write_archive( $provenance, array(), $dest );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource, not a filesystem path.
		rewind( $dest );
		return $dest;
	}
}
