<?php
/**
 * Pontifex dedicated wpdb — a second database connection that never bails.
 *
 * @package Pontifex\WordPress
 */

declare(strict_types=1);

namespace Pontifex\WordPress;

use wpdb;

/**
 * A `wpdb` that reports a failed connection instead of dying.
 *
 * The stock `wpdb` constructor connects with `$allow_bail = true`, so a host
 * that refuses a second connection (e.g. `max_user_connections = 1` on cheap
 * shared hosting) would `wp_die` the whole request. Pontifex opens a second
 * connection only as an upgrade — the consistent-snapshot export (ADR 0011) —
 * and must fall back gracefully when the host says no, so this subclass
 * connects quietly and exposes whether it succeeded.
 */
final class DedicatedWpdb extends wpdb {

	/**
	 * Whether the constructor's connection attempt succeeded.
	 *
	 * @var bool
	 */
	private bool $connected = false;

	/**
	 * Connect without ever bailing, recording the outcome.
	 *
	 * Called by the parent constructor; the $allow_bail argument is
	 * deliberately overridden to false so a refused connection returns
	 * here instead of dying inside `wp_die()`.
	 *
	 * @param bool $allow_bail Ignored; the parent is always called with false.
	 * @return bool True when the connection was established.
	 */
	public function db_connect( $allow_bail = true ) {
		unset( $allow_bail );
		$this->connected = (bool) parent::db_connect( false );
		return $this->connected;
	}

	/**
	 * Whether this instance holds a live database connection.
	 *
	 * @return bool True when the constructor's connection attempt succeeded.
	 */
	public function is_connected(): bool {
		return $this->connected;
	}

	/**
	 * Close the connection when this instance is finally garbage-collected.
	 *
	 * A last-resort tidy-up, not the release mechanism: WordPress retains
	 * hidden references to every wpdb instance, so this destructor only runs
	 * at script shutdown (found empirically). The snapshot's metadata locks
	 * are released deterministically by
	 * {@see \Pontifex\Manifest\WpdbAdapter::__destruct()} committing when the
	 * export's adapter goes out of scope.
	 */
	public function __destruct() {
		if ( $this->connected ) {
			$this->close();
		}
	}
}
