<?php
/**
 * Redacts sensitive values out of a Pontifex diagnostics bundle.
 *
 * @package Pontifex\Cli
 */

declare(strict_types=1);

namespace Pontifex\Cli;

/**
 * Sanitises the text and option values that go into a diagnostics bundle.
 *
 * A diagnostics bundle is meant to be shareable with a maintainer, so it must
 * not leak anything site-specific or secret. This applies the conservative
 * redaction policy from idea-bank Idea 003 — "when in doubt, redact":
 *
 *  - the site URL is replaced with a generic placeholder, so the host is not
 *    identifiable;
 *  - absolute filesystem paths (the WordPress root and wp-content) are replaced
 *    with `{ABSPATH}` / `{WP_CONTENT_DIR}` placeholders, so directory layout and
 *    usernames in paths are not exposed;
 *  - any wp_options value whose name ends in `_key`, `_secret`, `_token`, or
 *    `_password` is masked, so API keys and the like never enter the bundle.
 *
 * Stateless after construction (the URL and path prefixes to redact are fixed
 * per run), so it is safe to reuse across every artifact in a bundle.
 */
final class DiagnosticsRedactor {

	/**
	 * The placeholder the site URL is replaced with.
	 *
	 * `example.invalid` is reserved by RFC 6761 / RFC 2606, so it can never be a
	 * real host.
	 *
	 * @var string
	 */
	public const URL_PLACEHOLDER = 'https://example.invalid';

	/**
	 * The string a masked option value is replaced with.
	 *
	 * @var string
	 */
	public const MASK = '[redacted]';

	/**
	 * Option-name suffixes that mark a value as sensitive.
	 *
	 * @var string
	 */
	private const SENSITIVE_SUFFIX_PATTERN = '/(_key|_secret|_token|_password)$/i';

	/**
	 * The site URL to redact, or '' to skip URL redaction.
	 *
	 * @var string
	 */
	private string $site_url;

	/**
	 * Absolute path prefixes to redact, as [absolute path => placeholder] pairs,
	 * ordered longest-first so a nested path (wp-content) is replaced before the
	 * root that contains it.
	 *
	 * @var array<string, string>
	 */
	private array $path_replacements;

	/**
	 * Construct a redactor for one site's values.
	 *
	 * @param string $site_url        The site URL to redact (empty to skip).
	 * @param string $abspath         The WordPress root path to redact (empty to skip).
	 * @param string $wp_content_dir  The wp-content path to redact (empty to skip).
	 */
	public function __construct( string $site_url, string $abspath, string $wp_content_dir ) {
		$this->site_url = $site_url;

		$replacements = array();
		if ( '' !== $wp_content_dir ) {
			$replacements[ rtrim( $wp_content_dir, '/\\' ) ] = '{WP_CONTENT_DIR}';
		}
		if ( '' !== $abspath ) {
			$replacements[ rtrim( $abspath, '/\\' ) ] = '{ABSPATH}';
		}

		// Apply the longest path first so a nested directory is not left half-redacted
		// by an enclosing prefix.
		uksort(
			$replacements,
			static function ( string $a, string $b ): int {
				return strlen( $b ) <=> strlen( $a );
			}
		);

		$this->path_replacements = $replacements;
	}

	/**
	 * Redact the site URL and absolute paths out of a block of text.
	 *
	 * @param string $text The text to sanitise (a captured command's output, a log file, etc.).
	 * @return string The text with the URL and known absolute paths replaced by placeholders.
	 */
	public function redact_text( string $text ): string {
		if ( '' !== $this->site_url ) {
			$text = str_replace( $this->site_url, self::URL_PLACEHOLDER, $text );
		}

		foreach ( $this->path_replacements as $absolute => $placeholder ) {
			$text = str_replace( $absolute, $placeholder, $text );
		}

		return $text;
	}

	/**
	 * Whether an option name marks its value as sensitive.
	 *
	 * @param string $name The option name.
	 * @return bool True if the name ends in a sensitive suffix.
	 */
	public function is_sensitive_name( string $name ): bool {
		return 1 === preg_match( self::SENSITIVE_SUFFIX_PATTERN, $name );
	}

	/**
	 * Mask an option value when its name marks it sensitive; otherwise pass it through.
	 *
	 * @param string $name  The option name.
	 * @param mixed  $value The option value.
	 * @return mixed The masked placeholder for a sensitive option, or the original value.
	 */
	public function mask_option( string $name, mixed $value ) {
		return $this->is_sensitive_name( $name ) ? self::MASK : $value;
	}
}
