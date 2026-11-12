<?php
/**
 * Unit tests for ScheduleBootstrap's factory wiring.
 *
 * @package Pontifex\Tests\Unit\Schedule
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Schedule;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Pontifex\Log\FileLogger;
use Pontifex\Schedule\JobTicker;
use Pontifex\Schedule\ScheduleBootstrap;
use Pontifex\Schedule\ScheduledExporter;
use Psr\Log\LoggerInterface;

/**
 * Pins ScheduleBootstrap's real job: deciding WHERE unattended backups read
 * and write, and handing every collaborator the same answer.
 *
 * The one invariant that matters most is that {@see ScheduledExporter} and
 * the {@see JobTicker} it hands the job off to are rooted at the same
 * content directory. If they ever disagreed, the exporter would write a job
 * record the ticker's own directory scan can never find: a scheduled backup
 * would start every night and never progress, with nothing on screen or in
 * the log to say why.
 *
 * Two branches are deliberately NOT exercised here, and are recorded rather
 * than faked around:
 *
 *  - The `WP_CONTENT_DIR` branch of `content_dir()`. Constants are
 *    process-global in PHP; this suite's bootstrap never defines
 *    `WP_CONTENT_DIR`, and defining it inside a test would leak into every
 *    other test file that runs in the same process under
 *    `executionOrder="random"`. Only the `ABSPATH` fallback branch is
 *    covered here.
 *  - The `NullLogger` fallback in `logger()`. It only fires when
 *    `FileLogger::__construct()` throws, and that constructor only assigns
 *    fields — it performs no I/O and cannot throw. There is no way to reach
 *    that branch honestly without changing production code, so it is left
 *    untested rather than exercised via a contrived double.
 */
final class ScheduleBootstrapTest extends TestCase {

	/**
	 * The content directory ScheduleBootstrap resolves to under this suite's
	 * ABSPATH fallback (tests/bootstrap.php defines ABSPATH with a trailing
	 * slash and never defines WP_CONTENT_DIR).
	 *
	 * @var string
	 */
	private string $expected_content_dir;

	/**
	 * Compute the expected content root.
	 *
	 * Deliberately does not touch the filesystem: `$expected_content_dir`
	 * resolves to a path under the system temp directory (see
	 * `test_content_dir_resolves_the_absolute_path_fallback_with_trailing_slash_trimmed`'s
	 * docblock), which this suite does not own and must never create or
	 * delete. Nothing in this class creates a directory, so nothing needs
	 * removing before or after a test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->expected_content_dir = rtrim( ABSPATH, '/' ) . '/wp-content';
	}

	/**
	 * `content_dir()`'s ABSPATH fallback trims the trailing slash rather than
	 * doubling it into the appended path.
	 *
	 * Real defect this catches: ABSPATH always carries a trailing slash (both
	 * in this suite and on a real WordPress install); a fix that dropped the
	 * `rtrim()` would resolve every store to a path with a doubled slash,
	 * which still happens to work on most filesystems but is never what the
	 * directory listing, the log line, or a support request should show an
	 * operator.
	 *
	 * @return void
	 */
	public function test_content_dir_resolves_the_absolute_path_fallback_with_trailing_slash_trimmed(): void {
		$method = new ReflectionMethod( ScheduleBootstrap::class, 'content_dir' );

		$this->assertSame( $this->expected_content_dir, $method->invoke( null ) );
		$this->assertStringNotContainsString( '//', $method->invoke( null ) );
	}

	/**
	 * The exporter and the ticker it hands the job off to are rooted at one
	 * content directory: the load-bearing invariant this class exists for.
	 *
	 * Real defect this catches: if `scheduled_exporter()` ever built its own
	 * `JobStore`/`BackupStore` pair from a different content root than the
	 * one it hands into the nested `JobTicker`, the exporter would write a
	 * job the ticker's own directory scan can never see — a scheduled backup
	 * would start and then silently never progress.
	 *
	 * @return void
	 */
	public function test_scheduled_exporter_and_its_internal_ticker_share_one_content_root(): void {
		$exporter = ScheduleBootstrap::scheduled_exporter();

		$job_store    = self::read_private_property( $exporter, 'job_store' );
		$backup_store = self::read_private_property( $exporter, 'backup_store' );
		$ticker       = self::read_private_property( $exporter, 'ticker' );

		$this->assertInstanceOf( JobTicker::class, $ticker );

		$ticker_job_store    = self::read_private_property( $ticker, 'job_store' );
		$ticker_backup_store = self::read_private_property( $ticker, 'backup_store' );

		$expected_job_dir    = $this->expected_content_dir . '/pontifex/jobs';
		$expected_backup_dir = $this->expected_content_dir . '/pontifex/backups';

		$this->assertSame( $expected_job_dir, $job_store->directory() );
		$this->assertSame( $expected_backup_dir, $backup_store->directory() );
		$this->assertSame( $expected_job_dir, $ticker_job_store->directory(), 'The internal ticker must read jobs from the same directory the exporter writes them to.' );
		$this->assertSame( $expected_backup_dir, $ticker_backup_store->directory(), 'The internal ticker must land backups in the same directory the exporter resolved.' );
	}

