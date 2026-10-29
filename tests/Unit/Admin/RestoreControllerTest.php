<?php
/**
 * Tests for RestoreController — the admin-ajax endpoints behind the Restore screen.
 *
 * @package Pontifex\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Admin;

require_once __DIR__ . '/../Manifest/Fakes/WpdbStub.php';

use Brain\Monkey\Functions;
use DateTimeImmutable;
use DateTimeZone;
use Mockery;
use Pontifex\Admin\BackupStore;
use Pontifex\Admin\RestoreController;
use Pontifex\Archive\Codec\CodecRegistry;
use Pontifex\Archive\Codec\RawCodec;
use Pontifex\Archive\Crypto\Cipher;
use Pontifex\Archive\Crypto\EncryptionContext;
use Pontifex\Archive\Crypto\OpensslAesGcmCipher;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Format\ExporterInfo;
use Pontifex\Archive\Format\Provenance;
use Pontifex\Archive\Format\Scope;
use Pontifex\Archive\Reader\EntryReader;
use Pontifex\Archive\Writer\ArchiveWriter;
use Pontifex\Archive\Writer\EntryPlan;
use Pontifex\Archive\Writer\EntryWriter;
use Pontifex\Archive\Writer\FooterWriter;
use Pontifex\Environment\Environment;
use Pontifex\Exception\BuildCannotComply;
use Pontifex\Exception\HostCannotComply;
use Pontifex\Job\Job;
use Pontifex\Job\JobStore;
use Pontifex\Lock\OperationLock;
use Pontifex\Migrate\RewriteReport;
use Pontifex\Migrate\UrlMigratorInterface;
use Pontifex\Restore\DatabaseWriter;
use Pontifex\Restore\FileWriter;
use Pontifex\Restore\RestoreRunner;
use Pontifex\Restore\RestoreRunnerInterface;
use Pontifex\Rollback\RollbackStoreInterface;
use Pontifex\Rollback\SafetyArchiverInterface;
use Pontifex\Tests\TestCase;
use Pontifex\Tests\Unit\Manifest\Fakes\FakeDbAdapter;
use Pontifex\WordPress\WordPressContext;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionClassConstant;
use ReflectionMethod;
use RuntimeException;
use wpdb;

/**
 * Covers the controller's authorisation gates, the restore and rollback flows,
 * and the safety invariant that a broken backup is never written.
 *
 * The restore engine and the safety archiver are injected doubles, so the
 * handler is exercised without ever writing to the filesystem or the database —
 * the real wiring is covered by the dev-site browser test and the CLI round-trip,
 * matching how the Backup and Verify screens' default engines are covered. The
 * controller's own encryption pre-check still runs against a real (empty) plain
 * archive on disk. WordPress functions are stubbed with brain/monkey:
 * wp_send_json_error and wp_die throw, so a refused request halts as in
 * production; wp_send_json_success captures its payload.
 */
final class RestoreControllerTest extends TestCase {

	/**
	 * Temporary content directory the store is rooted at for one test.
	 *
	 * @var string
	 */
	private string $base = '';

	/**
	 * The most recent JSON response captured from the stubbed responders.
	 *
	 * @var array<string, mixed>
	 */
	private array $json = array();

	/**
	 * Reserve a unique temp content directory and reset the capture.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->base = sys_get_temp_dir() . '/pontifex-restore-controller-' . uniqid( '', true );
		$this->json = array();
		// The relink flag is read through sanitize_text_field on every restore; stub it
		// as the identity so the controller's own logic is what the tests exercise.
		Functions\when( 'sanitize_text_field' )->returnArg();
	}

	/**
	 * Remove the temp directory tree.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		self::rmtree( $this->base );
		parent::tearDown();
	}

	/**
	 * Refuses to restore without the managing capability.
	 *
	 * @return void
	 */
	public function test_restore_refuses_without_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->stub_json();

		try {
			$this->controller()->restore();
			$this->fail( 'restore() should refuse without the capability.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( 'pontifex-json-halt', $error->getMessage() );
		}

		$this->assertFalse( $this->json['success'] );
		$this->assertSame( 403, $this->json['status'] );
	}

