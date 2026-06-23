<?php
/**
 * Integration test: the pontifex_serialized_classes filter and its safety coercion.
 *
 * @package Pontifex\Tests\Integration
 */

declare(strict_types=1);

namespace Pontifex\Tests\Integration;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use Pontifex\WordPress\RealWordPressContext;

/**
 * Proves the migration class allowlist is driven by the real WordPress filter.
 *
 * {@see \Pontifex\WordPress\RealWordPressContext::serialised_classes_allowlist()}
 * resolves the `pontifex_serialized_classes` filter into the list of classes
 * the rewrite pass may decode. The security-critical property — proven here
 * against real WordPress hooks — is that a misbehaving filter cannot widen the
 * allowlist: a `true` return ("allow everything") and non-string entries are
 * coerced away, so the gadget-chain surface stays closed.
 */
final class SerialisedClassesFilterTest extends TestCase {

	/**
	 * Remove the filter between tests so each starts from a clean hook.
	 *
	 * @return void
	 */
	protected function tear_down(): void {
		remove_all_filters( 'pontifex_serialized_classes' );
		parent::tear_down();
	}

	/**
	 * With no filter registered, the allowlist is empty (no classes permitted).
	 *
	 * @return void
	 */
	public function test_the_default_allowlist_is_empty(): void {
		$this->assertSame( array(), ( new RealWordPressContext() )->serialised_classes_allowlist() );
	}

	/**
	 * A filter can opt specific class names into the allowlist.
	 *
	 * @return void
	 */
	public function test_a_filter_can_opt_classes_into_the_allowlist(): void {
		add_filter(
			'pontifex_serialized_classes',
			static function (): array {
				return array( 'WC_Order', 'My_Plugin_Settings' );
			}
		);

		$this->assertSame(
			array( 'WC_Order', 'My_Plugin_Settings' ),
			( new RealWordPressContext() )->serialised_classes_allowlist()
		);
	}

	/**
	 * A filter returning true is coerced to empty — it must never widen to all classes.
	 *
	 * @return void
	 */
	public function test_a_true_filter_is_coerced_to_empty(): void {
		add_filter( 'pontifex_serialized_classes', '__return_true' );

		$this->assertSame(
			array(),
			( new RealWordPressContext() )->serialised_classes_allowlist(),
			'A filter returning true must never widen the allowlist to every class.'
		);
	}

	/**
	 * Non-string and empty entries are dropped from the allowlist.
	 *
	 * @return void
	 */
	public function test_non_string_entries_are_dropped(): void {
		add_filter(
			'pontifex_serialized_classes',
			static function (): array {
				return array( 'Valid_Class', '', 123, null, 'Another_Class' );
			}
		);

		$this->assertSame(
			array( 'Valid_Class', 'Another_Class' ),
			( new RealWordPressContext() )->serialised_classes_allowlist()
		);
	}
}
