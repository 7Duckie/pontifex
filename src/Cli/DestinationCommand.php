<?php
/**
 * Pontifex Destination command — configure and use an offsite destination.
 *
 * @package Pontifex\Cli
 */

declare(strict_types=1);

namespace Pontifex\Cli;

use WP_CLI;
use Pontifex\Destination\DestinationAdapter;
use Pontifex\Destination\DestinationException;
use Pontifex\Destination\DestinationFactory;
use Pontifex\Destination\DestinationSpec;
use Pontifex\Destination\DestinationStore;
use Pontifex\WordPress\RealWordPressContext;
use Pontifex\WordPress\WordPressContext;

/**
 * `wp pontifex destination` — the CLI surface over named offsite destinations.
 *
 * A destination is user-owned storage (their SFTP server, their S3 bucket)
 * configured once and referenced by name. The {@see DestinationStore} holds the
 * non-secret configuration; a credential is referenced by an environment-variable
 * name and resolved by the {@see DestinationFactory} into a live
 * {@see DestinationAdapter} at point of use, so no secret is ever stored
 * (ADR 0017). This command adds and removes destinations, lists them, proves one
 * works, shows what it holds, and pulls one archive back. Uploading itself rides
 * on `wp pontifex export --destination=<name>`.
 *
 * ## OPTIONS
 *
 * <action>
 * : What to do.
 * ---
 * options:
 *   - add
 *   - remove
 *   - list
 *   - test
 *   - archives
 *   - pull
 * ---
 *
 * [<name>]
 * : The destination name. Required for every action except `list`.
 *
 * [<archive>]
 * : The remote archive's basename, as shown by `archives` (required with `pull`).
 *
 * [--type=<type>]
 * : Destination type when adding. Currently `sftp`. Default: sftp.
 *
 * [--host=<host>]
 * : SFTP server hostname (with `add`).
 *
 * [--port=<port>]
 * : SFTP server port (with `add`). Default: 22.
 *
 * [--username=<username>]
 * : SFTP login username (with `add`).
 *
 * [--path=<path>]
 * : Remote directory the archives live in (with `add`).
 *
 * [--auth=<auth>]
 * : SFTP authentication method (with `add`): `key` or `password`. Default: key.
 *
 * [--key-path=<path>]
 * : Path to the private-key file for key authentication (with `add`, `--auth=key`).
 *
 * [--secret-env=<name>]
 * : Name of the environment variable holding the password (`--auth=password`)
 *   or the key passphrase (`--auth=key`, optional). The value is read at use,
 *   never stored.
 *
 * [--host-key=<fingerprint>]
 * : The server's pinned host-key fingerprint (`SHA256:…`, with `add`). A
 *   connection whose key does not match is refused.
 *
 * [--insecure-host-key]
 * : Accept any host key instead of pinning one (with `add`). Unsafe — it
 *   defeats man-in-the-middle protection; pin `--host-key` instead where you can.
 *
 * [--retention=<count>]
 * : How many archives to keep at the destination (with `add`). Default: 0
 *   (keep all). Must not be negative.
 *
 * [--output=<path>]
 * : Where to write the downloaded archive (with `pull`). Defaults to the
 *   archive's basename in the current working directory.
 *
 * ## EXAMPLES
 *
 *     wp pontifex destination add offsite --host=backup.example.com --username=wp --path=/backups --key-path=/home/wp/.ssh/id_ed25519 --host-key=SHA256:abc… --retention=7
 *     wp pontifex destination list
 *     wp pontifex destination test offsite
 *     wp pontifex destination archives offsite
 *     wp pontifex destination pull offsite pontifex-2026-07-13-030000.wpmig --output=/tmp/restore.wpmig
 *     wp pontifex destination remove offsite
 *
 * @when after_wp_load
 */
final class DestinationCommand {

	/**
	 * The WordPressContext abstraction the destination store reads and writes through.
	 *
	 * Injected via the constructor so tests can substitute a mock; WP-CLI
	 * registers the command by class name and passes no arguments, so the
	 * parameter defaults to the real implementation.
	 *
	 * @var WordPressContext
	 */
	private WordPressContext $wordpress_context;

	/**
	 * Construct a DestinationCommand instance.
	 *
	 * @param WordPressContext|null $wordpress_context Optional. Defaults to a fresh RealWordPressContext.
	 */
	public function __construct( ?WordPressContext $wordpress_context = null ) {
		$this->wordpress_context = $wordpress_context ?? new RealWordPressContext();
	}

