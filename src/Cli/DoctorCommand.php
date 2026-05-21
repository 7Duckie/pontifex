<?php
/**
 * Pontifex Doctor command — host environment audit.
 *
 * @package Pontifex\Cli
 */

declare(strict_types=1);

namespace Pontifex\Cli;

use WP_CLI;
use WP_CLI\Formatter;
use Pontifex\Environment\Environment;
use Pontifex\Environment\RealEnvironment;

/**
 * `wp pontifex doctor` — host environment audit command.
 *
 * Runs a battery of read-only host-environment checks relevant to
 * WordPress migration. No mutations. No database writes. No filesystem
 * writes. Designed to be the first thing an operator (or support
 * engineer) reaches for when diagnosing a failing migration.
 *
 * Output is structured: each check produces a row with a category,
 * label, current value, status (OK/WARN/FAIL/INFO), and optional note.
 * The default presentation is a human-readable table; --format=json
 * gives machine-readable output for support tooling.
 *
 * ## EXAMPLES
 *
 *     wp pontifex doctor
 *     wp pontifex doctor --format=json
 *     wp pontifex doctor --fields=category,name,status
 *
 * @when after_wp_load
 */
final class DoctorCommand {

	/**
	 * Status constants.
	 *
	 * Defined as class constants rather than free strings so that:
	 *  - typos become parse-time errors, not runtime mysteries,
	 *  - static analysis (PHPStan) can reason about which strings are
	 *    valid status values,
	 *  - if we ever want to change the wire values (e.g. localise them
	 *    or shorten them), we change them in exactly one place.
	 */
	private const STATUS_OK   = 'OK';
	private const STATUS_WARN = 'WARN';
	private const STATUS_FAIL = 'FAIL';
	private const STATUS_INFO = 'INFO';

	/**
	 * Recommended minimum memory limit, in bytes (256 MB).
	 *
	 * Migration packs large database rows and walks large file trees.
	 * 256 MB is comfortable; 128 MB will work for small sites but
	 * deserves a WARN so the operator knows to watch for it.
	 */
	private const RECOMMENDED_MEMORY_BYTES = 256 * 1024 * 1024;

	/**
	 * Recommended minimum max_execution_time, in seconds.
	 *
	 * Pontifex resumes from checkpoints, so an aggressive timeout is
	 * not fatal — but a 30-second timeout will cause far more pause-
	 * and-resume churn than a 120-second one.
	 */
	private const RECOMMENDED_MAX_EXECUTION_SECONDS = 120;

	/**
	 * Recommended minimum free disk space at WP_CONTENT_DIR, in bytes.
	 *
	 * Snapshots and archives both consume disk. 2 GB is a soft floor;
	 * a real migration of a non-trivial site needs much more, but 2 GB
	 * is the threshold below which we cannot even meaningfully start.
	 */
	private const RECOMMENDED_FREE_DISK_BYTES = 2 * 1024 * 1024 * 1024;

	/**
	 * The Environment abstraction this command queries.
	 *
	 * Injected via the constructor so tests can substitute a mock that
	 * returns deterministic values for PHP version, extension presence,
	 * disk space, and the other environmental facts each check needs.
	 *
	 * @var Environment
	 */
	private Environment $environment;

	/**
	 * Construct a DoctorCommand instance.
	 *
	 * WP-CLI registers the command via its class name and does not pass
	 * constructor arguments, so the parameter is optional and defaults
	 * to a RealEnvironment that talks to PHP's actual global state.
	 * Tests pass a mock Environment explicitly.
	 *
	 * @param Environment|null $environment Optional. The Environment to query. Defaults to a fresh RealEnvironment instance.
	 */
	public function __construct( ?Environment $environment = null ) {
		$this->environment = $environment ?? new RealEnvironment();
	}