	/**
	 * `job_ticker()` — the standalone `pontifex_tick_jobs` handler used to
	 * continue a job whose driving request has already died — roots its own
	 * stores at the same content directory as the exporter.
	 *
	 * Real defect this catches: the cron-scheduled ticker (built fresh on its
	 * own WP-Cron invocation, not the one nested inside a live exporter run)
	 * reading from a different directory than the one an in-progress backup
	 * actually wrote into, so a continuation never finds the job it is meant
	 * to drive to completion.
	 *
	 * @return void
	 */
	public function test_job_ticker_factory_roots_its_own_stores_at_the_same_content_directory(): void {
		$ticker = ScheduleBootstrap::job_ticker();

		$job_store    = self::read_private_property( $ticker, 'job_store' );
		$backup_store = self::read_private_property( $ticker, 'backup_store' );

		$this->assertSame( $this->expected_content_dir . '/pontifex/jobs', $job_store->directory() );
		$this->assertSame( $this->expected_content_dir . '/pontifex/backups', $backup_store->directory() );
	}

	/**
	 * The exporter is wired with a real {@see FileLogger} rooted under the
	 * content directory's `pontifex/logs`, not merely something implementing
	 * {@see LoggerInterface}.
	 *
	 * Real defect this catches: a cron-driven backup's only trace, when
	 * nothing is watching a terminal, is this log file. If it were pointed
	 * anywhere else, an operator would look at the log the admin screen shows
	 * them and find no record of the run that actually happened.
	 *
	 * @return void
	 */
	public function test_scheduled_exporter_logger_is_a_file_logger_rooted_under_pontifex_logs(): void {
		$exporter = ScheduleBootstrap::scheduled_exporter();
		$logger   = self::read_private_property( $exporter, 'logger' );

		$this->assertInstanceOf( LoggerInterface::class, $logger );
		$this->assertInstanceOf( FileLogger::class, $logger );
		$this->assertSame( $this->expected_content_dir . '/pontifex/logs', self::read_private_property( $logger, 'log_dir' ) );
	}

	/**
	 * `job_ticker()`'s own logger is the same kind, rooted at the same place,
	 * as the exporter's — both factory methods must agree, not just the
	 * exporter's internal one.
	 *
	 * @return void
	 */
	public function test_job_ticker_factory_logger_is_a_file_logger_rooted_under_pontifex_logs(): void {
		$ticker = ScheduleBootstrap::job_ticker();
		$logger = self::read_private_property( $ticker, 'logger' );

		$this->assertInstanceOf( LoggerInterface::class, $logger );
		$this->assertInstanceOf( FileLogger::class, $logger );
		$this->assertSame( $this->expected_content_dir . '/pontifex/logs', self::read_private_property( $logger, 'log_dir' ) );
	}

	/**
	 * Calling either factory has no filesystem side effects.
	 *
	 * Real defect this catches: these are meant to be cold factories — the
	 * plugin bootstrap calls them from a closure registered against a cron
	 * hook, so every ordinary request that never fires `pontifex_scheduled_export`
	 * or `pontifex_tick_jobs` still constructs the handler object (to register
	 * it), and that must not scribble a `wp-content/pontifex` tree onto disk
	 * for a site that has never scheduled anything.
	 *
	 * The assertion is deliberately about the existence state being
	 * UNCHANGED, not about the directory being absent. `$expected_content_dir`
	 * resolves under the system temp directory, which this suite does not own
	 * and must never create or delete — asserting absence would only hold
	 * because a setUp()/tearDown() pair had just destroyed whatever a
	 * developer or another process keeps there, which is exactly the
	 * behaviour this project cannot ship in its own test suite. Recording the
	 * before-state and comparing it to the after-state proves the real claim
	 * — "this factory changes nothing on disk" — and holds whether or not the
	 * directory happens to pre-exist.
	 *
	 * @return void
	 */
	public function test_factories_have_no_filesystem_side_effects(): void {
		$existed_before = is_dir( $this->expected_content_dir );

		ScheduleBootstrap::scheduled_exporter();
		ScheduleBootstrap::job_ticker();

		$this->assertSame( $existed_before, is_dir( $this->expected_content_dir ), 'A cold factory must leave the content directory\'s existence state exactly as it found it.' );
	}

	/**
	 * Read a private property's value via reflection.
	 *
	 * @param object $instance      The object to read from.
	 * @param string $property_name The private property's name.
	 * @return mixed The property's current value.
	 */
	private static function read_private_property( object $instance, string $property_name ): mixed {
		return ( new ReflectionProperty( $instance, $property_name ) )->getValue( $instance );
	}
}
