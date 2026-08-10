<?php
/**
 * Unit tests for the SourceTablePrefix class.
 *
 * @package Pontifex\Tests\Unit\Restore
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Restore;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Pontifex\Archive\Codec\CodecRegistry;
use Pontifex\Archive\Codec\RawCodec;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Format\ExporterInfo;
use Pontifex\Archive\Format\Provenance;
use Pontifex\Archive\Reader\ArchiveReader;
use Pontifex\Archive\Reader\EntryReader;
use Pontifex\Archive\Writer\ArchiveWriter;
use Pontifex\Archive\Writer\EntryPlan;
use Pontifex\Archive\Writer\EntryWriter;
use Pontifex\Archive\Writer\FooterWriter;
use Pontifex\Restore\SourceTablePrefix;

/**
 * Tests for {@see SourceTablePrefix}.
 *
 * Refusing a table that does not belong to this site is the last resort, not
 * the first answer: a legitimate archive should have its source prefix
 * recovered — from its own recorded provenance when present, otherwise from
 * its table names — and rewritten onto this site, rather than refused. The
 * bulk of this file is {@see SourceTablePrefix::derive()}, which is pure and
 * carries the whole reasoning; {@see SourceTablePrefix::resolve()} is the
 * thin I/O wrapper that decides whether derivation is needed at all.
 *
 * The single most important test in this file is
 * {@see self::test_a_plugin_table_masquerading_as_core_does_not_win()}: a
 * plugin table that happens to end in a core suffix must never be allowed to
 * name a narrower "prefix" than the site's real one, because that narrower
 * candidate would then fail to match every OTHER core table and the whole
 * derivation would collapse to ''  when a correct answer was available.
 */
final class SourceTablePrefixTest extends TestCase {

	// -------------------------------------------------------------------------
	// derive() — pure, no I/O, carries the whole reasoning.
	// -------------------------------------------------------------------------

	/**
	 * An ordinary WordPress table set yields the shared "wp_" prefix.
	 *
	 * @return void
	 */
	public function test_an_ordinary_wordpress_table_set_yields_wp_prefix(): void {
		$names = array( 'wp_options', 'wp_posts', 'wp_postmeta', 'wp_users' );

		$this->assertSame( 'wp_', SourceTablePrefix::derive( $names ) );
	}

	/**
	 * A plugin table ending in a core suffix must not win as the prefix.
	 *
	 * "wp_myplugin_options" ends in the core suffix "options", so it produces
	 * the candidate "wp_myplugin_" alongside the correct "wp_" (from the
	 * sibling "wp_posts" and "wp_options" tables). "wp_myplugin_" is NOT a
	 * prefix of "wp_posts" — a plugin table's own name has no reason to
	 * relate to a WordPress core table's name — so it must be discarded at
	 * step 3 of derive()'s algorithm, leaving "wp_" as the only survivor.
	 * Getting this wrong in the other direction (letting the narrower,
	 * wronger candidate win, or letting it poison the result to '') would
	 * turn a completely ordinary backup into a refused restore.
	 *
	 * @return void
	 */
	public function test_a_plugin_table_masquerading_as_core_does_not_win(): void {
		$names = array( 'wp_myplugin_options', 'wp_posts', 'wp_options' );

		$this->assertSame( 'wp_', SourceTablePrefix::derive( $names ) );
	}

	/**
	 * A table set with no recognised core table at all yields ''.
	 *
	 * @return void
	 */
	public function test_a_set_with_no_core_table_yields_empty_string(): void {
		$names = array( 'acme_widgets', 'acme_things' );

		$this->assertSame( '', SourceTablePrefix::derive( $names ) );
	}

	/**
	 * A name failing the identifier shape is ignored, not thrown on, and the
	 * remaining valid names still resolve correctly.
	 *
	 * The three malformed names below would each let SQL metacharacters
	 * through if trusted; derive() has no I/O and no SQL of its own to
	 * protect, but a candidate built from one of them would eventually reach
	 * a rewrite statement via {@see SourceTablePrefix::resolve()}'s caller,
	 * so the identifier check discards them here, before any candidate is
	 * ever built from them.
	 *
	 * @return void
	 */
	public function test_a_malformed_name_is_ignored_and_valid_names_still_resolve(): void {
		$names = array( 'wp_posts', 'wp_options', 'wp`evil', 'wp evil', 'wp;evil' );

		$this->assertSame( 'wp_', SourceTablePrefix::derive( $names ) );
	}

	/**
	 * An empty array of table names yields ''.
	 *
	 * @return void
	 */
	public function test_an_empty_array_yields_empty_string(): void {
		$this->assertSame( '', SourceTablePrefix::derive( array() ) );
	}