	/**
	 * The WP-CLI command entry point.
	 *
	 * `__invoke` is a magic method: an instance with this method can
	 * be called like a function. WP-CLI uses it to dispatch to a single-
	 * command class. When we add `wp pontifex export`, it will be its
	 * own class with its own `__invoke`.
	 *
	 * @param array<int, string>         $positional_args  Positional arguments passed on the CLI. Unused for `doctor`.
	 * @param array<string, string|bool> $associative_args Associative `--key=value` and `--flag` arguments. Consumed by the formatter.
	 */
	public function __invoke( array $positional_args, array $associative_args ): void {

		// Build the list of check rows. Each row is an associative array
		// with the same shape, so the formatter can render them as a table
		// or serialise them as JSON without further transformation.
		$check_rows = $this->collect_all_checks();

		// The fields the formatter will render by default, in this order.
		$default_fields = array( 'category', 'name', 'value', 'status', 'note' );

		$formatter = new Formatter( $associative_args, $default_fields );
		$formatter->display_items( $check_rows );

		// Summary line: counts of each status. Useful for humans skimming
		// the output and for CI scripts grepping the tail.
		$this->print_summary( $check_rows );
	}

	/**
	 * Run every environment check and return the collected rows.
	 *
	 * Each check is a small private method returning a single row. This
	 * keeps each check independently understandable and independently
	 * testable. Adding a new check is one method plus one line here.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function collect_all_checks(): array {

		$check_rows = array();

		// Runtime versions.
		$check_rows[] = $this->check_php_version();
		$check_rows[] = $this->check_wordpress_version();
		$check_rows[] = $this->check_database_version();

		// PHP configuration that bites migrations.
		$check_rows[] = $this->check_memory_limit();
		$check_rows[] = $this->check_max_execution_time();
		$check_rows[] = $this->check_upload_max_filesize();
		$check_rows[] = $this->check_open_basedir();

		// PHP extensions Pontifex needs (or will need in Phase 1).
		$check_rows[] = $this->check_extension_present( 'zlib', true, 'Required: gzip compression fallback.' );
		$check_rows[] = $this->check_extension_present( 'zstd', false, 'Optional: zstd compression, preferred when present.' );
		$check_rows[] = $this->check_extension_present( 'sodium', true, 'Required: archive encryption (AES-256-GCM via libsodium).' );
		$check_rows[] = $this->check_extension_present( 'openssl', true, 'Required: signed download URLs (HMAC).' );
		$check_rows[] = $this->check_extension_present( 'mbstring', true, 'Required: safe string handling on multibyte content.' );
		$check_rows[] = $this->check_extension_present( 'pcre', true, 'Required: serialised-data parsing.' );
		$check_rows[] = $this->check_extension_present( 'json', true, 'Required: manifest serialisation.' );

		// Filesystem.
		$check_rows[] = $this->check_free_disk_space();
		$check_rows[] = $this->check_uploads_dir_writable();

		// WordPress configuration.
		$check_rows[] = $this->check_wp_cron_status();
		$check_rows[] = $this->check_action_scheduler_presence();

		return $check_rows;
	}

	// -------------------------------------------------------------------------
	// Individual checks. Each returns a single row.
	// -------------------------------------------------------------------------

	/**
	 * Check the running PHP version against the supported branch threshold.
	 *
	 * @return array<string, string>
	 */
	private function check_php_version(): array {

		$current_php_version = $this->environment->php_version();

		// We are guaranteed to be on >= 8.1 here, because the bootstrap
		// refused to load otherwise. So the only question is whether the
		// operator is on a still-supported branch.
		//
		// PHP 8.1 went EOL January 2026. We're not failing for it — the
		// plugin still works — but we want operators to know.
		$is_still_supported = version_compare( $current_php_version, '8.2.0', '>=' );

		return $this->build_row(
			'Runtime',
			'PHP version',
			$current_php_version,
			$is_still_supported ? self::STATUS_OK : self::STATUS_WARN,
			$is_still_supported
				? ''
				: sprintf( 'PHP %s is end-of-life. Upgrade to 8.2+ recommended.', $current_php_version )
		);
	}

