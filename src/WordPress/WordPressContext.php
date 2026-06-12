<?php
/**
 * WordPress context abstraction interface.
 *
 * @package Pontifex\WordPress
 */

declare(strict_types=1);

namespace Pontifex\WordPress;

use wpdb;

/**
 * Abstracts the WordPress-specific facts and utility functions Pontifex queries.
 *
 * Where {@see \Pontifex\Environment\Environment} abstracts the PHP
 * runtime and filesystem (PHP version, ini directives, extension
 * presence, disk space), WordPressContext abstracts the WordPress
 * layer that sits on top: which WordPress version is running, what
 * the site URL is, what the database character set is, and the
 * various WordPress utility functions for byte conversion and
 * upload-size reporting.
 *
 * The two interfaces are deliberately separate. PHP-runtime facts
 * are stable across hosts; WordPress facts depend on what WordPress
 * has loaded. Mixing them in one abstraction would muddy what each
 * is for.
 *
 * Pontifex's commands accept a WordPressContext via the constructor
 * and never touch get_bloginfo(), get_site_url(), $wpdb, or any
 * other WordPress function directly. This makes the commands
 * unit-testable without WordPress being loaded — tests pass a fake
 * WordPressContext that returns deterministic values.
 *
 * A real implementation ({@see RealWordPressContext}) delegates to
 * WordPress's actual functions and globals. Tests pass a fake
 * implementation that returns whatever the test specifies.
 *
 * New methods may be added as new commands require them. Methods
 * are named for the question they answer ("wp_version", not
 * "get_bloginfo"), not for the WordPress function they happen to
 * wrap. The method names are part of Pontifex's stable internal
 * vocabulary and should outlive any single WordPress API.
 *
 * Why some methods are not here:
 *
 *  - WP_CLI::log, WP_CLI::error, WP_CLI::confirm, etc. are output
 *    and control-flow primitives. They behave like echo and exit
 *    rather than reading state. Mocking them buys little — testing
 *    "does the command call WP_CLI::log with this string?" is
 *    testing output formatting, not logic. Those calls stay direct
 *    in command classes.
 *  - PHP constants like ABSPATH and WP_CONTENT_DIR are accessible
 *    via Environment::constant_value(), which is the right
 *    abstraction for them (they're PHP constants WordPress
 *    happens to define, not WordPress state).
 */
interface WordPressContext {

	/**
	 * The WordPress version string of the running site.
	 *
	 * Equivalent to get_bloginfo('version'), surfaced through the
	 * interface so it can be stubbed in tests.
	 *
	 * @return string e.g. "6.6.1".
	 */
	public function wp_version(): string;

	/**
	 * The canonical URL of the running site.
	 *
	 * Equivalent to get_site_url(). Used by ExportCommand when
	 * building the Provenance block.
	 *
	 * @return string e.g. "https://example.test".
	 */
	public function site_url(): string;

	/**
	 * The global $wpdb instance.
	 *
	 * Returned so callers can wrap it in a WpdbAdapter or otherwise
	 * pass it to code that needs the raw object. Most callers should
	 * prefer the higher-level methods on this interface; this is the
	 * escape hatch for the few places that genuinely need the wpdb
	 * instance itself.
	 *
	 * @return wpdb The wpdb global object.
	 */
	public function wpdb_instance(): wpdb;

	/**
	 * The character set the WordPress database is configured to use.
	 *
	 * Equivalent to $wpdb->charset. Recorded in Provenance so the
	 * restore side can verify charset compatibility.
	 *
	 * @return string e.g. "utf8mb4".
	 */
	public function wpdb_charset(): string;

	/**
	 * The collation the WordPress database is configured to use.
	 *
	 * Equivalent to $wpdb->collate. Recorded in Provenance.
	 *
	 * @return string e.g. "utf8mb4_unicode_520_ci".
	 */
	public function wpdb_collation(): string;

	/**
	 * The version string reported by the database server.
	 *
	 * Equivalent to $wpdb->get_var('SELECT VERSION()'). Used by
	 * DoctorCommand's environment audit; informational.
	 *
	 * @return string e.g. "8.0.36" or "10.11.6-MariaDB".
	 *                Returns an empty string if the query fails.
	 */
	public function db_server_version(): string;

	/**
	 * The absolute path of the WordPress uploads directory's basedir.
	 *
	 * Equivalent to wp_upload_dir()['basedir']. Used by DoctorCommand
	 * to check writability. WordPress guarantees the basedir key
	 * always exists.
	 *
	 * @return string An absolute filesystem path.
	 */
	public function upload_dir_basedir(): string;

	/**
	 * Convert a human-readable size string into bytes.
	 *
	 * Equivalent to wp_convert_hr_to_bytes(). Handles "256M", "1G",
	 * "512K" and similar. Returns 0 for the special "-1" value
	 * (which WordPress treats as the "unlimited" sentinel).
	 *
	 * @param string $value e.g. "256M".
	 * @return int Byte count.
	 */
	public function convert_hr_to_bytes( string $value ): int;

	/**
	 * The effective upload-size ceiling for the running site.
	 *
	 * Equivalent to wp_max_upload_size(). Takes both
	 * upload_max_filesize and post_max_size into account, plus any
	 * filters.
	 *
	 * @return int Maximum bytes a user can upload via the admin UI.
	 */
	public function max_upload_size(): int;

	/**
	 * Format a byte count as a human-readable size string.
	 *
	 * Equivalent to size_format(). Returns strings like "256 MB",
	 * "1 GB".
	 *
	 * @param int $bytes The byte count to format.
	 * @return string A human-readable size string.
	 *                Returns an empty string if size_format cannot format the value (e.g. zero or negative).
	 */
	public function format_size( int $bytes ): string;

	/**
	 * Read a stored WordPress option, or a default when it is absent.
	 *
	 * Equivalent to get_option(). Pontifex reads its own persisted
	 * state through this method (for example the export counters). The
	 * value is returned as stored; callers coerce it to the shape they
	 * expect.
	 *
	 * @param string $name     The option name.
	 * @param mixed  $fallback Value to return when the option is absent. Defaults to false, matching get_option.
	 * @return mixed The stored value, or $fallback if the option is absent.
	 */
	public function option_value( string $name, mixed $fallback = false ): mixed;

	/**
	 * Create or update a stored WordPress option.
	 *
	 * Equivalent to update_option(). Pontifex persists its own state
	 * (for example the export counters) through this method. Autoload
	 * defaults to false: this state is read rarely and should stay out
	 * of the alloptions cache.
	 *
	 * @param string $name     The option name.
	 * @param mixed  $value    The value to store.
	 * @param bool   $autoload Whether WordPress should autoload it. Defaults to false.
	 * @return void
	 */
	public function save_option( string $name, mixed $value, bool $autoload = false ): void;
}