	/**
	 * Refuses a filename that does not resolve to a real backup.
	 *
	 * @return void
	 */
	public function test_restore_refuses_an_unresolved_file(): void {
		$this->authorise();
		$this->stub_json();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_file_name' )->returnArg();
		$_POST['file'] = '../secret.txt';

		try {
			$this->controller()->restore();
			$this->fail( 'restore() should refuse a name that does not resolve.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( 'pontifex-json-halt', $error->getMessage() );
		} finally {
			unset( $_POST['file'] );
		}

		$this->assertFalse( $this->json['success'] );
		$this->assertSame( 404, $this->json['status'] );
	}

	/**
	 * Restores a sound backup: verifies, takes the safety archive, then restores.
	 *
	 * @return void
	 */
	public function test_restore_reports_a_successful_restore(): void {
		$this->authorise();
		$this->stub_json();
		$this->stub_transients();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_file_name' )->returnArg();

		$store = new BackupStore( $this->base );
		$store->ensure_directory();
		$name = 'pontifex-backup-20260101T000000Z.wpmig';
		$this->write_plain_archive( $store->directory() . '/' . $name );
		$_POST['file'] = $name;

		$runner = Mockery::mock( RestoreRunnerInterface::class );
		$runner->shouldReceive( 'verify' )->once();
		$runner->shouldReceive( 'restore' )->once()->andReturnUsing(
			static function ( $source, ?callable $on_entry, ?callable $on_bytes ): void {
				unset( $source, $on_bytes );
				if ( null !== $on_entry ) {
					$on_entry( 3, 3 );
				}
			}
		);
		$archiver = $this->safety_archiver_double();

		try {
			$this->controller( $runner, $archiver )->restore();
		} finally {
			unset( $_POST['file'] );
		}

		$this->assertTrue( $this->json['success'] );
		$this->assertTrue( $this->json['data']['restored'] );
		$this->assertSame( 3, $this->json['data']['entries'] );
	}

	/**
	 * The opt-in box rewrites the backup's links to THIS site's URL after the restore.
	 *
	 * With the migrate flag set, the controller reads the archive's source URL and
	 * rewrites it to the destination's own site_url() — never a typed address — so a
	 * backup taken on another server serves correctly here. The source URL is read
	 * from the still-open archive after the restore; the target is the live site. No
	 * login_url is returned: the site has not moved, so the page's own login still
	 * applies.
	 *
	 * @return void
	 */
	public function test_restore_with_the_migrate_flag_rewrites_links_to_this_site(): void {
		$this->authorise();
		$this->stub_json();
		$this->stub_transients();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_file_name' )->returnArg();

		$store = new BackupStore( $this->base );
		$store->ensure_directory();
		$name = 'pontifex-backup-20260101T000000Z.wpmig';
		$this->write_plain_archive( $store->directory() . '/' . $name );
		$_POST['file']    = $name;
		$_POST['migrate'] = '1';

		$runner = Mockery::mock( RestoreRunnerInterface::class );
		$runner->shouldReceive( 'verify' )->once();
		$runner->shouldReceive( 'restore' )->once();

		$report = new RewriteReport( 3, array(), 10, 7, 7, 0 );

		$migrator = Mockery::mock( UrlMigratorInterface::class );
		$migrator->shouldReceive( 'source_url' )->once()->andReturn( 'https://old-site.example' );
		$migrator->shouldReceive( 'migrate' )->once()
			->with( 'https://old-site.example', 'https://this-site.test' )
			->andReturn( $report );

		$controller = new RestoreController(
			$this->environment(),
			$this->context(),
			new BackupStore( $this->base ),
			Mockery::mock( RollbackStoreInterface::class ),
			new NullLogger(),
			$runner,
			$this->safety_archiver_double(),
			$migrator
		);

		try {
			$controller->restore();
		} finally {
			unset( $_POST['file'], $_POST['migrate'] );
		}

		$this->assertTrue( $this->json['success'] );
		$this->assertTrue( $this->json['data']['restored'] );
		$this->assertArrayNotHasKey( 'login_url', $this->json['data'], 'A same-site relink does not move the site, so no new login URL is returned.' );
	}

	/**
	 * The relink rewrites to the address this site had BEFORE the restore replaced it.
	 *
	 * The restore replays the database, wp_options included, so the moment it has run
	 * this site's recorded URL is the backup's. A controller that asks for its own
	 * address afterwards is handed the archive's URL and calls migrate( old, old ): a
	 * rewrite that changes nothing, reported as a success, with the site carrying on
	 * serving the old domain. A context whose site_url() is a constant cannot express
	 * that, so here it answers with this site's address until the fake restore has run
	 * and with the backup's address after it, as a real site does.
	 *
	 * @return void
	 */
	public function test_relink_rewrites_to_the_address_captured_before_the_restore_replaced_it(): void {
		$restored = false;
		$context  = $this->context(
			static function () use ( &$restored ): string {
				return $restored ? 'https://old-site.example' : 'https://this-site.test';
			}
		);

		$runner = Mockery::mock( RestoreRunnerInterface::class );
		$runner->shouldReceive( 'verify' )->once();
		$runner->shouldReceive( 'restore' )->once()->andReturnUsing(
			static function () use ( &$restored ): void {
				$restored = true;
			}
		);

		$calls    = array();
		$migrator = $this->recording_migrator( 'https://old-site.example', $calls );

		$this->run_relink_restore( $context, $migrator, $runner );

		$this->assertSame(
			array( array( 'https://old-site.example', 'https://this-site.test' ) ),
			$calls,
			'The target must be this site\'s address from before the restore, not the backup\'s own address read back after it.'
		);
		$this->assertTrue( $this->json['success'] );
		$this->assertStringContainsString( 'relinked', $this->json['data']['message'] );
	}

	/**
	 * A backup from another site still runs the rewrite and reports it.
	 *
	 * The counterpart to the same-site and no-source-URL cases below: the guard that
	 * skips those must not switch the feature off for the backup it exists for.
	 *
	 * @return void
	 */
	public function test_relink_still_runs_and_is_reported_for_a_backup_from_another_site(): void {
		$calls    = array();
		$migrator = $this->recording_migrator( 'https://old-site.example', $calls );

		$this->run_relink_restore( $this->context(), $migrator );

		$this->assertSame( array( array( 'https://old-site.example', 'https://this-site.test' ) ), $calls );
		$this->assertTrue( $this->json['success'] );
		$this->assertStringStartsWith( 'Restored and relinked — ', $this->json['data']['message'] );
		$this->assertStringContainsString( '7 database rows', $this->json['data']['message'] );
	}

	/**
	 * A backup taken at this site's own address skips the rewrite and reports a plain restore.
	 *
	 * The comparison is the one the preview hint uses, so a difference of case or a
	 * trailing slash does not make the same site look like another. With nothing to
	 * rewrite, the response must not claim a relink that did no work, and the skip is
	 * logged so the reason can be traced.
	 *
	 * @return void
	 */
	public function test_relink_is_skipped_when_the_backup_is_from_this_same_site(): void {
		$calls    = array();
		$migrator = $this->recording_migrator( 'https://This-Site.test/', $calls );

		$logger = Mockery::mock( LoggerInterface::class );
		$logger->shouldReceive( 'info' )->once();

		$this->run_relink_restore( $this->context(), $migrator, null, $logger );

		$this->assertSame( array(), $calls, 'A backup from this same site has nothing to rewrite.' );
		$this->assertTrue( $this->json['success'] );
		$this->assertTrue( $this->json['data']['restored'] );
		$this->assertStringStartsWith( 'Restored — ', $this->json['data']['message'] );
		$this->assertStringNotContainsString( 'relinked', $this->json['data']['message'] );
	}

	/**
	 * A backup recording no source URL skips the rewrite rather than rewriting from nothing.
	 *
	 * The rewriter refuses an empty search term by throwing, and by this point the
	 * database has already been replaced, so that throw would roll back a restore that
	 * had in fact succeeded. The migrator double stands in for any implementation that
	 * reports an empty source URL.
	 *
	 * @return void
	 */
	public function test_relink_is_skipped_when_the_backup_records_no_source_url(): void {
		$calls    = array();
		$migrator = $this->recording_migrator( '', $calls );

		$logger = Mockery::mock( LoggerInterface::class );
		$logger->shouldReceive( 'info' )->once();

		$this->run_relink_restore( $this->context(), $migrator, null, $logger );

		$this->assertSame( array(), $calls, 'There is nothing to rewrite from, so no rewrite may be attempted.' );
		$this->assertTrue( $this->json['success'] );
		$this->assertTrue( $this->json['data']['restored'] );
		$this->assertStringStartsWith( 'Restored — ', $this->json['data']['message'] );
	}

	/**
	 * Without the migrate flag, an ordinary restore never touches the migrator.
	 *
	 * The default — the box unticked — must leave the link rewriter untouched, so an
	 * ordinary restore is unaffected. An injected migrator whose methods are forbidden
	 * proves the rewrite path is not entered.
	 *
	 * @return void
	 */
	public function test_restore_without_the_migrate_flag_does_not_rewrite_links(): void {
		$this->authorise();
		$this->stub_json();
		$this->stub_transients();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_file_name' )->returnArg();

		$store = new BackupStore( $this->base );
		$store->ensure_directory();
		$name = 'pontifex-backup-20260101T000000Z.wpmig';
		$this->write_plain_archive( $store->directory() . '/' . $name );
		$_POST['file'] = $name;

		$runner = Mockery::mock( RestoreRunnerInterface::class );
		$runner->shouldReceive( 'verify' )->once();
		$runner->shouldReceive( 'restore' )->once();

		$migrator = Mockery::mock( UrlMigratorInterface::class );
		$migrator->shouldReceive( 'source_url' )->never();
		$migrator->shouldReceive( 'migrate' )->never();

		$controller = new RestoreController(
			$this->environment(),
			$this->context(),
			new BackupStore( $this->base ),
			Mockery::mock( RollbackStoreInterface::class ),
			new NullLogger(),
			$runner,
			$this->safety_archiver_double(),
			$migrator
		);

		try {
			$controller->restore();
		} finally {
			unset( $_POST['file'] );
		}

		$this->assertTrue( $this->json['success'] );
		$this->assertTrue( $this->json['data']['restored'] );
	}

	/**
	 * A successful restore flushes the stale option cache BEFORE recording, and
	 * records the attempt as well as the success.
	 *
	 * The restore replays the database with raw SQL, leaving WordPress's option
	 * cache holding pre-restore values; without a flush the post-restore counter
	 * write reads and writes stale state and is silently lost. This pins the
	 * flush-before-record ordering and that `attempted` is re-applied (it was
	 * wiped along with the replaced wp_options).
	 *
	 * @return void
	 */
	public function test_successful_restore_flushes_cache_before_recording_counters(): void {
		$this->authorise();
		$this->stub_json();
		$this->stub_transients();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_file_name' )->returnArg();

		$store = new BackupStore( $this->base );
		$store->ensure_directory();
		$name = 'pontifex-backup-20260101T000000Z.wpmig';
		$this->write_plain_archive( $store->directory() . '/' . $name );
		$_POST['file'] = $name;

		$runner = Mockery::mock( RestoreRunnerInterface::class );
		$runner->shouldReceive( 'verify' )->once();
		$runner->shouldReceive( 'restore' )->once()->andReturnUsing(
			static function ( $source, ?callable $on_entry, ?callable $on_bytes ): void {
				unset( $source, $on_bytes );
				if ( null !== $on_entry ) {
					$on_entry( 1, 1 );
				}
			}
		);

		$flushed       = false;
		$flushed_first = null;
		$stats         = null;
		$context       = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'format_size' )->andReturnUsing(
			static function ( int $bytes ): string {
				return $bytes . ' B';
			}
		);
		$context->shouldReceive( 'option_value' )->andReturnUsing(
			static function ( string $name, $fallback = false ) {
				unset( $name );
				return $fallback;
			}
		);
		$context->shouldReceive( 'flush_cache' )->once()->andReturnUsing(
			static function () use ( &$flushed ): void {
				$flushed = true;
			}
		);
		$context->shouldReceive( 'save_option' )->andReturnUsing(
			static function ( string $name, $value ) use ( &$flushed, &$flushed_first, &$stats ): void {
				if ( 'pontifex_import_stats' === $name ) {
					$flushed_first = $flushed;
					$stats         = $value;
				}
			}
		);
		$context->shouldReceive( 'acquire_named_lock' )->andReturn( true );
		$context->shouldReceive( 'release_named_lock' );

		$controller = new RestoreController(
			$this->environment(),
			$context,
			new BackupStore( $this->base ),
			Mockery::mock( RollbackStoreInterface::class ),
			new NullLogger(),
			$runner,
			$this->safety_archiver_double()
		);

		try {
			$controller->restore();
		} finally {
			unset( $_POST['file'] );
		}

		$this->assertTrue( $flushed, 'The cache must be flushed after the replay.' );
		$this->assertTrue( $flushed_first, 'Counters must be written AFTER the cache flush, not before.' );
		$this->assertIsArray( $stats );
		$this->assertSame( 1, $stats['attempted'], 'A successful restore must record the attempt.' );
		$this->assertSame( 1, $stats['succeeded'] );
		$this->assertSame( 0, $stats['failed'] );
		$this->assertArrayHasKey( 'bytes_imported', $stats );
	}

	/**
	 * Refuses a broken backup and writes nothing: no safety archive, no restore.
	 *
	 * The preview gate's whole purpose — a backup that fails verification must be
	 * refused before the safety archive or any write.
	 *
	 * @return void
	 */
	public function test_restore_refuses_a_broken_backup_without_writing(): void {
		$this->authorise();
		$this->stub_json();
		$this->stub_transients();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_file_name' )->returnArg();

		$store = new BackupStore( $this->base );
		$store->ensure_directory();
		$name = 'pontifex-backup-20260101T000000Z.wpmig';
		$this->write_plain_archive( $store->directory() . '/' . $name );
		$_POST['file'] = $name;

		$runner = Mockery::mock( RestoreRunnerInterface::class );
		$runner->shouldReceive( 'verify' )->once()->andThrow( new RuntimeException( 'entry 2 hash mismatch' ) );
		$runner->shouldReceive( 'restore' )->never();

		// The safety archive must never be taken for a broken backup.
		$archiver = Mockery::mock( SafetyArchiverInterface::class );
		$archiver->shouldReceive( 'create' )->never();

		try {
			$this->controller( $runner, $archiver )->restore();
		} finally {
			unset( $_POST['file'] );
		}

		$this->assertTrue( $this->json['success'], 'A broken verdict is reported as a JSON success.' );
		$this->assertFalse( $this->json['data']['restored'] );
	}

	/**
	 * A HostCannotComply from the verify gate is reported as "could not
	 * check", never as broken, and the safety archive is never taken.
	 *
	 * This host, not the backup, is what stopped verification — a low
	 * memory_limit unable to buffer a db_chunk it must decode whole is the
	 * case that keeps happening in practice, and WordPress's own default is
	 * 40 MB. Nothing was learned about the backup either way, so the message
	 * must not say "broken", and must not imply the operator should discard
	 * the file.
	 *
	 * @return void
	 */
	public function test_restore_reports_could_not_check_for_a_host_that_cannot_comply(): void {
		$this->authorise();
		$this->stub_json();
		$this->stub_transients();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_file_name' )->returnArg();

		$store = new BackupStore( $this->base );
		$store->ensure_directory();
		$name = 'pontifex-backup-20260101T000000Z.wpmig';
		$this->write_plain_archive( $store->directory() . '/' . $name );
		$_POST['file'] = $name;

		$runner = Mockery::mock( RestoreRunnerInterface::class );
		$runner->shouldReceive( 'verify' )->once()->andThrow( new HostCannotComply( 'Entry declares 41943040 decoded bytes, exceeding the 10485760-byte budget for this restore.' ) );
		$runner->shouldReceive( 'restore' )->never();

		// The safety archive must never be taken when the backup could not be checked.
		$archiver = Mockery::mock( SafetyArchiverInterface::class );
		$archiver->shouldReceive( 'create' )->never();

		try {
			$this->controller( $runner, $archiver )->restore();
		} finally {
			unset( $_POST['file'] );
		}

		$this->assertTrue( $this->json['success'], 'A could-not-check outcome is reported as a JSON success.' );
		$this->assertFalse( $this->json['data']['restored'] );
		$this->assertFalse( $this->json['data']['refused'] );
		$this->assertTrue( $this->json['data']['could_not_check'] );
		$this->assertStringNotContainsStringIgnoringCase( 'broken', $this->json['data']['message'], 'A could-not-check outcome must never use the word "broken".' );
	}

