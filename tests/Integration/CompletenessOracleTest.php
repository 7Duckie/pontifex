<?php
/**
 * Completeness oracle integration test: the archive against the real filesystem.
 *
 * @package Pontifex\Tests\Integration
 */

declare(strict_types=1);

namespace Pontifex\Tests\Integration;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use Pontifex\Archive\Codec\CodecRegistry;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Format\Scope;
use Pontifex\Archive\Reader\ArchiveReader;
use Pontifex\Archive\Reader\EntryReader;
use Pontifex\Environment\RealEnvironment;
use Pontifex\Export\ExportOptions;
use Pontifex\Export\ExportRunner;
use Pontifex\Manifest\DatabaseScanner;
use Pontifex\Manifest\ExclusionRules;
use Pontifex\Manifest\FileScanner;
use Pontifex\Manifest\ManifestBuilder;
use Pontifex\Manifest\WpdbAdapter;
use Pontifex\Restore\DatabaseWriter;
use Pontifex\Restore\FileWriter;
use Pontifex\Restore\RestoreRunner;
use Pontifex\WordPress\RealWordPressContext;

/**
 * Proves the single highest-value missing guarantee in the suite: that the
 * archive an export actually writes contains exactly what the filesystem
 * actually holds — nothing dropped, nothing invented.
 *
 * Every other round-trip test (RoundTripTest and its siblings) hand-assembles
 * its own EntryPlan list, so the scanner is never in the loop: a scanner that
 * silently drops a file cannot fail them. GitExclusionExportTest drives the
 * real scanner end to end but only checks presence/absence of specific known
 * paths — a "some things are right" oracle, not "everything is right and
 * nothing extra". This test is the missing set-equality oracle: it builds a
 * fixture tree, exports it through the REAL FileScanner -> ManifestBuilder ->
 * ExportRunner pipeline (no hand-written entry list anywhere), and asserts
 * set equality between the paths actually on disk (walked independently,
 * by plain RecursiveDirectoryIterator, never via FileScanner) and the
 * archive's own manifest — in both directions. It then restores the archive
 * for real and checks every file's bytes match.
 *
 * This is exactly the defect class that shipped in v0.9.3: a double next()
 * call in an earlier FileScanner silently swallowed the entry immediately
 * after any excluded directory, and verify still reported the resulting
 * archive sound. The fixture below deliberately places an ordinary file
 * beside an excluded directory to keep that shape alive in the suite.
 *
 * Non-destructive: the scanned and restored trees are both temp fixtures,
 * never the real WordPress install; the archive is a temp file. The export
 * is files-only (ADR 0016), so the database scanner and writer are wired in
 * but never asked to touch a real table.
 */
final class CompletenessOracleTest extends TestCase {

	/**
	 * Absolute path to the temp fixture tree the export scans.
	 *
	 * @var string
	 */
	private string $fixture_root = '';

	/**
	 * Absolute path to the temp directory the archive is restored into.
	 *
	 * @var string
	 */
	private string $restore_root = '';

	/**
	 * Absolute path of the archive this test writes.
	 *
	 * @var string
	 */
	private string $archive_path = '';

