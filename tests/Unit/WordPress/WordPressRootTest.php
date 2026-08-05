<?php
/**
 * Tests for WordPressRoot — the shared installation-root derivation.
 *
 * @package Pontifex\Tests\Unit\WordPress
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\WordPress;

use Mockery;
use Pontifex\Environment\Environment;
use Pontifex\Exception\HostCannotComply;
use Pontifex\Tests\TestCase;
use Pontifex\WordPress\WordPressRoot;

/**
 * Covers the derivation six classes used to each keep their own copy of.
 *
 * This is the destination root a restore confines every write against, so every
 * path check downstream is relative to it. Six byte-identical copies lived in
 * three CLI commands and three admin controllers. Nothing had gone wrong with
 * them — they still hashed identically when this was written — but nothing
 * would have noticed if one had drifted, and a surface confining against a
 * different directory than its siblings is the kind of divergence that is
 * invisible until it matters.
 */
final class WordPressRootTest extends TestCase {

	/**
	 * The trailing slash ABSPATH carries by convention is removed.
	 *
	 * Callers append `/` plus a relative path, and confinement is a string
	 * prefix comparison, so a doubled separator would be a real defect rather
	 * than cosmetic.
	 *
	 * @return void
	 */
	public function test_the_trailing_slash_is_removed(): void {
		$environment = Mockery::mock( Environment::class );
		$environment->shouldReceive( 'is_constant_defined' )->with( 'ABSPATH' )->andReturn( true );
		$environment->shouldReceive( 'constant_value' )->with( 'ABSPATH' )->andReturn( '/var/www/html/' );

		$this->assertSame( '/var/www/html', WordPressRoot::resolve( $environment ) );
	}

	/**
	 * A root with no trailing slash is returned unchanged.
	 *
	 * @return void
	 */
	public function test_a_root_without_a_trailing_slash_is_unchanged(): void {
		$environment = Mockery::mock( Environment::class );
		$environment->shouldReceive( 'is_constant_defined' )->with( 'ABSPATH' )->andReturn( true );
		$environment->shouldReceive( 'constant_value' )->with( 'ABSPATH' )->andReturn( '/var/www/html' );

		$this->assertSame( '/var/www/html', WordPressRoot::resolve( $environment ) );
	}

	/**
	 * Without ABSPATH the derivation refuses rather than returning something.
	 *
	 * Returning an empty string here would make every downstream confinement
	 * check compare against the filesystem root, which is the worst possible
	 * failure mode for the value this is.
	 *
	 * @return void
	 */
	public function test_a_missing_abspath_is_refused(): void {
		$environment = Mockery::mock( Environment::class );
		$environment->shouldReceive( 'is_constant_defined' )->with( 'ABSPATH' )->andReturn( false );

		$this->expectException( HostCannotComply::class );
		$this->expectExceptionMessage( 'ABSPATH is not defined' );

		WordPressRoot::resolve( $environment );
	}
}