	/**
	 * The same HostCannotComply outcome, reported through rollback()'s own gate.
	 *
	 * @return void
	 */
	public function test_rollback_reports_could_not_check_for_a_host_that_cannot_comply(): void {
		$this->authorise();
		$this->stub_json();
		$this->stub_transients();

		$archive_path = $this->base . '/pre-import-rollback-20260101T000000Z.wpmig';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Seeding a temp directory for the placeholder safety archive.
		mkdir( $this->base, 0o755, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Seeding a placeholder safety archive; the injected engine stands in for reading it.
		file_put_contents( $archive_path, 'x' );

		$rollback = Mockery::mock( RollbackStoreInterface::class );
		$rollback->shouldReceive( 'most_recent' )->once()->andReturn( $archive_path );

		$runner = Mockery::mock( RestoreRunnerInterface::class );
		$runner->shouldReceive( 'verify' )->once()->andThrow( new HostCannotComply( 'Not enough free disk space for a safety archive.' ) );
		$runner->shouldReceive( 'restore' )->never();

		$this->controller( $runner, null, $rollback )->rollback();

		$this->assertTrue( $this->json['success'], 'A could-not-check outcome is reported as a JSON success.' );
		$this->assertFalse( $this->json['data']['rolled_back'] );
		$this->assertFalse( $this->json['data']['refused'] );
		$this->assertTrue( $this->json['data']['could_not_check'] );
		$this->assertStringNotContainsStringIgnoringCase( 'broken', $this->json['data']['message'], 'A could-not-check outcome must never use the word "broken".' );
	}

	/**
	 * A BuildCannotComply from the verify gate is reported as "refused", never
	 * as broken or could-not-check, and the safety archive is never taken.
	 *
	 * The archive is sound and this host is fine; a ceiling compiled into
	 * every build of Pontifex is what stopped the walk on an entry too large
	 * to process. That is GATE_REFUSED's own meaning — intact, but a restore
	 * will not accept it — so this must not fall into GATE_BROKEN (which
	 * would send the operator to fetch a fresh copy no build would accept
	 * either) or GATE_COULD_NOT_CHECK (which implies a server fix exists).
	 *
	 * @return void
	 */
	public function test_restore_reports_refused_for_a_build_that_cannot_comply(): void {
		$this->authorise();
		$this->stub_json();
		$this->stub_transients();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_file_name' )->returnArg();

		$store = new BackupStore( $this->base );
		$store->ensure_directory();
		$name = 'pontifex-backup-20260101T000000Z.wpmig';
		$this->write_plain_archive( $store->directory() . '/' . $name );
		$_POST['file'] = $name;

		$runner = Mockery::mock( RestoreRunnerInterface::class );
		$runner->shouldReceive( 'verify' )->once()->andThrow( new BuildCannotComply( 'Entry "wp-content/uploads/2024/huge-video.mov" declares 2200000000 decoded bytes, exceeding the 2147483648-byte budget for this restore.' ) );
		$runner->shouldReceive( 'restore' )->never();

		// The safety archive must never be taken for a refused backup.
		$archiver = Mockery::mock( SafetyArchiverInterface::class );
		$archiver->shouldReceive( 'create' )->never();

		try {
			$this->controller( $runner, $archiver )->restore();
		} finally {
			unset( $_POST['file'] );
		}

		$this->assertTrue( $this->json['success'], 'A refused outcome is reported as a JSON success.' );
		$this->assertFalse( $this->json['data']['restored'] );
		$this->assertTrue( $this->json['data']['refused'] );
		$this->assertFalse( $this->json['data']['could_not_check'] );
		$this->assertStringNotContainsStringIgnoringCase( 'broken', $this->json['data']['message'], 'A refused outcome must never use the word "broken".' );
	}

	/**
	 * The same BuildCannotComply outcome, reported through rollback()'s own gate.
	 *
	 * @return void
	 */
	public function test_rollback_reports_refused_for_a_build_that_cannot_comply(): void {
		$this->authorise();
		$this->stub_json();
		$this->stub_transients();

		$archive_path = $this->base . '/pre-import-rollback-20260101T000000Z.wpmig';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Seeding a temp directory for the placeholder safety archive.
		mkdir( $this->base, 0o755, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Seeding a placeholder safety archive; the injected engine stands in for reading it.
		file_put_contents( $archive_path, 'x' );

		$rollback = Mockery::mock( RollbackStoreInterface::class );
		$rollback->shouldReceive( 'most_recent' )->once()->andReturn( $archive_path );

		$runner = Mockery::mock( RestoreRunnerInterface::class );
		$runner->shouldReceive( 'verify' )->once()->andThrow( new BuildCannotComply( 'Entry "wp-content/uploads/2024/huge-video.mov" declares 2200000000 decoded bytes, exceeding the 2147483648-byte budget for this restore.' ) );
		$runner->shouldReceive( 'restore' )->never();

		$this->controller( $runner, null, $rollback )->rollback();

		$this->assertTrue( $this->json['success'], 'A refused outcome is reported as a JSON success.' );
		$this->assertFalse( $this->json['data']['rolled_back'] );
		$this->assertTrue( $this->json['data']['refused'] );
		$this->assertFalse( $this->json['data']['could_not_check'] );
		$this->assertStringNotContainsStringIgnoringCase( 'broken', $this->json['data']['message'], 'A refused outcome must never use the word "broken".' );
	}

	/**
	 * A restore that fails mid-replay is automatically rolled back to its prior state.
	 *
	 * The safety archive is taken, the replay then fails, and the failure handler replays
	 * that safety archive to recover the site — reported as an automatic rollback rather
	 * than a bare failure.
	 *
	 * The injected engine is a {@see RestoreRunnerInterface} mock, not a real
	 * {@see \Pontifex\Restore\RestoreRunner} — so it has no creation ledger to consult,
	 * and recovery honestly reports a MERGE rather than a precise revert (see
	 * {@see \Pontifex\Restore\FileWriter::remove_created_paths()} and
	 * RestoreController's own RECOVERY_* constants). Both outcomes share the phrase
	 * "rolled back to its state before the restore", asserted below; the PRECISE
	 * wording needs the real engine, exercised at the RestoreRunner/FileWriter level in
	 * {@see \Pontifex\Tests\Unit\Restore\RecoveryCreationLedgerTest}.
	 *
	 * @return void
	 */
	public function test_restore_auto_rolls_back_after_a_failed_replay(): void {
		$this->authorise();
		$this->stub_json();
		$this->stub_transients();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_file_name' )->returnArg();

		$store = new BackupStore( $this->base );
		$store->ensure_directory();
		$name = 'pontifex-backup-20260101T000000Z.wpmig';
		$this->write_plain_archive( $store->directory() . '/' . $name );
		$_POST['file'] = $name;

		// The safety archive the restore takes; recovery replays it, so it must be a real
		// (placeholder) file the injected engine reads.
		$safety_path = $this->base . '/pre-import-rollback-20260101T000000Z.wpmig';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Seeding a placeholder safety archive; the injected engine stands in for reading it.
		file_put_contents( $safety_path, 'x' );
		$archiver = Mockery::mock( SafetyArchiverInterface::class );
		$archiver->shouldReceive( 'create' )->once()->andReturn( $safety_path );

		// Forward: verify passes, replay throws. Recovery: verify passes, replay succeeds.
		$replays = 0;
		$runner  = Mockery::mock( RestoreRunnerInterface::class );
		$runner->shouldReceive( 'verify' )->twice();
		$runner->shouldReceive( 'restore' )->twice()->andReturnUsing(
			static function () use ( &$replays ): void {
				++$replays;
				if ( 1 === $replays ) {
					throw new RuntimeException( 'replay failed mid-restore' );
				}
			}
		);

		try {
			$this->controller( $runner, $archiver )->restore();
			$this->fail( 'restore() should have halted with a JSON error.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( 'pontifex-json-halt', $error->getMessage() );
		} finally {
			unset( $_POST['file'] );
		}

		$this->assertFalse( $this->json['success'] );
		$this->assertSame( 500, $this->json['status'] );
		$this->assertStringContainsString( 'rolled back to its state before the restore', $this->json['data']['message'] );
	}

	/**
	 * When the restore fails and the auto-rollback also fails, the operator is told plainly.
	 *
	 * The recovery replay is best-effort; if it too fails the site may be partially
	 * restored, and the message says so rather than implying a clean recovery.
	 *
	 * @return void
	 */
	public function test_restore_reports_when_auto_recovery_also_fails(): void {
		$this->authorise();
		$this->stub_json();
		$this->stub_transients();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_file_name' )->returnArg();

		$store = new BackupStore( $this->base );
		$store->ensure_directory();
		$name = 'pontifex-backup-20260101T000000Z.wpmig';
		$this->write_plain_archive( $store->directory() . '/' . $name );
		$_POST['file'] = $name;

		$safety_path = $this->base . '/pre-import-rollback-20260101T000000Z.wpmig';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Seeding a placeholder safety archive.
		file_put_contents( $safety_path, 'x' );
		$archiver = Mockery::mock( SafetyArchiverInterface::class );
		$archiver->shouldReceive( 'create' )->once()->andReturn( $safety_path );

		// Both the forward replay and the recovery replay throw.
		$runner = Mockery::mock( RestoreRunnerInterface::class );
		$runner->shouldReceive( 'verify' )->twice();
		$runner->shouldReceive( 'restore' )->twice()->andThrow( new RuntimeException( 'replay failed' ) );

		try {
			$this->controller( $runner, $archiver )->restore();
			$this->fail( 'restore() should have halted with a JSON error.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( 'pontifex-json-halt', $error->getMessage() );
		} finally {
			unset( $_POST['file'] );
		}

		$this->assertFalse( $this->json['success'] );
		$this->assertSame( 500, $this->json['status'] );
		$this->assertStringContainsString( 'automatic recovery also failed', $this->json['data']['message'] );
	}

	/**
	 * Recovery reports the links outcome, never a precise revert, when the replay skips an absolute-target symlink.
	 *
	 * The forward runner here has written nothing, so the file cleanup is provably
	 * complete and the skipped link is the ONLY gap. That used to be reported as
	 * RECOVERY_MERGE, whose message asserts a cleanup that "could not be confirmed
	 * complete" — false in this case. The expectation changed to
	 * RECOVERY_MERGE_LINKS deliberately, with that message split; it is not a test
	 * bent to fit the code.
	 *
	 * Exercised directly via reflection on the private method — not through the
	 * full restore()/ajax flow — with two REAL {@see RestoreRunner} instances
	 * (real FileWriter, real EntryReader; a {@see FakeDbAdapter} for the
	 * database half, exactly as {@see \Pontifex\Tests\Unit\Restore\RecoveryCreationLedgerTest}
	 * does). The recovery runner's FileWriter is built with
	 * $replaying_safety_archive true, matching production's own
	 * default_restore_runner( null, $source, true ) call for this leg, and is
	 * injected via the constructor so default_restore_runner() itself is never
	 * reached — no WordPress wiring beyond the environment()/context() doubles
	 * this file already uses is needed. The forward runner is a second, freshly
	 * built RestoreRunner that has written nothing, so its own cleanup is
	 * always a precise revert on its own — isolating the outcome under test to
	 * whether the replay skipped a symlink.
	 *
	 * @return void
	 */
	public function test_recovery_reports_merge_links_when_only_a_symlink_is_skipped(): void {
		$safety_path = $this->base . '/pre-import-rollback-symlink-skipped.wpmig';
		$this->write_archive_with_plans( $safety_path, array( self::symlink_plan( 'wp-content/uploads/media', '/mnt/shared/media' ) ) );

		$outcome = $this->invoke_recovery_from_safety_archive( $safety_path );

		$this->assertSame( self::recovery_merge_links_constant(), $outcome, 'A skipped symlink on a recovery whose cleanup was complete must be reported as the links outcome, never a precise revert and never the unconfirmed-cleanup merge.' );
	}

	/**
	 * Recovery still reports a precise revert when nothing was skipped (false-positive guard).
	 *
	 * @return void
	 */
	public function test_recovery_still_reports_precise_when_nothing_is_skipped(): void {
		$safety_path = $this->base . '/pre-import-rollback-nothing-skipped.wpmig';
		$this->write_archive_with_plans( $safety_path, array( self::file_plan( 'wp-content/original.txt', 'ORIGINAL CONTENT' ) ) );

		$outcome = $this->invoke_recovery_from_safety_archive( $safety_path );

		$this->assertSame( self::recovery_precise_constant(), $outcome, 'A clean replay that skipped nothing must not be downgraded to a merge.' );
	}

	/**
	 * A links-only merge names the skipped link and does not claim the file cleanup is unconfirmed.
	 *
	 * The cleanup WAS provably complete here, so sending the operator to the log for
	 * stray paths would be false advice about something that never happened. What
	 * the operator needs is the link itself — its path and the target it should
	 * point at, so they can check it.
	 *
	 * @return void
	 */
	public function test_failure_message_for_a_links_only_merge_names_the_skipped_link(): void {
		$message = $this->invoke_failure_message(
			self::recovery_merge_links_constant(),
			array( 'wp-content/uploads/media' => '/mnt/shared/media' )
		);

		$this->assertStringContainsString( 'rolled back to its state before the restore', $message );
		$this->assertStringContainsString( 'file cleanup completed', $message );
		$this->assertStringContainsString( 'wp-content/uploads/media -> /mnt/shared/media', $message, 'The skipped link must be named, path and recorded target, in the operator-visible message.' );
		$this->assertStringNotContainsString( 'could not be confirmed complete', $message, 'A cleanup that was provably complete must not be described as unconfirmed.' );
		$this->assertStringNotContainsString( 'check the Pontifex log for which paths', $message, 'There are no stray paths to look for, so the message must not send the operator looking.' );
	}

	/**
	 * A merge with an unconfirmed cleanup names the skipped link too, and keeps its own cleanup sentence.
	 *
	 * Both causes apply at once here, so both get said: the cleanup could not be
	 * confirmed AND a link was not re-created. Dropping either would leave the
	 * operator with half the picture.
	 *
	 * @return void
	 */
	public function test_failure_message_for_an_unconfirmed_cleanup_also_names_a_skipped_link(): void {
		$message = $this->invoke_failure_message(
			self::recovery_merge_constant(),
			array( 'wp-content/uploads/media' => '/mnt/shared/media' )
		);

		$this->assertStringContainsString( 'could not be confirmed complete', $message, 'The cleanup sentence is still true and must stay.' );
		$this->assertStringContainsString( 'wp-content/uploads/media -> /mnt/shared/media', $message, 'The skipped link must be named as well.' );
	}

	/**
	 * A merge with an unconfirmed cleanup and no skipped link says exactly what it always said.
	 *
	 * The caveat helper returns an empty string for an empty array, so nothing —
	 * not even a trailing space — may be appended to the long-standing message.
	 *
	 * @return void
	 */
	public function test_failure_message_for_an_unconfirmed_cleanup_without_links_is_unchanged(): void {
		$this->assertSame(
			'The restore failed. Your site\'s database was rolled back to its state before the restore, but the file cleanup could not be confirmed complete — check the Pontifex log for which paths, since a file the failed restore created may still be present alongside the recovered content.',
			$this->invoke_failure_message( self::recovery_merge_constant() )
		);
	}

	/**
	 * The precise-revert message is unchanged, and ignores any skipped links it is handed.
	 *
	 * A precise revert and a skipped link cannot both be true — recovery downgrades
	 * the one to the other — so this branch has no use for the array; it must not
	 * start naming links just because one was passed in.
	 *
	 * @return void
	 */
	public function test_failure_message_for_a_precise_revert_is_unchanged(): void {
		$expected = 'The restore failed, so your site was automatically rolled back to its state before the restore. Check the Pontifex log for details.';

		$this->assertSame( $expected, $this->invoke_failure_message( self::recovery_precise_constant() ) );
		$this->assertSame( $expected, $this->invoke_failure_message( self::recovery_precise_constant(), array( 'wp-content/uploads/media' => '/mnt/shared/media' ) ) );
	}

	/**
	 * A restore that fails before the safety archive is taken reports the site unchanged.
	 *
	 * Here the safety archive creation itself fails, so the database was never written; no
	 * recovery is attempted and the operator is told the site was not changed.
	 *
	 * @return void
	 */
	public function test_restore_failure_before_the_safety_archive_reports_site_unchanged(): void {
		$this->authorise();
		$this->stub_json();
		$this->stub_transients();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_file_name' )->returnArg();

		$store = new BackupStore( $this->base );
		$store->ensure_directory();
		$name = 'pontifex-backup-20260101T000000Z.wpmig';
		$this->write_plain_archive( $store->directory() . '/' . $name );
		$_POST['file'] = $name;

		// The safety archive fails to write, so the restore never touches the database.
		$archiver = Mockery::mock( SafetyArchiverInterface::class );
		$archiver->shouldReceive( 'create' )->once()->andThrow( new RuntimeException( 'no disk space' ) );

		$runner = Mockery::mock( RestoreRunnerInterface::class );
		$runner->shouldReceive( 'verify' )->once();
		$runner->shouldReceive( 'restore' )->never();

		try {
			$this->controller( $runner, $archiver )->restore();
			$this->fail( 'restore() should have halted with a JSON error.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( 'pontifex-json-halt', $error->getMessage() );
		} finally {
			unset( $_POST['file'] );
		}

		$this->assertFalse( $this->json['success'] );
		$this->assertSame( 500, $this->json['status'] );
		$this->assertStringContainsString( 'was not changed', $this->json['data']['message'] );
	}

	/**
	 * Refuses a whole-site backup: the admin restore is content-only.
	 *
	 * A whole-site backup (one that includes WordPress core and wp-config.php) has no
	 * --whole-site path in the admin, so it is refused before any write — neither
	 * verified, backed up, nor restored.
	 *
	 * @return void
	 */
	public function test_restore_refuses_a_whole_site_backup(): void {
		$this->authorise();
		$this->stub_json();
		$this->stub_transients();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_file_name' )->returnArg();

		$store = new BackupStore( $this->base );
		$store->ensure_directory();
		$name = 'pontifex-backup-20260101T000000Z.wpmig';
		$this->write_whole_site_archive( $store->directory() . '/' . $name );
		$_POST['file'] = $name;

		$runner = Mockery::mock( RestoreRunnerInterface::class );
		$runner->shouldReceive( 'verify' )->never();
		$runner->shouldReceive( 'restore' )->never();

		$archiver = Mockery::mock( SafetyArchiverInterface::class );
		$archiver->shouldReceive( 'create' )->never();

		try {
			$this->controller( $runner, $archiver )->restore();
			$this->fail( 'restore() should refuse a whole-site backup.' );
		} catch ( RuntimeException $halt ) {
			$this->assertSame( 'pontifex-json-halt', $halt->getMessage() );
		} finally {
			unset( $_POST['file'] );
		}

		$this->assertFalse( $this->json['success'], 'A whole-site backup is refused, not restored.' );
	}

	/**
	 * Refuses a legacy (no-scope) backup the same way as a whole-site one.
	 *
	 * A backup that records no scope predates the content-only format and is treated
	 * as whole-site, so it is refused before any write.
	 *
	 * @return void
	 */
	public function test_restore_refuses_a_legacy_backup(): void {
		$this->authorise();
		$this->stub_json();
		$this->stub_transients();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_file_name' )->returnArg();

		$store = new BackupStore( $this->base );
		$store->ensure_directory();
		$name = 'pontifex-backup-20260101T000000Z.wpmig';
		$this->write_legacy_archive( $store->directory() . '/' . $name );
		$_POST['file'] = $name;

		$runner = Mockery::mock( RestoreRunnerInterface::class );
		$runner->shouldReceive( 'restore' )->never();

		$archiver = Mockery::mock( SafetyArchiverInterface::class );
		$archiver->shouldReceive( 'create' )->never();

		try {
			$this->controller( $runner, $archiver )->restore();
			$this->fail( 'restore() should refuse a legacy backup.' );
		} catch ( RuntimeException $halt ) {
			$this->assertSame( 'pontifex-json-halt', $halt->getMessage() );
		} finally {
			unset( $_POST['file'] );
		}

		$this->assertFalse( $this->json['success'], 'A legacy backup is refused, not restored.' );
	}

	/**
	 * Refuses a second restore while one is already running.
	 *
	 * @return void
	 */
	public function test_restore_refuses_when_a_restore_is_already_running(): void {
		$this->authorise();
		$this->stub_json();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_file_name' )->returnArg();
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'get_transient' )->alias(
			static function ( string $key ) {
				return OperationLock::LOCK_NAME === $key
					? array(
						'kind' => OperationLock::OP_RESTORE,
						'at'   => time(),
					)
					: false;
			}
		);

		$store = new BackupStore( $this->base );
		$store->ensure_directory();
		$name = 'pontifex-backup-20260101T000000Z.wpmig';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Seeding a placeholder backup; the lock is checked before it is opened.
		file_put_contents( $store->directory() . '/' . $name, 'x' );
		$_POST['file'] = $name;

		try {
			$this->controller()->restore();
			$this->fail( 'restore() should refuse while a restore is already running.' );
		} catch ( RuntimeException $halt ) {
			$this->assertSame( 'pontifex-json-halt', $halt->getMessage() );
		} finally {
			unset( $_POST['file'] );
		}

		$this->assertFalse( $this->json['success'] );
		$this->assertSame( 409, $this->json['status'] );
	}

	/**
	 * Refuses a restore when the named database lock is held elsewhere.
	 *
	 * The named lock is the primary single-runner guard: the database grants it
	 * to exactly one connection, atomically, so two simultaneous restore
	 * requests can never both pass — the check-then-set race the transient
	 * guard alone cannot close. A request that failed to acquire must not
	 * release the lock either, or it would free the running operation's lock.
	 *
	 * @return void
	 */
	public function test_restore_refuses_when_the_named_lock_is_held_elsewhere(): void {
		$this->authorise();
		$this->stub_json();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_file_name' )->returnArg();
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( false );

		$context = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'acquire_named_lock' )->once()->with( OperationLock::LOCK_NAME )->andReturn( false );
		$context->shouldNotReceive( 'release_named_lock' );

		$store = new BackupStore( $this->base );
		$store->ensure_directory();
		$name = 'pontifex-backup-20260101T000000Z.wpmig';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Seeding a placeholder backup; the lock is checked before it is opened.
		file_put_contents( $store->directory() . '/' . $name, 'x' );
		$_POST['file'] = $name;

		$controller = new RestoreController(
			$this->environment(),
			$context,
			$store,
			Mockery::mock( RollbackStoreInterface::class ),
			new NullLogger()
		);

		try {
			$controller->restore();
			$this->fail( 'restore() should refuse while the named lock is held elsewhere.' );
		} catch ( RuntimeException $halt ) {
			$this->assertSame( 'pontifex-json-halt', $halt->getMessage() );
		} finally {
			unset( $_POST['file'] );
		}

		$this->assertFalse( $this->json['success'] );
		$this->assertSame( 409, $this->json['status'] );
	}

	/**
	 * The named lock is handed back when the transient guard refuses a restore.
	 *
	 * The transient is the secondary guard, checked only while the named lock is
	 * held. When it refuses — a crashed run's transient still inside its TTL —
	 * the named lock just taken must be released again; under a persistent
	 * database connection it would otherwise linger and block every later run.
	 *
	 * @return void
	 */
	public function test_restore_hands_back_the_named_lock_when_the_transient_guard_refuses(): void {
		$this->authorise();
		$this->stub_json();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_file_name' )->returnArg();
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'get_transient' )->alias(
			static function ( string $key ) {
				return OperationLock::LOCK_NAME === $key
					? array(
						'kind' => OperationLock::OP_RESTORE,
						'at'   => time(),
					)
					: false;
			}
		);

		$context = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'acquire_named_lock' )->once()->with( OperationLock::LOCK_NAME )->andReturn( true );
		$context->shouldReceive( 'release_named_lock' )->once()->with( OperationLock::LOCK_NAME );

		$store = new BackupStore( $this->base );
		$store->ensure_directory();
		$name = 'pontifex-backup-20260101T000000Z.wpmig';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Seeding a placeholder backup; the lock is checked before it is opened.
		file_put_contents( $store->directory() . '/' . $name, 'x' );
		$_POST['file'] = $name;

		$controller = new RestoreController(
			$this->environment(),
			$context,
			$store,
			Mockery::mock( RollbackStoreInterface::class ),
			new NullLogger()
		);

		try {
			$controller->restore();
			$this->fail( 'restore() should refuse while the lock transient is set.' );
		} catch ( RuntimeException $halt ) {
			$this->assertSame( 'pontifex-json-halt', $halt->getMessage() );
		} finally {
			unset( $_POST['file'] );
		}

		$this->assertFalse( $this->json['success'] );
		$this->assertSame( 409, $this->json['status'] );
	}

	/**
	 * Reports byte progress against the archive size as the restore reads.
	 *
	 * @return void
	 */
	public function test_restore_reports_byte_progress(): void {
		$this->authorise();
		$this->stub_json();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_file_name' )->returnArg();

		$writes = array();
		Functions\when( 'set_transient' )->alias(
			static function ( string $key, $value ) use ( &$writes ): bool {
				if ( 'pontifex_restore_progress' === $key ) {
					$writes[] = $value;
				}
				return true;
			}
		);
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'delete_transient' )->justReturn( true );

		$store = new BackupStore( $this->base );
		$store->ensure_directory();
		$name = 'pontifex-backup-20260101T000000Z.wpmig';
		$this->write_plain_archive( $store->directory() . '/' . $name );
		$_POST['file'] = $name;

		$runner = Mockery::mock( RestoreRunnerInterface::class );
		$runner->shouldReceive( 'verify' )->once();
		$runner->shouldReceive( 'restore' )->once()->andReturnUsing(
			static function ( $source, ?callable $on_entry, ?callable $on_bytes ): void {
				unset( $source );
				if ( null !== $on_entry ) {
					$on_entry( 1, 1 );
				}
				if ( null !== $on_bytes ) {
					$on_bytes( 500 );
				}
			}
		);

		try {
			$this->controller( $runner, $this->safety_archiver_double() )->restore();
		} finally {
			unset( $_POST['file'] );
		}

		$restoring = array_values(
			array_filter(
				$writes,
				static function ( $write ): bool {
					return is_array( $write ) && isset( $write['phase'] ) && 'restoring' === $write['phase'];
				}
			)
		);

		$this->assertNotEmpty( $restoring, 'The restore phase must write byte progress.' );
		$max_done = 0;
		foreach ( $restoring as $write ) {
			$max_done = max( $max_done, (int) $write['bytes_done'] );
		}
		$this->assertGreaterThan( 0, $max_done, 'The byte callback must report archive bytes read during the restore.' );
	}

