<?php
/**
 * Unit tests for RestorePreflight and PreflightReport.
 *
 * @package Pontifex\Tests\Unit\Restore
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Restore;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Pontifex\Archive\Codec\Codec;
use Pontifex\Archive\Codec\CodecRegistry;
use Pontifex\Archive\Codec\CodecUnavailableException;
use Pontifex\Archive\Codec\RawCodec;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Format\ExporterInfo;
use Pontifex\Archive\Format\Provenance;
use Pontifex\Archive\Format\Scope;
use Pontifex\Archive\Reader\ArchiveLimits;
use Pontifex\Archive\Reader\ArchiveReader;
use Pontifex\Archive\Reader\EntryReader;
use Pontifex\Archive\Writer\ArchiveWriter;
use Pontifex\Archive\Writer\EntryPlan;
use Pontifex\Archive\Writer\EntryWriter;
use Pontifex\Archive\Writer\FooterWriter;
use Pontifex\Restore\FileWriter;
use Pontifex\Restore\PreflightReport;
use Pontifex\Restore\RestorePreflight;

/**
 * Tests for {@see RestorePreflight} and {@see PreflightReport}.
 *
 * These exist because of a specific, observed failure: an operator verified a
 * backup, was told it was SOUND, and then watched the restore refuse the very
 * same file. Verify ran none of the checks a restore settles up front, so it
 * was answering a narrower question than the word "sound" implies.
 *
 * The behaviour under test is therefore not "the checks work" — FileWriterTest
 * already proves that — but the SORTING. A finding has to land in the right one
 * of three buckets, because each one tells the reader to do something
 * different: throw the archive away, free some disk space, or ignore it because
 * nothing was actually decided. Getting that wrong is worse than not reporting
 * at all.
 */
final class RestorePreflightTest extends TestCase {

	/**
	 * Temporary destination root for this test's FileWriter.
	 *
	 * @var string
	 */
	private string $fixture_root;

	/**
	 * Create a unique fixture root per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->fixture_root = sys_get_temp_dir() . '/pontifex-preflight-test-' . bin2hex( random_bytes( 8 ) );
	}

	/**
	 * Remove the fixture tree.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		self::remove_tree( $this->fixture_root );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// The sorting: which bucket a finding lands in.
	// -------------------------------------------------------------------------

	/**
	 * An archive whose symlink would escape the site is an ARCHIVE finding.
	 *
	 * This is the case the whole change exists for. Every hash in this archive is
	 * valid, so integrity checking calls it sound; it is nonetheless an archive
	 * no restore will accept, on any host, ever.
	 *
	 * @return void
	 */
	public function test_escaping_symlink_is_an_archive_finding(): void {
		$source = self::build_archive_stream(
			array( self::symlink_plan( 'wp-content/leak', '../../../../etc/passwd' ) )
		);

		$report = $this->report_for( $source );

		$this->assertTrue( $report->archive_is_refused(), 'An escaping symlink must condemn the archive.' );
		$this->assertFalse( $report->host_cannot_restore(), 'It is not the host that is at fault.' );
		$this->assertArrayHasKey( RestorePreflight::CHECK_SYMLINK_CONFINEMENT, $report->archive_findings() );
	}

	/**
	 * An archive whose recorded scope contradicts its manifest is an ARCHIVE finding.
	 *
	 * A files-only backup that nonetheless carries database chunks is describing
	 * itself untruthfully. Restoring it would write data the scope says is absent.
	 *
	 * @return void
	 */
	public function test_scope_contradicting_the_manifest_is_an_archive_finding(): void {
		$source = self::build_archive_stream(
			array( self::db_chunk_plan( 'wp_options', 1, "INSERT INTO `wp_options` VALUES (1);\n" ) ),
			Scope::files_only( array() )
		);

		$report = $this->report_for( $source );

		$this->assertTrue( $report->archive_is_refused() );
		$this->assertArrayHasKey( RestorePreflight::CHECK_SCOPE, $report->archive_findings() );
	}

	/**
	 * A destination without room is a HOST finding, never an archive one.
	 *
	 * The distinction is the point. A full disk says nothing whatever about the
	 * backup, and a surface that reported it as an archive problem would be
	 * telling somebody to throw away a file that is very probably their only copy.
	 *
	 * @return void
	 */
	public function test_a_full_disk_is_a_host_finding_not_an_archive_one(): void {
		$source = self::build_archive_stream( array( self::file_plan( 'wp-content/big.txt', str_repeat( 'x', 4096 ) ) ) );

		$report = $this->report_for( $source, static fn (): int => 1 );

		$this->assertTrue( $report->host_cannot_restore(), 'No room must be reported against the host.' );
		$this->assertFalse( $report->archive_is_refused(), 'A full disk must never condemn the archive.' );
		$this->assertArrayHasKey( RestorePreflight::CHECK_FREE_SPACE, $report->host_findings() );
	}

