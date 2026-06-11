<?php
/**
 * A dependency-free PSR-3 logger that writes to a rotating file.
 *
 * @package Pontifex\Log
 */

declare(strict_types=1);

namespace Pontifex\Log;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;
use Throwable;

/**
 * Writes log lines to a single file, with size-based rotation.
 *
 * Design contract: this logger MUST NEVER throw and MUST NEVER let a
 * PHP warning reach the caller. A backup/restore tool cannot have its
 * own logging break the operation it is trying to record. Every I/O
 * failure is swallowed; on an unrecoverable one the logger silently
 * disables itself for the rest of its life.
 *
 * It has no WordPress coupling. It is built from a plain directory
 * path and a boolean, so it unit-tests against a temporary directory
 * with no WordPress bootstrap.
 *
 * Marked final: it is a leaf implementation with no extension point.
 * Tests exercise it directly rather than mocking it; the seam callers
 * depend on is the PSR-3 LoggerInterface, not this concrete class.
 */
final class FileLogger extends AbstractLogger {

	/**
	 * Maximum size of the live log file before it rotates, in bytes.
	 *
	 * Two megabytes. Paired with MAX_LOG_BACKUPS this caps total log
	 * storage at five files of two megabytes each: ten megabytes.
	 */
	private const MAX_LOG_BYTES = 2 * 1024 * 1024;

	/**
	 * How many rotated backups to keep (pontifex.log.1 .. pontifex.log.4).
	 *
	 * On rotation the oldest is dropped, so storage never grows past
	 * the live file plus this many backups.
	 */
	private const MAX_LOG_BACKUPS = 4;

	/**
	 * Base filename of the live log inside the log directory.
	 */
	private const LOG_FILENAME = 'pontifex.log';

	/**
	 * The eight PSR-3 levels ranked by severity, most severe first.
	 *
	 * A lower array index means more severe. The active floor and an
	 * incoming message are each looked up here and compared by index.
	 *
	 * @var array<int, string>
	 */
	private const LEVEL_SEVERITY = array(
		LogLevel::EMERGENCY,
		LogLevel::ALERT,
		LogLevel::CRITICAL,
		LogLevel::ERROR,
		LogLevel::WARNING,
		LogLevel::NOTICE,
		LogLevel::INFO,
		LogLevel::DEBUG,
	);

	/**
	 * Absolute path to the directory the log files live in.
	 *
	 * @var string
	 */
	private string $log_dir;

	/**
	 * Whether debug-level messages are written (true under WP_DEBUG).
	 *
	 * @var bool
	 */
	private bool $debug_enabled;

	/**
	 * Set once an I/O failure makes further logging pointless.
	 *
	 * @var bool
	 */
	private bool $disabled = false;

	/**
	 * Whether the once-per-instance rotation check has run.
	 *
	 * @var bool
	 */
	private bool $rotation_checked = false;

	/**
	 * Build a logger pointed at a directory.
	 *
	 * @param string $log_dir       Directory to write log files into. Created if absent.
	 * @param bool   $debug_enabled Whether to record debug-level lines (pass WP_DEBUG).
	 */
	public function __construct( string $log_dir, bool $debug_enabled ) {
		$this->log_dir       = rtrim( $log_dir, '/\\' );
		$this->debug_enabled = $debug_enabled;
	}

	/**
	 * Record one message at a given level (the single PSR-3 entry point).
	 *
	 * AbstractLogger routes every level-named helper (info(), error(),
	 * and the rest) through here. Messages below the active floor and
	 * all writes after a failure are silently dropped.
	 *
	 * @param mixed                $level   One of the PSR-3 LogLevel constants.
	 * @param string|Stringable    $message The message, possibly with {placeholders}.
	 * @param array<string, mixed> $context Values for placeholders and structured extras.
	 * @return void
	 */
	public function log( $level, string|Stringable $message, array $context = array() ): void {
		if ( $this->disabled ) {
			return;
		}

		if ( ! $this->passes_floor( (string) $level ) ) {
			return;
		}

		$this->write_line( $this->format_line( (string) $level, (string) $message, $context ) );
	}

	/**
	 * Decide whether a level is severe enough to be written.
	 *
	 * The floor is INFO normally and DEBUG when debug is enabled. An
	 * unrecognised level is never dropped, so an odd caller can never
	 * silently lose a message.
	 *
	 * @param string $level The level to test.
	 * @return bool True if the message should be written.
	 */
	private function passes_floor( string $level ): bool {
		$floor      = $this->debug_enabled ? LogLevel::DEBUG : LogLevel::INFO;
		$floor_rank = (int) array_search( $floor, self::LEVEL_SEVERITY, true );
		$level_rank = array_search( $level, self::LEVEL_SEVERITY, true );

		if ( false === $level_rank ) {
			return true;
		}

		return $level_rank <= $floor_rank;
	}

