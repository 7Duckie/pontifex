<?php
/**
 * Pontifex manifest exclusion pattern — one exclusion pattern, tagged with what it may match.
 *
 * @package Pontifex\Manifest
 */

declare(strict_types=1);

namespace Pontifex\Manifest;

use Pontifex\Archive\Format\EntryHeader;

/**
 * Immutable value object pairing a raw exclusion pattern with two facts
 * {@see ExclusionRules::matches()} needs and the pattern's own text cannot
 * safely reveal: which entry kinds it is allowed to match, and whether a
 * regex-shaped pattern is anchored to the start of the name.
 *
 * Two facts, three factories:
 *
 *  - Kind scope. A FILE-scope pattern ({@see self::operator_file()}) is
 *    only ever tested against KIND_FILE, KIND_DIRECTORY, and KIND_SYMLINK
 *    entries. A TABLE-scope pattern ({@see self::operator_table()}) is
 *    only ever tested against KIND_DB_CHUNK (a bare table name). An
 *    UNTAGGED pattern ({@see self::untagged()}) — the pre-existing
 *    behaviour, kept for every caller that has not said which it means —
 *    is tested against every kind, exactly as {@see ExclusionRules::matches()}
 *    always has. This is what stops a folder-shaped pattern from also
 *    reaching a database table (or a table pattern from reaching a file):
 *    each list a caller collects (`--exclude`/`--exclude-file`,
 *    `--exclude-table`) is tagged with the one scope it was documented to
 *    mean, rather than the two being merged into one list the way
 *    {@see \Pontifex\Cli\ExportCommand} used to.
 *  - Anchoring. A regex-shaped pattern ("/…/") built by
 *    {@see self::operator_file()} or {@see self::operator_table()} is
 *    matched from the start of the name; an untagged pattern keeps
 *    matching anywhere in the name, unchanged. This is what lets
 *    Pontifex's own `.git`-at-any-depth default
 *    ({@see ExclusionRules::default_v010()}) keep matching at every
 *    depth while an operator's own regex no longer matches a name that
 *    merely contains it. Only regex-shaped patterns are affected: the
 *    other three pattern shapes ExclusionRules recognises (directory-tree,
 *    glob, exact string) are already anchored by their own semantics.
 *
 * Why this exists rather than inspecting the pattern text: the built-in
 * `.git` default is a regex written deliberately to match at any depth,
 * and a person's own pattern can look exactly the same shape. Guessing
 * provenance from the pattern's text would work today and break the
 * first time somebody's own pattern happened to resemble a default.
 * Tagging a pattern at the point it is collected — a curated default, a
 * CLI flag — is the only place that genuinely knows which it is.
 */
final class ExclusionPattern {

	/**
	 * Kind scope: matched against every entry kind (the untagged default).
	 *
	 * @var string
	 */
	public const SCOPE_ANY = 'any';

	/**
	 * Kind scope: matched only against file, directory, and symlink entries.
	 *
	 * @var string
	 */
	public const SCOPE_FILE = 'file';

	/**
	 * Kind scope: matched only against database-chunk entries (bare table names).
	 *
	 * @var string
	 */
	public const SCOPE_TABLE = 'table';

	/**
	 * The raw pattern text, in one of ExclusionRules' four pattern shapes.
	 *
	 * @var string
	 */
	private string $pattern;

	/**
	 * Which entry kinds this pattern is allowed to match; one of the SCOPE_* constants.
	 *
	 * @var string
	 */
	private string $scope;

	/**
	 * Whether a regex-shaped pattern is anchored to the start of the name.
	 *
	 * @var bool
	 */
	private bool $anchored;

	/**
	 * Construct an ExclusionPattern. Private: use one of the named factories below.
	 *
	 * @param string $pattern  The raw pattern text.
	 * @param string $scope    One of the SCOPE_* constants.
	 * @param bool   $anchored True to anchor a regex-shaped pattern to the start of the name.
	 */
	private function __construct( string $pattern, string $scope, bool $anchored ) {
		$this->pattern  = $pattern;
		$this->scope    = $scope;
		$this->anchored = $anchored;
	}

	/**
	 * Build an untagged pattern: matches every entry kind, regex unanchored.
	 *
	 * The pre-existing behaviour, unchanged. Used for every pattern whose
	 * caller has not said which kind it means — including Pontifex's own
	 * curated defaults, so `.git`-at-any-depth keeps matching exactly as
	 * it always has, whichever other patterns it is combined with.
	 *
	 * @param string $pattern The raw pattern text.
	 * @return self An untagged pattern.
	 */
	public static function untagged( string $pattern ): self {
		return new self( $pattern, self::SCOPE_ANY, false );
	}