	/**
	 * Check the running WordPress version against the supported minimum.
	 *
	 * @return array<string, string>
	 */
	private function check_wordpress_version(): array {

		$current_wp_version = (string) get_bloginfo( 'version' );
		$minimum_wp_version = $this->environment->is_constant_defined( 'PONTIFEX_MINIMUM_WP_VERSION' )
			? (string) $this->environment->constant_value( 'PONTIFEX_MINIMUM_WP_VERSION' )
			: '6.5';

		$meets_minimum = version_compare( $current_wp_version, $minimum_wp_version, '>=' );

		return $this->build_row(
			'Runtime',
			'WordPress version',
			$current_wp_version,
			$meets_minimum ? self::STATUS_OK : self::STATUS_FAIL,
			$meets_minimum
				? ''
				: sprintf( 'Pontifex requires WordPress %s or newer.', $minimum_wp_version )
		);
	}

	/**
	 * Check the connected MySQL/MariaDB server version (informational only).
	 *
	 * @return array<string, string>
	 */
	private function check_database_version(): array {

		global $wpdb;

		// `get_var` returns the first value of the first row, or null.
		// We cast to string for a consistent row shape; an unknown DB
		// version is unusual but not a failure (just informational).
		$database_version = (string) $wpdb->get_var( 'SELECT VERSION()' );

		return $this->build_row(
			'Runtime',
			'Database version',
			'' !== $database_version ? $database_version : '(unknown)',
			self::STATUS_INFO,
			'MySQL 5.7+ or MariaDB 10.4+ recommended.'
		);
	}

	/**
	 * Check the PHP `memory_limit` setting against the recommended floor.
	 *
	 * @return array<string, string>
	 */
	private function check_memory_limit(): array {

		// `memory_limit` is a string like "256M". WordPress ships a
		// helper that parses these into byte counts. -1 means unlimited.
		$configured_value    = $this->environment->ini_get( 'memory_limit' );
		$configured_in_bytes = wp_convert_hr_to_bytes( $configured_value );

		// `wp_convert_hr_to_bytes('-1')` returns 0; we treat -1 specially.
		if ( '-1' === $configured_value ) {
			return $this->build_row( 'PHP config', 'memory_limit', 'unlimited', self::STATUS_OK, '' );
		}

		$is_sufficient = $configured_in_bytes >= self::RECOMMENDED_MEMORY_BYTES;

		return $this->build_row(
			'PHP config',
			'memory_limit',
			$configured_value,
			$is_sufficient ? self::STATUS_OK : self::STATUS_WARN,
			$is_sufficient ? '' : 'Recommend 256M or higher for migrations of medium+ sites.'
		);
	}

	/**
	 * Check the PHP `max_execution_time` against the recommended floor.
	 *
	 * @return array<string, string>
	 */
	private function check_max_execution_time(): array {

		$configured_seconds = (int) $this->environment->ini_get( 'max_execution_time' );

		// 0 means unlimited (typical on CLI runs).
		if ( 0 === $configured_seconds ) {
			return $this->build_row( 'PHP config', 'max_execution_time', 'unlimited', self::STATUS_OK, '' );
		}

		$is_sufficient = $configured_seconds >= self::RECOMMENDED_MAX_EXECUTION_SECONDS;

		return $this->build_row(
			'PHP config',
			'max_execution_time',
			sprintf( '%d seconds', $configured_seconds ),
			$is_sufficient ? self::STATUS_OK : self::STATUS_WARN,
			$is_sufficient
				? ''
				: 'Short timeouts cause more pause-and-resume cycles. 120s+ recommended.'
		);
	}

	/**
	 * Report the effective upload-size ceiling (informational only).
	 *
	 * @return array<string, string>
	 */
	private function check_upload_max_filesize(): array {

		// `wp_max_upload_size()` takes both `upload_max_filesize` and
		// `post_max_size` into account, plus any filters, and returns
		// the effective maximum a user can actually upload via the
		// admin UI. This is the number that matters for Import.
		$effective_maximum_bytes = (int) wp_max_upload_size();

		return $this->build_row(
			'PHP config',
			'Effective upload limit',
			size_format( $effective_maximum_bytes ),
			self::STATUS_INFO,
			'Archives larger than this must be uploaded via WP-CLI or SFTP.'
		);
	}

