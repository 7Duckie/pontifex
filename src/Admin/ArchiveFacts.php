<?php
/**
 * Pontifex archive facts — the operator-facing identity of one backup list row.
 *
 * @package Pontifex\Admin
 */

declare(strict_types=1);

namespace Pontifex\Admin;

use DateTimeImmutable;
use Pontifex\Archive\ScopeSummary;

/**
 * Immutable value object carrying what a backup list row needs to show about
 * one archive: what it contains, where it truly came from, and when it was
 * really made — every field read from the archive's own provenance block,
 * never guessed from the on-disk filename or the moment it was uploaded.
 *
 * Built by {@see ArchiveFactsReader::facts()} from one provenance read; this
 * class only formats what that read already found. A missing, corrupt, or
 * unreadable archive is represented by {@see self::unreadable()} rather than
 * an exception, matching {@see ScopeSummary}'s fail-soft posture: a label is
 * presentation, never integrity.
 */
final class ArchiveFacts {

	/**
	 * The longest a source label may be before it is truncated.
	 *
	 * The recorded URL is attacker-influenceable (provenance is read from a
	 * file Pontifex did not necessarily write, up to
	 * {@see \Pontifex\Archive\Format\Provenance::MAX_PAYLOAD_SIZE} 64 KiB), so
	 * the label shown in a list must never reach the DOM at that length
	 * regardless of what escaping happens downstream.
	 *
	 * @var int
	 */
	private const SOURCE_LABEL_LIMIT = 60;

	/**
	 * The longest a source URL may be before it is truncated for a title attribute.
	 *
	 * The visible label is capped tighter than this, but the full URL is also
	 * offered as a title so an operator can read an address the label truncated.
	 * That attribute is still the DOM, and the recorded URL is still
	 * attacker-influenceable, so it is bounded too — generously enough for any
	 * genuine site address, and far short of the 64 KiB the format permits.
	 *
	 * @var int
	 */
	private const SOURCE_URL_LIMIT = 200;

	/**
	 * The truncation marker appended when a label is cut to the limit.
	 *
	 * @var string
	 */
	private const TRUNCATION_MARKER = '…';

	/**
	 * The compact "Contains" label for this archive's recorded scope.
	 *
	 * @var string
	 */
	private string $scope_label;

	/**
	 * The archive's recorded source-site URL, verbatim, or null when unreadable.
	 *
	 * @var string|null
	 */
	private ?string $source_url;

	/**
	 * The archive's recorded creation moment, or null when unreadable.
	 *
	 * @var DateTimeImmutable|null
	 */
	private ?DateTimeImmutable $created;

	/**
	 * Construct the facts for one archive.
	 *
	 * @param string                 $scope_label The compact "Contains" label.
	 * @param string|null            $source_url  The recorded source-site URL, verbatim, or null when unreadable.
	 * @param DateTimeImmutable|null $created     The recorded creation moment, or null when unreadable.
	 */
	public function __construct( string $scope_label, ?string $source_url, ?DateTimeImmutable $created ) {
		$this->scope_label = $scope_label;
		$this->source_url  = $source_url;
		$this->created     = $created;
	}

	/**
	 * The facts for an archive whose provenance could not be read.
	 *
	 * @return self Facts reporting an unknown scope, source, and creation time.
	 */
	public static function unreadable(): self {
		return new self( ScopeSummary::unreadable_label(), null, null );
	}

	/**
	 * The "Contains" label for this archive.
	 *
	 * @return string The compact scope label.
	 */
	public function scope_label(): string {
		return $this->scope_label;
	}

	/**
	 * The archive's source, described relative to a given site.
	 *
	 * @param string $site_url The URL of the site the list is displayed on.
	 * @return string 'This site' when the archive was made here, the source host
	 *                (plus a non-root path, for subdirectory installs) when it was
	 *                made elsewhere, or 'Unknown' when the provenance could not be read.
	 */
	public function source_label( string $site_url ): string {
		if ( null === $this->source_url ) {
			return __( 'Unknown', 'pontifex' );
		}
		if ( self::is_same_site( $this->source_url, $site_url ) ) {
			return __( 'This site', 'pontifex' );
		}
		return self::truncate( self::host_label( $this->source_url ), self::SOURCE_LABEL_LIMIT );
	}

	/**
	 * Whether this archive was made on a site other than the given one.
	 *
	 * An archive whose source could not be read answers false: a convenience
	 * label must never claim "another site" when the truth is simply unknown.
	 *
	 * @param string $site_url The URL of the site the list is displayed on.
	 * @return bool True when the archive's recorded source differs from the given site.
	 */
	public function is_foreign( string $site_url ): bool {
		if ( null === $this->source_url ) {
			return false;
		}
		return ! self::is_same_site( $this->source_url, $site_url );
	}

	/**
	 * The archive's true creation time, in the site's local time.
	 *
	 * @return string A readable moment ('H:i \o\n d-m-Y'), or 'Unknown' when the provenance could not be read.
	 */
	public function created_label(): string {
		if ( null === $this->created ) {
			return __( 'Unknown', 'pontifex' );
		}
		$formatted = wp_date( 'H:i \o\n d-m-Y', $this->created->getTimestamp() );
		return false !== $formatted ? $formatted : __( 'Unknown', 'pontifex' );
	}

