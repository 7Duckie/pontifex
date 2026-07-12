<?php
/**
 * Unit tests for the ExclusionRules class.
 *
 * @package Pontifex\Tests\Unit\Manifest
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Manifest;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Manifest\ExclusionRules;

/**
 * Tests for {@see ExclusionRules}.
 *
 * Covers the four pattern types (exact, glob, regex, directory-tree),
 * the three named factories (none, default_v010, from_array), input
 * validation, and the widened kind acceptance set (file, directory,
 * symlink, db_chunk).
 */
final class ExclusionRulesTest extends TestCase {

	/**
	 * The none() factory must produce an instance that excludes nothing.
	 *
	 * @return void
	 */
	public function test_none_factory_excludes_nothing(): void {
		$rules = ExclusionRules::none();

		$this->assertFalse( $rules->matches( 'wp-config.php', EntryHeader::KIND_FILE ) );
		$this->assertFalse( $rules->matches( 'wp-content', EntryHeader::KIND_DIRECTORY ) );
		$this->assertFalse( $rules->matches( 'wp-content/uploads', EntryHeader::KIND_SYMLINK ) );
	}

	/**
	 * The constructor must accept an empty patterns array.
	 *
	 * @return void
	 */
	public function test_constructor_accepts_empty_patterns(): void {
		$rules = new ExclusionRules( array() );

		$this->assertSame( array(), $rules->patterns() );
	}

	/**
	 * The constructor must accept an array of string patterns.
	 *
	 * @return void
	 */
	public function test_constructor_accepts_string_patterns(): void {
		$patterns = array( 'wp-content/cache/**', '*.tmp' );
		$rules    = new ExclusionRules( $patterns );

		$this->assertSame( $patterns, $rules->patterns() );
	}

	/**
	 * The constructor must reject a non-string element in the patterns array.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_non_string_pattern(): void {
		$this->expectException( InvalidArgumentException::class );

		new ExclusionRules( array( 'wp-content/cache/**', 42 ) );
	}

	/**
	 * The matches method must reject an empty relative_path.
	 *
	 * @return void
	 */
	public function test_matches_rejects_empty_relative_path(): void {
		$this->expectException( InvalidArgumentException::class );

		ExclusionRules::none()->matches( '', EntryHeader::KIND_FILE );
	}

	/**
	 * The matches method must reject an unrecognised kind.
	 *
	 * @return void
	 */
	public function test_matches_rejects_unknown_kind(): void {
		$this->expectException( InvalidArgumentException::class );

		ExclusionRules::none()->matches( 'a.txt', 'mystery_kind' );
	}

	/**
	 * The matches method must accept the db_chunk kind (used by DatabaseScanner).
	 *
	 * @return void
	 */
	public function test_matches_accepts_db_chunk_kind(): void {
		$rules = new ExclusionRules( array( 'wp_postmeta' ) );

		$this->assertTrue( $rules->matches( 'wp_postmeta', EntryHeader::KIND_DB_CHUNK ) );
		$this->assertFalse( $rules->matches( 'wp_posts', EntryHeader::KIND_DB_CHUNK ) );
	}

	/**
	 * An exact-string pattern must match the same path exactly.
	 *
	 * @return void
	 */
	public function test_exact_pattern_matches_same_path(): void {
		$rules = new ExclusionRules( array( 'wp-content/cache' ) );

		$this->assertTrue( $rules->matches( 'wp-content/cache', EntryHeader::KIND_DIRECTORY ) );
	}

	/**
	 * An exact-string pattern must not match a different path.
	 *
	 * @return void
	 */
	public function test_exact_pattern_does_not_match_different_path(): void {
		$rules = new ExclusionRules( array( 'wp-content/cache' ) );

		$this->assertFalse( $rules->matches( 'wp-content/uploads', EntryHeader::KIND_DIRECTORY ) );
	}

	/**
	 * Exact-string patterns must NOT match children of the named path.
	 *
	 * @return void
	 */
	public function test_exact_pattern_does_not_match_children(): void {
		$rules = new ExclusionRules( array( 'wp-content/cache' ) );

		// "wp-content/cache" is exact; "wp-content/cache/foo.html" is a child and must NOT match.
		$this->assertFalse( $rules->matches( 'wp-content/cache/foo.html', EntryHeader::KIND_FILE ) );
	}

	/**
	 * Multiple patterns must all be considered.
	 *
	 * @return void
	 */
	public function test_multiple_patterns_are_all_considered(): void {
		$rules = new ExclusionRules( array( 'foo.txt', 'bar.txt', 'baz.txt' ) );

		$this->assertTrue( $rules->matches( 'foo.txt', EntryHeader::KIND_FILE ) );
		$this->assertTrue( $rules->matches( 'bar.txt', EntryHeader::KIND_FILE ) );
		$this->assertTrue( $rules->matches( 'baz.txt', EntryHeader::KIND_FILE ) );
		$this->assertFalse( $rules->matches( 'qux.txt', EntryHeader::KIND_FILE ) );
	}

