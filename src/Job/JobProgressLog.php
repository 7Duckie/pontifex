<?php
/**
 * Pontifex job progress log — the append-only record of a job's completed work.
 *
 * @package Pontifex\Job
 */

declare(strict_types=1);

namespace Pontifex\Job;

use JsonException;
use RuntimeException;

/**
 * Append-only JSON-lines sidecar carrying a job's per-item progress.
 *
 * The job record itself stays small (a cursor and counts); what grows
 * with the site — one record per completed archive entry, the
 * manifest-so-far a resumed export rebuilds from — is appended here,
 * one JSON object per line. Appending is O(1) however large the site,
 * where rewriting a giant job JSON on every entry would be O(n²) over
 * the export.
 *
 * Crash tolerance is the reason for the line-oriented shape: a ticker
 * killed mid-append leaves at most one torn final line. read_all()
 * tolerates exactly that — an unparseable LAST line is dropped, because
 * the work it described never had its job state saved either — while an
 * unparseable line anywhere earlier is corruption and is refused loudly.
 */
final class JobProgressLog {

	/**
	 * Absolute path of the JSONL file.
	 *
	 * @var string
	 */
	private string $path;

	/**
	 * Construct a log over the given path.
	 *
	 * @param string $path Absolute path of the .jsonl sidecar.
	 */
	public function __construct( string $path ) {
		$this->path = $path;
	}

	/**
	 * Return the log's absolute path.
	 *
	 * @return string The path.
	 */
	public function path(): string {
		return $this->path;
	}

	/**
	 * Append one record as a JSON line.
	 *
	 * @param array<string, mixed> $record The record to append.
	 * @return void
	 * @throws RuntimeException If encoding or the filesystem append fails.
	 */
	public function append( array $record ): void {
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Plugin-internal persistence encode with JSON_THROW_ON_ERROR; wp_json_encode adds nothing needed and depends on WordPress being loaded.
			$line = json_encode( $record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) . "\n";
		} catch ( JsonException $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $e is the underlying encode exception, chained for diagnostics; not HTML output.
			throw new RuntimeException( 'JobProgressLog: could not encode the progress record.', 0, $e );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents,WordPress.PHP.NoSilencedErrors.Discouraged -- Appending to the plugin's own progress sidecar; WP_Filesystem is unavailable in CLI/cron contexts where this runs.
		$written = @file_put_contents( $this->path, $line, FILE_APPEND );
		if ( strlen( $line ) !== $written ) {
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The path is plugin-derived; exception path, not HTML output.
				sprintf( 'JobProgressLog: could not append to the progress log: %s', $this->path )
			);
		}
	}

	/**
	 * Read every complete record, tolerating a torn final line.
	 *
	 * @return array<int, array<string, mixed>> The decoded records, in append order.
	 * @throws RuntimeException If the file is unreadable, or a line OTHER than the last is unparseable (corruption, not a crash artefact).
	 */
	public function read_all(): array {
		if ( ! is_file( $this->path ) ) {
			return array();
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.PHP.NoSilencedErrors.Discouraged -- Reading the plugin's own progress sidecar; WP_Filesystem is unavailable in CLI/cron contexts.
		$contents = @file_get_contents( $this->path );
		if ( false === $contents ) {
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The path is plugin-derived; exception path, not HTML output.
				sprintf( 'JobProgressLog: could not read the progress log: %s', $this->path )
			);
		}

		$lines   = explode( "\n", $contents );
		$last    = count( $lines ) - 1;
		$records = array();
		foreach ( $lines as $index => $line ) {
			if ( '' === $line ) {
				continue;
			}
			try {
				$record = json_decode( $line, true, 32, JSON_THROW_ON_ERROR );
			} catch ( JsonException $e ) {
				if ( $index === $last ) {
					// A torn final line — unterminated, so the ticker died
					// mid-append. The work it described was never committed to
					// the job state either, so dropping it is the consistent
					// view; a complete file ends with a newline, making the
					// final split element an empty string, never a record.
					break;
				}
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The path is plugin-derived and $e is chained for diagnostics; exception path, not HTML output.
				throw new RuntimeException( sprintf( 'JobProgressLog: the progress log is corrupt mid-file: %s', $this->path ), 0, $e );
			}
			if ( is_array( $record ) ) {
				$records[] = $record;
			}
		}
		return $records;
	}

	/**
	 * Truncate the log back to a known-good record count.
	 *
	 * Used on resume when the archive's verified tail is shorter than the
	 * log (the ticker died between appending here and flushing bytes): the
	 * log is rewritten to exactly the records the archive actually holds.
	 *
	 * @param int $count How many leading records to keep.
	 * @return void
	 * @throws RuntimeException If the rewrite fails.
	 */
	public function truncate_to( int $count ): void {
		$records = array_slice( $this->read_all(), 0, max( 0, $count ) );
		$lines   = '';
		foreach ( $records as $record ) {
			try {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Plugin-internal persistence encode with JSON_THROW_ON_ERROR; wp_json_encode adds nothing needed and depends on WordPress being loaded.
				$lines .= json_encode( $record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) . "\n";
			} catch ( JsonException $e ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $e is the underlying encode exception, chained for diagnostics; not HTML output.
				throw new RuntimeException( 'JobProgressLog: could not re-encode the progress log.', 0, $e );
			}
		}
		$temp = $this->path . '.' . uniqid( 'pontifex-', true ) . '.tmp';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents,WordPress.PHP.NoSilencedErrors.Discouraged -- Rewriting the plugin's own progress sidecar; WP_Filesystem is unavailable in CLI/cron contexts.
		if ( false === @file_put_contents( $temp, $lines ) ) {
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The path is plugin-derived; exception path, not HTML output.
				sprintf( 'JobProgressLog: could not rewrite the progress log: %s', $this->path )
			);
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename,WordPress.PHP.NoSilencedErrors.Discouraged -- Atomic same-directory move of the rewritten sidecar; WP_Filesystem is unavailable in CLI/cron contexts.
		if ( ! @rename( $temp, $this->path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort cleanup; its failure must not mask the rename failure.
			@unlink( $temp );
			throw new RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The path is plugin-derived; exception path, not HTML output.
				sprintf( 'JobProgressLog: could not move the rewritten progress log into place: %s', $this->path )
			);
		}
	}
}
