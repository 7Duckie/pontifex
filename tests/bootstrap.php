<?php
/**
 * PHPUnit bootstrap.
 *
 * Pure-unit tests in tests/Unit/ run via this bootstrap. No WordPress
 * runtime is loaded; tests must not assume any WordPress global state,
 * function, or class is available.
 *
 * Integration tests in tests/Integration/ will eventually use a separate
 * bootstrap (wp-phpunit-style) once the WP test suite is wired in.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Minimal constants some unit tests may need to reference safely.
// These are deliberately not a substitute for a real WP environment.
if (!defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir() . '/');
}