	/**
	 * A check that could not run at all is INCONCLUSIVE — not a refusal.
	 *
	 * The dangerous mistake here would be to treat "I could not answer" as "the
	 * answer is no". A reader told their backup is hostile will act on it.
	 *
	 * The failure is injected rather than provoked, on purpose. An earlier version
	 * of this test read the entries from a deliberately broken stream, which
	 * passed on PHP 8.5 and failed on 8.2 — the exception a broken read raises is
	 * not the same across versions, so the test was really asserting a property of
	 * the PHP runtime. What is being tested here is the SORTING RULE: a refusal
	 * that is neither of the two recognised kinds must land in neither bucket.
	 * Throwing a plain RuntimeException from a seam states that directly and
	 * cannot drift.
	 *
	 * @return void
	 */
	public function test_a_check_that_cannot_run_is_inconclusive_not_a_refusal(): void {
		$source = self::build_archive_stream( array( self::file_plan( 'wp-content/ok.txt', 'hello' ) ) );

		$preflight = new RestorePreflight(
			new EntryReader( CodecRegistry::with_defaults() ),
			new FileWriter(
				$this->fixture_root,
				false,
				null,
				static function ( string $path ): int {
					unset( $path );
					throw new RuntimeException( 'the free-space question could not be answered' );
				}
			)
		);
		$report    = $preflight->read_only_report( $source, self::manifest_of( $source ), null );

		$this->assertFalse( $report->archive_is_refused(), 'An unanswerable check must never condemn the archive.' );
		$this->assertFalse( $report->host_cannot_restore(), 'Nor may it be blamed on the host.' );
		$this->assertArrayHasKey( RestorePreflight::CHECK_FREE_SPACE, $report->inconclusive() );
		$this->assertFalse( $report->is_clear(), 'A report with an unanswered check is not clear.' );
	}

	/**
	 * A free-space reading that could not be taken at all is INCONCLUSIVE, not a pass and not a refusal.
	 *
	 * {@see FileWriter::assert_free_space_for()} returns false (rather than
	 * throwing) when its injected disk_free_space reader itself answers false
	 * — e.g. disk_free_space() restricted or disabled on this host, the same
	 * shape {@see \Pontifex\Rollback\SafetyArchiver::preflight_disk_space()}
	 * already treats as an unknown. The report must not read that silence as
	 * "no host finding, so the destination has room" — that would be exactly
	 * the false promise this job exists to remove — nor may it be read as a
	 * refusal of any kind, because nothing was actually established either
	 * way.
	 *
	 * @return void
	 */
	public function test_an_unmeasurable_free_space_reading_is_inconclusive_not_a_refusal(): void {
		$source    = self::build_archive_stream( array( self::file_plan( 'wp-content/ok.txt', 'hello' ) ) );
		$preflight = new RestorePreflight(
			new EntryReader( CodecRegistry::with_defaults() ),
			new FileWriter(
				$this->fixture_root,
				false,
				null,
				static fn (): bool => false
			)
		);

		$report = $preflight->read_only_report( $source, self::manifest_of( $source ), null );

		$this->assertFalse( $report->archive_is_refused(), 'An unmeasurable reading must never condemn the archive.' );
		$this->assertFalse( $report->host_cannot_restore(), 'Nor may it be blamed on the host — nothing was established about this host either.' );
		$this->assertArrayHasKey( RestorePreflight::CHECK_FREE_SPACE, $report->inconclusive() );
		$this->assertFalse( $report->is_clear(), 'A report holding an unmeasurable check is not clear.' );
	}

	/**
	 * A sound, restorable archive produces a clear report.
	 *
	 * @return void
	 */
	public function test_a_restorable_archive_reports_clear(): void {
		$source = self::build_archive_stream(
			array(
				self::file_plan( 'wp-content/ok.txt', 'hello' ),
				self::symlink_plan( 'wp-content/link', 'ok.txt' ),
			)
		);

		$report = $this->report_for( $source );

		$this->assertTrue( $report->is_clear(), implode( ' | ', $report->messages() ) );
		$this->assertSame(
			array(
				RestorePreflight::CHECK_SCOPE,
				RestorePreflight::CHECK_SYMLINK_CONFINEMENT,
				RestorePreflight::CHECK_FREE_SPACE,
				RestorePreflight::CHECK_ENTRY_SIZE,
				RestorePreflight::CHECK_ENTRY_MEMORY,
				RestorePreflight::CHECK_PATH_LENGTH,
				RestorePreflight::CHECK_CODEC_SUPPORT,
			),
			$report->checks_run()
		);
	}

