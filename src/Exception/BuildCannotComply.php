<?php
/**
 * This build of Pontifex will not process the archive.
 *
 * @package Pontifex\Exception
 */

declare(strict_types=1);

namespace Pontifex\Exception;

use RuntimeException;

/**
 * Raised when a defensive limit compiled into this build — not this host, and
 * not the archive — is what refuses to proceed.
 *
 * The archive is sound and the host is fine; a value baked into the plugin at
 * build time, identical on every machine that runs it, is what stops this. The
 * clearest example is the per-entry decoded-byte ceiling: an entry larger than
 * the ceiling is refused the same way on every host, however much memory or
 * disk it has, because the number it is measured against never changes —
 * unlike {@see HostCannotComply}, where a bigger or better-configured host
 * genuinely would let the same archive through.
 *
 * Named to sit beside {@see HostCannotComply} rather than blend into it,
 * because the two call for opposite remedies. A host problem has a fix on
 * this server: free some disk, raise `memory_limit`, ask the host to
 * re-enable a function. A build problem has no such fix — no server setting
 * moves a number compiled into the plugin, and a fresh copy of the same
 * archive is refused identically. It is also not {@see ArchiveNotTrustworthy}:
 * the archive is not malformed, tampered with, or lying about what it holds —
 * it is exactly what it says it is, only larger than this build will process.
 * Telling an operator to distrust or replace a backup for that reason would
 * be the exact false accusation ADR 0022 exists to stop Pontifex making.
 *
 * Extends RuntimeException so every existing `catch ( RuntimeException )`
 * continues to work: this taxonomy adds information and removes none.
 */
final class BuildCannotComply extends RuntimeException implements PontifexException {
}
