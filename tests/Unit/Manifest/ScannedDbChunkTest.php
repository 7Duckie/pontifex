<?php
/**
 * Unit tests for the ScannedDbChunk value object.
 *
 * @package Pontifex\Tests\Unit\Manifest
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Manifest;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Pontifex\Manifest\ScannedDbChunk;

/**
 * Tests for {@see ScannedDbChunk}.
 */
final class ScannedDbChunkTest extends TestCase {

	/**
	 * Build a trivial SQL provider that returns a fresh memory stream.
	 *
	 * @param string $sql The SQL bytes to write into the stream.
	 * @return callable A provider returning a fresh stream resource on each invocation.
	 */
	private static function provider( string $sql = "INSERT INTO `t` VALUES (1);\n" ): callable {
		return static function () use ( $sql ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://memory is an in-process buffer.
			$stream = fopen( 'php://memory', 'r+b' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- in-process stream.
			fwrite( $stream, $sql );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- in-process stream.
			rewind( $stream );
			return $stream;
		};
	}

	/**
	 * The constructor must accept valid inputs and expose them via accessors.
	 *
	 * @return void
	 */
	public function test_constructor_accepts_valid_inputs(): void {
		$chunk = new ScannedDbChunk( 'wp_posts', 0, 42, 1024, self::provider() );

		$this->assertSame( 'wp_posts', $chunk->table_name() );
		$this->assertSame( 0, $chunk->chunk_index() );
		$this->assertSame( 42, $chunk->statement_count() );
		$this->assertSame( 1024, $chunk->byte_count() );
	}

	/**
	 * The constructor must reject an empty table_name.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_empty_table_name(): void {
		$this->expectException( InvalidArgumentException::class );

		new ScannedDbChunk( '', 0, 0, 0, self::provider() );
	}

	/**
	 * The constructor must reject a negative chunk_index.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_negative_chunk_index(): void {
		$this->expectException( InvalidArgumentException::class );

		new ScannedDbChunk( 'wp_posts', -1, 0, 0, self::provider() );
	}

	/**
	 * The constructor must reject a negative statement_count.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_negative_statement_count(): void {
		$this->expectException( InvalidArgumentException::class );

		new ScannedDbChunk( 'wp_posts', 0, -1, 0, self::provider() );
	}

	/**
	 * The constructor must reject a negative byte_count.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_negative_byte_count(): void {
		$this->expectException( InvalidArgumentException::class );

		new ScannedDbChunk( 'wp_posts', 0, 0, -1, self::provider() );
	}

	/**
	 * The constructor must accept zero values for index, statement_count, and byte_count (empty-chunk case).
	 *
	 * @return void
	 */
	public function test_constructor_accepts_zero_values(): void {
		$chunk = new ScannedDbChunk( 'wp_posts', 0, 0, 0, self::provider() );

		$this->assertSame( 0, $chunk->chunk_index() );
		$this->assertSame( 0, $chunk->statement_count() );
		$this->assertSame( 0, $chunk->byte_count() );
	}

	/**
	 * The open_sql_stream method must invoke the provider and return a readable resource.
	 *
	 * @return void
	 */
	public function test_open_sql_stream_returns_provider_stream(): void {
		$expected_sql = 'INSERT INTO `t` VALUES (1);';
		$chunk        = new ScannedDbChunk( 't', 0, 1, 32, self::provider( $expected_sql ) );

		$stream = $chunk->open_sql_stream();
		$this->assertIsResource( $stream );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_stream_get_contents -- Operating on a test stream resource.
		$bytes = stream_get_contents( $stream );
		$this->assertSame( $expected_sql, $bytes );
	}

	/**
	 * Each call to open_sql_stream must invoke the provider afresh.
	 *
	 * @return void
	 */
	public function test_open_sql_stream_invokes_provider_each_call(): void {
		$call_count = 0;
		$provider   = function () use ( &$call_count ) {
			++$call_count;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- in-process stream.
			return fopen( 'php://memory', 'r+b' );
		};

		$chunk = new ScannedDbChunk( 't', 0, 0, 0, $provider );

		$chunk->open_sql_stream();
		$chunk->open_sql_stream();
		$chunk->open_sql_stream();

		$this->assertSame( 3, $call_count );
	}

	/**
	 * The open_sql_stream method must throw RuntimeException if the provider returns a non-resource.
	 *
	 * @return void
	 */
	public function test_open_sql_stream_throws_when_provider_returns_non_resource(): void {
		$bad_provider = static function () {
			return 'not a resource';
		};

		$chunk = new ScannedDbChunk( 't', 0, 0, 0, $bad_provider );

		$this->expectException( RuntimeException::class );
		$chunk->open_sql_stream();
	}
}
