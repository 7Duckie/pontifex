<?php
/**
 * Defensive limits the reader enforces while opening an archive.
 *
 * @package Pontifex\Archive\Reader
 */

declare(strict_types=1);

namespace Pontifex\Archive\Reader;

use InvalidArgumentException;

/**
 * Immutable set of ceilings that protect the reader from hostile archives.
 *
 * A `.wpmig` import parses bytes from a file the operator may have been
 * handed by someone else, so the reader must refuse archives crafted to
 * exhaust memory or fill the disk (the "decompression bomb" class of
 * attack). This value object carries the four ceilings that guard
 * against that, with conservative defaults the import path applies
 * unless told otherwise.
 *
 * The four limits, in plain terms:
 *
 *  - **max_entry_count** — refuse an archive that lists more entries
 *    than this. The manifest is already size-bounded, so this is a
 *    clear, early error rather than the only defence.
 *  - **max_entry_bytes** — no single entry may decode to more than this
 *    many bytes. The per-entry memory ceiling; a flat number because
 *    memory should not scale with the archive.
 *  - **max_total_ratio** — the whole restore may not decode to more
 *    than this multiple of the archive's own on-disk size. A real
 *    WordPress archive expands only a little (its bulk is already
 *    compressed media), so a generous multiple still refuses a bomb
 *    while never troubling a genuine site.
 *  - **max_total_bytes** — an absolute ceiling on the whole restore,
 *    the backstop for a large archive where the ratio alone would
 *    still permit an enormous total.
 *
 * The total budget for a given archive is the smaller of the two total
 * limits — see {@see ArchiveLimits::max_total_for_archive()}. That
 * single rule gives a generous expansion ratio for small archives and
 * a tapering one for large archives, capped by the absolute ceiling,
 * without any bespoke sliding-scale formula.
 *
 * The object is immutable; {@see ArchiveLimits::with_max_total_bytes()}
 * returns a copy with a different absolute ceiling. That wither is the
 * intended seam for a future `wp pontifex import --max-size` override:
 * the command would read the flag and pass a raised ceiling here,
 * leaving every other limit and all enforcement code untouched.
 */
final class ArchiveLimits {

	/**
	 * Default maximum number of entries an archive may declare.
	 *
	 * Set to 100,000. The previous value (50,000) refused legitimate sites for
	 * no benefit: the manifest's own 16 MiB byte cap already bounds an archive
	 * long before any realistic site reaches that count, so the lower ceiling
	 * only turned readable archives away.
	 *
	 * Note what this number is NOT. Dividing
	 * {@see \Pontifex\Archive\Format\ArchiveManifest::MAX_PAYLOAD_SIZE} by
	 * {@see \Pontifex\Archive\Format\ArchiveManifest::MIN_ENTRY_PAYLOAD_BYTES}
	 * gives roughly 99,273, but that is a deliberately conservative estimate
	 * rather than a structural cap: measured against the real writer, the true
	 * maximum an archive can express is about 114,912 entries. So 100,000 sits
	 * BELOW the structural maximum and does bite, in the band between the two.
	 * That is a real ceiling on entry count, not a formality — it simply sits
	 * far enough out that the byte cap is what a real site meets first.
	 *
	 * Whatever this value is set to, it used
	 * to be enforced too late to matter: the count was only checked AFTER
	 * {@see \Pontifex\Archive\Reader\ArchiveReader::manifest()} had already
	 * decoded every entry into memory, so a hostile or merely-large archive
	 * had already been fully allocated before the limit could refuse it. The
	 * real guard now is {@see \Pontifex\Archive\Format\ArchiveManifest::MIN_ENTRY_PAYLOAD_BYTES}'s
	 * pre-decode estimate in ArchiveReader, checked against the manifest's
	 * declared length BEFORE any byte is read or decoded; this count remains
	 * a second, post-parse check (defence in depth).
	 *
	 * Memory, not count, is the true constraint on opening a large archive:
	 * decoding a manifest costs roughly 1.6 KB of PHP memory per entry (the
	 * JSON payload plus the ManifestEntry object graph it decodes into), so
	 * an archive with entries near this ceiling needs a correspondingly
	 * larger memory_limit on the destination to open at all — streaming the
	 * manifest instead of decoding it whole would remove that requirement,
	 * but is deliberately deferred to its own future slice rather than
	 * bundled here.
	 *
	 * @var int
	 */
	public const DEFAULT_MAX_ENTRY_COUNT = 100000;

	/**
	 * Default maximum decoded size of any single entry, in bytes (2 GiB).
	 *
	 * @var int
	 */
	public const DEFAULT_MAX_ENTRY_BYTES = 2147483648;

	/**
	 * Default maximum ratio of total decoded bytes to archive size on disk.
	 *
	 * @var int
	 */
	public const DEFAULT_MAX_TOTAL_RATIO = 100;

	/**
	 * Default absolute ceiling on total decoded bytes for one restore (1 TiB).
	 *
	 * @var int
	 */
	public const DEFAULT_MAX_TOTAL_BYTES = 1099511627776;

