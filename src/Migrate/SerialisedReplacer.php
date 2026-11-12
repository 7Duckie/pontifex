<?php
/**
 * Pontifex serialised-safe search-replace — rewrites a value without corrupting serialised data.
 *
 * @package Pontifex\Migrate
 */

declare(strict_types=1);

namespace Pontifex\Migrate;

use OverflowException;

/**
 * Replaces a substring inside a database value, safely across PHP-serialised data.
 *
 * The classic WordPress-migration corruption bug: a PHP-serialised string
 * records its own byte length (`s:22:"https://old-site.local"`), so a naive
 * `str_replace` over serialised text leaves the recorded length disagreeing
 * with the bytes, and the value can no longer be unserialised. This class
 * avoids that by working on the *structured* value — it unserialises, replaces
 * recursively, and re-serialises, so every length is recomputed correctly.
 *
 * It is also the project's single most dangerous code path. `unserialize` on
 * attacker-controlled bytes is an object-injection (gadget-chain) remote-code-
 * execution surface (threat-model §1), because the data being walked is the
 * source site's database — which an attacker may control a row of. The defences
 * (ADR 0006), each exercised by the adversarial tests:
 *
 *  - **`allowed_classes => false` by default.** Every `unserialize` here passes
 *    the allowlist, so a serialised object cannot instantiate an arbitrary
 *    class; it becomes a harmless `__PHP_Incomplete_Class`. WordPress's
 *    `maybe_unserialize()` is deliberately NOT used, because it does not
 *    guarantee this guard across WordPress versions.
 *  - **Round-trip verification.** After replacing and re-serialising, the result
 *    is unserialised again; if it does not round-trip, the **original value is
 *    returned unchanged** rather than emit something that will not parse.
 *  - **Objects are opaque.** Object internals are never rewritten (under the
 *    default allowlist they are incomplete classes anyway), matching the
 *    "mismatch → keep original" rule. The allowlist — supplied by the
 *    `pontifex_serialized_classes` filter at a higher layer — widens this when
 *    an operator explicitly opts a class in.
 *  - **Bounded, abandon-on-breach recursion.** Both recursive walks
 *    (`replace_value()` and `contains_blocked_object()`) are bounded by
 *    depth and by a total-nodes-visited budget for one top-level value;
 *    breaching either abandons the WHOLE value rather than truncating one
 *    branch and continuing with its siblings, matching the same
 *    "mismatch → keep original" rule. Guards against a maliciously (or
 *    accidentally) cyclic serialised value — e.g. `a:1:{i:0;R:1;}`, a
 *    fourteen-byte array that references its own enclosing array — hanging
 *    the walk indefinitely (ADR 0006 amendment, 2026-08-12).
 *
 * The class is pure: no WordPress runtime, no collaborators, deterministic.
 */
final class SerialisedReplacer {

	/**
	 * Maximum recursion depth walked into one top-level decoded value.
	 *
	 * The bound that stops PHP's own call stack from overflowing: each level
	 * of array/object nesting, and each layer of doubly-(or more)-serialised
	 * string a value is unwrapped through, costs one level of this budget,
	 * carried through `replace_value()`'s whole walk of a single top-level
	 * value — including through any nested {@see replace_in_serialized()}
	 * layer, so the budget is not reset by nesting more serialised strings
	 * (ADR 0006 amendment, 2026-08-12).
	 *
	 * Measured: PHP's own call stack overflows — a segfault, not a catchable
	 * error — at a few tens of thousands of frames, so a bound anywhere near
	 * that would not be a safety margin at all. Ordinary WordPress serialised
	 * data (options, post meta, widget settings) nests one to three levels
	 * deep; 64 is generous by more than an order of magnitude over that,
	 * while sitting nowhere near the segfault floor.
	 *
	 * @var int
	 */
	private const MAX_DEPTH = 64;

	/**
	 * Maximum nodes visited while walking one top-level decoded value.
	 *
	 * The backstop a depth bound alone does not cover: a cyclic reference
	 * that branches (several keys all pointing back to the same enclosing
	 * array) stays within any depth cap while still being infinite to a walk
	 * that truncates a branch and carries on to its siblings — so without a
	 * node budget too, a wide cycle would run forever even though a single
	 * self-reference would not. Every node visited — every scalar, array,
	 * string, or serialised-layer transition — counts against this budget,
	 * carried the same way as {@see MAX_DEPTH} through `replace_value()`'s
	 * whole walk. `contains_blocked_object()` carries its own, independent
	 * copy of both budgets for its own, separate walk of the same decoded
	 * structure, so one method's cost can never starve the other's.
	 *
	 * 100,000 matches
	 * {@see \Pontifex\Archive\Reader\ArchiveLimits::DEFAULT_MAX_ENTRY_COUNT} —
	 * this project's existing precedent for "large enough that no genuine
	 * WordPress data comes close, small enough to abandon a hostile value
	 * before it costs anything that matters."
	 *
	 * @var int
	 */
	private const MAX_NODES = 100000;

