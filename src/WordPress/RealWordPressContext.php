<?php
/**
 * Production WordPressContext implementation.
 *
 * @package Pontifex\WordPress
 */

declare(strict_types=1);

namespace Pontifex\WordPress;

use RuntimeException;
use wpdb;

/**
 * The real WordPressContext Pontifex uses in production.
 *
 * Every method delegates to WordPress's built-in function, global,
 * or property of the same conceptual purpose. The class deliberately
 * has no logic of its own — it is a transparent passthrough to
 * WordPress's runtime state, isolated here so the rest of the
 * codebase can depend on the WordPressContext interface rather than
 * on WordPress functions directly.
 *
 * If a test ever fails because RealWordPressContext is doing
 * something surprising, the bug is in WordPress, not in this class.
 *
 * This class can only be instantiated inside a WordPress request
 * (or under WP-CLI). Calling its methods outside that context will
 * fail because the WordPress globals and functions it delegates to
 * are not available. Production code never constructs this class in
 * non-WordPress contexts; unit tests use a fake implementation
 * instead.
 */
final class RealWordPressContext implements WordPressContext {

	/**
	 * Return the WordPress version string of the running site.
	 *
	 * @return string e.g. "6.6.1".
	 */
	public function wp_version(): string {
		return (string) get_bloginfo( 'version' );
	}

	/**
	 * Return the canonical URL of the running site.
	 *
	 * @return string e.g. "https://example.test".
	 */
	public function site_url(): string {
		return (string) get_site_url();
	}

	/**
	 * Return the global $wpdb instance.
	 *
	 * Reads the WordPress global directly. WordPress guarantees
	 * $wpdb exists after init; commands using this interface declare
	 * the "@when after_wp_load" tag so the global is available by
	 * the time any method here runs.
	 *
	 * @return wpdb The wpdb global object.
	 * @throws RuntimeException If $wpdb is not available (should not happen inside a WordPress request).
	 */
	public function wpdb_instance(): wpdb {
		global $wpdb;
		if ( ! $wpdb instanceof wpdb ) {
			throw new RuntimeException( 'RealWordPressContext: $wpdb global is not available; is WordPress loaded?' );
		}
		return $wpdb;
	}

	/**
	 * Return the character set the WordPress database is configured to use.
	 *
	 * @return string e.g. "utf8mb4".
	 */
	public function wpdb_charset(): string {
		return (string) $this->wpdb_instance()->charset;
	}

	/**
	 * Return the collation the WordPress database is configured to use.
	 *
	 * @return string e.g. "utf8mb4_unicode_520_ci".
	 */
	public function wpdb_collation(): string {
		return (string) $this->wpdb_instance()->collate;
	}

	/**
	 * Return the table-name prefix the WordPress database is configured to use.
	 *
	 * @return string e.g. "wp_".
	 */
	public function wpdb_prefix(): string {
		return (string) $this->wpdb_instance()->prefix;
	}

	/**
	 * Return the version string reported by the database server.
	 *
	 * Returns an empty string if the SELECT VERSION() query returns
	 * no result. The query itself is harmless; the empty-string
	 * fallback exists so DoctorCommand can display "(unknown)"
	 * rather than crashing on a malformed result.
	 *
	 * @return string e.g. "8.0.36" or "10.11.6-MariaDB". Empty if unknown.
	 */
	public function db_server_version(): string {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Static read-only diagnostic query; no user input, no prepared-statement need, no caching benefit.
		$result = $this->wpdb_instance()->get_var( 'SELECT VERSION()' );
		return null === $result ? '' : (string) $result;
	}

	/**
	 * Return the absolute path of the WordPress uploads directory's basedir.
	 *
	 * WordPress's own stubs guarantee wp_upload_dir() returns an
	 * array with a 'basedir' key, so we cast directly without a
	 * defensive guard. If wp_upload_dir() ever changes shape,
	 * PHPStan will catch it before runtime does.
	 *
	 * @return string An absolute filesystem path.
	 */
	public function upload_dir_basedir(): string {
		$info = wp_upload_dir();
		return (string) $info['basedir'];
	}

	/**
	 * Convert a human-readable size string into bytes.
	 *
	 * Delegates to wp_convert_hr_to_bytes(). For the special "-1"
	 * value (the "unlimited" sentinel) WordPress returns 0; callers
	 * who care about that distinction must check the original string
	 * themselves before calling.
	 *
	 * @param string $value e.g. "256M".
	 * @return int Byte count (non-negative).
	 */
	public function convert_hr_to_bytes( string $value ): int {
		return (int) wp_convert_hr_to_bytes( $value );
	}

	/**
	 * Return the effective upload-size ceiling for the running site.
	 *
	 * @return int Maximum bytes a user can upload via the admin UI.
	 */
	public function max_upload_size(): int {
		return (int) wp_max_upload_size();
	}

	/**
	 * Format a byte count as a human-readable size string.
	 *
	 * Delegates to size_format(). Returns an empty string if
	 * size_format() returns false (which it does for non-positive
	 * inputs).
	 *
	 * @param int $bytes The byte count to format.
	 * @return string A human-readable size string, or empty on failure.
	 */
	public function format_size( int $bytes ): string {
		$formatted = size_format( $bytes );
		return false === $formatted ? '' : (string) $formatted;
	}

