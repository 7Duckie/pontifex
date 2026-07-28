<?php
/**
 * Tests for ArchiveFacts — the operator-facing identity of one backup list row.
 *
 * @package Pontifex\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use DateTimeImmutable;
use DateTimeZone;
use Pontifex\Admin\ArchiveFacts;
use Pontifex\Tests\TestCase;

/**
 * Covers the pure formatting ArchiveFacts does over whatever
 * {@see \Pontifex\Admin\ArchiveFactsReader} handed it: the same-site
 * comparison, the source label (including its length cap against a hostile or
 * oversized recorded URL), the created-time label, and the download filename's
 * fail-closed gate.
 */
final class ArchiveFactsTest extends TestCase {

	/**
	 * Stub the WordPress functions ArchiveFacts calls, to their plain-PHP equivalents.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_parse_url' )->alias(
			static function ( string $url, int $component = -1 ): ?string {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- This closure IS the wp_parse_url() stub for this WordPress-runtime-free test; calling wp_parse_url() here would be circular.
				$parsed = parse_url( $url, $component );
				return is_string( $parsed ) ? $parsed : null;
			}
		);
		Functions\when( 'wp_date' )->alias(
			static function ( string $format, ?int $timestamp = null ): string {
				return gmdate( $format, $timestamp ?? 0 );
			}
		);
	}

	// -------------------------------------------------------------------------
	// is_same_site().
	// -------------------------------------------------------------------------

	/**
	 * Two URLs differing only in case and a trailing slash are the same site.
	 *
	 * @return void
	 */
	public function test_is_same_site_ignores_case_and_a_trailing_slash(): void {
		$this->assertTrue( ArchiveFacts::is_same_site( 'https://Example.test/', 'https://example.test' ) );
	}

	/**
	 * A different host is not the same site.
	 *
	 * @return void
	 */
	public function test_is_same_site_reports_false_for_a_different_host(): void {
		$this->assertFalse( ArchiveFacts::is_same_site( 'https://example.test', 'https://other.test' ) );
	}

	// -------------------------------------------------------------------------
	// source_label() and is_foreign().
	// -------------------------------------------------------------------------

	/**
	 * Reports "This site" and not foreign for the site's own URL.
	 *
	 * @return void
	 */
	public function test_source_label_reports_this_site_for_a_matching_url(): void {
		$facts = new ArchiveFacts( 'Whole site', 'https://this-site.test/', new DateTimeImmutable( '2026-01-12T00:00:00+00:00' ) );

		$this->assertSame( 'This site', $facts->source_label( 'https://this-site.test' ) );
		$this->assertFalse( $facts->is_foreign( 'https://this-site.test' ) );
	}

	/**
	 * Reports the host, plus a non-root path, for a foreign subdirectory site.
	 *
	 * A subdirectory install (example.com/site1 vs example.com/site2) is a
	 * genuinely different site sharing a host, so the path must not be dropped.
	 *
	 * @return void
	 */
	public function test_source_label_reports_host_and_path_for_a_foreign_subdirectory_site(): void {
		$facts = new ArchiveFacts( 'Whole site', 'https://example.com/site1', new DateTimeImmutable( '2026-01-12T00:00:00+00:00' ) );

		$this->assertSame( 'example.com/site1', $facts->source_label( 'https://this-site.test' ) );
		$this->assertTrue( $facts->is_foreign( 'https://this-site.test' ) );
	}

	/**
	 * A foreign site with no meaningful path shows only its host.
	 *
	 * @return void
	 */
	public function test_source_label_reports_only_the_host_when_the_path_is_root(): void {
		$facts = new ArchiveFacts( 'Whole site', 'https://clientsite.com/', new DateTimeImmutable( '2026-01-12T00:00:00+00:00' ) );

		$this->assertSame( 'clientsite.com', $facts->source_label( 'https://this-site.test' ) );
	}

	/**
	 * A recorded value whose host cannot be parsed is shown verbatim rather than dropped.
	 *
	 * @return void
	 */
	public function test_source_label_returns_the_raw_value_when_the_host_cannot_be_parsed(): void {
		$facts = new ArchiveFacts( 'Whole site', 'not-a-url-at-all', new DateTimeImmutable( '2026-01-12T00:00:00+00:00' ) );

		$this->assertSame( 'not-a-url-at-all', $facts->source_label( 'https://this-site.test' ) );
	}

	/**
	 * A huge or hostile recorded URL is capped at 60 characters plus a marker.
	 *
	 * Provenance is read from a file Pontifex did not necessarily write, and the
	 * recorded URL can be up to 64 KiB of attacker-controlled bytes; the label
	 * must never reach the DOM at that length regardless of what HTML escaping
	 * happens downstream.
	 *
	 * @return void
	 */
	public function test_source_label_caps_a_huge_hostile_value(): void {
		$hostile = str_repeat( "<script>alert(1)</script>\r\n", 3000 );
		$facts   = new ArchiveFacts( 'Whole site', $hostile, new DateTimeImmutable( '2026-01-12T00:00:00+00:00' ) );

		$label = $facts->source_label( 'https://this-site.test' );

		$this->assertSame( 61, mb_strlen( $label, 'UTF-8' ), 'The label is 60 characters plus a one-character truncation marker, however hostile or oversized the recorded value.' );
		$this->assertStringEndsWith( '…', $label );
	}

