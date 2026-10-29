<?php
/**
 * Minimal global-namespace wpdb stub used only by Pontifex unit tests.
 *
 * @package Pontifex\Tests
 */

declare(strict_types=1);

if ( ! class_exists( 'wpdb' ) ) {
	/**
	 * Minimal stand-in for WordPress's global wpdb class.
	 *
	 * Required only so that PHPUnit can build mock objects against the
	 * type that {@see \Pontifex\Manifest\WpdbAdapter} depends on, when
	 * the real WordPress wpdb class is not loaded (the normal case in
	 * unit tests). Every method returns the simplest valid value and
	 * tests override behaviour per call via PHPUnit's mock API.
	 *
	 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- This stub stands in for WordPress's real wpdb class; the name must match exactly.
	 * phpcs:disable PEAR.NamingConventions.ValidClassName.StartWithCapital -- This stub stands in for WordPress's real wpdb class; the lowercase name must match exactly.
	 * phpcs:disable PSR2.Methods.MethodDeclaration.Underscore -- The real wpdb exposes _real_escape() with a leading underscore; the stub must match that signature.
	 * phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- Stub methods accept the same parameters as the real wpdb so signatures match; tests override behaviour per call.
	 */
	class wpdb {

		/**
		 * The marker the stub swaps in for a percent sign, in the real wpdb's shape.
		 *
		 * The real wpdb escapes a value by replacing every percent sign with a
		 * per-request marker: an opening brace, sixty-four hexadecimal
		 * characters, a closing brace. It is held here, once, so that
		 * _real_escape() and remove_placeholder_escape() cannot drift apart.
		 * Private on purpose: the real wpdb has no such constant, so a test that
		 * reached for it would pass here and fail against the real class.
		 *
		 * @var string
		 */
		private const PLACEHOLDER_ESCAPE = '{0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef}';

		/**
		 * Table prefix used by the real wpdb to scope queries to one site.
		 *
		 * @var string
		 */
		public string $prefix = 'wp_';

		/**
		 * Mirror of the real wpdb->last_error: most recent error message or empty string.
		 *
		 * @var string
		 */
		public string $last_error = '';

		/**
		 * Mirror of the real wpdb->charset: the site's configured connection character set.
		 *
		 * @var string
		 */
		public string $charset = 'utf8mb4';

		/**
		 * Stub for the real wpdb::esc_like(). Replaced via PHPUnit mock in tests.
		 *
		 * @param string $text Raw text to LIKE-escape.
		 * @return string The text, unchanged by the stub.
		 */
		public function esc_like( string $text ): string {
			return $text;
		}

		/**
		 * Stub for the real wpdb::prepare(). Replaced via PHPUnit mock in tests.
		 *
		 * @param string $query Query template (unused in the stub).
		 * @param mixed  ...$args Placeholder values (unused in the stub).
		 * @return string Empty string.
		 */
		public function prepare( string $query, ...$args ): string {
			return '';
		}

		/**
		 * Stub for the real wpdb::get_col(). Replaced via PHPUnit mock in tests.
		 *
		 * @param string $query SQL query (unused in the stub).
		 * @return array<int, string>
		 */
		public function get_col( string $query ): array {
			return array();
		}

		/**
		 * Stub for the real wpdb::get_var(). Replaced via PHPUnit mock in tests.
		 *
		 * @param string $query SQL query (unused in the stub).
		 * @return mixed
		 */
		public function get_var( string $query ) {
			return null;
		}

		/**
		 * Stub for the real wpdb::get_row(). Replaced via PHPUnit mock in tests.
		 *
		 * @param string $query  SQL query (unused in the stub).
		 * @param string $output Output format constant (unused in the stub).
		 * @return mixed
		 */
		public function get_row( string $query, string $output = 'OBJECT' ) {
			return null;
		}

		/**
		 * Stub for the real wpdb::get_results(). Replaced via PHPUnit mock in tests.
		 *
		 * @param string $query  SQL query (unused in the stub).
		 * @param string $output Output format constant (unused in the stub).
		 * @return mixed
		 */
		public function get_results( string $query, string $output = 'OBJECT' ) {
			return array();
		}

		/**
		 * Stub for the real wpdb::_real_escape(). Replaced via PHPUnit mock in tests.
		 *
		 * Like the real method, it ends by replacing every percent sign with the
		 * placeholder marker, which only remove_placeholder_escape() takes out again.
		 *
		 * @param string $value Raw value to escape.
		 * @return string Escaped value; the stub doubles single quotes and marks every percent sign.
		 */
		public function _real_escape( string $value ): string {
			return str_replace( '%', self::PLACEHOLDER_ESCAPE, str_replace( "'", "''", $value ) );
		}

		/**
		 * Stub for the real wpdb::remove_placeholder_escape(). Replaced via PHPUnit mock in tests.
		 *
		 * @param string $query SQL text that may carry the placeholder marker.
		 * @return string The text with every marker turned back into a percent sign.
		 */
		public function remove_placeholder_escape( string $query ): string {
			return str_replace( self::PLACEHOLDER_ESCAPE, '%', $query );
		}

		/**
		 * Stub for the real wpdb::update(). Replaced via PHPUnit mock in tests.
		 *
		 * @param string               $table        Table name (unused in the stub).
		 * @param array<string, mixed> $data         Column => value pairs (unused in the stub).
		 * @param array<string, mixed> $where        Column => value WHERE conditions (unused in the stub).
		 * @param mixed                $format       Value formats (unused in the stub).
		 * @param mixed                $where_format WHERE formats (unused in the stub).
		 * @return int|false Rows affected, or false on error.
		 */
		public function update( string $table, array $data, array $where, $format = null, $where_format = null ) {
			return 0;
		}

		/**
		 * Stub for the real wpdb::query(). Replaced via PHPUnit mock in tests.
		 *
		 * @param string $query SQL query (unused in the stub).
		 * @return mixed Rows affected / true, or false on error.
		 */
		public function query( string $query ) {
			return 0;
		}
	}
}

if ( ! defined( 'ARRAY_A' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- ARRAY_A is a WordPress core constant the test stub must define for code that depends on it; the name must match exactly.
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! defined( 'ARRAY_N' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- ARRAY_N is a WordPress core constant the test stub must define for code that depends on it; the name must match exactly.
	define( 'ARRAY_N', 'ARRAY_N' );
}
