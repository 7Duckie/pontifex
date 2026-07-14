<?php
/**
 * Unit tests for SftpDestination's pure, network-free logic.
 *
 * @package Pontifex\Tests\Unit\Destination
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Destination;

use Pontifex\Destination\SftpDestination;
use Pontifex\Tests\TestCase;
use ReflectionMethod;

/**
 * Coverage of {@see SftpDestination::fingerprint_of()} — the host-key
 * fingerprint computation the pinning check compares against.
 *
 * The transport paths need a real SFTP server and are proven by an integration
 * drill, but the fingerprint maths is pure and security-critical (a wrong
 * algorithm here would reject a valid key, or worse), so it is pinned here with
 * a known-answer test independent of the implementation.
 */
final class SftpDestinationTest extends TestCase {

	/**
	 * Invoke the private static fingerprint_of() via reflection.
	 *
	 * @param string $server_key The `<type> <base64>` host key string.
	 * @return string The computed fingerprint.
	 */
	private function fingerprint_of( string $server_key ): string {
		$method = new ReflectionMethod( SftpDestination::class, 'fingerprint_of' );

		return (string) $method->invoke( null, $server_key );
	}

	/**
	 * A host key yields the OpenSSH-style SHA-256 fingerprint of its blob.
	 *
	 * The base64 second field decodes to the bytes "pontifex-hostkey-blob";
	 * the expected value is `SHA256:` + unpadded base64 of that blob's SHA-256,
	 * computed offline with openssl, so this pins the algorithm, the encoding,
	 * and the parse without re-using the production code.
	 *
	 * @return void
	 */
	public function test_computes_the_openssh_sha256_fingerprint(): void {
		$server_key = 'ssh-ed25519 cG9udGlmZXgtaG9zdGtleS1ibG9i';
		$expected   = 'SHA256:BVWwjwbAeqxMWpULX1h1ZQk+J2yQ+gyc6cNVoQ4QGIk';

		$this->assertSame( $expected, $this->fingerprint_of( $server_key ) );
	}

	/**
	 * A key string with no space (no base64 field) yields the empty string.
	 *
	 * @return void
	 */
	public function test_returns_empty_for_a_field_less_key(): void {
		$this->assertSame( '', $this->fingerprint_of( 'ssh-ed25519' ) );
	}

	/**
	 * A key whose second field is not valid base64 yields the empty string.
	 *
	 * @return void
	 */
	public function test_returns_empty_for_an_unparseable_blob(): void {
		$this->assertSame( '', $this->fingerprint_of( 'ssh-ed25519 !!!not-base64!!!' ) );
	}
}
