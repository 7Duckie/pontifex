<?php
/**
 * Architecture test: no network egress outside the destination component.
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
 * Guards the plugin's central promise: it never touches anyone's cloud.
 *
 * Pontifex has no service, no account and no endpoint. The only way a byte
 * may leave the machine is an offsite destination the operator configures
 * themselves, which uploads a finished archive to a server they own. That
 * promise is stated in docs/PRIVACY.md and in readme.txt, and until this
 * test existed nothing enforced it — a single wp_remote_post() added in
 * good faith anywhere in the codebase would have broken it silently, and
 * the inherited WordPress sniffs actively push contributors towards
 * wp_remote_* as the "correct" way to make a request.
 *
 * The check reads the source rather than running it, and tokenises rather
 * than grepping. A plain string search would fire on prose: several
 * docblocks in this codebase discuss URLs and HTTP legitimately, and a test
 * that cries wolf is a test someone weakens. Only real call sites count.
 */
final class NoNetworkOutsideDestinationTest extends TestCase {

	/**
	 * Function names that reach the network, lower-cased.
	 *
	 * Prefix entries (matched with str_starts_with) cover the families —
	 * curl_init/curl_exec/..., wp_remote_get/wp_remote_post/..., and so on —
	 * so a newly-added member of a family is caught without editing this list.
	 *
	 * @var array<int, string>
	 */
	private const BANNED_PREFIXES = array(
		'curl_',
		'wp_remote_',
		'wp_safe_remote_',
		'ssh2_',
		'ftp_',
		'socket_',
	);

	/**
	 * Exact function names that reach the network, lower-cased.
	 *
	 * @var array<int, string>
	 */
	private const BANNED_EXACT = array(
		'fsockopen',
		'pfsockopen',
		'stream_socket_client',
		'stream_socket_server',
		'get_headers',
		'dns_get_record',
		'gethostbyname',
		'gethostbynamel',
		'checkdnsrr',
		'download_url',
		'wp_mail',
		'mail',
	);

	/**
	 * Directory, relative to src/, permitted to reach the network.
	 *
	 * @var string
	 */
	private const ALLOWED_DIRECTORY = 'Destination';

	/**
	 * No file outside the destination component may call a network function.
	 *
	 * @return void
	 */
	public function test_no_network_calls_outside_the_destination_component(): void {
		$violations = array();

		foreach ( self::plugin_sources() as $relative_path => $code ) {
			if ( str_starts_with( $relative_path, self::ALLOWED_DIRECTORY . '/' ) ) {
				continue;
			}
			foreach ( self::called_function_names( $code ) as $line => $name ) {
				if ( self::is_banned( $name ) ) {
					$violations[] = sprintf( 'src/%s:%d calls %s()', $relative_path, $line, $name );
				}
			}
		}

		$this->assertSame(
			array(),
			$violations,
			"Pontifex must make no network call outside src/Destination/. Found:\n" . implode( "\n", $violations )
		);
	}

	/**
	 * The permitted component must genuinely be where the network code lives.
	 *
	 * Without this, the test above would keep passing if the destination
	 * component were deleted or its transport moved elsewhere — it would be
	 * asserting the absence of something that no longer exists anywhere, which
	 * is the shape of a test that quietly stops testing.
	 *
	 * @return void
	 */
	public function test_the_destination_component_is_where_the_network_code_lives(): void {
		$found = false;

		foreach ( self::plugin_sources() as $relative_path => $code ) {
			if ( ! str_starts_with( $relative_path, self::ALLOWED_DIRECTORY . '/' ) ) {
				continue;
			}
			if ( false !== stripos( $code, 'phpseclib' ) || false !== stripos( $code, 'SFTP' ) ) {
				$found = true;
				break;
			}
		}

		$this->assertTrue(
			$found,
			'src/' . self::ALLOWED_DIRECTORY . '/ no longer contains the network transport; this test is no longer guarding anything.'
		);
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
	 * Extract the names of functions actually CALLED in a source file.
	 *
	 * Tokenises rather than pattern-matching, and skips method calls
	 * (`->name(`), static calls (`Class::name(`) and declarations
	 * (`function name(`), so only free-function invocations are reported.
	 * Comments and strings never reach this, which is the point: prose about
	 * HTTP is not an HTTP call.
	 *
	 * @param string $code The PHP source.
	 * @return array<int, string> Line number => lower-cased function name.
	 */
	private static function called_function_names( string $code ): array {
		$tokens = token_get_all( $code );
		$names  = array();
		$count  = count( $tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			if ( ! is_array( $token ) || T_STRING !== $token[0] ) {
				continue;
			}

			$previous = self::significant_token( $tokens, $i, -1 );
			if ( is_array( $previous ) && in_array( $previous[0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NULLSAFE_OBJECT_OPERATOR ), true ) ) {
				continue;
			}

			if ( '(' !== self::significant_token( $tokens, $i, 1 ) ) {
				continue;
			}

			$names[ (int) $token[2] ] = strtolower( (string) $token[1] );
		}

		return $names;
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

	/**
	 * Whether a called function name reaches the network.
	 *
	 * @param string $name Lower-cased function name.
	 * @return bool True if the name is banned outside the destination component.
	 */
	private static function is_banned( string $name ): bool {
		if ( in_array( $name, self::BANNED_EXACT, true ) ) {
			return true;
		}
		foreach ( self::BANNED_PREFIXES as $prefix ) {
			if ( str_starts_with( $name, $prefix ) ) {
				return true;
			}
		}

		return false;
	}
}