	/**
	 * Rolls back the most recent safety archive after verifying it.
	 *
	 * @return void
	 */
	public function test_rollback_reports_a_successful_rollback(): void {
		$this->authorise();
		$this->stub_json();
		$this->stub_transients();

		$archive_path = $this->base . '/pre-import-rollback-20260101T000000Z.wpmig';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Seeding a temp directory for the placeholder safety archive.
		mkdir( $this->base, 0o755, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Seeding a placeholder safety archive; the injected engine stands in for reading it.
		file_put_contents( $archive_path, 'x' );

		$rollback = Mockery::mock( RollbackStoreInterface::class );
		$rollback->shouldReceive( 'most_recent' )->once()->andReturn( $archive_path );

		$runner = Mockery::mock( RestoreRunnerInterface::class );
		$runner->shouldReceive( 'verify' )->once();
		$runner->shouldReceive( 'restore' )->once()->andReturnUsing(
			static function ( $source, ?callable $on_entry, ?callable $on_bytes ): void {
				unset( $source, $on_bytes );
				if ( null !== $on_entry ) {
					$on_entry( 2, 2 );
				}
			}
		);

		$this->controller( $runner, null, $rollback )->rollback();

		$this->assertTrue( $this->json['success'] );
		$this->assertTrue( $this->json['data']['rolled_back'] );
		$this->assertSame( 2, $this->json['data']['entries'] );
	}

	/**
	 * The rollback handler's OWN default wiring builds its runner in replay mode.
	 *
	 * Every other rollback test in this file injects a RestoreRunner (mock or
	 * real), which bypasses default_restore_runner() entirely and so proves
	 * nothing about the $replaying_safety_archive argument rollback() itself
	 * passes at its call site. This test injects NO runner, so rollback() must
	 * build its own via default_restore_runner( null, $source, true ) — the
	 * exact call this test exists to prove. It uses a real ArchiveWriter-built
	 * safety archive declaring an absolute-target symlink and a real
	 * RestoreRunner/FileWriter underneath (a FakeDbAdapter stands in for the
	 * database half, and a WpdbStub satisfies WordPressContext::wpdb_instance()'s
	 * type, exactly as {@see \Pontifex\Tests\Unit\Manifest\WpdbAdapterTest} does):
	 * if replay mode were NOT wired through, the symlink's absolute target would
	 * be refused and the rollback would report a failure instead of the success
	 * asserted below.
	 *
	 * @return void
	 */
	public function test_rollback_builds_its_own_runner_in_replay_mode(): void {
		$this->authorise();
		$this->stub_json();
		$this->stub_transients();

		$archive_path = $this->base . '/pre-import-rollback-symlink.wpmig';
		$this->write_archive_with_plans( $archive_path, array( self::symlink_plan( 'wp-content/uploads/media', '/mnt/shared/media' ) ) );

		$rollback = Mockery::mock( RollbackStoreInterface::class );
		$rollback->shouldReceive( 'most_recent' )->once()->andReturn( $archive_path );

		$wordpress_root = $this->base . '/wp-root';
		$environment    = Mockery::mock( Environment::class );
		$environment->shouldReceive( 'is_constant_defined' )->andReturn( true );
		$environment->shouldReceive( 'constant_value' )->andReturnUsing(
			function ( string $name ) use ( $wordpress_root ) {
				return 'ABSPATH' === $name ? $wordpress_root . '/' : $wordpress_root . '/wp-content';
			}
		);
		$environment->shouldReceive( 'ini_get' )->andReturn( '256M' );

		$context = $this->context();
		$context->shouldReceive( 'wpdb_prefix' )->andReturn( 'wp_' );
		$context->shouldReceive( 'wpdb_instance' )->andReturn( new wpdb() );
		$context->shouldReceive( 'convert_hr_to_bytes' )->andReturn( 0 );

		$controller = new RestoreController(
			$environment,
			$context,
			new BackupStore( $this->base ),
			$rollback,
			new NullLogger(),
			null, // No injected runner: this is the point of the test.
			null
		);

		$controller->rollback();

		$this->assertTrue( $this->json['success'], 'An absolute-target symlink must not make the rollback fail when replay mode is correctly wired.' );
		$this->assertTrue( $this->json['data']['rolled_back'] );
		$this->assertStringContainsString( 'wp-content/uploads/media', $this->json['data']['message'], 'The skipped link must be named in the operator-visible message.' );
		$this->assertStringContainsString( '/mnt/shared/media', $this->json['data']['message'] );
	}

	/**
	 * A successful rollback flushes the cache and records its own rollback counters.
	 *
	 * The rollback replays the database, so the cache is flushed before recording (or
	 * the write is lost), and the figures land in the separate rollback counters —
	 * not the import counters — so Overview's Rollbacks row reflects the undo.
	 *
	 * @return void
	 */
	public function test_successful_rollback_flushes_then_records_rollback_counters(): void {
		$this->authorise();
		$this->stub_json();
		$this->stub_transients();

		$archive_path = $this->base . '/pre-import-rollback-20260101T000000Z.wpmig';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Seeding a temp directory for the placeholder safety archive.
		mkdir( $this->base, 0o755, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Seeding a placeholder safety archive; the injected engine stands in for reading it.
		file_put_contents( $archive_path, 'x' );

		$rollback = Mockery::mock( RollbackStoreInterface::class );
		$rollback->shouldReceive( 'most_recent' )->once()->andReturn( $archive_path );

		$runner = Mockery::mock( RestoreRunnerInterface::class );
		$runner->shouldReceive( 'verify' )->once();
		$runner->shouldReceive( 'restore' )->once();

		$flushed       = false;
		$flushed_first = null;
		$stats         = null;
		$context       = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'format_size' )->andReturnUsing(
			static function ( int $bytes ): string {
				return $bytes . ' B';
			}
		);
		$context->shouldReceive( 'option_value' )->andReturnUsing(
			static function ( string $name, $fallback = false ) {
				unset( $name );
				return $fallback;
			}
		);
		$context->shouldReceive( 'flush_cache' )->once()->andReturnUsing(
			static function () use ( &$flushed ): void {
				$flushed = true;
			}
		);
		$context->shouldReceive( 'save_option' )->andReturnUsing(
			static function ( string $name, $value ) use ( &$flushed, &$flushed_first, &$stats ): void {
				if ( 'pontifex_rollback_stats' === $name ) {
					$flushed_first = $flushed;
					$stats         = $value;
				}
			}
		);
		$context->shouldReceive( 'acquire_named_lock' )->andReturn( true );
		$context->shouldReceive( 'release_named_lock' );

		$controller = new RestoreController(
			$this->environment(),
			$context,
			new BackupStore( $this->base ),
			$rollback,
			new NullLogger(),
			$runner,
			null
		);

		$controller->rollback();

		$this->assertTrue( $flushed, 'The cache must be flushed after the rollback replay.' );
		$this->assertTrue( $flushed_first, 'Rollback counters must be written AFTER the cache flush.' );
		$this->assertIsArray( $stats );
		$this->assertSame( 1, $stats['attempted'], 'A successful rollback must record the attempt.' );
		$this->assertSame( 1, $stats['succeeded'] );
		$this->assertSame( 0, $stats['failed'] );
		$this->assertArrayHasKey( 'bytes_rolled_back', $stats );
	}

	/**
	 * Refuses to roll back when there is no safety archive.
	 *
	 * @return void
	 */
	public function test_rollback_refuses_when_there_is_no_safety_archive(): void {
		$this->authorise();
		$this->stub_json();

		$rollback = Mockery::mock( RollbackStoreInterface::class );
		$rollback->shouldReceive( 'most_recent' )->once()->andReturn( null );

		try {
			$this->controller( null, null, $rollback )->rollback();
			$this->fail( 'rollback() should refuse when there is nothing to roll back to.' );
		} catch ( RuntimeException $halt ) {
			$this->assertSame( 'pontifex-json-halt', $halt->getMessage() );
		}

		$this->assertFalse( $this->json['success'] );
		$this->assertSame( 404, $this->json['status'] );
	}

	// -------------------------------------------------------------------------
	// preview() — the migration hint.
	// -------------------------------------------------------------------------

	/**
	 * Reports a migration hint when the backup's recorded URL differs from this site's.
	 *
	 * @return void
	 */
	public function test_preview_reports_migration_when_the_backup_is_from_another_site(): void {
		$this->authorise();
		$this->stub_json();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_file_name' )->returnArg();

		$store = new BackupStore( $this->base );
		$store->ensure_directory();
		$name = 'pontifex-backup-20260101T000000Z.wpmig';
		// sample_provenance() records https://example.test; the default context()'s
		// site_url() is https://this-site.test, so the two differ.
		$this->write_plain_archive( $store->directory() . '/' . $name );
		$_POST['file'] = $name;

		try {
			$this->controller()->preview();
		} finally {
			unset( $_POST['file'] );
		}

		$this->assertTrue( $this->json['success'] );
		$this->assertTrue( $this->json['data']['migration'] );
	}

	/**
	 * Reports no migration hint when the backup was taken on this same site.
	 *
	 * @return void
	 */
	public function test_preview_reports_no_migration_for_a_same_site_backup(): void {
		$this->authorise();
		$this->stub_json();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_file_name' )->returnArg();

		$store = new BackupStore( $this->base );
		$store->ensure_directory();
		$name = 'pontifex-backup-20260101T000000Z.wpmig';
		$this->write_plain_archive( $store->directory() . '/' . $name );
		$_POST['file'] = $name;

		try {
			$this->controller_with_site_url( 'https://example.test' )->preview();
		} finally {
			unset( $_POST['file'] );
		}

		$this->assertTrue( $this->json['success'] );
		$this->assertFalse( $this->json['data']['migration'] );
	}

	/**
	 * The URL comparison ignores case and a single trailing slash.
	 *
	 * @return void
	 */
	public function test_preview_ignores_case_and_a_trailing_slash(): void {
		$this->authorise();
		$this->stub_json();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_file_name' )->returnArg();

		$store = new BackupStore( $this->base );
		$store->ensure_directory();
		$name = 'pontifex-backup-20260101T000000Z.wpmig';
		$this->write_plain_archive( $store->directory() . '/' . $name );
		$_POST['file'] = $name;

		try {
			$this->controller_with_site_url( 'https://EXAMPLE.test/' )->preview();
		} finally {
			unset( $_POST['file'] );
		}

		$this->assertTrue( $this->json['success'] );
		$this->assertFalse( $this->json['data']['migration'] );
	}

	/**
	 * The hint is suppressed for an encrypted archive — the admin cannot restore one anyway.
	 *
	 * The site_url() differs from the archive's recorded URL (as in the first test above),
	 * so an unencrypted archive with the same provenance would report a migration hint;
	 * this proves encryption alone suppresses it, not a same-URL coincidence.
	 *
	 * @return void
	 */
	public function test_preview_suppresses_the_hint_for_an_encrypted_backup(): void {
		$this->authorise();
		$this->stub_json();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_file_name' )->returnArg();

		$store = new BackupStore( $this->base );
		$store->ensure_directory();
		$name = 'pontifex-backup-20260101T000000Z.wpmig';
		$this->write_encrypted_archive( $store->directory() . '/' . $name );
		$_POST['file'] = $name;

		try {
			$this->controller()->preview();
		} finally {
			unset( $_POST['file'] );
		}

		$this->assertTrue( $this->json['success'] );
		$this->assertFalse( $this->json['data']['migration'] );
	}

	/**
	 * Fails soft — never a 404 — when the chosen filename does not resolve to a backup.
	 *
	 * @return void
	 */
	public function test_preview_reports_no_migration_when_the_backup_cannot_be_found(): void {
		$this->authorise();
		$this->stub_json();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_file_name' )->returnArg();

		$_POST['file'] = 'does-not-exist.wpmig';

		try {
			$this->controller()->preview();
		} finally {
			unset( $_POST['file'] );
		}

		$this->assertTrue( $this->json['success'] );
		$this->assertFalse( $this->json['data']['migration'] );
	}

	/**
	 * Reports no migration hint for a resolvable file that is not a valid archive.
	 *
	 * The name resolves, so the read is attempted, but the bytes are not an
	 * archive: the reader throws and the fail-soft path answers "no hint" rather
	 * than surfacing an error to the operator — a stray or corrupt file in the
	 * backups directory must never break the screen.
	 *
	 * @return void
	 */
	public function test_preview_reports_no_migration_for_a_corrupt_archive(): void {
		$this->authorise();
		$this->stub_json();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_file_name' )->returnArg();

		$store = new BackupStore( $this->base );
		$store->ensure_directory();
		$name = 'pontifex-backup-20260101T000000Z.wpmig';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Seeding a corrupt (non-archive) fixture in a temp directory.
		file_put_contents( $store->directory() . '/' . $name, 'this is not a valid archive' );
		$_POST['file'] = $name;

		try {
			$this->controller()->preview();
		} finally {
			unset( $_POST['file'] );
		}

		$this->assertTrue( $this->json['success'] );
		$this->assertFalse( $this->json['data']['migration'] );
	}

	/**
	 * Refuses without the managing capability or a valid nonce.
	 *
	 * @return void
	 */
	public function test_preview_refuses_without_capability_or_nonce(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->stub_json();

		try {
			$this->controller()->preview();
			$this->fail( 'preview() should refuse without the capability.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( 'pontifex-json-halt', $error->getMessage() );
		}

		$this->assertFalse( $this->json['success'] );
		$this->assertSame( 403, $this->json['status'] );
	}

	// -------------------------------------------------------------------------
	// auth_check() — the nonce-free re-auth poll behind the signed-out overlay.
	// -------------------------------------------------------------------------

	/**
	 * Reports authenticated when the current request holds the managing capability.
	 *
	 * @return void
	 */
	public function test_auth_check_reports_authenticated_for_a_manager(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->stub_json();

		$this->controller()->auth_check();

		$this->assertTrue( $this->json['success'] );
		$this->assertTrue( $this->json['data']['authenticated'] );
	}

	/**
	 * Reports not authenticated when the current request lacks the capability.
	 *
	 * @return void
	 */
	public function test_auth_check_reports_not_authenticated_without_the_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->stub_json();

		$this->controller()->auth_check();

		$this->assertTrue( $this->json['success'] );
		$this->assertFalse( $this->json['data']['authenticated'] );
	}

	/**
	 * The handler never gates on a nonce, unlike every other one on this controller.
	 *
	 * A restore that replaces the users table invalidates the session, so a
	 * fresh login mints a new session token and the page's baked-in nonce
	 * (bound to the old session) can never verify again. If auth_check() went
	 * through {@see RestoreController::is_authorised()} like restore(),
	 * rollback(), progress() and preview() all do, it would need a valid
	 * `pontifex_restore` nonce to pass check_ajax_referer() — deliberately left
	 * unstubbed here, unlike {@see self::authorise()} which stubs it for every
	 * other test. If the nonce gate were reached, the unstubbed
	 * check_ajax_referer() call would fatal instead of the handler returning a
	 * normal success envelope, so a clean success proves the 403
	 * unauthorised-nonce path is never hit.
	 *
	 * @return void
	 */
	public function test_auth_check_does_not_require_a_nonce(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->stub_json();

		$this->controller()->auth_check();

		// With current_user_can stubbed true, routing through the nonce gate would
		// reach the unstubbed check_ajax_referer() and fatal; a clean success
		// envelope proves auth_check() never reaches a nonce check at all.
		$this->assertTrue( $this->json['success'], 'auth_check() must answer without ever reaching a nonce gate.' );
		$this->assertTrue( $this->json['data']['authenticated'] );
	}

	// -------------------------------------------------------------------------
	// Collaborator builders and stubs.
	// -------------------------------------------------------------------------

	/**
	 * Build a controller around mocks and a real store on the temp dir.
	 *
	 * The restore runner and safety archiver are injected so no write ever
	 * reaches the filesystem or database. The Environment reports ABSPATH for the
	 * safety archiver's root; the WordPressContext formats sizes and reads/writes
	 * the counters and transfer history through option_value/save_option.
	 *
	 * @param RestoreRunnerInterface|null  $runner   Optional injected engine.
	 * @param SafetyArchiverInterface|null $archiver Optional injected archiver.
	 * @param RollbackStoreInterface|null  $rollback Optional injected rollback store.
	 * @param LoggerInterface|null         $logger   Optional injected logger; a NullLogger by default.
	 * @return RestoreController
	 */
	private function controller(
		?RestoreRunnerInterface $runner = null,
		?SafetyArchiverInterface $archiver = null,
		?RollbackStoreInterface $rollback = null,
		?LoggerInterface $logger = null
	): RestoreController {
		return new RestoreController(
			$this->environment(),
			$this->context(),
			new BackupStore( $this->base ),
			$rollback ?? Mockery::mock( RollbackStoreInterface::class ),
			$logger ?? new NullLogger(),
			$runner,
			$archiver
		);
	}

	/**
	 * Build a controller whose WordPressContext reports the given site_url(), for
	 * the preview() migration-comparison tests.
	 *
	 * @param string $site_url The URL site_url() should report.
	 * @return RestoreController
	 */
	private function controller_with_site_url( string $site_url ): RestoreController {
		$context = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'site_url' )->andReturn( $site_url );

		return new RestoreController(
			$this->environment(),
			$context,
			new BackupStore( $this->base ),
			Mockery::mock( RollbackStoreInterface::class ),
			new NullLogger()
		);
	}

