<?php
/**
 * Pontifex manifest exclusion rules — decides which paths to omit from archives.
 *
 * @package Pontifex\Manifest
 */

declare(strict_types=1);

namespace Pontifex\Manifest;

use InvalidArgumentException;
use Pontifex\Archive\Format\EntryHeader;

/**
 * Decides whether a given scanned path should be excluded from the archive.
 *
 * Pattern syntax (matched in this order — first match wins):
 *
 *  1. **Regex patterns** — patterns that start and end with "/" are
 *     treated as PCRE regular expressions. The pattern is passed to
 *     preg_match() whole, with its leading and trailing slashes kept
 *     as the PCRE delimiters, and no modifiers are added.
 *     Example: "/\.tmp$/" matches any path ending in ".tmp". Where in
 *     the path it is allowed to match depends on how the pattern was
 *     tagged (see {@see ExclusionPattern}): an untagged pattern (the
 *     constructor, {@see self::from_array()}, and the curated defaults)
 *     matches anywhere in the path, exactly as before; an operator
 *     pattern ({@see self::from_tagged_patterns()} via
 *     {@see ExclusionPattern::operator_file()} or
 *     {@see ExclusionPattern::operator_table()}) is anchored to the
 *     start of the path, so "/log/" no longer matches "blogmap.php".
 *     This only changes regex patterns; the other three shapes below
 *     are already anchored by their own semantics.
 *  2. **Directory-tree patterns** — patterns ending with "/**" match
 *     the directory itself AND every path beneath it. Example:
 *     "wp-content/cache/**" matches "wp-content/cache",
 *     "wp-content/cache/foo.html", "wp-content/cache/sub/bar.css".
 *     This is the most commonly useful pattern type and is what most
 *     of {@see ExclusionRules::default_v010()} uses.
 *  3. **Glob patterns** — patterns containing "*" or "?" (but not
 *     "**") are matched with fnmatch() using FNM_PATHNAME, so "*"
 *     does not cross slashes. "**" is NOT a glob-star here: fnmatch()
 *     has no globstar concept, so "**" is just two adjacent "*", and
 *     FNM_PATHNAME still stops each one at a "/". Concretely,
 *     "**\/file.log" does NOT match "a/b/file.log" — verified with
 *     fnmatch() directly, not assumed. A directory-tree pattern
 *     matches a fixed directory at any depth beneath it, but not a
 *     directory whose own position varies; for that — "match this
 *     name at any depth, wherever it occurs" — use a regex. This is
 *     exactly why {@see ExclusionRules::default_v010()}'s `.git`
 *     exclusion is the regex `/(^|\/)\.git(\/|$)/`, not a glob or a
 *     directory-tree pattern: a `.git` directory can sit at the site
 *     root, one level down, or inside any plugin or theme, and only a
 *     regex matches it at all of those depths.
 *  4. **Exact strings** — patterns with no special characters are
 *     compared with strict equality against the relative path.
 *     Example: "wp-config-sample.php" matches only that file at the
 *     scan root.
 *
 * Public API (locked from commit 10 forward; commit 12 added the
 * pattern-type dispatch and three factories without changing any
 * existing signature):
 *
 *  - {@see ExclusionRules::__construct()} — patterns array; validates
 *    that every element is a string. Every pattern built this way is
 *    untagged (see {@see ExclusionPattern::untagged()}): matched against
 *    every entry kind, with an unanchored regex — the original,
 *    unchanged behaviour.
 *  - {@see ExclusionRules::none()} — empty rule set; excludes nothing.
 *  - {@see ExclusionRules::default_v010()} — Pontifex's curated default
 *    exclusion list for v0.1.0 (Pontifex's own working dir and the
 *    WordPress core cache directory). Untagged, like the constructor.
 *  - {@see ExclusionRules::from_array()} — explicit factory equivalent
 *    to the constructor; documented for callers who prefer factory
 *    methods over direct construction. Untagged, like the constructor.
 *  - {@see ExclusionRules::from_tagged_patterns()} — builds a rule set
 *    from explicitly tagged {@see ExclusionPattern} entries, each
 *    carrying its own kind scope and anchoring. This is how a caller
 *    combines Pontifex's own untagged defaults with an operator's own
 *    file and table patterns without the two lists losing what kind of
 *    thing each pattern was ever meant to match. A later entry that an
 *    earlier entry already completely covers ({@see ExclusionPattern::covers()})
 *    is dropped, so an operator pattern identical to a curated default
 *    is not silently shadowed by it and left reporting a false zero in
 *    {@see self::match_counts()}.
 *  - {@see ExclusionRules::matches()} — true if the path should be
 *    excluded; false otherwise. A pattern whose scope does not cover the
 *    given kind is skipped, never evaluated.
 *  - {@see ExclusionRules::patterns()} — read-only patterns view (the
 *    raw pattern text only, in construction order — scope and anchoring
 *    are not part of this view).
 *  - {@see ExclusionRules::match_counts()} — how many times each pattern
 *    has excluded something, in the same order as {@see self::patterns()}.
 *
 * Default-vs-user-control philosophy: {@see ExclusionRules::default_v010()}
 * returns a deliberately small, defensible list — three categories of
 * exclusion where the rationale is clear (recursion prevention, WordPress's
 * own ephemeral cache, and version-control metadata that is not site content).
 * Everything else a site holds is the owner's data, so Pontifex does not drop
 * it on their behalf. Pontifex's CLI surface (Phase 4) exposes the active
 * exclusion list before performing an export, so users always see what is
 * being skipped and can override with --no-defaults or --exclude-file.
 *
 * The one exclusion that is NOT in the configurable list — Pontifex's
 * own working directory recursion prevention — is enforced
 * structurally inside FileScanner regardless of which ExclusionRules
 * instance is in use. This way the recursion invariant cannot be
 * accidentally disabled by passing ExclusionRules::none().
 */
