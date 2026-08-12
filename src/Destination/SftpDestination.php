<?php
/**
 * Pontifex SFTP destination — uploads a finished archive to the user's own SFTP server.
 *
 * @package Pontifex\Destination
 */

declare(strict_types=1);

namespace Pontifex\Destination;

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use Throwable;

/**
 * A {@see DestinationAdapter} backed by a user-owned SFTP server, via phpseclib.
 *
 * The phpseclib library is pure PHP and needs no `ssh2` extension, so this works
 * on the shared hosts where local-only backups are most dangerous. The connection
 * is lazy — constructing the adapter touches no network, so its wiring is testable;
 * the real transfer paths are proven by an integration drill against a real SFTP
 * server (ADR 0017).
 *
 * The host key is pinned: before any credential is sent, the server's key
 * fingerprint is compared to the configured one, and a mismatch (or an unpinned
 * key) is refused unless the insecure opt-in is set. This closes the
 * trust-on-first-use window a man-in-the-middle relies on. Secrets are passed in
 * already resolved from their environment variables by the {@see DestinationFactory}
 * — this class never reads the environment or the filesystem for them.
 */
final class SftpDestination implements DestinationAdapter {

	/**
	 * Suffix appended to the final archive name while an upload is in progress.
	 *
	 * {@see self::put()} writes to this temporary remote name first and renames
	 * it into place only once the transfer is complete and verified — the same
	 * temp-then-rename shape {@see \Pontifex\Restore\FileWriter} has used
	 * locally since PR #113. The suffix is deliberately chosen so a fragment
	 * left under it by a killed or failed upload is invisible to every
	 * listing: it neither ends `.wpmig` (so {@see self::is_archive_name()}
	 * skips it) nor matches the canonical shape (so
	 * {@see \Pontifex\Archive\ArchiveName::PATTERN} skips it too), and
	 * {@see \Pontifex\Destination\DestinationRetention} already refuses to
	 * touch anything that does not match that pattern. No new rule is needed
	 * to keep a fragment out of retention — only this name shape.
	 *
	 * @var string
	 */
	private const TEMP_UPLOAD_SUFFIX = '.part';

	/**
	 * The server hostname.
	 *
	 * @var string
	 */
	private string $host;

	/**
	 * The server port.
	 *
	 * @var int
	 */
	private int $port;

	/**
	 * The login username.
	 *
	 * @var string
	 */
	private string $username;

	/**
	 * The remote directory archives live in, without a trailing slash.
	 *
	 * @var string
	 */
	private string $remote_path;

	/**
	 * The private key in PEM form for key auth, or '' for password auth.
	 *
	 * @var string
	 */
	private string $private_key;

	/**
	 * The password (password auth) or the key passphrase (key auth), or ''.
	 *
	 * @var string
	 */
	private string $secret;

	/**
	 * The pinned host-key fingerprint (`SHA256:…`), or '' when insecure.
	 *
	 * @var string
	 */
	private string $host_key_fingerprint;

	/**
	 * Whether an unpinned or mismatched host key is tolerated.
	 *
	 * @var bool
	 */
	private bool $insecure_host_key;

	/**
	 * The live connection once established, cached for reuse.
	 *
	 * @var SFTP|null
	 */
	private ?SFTP $connection = null;

	/**
	 * Construct an SFTP destination from resolved connection parameters.
	 *
	 * @param string $host                 The server hostname.
	 * @param int    $port                 The server port.
	 * @param string $username             The login username.
	 * @param string $remote_path          The remote directory archives live in.
	 * @param string $private_key          The private key in PEM form for key auth, or '' for password auth.
	 * @param string $secret               The password (password auth) or key passphrase (key auth), or ''.
	 * @param string $host_key_fingerprint The pinned host-key fingerprint (`SHA256:…`), or '' when insecure.
	 * @param bool   $insecure_host_key    Whether to tolerate an unpinned or mismatched host key.
	 */
	public function __construct(
		string $host,
		int $port,
		string $username,
		string $remote_path,
		string $private_key,
		string $secret,
		string $host_key_fingerprint,
		bool $insecure_host_key
	) {
		$this->host                 = $host;
		$this->port                 = $port;
		$this->username             = $username;
		$this->remote_path          = rtrim( $remote_path, '/' );
		$this->private_key          = $private_key;
		$this->secret               = $secret;
		$this->host_key_fingerprint = $host_key_fingerprint;
		$this->insecure_host_key    = $insecure_host_key;
	}

