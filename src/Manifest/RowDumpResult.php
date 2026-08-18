<?php
/**
 * Pontifex manifest row-dump result — one table window's SQL and its end key.
 *
 * @package Pontifex\Manifest
 */

declare(strict_types=1);

namespace Pontifex\Manifest;

use InvalidArgumentException;

/**
 * Immutable value object returned by {@see DatabaseAdapter::dump_table_rows()}.
 *
 * Carries the window's INSERT SQL alongside the end key: the primary-key
 * values of the last row the window emitted. A caller doing keyset
 * pagination (DatabaseScanner's chunk planning) feeds the end key back in
 * as the next window's $after_key, so consecutive windows chain into a
 * pure index seek — `WHERE (pk) > (last key) ORDER BY (pk) LIMIT n` —
 * rather than an OFFSET the server must scan and discard.
 *
 * The end key is null in exactly two cases: the table has no primary key
 * (dumped by LIMIT/OFFSET, which has no keyset cursor to report), or the
 * window emitted no rows (nothing to key off).
 */
final class RowDumpResult {

	/**
	 * The window's SQL bytes.
	 *
	 * @var string
	 */
	private string $sql;

	/**
	 * The primary-key values of the last row emitted, or null.
	 *
	 * @var array<string, int|string|float|bool>|null
	 */
	private ?array $end_key;

	/**
	 * Construct a RowDumpResult with explicit field values.
	 *
	 * @param string                                    $sql     The window's SQL bytes; empty when the window emitted no rows.
	 * @param array<string, int|string|float|bool>|null $end_key The last emitted row's primary-key values, or null.
	 * @throws InvalidArgumentException If $end_key carries a non-string/empty column name or a non-scalar value.
	 */
	public function __construct( string $sql, ?array $end_key ) {
		if ( null !== $end_key ) {
			foreach ( $end_key as $column => $value ) {
				if ( ! is_string( $column ) || '' === $column ) {
					throw new InvalidArgumentException( 'end_key column names must be non-empty strings.' );
				}
				if ( ! is_scalar( $value ) ) {
					throw new InvalidArgumentException(
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $column is a validated non-empty string key, reported verbatim for diagnostic context; exception path, not HTML output.
						sprintf( 'end_key value for column "%s" must be scalar.', $column )
					);
				}
			}
		}

		$this->sql     = $sql;
		$this->end_key = $end_key;
	}

	/**
	 * Return the window's SQL bytes.
	 *
	 * @return string SQL bytes; empty when the window emitted no rows.
	 */
	public function sql(): string {
		return $this->sql;
	}

	/**
	 * Return the end key: the primary-key values of the last row emitted.
	 *
	 * @return array<string, int|string|float|bool>|null Null when the table has no primary key, or the window emitted no rows.
	 */
	public function end_key(): ?array {
		return $this->end_key;
	}
}