	/**
	 * Report whether `open_basedir` is configured (warns if so).
	 *
	 * @return array<string, string>
	 */
	private function check_open_basedir(): array {

		$open_basedir_setting = $this->environment->ini_get( 'open_basedir' );

		if ( '' === $open_basedir_setting ) {
			return $this->build_row( 'PHP config', 'open_basedir', '(not set)', self::STATUS_OK, '' );
		}

		return $this->build_row(
			'PHP config',
			'open_basedir',
			$open_basedir_setting,
			self::STATUS_WARN,
			'open_basedir restrictions can prevent Pontifex writing snapshots outside web root.'
		);
	}

	/**
	 * Check whether a named PHP extension is loaded.
	 *
	 * @param string $extension_name Extension identifier, e.g. 'zstd' or 'sodium'.
	 * @param bool   $is_required    True → missing extension is FAIL; false → missing extension is WARN only.
	 * @param string $purpose_note   Human-readable reason the extension matters, shown in the row's note column.
	 * @return array<string, string>
	 */
	private function check_extension_present(
		string $extension_name,
		bool $is_required,
		string $purpose_note
	): array {

		$is_loaded = $this->environment->extension_loaded( $extension_name );

		if ( $is_loaded ) {
			return $this->build_row(
				'PHP extensions',
				sprintf( 'ext-%s', $extension_name ),
				'loaded',
				self::STATUS_OK,
				$purpose_note
			);
		}

		return $this->build_row(
			'PHP extensions',
			sprintf( 'ext-%s', $extension_name ),
			'missing',
			$is_required ? self::STATUS_FAIL : self::STATUS_WARN,
			$purpose_note
		);
	}

	/**
	 * Report free disk space at WP_CONTENT_DIR.
	 *
	 * @return array<string, string>
	 */
	private function check_free_disk_space(): array {

		// `disk_free_space` returns false on failure (e.g. open_basedir
		// restriction). We treat false distinctly from a low number.
		$wp_content_dir = (string) $this->environment->constant_value( 'WP_CONTENT_DIR' );
		$free_bytes_raw = $this->environment->disk_free_space( $wp_content_dir );

		if ( false === $free_bytes_raw ) {
			return $this->build_row(
				'Filesystem',
				'Free disk space (wp-content)',
				'(unavailable)',
				self::STATUS_WARN,
				'Could not read disk space; open_basedir or permission restriction likely.'
			);
		}

		$free_bytes    = (int) $free_bytes_raw;
		$is_sufficient = $free_bytes >= self::RECOMMENDED_FREE_DISK_BYTES;

		return $this->build_row(
			'Filesystem',
			'Free disk space (wp-content)',
			size_format( $free_bytes ),
			$is_sufficient ? self::STATUS_OK : self::STATUS_WARN,
			$is_sufficient
				? ''
				: 'Less than 2 GB free. Snapshots and exports will be tight.'
		);
	}

	/**
	 * Check whether the WordPress uploads directory is writable by PHP.
	 *
	 * @return array<string, string>
	 */
	private function check_uploads_dir_writable(): array {

		// `wp_upload_dir()` returns an array; the `basedir` key is the
		// absolute filesystem path. We test for writability via PHP
		// rather than asking the OS, because PHP's view (after FPM user,
		// group, ACLs, open_basedir) is what actually matters.
		//
		// PHPStan/the WordPress stubs guarantee `basedir` is always
		// present in the returned array, so we cast directly without
		// an isset() guard.
		$uploads_info    = wp_upload_dir();
		$uploads_basedir = (string) $uploads_info['basedir'];

		if ( '' === $uploads_basedir || ! $this->environment->is_dir( $uploads_basedir ) ) {
			return $this->build_row(
				'Filesystem',
				'Uploads directory',
				'(not found)',
				self::STATUS_FAIL,
				'wp_upload_dir() did not return a usable basedir.'
			);
		}

		// `is_writable()` is used deliberately here. The WP_Filesystem
		// abstraction is the right tool for *writes* (it picks the FTP/
		// direct/SSH back-end based on FS_METHOD); for a read-only
		// permission probe inside a CLI command, the native call is the
		// appropriate primitive.
		$is_writable = $this->environment->is_writable( $uploads_basedir );

		return $this->build_row(
			'Filesystem',
			'Uploads directory writable',
			$uploads_basedir,
			$is_writable ? self::STATUS_OK : self::STATUS_FAIL,
			$is_writable ? '' : 'Uploads directory is not writable by PHP. Restores will fail.'
		);
	}