	/**
	 * Maximum number of entries an archive may declare.
	 *
	 * @var int
	 */
	private int $max_entry_count;

	/**
	 * Maximum decoded size of any single entry, in bytes.
	 *
	 * @var int
	 */
	private int $max_entry_bytes;

	/**
	 * Maximum ratio of total decoded bytes to the archive's on-disk size.
	 *
	 * @var int
	 */
	private int $max_total_ratio;

	/**
	 * Absolute ceiling on total decoded bytes for a single restore.
	 *
	 * @var int
	 */
	private int $max_total_bytes;

	/**
	 * Construct an immutable set of archive limits.
	 *
	 * Every limit must be a positive integer; a non-positive value is a
	 * programming error and is rejected loudly rather than silently
	 * disabling a defence.
	 *
	 * @param int $max_entry_count Maximum number of entries an archive may declare.
	 * @param int $max_entry_bytes Maximum decoded size of any single entry, in bytes.
	 * @param int $max_total_ratio Maximum ratio of total decoded bytes to archive size on disk.
	 * @param int $max_total_bytes Absolute ceiling on total decoded bytes for one restore.
	 * @throws InvalidArgumentException If any limit is less than 1.
	 */
	public function __construct( int $max_entry_count, int $max_entry_bytes, int $max_total_ratio, int $max_total_bytes ) {
		if ( 1 > $max_entry_count ) {
			throw new InvalidArgumentException( sprintf( 'ArchiveLimits: max_entry_count %d must be at least 1.', (int) $max_entry_count ) );
		}
		if ( 1 > $max_entry_bytes ) {
			throw new InvalidArgumentException( sprintf( 'ArchiveLimits: max_entry_bytes %d must be at least 1.', (int) $max_entry_bytes ) );
		}
		if ( 1 > $max_total_ratio ) {
			throw new InvalidArgumentException( sprintf( 'ArchiveLimits: max_total_ratio %d must be at least 1.', (int) $max_total_ratio ) );
		}
		if ( 1 > $max_total_bytes ) {
			throw new InvalidArgumentException( sprintf( 'ArchiveLimits: max_total_bytes %d must be at least 1.', (int) $max_total_bytes ) );
		}

		$this->max_entry_count = $max_entry_count;
		$this->max_entry_bytes = $max_entry_bytes;
		$this->max_total_ratio = $max_total_ratio;
		$this->max_total_bytes = $max_total_bytes;
	}

	/**
	 * Build the conservative default limits applied by the import path.
	 *
	 * @return self A limits object using every DEFAULT_* constant.
	 */
	public static function defaults(): self {
		return new self(
			self::DEFAULT_MAX_ENTRY_COUNT,
			self::DEFAULT_MAX_ENTRY_BYTES,
			self::DEFAULT_MAX_TOTAL_RATIO,
			self::DEFAULT_MAX_TOTAL_BYTES
		);
	}

	/**
	 * Return a copy with a different absolute total ceiling.
	 *
	 * The intended seam for a future `import --max-size` override. Every
	 * other limit is carried over unchanged.
	 *
	 * @param int $max_total_bytes The new absolute ceiling, in bytes.
	 * @return self A new instance; this one is left unchanged.
	 * @throws InvalidArgumentException If the ceiling is less than 1.
	 */
	public function with_max_total_bytes( int $max_total_bytes ): self {
		return new self( $this->max_entry_count, $this->max_entry_bytes, $this->max_total_ratio, $max_total_bytes );
	}

	/**
	 * Return the maximum number of entries an archive may declare.
	 *
	 * @return int The entry-count ceiling.
	 */
	public function max_entry_count(): int {
		return $this->max_entry_count;
	}

	/**
	 * Return the maximum decoded size of any single entry, in bytes.
	 *
	 * @return int The per-entry byte ceiling.
	 */
	public function max_entry_bytes(): int {
		return $this->max_entry_bytes;
	}

	/**
	 * Return the maximum ratio of total decoded bytes to archive size.
	 *
	 * @return int The total expansion-ratio ceiling.
	 */
	public function max_total_ratio(): int {
		return $this->max_total_ratio;
	}

	/**
	 * Return the absolute ceiling on total decoded bytes for one restore.
	 *
	 * @return int The absolute total ceiling, in bytes.
	 */
	public function max_total_bytes(): int {
		return $this->max_total_bytes;
	}

	/**
	 * Compute the total decoded-byte budget for an archive of a given size.
	 *
	 * The budget is the smaller of the ratio bound (ratio times the
	 * archive's on-disk size) and the absolute ceiling. Small archives
	 * are therefore allowed a generous expansion while large ones are
	 * held to the absolute ceiling.
	 *
	 * @param int $archive_size_bytes The archive's on-disk size, in bytes.
	 * @return int The maximum total decoded bytes permitted for this archive.
	 */
	public function max_total_for_archive( int $archive_size_bytes ): int {
		return min( $this->max_total_ratio * $archive_size_bytes, $this->max_total_bytes );
	}
}