	/**
	 * Upload a local archive, streaming from disk so a large file is not buffered.
	 *
	 * Writes to a temporary remote name first ({@see self::TEMP_UPLOAD_SUFFIX})
	 * and renames it into place only once the transfer has completed and its
	 * size has been verified against the local file. Without this, a killed
	 * upload — or one that fails partway without phpseclib reporting it —
	 * leaves a partial file under the FINAL name: measured on a real server at
	 * 274,025,430 bytes of a 419,645,479-byte archive. That fragment is then
	 * listed as a backup, the newest one, and retention evicts a sound backup
	 * to make room for it; sorting by time cannot catch this, because a fresh
	 * fragment genuinely has a fresh time. Two failure modes are covered
	 * separately: {@see self::assert_upload_size_matches()} catches a put()
	 * that returns success on an incomplete transfer, and this method's own
	 * rename catches a hard kill that never returns at all — in either case
	 * the archive never appears under its real name until it is known good.
	 *
	 * SFTP's rename refuses to overwrite an existing target — unlike the
	 * plain put() this replaced, which simply wrote through whatever was
	 * already at the final name. So immediately before the rename, once (and
	 * only once) the new upload is verified, any existing file at the FINAL
	 * name is cleared out of the way; see {@see self::discard_remote_file()}.
	 * This is measured against uploading straight to the final name, not
	 * against some hypothetical safer alternative: the old behaviour already
	 * overwrote a real backup — it just did so gradually, over the whole
	 * length of the transfer, so a connection dropped partway left the
	 * destination holding neither the old archive nor the new one. Clearing
	 * an already size-verified replacement immediately before an atomic
	 * rename narrows that same exposure from the length of a transfer to a
	 * few milliseconds; it does not introduce it. Re-running an export with
	 * the same `--output`, retrying after an attempt that DID land the final
	 * name, or two sites sharing one destination and generating the same
	 * name are the ordinary ways to hit an existing target here.
	 *
	 * @param string $local_path Absolute path of the finished archive to upload.
	 * @return void
	 * @throws DestinationException If the connection, authentication, upload, size
	 *                              verification, or the final rename fails.
	 */
	public function put( string $local_path ): void {
		$sftp        = $this->connect();
		$final_name  = basename( $local_path );
		$remote      = $this->remote_path . '/' . $final_name;
		$temp_remote = $remote . self::TEMP_UPLOAD_SUFFIX;
		$temp_name   = basename( $temp_remote );

		// Best-effort: remove a temp file a previous failed attempt left
		// behind, so it cannot block this retry. put()'s own CREATE|TRUNCATE
		// flags would overwrite it regardless; this only tidies up first.
		$this->discard_remote_file( $sftp, $temp_remote );

		try {
			$ok = $sftp->put( $temp_remote, $local_path, SFTP::SOURCE_LOCAL_FILE );
		} catch ( Throwable $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Underlying error reported for diagnostic context; exception path, not HTML output.
			throw new DestinationException( sprintf( 'The upload to the SFTP destination failed: %s', $e->getMessage() ) );
		}

		if ( true !== $ok ) {
			$this->discard_remote_file( $sftp, $temp_remote );
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Archive basename reported for diagnostic context; exception path, not HTML output.
			throw new DestinationException( sprintf( 'The SFTP destination refused the upload of "%s".', $final_name ) );
		}

		$this->assert_upload_size_matches( $sftp, $temp_remote, $local_path, $final_name );

		// Only NOW, with the replacement already verified: clear whatever
		// currently occupies the final name, so the rename below — which
		// SFTP refuses to perform onto an existing target — can succeed.
		// Never done any earlier than this: doing it before the upload was
		// even attempted, or before it was size-verified, would destroy a
		// working backup for an upload that might still fail.
		$this->discard_remote_file( $sftp, $remote );

		try {
			$renamed = $sftp->rename( $temp_remote, $remote );
		} catch ( Throwable $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Archive and temporary names, and the underlying error, reported for diagnostic context; exception path, not HTML output.
			throw new DestinationException( sprintf( 'The upload of "%1$s" finished and was verified, but moving it into place at the SFTP destination failed: %2$s. The verified upload has not been lost — it is stored there under the temporary name "%3$s". Retrying the upload will not help: check why the destination refuses to rename a file into "%1$s" there, which is usually a write-permission restriction on the destination directory.', $final_name, $e->getMessage(), $temp_name ) );
		}

		if ( true !== $renamed ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Archive and temporary names reported for diagnostic context; exception path, not HTML output.
			throw new DestinationException( sprintf( 'The upload of "%1$s" finished and was verified, but the SFTP destination refused to move it into place. The verified upload has not been lost — it is stored there under the temporary name "%2$s". Retrying the upload will not help: check why the destination refuses to rename a file into "%1$s" there, which is usually a write-permission restriction on the destination directory.', $final_name, $temp_name ) );
		}
	}

