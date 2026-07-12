<?php
/**
 * Pontifex admin upload controller — receives a foreign backup uploaded in chunks.
 *
 * @package Pontifex\Admin
 */

declare(strict_types=1);

namespace Pontifex\Admin;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use Pontifex\Archive\Reader\ArchiveReader;
use Pontifex\WordPress\WordPressContext;
use Psr\Log\LoggerInterface;

/**
 * Handles the admin-ajax action that uploads a backup from another site.
 *
 * Restoring a backup taken on a different server first needs that backup on this
 * one. A single HTTP POST cannot carry a large archive — PHP caps it at
 * `upload_max_filesize`/`post_max_size` — so the page's script slices the `.wpmig`
 * file into chunks and posts them one at a time to {@see self::chunk()}, which
 * appends each into a `.part` file in the uploads directory. When the last chunk
 * arrives the assembled file is validated as a real Pontifex archive and only then
 * moved into the backups directory, where it joins the Restore screen's list. (A
 * backup copied straight into that directory by other means already appears there;
 * this is the no-shell-access way to do the same thing.)
 *
 * The action is deny-by-default: it requires the {@see Menu::CAPABILITY} capability
 * **and** a valid `pontifex_upload` nonce, is registered only as `wp_ajax_`
 * (logged-in), never `wp_ajax_nopriv_`, and every byte is held at arm's length —
 * the chunk must be a genuine HTTP upload, no larger than the site's upload
 * ceiling; the upload id is an opaque token that cannot escape the uploads
 * directory ({@see BackupStore::append_chunk()}); the client filename is never used
 * as a path; and a file that does not parse as an archive is refused and deleted
 * rather than stored. Nothing is ever overwritten — the stored backup is given a
 * fresh name.
 */
final class UploadController {

	/**
	 * The nonce action shared by the upload endpoint and the page's script.
	 *
	 * @var string
	 */
	public const NONCE_ACTION = 'pontifex_upload';

	/**
	 * Age, in seconds, past which an abandoned part file is swept (24 hours).
	 *
	 * A literal rather than DAY_IN_SECONDS so the class is testable without
	 * WordPress loaded.
	 *
	 * @var int
	 */
	private const STALE_UPLOAD_SECONDS = 86400;

	/**
	 * The WordPressContext abstraction (upload ceiling and size formatting).
	 *
	 * @var WordPressContext
	 */
	private WordPressContext $wordpress_context;

	/**
	 * The store the chunks assemble in and the finished backup is stored in.
	 *
	 * @var BackupStore
	 */
	private BackupStore $store;

	/**
	 * PSR-3 logger; an upload's real failure cause is recorded here.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * Guard confirming a posted chunk is a genuine HTTP upload.
	 *
	 * Wraps PHP's is_uploaded_file() — the defence against a request naming an
	 * arbitrary server path as its "upload". Held behind a seam (defaulting to the
	 * real function) only so unit tests, which have no genuine HTTP upload, can
	 * substitute it; production always uses is_uploaded_file().
	 *
	 * @var Closure(string): bool
	 */
	private Closure $is_uploaded_file;

	/**
	 * Construct the controller around its collaborators.
	 *
	 * @param WordPressContext $wordpress_context Upload-ceiling and size formatting.
	 * @param BackupStore      $store             The uploads/backups directories.
	 * @param LoggerInterface  $logger            Records a failure's real cause.
	 * @param callable|null    $is_uploaded_file  Optional genuine-upload guard; defaults to is_uploaded_file().
	 */
	public function __construct( WordPressContext $wordpress_context, BackupStore $store, LoggerInterface $logger, ?callable $is_uploaded_file = null ) {
		$this->wordpress_context = $wordpress_context;
		$this->store             = $store;
		$this->logger            = $logger;
		$this->is_uploaded_file  = null !== $is_uploaded_file
			? Closure::fromCallable( $is_uploaded_file )
			: Closure::fromCallable( 'is_uploaded_file' );
	}