	/**
	 * Turn a level, message and context into one finished log line.
	 *
	 * Shape: "[UTC-ISO-8601] LEVEL: message | ExceptionClass: detail | {json}".
	 * Placeholders in the message are interpolated from context; an
	 * 'exception' Throwable is rendered as class and message; any
	 * remaining context is appended as compact JSON.
	 *
	 * @param string               $level   The level name.
	 * @param string               $message The raw message.
	 * @param array<string, mixed> $context The context array.
	 * @return string The line, terminated with a newline.
	 */
	private function format_line( string $level, string $message, array $context ): string {
		$line = sprintf( '[%s] %s: %s', gmdate( 'c' ), strtoupper( $level ), $this->interpolate( $message, $context ) );

		if ( isset( $context['exception'] ) && $context['exception'] instanceof Throwable ) {
			$exception = $context['exception'];
			$line     .= sprintf( ' | %s: %s', $exception::class, $exception->getMessage() );
			unset( $context['exception'] );
		}

		if ( array() !== $context ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- This class is deliberately WordPress-free so it stays unit-testable; wp_json_encode is unavailable here.
			$encoded = json_encode( $context );
			if ( false !== $encoded && '{}' !== $encoded && '[]' !== $encoded ) {
				$line .= ' | ' . $encoded;
			}
		}

		return $line . "\n";
	}

	/**
	 * Substitute {placeholder} tokens in a message with context values.
	 *
	 * Implements the PSR-3 interpolation convention. Only scalar,
	 * null, or Stringable context values are substituted; anything
	 * else is left for the JSON tail.
	 *
	 * @param string               $message The message possibly containing tokens.
	 * @param array<string, mixed> $context The replacement values.
	 * @return string The interpolated message.
	 */
	private function interpolate( string $message, array $context ): string {
		if ( ! str_contains( $message, '{' ) ) {
			return $message;
		}

		$replacements = array();
		foreach ( $context as $key => $value ) {
			if ( null === $value || is_scalar( $value ) || $value instanceof Stringable ) {
				$replacements[ '{' . $key . '}' ] = (string) $value;
			}
		}

		return strtr( $message, $replacements );
	}

	/**
	 * Write one already-formatted line to the live log file.
	 *
	 * Ensures the directory exists, rotates once if the file is over
	 * size, then appends. Any failure disables the logger rather than
	 * surfacing.
	 *
	 * @param string $line The finished line to append.
	 * @return void
	 */
	private function write_line( string $line ): void {
		if ( ! $this->ensure_directory() ) {
			$this->disabled = true;
			return;
		}

		$path = $this->log_dir . '/' . self::LOG_FILENAME;
		$this->maybe_rotate( $path );

		$this->silently(
			function () use ( $path, $line ): void {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Low-level append to a plugin-owned log file; WP_Filesystem is not loaded in CLI/test contexts.
				$handle = fopen( $path, 'ab' );
				if ( false === $handle ) {
					$this->disabled = true;
					return;
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Paired with the fopen above; raw stream write is intentional here.
				fwrite( $handle, $line );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the handle opened above.
				fclose( $handle );
			}
		);
	}

	/**
	 * Make sure the log directory exists, creating it if needed.
	 *
	 * @return bool True if the directory exists or was created.
	 */
	private function ensure_directory(): bool {
		if ( is_dir( $this->log_dir ) ) {
			return true;
		}

		$this->silently(
			function (): void {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating a plugin-owned log directory; WP_Filesystem is unavailable in CLI/test contexts.
				mkdir( $this->log_dir, 0755, true );
			}
		);

		return is_dir( $this->log_dir );
	}

	/**
	 * Rotate the log once per instance if it has grown past the cap.
	 *
	 * The check runs at most once in this logger's life, so a normal
	 * run pays a single stat. A run that writes a great deal in one
	 * go may overshoot the cap until the next run rotates it — an
	 * accepted trade for not stat-ing on every line.
	 *
	 * @param string $path Path to the live log file.
	 * @return void
	 */
	private function maybe_rotate( string $path ): void {
		if ( $this->rotation_checked ) {
			return;
		}
		$this->rotation_checked = true;

		$size = 0;
		$this->silently(
			function () use ( $path, &$size ): void {
				if ( is_file( $path ) ) {
					$size = (int) filesize( $path );
				}
			}
		);

		if ( $size < self::MAX_LOG_BYTES ) {
			return;
		}

		$this->rotate( $path );
	}

	/**
	 * Shuffle the backups up by one and move the live log to .1.
	 *
	 * Drops the oldest backup, renames .3 to .4, .2 to .3, .1 to .2,
	 * then the live log to .1, leaving a fresh slot for the next write.
	 *
	 * @param string $path Path to the live log file.
	 * @return void
	 */
	private function rotate( string $path ): void {
		$this->silently(
			function () use ( $path ): void {
				$oldest = $path . '.' . self::MAX_LOG_BACKUPS;
				if ( is_file( $oldest ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removing the oldest plugin-owned log backup; wp_delete_file is unavailable in CLI/test contexts.
					unlink( $oldest );
				}

				for ( $index = self::MAX_LOG_BACKUPS - 1; $index >= 1; $index-- ) {
					$from = $path . '.' . $index;
					if ( is_file( $from ) ) {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Shifting a plugin-owned log backup up one slot.
						rename( $from, $path . '.' . ( $index + 1 ) );
					}
				}

				if ( is_file( $path ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Moving the live log to the first backup slot.
					rename( $path, $path . '.1' );
				}
			}
		);
	}

	/**
	 * Run an I/O operation with PHP warnings suppressed.
	 *
	 * Logging must never interrupt the caller, so the operation runs
	 * under a handler that swallows any warning (a failed fopen on a
	 * read-only volume, say). The handler is always restored.
	 *
	 * @param callable():void $operation The file operation to attempt.
	 * @return void
	 */
	private function silently( callable $operation ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- A scoped handler that swallows any I/O warning so logging never disrupts the caller; restored in the finally below.
		set_error_handler( static fn (): bool => true );
		try {
			$operation();
		} finally {
			restore_error_handler();
		}
	}
}
