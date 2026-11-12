<?php
/**
 * Unit tests for RemoteObject.
 *
 * @package Pontifex\Tests\Unit\Destination
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Destination;

use Pontifex\Destination\RemoteObject;
use Pontifex\Tests\TestCase;

/**
 * Behavioural coverage of {@see RemoteObject}: a plain getter trio, plus the
 * -1 "unknown" sentinel a destination's listing may report for the size and
 * the modification time alike.
 */
final class RemoteObjectTest extends TestCase {

	/**
	 * The constructor arguments are exposed unchanged through the getters.
	 *
	 * @return void
	 */
	public function test_getters_expose_the_constructor_arguments(): void {
		$object = new RemoteObject( 'pontifex-2026-07-13-030000.wpmig', 1048576, 1786535320 );

		$this->assertSame( 'pontifex-2026-07-13-030000.wpmig', $object->name() );
		$this->assertSame( 1048576, $object->size() );
		$this->assertSame( 1786535320, $object->modification_time() );
	}

	/**
	 * The size defaults to -1, the sentinel for "the destination did not report it".
	 *
	 * @return void
	 */
	public function test_size_defaults_to_unknown(): void {
		$object = new RemoteObject( 'pontifex-2026-07-13-030000.wpmig' );

		$this->assertSame( -1, $object->size() );
	}

	/**
	 * The modification time defaults to -1, the same sentinel the size uses
	 * for "the destination did not report it".
	 *
	 * @return void
	 */
	public function test_modification_time_defaults_to_unknown(): void {
		$object = new RemoteObject( 'pontifex-2026-07-13-030000.wpmig' );

		$this->assertSame( -1, $object->modification_time() );
	}
}