	/**
	 * Caps the recorded URL offered as a title, not only the visible label.
	 *
	 * The full URL rides along in a title attribute so an operator can read an
	 * address the label truncated — but a title attribute is still the DOM. A
	 * crafted archive recording 64 KiB must not bloat every row of every list;
	 * escaping alone would keep it safe but would not keep it small.
	 *
	 * @return void
	 */
	public function test_source_url_caps_a_huge_hostile_value(): void {
		$hostile = str_repeat( "<script>alert(1)</script>\r\n", 3000 );
		$facts   = new ArchiveFacts( 'Whole site', $hostile, new DateTimeImmutable( '2026-01-12T00:00:00+00:00' ) );

		$url = $facts->source_url();

		$this->assertNotNull( $url );
		$this->assertSame( 201, mb_strlen( $url, 'UTF-8' ), 'The title value is 200 characters plus a one-character truncation marker, however oversized the recorded value.' );
		$this->assertStringEndsWith( '…', $url );
	}

	/**
	 * Reports "Unknown" for both the label and is_foreign() when the provenance was unreadable.
	 *
	 * @return void
	 */
	public function test_unreadable_facts_report_unknown_source_and_never_foreign(): void {
		$facts = ArchiveFacts::unreadable();

		$this->assertSame( 'Unknown', $facts->source_label( 'https://this-site.test' ) );
		$this->assertFalse( $facts->is_foreign( 'https://this-site.test' ), 'A convenience label must never claim "another site" when the truth is simply unknown.' );
	}

	// -------------------------------------------------------------------------
	// created_label().
	// -------------------------------------------------------------------------

	/**
	 * Formats a known creation time the same way the rest of the admin does.
	 *
	 * @return void
	 */
	public function test_created_label_formats_a_known_time(): void {
		$facts = new ArchiveFacts( 'Whole site', 'https://example.test', new DateTimeImmutable( '2026-01-12T14:30:00+00:00', new DateTimeZone( 'UTC' ) ) );

		$this->assertSame( '14:30 on 12-01-2026', $facts->created_label() );
	}

	/**
	 * Reports "Unknown" when the provenance was unreadable.
	 *
	 * @return void
	 */
	public function test_created_label_reports_unknown_when_unreadable(): void {
		$this->assertSame( 'Unknown', ArchiveFacts::unreadable()->created_label() );
	}

	// -------------------------------------------------------------------------
	// download_name().
	// -------------------------------------------------------------------------

	/**
	 * Builds a friendly '<host>-<date>.wpmig' name from real provenance.
	 *
	 * @return void
	 */
	public function test_download_name_builds_host_and_date(): void {
		Functions\when( 'sanitize_file_name' )->returnArg();

		$facts = new ArchiveFacts( 'Whole site', 'https://clientsite.com/', new DateTimeImmutable( '2026-01-12T00:00:00+00:00' ) );

		$this->assertSame( 'clientsite.com-2026-01-12.wpmig', $facts->download_name() );
	}

	/**
	 * Returns null when the provenance was unreadable (no source URL or creation time).
	 *
	 * @return void
	 */
	public function test_download_name_is_null_when_unreadable(): void {
		Functions\when( 'sanitize_file_name' )->returnArg();

		$this->assertNull( ArchiveFacts::unreadable()->download_name() );
	}

	/**
	 * Returns null when the built name fails the strict ASCII gate.
	 *
	 * An IDN (internationalised) host is a realistic case where the recorded URL
	 * is entirely legitimate but the built filename is not plain ASCII; the gate
	 * refuses it outright rather than emitting a header value on trust.
	 *
	 * @return void
	 */
	public function test_download_name_is_null_when_the_built_name_fails_the_gate(): void {
		Functions\when( 'sanitize_file_name' )->returnArg();

		$facts = new ArchiveFacts( 'Whole site', 'https://héllo.example/', new DateTimeImmutable( '2026-01-12T00:00:00+00:00' ) );

		$this->assertNull( $facts->download_name() );
	}

	/**
	 * Refuses a name that ends in a newline, which a lax end anchor would pass.
	 *
	 * The gate is the check that proves a header value cannot be broken, so it
	 * must not itself depend on sanitize_file_name having stripped the newline
	 * first: PCRE's `$` matches before a trailing newline, so only `\z` refuses
	 * "evil.wpmig\n". Stubbing sanitize_file_name to hand one back stands in for
	 * a third-party filter that appends one.
	 *
	 * @return void
	 */
	public function test_download_name_is_null_when_the_built_name_ends_in_a_newline(): void {
		Functions\when( 'sanitize_file_name' )->justReturn( "evil.wpmig\n" );

		$facts = new ArchiveFacts( 'Whole site', 'https://clientsite.com/', new DateTimeImmutable( '2026-01-12T00:00:00+00:00' ) );

		$this->assertNull( $facts->download_name(), 'A trailing newline must fail the gate, or the Content-Disposition header could be broken.' );
	}
}
