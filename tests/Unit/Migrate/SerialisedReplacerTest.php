<?php
/**
 * Tests for SerialisedReplacer — serialised-safe search-replace.
 *
 * @package Pontifex\Tests\Unit\Migrate
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Migrate;

use Pontifex\Migrate\SerialisedReplacer;
use Pontifex\Tests\TestCase;
use stdClass;

// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize,WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- This suite exercises a serialisation component; building and verifying serialised fixtures is its whole purpose.

/**
 * Adversarial coverage of the project's most dangerous code path.
 *
 * Confirms the correctness property (serialised lengths recomputed, never
 * corrupted) and the threat-model §1 / ADR 0006 defences: a gadget object
 * never instantiates under `allowed_classes => false`, a value holding a
 * blocked class is kept unchanged, a value that will not round-trip is kept
 * unchanged, and the allowlist is honoured when supplied.
 */
final class SerialisedReplacerTest extends TestCase {


	/**
	 * A plain (non-serialised) string is replaced directly.
	 *
	 * @return void
	 */
	public function test_replaces_in_a_plain_string(): void {
		$replacer = new SerialisedReplacer();
		$this->assertSame(
			'visit https://new.example today',
			$replacer->replace( 'old.test', 'new.example', 'visit https://old.test today' )
		);
	}

	/**
	 * A serialised string's recorded byte length is recomputed, not left stale.
	 *
	 * This is the headline corruption bug: a different-length replacement must
	 * produce a serialisation whose `s:NN:` length matches the new bytes.
	 *
	 * @return void
	 */
	public function test_recomputes_the_serialised_string_length(): void {
		$replacer = new SerialisedReplacer();
		$original = serialize( 'https://old-site.local' );

		$result = $replacer->replace( 'old-site.local', 'a-considerably-longer-domain.example', $original );

		$this->assertSame( serialize( 'https://a-considerably-longer-domain.example' ), $result );
		$this->assertSame( 'https://a-considerably-longer-domain.example', unserialize( $result, array( 'allowed_classes' => false ) ) );
	}

	/**
	 * Values inside a serialised array are replaced and the array re-serialises cleanly.
	 *
	 * @return void
	 */
	public function test_replaces_inside_a_serialised_array(): void {
		$replacer = new SerialisedReplacer();
		$original = serialize(
			array(
				'siteurl' => 'https://old.test',
				'home'    => 'https://old.test/blog',
				'count'   => 7,
			)
		);

		$result  = $replacer->replace( 'old.test', 'new.example', $original );
		$decoded = unserialize( $result, array( 'allowed_classes' => false ) );

		$this->assertSame(
			array(
				'siteurl' => 'https://new.example',
				'home'    => 'https://new.example/blog',
				'count'   => 7,
			),
			$decoded
		);
	}

	/**
	 * Doubly-serialised data (a serialised string inside a serialised array) is replaced at both levels.
	 *
	 * @return void
	 */
	public function test_replaces_doubly_serialised_data(): void {
		$replacer = new SerialisedReplacer();
		$inner    = serialize( array( 'url' => 'https://old.test' ) );
		$original = serialize( array( 'payload' => $inner ) );

		$result  = $replacer->replace( 'old.test', 'new.example', $original );
		$decoded = unserialize( $result, array( 'allowed_classes' => false ) );
		$inner2  = unserialize( $decoded['payload'], array( 'allowed_classes' => false ) );

		$this->assertSame( array( 'url' => 'https://new.example' ), $inner2 );
	}

	/**
	 * String array keys are replaced as well as values.
	 *
	 * @return void
	 */
	public function test_replaces_string_array_keys(): void {
		$replacer = new SerialisedReplacer();
		$original = serialize( array( 'old.test' => 'value' ) );

		$decoded = unserialize( $replacer->replace( 'old.test', 'new.example', $original ), array( 'allowed_classes' => false ) );

		$this->assertSame( array( 'new.example' => 'value' ), $decoded );
	}

