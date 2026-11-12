<?php
/**
 * Unit tests for the ExclusionPattern class.
 *
 * @package Pontifex\Tests\Unit\Manifest
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Manifest;

use PHPUnit\Framework\TestCase;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Manifest\ExclusionPattern;

/**
 * Tests for {@see ExclusionPattern}.
 *
 * Covers the three factories (untagged, operator_file, operator_table),
 * their scope and anchoring, and applies_to_kind() against all four
 * EntryHeader kinds.
 */
final class ExclusionPatternTest extends TestCase {

	/**
	 * The untagged() factory must report the ANY scope and be unanchored.
	 *
	 * @return void
	 */
	public function test_untagged_reports_any_scope_and_unanchored(): void {
		$entry = ExclusionPattern::untagged( '/(^|\/)\.git(\/|$)/' );

		$this->assertSame( '/(^|\/)\.git(\/|$)/', $entry->pattern() );
		$this->assertSame( ExclusionPattern::SCOPE_ANY, $entry->scope() );
		$this->assertFalse( $entry->is_anchored() );
	}

	/**
	 * The operator_file() factory must report the FILE scope and be anchored.
	 *
	 * @return void
	 */
	public function test_operator_file_reports_file_scope_and_anchored(): void {
		$entry = ExclusionPattern::operator_file( '/comments/' );

		$this->assertSame( '/comments/', $entry->pattern() );
		$this->assertSame( ExclusionPattern::SCOPE_FILE, $entry->scope() );
		$this->assertTrue( $entry->is_anchored() );
	}

	/**
	 * The operator_table() factory must report the TABLE scope and be anchored.
	 *
	 * @return void
	 */
	public function test_operator_table_reports_table_scope_and_anchored(): void {
		$entry = ExclusionPattern::operator_table( 'wp_actionscheduler_*' );

		$this->assertSame( 'wp_actionscheduler_*', $entry->pattern() );
		$this->assertSame( ExclusionPattern::SCOPE_TABLE, $entry->scope() );
		$this->assertTrue( $entry->is_anchored() );
	}

	/**
	 * An untagged (ANY-scope) pattern applies to every one of the four entry kinds.
	 *
	 * @return void
	 */
	public function test_untagged_applies_to_every_kind(): void {
		$entry = ExclusionPattern::untagged( 'anything' );

		$this->assertTrue( $entry->applies_to_kind( EntryHeader::KIND_FILE ) );
		$this->assertTrue( $entry->applies_to_kind( EntryHeader::KIND_DIRECTORY ) );
		$this->assertTrue( $entry->applies_to_kind( EntryHeader::KIND_SYMLINK ) );
		$this->assertTrue( $entry->applies_to_kind( EntryHeader::KIND_DB_CHUNK ) );
	}

	/**
	 * A FILE-scope pattern applies to file, directory, and symlink kinds, never db_chunk.
	 *
	 * @return void
	 */
	public function test_operator_file_applies_only_to_file_shaped_kinds(): void {
		$entry = ExclusionPattern::operator_file( 'anything' );

		$this->assertTrue( $entry->applies_to_kind( EntryHeader::KIND_FILE ) );
		$this->assertTrue( $entry->applies_to_kind( EntryHeader::KIND_DIRECTORY ) );
		$this->assertTrue( $entry->applies_to_kind( EntryHeader::KIND_SYMLINK ) );
		$this->assertFalse( $entry->applies_to_kind( EntryHeader::KIND_DB_CHUNK ) );
	}

	/**
	 * A TABLE-scope pattern applies only to the db_chunk kind.
	 *
	 * @return void
	 */
	public function test_operator_table_applies_only_to_db_chunk(): void {
		$entry = ExclusionPattern::operator_table( 'anything' );

		$this->assertFalse( $entry->applies_to_kind( EntryHeader::KIND_FILE ) );
		$this->assertFalse( $entry->applies_to_kind( EntryHeader::KIND_DIRECTORY ) );
		$this->assertFalse( $entry->applies_to_kind( EntryHeader::KIND_SYMLINK ) );
		$this->assertTrue( $entry->applies_to_kind( EntryHeader::KIND_DB_CHUNK ) );
	}