	/**
	 * Every read-only check runs, so one failure does not hide the next.
	 *
	 * A restore stops at the first refusal because it is about to overwrite a
	 * site. A report has no such reason to stop, and stopping would make an
	 * operator fix one problem only to be told about the next one on the next run.
	 *
	 * @return void
	 */
	public function test_reporting_does_not_stop_at_the_first_finding(): void {
		$source = self::build_archive_stream(
			array(
				self::file_plan( 'wp-content/big.txt', str_repeat( 'x', 4096 ) ),
				self::symlink_plan( 'wp-content/leak', '../../../../etc/passwd' ),
			)
		);

		$report = $this->report_for( $source, static fn (): int => 1 );

		$this->assertTrue( $report->archive_is_refused() );
		$this->assertTrue( $report->host_cannot_restore() );
		$this->assertCount( 2, $report->messages() );
	}

	// -------------------------------------------------------------------------
	// Limits a restore enforces that verify never checked.
	// -------------------------------------------------------------------------

	/**
	 * An entry above the per-entry limit is a HOST finding, and the archive is not condemned.
	 *
	 * The archive is undamaged; the limit is a property of this build, not of the
	 * host, and no server setting changes it.
	 *
	 * @return void
	 */
	public function test_an_entry_over_the_size_limit_is_a_host_finding(): void {
		$source = self::build_archive_stream( array( self::file_plan( 'wp-content/big.txt', str_repeat( 'x', 4096 ) ) ) );

		$report = $this->report_with( $source, self::limits_with_entry_bytes( 1000 ), 1073741824 );

		$this->assertTrue( $report->host_cannot_restore() );
		$this->assertFalse( $report->archive_is_refused(), 'An installation limit must never condemn the archive.' );
		$this->assertArrayNotHasKey( RestorePreflight::CHECK_ENTRY_SIZE, $report->inconclusive() );
		$this->assertArrayHasKey( RestorePreflight::CHECK_ENTRY_SIZE, $report->host_findings() );
		$this->assertContains( RestorePreflight::CHECK_ENTRY_SIZE, $report->checks_run() );
		$this->assertStringContainsString( 'wp-content/big.txt', $report->host_findings()[ RestorePreflight::CHECK_ENTRY_SIZE ] );
		$this->assertStringContainsString( '4,096', $report->host_findings()[ RestorePreflight::CHECK_ENTRY_SIZE ] );
		$this->assertStringContainsString( '1,000', $report->host_findings()[ RestorePreflight::CHECK_ENTRY_SIZE ] );
		$this->assertStringContainsString( 'limit this build of Pontifex applies to a single entry, on any host;', $report->host_findings()[ RestorePreflight::CHECK_ENTRY_SIZE ] );
		$this->assertStringContainsString( 'The backup is not damaged and does not need replacing — no server setting changes this ceiling.', $report->host_findings()[ RestorePreflight::CHECK_ENTRY_SIZE ] );
	}

	/**
	 * The size limit covers every entry kind, so an oversized database chunk is refused too.
	 *
	 * @return void
	 */
	public function test_an_oversized_database_chunk_is_refused_by_the_size_limit(): void {
		$sql    = "INSERT INTO `wp_options` VALUES (1);\n" . str_repeat( '-', 4096 );
		$source = self::build_archive_stream( array( self::db_chunk_plan( 'wp_options', 1, $sql ) ) );

		$report = $this->report_with( $source, self::limits_with_entry_bytes( 1000 ), 1073741824 );

		$this->assertArrayHasKey( RestorePreflight::CHECK_ENTRY_SIZE, $report->host_findings() );
		$this->assertStringContainsString( 'wp_options', $report->host_findings()[ RestorePreflight::CHECK_ENTRY_SIZE ] );
	}

	/**
	 * An entry within the size limit passes, and the check is still recorded as run.
	 *
	 * @return void
	 */
	public function test_entries_within_the_size_limit_pass(): void {
		$source = self::build_archive_stream( array( self::file_plan( 'wp-content/ok.txt', 'hello' ) ) );

		$report = $this->report_with( $source, self::limits_with_entry_bytes( 1000 ), 1073741824 );

		$this->assertArrayNotHasKey( RestorePreflight::CHECK_ENTRY_SIZE, $report->host_findings() );
		$this->assertArrayNotHasKey( RestorePreflight::CHECK_ENTRY_SIZE, $report->archive_findings() );
		$this->assertArrayNotHasKey( RestorePreflight::CHECK_ENTRY_SIZE, $report->inconclusive() );
		$this->assertContains( RestorePreflight::CHECK_ENTRY_SIZE, $report->checks_run() );
	}

