<?php
/**
 * Smoke test proving the integration rig boots a real WordPress.
 *
 * Extends the PHPUnit Polyfills TestCase rather than WP_UnitTestCase.
 * The WordPress base class calls PHPUnit\Util\Test::parseTestMethodAnnotations()
 * during its per-test set-up, and that method was removed in PHPUnit 10
 * and is still gone in 11 (WordPress core has not yet caught up). The
 * WordPress runtime itself loads fine under PHPUnit 11 — only that base
 * class is incompatible — so the integration bootstrap still boots a real
 * WordPress, and we call WordPress functions and the database directly.
 *
 * Because we are not using WP_UnitTestCase, there is no automatic per-test
 * rollback, so anything written to the database is cleaned up in tear-down.
 * That is no real loss here: import performs table-level DDL, which causes
 * implicit commits in MySQL and defeats transactional rollback anyway.
 *
 * @package Pontifex\Tests\Integration
 */

declare(strict_types=1);

namespace Pontifex\Tests\Integration;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Verifies that the integration test environment boots a usable WordPress.
 */
final class LoadsWordPressTest extends TestCase {

	/**
	 * Post IDs created during a test, removed in tear-down so the test
	 * database is left as it was found.
	 *
	 * @var array<int>
	 */
	private array $created_posts = array();

	/**
	 * Remove any posts created during the test.
	 */
	protected function tear_down(): void {
		foreach ( $this->created_posts as $post_id ) {
			wp_delete_post( $post_id, true );
		}

		$this->created_posts = array();

		parent::tear_down();
	}

	/**
	 * WordPress core functions should be loaded in the integration suite.
	 */
	public function test_wordpress_core_is_loaded(): void {
		$this->assertTrue(
			function_exists( 'wp_insert_post' ),
			'WordPress core functions should be available inside the integration suite.'
		);
	}

	/**
	 * A post written to the database should read back unchanged.
	 */
	public function test_database_round_trips_a_post(): void {
		$title = 'Pontifex integration smoke test';

		$post_id = wp_insert_post(
			array(
				'post_title'  => $title,
				'post_status' => 'publish',
			),
			true
		);

		$this->assertIsInt( $post_id, 'wp_insert_post should return an integer post ID.' );

		$this->created_posts[] = $post_id;

		$this->assertSame( $title, get_post( $post_id )->post_title );
	}

	/**
	 * Pontifex itself should have loaded into the test WordPress.
	 */
	public function test_pontifex_loaded_into_the_test_site(): void {
		$this->assertTrue(
			defined( 'PONTIFEX_VERSION' ),
			'Pontifex should have loaded into the test WordPress via muplugins_loaded.'
		);
	}
}
