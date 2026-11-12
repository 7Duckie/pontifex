<?php
/**
 * Architecture test: row values are escaped in exactly one place.
 *
 * @package Pontifex\Tests\Unit\Architecture
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Guards the premise that the restore's placeholder-marker repair rests on.
 *
 * WordPress's `wpdb::_real_escape()` finishes by swapping every percent sign for a
 * per-request marker, and archives written by Pontifex 1.1.0 or earlier carried
 * that marker frozen into their SQL. {@see \Pontifex\Restore\PlaceholderMarkerRepair}
 * repairs it on replay, and only in INSERT statements — which is complete only
 * because `WpdbAdapter::encode_value()`, the code that builds every INSERT's row
 * values, is the one place the exporter escapes anything with that call. Every
 * other statement the exporter writes is built from backtick-escaped identifiers
 * or copied verbatim from the server, so none of them can carry a marker.
 *
 * That defect shipped through eleven releases because nothing noticed it. This
 * test is the cheapest defence against its return by the same route: a second
 * call that escapes a value, added in good faith somewhere under src/, would put
 * markers into a statement shape the repair's gate does not cover, and a restore
 * of such an archive would silently leave them in.
 *
 * The check reads the source rather than running it, and tokenises rather than
 * grepping, because several docblocks in this codebase discuss `_real_escape()`
 * legitimately and a test that cries wolf is a test someone weakens. Only real
 * call sites count. The names guarded are the ones that reach `_real_escape()`:
 * the method itself, `wpdb::_escape()`, `wpdb::escape_by_ref()` and the
 * `esc_sql()` function that wraps them. `wpdb::prepare()` also inserts markers
 * into its output but is used for reads throughout the plugin and writes nothing
 * into an archive, so it is deliberately not part of this guard.
 */
final class EscapedRowValuesHaveOneSourceTest extends TestCase {

	/**
	 * Call names that route a value through `_real_escape()`, lower-cased.
	 *
	 * @var array<int, string>
	 */
	private const GUARDED_NAMES = array(
		'_real_escape',
		'_escape',
		'escape_by_ref',
		'esc_sql',
	);

	/**
	 * Under src/, a value is escaped for an archive in exactly one place: WpdbAdapter::encode_value().
	 *
	 * @return void
	 */
	public function test_row_values_are_escaped_in_exactly_one_place(): void {
		$identities = array();
		$locations  = array();

		foreach ( self::plugin_sources() as $relative_path => $code ) {
			foreach ( self::guarded_calls( $code ) as $call ) {
				$identities[] = sprintf( '%s|%s|%s', $relative_path, $call['function'], $call['name'] );
				$locations[]  = sprintf( 'src/%s:%d  %s() inside %s()', $relative_path, $call['line'], $call['name'], $call['function'] );
			}
		}

		$this->assertSame(
			array( 'Manifest/WpdbAdapter.php|encode_value|_real_escape' ),
			$identities,
			self::failure_message( $locations )
		);
	}

	/**
	 * The explanation shown when the single call site is no longer the only one, or has moved.
	 *
	 * @param array<int, string> $found Every guarded call found under src/, formatted.
	 * @return string
	 */
	private static function failure_message( array $found ): string {
		return "Row values must be escaped in exactly one place under src/: WpdbAdapter::encode_value(), via wpdb::_real_escape().\n"
			. "Found:\n" . implode( "\n", $found ) . "\n\n"
			. "PlaceholderMarkerRepair's statement gate (REPAIRED_STATEMENT_PREFIX, which restricts the repair to INSERT statements) is complete only because that is the one place a value is escaped for an archive. "
			. 'wpdb::_real_escape() swaps every percent sign for a per-request marker; an archive that carries a second escaped value in some other statement shape would restore with the marker left in as literal text. '
			. "If you added a second call, the gate in src/Restore/PlaceholderMarkerRepair.php must be widened to cover whatever statement it writes into, with a test, and then this test's expectation updated. "
			. 'If encode_value() moved or was renamed, update this test to name its new home.';
	}

	/**
	 * Read every PHP file under src/, keyed by its path relative to src/.
	 *
	 * @return array<string, string> Relative path => file contents.
	 */
	private static function plugin_sources(): array {
		$root    = dirname( __DIR__, 3 ) . '/src';
		$sources = array();

		// @phpstan-var iterable<SplFileInfo> $files
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, RecursiveDirectoryIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}
			$path = str_replace( '\\', '/', substr( (string) $file->getRealPath(), strlen( $root ) + 1 ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading the plugin's own source in a static architecture check; WP_Filesystem is not loaded in unit tests.
			$sources[ $path ] = (string) file_get_contents( (string) $file->getRealPath() );
		}

		ksort( $sources );
		return $sources;
	}

	/**
	 * Find every real call to a guarded name in a source file.
	 *
	 * Tokenises rather than pattern-matching, so comments, docblocks and strings
	 * never count, and a declaration (`function esc_sql(`) is not a call. Each
	 * hit records the method or function it sits inside, so the single permitted
	 * call site can be named exactly rather than only by file.
	 *
	 * @param string $code The PHP source.
	 * @return array<int, array{line: int, name: string, function: string}>
	 */
	private static function guarded_calls( string $code ): array {
		$tokens = token_get_all( $code );
		$calls  = array();
		$count  = count( $tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			if ( ! is_array( $token ) || T_STRING !== $token[0] ) {
				continue;
			}
			$name = strtolower( (string) $token[1] );
			if ( ! in_array( $name, self::GUARDED_NAMES, true ) ) {
				continue;
			}

			$previous = self::significant_token( $tokens, $i, -1 );
			if ( is_array( $previous ) && T_FUNCTION === $previous[0] ) {
				continue;
			}
			if ( '(' !== self::significant_token( $tokens, $i, 1 ) ) {
				continue;
			}

			$calls[] = array(
				'line'     => (int) $token[2],
				'name'     => $name,
				'function' => self::enclosing_function_name( $tokens, $i ),
			);
		}

		return $calls;
	}

	/**
	 * The name of the named function or method a token sits inside, or '(outside any function)'.
	 *
	 * Walks back to the nearest `function <name>` declaration. A closure
	 * (`function (`) has no name and is skipped, so a call inside one is reported
	 * against the method that contains it.
	 *
	 * @param array<int, array{0:int,1:string,2:int}|string> $tokens The token stream.
	 * @param int                                            $index  Index of the token to locate.
	 * @return string
	 */
	private static function enclosing_function_name( array $tokens, int $index ): string {
		for ( $i = $index - 1; $i >= 0; $i-- ) {
			if ( ! is_array( $tokens[ $i ] ) || T_FUNCTION !== $tokens[ $i ][0] ) {
				continue;
			}
			$next = self::significant_token( $tokens, $i, 1 );
			if ( is_array( $next ) && T_STRING === $next[0] ) {
				return (string) $next[1];
			}
		}

		return '(outside any function)';
	}

	/**
	 * Return the next or previous token that is not whitespace or a comment.
	 *
	 * @param array<int, array{0:int,1:string,2:int}|string> $tokens    The token stream.
	 * @param int                                            $index     Starting index.
	 * @param int                                            $direction +1 forwards, -1 backwards.
	 * @return array{0:int,1:string,2:int}|string|null The token, or null at the stream's edge.
	 */
	private static function significant_token( array $tokens, int $index, int $direction ) {
		for ( $i = $index + $direction; isset( $tokens[ $i ] ); $i += $direction ) {
			$token = $tokens[ $i ];
			if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			return $token;
		}

		return null;
	}
}