	/**
	 * An Environment mock reporting a WordPress root for the safety archiver.
	 *
	 * @return Environment&\Mockery\MockInterface
	 */
	private function environment() {
		$environment = Mockery::mock( Environment::class );
		$environment->shouldReceive( 'is_constant_defined' )->andReturn( true );
		$environment->shouldReceive( 'constant_value' )->andReturn( '/var/www/html' );
		return $environment;
	}

	/**
	 * A WordPressContext mock that formats sizes and stores counters/history.
	 *
	 * @param callable|null $site_url Optional. Answers every site_url() call, so a test can make the address change as the restore runs; by default it is always https://this-site.test.
	 * @return WordPressContext&\Mockery\MockInterface
	 */
	private function context( ?callable $site_url = null ) {
		$context = Mockery::mock( WordPressContext::class );
		$context->shouldReceive( 'format_size' )->andReturnUsing(
			static function ( int $bytes ): string {
				return $bytes . ' B';
			}
		);
		$context->shouldReceive( 'option_value' )->andReturnUsing(
			static function ( string $name, $fallback = false ) {
				unset( $name );
				return $fallback;
			}
		);
		$context->shouldReceive( 'save_option' );
		$context->shouldReceive( 'flush_cache' );
		$context->shouldReceive( 'site_url' )->andReturnUsing(
			$site_url ?? static function (): string {
				return 'https://this-site.test';
			}
		);
		$context->shouldReceive( 'acquire_named_lock' )->andReturn( true );
		$context->shouldReceive( 'release_named_lock' );
		return $context;
	}

