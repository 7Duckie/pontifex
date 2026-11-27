<?php
/**
 * Unencodable-path integration test: a real filesystem, a real scanner and a real export.
 *
 * @package Pontifex\Tests\Integration
 */

declare(strict_types=1);

namespace Pontifex\Tests\Integration;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use Pontifex\Archive\Format\Scope;
use Pontifex\Environment\RealEnvironment;
use Pontifex\Export\ExportOptions;
use Pontifex\Export\ExportRunner;
use Pontifex\Export\UnencodablePathException;
use Pontifex\Manifest\DatabaseScanner;
use Pontifex\Manifest\ExclusionRules;
use Pontifex\Manifest\FileScanner;
use Pontifex\Manifest\ManifestBuilder;
use Pontifex\Manifest\WpdbAdapter;
use Pontifex\WordPress\RealWordPressContext;

/**
 * Proves that a name the filesystem accepts but UTF-8 forbids is refused by the
 * whole export pipeline, over the real production wiring.
 *
 * {@see \Pontifex\Tests\Unit\Archive\Format\PathEncodingTest} and the runner unit
 * tests prove the logic against hand-built entries. This is the end-to-end
 * counterpart: a real FileScanner walks a real fixture tree holding a real
 * invalid-UTF-8 name (and, separately, a symbolic link whose target is not valid
 * UTF-8, the case that once slipped the manifest-size preflight and aborted
 * part-way through the write), and the real ExportRunner must refuse it, name
 * it, and write nothing.
 *
 * A filesystem that refuses such a name outright (APFS on macOS) cannot hold the
 * fixture, so those tests skip there rather than fail; inside the wp-env
 * containers the temp directory accepts it.
 *
 * Non-destructive: the scanned tree is a temp fixture, never the real WordPress
 * install, and every archive path is a temp file.
 */
