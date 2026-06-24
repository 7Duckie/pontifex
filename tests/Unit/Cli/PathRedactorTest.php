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
}