	/**
	 * Run restore() with the relink box ticked, over a sound content-only backup.
	 *
	 * Leaves the JSON response in $this->json. The restore engine is a double, so
	 * nothing is written; the context, migrator and, optionally, the engine and logger
	 * are the parts the relink tests vary.
	 *
	 * @param WordPressContext            $context  The context the controller reads this site's address from.
	 * @param UrlMigratorInterface        $migrator The migrator double.
	 * @param RestoreRunnerInterface|null $runner   Optional engine; by default one that verifies and restores without doing anything.
	 * @param LoggerInterface|null        $logger   Optional logger; a NullLogger by default.
	 * @return void
	 */
	private function run_relink_restore(
		WordPressContext $context,
		UrlMigratorInterface $migrator,
		?RestoreRunnerInterface $runner = null,
		?LoggerInterface $logger = null
	): void {
		$this->authorise();
		$this->stub_json();
		$this->stub_transients();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_file_name' )->returnArg();

		if ( null === $runner ) {
			$runner = Mockery::mock( RestoreRunnerInterface::class );
			$runner->shouldReceive( 'verify' )->once();
			$runner->shouldReceive( 'restore' )->once();
		}

		$store = new BackupStore( $this->base );
		$store->ensure_directory();
		$name = 'pontifex-backup-20260101T000000Z.wpmig';
		$this->write_plain_archive( $store->directory() . '/' . $name );
		$_POST['file']    = $name;
		$_POST['migrate'] = '1';

		$controller = new RestoreController(
			$this->environment(),
			$context,
			new BackupStore( $this->base ),
			Mockery::mock( RollbackStoreInterface::class ),
			$logger ?? new NullLogger(),
			$runner,
			$this->safety_archiver_double(),
			$migrator
		);

		try {
			$controller->restore();
		} finally {
			unset( $_POST['file'], $_POST['migrate'] );
		}
	}

