<?php
/**
 * The WordPress installation root — one derivation, shared by everything that confines against it.
 *
 * @package Pontifex\WordPress
 */

declare(strict_types=1);

namespace Pontifex\WordPress;

use Pontifex\Environment\Environment;
use Pontifex\Exception\HostCannotComply;

/**
 * Resolves the WordPress installation root from ABSPATH.
 *
 * This value is the destination root a restore confines every write against —
 * arguably the single most load-bearing value in the whole confinement model,
 * because every path check downstream is relative to it. It was copy-pasted
 * byte-identically into six classes: three CLI commands, two admin controllers
 * and the verify controller. Six copies of the foundation, differing only in
 * which class name appeared in the error message, with nothing making them
 * agree.
 *
 * Nothing had gone wrong with them — they were still identical when this was
 * written, confirmed by hashing all six. The objection is not that they had
 * drifted but that nothing would have noticed if one had: a change to how the
 * root is derived, applied to five of six, leaves one surface confining against
 * a different directory than the others, and no test compares them.
 *
 * A small class rather than a method on {@see Environment}: that interface is
 * part of the public API frozen at v1.0.0, and adding a method to an interface
 * breaks every implementer.
 */
final class WordPressRoot {

	/**
	 * The installation root, with any trailing slash removed.
	 *
	 * The trailing slash matters: ABSPATH carries one by WordPress convention,
	 * and callers append `/` plus a relative path. Leaving it would produce a
	 * doubled separator in every confined path, and the prefix comparisons that
	 * confinement relies on are string comparisons.
	 *
	 * @param Environment $environment The runtime to read the constant from.
	 * @return string The absolute installation root, with no trailing slash.
	 * @throws HostCannotComply If ABSPATH is not defined, meaning WordPress is not loaded.
	 */
	public static function resolve( Environment $environment ): string {
		if ( ! $environment->is_constant_defined( 'ABSPATH' ) ) {
			throw new HostCannotComply( 'Pontifex: ABSPATH is not defined; is WordPress loaded?' );
		}

		return rtrim( (string) $environment->constant_value( 'ABSPATH' ), '/' );
	}
}
