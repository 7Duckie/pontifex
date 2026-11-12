<?php
/**
 * Pontifex source table prefix — recovers a cross-prefix restore's source prefix when the archive does not record one.
 *
 * @package Pontifex\Restore
 */

declare(strict_types=1);

namespace Pontifex\Restore;

use Pontifex\Archive\Format\ArchiveManifest;
use Pontifex\Archive\Reader\EntryReader;

/**
 * Establishes the archive's own table prefix, so a cross-prefix restore can run
 * even when the archive predates the field that records it.
 *
 * `table_prefix` in the Provenance block is optional, added at format v1.1
 * ({@see \Pontifex\Archive\Format\Provenance::table_prefix()}); an archive
 * written before that carries none. Without a source prefix
 * {@see \Pontifex\Restore\DatabaseWriter}'s cross-prefix rewrite never
 * activates, so its cross-site guard — refusing a table whose name does not
 * begin with the destination it is targeting — is the ONLY thing standing
 * between such a restore and refusal, even for an operator restoring their
 * own, entirely legitimate archive onto a site with a different prefix.
 * {@see self::derive()} recovers the same fact from the one place it is
 * still written down: the table names themselves.
 *
 * A dedicated class rather than a method on an existing type, the same
 * reasoning {@see \Pontifex\WordPress\WordPressRoot} was extracted for: one
 * home for a fact more than one call site needs, so a future change to how
 * it is derived cannot silently diverge between them.
 *
 * The safety argument this class rests on: a derived prefix is never trusted
 * as a DESTINATION, only as the SOURCE half of a rewrite. With a rewrite
 * active, {@see DatabaseWriter::destination_table_name()} builds the new name
 * as `dest_prefix . substr( name, strlen( source_prefix ) )` — and
 * `dest_prefix` is always this site's own (a constructor argument Pontifex's
 * own calling code supplies, never the archive), so every rewritten name
 * lands inside this site's namespace BY CONSTRUCTION regardless of what
 * `source_prefix` was. A wrong or hostile `source_prefix` only changes how
 * many leading characters of the archive's own name are stripped before the
 * destination prefix is glued on — it cannot make the result escape this
 * site's namespace, so it cannot reach a table `write_entry()`'s own guard
 * would otherwise have refused. At worst a hostile archive causes its own
 * tables to land confusingly on this site's tables, which is what restoring
 * any hostile archive's data already does; deriving `source_prefix` from
 * archive-supplied names is therefore safe in a way that trusting an
 * archive-supplied value as a DESTINATION never was, which is exactly what
 * the destination-prefix guard exists to refuse.
 */
final class SourceTablePrefix {

	/**
	 * WordPress core table name suffixes this class recognises, single-site only.
	 *
	 * Multisite is refused at bootstrap (this project does not support it), so
	 * the network tables — `site`, `sitemeta`, `blogs`, and the rest — are
	 * deliberately absent: an archive this codebase can produce never carries
	 * them, and including them here would only widen what a hostile archive
	 * could use to manufacture a misleading candidate.
	 *
	 * @var string[]
	 */
	private const CORE_TABLE_SUFFIXES = array(
		'commentmeta',
		'comments',
		'links',
		'options',
		'postmeta',
		'posts',
		'term_relationships',
		'term_taxonomy',
		'termmeta',
		'terms',
		'usermeta',
		'users',
	);

	/**
	 * The identifier shape a table name, or a derived candidate prefix, must match.
	 *
	 * The same shape a WordPress table prefix always takes — a non-empty run
	 * of ASCII letters, digits, and underscores — used both to discard a
	 * table name that cannot be trusted to mean what it looks like, and to
	 * discard a candidate prefix that would carry SQL metacharacters from a
	 * crafted archive into a rewrite statement.
	 *
	 * @var string
	 */
	private const IDENTIFIER_PATTERN = '/^[A-Za-z0-9_]+$/';

	/**
	 * Resolve the source table prefix a cross-prefix restore should rewrite from.
	 *
	 * Returns the recorded prefix when the archive's provenance carries one
	 * and it is well-formed — the archive's own word for what it is, never
	 * second-guessed when present. Otherwise gathers the archive's database
	 * table names, cheaply, and defers to {@see self::derive()}. Returns ''
	 * when the archive has no database half, or when nothing can be
	 * established either way — which leaves the rewrite off and lets
	 * {@see DatabaseWriter::write_entry()}'s own cross-site guard refuse with
	 * its ordinary message, the backstop this class does not replace.
	 *
	 * @param string|null     $recorded_prefix The prefix recorded in the archive's Provenance block (format v1.1), or null when unrecorded — an archive predating the field, or one whose provenance omitted it.
	 * @param ArchiveManifest $manifest        The archive's already-decoded manifest, to find its db_chunk entries.
	 * @param resource        $source          The open, seekable archive stream, for reading each candidate entry's header.
	 * @param EntryReader     $entry_reader     Reads and validates one entry's header without decoding its payload.
	 * @return string The prefix to rewrite from; '' when no rewrite should run.
	 * @throws \Pontifex\Exception\ArchiveNotTrustworthy If a db_chunk header cannot be read or is malformed, or its kind contradicts the manifest.
	 */
	public static function resolve( ?string $recorded_prefix, ArchiveManifest $manifest, $source, EntryReader $entry_reader ): string {
		if ( null !== $recorded_prefix && 1 === preg_match( self::IDENTIFIER_PATTERN, $recorded_prefix ) ) {
			return $recorded_prefix;
		}
		return self::derive( self::first_chunk_table_names( $manifest, $source, $entry_reader ) );
	}

