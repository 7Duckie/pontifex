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
use Pontifex\Cli\PathRedactor;

/**
 * Behavioural coverage of {@see DiagnosticsRedactor}: the redaction policy that
 * keeps a sharable diagnostics bundle free of site-identifying or secret values.
 *
 * The redactor is the security-critical half of the diagnostics command — an
 * under-redaction is a leak — so its rules are pinned down here; the command's
 * gather-and-pack path is smoke-proven on real WordPress. Absolute-path
 * matching itself lives in {@see PathRedactor} and is covered by its own test;
 * here we check the URL and option rules and that path redaction is wired in.
 */
final class DiagnosticsRedactorTest extends TestCase {

	/**
	 * A redactor with no path prefixes, for the URL and option tests.
	 *
	 * @param string $site_url The site URL to redact (empty to skip).
	 * @return DiagnosticsRedactor A redactor that does no path redaction.
	 */
	private static function redactor( string $site_url = '' ): DiagnosticsRedactor {
		return new DiagnosticsRedactor( $site_url, new PathRedactor( array() ) );
	}

	/**
	 * The site URL is replaced with the reserved placeholder.
	 *
	 * @return void
	 */
	public function test_redact_text_replaces_the_site_url(): void {
		$redactor = self::redactor( 'https://my-site.example' );

		$this->assertSame(
			'Visit ' . DiagnosticsRedactor::URL_PLACEHOLDER . '/wp-admin',
			$redactor->redact_text( 'Visit https://my-site.example/wp-admin' )
		);
	}

	/**
	 * Path redaction is delegated to the injected PathRedactor and applied with URL redaction.
	 *
	 * @return void
	 */
	public function test_redact_text_delegates_path_redaction(): void {
		$redactor = new DiagnosticsRedactor(
			'https://my-site.example',
			PathRedactor::from_paths( '/var/www/html', '/var/www/html/wp-content', '/home/alice', '/tmp' )
		);

		$this->assertSame(
			'{WP_CONTENT_DIR}/pontifex/logs at https://example.invalid',
			$redactor->redact_text( '/var/www/html/wp-content/pontifex/logs at https://my-site.example' )
		);
		$this->assertSame( '{HOME}/secret.key', $redactor->redact_text( '/home/alice/secret.key' ) );
	}

	/**
	 * An empty URL and an empty path redactor leave the text untouched.
	 *
	 * @return void
	 */
	public function test_redact_text_skips_empty_url_and_paths(): void {
		$redactor = self::redactor();

		$this->assertSame( 'left /var/www alone', $redactor->redact_text( 'left /var/www alone' ) );
	}

	/**
	 * Option names ending in a sensitive suffix are recognised; others are not.
	 *
	 * @return void
	 */
	public function test_is_sensitive_name_matches_only_the_sensitive_suffixes(): void {
		$redactor = self::redactor();

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
		$redactor = self::redactor();

		$this->assertSame( DiagnosticsRedactor::MASK, $redactor->mask_option( 'auth_key', 'abc123' ) );
		$this->assertSame( DiagnosticsRedactor::MASK, $redactor->mask_option( 'some_token', array( 'nested' => 'value' ) ) );
		$this->assertSame( 'twentytwentyfour', $redactor->mask_option( 'template', 'twentytwentyfour' ) );
	}
}
