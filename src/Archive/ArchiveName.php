<?php
/**
 * Pontifex archive naming — the one master for what a Pontifex-written archive is called.
 *
 * @package Pontifex\Archive
 */

declare(strict_types=1);

namespace Pontifex\Archive;

/**
 * Recognises the canonical name Pontifex gives an archive it writes itself.
 *
 * `pontifex-backup-<UTC>.wpmig`, where `<UTC>` is a basic-format ISO 8601
 * timestamp — `20260805T134500Z`. Two properties follow from that shape and
 * both are relied on elsewhere: the name is unique per second, and because the
 * timestamp is fixed-width and most-significant-first, **lexical order is age
 * order**.
 *
 * That second property is why this class exists. Remote retention sorts by name
 * and deletes the surplus from the front, which is only sound for names of this
 * shape. The rule used to be asserted in two docblocks and enforced nowhere,
 * while the system carried two different ideas of what counted as an archive:
 * the admin backup store matched this exact pattern, but the SFTP adapter
 * accepted anything ending `.wpmig` and the CLI uploads under whatever
 * basename `--output` was given. So an operator running
 *
 *     wp pontifex export --output=/backups/before-upgrade.wpmig --destination=nas
 *
 * put a file called `before-upgrade.wpmig` into the set that sort assumes is
 * timestamped. It sorts before every `pontifex-backup-…` name, so retention
 * deleted it as "the oldest" — the backup taken minutes earlier, specifically
 * to be there if the upgrade went wrong — and reported it as pruning an old
 * archive.
 *
 * Keeping the pattern in one place means the answer to "is this one of ours?"
 * cannot drift between the surfaces that ask it.
 */
final class ArchiveName {

	/**
	 * The canonical name of an archive Pontifex wrote itself.
	 *
	 * Anchored at both ends: a name that merely contains the shape is not the
	 * shape. `\d{8}T\d{6}Z` is the basic-format UTC timestamp.
	 *
	 * @var string
	 */
	public const PATTERN = '/^pontifex-backup-\d{8}T\d{6}Z\.wpmig$/';

	/**
	 * Whether a basename is one Pontifex generated.
	 *
	 * Callers pass a basename, not a path: this is a question about the name, and
	 * accepting a path would invite a caller to ask it of something that has not
	 * been confined yet.
	 *
	 * @param string $basename The file's basename, with no directory component.
	 * @return bool True when the name matches the canonical generated form.
	 */
	public static function is_generated( string $basename ): bool {
		return 1 === preg_match( self::PATTERN, $basename );
	}
}
