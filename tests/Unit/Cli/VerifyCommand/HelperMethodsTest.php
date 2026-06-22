<?php
/**
 * Helper-method tests for VerifyCommand.
 *
 * @package Pontifex\Tests\Unit\Cli\VerifyCommand
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Cli\VerifyCommand;

use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Format\ManifestEntry;
use Pontifex\Cli\VerifyCommand;
use Pontifex\Tests\TestCase;
use ReflectionMethod;

/**
 * Unit coverage of VerifyCommand's pure --list row builder.
 *
 * The private static manifest_rows() method maps the archive's manifest
 * entries to the display rows the --list flag renders. It is kept separate
 * from the WP-CLI formatter precisely so its logic — the db_chunk name
 * fallback and the shortened hash — can be unit-tested without the WP-CLI
 * runtime. Reflection invokes it directly, matching the pattern
 * ExportCommand's HelperMethodsTest uses for its private statics.
 */
final class HelperMethodsTest extends TestCase {


	/**
	 * Invoke the private static manifest_rows() via reflection.
	 *
	 * @param array<int, ManifestEntry> $entries The manifest entries to map.
	 * @return array<int, array<string, int|string>> The display rows.
	 */
	private function manifest_rows( array $entries ): array {
		$reflection = new ReflectionMethod( VerifyCommand::class, 'manifest_rows' );
		return $reflection->invoke( null, $entries );
	}

	/**
	 * A fixed 32-byte entry hash whose first twelve hex characters are known.
	 *
	 * Each byte is 0xAB, so bin2hex is "abab…" and the shortened hash the row
	 * carries is the first twelve of those characters: "abababababab".
	 *
	 * @return string A 32-byte binary string.
	 */
	private static function hash(): string {
		return str_repeat( "\xab", 32 );
	}

	/**
	 * A file entry maps to a row carrying its path, kind, codec, size and short hash.
	 *
	 * @return void
	 */
	public function test_manifest_rows_maps_a_file_entry(): void {
		$entry = ManifestEntry::for_file( 0, 100, 2048, 'wp-content/index.php', 0, self::hash() );

		$rows = $this->manifest_rows( array( $entry ) );

		$this->assertCount( 1, $rows );
		$this->assertSame( 0, $rows[0]['index'] );
		$this->assertSame( EntryHeader::KIND_FILE, $rows[0]['kind'] );
		$this->assertSame( 'wp-content/index.php', $rows[0]['name'] );
		$this->assertSame( 0, $rows[0]['codec'] );
		$this->assertSame( 2048, $rows[0]['size'] );
		$this->assertSame( 'abababababab', $rows[0]['hash'] );
	}

	/**
	 * A db_chunk entry has no path, so its name column is "db chunk #<index>".
	 *
	 * @return void
	 */
	public function test_manifest_rows_labels_a_db_chunk_by_its_index(): void {
		$entry = ManifestEntry::for_db_chunk( 5, 4096, 512, 7, 1, self::hash() );

		$rows = $this->manifest_rows( array( $entry ) );

		$this->assertSame( EntryHeader::KIND_DB_CHUNK, $rows[0]['kind'] );
		$this->assertSame( 'db chunk #7', $rows[0]['name'] );
		$this->assertSame( 1, $rows[0]['codec'] );
	}

	/**
	 * Rows are produced one-per-entry, in manifest order.
	 *
	 * @return void
	 */
	public function test_manifest_rows_preserves_order_and_count(): void {
		$entries = array(
			ManifestEntry::for_directory( 0, 0, 64, 'wp-content', 0, self::hash() ),
			ManifestEntry::for_file( 1, 64, 128, 'wp-content/a.txt', 0, self::hash() ),
			ManifestEntry::for_db_chunk( 2, 192, 256, 0, 0, self::hash() ),
		);

		$rows = $this->manifest_rows( $entries );

		$this->assertCount( 3, $rows );
		$this->assertSame( array( 0, 1, 2 ), array_column( $rows, 'index' ) );
		$this->assertSame( 'wp-content', $rows[0]['name'] );
		$this->assertSame( 'db chunk #0', $rows[2]['name'] );
	}
}