	/**
	 * Build an operator-supplied file pattern: files, directories, and symlinks only.
	 *
	 * A regex-shaped pattern is anchored to the start of the name — see
	 * the class docblock. Used for `--exclude` and `--exclude-file`.
	 *
	 * @param string $pattern The raw pattern text.
	 * @return self A file-scoped, anchored pattern.
	 */
	public static function operator_file( string $pattern ): self {
		return new self( $pattern, self::SCOPE_FILE, true );
	}

	/**
	 * Build an operator-supplied table pattern: database chunks (bare table names) only.
	 *
	 * A regex-shaped pattern is anchored to the start of the name — see
	 * the class docblock. Used for `--exclude-table`.
	 *
	 * @param string $pattern The raw pattern text.
	 * @return self A table-scoped, anchored pattern.
	 */
	public static function operator_table( string $pattern ): self {
		return new self( $pattern, self::SCOPE_TABLE, true );
	}

	/**
	 * Return the raw pattern text.
	 *
	 * @return string The pattern, exactly as supplied.
	 */
	public function pattern(): string {
		return $this->pattern;
	}

	/**
	 * Return this pattern's kind scope.
	 *
	 * @return string One of the SCOPE_* constants.
	 */
	public function scope(): string {
		return $this->scope;
	}

	/**
	 * Whether a regex-shaped pattern is anchored to the start of the name.
	 *
	 * @return bool True for an anchored (operator-supplied) pattern.
	 */
	public function is_anchored(): bool {
		return $this->anchored;
	}

	/**
	 * Whether this pattern is allowed to match the given entry kind.
	 *
	 * @param string $kind One of the EntryHeader::KIND_* constants.
	 * @return bool True if this pattern's scope covers $kind.
	 */
	public function applies_to_kind( string $kind ): bool {
		return match ( $this->scope ) {
			self::SCOPE_FILE  => EntryHeader::KIND_DB_CHUNK !== $kind,
			self::SCOPE_TABLE => EntryHeader::KIND_DB_CHUNK === $kind,
			default           => true,
		};
	}

	/**
	 * Whether this pattern completely covers $other — everything $other
	 * would ever match, this pattern already matches, so keeping $other
	 * alongside this one adds no reach and only steals its match credit.
	 *
	 * Used by {@see ExclusionRules::from_tagged_patterns()} to drop a
	 * later entry an earlier one has already made redundant — the
	 * measured defect this exists to fix: an operator's own
	 * `--exclude='wp-content/cache/**'` is textually identical to one of
	 * Pontifex's curated defaults, so the default (tested first) took
	 * every match and the operator's own entry reported a permanent 0,
	 * even though it was working perfectly.
	 *
	 * All three of the following must hold:
	 *
	 *  1. The pattern text is character-for-character identical. Two
	 *     different patterns can never make one another redundant, no
	 *     matter how their scope or anchoring compare.
	 *  2. This pattern's kind scope covers $other's: SCOPE_ANY covers
	 *     everything; SCOPE_FILE covers only SCOPE_FILE; SCOPE_TABLE
	 *     covers only SCOPE_TABLE. This is deliberately NOT "same scope"
	 *     — it is why text alone would be the wrong test. Someone
	 *     excluding a `wp_comments` FILE and the `wp_comments` TABLE has
	 *     typed the same text twice with two different, genuinely
	 *     meaningful scopes; collapsing them on text alone would silently
	 *     drop whichever one lost the tie, exactly the kind of quiet data
	 *     loss this feature exists to make visible.
	 *  3. This pattern's anchoring is at least as broad as $other's: an
	 *     unanchored pattern matches strictly more than an anchored one
	 *     with the same text, so it covers either; an anchored pattern
	 *     covers only an equally anchored $other. With today's three
	 *     factories this already follows from scope (untagged patterns
	 *     are never anchored, operator patterns always are), so this
	 *     check is currently implied by the scope check — it is written
	 *     out explicitly anyway, so a future fourth factory that mixes
	 *     scope and anchoring differently cannot silently make this
	 *     wrong.
	 *
	 * @param self $other The later pattern to test against this one.
	 * @return bool True if this pattern already matches everything $other could.
	 */
	public function covers( self $other ): bool {
		if ( $this->pattern !== $other->pattern ) {
			return false;
		}
		if ( self::SCOPE_ANY !== $this->scope && $this->scope !== $other->scope ) {
			return false;
		}
		if ( $this->anchored && ! $other->anchored ) {
			return false;
		}
		return true;
	}
}