	/**
	 * Classes permitted when unserialising untrusted data.
	 *
	 * `false` (the default) permits none — every object decodes to a harmless
	 * `__PHP_Incomplete_Class`. An array of class names opts those classes in.
	 *
	 * @var bool|array<int, string>
	 */
	private bool|array $allowed_classes;

	/**
	 * Construct a replacer with an optional class allowlist.
	 *
	 * @param bool|array<int, string> $allowed_classes Classes to permit when unserialising; false (default) permits none.
	 */
	public function __construct( bool|array $allowed_classes = false ) {
		$this->allowed_classes = $allowed_classes;
	}

	/**
	 * Return $value with $search replaced by $replace, safely across serialised data.
	 *
	 * Plain strings are replaced directly; serialised strings are unserialised,
	 * rewritten recursively, and re-serialised with correct lengths. A value that
	 * cannot be safely round-tripped is returned unchanged.
	 *
	 * @param string $search  The substring to find.
	 * @param string $replace The substring to substitute.
	 * @param string $value   The database value to rewrite.
	 * @return string The rewritten value (or the original, if it could not be safely transformed).
	 */
	public function replace( string $search, string $replace, string $value ): string {
		$result = $this->replace_value( $search, $replace, $value );
		return is_string( $result ) ? $result : $value;
	}

	/**
	 * Recursively replace within a decoded value of any type.
	 *
	 * Strings are replaced (recursing if they are themselves serialised); arrays
	 * are walked into their keys and values; objects are left opaque; other
	 * scalars are returned unchanged.
	 *
	 * Bounded by depth and by a total-nodes-visited budget ({@see MAX_DEPTH},
	 * {@see MAX_NODES}) for the whole top-level value: `$depth` and `$nodes`
	 * are threaded through every recursive call, including through any
	 * nested {@see replace_in_serialized()} layer for doubly-serialised
	 * strings, so the budget cannot be reset by nesting more of them.
	 * Breaching either bound throws, which unwinds every pending recursive
	 * call — and every sibling loop iteration still to come — without
	 * exploring any of them further; {@see replace_in_serialized()} is the
	 * only caller that ever catches it, and it keeps the original value
	 * unchanged when it does.
	 *
	 * @param string $search  The substring to find.
	 * @param string $replace The substring to substitute.
	 * @param mixed  $value   The decoded value to rewrite.
	 * @param int    $depth   Recursion depth reached so far for this top-level value; callers always omit this.
	 * @param int    $nodes   Nodes visited so far for this top-level value, carried by reference; callers always omit this.
	 * @return mixed The rewritten value.
	 * @throws OverflowException If the walk exceeds {@see MAX_DEPTH} or {@see MAX_NODES}.
	 */
	private function replace_value( string $search, string $replace, mixed $value, int $depth = 0, int &$nodes = 0 ): mixed {
		if ( $depth > self::MAX_DEPTH || ++$nodes > self::MAX_NODES ) {
			throw new OverflowException( 'SerialisedReplacer: walk bound exceeded; abandoning the value.' );
		}

		if ( is_string( $value ) ) {
			return $this->is_serialized( $value )
				? $this->replace_in_serialized( $search, $replace, $value, $depth + 1, $nodes )
				: str_replace( $search, $replace, $value );
		}

		if ( is_array( $value ) ) {
			$result = array();
			foreach ( $value as $key => $item ) {
				$new_key            = is_string( $key ) ? str_replace( $search, $replace, $key ) : $key;
				$result[ $new_key ] = $this->replace_value( $search, $replace, $item, $depth + 1, $nodes );
			}
			return $result;
		}

		// Objects are left opaque (untrusted internals are not rewritten under
		// the default allowlist); all other scalars are returned unchanged.
		return $value;
	}