	/**
	 * Check whether WP-Cron is enabled.
	 *
	 * @return array<string, string>
	 */
	private function check_wp_cron_status(): array {

		$is_wp_cron_disabled = $this->environment->is_constant_defined( 'DISABLE_WP_CRON' )
			&& (bool) $this->environment->constant_value( 'DISABLE_WP_CRON' );

		if ( ! $is_wp_cron_disabled ) {
			return $this->build_row(
				'WordPress config',
				'WP-Cron',
				'enabled',
				self::STATUS_OK,
				''
			);
		}

		return $this->build_row(
			'WordPress config',
			'WP-Cron',
			'disabled (DISABLE_WP_CRON = true)',
			self::STATUS_WARN,
			'Background jobs require a system cron pinging wp-cron.php, otherwise migrations stall when the browser closes.'
		);
	}

	/**
	 * Detect whether Action Scheduler is already loaded by another plugin.
	 *
	 * @return array<string, string>
	 */
	private function check_action_scheduler_presence(): array {

		$is_loaded_by_other_plugin = $this->environment->class_exists( 'ActionScheduler', false )
			|| $this->environment->function_exists( 'as_schedule_single_action' );

		return $this->build_row(
			'WordPress config',
			'Action Scheduler',
			$is_loaded_by_other_plugin ? 'loaded by another plugin' : 'not loaded yet',
			self::STATUS_INFO,
			'Pontifex does not yet bundle Action Scheduler. Phase 1 will.'
		);
	}

	// -------------------------------------------------------------------------
	// Helpers.
	// -------------------------------------------------------------------------

	/**
	 * Build a single check row in the canonical shape.
	 *
	 * Centralising this guarantees every check produces the exact same
	 * keys in the exact same order — important because the formatter
	 * uses the first row's keys to define the table columns.
	 *
	 * @param string $category Group label for the row (e.g. 'Runtime', 'PHP config').
	 * @param string $name     Short check name displayed in the table.
	 * @param string $value    The observed value being reported.
	 * @param string $status   One of the STATUS_* constants on this class.
	 * @param string $note     Optional human-readable note explaining the value or implication.
	 * @return array<string, string>
	 */
	private function build_row( string $category, string $name, string $value, string $status, string $note ): array {
		return array(
			'category' => $category,
			'name'     => $name,
			'value'    => $value,
			'status'   => $status,
			'note'     => $note,
		);
	}

	/**
	 * Print a one-line summary counting the rows by status.
	 *
	 * Also halts WP-CLI with exit code 1 if any FAIL rows are present,
	 * so CI and shell scripts can detect failure via the exit code.
	 *
	 * @param array<int, array<string, string>> $check_rows All collected rows from the run.
	 */
	private function print_summary( array $check_rows ): void {

		$status_counts = array(
			self::STATUS_OK   => 0,
			self::STATUS_WARN => 0,
			self::STATUS_FAIL => 0,
			self::STATUS_INFO => 0,
		);

		foreach ( $check_rows as $row ) {
			$row_status = $row['status'] ?? self::STATUS_INFO;
			if ( isset( $status_counts[ $row_status ] ) ) {
				++$status_counts[ $row_status ];
			}
		}

		WP_CLI::log( '' );
		WP_CLI::log(
			sprintf(
				'Summary: %d OK, %d warning(s), %d failure(s), %d informational.',
				$status_counts[ self::STATUS_OK ],
				$status_counts[ self::STATUS_WARN ],
				$status_counts[ self::STATUS_FAIL ],
				$status_counts[ self::STATUS_INFO ]
			)
		);

		// If any FAIL exists, exit non-zero so CI scripts can detect it.
		if ( $status_counts[ self::STATUS_FAIL ] > 0 ) {
			WP_CLI::halt( 1 );
		}
	}
}