	/**
	 * Refuse to treat an upload as complete unless the destination's own copy
	 * is exactly the size of the local file that was sent.
	 *
	 * Catches the failure mode {@see self::put()}'s rename cannot: a put()
	 * call that returns success while the transfer was, in fact, incomplete
	 * (a dropped connection partway through that phpseclib does not itself
	 * surface as a failed return). On a mismatch — or when either size cannot
	 * even be read — the temporary file is discarded and the upload is
	 * refused, rather than left for a later rename to promote into a real
	 * backup's name.
	 *
	 * @param SFTP   $sftp        The live, authenticated connection.
	 * @param string $temp_remote The temporary remote path the upload was just written to.
	 * @param string $local_path  The local archive that was uploaded.
	 * @param string $final_name  The archive's basename, for the error message.
	 * @return void
	 * @throws DestinationException If either size cannot be read, or they do not match.
	 */
	private function assert_upload_size_matches( SFTP $sftp, string $temp_remote, string $local_path, string $final_name ): void {
		$local_size = filesize( $local_path );
		if ( false === $local_size ) {
			$this->discard_remote_file( $sftp, $temp_remote );
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Archive basename reported for diagnostic context; exception path, not HTML output.
			throw new DestinationException( sprintf( 'The upload of "%s" to the SFTP destination could not be verified, because the local archive\'s size could not be read. The partial upload has been removed; retry it.', $final_name ) );
		}

		$remote_size = $sftp->filesize( $temp_remote );
		if ( ! is_int( $remote_size ) || $remote_size !== $local_size ) {
			$this->discard_remote_file( $sftp, $temp_remote );
			$reported = is_int( $remote_size ) ? sprintf( '%d bytes', $remote_size ) : 'an unreadable size';
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Archive basename and byte counts reported for diagnostic context; exception path, not HTML output.
			throw new DestinationException( sprintf( 'The upload of "%s" to the SFTP destination could not be verified: the destination holds %s, but the local archive is %d bytes. The partial upload has been removed; retry it.', $final_name, $reported, $local_size ) );
		}
	}

