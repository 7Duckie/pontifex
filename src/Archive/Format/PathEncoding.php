<?php
/**
 * Pontifex path encoding — the one place that decides whether a path can go in the format.
 *
 * @package Pontifex\Archive\Format
 */

declare(strict_types=1);

namespace Pontifex\Archive\Format;

/**
 * Tests whether a path can be recorded in an archive, and renders one that cannot.
 *
 * Kept in one place on purpose. The archive format records every path as UTF-8 text
 * (specification §13.2.1), so a file name or symbolic-link target that is not valid UTF-8
 * cannot be written at all. The one-shot export, the resumable export and the safety archive
 * taken before a restore each have to refuse such a backup and name the offending entries, so
 * all three read the same test and the same wording rather than each holding a copy that could
 * drift — a copy that drifted would let one surface refuse a name another surface accepts.
 */
final class PathEncoding {

	/**
	 * The fault reported when an entry's own path is not valid UTF-8.
	 *
	 * @var string
	 */
	public const FAULT_NAME = 'name';

	/**
	 * The fault reported when an entry's path is fine but its symbolic-link target is not valid UTF-8.
	 *
	 * @var string
	 */
	public const FAULT_TARGET = 'target';

	/**
	 * Whether a string is valid UTF-8 and can therefore be recorded in the format.
	 *
	 * Uses PCRE's UTF-8 mode rather than mb_check_encoding(): mbstring is not guaranteed to be
	 * present on a host, and this project guards host functions deliberately, whereas PCRE's UTF-8
	 * support is always compiled in.
	 *
	 * @param string $value The path or symbolic-link target to test.
	 * @return bool True when the value is valid UTF-8; false when the format cannot record it.
	 */
	public static function is_encodable( string $value ): bool {
		return 1 === preg_match( '//u', $value );
	}

	/**
	 * Render a string so it is always valid UTF-8 and safe to put in a message, a log line, a JSON response or a terminal.
	 *
	 * Escapes every byte outside printable ASCII, and the backslash itself, as the C-style escape
	 * addcslashes() produces: `wp-content/uploads/caf<0xE9>.txt` renders as
	 * `wp-content/uploads/caf\351.txt`, and a name holding a newline, a backslash and the bytes
	 * 0x80 and 0xFF renders as `wp-content/a\nb\\c\200\377.txt`. The result is pure ASCII, so it is
	 * always valid UTF-8.
	 *
	 * Because every byte above 0x7F is escaped, a legitimate accented or non-Latin name would also
	 * come out as octal escapes. That is acceptable: this is only ever called on a value already
	 * shown to be unencodable. The leading backslash in the escaped character list is what keeps
	 * the rendering unambiguous — a real backslash in the name renders as `\\`.
	 *
	 * @param string $value The path or symbolic-link target to render.
	 * @return string The escaped, printable rendering.
	 */
	public static function printable( string $value ): string {
		return addcslashes( $value, "\\\0..\37\177..\377" );
	}

	// phpcs:disable Squiz.Commenting.FunctionComment.IncorrectTypeHint -- $entry_headers is documented as iterable<EntryHeader> because PHPStan level 6 requires the value type; see the matching disable on ArchiveManifest::project_payload_bytes().
	/**
	 * Find the entries whose path or symbolic-link target the format cannot record.
	 *
	 * Makes one pass over the headers. For each it tests the path first and then the target
	 * (a db_chunk entry has neither; only a symlink has a target). An entry whose path is
	 * unencodable is counted once, as FAULT_NAME, even if its target is also unencodable —
	 * the operator fixes the one entry either way. Offenders are always reported by the
	 * entry's own path, because that is what the operator has to rename or exclude.
	 *
	 * At most $collect_limit offenders are collected, in the order the headers were given, but
	 * every offending entry is counted in the total, so a message can say "and 12 more" without
	 * holding every offender in memory on a pathological site.
	 *
	 * @param iterable<int, EntryHeader> $entry_headers The entry headers to test, in archive order.
	 * @param int                        $collect_limit The most offenders to collect; the total still counts them all.
	 * @return array{offenders: list<array{entry: string, fault: string}>, total: int} The collected offenders (each entry path escaped for display) and the count of every offending entry.
	 */
	public static function find_unencodable( iterable $entry_headers, int $collect_limit ): array {
		// phpcs:enable Squiz.Commenting.FunctionComment.IncorrectTypeHint
		$offenders = array();
		$total     = 0;

		foreach ( $entry_headers as $header ) {
			$path   = $header->path();
			$target = $header->target();
			$fault  = null;

			if ( null !== $path && ! self::is_encodable( $path ) ) {
				$fault = self::FAULT_NAME;
			} elseif ( null !== $target && ! self::is_encodable( $target ) ) {
				$fault = self::FAULT_TARGET;
			}

			if ( null === $fault ) {
				continue;
			}

			++$total;

			if ( count( $offenders ) < $collect_limit ) {
				$offenders[] = array(
					'entry' => self::printable( (string) $path ),
					'fault' => $fault,
				);
			}
		}

		return array(
			'offenders' => $offenders,
			'total'     => $total,
		);
	}

	/**
	 * Compose the list of offending entries for an error message.
	 *
	 * Each offender is its escaped entry path, followed by a note when it is the symbolic-link
	 * target rather than the name that is at fault; they are joined with commas, and a tail says
	 * how many more were found than are listed. Plain English in an engine class, like the other
	 * engine messages, so it is not translated.
	 *
	 * @param array{offenders: list<array{entry: string, fault: string}>, total: int} $result What {@see self::find_unencodable()} returned.
	 * @return string The offender list, ready to place in a message.
	 */
	public static function describe_offenders( array $result ): string {
		$parts = array();

		foreach ( $result['offenders'] as $offender ) {
			$parts[] = $offender['entry'] . ( self::FAULT_TARGET === $offender['fault'] ? ' (its symbolic-link target)' : '' );
		}

		$text = implode( ', ', $parts );

		$more = $result['total'] - count( $result['offenders'] );
		if ( $more > 0 ) {
			$text .= sprintf( ' (and %d more)', $more );
		}

		return $text;
	}
}
