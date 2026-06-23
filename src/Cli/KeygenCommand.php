<?php
/**
 * Pontifex Keygen command — generates an Ed25519 keypair for signing archives.
 *
 * @package Pontifex\Cli
 */

declare(strict_types=1);

namespace Pontifex\Cli;

use Throwable;
use WP_CLI;
use Pontifex\Archive\Crypto\SigningKeypair;

/**
 * `wp pontifex keygen` — generate an Ed25519 keypair for signing archives.
 *
 * Writes a secret-key file (mode 0600) and a public-key file. Sign an export
 * with the secret key (`export --sign --signing-key=<path>`); share the public
 * key so others can verify a restore or check (`verify` / `import
 * --public-key=<path>`).
 *
 * There is no passphrase on the key: keep the secret-key file safe and backed
 * up. Losing it means you can no longer sign as this key; leaking it lets
 * someone else sign archives as you.
 *
 * ## OPTIONS
 *
 * --secret-key=<path>
 * : Absolute path to write the secret key to. Refused if the file already exists.
 *
 * --public-key=<path>
 * : Absolute path to write the public key to. Refused if the file already exists.
 *
 * ## EXAMPLES
 *
 *     wp pontifex keygen --secret-key=/root/pontifex.key --public-key=/root/pontifex.pub
 *
 * @when after_wp_load
 */
final class KeygenCommand {

	/**
	 * The WP-CLI command entry point.
	 *
	 * Generates a fresh Ed25519 keypair and writes the two key files, then
	 * prints the key id and where each half was written.
	 *
	 * @param array<int, string>         $positional_args  Positional arguments. Unused for `keygen`.
	 * @param array<string, string|bool> $associative_args The `--secret-key` and `--public-key` paths.
	 * @return void
	 */
	public function __invoke( array $positional_args, array $associative_args ): void {
		$secret_path = $this->require_path( $associative_args, 'secret-key' );
		$public_path = $this->require_path( $associative_args, 'public-key' );

		$keypair = SigningKeypair::generate();

		try {
			SigningKeys::write_keypair( $keypair, $secret_path, $public_path );

			WP_CLI::log( sprintf( 'Generated Ed25519 signing keypair (key id %s).', bin2hex( $keypair->key_id() ) ) );
			WP_CLI::log( sprintf( '  secret key: %s (mode 0600 — keep it safe and backed up; there is no passphrase)', $secret_path ) );
			WP_CLI::log( sprintf( '  public key: %s (share this so others can verify)', $public_path ) );
		} catch ( Throwable $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WP_CLI::error renders the message to the terminal, not HTML; the message is our own.
			WP_CLI::error( $error->getMessage() );
		}
	}

	/**
	 * Read a required absolute-path option, erroring out if it is missing or relative.
	 *
	 * @param array<string, string|bool> $associative_args The CLI's associative args.
	 * @param string                     $key              The option name.
	 * @return string The validated absolute path.
	 */
	private function require_path( array $associative_args, string $key ): string {
		if ( ! isset( $associative_args[ $key ] ) || '' === $associative_args[ $key ] || true === $associative_args[ $key ] ) {
			WP_CLI::error( sprintf( '--%s=<path> is required.', $key ) );
		}
		$path = (string) $associative_args[ $key ];
		if ( '/' !== substr( $path, 0, 1 ) ) {
			WP_CLI::error( sprintf( '--%s must be an absolute path; got "%s".', $key, $path ) );
		}
		return $path;
	}
}
