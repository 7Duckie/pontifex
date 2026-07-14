<?php
/**
 * Pontifex destination store — persists named destinations, without their secrets.
 *
 * @package Pontifex\Destination
 */

declare(strict_types=1);

namespace Pontifex\Destination;

use InvalidArgumentException;
use Pontifex\WordPress\WordPressContext;

/**
 * Persists the user's named destinations in one option.
 *
 * A destination is stored by name so `wp pontifex export --destination=<name>`
 * can resolve it. Only non-secret configuration is kept — a password, key
 * passphrase, or S3 secret key is referenced by an environment-variable name and
 * read at use, never written here (ADR 0017). The option travels through the
 * {@see WordPressContext} seam, the same posture as {@see \Pontifex\Schedule\ScheduleStore}.
 */
final class DestinationStore {

	/**
	 * The wp_options key the destinations map lives under.
	 *
	 * @var string
	 */
	public const OPTION = 'pontifex_destinations';

	/**
	 * The WordPressContext seam options travel through.
	 *
	 * @var WordPressContext
	 */
	private WordPressContext $wordpress_context;

	/**
	 * Construct a DestinationStore over the context seam.
	 *
	 * @param WordPressContext $wordpress_context The seam for option reads and writes.
	 */
	public function __construct( WordPressContext $wordpress_context ) {
		$this->wordpress_context = $wordpress_context;
	}

	/**
	 * Load every stored destination, skipping any unreadable record.
	 *
	 * @return array<string, DestinationSpec> Specs keyed by name.
	 */
	public function all(): array {
		$stored = $this->wordpress_context->option_value( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$specs = array();
		foreach ( $stored as $name => $record ) {
			if ( ! is_string( $name ) || ! is_array( $record ) ) {
				continue;
			}
			try {
				$specs[ $name ] = DestinationSpec::from_array( $name, $record );
			} catch ( InvalidArgumentException $e ) {
				continue;
			}
		}

		return $specs;
	}

	/**
	 * Load one destination by name, or null when it is absent or unreadable.
	 *
	 * @param string $name The destination name.
	 * @return DestinationSpec|null
	 */
	public function get( string $name ): ?DestinationSpec {
		$all = $this->all();
		return $all[ $name ] ?? null;
	}

	/**
	 * The names of every stored destination.
	 *
	 * @return array<int, string>
	 */
	public function names(): array {
		return array_keys( $this->all() );
	}

	/**
	 * Persist a destination, replacing any existing one of the same name.
	 *
	 * @param DestinationSpec $spec The destination to store.
	 * @return void
	 */
	public function save( DestinationSpec $spec ): void {
		$stored                  = $this->raw_records();
		$stored[ $spec->name() ] = $spec->to_array();
		$this->wordpress_context->save_option( self::OPTION, $stored );
	}

	/**
	 * Remove a destination by name.
	 *
	 * @param string $name The destination name.
	 * @return void
	 */
	public function delete( string $name ): void {
		$stored = $this->raw_records();
		unset( $stored[ $name ] );
		$this->wordpress_context->save_option( self::OPTION, $stored );
	}

	/**
	 * The stored records as-is, for a read-modify-write that preserves
	 * any record this version cannot parse.
	 *
	 * @return array<string, mixed>
	 */
	private function raw_records(): array {
		$stored = $this->wordpress_context->option_value( self::OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}
}