	/**
	 * Best-effort removal of one remote file.
	 *
	 * Three distinct call sites in {@see self::put()} share this, and all
	 * three swallow failure the same way for the same reason — the caller
	 * either has nothing at that path to lose, or is about to replace it with
	 * something already verified:
	 *
	 *  - Before a new upload starts, clearing a temporary file a previous
	 *    failed attempt left behind. The ordinary case is that there is
	 *    nothing there at all.
	 *  - After an upload is rejected (the transfer itself failed, or its size
	 *    could not be verified), so a fragment does not linger under the
	 *    temporary name — harmless either way, since
	 *    {@see self::TEMP_UPLOAD_SUFFIX}'s docblock explains why nothing ever
	 *    lists or prunes it as a backup.
	 *  - Immediately before the final rename, clearing whatever currently
	 *    occupies the FINAL name — because SFTP's rename refuses to overwrite
	 *    an existing target, unlike the plain put() this class used to make.
	 *    Only reached once the replacement has already been uploaded and
	 *    size-verified, never before. A failure to clear here is not treated
	 *    as an error in its own right: the rename that follows simply fails
	 *    next, exactly as it always would when nothing occupied the final
	 *    name, and {@see self::put()} reports that failure with its own
	 *    message.
	 *
	 * In every case, the caller is already either about to raise a more
	 * specific exception or about to attempt the operation this clears the
	 * way for — a cleanup failure here must not mask either one.
	 *
	 * @param SFTP   $sftp        The live, authenticated connection.
	 * @param string $remote_path The remote path to remove.
	 * @return void
	 */
	private function discard_remote_file( SFTP $sftp, string $remote_path ): void {
		try {
			$sftp->delete( $remote_path, false );
		} catch ( Throwable $swallowed ) {
			// Best-effort cleanup; see this method's own docblock for why a
			// failure here is deliberately never surfaced.
			unset( $swallowed );
		}
	}

