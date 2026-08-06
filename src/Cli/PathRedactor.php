<?php
/**
 * Replaces sensitive absolute path prefixes with stable placeholders.
 *
 * @package Pontifex\Cli
 */

declare(strict_types=1);

namespace Pontifex\Cli;

/**
 * Replaces known absolute path prefixes in a string with placeholders.
 *
 * Absolute paths in a message — the WordPress root, wp-content, the operator's
 * home directory, the system temp directory, or /root — leak directory layout
 * and usernames when that message is shared (a pasted log, a diagnostics
 * bundle, a screenshot). This swaps each known prefix for a placeholder such as
 * `{WP_CONTENT_DIR}` or `{HOME}`, keeping the message readable (the file under
 * the directory is still named) without exposing where it lives.
 *
 * Matching is path-boundary aware: a prefix is replaced only when it is
 * followed by a path separator or the end of the string, so `{ROOT}` cannot
 * accidentally rewrite the middle of an unrelated path such as `/rootfs/...`.
 *
 * Stateless after construction (the prefixes are fixed per run), so one
 * instance can sanitise many messages.
 */
final class PathRedactor {

	/**
	 * Absolute path prefixes to redact, as [prefix => placeholder] pairs,
	 * ordered longest-first so a nested path (wp-content) is replaced before
	 * the root that contains it.
	 *
	 * @var array<string, string>
	 */
	private array $replacements;

	/**
	 * Construct a redactor from a map of absolute prefixes to placeholders.
	 *
	 * Prefixes that are empty or a single character (e.g. '' or '/') are
	 * dropped: they would match almost everything and redact blindly rather
	 * than precisely.
	 *
	 * @param array<string, string> $replacements Map of [absolute prefix => placeholder].
	 */
	public function __construct( array $replacements ) {
		$safe = array();
		foreach ( $replacements as $prefix => $placeholder ) {
			$trimmed = rtrim( $prefix, '/\\' );
			if ( strlen( $trimmed ) > 1 ) {
				$safe[ $trimmed ] = $placeholder;
			}
		}

		// Apply the longest prefix first so a nested directory is not left
		// half-redacted by an enclosing one.
		uksort(
			$safe,
			static function ( string $a, string $b ): int {
				return strlen( $b ) <=> strlen( $a );
			}
		);

		$this->replacements = $safe;
	}

	/**
	 * Build a redactor for the usual Pontifex path prefixes.
	 *
	 * @param string $abspath        The WordPress root, or '' to skip.
	 * @param string $wp_content_dir The wp-content path, or '' to skip.
	 * @param string $home           The operator's home directory, or '' to skip.
	 * @param string $temp_dir       The system temp directory, or '' to skip.
	 * @return self A redactor covering the supplied prefixes plus /root.
	 */
	public static function from_paths( string $abspath, string $wp_content_dir, string $home, string $temp_dir ): self {
		// Trailing slashes are stripped so a path matches in both the forms it
		// occurs in. ABSPATH carries a trailing slash by WordPress convention, but
		// the code that reports a path has usually rtrim()ed it — so the literal
		// "/var/www/html/" never matched a message saying "/var/www/html", and the
		// server's real path was printed to the operator unredacted. Matching the
		// slash-less form covers both, because the longer form simply leaves the
		// following slash in place after the placeholder.
		return new self(
			array(
				rtrim( $wp_content_dir, '/' ) => '{WP_CONTENT_DIR}',
				rtrim( $abspath, '/' )        => '{ABSPATH}',
				rtrim( $home, '/' )           => '{HOME}',
				rtrim( $temp_dir, '/' )       => '{TMP}',
				'/root'                       => '{ROOT}',
			)
		);
	}

	/**
	 * Build a redactor from the running process's paths.
	 *
	 * Reads the WordPress path constants, the HOME environment variable and the
	 * system temp directory directly, so any CLI command can sanitise a message
	 * without threading path state through its constructor.
	 *
	 * @return self A redactor for this process's paths.
	 */
	public static function from_environment(): self {
		$abspath        = defined( 'ABSPATH' ) ? (string) constant( 'ABSPATH' ) : '';
		$wp_content_dir = defined( 'WP_CONTENT_DIR' ) ? (string) constant( 'WP_CONTENT_DIR' ) : '';
		$home           = (string) getenv( 'HOME' );

		return self::from_paths( $abspath, $wp_content_dir, $home, sys_get_temp_dir() );
	}

	/**
	 * Replace every known path prefix in the text with its placeholder.
	 *
	 * @param string $text The text to sanitise.
	 * @return string The text with known absolute path prefixes replaced.
	 */
	public function redact( string $text ): string {
		foreach ( $this->replacements as $prefix => $placeholder ) {
			// The prefix must not be followed by a character that would continue a
			// path segment, so "/var/www/htmlfoo" is left alone while "/var/www/html"
			// is replaced wherever it genuinely ends. The earlier form required a
			// slash or the end of the string, which meant a path quoted inside a
			// sentence — `… is not inside the site at "/var/www/html".` — never
			// matched, and the server's real path reached the operator unredacted.
			$pattern = '#' . preg_quote( $prefix, '#' ) . '(?![A-Za-z0-9._-])#';
			$result  = preg_replace( $pattern, $placeholder, $text );
			if ( null !== $result ) {
				$text = $result;
			}
		}

		return $text;
	}
}
