<?php
/**
 * Environment abstraction interface.
 *
 * @package Pontifex\Environment
 */

declare(strict_types=1);

namespace Pontifex\Environment;

/**
 * Abstracts the PHP runtime and filesystem reads that Pontifex performs.
 *
 * Pontifex's job is to interact with WordPress hosts of widely varying
 * configuration: different PHP versions, different extension sets,
 * different filesystem semantics. The decisions Pontifex makes during a
 * doctor sweep, an export, or an import all depend on questions like
 * "what version of PHP is this?", "is sodium loaded?", "is this path
 * writable?". Calling PHP's built-ins directly inside business logic
 * couples that logic to global state, making it impossible to test in
 * isolation and impossible to substitute (e.g. to add caching or
 * logging) without invasive change.
 *
 * This interface declares the questions Pontifex needs to ask. A real
 * implementation (RealEnvironment) delegates to PHP's built-ins; a test
 * implementation answers with whatever values the test specifies.
 * Production code accepts an Environment via the constructor and never
 * touches PHP_VERSION, ini_get(), extension_loaded(), or the filesystem
 * functions directly.
 *
 * New methods may be added as new checks require them. Methods are
 * named for the question they answer, not for the PHP function they
 * happen to wrap.
 */
interface Environment {

	/**
	 * The PHP version string of the running interpreter.
	 *
	 * Equivalent to the PHP_VERSION constant, but readable through the
	 * interface so it can be stubbed in tests.
	 *
	 * @return string e.g. "8.1.29".
	 */
	public function php_version(): string;

	/**
	 * Whether a named PHP extension is loaded.
	 *
	 * @param string $extension_name e.g. "sodium", "zstd", "openssl".
	 * @return bool
	 */
	public function extension_loaded( string $extension_name ): bool;

	/**
	 * The current value of a php.ini directive, as a string.
	 *
	 * Returns an empty string if the directive is not set, matching
	 * PHP's own ini_get() behaviour for the empty case.
	 *
	 * @param string $directive_name e.g. "memory_limit", "max_execution_time".
	 * @return string
	 */
	public function ini_get( string $directive_name ): string;

	/**
	 * Free disk space available at the given path, in bytes.
	 *
	 * Returns false if the value cannot be read (typically due to
	 * open_basedir restrictions or insufficient permissions).
	 *
	 * @param string $path Absolute filesystem path.
	 * @return float|false Bytes available, or false on failure.
	 */
	public function disk_free_space( string $path );

	/**
	 * Whether the given path is writable by the running PHP process.
	 *
	 * @param string $path Absolute filesystem path.
	 * @return bool
	 */
	public function is_writable( string $path ): bool;

	/**
	 * Whether the given path is an existing directory.
	 *
	 * @param string $path Absolute filesystem path.
	 * @return bool
	 */
	public function is_dir( string $path ): bool;

	/**
	 * Whether a named PHP constant is currently defined.
	 *
	 * @param string $constant_name e.g. "DISABLE_WP_CRON".
	 * @return bool
	 */
	public function is_constant_defined( string $constant_name ): bool;

	/**
	 * The value of a named PHP constant, if defined.
	 *
	 * Returns null when the constant is not defined. Callers should
	 * guard with is_constant_defined() first when the absence of the
	 * constant is semantically distinct from a null value.
	 *
	 * @param string $constant_name e.g. "DISABLE_WP_CRON", "WP_CONTENT_DIR".
	 * @return mixed
	 */
	public function constant_value( string $constant_name );

	/**
	 * Whether a class exists (with or without triggering autoload).
	 *
	 * Used for detecting bundled dependencies that other plugins may
	 * have loaded into the runtime, e.g. ActionScheduler.
	 *
	 * @param string $class_name           Fully-qualified class name.
	 * @param bool   $should_trigger_autoload Whether to try autoloading. Default false to match the existing call site's intent.
	 * @return bool
	 */
	public function class_exists( string $class_name, bool $should_trigger_autoload = false ): bool;

	/**
	 * Whether a function exists in the running PHP process.
	 *
	 * @param string $function_name e.g. "as_schedule_single_action".
	 * @return bool
	 */
	public function function_exists( string $function_name ): bool;
}