	/**
	 * A value without the search term is returned unchanged.
	 *
	 * @return void
	 */
	public function test_leaves_a_value_without_the_search_term_unchanged(): void {
		$replacer = new SerialisedReplacer();
		$original = serialize( array( 'note' => 'nothing to see' ) );

		$this->assertSame( $original, $replacer->replace( 'old.test', 'new.example', $original ) );
	}

	/**
	 * A serialised object never instantiates its class under allowed_classes => false.
	 *
	 * The gadget defence: unserialising attacker-controlled bytes must not wake
	 * an arbitrary class. The probe's __wakeup would flip a flag if it ran.
	 *
	 * @return void
	 */
	public function test_a_serialised_object_does_not_instantiate_the_class(): void {
		$probe      = new GadgetProbe();
		$probe->url = 'https://old.test';
		$hostile    = serialize( $probe );

		GadgetProbe::$awoken = false;
		$replacer            = new SerialisedReplacer();

		$result = $replacer->replace( 'old.test', 'new.example', $hostile );

		$this->assertFalse(
			GadgetProbe::$awoken,
			'allowed_classes => false must stop the gadget class from waking on unserialise.'
		);
		// A value holding a blocked object is kept unchanged (not rewritten).
		$this->assertSame( $hostile, $result );
	}

	/**
	 * A serialised value that holds a blocked object anywhere is kept unchanged.
	 *
	 * Even sibling values are not rewritten, because re-serialising a structure
	 * containing an incomplete class is avoided entirely.
	 *
	 * @return void
	 */
	public function test_a_value_holding_a_blocked_object_is_kept_unchanged(): void {
		$probe    = new GadgetProbe();
		$original = serialize(
			array(
				'siteurl' => 'https://old.test',
				'gadget'  => $probe,
			)
		);

		GadgetProbe::$awoken = false;
		$replacer            = new SerialisedReplacer();

		$this->assertSame( $original, $replacer->replace( 'old.test', 'new.example', $original ) );
		$this->assertFalse( GadgetProbe::$awoken );
	}

	/**
	 * A corrupt serialised value (declared length wrong) is kept unchanged.
	 *
	 * @return void
	 */
	public function test_a_corrupt_serialised_value_is_kept_unchanged(): void {
		$replacer = new SerialisedReplacer();
		// Declares 100 bytes but carries far fewer — unserialise fails.
		$corrupt = 's:100:"https://old.test";';

		$this->assertSame( $corrupt, $replacer->replace( 'old.test', 'new.example', $corrupt ) );
	}

	/**
	 * Serialised null and boolean false are handled without error.
	 *
	 * @return void
	 */
	public function test_handles_serialised_null_and_false(): void {
		$replacer = new SerialisedReplacer();

		$this->assertSame( serialize( null ), $replacer->replace( 'old.test', 'new.example', serialize( null ) ) );
		$this->assertSame( serialize( false ), $replacer->replace( 'old.test', 'new.example', serialize( false ) ) );
	}

	/**
	 * An allowlisted class is permitted, so it decodes as itself, not an incomplete class.
	 *
	 * Proves the allowed_classes constructor argument flows to unserialise.
	 *
	 * @return void
	 */
	public function test_an_allowlisted_class_is_permitted(): void {
		$object       = new stdClass();
		$object->host = 'old.test';
		$original     = serialize( $object );

		$replacer = new SerialisedReplacer( array( 'stdClass' ) );
		$result   = $replacer->replace( 'old.test', 'new.example', $original );

		$decoded = unserialize( $result, array( 'allowed_classes' => array( 'stdClass' ) ) );
		$this->assertInstanceOf( stdClass::class, $decoded );
	}