final class UnencodablePathExportTest extends TestCase {

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
		$this->fixture_root = sys_get_temp_dir() . '/pontifex-unencodable-path-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating the integration test's temp fixture root.
		mkdir( $this->fixture_root, 0o755, true );
	}

	/**
	 * Remove the temp fixture tree and any archive after each test.
	 *
	 * @return void
	 */
	protected function tear_down(): void {
		if ( '' !== $this->fixture_root ) {
			if ( file_exists( $this->fixture_root . '.wpmig' ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Removing an archive a failing test left behind; best-effort.
				@unlink( $this->fixture_root . '.wpmig' );
			}
			if ( is_dir( $this->fixture_root ) ) {
				self::rmtree( $this->fixture_root );
			}
		}
		parent::tear_down();
	}

	/**
	 * A file whose name is not valid UTF-8 refuses the export, names it escaped, and writes nothing.
	 *
	 * @return void
	 */
	public function test_export_refuses_a_file_name_that_is_not_valid_utf8(): void {
		$this->write_fixture_file( 'plugins/demo/demo.php', "<?php\n// a real plugin file\n" );
		$this->plant_or_skip_file( "plugins/demo/caf\xe9.txt" );

		$output = $this->fixture_root . '.wpmig';
		$thrown = null;
		try {
			$this->run_export( $output );
		} catch ( UnencodablePathException $e ) {
			$thrown = $e;
		}

		$this->assertNotNull( $thrown, 'A name that is not valid UTF-8 must refuse the export.' );
		$this->assertStringContainsString( 'caf\351.txt', $thrown->getMessage(), 'The message must name the entry with its raw byte escaped.' );
		$this->assertStringNotContainsString( "caf\xe9", $thrown->getMessage(), 'The message must not carry the raw invalid byte.' );
		$this->assertFileDoesNotExist( $output, 'A refused export must write nothing.' );
	}

	/**
	 * A symbolic link with a valid name but a target that is not valid UTF-8 refuses the export and names the link.
	 *
	 * This is the case that previously slipped the manifest-size preflight and aborted
	 * part-way through the write.
	 *
	 * @return void
	 */
	public function test_export_refuses_a_symlink_whose_target_is_not_valid_utf8(): void {
		$this->write_fixture_file( 'plugins/demo/demo.php', "<?php\n// a real plugin file\n" );
		$link = $this->fixture_root . '/plugins/demo/badlink';
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A filesystem that refuses the target is detected from the return value, and the test then skips.
		if ( ! @symlink( "/tmp/targ\xe9t", $link ) ) {
			$this->markTestSkipped( 'This filesystem will not store a symbolic-link target that is not valid UTF-8.' );
		}

		$output = $this->fixture_root . '.wpmig';
		$thrown = null;
		try {
			$this->run_export( $output );
		} catch ( UnencodablePathException $e ) {
			$thrown = $e;
		}

		$this->assertNotNull( $thrown, 'A symbolic-link target that is not valid UTF-8 must refuse the export.' );
		$this->assertStringContainsString( 'badlink', $thrown->getMessage(), 'The message must name the link, the entry the operator has to fix.' );
		$this->assertStringContainsString( ' (its symbolic-link target)', $thrown->getMessage(), 'The message must say it is the target, not the name, that is at fault.' );
		$this->assertFileDoesNotExist( $output, 'A refused export must write nothing.' );
	}

	/**
	 * An ordinary tree, including a legitimately accented name, still exports.
	 *
	 * The control that catches a check which refuses too much: an implementation that
	 * rejected every non-ASCII name would pass the two tests above and quietly break
	 * every site with an accented filename.
	 *
	 * @return void
	 */
	public function test_export_still_succeeds_for_valid_names_including_accented_ones(): void {
		$this->write_fixture_file( 'plugins/demo/demo.php', "<?php\n// a real plugin file\n" );
		$this->write_fixture_file( "plugins/demo/caf\u{e9}-valid.txt", 'accented but valid UTF-8' );

		$output = $this->fixture_root . '.wpmig';
		try {
			$this->run_export( $output );

			$this->assertFileExists( $output, 'An ordinary tree must still produce an archive.' );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Removing the archive this test wrote; best-effort.
			@unlink( $output );
		}
	}

	/**
	 * Run a real, files-only export of the fixture tree to the given path.
	 *
	 * @param string $output Absolute path of the archive to write.
	 * @return void
	 * @throws \Throwable Whatever the real scan or export raises, including the refusal under test.
	 */
	private function run_export( string $output ): void {
		global $wpdb;

		$rules            = ExclusionRules::default_v010();
		$file_scanner     = new FileScanner( $rules, 'wp-content' );
		$database_scanner = new DatabaseScanner( new WpdbAdapter( $wpdb ), $rules );
		// Files only: the database half is never scanned, so this test needs
		// no seeded table and cannot be defeated by one going missing.
		$manifest_builder = new ManifestBuilder( $file_scanner, $database_scanner, true, false );
		$entry_plans      = $manifest_builder->build( $this->fixture_root );

		$runner = new ExportRunner( new RealEnvironment(), new RealWordPressContext() );
		$runner->export(
			new ExportOptions( $output, null, null, null, Scope::files_only( $rules->patterns() ) ),
			$entry_plans
		);
	}

	/**
	 * Plant a file whose name the filesystem may refuse, skipping the test when it does.
	 *
	 * @param string $relative Relative path inside the fixture root, holding a byte that is not valid UTF-8.
	 * @return void
	 */
	private function plant_or_skip_file( string $relative ): void {
		$absolute = $this->fixture_root . '/' . $relative;
		$dir      = dirname( $absolute );
		if ( ! is_dir( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating a fixture file's parent directory in the temp tree.
			mkdir( $dir, 0o755, true );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents,WordPress.PHP.NoSilencedErrors.Discouraged -- Writing a fixture file into the temp tree; a refusal is detected from the return value, and the test then skips.
		if ( false === @file_put_contents( $absolute, 'x' ) ) {
			$this->markTestSkipped( 'This filesystem will not store a name that is not valid UTF-8.' );
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