	/**
	 * A glob pattern with "*" must match a same-segment path.
	 *
	 * @return void
	 */
	public function test_glob_star_matches_same_segment(): void {
		$rules = new ExclusionRules( array( '*.tmp' ) );

		$this->assertTrue( $rules->matches( 'scratch.tmp', EntryHeader::KIND_FILE ) );
	}

	/**
	 * A glob pattern with "*" must NOT cross slashes (FNM_PATHNAME semantics).
	 *
	 * @return void
	 */
	public function test_glob_star_does_not_cross_slashes(): void {
		$rules = new ExclusionRules( array( '*.tmp' ) );

		// "sub/scratch.tmp" should not be matched by "*.tmp" because * is path-bounded.
		$this->assertFalse( $rules->matches( 'sub/scratch.tmp', EntryHeader::KIND_FILE ) );
	}

	/**
	 * A glob pattern with "?" must match exactly one character.
	 *
	 * @return void
	 */
	public function test_glob_question_mark_matches_single_character(): void {
		$rules = new ExclusionRules( array( 'fil?.txt' ) );

		$this->assertTrue( $rules->matches( 'file.txt', EntryHeader::KIND_FILE ) );
		$this->assertFalse( $rules->matches( 'fi.txt', EntryHeader::KIND_FILE ) );
	}

	/**
	 * A directory-tree pattern must match the directory itself.
	 *
	 * @return void
	 */
	public function test_tree_pattern_matches_directory_itself(): void {
		$rules = new ExclusionRules( array( 'wp-content/cache/**' ) );

		$this->assertTrue( $rules->matches( 'wp-content/cache', EntryHeader::KIND_DIRECTORY ) );
	}

	/**
	 * A directory-tree pattern must match immediate children.
	 *
	 * @return void
	 */
	public function test_tree_pattern_matches_immediate_children(): void {
		$rules = new ExclusionRules( array( 'wp-content/cache/**' ) );

		$this->assertTrue( $rules->matches( 'wp-content/cache/foo.html', EntryHeader::KIND_FILE ) );
	}

	/**
	 * A directory-tree pattern must match deeply nested descendants.
	 *
	 * @return void
	 */
	public function test_tree_pattern_matches_deep_descendants(): void {
		$rules = new ExclusionRules( array( 'wp-content/cache/**' ) );

		$this->assertTrue( $rules->matches( 'wp-content/cache/sub/sub/file.css', EntryHeader::KIND_FILE ) );
	}

	/**
	 * A directory-tree pattern must NOT match a similarly-named sibling directory.
	 *
	 * @return void
	 */
	public function test_tree_pattern_does_not_match_similarly_named_sibling(): void {
		$rules = new ExclusionRules( array( 'wp-content/cache/**' ) );

		// "wp-content/cache-old" must NOT match "wp-content/cache/**" — only the exact directory and its tree.
		$this->assertFalse( $rules->matches( 'wp-content/cache-old', EntryHeader::KIND_DIRECTORY ) );
		$this->assertFalse( $rules->matches( 'wp-content/cache-old/foo', EntryHeader::KIND_FILE ) );
	}

	/**
	 * A directory-tree pattern with a glob in its prefix must match any matching tree.
	 *
	 * @return void
	 */
	public function test_tree_pattern_with_glob_prefix_matches_multiple_trees(): void {
		$rules = new ExclusionRules( array( 'wp-content/snapshots-*/**' ) );

		$this->assertTrue( $rules->matches( 'wp-content/snapshots-1234', EntryHeader::KIND_DIRECTORY ) );
		$this->assertTrue( $rules->matches( 'wp-content/snapshots-1234/log.txt', EntryHeader::KIND_FILE ) );
		$this->assertTrue( $rules->matches( 'wp-content/snapshots-abc', EntryHeader::KIND_DIRECTORY ) );
		$this->assertFalse( $rules->matches( 'wp-content/uploads', EntryHeader::KIND_DIRECTORY ) );
	}

	/**
	 * A regex pattern must match using PCRE semantics.
	 *
	 * @return void
	 */
	public function test_regex_pattern_matches_via_pcre(): void {
		$rules = new ExclusionRules( array( '/\.swp$/' ) );

		$this->assertTrue( $rules->matches( 'wp-config.php.swp', EntryHeader::KIND_FILE ) );
		$this->assertFalse( $rules->matches( 'wp-config.php', EntryHeader::KIND_FILE ) );
	}

