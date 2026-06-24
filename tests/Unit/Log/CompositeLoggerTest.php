<?php
/**
 * Unit tests for the CompositeLogger fan-out.
 *
 * @package Pontifex\Tests\Unit\Log
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Log;

use Mockery;
use Pontifex\Log\CompositeLogger;
use Pontifex\Tests\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Behavioural coverage of {@see CompositeLogger}: every line reaches every child
 * logger unchanged, and a composite over no loggers is a harmless no-op.
 *
 * The children are LoggerInterface mocks whose forwarded arguments are captured
 * and asserted explicitly, so the tests pin the behaviour rather than relying on
 * the mock expectation alone.
 */
final class CompositeLoggerTest extends TestCase {

	/**
	 * Each logged line is forwarded to every child with identical arguments.
	 *
	 * @return void
	 */
	public function test_fans_each_line_out_to_every_logger(): void {
		$received = array();
		$capture  = static function ( $level, $message, $context ) use ( &$received ): void {
			$received[] = array( $level, (string) $message, $context );
		};

		$first = Mockery::mock( LoggerInterface::class );
		$first->shouldReceive( 'log' )->once()->andReturnUsing( $capture );
		$second = Mockery::mock( LoggerInterface::class );
		$second->shouldReceive( 'log' )->once()->andReturnUsing( $capture );

		( new CompositeLogger( $first, $second ) )->info( 'Export started.', array( 'output' => '/tmp/site.wpmig' ) );

		$this->assertCount( 2, $received );
		$this->assertSame( array( LogLevel::INFO, 'Export started.', array( 'output' => '/tmp/site.wpmig' ) ), $received[0] );
		$this->assertSame( $received[0], $received[1], 'Both children should receive the identical call.' );
	}

	/**
	 * The level and a defaulted (empty) context are forwarded faithfully.
	 *
	 * @return void
	 */
	public function test_forwards_level_and_default_context(): void {
		$received = array();
		$child    = Mockery::mock( LoggerInterface::class );
		$child->shouldReceive( 'log' )->once()->andReturnUsing(
			static function ( $level, $message, $context ) use ( &$received ): void {
				$received = array( $level, (string) $message, $context );
			}
		);

		( new CompositeLogger( $child ) )->error( 'Export failed.' );

		$this->assertSame( array( LogLevel::ERROR, 'Export failed.', array() ), $received );
	}

	/**
	 * A composite over no loggers swallows the line without error.
	 *
	 * @return void
	 */
	public function test_no_loggers_is_a_silent_no_op(): void {
		$this->expectNotToPerformAssertions();

		( new CompositeLogger() )->info( 'nothing is listening' );
	}
}
