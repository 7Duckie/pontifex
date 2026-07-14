<?php
/**
 * Pontifex destination factory — turns a stored spec into a live adapter.
 *
 * @package Pontifex\Destination
 */

declare(strict_types=1);

namespace Pontifex\Destination;

/**
 * Builds a live {@see DestinationAdapter} from a {@see DestinationSpec}.
 *
 * This is the single place secrets are resolved: a spec references its
 * credentials by environment-variable name, and the factory reads those
 * variables (and, for SFTP key auth, the private-key file) at build time and
 * hands the resolved values to the adapter's constructor. Nothing sensitive is
 * ever stored; a missing credential fails here with a message that names the
 * variable, not its value (ADR 0017).
 */
final class DestinationFactory {

	/**
	 * Build the adapter a spec describes.
	 *
	 * @param DestinationSpec $spec The stored destination.
	 * @return DestinationAdapter
	 * @throws DestinationException If a required setting or credential is missing, or the type is unsupported.
	 */
	public function from_spec( DestinationSpec $spec ): DestinationAdapter {
		if ( DestinationSpec::TYPE_SFTP === $spec->type() ) {
			return $this->build_sftp( $spec );
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Destination type reported for diagnostic context; exception path, not HTML output.
		throw new DestinationException( sprintf( 'Destination type "%s" is not supported in this version.', $spec->type() ) );
	}

	/**
	 * Build an SFTP adapter, resolving its secret and key from the environment.
	 *
	 * @param DestinationSpec $spec The SFTP destination.
	 * @return SftpDestination
	 * @throws DestinationException If a required setting or credential is missing or unreadable.
	 */
	private function build_sftp( DestinationSpec $spec ): SftpDestination {
		$host        = (string) $spec->setting( 'host' );
		$username    = (string) $spec->setting( 'username' );
		$remote_path = (string) $spec->setting( 'path' );
		if ( '' === $host || '' === $username || '' === $remote_path ) {
			throw new DestinationException( 'The SFTP destination needs a host, a username, and a remote path.' );
		}

		$port              = (int) $spec->setting( 'port', 22 );
		$auth              = (string) $spec->setting( 'auth', 'key' );
		$host_key          = (string) $spec->setting( 'host_key', '' );
		$insecure_host_key = (bool) $spec->setting( 'insecure_host_key', false );
		$secret_env        = (string) $spec->setting( 'secret_env', '' );
		$secret            = '' !== $secret_env ? $this->require_env( $secret_env ) : '';

		$private_key = '';
		if ( 'password' === $auth ) {
			if ( '' === $secret ) {
				throw new DestinationException( 'An SFTP destination using password authentication needs a secret_env naming the password variable.' );
			}
		} else {
			$key_path = (string) $spec->setting( 'key_path', '' );
			if ( '' === $key_path ) {
				throw new DestinationException( 'An SFTP destination using key authentication needs a key_path.' );
			}
			$private_key = $this->read_key_file( $key_path );
		}

		return new SftpDestination( $host, $port, $username, $remote_path, $private_key, $secret, $host_key, $insecure_host_key );
	}

	/**
	 * Read a required environment variable, failing with its name when unset.
	 *
	 * @param string $name The environment-variable name.
	 * @return string The value.
	 * @throws DestinationException If the variable is unset or empty.
	 */
	private function require_env( string $name ): string {
		$value = getenv( $name );
		if ( false === $value || '' === $value ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Variable name (not its value) reported for diagnostic context; exception path, not HTML output.
			throw new DestinationException( sprintf( 'The environment variable "%s" is not set; the destination credential cannot be read.', $name ) );
		}

		return $value;
	}

	/**
	 * Read a private-key file, failing when it cannot be read.
	 *
	 * @param string $key_path The absolute path to the private-key file.
	 * @return string The key contents.
	 * @throws DestinationException If the file cannot be read.
	 */
	private function read_key_file( string $key_path ): string {
		if ( ! is_readable( $key_path ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Key path (not its contents) reported for diagnostic context; exception path, not HTML output.
			throw new DestinationException( sprintf( 'The SFTP private-key file "%s" cannot be read.', $key_path ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local private-key file from disk; wp_remote_get is for URLs, not local paths.
		$contents = file_get_contents( $key_path );
		if ( false === $contents || '' === $contents ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Key path (not its contents) reported for diagnostic context; exception path, not HTML output.
			throw new DestinationException( sprintf( 'The SFTP private-key file "%s" is empty or unreadable.', $key_path ) );
		}

		return $contents;
	}
}
