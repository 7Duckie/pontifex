<?php
/**
 * Unit tests for the RestorableTableTypes class.
 *
 * @package Pontifex\Tests\Unit\Database
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use Pontifex\Database\RestorableTableTypes;

/**
 * Tests for {@see RestorableTableTypes}.
 *
 * The list has one home so the export (which leaves unrestorable objects out) and the
 * restore (which refuses them) cannot disagree about what a restore can replay.
 */
final class RestorableTableTypesTest extends TestCase {

	/**
	 * Every object type a restore accepts is reported as restorable.
	 *
	 * SYSTEM VERSIONED and SEQUENCE are MariaDB objects the restore replays; dropping
	 * either from a backup would turn this check into a data-loss bug.
	 *
	 * @return void
	 */
	public function test_every_type_the_restore_accepts_is_restorable(): void {
		$this->assertTrue( RestorableTableTypes::is_restorable( 'BASE TABLE' ) );
		$this->assertTrue( RestorableTableTypes::is_restorable( 'SYSTEM VERSIONED' ) );
		$this->assertTrue( RestorableTableTypes::is_restorable( 'SEQUENCE' ) );
	}

	/**
	 * Views, and anything else not on the list, are not restorable.
	 *
	 * @return void
	 */
	public function test_views_and_unknown_types_are_not_restorable(): void {
		$this->assertFalse( RestorableTableTypes::is_restorable( 'VIEW' ) );
		$this->assertFalse( RestorableTableTypes::is_restorable( 'SYSTEM VIEW' ) );
		$this->assertFalse( RestorableTableTypes::is_restorable( '' ) );
	}
}
