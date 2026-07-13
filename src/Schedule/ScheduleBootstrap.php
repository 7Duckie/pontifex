<?php
/**
 * Pontifex schedule bootstrap — builds the cron handlers with their real collaborators.
 *
 * @package Pontifex\Schedule
 */

declare(strict_types=1);

namespace Pontifex\Schedule;

use Pontifex\Admin\BackupStore;
use Pontifex\Environment\RealEnvironment;
use Pontifex\Job\JobStore;
use Pontifex\Log\FileLogger;
use Pontifex\WordPress\RealWordPressContext;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Wires the cron handlers' production dependencies.
 *
 * The plugin bootstrap keeps its closures one-line by delegating the
 * construction here. Everything is built lazily inside the factory
 * methods, so requests that never fire a cron event construct nothing.
 */
final class ScheduleBootstrap {

	/**
	 * Build the scheduled-export handler with production collaborators.
	 *
	 * @return ScheduledExporter The handler.
	 */
	public static function scheduled_exporter(): ScheduledExporter {
		$environment  = new RealEnvironment();
		$context      = new RealWordPressContext();
		$content_dir  = self::content_dir();
		$job_store    = new JobStore( $content_dir );
		$backup_store = new BackupStore( $content_dir );
		$logger       = self::logger( $content_dir );

		return new ScheduledExporter(
			$environment,
			$context,
			$job_store,
			$backup_store,
			new JobTicker( $environment, $context, $job_store, $backup_store, $logger ),
			$logger
		);
	}

	/**
	 * Build the job-tick handler with production collaborators.
	 *
	 * @return JobTicker The handler.
	 */
	public static function job_ticker(): JobTicker {
		$environment = new RealEnvironment();
		$context     = new RealWordPressContext();
		$content_dir = self::content_dir();

		return new JobTicker(
			$environment,
			$context,
			new JobStore( $content_dir ),
			new BackupStore( $content_dir ),
			self::logger( $content_dir )
		);
	}

	/**
	 * The site's wp-content directory.
	 *
	 * @return string The absolute path, no trailing slash.
	 */
	private static function content_dir(): string {
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			return rtrim( (string) constant( 'WP_CONTENT_DIR' ), '/' );
		}
		return rtrim( ABSPATH, '/' ) . '/wp-content';
	}

	/**
	 * The central file logger, falling back to silence if it cannot be built.
	 *
	 * A cron run on a read-only filesystem must still do its work; losing the
	 * log line is the acceptable degradation.
	 *
	 * @param string $content_dir The wp-content directory.
	 * @return LoggerInterface The logger.
	 */
	private static function logger( string $content_dir ): LoggerInterface {
		try {
			return new FileLogger( $content_dir . '/pontifex/logs', false );
		} catch ( \Throwable $error ) {
			return new NullLogger();
		}
	}
}