	/**
	 * Receive one chunk of an uploaded backup, finalising it when the last arrives.
	 *
	 * The `wp_ajax_pontifex_upload_chunk` handler. Refuses without capability and
	 * nonce, validates the chunk and its place in the sequence, appends it, and —
	 * when the assembled size reaches the declared total — validates the result is a
	 * real archive and stores it. Each non-final chunk replies with the bytes
	 * received so far so the client resumes from the server's authoritative view.
	 *
	 * @return void
	 */
	public function chunk(): void {
		if ( ! $this->is_authorised() ) {
			wp_send_json_error( array( 'message' => $this->unauthorised_message() ), 403 );
		}

		$upload_id = $this->request_string( 'upload_id' );
		$offset    = $this->request_int( 'offset' );
		$total     = $this->request_int( 'total' );

		if ( '' === $upload_id || $offset < 0 || $total <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'The upload request was malformed.', 'pontifex' ) ), 400 );
		}

		$chunk = $this->validated_chunk( $total - $offset );
		$first = ( 0 === $offset );

		// The first chunk starts the part file fresh, so it is the moment to sweep any
		// abandoned uploads and to check there is room for the whole archive.
		if ( $first ) {
			$this->prepare_uploads();
			$this->refuse_if_no_room( $total );
		}

		// Sequential integrity: the client must resume exactly where the server is. The
		// check sits outside the store try below, so its resume reply reaches the client
		// as a 409 rather than being mistaken for a server failure.
		$have = $this->store->upload_size( $upload_id );
		if ( $offset !== $have ) {
			wp_send_json_error(
				array(
					'message'         => __( 'The upload fell out of step; it will resume.', 'pontifex' ),
					'expected_offset' => $have,
				),
				409
			);
		}

		$received = $this->append_chunk_to_upload( $upload_id, $chunk['tmp_name'], $first );

		if ( $received < $total ) {
			// More chunks to come: acknowledge the bytes received so the client resumes
			// from the server's authoritative count. In production wp_send_json_success
			// ends the request; the if/else keeps the completion path below from running
			// when the responder does not exit (as under test).
			wp_send_json_success(
				array(
					'done'     => false,
					'received' => $received,
					'total'    => $total,
				)
			);
		} else {
			// The whole archive has arrived: prove it is a real Pontifex archive before it
			// is allowed into the backups directory, then store it under a fresh name.
			if ( ! $this->assembled_upload_is_an_archive( $upload_id ) ) {
				$this->store->discard_upload( $upload_id );
				wp_send_json_error(
					array( 'message' => __( 'That file is not a Pontifex backup, so it was not stored.', 'pontifex' ) ),
					422
				);
			}

			$path = $this->store_completed_upload( $upload_id );

			wp_send_json_success(
				array(
					'done'     => true,
					'filename' => basename( $path ),
					'message'  => sprintf(
						/* translators: 1: the uploaded backup's size, human-readable, 2: the stored backup filename */
						__( 'Uploaded — %1$s stored as %2$s. It is now in your backups list below, ready to restore.', 'pontifex' ),
						$this->wordpress_context->format_size( $total ),
						basename( $path )
					),
				)
			);
		}
	}

	/**
	 * Prepare the uploads directory for a new upload's first chunk.
	 *
	 * Creates the uploads directory and sweeps abandoned part files. A failure here is
	 * a genuine server error (a 500); there is nothing appended yet to discard.
	 *
	 * @return void
	 */
	private function prepare_uploads(): void {
		try {
			$this->store->ensure_uploads_directory();
			$this->store->sweep_stale_uploads( self::STALE_UPLOAD_SECONDS );
		} catch ( Throwable $error ) {
			$this->fail( $error, '' );
		}
	}

	/**
	 * Append a chunk to the upload, returning the assembled size so far.
	 *
	 * Wraps the store write so a genuine filesystem failure becomes a 500 (discarding
	 * the part file); the success path returns the running total.
	 *
	 * @param string $upload_id  The upload id.
	 * @param string $chunk_path The temporary file holding this chunk.
	 * @param bool   $first      Whether this is the first chunk.
	 * @return int The assembled size after appending this chunk.
	 */
	private function append_chunk_to_upload( string $upload_id, string $chunk_path, bool $first ): int {
		try {
			return $this->store->append_chunk( $upload_id, $chunk_path, $first );
		} catch ( Throwable $error ) {
			$this->fail( $error, $upload_id );
		}
		return 0;
	}

	/**
	 * Move a completed, validated upload into the backups directory.
	 *
	 * Wraps the store move so a genuine filesystem failure becomes a 500 (discarding
	 * the part file); the success path returns the stored backup path.
	 *
	 * @param string $upload_id The upload id.
	 * @return string The absolute path of the stored backup.
	 */
	private function store_completed_upload( string $upload_id ): string {
		try {
			return $this->store->finalise_upload( $upload_id, new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) );
		} catch ( Throwable $error ) {
			$this->fail( $error, $upload_id );
		}
		return '';
	}

	/**
	 * Record an upload failure, discard any part file, and reply 500.
	 *
	 * The real cause is logged; the operator sees a generic message pointing at the
	 * log. In production wp_send_json_error ends the request, so this does not return.
	 *
	 * @param Throwable $error     The failure's real cause.
	 * @param string    $upload_id The upload id to discard, or '' when there is none yet.
	 * @return void
	 */
	private function fail( Throwable $error, string $upload_id ): void {
		$this->logger->error( 'Admin upload failed.', array( 'exception' => $error ) );
		if ( '' !== $upload_id ) {
			$this->store->discard_upload( $upload_id );
		}
		wp_send_json_error(
			array( 'message' => __( 'The upload could not be completed. Check the Pontifex log for details.', 'pontifex' ) ),
			500
		);
	}

	/**
	 * Validate the posted chunk file and return its upload entry.
	 *
	 * Refuses anything that is not a genuine, error-free HTTP upload, or that is
	 * larger than the site's upload ceiling or than the bytes still expected. Halts
	 * the request on any failure, so the caller receives only a sound chunk.
	 *
	 * @param int $remaining Bytes still expected for this upload (total minus offset).
	 * @return array{tmp_name: string, size: int} The validated chunk's temp path and size.
	 */
	private function validated_chunk( int $remaining ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in is_authorised(); the chunk's members are each validated below (error and size cast to int, tmp_name guarded by is_uploaded_file, the client name never used as a path).
		$file = isset( $_FILES['chunk'] ) && is_array( $_FILES['chunk'] ) ? $_FILES['chunk'] : array();

		$error    = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
		$tmp_name = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		$size     = isset( $file['size'] ) ? (int) $file['size'] : 0;

		if ( UPLOAD_ERR_OK !== $error || '' === $tmp_name || ! ( $this->is_uploaded_file )( $tmp_name ) ) {
			wp_send_json_error( array( 'message' => __( 'The upload chunk did not arrive intact.', 'pontifex' ) ), 400 );
		}

		if ( $size <= 0 || $size > $this->wordpress_context->max_upload_size() || $size > $remaining ) {
			wp_send_json_error( array( 'message' => __( 'The upload chunk was the wrong size.', 'pontifex' ) ), 400 );
		}

		return array(
			'tmp_name' => $tmp_name,
			'size'     => $size,
		);
	}

	/**
	 * Refuse the upload when the disk cannot hold the whole archive.
	 *
	 * A best-effort guard read on the first chunk: when the free space on the uploads
	 * volume can be determined and is smaller than the declared total, the upload is
	 * refused up front rather than failing part-way and leaving a large part file.
	 *
	 * @param int $total The declared total size of the archive.
	 * @return void
	 */
	private function refuse_if_no_room( int $total ): void {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- disk_free_space can be disabled by the host; the guard is best-effort and its failure must not block the upload.
		$free = @disk_free_space( $this->store->uploads_directory() );
		if ( false !== $free && $total > $free ) {
			wp_send_json_error(
				array( 'message' => __( 'There is not enough disk space to store this backup.', 'pontifex' ) ),
				507
			);
		}
	}

	/**
	 * Whether the assembled upload parses as a Pontifex archive.
	 *
	 * Opens the completed part file and reads its header and provenance; a file that
	 * is not a real archive throws, and is reported as not an archive. Reads only —
	 * nothing is written or restored here.
	 *
	 * @param string $upload_id The upload id whose assembled part file to check.
	 * @return bool True when the file is a readable Pontifex archive.
	 */
	private function assembled_upload_is_an_archive( string $upload_id ): bool {
		$stream = $this->store->open_upload( $upload_id );
		if ( null === $stream ) {
			return false;
		}
		try {
			$reader = new ArchiveReader( $stream );
			$reader->header();
			$reader->provenance();
			$valid = true;
		} catch ( Throwable $error ) {
			$this->logger->warning( 'Admin upload: the assembled file is not a valid archive.', array( 'exception' => $error ) );
			$valid = false;
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the part-file stream opened for validation; not a WP_Filesystem operation.
			fclose( $stream );
		}
		return $valid;
	}

	/**
	 * Whether the current request is a permitted upload.
	 *
	 * Deny-by-default: the user must hold the managing capability and present a valid
	 * `pontifex_upload` nonce. Both halves must pass.
	 *
	 * @return bool True when the request is authorised.
	 */
	private function is_authorised(): bool {
		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			return false;
		}
		return false !== check_ajax_referer( self::NONCE_ACTION, '_wpnonce', false );
	}

	/**
	 * The message returned when an upload is refused.
	 *
	 * @return string A human-readable refusal message.
	 */
	private function unauthorised_message(): string {
		return __( 'You do not have permission to upload backups, or your session has expired. Reload the page and try again.', 'pontifex' );
	}

	/**
	 * Read a trimmed string field from the request, or '' when absent.
	 *
	 * @param string $key The POST field name.
	 * @return string The field value, trimmed, or ''.
	 */
	private function request_string( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The nonce is verified in is_authorised() before any field is read.
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ) : '';
	}

	/**
	 * Read an integer field from the request, defaulting to a sentinel when absent.
	 *
	 * @param string $key The POST field name.
	 * @return int The field value as an int, or -1 when absent.
	 */
	private function request_int( string $key ): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The nonce is verified in is_authorised(); casting to int fully sanitises the value (and keeps a negative offset for the caller to reject).
		return isset( $_POST[ $key ] ) ? (int) wp_unslash( (string) $_POST[ $key ] ) : -1;
	}
}
