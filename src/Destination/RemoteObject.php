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
 * basename (for example `pontifex-backup-20260813T030000Z.wpmig`). Retention
 * used to order by that name alone — the export writer stamps it with a
 * sortable UTC timestamp — but a name is untrusted, self-reported data: a
 * killed upload can leave a partial file under the canonical name, and a
 * hand-set clock can mint a future-dated one, and neither is distinguishable
 * from a genuine archive by name alone. {@see $modification_time} carries
 * what the destination itself reports the file's mtime to be, so retention
 * can order by real age instead (mirroring
 * {@see \Pontifex\Admin\BackupStore::compare_by_age()}). Both the size and
 * the modification time are best-effort: a destination that cannot or will
 * not report one degrades to its own "-1, unknown" sentinel rather than
 * failing the whole listing.
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
	 * The archive's modification time as a Unix timestamp, or -1 when the
	 * destination did not report one.
	 *
	 * @var int
	 */
	private int $modification_time;

	/**
	 * Construct a remote-object description.
	 *
	 * @param string $name              The remote basename.
	 * @param int    $size              The size in bytes, or -1 if unknown.
	 * @param int    $modification_time The modification time as a Unix timestamp, or -1 if unknown.
	 */
	public function __construct( string $name, int $size = -1, int $modification_time = -1 ) {
		$this->name              = $name;
		$this->size              = $size;
		$this->modification_time = $modification_time;
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

	/**
	 * The modification time as a Unix timestamp, or -1 when the destination
	 * did not report one.
	 *
	 * @return int
	 */
	public function modification_time(): int {
		return $this->modification_time;
	}
}