	/**
	 * A malformed regex pattern must throw InvalidArgumentException at match time.
	 *
	 * @return void
	 */
	public function test_malformed_regex_pattern_throws(): void {
		$rules = new ExclusionRules( array( '/[/' ) );

		$this->expectException( InvalidArgumentException::class );

		$rules->matches( 'foo', EntryHeader::KIND_FILE );
	}

	/**
	 * The default_v010 factory must include Pontifex's own working directory pattern.
	 *
	 * @return void
	 */
	public function test_default_v010_includes_pontifex_working_dir(): void {
		$patterns = ExclusionRules::default_v010()->patterns();

		$this->assertContains( 'wp-content/pontifex/**', $patterns );
	}

	/**
	 * The default_v010 factory must include WordPress's own cache directory pattern.
	 *
	 * @return void
	 */
	public function test_default_v010_includes_wp_cache_dir(): void {
		$patterns = ExclusionRules::default_v010()->patterns();

		$this->assertContains( 'wp-content/cache/**', $patterns );
	}

	/**
	 * The default_v010 factory must hold only the two structural exclusions.
	 *
	 * The curated defaults were trimmed (ADR 0008) to just Pontifex's own working
	 * directory and WordPress's regenerable cache; anything else a site holds is
	 * the owner's data and is kept by default.
	 *
	 * @return void
	 */
	public function test_default_v010_holds_only_the_two_structural_exclusions(): void {
		$patterns = ExclusionRules::default_v010()->patterns();

		$this->assertSame(
			array( 'wp-content/pontifex/**', 'wp-content/cache/**' ),
			$patterns
		);
	}

	/**
	 * The default_v010 factory must no longer exclude other tools' working directories.
	 *
	 * Whatever data another plugin has written under wp-content is the site owner's
	 * data; Pontifex keeps it rather than deciding on their behalf to drop it. The
	 * directory names below are illustrative — the point is that an arbitrary
	 * plugin-data directory is not excluded. Defends against a regression that
	 * reinstates a curated drop-list.
	 *
	 * @return void
	 */
	public function test_default_v010_keeps_other_plugin_directories(): void {
		$rules = ExclusionRules::default_v010();

		$this->assertFalse( $rules->matches( 'wp-content/some-backup-plugin/backup-2026-01-01.zip', EntryHeader::KIND_FILE ) );
		$this->assertFalse( $rules->matches( 'wp-content/another-plugins-data/site.dat', EntryHeader::KIND_FILE ) );
		$this->assertFalse( $rules->matches( 'wp-content/backups-abc123', EntryHeader::KIND_DIRECTORY ) );
	}

	/**
	 * The default_v010 factory must produce matches against typical excluded paths.
	 *
	 * @return void
	 */
	public function test_default_v010_excludes_typical_paths(): void {
		$rules = ExclusionRules::default_v010();

		$this->assertTrue( $rules->matches( 'wp-content/pontifex', EntryHeader::KIND_DIRECTORY ) );
		$this->assertTrue( $rules->matches( 'wp-content/pontifex/logs/2026.log', EntryHeader::KIND_FILE ) );
		$this->assertTrue( $rules->matches( 'wp-content/cache/page/index.html', EntryHeader::KIND_FILE ) );
	}

	/**
	 * The default_v010 factory must NOT exclude typical site content.
	 *
	 * @return void
	 */
	public function test_default_v010_does_not_exclude_site_content(): void {
		$rules = ExclusionRules::default_v010();

		$this->assertFalse( $rules->matches( 'wp-config.php', EntryHeader::KIND_FILE ) );
		$this->assertFalse( $rules->matches( 'wp-content/uploads/2026/05/image.jpg', EntryHeader::KIND_FILE ) );
		$this->assertFalse( $rules->matches( 'wp-content/themes/twentytwentyfour/style.css', EntryHeader::KIND_FILE ) );
		$this->assertFalse( $rules->matches( 'wp-content/plugins/akismet/akismet.php', EntryHeader::KIND_FILE ) );
	}

	/**
	 * The from_array factory must produce a rule set equivalent to construction.
	 *
	 * @return void
	 */
	public function test_from_array_factory_is_equivalent_to_constructor(): void {
		$patterns = array( 'a.txt', '*.tmp' );
		$rules    = ExclusionRules::from_array( $patterns );

		$this->assertSame( $patterns, $rules->patterns() );
		$this->assertTrue( $rules->matches( 'a.txt', EntryHeader::KIND_FILE ) );
		$this->assertTrue( $rules->matches( 'scratch.tmp', EntryHeader::KIND_FILE ) );
	}

	/**
	 * The from_array factory must reject a non-string pattern entry.
	 *
	 * @return void
	 */
	public function test_from_array_factory_rejects_non_string_pattern(): void {
		$this->expectException( InvalidArgumentException::class );

		ExclusionRules::from_array( array( 'valid', 42 ) );
	}
}