	/**
	 * A value nested legitimately deeper than a handful of levels is still rewritten normally.
	 *
	 * Proves the depth bound does not refuse ordinary data: 20 levels of
	 * array nesting is far beyond anything real WordPress data produces,
	 * yet comfortably inside the budget.
	 *
	 * @return void
	 */
	public function test_a_legitimately_deep_value_is_still_rewritten_normally(): void {
		$replacer = new SerialisedReplacer();
		$original = serialize( $this->nested_array( 20, 'https://old.test' ) );

		$result = $replacer->replace( 'old.test', 'new.example', $original );

		$this->assertNotSame( $original, $result, 'A legitimately deep value must still be rewritten.' );
		$this->assertSame( $this->nested_array( 20, 'https://new.example' ), unserialize( $result, array( 'allowed_classes' => false ) ) );
	}

	/**
	 * A value beyond the depth bound is kept unchanged.
	 *
	 * Not cyclic — just deeper than the walk permits — so this is safe to
	 * run directly: a finite structure terminates whatever the bound logic
	 * does, so no deadline guard is needed here (unlike the cyclic cases
	 * below).
	 *
	 * @return void
	 */
	public function test_a_value_beyond_the_depth_bound_is_kept_unchanged(): void {
		$replacer = new SerialisedReplacer();
		$original = serialize( $this->nested_array( 80, 'https://old.test' ) );

		$this->assertSame( $original, $replacer->replace( 'old.test', 'new.example', $original ) );
	}

	/**
	 * Build a value nested $levels deep, e.g. array( 'a' => array( 'a' => $leaf ) ) for $levels = 2.
	 *
	 * @param int   $levels How many levels of array nesting to build.
	 * @param mixed $leaf   The innermost value.
	 * @return mixed The nested structure.
	 */
	private function nested_array( int $levels, mixed $leaf ): mixed {
		$value = $leaf;
		for ( $i = 0; $i < $levels; $i++ ) {
			$value = array( 'a' => $value );
		}
		return $value;
	}

	/**
	 * A self-referencing value is kept unchanged.
	 *
	 * The measured case: `a:1:{i:0;R:1;}` — fourteen bytes, an array whose
	 * only element is a reference to its own enclosing array. Also proves
	 * `contains_blocked_object()`'s own bound, not just `replace_value()`'s:
	 * it runs FIRST on this decoded value, and this value holds no blocked
	 * object at all, so a wrongly-rewritten result here would mean its bound
	 * (or its breach signal) is broken, not `replace_value()`'s. A
	 * regression to unbounded recursion would hang this call; every CI job
	 * carries its own `timeout-minutes`, so such a regression fails the job
	 * loudly rather than needing this test to detect it itself.
	 *
	 * @return void
	 */
	public function test_a_self_referencing_value_is_kept_unchanged_rather_than_hanging(): void {
		$replacer = new SerialisedReplacer();
		$cyclic   = 'a:1:{i:0;R:1;}';

		$this->assertSame( $cyclic, $replacer->replace( 'old.test', 'new.example', $cyclic ) );
	}

	/**
	 * A wide self-referencing value is kept unchanged.
	 *
	 * `a:3:{i:0;R:1;i:1;R:1;i:2;R:1;}` — three siblings, all referencing the
	 * same enclosing array. This is the regression guard for the
	 * abandon-versus-truncate distinction: a depth cap that truncates one
	 * branch and carries on to explore its siblings still passes the single
	 * self-reference test above (there is only one branch to truncate) but
	 * hangs on this shape, because each of the three siblings independently
	 * re-explores the same cycle up to the cap.
	 *
	 * @return void
	 */
	public function test_a_wide_self_referencing_value_is_kept_unchanged_rather_than_hanging(): void {
		$replacer = new SerialisedReplacer();
		$cyclic   = 'a:3:{i:0;R:1;i:1;R:1;i:2;R:1;}';

		$this->assertSame( $cyclic, $replacer->replace( 'old.test', 'new.example', $cyclic ) );
	}
}