	/**
	 * Derive the common table prefix from a set of table names, or '' when none can be established.
	 *
	 * Pure and side-effect-free: no I/O, no archive knowledge, just the table
	 * names it is given. Every name is untrusted — this may be called on
	 * names read straight out of a hostile archive — so the result is
	 * confirmed correct by construction (see the class docblock) rather than
	 * by trusting the input.
	 *
	 * The algorithm:
	 *
	 *  1. Discard any name that is not a non-empty run of `[A-Za-z0-9_]`.
	 *  2. For each {@see self::CORE_TABLE_SUFFIXES} suffix, and each surviving
	 *     name ending in it, the candidate prefix is that name with the
	 *     suffix removed. A candidate that is empty, or does not itself match
	 *     the same identifier shape, is discarded.
	 *  3. Keep only candidates that are a prefix of EVERY surviving name —
	 *     what rejects a plugin table masquerading as a core one:
	 *     `wp_myplugin_options` yields the candidate `wp_myplugin_`, which is
	 *     not a prefix of a sibling `wp_posts`, so it dies here.
	 *  4. Several distinct candidates can survive step 3 at once — every name
	 *     starting with the LONGER of two candidates also, trivially, starts
	 *     with the SHORTER one. The shortest is returned: it treats the
	 *     fewest characters as prefix, so it cannot over-strip.
	 *  5. If no candidate survives step 3, the answer is ''.
	 *
	 * @param string[] $table_names Table names read from the archive; untrusted, and not assumed unique.
	 * @return string The derived prefix, or '' when none can be established.
	 */
	public static function derive( array $table_names ): string {
		$valid_names = array_values(
			array_filter(
				$table_names,
				static fn ( string $name ): bool => '' !== $name && 1 === preg_match( self::IDENTIFIER_PATTERN, $name )
			)
		);

		if ( array() === $valid_names ) {
			return '';
		}

		// Every distinct candidate a suffix strip can produce, from any surviving
		// name. Keyed by the candidate string itself so the same candidate found
		// from several names is recorded once.
		$candidates = array();
		foreach ( $valid_names as $name ) {
			foreach ( self::CORE_TABLE_SUFFIXES as $suffix ) {
				if ( ! str_ends_with( $name, $suffix ) ) {
					continue;
				}
				$candidate = substr( $name, 0, -strlen( $suffix ) );
				if ( '' === $candidate || 1 !== preg_match( self::IDENTIFIER_PATTERN, $candidate ) ) {
					continue;
				}
				$candidates[ $candidate ] = true;
			}
		}

		$shortest = null;
		foreach ( array_keys( $candidates ) as $candidate ) {
			if ( ! self::is_prefix_of_every_name( $candidate, $valid_names ) ) {
				continue;
			}
			if ( null === $shortest || strlen( $candidate ) < strlen( $shortest ) ) {
				$shortest = $candidate;
			}
		}

		return $shortest ?? '';
	}

	/**
	 * Whether $candidate is a leading substring of every name in $names.
	 *
	 * @param string   $candidate The candidate prefix.
	 * @param string[] $names     The names to check it against.
	 * @return bool True when every name begins with $candidate.
	 */
	private static function is_prefix_of_every_name( string $candidate, array $names ): bool {
		foreach ( $names as $name ) {
			if ( ! str_starts_with( $name, $candidate ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Gather the table name declared by each of the archive's first-chunk db_chunk entries.
	 *
	 * A large table's export spans several db_chunk entries — schema, then one
	 * or more row windows — but every chunk for one table carries the same
	 * `table_name` and only the FIRST one is needed to learn it. `chunk_index`
	 * is a field the manifest itself records per entry (not something that
	 * requires decoding the entry to learn), so filtering to `chunk_index()
	 * === 0` costs nothing beyond the manifest Pontifex has already parsed —
	 * one entry per table, never one per chunk. Only that entry's HEADER is
	 * then read, via {@see EntryReader::peek_header()}, which decodes no
	 * payload; the table name a db_chunk's header carries is never encrypted
	 * even in an encrypted archive (the header is the AES-GCM AAD, not the
	 * ciphertext), so this needs no passphrase either.
	 *
	 * @param ArchiveManifest $manifest     The archive's already-decoded manifest.
	 * @param resource        $source       The open, seekable archive stream.
	 * @param EntryReader     $entry_reader Reads and validates one entry's header without decoding its payload.
	 * @return string[] One table name per db_chunk table; empty when the archive has no database half.
	 */
	private static function first_chunk_table_names( ArchiveManifest $manifest, $source, EntryReader $entry_reader ): array {
		$names = array();
		foreach ( $manifest->entries() as $entry ) {
			if ( ! $entry->is_db_chunk() || 0 !== $entry->chunk_index() ) {
				continue;
			}
			$table_name = $entry_reader->peek_header( $source, $entry )->table_name();
			if ( null !== $table_name ) {
				$names[] = $table_name;
			}
		}
		return $names;
	}
}
