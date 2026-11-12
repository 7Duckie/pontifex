<?php
/**
 * Unit tests for the absolute-path redactor.
 *
 * @package Pontifex\Tests\Unit\Cli
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Pontifex\Cli\PathRedactor;

/**
 * Behavioural coverage of {@see PathRedactor}: the shared helper that swaps
 * absolute path prefixes for placeholders so a shared message does not leak
 * directory layout or usernames.
 */
final class PathRedactorTest extends TestCase {

	/**
	 * Each known prefix is replaced with its placeholder.
	 *
	 * @return void
	 */
	public function test_redact_replaces_each_known_prefix(): void {
		$redactor = new PathRedactor(
			array(
				'/var/www/html' => '{ABSPATH}',
				'/home/alice'   => '{HOME}',
				'/tmp'          => '{TMP}',
			)
		);

		$this->assertSame( '{ABSPATH}/wp-config.php', $redactor->redact( '/var/www/html/wp-config.php' ) );
		$this->assertSame( '{HOME}/keys/secret.key', $redactor->redact( '/home/alice/keys/secret.key' ) );
		$this->assertSame( '{TMP}/pontifex.wpmig', $redactor->redact( '/tmp/pontifex.wpmig' ) );
	}

	/**
	 * A nested path is redacted by its most specific prefix, longest-first.
	 *
	 * @return void
	 */
	public function test_redact_redacts_nested_paths_longest_first(): void {
		$redactor = new PathRedactor(
			array(
				'/var/www/html'            => '{ABSPATH}',
				'/var/www/html/wp-content' => '{WP_CONTENT_DIR}',
			)
		);

		$this->assertSame(
			'{WP_CONTENT_DIR}/pontifex/logs/pontifex.log',
			$redactor->redact( '/var/www/html/wp-content/pontifex/logs/pontifex.log' )
		);
		$this->assertSame( '{ABSPATH}/wp-load.php', $redactor->redact( '/var/www/html/wp-load.php' ) );
	}

	/**
	 * A prefix is matched only at a path boundary, never mid-segment.
	 *
	 * @return void
	 */
	public function test_redact_matches_only_at_a_path_boundary(): void {
		$redactor = new PathRedactor( array( '/root' => '{ROOT}' ) );

		$this->assertSame( '{ROOT}/keys/secret.key', $redactor->redact( '/root/keys/secret.key' ) );
		$this->assertSame( '{ROOT}', $redactor->redact( '/root' ) );
		// /rootfs is a different directory and must be left intact.
		$this->assertSame( '/rootfs/data', $redactor->redact( '/rootfs/data' ) );
	}

	/**
	 * Empty or single-character prefixes are dropped, never redacting blindly.
	 *
	 * @return void
	 */
	public function test_constructor_drops_empty_and_root_prefixes(): void {
		$redactor = new PathRedactor(
			array(
				''  => '{EMPTY}',
				'/' => '{SLASH}',
			)
		);

		$this->assertSame( 'left /var/www alone', $redactor->redact( 'left /var/www alone' ) );
	}

	/**
	 * Text with no known prefix is returned unchanged.
	 *
	 * @return void
	 */
	public function test_redact_leaves_unknown_paths_untouched(): void {
		$redactor = new PathRedactor( array( '/home/alice' => '{HOME}' ) );

		$this->assertSame( 'see /var/log/syslog for detail', $redactor->redact( 'see /var/log/syslog for detail' ) );
	}

	/**
	 * The from_paths factory covers the WordPress, home, temp and /root prefixes.
	 *
	 * @return void
	 */
	public function test_from_paths_covers_the_usual_prefixes(): void {
		$redactor = PathRedactor::from_paths( '/var/www/html', '/var/www/html/wp-content', '/home/bob', '/tmp' );

		$this->assertSame( '{WP_CONTENT_DIR}/uploads', $redactor->redact( '/var/www/html/wp-content/uploads' ) );
		$this->assertSame( '{ABSPATH}/wp-load.php', $redactor->redact( '/var/www/html/wp-load.php' ) );
		$this->assertSame( '{HOME}/keys', $redactor->redact( '/home/bob/keys' ) );
		$this->assertSame( '{TMP}/x', $redactor->redact( '/tmp/x' ) );
		$this->assertSame( '{ROOT}/y', $redactor->redact( '/root/y' ) );
	}

