<?php
/**
 * Tests for the exception taxonomy — ADR 0022.
 *
 * @package Pontifex\Tests\Unit\Exception
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Exception;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pontifex\Archive\Codec\CodecException;
use Pontifex\Archive\Crypto\CipherException;
use Pontifex\Archive\Crypto\SignatureException;
use Pontifex\Destination\DestinationException;
use Pontifex\Exception\ArchiveNotTrustworthy;
use Pontifex\Exception\HostCannotComply;
use Pontifex\Exception\InvalidRequest;
use Pontifex\Exception\PontifexException;
use Pontifex\Export\ManifestTooLargeException;
use RuntimeException;

/**
 * Covers the two properties the taxonomy exists for.
 *
 * First, that a caller can tell the three kinds apart: an untrustworthy
 * archive, a host that cannot comply, and a request that was not valid. Those
 * demand three different responses, and before this every one of them arrived
 * as a bare RuntimeException carrying only a message — which is why every admin
 * surface collapsed them into one sentence.
 *
 * Second, and the reason adoption can be incremental: the change ADDS
 * information and removes none. Every existing `catch ( RuntimeException )` and
 * `catch ( InvalidArgumentException )` in the codebase must keep working
 * untouched, or converting a throw site would silently change which handler
 * runs.
 */
final class TaxonomyTest extends TestCase {

	/**
	 * Each kind is catchable as itself, and not as its siblings.
	 *
	 * @return void
	 */
	public function test_the_three_kinds_are_distinguishable(): void {
		$this->assertInstanceOf( ArchiveNotTrustworthy::class, new ArchiveNotTrustworthy( 'x' ) );

		$this->assertNotInstanceOf( HostCannotComply::class, new ArchiveNotTrustworthy( 'x' ) );
		$this->assertNotInstanceOf( ArchiveNotTrustworthy::class, new HostCannotComply( 'x' ) );
		$this->assertNotInstanceOf( ArchiveNotTrustworthy::class, new InvalidRequest( 'x' ) );
	}

	/**
	 * Every kind is reachable through the marker interface.
	 *
	 * Catching this separates "Pontifex refused" from "something else broke" — a
	 * TypeError, a Random\RandomException, a driver failure — which no handler
	 * could distinguish before, and which warrants different advice.
	 *
	 * @return void
	 */
	public function test_every_kind_is_a_pontifex_exception(): void {
		foreach ( array( new ArchiveNotTrustworthy( 'x' ), new HostCannotComply( 'x' ), new InvalidRequest( 'x' ) ) as $error ) {
			$this->assertInstanceOf( PontifexException::class, $error, get_class( $error ) . ' must carry the marker.' );
		}
	}

	/**
	 * Existing SPL catches keep working — the change adds, it does not remove.
	 *
	 * This is the property that makes staged adoption safe. If converting a
	 * throw site changed which handler ran, every conversion would be a
	 * behaviour change and the 279 sites could not be migrated a few at a time.
	 *
	 * @return void
	 */
	public function test_existing_spl_catches_still_apply(): void {
		$this->assertInstanceOf( RuntimeException::class, new ArchiveNotTrustworthy( 'x' ) );
		$this->assertInstanceOf( RuntimeException::class, new HostCannotComply( 'x' ) );
		$this->assertInstanceOf( InvalidArgumentException::class, new InvalidRequest( 'x' ) );
	}

	/**
	 * The typed exceptions that predate the taxonomy carry the marker too.
	 *
	 * They adopted the interface without changing what they extend, which is
	 * the reason ADR 0022 chose an interface over a base class.
	 *
	 * @return void
	 */
	public function test_the_pre_existing_typed_exceptions_carry_the_marker(): void {
		$reflected = array(
			CodecException::class,
			CipherException::class,
			SignatureException::class,
			DestinationException::class,
			ManifestTooLargeException::class,
		);

		foreach ( $reflected as $class ) {
			$this->assertContains(
				PontifexException::class,
				class_implements( $class ),
				$class . ' predates the taxonomy and must still be reachable through the marker.'
			);
		}
	}
}