	/**
	 * The size message names the largest entry, and reads correctly for one and for many.
	 *
	 * @return void
	 */
	public function test_the_size_message_counts_correctly_and_names_the_largest(): void {
		$one = $this->report_with(
			self::build_archive_stream( array( self::file_plan( 'wp-content/big.txt', str_repeat( 'x', 4096 ) ) ) ),
			self::limits_with_entry_bytes( 1000 ),
			1073741824
		)->host_findings()[ RestorePreflight::CHECK_ENTRY_SIZE ];

		$this->assertStringStartsWith( 'One entry has', $one );
		$this->assertStringNotContainsString( '1 entries', $one );

		$many = $this->report_with(
			self::build_archive_stream(
				array(
					self::file_plan( 'wp-content/a.txt', str_repeat( 'x', 2000 ) ),
					self::file_plan( 'wp-content/b.txt', str_repeat( 'x', 4096 ) ),
					self::file_plan( 'wp-content/c.txt', 'tiny' ),
				)
			),
			self::limits_with_entry_bytes( 1000 ),
			1073741824
		)->host_findings()[ RestorePreflight::CHECK_ENTRY_SIZE ];

		$this->assertStringStartsWith( '2 entries have', $many );
		$this->assertStringContainsString( 'wp-content/b.txt', $many );
		$this->assertStringNotContainsString( 'wp-content/a.txt', $many );
	}

	/**
	 * An entry above the memory budget is a HOST finding.
	 *
	 * @return void
	 */
	public function test_an_entry_over_the_memory_budget_is_a_host_finding(): void {
		$source = self::build_archive_stream( array( self::file_plan( 'wp-content/big.txt', str_repeat( 'x', 4096 ) ) ) );

		$report = $this->report_with( $source, null, 1000 );

		$this->assertTrue( $report->host_cannot_restore() );
		$this->assertFalse( $report->archive_is_refused() );
		$this->assertArrayNotHasKey( RestorePreflight::CHECK_ENTRY_MEMORY, $report->inconclusive() );
		$this->assertArrayHasKey( RestorePreflight::CHECK_ENTRY_MEMORY, $report->host_findings() );
		$this->assertContains( RestorePreflight::CHECK_ENTRY_MEMORY, $report->checks_run() );
		$message = $report->host_findings()[ RestorePreflight::CHECK_ENTRY_MEMORY ];
		$this->assertStringStartsWith( 'One entry has', $message );
		$this->assertStringNotContainsString( '1 entries', $message );
		$this->assertStringContainsString( 'wp-content/big.txt', $message );
		$this->assertStringContainsString( '1,000', $message );
	}

	/**
	 * An entry within the memory budget passes, and the check is recorded as run.
	 *
	 * @return void
	 */
	public function test_entries_within_the_memory_budget_pass(): void {
		$source = self::build_archive_stream( array( self::file_plan( 'wp-content/ok.txt', 'hello' ) ) );

		$report = $this->report_with( $source, null, 1073741824 );

		$this->assertArrayNotHasKey( RestorePreflight::CHECK_ENTRY_MEMORY, $report->host_findings() );
		$this->assertArrayNotHasKey( RestorePreflight::CHECK_ENTRY_MEMORY, $report->inconclusive() );
		$this->assertContains( RestorePreflight::CHECK_ENTRY_MEMORY, $report->checks_run() );
	}

	/**
	 * With no memory budget the check has nothing to judge by, and records nothing.
	 *
	 * A budget of 0 means the host enforces no memory cap, so a restore can never
	 * refuse on memory grounds. That is not an unanswered question, so it is not
	 * inconclusive either: the check ran, found nothing, and the report is clear.
	 *
	 * @return void
	 */
	public function test_no_memory_budget_records_nothing_and_stays_clear(): void {
		$source = self::build_archive_stream( array( self::file_plan( 'wp-content/big.txt', str_repeat( 'x', 4096 ) ) ) );

		$report = $this->report_with( $source, null, 0 );

		$this->assertContains( RestorePreflight::CHECK_ENTRY_MEMORY, $report->checks_run() );
		$this->assertArrayNotHasKey( RestorePreflight::CHECK_ENTRY_MEMORY, $report->archive_findings() );
		$this->assertArrayNotHasKey( RestorePreflight::CHECK_ENTRY_MEMORY, $report->host_findings() );
		$this->assertArrayNotHasKey( RestorePreflight::CHECK_ENTRY_MEMORY, $report->inconclusive() );
		$this->assertTrue( $report->is_clear(), implode( ' | ', $report->messages() ) );
	}

