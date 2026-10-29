<?php
/**
 * Surgical __invoke branch tests for ImportCommand.
 *
 * @package Pontifex\Tests\Unit\Cli\ImportCommand
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Cli\ImportCommand;

require_once __DIR__ . '/../../Manifest/Fakes/WpdbStub.php';

use DateTimeImmutable;
use DateTimeZone;
use Brain\Monkey\Functions;
use Mockery;
use Pontifex\Admin\RestoreOutcome;
use Pontifex\Archive\Codec\CodecRegistry;
use Pontifex\Archive\Codec\RawCodec;
use Pontifex\Archive\Crypto\CipherException;
use Pontifex\Archive\Crypto\SigningContext;
use Pontifex\Archive\Crypto\SigningKeypair;
use Pontifex\Archive\Format\ArchiveSignature;
use Pontifex\Archive\Format\ByteOrder;
use Pontifex\Archive\Format\EntryHeader;
use Pontifex\Archive\Format\ExporterInfo;
use Pontifex\Archive\Format\Header;
use Pontifex\Archive\Format\Provenance;
use Pontifex\Archive\Format\Scope;
use Pontifex\Archive\Reader\EntryReader;
use Pontifex\Archive\Writer\ArchiveWriter;
use Pontifex\Archive\Writer\EntryPlan;
use Pontifex\Archive\Writer\EntryWriter;
use Pontifex\Archive\Writer\FooterWriter;
use Pontifex\Cli\ImportCommand;
use Pontifex\Cli\NullProgressBar;
use Pontifex\Cli\SigningKeys;
use Pontifex\Cli\TransferHistory;
use Pontifex\Environment\Environment;
use Pontifex\Exception\ArchiveNotTrustworthy;
use Pontifex\Exception\BuildCannotComply;
use Pontifex\Exception\HostCannotComply;
use Pontifex\Exception\InvalidRequest;
use Pontifex\Job\JobStore;
use Pontifex\Lock\OperationLock;
use Pontifex\Migrate\RewriteReport;
use Pontifex\Migrate\UrlMigratorInterface;
use Pontifex\Restore\DatabaseWriter;
use Pontifex\Restore\FileWriter;
use Pontifex\Restore\RestoreRunner;
use Pontifex\Restore\RestoreRunnerInterface;
use Pontifex\Rollback\SafetyArchiverInterface;
use Pontifex\Tests\TestCase;
use Pontifex\Tests\Unit\Manifest\Fakes\FakeDbAdapter;
use Pontifex\WordPress\WordPressContext;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use wpdb;

/**
 * Behavioural coverage of the genuine __invoke branches.
 *
 * As with ExportCommand, the bulk of orchestration is not worth a behavioural
 * __invoke test. The branches that genuinely earn a surgical unit test are:
 *
 *  1. The --yes short-circuit: confirm() is never called when --yes is set.
 *  2. The try-finally exception path: a restore failure closes the handle,
 *     releases the lock, reports a verdict naming which kind of refusal it was
 *     (ADR 0022), and halts non-zero rather than propagating.
 *  3. The --dry-run branch: it calls verify() (not restore()), writes no
 *     counters, and takes no safety archive.
 *  4. The safety archive (v0.2.0): a real import takes one before restoring;
 *     --no-rollback-archive skips it; and a safety-archive failure aborts the
 *     import before the destructive restore runs.
 *
 * The restore engine and the safety archiver are injected as their interfaces —
 * the seams that exist precisely so these final-class collaborators can be faked
 * here. With them injected, the default wiring is never reached.
 */
final class InvokeBranchesTest extends TestCase {


	/**
	 * A real temporary archive file used as the import source.
	 *
	 * @var string|null
	 */
	private ?string $temp_archive_path = null;

	/**
	 * Create a real, readable temp archive file for the import source.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->temp_archive_path = sys_get_temp_dir() . '/pontifex-import-invoke-test-' . uniqid( '', true ) . '.wpmig';
		// A real (empty, unsigned) archive: ImportCommand now reads the header to
		// check for a signature before restoring, so the source must parse as one.
		self::write_unsigned_archive( $this->temp_archive_path );
	}

	/**
	 * Write a minimal, valid, unsigned, content-only archive to the given path.
	 *
	 * Content-only so the default (content-only) import accepts it; the import scope
	 * gate is covered separately by the whole-site and legacy refusal tests.
	 *
	 * @param string $path Destination path.
	 * @return void
	 */
	private static function write_unsigned_archive( string $path ): void {
		self::write_archive_to( $path, null, Scope::content_only( array() ), 'wp_' );
	}

	/**
	 * Write a minimal, valid, unsigned, whole-site archive to the given path.
	 *
	 * @param string $path Destination path.
	 * @return void
	 */
	private static function write_whole_site_archive( string $path ): void {
		self::write_archive_to( $path, null, Scope::whole_site( array() ), 'wp_' );
	}

	/**
	 * Write a minimal, valid, unsigned, legacy (no-scope) archive to the given path.
	 *
	 * @param string $path Destination path.
	 * @return void
	 */
	private static function write_legacy_archive( string $path ): void {
		self::write_archive_to( $path, null, null, null );
	}

	/**
	 * Write a minimal, valid archive to the given path, optionally signed and scoped.
	 *
	 * @param string              $path         Destination path.
	 * @param SigningContext|null $signing      Signing context, or null for an unsigned archive.
	 * @param Scope|null          $scope        The scope to record, or null for a legacy (no-scope) archive.
	 * @param string|null         $table_prefix The table prefix to record, or null for none.
	 * @param EntryPlan[]         $plans        Optional. The entries to include; default none, an empty archive.
	 * @param string              $exporter_version Optional. The Pontifex version to record as the writer; default '0.3.0', an affected version.
	 * @return void
	 */
	private static function write_archive_to( string $path, ?SigningContext $signing, ?Scope $scope = null, ?string $table_prefix = null, array $plans = array(), string $exporter_version = '0.3.0' ): void {
		$provenance = new Provenance(
			'6.6.1',
			'8.2.10',
			'https://example.test',
			'utf8mb4',
			'utf8mb4_unicode_520_ci',
			new ExporterInfo( 'pontifex', $exporter_version ),
			new DateTimeImmutable( '2026-06-23T10:00:00+00:00', new DateTimeZone( 'UTC' ) ),
			null,
			$table_prefix,
			$scope
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Opening a temp source file for the command to read; WP_Filesystem is not bootstrapped in unit tests.
		$destination = fopen( $path, 'w+b' );
		$writer      = new ArchiveWriter( new EntryWriter( CodecRegistry::with_defaults() ), new FooterWriter() );
		$writer->write_archive( $provenance, $plans, $destination, null, null, $signing );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the test's own handle.
		fclose( $destination );
	}

	/**
	 * Remove the temp archive file (and any sibling key files) the test created.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ( null !== $this->temp_archive_path ) {
			foreach ( array( $this->temp_archive_path, $this->temp_archive_path . '.key', $this->temp_archive_path . '.pub' ) as $path ) {
				if ( file_exists( $path ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test-only cleanup of a file the test itself created in sys_get_temp_dir().
					unlink( $path );
				}
			}
		}
		$this->temp_archive_path = null;
		parent::tearDown();
	}

	/**
	 * Passing --yes must short-circuit WP_CLI::confirm so it is never invoked.
	 *
	 * @return void
	 */
	public function test_invoke_with_yes_flag_short_circuits_confirmation(): void {
		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'warning' )->zeroOrMoreTimes();
		$wp_cli->shouldNotReceive( 'confirm' );

		$command = new ImportCommand(
			$this->build_environment_mock(),
			$this->build_wordpress_context_mock(),
			$this->build_restore_runner_mock_succeeding(),
			new NullLogger(),
			new NullProgressBar(),
			$this->build_safety_archiver_succeeding()
		);

		$command( array( $this->temp_archive_path ), array( 'yes' => true ) );

		$this->assertFileExists(
			$this->temp_archive_path,
			'ImportCommand should have run to completion with --yes set.'
		);
	}

