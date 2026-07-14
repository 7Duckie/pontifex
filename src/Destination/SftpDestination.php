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
	 * @param string $local_path Absolute path of the finished archive to upload.
	 * @return void
	 * @throws DestinationException If the connection, authentication, or upload fails.
	 */
	public function put( string $local_path ): void {
		$sftp   = $this->connect();
		$remote = $this->remote_path . '/' . basename( $local_path );

		try {
			$ok = $sftp->put( $remote, $local_path, SFTP::SOURCE_LOCAL_FILE );
		} catch ( Throwable $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Underlying error reported for diagnostic context; exception path, not HTML output.
			throw new DestinationException( sprintf( 'The upload to the SFTP destination failed: %s', $e->getMessage() ) );
		}

		if ( true !== $ok ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Archive basename reported for diagnostic context; exception path, not HTML output.
			throw new DestinationException( sprintf( 'The SFTP destination refused the upload of "%s".', basename( $local_path ) ) );
		}
	}

	/**
	 * List the `.wpmig` archives in the remote directory.
	 *
	 * @return array<int, RemoteObject> The archives found.
	 * @throws DestinationException If the connection, authentication, or listing fails.
	 */
	public function list(): array {
		$sftp = $this->connect();

		try {
			$names = $sftp->nlist( $this->remote_path );
		} catch ( Throwable $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Underlying error reported for diagnostic context; exception path, not HTML output.
			throw new DestinationException( sprintf( 'The SFTP destination listing failed: %s', $e->getMessage() ) );
		}

		if ( ! is_array( $names ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Remote path reported for diagnostic context; exception path, not HTML output.
			throw new DestinationException( sprintf( 'The SFTP destination has no readable directory at "%s".', $this->remote_path ) );
		}

		$objects = array();
		foreach ( $names as $name ) {
			if ( ! is_string( $name ) || ! self::is_archive_name( $name ) ) {
				continue;
			}
			$size      = $sftp->filesize( $this->remote_path . '/' . $name );
			$objects[] = new RemoteObject( $name, is_int( $size ) ? $size : -1 );
		}

		return $objects;
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