	/**
	 * If the shared header pass cannot be completed, all three checks say so as inconclusive.
	 *
	 * The stream handed to the preflight is empty, so the first header read fails
	 * deterministically. None of the three checks can then answer, and none may
	 * condemn the archive or the host for it.
	 *
	 * @return void
	 */
	public function test_an_unreadable_header_makes_the_three_new_checks_inconclusive(): void {
		$real     = self::build_archive_stream( array( self::file_plan( 'wp-content/ok.txt', 'hello' ) ) );
		$manifest = self::manifest_of( $real );
		$empty    = self::memory_stream();

		$preflight = new RestorePreflight(
			new EntryReader( CodecRegistry::with_defaults() ),
			new FileWriter( $this->fixture_root ),
			null,
			1073741824
		);
		$report    = $preflight->read_only_report( $empty, $manifest, null );

		foreach ( array( RestorePreflight::CHECK_ENTRY_SIZE, RestorePreflight::CHECK_ENTRY_MEMORY, RestorePreflight::CHECK_PATH_LENGTH ) as $check ) {
			$this->assertContains( $check, $report->checks_run() );
			$this->assertArrayHasKey( $check, $report->inconclusive() );
			$this->assertArrayNotHasKey( $check, $report->archive_findings() );
			$this->assertArrayNotHasKey( $check, $report->host_findings() );
		}
		$this->assertFalse( $report->is_clear() );
	}

	/**
	 * A path component over 255 bytes is a HOST finding naming the path.
	 *
	 * @return void
	 */
	public function test_a_path_component_over_255_bytes_is_a_host_finding(): void {
		$path   = 'wp-content/uploads/' . str_repeat( 'a', 256 ) . '.txt';
		$source = self::build_archive_stream( array( self::file_plan( $path, 'hello' ) ) );

		$report = $this->report_with( $source, null, 1073741824 );

		$this->assertTrue( $report->host_cannot_restore() );
		$this->assertFalse( $report->archive_is_refused() );
		$this->assertArrayNotHasKey( RestorePreflight::CHECK_PATH_LENGTH, $report->inconclusive() );
		$this->assertContains( RestorePreflight::CHECK_PATH_LENGTH, $report->checks_run() );
		$message = $report->host_findings()[ RestorePreflight::CHECK_PATH_LENGTH ];
		$this->assertStringStartsWith( 'One entry has', $message );
		$this->assertStringNotContainsString( '1 entries', $message );
		$this->assertStringContainsString( 'wp-content/uploads/aaaa', $message );
	}

	/**
	 * A component of exactly 255 bytes passes, and the whole path may be far longer.
	 *
	 * @return void
	 */
	public function test_a_component_of_exactly_255_bytes_passes(): void {
		$path   = 'wp-content/' . str_repeat( 'a', 255 ) . '/' . str_repeat( 'b', 251 ) . '.txt';
		$source = self::build_archive_stream( array( self::file_plan( $path, 'hello' ) ) );

		$report = $this->report_with( $source, null, 1073741824 );

		$this->assertArrayNotHasKey( RestorePreflight::CHECK_PATH_LENGTH, $report->host_findings() );
		$this->assertContains( RestorePreflight::CHECK_PATH_LENGTH, $report->checks_run() );
	}

	/**
	 * A name under 255 CHARACTERS but over 255 BYTES is refused.
	 *
	 * This is the case an mb_strlen() implementation would wrongly pass: 128
	 * two-byte characters are 128 characters and 256 bytes. The message also
	 * carries the name escaped, never as raw bytes.
	 *
	 * @return void
	 */
	public function test_a_multibyte_name_is_measured_in_bytes_not_characters(): void {
		$name = str_repeat( "\u{E9}", 128 );
		$this->assertSame( 128, mb_strlen( $name ) );
		$this->assertSame( 256, strlen( $name ) );

		$source = self::build_archive_stream( array( self::file_plan( 'wp-content/' . $name, 'hello' ) ) );

		$report = $this->report_with( $source, null, 1073741824 );

		$this->assertArrayHasKey( RestorePreflight::CHECK_PATH_LENGTH, $report->host_findings() );
		$message = $report->host_findings()[ RestorePreflight::CHECK_PATH_LENGTH ];
		$this->assertStringContainsString( '\\xC3\\xA9', $message );
		$this->assertSame( 1, preg_match( '/^[\x20-\x7E]*$/', $message ), 'The message must hold printable ASCII only.' );
		$this->assertLessThan( 600, strlen( $message ), 'A long path must be shortened in the message.' );
	}

