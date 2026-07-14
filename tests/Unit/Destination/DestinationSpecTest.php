<?php
/**
 * Unit tests for DestinationSpec.
 *
 * @package Pontifex\Tests\Unit\Destination
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Destination;

use InvalidArgumentException;
use Pontifex\Destination\DestinationSpec;
use Pontifex\Tests\TestCase;

/**
 * Behavioural coverage of {@see DestinationSpec}: its construction guards,
 * its getters, and the to_array()/from_array() round trip a stored record
 * must survive — including a garbage record that must degrade rather than
 * fatal outside the constructor's own guards.
 */
final class DestinationSpecTest extends TestCase {

	/**
	 * An empty name is refused.
	 *
	 * @return void
	 */
	public function test_an_empty_name_is_refused(): void {
		$this->expectException( InvalidArgumentException::class );

		new DestinationSpec( '', DestinationSpec::TYPE_SFTP, array(), 3 );
	}

	/**
	 * A name that is only whitespace is refused.
	 *
	 * @return void
	 */
	public function test_a_whitespace_only_name_is_refused(): void {
		$this->expectException( InvalidArgumentException::class );

		new DestinationSpec( '   ', DestinationSpec::TYPE_SFTP, array(), 3 );
	}

	/**
	 * An unknown destination type is refused.
	 *
	 * @return void
	 */
	public function test_an_unknown_type_is_refused(): void {
		$this->expectException( InvalidArgumentException::class );

		new DestinationSpec( 'backup-server', 'ftp', array(), 3 );
	}

	/**
	 * A negative retention count is refused.
	 *
	 * @return void
	 */
	public function test_a_negative_retention_is_refused(): void {
		$this->expectException( InvalidArgumentException::class );

		new DestinationSpec( 'backup-server', DestinationSpec::TYPE_SFTP, array(), -1 );
	}

	/**
	 * A valid spec exposes its name, type, settings, and retention.
	 *
	 * @return void
	 */
	public function test_a_valid_spec_exposes_its_fields(): void {
		$spec = new DestinationSpec( 'backup-server', DestinationSpec::TYPE_SFTP, array( 'host' => 'example.test' ), 5 );

		$this->assertSame( 'backup-server', $spec->name() );
		$this->assertSame( DestinationSpec::TYPE_SFTP, $spec->type() );
		$this->assertSame( array( 'host' => 'example.test' ), $spec->settings() );
		$this->assertSame( 5, $spec->retention() );
	}

	/**
	 * A retention of exactly zero is accepted (the boundary, not just >0).
	 *
	 * @return void
	 */
	public function test_zero_retention_is_accepted(): void {
		$spec = new DestinationSpec( 'backup-server', DestinationSpec::TYPE_SFTP, array(), 0 );

		$this->assertSame( 0, $spec->retention() );
	}

	/**
	 * Reading a setting returns the stored value when present, and the fallback when absent.
	 *
	 * @return void
	 */
	public function test_setting_returns_stored_value_or_fallback(): void {
		$spec = new DestinationSpec( 'backup-server', DestinationSpec::TYPE_SFTP, array( 'host' => 'example.test' ), 3 );

		$this->assertSame( 'example.test', $spec->setting( 'host' ) );
		$this->assertSame( '', $spec->setting( 'missing' ) );
		$this->assertSame( 22, $spec->setting( 'port', 22 ) );
	}

	/**
	 * Reading a setting distinguishes an absent key from one stored as an empty string.
	 *
	 * @return void
	 */
	public function test_setting_distinguishes_absent_from_stored_empty(): void {
		$spec = new DestinationSpec( 'backup-server', DestinationSpec::TYPE_SFTP, array( 'note' => '' ), 3 );

		$this->assertSame( '', $spec->setting( 'note', 'fallback' ), 'A stored empty string is not the same as an absent key.' );
		$this->assertSame( 'fallback', $spec->setting( 'missing', 'fallback' ) );
	}

	/**
	 * Serialising to an array carries type, settings, and retention, but never the name.
	 *
	 * @return void
	 */
	public function test_to_array_serialises_the_stored_fields(): void {
		$spec = new DestinationSpec( 'backup-server', DestinationSpec::TYPE_S3, array( 'bucket' => 'my-bucket' ), 7 );

		$this->assertSame(
			array(
				'type'      => DestinationSpec::TYPE_S3,
				'settings'  => array( 'bucket' => 'my-bucket' ),
				'retention' => 7,
			),
			$spec->to_array()
		);
	}

	/**
	 * A spec survives a to_array()/from_array() round trip unchanged.
	 *
	 * @return void
	 */
	public function test_round_trips_through_to_array_and_from_array(): void {
		$original = new DestinationSpec( 'backup-server', DestinationSpec::TYPE_SFTP, array( 'host' => 'example.test' ), 4 );

		$restored = DestinationSpec::from_array( $original->name(), $original->to_array() );

		$this->assertSame( $original->name(), $restored->name() );
		$this->assertSame( $original->type(), $restored->type() );
		$this->assertSame( $original->settings(), $restored->settings() );
		$this->assertSame( $original->retention(), $restored->retention() );
	}

	/**
	 * Rebuilding from an array tolerates a record missing every key, defaulting
	 * settings to empty and retention to zero — but the type default of '' is
	 * not a known type, so construction still throws.
	 *
	 * @return void
	 */
	public function test_from_array_on_an_empty_record_throws_for_the_missing_type(): void {
		$this->expectException( InvalidArgumentException::class );

		DestinationSpec::from_array( 'backup-server', array() );
	}

	/**
	 * Rebuilding from an array tolerates garbage-typed values for settings and
	 * retention, degrading them to their defaults rather than fataling on a type error.
	 *
	 * @return void
	 */
	public function test_from_array_tolerates_garbage_settings_and_retention(): void {
		$spec = DestinationSpec::from_array(
			'backup-server',
			array(
				'type'      => DestinationSpec::TYPE_SFTP,
				'settings'  => 'not an array',
				'retention' => 'not numeric',
			)
		);

		$this->assertSame( array(), $spec->settings() );
		$this->assertSame( 0, $spec->retention() );
	}

	/**
	 * Rebuilding from an array accepts a numeric-string retention, coercing it to int.
	 *
	 * @return void
	 */
	public function test_from_array_coerces_a_numeric_string_retention(): void {
		$spec = DestinationSpec::from_array(
			'backup-server',
			array(
				'type'      => DestinationSpec::TYPE_S3,
				'settings'  => array(),
				'retention' => '9',
			)
		);

		$this->assertSame( 9, $spec->retention() );
	}

	/**
	 * Rebuilding from an array rejects a non-string type value by falling back
	 * to '', which then fails the constructor's known-type guard.
	 *
	 * @return void
	 */
	public function test_from_array_rejects_a_non_string_type(): void {
		$this->expectException( InvalidArgumentException::class );

		DestinationSpec::from_array(
			'backup-server',
			array(
				'type'      => array( 'not', 'a', 'string' ),
				'settings'  => array(),
				'retention' => 0,
			)
		);
	}

	/**
	 * The known-types list carries exactly the two known destination types.
	 *
	 * @return void
	 */
	public function test_types_lists_the_known_types(): void {
		$this->assertSame( array( DestinationSpec::TYPE_SFTP, DestinationSpec::TYPE_S3 ), DestinationSpec::types() );
	}
}
