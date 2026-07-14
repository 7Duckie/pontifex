<?php
/**
 * Pontifex scope summary — the operator-facing description of what an archive holds.
 *
 * @package Pontifex\Archive
 */

declare(strict_types=1);

namespace Pontifex\Archive;

use Pontifex\Archive\Format\Scope;

/**
 * Turns a recorded {@see Scope} into a single human clause for a verify verdict.
 *
 * The one place the operator-facing wording lives, so the CLI and the admin
 * Verify screen describe an archive identically and cannot drift. The scope
 * classification itself is single-sourced in {@see Scope::content_summary_key()};
 * this maps each key (plus the legacy-null and unreadable cases) to its
 * translated sentence fragment. A presentation helper over the pure format
 * value object, deliberately separate from it so the Scope value object keeps
 * no WordPress dependency.
 */
final class ScopeSummary {

	/**
	 * Describe, in one clause, what an archive with this scope holds.
	 *
	 * @param Scope|null $scope The recorded scope, or null for a legacy archive with no scope block.
	 * @return string The human-readable content description.
	 */
	public static function describe( ?Scope $scope ): string {
		if ( null === $scope ) {
			return __( 'the whole site (a legacy archive with no recorded scope)', 'pontifex' );
		}
		switch ( $scope->content_summary_key() ) {
			case Scope::SUMMARY_WHOLE_SITE:
				return __( 'the whole site — WordPress core, wp-config.php, wp-content, and the database', 'pontifex' );
			case Scope::SUMMARY_DB_ONLY:
				return __( 'the database only, with no files', 'pontifex' );
			case Scope::SUMMARY_FILES_ONLY:
				return __( 'files only (wp-content), with no database', 'pontifex' );
			default:
				return __( 'your content — wp-content and the whole database', 'pontifex' );
		}
	}

	/**
	 * The description used when the archive's scope could not be read.
	 *
	 * A label is presentation, not integrity: verify checks every hash, so a
	 * provenance block that cannot be re-read for the label must not turn a
	 * sound archive into a failure — its contents are simply not describable.
	 *
	 * @return string The human-readable fallback description.
	 */
	public static function unreadable(): string {
		return __( 'contents that could not be read from the archive', 'pontifex' );
	}

	/**
	 * Describe, in a compact list label, what an archive with this scope holds.
	 *
	 * The short form of {@see self::describe()}: a couple of words for a
	 * "Contains" column rather than a full sentence clause, over the same
	 * {@see Scope::content_summary_key()} classification so the two never
	 * disagree about what a given scope is.
	 *
	 * @param Scope|null $scope The recorded scope, or null for a legacy archive with no scope block.
	 * @return string The human-readable compact label.
	 */
	public static function label( ?Scope $scope ): string {
		if ( null === $scope ) {
			return __( 'Whole site (legacy)', 'pontifex' );
		}
		switch ( $scope->content_summary_key() ) {
			case Scope::SUMMARY_WHOLE_SITE:
				return __( 'Whole site', 'pontifex' );
			case Scope::SUMMARY_DB_ONLY:
				return __( 'Database only', 'pontifex' );
			case Scope::SUMMARY_FILES_ONLY:
				return __( 'Files only', 'pontifex' );
			default:
				return __( 'Content and database', 'pontifex' );
		}
	}

	/**
	 * The compact list label used when the archive's scope could not be read.
	 *
	 * The short form of {@see self::unreadable()}, for the same fail-soft reason:
	 * a label is presentation, not integrity, so an archive a list cannot read the
	 * scope of is simply shown as unknown rather than breaking the list.
	 *
	 * @return string The human-readable fallback label.
	 */
	public static function unreadable_label(): string {
		return __( 'Unknown', 'pontifex' );
	}
}