	/**
	 * The three new checks run alongside the existing ones, so one finding does not hide another.
	 *
	 * @return void
	 */
	public function test_the_new_checks_do_not_hide_the_old_ones(): void {
		$source = self::build_archive_stream(
			array(
				self::file_plan( 'wp-content/big.txt', str_repeat( 'x', 4096 ) ),
				self::symlink_plan( 'wp-content/leak', '../../../../etc/passwd' ),
			)
		);

		$report = $this->report_with( $source, self::limits_with_entry_bytes( 1000 ), 2000 );

		$this->assertArrayHasKey( RestorePreflight::CHECK_SYMLINK_CONFINEMENT, $report->archive_findings() );
		$this->assertArrayHasKey( RestorePreflight::CHECK_ENTRY_SIZE, $report->host_findings() );
		$this->assertArrayHasKey( RestorePreflight::CHECK_ENTRY_MEMORY, $report->host_findings() );
	}

	// -------------------------------------------------------------------------
	// The write-probe stays out of the read-only path.
	// -------------------------------------------------------------------------

	/**
	 * The read-only report must never invoke the symlink-creation probe.
	 *
	 * That probe establishes host capability by creating a real symbolic link and
	 * removing it again. Verify promises to write nothing, so this is not a matter
	 * of taste: if the read-only path ever reached the probe, verify would be
	 * breaking its own contract on every archive containing a link.
	 *
	 * @return void
	 */
	public function test_the_read_only_report_never_runs_the_write_probe(): void {
		$probed = false;
		$probe  = function ( string $directory ) use ( &$probed ): bool {
			unset( $directory );
			$probed = true;
			return true;
		};

		$source    = self::build_archive_stream( array( self::symlink_plan( 'wp-content/link', 'ok.txt' ) ) );
		$preflight = new RestorePreflight(
			new EntryReader( CodecRegistry::with_defaults() ),
			new FileWriter( $this->fixture_root, false, null, null, $probe )
		);

		$preflight->read_only_report( $source, self::manifest_of( $source ), null );

		$this->assertFalse( $probed, 'read_only_report() must not create a test symlink.' );
	}

	/**
	 * The write-probe path does run the probe, and throws when it fails.
	 *
	 * @return void
	 */
	public function test_assert_host_can_write_runs_the_probe_and_throws(): void {
		$source    = self::build_archive_stream( array( self::symlink_plan( 'wp-content/link', 'ok.txt' ) ) );
		$preflight = new RestorePreflight(
			new EntryReader( CodecRegistry::with_defaults() ),
			new FileWriter( $this->fixture_root, false, null, null, static fn (): bool => false )
		);

		$this->expectException( \Pontifex\Exception\HostCannotComply::class );
		$this->expectExceptionMessageMatches( '/could not create a test link/' );

		$preflight->assert_host_can_write( $source, self::manifest_of( $source ) );
	}

	// -------------------------------------------------------------------------
	// PreflightReport itself.
	// -------------------------------------------------------------------------

	/**
	 * An empty report is clear, and a report with only an inconclusive check is not.
	 *
	 * "Nothing was decided" is not the same as "nothing is wrong", and a caller
	 * asking is_clear() is entitled to know the difference.
	 *
	 * @return void
	 */
	public function test_inconclusive_findings_stop_a_report_being_clear(): void {
		$this->assertTrue( ( new PreflightReport( array() ) )->is_clear() );

		$report = new PreflightReport( array( 'x' ), array(), array(), array( 'x' => 'could not read' ) );

		$this->assertFalse( $report->is_clear() );
		$this->assertFalse( $report->archive_is_refused() );
		$this->assertFalse( $report->host_cannot_restore() );
	}

	/**
	 * Archive findings lead the flat message list, because they cannot be worked around.
	 *
	 * @return void
	 */
	public function test_archive_findings_come_first_in_the_message_list(): void {
		$report = new PreflightReport(
			array( 'a', 'b' ),
			array( 'a' => 'the archive is wrong' ),
			array( 'b' => 'the host is full' )
		);

		$this->assertSame( array( 'the archive is wrong', 'the host is full' ), $report->messages() );
	}

	// -------------------------------------------------------------------------
	// Helpers.
	// -------------------------------------------------------------------------

	/**
	 * A codec this host cannot decode is a HOST finding, never an archive one.
	 *
	 * Verify hashes stored bytes and never decodes, so without this check a zstd
	 * backup is called sound on a host with no zstd extension and then fails to
	 * restore. The registry here holds a codec whose decode() throws what the zstd
	 * codec throws when its extension is missing.
	 *
	 * @return void
	 */
	public function test_a_codec_this_host_cannot_decode_is_a_host_finding(): void {
		$source = self::build_archive_stream( array( self::file_plan( 'wp-content/ok.txt', 'hello' ) ) );

		$report = $this->report_with_registry( $source, self::unavailable_codec( RawCodec::ID ) );

		$this->assertContains( RestorePreflight::CHECK_CODEC_SUPPORT, $report->checks_run() );
		$this->assertArrayHasKey( RestorePreflight::CHECK_CODEC_SUPPORT, $report->host_findings() );
		$this->assertArrayNotHasKey( RestorePreflight::CHECK_CODEC_SUPPORT, $report->archive_findings() );
		$this->assertArrayNotHasKey( RestorePreflight::CHECK_CODEC_SUPPORT, $report->inconclusive() );
		$this->assertStringContainsString( 'not damaged', $report->host_findings()[ RestorePreflight::CHECK_CODEC_SUPPORT ] );
	}

