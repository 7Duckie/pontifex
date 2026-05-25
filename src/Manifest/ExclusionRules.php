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
 * The public interface is intentionally minimal so FileScanner (commit
 * 10) can depend on it before the real exclusion logic exists. The
 * full pattern-matching implementation lands in commit 12 along with
 * ManifestBuilder.
 *
 * Public API for commit 10 (stable through v0.1.0):
 *
 *  - {@see ExclusionRules::none()} — factory returning a rule set that
 *    excludes nothing; used by tests and by callers that want to
 *    archive everything FileScanner finds.
 *  - {@see ExclusionRules::matches()} — returns true if the given
 *    relative path (with its kind) should be excluded.
 *
 * Future additions in commit 12 (richer factories such as
 * default_v010() and from_array()) will not change the
 * FileScanner constructor or the matches() method signature.
 */
final class ExclusionRules {

	/**
	 * Patterns to match against relative paths.
	 *
	 * For the commit-10 stub this is always an empty array. Commit 12
	 * will populate it with glob/regex patterns as appropriate.
	 *
	 * @var string[]
	 */
	private array $patterns;

	/**
	 * Construct an ExclusionRules with an explicit list of patterns.
	 *
	 * Most callers should use the {@see ExclusionRules::none()}
	 * factory rather than the constructor directly. Commit 12 will
	 * introduce richer factories.
	 *
	 * @param string[] $patterns Patterns to match against relative paths. Reserved for commit 12.
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
	 * Used by tests and by callers that want the scanner to return
	 * every file, directory, and symlink it finds.
	 *
	 * @return self A rule set with no patterns.
	 */
	public static function none(): self {
		return new self( array() );
	}

	/**
	 * Decide whether the given path should be excluded from the archive.
	 *
	 * Commit-10 matching semantics: exact string match between
	 * $relative_path and any configured pattern. A pattern equal to
	 * the relative path excludes that path. Commit 12 will extend
	 * this with glob and regex matching without changing the method
	 * signature.
	 *
	 * @param string $relative_path Path relative to the scan root.
	 * @param string $kind          One of EntryHeader::KIND_FILE, KIND_DIRECTORY, KIND_SYMLINK.
	 * @return bool True if the path should be excluded; false otherwise.
	 * @throws InvalidArgumentException If $relative_path is empty or $kind is not a recognised scanner kind.
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

		// Commit-10 behaviour: exact string match against any configured pattern.
		// Commit 12 will extend to glob and regex without changing this signature.
		return in_array( $relative_path, $this->patterns, true );
	}

	/**
	 * Return the configured patterns (read-only view).
	 *
	 * Exposed so tests and callers can inspect what a rule set contains.
	 * Commit 12 may extend this with normalisation or expansion, but
	 * the signature stays the same.
	 *
	 * @return string[] The patterns, in construction order.
	 */
	public function patterns(): array {
		return $this->patterns;
	}
}