	/**
	 * The archive's recorded source-site URL, bounded, for a title attribute only.
	 *
	 * Never render this as a link and never carry it in a data attribute — it is
	 * attacker-influenceable provenance, shown only as inspectable text. It is
	 * truncated to {@see self::SOURCE_URL_LIMIT} for the same reason the visible
	 * label is: a title attribute is still the DOM, and a crafted archive may
	 * record a URL of up to 64 KiB. Escaping alone would keep such a URL safe but
	 * would still let it bloat every row of every list.
	 *
	 * @return string|null The recorded URL, truncated to a sane bound, or null when the provenance could not be read.
	 */
	public function source_url(): ?string {
		if ( null === $this->source_url ) {
			return null;
		}

		return self::truncate( $this->source_url, self::SOURCE_URL_LIMIT );
	}

	/**
	 * A friendly download filename for this archive, or null when one cannot be built.
	 *
	 * Built as '<source host>-<created date>.wpmig' so a downloaded file is
	 * identifiable months later without opening it — the on-disk stored name
	 * (`pontifex-backup-<UTC>.wpmig`) never changes; this is presentation for the
	 * `Content-Disposition` header only.
	 *
	 * The created date is formatted in the site's local time deliberately (not
	 * UTC), so it matches the date shown in the row this download came from.
	 *
	 * The result is passed through a strict allow-list gate after
	 * `sanitize_file_name()`. `sanitize_file_name()` already collapses control
	 * characters such as CR/LF, but the gate makes header injection into
	 * `Content-Disposition` provably impossible independent of that WordPress
	 * behaviour, and it keeps the filename plain ASCII so the simple
	 * `filename="…"` form (RFC 6266) is always correct — no `filename*`
	 * fallback is ever needed.
	 *
	 * @return string|null The download filename, or null when the provenance is unreadable or the built name fails the gate.
	 */
	public function download_name(): ?string {
		if ( null === $this->source_url || null === $this->created ) {
			return null;
		}

		$host = wp_parse_url( $this->source_url, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return null;
		}

		$date = wp_date( 'Y-m-d', $this->created->getTimestamp() );
		if ( false === $date ) {
			return null;
		}

		$name = sanitize_file_name( $host . '-' . $date . '.wpmig' );

		// Fail-closed gate: a name that is not plain ASCII filename characters is
		// refused outright rather than passed on trust. The end anchor is \z, not
		// $: PCRE's $ also matches before a trailing newline, which would let a
		// header-breaking name through the one check that is meant to prove it
		// cannot happen.
		if ( 1 !== preg_match( '/^[A-Za-z0-9._-]{1,100}\z/', $name ) ) {
			return null;
		}

		return $name;
	}

	/**
	 * Whether two site URLs point at the same site.
	 *
	 * Compared case-insensitively and ignoring a single trailing slash; scheme,
	 * host, port and path remain significant — none of which changes which site
	 * a URL points at. The one comparison shared by every screen and by
	 * {@see \Pontifex\Admin\RestoreController::archive_needs_migration()}, so
	 * they cannot drift apart.
	 *
	 * @param string $a The first URL.
	 * @param string $b The second URL.
	 * @return bool True when the two URLs are the same site.
	 */
	public static function is_same_site( string $a, string $b ): bool {
		return self::normalise_url( $a ) === self::normalise_url( $b );
	}

	/**
	 * Normalise a site URL for comparison: lower case, no trailing slash.
	 *
	 * @param string $url The URL to normalise.
	 * @return string The normalised URL.
	 */
	private static function normalise_url( string $url ): string {
		return strtolower( rtrim( $url, '/' ) );
	}

	/**
	 * Describe a URL as its host, plus a non-root path for subdirectory installs.
	 *
	 * A subdirectory multisite-style install (`example.com/site1` vs
	 * `example.com/site2`) is genuinely a different site sharing a host, so the
	 * path is kept when it is more than just '/'. A URL whose host cannot be
	 * parsed is shown verbatim rather than silently dropped.
	 *
	 * @param string $url The recorded source URL.
	 * @return string The host (plus path, where meaningful), or the raw URL if unparseable.
	 */
	private static function host_label( string $url ): string {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return $url;
		}

		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( is_string( $path ) && '' !== $path && '/' !== $path ) {
			return $host . $path;
		}

		return $host;
	}

	/**
	 * Truncate text to a character limit, preferring multibyte-aware counting.
	 *
	 * @param string $text  The text to truncate.
	 * @param int    $limit The maximum length before truncation, in characters.
	 * @return string The text unchanged if within the limit, otherwise cut to the limit with a trailing marker.
	 */
	private static function truncate( string $text, int $limit ): string {
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
		if ( $length <= $limit ) {
			return $text;
		}

		$cut = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $limit, 'UTF-8' ) : substr( $text, 0, $limit );
		return $cut . self::TRUNCATION_MARKER;
	}
}
