<?php
/**
 * Unit tests for the diagnostics-bundle redactor.
 *
 * @package Pontifex\Tests\Unit\Cli
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Pontifex\Cli\DiagnosticsRedactor;

/**
 * Behavioural coverage of {@see DiagnosticsRedactor}: the redaction policy that
 * keeps a sharable diagnostics bundle free of site-identifying or secret values.
 *
 * The redactor is the security-critical half of the diagnostics command — an
 * under-redaction is a leak — so its rules are pinned down here; the command's
 * gather-and-pack path is smoke-proven on real WordPress.
 */
final class DiagnosticsRedactorTest extends TestCase {

	/**
	 * The site URL is replaced with the reserved placeholder.
	 *
	 * @return void
	 */
	public function test_redact_text_replaces_the_site_url(): void {
		$redactor = new DiagnosticsRedactor( 'https://my-site.example', '', '' );

		$this->assertSame(
			'Visit ' . DiagnosticsRedactor::URL_PLACEHOLDER . '/wp-admin',
			$redactor->redact_text( 'Visit https://my-site.example/wp-admin' )
		);
	}

	/**
	 * Absolute paths are replaced with their placeholders.
	 *
	 * @return void
	 */
	public function test_redact_text_replaces_absolute_paths(): void {
		$redactor = new DiagnosticsRedactor( '', '/var/www/html', '/srv/content' );

		$this->assertSame( '{ABSPATH}/wp-config.php', $redactor->redact_text( '/var/www/html/wp-config.php' ) );
		$this->assertSame( '{WP_CONTENT_DIR}/pontifex/logs', $redactor->redact_text( '/srv/content/pontifex/logs' ) );
	}

	/**
	 * When wp-content is nested under the root, the longer path is redacted first.
	 *
	 * @return void
	 */
	public function test_redact_text_redacts_nested_paths_longest_first(): void {
		// wp-content sits inside ABSPATH; the more-specific path must win.
		$redactor = new DiagnosticsRedactor( '', '/var/www/html', '/var/www/html/wp-content' );

		$this->assertSame(
			'{WP_CONTENT_DIR}/pontifex/logs/pontifex.log',
			$redactor->redact_text( '/var/www/html/wp-content/pontifex/logs/pontifex.log' )
		);
		$this->assertSame( '{ABSPATH}/wp-load.php', $redactor->redact_text( '/var/www/html/wp-load.php' ) );
	}

	/**
	 * An empty URL or path is skipped rather than redacting blindly.
	 *
	 * @return void
	 */
	public function test_redact_text_skips_empty_url_and_paths(): void {
		$redactor = new DiagnosticsRedactor( '', '', '' );

		$this->assertSame( 'left /var/www alone', $redactor->redact_text( 'left /var/www alone' ) );
	}

	/**
	 * Option names ending in a sensitive suffix are recognised; others are not.
	 *
	 * @return void
	 */
	public function test_is_sensitive_name_matches_only_the_sensitive_suffixes(): void {
		$redactor = new DiagnosticsRedactor( '', '', '' );

		$this->assertTrue( $redactor->is_sensitive_name( 'auth_key' ) );
		$this->assertTrue( $redactor->is_sensitive_name( 'mailchimp_api_secret' ) );
		$this->assertTrue( $redactor->is_sensitive_name( 'service_access_token' ) );
		$this->assertTrue( $redactor->is_sensitive_name( 'smtp_password' ) );

		$this->assertFalse( $redactor->is_sensitive_name( 'template' ) );
		$this->assertFalse( $redactor->is_sensitive_name( 'active_plugins' ) );
		$this->assertFalse( $redactor->is_sensitive_name( 'secret_sauce' ) );
	}

	/**
	 * A sensitively named option's value is masked; an ordinary one passes through.
	 *
	 * @return void
	 */
	public function test_mask_option_masks_only_sensitive_names(): void {
		$redactor = new DiagnosticsRedactor( '', '', '' );

		$this->assertSame( DiagnosticsRedactor::MASK, $redactor->mask_option( 'auth_key', 'abc123' ) );
		$this->assertSame( DiagnosticsRedactor::MASK, $redactor->mask_option( 'some_token', array( 'nested' => 'value' ) ) );
		$this->assertSame( 'twentytwentyfour', $redactor->mask_option( 'template', 'twentytwentyfour' ) );
	}
}
