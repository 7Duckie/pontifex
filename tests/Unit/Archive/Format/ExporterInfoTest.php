<?php
/**
 * Behavioural tests for the ExporterInfo value object.
 *
 * @package Pontifex\Tests\Unit\Archive\Format
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Archive\Format;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pontifex\Archive\Format\ExporterInfo;

/**
 * Behavioural tests for the ExporterInfo class.
 *
 * Verifies that the value object accepts non-empty name and version
 * strings, exposes them via accessors, and rejects empty strings.
 */
final class ExporterInfoTest extends TestCase {

	/**
	 * The constructor must accept any non-empty name and version pair.
	 *
	 * @return void
	 */
	public function test_constructor_accepts_valid_values(): void {
		$exporter = new ExporterInfo( 'pontifex', '0.1.0' );

		$this->assertSame( 'pontifex', $exporter->name() );
		$this->assertSame( '0.1.0', $exporter->version() );
	}

	/**
	 * The constructor must reject an empty name.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_empty_name(): void {
		$this->expectException( InvalidArgumentException::class );

		new ExporterInfo( '', '0.1.0' );
	}

	/**
	 * The constructor must reject an empty version.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_empty_version(): void {
		$this->expectException( InvalidArgumentException::class );

		new ExporterInfo( 'pontifex', '' );
	}

	/**
	 * Whitespace-only values are accepted; the constructor only rejects the empty string.
	 *
	 * Pontifex stores whatever the writer provides; the writer is
	 * responsible for picking sensible values.
	 *
	 * @return void
	 */
	public function test_constructor_accepts_whitespace_only_values(): void {
		$exporter = new ExporterInfo( ' ', ' ' );

		$this->assertSame( ' ', $exporter->name() );
		$this->assertSame( ' ', $exporter->version() );
	}
}
