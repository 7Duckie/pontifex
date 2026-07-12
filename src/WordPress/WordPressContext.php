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
	 * The table-name prefix the WordPress database is configured to use.
	 *
	 * Equivalent to $wpdb->prefix. Recorded in Provenance (format v1.1) so a
	 * content-only restore can rewrite the source-prefixed table names to the
	 * destination's own prefix.
	 *
	 * @return string e.g. "wp_".
	 */
	public function wpdb_prefix(): string;

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

	/**
	 * Flush WordPress's in-memory and persistent object cache.
	 *
	 * Equivalent to wp_cache_flush(). Called after a restore has replayed the
	 * database with raw SQL: WordPress's option cache (and any persistent object
	 * cache) still holds the pre-restore values, so a later option_value()/
	 * save_option() would read and write against state that no longer matches the
	 * database — silently losing the post-restore counter writes. Flushing makes
	 * subsequent reads and writes see the restored database.
	 *
	 * @return void
	 */
	public function flush_cache(): void;

	/**
	 * The class allowlist for unserialising during a cross-URL migration.
	 *
	 * Resolves the `pontifex_serialized_classes` filter (threat-model §1,
	 * ADR 0006) into the list of class names that
	 * {@see \Pontifex\Migrate\SerialisedReplacer} may decode. The default is
	 * empty — no classes are allowed, so every serialised object decodes to a
	 * harmless incomplete class — and a site owner opts specific trusted
	 * classes back in through the filter.
	 *
	 * The result is always a list of class-name strings. A filter that returns
	 * a non-array (for example `true`, meaning "allow everything") or
	 * non-string entries is coerced away, so a misbehaving filter can never
	 * widen the unserialise allowlist to the gadget-chain surface this guard
	 * exists to close.
	 *
	 * @return string[] Class names permitted when unserialising; empty allows none.
	 */
	public function serialised_classes_allowlist(): array;

	/**
	 * Acquire a site-scoped named database lock without blocking.
	 *
	 * Wraps MySQL's GET_LOCK(). A named lock is granted by the database
	 * server to exactly one connection at a time, atomically: there is no
	 * gap between "is it free?" and "take it" for two simultaneous requests
	 * to race through, which is the flaw a check-then-set transient lock
	 * cannot escape. And because the lock belongs to the connection that
	 * took it, the server releases it the instant that connection dies — a
	 * crashed request can never leave a permanent stale lock behind.
	 *
	 * Named locks are server-wide, not per-database: on shared hosting many
	 * unrelated sites use one database server, so an unscoped name would
	 * wrongly serialise operations across strangers' sites. The
	 * implementation therefore scopes the name to this site (its database
	 * and table prefix) before asking the server.
	 *
	 * The call never waits: if the lock is already held elsewhere it reports
	 * failure immediately. A query error also reports failure — callers must
	 * treat "not acquired" as "do not proceed" (fail closed).
	 *
	 * @param string $name The lock's logical name, unique per operation (e.g. "pontifex_backup_lock").
	 * @return bool True if this connection now holds the lock; false if it is held elsewhere or the query failed.
	 */
	public function acquire_named_lock( string $name ): bool;

	/**
	 * Release a named database lock previously acquired by this request.
	 *
	 * Wraps MySQL's RELEASE_LOCK() with the same site-scoping as
	 * {@see self::acquire_named_lock()}. Releasing a lock this connection
	 * does not hold is harmless — the server simply reports that nothing was
	 * released — so cleanup paths may release unconditionally.
	 *
	 * @param string $name The lock's logical name, as passed to acquire_named_lock().
	 * @return void
	 */
	public function release_named_lock( string $name ): void;

	/**
	 * Open a dedicated second database connection, or report that the host refuses one.
	 *
	 * The consistent-snapshot export (ADR 0011) dumps the database inside a
	 * REPEATABLE READ transaction. That transaction must not live on the global
	 * `$wpdb` connection: mid-export writes (progress transients, counters)
	 * would join it and stay invisible to other requests until commit — the
	 * admin progress bar would freeze. A dedicated connection gives the dump
	 * its own snapshot while the global connection stays live, which is how
	 * standalone dump tools are architected.
	 *
	 * Null means the environment refused a second connection (e.g. a shared
	 * host capping connections per user); callers must degrade gracefully —
	 * the export falls back to the global connection without a snapshot.
	 *
	 * @return wpdb|null A connected second wpdb with the site's table prefix, or null when unavailable.
	 */
	public function dedicated_wpdb_connection(): ?wpdb;
}