	/**
	 * An ordinary archive runs the codec check and records nothing for it.
	 *
	 * @return void
	 */
	public function test_an_ordinary_archive_records_nothing_for_codec_support(): void {
		$source = self::build_archive_stream( array( self::file_plan( 'wp-content/ok.txt', 'hello' ) ) );

		$report = $this->report_for( $source );

		$this->assertContains( RestorePreflight::CHECK_CODEC_SUPPORT, $report->checks_run() );
		$this->assertArrayNotHasKey( RestorePreflight::CHECK_CODEC_SUPPORT, $report->host_findings() );
		$this->assertArrayNotHasKey( RestorePreflight::CHECK_CODEC_SUPPORT, $report->archive_findings() );
		$this->assertArrayNotHasKey( RestorePreflight::CHECK_CODEC_SUPPORT, $report->inconclusive() );
	}

	/**
	 * The codec check reads the manifest, so an unreadable header does not silence it.
	 *
	 * @return void
	 */
	public function test_the_codec_check_still_answers_when_an_entry_header_cannot_be_read(): void {
		$real     = self::build_archive_stream( array( self::file_plan( 'wp-content/ok.txt', 'hello' ) ) );
		$manifest = self::manifest_of( $real );
		$empty    = self::memory_stream();

		$registry = new CodecRegistry();
		$registry->register( self::unavailable_codec( RawCodec::ID ) );
		$preflight = new RestorePreflight(
			new EntryReader( $registry ),
			new FileWriter( $this->fixture_root )
		);
		$report    = $preflight->read_only_report( $empty, $manifest, null );

		$this->assertArrayHasKey( RestorePreflight::CHECK_CODEC_SUPPORT, $report->host_findings() );
		$this->assertArrayNotHasKey( RestorePreflight::CHECK_CODEC_SUPPORT, $report->inconclusive() );
	}

	/**
	 * Run the read-only report with one extra codec registered in place of the real one.
	 *
	 * @param resource $source The archive stream.
	 * @param Codec    $codec  The codec to register in an otherwise empty registry.
	 * @return PreflightReport
	 */
	private function report_with_registry( $source, Codec $codec ): PreflightReport {
		$manifest = self::manifest_of( $source );
		$scope    = self::scope_of( $source );
		$registry = new CodecRegistry();
		$registry->register( $codec );

		$preflight = new RestorePreflight(
			new EntryReader( $registry ),
			new FileWriter( $this->fixture_root )
		);

		return $preflight->read_only_report( $source, $manifest, $scope );
	}

	/**
	 * A codec whose decode() fails the way a missing PHP extension makes it fail.
	 *
	 * @param int $id The codec id to report.
	 * @return Codec
	 */
	private static function unavailable_codec( int $id ): Codec {
		return new class( $id ) implements Codec {
			/**
			 * The codec id this double reports.
			 *
			 * @var int
			 */
			private int $stub_id;

			/**
			 * Construct the double.
			 *
			 * @param int $id The codec id to report.
			 */
			public function __construct( int $id ) {
				$this->stub_id = $id;
			}

			/**
			 * Return the configured id.
			 *
			 * @return int
			 */
			public function id(): int {
				return $this->stub_id;
			}

			/**
			 * No-op encode.
			 *
			 * @param resource      $input   Ignored.
			 * @param resource      $output  Ignored.
			 * @param callable|null $on_read Ignored.
			 * @return int Always zero.
			 */
			public function encode( $input, $output, ?callable $on_read = null ): int {
				return 0;
			}

			/**
			 * Always report the extension as missing.
			 *
			 * @param resource $input            Ignored.
			 * @param resource $output           Ignored.
			 * @param int|null $max_output_bytes Ignored.
			 * @return never
			 * @throws CodecUnavailableException Always.
			 */
			public function decode( $input, $output, ?int $max_output_bytes = null ): never {
				throw new CodecUnavailableException( 'The required PHP extension is not loaded.' );
			}
		};
	}

	/**
	 * Run the read-only report over an archive stream, rooted at the fixture.
	 *
	 * @param resource      $source          The archive stream.
	 * @param callable|null $disk_free_space Optional free-space stub.
	 * @return PreflightReport
	 */
	private function report_for( $source, ?callable $disk_free_space = null ): PreflightReport {
		$manifest = self::manifest_of( $source );
		$scope    = self::scope_of( $source );

		$preflight = new RestorePreflight(
			new EntryReader( CodecRegistry::with_defaults() ),
			new FileWriter( $this->fixture_root, false, null, $disk_free_space )
		);

		return $preflight->read_only_report( $source, $manifest, $scope );
	}