	/**
	 * Reserve fresh fixture/restore roots and an archive path before each test.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		$suffix             = bin2hex( random_bytes( 8 ) );
		$this->fixture_root = sys_get_temp_dir() . '/pontifex-completeness-fixture-' . $suffix;
		$this->restore_root = sys_get_temp_dir() . '/pontifex-completeness-restore-' . $suffix;
		$this->archive_path = sys_get_temp_dir() . '/pontifex-completeness-' . $suffix . '.wpmig';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating the integration test's temp fixture root.
		mkdir( $this->fixture_root, 0o755, true );
	}

	/**
	 * Remove the fixture tree, the restored tree, and the archive.
	 *
	 * @return void
	 */
	protected function tear_down(): void {
		if ( '' !== $this->fixture_root && is_dir( $this->fixture_root ) ) {
			self::rmtree( $this->fixture_root );
		}
		if ( '' !== $this->restore_root && is_dir( $this->restore_root ) ) {
			self::rmtree( $this->restore_root );
		}
		if ( '' !== $this->archive_path && is_file( $this->archive_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Test fixture teardown; best-effort.
			@unlink( $this->archive_path );
		}
		parent::tear_down();
	}

	/**
	 * A real export carries exactly the files and directories on disk, minus exclusions.
	 *
	 * @return void
	 */
	public function test_real_export_carries_exactly_the_filesystem_minus_exclusions(): void {
		global $wpdb;

		// 1. A fixture tree with awkward-but-legitimate shapes.
		$this->write_fixture_file( 'index.php', "<?php\n// fixture root file\n" );
		$this->write_fixture_file( 'plugins/demo/demo.php', "<?php\n// a real plugin file\n" );
		// The v0.9.3 shape: an ordinary file sitting beside an excluded directory.
		$this->write_fixture_file( 'excluded-dir/skip-me.txt', 'must not appear in the archive' );
		$this->write_fixture_file( 'excluded-dir-sibling.txt', "must survive the exclusion\n" );
		$this->write_fixture_file( 'uploads/.hidden-dotfile', "dotfiles are legitimate content\n" );
		$this->write_fixture_file( 'uploads/file with spaces.txt', "spaces in a filename are legitimate\n" );
		$this->write_fixture_file( 'uploads/日本語ファイル.txt', "a unicode filename and its content: café ☕ 日本語\n" );
		$this->write_fixture_file( 'uploads/deep/a/b/c/d/leaf.txt', "a deeply nested file\n" );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating an empty fixture directory.
		mkdir( $this->fixture_root . '/empty-dir', 0o755, true );

		$rules = ExclusionRules::from_array( array( 'excluded-dir/**' ) );

		// 2. Export through the REAL FileScanner -> ManifestBuilder -> ExportRunner.
		// No hand-written entry list anywhere; files-only so the database scanner
		// (wired in because ManifestBuilder requires one) is never asked to scan.
		$file_scanner     = new FileScanner( $rules, '' );
		$database_scanner = new DatabaseScanner( new WpdbAdapter( $wpdb ), $rules );
		$manifest_builder = new ManifestBuilder( $file_scanner, $database_scanner, true, false );
		$entry_plans      = $manifest_builder->build( $this->fixture_root );

		$export_runner = new ExportRunner( new RealEnvironment(), new RealWordPressContext() );
		$export_runner->export(
			new ExportOptions( $this->archive_path, null, null, null, Scope::files_only( $rules->patterns() ) ),
			$entry_plans
		);

		// 3. Set equality, both directions: the independent walk (never via
		// FileScanner) against the archive's own manifest, for files and for
		// directories separately.
		$expected_files       = self::independent_walk( $this->fixture_root, $rules, EntryHeader::KIND_FILE );
		$expected_directories = self::independent_walk( $this->fixture_root, $rules, EntryHeader::KIND_DIRECTORY );

		$archive_paths = self::archive_paths_by_kind( $this->archive_path );

		$this->assertEqualsCanonicalizing(
			$expected_files,
			$archive_paths[ EntryHeader::KIND_FILE ],
			'The archive must carry exactly the files on disk, minus the configured exclusions: nothing missing, nothing invented.'
		);
		$this->assertEqualsCanonicalizing(
			$expected_directories,
			$archive_paths[ EntryHeader::KIND_DIRECTORY ],
			'The archive must carry exactly the directories on disk, minus the configured exclusions: nothing missing, nothing invented.'
		);

		// 4. Byte-equality of every file's content, via a real restore of the
		// archive this test just wrote — not a re-read of the source tree.
		$restore_runner = new RestoreRunner(
			new EntryReader( CodecRegistry::with_defaults() ),
			new FileWriter( $this->restore_root ),
			new DatabaseWriter( new WpdbAdapter( $wpdb ) )
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Opening the just-written archive to restore it for real.
		$source = fopen( $this->archive_path, 'rb' );
		if ( false === $source ) {
			$this->fail( 'Could not open the written archive for restore.' );
		}
		try {
			$restore_runner->restore( $source );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the archive stream opened in this test.
			fclose( $source );
		}

		foreach ( $expected_files as $relative_path ) {
			$original = $this->fixture_root . '/' . $relative_path;
			$restored = $this->restore_root . '/' . $relative_path;
			$this->assertFileExists( $restored, sprintf( 'Restored file missing: %s', $relative_path ) );
			$this->assertSame(
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading fixture/restored files for a byte-equality assertion.
				file_get_contents( $original ),
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading fixture/restored files for a byte-equality assertion.
				file_get_contents( $restored ),
				sprintf( 'Restored content differs from the source file: %s', $relative_path )
			);
		}
	}

	// -------------------------------------------------------------------------
	// The independent oracle: a plain filesystem walk, deliberately NOT via FileScanner.
	// -------------------------------------------------------------------------

	/**
	 * Walk a directory tree with a plain iterator (no FileScanner involved) and
	 * return every relative path of the given kind that survives the exclusion
	 * rules.
	 *
	 * Deliberately independent of {@see FileScanner}: it uses
	 * RecursiveDirectoryIterator/RecursiveIteratorIterator with no pruning
	 * callback, so a regression in FileScanner's own traversal or pruning logic
	 * (the exact defect class that shipped in v0.9.3) cannot also corrupt this
	 * expectation. {@see ExclusionRules::matches()} is reused as a pure
	 * predicate — not the pruning mechanism, only the pattern-matching rule —
	 * applied to every ancestor of a path as well as the path itself, so that a
	 * file beneath an excluded directory is excluded even though this walker
	 * never stops descending into excluded directories the way FileScanner does.
	 *
	 * @param string         $root        Absolute path of the tree to walk.
	 * @param ExclusionRules $rules       Exclusion rules to apply.
	 * @param string         $kind_filter One of EntryHeader::KIND_FILE or KIND_DIRECTORY; only paths of this kind are returned.
	 * @return string[] Relative paths (forward-slash separated), unsorted.
	 */
	private static function independent_walk( string $root, ExclusionRules $rules, string $kind_filter ): array {
		$normalised_root = rtrim( $root, '/\\' );
		$prefix_len      = strlen( $normalised_root ) + 1;

		$flags    = RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::UNIX_PATHS;
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $normalised_root, $flags ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		$paths = array();
		foreach ( $iterator as $info ) {
			/**
			 * The iterator's view of the current item.
			 *
			 * @var SplFileInfo $info
			 */
			$absolute = $info->getPathname();
			$relative = str_replace( '\\', '/', substr( $absolute, $prefix_len ) );
			$kind     = $info->isDir() ? EntryHeader::KIND_DIRECTORY : EntryHeader::KIND_FILE;

			if ( self::excluded_by_self_or_ancestor( $relative, $kind, $rules ) ) {
				continue;
			}
			if ( $kind_filter !== $kind ) {
				continue;
			}
			$paths[] = $relative;
		}

		return $paths;
	}

	/**
	 * Whether a path, or any directory ancestor of it, matches the exclusion rules.
	 *
	 * Mirrors FileScanner's "an excluded directory is never entered" contract —
	 * so anything beneath an excluded directory is excluded too — without
	 * sharing FileScanner's pruning code: each path prefix is tested in turn as
	 * its own independent call to {@see ExclusionRules::matches()}.
	 *
	 * @param string         $relative_path Path relative to the walked root.
	 * @param string         $leaf_kind     The kind of $relative_path itself (its ancestors are always directories).
	 * @param ExclusionRules $rules         Exclusion rules to test against.
	 * @return bool True if $relative_path or an ancestor of it is excluded.
	 */
	private static function excluded_by_self_or_ancestor( string $relative_path, string $leaf_kind, ExclusionRules $rules ): bool {
		$segments    = explode( '/', $relative_path );
		$last_index  = count( $segments ) - 1;
		$accumulated = '';
		foreach ( $segments as $index => $segment ) {
			$accumulated = '' === $accumulated ? $segment : $accumulated . '/' . $segment;
			$kind        = $last_index === $index ? $leaf_kind : EntryHeader::KIND_DIRECTORY;
			if ( $rules->matches( $accumulated, $kind ) ) {
				return true;
			}
		}
		return false;
	}

	// -------------------------------------------------------------------------
	// Archive-reading and fixture helpers.
	// -------------------------------------------------------------------------

	/**
	 * Open a written archive and return its file and directory paths, by kind.
	 *
	 * @param string $path Absolute path to the archive.
	 * @return array{file: string[], directory: string[]} Paths keyed by EntryHeader::KIND_FILE / KIND_DIRECTORY; db_chunk entries carry no path and are skipped.
	 * @throws RuntimeException If the archive cannot be opened for reading.
	 */
	private static function archive_paths_by_kind( string $path ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Opening a just-written archive to read its manifest back.
		$source = fopen( $path, 'rb' );
		if ( false === $source ) {
			throw new RuntimeException( 'Could not open the written archive for reading.' );
		}
		try {
			$reader = new ArchiveReader( $source );
			$paths  = array(
				EntryHeader::KIND_FILE      => array(),
				EntryHeader::KIND_DIRECTORY => array(),
			);
			foreach ( $reader->manifest()->entries() as $entry ) {
				$entry_path = $entry->path();
				if ( null === $entry_path ) {
					continue;
				}
				if ( isset( $paths[ $entry->kind() ] ) ) {
					$paths[ $entry->kind() ][] = $entry_path;
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
	 * @return void
	 */
	private function write_fixture_file( string $relative, string $contents ): void {
		$absolute = $this->fixture_root . '/' . $relative;
		$dir      = dirname( $absolute );
		if ( ! is_dir( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating a fixture file's parent directory in the temp tree.
			mkdir( $dir, 0o755, true );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing a fixture file into the temp tree.
		file_put_contents( $absolute, $contents );
	}

	/**
	 * Recursively delete a directory tree.
	 *
	 * @param string $path Absolute path to remove.
	 * @return void
	 */
	private static function rmtree( string $path ): void {
		if ( is_link( $path ) || ! is_dir( $path ) ) {
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
