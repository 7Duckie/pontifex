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
 *  - **includes_database** — whether the database is in the archive. A files-only
 *    backup records this false; every other mode records it true.
 *  - **includes_files** — whether file entries are in the archive. A db-only
 *    backup records this false; every other mode records it true. To keep an
 *    ordinary archive byte-identical to a pre-partial one, this field is
 *    serialised only when false, and a reader defaults it to true when absent.
 *  - **excluded_paths** — the exclusion patterns that were applied, so a
 *    destination reader can see exactly what was skipped.
 *
 * A backup must carry at least one half: a scope with neither files nor the
 * database is refused, since an archive of nothing is never intended (ADR 0016).
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
	 * Whether file entries are included (false only for a db-only backup).
	 *
	 * @var bool
	 */
	private bool $includes_files;

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
	 * @param bool     $includes_files     Whether file entries are included; defaults to true.
	 * @throws InvalidArgumentException If any element of $excluded_paths is not a string, or the archive would carry nothing.
	 */
	public function __construct(
		bool $content_only,
		string $content_root,
		bool $includes_core,
		bool $includes_wp_config,
		bool $includes_database,
		array $excluded_paths,
		bool $includes_files = true
	) {
		foreach ( $excluded_paths as $i => $pattern ) {
			if ( ! is_string( $pattern ) ) {
				throw new InvalidArgumentException(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $i is an array index cast to string for diagnostic context; exception message, not HTML output.
					sprintf( 'Scope: excluded_paths[%s] must be a string.', (string) $i )
				);
			}
		}
		if ( ! $includes_files && ! $includes_database ) {
			throw new InvalidArgumentException( 'Scope: a backup must include the files, the database, or both — an archive of neither is refused.' );
		}

		$this->content_only       = $content_only;
		$this->content_root       = $content_root;
		$this->includes_core      = $includes_core;
		$this->includes_wp_config = $includes_wp_config;
		$this->includes_database  = $includes_database;
		$this->includes_files     = $includes_files;
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
	 * Build the scope for a files-only content backup (the `wp-content` tree, no database).
	 *
	 * The same content scope as {@see self::content_only()} but with the database
	 * deliberately left out — a quick file backup that does not pay for the whole
	 * database dump. It stays a content-only archive (it restores only under
	 * `wp-content`), so the content-only restore gate accepts it; only its
	 * database half is absent.
	 *
	 * @param string[] $excluded_paths The exclusion patterns applied to the export.
	 * @return self A files-only content scope.
	 * @throws InvalidArgumentException If any element of $excluded_paths is not a string.
	 */
	public static function files_only( array $excluded_paths ): self {
		return new self( true, 'wp-content', false, false, false, $excluded_paths, true );
	}

	/**
	 * Build the scope for a database-only content backup (the whole database, no files).
	 *
	 * The database with no file entries at all. It stays a content-only archive
	 * (restoring it touches only the database, never core or `wp-config.php`), so
	 * the content-only restore gate accepts it; only its file half is absent. The
	 * content root is recorded as `wp-content` for consistency even though no file
	 * entries reference it.
	 *
	 * @param string[] $excluded_paths The exclusion patterns applied to the export.
	 * @return self A database-only content scope.
	 * @throws InvalidArgumentException If any element of $excluded_paths is not a string.
	 */
	public static function db_only( array $excluded_paths ): self {
		return new self( true, 'wp-content', false, false, true, $excluded_paths, false );
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
	 * Whether file entries are included.
	 *
	 * @return bool True when files are in the archive; false only for a db-only backup.
	 */
	public function includes_files(): bool {
		return $this->includes_files;
	}

	/**
	 * Summary key: whole-site.
	 *
	 * @var string
	 */
	public const SUMMARY_WHOLE_SITE = 'whole_site';

	/**
	 * Summary key: content (wp-content plus the whole database).
	 *
	 * @var string
	 */
	public const SUMMARY_CONTENT = 'content';

	/**
	 * Summary key: files only (wp-content, no database).
	 *
	 * @var string
	 */
	public const SUMMARY_FILES_ONLY = 'files_only';

	/**
	 * Summary key: database only (no files).
	 *
	 * @var string
	 */
	public const SUMMARY_DB_ONLY = 'db_only';

	/**
	 * Classify what this scope holds into one stable key, for a human summary.
	 *
	 * The single source of truth for the four shapes, so a surface that turns a
	 * scope into operator-facing text (the verify verdict) never re-derives the
	 * branch order and cannot drift from another surface. A null scope (a legacy
	 * archive) is the caller's concern — it has no Scope to classify.
	 *
	 * @return string One of the SUMMARY_* constants.
	 */
	public function content_summary_key(): string {
		if ( ! $this->content_only ) {
			return self::SUMMARY_WHOLE_SITE;
		}
		if ( ! $this->includes_files ) {
			return self::SUMMARY_DB_ONLY;
		}
		if ( ! $this->includes_database ) {
			return self::SUMMARY_FILES_ONLY;
		}
		return self::SUMMARY_CONTENT;
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
	 * Field order is fixed so the JSON byte output stays deterministic. The
	 * `includes_files` field is appended only when false (a db-only backup), so
	 * every other archive is byte-identical to one written before the field
	 * existed and the golden conformance archive stays unchanged.
	 *
	 * @return array<string, bool|string|string[]> The canonical scope array.
	 */
	public function to_array(): array {
		$array = array(
			'content_only'       => $this->content_only,
			'content_root'       => $this->content_root,
			'includes_core'      => $this->includes_core,
			'includes_wp_config' => $this->includes_wp_config,
			'includes_database'  => $this->includes_database,
			'excluded_paths'     => $this->excluded_paths,
		);
		if ( ! $this->includes_files ) {
			$array['includes_files'] = false;
		}
		return $array;
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

		// includes_files is optional and defaults to true: an archive written
		// before the field existed, or any non-db-only archive, has files.
		$includes_files = array_key_exists( 'includes_files', $data ) ? $data['includes_files'] : true;
		if ( ! is_bool( $includes_files ) ) {
			throw new InvalidArgumentException( 'Scope: field "includes_files" must be a boolean.' );
		}

		return new self(
			$data['content_only'],
			$data['content_root'],
			$data['includes_core'],
			$data['includes_wp_config'],
			$data['includes_database'],
			$data['excluded_paths'],
			$includes_files
		);
	}
}