final class ExclusionRules {

	/**
	 * The patterns this rule set was constructed with, each tagged with its
	 * kind scope and anchoring.
	 *
	 * Stored in construction order. Pattern-type detection happens lazily
	 * on each call to matches().
	 *
	 * @var ExclusionPattern[]
	 */
	private array $entries;

	/**
	 * How many times each entry (by its index in $entries) has excluded something.
	 *
	 * @var array<int, int>
	 */
	private array $match_counts;

	/**
	 * Construct an ExclusionRules with an explicit list of patterns.
	 *
	 * Every pattern built this way is untagged (see
	 * {@see ExclusionPattern::untagged()}): matched against every entry
	 * kind, with an unanchored regex — unchanged from before tagging
	 * existed. Most callers should prefer one of the named factories:
	 * {@see ExclusionRules::none()}, {@see ExclusionRules::default_v010()},
	 * or {@see ExclusionRules::from_array()}. A caller that needs to give
	 * some patterns a narrower scope wants {@see ExclusionRules::from_tagged_patterns()}
	 * instead.
	 *
	 * @param string[] $patterns Patterns to match against relative paths.
	 * @throws InvalidArgumentException If any element of $patterns is not a string.
	 */
	public function __construct( array $patterns = array() ) {
		$entries = array();
		foreach ( $patterns as $i => $pattern ) {
			if ( ! is_string( $pattern ) ) {
				throw new InvalidArgumentException(
					sprintf( 'Patterns[%d] must be a string.', (int) $i )
				);
			}
			$entries[] = ExclusionPattern::untagged( $pattern );
		}
		$this->entries      = $entries;
		$this->match_counts = array_fill( 0, count( $entries ), 0 );
	}

	/**
	 * Build a rule set that excludes nothing.
	 *
	 * Useful for tests and for callers that want the scanner to
	 * return every file, directory, and symlink it finds.
	 *
	 * @return self A rule set with no patterns.
	 */
	public static function none(): self {
		return new self( array() );
	}

