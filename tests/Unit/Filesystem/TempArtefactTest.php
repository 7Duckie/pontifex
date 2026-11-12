<?php
/**
 * Behavioural tests for TempArtefact.
 *
 * @package Pontifex\Tests\Unit\Filesystem
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Filesystem;

use PHPUnit\Framework\TestCase;
use Pontifex\Filesystem\TempArtefact;

/**
 * Exercises the two halves of {@see TempArtefact} against each other and
 * against the shapes they must never agree on.
 *
 * The load-bearing property this suite proves is that {@see TempArtefact::suffix()}
 * (what every producer WRITES) and {@see TempArtefact::is_orphan_name()} (what
 * every sweep RECOGNISES) never drift apart — see the class's own docblock for
 * why two independent copies of this shape, one per deleter, would be a drift
 * hazard rather than mere duplication. Because uniqid()'s pseudorandom
 * component means a single generated sample could pass even if the two halves
 * subtly disagreed (wrong digit counts, a stray anchor), the anti-drift tests
 * loop over many generated names rather than asserting one.
 *
 * No WordPress coupling and no filesystem access: both methods are pure
 * string operations, so this is a plain unit test with no bootstrap and no
 * fixture directory.
 */
final class TempArtefactTest extends TestCase {

	/**
	 * Every name suffix() builds is recognised by is_orphan_name(), across
	 * many generated samples.
	 *
	 * Two hundred samples, not one: uniqid()'s pseudorandom component means a
	 * single lucky draw could pass even if the pattern subtly disagreed with
	 * the generator's real output shape (e.g. an off-by-one in the hex-run
	 * floor), so this asserts the property holds across the generator's
	 * actual output distribution.
	 *
	 * @return void
	 */
	public function test_every_generated_suffix_is_recognised_as_an_orphan(): void {
		for ( $i = 0; $i < 200; $i++ ) {
			$suffix   = TempArtefact::suffix();
			$basename = 'photo.jpg' . $suffix;

			$this->assertTrue(
				TempArtefact::is_orphan_name( $basename ),
				sprintf( 'suffix() output "%s" (basename "%s") must be recognised by is_orphan_name().', $suffix, $basename )
			);
		}
	}

	/**
	 * A resumable export's `.part` shape is never recognised as an orphan.
	 *
	 * A `.part` file is live state a still-running export is writing to —
	 * see {@see \Pontifex\Export\ResumableExportRunner} — and deleting one
	 * would destroy real, unrecoverable work. Two shapes are checked: the
	 * real one ResumableExportRunner actually builds
	 * (`uniqid( 'pontifex-job-', true )` plus `.part`), and a bare `.part`
	 * with no uniqid() shape in front of it at all, so neither a realistic
	 * nor a degenerate `.part` name is ever mistaken for this class's own
	 * artefact shape.
	 *
	 * @return void
	 */
	public function test_resumable_export_part_shape_never_matches(): void {
		$realistic = 'site.wpmig.' . uniqid( 'pontifex-job-', true ) . '.part';

		$this->assertFalse( TempArtefact::is_orphan_name( $realistic ), sprintf( '"%s" must not be recognised as an orphan.', $realistic ) );
		$this->assertFalse( TempArtefact::is_orphan_name( '.part' ), '".part" alone must not be recognised as an orphan.' );
	}

	/**
	 * The two demonstrated false positives never match.
	 *
	 * "archive.pontifex-2024.01.tmp" and "db.pontifex-1.2.tmp" are the
	 * closest an ordinary, human-typed filename comes to this class's own
	 * uniqid() shape: "2024" and "1" are legal hex runs, and both are
	 * followed by a dot and a decimal run — but neither reaches the
	 * eight-character floor {@see TempArtefact}'s pattern requires, which is
	 * exactly the false positive that floor exists to rule out.
	 *
	 * @return void
	 */
	public function test_the_two_demonstrated_false_positives_never_match(): void {
		$this->assertFalse( TempArtefact::is_orphan_name( 'archive.pontifex-2024.01.tmp' ) );
		$this->assertFalse( TempArtefact::is_orphan_name( 'db.pontifex-1.2.tmp' ) );
	}

	/**
	 * Uppercase hex, non-hex characters, and a missing decimal run never match.
	 *
	 * @return void
	 */
	public function test_malformed_shapes_never_match(): void {
		// Uppercase hex: the pattern is case-sensitive and only accepts
		// lower-case a-f, matching the case uniqid() actually produces.
		$this->assertFalse(
			TempArtefact::is_orphan_name( 'photo.jpg.pontifex-6A743B0B47CFF2.47524803.tmp' ),
			'Uppercase hex must not be recognised.'
		);

		// Non-hex characters break the run before it reaches the eight-digit
		// floor: "abcdef" (6 valid hex characters) then "g", which is not a
		// hex digit at all, stops the run two characters short.
		$this->assertFalse(
			TempArtefact::is_orphan_name( 'photo.jpg.pontifex-abcdefgh.12345678.tmp' ),
			'A non-hex character breaking the hex run early must not be recognised.'
		);

		// A missing decimal run: a full fourteen-character hex run followed
		// directly by ".tmp", with no ".<digits>" segment in between at all.
		$this->assertFalse(
			TempArtefact::is_orphan_name( 'photo.jpg.pontifex-6a743b0b47cff2.tmp' ),
			'A hex run with no following decimal run must not be recognised.'
		);
	}

	/**
	 * Each call to suffix() returns a value unique from every other call.
	 *
	 * @return void
	 */
	public function test_suffix_output_is_unique_across_repeated_calls(): void {
		$suffixes = array();
		for ( $i = 0; $i < 200; $i++ ) {
			$suffixes[] = TempArtefact::suffix();
		}

		$this->assertCount( 200, array_unique( $suffixes ), 'Every generated suffix must be unique.' );
	}
}
