<?php
/**
 * Pontifex archive exporter info — name and version of the tool that wrote the archive.
 *
 * @package Pontifex\Archive\Format
 */

declare(strict_types=1);

namespace Pontifex\Archive\Format;

use InvalidArgumentException;

/**
 * Immutable value object holding the exporter name and version pair.
 *
 * Forms the "exporter" sub-object inside the Provenance JSON. The
 * two fields always travel together: a Provenance carries one
 * ExporterInfo rather than two loose strings, which prevents a
 * caller from ever passing a name without a version.
 *
 * Both fields are required and must be non-empty. Extension fields
 * (e.g. commit_sha, platform) may be added in future versions
 * without changing the Provenance class.
 */
final class ExporterInfo {

	/**
	 * Exporter name (e.g. "pontifex").
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * Exporter version (e.g. "0.1.0").
	 *
	 * @var string
	 */
	private string $version;

	/**
	 * Construct an ExporterInfo with name and version.
	 *
	 * @param string $name    Exporter name; must be non-empty.
	 * @param string $version Exporter version; must be non-empty.
	 * @throws InvalidArgumentException If either argument is the empty string.
	 */
	public function __construct( string $name, string $version ) {
		if ( '' === $name ) {
			throw new InvalidArgumentException( 'ExporterInfo: name must not be empty.' );
		}
		if ( '' === $version ) {
			throw new InvalidArgumentException( 'ExporterInfo: version must not be empty.' );
		}

		$this->name    = $name;
		$this->version = $version;
	}

	/**
	 * Return the exporter name.
	 *
	 * @return string The exporter name.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Return the exporter version.
	 *
	 * @return string The exporter version.
	 */
	public function version(): string {
		return $this->version;
	}
}