	/**
	 * Replace within a serialised string by decoding, rewriting, and re-encoding.
	 *
	 * Unserialises under the class allowlist, replaces recursively, re-serialises
	 * (so byte lengths are recomputed), then verifies the result round-trips. If
	 * the value will not decode, the decoded structure exceeds the walk's depth
	 * or node bounds (a cyclic reference, or nesting far beyond anything ordinary
	 * WordPress data produces), or the result will not re-decode, the original
	 * is returned unchanged.
	 *
	 * @param string $search  The substring to find.
	 * @param string $replace The substring to substitute.
	 * @param string $value   The serialised value to rewrite.
	 * @param int    $depth   Recursion depth reached so far for this top-level value; callers always omit this.
	 * @param int    $nodes   Nodes visited so far for this top-level value, carried by reference; callers always omit this.
	 * @return string The re-serialised value, or the original on any failure.
	 */
	private function replace_in_serialized( string $search, string $replace, string $value, int $depth = 0, int &$nodes = 0 ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize,WordPress.PHP.NoSilencedErrors.Discouraged -- The one controlled unserialize call, guarded by allowed_classes; @ traps a malformed-data warning handled by the false check below (threat-model §1, ADR 0006).
		$data = @unserialize( $value, array( 'allowed_classes' => $this->allowed_classes ) );
		if ( false === $data && 'b:0;' !== trim( $value ) ) {
			// Declared serialised but did not decode — keep the original untouched.
			return $value;
		}

		try {
			if ( $this->contains_blocked_object( $data ) ) {
				// A class outside the allowlist was present (now an incomplete object);
				// do not rewrite or re-serialise it — keep the original untouched.
				return $value;
			}

			$replaced = $this->replace_value( $search, $replace, $data, $depth, $nodes );
		} catch ( OverflowException ) {
			// The decoded structure exceeded the walk's depth or node bounds —
			// abandon the whole value and keep the original untouched, exactly
			// as an undecodable or non-round-tripping value already is (ADR
			// 0006 amendment, 2026-08-12).
			return $value;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Re-encoding the rewritten structure so serialised byte lengths are recomputed correctly.
		$reserialized = serialize( $replaced );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize,WordPress.PHP.NoSilencedErrors.Discouraged -- Round-trip verification under the same allowlist; never emit serialised data that will not re-decode.
		$check = @unserialize( $reserialized, array( 'allowed_classes' => $this->allowed_classes ) );
		if ( false === $check && 'b:0;' !== $reserialized ) {
			return $value;
		}

		return $reserialized;
	}

	/**
	 * Whether a decoded value contains an object outside the allowlist.
	 *
	 * A class the allowlist blocked is decoded as a `__PHP_Incomplete_Class`. If
	 * one is present anywhere in the structure, the caller keeps the original
	 * serialised value rather than rewrite or re-serialise it. The incomplete-
	 * class check runs before any property access, so an incomplete object is
	 * never touched.
	 *
	 * Bounded the same way as {@see replace_value()} ({@see MAX_DEPTH},
	 * {@see MAX_NODES}), but with its own independent budget for its own
	 * walk of the decoded structure — this method runs FIRST on every
	 * decoded value, so a cyclic reference holding no blocked object at all
	 * would hang here before `replace_value()` ever ran. Breaching a bound
	 * throws rather than returning `false`, so a breach can never be
	 * mistaken for "no blocked object found" and sent onwards to be
	 * rewritten and re-serialised.
	 *
	 * @param mixed $value The decoded value to scan.
	 * @param int   $depth Recursion depth reached so far for this scan; callers always omit this.
	 * @param int   $nodes Nodes visited so far for this scan, carried by reference; callers always omit this.
	 * @return bool True if a blocked (incomplete) object is present anywhere.
	 * @throws OverflowException If the walk exceeds {@see MAX_DEPTH} or {@see MAX_NODES}.
	 */
	private function contains_blocked_object( mixed $value, int $depth = 0, int &$nodes = 0 ): bool {
		if ( $depth > self::MAX_DEPTH || ++$nodes > self::MAX_NODES ) {
			throw new OverflowException( 'SerialisedReplacer: walk bound exceeded while scanning for blocked objects.' );
		}

		if ( $value instanceof \__PHP_Incomplete_Class ) {
			return true;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( $this->contains_blocked_object( $item, $depth + 1, $nodes ) ) {
					return true;
				}
			}
		} elseif ( is_object( $value ) ) {
			foreach ( get_object_vars( $value ) as $item ) {
				if ( $this->contains_blocked_object( $item, $depth + 1, $nodes ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Whether a string is PHP-serialised data (strict).
	 *
	 * A correct positive is what protects against corruption: a false negative
	 * (treating serialised data as a plain string) would let a naive replace run
	 * and break the length prefixes. Implemented in-house rather than calling
	 * WordPress's `is_serialized()` so this P0 surface has no WordPress coupling.
	 * Mirrors the well-known structural check: a leading type token, a `:` at
	 * offset 1, a closing `;` or `}`, and a per-token shape.
	 *
	 * @param string $value The string to test.
	 * @return bool True when $value looks like PHP-serialised data.
	 */
	private function is_serialized( string $value ): bool {
		$value = trim( $value );

		if ( 'N;' === $value ) {
			return true;
		}
		if ( strlen( $value ) < 4 ) {
			return false;
		}
		if ( ':' !== $value[1] ) {
			return false;
		}

		$last = substr( $value, -1 );
		if ( ';' !== $last && '}' !== $last ) {
			return false;
		}

		$token = $value[0];
		switch ( $token ) {
			case 's':
				if ( '"' !== substr( $value, -2, 1 ) ) {
					return false;
				}
				return 1 === preg_match( '/^' . $token . ':[0-9]+:/s', $value );
			case 'a':
			case 'O':
			case 'E':
				return 1 === preg_match( '/^' . $token . ':[0-9]+:/s', $value );
			case 'b':
			case 'i':
			case 'd':
				return 1 === preg_match( '/^' . $token . ':[0-9.E+-]+;$/', $value );
		}

		return false;
	}
}
