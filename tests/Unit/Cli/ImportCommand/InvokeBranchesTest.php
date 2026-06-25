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
use Pontifex\Archive\Format\ExporterInfo;
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

		$logger = Mockery::mock( LoggerInterface::class );
		$logger->shouldReceive( 'info' )->zeroOrMoreTimes();
		$logger->shouldReceive( 'error' )->once();

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
		return $mock;
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
