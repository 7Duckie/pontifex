<?php
/**
 * Pontifex remote object — one archive stored at an offsite destination.
 *
 * @package Pontifex\Destination
 */

declare(strict_types=1);

namespace Pontifex\Destination;

/**
 * An immutable description of one archive found at a destination.
 *
 * A listing returns these so a caller can present, pull, or prune remote
 * archives without holding open a connection. The {@see $name} is the remote
 * basename (for example `pontifex-2026-07-13-030000.wpmig`); retention orders
 * by that name — the export writer stamps it with a sortable UTC timestamp —
 * rather than by a remote modification time, which a server clock or a re-upload
 * can make unreliable. The size is best-effort, for display only.
 */
final class RemoteObject {

	/**
	 * The remote basename of the archive.
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * The archive size in bytes, or -1 when the destination did not report it.
	 *
	 * @var int
	 */
	private int $size;

	/**
	 * Construct a remote-object description.
	 *
	 * @param string $name The remote basename.
	 * @param int    $size The size in bytes, or -1 if unknown.
	 */
	public function __construct( string $name, int $size = -1 ) {
		$this->name = $name;
		$this->size = $size;
	}

	/**
	 * The remote basename of the archive.
	 *
	 * @return string
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * The size in bytes, or -1 when the destination did not report one.
	 *
	 * @return int
	 */
	public function size(): int {
		return $this->size;
	}
}
