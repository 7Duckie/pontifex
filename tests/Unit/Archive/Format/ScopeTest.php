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

	// -------------------------------------------------------------------------
	// Partial scopes (files-only / db-only), ADR 0016.
	// -------------------------------------------------------------------------

	/**
	 * A files-only scope stays content-only but records the database absent.
	 *
	 * @return void
	 */
	public function test_files_only_factory(): void {
		$scope = Scope::files_only( array( '*.log' ) );

		$this->assertTrue( $scope->is_content_only(), 'A files-only backup is still a content archive.' );
		$this->assertFalse( $scope->includes_database(), 'A files-only backup omits the database.' );
		$this->assertTrue( $scope->includes_files(), 'A files-only backup carries files.' );
		$this->assertSame( 'wp-content', $scope->content_root() );
	}

	/**
	 * A db-only scope stays content-only but records the files absent.
	 *
	 * @return void
	 */
	public function test_db_only_factory(): void {
		$scope = Scope::db_only( array() );

		$this->assertTrue( $scope->is_content_only() );
		$this->assertTrue( $scope->includes_database(), 'A db-only backup carries the database.' );
		$this->assertFalse( $scope->includes_files(), 'A db-only backup omits files.' );
	}

	/**
	 * The includes_files field is serialised only when false, so ordinary archives stay byte-identical.
	 *
	 * This is the byte-stability contract: content-only, whole-site, and
	 * files-only archives (all includes_files=true) must NOT carry the new key,
	 * so their JSON — and the golden conformance archive — are unchanged. Only a
	 * db-only archive carries it.
	 *
	 * @return void
	 */
	public function test_includes_files_is_emitted_only_when_false(): void {
		$this->assertArrayNotHasKey( 'includes_files', Scope::content_only( array() )->to_array(), 'A content archive does not carry the new key.' );
		$this->assertArrayNotHasKey( 'includes_files', Scope::whole_site( array() )->to_array(), 'A whole-site archive does not carry the new key.' );
		$this->assertArrayNotHasKey( 'includes_files', Scope::files_only( array() )->to_array(), 'A files-only archive (files present) does not carry the new key.' );

		$db_only = Scope::db_only( array() )->to_array();
		$this->assertArrayHasKey( 'includes_files', $db_only, 'A db-only archive carries the new key.' );
		$this->assertFalse( $db_only['includes_files'] );
	}

	/**
	 * Reading a scope with no includes_files key defaults it to true (back-compat).
	 *
	 * Every archive shipped before this field existed has no includes_files key,
	 * and must decode as carrying files.
	 *
	 * @return void
	 */
	public function test_from_array_defaults_includes_files_true_when_absent(): void {
		$data = Scope::content_only( array() )->to_array();
		$this->assertArrayNotHasKey( 'includes_files', $data );

		$this->assertTrue( Scope::from_array( $data )->includes_files(), 'An absent key decodes as files present.' );
	}

	/**
	 * A db-only scope round-trips through to_array and from_array.
	 *
	 * @return void
	 */
	public function test_db_only_round_trips(): void {
		$scope    = Scope::db_only( array( 'wp_actionscheduler_logs' ) );
		$restored = Scope::from_array( $scope->to_array() );

		$this->assertFalse( $restored->includes_files() );
		$this->assertTrue( $restored->includes_database() );
		$this->assertSame( array( 'wp_actionscheduler_logs' ), $restored->excluded_paths() );
	}

	/**
	 * Reading a non-boolean includes_files is rejected.
	 *
	 * @return void
	 */
	public function test_from_array_rejects_non_boolean_includes_files(): void {
		$data                   = Scope::content_only( array() )->to_array();
		$data['includes_files'] = 'yes';

		$this->expectException( InvalidArgumentException::class );
		Scope::from_array( $data );
	}

	/**
	 * A scope with neither files nor the database is refused — an archive of nothing.
	 *
	 * @return void
	 */
	public function test_a_scope_of_nothing_is_refused(): void {
		$this->expectException( InvalidArgumentException::class );
		new Scope( true, 'wp-content', false, false, false, array(), false );
	}

	/**
	 * The content_summary_key classifies each of the four shapes distinctly.
	 *
	 * The single source of truth for the verify verdict's wording, so the branch
	 * order is pinned here rather than re-derived in each surface.
	 *
	 * @return void
	 */
	public function test_content_summary_key_classifies_each_shape(): void {
		$this->assertSame( Scope::SUMMARY_CONTENT, Scope::content_only( array() )->content_summary_key() );
		$this->assertSame( Scope::SUMMARY_WHOLE_SITE, Scope::whole_site( array() )->content_summary_key() );
		$this->assertSame( Scope::SUMMARY_FILES_ONLY, Scope::files_only( array() )->content_summary_key() );
		$this->assertSame( Scope::SUMMARY_DB_ONLY, Scope::db_only( array() )->content_summary_key() );
	}
}
