<?php
/**
 * Pontifex destination contract — moves a finished archive to and from user-owned storage.
 *
 * @package Pontifex\Destination
 */

declare(strict_types=1);

namespace Pontifex\Destination;

/**
 * Contract for an offsite destination: storage the user owns and configures.
 *
 * An adapter uploads a finished `.wpmig`, lists what it holds, fetches one back,
 * and deletes one — the four operations export upload, `pull`, retention, and a
 * live health check are built from. Every implementation writes to the user's
 * own storage (their SFTP server, their S3 bucket); Pontifex runs no service and
 * makes no call home (ADR 0017).
 *
 * The seam keeps transport types out of the archive and CLI layers, in the style
 * of {@see \Pontifex\Rollback\SafetyArchiverInterface}, so a fake stands in for
 * the network in tests. Every method raises {@see DestinationException} on
 * failure rather than returning an error, and never places a secret in the
 * message.
 */
interface DestinationAdapter {

	/**
	 * Upload a local archive to the destination.
	 *
	 * The remote name is the basename of $local_path, so a later {@see list()}
	 * and {@see get()} address it by the same name the local file had.
	 *
	 * @param string $local_path Absolute path of the finished archive to upload.
	 * @return void
	 * @throws DestinationException If the connection, authentication, or upload fails.
	 */
	public function put( string $local_path ): void;

	/**
	 * List the archives the destination currently holds.
	 *
	 * Returns only Pontifex archives (files ending `.wpmig`), so retention and
	 * `pull` never touch unrelated objects sharing the destination.
	 *
	 * @return array<int, RemoteObject> The archives found, in no guaranteed order.
	 * @throws DestinationException If the connection, authentication, or listing fails.
	 */
	public function list(): array;

	/**
	 * Download one archive from the destination to a local path.
	 *
	 * @param string $remote_name The remote basename, as returned by {@see list()}.
	 * @param string $local_path  Absolute path to write the downloaded archive to.
	 * @return void
	 * @throws DestinationException If the archive is absent, or the download fails.
	 */
	public function get( string $remote_name, string $local_path ): void;

	/**
	 * Delete one archive from the destination.
	 *
	 * @param string $remote_name The remote basename, as returned by {@see list()}.
	 * @return void
	 * @throws DestinationException If the delete fails.
	 */
	public function delete( string $remote_name ): void;

	/**
	 * Verify the destination is reachable, authenticated, and writable.
	 *
	 * A live, on-demand check for `wp pontifex destination test`: it connects,
	 * authenticates, and confirms the target directory or bucket can be written,
	 * without leaving a stray object behind. Doctor never calls this — its checks
	 * stay network-free (ADR 0017).
	 *
	 * @return void
	 * @throws DestinationException If the destination cannot be reached, authenticated, or written.
	 */
	public function test(): void;
}
