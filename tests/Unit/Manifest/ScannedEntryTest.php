<?php
/**
 * Unit tests for the ScannedEntry value object.
 *
 * @package Pontifex\Tests\Unit\Manifest
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Manifest;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Manifest\ScannedEntry;

/**
 * Tests for {@see ScannedEntry}.
 */
final class ScannedEntryTest extends TestCase {

	/**
	 * The constructor must accept valid file inputs and expose them via accessors.
	 *
	 * @return void
	 */
	public function test_constructor_accepts_valid_file_inputs(): void {
		$entry = new ScannedEntry(
			EntryHeader::KIND_FILE,
			'wp-config.php',
			'/var/www/html/wp-config.php',
			1234,
			0644,
			1690000000,
			null
		);

		$this->assertSame( EntryHeader::KIND_FILE, $entry->kind() );
		$this->assertSame( 'wp-config.php', $entry->relative_path() );
		$this->assertSame( '/var/www/html/wp-config.php', $entry->absolute_path() );
		$this->assertSame( 1234, $entry->size() );
		$this->assertSame( 0644, $entry->mode() );
		$this->assertSame( 1690000000, $entry->mtime() );
		$this->assertNull( $entry->target() );
	}

	/**
	 * The constructor must accept valid directory inputs.
	 *
	 * @return void
	 */
	public function test_constructor_accepts_valid_directory_inputs(): void {
		$entry = new ScannedEntry(
			EntryHeader::KIND_DIRECTORY,
			'wp-content/uploads',
			'/var/www/html/wp-content/uploads',
			0,
			0755,
			1690000000
		);

		$this->assertSame( EntryHeader::KIND_DIRECTORY, $entry->kind() );
		$this->assertSame( 0, $entry->size() );
		$this->assertSame( 0755, $entry->mode() );
		$this->assertNull( $entry->target() );
	}

	/**
	 * The constructor must accept valid symlink inputs and store the target.
	 *
	 * @return void
	 */
	public function test_constructor_accepts_valid_symlink_inputs(): void {
		$entry = new ScannedEntry(
			EntryHeader::KIND_SYMLINK,
			'wp-content/uploads',
			'/var/www/html/wp-content/uploads',
			0,
			0777,
			1690000000,
			'/mnt/uploads'
		);

		$this->assertSame( EntryHeader::KIND_SYMLINK, $entry->kind() );
		$this->assertSame( '/mnt/uploads', $entry->target() );
	}

	/**
	 * The constructor must reject an unrecognised kind.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_unknown_kind(): void {
		$this->expectException( InvalidArgumentException::class );

		new ScannedEntry( 'mystery_kind', 'a.txt', '/a.txt', 0, 0644, 0 );
	}

	/**
	 * The constructor must reject db_chunk kind (FileScanner does not produce these).
	 *
	 * @return void
	 */
	public function test_constructor_rejects_db_chunk_kind(): void {
		$this->expectException( InvalidArgumentException::class );

		new ScannedEntry( EntryHeader::KIND_DB_CHUNK, 'wp_posts', '/wp_posts', 0, 0644, 0 );
	}

	/**
	 * The constructor must reject an empty relative_path.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_empty_relative_path(): void {
		$this->expectException( InvalidArgumentException::class );

		new ScannedEntry( EntryHeader::KIND_FILE, '', '/a.txt', 0, 0644, 0 );
	}

	/**
	 * The constructor must reject an empty absolute_path.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_empty_absolute_path(): void {
		$this->expectException( InvalidArgumentException::class );

		new ScannedEntry( EntryHeader::KIND_FILE, 'a.txt', '', 0, 0644, 0 );
	}

	/**
	 * The constructor must reject a negative size.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_negative_size(): void {
		$this->expectException( InvalidArgumentException::class );

		new ScannedEntry( EntryHeader::KIND_FILE, 'a.txt', '/a.txt', -1, 0644, 0 );
	}

	/**
	 * The constructor must reject a mode outside the POSIX range.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_oversize_mode(): void {
		$this->expectException( InvalidArgumentException::class );

		new ScannedEntry( EntryHeader::KIND_FILE, 'a.txt', '/a.txt', 0, EntryHeader::MAX_POSIX_MODE + 1, 0 );
	}

	/**
	 * The constructor must reject a negative mtime.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_negative_mtime(): void {
		$this->expectException( InvalidArgumentException::class );

		new ScannedEntry( EntryHeader::KIND_FILE, 'a.txt', '/a.txt', 0, 0644, -1 );
	}

	/**
	 * The constructor must reject a symlink with a null target.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_symlink_with_null_target(): void {
		$this->expectException( InvalidArgumentException::class );

		new ScannedEntry( EntryHeader::KIND_SYMLINK, 'link', '/link', 0, 0777, 0, null );
	}

	/**
	 * The constructor must reject a symlink with an empty target.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_symlink_with_empty_target(): void {
		$this->expectException( InvalidArgumentException::class );

		new ScannedEntry( EntryHeader::KIND_SYMLINK, 'link', '/link', 0, 0777, 0, '' );
	}

	/**
	 * The constructor must reject a file with a non-null target.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_file_with_target(): void {
		$this->expectException( InvalidArgumentException::class );

		new ScannedEntry( EntryHeader::KIND_FILE, 'a.txt', '/a.txt', 0, 0644, 0, '/somewhere' );
	}

	/**
	 * The constructor must reject a directory with a non-null target.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_directory_with_target(): void {
		$this->expectException( InvalidArgumentException::class );

		new ScannedEntry( EntryHeader::KIND_DIRECTORY, 'd', '/d', 0, 0755, 0, '/somewhere' );
	}

	/**
	 * The is_file predicate must reflect the kind correctly.
	 *
	 * @return void
	 */
	public function test_is_file_predicate(): void {
		$file = new ScannedEntry( EntryHeader::KIND_FILE, 'a.txt', '/a.txt', 0, 0644, 0 );
		$dir  = new ScannedEntry( EntryHeader::KIND_DIRECTORY, 'd', '/d', 0, 0755, 0 );

		$this->assertTrue( $file->is_file() );
		$this->assertFalse( $dir->is_file() );
	}

	/**
	 * The is_directory predicate must reflect the kind correctly.
	 *
	 * @return void
	 */
	public function test_is_directory_predicate(): void {
		$file = new ScannedEntry( EntryHeader::KIND_FILE, 'a.txt', '/a.txt', 0, 0644, 0 );
		$dir  = new ScannedEntry( EntryHeader::KIND_DIRECTORY, 'd', '/d', 0, 0755, 0 );

		$this->assertTrue( $dir->is_directory() );
		$this->assertFalse( $file->is_directory() );
	}

	/**
	 * The is_symlink predicate must reflect the kind correctly.
	 *
	 * @return void
	 */
	public function test_is_symlink_predicate(): void {
		$file    = new ScannedEntry( EntryHeader::KIND_FILE, 'a.txt', '/a.txt', 0, 0644, 0 );
		$symlink = new ScannedEntry( EntryHeader::KIND_SYMLINK, 'link', '/link', 0, 0777, 0, '/target' );

		$this->assertTrue( $symlink->is_symlink() );
		$this->assertFalse( $file->is_symlink() );
	}
}
