<?php
/**
 * Pontifex destination spec — the stored configuration of one named destination.
 *
 * @package Pontifex\Destination
 */

declare(strict_types=1);

namespace Pontifex\Destination;

use InvalidArgumentException;

/**
 * An immutable description of one named, user-configured destination.
 *
 * A spec carries what is safe to store: the destination's name, its type
 * (`sftp` or `s3`), its non-secret settings, and a retention count. It never
 * holds a secret — a password, key passphrase, or S3 secret key is referenced
 * by the name of an environment variable (a `*_env` setting) and read at point
 * of use, so nothing sensitive is written to the database or a config dump
 * (ADR 0017). The {@see DestinationFactory} turns a spec into a live
 * {@see DestinationAdapter}; each adapter validates the settings it needs.
 */
final class DestinationSpec {

	/**
	 * The SFTP destination type.
	 *
	 * @var string
	 */
	public const TYPE_SFTP = 'sftp';

	/**
	 * The S3-compatible destination type.
	 *
	 * @var string
	 */
	public const TYPE_S3 = 's3';

	/**
	 * The destination's unique name.
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * The destination type, one of the TYPE_* constants.
	 *
	 * @var string
	 */
	private string $type;

	/**
	 * The non-secret settings, keyed by setting name.
	 *
	 * @var array<string, scalar>
	 */
	private array $settings;

	/**
	 * How many archives to keep at the destination.
	 *
	 * @var int
	 */
	private int $retention;

	/**
	 * Construct a destination spec.
	 *
	 * @param string                $name      The unique destination name (non-empty).
	 * @param string                $type      One of the TYPE_* constants.
	 * @param array<string, scalar> $settings  The non-secret settings.
	 * @param int                   $retention How many archives to keep (>= 0).
	 * @throws InvalidArgumentException If the name is empty, the type is unknown, or retention is negative.
	 */
	public function __construct( string $name, string $type, array $settings, int $retention ) {
		if ( '' === trim( $name ) ) {
			throw new InvalidArgumentException( 'A destination name must not be empty.' );
		}
		if ( ! in_array( $type, self::types(), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $type is reported verbatim for diagnostic context; exception path, not HTML output.
			throw new InvalidArgumentException( sprintf( 'Unknown destination type "%s".', $type ) );
		}
		if ( $retention < 0 ) {
			throw new InvalidArgumentException( 'A destination retention count must not be negative.' );
		}

		$this->name      = $name;
		$this->type      = $type;
		$this->settings  = $settings;
		$this->retention = $retention;
	}

	/**
	 * The known destination types.
	 *
	 * @return array<int, string>
	 */
	public static function types(): array {
		return array( self::TYPE_SFTP, self::TYPE_S3 );
	}

	/**
	 * The destination's unique name.
	 *
	 * @return string
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * The destination type.
	 *
	 * @return string
	 */
	public function type(): string {
		return $this->type;
	}

	/**
	 * Read one setting, returning $fallback when it is absent.
	 *
	 * @param string $key      The setting name.
	 * @param scalar $fallback The value to return when the setting is absent.
	 * @return scalar
	 */
	public function setting( string $key, $fallback = '' ) {
		return array_key_exists( $key, $this->settings ) ? $this->settings[ $key ] : $fallback;
	}

	/**
	 * All non-secret settings.
	 *
	 * @return array<string, scalar>
	 */
	public function settings(): array {
		return $this->settings;
	}

	/**
	 * How many archives to keep at the destination.
	 *
	 * @return int
	 */
	public function retention(): int {
		return $this->retention;
	}

	/**
	 * Serialise the spec for storage under its name.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'type'      => $this->type,
			'settings'  => $this->settings,
			'retention' => $this->retention,
		);
	}

	/**
	 * Rebuild a spec from its stored array, tolerating a garbage record.
	 *
	 * @param string               $name The destination name (the store's key).
	 * @param array<string, mixed> $data The stored record.
	 * @return self
	 * @throws InvalidArgumentException If the record cannot form a valid spec.
	 */
	public static function from_array( string $name, array $data ): self {
		$type      = isset( $data['type'] ) && is_string( $data['type'] ) ? $data['type'] : '';
		$settings  = isset( $data['settings'] ) && is_array( $data['settings'] ) ? $data['settings'] : array();
		$retention = isset( $data['retention'] ) && is_numeric( $data['retention'] ) ? (int) $data['retention'] : 0;

		return new self( $name, $type, $settings, $retention );
	}
}
