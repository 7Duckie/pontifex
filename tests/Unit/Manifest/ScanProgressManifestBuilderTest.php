<?php
/**
 * Unit tests for the ScanProgressManifestBuilder decorator.
 *
 * @package Pontifex\Tests\Unit\Manifest
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Manifest;

use Mockery;
use Pontifex\Tests\TestCase;
use Pontifex\Manifest\ManifestBuilderInterface;
use Pontifex\Manifest\ScanProgressManifestBuilder;

/**
 * Tests for {@see ScanProgressManifestBuilder}.
 *
 * The admin Backup screen always wraps its builder in this decorator, so anything the
 * decorator does not forward is silently lost on exactly that path.
 */
final class ScanProgressManifestBuilderTest extends TestCase {

	/**
	 * The views the inner builder left out are the views the decorator reports.
	 *
	 * The inner builder is asked for them, and its answer is returned unchanged, so a
	 * decorator that answered for itself (an empty list) would fail here.
	 *
	 * @return void
	 */
	public function test_it_forwards_the_excluded_views_of_the_inner_builder(): void {
		$inner = Mockery::mock( ManifestBuilderInterface::class );
		$inner->shouldReceive( 'excluded_views' )->once()->andReturn( array( 'wp_a_view', 'wp_b_view' ) );

		$decorator = new ScanProgressManifestBuilder( $inner, static function (): void {} );

		$this->assertSame( array( 'wp_a_view', 'wp_b_view' ), $decorator->excluded_views() );
	}
}
