<?php
/**
 * Production Environment implementation.
 *
 * @package Pontifex\Environment
 */

declare(strict_types=1);

namespace Pontifex\Environment;

/**
 * The real Environment Pontifex uses in production.
 *
 * Every method delegates to PHP's built-in function or constant of the
 * same conceptual purpose. The class deliberately has no logic of its
 * own — it is a transparent passthrough to PHP's global state, isolated
 * here so the rest of the codebase can depend on the Environment
 * interface rather than on PHP itself.
 *
 * If a test ever fails because RealEnvironment is doing something
 * surprising, the bug is in PHP, not in this class.
 */
final class RealEnvironment implements Environment {

	/**
	 * Return the PHP version string of the running interpreter.
	 *
	 * @return string e.g. "8.1.29".
	 */
	public function php_version(): string {
		return PHP_VERSION;
	}

	/**
	 * Return whether a named PHP extension is loaded.
	 *
	 * @param string $extension_name e.g. "sodium", "zstd", "openssl".
	 * @return bool
	 */
	public function extension_loaded( string $extension_name ): bool {
		return extension_loaded( $extension_name );
	}

	/**
	 * Return the current value of a php.ini directive as a string.
	 *
	 * Returns an empty string if the directive is not set, matching
	 * PHP's own ini_get() behaviour for the empty case.
	 *
	 * @param string $directive_name e.g. "memory_limit", "max_execution_time".
	 * @return string
	 */
	public function ini_get( string $directive_name ): string {
		$value = ini_get( $directive_name );
		return false === $value ? '' : $value;
	}

	/**
	 * Return free disk space available at the given path, in bytes.
	 *
	 * Returns false if the value cannot be read (typically due to
	 * open_basedir restrictions or insufficient permissions).
	 *
	 * @param string $path Absolute filesystem path.
	 * @return float|false Bytes available, or false on failure.
	 */
	public function disk_free_space( string $path ) {
		return disk_free_space( $path );
	}

	/**
	 * Return whether the given path is writable by the running PHP process.
	 *
	 * @param string $path Absolute filesystem path.
	 * @return bool
	 */
	public function is_writable( string $path ): bool {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- RealEnvironment is the deliberate seam to PHP built-ins; WP_Filesystem is the right tool for writes, not for read-only permission probes.
		return is_writable( $path );
	}

	/**
	 * Return whether the given path is an existing directory.
	 *
	 * @param string $path Absolute filesystem path.
	 * @return bool
	 */
	public function is_dir( string $path ): bool {
		return is_dir( $path );
	}

	/**
	 * Return whether a named PHP constant is currently defined.
	 *
	 * @param string $constant_name e.g. "DISABLE_WP_CRON".
	 * @return bool
	 */
	public function is_constant_defined( string $constant_name ): bool {
		return defined( $constant_name );
	}

	/**
	 * Return the value of a named PHP constant, if defined.
	 *
	 * Returns null when the constant is not defined. Callers should
	 * guard with is_constant_defined() first when the absence of the
	 * constant is semantically distinct from a null value.
	 *
	 * @param string $constant_name e.g. "DISABLE_WP_CRON", "WP_CONTENT_DIR".
	 * @return mixed
	 */
	public function constant_value( string $constant_name ) {
		return defined( $constant_name ) ? constant( $constant_name ) : null;
	}

	/**
	 * Return whether a class exists in the running PHP process.
	 *
	 * @param string $class_name              Fully-qualified class name.
	 * @param bool   $should_trigger_autoload Whether to try autoloading. Default false to match the existing call site's intent.
	 * @return bool
	 */
	public function class_exists( string $class_name, bool $should_trigger_autoload = false ): bool {
		return class_exists( $class_name, $should_trigger_autoload );
	}

	/**
	 * Return whether a function exists in the running PHP process.
	 *
	 * @param string $function_name e.g. "as_schedule_single_action".
	 * @return bool
	 */
	public function function_exists( string $function_name ): bool {
		return function_exists( $function_name );
	}
}
