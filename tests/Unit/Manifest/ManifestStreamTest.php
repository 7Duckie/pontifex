<?php
/**
 * Unit tests for the ManifestStream class.
 *
 * @package Pontifex\Tests\Unit\Manifest
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Manifest;

use PHPUnit\Framework\TestCase;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Writer\EntryPlan;
use Pontifex\Archive\Writer\EntryWriter;
use Pontifex\Manifest\ManifestStream;

/**
 * Tests for {@see ManifestStream}.
 *
 * The stream is the memory bound of the export: it must count and estimate in
 * O(1) without building any EntryPlan, and realise plans one at a time only as
 * they are pulled. These tests pin that contract — a regression that
 * materialised every plan up front (the bug this class exists to prevent) would
 * fail {@see test_get_iterator_builds_each_plan_lazily}.
 */
final class ManifestStreamTest extends TestCase {

	/**
	 * Build a throwaway file EntryPlan for use as a stream item.
	 *
	 * @param string $path Relative path for the entry header.
	 * @return EntryPlan A plan with an empty in-memory source.
	 */
	private function sample_plan( string $path ): EntryPlan {
		$header = EntryHeader::for_file( $path, 0, 0o644, 0, 'application/octet-stream', 0 );
		return new EntryPlan( $header, 0, str_repeat( "\0", EntryWriter::NONCE_SIZE ), $this->empty_stream() );
	}

	/**
	 * Open an empty readable in-memory stream.
	 *
	 * @return resource
	 */
	private function empty_stream() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://memory is an in-process buffer, not a file.
		$stream = fopen( 'php://memory', 'r+b' );
		if ( false === $stream ) {
			$this->fail( 'Could not open php://memory.' );
		}
		return $stream;
	}

	/**
	 * Counting the stream reports the item total without building any plan.
	 *
	 * @return void
	 */
	public function test_count_returns_item_count(): void {
		$built  = 0;
		$stream = new ManifestStream(
			array( 'a', 'b', 'c' ),
			function ( $item ) use ( &$built ): EntryPlan {
				++$built;
				return $this->sample_plan( (string) $item );
			},
			0
		);

		$this->assertCount( 3, $stream );
		$this->assertSame( 0, $built, 'count() must not build any EntryPlan.' );
	}

	/**
	 * The byte estimate is the constructor total, returned without building any plan.
	 *
	 * @return void
	 */
	public function test_estimated_bytes_returns_total(): void {
		$built  = 0;
		$stream = new ManifestStream(
			array( 'a', 'b' ),
			function ( $item ) use ( &$built ): EntryPlan {
				++$built;
				return $this->sample_plan( (string) $item );
			},
			4096
		);

		$this->assertSame( 4096, $stream->estimated_bytes() );
		$this->assertSame( 0, $built, 'estimated_bytes() must not build any EntryPlan.' );
	}

	/**
	 * Iterating realises exactly one plan per pulled item, never all up front.
	 *
	 * This is the regression guard for the bug ManifestStream prevents: the old
	 * build() returned an array with every EntryPlan already constructed, so peak
	 * memory grew with the entry count. Here no plan exists until it is pulled,
	 * and only one more is built per step.
	 *
	 * @return void
	 */
	public function test_get_iterator_builds_each_plan_lazily(): void {
		$built  = 0;
		$stream = new ManifestStream(
			array( 'a', 'b', 'c' ),
			function ( $item ) use ( &$built ): EntryPlan {
				++$built;
				return $this->sample_plan( (string) $item );
			},
			0
		);

		// Counting and estimating must not trigger any build.
		$this->assertCount( 3, $stream );
		$this->assertSame( 0, $stream->estimated_bytes() );
		$this->assertSame( 0, $built, 'No plan may be built before iteration.' );

		$seen = 0;
		foreach ( $stream as $plan ) {
			++$seen;
			$this->assertInstanceOf( EntryPlan::class, $plan );
			$this->assertSame( $seen, $built, 'Each pulled item builds exactly one more plan — never all at once.' );
		}

		$this->assertSame( 3, $seen );
		$this->assertSame( 3, $built );
	}

	/**
	 * Wrapping ready plans yields them unchanged and in order.
	 *
	 * @return void
	 */
	public function test_from_plans_yields_the_plans_in_order(): void {
		$first  = $this->sample_plan( 'first.txt' );
		$second = $this->sample_plan( 'second.txt' );

		$stream = ManifestStream::from_plans( array( $first, $second ) );

		$this->assertCount( 2, $stream );
		$this->assertSame( array( $first, $second ), iterator_to_array( $stream ) );
	}

	/**
	 * Wrapping ready plans sums the byte estimate from their headers.
	 *
	 * @return void
	 */
	public function test_from_plans_sums_estimated_bytes_from_headers(): void {
		$header_a = EntryHeader::for_file( 'a.txt', 100, 0o644, 0, 'application/octet-stream', 0 );
		$header_b = EntryHeader::for_file( 'b.txt', 250, 0o644, 0, 'application/octet-stream', 0 );
		$plan_a   = new EntryPlan( $header_a, 0, str_repeat( "\0", EntryWriter::NONCE_SIZE ), $this->empty_stream() );
		$plan_b   = new EntryPlan( $header_b, 0, str_repeat( "\0", EntryWriter::NONCE_SIZE ), $this->empty_stream() );

		$stream = ManifestStream::from_plans( array( $plan_a, $plan_b ) );

		$this->assertSame( 350, $stream->estimated_bytes() );
	}

	/**
	 * The stream can be iterated more than once (IteratorAggregate, not a spent generator).
	 *
	 * @return void
	 */
	public function test_stream_can_be_iterated_more_than_once(): void {
		$stream = ManifestStream::from_plans(
			array( $this->sample_plan( 'a.txt' ), $this->sample_plan( 'b.txt' ) )
		);

		$first  = iterator_to_array( $stream );
		$second = iterator_to_array( $stream );

		$this->assertCount( 2, $first );
		$this->assertCount( 2, $second );
	}
}