	/**
	 * A restore failure halts non-zero instead of escaping as an uncaught exception.
	 *
	 * It used to be re-thrown out of __invoke, where WordPress's fatal handler caught
	 * it and printed a raw stack trace followed by "There has been a critical error on
	 * this website" — the sentence that says Pontifex is broken when the truth is
	 * usually that the archive is. The exit code was already non-zero and stays so;
	 * what changes is that a human can now read why it stopped.
	 *
	 * @return void
	 */
	public function test_invoke_halts_instead_of_propagating_a_restore_failure(): void {
		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'restore' )->once()->andThrow( new RuntimeException( 'simulated restore failure' ) );

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'warning' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'halt' )->once()->with( 1 );

		$command = new ImportCommand(
			$this->build_environment_mock(),
			$this->build_wordpress_context_mock(),
			$restore_runner,
			new NullLogger(),
			new NullProgressBar(),
			$this->build_safety_archiver_succeeding()
		);

		$command( array( $this->temp_archive_path ), array( 'yes' => true ) );

		$this->assertFileExists(
			$this->temp_archive_path,
			'ImportCommand should swallow the failure and run to completion (halt is mocked, so execution continues).'
		);
	}

	/**
	 * A successful import records informational log lines and no error.
	 *
	 * @return void
	 */
	public function test_invoke_logs_info_on_success(): void {
		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'warning' )->zeroOrMoreTimes();

		$logger = Mockery::mock( LoggerInterface::class );
		$logger->shouldReceive( 'info' )->atLeast()->once();
		$logger->shouldReceive( 'error' )->never();

		$command = new ImportCommand(
			$this->build_environment_mock(),
			$this->build_wordpress_context_mock(),
			$this->build_restore_runner_mock_succeeding(),
			$logger,
			new NullProgressBar(),
			$this->build_safety_archiver_succeeding()
		);

		$command( array( $this->temp_archive_path ), array( 'yes' => true ) );

		$this->assertFileExists(
			$this->temp_archive_path,
			'ImportCommand should have run to completion on the happy path.'
		);
	}

	/**
	 * A failing import still records its error log line, now alongside the verdict.
	 *
	 * The log entry is what a browser-side operator has to fall back on, so the
	 * readable CLI verdict is in addition to it, never instead of it.
	 *
	 * @return void
	 */
	public function test_invoke_logs_error_when_restore_fails(): void {
		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'restore' )->once()->andThrow( new RuntimeException( 'simulated restore failure' ) );

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'warning' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'halt' )->once()->with( 1 );

		$logger = Mockery::mock( LoggerInterface::class );
		$logger->shouldReceive( 'info' )->zeroOrMoreTimes();
		// Two errors are logged: the import failure, then the auto-recovery failure (the
		// safety archive path here is a placeholder the recovery cannot open).
		$logger->shouldReceive( 'error' )->twice();

		$command = new ImportCommand(
			$this->build_environment_mock(),
			$this->build_wordpress_context_mock(),
			$restore_runner,
			$logger,
			new NullProgressBar(),
			$this->build_safety_archiver_succeeding()
		);

		$command( array( $this->temp_archive_path ), array( 'yes' => true ) );

		$this->assertFileExists(
			$this->temp_archive_path,
			'The failure is logged and reported, not re-thrown (halt is mocked, so execution continues).'
		);
	}

	/**
	 * A restore that fails mid-replay is automatically rolled back from the safety archive.
	 *
	 * The forward replay throws; the failure handler then opens the safety archive and
	 * replays it (verify then restore) to recover the site, warning the operator that an
	 * automatic rollback occurred. The command still exits non-zero — now by halting
	 * rather than by re-throwing.
	 *
	 * The forward engine here is a {@see RestoreRunnerInterface} mock, not a real
	 * {@see \Pontifex\Restore\RestoreRunner} — so it has no creation ledger for the
	 * failure handler to consult, and the recovery honestly reports a MERGE rather than
	 * a precise revert (see {@see \Pontifex\Restore\FileWriter::remove_created_paths()}
	 * and ImportCommand's own RECOVERY_* constants). Both outcomes share the phrase
	 * "rolled back to its state before the import", which is what this test asserts on;
	 * proving the PRECISE wording needs the real engine, and that is exercised at the
	 * RestoreRunner/FileWriter level in
	 * {@see \Pontifex\Tests\Unit\Restore\RecoveryCreationLedgerTest}.
	 *
	 * The order matters and is asserted: the verdict says why the import stopped, then
	 * the warning says what was done about it. Reversed, the operator reads that their
	 * site was rolled back before reading why.
	 *
	 * @return void
	 */
	public function test_invoke_auto_rolls_back_after_a_failed_replay(): void {
		// A real (placeholder) safety archive file the recovery can open.
		$safety_path = sys_get_temp_dir() . '/pontifex-safety-' . uniqid( '', true ) . '.wpmig';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Seeding a placeholder safety archive; the injected engine stands in for reading it.
		file_put_contents( $safety_path, 'x' );

		$safety_archiver = Mockery::mock( SafetyArchiverInterface::class );
		$safety_archiver->shouldReceive( 'create' )->once()->andReturn( $safety_path );

		// Forward: replay throws. Recovery: verify passes, replay succeeds. The forward
		// failure is a typed refusal because that is the realistic cause of a mid-replay
		// abort (an entry that does not match its recorded hash), and because it is the
		// one path that exercises a REAL import's refusal verdict — every other verdict
		// test is a dry run.
		$replays        = 0;
		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'verify' )->once();
		$restore_runner->shouldReceive( 'restore' )->twice()->andReturnUsing(
			static function () use ( &$replays ): void {
				++$replays;
				if ( 1 === $replays ) {
					throw new ArchiveNotTrustworthy( 'simulated restore failure' );
				}
			}
		);

		// Both streams are captured in one ordered list so the verdict-then-warning
		// sequence can be asserted, not just the presence of each.
		$printed     = array();
		$rolled_back = false;
		$wp_cli      = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'halt' )->once()->with( 1 );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes()->andReturnUsing(
			static function ( string $message ) use ( &$printed ): void {
				$printed[] = $message;
			}
		);
		$wp_cli->shouldReceive( 'warning' )->atLeast()->once()->andReturnUsing(
			static function ( string $message ) use ( &$rolled_back, &$printed ): void {
				$printed[] = $message;
				if ( str_contains( $message, 'rolled back to its state before the import' ) ) {
					$rolled_back = true;
				}
			}
		);

		$command = new ImportCommand(
			$this->build_environment_mock(),
			$this->build_wordpress_context_mock(),
			$restore_runner,
			new NullLogger(),
			new NullProgressBar(),
			$safety_archiver
		);

		try {
			$command( array( $this->temp_archive_path ), array( 'yes' => true ) );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Test cleanup of the placeholder safety archive.
			@unlink( $safety_path );
		}

		$this->assertTrue( $rolled_back, 'The operator is warned that the site\'s database was rolled back.' );

		$output = implode( "\n", $printed );
		$this->assertStringContainsString( 'Import refused.', $output, 'A real import states plainly that it refused, without the dry-run framing.' );
		$this->assertStringContainsString( 'This archive cannot be trusted: simulated restore failure', $output );
		$this->assertStringNotContainsString( 'Your site was not changed.', $output, 'A real import must never claim nothing happened — the site was written to.' );

		$verdict_at = self::index_of_line_containing( $printed, 'simulated restore failure' );
		$warning_at = self::index_of_line_containing( $printed, 'rolled back to its state before the import' );
		$this->assertNotNull( $verdict_at, 'The failure verdict names the engine\'s own message.' );
		$this->assertNotNull( $warning_at, 'The recovery warning is printed.' );
		$this->assertLessThan(
			$warning_at,
			$verdict_at,
			'Why it stopped must be printed before what was done about it.'
		);
	}

	/**
	 * A --dry-run calls verify(), never restore(), writes no counters, and takes no safety archive.
	 *
	 * @return void
	 */
	public function test_invoke_dry_run_verifies_without_restoring_or_counting(): void {
		$wordpress_context = Mockery::mock( WordPressContext::class );
		$wordpress_context->shouldNotReceive( 'save_option' );

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'verify' )->once();
		$restore_runner->shouldNotReceive( 'restore' );

		$safety_archiver = Mockery::mock( SafetyArchiverInterface::class );
		$safety_archiver->shouldNotReceive( 'create' );

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'warning' )->zeroOrMoreTimes();
		$wp_cli->shouldNotReceive( 'confirm' );

		$command = new ImportCommand(
			$this->build_environment_mock(),
			$wordpress_context,
			$restore_runner,
			new NullLogger(),
			new NullProgressBar(),
			$safety_archiver
		);

		$command( array( $this->temp_archive_path ), array( 'dry-run' => true ) );

		$this->assertFileExists(
			$this->temp_archive_path,
			'A dry-run should read the archive without removing or altering it.'
		);
	}

	/**
	 * A real import takes a safety archive before it restores.
	 *
	 * Ordering is asserted: create() must be called before restore(), so the
	 * undo exists before the destructive write begins.
	 *
	 * @return void
	 */
	public function test_invoke_takes_a_safety_archive_before_restoring(): void {
		$safety_archiver = Mockery::mock( SafetyArchiverInterface::class );
		$safety_archiver->shouldReceive( 'create' )->once()->ordered()->andReturn( '/var/www/html/wp-content/pontifex/rollback/safety.wpmig' );

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'restore' )->once()->ordered();

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'warning' )->zeroOrMoreTimes();

		$command = new ImportCommand(
			$this->build_environment_mock(),
			$this->build_wordpress_context_mock(),
			$restore_runner,
			new NullLogger(),
			new NullProgressBar(),
			$safety_archiver
		);

		$command( array( $this->temp_archive_path ), array( 'yes' => true ) );

		$this->assertFileExists( $this->temp_archive_path );
	}

	/**
	 * --no-rollback-archive skips the safety archive but still restores.
	 *
	 * The flag is passed as WP-CLI delivers it: its --no-<name> convention turns
	 * `--no-rollback-archive` into array( 'rollback-archive' => false ), not a
	 * 'no-rollback-archive' key. The command must read that real form.
	 *
	 * @return void
	 */
	public function test_invoke_no_rollback_archive_flag_skips_the_safety_archive(): void {
		$safety_archiver = Mockery::mock( SafetyArchiverInterface::class );
		$safety_archiver->shouldNotReceive( 'create' );

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'restore' )->once();

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'warning' )->zeroOrMoreTimes();

		$command = new ImportCommand(
			$this->build_environment_mock(),
			$this->build_wordpress_context_mock(),
			$restore_runner,
			new NullLogger(),
			new NullProgressBar(),
			$safety_archiver
		);

		$command(
			array( $this->temp_archive_path ),
			array(
				'yes'              => true,
				'rollback-archive' => false,
			)
		);

		$this->assertFileExists( $this->temp_archive_path );
	}

	/**
	 * A safety-archive failure aborts the import before the restore runs.
	 *
	 * The safety archive is written before the destructive restore, so if it
	 * throws (e.g. the disk preflight refuses), restore() must never be reached;
	 * the failure is logged and re-thrown.
	 *
	 * @return void
	 */
	public function test_invoke_safety_archive_failure_aborts_before_restore(): void {
		$safety_archiver = Mockery::mock( SafetyArchiverInterface::class );
		$safety_archiver->shouldReceive( 'create' )->once()->andThrow( new RuntimeException( 'not enough free disk space' ) );

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldNotReceive( 'restore' );

		$printed = array();
		$wp_cli  = $this->mock_wp_cli_capturing_output( $printed );
		$wp_cli->shouldReceive( 'halt' )->once()->with( 1 );

		$logger = Mockery::mock( LoggerInterface::class );
		$logger->shouldReceive( 'info' )->zeroOrMoreTimes();
		$logger->shouldReceive( 'error' )->once();

		$command = new ImportCommand(
			$this->build_environment_mock(),
			$this->build_wordpress_context_mock(),
			$restore_runner,
			$logger,
			new NullProgressBar(),
			$safety_archiver
		);

		$command( array( $this->temp_archive_path ), array( 'yes' => true ) );

		$this->assertStringContainsString(
			'not enough free disk space',
			implode( "\n", $printed ),
			'A safety-archive failure must still tell the operator what stopped the import.'
		);
	}

	/**
	 * With --new-url, the command migrates the database after restoring.
	 *
	 * Ordering is asserted: restore() runs before migrate(), so the URL rewrite
	 * only touches data the restore has already put in place.
	 *
	 * @return void
	 */
	public function test_invoke_with_new_url_migrates_after_restoring(): void {
		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'restore' )->once()->ordered();

		$url_migrator = Mockery::mock( UrlMigratorInterface::class );
		$url_migrator->shouldReceive( 'source_url' )->andReturn( 'https://old.test' );
		$url_migrator->shouldReceive( 'migrate' )
			->once()
			->ordered()
			->with( 'https://old.test', 'https://new.example' )
			->andReturn( new RewriteReport( 1, array(), 1, 1, 1, 0 ) );

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'warning' )->zeroOrMoreTimes();

		$command = new ImportCommand(
			$this->build_environment_mock(),
			$this->build_wordpress_context_mock(),
			$restore_runner,
			new NullLogger(),
			new NullProgressBar(),
			$this->build_safety_archiver_succeeding(),
			$url_migrator
		);

		$command(
			array( $this->temp_archive_path ),
			array(
				'yes'     => true,
				'new-url' => 'https://new.example',
			)
		);

		$this->assertFileExists( $this->temp_archive_path );
	}

	/**
	 * Without --new-url, the migrator is never consulted.
	 *
	 * @return void
	 */
	public function test_invoke_without_new_url_never_migrates(): void {
		$url_migrator = Mockery::mock( UrlMigratorInterface::class );
		$url_migrator->shouldNotReceive( 'source_url' );
		$url_migrator->shouldNotReceive( 'migrate' );

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'warning' )->zeroOrMoreTimes();

		$command = new ImportCommand(
			$this->build_environment_mock(),
			$this->build_wordpress_context_mock(),
			$this->build_restore_runner_mock_succeeding(),
			new NullLogger(),
			new NullProgressBar(),
			$this->build_safety_archiver_succeeding(),
			$url_migrator
		);

		$command( array( $this->temp_archive_path ), array( 'yes' => true ) );

		$this->assertFileExists( $this->temp_archive_path );
	}

	/**
	 * A --dry-run with --new-url reads the source URL to announce the plan but migrates nothing.
	 *
	 * @return void
	 */
	public function test_invoke_dry_run_with_new_url_announces_but_does_not_migrate(): void {
		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'verify' )->once();
		$restore_runner->shouldNotReceive( 'restore' );

		$url_migrator = Mockery::mock( UrlMigratorInterface::class );
		$url_migrator->shouldReceive( 'source_url' )->once()->andReturn( 'https://old.test' );
		$url_migrator->shouldNotReceive( 'migrate' );

		$safety_archiver = Mockery::mock( SafetyArchiverInterface::class );
		$safety_archiver->shouldNotReceive( 'create' );

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'warning' )->zeroOrMoreTimes();
		$wp_cli->shouldNotReceive( 'confirm' );

		$command = new ImportCommand(
			$this->build_environment_mock(),
			$this->build_wordpress_context_mock(),
			$restore_runner,
			new NullLogger(),
			new NullProgressBar(),
			$safety_archiver,
			$url_migrator
		);

		$command(
			array( $this->temp_archive_path ),
			array(
				'dry-run' => true,
				'new-url' => 'https://new.example',
			)
		);

		$this->assertFileExists( $this->temp_archive_path );
	}

	/**
	 * An unsigned archive is refused when a trusted key is supplied.
	 *
	 * ADR 0012: supplying a key declares "only signed archives are trusted".
	 * The unkeyed integrity hashes detect corruption, not tampering, so an
	 * unsigned archive under a trusted key must refuse before any write.
	 *
	 * @return void
	 */
	public function test_invoke_refuses_an_unsigned_archive_when_a_key_is_supplied(): void {
		self::write_archive_to( $this->temp_archive_path, null, Scope::content_only( array() ) );
		SigningKeys::write_keypair( SigningKeypair::generate(), $this->temp_archive_path . '.key', $this->temp_archive_path . '.pub' );

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldNotReceive( 'restore' );
		$restore_runner->shouldNotReceive( 'verify' );

		$safety_archiver = Mockery::mock( SafetyArchiverInterface::class );
		$safety_archiver->shouldNotReceive( 'create' );

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'warning' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'error' )->once()->andThrow( new RuntimeException( 'refusing unsigned' ) );

		$command = new ImportCommand(
			$this->build_environment_mock(),
			$this->build_wordpress_context_mock(),
			$restore_runner,
			new NullLogger(),
			new NullProgressBar(),
			$safety_archiver
		);

		$this->expectException( RuntimeException::class );

		$command(
			array( $this->temp_archive_path ),
			array(
				'yes'        => true,
				'public-key' => $this->temp_archive_path . '.pub',
			)
		);
	}

	/**
	 * The strip attack: a signed archive with its signature removed is refused.
	 *
	 * The exact downgrade the audit found: clear the header's signed flag and
	 * truncate the trailing signature block, and the archive presents as a
	 * well-formed UNSIGNED one (the unkeyed hashes need no rebuilding, since
	 * none of them covers the header flags). Under a trusted key it must now
	 * refuse — previously it restored with no warning at all.
	 *
	 * @return void
	 */
	public function test_invoke_refuses_a_stripped_signature_when_a_key_is_supplied(): void {
		$keypair = SigningKeypair::generate();
		self::write_archive_to( $this->temp_archive_path, SigningContext::from_keypair( $keypair ), Scope::content_only( array() ) );
		SigningKeys::write_keypair( $keypair, $this->temp_archive_path . '.key', $this->temp_archive_path . '.pub' );
		self::strip_signature( $this->temp_archive_path );

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldNotReceive( 'restore' );
		$restore_runner->shouldNotReceive( 'verify' );

		$safety_archiver = Mockery::mock( SafetyArchiverInterface::class );
		$safety_archiver->shouldNotReceive( 'create' );

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'warning' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'error' )->once()->andThrow( new RuntimeException( 'refusing stripped' ) );

		$command = new ImportCommand(
			$this->build_environment_mock(),
			$this->build_wordpress_context_mock(),
			$restore_runner,
			new NullLogger(),
			new NullProgressBar(),
			$safety_archiver
		);

		$this->expectException( RuntimeException::class );

		$command(
			array( $this->temp_archive_path ),
			array(
				'yes'        => true,
				'public-key' => $this->temp_archive_path . '.pub',
			)
		);
	}

	/**
	 * A pinned key enforces signatures with no flag on the command line.
	 *
	 * PONTIFEX_PUBLIC_KEY in wp-config.php is the durable trust anchor: the
	 * decision is made once in configuration instead of resting on a human
	 * remembering --public-key on every run.
	 *
	 * @return void
	 */
	public function test_invoke_enforces_a_pinned_key_without_the_flag(): void {
		self::write_archive_to( $this->temp_archive_path, null );
		SigningKeys::write_keypair( SigningKeypair::generate(), $this->temp_archive_path . '.key', $this->temp_archive_path . '.pub' );

		$environment = $this->build_environment_mock_with_pin( $this->temp_archive_path . '.pub' );

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldNotReceive( 'restore' );
		$restore_runner->shouldNotReceive( 'verify' );

		$safety_archiver = Mockery::mock( SafetyArchiverInterface::class );
		$safety_archiver->shouldNotReceive( 'create' );

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'warning' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'error' )->once()->andThrow( new RuntimeException( 'refusing unsigned under pin' ) );

		$command = new ImportCommand(
			$environment,
			$this->build_wordpress_context_mock(),
			$restore_runner,
			new NullLogger(),
			new NullProgressBar(),
			$safety_archiver
		);

		$this->expectException( RuntimeException::class );

		$command( array( $this->temp_archive_path ), array( 'yes' => true ) );
	}

	/**
	 * An explicit --public-key overrides the pinned key for that run.
	 *
	 * Explicit beats ambient: the pin names the WRONG key here, the flag names
	 * the right one, and the signed archive restores.
	 *
	 * @return void
	 */
	public function test_invoke_flag_overrides_the_pinned_key(): void {
		$keypair = SigningKeypair::generate();
		self::write_archive_to( $this->temp_archive_path, SigningContext::from_keypair( $keypair ), Scope::content_only( array() ) );
		SigningKeys::write_keypair( $keypair, $this->temp_archive_path . '.key', $this->temp_archive_path . '.pub' );
		SigningKeys::write_keypair( SigningKeypair::generate(), $this->temp_archive_path . '.wrong.key', $this->temp_archive_path . '.wrong.pub' );

		$environment = $this->build_environment_mock_with_pin( $this->temp_archive_path . '.wrong.pub' );

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'warning' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'success' )->zeroOrMoreTimes();
		$wp_cli->shouldNotReceive( 'error' );

		$command = new ImportCommand(
			$environment,
			$this->build_wordpress_context_mock(),
			$this->build_restore_runner_mock_succeeding(),
			new NullLogger(),
			new NullProgressBar(),
			$this->build_safety_archiver_succeeding()
		);

		$command(
			array( $this->temp_archive_path ),
			array(
				'yes'        => true,
				'public-key' => $this->temp_archive_path . '.pub',
			)
		);

		$this->assertFileExists( $this->temp_archive_path, 'The flag key verified the signature, so the restore ran (no error was raised).' );
	}

	/**
	 * A signed archive that fails the supplied public key is refused before any restore.
	 *
	 * The signature gate runs before the safety archive and the restore, so a
	 * bad signature must reach neither: nothing is written.
	 *
	 * @return void
	 */
	public function test_invoke_aborts_before_restore_on_a_bad_signature(): void {
		$keypair = SigningKeypair::generate();
		self::write_archive_to( $this->temp_archive_path, SigningContext::from_keypair( $keypair ), Scope::content_only( array() ) );
		// A different keypair's public key — so the signature will not verify.
		SigningKeys::write_keypair( SigningKeypair::generate(), $this->temp_archive_path . '.key', $this->temp_archive_path . '.pub' );

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldNotReceive( 'restore' );
		$restore_runner->shouldNotReceive( 'verify' );

		$safety_archiver = Mockery::mock( SafetyArchiverInterface::class );
		$safety_archiver->shouldNotReceive( 'create' );

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'warning' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'warning' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'error' )->once()->andThrow( new RuntimeException( 'refusing to restore' ) );

		$command = new ImportCommand(
			$this->build_environment_mock(),
			$this->build_wordpress_context_mock(),
			$restore_runner,
			new NullLogger(),
			new NullProgressBar(),
			$safety_archiver
		);

		$this->expectException( RuntimeException::class );

		$command(
			array( $this->temp_archive_path ),
			array(
				'yes'        => true,
				'public-key' => $this->temp_archive_path . '.pub',
			)
		);
	}

	/**
	 * A default (content-only) import refuses a whole-site archive before any restore.
	 *
	 * The scope gate runs before the safety archive and the restore: a whole-site
	 * archive (which carries WordPress core and wp-config.php) is refused unless
	 * --whole-site is given, so a default restore never overwrites a live site's core.
	 *
	 * @return void
	 */
	public function test_invoke_refuses_a_whole_site_archive_by_default(): void {
		self::write_whole_site_archive( $this->temp_archive_path );

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldNotReceive( 'restore' );
		$restore_runner->shouldNotReceive( 'verify' );

		$safety_archiver = Mockery::mock( SafetyArchiverInterface::class );
		$safety_archiver->shouldNotReceive( 'create' );

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'warning' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'error' )->once()->andThrow( new RuntimeException( 'refusing a whole-site archive' ) );

		$command = new ImportCommand(
			$this->build_environment_mock(),
			$this->build_wordpress_context_mock(),
			$restore_runner,
			new NullLogger(),
			new NullProgressBar(),
			$safety_archiver
		);

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'refusing a whole-site archive' );

		$command( array( $this->temp_archive_path ), array( 'yes' => true ) );
	}

	/**
	 * A default (content-only) import refuses a legacy (no-scope) archive before any restore.
	 *
	 * A legacy archive predates the content-only format and is treated as
	 * whole-site, so it is refused on the default path just like an explicit
	 * whole-site archive.
	 *
	 * @return void
	 */
	public function test_invoke_refuses_a_legacy_archive_by_default(): void {
		self::write_legacy_archive( $this->temp_archive_path );

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldNotReceive( 'restore' );

		$safety_archiver = Mockery::mock( SafetyArchiverInterface::class );
		$safety_archiver->shouldNotReceive( 'create' );

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'warning' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'error' )->once()->andThrow( new RuntimeException( 'refusing a legacy archive' ) );

		$command = new ImportCommand(
			$this->build_environment_mock(),
			$this->build_wordpress_context_mock(),
			$restore_runner,
			new NullLogger(),
			new NullProgressBar(),
			$safety_archiver
		);

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'refusing a legacy archive' );

		$command( array( $this->temp_archive_path ), array( 'yes' => true ) );
	}

	/**
	 * Passing --whole-site allows restoring a whole-site archive.
	 *
	 * The opt-in path: with --whole-site the scope gate permits a whole-site
	 * archive, so the safety archive is taken and the restore proceeds.
	 *
	 * @return void
	 */
	public function test_invoke_with_whole_site_flag_restores_a_whole_site_archive(): void {
		self::write_whole_site_archive( $this->temp_archive_path );

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'warning' )->zeroOrMoreTimes();

		$command = new ImportCommand(
			$this->build_environment_mock(),
			$this->build_wordpress_context_mock(),
			$this->build_restore_runner_mock_succeeding(),
			new NullLogger(),
			new NullProgressBar(),
			$this->build_safety_archiver_succeeding()
		);

		$command(
			array( $this->temp_archive_path ),
			array(
				'whole-site' => true,
				'yes'        => true,
			)
		);

		$this->assertFileExists( $this->temp_archive_path );
	}

	/**
	 * A whole-site archive under the default (content-only) scope gate never
	 * acquires the lock — the reorder regression test.
	 *
	 * Before the fix, acquire() ran ahead of open_source()/verify_signature_gate()/
	 * assert_scope_permits_restore(), so a refused archive left the holder
	 * transient set (WP_CLI::error exits the real process, skipping the finally
	 * that would have released it). The command now validates the archive first,
	 * so a real OperationLock's acquire_named_lock() — the first thing acquire()
	 * itself calls — must never be reached; the command still errors exactly as
	 * before.
	 *
	 * @return void
	 */
	public function test_invoke_refuses_a_whole_site_archive_without_acquiring_the_lock(): void {
		self::write_whole_site_archive( $this->temp_archive_path );

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldNotReceive( 'restore' );
		$restore_runner->shouldNotReceive( 'verify' );

		$safety_archiver = Mockery::mock( SafetyArchiverInterface::class );
		$safety_archiver->shouldNotReceive( 'create' );

		$lock_context = Mockery::mock( WordPressContext::class );
		$lock_context->shouldNotReceive( 'acquire_named_lock' );
		$lock_context->shouldNotReceive( 'release_named_lock' );
		$lock = new OperationLock( $lock_context, new JobStore( sys_get_temp_dir() . '/pontifex-import-lock-test-' . uniqid( '', true ) ) );

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'warning' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'error' )->once()->andThrow( new RuntimeException( 'refusing a whole-site archive' ) );

		$command = new ImportCommand(
			$this->build_environment_mock(),
			$this->build_wordpress_context_mock(),
			$restore_runner,
			new NullLogger(),
			new NullProgressBar(),
			$safety_archiver,
			null,
			null,
			$lock
		);

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'refusing a whole-site archive' );

		$command( array( $this->temp_archive_path ), array( 'yes' => true ) );
	}

	/**
	 * The shutdown backstop releases a lock this command still holds.
	 *
	 * Simulates a mid-work fatal: the lock was genuinely acquired (as if by a real
	 * run) but nothing else called release(), so is_held() is still true when
	 * release_lock_on_shutdown() runs — mirroring register_shutdown_function()
	 * firing after PHP dies mid-restore. A second call afterwards must be a no-op,
	 * proving the idempotent release() guard: the transient is cleared exactly once.
	 *
	 * @return void
	 */
	public function test_release_lock_on_shutdown_releases_a_held_lock(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		$delete_transient_calls = 0;
		Functions\when( 'delete_transient' )->alias(
			static function () use ( &$delete_transient_calls ): bool {
				++$delete_transient_calls;
				return true;
			}
		);

		$lock_context = Mockery::mock( WordPressContext::class );
		$lock_context->shouldReceive( 'acquire_named_lock' )->once()->andReturn( true );
		$lock_context->shouldReceive( 'release_named_lock' )->once();

		$lock = new OperationLock( $lock_context, new JobStore( sys_get_temp_dir() . '/pontifex-import-lock-test-' . uniqid( '', true ) ) );
		$this->assertTrue( $lock->acquire( OperationLock::OP_RESTORE ), 'The lock must be genuinely held before the shutdown handler runs.' );

		$command = new ImportCommand(
			logger: new NullLogger(),
			progress: new NullProgressBar(),
			lock: $lock
		);

		$command->release_lock_on_shutdown();
		$this->assertSame( 1, $delete_transient_calls, 'A held lock must be released at shutdown, clearing the holder transient.' );
		$this->assertFalse( $lock->is_held(), 'The lock must no longer be held once the shutdown handler has released it.' );

		// A second shutdown call (e.g. two register_shutdown_function() registrations
		// firing) must not clear another operation's transient a second time.
		$command->release_lock_on_shutdown();
		$this->assertSame( 1, $delete_transient_calls, 'A second shutdown call after a clean release must be a no-op.' );
	}

	/**
	 * The shutdown backstop is a no-op when the lock was never acquired (or was
	 * already released cleanly through the normal finally).
	 *
	 * @return void
	 */
	public function test_release_lock_on_shutdown_is_a_no_op_when_the_lock_is_not_held(): void {
		$lock_context = Mockery::mock( WordPressContext::class );
		$lock_context->shouldNotReceive( 'acquire_named_lock' );
		$lock_context->shouldNotReceive( 'release_named_lock' );

		$lock = new OperationLock( $lock_context, new JobStore( sys_get_temp_dir() . '/pontifex-import-lock-test-' . uniqid( '', true ) ) );

		$command = new ImportCommand(
			logger: new NullLogger(),
			progress: new NullProgressBar(),
			lock: $lock
		);

		$command->release_lock_on_shutdown();

		$this->assertFalse( $lock->is_held(), 'A lock that was never held must still report unheld after the no-op shutdown call.' );
	}

	/**
	 * An archive that cannot be trusted is named as such, and the operator is told not to restore it.
	 *
	 * This is the kind that matters most: the file is the problem, so the useful
	 * advice is "do not restore this", not "check your server".
	 *
	 * @return void
	 */
	public function test_invoke_reports_an_untrustworthy_archive_and_halts(): void {
		$printed = array();
		$wp_cli  = $this->mock_wp_cli_capturing_output( $printed );
		$wp_cli->shouldReceive( 'halt' )->once()->with( 1 );

		$this->run_failing_dry_run( new ArchiveNotTrustworthy( 'the entry hash does not match' ) );

		$output = implode( "\n", $printed );
		$this->assertStringContainsString( 'Dry run: this restore would be refused. Your site was not changed.', $output );
		$this->assertStringContainsString( 'This archive cannot be trusted: the entry hash does not match', $output );
		$this->assertStringContainsString( 'Do not restore this archive', $output );
	}

	/**
	 * A host that cannot comply is distinguished from a bad archive.
	 *
	 * The archive may be perfectly good, so telling the operator to fetch a fresh
	 * copy would send them to fix the one thing that is not broken.
	 *
	 * @return void
	 */
	public function test_invoke_reports_a_host_that_cannot_comply_and_halts(): void {
		$printed = array();
		$wp_cli  = $this->mock_wp_cli_capturing_output( $printed );
		$wp_cli->shouldReceive( 'halt' )->once()->with( 1 );

		$this->run_failing_dry_run( new HostCannotComply( 'there is not enough free disk space' ) );

		$output = implode( "\n", $printed );
		$this->assertStringContainsString( 'Dry run: this restore would be refused. Your site was not changed.', $output );
		$this->assertStringContainsString( 'This host cannot complete the restore: there is not enough free disk space', $output );
		$this->assertStringContainsString( 'the problem is this server', $output );
		$this->assertStringNotContainsString( 'Do not restore this archive', $output );
	}

	/**
	 * A build that cannot comply is distinguished from both a bad archive and a
	 * host problem.
	 *
	 * The archive is sound and the host is fine; a ceiling compiled into every
	 * build of Pontifex is what stopped this one entry. Telling the operator
	 * to fetch a fresh copy would send them nowhere — the same ceiling
	 * refuses the identical entry in the fresh copy too — and telling them to
	 * fix their server would be equally useless, since no server setting
	 * moves this number.
	 *
	 * @return void
	 */
	public function test_invoke_reports_a_build_that_cannot_comply_and_halts(): void {
		$printed = array();
		$wp_cli  = $this->mock_wp_cli_capturing_output( $printed );
		$wp_cli->shouldReceive( 'halt' )->once()->with( 1 );

		$this->run_failing_dry_run( new BuildCannotComply( 'Entry "wp-content/uploads/2024/huge-video.mov" declares 2200000000 decoded bytes, exceeding the 2147483648-byte budget for this restore.' ) );

		$output = implode( "\n", $printed );
		$this->assertStringContainsString( 'Dry run: this restore would be refused. Your site was not changed.', $output );
		$this->assertStringContainsString( 'wp-content/uploads/2024/huge-video.mov', $output, 'The verdict must name the entry that was too large.' );
		$this->assertStringContainsString( '2 GB', $output, 'The verdict must give the limit as a human figure, not a raw byte count.' );
		$this->assertStringContainsString( 'not damaged', $output );
		$this->assertStringContainsString( 'does not need replacing', $output );
		$this->assertStringNotContainsString( 'fetch a fresh copy', $output, 'A fresh copy of the same archive would be refused identically.' );
		$this->assertStringNotContainsString( 'cannot be trusted', $output );
		$this->assertStringNotContainsString( 'this server', $output, 'No server setting fixes a limit compiled into the build.' );
	}

	/**
	 * A wrong request is reported without blaming the archive or the host.
	 *
	 * Nothing is wrong with anything; the invocation needs correcting, so the
	 * archive path is not part of the verdict.
	 *
	 * @return void
	 */
	public function test_invoke_reports_an_invalid_request_and_halts(): void {
		$printed = array();
		$wp_cli  = $this->mock_wp_cli_capturing_output( $printed );
		$wp_cli->shouldReceive( 'halt' )->once()->with( 1 );

		$this->run_failing_dry_run( new InvalidRequest( '--new-url requires a new site URL' ) );

		$output = implode( "\n", $printed );
		$this->assertStringContainsString( 'The request needs correcting: --new-url requires a new site URL', $output );
		$this->assertStringNotContainsString( 'cannot be trusted', $output );
		$this->assertStringNotContainsString( 'Full details are in the Pontifex log', $output );
	}

	/**
	 * A refusal Pontifex has not yet classified is still reported as a refusal.
	 *
	 * Most of the safety core still throws exceptions that predate the taxonomy, and
	 * five of them (a wrong passphrase among them) carry the marker interface without
	 * being one of the three kinds. Those are decisions Pontifex made deliberately, so
	 * calling them a failure would misreport a refusal as a fault.
	 *
	 * @return void
	 */
	public function test_invoke_reports_an_unclassified_refusal_as_a_refusal(): void {
		$printed = array();
		$wp_cli  = $this->mock_wp_cli_capturing_output( $printed );
		$wp_cli->shouldReceive( 'halt' )->once()->with( 1 );

		$this->run_failing_dry_run( new CipherException( 'failed to decrypt; the passphrase is wrong' ) );

		$output = implode( "\n", $printed );
		$this->assertStringContainsString( 'Dry run: this restore would be refused.', $output );
		$this->assertStringContainsString( 'The failure was: failed to decrypt; the passphrase is wrong', $output );
	}

	/**
	 * A failure carrying no Pontifex type is reported as a failure, not a refusal.
	 *
	 * This is the case the defect was found on — a truncated archive raises a bare
	 * RuntimeException from the reader — and it is why the three kinds alone are not
	 * enough. Claiming a refusal here would assert an intent that was never there.
	 *
	 * @return void
	 */
	public function test_invoke_reports_an_unrecognised_failure_as_a_failure_not_a_refusal(): void {
		$printed = array();
		$wp_cli  = $this->mock_wp_cli_capturing_output( $printed );
		$wp_cli->shouldReceive( 'halt' )->once()->with( 1 );

		$this->run_failing_dry_run( new RuntimeException( 'ArchiveReader: manifest does not fit between header and footer' ) );

		$output = implode( "\n", $printed );
		$this->assertStringContainsString( 'Dry run: this restore failed. Your site was not changed.', $output );
		$this->assertStringNotContainsString( 'would be refused', $output );
		$this->assertStringContainsString( 'The failure was: ArchiveReader: manifest does not fit', $output );
		$this->assertStringContainsString( 'Full details are in the Pontifex log', $output );
	}

	/**
	 * The verdict carries no absolute server paths.
	 *
	 * This output is exactly what an operator pastes into a support thread or a bug
	 * report, and the engine's own messages routinely name absolute paths — so the
	 * message is redacted as well as the archive path.
	 *
	 * @return void
	 */
	public function test_invoke_redacts_absolute_paths_from_the_verdict(): void {
		$temp_dir = rtrim( sys_get_temp_dir(), '/' );

		$printed = array();
		$wp_cli  = $this->mock_wp_cli_capturing_output( $printed );
		$wp_cli->shouldReceive( 'halt' )->once()->with( 1 );

		$this->run_failing_dry_run( new ArchiveNotTrustworthy( sprintf( 'refusing symlink target %s/escape', $temp_dir ) ) );

		$output = implode( "\n", $printed );
		$this->assertStringNotContainsString( $temp_dir, $output, 'Neither the message nor the archive path may leak an absolute path.' );
		$this->assertStringContainsString( '{TMP}/escape', $output, 'The redacted message still names the file, just not where it lives.' );
	}

	/**
	 * The operation lock is released before the command halts.
	 *
	 * WP_CLI::halt() calls exit(), and PHP does not run a finally block when exit() is
	 * called — so halting inside the catch would skip the release in the finally and
	 * leave the site's lock to the shutdown backstop. That backstop exists for a
	 * mid-work fatal, not as the normal path off a handled failure.
	 *
	 * @return void
	 */
	public function test_invoke_releases_the_lock_before_halting(): void {
		$lock_context = Mockery::mock( WordPressContext::class );
		$lock_context->shouldReceive( 'acquire_named_lock' )->once()->andReturn( true );
		$lock_context->shouldReceive( 'release_named_lock' )->once();
		$lock = new OperationLock( $lock_context, new JobStore( sys_get_temp_dir() . '/pontifex-import-lock-test-' . uniqid( '', true ) ) );

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'restore' )->once()->andThrow( new RuntimeException( 'simulated restore failure' ) );

		$held_at_halt = null;
		$wp_cli       = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'warning' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'halt' )->once()->with( 1 )->andReturnUsing(
			static function () use ( $lock, &$held_at_halt ): void {
				$held_at_halt = $lock->is_held();
			}
		);

		$command = new ImportCommand(
			$this->build_environment_mock(),
			$this->build_wordpress_context_mock(),
			$restore_runner,
			new NullLogger(),
			new NullProgressBar(),
			$this->build_safety_archiver_succeeding(),
			null,
			null,
			$lock
		);

		$command( array( $this->temp_archive_path ), array( 'yes' => true ) );

		$this->assertFalse( $held_at_halt, 'The lock must already be released by the time the command halts.' );
	}

	// -----------------------------------------------------------------
	// The repair report: an archive written by an affected version is repaired as
	// it replays, and the operator is told — but only when something was repaired.
	// -----------------------------------------------------------------

	/**
	 * A restore that repaired frozen placeholder markers says so, with the count, in the terminal.
	 *
	 * The restore engine here is a REAL {@see RestoreRunner} over a repairing
	 * {@see DatabaseWriter} and a {@see FakeDbAdapter}, because the fact under test is
	 * the writer's own tally reaching the terminal through the runner — which a
	 * {@see RestoreRunnerInterface} mock has no writer to produce. The import's
	 * source archive carries one row whose two percent signs were frozen into
	 * markers. The exact wording is asserted, and so is the database side: the
	 * statement that reached the adapter has the percent signs back.
	 *
	 * @return void
	 */
	public function test_invoke_reports_how_many_markers_a_restore_repaired(): void {
		$m = self::frozen_marker();
		self::write_archive_to(
			$this->temp_archive_path,
			null,
			Scope::content_only( array() ),
			'wp_',
			array( self::db_chunk_plan( 'wp_options', 1, "INSERT INTO `wp_options` VALUES ('/{$m}postname{$m}/');\n" ) )
		);

		$db           = new FakeDbAdapter();
		$restore_root = sys_get_temp_dir() . '/pontifex-import-invoke-repair-' . bin2hex( random_bytes( 8 ) );
		$printed      = array();
		$this->mock_wp_cli_capturing_output( $printed );

		try {
			$command = new ImportCommand(
				$this->build_environment_mock(),
				$this->build_wordpress_context_mock(),
				self::build_real_restore_runner( $db, $restore_root ),
				new NullLogger(),
				new NullProgressBar(),
				$this->build_safety_archiver_succeeding()
			);

			$command( array( $this->temp_archive_path ), array( 'yes' => true ) );
		} finally {
			self::rmtree( $restore_root );
		}

		$this->assertContains( "INSERT INTO `pontifexstg_wp_options` VALUES ('/%postname%/')", $db->executed_statements(), 'The repaired statement is what reached the database.' );
		$this->assertContains(
			'Repaired 2 percent signs that an older version of Pontifex had written as a long marker when this backup was taken.',
			$printed,
			'The operator must be told, with the count, that the restore repaired the archive.'
		);
	}

	/**
	 * A clean archive restores without a word about repairs — the message must not appear on every ordinary restore.
	 *
	 * The engine is the same real runner with the repair switched on, so the only
	 * thing that can keep the line off the terminal is the zero count.
	 *
	 * @return void
	 */
	public function test_invoke_prints_nothing_about_repairs_for_a_clean_archive(): void {
		self::write_archive_to(
			$this->temp_archive_path,
			null,
			Scope::content_only( array() ),
			'wp_',
			array( self::db_chunk_plan( 'wp_options', 1, "INSERT INTO `wp_options` VALUES ('/%postname%/');\n" ) )
		);

		$db           = new FakeDbAdapter();
		$restore_root = sys_get_temp_dir() . '/pontifex-import-invoke-clean-' . bin2hex( random_bytes( 8 ) );
		$printed      = array();
		$this->mock_wp_cli_capturing_output( $printed );

		try {
			$command = new ImportCommand(
				$this->build_environment_mock(),
				$this->build_wordpress_context_mock(),
				self::build_real_restore_runner( $db, $restore_root ),
				new NullLogger(),
				new NullProgressBar(),
				$this->build_safety_archiver_succeeding()
			);

			$command( array( $this->temp_archive_path ), array( 'yes' => true ) );
		} finally {
			self::rmtree( $restore_root );
		}

		$this->assertContains( "INSERT INTO `pontifexstg_wp_options` VALUES ('/%postname%/')", $db->executed_statements(), 'The restore really ran.' );
		$this->assertNull( self::index_of_line_containing( $printed, 'Repaired' ), 'A restore that repaired nothing must print nothing about repairs.' );
		$this->assertNull( self::index_of_line_containing( $printed, 'marker' ), 'No mention of markers either.' );
	}

	/**
	 * The command's own default wiring repairs an archive written by an affected version.
	 *
	 * Every test above injects a runner, which bypasses build_default_restore_runner()
	 * and so proves nothing about the repair flag that method derives from the
	 * archive's own provenance. This one injects none: an archive recording
	 * Pontifex 1.0.3 as its writer, carrying a marker-bearing row, is imported
	 * through the real wiring, and the statement the database received has the
	 * percent signs back, with the operator told so.
	 *
	 * @return void
	 */
	public function test_default_wiring_repairs_an_archive_written_by_an_affected_version(): void {
		$queries = array();
		$printed = array();

		$this->run_through_default_wiring( '1.0.3', $queries, $printed );

		$this->assertContains( "INSERT INTO `pontifexstg_wp_options` VALUES ('/%postname%/')", $queries, 'An affected writer\'s archive must be repaired by the default wiring.' );
		$this->assertContains(
			'Repaired 2 percent signs that an older version of Pontifex had written as a long marker when this backup was taken.',
			$printed
		);
	}

	/**
	 * The command's own default wiring leaves an archive written after the fix exactly as it came.
	 *
	 * The same archive, recording a version after the boundary: the marker-shaped
	 * text is real data there and must reach the database untouched, with nothing
	 * said about repairs.
	 *
	 * @return void
	 */
	public function test_default_wiring_leaves_an_archive_written_after_the_fix_untouched(): void {
		$queries = array();
		$printed = array();
		$m       = self::frozen_marker();

		$this->run_through_default_wiring( '1.1.1', $queries, $printed );

		$this->assertContains( "INSERT INTO `pontifexstg_wp_options` VALUES ('/{$m}postname{$m}/')", $queries, 'A current archive must reach the database exactly as written.' );
		$this->assertNull( self::index_of_line_containing( $printed, 'Repaired' ) );
	}

	/**
	 * A runner that is not the real engine reports nothing — it has no writer to ask.
	 *
	 * @return void
	 */
	public function test_invoke_prints_nothing_about_repairs_when_the_engine_is_a_fake(): void {
		$printed = array();
		$this->mock_wp_cli_capturing_output( $printed );

		$command = new ImportCommand(
			$this->build_environment_mock(),
			$this->build_wordpress_context_mock(),
			$this->build_restore_runner_mock_succeeding(),
			new NullLogger(),
			new NullProgressBar(),
			$this->build_safety_archiver_succeeding()
		);

		$command( array( $this->temp_archive_path ), array( 'yes' => true ) );

		$this->assertNull( self::index_of_line_containing( $printed, 'Repaired' ) );
	}

	/**
	 * A frozen placeholder marker, as an affected export wrote it into an archive.
	 *
	 * @return string A brace, 64 lowercase hexadecimal characters, a brace.
	 */
	private static function frozen_marker(): string {
		return '{' . str_repeat( '0123456789abcdef', 4 ) . '}';
	}

	/**
	 * Build a db_chunk EntryPlan.
	 *
	 * @param string $table_name      Source table name.
	 * @param int    $statement_count Number of statements in the chunk.
	 * @param string $sql             SQL bytes, each statement terminated with a semicolon and a newline.
	 * @return EntryPlan
	 */
	private static function db_chunk_plan( string $table_name, int $statement_count, string $sql ): EntryPlan {
		$header = EntryHeader::for_db_chunk( 0, $table_name, $statement_count, strlen( $sql ), 0 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://memory is an in-process buffer, not a file.
		$stream = fopen( 'php://memory', 'r+b' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Operating on a test stream resource, not a filesystem path.
		fwrite( $stream, $sql );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Operating on a test stream resource, not a filesystem path.
		rewind( $stream );
		return new EntryPlan( $header, RawCodec::ID, str_repeat( "\0", EntryWriter::NONCE_SIZE ), $stream );
	}

	/**
	 * Build a REAL RestoreRunner whose database writer repairs frozen markers, over a fake database.
	 *
	 * @param FakeDbAdapter $db           The adapter the writer replays into.
	 * @param string        $restore_root The directory the file writer is rooted at; created on demand.
	 * @return RestoreRunner
	 */
	private static function build_real_restore_runner( FakeDbAdapter $db, string $restore_root ): RestoreRunner {
		return new RestoreRunner(
			new EntryReader( CodecRegistry::with_defaults() ),
			new FileWriter( $restore_root, false, 'wp-content' ),
			new DatabaseWriter( $db, '', '', true )
		);
	}

	/**
	 * Import a marker-bearing archive through the command's OWN default wiring, recording what the database received.
	 *
	 * No runner is injected, so build_default_restore_runner() builds the real one,
	 * over a wpdb whose query() records what it is given. The WordPress root is a
	 * throw-away directory, removed afterwards.
	 *
	 * @param string             $exporter_version The Pontifex version the archive records as its writer.
	 * @param array<int, string> $queries          Out-parameter. Filled with every statement the wpdb was asked to run, in order.
	 * @param array<int, string> $printed          Out-parameter. Filled with every line the command printed, in order.
	 * @return void
	 */
	private function run_through_default_wiring( string $exporter_version, array &$queries, array &$printed ): void {
		$m = self::frozen_marker();
		self::write_archive_to(
			$this->temp_archive_path,
			null,
			Scope::content_only( array() ),
			'wp_',
			array( self::db_chunk_plan( 'wp_options', 1, "INSERT INTO `wp_options` VALUES ('/{$m}postname{$m}/');\n" ) ),
			$exporter_version
		);

		$root        = sys_get_temp_dir() . '/pontifex-import-wiring-' . bin2hex( random_bytes( 8 ) );
		$environment = Mockery::mock( Environment::class );
		$environment->shouldReceive( 'is_constant_defined' )->andReturnUsing(
			static function ( string $name ): bool {
				return 'PONTIFEX_PUBLIC_KEY' !== $name;
			}
		);
		$environment->shouldReceive( 'constant_value' )->andReturnUsing(
			static function ( string $name ) use ( $root ) {
				return 'ABSPATH' === $name ? $root . '/' : $root . '/wp-content';
			}
		);
		$environment->shouldReceive( 'ini_get' )->andReturn( '256M' );

		$wpdb = Mockery::mock( wpdb::class )->makePartial();
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			static function ( string $query ) use ( &$queries ) {
				$queries[] = $query;
				return 0;
			}
		);

		$context = $this->build_wordpress_context_mock();
		$context->shouldReceive( 'wpdb_prefix' )->andReturn( 'wp_' );
		$context->shouldReceive( 'wpdb_instance' )->andReturn( $wpdb );
		$context->shouldReceive( 'convert_hr_to_bytes' )->andReturn( 0 );

		$this->mock_wp_cli_capturing_output( $printed );

		try {
			$command = new ImportCommand(
				$environment,
				$context,
				null,
				new NullLogger(),
				new NullProgressBar(),
				$this->build_safety_archiver_succeeding()
			);
			$command( array( $this->temp_archive_path ), array( 'yes' => true ) );
		} finally {
			self::rmtree( $root );
		}
	}

	/**
	 * Recursively delete a directory tree, if it exists.
	 *
	 * @param string $path Absolute path to remove.
	 * @return void
	 */
	private static function rmtree( string $path ): void {
		if ( is_link( $path ) || ! is_dir( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Test fixture teardown.
			@unlink( $path );
			return;
		}
		foreach ( scandir( $path ) as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				self::rmtree( $path . '/' . $entry );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.PHP.NoSilencedErrors.Discouraged -- Test fixture teardown.
		@rmdir( $path );
	}

	/**
	 * Run a --dry-run whose verify walk raises the given failure.
	 *
	 * A dry run is the shortest path to the failure handler: it takes no lock and no
	 * safety archive, so the verdict is the only thing under test. The safety archiver
	 * is asserted untouched to keep that true.
	 *
	 * @param \Throwable $failure The failure the verify walk raises.
	 * @return void
	 */
	private function run_failing_dry_run( \Throwable $failure ): void {
		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'verify' )->once()->andThrow( $failure );
		$restore_runner->shouldNotReceive( 'restore' );

		$safety_archiver = Mockery::mock( SafetyArchiverInterface::class );
		$safety_archiver->shouldNotReceive( 'create' );

		$command = new ImportCommand(
			$this->build_environment_mock(),
			$this->build_wordpress_context_mock(),
			$restore_runner,
			new NullLogger(),
			new NullProgressBar(),
			$safety_archiver
		);

		$command(
			array( $this->temp_archive_path ),
			array(
				'yes'     => true,
				'dry-run' => true,
			)
		);
	}

	/**
	 * Alias-mock WP_CLI, collecting every log and warning line in the order printed.
	 *
	 * @param array<int, string> $printed Filled with each message as it is printed.
	 * @return \Mockery\MockInterface
	 */
	private function mock_wp_cli_capturing_output( array &$printed ) {
		$collect = static function ( string $message ) use ( &$printed ): void {
			$printed[] = $message;
		};

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes()->andReturnUsing( $collect );
		$wp_cli->shouldReceive( 'warning' )->zeroOrMoreTimes()->andReturnUsing( $collect );
		return $wp_cli;
	}

	/**
	 * Find the position of the first captured line containing the given text.
	 *
	 * @param array<int, string> $printed The captured output, in order.
	 * @param string             $needle  The text to look for.
	 * @return int|null The line's position, or null when no line contains it.
	 */
	private static function index_of_line_containing( array $printed, string $needle ): ?int {
		foreach ( array_values( $printed ) as $index => $line ) {
			if ( str_contains( $line, $needle ) ) {
				return $index;
			}
		}
		return null;
	}

	/**
	 * Build an Environment mock that answers the ABSPATH lookup.
	 *
	 * The take_safety_archive step resolves the WordPress root through ABSPATH to
	 * feed the archiver; the restore path never reaches the Environment because a
	 * runner is injected.
	 *
	 * @return Environment&\Mockery\MockInterface
	 */
	private function build_environment_mock() {
		$mock = Mockery::mock( Environment::class );
		$mock->shouldReceive( 'is_constant_defined' )->with( 'ABSPATH' )->andReturn( true );
		$mock->shouldReceive( 'constant_value' )->with( 'ABSPATH' )->andReturn( '/var/www/html' );
		$mock->shouldReceive( 'is_constant_defined' )->with( 'WP_CONTENT_DIR' )->andReturn( true );
		$mock->shouldReceive( 'constant_value' )->with( 'WP_CONTENT_DIR' )->andReturn( '/var/www/html/wp-content' );
		// No pinned key by default; pin-specific tests build their own mock.
		$mock->shouldReceive( 'is_constant_defined' )->with( 'PONTIFEX_PUBLIC_KEY' )->andReturn( false );
		return $mock;
	}

	/**
	 * Build an Environment mock whose PONTIFEX_PUBLIC_KEY pin names the given key file.
	 *
	 * @param string $public_key_path Absolute path of the pinned public-key file.
	 * @return Environment&\Mockery\MockInterface
	 */
	private function build_environment_mock_with_pin( string $public_key_path ) {
		$mock = Mockery::mock( Environment::class );
		$mock->shouldReceive( 'is_constant_defined' )->with( 'ABSPATH' )->andReturn( true );
		$mock->shouldReceive( 'constant_value' )->with( 'ABSPATH' )->andReturn( '/var/www/html' );
		$mock->shouldReceive( 'is_constant_defined' )->with( 'WP_CONTENT_DIR' )->andReturn( true );
		$mock->shouldReceive( 'constant_value' )->with( 'WP_CONTENT_DIR' )->andReturn( '/var/www/html/wp-content' );
		$mock->shouldReceive( 'is_constant_defined' )->with( 'PONTIFEX_PUBLIC_KEY' )->andReturn( true );
		$mock->shouldReceive( 'constant_value' )->with( 'PONTIFEX_PUBLIC_KEY' )->andReturn( $public_key_path );
		return $mock;
	}

	/**
	 * Perform the signature-strip attack on an archive file in place.
	 *
	 * Clears the header's signed flag and truncates the trailing signature
	 * block — after which the archive is a well-formed UNSIGNED one, because
	 * none of the unkeyed hashes covers the header flags. This is the exact
	 * downgrade ADR 0012 closes.
	 *
	 * @param string $path Absolute path of the signed archive to strip.
	 * @return void
	 */
	private static function strip_signature( string $path ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test fixture manipulation of a temp archive.
		$bytes = (string) file_get_contents( $path );
		$flags = ByteOrder::unpack_uint32( substr( $bytes, 12, 4 ) );
		$bytes = substr_replace( $bytes, ByteOrder::pack_uint32( $flags & ~Header::FLAG_SIGNED ), 12, 4 );
		$bytes = substr( $bytes, 0, -ArchiveSignature::SIZE );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture manipulation of a temp archive.
		file_put_contents( $path, $bytes );
	}

	/**
	 * Build a WordPressContext mock for the real-run path.
	 *
	 * @return WordPressContext&\Mockery\MockInterface
	 */
	private function build_wordpress_context_mock() {
		$mock = Mockery::mock( WordPressContext::class );
		$mock->shouldReceive( 'option_value' )->andReturn( array() );
		$mock->shouldReceive( 'save_option' )->zeroOrMoreTimes();
		$mock->shouldReceive( 'flush_cache' )->zeroOrMoreTimes();
		$mock->shouldReceive( 'format_size' )->andReturn( '0 B' );
		// The shared single-runner lock: free by default so __invoke's new lock
		// acquisition does not need a dedicated stub in every test. The named
		// lock is granted through the context mock above; the holder transient
		// OperationLock reads/writes directly via the global WordPress transient
		// functions, stubbed here to a plain "nothing is running" default.
		$mock->shouldReceive( 'acquire_named_lock' )->andReturn( true );
		$mock->shouldReceive( 'release_named_lock' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		return $mock;
	}

	/**
	 * Build a RestoreRunnerInterface mock whose restore() succeeds silently.
	 *
	 * @return RestoreRunnerInterface&\Mockery\MockInterface
	 */
	private function build_restore_runner_mock_succeeding() {
		$mock = Mockery::mock( RestoreRunnerInterface::class );
		$mock->shouldReceive( 'restore' )->once();
		return $mock;
	}

	/**
	 * Build a SafetyArchiverInterface mock whose create() succeeds, returning a path.
	 *
	 * @return SafetyArchiverInterface&\Mockery\MockInterface
	 */
	private function build_safety_archiver_succeeding() {
		$mock = Mockery::mock( SafetyArchiverInterface::class );
		$mock->shouldReceive( 'create' )->once()->andReturn( '/var/www/html/wp-content/pontifex/rollback/safety.wpmig' );
		return $mock;
	}

	// -----------------------------------------------------------------
	// The durable record: what an import did is written to the transfer
	// history (counts and flags only), for the screens that read it back.
	// -----------------------------------------------------------------

	/**
	 * A successful import records the entries it wrote and, with no relink, says so; the cache is flushed first.
	 *
	 * The replay leaves a stale option cache that can swallow a write into the
	 * just-replaced wp_options, so the flush must come before the record. No
	 * `recovery` key is written: a successful import ran no recovery, and an
	 * absent key already reads as that.
	 *
	 * @return void
	 */
	public function test_invoke_records_what_an_import_did_in_the_history_after_the_flush(): void {
		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'warning' )->zeroOrMoreTimes();

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'restore' )->once()->andReturnUsing(
			static function ( $source, ?callable $on_entry = null ): void {
				unset( $source );
				if ( null !== $on_entry ) {
					$on_entry( 1, 4 );
				}
			}
		);

		$events  = array();
		$history = array();
		$command = new ImportCommand(
			$this->build_environment_mock(),
			$this->build_history_capturing_context( $history, $events ),
			$restore_runner,
			new NullLogger(),
			new NullProgressBar(),
			$this->build_safety_archiver_succeeding()
		);

		$command( array( $this->temp_archive_path ), array( 'yes' => true ) );

		$this->assertSame( array( 'flush', 'history' ), $events, 'The history must be written after the cache flush, never before it.' );
		$this->assertCount( 1, $history );
		$this->assertSame( 'import', $history[0]['operation'] );
		$this->assertSame( 'succeeded', $history[0]['outcome'] );
		$this->assertSame(
			array(
				'entries'          => 4,
				'relinked'         => false,
				'rows_rewritten'   => 0,
				'repaired_markers' => 0,
				'skipped_links'    => 0,
			),
			$history[0]['details']
		);
	}

	/**
	 * With --new-url the record says a rewrite ran, and how many rows it changed.
	 *
	 * @return void
	 */
	public function test_invoke_records_the_rows_a_new_url_rewrite_changed(): void {
		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'warning' )->zeroOrMoreTimes();

		$url_migrator = Mockery::mock( UrlMigratorInterface::class );
		$url_migrator->shouldReceive( 'source_url' )->andReturn( 'https://old.test' );
		$url_migrator->shouldReceive( 'migrate' )->once()->andReturn( new RewriteReport( 1, array(), 1, 6, 1, 0 ) );

		$events  = array();
		$history = array();
		$command = new ImportCommand(
			$this->build_environment_mock(),
			$this->build_history_capturing_context( $history, $events ),
			$this->build_restore_runner_mock_succeeding(),
			new NullLogger(),
			new NullProgressBar(),
			$this->build_safety_archiver_succeeding(),
			$url_migrator
		);

		$command(
			array( $this->temp_archive_path ),
			array(
				'yes'     => true,
				'new-url' => 'https://new.example',
			)
		);

		$this->assertCount( 1, $history );
		$this->assertTrue( $history[0]['details']['relinked'] );
		$this->assertSame( 6, $history[0]['details']['rows_rewritten'] );
	}

	/**
	 * A restore that repaired frozen markers records how many, read from the real engine's own tally.
	 *
	 * @return void
	 */
	public function test_invoke_records_how_many_markers_the_restore_repaired(): void {
		$m = self::frozen_marker();
		self::write_archive_to(
			$this->temp_archive_path,
			null,
			Scope::content_only( array() ),
			'wp_',
			array( self::db_chunk_plan( 'wp_options', 1, "INSERT INTO `wp_options` VALUES ('/{$m}postname{$m}/');\n" ) )
		);

		$db           = new FakeDbAdapter();
		$restore_root = sys_get_temp_dir() . '/pontifex-import-invoke-history-' . bin2hex( random_bytes( 8 ) );
		$printed      = array();
		$events       = array();
		$history      = array();
		$this->mock_wp_cli_capturing_output( $printed );

		try {
			$command = new ImportCommand(
				$this->build_environment_mock(),
				$this->build_history_capturing_context( $history, $events ),
				self::build_real_restore_runner( $db, $restore_root ),
				new NullLogger(),
				new NullProgressBar(),
				$this->build_safety_archiver_succeeding()
			);

			$command( array( $this->temp_archive_path ), array( 'yes' => true ) );
		} finally {
			self::rmtree( $restore_root );
		}

		$this->assertCount( 1, $history );
		$this->assertSame( 2, $history[0]['details']['repaired_markers'] );
		$this->assertSame( 1, $history[0]['details']['entries'] );
	}

	/**
	 * A failed import records the failure AFTER its recovery, flushed first, with the recovery's verdict.
	 *
	 * Recovery replays the safety archive, which replaces wp_options again, so a counter
	 * or record written before it is wiped; this command used to lose both whenever a
	 * recovery succeeded. The order of events is the claim under test: the forward
	 * replay fails, the recovery replay runs, and only then the cache is flushed and the
	 * history written. The runner is a double, so the recovery honestly reports a merge.
	 *
	 * @return void
	 */
	public function test_invoke_records_a_failed_import_after_the_recovery_with_its_verdict(): void {
		$safety_path = sys_get_temp_dir() . '/pontifex-safety-' . uniqid( '', true ) . '.wpmig';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Seeding a placeholder safety archive; the injected engine stands in for reading it.
		file_put_contents( $safety_path, 'x' );

		$safety_archiver = Mockery::mock( SafetyArchiverInterface::class );
		$safety_archiver->shouldReceive( 'create' )->once()->andReturn( $safety_path );

		$events  = array();
		$history = array();
		$replays = 0;

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'verify' )->once();
		$restore_runner->shouldReceive( 'restore' )->twice()->andReturnUsing(
			static function () use ( &$replays, &$events ): void {
				++$replays;
				$events[] = 1 === $replays ? 'forward-replay' : 'recovery-replay';
				if ( 1 === $replays ) {
					throw new ArchiveNotTrustworthy( 'simulated restore failure' );
				}
			}
		);

		$printed = array();
		$wp_cli  = $this->mock_wp_cli_capturing_output( $printed );
		$wp_cli->shouldReceive( 'halt' )->once()->with( 1 );

		$command = new ImportCommand(
			$this->build_environment_mock(),
			$this->build_history_capturing_context( $history, $events ),
			$restore_runner,
			new NullLogger(),
			new NullProgressBar(),
			$safety_archiver
		);

		try {
			$command( array( $this->temp_archive_path ), array( 'yes' => true ) );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Test cleanup of the placeholder safety archive.
			@unlink( $safety_path );
		}

		$this->assertSame(
			array( 'forward-replay', 'recovery-replay', 'flush', 'history' ),
			$events,
			'The failure record must be written after the recovery has finished, and only after the cache flush.'
		);
		$this->assertCount( 1, $history );
		$this->assertSame( 'import', $history[0]['operation'] );
		$this->assertSame( 'failed', $history[0]['outcome'] );
		$this->assertSame( 0, $history[0]['bytes'] );
		$this->assertSame(
			array(
				'entries'          => 0,
				'relinked'         => false,
				'rows_rewritten'   => 0,
				'repaired_markers' => 0,
				'skipped_links'    => 0,
				'recovery'         => RestoreOutcome::RECOVERY_MERGE,
			),
			$history[0]['details']
		);
	}

	/**
	 * A failed import whose recovery skipped a symbolic link records the link count and the links-only verdict.
	 *
	 * One REAL runner serves both the failing forward replay and the recovery (the
	 * command reuses an injected runner for both), with the recovery archive carrying an
	 * absolute-target link. The forward archive's database write is made to fail once, so
	 * the recovery replay that follows is the one that skips the link.
	 *
	 * @return void
	 */
	public function test_invoke_records_the_skipped_links_and_the_links_verdict_of_a_recovery(): void {
		$m = self::frozen_marker();
		self::write_archive_to(
			$this->temp_archive_path,
			null,
			Scope::content_only( array() ),
			'wp_',
			array( self::db_chunk_plan( 'wp_options', 1, "INSERT INTO `wp_options` VALUES ('/{$m}postname{$m}/');\n" ) )
		);

		$safety_path = sys_get_temp_dir() . '/pontifex-safety-' . uniqid( '', true ) . '.wpmig';
		self::write_archive_to(
			$safety_path,
			null,
			Scope::content_only( array() ),
			'wp_',
			array( self::symlink_plan( 'wp-content/uploads/media', '/mnt/shared/media' ) )
		);

		$safety_archiver = Mockery::mock( SafetyArchiverInterface::class );
		$safety_archiver->shouldReceive( 'create' )->once()->andReturn( $safety_path );

		$db = new FakeDbAdapter();
		$db->fail_next_execute( 'simulated database failure' );
		$restore_root = sys_get_temp_dir() . '/pontifex-import-invoke-links-' . bin2hex( random_bytes( 8 ) );

		$printed = array();
		$wp_cli  = $this->mock_wp_cli_capturing_output( $printed );
		$wp_cli->shouldReceive( 'halt' )->once()->with( 1 );

		$events  = array();
		$history = array();

		$runner = new RestoreRunner(
			new EntryReader( CodecRegistry::with_defaults() ),
			new FileWriter( $restore_root, false, null, null, null, null, true ),
			new DatabaseWriter( $db )
		);

		try {
			$command = new ImportCommand(
				$this->build_environment_mock(),
				$this->build_history_capturing_context( $history, $events ),
				$runner,
				new NullLogger(),
				new NullProgressBar(),
				$safety_archiver
			);

			$command( array( $this->temp_archive_path ), array( 'yes' => true ) );
		} finally {
			self::rmtree( $restore_root );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Test cleanup of the safety archive written above.
			@unlink( $safety_path );
		}

		$this->assertCount( 1, $history );
		$this->assertSame( 'failed', $history[0]['outcome'] );
		$this->assertSame( 1, $history[0]['details']['skipped_links'], 'The links the recovery replay skipped are counted.' );
		$this->assertSame( RestoreOutcome::RECOVERY_MERGE_LINKS, $history[0]['details']['recovery'] );
	}

	/**
	 * A failed import with no safety archive ran no recovery, records none, and still flushes before it records.
	 *
	 * @return void
	 */
	public function test_invoke_records_no_recovery_when_no_safety_archive_was_taken(): void {
		$safety_archiver = Mockery::mock( SafetyArchiverInterface::class );
		$safety_archiver->shouldNotReceive( 'create' );

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'restore' )->once()->andThrow( new RuntimeException( 'simulated restore failure' ) );

		$printed = array();
		$wp_cli  = $this->mock_wp_cli_capturing_output( $printed );
		$wp_cli->shouldReceive( 'halt' )->once()->with( 1 );

		$events  = array();
		$history = array();

		$command = new ImportCommand(
			$this->build_environment_mock(),
			$this->build_history_capturing_context( $history, $events ),
			$restore_runner,
			new NullLogger(),
			new NullProgressBar(),
			$safety_archiver
		);

		$command(
			array( $this->temp_archive_path ),
			array(
				'yes'              => true,
				'rollback-archive' => false,
			)
		);

		$this->assertSame( array( 'flush', 'history' ), $events );
		$this->assertCount( 1, $history );
		$this->assertSame( 'failed', $history[0]['outcome'] );
		$this->assertSame( 0, $history[0]['details']['skipped_links'] );
		$this->assertSame( RestoreOutcome::RECOVERY_NONE, $history[0]['details']['recovery'] );
	}

	/**
	 * A recovery that cannot even open its safety archive is recorded as a failed recovery.
	 *
	 * @return void
	 */
	public function test_invoke_records_a_failed_recovery(): void {
		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'restore' )->once()->andThrow( new RuntimeException( 'simulated restore failure' ) );

		$printed = array();
		$wp_cli  = $this->mock_wp_cli_capturing_output( $printed );
		$wp_cli->shouldReceive( 'halt' )->once()->with( 1 );

		$events  = array();
		$history = array();

		// The default safety archiver double reports a path that does not exist on disk.
		$command = new ImportCommand(
			$this->build_environment_mock(),
			$this->build_history_capturing_context( $history, $events ),
			$restore_runner,
			new NullLogger(),
			new NullProgressBar(),
			$this->build_safety_archiver_succeeding()
		);

		$command( array( $this->temp_archive_path ), array( 'yes' => true ) );

		$this->assertSame( array( 'flush', 'history' ), $events );
		$this->assertCount( 1, $history );
		$this->assertSame( RestoreOutcome::RECOVERY_FAILED, $history[0]['details']['recovery'] );
	}

	/**
	 * A failing dry run changed nothing, so it flushes nothing and records nothing.
	 *
	 * @return void
	 */
	public function test_invoke_records_nothing_for_a_failing_dry_run(): void {
		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'verify' )->once()->andThrow( new ArchiveNotTrustworthy( 'would be refused' ) );
		$restore_runner->shouldNotReceive( 'restore' );

		$safety_archiver = Mockery::mock( SafetyArchiverInterface::class );
		$safety_archiver->shouldNotReceive( 'create' );

		$printed = array();
		$wp_cli  = $this->mock_wp_cli_capturing_output( $printed );
		$wp_cli->shouldReceive( 'halt' )->once()->with( 1 );

		$events  = array();
		$history = array();

		$command = new ImportCommand(
			$this->build_environment_mock(),
			$this->build_history_capturing_context( $history, $events ),
			$restore_runner,
			new NullLogger(),
			new NullProgressBar(),
			$safety_archiver
		);

		$command(
			array( $this->temp_archive_path ),
			array(
				'yes'     => true,
				'dry-run' => true,
			)
		);

		$this->assertSame( array(), $events );
		$this->assertSame( array(), $history );
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
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://memory is an in-process buffer, not a file.
		$stream = fopen( 'php://memory', 'r+b' );
		return new EntryPlan( $header, RawCodec::ID, str_repeat( "\0", EntryWriter::NONCE_SIZE ), $stream );
	}

	/**
	 * A WordPressContext mock that records, in order, the cache flushes and the transfer-history writes.
	 *
	 * Only a write to the history option is captured; the counters' writes are
	 * accepted and ignored.
	 *
	 * @param array<int, array<string, mixed>> $history Out-parameter. Receives the history as it was last saved.
	 * @param array<int, string>               $events  Out-parameter. Receives 'flush' for each cache flush and 'history' for each history write, in the order they happen, beside any other events the test appends.
	 * @return WordPressContext&\Mockery\MockInterface
	 */
	private function build_history_capturing_context( array &$history, array &$events ) {
		$mock = Mockery::mock( WordPressContext::class );
		$mock->shouldReceive( 'option_value' )->andReturnUsing(
			static function ( string $name, $fallback = false ) {
				unset( $name );
				return $fallback;
			}
		);
		$mock->shouldReceive( 'flush_cache' )->andReturnUsing(
			static function () use ( &$events ): void {
				$events[] = 'flush';
			}
		);
		$mock->shouldReceive( 'save_option' )->andReturnUsing(
			static function ( string $name, $value ) use ( &$history, &$events ): void {
				if ( TransferHistory::OPTION === $name ) {
					$events[] = 'history';
					$history  = $value;
				}
			}
		);
		$mock->shouldReceive( 'format_size' )->andReturn( '0 B' );
		$mock->shouldReceive( 'acquire_named_lock' )->andReturn( true );
		$mock->shouldReceive( 'release_named_lock' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		return $mock;
	}

	/**
	 * A restore that repaired exactly one marker says "percent sign", not "percent signs".
	 *
	 * @return void
	 */
	public function test_invoke_uses_the_singular_when_a_restore_repaired_one_marker(): void {
		$m = self::frozen_marker();
		self::write_archive_to(
			$this->temp_archive_path,
			null,
			Scope::content_only( array() ),
			'wp_',
			array( self::db_chunk_plan( 'wp_options', 1, "INSERT INTO `wp_options` VALUES ('/{$m}postname');\n" ) )
		);

		$db           = new FakeDbAdapter();
		$restore_root = sys_get_temp_dir() . '/pontifex-import-invoke-singular-' . bin2hex( random_bytes( 8 ) );
		$printed      = array();
		$this->mock_wp_cli_capturing_output( $printed );

		try {
			$command = new ImportCommand(
				$this->build_environment_mock(),
				$this->build_wordpress_context_mock(),
				self::build_real_restore_runner( $db, $restore_root ),
				new NullLogger(),
				new NullProgressBar(),
				$this->build_safety_archiver_succeeding()
			);

			$command( array( $this->temp_archive_path ), array( 'yes' => true ) );
		} finally {
			self::rmtree( $restore_root );
		}

		$this->assertContains(
			'Repaired 1 percent sign that an older version of Pontifex had written as a long marker when this backup was taken.',
			$printed
		);
	}

	// -----------------------------------------------------------------
	// The attempt counter: counted before the work so a killed run still
	// shows, and never left below succeeded plus failed by a replay that
	// rebuilds wp_options. These tests drive a context that holds options
	// like the real one, so a "replay" in the engine double really does
	// replace them.
	// -----------------------------------------------------------------

	/**
	 * A successful import counts its attempt before the replay runs, and still has it counted after the replay wipes it.
	 *
	 * The early count is what makes a run killed mid-restore show as attempted. The
	 * replay then rebuilds wp_options with the backup's own counters, which cannot
	 * contain this run. The invariant asserted is the one `wp pontifex stats` and the
	 * Overview rely on: attempted is never below succeeded plus failed.
	 *
	 * @return void
	 */
	public function test_a_successful_import_counts_the_attempt_a_replay_wipes(): void {
		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'warning' )->zeroOrMoreTimes();

		$options   = array( 'pontifex_import_stats' => self::import_stats( 2, 1, 1 ) );
		$at_replay = null;

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'restore' )->once()->andReturnUsing(
			static function () use ( &$options, &$at_replay ): void {
				$at_replay                        = $options['pontifex_import_stats']['attempted'];
				$options['pontifex_import_stats'] = self::import_stats( 2, 1, 1 );
			}
		);

		$command = new ImportCommand(
			$this->build_environment_mock(),
			$this->build_stateful_counters_context( $options ),
			$restore_runner,
			new NullLogger(),
			new NullProgressBar(),
			$this->build_safety_archiver_succeeding()
		);

		$command( array( $this->temp_archive_path ), array( 'yes' => true ) );

		$this->assertSame( 3, $at_replay, 'The attempt is counted before the replay runs, so a run killed part-way still shows as attempted.' );
		$this->assertAttemptsCovered( $options['pontifex_import_stats'] );
		$this->assertSame( 3, $options['pontifex_import_stats']['attempted'] );
		$this->assertSame( 2, $options['pontifex_import_stats']['succeeded'] );
	}

	/**
	 * A backup carrying no counters at all still leaves a successful import with its one attempt.
	 *
	 * @return void
	 */
	public function test_a_successful_import_over_a_backup_with_no_counters_counts_one_attempt(): void {
		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'warning' )->zeroOrMoreTimes();

		$options = array();

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'restore' )->once()->andReturnUsing(
			static function () use ( &$options ): void {
				$options = array();
			}
		);

		$command = new ImportCommand(
			$this->build_environment_mock(),
			$this->build_stateful_counters_context( $options ),
			$restore_runner,
			new NullLogger(),
			new NullProgressBar(),
			$this->build_safety_archiver_succeeding()
		);

		$command( array( $this->temp_archive_path ), array( 'yes' => true ) );

		$this->assertAttemptsCovered( $options['pontifex_import_stats'] );
		$this->assertSame( 1, $options['pontifex_import_stats']['attempted'] );
		$this->assertSame( 1, $options['pontifex_import_stats']['succeeded'] );
	}

	/**
	 * A failed import with no recovery to follow (no safety archive) still leaves the attempt covered.
	 *
	 * The forward replay cut over, wiping the early count, and nothing restored it.
	 *
	 * @return void
	 */
	public function test_a_failed_import_with_no_recovery_keeps_the_attempt_covered(): void {
		$printed = array();
		$wp_cli  = $this->mock_wp_cli_capturing_output( $printed );
		$wp_cli->shouldReceive( 'halt' )->once()->with( 1 );

		$options = array( 'pontifex_import_stats' => self::import_stats( 2, 1, 1 ) );

		$safety_archiver = Mockery::mock( SafetyArchiverInterface::class );
		$safety_archiver->shouldNotReceive( 'create' );

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'restore' )->once()->andReturnUsing(
			static function () use ( &$options ): void {
				$options['pontifex_import_stats'] = self::import_stats( 2, 1, 1 );
				throw new RuntimeException( 'simulated restore failure' );
			}
		);

		$command = new ImportCommand(
			$this->build_environment_mock(),
			$this->build_stateful_counters_context( $options ),
			$restore_runner,
			new NullLogger(),
			new NullProgressBar(),
			$safety_archiver
		);

		$command(
			array( $this->temp_archive_path ),
			array(
				'yes'              => true,
				'rollback-archive' => false,
			)
		);

		$this->assertAttemptsCovered( $options['pontifex_import_stats'] );
		$this->assertSame( 3, $options['pontifex_import_stats']['attempted'] );
		$this->assertSame( 2, $options['pontifex_import_stats']['failed'] );
	}

	/**
	 * A failed import that was recovered does not count its attempt twice.
	 *
	 * The safety archive was taken after the early count, so replaying it restores
	 * that count. Adding one more attempt here would double-count, which is why the
	 * failure makes sure the attempt is covered rather than adding another.
	 *
	 * @return void
	 */
	public function test_a_failed_import_that_recovered_does_not_count_the_attempt_twice(): void {
		$safety_path = sys_get_temp_dir() . '/pontifex-safety-' . uniqid( '', true ) . '.wpmig';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Seeding a placeholder safety archive; the injected engine stands in for reading it.
		file_put_contents( $safety_path, 'x' );

		$safety_archiver = Mockery::mock( SafetyArchiverInterface::class );
		$safety_archiver->shouldReceive( 'create' )->once()->andReturn( $safety_path );

		$options = array( 'pontifex_import_stats' => self::import_stats( 2, 1, 1 ) );
		$replays = 0;

		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'verify' )->once();
		$restore_runner->shouldReceive( 'restore' )->twice()->andReturnUsing(
			static function () use ( &$options, &$replays ): void {
				++$replays;
				if ( 1 === $replays ) {
					// The forward replay cut over: the backup's own counters, without this run.
					$options['pontifex_import_stats'] = self::import_stats( 2, 1, 1 );
					throw new ArchiveNotTrustworthy( 'simulated restore failure' );
				}
				// The recovery replays the safety archive, taken after the early count, so it holds it.
				$options['pontifex_import_stats'] = self::import_stats( 3, 1, 1 );
			}
		);

		$printed = array();
		$wp_cli  = $this->mock_wp_cli_capturing_output( $printed );
		$wp_cli->shouldReceive( 'halt' )->once()->with( 1 );

		$command = new ImportCommand(
			$this->build_environment_mock(),
			$this->build_stateful_counters_context( $options ),
			$restore_runner,
			new NullLogger(),
			new NullProgressBar(),
			$safety_archiver
		);

		try {
			$command( array( $this->temp_archive_path ), array( 'yes' => true ) );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Test cleanup of the placeholder safety archive.
			@unlink( $safety_path );
		}

		$this->assertAttemptsCovered( $options['pontifex_import_stats'] );
		$this->assertSame( 3, $options['pontifex_import_stats']['attempted'], 'One run, one attempt: not counted again on top of the one the recovery restored.' );
		$this->assertSame( 2, $options['pontifex_import_stats']['failed'] );
	}

	/**
	 * Assert the invariant `wp pontifex stats` and the Overview rely on: attempted is never below succeeded plus failed.
	 *
	 * @param array<string, int> $stats The stored import counters.
	 * @return void
	 */
	private function assertAttemptsCovered( array $stats ): void {
		$this->assertGreaterThanOrEqual(
			$stats['succeeded'] + $stats['failed'],
			$stats['attempted'],
			'attempted must never be below succeeded plus failed.'
		);
	}

	/**
	 * A set of import counters.
	 *
	 * @param int $attempted How many runs were counted as attempted.
	 * @param int $succeeded How many succeeded.
	 * @param int $failed    How many failed.
	 * @return array<string, int>
	 */
	private static function import_stats( int $attempted, int $succeeded, int $failed ): array {
		return array(
			'attempted'      => $attempted,
			'succeeded'      => $succeeded,
			'failed'         => $failed,
			'bytes_imported' => 0,
		);
	}

	/**
	 * A WordPressContext mock that really holds options, so a "replay" in an engine double can replace them.
	 *
	 * @param array<string, mixed> $options Out-parameter. The stored options, by name; read and written through the context.
	 * @return WordPressContext&\Mockery\MockInterface
	 */
	private function build_stateful_counters_context( array &$options ) {
		$mock = Mockery::mock( WordPressContext::class );
		$mock->shouldReceive( 'option_value' )->andReturnUsing(
			static function ( string $name, $fallback = false ) use ( &$options ) {
				return $options[ $name ] ?? $fallback;
			}
		);
		$mock->shouldReceive( 'save_option' )->andReturnUsing(
			static function ( string $name, $value ) use ( &$options ): void {
				$options[ $name ] = $value;
			}
		);
		$mock->shouldReceive( 'flush_cache' );
		$mock->shouldReceive( 'format_size' )->andReturn( '0 B' );
		$mock->shouldReceive( 'acquire_named_lock' )->andReturn( true );
		$mock->shouldReceive( 'release_named_lock' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		return $mock;
	}

	/**
	 * A successful import of an archive with no database counts its one attempt once.
	 *
	 * Nothing is replayed, so wp_options is untouched and the early count is still
	 * standing. Adding another attempt on top would count the run twice.
	 *
	 * @return void
	 */
	public function test_a_successful_import_of_an_archive_with_no_database_does_not_count_the_attempt_twice(): void {
		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'warning' )->zeroOrMoreTimes();

		$options = array( 'pontifex_import_stats' => self::import_stats( 2, 1, 1 ) );

		$command = new ImportCommand(
			$this->build_environment_mock(),
			$this->build_stateful_counters_context( $options ),
			$this->build_restore_runner_mock_succeeding(),
			new NullLogger(),
			new NullProgressBar(),
			$this->build_safety_archiver_succeeding()
		);

		$command( array( $this->temp_archive_path ), array( 'yes' => true ) );

		$this->assertAttemptsCovered( $options['pontifex_import_stats'] );
		$this->assertSame( 3, $options['pontifex_import_stats']['attempted'], 'One run, one attempt: nothing was replayed, so the early count was never lost.' );
		$this->assertSame( 2, $options['pontifex_import_stats']['succeeded'] );
	}
}