	/**
	 * The from_environment factory wires the system temp directory.
	 *
	 * @return void
	 */
	public function test_from_environment_redacts_the_temp_dir(): void {
		$redactor = PathRedactor::from_environment();

		$this->assertSame( '{TMP}/example.wpmig', $redactor->redact( sys_get_temp_dir() . '/example.wpmig' ) );
	}

	/**
	 * The from_paths() factory still redacts correctly when every supplied
	 * prefix carries a trailing slash, exactly the shape WordPress hands
	 * ABSPATH in by convention.
	 *
	 * BUG: the stored prefix used to keep whatever trailing slash the caller
	 * passed in. ABSPATH always carries one, but the code that goes on to
	 * report a path has usually rtrim()ed it first, so a stored prefix of
	 * "/var/www/html/" never matched a message reading "/var/www/html" — the
	 * server's real filesystem path (its directory layout, the site's
	 * username) reached the operator completely unredacted. The existing
	 * coverage ({@see self::test_from_paths_covers_the_usual_prefixes()})
	 * only ever passes slash-less prefixes, which is why the bug survived it.
	 *
	 * @return void
	 */
	public function test_from_paths_redacts_correctly_when_every_prefix_carries_a_trailing_slash(): void {
		$redactor = PathRedactor::from_paths( '/var/www/html/', '/var/www/html/wp-content/', '/home/bob/', '/tmp/' );

		$this->assertSame( '{ABSPATH}/wp-config.php', $redactor->redact( '/var/www/html/wp-config.php' ) );
		$this->assertSame( '{ABSPATH}', $redactor->redact( '/var/www/html' ) );
		$this->assertSame( '{WP_CONTENT_DIR}/uploads', $redactor->redact( '/var/www/html/wp-content/uploads' ) );
	}

	/**
	 * A path quoted inside a sentence — followed by a closing quote, a comma,
	 * or a bracket rather than a slash or the end of the string — is still
	 * redacted, and the path-boundary rule still refuses to over-match.
	 *
	 * BUG: the earlier matching rule required a prefix to be followed by a
	 * path separator or the end of the string. A path quoted inside a
	 * sentence — for example an archive-confinement refusal reading
	 * `… is not inside the site at "/var/www/html".` — is followed by a
	 * closing quote and a full stop, neither a slash nor the end of the
	 * string, so it never matched and the real server path reached the
	 * operator unredacted. Both halves are asserted here: a fix that
	 * over-matches (redacting "/var/www/htmlfoo" or "/var/www/html.bak") is
	 * just as much a new bug as the one it replaces.
	 *
	 * @return void
	 */
	public function test_redact_matches_a_path_quoted_inside_a_sentence_and_still_respects_the_boundary(): void {
		$redactor = new PathRedactor(
			array(
				'/var/www/html' => '{ABSPATH}',
				'/root'         => '{ROOT}',
			)
		);

		$this->assertSame(
			'The archive is not inside the site at "{ABSPATH}".',
			$redactor->redact( 'The archive is not inside the site at "/var/www/html".' )
		);
		$this->assertSame( 'see {ABSPATH}, then retry', $redactor->redact( 'see /var/www/html, then retry' ) );
		$this->assertSame( '({ABSPATH})', $redactor->redact( '(/var/www/html)' ) );
		$this->assertSame( "path is '{ABSPATH}'", $redactor->redact( "path is '/var/www/html'" ) );

		// The boundary rule must still hold in the other direction: none of
		// these share a genuine path-segment boundary with a known prefix, so
		// over-matching here would be a new bug, not a fix.
		$this->assertSame( '/var/www/htmlfoo', $redactor->redact( '/var/www/htmlfoo' ) );
		$this->assertSame( '/var/www/html.bak', $redactor->redact( '/var/www/html.bak' ) );
		$this->assertSame( '/rootfs/data', $redactor->redact( '/rootfs/data' ) );
	}
}
