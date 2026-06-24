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
 *     treated as PCRE regular expressions. The slashes are stripped
 *     and the rest is passed to preg_match() with no modifiers added.
 *     Example: "/\.tmp$/" matches any path ending in ".tmp".
 *  2. **Directory-tree patterns** — patterns ending with "/**" match
 *     the directory itself AND every path beneath it. Example:
 *     "wp-content/cache/**" matches "wp-content/cache",
 *     "wp-content/cache/foo.html", "wp-content/cache/sub/bar.css".
 *     This is the most commonly useful pattern type and is what
 *     {@see ExclusionRules::default_v010()} uses.
 *  3. **Glob patterns** — patterns containing "*" or "?" (but not
 *     "**") are matched with fnmatch() using FNM_PATHNAME, so "*"
 *     does not cross slashes. Example: "*.log" matches "foo.log" but
 *     not "sub/foo.log". For "match at any depth" use "**\/file.log"
 *     or a directory-tree pattern.
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
 *    that every element is a string.
 *  - {@see ExclusionRules::none()} — empty rule set; excludes nothing.
 *  - {@see ExclusionRules::default_v010()} — Pontifex's curated default
 *    exclusion list for v0.1.0 (Pontifex's own working dir, the
 *    WordPress core cache directory, and known other-backup-plugin
 *    working directories).
 *  - {@see ExclusionRules::from_array()} — explicit factory equivalent
 *    to the constructor; documented for callers who prefer factory
 *    methods over direct construction.
 *  - {@see ExclusionRules::matches()} — true if the path should be
 *    excluded; false otherwise.
 *  - {@see ExclusionRules::patterns()} — read-only patterns view.
 *
 * Default-vs-user-control philosophy: {@see ExclusionRules::default_v010()}
 * returns a deliberately small, defensible list — three categories of
 * exclusion where the rationale is clear (recursion prevention,
 * WordPress's own ephemeral cache, other backup plugins' working
 * directories). Pontifex's CLI surface (Phase 4) exposes the active
 * exclusion list before performing an export, so users always see
 * what is being skipped and can override with --no-defaults or
 * --exclude-file.
 *
 * The one exclusion that is NOT in the configurable list — Pontifex's
 * own working directory recursion prevention — is enforced
 * structurally inside FileScanner regardless of which ExclusionRules
 * instance is in use. This way the recursion invariant cannot be
 * accidentally disabled by passing ExclusionRules::none().
 */
final class ExclusionRules {

	/**
	 * The patterns this rule set was constructed with.
	 *
	 * Stored verbatim in construction order. Pattern-type detection
	 * happens lazily on each call to matches().
	 *
	 * @var string[]
	 */
	private array $patterns;

	/**
	 * Construct an ExclusionRules with an explicit list of patterns.
	 *
	 * Most callers should prefer one of the named factories:
	 * {@see ExclusionRules::none()}, {@see ExclusionRules::default_v010()},
	 * or {@see ExclusionRules::from_array()}.
	 *
	 * @param string[] $patterns Patterns to match against relative paths.
	 * @throws InvalidArgumentException If any element of $patterns is not a string.
	 */
	public function __construct( array $patterns = array() ) {
		foreach ( $patterns as $i => $pattern ) {
			if ( ! is_string( $pattern ) ) {
				throw new InvalidArgumentException(
					sprintf( 'ExclusionRules: patterns[%d] must be a string.', (int) $i )
				);
			}
		}
		$this->patterns = $patterns;
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
	 * Three categories of exclusion, all with defensible rationale:
	 *
	 *  1. Pontifex's own working directory — prevents recursive
	 *     archive-of-archives nesting if a previous Pontifex export
	 *     left files in wp-content/pontifex/.
	 *  2. WordPress's own ephemeral cache directory — by WordPress
	 *     convention, wp-content/cache/ holds regenerable cache data
	 *     used by transient and page-cache plugins. Every mature
	 *     backup tool excludes it by default.
	 *  3. Known other-backup-plugin working directories — UpdraftPlus,
	 *     All-in-One WP Migration, Backup Guard, BackWPup, WP Clone,
	 *     and Duplicator each create their own backup-output
	 *     directories. Including these in a Pontifex archive produces
	 *     an archive-of-archives with zero correctness benefit.
	 *
	 * Pontifex's CLI surfaces this list before running an export, so
	 * users see what is being skipped and can override with
	 * --no-defaults or a custom --exclude-file.
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

				// Other backup plugins' working directories.
				'wp-content/updraft/**',
				'wp-content/ai1wm-backups/**',
				'wp-content/backup-guard/**',
				'wp-content/backwpup-*/**',
				'wp-content/wp-clone/**',
				'wp-content/duplicator/**',
				'wp-content/backups-*/**',
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
	 * Decide whether the given path should be excluded from the archive.
	 *
	 * Iterates the patterns in construction order; returns true on
	 * the first match. Each pattern is dispatched to one of four
	 * matchers based on its shape (regex, directory-tree, glob, or
	 * exact-string).
	 *
	 * @param string $relative_path Path relative to the scan root.
	 * @param string $kind          One of the EntryHeader::KIND_* constants.
	 * @return bool True if the path should be excluded; false otherwise.
	 * @throws InvalidArgumentException If $relative_path is empty or $kind is not a recognised entry kind.
	 */
	public function matches( string $relative_path, string $kind ): bool {
		if ( '' === $relative_path ) {
			throw new InvalidArgumentException( 'ExclusionRules::matches: relative_path must be non-empty.' );
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
				sprintf( 'ExclusionRules::matches: kind "%s" is not one of file, directory, symlink, db_chunk.', $kind )
			);
		}

		foreach ( $this->patterns as $pattern ) {
			if ( self::pattern_matches( $pattern, $relative_path ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Return the configured patterns (read-only view).
	 *
	 * @return string[] The patterns, in construction order.
	 */
	public function patterns(): array {
		return $this->patterns;
	}

	/**
	 * Match a single pattern against a single path, dispatching by pattern shape.
	 *
	 * @param string $pattern       The pattern to interpret.
	 * @param string $relative_path The path to test.
	 * @return bool True if the pattern matches the path.
	 */
	private static function pattern_matches( string $pattern, string $relative_path ): bool {
		// Empty patterns never match anything; defensive against malformed config.
		if ( '' === $pattern ) {
			return false;
		}

		// Regex: starts AND ends with "/".
		// Must be at least 2 chars (so "/" alone is exact-string, not malformed regex).
		if ( strlen( $pattern ) >= 2 && '/' === $pattern[0] && '/' === $pattern[ strlen( $pattern ) - 1 ] ) {
			return self::regex_matches( $pattern, $relative_path );
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
	 * @param string $pattern       The PCRE pattern including its / delimiters.
	 * @param string $relative_path The path to test.
	 * @return bool True if the regex matches.
	 * @throws InvalidArgumentException If the pattern is not a valid PCRE expression.
	 */
	private static function regex_matches( string $pattern, string $relative_path ): bool {
		// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged,WordPress.PHP.NoSilencedErrors.Discouraged -- preg_match emits a warning on invalid patterns; we trap it and rethrow with a clearer message.
		$result = @preg_match( $pattern, $relative_path );
		if ( false === $result ) {
			throw new InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $pattern reported verbatim in exception message for diagnostic context; not HTML output.
				sprintf( 'ExclusionRules: pattern "%s" is not a valid regular expression.', $pattern )
			);
		}
		return 1 === $result;
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
	 * comparison. This handles patterns like "wp-content/backwpup-*\/**"
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