	/**
	 * Build the curated default exclusion list for v0.1.0.
	 *
	 * Three categories of exclusion, each with a defensible rationale:
	 *
	 *  1. Pontifex's own working directory — prevents recursive
	 *     archive-of-archives nesting if a previous Pontifex export
	 *     left files in wp-content/pontifex/. (This is also enforced
	 *     structurally inside FileScanner; the pattern keeps it visible
	 *     in the printed exclusion summary.)
	 *  2. WordPress's own ephemeral cache directory — by WordPress
	 *     convention, wp-content/cache/ holds regenerable cache data
	 *     used by transient and page-cache plugins, so it is safe to
	 *     skip and regenerates on the destination.
	 *  3. Version-control metadata (`.git`, at any depth) — added in
	 *     v0.9.3 (ADR 0008 amendment, 2026-07-16). Unlike categories 1
	 *     and 2, this is not a fixed-position directory, so it is the
	 *     one entry in this list that is a regex rather than a
	 *     directory-tree pattern (see the class docblock's pattern-type
	 *     note on why a glob or tree pattern cannot express "at any
	 *     depth").
	 *
	 * The list is deliberately minimal: anything else a site holds —
	 * including data other plugins have written — is the owner's data,
	 * and Pontifex does not decide on their behalf to drop it. Pontifex's
	 * CLI surfaces this list before running an export, so users see what
	 * is being skipped and can override with --no-defaults or a custom
	 * --exclude-file.
	 *
	 * @return self A rule set with the v0.1.0 default exclusions.
	 */
	public static function default_v010(): self {
		return new self(
			array(
				// Pontifex's own working directory (recursion prevention).
				'wp-content/pontifex/**',

				// WordPress core ephemeral cache (regenerable by design).
				'wp-content/cache/**',

				// Version-control metadata at any depth: a git-deployed site's history is not
				// site content, is regenerable from its remote, and would otherwise be carried
				// into every archive (and back out again on a restore).
				'/(^|\/)\.git(\/|$)/',
			)
		);
	}

	/**
	 * Build a rule set from an explicit list of patterns.
	 *
	 * Equivalent to calling the constructor directly; provided as a
	 * named factory for callers (CLI, config loaders) that prefer the
	 * factory style.
	 *
	 * @param string[] $patterns Patterns to match against relative paths.
	 * @return self A rule set containing exactly the given patterns.
	 * @throws InvalidArgumentException If any element of $patterns is not a string.
	 */
	public static function from_array( array $patterns ): self {
		return new self( $patterns );
	}

	/**
	 * Build a rule set from explicitly tagged patterns.
	 *
	 * Unlike {@see self::from_array()}, each pattern here says which
	 * entry kind it may match and whether a regex-shaped pattern is
	 * anchored to the start of the name (see {@see ExclusionPattern}).
	 * This is how a caller keeps Pontifex's own untagged defaults
	 * matching exactly as before while giving an operator's own file and
	 * table patterns their own, narrower behaviour — see
	 * {@see \Pontifex\Cli\ExportCommand::build_exclusion_rules()} for the
	 * caller that does this.
	 *
	 * Before the rule set is built, a later entry that an earlier entry
	 * already completely covers ({@see ExclusionPattern::covers()}) is
	 * dropped, keeping the first occurrence and leaving every other
	 * entry's order and count untouched. This closes a real defect: an
	 * operator's own pattern can be textually identical to one of
	 * Pontifex's curated defaults (`--exclude='wp-content/cache/**'`,
	 * say), and without de-duplication the default — tested first —
	 * would take every match, leaving the operator's own, functioning
	 * pattern reporting a permanent 0 in {@see self::match_counts()}.
	 * De-duplication is never done on pattern text alone: the same text
	 * given once as a file pattern and once as a table pattern is two
	 * genuinely different rules (a `wp_comments` file and a `wp_comments`
	 * table), and both are kept — see {@see ExclusionPattern::covers()}
	 * for the exact rule.
	 *
	 * @param ExclusionPattern[] $entries The tagged patterns, in match order.
	 * @return self A rule set built from the tagged patterns, with any entry a preceding one fully covers removed.
	 * @throws InvalidArgumentException If any element of $entries is not an ExclusionPattern.
	 */
	public static function from_tagged_patterns( array $entries ): self {
		foreach ( $entries as $i => $entry ) {
			if ( ! $entry instanceof ExclusionPattern ) {
				throw new InvalidArgumentException(
					sprintf( 'Entries[%d] must be an ExclusionPattern.', (int) $i )
				);
			}
		}

		$deduplicated = array();
		foreach ( $entries as $entry ) {
			$already_covered = false;
			foreach ( $deduplicated as $kept ) {
				if ( $kept->covers( $entry ) ) {
					$already_covered = true;
					break;
				}
			}
			if ( ! $already_covered ) {
				$deduplicated[] = $entry;
			}
		}

		$rules               = new self();
		$rules->entries      = array_values( $deduplicated );
		$rules->match_counts = array_fill( 0, count( $rules->entries ), 0 );
		return $rules;
	}

