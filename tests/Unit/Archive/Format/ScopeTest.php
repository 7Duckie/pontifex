<?php
/**
 * Behavioural tests for the Scope value object.
 *
 * @package Pontifex\Tests\Unit\Archive\Format
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Archive\Format;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pontifex\Archive\Format\Scope;

/**
 * Behavioural tests for the Scope class.
 *
 * Verifies that the value object exposes its six fields, encodes to a
 * fixed-order array for deterministic JSON, decodes back through from_array(),
 * and rejects malformed decoded input.
 */
final class ScopeTest extends TestCase {

	/**
	 * Build a content-only scope fixture.
	 *
	 * @return Scope A content-only Scope with known values.
	 */
	private function content_only_scope(): Scope {
		return new Scope( true, 'wp-content', false, false, true, array( 'wp-content/pontifex/**', 'wp-content/cache/**' ) );
	}

	/**
	 * The accessors must return the values passed to the constructor.
	 *
	 * @return void
	 */
	public function test_accessors_return_constructor_values(): void {
		$scope = $this->content_only_scope();

		$this->assertTrue( $scope->is_content_only() );
		$this->assertSame( 'wp-content', $scope->content_root() );
		$this->assertFalse( $scope->includes_core() );
		$this->assertFalse( $scope->includes_wp_config() );
		$this->assertTrue( $scope->includes_database() );
		$this->assertSame( array( 'wp-content/pontifex/**', 'wp-content/cache/**' ), $scope->excluded_paths() );
	}

	/**
	 * A whole-site scope records core/config inclusion and an empty content root.
	 *
	 * @return void
	 */
	public function test_whole_site_scope_records_core_and_config(): void {
		$scope = new Scope( false, '', true, true, true, array() );

		$this->assertFalse( $scope->is_content_only() );
		$this->assertSame( '', $scope->content_root() );
		$this->assertTrue( $scope->includes_core() );
		$this->assertTrue( $scope->includes_wp_config() );
		$this->assertSame( array(), $scope->excluded_paths() );
	}

	/**
	 * The content_only() factory must fix the content-only facts.
	 *
	 * Records content-only true, a "wp-content" content root, core and wp-config.php
	 * excluded, the database included, and the supplied exclusion patterns.
	 *
	 * @return void
	 */
	public function test_content_only_factory_fixes_the_content_only_facts(): void {
		$scope = Scope::content_only( array( 'wp-content/cache/**' ) );

		$this->assertTrue( $scope->is_content_only() );
		$this->assertSame( 'wp-content', $scope->content_root() );
		$this->assertFalse( $scope->includes_core() );
		$this->assertFalse( $scope->includes_wp_config() );
		$this->assertTrue( $scope->includes_database() );
		$this->assertSame( array( 'wp-content/cache/**' ), $scope->excluded_paths() );
	}

	/**
	 * The whole_site() factory must fix the whole-site facts.
	 *
	 * Records content-only false, an empty content root (entries rooted at the site
	 * root), core and wp-config.php included, the database included, and the supplied
	 * exclusion patterns.
	 *
	 * @return void
	 */
	public function test_whole_site_factory_fixes_the_whole_site_facts(): void {
		$scope = Scope::whole_site( array( 'wp-content/cache/**' ) );

		$this->assertFalse( $scope->is_content_only() );
		$this->assertSame( '', $scope->content_root() );
		$this->assertTrue( $scope->includes_core() );
		$this->assertTrue( $scope->includes_wp_config() );
		$this->assertTrue( $scope->includes_database() );
		$this->assertSame( array( 'wp-content/cache/**' ), $scope->excluded_paths() );
	}

	/**
	 * Encoding via to_array() must emit the six fields in a fixed order for deterministic JSON.
	 *
	 * @return void
	 */
	public function test_to_array_uses_fixed_field_order(): void {
		$this->assertSame(
			array(
				'content_only'       => true,
				'content_root'       => 'wp-content',
				'includes_core'      => false,
				'includes_wp_config' => false,
				'includes_database'  => true,
				'excluded_paths'     => array( 'wp-content/pontifex/**', 'wp-content/cache/**' ),
			),
			$this->content_only_scope()->to_array()
		);
	}

	/**
	 * Round-tripping through from_array() must reconstruct an equivalent Scope.
	 *
	 * @return void
	 */
	public function test_from_array_round_trips_to_array(): void {
		$original = $this->content_only_scope();
		$restored = Scope::from_array( $original->to_array() );

		$this->assertSame( $original->to_array(), $restored->to_array() );
	}

	/**
	 * The constructor must reject a non-string element in excluded_paths.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_non_string_excluded_path(): void {
		$this->expectException( InvalidArgumentException::class );

		// @phpstan-ignore-next-line -- deliberately passing a bad type to assert the guard.
		new Scope( true, 'wp-content', false, false, true, array( 'ok', 123 ) );
	}

	/**
	 * Decoding must reject a missing boolean field.
	 *
	 * @return void
	 */
	public function test_from_array_rejects_missing_boolean_field(): void {
		$data = $this->content_only_scope()->to_array();
		unset( $data['includes_core'] );

		$this->expectException( InvalidArgumentException::class );
		Scope::from_array( $data );
	}

	/**
	 * Decoding must reject a non-boolean where a boolean is required.
	 *
	 * @return void
	 */
	public function test_from_array_rejects_non_boolean_field(): void {
		$data                 = $this->content_only_scope()->to_array();
		$data['content_only'] = 'yes';

		$this->expectException( InvalidArgumentException::class );
		Scope::from_array( $data );
	}

	/**
	 * Decoding must reject a non-string content_root.
	 *
	 * @return void
	 */
	public function test_from_array_rejects_non_string_content_root(): void {
		$data                 = $this->content_only_scope()->to_array();
		$data['content_root'] = 5;

		$this->expectException( InvalidArgumentException::class );
		Scope::from_array( $data );
	}

	/**
	 * Decoding must reject excluded_paths that is not an array.
	 *
	 * @return void
	 */
	public function test_from_array_rejects_non_array_excluded_paths(): void {
		$data                   = $this->content_only_scope()->to_array();
		$data['excluded_paths'] = 'not-an-array';

		$this->expectException( InvalidArgumentException::class );
		Scope::from_array( $data );
	}
}
