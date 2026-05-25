<?php
/**
 * Unit tests for the ExclusionRules class (commit-10 stub coverage).
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
 * Tests for {@see ExclusionRules} covering the commit-10 stub.
 *
 * The class is intentionally minimal in commit 10 (it exists so
 * FileScanner can depend on it); commit 12 will add real
 * pattern-matching logic and additional factories. These tests fix
 * the stable public API surface so future commits do not break it.
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
	 * Commit 10 stores patterns but does not use them; commit 12 will.
	 *
	 * @return void
	 */
	public function test_constructor_accepts_string_patterns(): void {
		$patterns = array( 'wp-content/cache/*', '*.tmp' );
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

		new ExclusionRules( array( 'wp-content/cache/*', 42 ) );
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
	 * The matches method must reject the db_chunk kind (not produced by FileScanner).
	 *
	 * @return void
	 */
	public function test_matches_rejects_db_chunk_kind(): void {
		$this->expectException( InvalidArgumentException::class );

		ExclusionRules::none()->matches( 'wp_posts', EntryHeader::KIND_DB_CHUNK );
	}

	/**
	 * The none() factory must return false for any input.
	 *
	 * @return void
	 */
	public function test_none_factory_returns_false_for_everything(): void {
		$rules = ExclusionRules::none();

		$this->assertFalse( $rules->matches( 'wp-content/cache/foo', EntryHeader::KIND_FILE ) );
		$this->assertFalse( $rules->matches( 'wp-content/cache', EntryHeader::KIND_DIRECTORY ) );
		$this->assertFalse( $rules->matches( 'anything', EntryHeader::KIND_SYMLINK ) );
	}

	/**
	 * An exact-string pattern must match the same path exactly.
	 *
	 * @return void
	 */
	public function test_matches_returns_true_for_exact_string_pattern(): void {
		$rules = new ExclusionRules( array( 'wp-content/cache' ) );

		$this->assertTrue( $rules->matches( 'wp-content/cache', EntryHeader::KIND_DIRECTORY ) );
	}

	/**
	 * An exact-string pattern must not match a different path.
	 *
	 * @return void
	 */
	public function test_matches_returns_false_for_non_matching_path(): void {
		$rules = new ExclusionRules( array( 'wp-content/cache' ) );

		$this->assertFalse( $rules->matches( 'wp-content/uploads', EntryHeader::KIND_DIRECTORY ) );
	}

	/**
	 * Multiple patterns must all be considered.
	 *
	 * @return void
	 */
	public function test_matches_considers_every_pattern(): void {
		$rules = new ExclusionRules( array( 'foo.txt', 'bar.txt', 'baz.txt' ) );

		$this->assertTrue( $rules->matches( 'foo.txt', EntryHeader::KIND_FILE ) );
		$this->assertTrue( $rules->matches( 'bar.txt', EntryHeader::KIND_FILE ) );
		$this->assertTrue( $rules->matches( 'baz.txt', EntryHeader::KIND_FILE ) );
		$this->assertFalse( $rules->matches( 'qux.txt', EntryHeader::KIND_FILE ) );
	}
}
