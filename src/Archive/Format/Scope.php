<?php
/**
 * Pontifex archive scope — what a given archive backed up (content-only vs whole-site).
 *
 * @package Pontifex\Archive\Format
 */

declare(strict_types=1);

namespace Pontifex\Archive\Format;

use InvalidArgumentException;

/**
 * Immutable value object describing what an archive backed up.
 *
 * Forms the optional "scope" sub-object inside the Provenance JSON (added in
 * format v1.1). It lets a reader tell, without unpacking anything, whether an
 * archive is a **content-only** backup (the `wp-content` tree plus the whole
 * database, the everyday working-WordPress-to-working-WordPress default) or a
 * **whole-site** backup that also carries WordPress core and `wp-config.php`
 * (the explicit opt-in for cloning onto a bare destination). The scope decision
 * itself is recorded in ADR 0008.
 *
 * The block's absence is meaningful: an archive with no `scope` predates this
 * field and is treated as a legacy whole-site archive by readers that
 * understand the distinction.
 *
 * Fields:
 *
 *  - **content_only** — true for a content-only archive, false for whole-site.
 *  - **content_root** — the directory the file entries are relative to and land
 *    under, as a path relative to the WordPress root (`wp-content` for a
 *    content-only archive; the empty string for a whole-site archive, whose
 *    entries are rooted at the site root itself).
 *  - **includes_core** — whether WordPress core (`wp-admin`, `wp-includes`, the
 *    root core PHP files) is in the archive.
 *  - **includes_wp_config** — whether `wp-config.php` is in the archive.
 *  - **includes_database** — whether the database is in the archive (always true
 *    for both modes today; recorded so the "files-only scoping, database still
 *    whole" promise is legible).
 *  - **excluded_paths** — the exclusion patterns that were applied, so a
 *    destination reader can see exactly what was skipped.
 *
 * Immutable after construction; safe to share.
 */
final class Scope {

	/**
	 * Whether this is a content-only archive (true) or a whole-site archive (false).
	 *
	 * @var bool
	 */
	private bool $content_only;

	/**
	 * The path the file entries are relative to, relative to the WordPress root.
	 *
	 * `wp-content` for a content-only archive; the empty string for a whole-site
	 * archive (whose entries are rooted at the site root).
	 *
	 * @var string
	 */
	private string $content_root;

	/**
	 * Whether WordPress core files are included.
	 *
	 * @var bool
	 */
	private bool $includes_core;

	/**
	 * Whether `wp-config.php` is included.
	 *
	 * @var bool
	 */
	private bool $includes_wp_config;

	/**
	 * Whether the database is included.
	 *
	 * @var bool
	 */
	private bool $includes_database;

	/**
	 * The exclusion patterns applied to the export, in the order they were applied.
	 *
	 * @var string[]
	 */
	private array $excluded_paths;

	/**
	 * Construct a Scope describing what an archive backed up.
	 *
	 * @param bool     $content_only       True for content-only, false for whole-site.
	 * @param string   $content_root       The entries' root relative to the WordPress root (`wp-content`, or '' for whole-site).
	 * @param bool     $includes_core      Whether WordPress core is included.
	 * @param bool     $includes_wp_config Whether `wp-config.php` is included.
	 * @param bool     $includes_database  Whether the database is included.
	 * @param string[] $excluded_paths     The applied exclusion patterns.
	 * @throws InvalidArgumentException If any element of $excluded_paths is not a string.
	 */
	public function __construct(
		bool $content_only,
		string $content_root,
		bool $includes_core,
		bool $includes_wp_config,
		bool $includes_database,
		array $excluded_paths
	) {
		foreach ( $excluded_paths as $i => $pattern ) {
			if ( ! is_string( $pattern ) ) {
				throw new InvalidArgumentException(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $i is an array index cast to string for diagnostic context; exception message, not HTML output.
					sprintf( 'Scope: excluded_paths[%s] must be a string.', (string) $i )
				);
			}
		}

		$this->content_only       = $content_only;
		$this->content_root       = $content_root;
		$this->includes_core      = $includes_core;
		$this->includes_wp_config = $includes_wp_config;
		$this->includes_database  = $includes_database;
		$this->excluded_paths     = array_values( $excluded_paths );
	}