	/**
	 * Read a stored WordPress option, or a default when it is absent.
	 *
	 * @param string $name     The option name.
	 * @param mixed  $fallback Value to return when the option is absent.
	 * @return mixed The stored value, or $fallback if the option is absent.
	 */
	public function option_value( string $name, mixed $fallback = false ): mixed {
		return get_option( $name, $fallback );
	}

	/**
	 * Create or update a stored WordPress option.
	 *
	 * @param string $name     The option name.
	 * @param mixed  $value    The value to store.
	 * @param bool   $autoload Whether WordPress should autoload it.
	 * @return void
	 */
	public function save_option( string $name, mixed $value, bool $autoload = false ): void {
		update_option( $name, $value, $autoload );
	}

	/**
	 * Flush WordPress's object cache after a raw-SQL database replay.
	 *
	 * @return void
	 */
	public function flush_cache(): void {
		wp_cache_flush();
	}

	/**
	 * Resolve the cross-URL migration class allowlist from the filter.
	 *
	 * Reads `apply_filters( 'pontifex_serialized_classes', array() )` and keeps
	 * only non-empty string entries. This is the one method here that is not a
	 * pure passthrough: it deliberately coerces the filter's output, because
	 * the value guards a security boundary — the unserialise allowlist in
	 * {@see \Pontifex\Migrate\SerialisedReplacer} — and must never be widened
	 * to "all classes" or polluted with non-class junk by a misbehaving filter.
	 *
	 * @return string[] Class names permitted when unserialising; empty allows none.
	 */
	public function serialised_classes_allowlist(): array {
		/**
		 * Filters the classes Pontifex permits when unserialising during a cross-URL migration.
		 *
		 * Return an array of fully-qualified class names to opt them into the
		 * allowlist. The default — an empty array — allows no classes, so every
		 * serialised object decodes to a harmless incomplete class.
		 *
		 * @since 0.3.0
		 *
		 * @param string[] $classes Class names to permit when unserialising. Default empty.
		 */
		$allowed = apply_filters( 'pontifex_serialized_classes', array() );

		if ( ! is_array( $allowed ) ) {
			return array();
		}

		$classes = array();
		foreach ( $allowed as $class ) {
			if ( is_string( $class ) && '' !== $class ) {
				$classes[] = $class;
			}
		}
		return array_values( $classes );
	}

	/**
	 * Acquire a site-scoped named database lock without blocking.
	 *
	 * The lock name is scoped server-side: DATABASE() and the table prefix
	 * are folded into an MD5 hash, so the name is unique to this site and
	 * always fits MySQL's 64-character lock-name limit regardless of how
	 * long the database name or prefix is. The zero timeout means the call
	 * never waits. Only the literal '1' result — "this connection now holds
	 * the lock" — counts as acquired; '0' (held by another connection) and
	 * NULL (query error) both report failure, so an error fails closed.
	 *
	 * The name expression must stay identical to release_named_lock()'s, or
	 * a release would target a different lock than the acquire took.
	 *
	 * @param string $name The lock's logical name.
	 * @return bool True if this connection now holds the lock; false if it is held elsewhere or the query failed.
	 */
	public function acquire_named_lock( string $name ): bool {
		$wpdb = $this->wpdb_instance();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- The argument is a $wpdb->prepare() call; a lock acquisition is inherently uncacheable and has no higher-level WordPress API.
		$result = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT GET_LOCK(CONCAT('pontifex_', MD5(CONCAT_WS('|', DATABASE(), %s))), 0)",
				$wpdb->prefix . '|' . $name
			)
		);
		return '1' === (string) $result;
	}

	/**
	 * Release a named database lock previously acquired by this request.
	 *
	 * Computes the same site-scoped name as acquire_named_lock() and asks
	 * the server to release it. Releasing a lock this connection does not
	 * hold is harmless: RELEASE_LOCK() reports 0 or NULL and changes
	 * nothing, so cleanup paths may call this unconditionally.
	 *
	 * @param string $name The lock's logical name, as passed to acquire_named_lock().
	 * @return void
	 */
	public function release_named_lock( string $name ): void {
		$wpdb = $this->wpdb_instance();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- The argument is a $wpdb->prepare() call; a lock release is inherently uncacheable and has no higher-level WordPress API.
		$wpdb->get_var(
			$wpdb->prepare(
				"SELECT RELEASE_LOCK(CONCAT('pontifex_', MD5(CONCAT_WS('|', DATABASE(), %s))))",
				$wpdb->prefix . '|' . $name
			)
		);
	}

	/**
	 * Open a dedicated second database connection, or null when the host refuses.
	 *
	 * Built from the same DB_* constants the global connection used, through
	 * {@see DedicatedWpdb} so a refused connection reports failure instead of
	 * dying inside wp_die() mid-request. The site's table prefix is adopted
	 * from the global connection so per-table scope guards behave identically.
	 *
	 * @return wpdb|null A connected second wpdb, or null when unavailable.
	 */
	public function dedicated_wpdb_connection(): ?wpdb {
		if ( ! defined( 'DB_USER' ) || ! defined( 'DB_PASSWORD' ) || ! defined( 'DB_NAME' ) || ! defined( 'DB_HOST' ) ) {
			return null;
		}
		$connection = new DedicatedWpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		if ( ! $connection->is_connected() ) {
			return null;
		}
		$connection->set_prefix( (string) $this->wpdb_instance()->prefix );
		return $connection;
	}
}
