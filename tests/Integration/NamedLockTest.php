<?php
/**
 * Integration test: the site-scoped named database lock over a real MySQL server.
 *
 * @package Pontifex\Tests\Integration
 */

declare(strict_types=1);

namespace Pontifex\Tests\Integration;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use Pontifex\WordPress\RealWordPressContext;
use wpdb;

/**
 * Proves the named lock is genuinely mutually exclusive across connections.
 *
 * The unit suite can only prove structure here: GET_LOCK is re-entrant on a
 * single connection, so mutual exclusion — the property the admin single-runner
 * lock exists for — is only observable between two real connections to a real
 * server. This test opens a second wpdb connection and swaps it into the
 * global, so both sides of the contest run through RealWordPressContext itself,
 * the same code the admin controllers use:
 *
 *  - a lock held by one connection is refused to the other, atomically;
 *  - a connection that does not hold the lock cannot release it;
 *  - once the holder releases, the other connection can acquire;
 *  - the same logical name under a different table prefix is a different
 *    lock (the site-scoping that keeps shared database servers safe).
 */
final class NamedLockTest extends TestCase {

	/**
	 * The logical lock name used throughout; deliberately test-specific so a
	 * failure can never contend with a real Pontifex operation's lock.
	 *
	 * @var string
	 */
	private const LOCK_NAME = 'pontifex_named_lock_test';

	/**
	 * The second, independent database connection playing the rival request.
	 *
	 * @var wpdb|null
	 */
	private ?wpdb $second_connection = null;

	/**
	 * The WordPress-managed wpdb displaced from the global, restored in tear_down.
	 *
	 * @var wpdb|null
	 */
	private ?wpdb $original_wpdb = null;

	/**
	 * Open the second connection with the same table prefix as the first.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();
		$this->original_wpdb     = $GLOBALS['wpdb'];
		$this->second_connection = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$this->second_connection->set_prefix( $this->original_wpdb->prefix );
	}

	/**
	 * Release the lock on both connections and close the second one.
	 *
	 * Releasing a lock a connection does not hold changes nothing, so this is
	 * safe to run unconditionally — it guarantees a failed assertion cannot
	 * leak a held lock into a later test.
	 *
	 * @return void
	 */
	protected function tear_down(): void {
		$context = new RealWordPressContext();
		if ( null !== $this->original_wpdb ) {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the WordPress-managed connection the test displaced.
			$GLOBALS['wpdb'] = $this->original_wpdb;
			$context->release_named_lock( self::LOCK_NAME );
		}
		if ( null !== $this->second_connection ) {
			$this->on_second_connection(
				function () use ( $context ): void {
					$context->release_named_lock( self::LOCK_NAME );
				}
			);
			$this->second_connection->close();
			$this->second_connection = null;
		}
		parent::tear_down();
	}

	/**
	 * A lock held by one connection is refused to another until released.
	 *
	 * This is the property the admin single-runner lock rests on: the server
	 * grants a named lock to exactly one connection, atomically, so two
	 * simultaneous requests can never both acquire.
	 *
	 * @return void
	 */
	public function test_lock_is_mutually_exclusive_across_connections(): void {
		$context = new RealWordPressContext();

		$this->assertTrue(
			$context->acquire_named_lock( self::LOCK_NAME ),
			'The first connection should acquire a free lock.'
		);

		$this->on_second_connection(
			function () use ( $context ): void {
				$this->assertFalse(
					$context->acquire_named_lock( self::LOCK_NAME ),
					'A second connection must be refused while the first holds the lock.'
				);
			}
		);

		$context->release_named_lock( self::LOCK_NAME );

		$this->on_second_connection(
			function () use ( $context ): void {
				$this->assertTrue(
					$context->acquire_named_lock( self::LOCK_NAME ),
					'The lock must be acquirable again once the holder releases it.'
				);
				$context->release_named_lock( self::LOCK_NAME );
			}
		);
	}

	/**
	 * A connection that does not hold the lock cannot release it.
	 *
	 * The refusal path in the controllers releases unconditionally in cleanup;
	 * this proves such a release can never free a lock a *different* request
	 * is holding — RELEASE_LOCK only ever releases the caller's own lock.
	 *
	 * @return void
	 */
	public function test_release_by_a_non_holder_does_not_free_the_lock(): void {
		$context = new RealWordPressContext();

		$this->assertTrue(
			$context->acquire_named_lock( self::LOCK_NAME ),
			'The first connection should acquire a free lock.'
		);

		$this->on_second_connection(
			function () use ( $context ): void {
				$context->release_named_lock( self::LOCK_NAME );
				$this->assertFalse(
					$context->acquire_named_lock( self::LOCK_NAME ),
					'The first connection must still hold the lock after a non-holder tried to release it.'
				);
			}
		);

		$context->release_named_lock( self::LOCK_NAME );
	}

	/**
	 * The same logical name under a different table prefix is a different lock.
	 *
	 * Named locks are server-wide, so the implementation scopes the name to the
	 * site (database and table prefix). Two sites sharing one database server —
	 * or one database with different prefixes — must never contend for each
	 * other's locks.
	 *
	 * @return void
	 */
	public function test_lock_is_scoped_to_the_table_prefix(): void {
		$context         = new RealWordPressContext();
		$original_prefix = $this->original_wpdb->prefix;

		$this->assertTrue(
			$context->acquire_named_lock( self::LOCK_NAME ),
			'The first connection should acquire a free lock.'
		);

		$this->on_second_connection(
			function () use ( $context, $original_prefix ): void {
				$GLOBALS['wpdb']->set_prefix( 'pfxscope_' );
				try {
					$this->assertTrue(
						$context->acquire_named_lock( self::LOCK_NAME ),
						'The same logical name under a different table prefix must be a different, free lock.'
					);
					$context->release_named_lock( self::LOCK_NAME );
				} finally {
					$GLOBALS['wpdb']->set_prefix( $original_prefix );
				}
			}
		);

		$context->release_named_lock( self::LOCK_NAME );
	}

	/**
	 * Run a callback with the second connection swapped into the wpdb global.
	 *
	 * RealWordPressContext reads the global, so swapping the global is how the
	 * rival request's side of the contest runs through the production code
	 * path. The original connection is always restored, even on a failed
	 * assertion.
	 *
	 * @param callable(): void $callback The steps to run against the second connection.
	 * @return void
	 */
	private function on_second_connection( callable $callback ): void {
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Swapping in the test's second connection so RealWordPressContext runs against it; restored in the finally.
		$GLOBALS['wpdb'] = $this->second_connection;
		try {
			$callback();
		} finally {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the WordPress-managed connection.
			$GLOBALS['wpdb'] = $this->original_wpdb;
		}
	}
}