	/**
	 * A set where one name does not share the candidate prefix yields ''.
	 *
	 * "wp_options" produces the candidate "wp_"; "other_posts" produces the
	 * candidate "other_". Neither is a prefix of the other name, so neither
	 * survives step 3 and nothing is left to return.
	 *
	 * @return void
	 */
	public function test_a_set_without_a_shared_candidate_yields_empty_string(): void {
		$names = array( 'wp_options', 'other_posts' );

		$this->assertSame( '', SourceTablePrefix::derive( $names ) );
	}

	/**
	 * A non-default table prefix is derived correctly.
	 *
	 * @return void
	 */
	public function test_a_non_default_prefix_is_derived(): void {
		$names = array( 'mysite_options', 'mysite_posts' );

		$this->assertSame( 'mysite_', SourceTablePrefix::derive( $names ) );
	}

	/**
	 * A table named exactly "options", with no prefix at all, does not
	 * produce a winning empty-string candidate.
	 *
	 * Stripping the "options" suffix from "options" leaves '', and derive()'s
	 * algorithm explicitly discards an empty candidate at step 2 — before it
	 * is ever tested for being a prefix of every name. So this table
	 * contributes no candidate whatsoever, and with nothing else in the set
	 * to derive one from, the result is '' — the same answer as "no prefix
	 * could be established", not a confirmed empty prefix. Pinned here
	 * because the distinction between those two meanings of '' matters to
	 * {@see SourceTablePrefix::resolve()}'s caller, which treats '' as "run
	 * no rewrite" either way.
	 *
	 * @return void
	 */
	public function test_a_table_named_exactly_options_yields_empty_string(): void {
		$this->assertSame( '', SourceTablePrefix::derive( array( 'options' ) ) );
	}

	/**
	 * The "usermeta" core suffix is recognised and derives the right prefix.
	 *
	 * Exercised on its own rather than folded into the ordinary-table-set
	 * test above, because it is one long compound word with no separator
	 * before "meta" — worth confirming str_ends_with() matches it exactly
	 * and produces no stray candidate alongside the correct "wp_".
	 *
	 * @return void
	 */
	public function test_the_usermeta_suffix_is_recognised(): void {
		$names = array( 'wp_usermeta', 'wp_users', 'wp_options' );

		$this->assertSame( 'wp_', SourceTablePrefix::derive( $names ) );
	}

	/**
	 * The "term_relationships" core suffix is recognised and derives the right prefix.
	 *
	 * The longest of the recognised suffixes, and one of two (with
	 * "term_taxonomy") that carry an underscore of their own rather than
	 * being a single glued-together word like "usermeta" or "postmeta" —
	 * worth confirming it is matched as one whole suffix rather than, say,
	 * only its "terms" root.
	 *
	 * @return void
	 */
	public function test_the_term_relationships_suffix_is_recognised(): void {
		$names = array( 'wp_term_relationships', 'wp_terms', 'wp_options' );

		$this->assertSame( 'wp_', SourceTablePrefix::derive( $names ) );
	}

	// -------------------------------------------------------------------------
	// resolve() — the thin I/O wrapper: recorded prefix first, else derive().
	// -------------------------------------------------------------------------

	/**
	 * A valid recorded prefix is returned unchanged, and no derivation happens.
	 *
	 * The archive's table names would derive to "wp_"; the recorded prefix
	 * disagrees with that on purpose, so a pass here can only be explained by
	 * the recorded value winning outright, never by the two coinciding.
	 *
	 * @return void
	 */
	public function test_a_valid_recorded_prefix_is_returned_unchanged(): void {
		$source         = self::build_archive_stream(
			array(
				self::db_chunk_plan( 'wp_options', 1, "INSERT INTO `wp_options` VALUES (1);\n" ),
				self::db_chunk_plan( 'wp_posts', 1, "INSERT INTO `wp_posts` VALUES (1);\n" ),
			)
		);
		$archive_reader = new ArchiveReader( $source );
		$entry_reader   = new EntryReader( CodecRegistry::with_defaults() );

		$resolved = SourceTablePrefix::resolve( 'xyz_', $archive_reader->manifest(), $source, $entry_reader );

		$this->assertSame( 'xyz_', $resolved, 'The recorded prefix must win over what the table names would derive.' );
	}

	/**
	 * A null recorded prefix causes derivation from the archive's table names.
	 *
	 * @return void
	 */
	public function test_a_null_recorded_prefix_causes_derivation(): void {
		$source         = self::build_archive_stream(
			array(
				self::db_chunk_plan( 'mysite_options', 1, "INSERT INTO `mysite_options` VALUES (1);\n" ),
				self::db_chunk_plan( 'mysite_posts', 1, "INSERT INTO `mysite_posts` VALUES (1);\n" ),
			)
		);
		$archive_reader = new ArchiveReader( $source );
		$entry_reader   = new EntryReader( CodecRegistry::with_defaults() );

		$resolved = SourceTablePrefix::resolve( null, $archive_reader->manifest(), $source, $entry_reader );

		$this->assertSame( 'mysite_', $resolved );
	}

