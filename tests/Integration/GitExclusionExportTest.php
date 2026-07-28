<?php
/**
 * .git exclusion integration test: the default over a real scan and a real export.
 *
 * @package Pontifex\Tests\Integration
 */

declare(strict_types=1);

namespace Pontifex\Tests\Integration;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use Pontifex\Archive\Format\Scope;
use Pontifex\Archive\Reader\ArchiveReader;
use Pontifex\Environment\RealEnvironment;
use Pontifex\Export\ExportOptions;
use Pontifex\Export\ExportRunner;
use Pontifex\Manifest\DatabaseScanner;
use Pontifex\Manifest\ExclusionRules;
use Pontifex\Manifest\FileScanner;
use Pontifex\Manifest\ManifestBuilder;
use Pontifex\Manifest\WpdbAdapter;
use Pontifex\Rollback\RollbackStore;
use Pontifex\Rollback\SafetyArchiver;
use Pontifex\WordPress\RealWordPressContext;

/**
 * Proves the default `.git` exclusion (v0.9.3, ADR 0008 amendment) over the
 * real production wiring, not just the pattern matcher in isolation.
 *
 * {@see \Pontifex\Tests\Unit\Manifest\ExclusionRulesTest} proves the regex
 * matches at every depth and kind; {@see \Pontifex\Tests\Unit\Manifest\FileScannerTest}
 * proves the scanner prunes rather than walks a `.git` directory. This test is
 * the end-to-end counterpart: a real FileScanner walking a real fixture tree,
 * through the same ExportRunner/ManifestBuilder pipeline the CLI and admin
 * paths use, actually keeps every `.git` entry out of a written `.wpmig`; and
 * SafetyArchiver's own default wiring — used whenever no manifest builder is
 * injected, i.e. every real pre-import safety archive — inherits the same
 * exclusion without this test injecting anything to make it so. The second
 * case is the wiring the 695 MB dev-site observation that prompted this
 * default traced back to.
 *
 * Non-destructive: the scanned tree is a temp fixture, never the real
 * WordPress install, and every archive this test writes is a temp file. The
 * safety-archiver case scans the real (test) WordPress installation's
 * database — SafetyArchiver always captures both halves, and only the file
 * root is the fixture tree — the same real-database-scan pattern
 * OrderedPaginationTest and WideRowChunkingTest already use.
 */
final class GitExclusionExportTest extends TestCase {

	/**
	 * Absolute path to the temp fixture tree scanned by each test.
	 *
	 * @var string
	 */
	private string $fixture_root = '';

	/**
	 * Reserve a fresh fixture root before each test.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();
		$this->fixture_root = sys_get_temp_dir() . '/pontifex-git-exclusion-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating the integration test's temp fixture root.
		mkdir( $this->fixture_root, 0o755, true );
	}

	/**
	 * Remove the temp fixture tree after each test.
	 *
	 * @return void
	 */
	protected function tear_down(): void {
		if ( '' !== $this->fixture_root && is_dir( $this->fixture_root ) ) {
			self::rmtree( $this->fixture_root );
		}
		parent::tear_down();
	}

