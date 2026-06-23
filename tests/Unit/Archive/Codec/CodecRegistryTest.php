<?php
/**
 * Behavioural tests for CodecRegistry.
 *
 * @package Pontifex\Tests\Unit\Archive\Codec
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Archive\Codec;

use PHPUnit\Framework\TestCase;
use Pontifex\Archive\Codec\Codec;
use Pontifex\Archive\Codec\CodecException;
use Pontifex\Archive\Codec\CodecRegistry;
use Pontifex\Archive\Codec\GzipCodec;
use Pontifex\Archive\Codec\RawCodec;
use Pontifex\Archive\Codec\ZstdCodec;

/**
 * Behavioural tests for the CodecRegistry class.
 *
 * Verifies the registry's invariants:
 *
 *  - Empty registry has no codecs and rejects lookups.
 *  - Registered codecs can be retrieved by ID.
 *  - has() reflects registration state correctly.
 *  - Duplicate registration raises CodecException.
 *  - Looking up an unknown ID raises CodecException.
 *  - with_defaults() returns a registry containing RawCodec
 *    (0x0000), GzipCodec (0x0001), and ZstdCodec (0x0002), and forwards a custom gzip
 *    chunk size when supplied.
 */
final class CodecRegistryTest extends TestCase {

	/**
	 * Helper: produce a minimal Codec stub with the given ID.
	 *
	 * The stub's encode and decode methods are no-ops. The stub is used
	 * to drive registry behaviour in isolation from real codec
	 * implementations, so registry tests do not depend on the concrete
	 * codecs working correctly.
	 *
	 * @param int $id The codec ID the stub will report.
	 * @return Codec A Codec instance reporting the given ID.
	 */
	private function stub_codec( int $id ): Codec {
		return new class( $id ) implements Codec {
			/**
			 * The codec ID this stub reports.
			 *
			 * @var int
			 */
			private int $stub_id;

			/**
			 * Construct a stub codec reporting the given ID.
			 *
			 * @param int $id The codec ID to report.
			 */
			public function __construct( int $id ) {
				$this->stub_id = $id;
			}

			/**
			 * Return the stub's codec ID.
			 *
			 * @return int The configured stub ID.
			 */
			public function id(): int {
				return $this->stub_id;
			}

			/**
			 * No-op encode for stub purposes.
			 *
			 * @param resource $input  A readable stream resource.
			 * @param resource $output A writable stream resource.
			 * @return int Always zero.
			 */
			public function encode( $input, $output ): int {
				return 0;
			}

			/**
			 * No-op decode for stub purposes.
			 *
			 * @param resource $input            A readable stream resource.
			 * @param resource $output           A writable stream resource.
			 * @param int|null $max_output_bytes Ignored by this stub.
			 * @return int Always zero.
			 */
			public function decode( $input, $output, ?int $max_output_bytes = null ): int {
				return 0;
			}
		};
	}

	/**
	 * An empty registry must report has() false for any ID.
	 *
	 * @return void
	 */
	public function test_empty_registry_has_no_codecs(): void {
		$registry = new CodecRegistry();

		$this->assertFalse( $registry->has( 0x0000 ) );
		$this->assertFalse( $registry->has( 0x0001 ) );
		$this->assertFalse( $registry->has( 0xFFFF ) );
	}

	/**
	 * Calling get() on an empty registry must raise CodecException.
	 *
	 * @return void
	 */
	public function test_get_throws_on_unknown_id(): void {
		$this->expectException( CodecException::class );

		$registry = new CodecRegistry();
		$registry->get( 0x0042 );
	}

	/**
	 * Registering a codec must make has() return true for that ID.
	 *
	 * @return void
	 */
	public function test_register_adds_codec(): void {
		$registry = new CodecRegistry();
		$registry->register( $this->stub_codec( 0x0042 ) );

		$this->assertTrue( $registry->has( 0x0042 ) );
	}

	/**
	 * Looking up a codec by ID must return the exact instance that was registered.
	 *
	 * @return void
	 */
	public function test_get_returns_registered_instance(): void {
		$registry = new CodecRegistry();
		$codec    = $this->stub_codec( 0x0042 );
		$registry->register( $codec );

		$this->assertSame( $codec, $registry->get( 0x0042 ) );
	}

	/**
	 * Repeated get() calls must return the same instance every time.
	 *
	 * @return void
	 */
	public function test_get_is_idempotent(): void {
		$registry = new CodecRegistry();
		$registry->register( $this->stub_codec( 0x0042 ) );

		$this->assertSame( $registry->get( 0x0042 ), $registry->get( 0x0042 ) );
	}

	/**
	 * Registering two codecs under the same ID must raise CodecException.
	 *
	 * @return void
	 */
	public function test_register_throws_on_duplicate_id(): void {
		$registry = new CodecRegistry();
		$registry->register( $this->stub_codec( 0x0042 ) );

		$this->expectException( CodecException::class );

		$registry->register( $this->stub_codec( 0x0042 ) );
	}

	/**
	 * The with_defaults() factory must register the baseline codecs.
	 *
	 * @return void
	 */
	public function test_with_defaults_registers_baseline_codecs(): void {
		$registry = CodecRegistry::with_defaults();

		$this->assertTrue( $registry->has( RawCodec::ID ) );
		$this->assertTrue( $registry->has( GzipCodec::ID ) );
		$this->assertTrue( $registry->has( ZstdCodec::ID ) );
		$this->assertInstanceOf( RawCodec::class, $registry->get( RawCodec::ID ) );
		$this->assertInstanceOf( GzipCodec::class, $registry->get( GzipCodec::ID ) );
		$this->assertInstanceOf( ZstdCodec::class, $registry->get( ZstdCodec::ID ) );
	}

	/**
	 * The with_defaults() factory must not register the encrypted codecs yet.
	 *
	 * Locks the current codec set into a test that future changes will trip
	 * over. When an encrypted codec is added to with_defaults(), this test
	 * breaks and demands deliberate acknowledgement.
	 *
	 * @return void
	 */
	public function test_with_defaults_does_not_register_encrypted_codecs(): void {
		$registry = CodecRegistry::with_defaults();

		// Encryption codec IDs reserved by the spec but not yet implemented.
		$this->assertFalse( $registry->has( 0x0100 ), 'encrypted raw (0x0100) not yet implemented' );
		$this->assertFalse( $registry->has( 0x0101 ), 'encrypted gzip (0x0101) not yet implemented' );
		$this->assertFalse( $registry->has( 0x0102 ), 'encrypted zstd (0x0102) not yet implemented' );
	}

	/**
	 * The with_defaults() factory must forward a custom gzip chunk size to the GzipCodec it constructs.
	 *
	 * Verifies the chunk-size override path end-to-end: a value passed
	 * to with_defaults() must reach the GzipCodec constructor. An
	 * out-of-range value must therefore raise the same exception
	 * GzipCodec would raise directly.
	 *
	 * @return void
	 */
	public function test_with_defaults_forwards_invalid_gzip_chunk_size(): void {
		$this->expectException( CodecException::class );

		CodecRegistry::with_defaults( GzipCodec::MAX_CHUNK_SIZE + 1 );
	}

	/**
	 * Calling with_defaults() with an explicit in-range chunk size must succeed.
	 *
	 * @return void
	 */
	public function test_with_defaults_accepts_explicit_gzip_chunk_size(): void {
		$registry = CodecRegistry::with_defaults( 65536 );

		$this->assertTrue( $registry->has( GzipCodec::ID ) );
		$this->assertInstanceOf( GzipCodec::class, $registry->get( GzipCodec::ID ) );
	}
}