	/**
	 * An invalid recorded prefix falls through to derivation rather than being used.
	 *
	 * @return void
	 */
	public function test_an_invalid_recorded_prefix_falls_through_to_derivation(): void {
		$source         = self::build_archive_stream(
			array(
				self::db_chunk_plan( 'wp_options', 1, "INSERT INTO `wp_options` VALUES (1);\n" ),
				self::db_chunk_plan( 'wp_posts', 1, "INSERT INTO `wp_posts` VALUES (1);\n" ),
			)
		);
		$archive_reader = new ArchiveReader( $source );
		$entry_reader   = new EntryReader( CodecRegistry::with_defaults() );

		$resolved = SourceTablePrefix::resolve( "wp'; DROP", $archive_reader->manifest(), $source, $entry_reader );

		$this->assertSame( 'wp_', $resolved, 'A recorded prefix failing the identifier shape must not be trusted.' );
	}

	/**
	 * An archive with no db_chunk entries at all returns '' without error.
	 *
	 * @return void
	 */
	public function test_an_archive_with_no_db_chunk_entries_returns_empty_string(): void {
		$source         = self::build_archive_stream(
			array( self::file_plan( 'wp-content/uploads/hello.txt', 'hello' ) )
		);
		$archive_reader = new ArchiveReader( $source );
		$entry_reader   = new EntryReader( CodecRegistry::with_defaults() );

		$resolved = SourceTablePrefix::resolve( null, $archive_reader->manifest(), $source, $entry_reader );

		$this->assertSame( '', $resolved );
	}

	// -------------------------------------------------------------------------
	// Helpers — the same real-archive machinery RestorePreflightTest uses.
	// -------------------------------------------------------------------------

	/**
	 * Build an in-memory archive from entry plans.
	 *
	 * @param EntryPlan[] $plans The entries to write.
	 * @return resource A readable, seekable stream of archive bytes.
	 */
	private static function build_archive_stream( array $plans ) {
		$destination = self::memory_stream();
		$writer      = new ArchiveWriter( new EntryWriter( CodecRegistry::with_defaults() ), new FooterWriter() );
		$writer->write_archive( self::sample_provenance(), $plans, $destination );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Test stream resource, not a filesystem path.
		rewind( $destination );
		return $destination;
	}

	/**
	 * A provenance block for the test archives.
	 *
	 * The recorded table_prefix field is deliberately left null here:
	 * resolve() takes its recorded prefix as a plain argument, never reading
	 * it back out of the provenance itself, so the tests above pass whatever
	 * recorded value they want to exercise directly.
	 *
	 * @return Provenance
	 */
	private static function sample_provenance(): Provenance {
		return new Provenance(
			'6.6.1',
			'8.2.10',
			'https://example.test',
			'utf8mb4',
			'utf8mb4_unicode_520_ci',
			new ExporterInfo( 'pontifex', '1.1.1' ),
			new DateTimeImmutable( '2026-08-10T10:00:00+00:00', new DateTimeZone( 'UTC' ) )
		);
	}

	/**
	 * Build an EntryPlan for a db_chunk entry.
	 *
	 * @param string $table_name      Source table name.
	 * @param int    $statement_count Number of statements.
	 * @param string $sql             SQL bytes.
	 * @return EntryPlan
	 */
	private static function db_chunk_plan( string $table_name, int $statement_count, string $sql ): EntryPlan {
		$header = EntryHeader::for_db_chunk( 0, $table_name, $statement_count, strlen( $sql ), 0 );
		return new EntryPlan( $header, RawCodec::ID, str_repeat( "\0", EntryWriter::NONCE_SIZE ), self::memory_stream( $sql ) );
	}

	/**
	 * Build an EntryPlan for a file entry.
	 *
	 * @param string $path     Relative path inside the archive.
	 * @param string $contents File contents.
	 * @return EntryPlan
	 */
	private static function file_plan( string $path, string $contents ): EntryPlan {
		$header = EntryHeader::for_file( $path, strlen( $contents ), 0o644, 1690000000, 'application/octet-stream', 0 );
		return new EntryPlan( $header, RawCodec::ID, str_repeat( "\0", EntryWriter::NONCE_SIZE ), self::memory_stream( $contents ) );
	}

	/**
	 * An in-memory read/write stream, optionally pre-filled.
	 *
	 * @param string $contents Optional initial contents.
	 * @return resource
	 */
	private static function memory_stream( string $contents = '' ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- In-memory test stream, not a filesystem path.
		$stream = fopen( 'php://memory', 'r+b' );
		if ( '' !== $contents ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Writing to an in-memory test stream, not a filesystem path.
			fwrite( $stream, $contents );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Test stream resource, not a filesystem path.
			rewind( $stream );
		}
		return $stream;
	}
}