	/**
	 * Build the scope for a content-only archive (the everyday default).
	 *
	 * A content-only archive carries the `wp-content` tree plus the whole database
	 * and deliberately omits WordPress core and `wp-config.php` (ADR 0008). This
	 * factory fixes those facts in one place so no caller can record an
	 * inconsistent content-only scope; the caller supplies only the exclusion
	 * patterns that were actually applied.
	 *
	 * @param string[] $excluded_paths The exclusion patterns applied to the export.
	 * @return self A content-only scope.
	 * @throws InvalidArgumentException If any element of $excluded_paths is not a string.
	 */
	public static function content_only( array $excluded_paths ): self {
		return new self( true, 'wp-content', false, false, true, $excluded_paths );
	}

	/**
	 * Build the scope for a whole-site archive (the explicit clone-onto-bare opt-in).
	 *
	 * A whole-site archive carries everything under the WordPress root — WordPress
	 * core and `wp-config.php` included — plus the whole database, so its entries
	 * are rooted at the site root itself rather than under `wp-content` (ADR 0008).
	 * This factory fixes those facts in one place; the caller supplies only the
	 * exclusion patterns that were actually applied.
	 *
	 * @param string[] $excluded_paths The exclusion patterns applied to the export.
	 * @return self A whole-site scope.
	 * @throws InvalidArgumentException If any element of $excluded_paths is not a string.
	 */
	public static function whole_site( array $excluded_paths ): self {
		return new self( false, '', true, true, true, $excluded_paths );
	}

	/**
	 * Whether this is a content-only archive.
	 *
	 * @return bool True for content-only, false for whole-site.
	 */
	public function is_content_only(): bool {
		return $this->content_only;
	}

	/**
	 * The entries' root, relative to the WordPress root.
	 *
	 * @return string `wp-content` for content-only, '' for whole-site.
	 */
	public function content_root(): string {
		return $this->content_root;
	}

	/**
	 * Whether WordPress core is included.
	 *
	 * @return bool True when core is in the archive.
	 */
	public function includes_core(): bool {
		return $this->includes_core;
	}

	/**
	 * Whether `wp-config.php` is included.
	 *
	 * @return bool True when `wp-config.php` is in the archive.
	 */
	public function includes_wp_config(): bool {
		return $this->includes_wp_config;
	}

	/**
	 * Whether the database is included.
	 *
	 * @return bool True when the database is in the archive.
	 */
	public function includes_database(): bool {
		return $this->includes_database;
	}

	/**
	 * The applied exclusion patterns.
	 *
	 * @return string[] The patterns, in application order.
	 */
	public function excluded_paths(): array {
		return $this->excluded_paths;
	}

	/**
	 * Encode the scope to its canonical array form for the Provenance JSON.
	 *
	 * Field order is fixed so the JSON byte output stays deterministic.
	 *
	 * @return array<string, bool|string|string[]> The canonical scope array.
	 */
	public function to_array(): array {
		return array(
			'content_only'       => $this->content_only,
			'content_root'       => $this->content_root,
			'includes_core'      => $this->includes_core,
			'includes_wp_config' => $this->includes_wp_config,
			'includes_database'  => $this->includes_database,
			'excluded_paths'     => $this->excluded_paths,
		);
	}

	/**
	 * Build a Scope from its decoded JSON array, validating each field.
	 *
	 * @param array<array-key, mixed> $data The decoded `scope` object.
	 * @return self A Scope reflecting the decoded data.
	 * @throws InvalidArgumentException If a field is missing or has the wrong type.
	 */
	public static function from_array( array $data ): self {
		foreach ( array( 'content_only', 'includes_core', 'includes_wp_config', 'includes_database' ) as $bool_field ) {
			if ( ! array_key_exists( $bool_field, $data ) || ! is_bool( $data[ $bool_field ] ) ) {
				throw new InvalidArgumentException(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $bool_field is a hardcoded literal from the list above; exception message, not HTML output.
					sprintf( 'Scope: field "%s" must be a boolean.', $bool_field )
				);
			}
		}
		if ( ! array_key_exists( 'content_root', $data ) || ! is_string( $data['content_root'] ) ) {
			throw new InvalidArgumentException( 'Scope: field "content_root" must be a string.' );
		}
		if ( ! array_key_exists( 'excluded_paths', $data ) || ! is_array( $data['excluded_paths'] ) ) {
			throw new InvalidArgumentException( 'Scope: field "excluded_paths" must be an array of strings.' );
		}

		return new self(
			$data['content_only'],
			$data['content_root'],
			$data['includes_core'],
			$data['includes_wp_config'],
			$data['includes_database'],
			$data['excluded_paths']
		);
	}
}
