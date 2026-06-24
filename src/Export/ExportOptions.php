<?php
/**
 * Pontifex export options — the inputs that vary between one export and the next.
 *
 * @package Pontifex\Export
 */

declare(strict_types=1);

namespace Pontifex\Export;

use InvalidArgumentException;
use Pontifex\Archive\Crypto\EncryptionContext;
use Pontifex\Archive\Crypto\SigningContext;

/**
 * Immutable value object carrying the per-export inputs for {@see ExportRunner::export()}.
 *
 * ExportRunner is the one place the archive-writing recipe lives, shared by the
 * `wp pontifex export` command, the pre-import safety archiver, and (later) the
 * admin Backup screen. The pieces that differ between those callers — where the
 * archive is written, whether it is encrypted or signed, and the provenance note
 * recorded when it is left unencrypted — travel together in this object so the
 * runner's signature stays small and a caller can never pass half of a coupled
 * pair.
 *
 * Deliberately permissive about the encryption/reason pairing: the CLI export
 * records a non-empty reason when it writes in the clear (ARCHIVE-FORMAT.md
 * §8.5), whereas the safety archiver passes neither. Both are valid, so this
 * object only holds the values — it does not enforce a relationship between them.
 */
final class ExportOptions {

	/**
	 * Absolute filesystem path the archive is written to.
	 *
	 * @var string
	 */
	private string $output_path;

	/**
	 * Encryption inputs, or null to write an unencrypted archive.
	 *
	 * @var EncryptionContext|null
	 */
	private ?EncryptionContext $encryption;

	/**
	 * Signing inputs, or null to write an unsigned archive.
	 *
	 * @var SigningContext|null
	 */
	private ?SigningContext $signing;

	/**
	 * Reason recorded in provenance when the archive is unencrypted, or null.
	 *
	 * Non-empty when a caller writes an unencrypted archive and wants to record
	 * why (the §8.5 explanation the CLI export supplies); null when encrypting, or
	 * when a caller records no reason (the safety archiver).
	 *
	 * @var string|null
	 */
	private ?string $encryption_disabled_reason;

	/**
	 * Construct an ExportOptions.
	 *
	 * @param string                 $output_path                Absolute path the archive is written to; must be non-empty.
	 * @param EncryptionContext|null $encryption                 Encryption inputs, or null for an unencrypted archive.
	 * @param SigningContext|null    $signing                    Signing inputs, or null for an unsigned archive.
	 * @param string|null            $encryption_disabled_reason Reason recorded when the archive is unencrypted, or null.
	 * @throws InvalidArgumentException If $output_path is the empty string.
	 */
	public function __construct(
		string $output_path,
		?EncryptionContext $encryption = null,
		?SigningContext $signing = null,
		?string $encryption_disabled_reason = null
	) {
		if ( '' === $output_path ) {
			throw new InvalidArgumentException( 'ExportOptions: output_path must not be empty.' );
		}

		$this->output_path                = $output_path;
		$this->encryption                 = $encryption;
		$this->signing                    = $signing;
		$this->encryption_disabled_reason = $encryption_disabled_reason;
	}

	/**
	 * Return the absolute output path.
	 *
	 * @return string The path the archive is written to.
	 */
	public function output_path(): string {
		return $this->output_path;
	}

	/**
	 * Return the encryption context, or null when unencrypted.
	 *
	 * @return EncryptionContext|null The encryption inputs, or null.
	 */
	public function encryption(): ?EncryptionContext {
		return $this->encryption;
	}

	/**
	 * Return the signing context, or null when unsigned.
	 *
	 * @return SigningContext|null The signing inputs, or null.
	 */
	public function signing(): ?SigningContext {
		return $this->signing;
	}

	/**
	 * Return the unencrypted-archive reason, or null.
	 *
	 * @return string|null The reason recorded when unencrypted, or null.
	 */
	public function encryption_disabled_reason(): ?string {
		return $this->encryption_disabled_reason;
	}
}
