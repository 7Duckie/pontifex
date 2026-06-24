<?php
/**
 * A PSR-3 logger that fans every line out to several other loggers.
 *
 * @package Pontifex\Log
 */

declare(strict_types=1);

namespace Pontifex\Log;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Stringable;

/**
 * Forwards each log line to every logger it was given (a "tee").
 *
 * Pontifex keeps one central rotating log for the whole site, but also wants a
 * self-contained log of a single transfer written alongside that transfer's
 * archive. Rather than teach every command to write twice, a command wraps its
 * logger in this one: a single info()/error() call then reaches both the
 * central file and the per-transfer file.
 *
 * Extending AbstractLogger means only log() has to be implemented; the eight
 * level-named helpers (info(), error(), and the rest) all route through it, so
 * each child receives exactly the call the caller made.
 *
 * This class does no I/O of its own. It relies on its children honouring the
 * logging contract — a logger MUST NEVER throw (see {@see FileLogger}) — so a
 * failing sink can neither break the transfer nor starve the other sinks. It is
 * therefore deliberately free of its own error handling.
 *
 * Marked final: it is a leaf composition with no extension point.
 */
final class CompositeLogger extends AbstractLogger {

	/**
	 * The loggers each line is forwarded to, in order.
	 *
	 * @var array<int, LoggerInterface>
	 */
	private array $loggers;

	/**
	 * Build a composite over zero or more loggers.
	 *
	 * Variadic so callers read naturally — `new CompositeLogger( $central,
	 * $per_transfer )`. With no arguments it is a working no-op logger.
	 *
	 * @param LoggerInterface ...$loggers The loggers to fan out to.
	 */
	public function __construct( LoggerInterface ...$loggers ) {
		$this->loggers = $loggers;
	}

	/**
	 * Forward one log line to every child logger.
	 *
	 * The single PSR-3 entry point: AbstractLogger routes every level-named
	 * helper through here, so passing the level, message and context straight on
	 * gives each child the identical call.
	 *
	 * @param mixed                $level   One of the PSR-3 LogLevel constants.
	 * @param string|Stringable    $message The message, possibly with {placeholders}.
	 * @param array<string, mixed> $context Values for placeholders and structured extras.
	 * @return void
	 */
	public function log( $level, string|Stringable $message, array $context = array() ): void {
		foreach ( $this->loggers as $logger ) {
			$logger->log( $level, $message, $context );
		}
	}
}
