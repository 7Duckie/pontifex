<?php
/**
 * Gadget-probe fixture for the SerialisedReplacer tests.
 *
 * @package Pontifex\Tests\Unit\Migrate
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Migrate;

/**
 * A probe class whose __wakeup records that it ran — a stand-in for a gadget.
 *
 * Used by {@see SerialisedReplacerTest} to prove that unserialising under
 * `allowed_classes => false` never instantiates — and so never wakes — an
 * arbitrary class.
 */
final class GadgetProbe {

	/**
	 * Set true if __wakeup is ever invoked (it must not be).
	 *
	 * @var bool
	 */
	public static bool $awoken = false;

	/**
	 * A URL property, so the probe carries replaceable content.
	 *
	 * @var string
	 */
	public string $url = '';

	/**
	 * Record that the object was woken on unserialise.
	 *
	 * @return void
	 */
	public function __wakeup(): void {
		self::$awoken = true;
	}
}