	/**
	 * Decide whether the given path should be excluded from the archive.
	 *
	 * Iterates the patterns in construction order; returns true on
	 * the first match whose scope covers $kind. Each pattern is
	 * dispatched to one of four matchers based on its shape (regex,
	 * directory-tree, glob, or exact-string).
	 *
	 * @param string $relative_path Path relative to the scan root.
	 * @param string $kind          One of the EntryHeader::KIND_* constants.
	 * @return bool True if the path should be excluded; false otherwise.
	 * @throws InvalidArgumentException If $relative_path is empty or $kind is not a recognised entry kind.
	 */
	public function matches( string $relative_path, string $kind ): bool {
		if ( '' === $relative_path ) {
			throw new InvalidArgumentException( 'relative_path must be non-empty.' );
		}
		$allowed_kinds = array(
			EntryHeader::KIND_FILE,
			EntryHeader::KIND_DIRECTORY,
			EntryHeader::KIND_SYMLINK,
			EntryHeader::KIND_DB_CHUNK,
		);
		if ( ! in_array( $kind, $allowed_kinds, true ) ) {
			throw new InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $kind reported verbatim in exception message for diagnostic context; not HTML output.
				sprintf( 'Kind "%s" is not one of file, directory, symlink, db_chunk.', $kind )
			);
		}