	/**
	 * Run the read-only report with explicit limits and memory budget.
	 *
	 * @param resource           $source The archive stream.
	 * @param ArchiveLimits|null $limits Optional limits; null applies the defaults.
	 * @param int|null           $budget Optional per-entry memory budget; null or 0 means none.
	 * @return PreflightReport
	 */
	private function report_with( $source, ?ArchiveLimits $limits, ?int $budget ): PreflightReport {
		$manifest  = self::manifest_of( $source );
		$scope     = self::scope_of( $source );
		$preflight = new RestorePreflight(
			new EntryReader( CodecRegistry::with_defaults() ),
			new FileWriter( $this->fixture_root ),
			$limits,
			$budget
		);

		return $preflight->read_only_report( $source, $manifest, $scope );
	}

	/**
	 * Default limits with only the per-entry byte ceiling lowered.
	 *
	 * @param int $max_entry_bytes The per-entry ceiling.
	 * @return ArchiveLimits
	 */
	private static function limits_with_entry_bytes( int $max_entry_bytes ): ArchiveLimits {
		return new ArchiveLimits(
			ArchiveLimits::DEFAULT_MAX_ENTRY_COUNT,
			$max_entry_bytes,
			ArchiveLimits::DEFAULT_MAX_TOTAL_RATIO,
			ArchiveLimits::DEFAULT_MAX_TOTAL_BYTES
		);
	}

	/**
	 * Read the manifest from an archive stream, rewinding afterwards.
	 *
	 * @param resource $source The archive stream.
	 * @return \Pontifex\Archive\Format\ArchiveManifest
	 */
	private static function manifest_of( $source ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Test stream resource, not a filesystem path.
		rewind( $source );
		return ( new ArchiveReader( $source ) )->manifest();
	}

	/**
	 * Read the recorded scope from an archive stream.
	 *
	 * @param resource $source The archive stream.
	 * @return Scope|null
	 */
	private static function scope_of( $source ): ?Scope {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Test stream resource, not a filesystem path.
		rewind( $source );
		return ( new ArchiveReader( $source ) )->provenance()->scope();
	}

	/**
	 * Build an in-memory archive from entry plans.
	 *
	 * @param EntryPlan[] $plans The entries to write.
	 * @param Scope|null  $scope Optional recorded scope.
	 * @return resource A readable, seekable stream of archive bytes.
	 */
	private static function build_archive_stream( array $plans, ?Scope $scope = null ) {
		$destination = self::memory_stream();
		$writer      = new ArchiveWriter( new EntryWriter( CodecRegistry::with_defaults() ), new FooterWriter() );
		$writer->write_archive( self::sample_provenance( $scope ), $plans, $destination );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Test stream resource, not a filesystem path.
		rewind( $destination );
		return $destination;
	}

	/**
	 * A provenance block for the test archives.
	 *
	 * @param Scope|null $scope Optional recorded scope.
	 * @return Provenance
	 */
	private static function sample_provenance( ?Scope $scope = null ): Provenance {
		return new Provenance(
			'6.6.1',
			'8.2.10',
			'https://example.test',
			'utf8mb4',
			'utf8mb4_unicode_520_ci',
			new ExporterInfo( 'pontifex', '1.0.3' ),
			new DateTimeImmutable( '2026-08-05T10:00:00+00:00', new DateTimeZone( 'UTC' ) ),
			null,
			null,
			$scope
		);
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
	 * Build an EntryPlan for a symlink entry.
	 *
	 * @param string $path   Relative path inside the archive.
	 * @param string $target The link target.
	 * @return EntryPlan
	 */
	private static function symlink_plan( string $path, string $target ): EntryPlan {
		$header = EntryHeader::for_symlink( $path, $target, 0 );
		return new EntryPlan( $header, RawCodec::ID, str_repeat( "\0", EntryWriter::NONCE_SIZE ), self::memory_stream() );
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

	/**
	 * Recursively remove a directory tree created by a test.
	 *
	 * @param string $path The tree to remove.
	 * @return void
	 */
	private static function remove_tree( string $path ): void {
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( array_diff( (array) scandir( $path ), array( '.', '..' ) ) as $entry ) {
			$child = $path . '/' . $entry;
			if ( is_link( $child ) || is_file( $child ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test-only cleanup of a tree the test itself created.
				unlink( $child );
				continue;
			}
			self::remove_tree( $child );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test-only cleanup of a tree the test itself created.
		rmdir( $path );
	}
}
