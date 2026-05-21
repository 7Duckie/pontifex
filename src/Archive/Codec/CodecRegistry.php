<?php
/**
 * Codec registry mapping codec IDs to Codec instances.
 *
 * @package Pontifex\Archive\Codec
 */

declare(strict_types=1);

namespace Pontifex\Archive\Codec;

/**
 * Lookup table that resolves codec IDs to Codec instances.
 *
 * The archive format stores each entry with a two-byte codec
 * identifier that the reader uses to select the correct decoder. As
 * Pontifex grows from one codec (RawCodec, 0x0000) to several (gzip,
 * zstd, encrypted variants), hard-coding the selection becomes
 * untenable. CodecRegistry is the registration-and-lookup layer that
 * lets the writer and reader work in terms of "the codec for ID X"
 * without needing to know each codec's class up front.
 *
 * Behavioural choices worth noting:
 *
 *  - Duplicate registration raises CodecException. Two different
 *    codecs registered under the same ID would be a programmer
 *    mistake; failing loudly catches the bug, silent overwrite
 *    hides it.
 *  - Looking up an unknown ID raises CodecException. When reading an
 *    archive, an unknown codec means the archive was produced by a
 *    future Pontifex version using a codec we do not yet support, or
 *    the archive is corrupted. Refusing to proceed is the safe
 *    behaviour.
 *  - {@see CodecRegistry::with_defaults()} constructs a registry
 *    pre-populated with the v0.1.0 baseline (RawCodec, GzipCodec).
 *    Tests that need empty or partial registries construct the
 *    registry directly and call register() as required.
 */
final class CodecRegistry {

	/**
	 * Registered codecs, keyed by codec ID.
	 *
	 * @var array<int, Codec>
	 */
	private array $codecs = array();

	/**
	 * Register a codec under its declared ID.
	 *
	 * @param Codec $codec The codec instance to register.
	 * @return void
	 * @throws CodecException If a codec with the same ID is already registered.
	 */
	public function register( Codec $codec ): void {
		$id = $codec->id();
		if ( isset( $this->codecs[ $id ] ) ) {
			throw new CodecException(
				sprintf( 'Codec ID 0x%04X is already registered.', (int) $id )
			);
		}
		$this->codecs[ $id ] = $codec;
	}

	/**
	 * Look up a registered codec by its ID.
	 *
	 * @param int $id The codec ID to resolve.
	 * @return Codec The registered codec instance.
	 * @throws CodecException If no codec is registered for the given ID.
	 */
	public function get( int $id ): Codec {
		if ( ! isset( $this->codecs[ $id ] ) ) {
			throw new CodecException(
				sprintf( 'Codec ID 0x%04X is not registered.', (int) $id )
			);
		}
		return $this->codecs[ $id ];
	}

	/**
	 * Check whether a codec is registered for the given ID.
	 *
	 * @param int $id The codec ID to check.
	 * @return bool True if a codec is registered, false otherwise.
	 */
	public function has( int $id ): bool {
		return isset( $this->codecs[ $id ] );
	}

	/**
	 * Build a registry pre-populated with the v0.1.0 baseline codecs.
	 *
	 * Registers RawCodec (0x0000) and GzipCodec (0x0001). v0.2.0 will
	 * extend this set with ZstdCodec and the encrypted-codec variants
	 * once those land.
	 *
	 * The optional gzip chunk size is forwarded to GzipCodec's
	 * constructor. The default matches GzipCodec's own default; callers
	 * who have benchmarked their workload and want a different chunk
	 * size may pass it through here.
	 *
	 * @param int $gzip_chunk_size Optional chunk size for the gzip codec.
	 * @return self A new registry with the default codecs registered.
	 * @throws CodecException If $gzip_chunk_size is outside the valid range.
	 */
	public static function with_defaults( int $gzip_chunk_size = GzipCodec::DEFAULT_CHUNK_SIZE ): self {
		$registry = new self();
		$registry->register( new RawCodec() );
		$registry->register( new GzipCodec( $gzip_chunk_size ) );
		return $registry;
	}
}