	/**
	 * List the `.wpmig` archives in the remote directory.
	 *
	 * A single `rawlist()` round trip, rather than `nlist()` followed by one
	 * `filesize()` call per file: the earlier shape made N+1 round trips to a
	 * remote server and never asked for a modification time at all, which is
	 * what {@see \Pontifex\Destination\DestinationRetention} needs to order by
	 * real age instead of trusting the name. `rawlist()` reports each entry's
	 * `size`, `mtime`, and `type` in the one response; `.` and `..` are
	 * skipped, and the `type` field — never checked before this — is used to
	 * exclude anything that is not a regular file, so a directory or symlink
	 * named `something.wpmig` is no longer listed as an archive. A `size` or
	 * `mtime` a server omits degrades to {@see RemoteObject}'s own "-1,
	 * unknown" sentinel rather than failing the listing; a missing `type` is
	 * likewise not treated as proof the entry is NOT a regular file, since
	 * some servers omit it, and the pre-existing name filter already confines
	 * this to Pontifex's own archives.
	 *
	 * @return array<int, RemoteObject> The archives found.
	 * @throws DestinationException If the connection, authentication, or listing fails.
	 */
	public function list(): array {
		$sftp = $this->connect();

		try {
			$entries = $sftp->rawlist( $this->remote_path );
		} catch ( Throwable $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Underlying error reported for diagnostic context; exception path, not HTML output.
			throw new DestinationException( sprintf( 'The SFTP destination listing failed: %s', $e->getMessage() ) );
		}

		if ( ! is_array( $entries ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Remote path reported for diagnostic context; exception path, not HTML output.
			throw new DestinationException( sprintf( 'The SFTP destination has no readable directory at "%s".', $this->remote_path ) );
		}

		$objects = array();
		foreach ( $entries as $name => $attributes ) {
			$name = (string) $name;
			if ( '.' === $name || '..' === $name || ! self::is_archive_name( $name ) ) {
				continue;
			}
			if ( ! is_array( $attributes ) || ! self::is_regular_file( $attributes ) ) {
				continue;
			}

			$size  = isset( $attributes['size'] ) && is_int( $attributes['size'] ) ? $attributes['size'] : -1;
			$mtime = isset( $attributes['mtime'] ) && is_int( $attributes['mtime'] ) ? $attributes['mtime'] : -1;

			$objects[] = new RemoteObject( $name, $size, $mtime );
		}

		return $objects;
	}

	/**
	 * Whether a `rawlist()` entry's reported type is a regular file.
	 *
	 * `NET_SFTP_TYPE_REGULAR` is a global constant phpseclib's
	 * {@see SFTP::__construct()} defines the first time an `SFTP` instance is
	 * built — see `vendor/phpseclib/phpseclib/phpseclib/Net/SFTP.php`, where
	 * it is registered via `self::define_array()` alongside the protocol's
	 * other numeric codes. By the time this runs, {@see self::connect()} has
	 * already constructed one, so the constant is guaranteed to exist; the
	 * `defined()` guard is a defensive fallback, not a live code path.
	 *
	 * A `type` a server did not report at all is deliberately NOT treated as
	 * proof the entry is not a regular file: some SFTP server implementations
	 * omit it, and before this method existed nothing checked type at all, so
	 * an unreported type keeps today's behaviour (include it) rather than
	 * introducing a new way to lose sight of a genuine archive.
	 *
	 * @param array<string, mixed> $attributes One `rawlist()` entry's attributes.
	 * @return bool True when the entry's type is known and is a regular file, or the type is not reported at all.
	 */
	private static function is_regular_file( array $attributes ): bool {
		if ( ! isset( $attributes['type'] ) ) {
			return true;
		}

		return defined( 'NET_SFTP_TYPE_REGULAR' ) ? NET_SFTP_TYPE_REGULAR === $attributes['type'] : true;
	}

	/**
	 * Download one archive to a local path.
	 *
	 * @param string $remote_name The remote basename.
	 * @param string $local_path  Absolute path to write the archive to.
	 * @return void
	 * @throws DestinationException If the archive is absent, or the download fails.
	 */
	public function get( string $remote_name, string $local_path ): void {
		$sftp   = $this->connect();
		$remote = $this->remote_path . '/' . basename( $remote_name );

		try {
			$ok = $sftp->get( $remote, $local_path );
		} catch ( Throwable $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Underlying error reported for diagnostic context; exception path, not HTML output.
			throw new DestinationException( sprintf( 'The download from the SFTP destination failed: %s', $e->getMessage() ) );
		}

		if ( false === $ok ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Archive basename reported for diagnostic context; exception path, not HTML output.
			throw new DestinationException( sprintf( 'The SFTP destination has no archive named "%s".', basename( $remote_name ) ) );
		}
	}

	/**
	 * Delete one archive from the remote directory.
	 *
	 * @param string $remote_name The remote basename.
	 * @return void
	 * @throws DestinationException If the delete fails.
	 */
	public function delete( string $remote_name ): void {
		$sftp   = $this->connect();
		$remote = $this->remote_path . '/' . basename( $remote_name );

		try {
			$ok = $sftp->delete( $remote, false );
		} catch ( Throwable $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Underlying error reported for diagnostic context; exception path, not HTML output.
			throw new DestinationException( sprintf( 'The SFTP destination delete failed: %s', $e->getMessage() ) );
		}

		if ( true !== $ok ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Archive basename reported for diagnostic context; exception path, not HTML output.
			throw new DestinationException( sprintf( 'The SFTP destination could not delete "%s".', basename( $remote_name ) ) );
		}
	}

	/**
	 * Verify the destination connects, authenticates, and is writable.
	 *
	 * Writes a tiny probe file into the remote directory and removes it, so a
	 * genuine write is proven without leaving anything behind.
	 *
	 * @return void
	 * @throws DestinationException If the destination cannot be reached, authenticated, or written.
	 */
	public function test(): void {
		$sftp  = $this->connect();
		$probe = $this->remote_path . '/.pontifex-write-test';

		try {
			$written = $sftp->put( $probe, 'pontifex' );
			if ( true === $written ) {
				$sftp->delete( $probe, false );
			}
		} catch ( Throwable $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Underlying error reported for diagnostic context; exception path, not HTML output.
			throw new DestinationException( sprintf( 'The SFTP destination is not writable: %s', $e->getMessage() ) );
		}

		if ( true !== $written ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Remote path reported for diagnostic context; exception path, not HTML output.
			throw new DestinationException( sprintf( 'The SFTP destination directory "%s" is not writable.', $this->remote_path ) );
		}
	}

	/**
	 * Open, host-key-verify, and authenticate the connection, caching it.
	 *
	 * The host key is checked *before* any credential is sent: a mismatch, or an
	 * unpinned key without the insecure opt-in, is refused.
	 *
	 * @return SFTP The live, authenticated connection.
	 * @throws DestinationException If the host key, connection, or authentication is rejected.
	 */
	private function connect(): SFTP {
		if ( null !== $this->connection ) {
			return $this->connection;
		}

		try {
			$sftp = new SFTP( $this->host, $this->port );
			$this->verify_host_key( $sftp );
			$this->authenticate( $sftp );
		} catch ( DestinationException $e ) {
			throw $e;
		} catch ( Throwable $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Host and underlying error reported for diagnostic context; exception path, not HTML output.
			throw new DestinationException( sprintf( 'The SFTP destination "%s" could not be reached: %s', $this->host, $e->getMessage() ) );
		}

		$this->connection = $sftp;
		return $sftp;
	}

	/**
	 * Refuse the connection unless the server's host key matches the pinned one.
	 *
	 * @param SFTP $sftp The connection, before authentication.
	 * @return void
	 * @throws DestinationException If the key is unpinned or mismatched and the insecure opt-in is off.
	 */
	private function verify_host_key( SFTP $sftp ): void {
		if ( $this->insecure_host_key ) {
			return;
		}

		if ( '' === $this->host_key_fingerprint ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Host reported for diagnostic context; exception path, not HTML output.
			throw new DestinationException( sprintf( 'The SFTP destination "%s" has no pinned host-key fingerprint. Pin one, or set the insecure host-key option to accept any key.', $this->host ) );
		}

		$server_key = $sftp->getServerPublicHostKey();
		if ( ! is_string( $server_key ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Host reported for diagnostic context; exception path, not HTML output.
			throw new DestinationException( sprintf( 'The SFTP destination "%s" did not present a host key.', $this->host ) );
		}

		$actual = self::fingerprint_of( $server_key );
		if ( ! hash_equals( $this->host_key_fingerprint, $actual ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Host and observed fingerprint reported for diagnostic context; exception path, not HTML output.
			throw new DestinationException( sprintf( 'The SFTP host key for "%s" does not match the pinned fingerprint (got %s). Refusing to connect.', $this->host, $actual ) );
		}
	}

	/**
	 * Authenticate with a private key or a password.
	 *
	 * @param SFTP $sftp The connection, after host-key verification.
	 * @return void
	 * @throws DestinationException If authentication is refused.
	 */
	private function authenticate( SFTP $sftp ): void {
		if ( '' !== $this->private_key ) {
			$credential = PublicKeyLoader::load( $this->private_key, '' !== $this->secret ? $this->secret : false );
		} else {
			$credential = $this->secret;
		}

		if ( ! $sftp->login( $this->username, $credential ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Host and username reported for diagnostic context; exception path, not HTML output.
			throw new DestinationException( sprintf( 'The SFTP destination "%s" refused authentication for user "%s".', $this->host, $this->username ) );
		}
	}

	/**
	 * Compute the OpenSSH-style SHA-256 fingerprint of a server host key.
	 *
	 * @param string $server_key The `<type> <base64>` host key as phpseclib reports it.
	 * @return string The `SHA256:…` fingerprint (unpadded base64), or '' if unparseable.
	 */
	private static function fingerprint_of( string $server_key ): string {
		$parts = explode( ' ', trim( $server_key ) );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding an SSH host-key blob to fingerprint it, not obfuscation.
		$blob = 2 <= count( $parts ) ? base64_decode( $parts[1], true ) : false;
		if ( false === $blob ) {
			return '';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding a SHA-256 host-key fingerprint for display, not obfuscation.
		return 'SHA256:' . rtrim( base64_encode( hash( 'sha256', $blob, true ) ), '=' );
	}

	/**
	 * Whether a remote name is a Pontifex archive.
	 *
	 * @param string $name The remote basename.
	 * @return bool
	 */
	private static function is_archive_name( string $name ): bool {
		return '.wpmig' === substr( $name, -6 );
	}
}