	// -------------------------------------------------------------------------
	// covers(): the exact rule ExclusionRules::from_tagged_patterns() uses to
	// drop a later entry an earlier one has already made redundant.
	// -------------------------------------------------------------------------

	/**
	 * A curated default (untagged, ANY scope, unanchored) covers an identically
	 * worded operator file pattern: the default already matches everything the
	 * operator pattern would, so the operator pattern is redundant.
	 *
	 * This is the measured defect: an operator's own
	 * `--exclude='wp-content/cache/**'` is textually identical to one of
	 * Pontifex's curated defaults, and the default — tested first — took every
	 * match, leaving the operator's own pattern reporting a permanent 0.
	 *
	 * @return void
	 */
	public function test_untagged_default_covers_identical_operator_file_pattern(): void {
		$default = ExclusionPattern::untagged( 'wp-content/cache/**' );
		$file    = ExclusionPattern::operator_file( 'wp-content/cache/**' );

		$this->assertTrue( $default->covers( $file ) );
	}

	/**
	 * A curated default also covers an identically worded operator table
	 * pattern, for the same reason: ANY scope reaches db_chunk entries too.
	 *
	 * @return void
	 */
	public function test_untagged_default_covers_identical_operator_table_pattern(): void {
		$default = ExclusionPattern::untagged( 'wp_actionscheduler_logs' );
		$table   = ExclusionPattern::operator_table( 'wp_actionscheduler_logs' );

		$this->assertTrue( $default->covers( $table ) );
	}

	/**
	 * Two identically worded operator file patterns: the earlier one covers
	 * the later — same scope, same anchoring, same text, so the second is
	 * pure duplication (an operator repeating --exclude with the same value).
	 *
	 * @return void
	 */
	public function test_operator_file_covers_identical_operator_file_pattern(): void {
		$first  = ExclusionPattern::operator_file( 'wp-content/uploads/tmp/**' );
		$second = ExclusionPattern::operator_file( 'wp-content/uploads/tmp/**' );

		$this->assertTrue( $first->covers( $second ) );
	}

	/**
	 * An operator FILE pattern must NOT cover an identically worded operator
	 * TABLE pattern — this is the regression guard the brief exists to
	 * protect: someone excluding a `wp_comments` FILE and the `wp_comments`
	 * TABLE has typed the same text for two genuinely different, meaningful
	 * rules, and de-duplicating on text alone would silently drop one of them.
	 *
	 * @return void
	 */
	public function test_operator_file_does_not_cover_identically_worded_operator_table_pattern(): void {
		$file  = ExclusionPattern::operator_file( 'wp_comments' );
		$table = ExclusionPattern::operator_table( 'wp_comments' );

		$this->assertFalse( $file->covers( $table ) );
	}

	/**
	 * The reverse pairing: an operator TABLE pattern must not cover an
	 * identically worded operator FILE pattern either — neither ordering may
	 * collapse the two, since both remain meaningful whichever was typed first.
	 *
	 * @return void
	 */
	public function test_operator_table_does_not_cover_identically_worded_operator_file_pattern(): void {
		$table = ExclusionPattern::operator_table( 'wp_comments' );
		$file  = ExclusionPattern::operator_file( 'wp_comments' );

		$this->assertFalse( $table->covers( $file ) );
	}

	/**
	 * An operator FILE pattern must not cover an identically worded untagged
	 * (ANY-scope) pattern that comes after it: FILE scope does not reach
	 * db_chunk entries, so the untagged pattern still reaches further than the
	 * operator one and is not redundant.
	 *
	 * @return void
	 */
	public function test_operator_file_does_not_cover_identically_worded_untagged_pattern(): void {
		$file     = ExclusionPattern::operator_file( 'wp_comments' );
		$untagged = ExclusionPattern::untagged( 'wp_comments' );

		$this->assertFalse( $file->covers( $untagged ) );
	}

	/**
	 * Differently worded patterns never cover one another, regardless of how
	 * their scope and anchoring compare.
	 *
	 * @return void
	 */
	public function test_different_pattern_text_never_covers(): void {
		$default = ExclusionPattern::untagged( 'wp-content/cache/**' );
		$other   = ExclusionPattern::operator_file( 'wp-content/uploads/**' );

		$this->assertFalse( $default->covers( $other ) );
	}
}
