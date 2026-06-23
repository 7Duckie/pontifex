<?php
/**
 * Pontifex URL migrator — reads the source URL and rewrites it across the live database.
 *
 * @package Pontifex\Migrate
 */

declare(strict_types=1);

namespace Pontifex\Migrate;

use InvalidArgumentException;
use RuntimeException;
use Pontifex\Archive\Reader\ArchiveReader;
use Pontifex\WordPress\WordPressContext;

/**
 * Concrete {@see UrlMigratorInterface}: provenance read + serialised-safe rewrite.
 *
 * {@see source_url()} reads the source URL from the archive's provenance block
 * via {@see ArchiveReader}. {@see migrate()} builds a {@see DatabaseRewriter}
 * over the live `$wpdb` and runs it, with the class allowlist taken from the
 * `pontifex_serialized_classes` filter through the WordPress context — so a
 * site owner's opted-in classes take effect, and nothing else widens the
 * unserialise guard.
 *
 * The migration database is injectable: production passes none and the migrator
 * walks every prefixed table (the wp search-replace default); a test passes a
 * scoped {@see WpdbMigrationDatabase} so it can exercise a single table without
 * touching the rest of the database.
 */
final class UrlMigrator implements UrlMigratorInterface {

	/**
	 * The WordPress context supplying the wpdb instance and the class allowlist.
	 *
	 * @var WordPressContext
	 */
	private WordPressContext $wordpress_context;

	/**
	 * The migration database to rewrite through, or null to build the default unscoped one.
	 *
	 * @var MigrationDatabase|null
	 */
	private ?MigrationDatabase $database;

	/**
	 * Construct a UrlMigrator.
	 *
	 * @param WordPressContext       $wordpress_context Supplies the wpdb instance and the serialised-classes allowlist.
	 * @param MigrationDatabase|null $database          Optional migration database; null walks every prefixed table.
	 */
	public function __construct( WordPressContext $wordpress_context, ?MigrationDatabase $database = null ) {
		$this->wordpress_context = $wordpress_context;
		$this->database          = $database;
	}

	/**
	 * Return the source-site URL recorded in the archive's provenance.
	 *
	 * @param resource $archive_source A seekable, readable stream containing a Pontifex archive.
	 * @return string The source-site URL.
	 * @throws RuntimeException If the archive cannot be read or its provenance is malformed.
	 */
	public function source_url( $archive_source ): string {
		return ( new ArchiveReader( $archive_source ) )->provenance()->url();
	}

	/**
	 * Rewrite the old URL to the new one across the live database.
	 *
	 * @param string $old_url The URL to replace; must be non-empty.
	 * @param string $new_url The replacement URL.
	 * @return RewriteReport The counts of what changed.
	 * @throws InvalidArgumentException If $old_url is empty.
	 * @throws RuntimeException         If the database signals a failure.
	 */
	public function migrate( string $old_url, string $new_url ): RewriteReport {
		$database = $this->database ?? new WpdbMigrationDatabase( $this->wordpress_context->wpdb_instance() );
		$replacer = new SerialisedReplacer( $this->wordpress_context->serialised_classes_allowlist() );

		return ( new DatabaseRewriter( $database, $replacer ) )->rewrite( $old_url, $new_url );
	}
}