	/**
	 * The WP-CLI command entry point.
	 *
	 * Dispatches on the positional action. Every action but `list` needs a
	 * destination name; `pull` additionally needs the remote archive name.
	 *
	 * @param array<int, string>         $positional_args  Positional arguments; [0] the action, [1] the name, [2] the archive for `pull`.
	 * @param array<string, string|bool> $associative_args Associative arguments (the `add` settings and `--output`).
	 * @return void
	 */
	public function __invoke( array $positional_args, array $associative_args ): void {
		$action = isset( $positional_args[0] ) ? (string) $positional_args[0] : '';

		if ( 'list' === $action ) {
			$this->list_configured();
			return;
		}

		$name = isset( $positional_args[1] ) ? (string) $positional_args[1] : '';
		if ( '' === $name ) {
			WP_CLI::error( __( 'A destination name is required: wp pontifex destination <action> <name>.', 'pontifex' ) );
		}

		switch ( $action ) {
			case 'add':
				$this->add( $name, $associative_args );
				break;
			case 'remove':
				$this->remove( $name );
				break;
			case 'test':
				$this->test( $name );
				break;
			case 'archives':
				$this->archives( $name );
				break;
			case 'pull':
				$archive = isset( $positional_args[2] ) ? (string) $positional_args[2] : '';
				if ( '' === $archive ) {
					WP_CLI::error( __( 'An archive name is required: wp pontifex destination pull <name> <archive>.', 'pontifex' ) );
				}
				$this->pull( $name, $archive, $associative_args );
				break;
			default:
				WP_CLI::error( __( 'Unknown action. Usage: wp pontifex destination <add|remove|list|test|archives|pull>.', 'pontifex' ) );
		}
	}

	/**
	 * Add or replace a named destination.
	 *
	 * @param string                     $name             The destination name.
	 * @param array<string, string|bool> $associative_args The configuration flags.
	 * @return void
	 */
	private function add( string $name, array $associative_args ): void {
		$type = isset( $associative_args['type'] ) ? (string) $associative_args['type'] : DestinationSpec::TYPE_SFTP;
		if ( DestinationSpec::TYPE_SFTP !== $type ) {
			WP_CLI::error( __( 'Only the sftp destination type is supported in this version.', 'pontifex' ) );
		}

		$host     = isset( $associative_args['host'] ) ? (string) $associative_args['host'] : '';
		$username = isset( $associative_args['username'] ) ? (string) $associative_args['username'] : '';
		$path     = isset( $associative_args['path'] ) ? (string) $associative_args['path'] : '';
		if ( '' === $host || '' === $username || '' === $path ) {
			WP_CLI::error( __( 'An sftp destination needs --host, --username, and --path.', 'pontifex' ) );
		}

		$auth = isset( $associative_args['auth'] ) ? (string) $associative_args['auth'] : 'key';
		if ( 'key' !== $auth && 'password' !== $auth ) {
			WP_CLI::error( __( 'The --auth value must be "key" or "password".', 'pontifex' ) );
		}

		$secret_env = isset( $associative_args['secret-env'] ) ? (string) $associative_args['secret-env'] : '';
		$key_path   = isset( $associative_args['key-path'] ) ? (string) $associative_args['key-path'] : '';
		if ( 'password' === $auth && '' === $secret_env ) {
			WP_CLI::error( __( 'Password authentication needs --secret-env naming the environment variable that holds the password.', 'pontifex' ) );
		}
		if ( 'key' === $auth && '' === $key_path ) {
			WP_CLI::error( __( 'Key authentication needs --key-path pointing at the private-key file.', 'pontifex' ) );
		}

		$host_key = isset( $associative_args['host-key'] ) ? (string) $associative_args['host-key'] : '';
		$insecure = isset( $associative_args['insecure-host-key'] ) && false !== $associative_args['insecure-host-key'];
		if ( '' === $host_key && ! $insecure ) {
			WP_CLI::warning( __( 'No host key pinned. `test` and `export --destination` will refuse to connect until you pass --host-key=<fingerprint>, or accept the risk with --insecure-host-key.', 'pontifex' ) );
		}

		$retention = isset( $associative_args['retention'] ) ? (int) $associative_args['retention'] : 0;
		if ( $retention < 0 ) {
			WP_CLI::error( __( 'The --retention count must not be negative.', 'pontifex' ) );
		}

		$settings = array(
			'host'              => $host,
			'port'              => isset( $associative_args['port'] ) ? (int) $associative_args['port'] : 22,
			'username'          => $username,
			'path'              => $path,
			'auth'              => $auth,
			'key_path'          => $key_path,
			'secret_env'        => $secret_env,
			'host_key'          => $host_key,
			'insecure_host_key' => $insecure,
		);

		$this->store()->save( new DestinationSpec( $name, $type, $settings, $retention ) );

		WP_CLI::success(
			sprintf(
				/* translators: %1$s: the destination name that was saved */
				__( 'Destination "%1$s" saved. Run `wp pontifex destination test %1$s` to verify it.', 'pontifex' ),
				$name
			)
		);
	}

	/**
	 * Remove a named destination.
	 *
	 * @param string $name The destination name.
	 * @return void
	 */
	private function remove( string $name ): void {
		if ( null === $this->store()->get( $name ) ) {
			WP_CLI::error(
				sprintf(
					/* translators: %s: the destination name that was not found */
					__( 'No destination named "%s" is configured.', 'pontifex' ),
					$name
				)
			);
		}

		$this->store()->delete( $name );

		WP_CLI::success(
			sprintf(
				/* translators: %s: the destination name that was removed */
				__( 'Destination "%s" removed.', 'pontifex' ),
				$name
			)
		);
	}

