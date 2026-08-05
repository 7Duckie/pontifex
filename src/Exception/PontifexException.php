<?php
/**
 * Marker interface for every refusal Pontifex makes deliberately.
 *
 * @package Pontifex\Exception
 */

declare(strict_types=1);

namespace Pontifex\Exception;

use Throwable;

/**
 * Implemented by every exception Pontifex raises on purpose.
 *
 * Catching this separates "Pontifex refused" from "something else broke" — a
 * TypeError, a Random\RandomException, a driver failure. No handler could make
 * that distinction before, and the two warrant different advice: one is a
 * decision the tool made and can explain, the other is a fault.
 *
 * An interface rather than a base class because the three kinds below already
 * have different natural SPL parents, and because the typed exceptions that
 * predate this taxonomy — CodecException, CipherException, SignatureException,
 * DestinationException, ManifestTooLargeException — can adopt the marker
 * without changing what they extend. See
 * {@see ../../docs/adr/0022-exception-taxonomy.md}.
 */
interface PontifexException extends Throwable {
}
