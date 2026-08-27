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
use Pontifex\Manifest\ExclusionPattern;
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
	 * The default_v010 factory must include the .git-at-any-depth regex pattern.
	 *
	 * Added in v0.9.3 (ADR 0008 amendment): version-control metadata is not site
	 * content, so it is excluded like the other two structural defaults.
	 *
	 * @return void
	 */
	public function test_default_v010_includes_git_exclusion(): void {
		$patterns = ExclusionRules::default_v010()->patterns();

		$this->assertContains( '/(^|\/)\.git(\/|$)/', $patterns );
	}

	/**
	 * The default_v010 factory must hold only the three structural exclusions.
	 *
	 * The curated defaults were trimmed (ADR 0008) to Pontifex's own working
	 * directory and WordPress's regenerable cache, then grew a third entry
	 * (ADR 0008 amendment, v0.9.3): version-control metadata (`.git`), which is
	 * not site content either. Anything else a site holds is the owner's data
	 * and is kept by default.
	 *
	 * @return void
	 */
	public function test_default_v010_holds_only_the_three_structural_exclusions(): void {
		$patterns = ExclusionRules::default_v010()->patterns();

		$this->assertSame(
			array( 'wp-content/pontifex/**', 'wp-content/cache/**', '/(^|\/)\.git(\/|$)/' ),
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
	 * The default_v010 factory must exclude a `.git` directory at the scan root.
	 *
	 * @return void
	 */
	public function test_default_v010_excludes_git_at_the_scan_root(): void {
		$rules = ExclusionRules::default_v010();

		$this->assertTrue( $rules->matches( '.git', EntryHeader::KIND_DIRECTORY ) );
		$this->assertTrue( $rules->matches( '.git/config', EntryHeader::KIND_FILE ) );
	}

	/**
	 * The default_v010 factory must exclude a `.git` directory one level deep.
	 *
	 * The depth a whole-site or content-only scan actually sees for a
	 * git-deployed wp-content directory.
	 *
	 * @return void
	 */
	public function test_default_v010_excludes_git_one_level_deep(): void {
		$rules = ExclusionRules::default_v010();

		$this->assertTrue( $rules->matches( 'wp-content/.git', EntryHeader::KIND_DIRECTORY ) );
		$this->assertTrue( $rules->matches( 'wp-content/.git/HEAD', EntryHeader::KIND_FILE ) );
	}

	/**
	 * The default_v010 factory must exclude a `.git` directory nested inside a plugin or theme.
	 *
	 * This is the case a "**\/.git/**" glob would silently miss (fnmatch() has
	 * no globstar and FNM_PATHNAME stops "*" at "/"); only a regex matches at
	 * every depth, which is why default_v010() uses one for this entry.
	 *
	 * @return void
	 */
	public function test_default_v010_excludes_git_nested_in_a_plugin(): void {
		$rules = ExclusionRules::default_v010();

		$this->assertTrue( $rules->matches( 'wp-content/plugins/foo/.git', EntryHeader::KIND_DIRECTORY ) );
		$this->assertTrue( $rules->matches( 'wp-content/plugins/foo/.git/objects/ab/cdef', EntryHeader::KIND_FILE ) );
	}

	/**
	 * The default_v010 factory must exclude `.git` even when it is a FILE, not a directory.
	 *
	 * A git submodule or a worktree records ".git" as a one-line gitdir pointer
	 * file, not a directory. {@see ExclusionRules::matches()} validates $kind but
	 * never conditions the match on it, so the same pattern catches both shapes.
	 *
	 * @return void
	 */
	public function test_default_v010_excludes_git_when_it_is_a_file(): void {
		$rules = ExclusionRules::default_v010();

		$this->assertTrue( $rules->matches( 'wp-content/plugins/foo/.git', EntryHeader::KIND_FILE ) );
	}

	/**
	 * The default_v010 factory must NOT exclude paths that merely resemble `.git`.
	 *
	 * Defends against an over-eager pattern: a `.gitignore` file, a `.github`
	 * directory, a coincidentally-named "mygit" upload folder, and a filename
	 * that merely contains the substring "git." must all survive.
	 *
	 * @return void
	 */
	public function test_default_v010_does_not_exclude_git_lookalikes(): void {
		$rules = ExclusionRules::default_v010();

		$this->assertFalse( $rules->matches( '.gitignore', EntryHeader::KIND_FILE ) );
		$this->assertFalse( $rules->matches( '.github/workflows/ci.yml', EntryHeader::KIND_FILE ) );
		$this->assertFalse( $rules->matches( 'wp-content/uploads/mygit/x.txt', EntryHeader::KIND_FILE ) );
		$this->assertFalse( $rules->matches( 'notes.git.txt', EntryHeader::KIND_FILE ) );
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

	// -------------------------------------------------------------------------
	// from_tagged_patterns: kind scope, anchoring, and match counts.
	// -------------------------------------------------------------------------

	/**
	 * The from_tagged_patterns factory must reject an element that is not an ExclusionPattern.
	 *
	 * @return void
	 */
	public function test_from_tagged_patterns_rejects_non_exclusion_pattern(): void {
		$this->expectException( InvalidArgumentException::class );

		// @phpstan-ignore-next-line -- deliberately passing the wrong type to prove the guard.
		ExclusionRules::from_tagged_patterns( array( ExclusionPattern::untagged( 'valid' ), 'not-a-pattern' ) );
	}

	/**
	 * The from_tagged_patterns factory must produce patterns() equivalent to the raw pattern text.
	 *
	 * @return void
	 */
	public function test_from_tagged_patterns_patterns_view_is_raw_text_only(): void {
		$rules = ExclusionRules::from_tagged_patterns(
			array(
				ExclusionPattern::operator_file( '/comments/' ),
				ExclusionPattern::operator_table( 'wp_actionscheduler_*' ),
			)
		);

		$this->assertSame( array( '/comments/', 'wp_actionscheduler_*' ), $rules->patterns() );
	}

	/**
	 * Headline case: a file-scoped exclusion pattern must never exclude a database table,
	 * while it still excludes a matching file path.
	 *
	 * Typing "/comments/" as a file exclusion, meaning "skip the comments
	 * folder", must not silently drop the wp_comments table from the backup.
	 *
	 * @return void
	 */
	public function test_file_scoped_pattern_does_not_exclude_a_table(): void {
		$rules = ExclusionRules::from_tagged_patterns(
			array( ExclusionPattern::operator_file( '/comments/' ) )
		);

		$this->assertFalse( $rules->matches( 'wp_comments', EntryHeader::KIND_DB_CHUNK ) );
		$this->assertTrue( $rules->matches( 'comments/2026-01.html', EntryHeader::KIND_FILE ) );
	}

	/**
	 * A table-scoped exclusion pattern must never exclude a file, even one whose
	 * name it would otherwise match.
	 *
	 * @return void
	 */
	public function test_table_scoped_pattern_does_not_exclude_a_file(): void {
		$rules = ExclusionRules::from_tagged_patterns(
			array( ExclusionPattern::operator_table( 'wp_actionscheduler_*' ) )
		);

		$this->assertTrue( $rules->matches( 'wp_actionscheduler_logs', EntryHeader::KIND_DB_CHUNK ) );
		$this->assertFalse( $rules->matches( 'wp_actionscheduler_logs', EntryHeader::KIND_FILE ) );
	}

	/**
	 * An operator-supplied regex pattern is anchored to the start of the name:
	 * "/log/" must stop matching a name that merely contains "log" partway through.
	 *
	 * @return void
	 */
	public function test_operator_regex_no_longer_matches_mid_name(): void {
		$rules = ExclusionRules::from_tagged_patterns(
			array( ExclusionPattern::operator_file( '/log/' ) )
		);

		$this->assertFalse( $rules->matches( 'blogmap.php', EntryHeader::KIND_FILE ) );
	}

	/**
	 * A self-anchored operator regex ("/^blog/") still matches at the start of the name.
	 *
	 * Anchoring restricts WHERE a match may begin; it does not stop a pattern
	 * that already anchors itself from matching exactly as it always would.
	 *
	 * @return void
	 */
	public function test_operator_regex_self_anchored_still_matches(): void {
		$rules = ExclusionRules::from_tagged_patterns(
			array( ExclusionPattern::operator_file( '/^blog/' ) )
		);

		$this->assertTrue( $rules->matches( 'blogmap.php', EntryHeader::KIND_FILE ) );
	}

	/**
	 * Pontifex's own untagged `.git`-at-any-depth default must keep matching at
	 * any depth even when combined with an anchored operator pattern.
	 *
	 * This is the regression the brief calls out directly: the built-in default
	 * must be unaffected by whatever anchoring an operator's own pattern gets,
	 * however similar in shape the two patterns are.
	 *
	 * @return void
	 */
	public function test_untagged_git_default_matches_at_any_depth_alongside_operator_patterns(): void {
		$rules = ExclusionRules::from_tagged_patterns(
			array(
				ExclusionPattern::untagged( '/(^|\/)\.git(\/|$)/' ),
				ExclusionPattern::operator_file( '/log/' ),
			)
		);

		$this->assertTrue( $rules->matches( 'wp-content/plugins/foo/.git/config', EntryHeader::KIND_FILE ) );
	}

	/**
	 * Anchoring an operator pattern only changes the regex shape; the other
	 * three pattern shapes (directory-tree, glob, exact string) behave exactly
	 * as they do for an untagged pattern.
	 *
	 * @return void
	 */
	public function test_operator_pattern_other_three_shapes_unchanged(): void {
		$rules = ExclusionRules::from_tagged_patterns(
			array(
				ExclusionPattern::operator_file( 'wp-content/cache/**' ),
				ExclusionPattern::operator_file( '*.tmp' ),
				ExclusionPattern::operator_file( 'wp-config-sample.php' ),
			)
		);

		// Directory-tree: matches the directory and anything beneath it.
		$this->assertTrue( $rules->matches( 'wp-content/cache', EntryHeader::KIND_DIRECTORY ) );
		$this->assertTrue( $rules->matches( 'wp-content/cache/sub/file.css', EntryHeader::KIND_FILE ) );
		$this->assertFalse( $rules->matches( 'wp-content/cache-old', EntryHeader::KIND_DIRECTORY ) );

		// Glob: "*" stays path-bounded.
		$this->assertTrue( $rules->matches( 'scratch.tmp', EntryHeader::KIND_FILE ) );
		$this->assertFalse( $rules->matches( 'sub/scratch.tmp', EntryHeader::KIND_FILE ) );

		// Exact string: matches only the same path.
		$this->assertTrue( $rules->matches( 'wp-config-sample.php', EntryHeader::KIND_FILE ) );
		$this->assertFalse( $rules->matches( 'wp-config-sample.php.bak', EntryHeader::KIND_FILE ) );
	}

	/**
	 * A fresh rule set reports a zero match count for every pattern before matches() runs.
	 *
	 * @return void
	 */
	public function test_match_counts_start_at_zero(): void {
		$rules = ExclusionRules::from_tagged_patterns(
			array(
				ExclusionPattern::operator_file( 'a.txt' ),
				ExclusionPattern::operator_file( 'b.txt' ),
			)
		);

		$this->assertSame(
			array(
				array(
					'pattern' => 'a.txt',
					'count'   => 0,
				),
				array(
					'pattern' => 'b.txt',
					'count'   => 0,
				),
			),
			$rules->match_counts()
		);
	}

	/**
	 * Each pattern's match count reflects exactly how many times it excluded
	 * something: a pattern matched three times reports 3; a pattern that never
	 * matched reports 0, not an absent entry.
	 *
	 * @return void
	 */
	public function test_match_counts_reflect_actual_matches_per_pattern(): void {
		$rules = ExclusionRules::from_tagged_patterns(
			array(
				ExclusionPattern::operator_file( 'wp-content/cache/**' ),
				ExclusionPattern::operator_file( 'never-matches.txt' ),
			)
		);

		$rules->matches( 'wp-content/cache', EntryHeader::KIND_DIRECTORY );
		$rules->matches( 'wp-content/cache/one.html', EntryHeader::KIND_FILE );
		$rules->matches( 'wp-content/cache/two.html', EntryHeader::KIND_FILE );
		// A path that matches neither pattern must not increment anything.
		$rules->matches( 'wp-content/uploads/photo.jpg', EntryHeader::KIND_FILE );

		$this->assertSame(
			array(
				array(
					'pattern' => 'wp-content/cache/**',
					'count'   => 3,
				),
				array(
					'pattern' => 'never-matches.txt',
					'count'   => 0,
				),
			),
			$rules->match_counts()
		);
	}

	/**
	 * The match_counts() method only credits the FIRST pattern that matched a given path —
	 * matches() stops at the first match, so a later pattern that would also
	 * have matched is never reached for that call.
	 *
	 * @return void
	 */
	public function test_match_counts_credit_only_the_first_matching_pattern(): void {
		$rules = ExclusionRules::from_tagged_patterns(
			array(
				ExclusionPattern::operator_file( 'wp-content/cache/**' ),
				ExclusionPattern::operator_file( '*.html' ),
			)
		);

		$rules->matches( 'wp-content/cache/one.html', EntryHeader::KIND_FILE );

		$counts = $rules->match_counts();
		$this->assertSame( 1, $counts[0]['count'] );
		$this->assertSame( 0, $counts[1]['count'] );
	}

	// -------------------------------------------------------------------------
	// from_tagged_patterns: de-duplicating an entry a preceding one already
	// covers (the measured defect — see ExclusionPattern::covers()).
	// -------------------------------------------------------------------------

	/**
	 * Headline case, reproducing the measured defect and proving it fixed: a
	 * curated default followed by an operator file pattern with identical
	 * text must collapse to ONE entry, and after a match that surviving
	 * entry's count is 1 — not the pre-fix shape, where the default silently
	 * took the credit and the operator's own, working pattern reported 0.
	 *
	 * @return void
	 */
	public function test_identical_default_and_operator_file_pattern_collapse_to_one_credited_entry(): void {
		$rules = ExclusionRules::from_tagged_patterns(
			array(
				ExclusionPattern::untagged( 'wp-content/cache/**' ),
				ExclusionPattern::operator_file( 'wp-content/cache/**' ),
			)
		);

		$this->assertCount( 1, $rules->entries() );
		$this->assertSame( array( 'wp-content/cache/**' ), $rules->patterns() );

		$this->assertTrue( $rules->matches( 'wp-content/cache/one.html', EntryHeader::KIND_FILE ) );

		$counts = $rules->match_counts();
		$this->assertSame( 1, $counts[0]['count'] );
	}

	/**
	 * Regression guard for the mistake this brief exists to prevent: the same
	 * text given once as an operator FILE pattern and once as an operator
	 * TABLE pattern is two genuinely different rules (a `wp_comments` file
	 * and the `wp_comments` table) and must NOT collapse — both entries
	 * survive, and each is credited only for matches of its own kind.
	 *
	 * @return void
	 */
	public function test_same_text_as_file_and_table_pattern_produces_two_independently_credited_entries(): void {
		$rules = ExclusionRules::from_tagged_patterns(
			array(
				ExclusionPattern::operator_file( 'wp_comments' ),
				ExclusionPattern::operator_table( 'wp_comments' ),
			)
		);

		$this->assertCount( 2, $rules->entries() );
		$this->assertSame( array( 'wp_comments', 'wp_comments' ), $rules->patterns() );

		$this->assertTrue( $rules->matches( 'wp_comments', EntryHeader::KIND_FILE ) );
		$this->assertTrue( $rules->matches( 'wp_comments', EntryHeader::KIND_DB_CHUNK ) );

		$counts = $rules->match_counts();
		$this->assertSame( 1, $counts[0]['count'] );
		$this->assertSame( 1, $counts[1]['count'] );
	}

	/**
	 * De-duplication keeps the first occurrence, preserves the order of every
	 * other entry, and leaves patterns with no duplicate untouched.
	 *
	 * @return void
	 */
	public function test_deduplication_preserves_order_and_leaves_unrelated_patterns_untouched(): void {
		$rules = ExclusionRules::from_tagged_patterns(
			array(
				ExclusionPattern::untagged( 'wp-content/pontifex/**' ),
				ExclusionPattern::untagged( 'wp-content/cache/**' ),
				ExclusionPattern::operator_file( 'wp-content/cache/**' ),
				ExclusionPattern::operator_table( 'wp_actionscheduler_*' ),
			)
		);

		$this->assertSame(
			array( 'wp-content/pontifex/**', 'wp-content/cache/**', 'wp_actionscheduler_*' ),
			$rules->patterns()
		);
	}
}
