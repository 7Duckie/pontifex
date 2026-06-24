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
			null,
			'text/x-php'
		);

		$this->assertSame( EntryHeader::KIND_FILE, $entry->kind() );
		$this->assertSame( 'wp-config.php', $entry->relative_path() );
		$this->assertSame( '/var/www/html/wp-config.php', $entry->absolute_path() );
		$this->assertSame( 1234, $entry->size() );
		$this->assertSame( 0644, $entry->mode() );
		$this->assertSame( 1690000000, $entry->mtime() );
		$this->assertNull( $entry->target() );
		$this->assertSame( 'text/x-php', $entry->media_type() );
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

		new ScannedEntry( EntryHeader::KIND_FILE, 'a.txt', '/a.txt', 0, EntryHeader::MAX_POSIX_MODE + 1, 0, null, 'text/plain' );
	}

	/**
	 * The constructor must reject a negative mtime.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_negative_mtime(): void {
		$this->expectException( InvalidArgumentException::class );

		new ScannedEntry( EntryHeader::KIND_FILE, 'a.txt', '/a.txt', 0, 0644, -1, null, 'text/plain' );
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
	 * The constructor must reject a file entry with null media_type.
	 *
	 * File entries require a non-null, non-empty media_type so the
	 * downstream EntryHeader::for_file() factory can always be called.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_file_with_null_media_type(): void {
		$this->expectException( InvalidArgumentException::class );

		new ScannedEntry( EntryHeader::KIND_FILE, 'a.txt', '/a.txt', 0, 0644, 0, null, null );
	}

	/**
	 * The constructor must reject a file entry with empty media_type.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_file_with_empty_media_type(): void {
		$this->expectException( InvalidArgumentException::class );

		new ScannedEntry( EntryHeader::KIND_FILE, 'a.txt', '/a.txt', 0, 0644, 0, null, '' );
	}

	/**
	 * The constructor must reject a directory with a non-null media_type.
	 *
	 * The media_type field is meaningful only for files; passing it to a
	 * directory indicates a caller bug.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_directory_with_media_type(): void {
		$this->expectException( InvalidArgumentException::class );

		new ScannedEntry( EntryHeader::KIND_DIRECTORY, 'd', '/d', 0, 0755, 0, null, 'text/plain' );
	}

	/**
	 * The constructor must reject a symlink with a non-null media_type.
	 *
	 * @return void
	 */
	public function test_constructor_rejects_symlink_with_media_type(): void {
		$this->expectException( InvalidArgumentException::class );

		new ScannedEntry( EntryHeader::KIND_SYMLINK, 'link', '/link', 0, 0777, 0, '/target', 'text/plain' );
	}

	/**
	 * The media_type accessor must return null for non-file entries.
	 *
	 * @return void
	 */
	public function test_media_type_accessor_returns_null_for_non_file_kinds(): void {
		$dir = new ScannedEntry( EntryHeader::KIND_DIRECTORY, 'd', '/d', 0, 0755, 0 );

		$this->assertNull( $dir->media_type() );
	}
}