	/**
	 * A migrator double that reports the given source URL and records every migrate() call.
	 *
	 * @param string                         $source_url The URL source_url() reports.
	 * @param array<int, array<int, string>> $calls      Out-parameter. Filled with one array( old URL, new URL ) per migrate() call.
	 * @return UrlMigratorInterface&\Mockery\MockInterface
	 */
	private function recording_migrator( string $source_url, array &$calls ) {
		$migrator = Mockery::mock( UrlMigratorInterface::class );
		$migrator->shouldReceive( 'source_url' )->once()->andReturn( $source_url );
		$migrator->shouldReceive( 'migrate' )->andReturnUsing(
			static function ( string $old_url, string $new_url ) use ( &$calls ): RewriteReport {
				$calls[] = array( $old_url, $new_url );
				return new RewriteReport( 3, array(), 10, 7, 7, 0 );
			}
		);
		return $migrator;
	}

	/**
	 * A SafetyArchiver double whose create() reports progress and returns a path.
	 *
	 * @return SafetyArchiverInterface&\Mockery\MockInterface
	 */
	private function safety_archiver_double() {
		$archiver = Mockery::mock( SafetyArchiverInterface::class );
		$archiver->shouldReceive( 'create' )->once()->andReturnUsing(
			static function ( string $root, ?callable $on_entry, ?callable $on_bytes ): string {
				unset( $root, $on_entry );
				if ( null !== $on_bytes ) {
					$on_bytes( 500 );
				}
				return '/tmp/pontifex-safety.wpmig';
			}
		);
		return $archiver;
	}

	/**
	 * Stub the capability and nonce checks to pass.
	 *
	 * @return void
	 */
	private function authorise(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_ajax_referer' )->justReturn( 1 );
	}

	/**
	 * Stub the JSON responders: success captures, error captures and halts.
	 *
	 * @return void
	 */
	private function stub_json(): void {
		Functions\when( 'wp_send_json_success' )->alias(
			function ( $data = null ): void {
				$this->json = array(
					'success' => true,
					'data'    => $data,
				);
			}
		);
		Functions\when( 'wp_send_json_error' )->alias(
			function ( $data = null, $status = null ): void {
				$this->json = array(
					'success' => false,
					'data'    => $data,
					'status'  => $status,
				);
				throw new RuntimeException( 'pontifex-json-halt' );
			}
		);
	}

