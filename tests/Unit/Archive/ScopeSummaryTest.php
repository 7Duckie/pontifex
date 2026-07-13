<?php
/**
 * Tests for ScopeSummary — the operator-facing description of what an archive holds.
 *
 * @package Pontifex\Tests\Unit\Archive
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Archive;

use Pontifex\Archive\Format\Scope;
use Pontifex\Archive\ScopeSummary;
use Pontifex\Tests\TestCase;

/**
 * Pins the wording for every archive shape, so the verify verdict's single
 * source of truth cannot silently misclassify or reword. Extends the
 * WordPress-aware base case so __() is stubbed to return its argument.
 */
final class ScopeSummaryTest extends TestCase {

	/**
	 * A legacy archive (no scope) describes as the whole site.
	 *
	 * @return void
	 */
	public function test_null_scope_describes_as_legacy_whole_site(): void {
		$this->assertStringContainsString( 'legacy', ScopeSummary::describe( null ) );
		$this->assertStringContainsString( 'whole site', ScopeSummary::describe( null ) );
	}

	/**
	 * A content-only scope describes as content (files and database).
	 *
	 * @return void
	 */
	public function test_content_only_describes_as_content(): void {
		$label = ScopeSummary::describe( Scope::content_only( array() ) );
		$this->assertStringContainsString( 'wp-content', $label );
		$this->assertStringContainsString( 'database', $label );
	}

	/**
	 * A whole-site scope describes as the whole site with core.
	 *
	 * @return void
	 */
	public function test_whole_site_describes_as_whole_site(): void {
		$this->assertStringContainsString( 'WordPress core', ScopeSummary::describe( Scope::whole_site( array() ) ) );
	}

	/**
	 * A files-only scope describes as files only, no database.
	 *
	 * @return void
	 */
	public function test_files_only_describes_as_files_only(): void {
		$this->assertSame( 'files only (wp-content), with no database', ScopeSummary::describe( Scope::files_only( array() ) ) );
	}

	/**
	 * A db-only scope describes as database only, no files.
	 *
	 * @return void
	 */
	public function test_db_only_describes_as_database_only(): void {
		$this->assertSame( 'the database only, with no files', ScopeSummary::describe( Scope::db_only( array() ) ) );
	}

	/**
	 * The unreadable fallback names the archive rather than claiming any scope.
	 *
	 * @return void
	 */
	public function test_unreadable_fallback(): void {
		$this->assertStringContainsString( 'could not be read', ScopeSummary::unreadable() );
	}
}
