<?php
/**
 * Structural tests for the RealWordPressContext class.
 *
 * @package Pontifex\Tests\Unit\WordPress
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\WordPress;

use PHPUnit\Framework\TestCase;
use Pontifex\WordPress\RealWordPressContext;
use Pontifex\WordPress\WordPressContext;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Asserts the structural invariants of RealWordPressContext.
 *
 * RealWordPressContext is a transparent passthrough to WordPress
 * functions and globals; it cannot meaningfully be unit-tested
 * without WordPress loaded. These tests verify the structural
 * contract instead: the class exists, is final, implements the
 * WordPressContext interface, and every interface method is
 * declared with the expected return type.
 *
 * Behavioural verification — that the class actually returns the
 * value WordPress would return — happens via integration tests
 * (Phase 5) when WordPress is genuinely loaded. Until then, the
 * structural contract is what we can verify in unit context.
 */
final class RealWordPressContextTest extends TestCase {

	/**
	 * The RealWordPressContext class must be present and loadable via PSR-4.
	 *
	 * @return void
	 */
	public function test_class_exists(): void {
		$this->assertTrue( class_exists( RealWordPressContext::class ) );
	}

	/**
	 * RealWordPressContext must be marked final to prevent extension.
	 *
	 * The class is a transparent seam to WordPress and has no
	 * extension points. Loosening this requires deliberate review.
	 *
	 * @return void
	 */
	public function test_class_is_final(): void {
		$reflection = new ReflectionClass( RealWordPressContext::class );
		$this->assertTrue(
			$reflection->isFinal(),
			'RealWordPressContext is marked final; loosening this requires deliberate review.'
		);
	}

	/**
	 * RealWordPressContext must implement the WordPressContext interface.
	 *
	 * @return void
	 */
	public function test_implements_interface(): void {
		$reflection = new ReflectionClass( RealWordPressContext::class );
		$this->assertTrue(
			$reflection->implementsInterface( WordPressContext::class ),
			'RealWordPressContext must implement WordPressContext.'
		);
	}

	/**
	 * Every method declared by WordPressContext must be present on RealWordPressContext.
	 *
	 * Catches the case where an interface method is added but the
	 * implementation is forgotten — a runtime fatal that this
	 * structural check turns into a clear test failure.
	 *
	 * @return void
	 */
	public function test_every_interface_method_is_implemented(): void {
		$interface_reflection      = new ReflectionClass( WordPressContext::class );
		$implementation_reflection = new ReflectionClass( RealWordPressContext::class );

		foreach ( $interface_reflection->getMethods() as $interface_method ) {
			$this->assertTrue(
				$implementation_reflection->hasMethod( $interface_method->getName() ),
				sprintf(
					'RealWordPressContext is missing the %s() method declared by WordPressContext.',
					$interface_method->getName()
				)
			);
		}
	}

	/**
	 * Every public method on RealWordPressContext must declare an explicit return type.
	 *
	 * Catches accidental drift where a method's return type is
	 * removed or forgotten — important because callers rely on
	 * declared types for static analysis.
	 *
	 * @return void
	 */
	public function test_every_public_method_declares_a_return_type(): void {
		$reflection = new ReflectionClass( RealWordPressContext::class );

		foreach ( $reflection->getMethods( ReflectionMethod::IS_PUBLIC ) as $method ) {
			// Skip the magic methods (none currently, but defensive against future additions).
			if ( 0 === strpos( $method->getName(), '__' ) ) {
				continue;
			}
			$return_type = $method->getReturnType();
			$this->assertInstanceOf(
				ReflectionNamedType::class,
				$return_type,
				sprintf(
					'RealWordPressContext::%s() must declare an explicit return type.',
					$method->getName()
				)
			);
		}
	}

	/**
	 * The wpdb_instance method must declare wpdb as its return type.
	 *
	 * Specific check because this is the one method that returns a
	 * class type rather than a scalar; static analysis depends on
	 * the declared type being exactly "wpdb".
	 *
	 * @return void
	 */
	public function test_wpdb_instance_returns_wpdb(): void {
		$method      = new ReflectionMethod( RealWordPressContext::class, 'wpdb_instance' );
		$return_type = $method->getReturnType();

		$this->assertInstanceOf( ReflectionNamedType::class, $return_type );
		$this->assertSame( 'wpdb', $return_type->getName() );
	}

	/**
	 * The serialised_classes_allowlist method must declare array as its return type.
	 *
	 * The allowlist is always a list of class-name strings, never a bool or
	 * other type, so the migration replacer can be constructed from it
	 * directly and a misbehaving filter cannot widen it to "all classes". An
	 * empty list means no classes are permitted.
	 *
	 * @return void
	 */
	public function test_serialised_classes_allowlist_returns_array(): void {
		$method      = new ReflectionMethod( RealWordPressContext::class, 'serialised_classes_allowlist' );
		$return_type = $method->getReturnType();

		$this->assertInstanceOf( ReflectionNamedType::class, $return_type );
		$this->assertSame( 'array', $return_type->getName() );
	}
}