	/**
	 * Stub the transient functions used to report progress and hold the lock.
	 *
	 * @return void
	 */
	private function stub_transients(): void {
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'delete_transient' )->justReturn( true );
	}

	// -------------------------------------------------------------------------
	// Archive fixtures.
	// -------------------------------------------------------------------------

	/**
	 * Write a valid, empty, unencrypted archive to the given path.
	 *
	 * Enough for the controller's encryption pre-check to read a plain header; the
	 * injected engine stands in for the verify and restore walks.
	 *
	 * @param string $path Absolute path to write the archive to.
	 * @return void
	 */
	private function write_plain_archive( string $path ): void {
		$this->write_archive_with_scope( $path, Scope::content_only( array() ) );
	}

	/**
	 * Write a valid, empty, unencrypted whole-site archive to the given path.
	 *
	 * @param string $path Absolute path to write the archive to.
	 * @return void
	 */
	private function write_whole_site_archive( string $path ): void {
		$this->write_archive_with_scope( $path, Scope::whole_site( array() ) );
	}

	/**
	 * Write a valid, empty, unencrypted legacy (no-scope) archive to the given path.
	 *
	 * @param string $path Absolute path to write the archive to.
	 * @return void
	 */
	private function write_legacy_archive( string $path ): void {
		$this->write_archive_with_scope( $path, null );
	}

	/**
	 * Write a valid, empty, unencrypted archive recording the given scope.
	 *
	 * @param string     $path  Absolute path to write the archive to.
	 * @param Scope|null $scope The scope to record, or null for a legacy archive.
	 * @return void
	 */
	private function write_archive_with_scope( string $path, ?Scope $scope ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Opening a temp fixture archive for writing.
		$dest = fopen( $path, 'w+b' );
		if ( false === $dest ) {
			$this->fail( 'Could not open the fixture archive for writing.' );
		}
		( new ArchiveWriter( new EntryWriter( CodecRegistry::with_defaults() ), new FooterWriter() ) )
			->write_archive( $this->sample_provenance( $scope ), array(), $dest );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the temp fixture archive.
		fclose( $dest );
	}

	/**
	 * Write a valid, unencrypted, content-only archive carrying the given entries.
	 *
	 * Unlike {@see self::write_archive_with_scope()}, this carries real entries —
	 * needed for {@see self::test_recovery_reports_merge_when_a_symlink_is_skipped()}
	 * and its precise-revert companion, which replay the archive through a real
	 * {@see RestoreRunner}/{@see FileWriter}, not an injected double.
	 *
	 * @param string      $path  Absolute path to write the archive to.
	 * @param EntryPlan[] $plans The plans to include.
	 * @return void
	 */
	private function write_archive_with_plans( string $path, array $plans ): void {
		if ( ! is_dir( dirname( $path ) ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating the temp fixture directory the archive is written into.
			mkdir( dirname( $path ), 0o755, true );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Opening a temp fixture archive for writing.
		$dest = fopen( $path, 'w+b' );
		if ( false === $dest ) {
			$this->fail( 'Could not open the fixture archive for writing.' );
		}
		( new ArchiveWriter( new EntryWriter( CodecRegistry::with_defaults() ), new FooterWriter() ) )
			->write_archive( $this->sample_provenance( Scope::content_only( array() ) ), $plans, $dest );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the temp fixture archive.
		fclose( $dest );
	}

	/**
	 * Build a file EntryPlan.
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
	 * Build a symlink EntryPlan.
	 *
	 * @param string $path   Relative path of the link inside the archive.
	 * @param string $target The target string recorded verbatim.
	 * @return EntryPlan
	 */
	private static function symlink_plan( string $path, string $target ): EntryPlan {
		$header = EntryHeader::for_symlink( $path, $target, 0 );
		return new EntryPlan( $header, RawCodec::ID, str_repeat( "\0", EntryWriter::NONCE_SIZE ), self::memory_stream() );
	}

	/**
	 * Open a php://memory stream, optionally pre-filled and rewound.
	 *
	 * @param string $contents Optional initial contents.
	 * @return resource
	 * @throws RuntimeException If php://memory cannot be opened.
	 */
	private static function memory_stream( string $contents = '' ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://memory is an in-process buffer, not a file.
		$stream = fopen( 'php://memory', 'r+b' );
		if ( false === $stream ) {
			throw new RuntimeException( 'Could not open php://memory.' );
		}
		if ( '' !== $contents ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Operating on a test stream resource, not a filesystem path.
			fwrite( $stream, $contents );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource, not a filesystem path.
			rewind( $stream );
		}
		return $stream;
	}

	/**
	 * Build a controller wired with a real recovery runner, and invoke recover_from_safety_archive() on it.
	 *
	 * The recovery runner's FileWriter is built with $replaying_safety_archive
	 * true — matching production's own default_restore_runner( null, $source, true )
	 * call for this exact leg — rooted under $this->base so
	 * {@see self::tearDown()} cleans it up, and injected via
	 * {@see self::controller()} so default_restore_runner() itself is never
	 * reached. The forward runner is a second, freshly built RestoreRunner that
	 * has written nothing, so remove_created_paths() on it is always a precise
	 * revert on its own — isolating the outcome to whether the replay skipped a
	 * symlink.
	 *
	 * @param string $safety_path Absolute path of the real safety archive to replay.
	 * @return string One of RestoreController's own RECOVERY_* constants.
	 */
	private function invoke_recovery_from_safety_archive( string $safety_path ): string {
		$this->stub_transients();

		$recovery_runner = new RestoreRunner(
			new EntryReader( CodecRegistry::with_defaults() ),
			new FileWriter( $this->base . '/recovery-root', false, null, null, null, null, true ),
			new DatabaseWriter( new FakeDbAdapter() )
		);
		$forward_runner  = new RestoreRunner(
			new EntryReader( CodecRegistry::with_defaults() ),
			new FileWriter( $this->base . '/forward-root' ),
			new DatabaseWriter( new FakeDbAdapter() )
		);

		$controller = $this->controller( $recovery_runner );

		$method = new ReflectionMethod( RestoreController::class, 'recover_from_safety_archive' );
		return (string) $method->invoke( $controller, $safety_path, $forward_runner );
	}

	/**
	 * RestoreController::RECOVERY_MERGE, read via reflection (a private constant).
	 *
	 * @return string
	 */
	private static function recovery_merge_constant(): string {
		return (string) ( new ReflectionClassConstant( RestoreController::class, 'RECOVERY_MERGE' ) )->getValue();
	}

	/**
	 * RestoreController::RECOVERY_PRECISE, read via reflection (a private constant).
	 *
	 * @return string
	 */
	private static function recovery_precise_constant(): string {
		return (string) ( new ReflectionClassConstant( RestoreController::class, 'RECOVERY_PRECISE' ) )->getValue();
	}

	/**
	 * RestoreController::RECOVERY_MERGE_LINKS, read via reflection (a private constant).
	 *
	 * @return string
	 */
	private static function recovery_merge_links_constant(): string {
		return (string) ( new ReflectionClassConstant( RestoreController::class, 'RECOVERY_MERGE_LINKS' ) )->getValue();
	}

	/**
	 * Invoke the controller's private failure_message() with the given recovery outcome and skipped links.
	 *
	 * @param string|null           $recovery         One of RestoreController's own RECOVERY_* constants, or null for no recovery attempted.
	 * @param array<string, string> $skipped_symlinks Every skipped link, as entry path => the raw absolute target recorded in the archive.
	 * @return string The operator-visible failure message.
	 */
	private function invoke_failure_message( ?string $recovery, array $skipped_symlinks = array() ): string {
		$method = new ReflectionMethod( RestoreController::class, 'failure_message' );
		return (string) $method->invoke( $this->controller(), new RuntimeException( 'replay failed mid-restore' ), $recovery, $skipped_symlinks );
	}

	/**
	 * Write a valid, empty, encrypted archive to the given path.
	 *
	 * For {@see self::test_preview_suppresses_the_hint_for_an_encrypted_backup()}:
	 * the controller's encryption pre-check must see a genuinely encrypted header,
	 * not merely a scope, so this writes with an EncryptionContext rather than
	 * going through write_archive_with_scope().
	 *
	 * @param string $path Absolute path to write the archive to.
	 * @return void
	 */
	private function write_encrypted_archive( string $path ): void {
		$key        = str_repeat( 'k', Cipher::KEY_SIZE );
		$encryption = new EncryptionContext( new OpensslAesGcmCipher(), $key, str_repeat( 's', 16 ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Opening a temp fixture archive for writing.
		$dest = fopen( $path, 'w+b' );
		if ( false === $dest ) {
			$this->fail( 'Could not open the fixture archive for writing.' );
		}
		( new ArchiveWriter( new EntryWriter( CodecRegistry::with_defaults() ), new FooterWriter() ) )
			->write_archive( $this->sample_provenance( Scope::content_only( array() ) ), array(), $dest, null, $encryption );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the temp fixture archive.
		fclose( $dest );
	}

	/**
	 * Build a Provenance with realistic but arbitrary field values for the fixture.
	 *
	 * @param Scope|null $scope The scope to record, or null for a legacy archive.
	 * @return Provenance
	 */
	private function sample_provenance( ?Scope $scope ): Provenance {
		return new Provenance(
			'6.6.1',
			'8.2.10',
			'https://example.test',
			'utf8mb4',
			'utf8mb4_unicode_520_ci',
			new ExporterInfo( 'pontifex', '0.1.0' ),
			new DateTimeImmutable( '2026-05-23T10:00:00+00:00', new DateTimeZone( 'UTC' ) ),
			null,
			'wp_',
			$scope
		);
	}

	/**
	 * Recursively delete a directory tree.
	 *
	 * @param string $path Absolute path to remove.
	 * @return void
	 */
	private static function rmtree( string $path ): void {
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( scandir( $path ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$full = $path . '/' . $entry;
			if ( is_dir( $full ) ) {
				self::rmtree( $full );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Test fixture teardown.
				@unlink( $full );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.PHP.NoSilencedErrors.Discouraged -- Test fixture teardown.
		@rmdir( $path );
	}
}