	/**
	 * A real export of a tree containing `.git` at several depths never carries it.
	 *
	 * `.git` sits both at the fixture root and inside a plugin's own repository —
	 * the depth a plain "**\/.git/**" glob would have missed, per ExclusionRules'
	 * class docblock — and neither appears in the written archive. Pruning itself
	 * is proved by {@see \Pontifex\Tests\Unit\Manifest\FileScannerTest}, which can
	 * make the `.git` directory unreadable and assert the scan still completes;
	 * this test's job is the end-to-end one, that the whole export pipeline honours
	 * the exclusion over real production wiring.
	 *
	 * @return void
	 */
	public function test_export_pipeline_omits_git_at_any_depth(): void {
		global $wpdb;

		$this->write_fixture_file( '.git/HEAD', "ref: refs/heads/main\n" );
		$this->write_fixture_file( 'plugins/demo/demo.php', "<?php\n// a real plugin file\n" );
		$unreadable = $this->write_fixture_file( 'plugins/demo/.git/objects/pack/pack-abc.pack', 'binary-ish' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Test fixture; unreadable so any .git payload that leaked into the archive would fail the export outright rather than merely fail an assertion.
		chmod( $unreadable, 0o000 );

		try {
			$rules            = ExclusionRules::default_v010();
			$file_scanner     = new FileScanner( $rules, 'wp-content' );
			$database_scanner = new DatabaseScanner( new WpdbAdapter( $wpdb ), $rules );
			// Files only: the database half is never scanned, so this test needs
			// no seeded table and cannot be defeated by one going missing.
			$manifest_builder = new ManifestBuilder( $file_scanner, $database_scanner, true, false );

			$entry_plans = $manifest_builder->build( $this->fixture_root );

			$output = $this->fixture_root . '.wpmig';
			$runner = new ExportRunner( new RealEnvironment(), new RealWordPressContext() );
			$runner->export(
				new ExportOptions( $output, null, null, null, Scope::files_only( $rules->patterns() ) ),
				$entry_plans
			);

			try {
				$paths = $this->archive_paths( $output );

				$this->assertContains( 'wp-content/plugins/demo/demo.php', $paths, 'A real plugin file must still be captured.' );
				foreach ( $paths as $path ) {
					$this->assertStringNotContainsString( '.git', $path, sprintf( 'The archive must never carry a .git entry; found "%s".', $path ) );
				}
			} finally {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Removing the archive this test wrote; best-effort.
				@unlink( $output );
			}
		} finally {
			// Restore readability so tear_down's rmtree can remove the fixture.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Test fixture cleanup.
			chmod( $unreadable, 0o644 );
		}
	}

	/**
	 * SafetyArchiver's own default wiring inherits the same `.git` exclusion,
	 * without this test injecting anything to make it so.
	 *
	 * This is the wiring the 695 MB dev-site observation traced back to: a
	 * pre-import safety archive of a git-deployed plugin directory. No manifest
	 * builder is injected, so SafetyArchiver builds its real default one
	 * (ExportRunner::default_manifest_builder over ExclusionRules::default_v010())
	 * exactly as a live `wp pontifex import` would. The database half scans the
	 * real WordPress test installation — SafetyArchiver always captures both
	 * halves — the same real-database pattern OrderedPaginationTest and
	 * WideRowChunkingTest already use; only the file root is the fixture tree.
	 *
	 * @return void
	 */
	public function test_safety_archiver_default_wiring_inherits_the_git_exclusion(): void {
		$this->write_fixture_file( '.git/config', "[core]\n\tbare = false\n" );
		$this->write_fixture_file( 'uploads/keep.txt', 'site-content' );

		$rollback_dir = $this->fixture_root . '-rollback';
		$store        = new RollbackStore( $rollback_dir );

		$archiver = new SafetyArchiver(
			new RealEnvironment(),
			new RealWordPressContext(),
			$store,
			null,
			2,
			true
		);

		try {
			$path  = $archiver->create( $this->fixture_root );
			$paths = $this->archive_paths( $path );

			$this->assertContains( 'wp-content/uploads/keep.txt', $paths, 'Real site content must still be captured.' );
			foreach ( $paths as $entry_path ) {
				$this->assertStringNotContainsString( '.git', $entry_path, sprintf( 'The safety archive must never carry a .git entry; found "%s".', $entry_path ) );
			}
		} finally {
			self::rmtree( $rollback_dir );
		}
	}

	/**
	 * Open a written archive and return every file/directory/symlink path its manifest declares.
	 *
	 * @param string $path Absolute path to the archive.
	 * @return string[] Every recorded path (db_chunk entries carry no path and are skipped).
	 */
	private function archive_paths( string $path ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Opening a just-written archive to read its manifest back.
		$source = fopen( $path, 'rb' );
		if ( false === $source ) {
			$this->fail( 'Could not open the written archive.' );
		}
		try {
			$reader = new ArchiveReader( $source );
			$paths  = array();
			foreach ( $reader->manifest()->entries() as $entry ) {
				$entry_path = $entry->path();
				if ( null !== $entry_path ) {
					$paths[] = $entry_path;
				}
			}
			return $paths;
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the archive stream opened in this helper.
			fclose( $source );
		}
	}

	/**
	 * Write a fixture file under the temp fixture root, creating parents.
	 *
	 * @param string $relative Relative path inside the fixture root.
	 * @param string $contents File contents.
	 * @return string The absolute path written.
	 */
	private function write_fixture_file( string $relative, string $contents ): string {
		$absolute = $this->fixture_root . '/' . $relative;
		$dir      = dirname( $absolute );
		if ( ! is_dir( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating a fixture file's parent directory in the temp tree.
			mkdir( $dir, 0o755, true );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing a fixture file into the temp tree.
		file_put_contents( $absolute, $contents );
		return $absolute;
	}

	/**
	 * Recursively delete a directory tree.
	 *
	 * @param string $path Absolute path to remove.
	 * @return void
	 */
	private static function rmtree( string $path ): void {
		if ( ! is_dir( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Test fixture teardown; best-effort.
			@unlink( $path );
			return;
		}
		foreach ( scandir( $path ) as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				self::rmtree( $path . '/' . $entry );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.PHP.NoSilencedErrors.Discouraged -- Test fixture teardown; best-effort.
		@rmdir( $path );
	}
}
