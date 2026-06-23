<?php
/**
 * Unit tests for the StatsCommand row-building and activity logic.
 *
 * @package Pontifex\Tests\Unit\Cli
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Cli;

use Mockery;
use ReflectionMethod;
use Pontifex\Cli\StatsCommand;
use Pontifex\Tests\TestCase;
use Pontifex\WordPress\WordPressContext;

/**
 * Behavioural coverage of {@see StatsCommand}'s pure logic.
 *
 * The `__invoke` path renders through WP-CLI's Formatter, which needs the
 * WP-CLI runtime, so it is exercised by the wp-env smoke (as with DoctorCommand)
 * rather than here. What these tests pin down is the readout itself: that the
 * rows report the stored counters per operation, that a missing or corrupt
 * option degrades to zeros, that the byte total is rendered through format_size,
 * and that the "no activity" and machine-format predicates behave.
 *
 * Private methods are reached via reflection, mirroring DoctorCommand's tests.
 */
final class StatsCommandTest extends TestCase {

	/**
	 * Build a StatsCommand with the given WordPressContext.
	 *
	 * @param WordPressContext $wordpress_context The context to inject.
	 * @return StatsCommand The command.
	 */
	private function build_command( WordPressContext $wordpress_context ): StatsCommand {
		return new StatsCommand( $wordpress_context );
	}

	/**
	 * Invoke a private method on the command via reflection.
	 *
	 * @param StatsCommand $command The command instance.
	 * @param string       $method  The private method name.
	 * @param mixed        ...$args The arguments to pass.
	 * @return mixed The method's return value.
	 */
	private function invoke_private( StatsCommand $command, string $method, ...$args ) {
		$reflection = new ReflectionMethod( $command, $method );
		return $reflection->invoke( $command, ...$args );
	}

	/**
	 * Build a WordPressContext mock whose format_size echoes the byte count.
	 *
	 * @return WordPressContext&\Mockery\MockInterface
	 */
	private function context_with_echoing_format_size() {
		$mock = Mockery::mock( WordPressContext::class );
		$mock->shouldReceive( 'format_size' )->andReturnUsing(
			static function ( int $bytes ): string {
				return $bytes . ' bytes';
			}
		);
		return $mock;
	}

	/**
	 * The rows report the stored export and import counters, one row per operation.
	 *
	 * @return void
	 */
	public function test_build_rows_reports_export_and_import_counts(): void {
		$command = $this->build_command( $this->context_with_echoing_format_size() );

		$export = array(
			'attempted'      => 5,
			'succeeded'      => 4,
			'failed'         => 1,
			'bytes_exported' => 1000,
		);
		$import = array(
			'attempted'      => 2,
			'succeeded'      => 2,
			'failed'         => 0,
			'bytes_imported' => 500,
		);

		$rows = $this->invoke_private( $command, 'build_rows', $export, $import );

		$this->assertCount( 2, $rows );

		$this->assertSame( 'export', $rows[0]['operation'] );
		$this->assertSame( 5, $rows[0]['attempted'] );
		$this->assertSame( 4, $rows[0]['succeeded'] );
		$this->assertSame( 1, $rows[0]['failed'] );
		$this->assertSame( '1000 bytes', $rows[0]['size'] );

		$this->assertSame( 'import', $rows[1]['operation'] );
		$this->assertSame( 2, $rows[1]['attempted'] );
		$this->assertSame( 0, $rows[1]['failed'] );
		$this->assertSame( '500 bytes', $rows[1]['size'] );
	}

	/**
	 * A missing or corrupt counters option degrades every value to zero.
	 *
	 * @return void
	 */
	public function test_build_rows_tolerates_missing_or_corrupt_counters(): void {
		$command = $this->build_command( $this->context_with_echoing_format_size() );

		// Export: empty option. Import: a non-numeric attempted and a null byte total.
		$rows = $this->invoke_private(
			$command,
			'build_rows',
			array(),
			array(
				'attempted'      => 'garbage',
				'bytes_imported' => null,
			)
		);

		$this->assertSame( 0, $rows[0]['attempted'] );
		$this->assertSame( '0 bytes', $rows[0]['size'] );
		$this->assertSame( 0, $rows[1]['attempted'] );
		$this->assertSame( '0 bytes', $rows[1]['size'] );
	}

	/**
	 * The no_activity predicate is true only when neither operation has been attempted.
	 *
	 * @return void
	 */
	public function test_no_activity_reflects_the_attempted_counters(): void {
		$command = $this->build_command( Mockery::mock( WordPressContext::class ) );

		$this->assertTrue( $this->invoke_private( $command, 'no_activity', array(), array() ) );
		$this->assertTrue(
			$this->invoke_private( $command, 'no_activity', array( 'attempted' => 0 ), array( 'attempted' => 0 ) )
		);
		$this->assertFalse(
			$this->invoke_private( $command, 'no_activity', array( 'attempted' => 1 ), array() )
		);
		$this->assertFalse(
			$this->invoke_private( $command, 'no_activity', array(), array( 'attempted' => 3 ) )
		);
	}

	/**
	 * The is_machine_format predicate is true for any format other than the default table.
	 *
	 * @return void
	 */
	public function test_is_machine_format_distinguishes_table_from_the_rest(): void {
		$command = $this->build_command( Mockery::mock( WordPressContext::class ) );

		$this->assertFalse( $this->invoke_private( $command, 'is_machine_format', array() ) );
		$this->assertFalse( $this->invoke_private( $command, 'is_machine_format', array( 'format' => 'table' ) ) );
		$this->assertTrue( $this->invoke_private( $command, 'is_machine_format', array( 'format' => 'json' ) ) );
		$this->assertTrue( $this->invoke_private( $command, 'is_machine_format', array( 'format' => 'csv' ) ) );
	}
}