		foreach ( $this->entries as $index => $entry ) {
			if ( ! $entry->applies_to_kind( $kind ) ) {
				continue;
			}
			if ( self::pattern_matches( $entry->pattern(), $relative_path, $entry->is_anchored() ) ) {
				++$this->match_counts[ $index ];
				return true;
			}
		}
		return false;
	}

	/**
	 * Return the configured patterns (read-only view).
	 *
	 * Raw pattern text only, in construction order — an entry's kind
	 * scope and anchoring are not part of this view; see
	 * {@see ExclusionPattern} for those.
	 *
	 * @return string[] The patterns, in construction order.
	 */
	public function patterns(): array {
		return array_map(
			static fn ( ExclusionPattern $entry ): string => $entry->pattern(),
			$this->entries
		);
	}

	/**
	 * Return the tagged pattern entries (read-only view), in construction order.
	 *
	 * Unlike {@see self::patterns()}, each entry carries its own kind scope
	 * and anchoring — see {@see ExclusionPattern}. This is what lets a
	 * caller that built a rule set from tagged patterns (an export, a
	 * scheduled backup) hand that same tagging on to a second consumer —
	 * a resumable job's persisted payload, say — without regenerating the
	 * tags from scratch or losing them along the way.
	 *
	 * @return ExclusionPattern[] The tagged patterns, in construction order.
	 */
	public function entries(): array {
		return $this->entries;
	}

	/**
	 * Return how many times each pattern has excluded something.
	 *
	 * One entry per pattern, in the same order as {@see self::patterns()},
	 * so the two can be zipped together. A pattern that never matched
	 * reports 0, not an absent entry — the point is to make a pattern
	 * that quietly matched nothing exactly as visible as one that matched
	 * something it should not have.
	 *
	 * @return array<int, array{pattern: string, count: int}> One entry per pattern.
	 */
	public function match_counts(): array {
		$counts = array();
		foreach ( $this->entries as $index => $entry ) {
			$counts[] = array(
				'pattern' => $entry->pattern(),
				'count'   => $this->match_counts[ $index ],
			);
		}
		return $counts;
	}

	/**
	 * Match a single pattern against a single path, dispatching by pattern shape.
	 *
	 * @param string $pattern       The pattern to interpret.
	 * @param string $relative_path The path to test.
	 * @param bool   $anchored      True to anchor a regex-shaped pattern to the start of $relative_path.
	 * @return bool True if the pattern matches the path.
	 */
	private static function pattern_matches( string $pattern, string $relative_path, bool $anchored ): bool {
		// Empty patterns never match anything; defensive against malformed config.
		if ( '' === $pattern ) {
			return false;
		}

		// Regex: starts AND ends with "/".
		// Must be at least 2 chars (so "/" alone is exact-string, not malformed regex).
		if ( strlen( $pattern ) >= 2 && '/' === $pattern[0] && '/' === $pattern[ strlen( $pattern ) - 1 ] ) {
			return self::regex_matches( $pattern, $relative_path, $anchored );
		}

		// Directory-tree: ends with "/**". Matches the directory and everything beneath it.
		if ( str_ends_with( $pattern, '/**' ) ) {
			return self::tree_matches( $pattern, $relative_path );
		}

		// Glob: contains "*" or "?".
		if ( false !== strpos( $pattern, '*' ) || false !== strpos( $pattern, '?' ) ) {
			return self::glob_matches( $pattern, $relative_path );
		}

		// Exact string.
		return $pattern === $relative_path;
	}

	/**
	 * Apply a regex pattern using preg_match.
	 *
	 * The leading and trailing slashes are kept as PCRE delimiters.
	 * A malformed regex produces an InvalidArgumentException at
	 * match-time so the user sees the error rather than the pattern
	 * silently failing to match.
	 *
	 * $anchored does not rewrite the pattern text (which would risk
	 * interacting badly with alternation, groups, or a pattern's own
	 * anchors) — it asks preg_match() where its match began, via
	 * PREG_OFFSET_CAPTURE, and only accepts a match starting at offset 0.
	 * A pattern that already anchors itself with "^" is unaffected: its
	 * own match already starts at 0.
	 *
	 * @param string $pattern       The PCRE pattern including its / delimiters.
	 * @param string $relative_path The path to test.
	 * @param bool   $anchored      True to require the match to start at offset 0 of $relative_path.
	 * @return bool True if the regex matches.
	 * @throws InvalidArgumentException If the pattern is not a valid PCRE expression.
	 */
	private static function regex_matches( string $pattern, string $relative_path, bool $anchored ): bool {
		// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged,WordPress.PHP.NoSilencedErrors.Discouraged -- preg_match emits a warning on invalid patterns; we trap it and rethrow with a clearer message.
		$result = @preg_match( $pattern, $relative_path, $matches, PREG_OFFSET_CAPTURE );
		if ( false === $result ) {
			throw new InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $pattern reported verbatim in exception message for diagnostic context; not HTML output.
				sprintf( 'Pattern "%s" is not a valid regular expression.', $pattern )
			);
		}
		if ( 1 !== $result ) {
			return false;
		}
		if ( ! $anchored ) {
			return true;
		}
		return 0 === $matches[0][1];
	}

	/**
	 * Apply a directory-tree pattern (one ending in "/**").
	 *
	 * The pattern "wp-content/cache/**" matches:
	 *  - "wp-content/cache"            (the directory itself)
	 *  - "wp-content/cache/foo"        (immediate children)
	 *  - "wp-content/cache/sub/bar"    (any depth beneath)
	 *
	 * If the prefix portion contains glob characters, fnmatch is
	 * applied to each path-prefix component instead of strict
	 * comparison. This handles patterns like "wp-content/snapshots-*\/**"
	 * that target multiple sibling directories.
	 *
	 * @param string $pattern       Pattern ending in "/**".
	 * @param string $relative_path The path to test.
	 * @return bool True if the pattern matches the directory or anything beneath it.
	 */
	private static function tree_matches( string $pattern, string $relative_path ): bool {
		// Strip the trailing "/**".
		$prefix = substr( $pattern, 0, -3 );

		if ( '' === $prefix ) {
			// Pattern was "/**" alone: matches everything.
			return true;
		}

		$has_glob_chars = false !== strpos( $prefix, '*' ) || false !== strpos( $prefix, '?' );

		if ( $has_glob_chars ) {
			// Match each path prefix against the glob prefix using fnmatch.
			$segments     = explode( '/', $relative_path );
			$accumulating = '';
			foreach ( $segments as $segment ) {
				$accumulating = '' === $accumulating ? $segment : $accumulating . '/' . $segment;
				if ( fnmatch( $prefix, $accumulating, FNM_PATHNAME ) ) {
					return true;
				}
			}
			return false;
		}

		// Plain prefix: match if path equals it or has it as a slash-bounded ancestor.
		if ( $relative_path === $prefix ) {
			return true;
		}
		$prefix_with_sep = $prefix . '/';
		return 0 === strncmp( $relative_path, $prefix_with_sep, strlen( $prefix_with_sep ) );
	}

	/**
	 * Apply a glob pattern using fnmatch() with FNM_PATHNAME.
	 *
	 * FNM_PATHNAME makes "*" stop at path separators, matching common
	 * glob semantics. Use a tree pattern ("dir/**") for "match at any
	 * depth" semantics.
	 *
	 * @param string $pattern       The glob pattern.
	 * @param string $relative_path The path to test.
	 * @return bool True if the glob matches the path.
	 */
	private static function glob_matches( string $pattern, string $relative_path ): bool {
		return fnmatch( $pattern, $relative_path, FNM_PATHNAME );
	}
}