	/**
	 * List the configured destinations.
	 *
	 * @return void
	 */
	private function list_configured(): void {
		$specs = $this->store()->all();
		if ( array() === $specs ) {
			WP_CLI::log( __( 'No destinations are configured. Add one with `wp pontifex destination add`.', 'pontifex' ) );
			return;
		}

		$rows = array();
		foreach ( $specs as $spec ) {
			$rows[] = array(
				'name'      => $spec->name(),
				'type'      => $spec->type(),
				'retention' => 0 === $spec->retention() ? __( '(keep all)', 'pontifex' ) : (string) $spec->retention(),
			);
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'name', 'type', 'retention' ) );
	}

	/**
	 * Run a live reachability, authentication, and writability check.
	 *
	 * @param string $name The destination name.
	 * @return void
	 */
	private function test( string $name ): void {
		$adapter = $this->resolve( $name );

		try {
			$adapter->test();
		} catch ( DestinationException $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WP_CLI::error renders the message to the terminal, not HTML; DestinationException messages never carry a secret (ADR 0017).
			WP_CLI::error( $e->getMessage() );
		}

		WP_CLI::success(
			sprintf(
				/* translators: %s: the destination name that was tested */
				__( 'Destination "%s" is reachable, authenticated, and writable.', 'pontifex' ),
				$name
			)
		);
	}

	/**
	 * List the archives a destination currently holds.
	 *
	 * @param string $name The destination name.
	 * @return void
	 */
	private function archives( string $name ): void {
		$adapter = $this->resolve( $name );

		try {
			$objects = $adapter->list();
		} catch ( DestinationException $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WP_CLI::error renders the message to the terminal, not HTML; DestinationException messages never carry a secret (ADR 0017).
			WP_CLI::error( $e->getMessage() );
		}

		if ( array() === $objects ) {
			WP_CLI::log(
				sprintf(
					/* translators: %s: the destination name that holds no archives */
					__( 'Destination "%s" holds no archives.', 'pontifex' ),
					$name
				)
			);
			return;
		}

		$rows = array();
		foreach ( $objects as $object ) {
			$rows[] = array(
				'name' => $object->name(),
				'size' => -1 === $object->size() ? __( '(unknown)', 'pontifex' ) : $this->wordpress_context->format_size( $object->size() ),
			);
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'name', 'size' ) );
	}

	/**
	 * Download one remote archive to a local path.
	 *
	 * @param string                     $name             The destination name.
	 * @param string                     $archive          The remote archive's basename.
	 * @param array<string, string|bool> $associative_args The command's flags; `--output` overrides the default local path.
	 * @return void
	 */
	private function pull( string $name, string $archive, array $associative_args ): void {
		$adapter = $this->resolve( $name );

		$output = isset( $associative_args['output'] ) && is_string( $associative_args['output'] ) && '' !== $associative_args['output']
			? $associative_args['output']
			: self::default_output_path( $archive );

		try {
			$adapter->get( $archive, $output );
		} catch ( DestinationException $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WP_CLI::error renders the message to the terminal, not HTML; DestinationException messages never carry a secret (ADR 0017).
			WP_CLI::error( $e->getMessage() );
		}

		WP_CLI::success(
			sprintf(
				/* translators: %s: the local path the archive was written to */
				__( 'Downloaded to %s.', 'pontifex' ),
				$output
			)
		);
	}

	/**
	 * Resolve a destination name to a live adapter.
	 *
	 * A missing name and an unbuildable spec (a missing credential, an
	 * unsupported type) are both reported through WP_CLI::error(), which halts
	 * the command — so every subcommand can use the result without a null check.
	 *
	 * @param string $name The destination name.
	 * @return DestinationAdapter
	 */
	private function resolve( string $name ): DestinationAdapter {
		$spec = $this->store()->get( $name );
		if ( null === $spec ) {
			WP_CLI::error(
				sprintf(
					/* translators: %s: the destination name that was not found */
					__( 'No destination named "%s" is configured.', 'pontifex' ),
					$name
				)
			);
		}

		try {
			return $this->factory()->from_spec( $spec );
		} catch ( DestinationException $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WP_CLI::error renders the message to the terminal, not HTML; DestinationException messages never carry a secret (ADR 0017).
			WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * The destination store over this command's context.
	 *
	 * @return DestinationStore The store.
	 */
	private function store(): DestinationStore {
		return new DestinationStore( $this->wordpress_context );
	}

	/**
	 * The destination factory that turns a stored spec into a live adapter.
	 *
	 * @return DestinationFactory The factory.
	 */
	private function factory(): DestinationFactory {
		return new DestinationFactory();
	}

	/**
	 * The default local path `pull` writes to when `--output` is absent.
	 *
	 * @param string $archive The remote archive's basename.
	 * @return string The archive's basename inside the current working directory.
	 */
	private static function default_output_path( string $archive ): string {
		$cwd = getcwd();
		return ( false !== $cwd ? $cwd : '.' ) . '/' . basename( $archive );
	}
}
