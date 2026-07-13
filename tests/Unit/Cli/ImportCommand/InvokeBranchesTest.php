<?php
/**
 * Surgical __invoke branch tests for ImportCommand.
 *
 * @package Pontifex\Tests\Unit\Cli\ImportCommand
 */

declare(strict_types=1);

namespace Pontifex\Tests\Unit\Cli\ImportCommand;

use DateTimeImmutable;
use DateTimeZone;
use Mockery;
use Pontifex\Archive\Codec\CodecRegistry;
use Pontifex\Archive\Crypto\SigningContext;
use Pontifex\Archive\Crypto\SigningKeypair;
use Pontifex\Archive\Format\ArchiveSignature;
use Pontifex\Archive\Format\ByteOrder;
use Pontifex\Archive\Format\ExporterInfo;
use Pontifex\Archive\Format\Header;
use Pontifex\Archive\Format\Provenance;
use Pontifex\Archive\Format\Scope;
use Pontifex\Archive\Writer\ArchiveWriter;
use Pontifex\Archive\Writer\EntryWriter;
use Pontifex\Archive\Writer\FooterWriter;
use Pontifex\Cli\ImportCommand;
use Pontifex\Cli\NullProgressBar;
use Pontifex\Cli\SigningKeys;
use Pontifex\Environment\Environment;
use Pontifex\Migrate\RewriteReport;
use Pontifex\Migrate\UrlMigratorInterface;
use Pontifex\Restore\RestoreRunnerInterface;
use Pontifex\Rollback\SafetyArchiverInterface;
use Pontifex\Tests\TestCase;
use Pontifex\WordPress\WordPressContext;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Behavioural coverage of the genuine __invoke branches.
 *
 * As with ExportCommand, the bulk of orchestration is not worth a behavioural
 * __invoke test. The branches that genuinely earn a surgical unit test are:
 *
 *  1. The --yes short-circuit: confirm() is never called when --yes is set.
 *  2. The try-finally exception path: a restore failure closes the handle and
 *     propagates unswallowed.
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
	 * @return void
	 */
	private static function write_archive_to( string $path, ?SigningContext $signing, ?Scope $scope = null, ?string $table_prefix = null ): void {
		$provenance = new Provenance(
			'6.6.1',
			'8.2.10',
			'https://example.test',
			'utf8mb4',
			'utf8mb4_unicode_520_ci',
			new ExporterInfo( 'pontifex', '0.3.0' ),
			new DateTimeImmutable( '2026-06-23T10:00:00+00:00', new DateTimeZone( 'UTC' ) ),
			null,
			$table_prefix,
			$scope
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Opening a temp source file for the command to read; WP_Filesystem is not bootstrapped in unit tests.
		$destination = fopen( $path, 'w+b' );
		$writer      = new ArchiveWriter( new EntryWriter( CodecRegistry::with_defaults() ), new FooterWriter() );
		$writer->write_archive( $provenance, array(), $destination, null, null, $signing );
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
	 * An exception thrown by RestoreRunner::restore must propagate out of __invoke.
	 *
	 * @return void
	 */
	public function test_invoke_propagates_restore_exception(): void {
		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'restore' )->once()->andThrow( new RuntimeException( 'simulated restore failure' ) );

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'warning' )->zeroOrMoreTimes();

		$command = new ImportCommand(
			$this->build_environment_mock(),
			$this->build_wordpress_context_mock(),
			$restore_runner,
			new NullLogger(),
			new NullProgressBar(),
			$this->build_safety_archiver_succeeding()
		);

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'simulated restore failure' );

		$command( array( $this->temp_archive_path ), array( 'yes' => true ) );
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
	 * A failing import records an error log line and re-throws unchanged.
	 *
	 * @return void
	 */
	public function test_invoke_logs_error_when_restore_fails(): void {
		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'restore' )->once()->andThrow( new RuntimeException( 'simulated restore failure' ) );

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'warning' )->zeroOrMoreTimes();

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

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'simulated restore failure' );

		$command( array( $this->temp_archive_path ), array( 'yes' => true ) );
	}

	/**
	 * A restore that fails mid-replay is automatically rolled back from the safety archive.
	 *
	 * The forward replay throws; the failure handler then opens the safety archive and
	 * replays it (verify then restore) to recover the site, warning the operator that an
	 * automatic rollback occurred. The original import error still propagates so the
	 * command exits non-zero.
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

		// Forward: replay throws. Recovery: verify passes, replay succeeds.
		$replays        = 0;
		$restore_runner = Mockery::mock( RestoreRunnerInterface::class );
		$restore_runner->shouldReceive( 'verify' )->once();
		$restore_runner->shouldReceive( 'restore' )->twice()->andReturnUsing(
			static function () use ( &$replays ): void {
				++$replays;
				if ( 1 === $replays ) {
					throw new RuntimeException( 'simulated restore failure' );
				}
			}
		);

		$rolled_back = false;
		$wp_cli      = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'warning' )->atLeast()->once()->andReturnUsing(
			static function ( string $message ) use ( &$rolled_back ): void {
				if ( str_contains( $message, 'automatically rolled back' ) ) {
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
			$this->fail( 'The import should re-throw the restore failure after recovering.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( 'simulated restore failure', $error->getMessage() );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Test cleanup of the placeholder safety archive.
			@unlink( $safety_path );
		}

		$this->assertTrue( $rolled_back, 'The operator is warned that the site was automatically rolled back.' );
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

		$wp_cli = Mockery::mock( 'alias:WP_CLI' );
		$wp_cli->shouldReceive( 'log' )->zeroOrMoreTimes();
		$wp_cli->shouldReceive( 'warning' )->zeroOrMoreTimes();

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

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'not enough free disk space' );

		$command( array( $this->temp_archive_path ), array( 'yes' => true ) );
	}

	/**
	 * With --url, the command migrates the database after restoring.
	 *
	 * Ordering is asserted: restore() runs before migrate(), so the URL rewrite
	 * only touches data the restore has already put in place.
	 *
	 * @return void
	 */
	public function test_invoke_with_url_migrates_after_restoring(): void {
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
				'yes' => true,
				'url' => 'https://new.example',
			)
		);

		$this->assertFileExists( $this->temp_archive_path );
	}

	/**
	 * Without --url, the migrator is never consulted.
	 *
	 * @return void
	 */
	public function test_invoke_without_url_never_migrates(): void {
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
	 * A --dry-run with --url reads the source URL to announce the plan but migrates nothing.
	 *
	 * @return void
	 */
	public function test_invoke_dry_run_with_url_announces_but_does_not_migrate(): void {
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
				'url'     => 'https://new.example',
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
		self::write_archive_to( $this->temp_archive_path, null );
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
		self::write_archive_to( $this->temp_archive_path, SigningContext::from_keypair( $keypair ) );
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
		self::write_archive_to( $this->temp_archive_path, SigningContext::from_keypair( $keypair ) );
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
		$mock->shouldReceive( 'format_size' )->andReturn( '0 B' );
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
}
