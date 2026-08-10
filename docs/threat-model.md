# Pontifex threat model

## What this document is

This is a *plugin-level* threat model. It ranks the attack surfaces
Pontifex exposes while it is running — the places where a successful
exploit would do the most damage — so that triage decisions on CVEs,
code reviews of security-adjacent changes, and design decisions in
future phases can refer to a single shared picture rather than each
contributor reasoning from scratch.

It is distinct from, and complementary to, the *format-level* threat
model in [the archive format design doc](./archive-format-design.md#5-threat-model).
That document covers what cryptographic properties the `.wpmig`
format provides and what attacks it explicitly does not defend
against. This document is about the plugin as a running system:
which moving parts touch attacker-controlled data, and what would
happen if one of them failed.

### How to read the rankings

"Blast radius" is the realistic worst-case damage if an exploit at
that surface succeeded. A surface where a single exploit grants
remote code execution on the destination site has a far larger blast
radius than one where the worst outcome is denial of service. The
list goes from largest blast radius (1) to smallest (6); urgency of
patching, defensive review, and CVE response should scale with the
ranking.

### CVE priority

Each surface notes the priority assigned to CVEs that touch it. The
scale used here:

- **P0** — drop everything, ship a fix within days. Reserved for
  surfaces where an exploit gives an attacker code execution, key
  recovery, or unrestricted data access.
- **P1** — fix promptly, within a release cycle. Significant
  exposure but not catastrophic.
- **P2/P3** — patch when convenient. Either the surface has small
  blast radius or strong mitigations make exploitation impractical.

---

## 1. Search-replace running on attacker-controlled data

The serialised-data walker is fed everything in the source database.
If an attacker controls a row in `wp_options` or `wp_postmeta` and a
deserialisation gadget — a chain of class instantiations and method
calls triggered by PHP's `unserialize` on attacker-controlled bytes,
used to reach an arbitrary code execution sink — is reachable from
the data we walk, we have a remote code execution path on the
destination site at the moment of import.

**Mitigations in design:**

- Class allowlist (`allowed_classes => false`) to defeat gadget chains
- Round-trip verification: re-serialise and compare; mismatch → keep the
  original value and count it as skipped; a non-zero skipped-value tally
  is reported to the operator after the migration
- A post-restore rewrite report (`RewriteReport`) that tallies rows and
  values changed and, crucially, counts the values that held the search
  term but were left unchanged for safety — a non-zero `values_skipped`
  is the operator's cue to review the migration. The report carries
  counts and table names only, never row contents.
- Filter-extensibility (`pontifex_serialized_classes`) so legitimate
  custom classes can opt in explicitly

**CVE priority:** any CVE touching PHP's `unserialize`, the
serialisation format itself, or library code we use to walk
serialised data is P0.

## 2. Archive contents on import

A malicious `.wpmig` file uploaded by an admin (the threat model is
"admin made a mistake or was phished," not "anyone on the internet")
could contain crafted FILE entries, oversized manifests, malicious
SQL, or path-traversal filenames. Every byte we read from an archive
is untrusted.

**Mitigations in design:**

- Magic/version/footer validated before any other parsing (see
  [archive-format.md §12](./archive-format.md#12-integrity-and-tamper-detection)
  for the exact verification flow)
- Manifest size capped at 16 MiB (`ArchiveManifest::MAX_PAYLOAD_SIZE`),
  checked before the manifest is decoded, not after
- Per-entry length checked against remaining file size
- FILE, directory and symlink entry **paths** are validated
  relative-and-within-wp-content on a content-only restore (no traversal:
  no absolute path, no `..` segment, no null byte), and an entry whose
  ancestor is itself a symlink is refused
  (`FileWriter::assert_no_symlinked_ancestor()`)
- A symlink's **target** is a separate question from its path, and is
  confined separately, before any byte of the restore is written
  (`FileWriter::assert_symlink_targets_confined()`, [ADR
  0021](./adr/0021-symlink-target-confinement.md)). Every symlink the
  archive declares is resolved the way the kernel would resolve it —
  component by component, substituting a target already declared or
  already on disk when a component is itself a link — over the archive's
  whole declared set of links at once, because the attack this closes
  needs two co-operating links and no single entry looks wrong on its
  own. A target is refused if it is absolute, if it resolves outside the
  site root, or if it resolves to the site's own `wp-config.php` or to
  `wp-content/pontifex` (where this site's own backups and safety
  archives live). This closed a confirmed unauthenticated read of
  `wp-config.php` that the previous, textual-only check missed. An
  operator who has read the reported resolution and trusts the archive
  can waive this specific target check with `wp pontifex import
  --allow-unsafe-symlinks`; nothing in the admin browser UI can waive
  it — both the Restore and Verify controllers always construct the
  writer with this disabled — and the capability probe below always
  runs regardless. Before any of this is even judged, the host is asked
  whether it can create a symlink at all, once per distinct directory a
  declared link would land in — collapsed to the nearest directory that
  already exists, since the walk creates the rest as it goes. Every
  declared link is resolved and walked before this cap is even
  consulted, so the cap bounds only the number of real
  create-a-test-symlink probes, not that per-link resolution work:
  above 64 distinct directories the preflight probes their deepest
  common ancestor instead of each one individually
  (`FileWriter::assert_symlinks_creatable()`) — a host with `symlink`
  in `disable_functions` (common on shared hosting) would otherwise be
  discovered only once the write walk reached the archive's first
  symlink entry, by which point every file entry ahead of it has already
  overwritten the live site. Neither preflight runs on `wp pontifex
  verify` or a dry-run import, which write nothing and so have nothing
  to preflight: an archive that a restore would refuse for an escaping
  or uncreatable symlink can still report as structurally sound at
  verify time, and is refused only when a real restore is attempted —
  the same shape of gap the `db_chunk` residual risk below states for
  SQL.
- Every `db_chunk`'s payload is anchored, statement by statement, to the
  destination table identifier the restore engine itself constructs — never
  one read from the chunk's header or payload — with each `;\n`-terminated
  statement required to begin, at byte offset 0, with an exact, allow-listed
  shape (a `DROP TABLE IF EXISTS`, a `CREATE TABLE`, or an `INSERT INTO` of
  that identifier, with or without a column list). Anything else refuses the
  whole chunk before any statement in it executes. This closes a confirmed
  vulnerability in which the engine's own splitting of a chunk into
  individual driver calls (needed because mysqli refuses a query string
  containing more than one statement) incidentally defeated that same driver
  protection, letting a smuggled statement run against the live database
  outside the chunk's declared table.
- The opening-bytes shape check is deliberately blind to a `CREATE TABLE`
  statement's body — a real table can legitimately carry a `FOREIGN KEY`
  reference there — and that silence was itself a confirmed vulnerability: a
  storage-engine clause in the body (MySQL's built-in `MERGE` engine, or
  `FEDERATED`/`CONNECT` where the server compiles them in) can turn the
  staged table into a writable alias for a live table, a connection to a
  remote server, or a local file, entirely through bytes the shape check
  never inspects — proven end-to-end against real MariaDB. A
  `DATA DIRECTORY`/`INDEX DIRECTORY` clause in the same body is the same
  route again, and is a *write* attempt (it points the table's on-disk
  storage at an arbitrary path), not a read — and that clause can be
  attached either to the whole table or, independently, to a single
  partition; a partitioned table's `CREATE_OPTIONS` reports only the word
  `partitioned`, so a check that reads `CREATE_OPTIONS` alone cannot see a
  per-partition directory clause at all, and this was proven, against real
  MariaDB, to still reach the filesystem after the table-level check
  shipped. This is closed by asking the server what the staged identifier
  actually became once `CREATE TABLE` ran: the whole restore is refused
  unless the reported storage engine is on an allow-list of ordinary local
  row-store engines — InnoDB, MyISAM, MariaDB's Aria, and MEMORY, and
  nothing else — the reported `CREATE_OPTIONS` name none of a unioned
  table list, a remote connection, a data directory, or an index
  directory, the same identifier's own partitions (read separately,
  because `CREATE_OPTIONS` alone reports a partitioned table only as
  `partitioned`) name no data or index directory of their own, and the
  server's own catalogue reports the object as an ordinary base table. The
  engine allow-list is a deliberate trade: every refused engine — `MERGE`
  (a writable alias over other tables), `FEDERATED`/`CONNECT` (a remote
  connection), and file-backed or storage-less engines such as `CSV`,
  `ARCHIVE`, and `BLACKHOLE` — exists specifically to reach something
  other than its own local rows, which is exactly what this guard rules
  out; an operator whose site genuinely depends on a table using a refused
  engine has that restore refused outright rather than silently degraded,
  a real operational cost worth finding in this document rather than
  mid-recovery.
- Every statement is also independently refused if it contains a semicolon
  outside a quoted literal or a comment, followed by further content. The
  scan tracks SQL's comment forms (`-- `, `#`, `/* ... */`) as well as its
  quoting, because a scan that tracks quoting alone can be desynchronised by
  an unbalanced quote-like byte placed inside a comment — nine such variants
  were confirmed to defeat the scan's first version against real MariaDB.
  MySQL's conditional-execution comment syntax, `/*! ... */`, and MariaDB's
  own equivalent marker, `/*M! ... */`, are deliberately **not** treated as
  comments, because the server executes the bytes either one encloses. (A
  semicolon placed inside an unrecognised `/*M! ... */` block was tested
  directly against real MariaDB and rejected as a syntax error in every
  case tried, rather than letting a second statement through — so this was
  closed as a specification gap in what the scan recognised as executable,
  not as a proven exploitable bypass in its own right.) This closes the
  residual gap the shape and server checks above leave open: containment
  of a stacked statement previously
  rested, in part, on mysqli's own multi-statement refusal — a driver
  behaviour this code neither stated nor controlled, and which the
  statement-splitting above defeats anyway. The scan is a lexical pass over
  statement bytes, not a SQL parser, so this removes the driver dependency
  for every desynchronisation route found during adversarial testing, not
  as a formal guarantee against every lexical corner case. See
  [ADR 0019](./adr/0019-db-chunk-statement-containment.md) for the full
  account, including why a verb-plus-target allow-list and verb extraction
  were both tried first and both defeated.
- Regex use against untrusted archive bytes is deliberately narrow rather
  than general-purpose. The serialised-data walker's own patterns
  (`SerialisedReplacer`) test only a value's structural type marker
  against a short, fixed-shape prefix — for example `^s:[0-9]+:` for a
  string length — never open-ended matching over the value's own
  content, so there is no adversarial-length run for a
  catastrophic-backtracking pattern to work against. Where a pattern
  does have to match a long, attacker-shaped run — a `db_chunk`'s
  backtick-quoted column identifiers ([ADR
  0019](./adr/0019-db-chunk-statement-containment.md)) — it is built with
  possessive quantifiers specifically to keep the cost linear in the
  input length, because the naive alternation costs the PCRE engine one
  backtrackable stack frame per matched character and was confirmed to
  exhaust the engine's stack on a table with a few hundred columns.

**Residual risk, stated honestly:** the checks above — the statement-shape
anchor, the server-fact check on what a `CREATE TABLE` actually produced,
and a post-CREATE row-count check on the object it produced — together
confine what table a chunk *writes* to, or aliases, before cut-over, and
confine what a `CREATE`'s own `SELECT` clause may populate that table
with. That confinement closes the identifier-spoofing route this section
opened with, the storage-engine route (`MERGE`/`FEDERATED`/`CONNECT`, and a
`DATA DIRECTORY`/`INDEX DIRECTORY` clause — at the table level or, checked
separately, per partition — redirecting the table's storage — all
*write*-side, not read-side, despite living inside a `CREATE TABLE`), and
the `CREATE ... AS SELECT` family: a `CREATE TABLE` built with an
allow-listed engine and no disallowed `CREATE_OPTIONS` can pass the
server-fact check on its shape while its `SELECT` clause populates the
table from another table wholesale, so the row-count check reads the
just-created object's own exact row count, immediately after its `CREATE
TABLE` executes, and refuses the whole restore if it holds any — confirmed
against real MariaDB to catch the `(cols)`/`(cols) AS`/`IGNORE`/`REPLACE
SELECT`, subquery, and `UNION` forms alike, because every one of them
populates at least one row the moment the `CREATE` runs. A `SELECT` that
returns zero rows still passes — an empty result exfiltrates nothing, so a
row count, rather than a parse of the `SELECT` clause, is the right check.

What no check at this layer constrains is what a permitted `INSERT`'s own
`VALUES` *read* from elsewhere on the destination server — sanctioned
statement content, not a `CREATE TABLE`'s catalogue facts, so there is no
server-reported fact for a row-count-style check to read. A read here is
not inert — what is read is written into the staged table, which the
cut-over then installs as part of the restored site. Confirmed against
real MariaDB: an `INSERT` whose value is `LOAD_FILE('/etc/hostname')` reads
an arbitrary file from **the database server's own filesystem** — on the
common single-host deployment (web and database on one machine, the
database user holding the `FILE` privilege, `secure_file_priv` unset) this
can read `wp-config.php` itself into a table the restored site then
serves; this is the more serious of the two remaining routes and was
previously undocumented anywhere in this project. An `INSERT` whose value
is a scalar subquery against another table (`(SELECT user_pass FROM
wp_users ORDER BY ID LIMIT 1)`) copies live data, such as a password hash,
into the chunk's own staged table. Neither of these two can be closed by
pattern-matching more "known-bad" fragments — `LOAD_FILE` and a subquery
are both ordinary SQL that can appear inside a value expression the checks
deliberately do not parse the meaning of, so they are documented here, as
the guard's genuinely residual risk, rather than chased with a deny-list
(see [ADR 0019](./adr/0019-db-chunk-statement-containment.md) for the full
account). Nor do these checks make a hostile archive's data trustworthy — a
legitimate restore of a users-table chunk is supposed to overwrite
`wp_users` with the archive's version, so an attacker who controls an
otherwise correctly-shaped chunk's row data can still plant it. The checks
are enforced only when a restore actually runs SQL: `wp pontifex verify`
never executes a chunk's payload, so a chunk that violates any of these
rules still reports as structurally sound and is refused only at restore
time. Read together, the operator conclusion is plain: an archive from an
untrusted source should not be restored; these checks reduce, but do not
eliminate, the consequences of doing so anyway.

**CVE priority:** CVEs in the archive parser or any decompression
library (zstd, zlib) are P0.

## 3. Snapshot files at rest

Encryption is **opt-in per export**, not automatic: `wp pontifex export
--encrypt` (an interactive, double-entry passphrase prompt) or
`--passphrase-stdin` (for scripts). An archive taken without either flag —
the default — is unencrypted. When encryption is requested, the key is a
32-byte AES-256 key derived from the operator's own passphrase with
Argon2id (`Argon2idKdf`); it is never derived from `wp-config.php` or any
other site-held secret, and there is no key escrow — lose the passphrase
and the archive is unrecoverable by design.

An **unencrypted** archive — the common case today — is still
hash-verified for integrity (a modified byte is detected on read) but is
plaintext: it contains the whole database, including password hashes and
secret keys, and every uploaded file, in the clear. Anyone who can read
the archive file can read its contents; this is why
[PRIVACY.md](./PRIVACY.md) tells operators to treat every archive,
encrypted or not, as highly sensitive and store it outside the web root.

**Mitigations in design (encrypted archives):**

- AES-256-GCM per entry: corruption is *bounded* to a single entry
  rather than cascading through the whole archive. A flipped bit
  produces one decryption failure, not a chain of garbage output
- Argon2id KDF with strong, format-locked cost parameters (see
  [archive-format.md §8.1](./archive-format.md#81-key-derivation)
  for the exact figures) — deliberately slow and memory-hungry, so
  brute-forcing a weak passphrase is expensive even when the passphrase
  itself is not
- A fresh salt per archive and a use-once `EncryptionContext`, so the
  same derived key is never used to encrypt two archives — the failure
  mode a reused key/nonce pair would create for AES-GCM is catastrophic,
  not merely a weakened cipher

**Mitigations in design (both encrypted and unencrypted archives):**

- The rollback safety archive (ADR 0005) and any scheduled/local backup
  are written to `wp-content/pontifex/…`, created not world-readable
  (directory mode 0700, files 0600), so filesystem permissions are the
  first line of defence for an archive an operator has not chosen to
  encrypt
- Retention pruning (the safety-archive floor of 2, and a schedule's
  configured retention) bounds how many of these archives accumulate on
  disk; there is no general disk-quota enforcement beyond these pruning
  policies

**CVE priority:** CVEs in `ext-sodium` or libsodium itself are P0.

## 4. Backup download and delete

The Backup screen's download and delete actions run over
`admin-ajax.php` (`wp_ajax_pontifex_download_backup`,
`wp_ajax_pontifex_delete_backup`), gated by a `current_user_can(
'manage_options' )` capability check plus a WordPress nonce
(`check_ajax_referer()`, action `pontifex_backup`) — WordPress core's own
mechanism, the same one Section 5 covers, not a bespoke signed-URL or
token scheme. The requested filename is resolved through
`BackupStore::resolve()` before anything is read or deleted; a name that
does not match a real file already inside the protected backups directory
is refused outright, so the download and delete actions themselves are
never reachable by a public or unauthenticated request, and a filename
cannot be used to escape that directory.

That gate covers the ajax actions, not a direct web request for the
archive file itself. The backups directory is additionally guarded by a
deny-all `.htaccess` and an `index.php`, checked and repaired every time
the directory is ensured (`ProtectedDirectory`) — repair only replaces a
file that is a truncated or empty write Pontifex made itself, never one
it did not write. It remains an Apache-only mechanism: it does nothing on
nginx, and every write is silently swallowed on failure, so it cannot be
relied on. A backup's filename is a predictable
UTC timestamp (`pontifex-backup-<UTC>.wpmig`), so on nginx, or on any
host where those guard writes failed, an unauthenticated request for
that filename under `wp-content/pontifex/backups/` can reach the
archive — which contains the whole database — directly, bypassing the
ajax handler and its nonce and capability check entirely. An operator on
such a host must deny access to `wp-content/pontifex/` in the server
configuration.

**CVE priority:** a CVE in WordPress core's nonce verification or
capability system reaches this surface directly, and is propagated the
same way as Section 5.

## 5. Admin UI form handling

Every wp-admin form requires nonce + capability checks. A CVE in
WordPress core's nonce system affects us; a CVE in admin-ajax
handling does too.

**CVE priority:** WordPress core CVEs are propagated by WordPress
itself; we just need to be prompt about supporting the version that
includes the fix.

## 6. Development dependencies

PHPUnit, PHPStan, PHPCS, and their transitive deps. A CVE here is
annoying but does not ship to users.

**CVE priority:** P2/P3. Patch when convenient, don't lose sleep.

---

## What this model does not cover

The plugin threat model assumes a baseline trust environment and
treats certain attacks as outside its scope. These are not
oversights — they are intentional limits on what the plugin can
defend against, and stating them explicitly keeps the model honest.

- **Administrators acting in bad faith with valid credentials.** The
  threat model is "admin made a mistake or was phished," not "the
  admin is the attacker." A WordPress administrator with valid
  credentials can already do anything Pontifex can do, without
  Pontifex needing to be exploited. Defending against this case is
  not a plugin-level concern.
- **WordPress core or PHP runtime compromise.** If the underlying
  WordPress installation or PHP itself has been compromised, no
  amount of plugin-side defence helps. Propagating upstream CVE
  fixes is our part of the contract; the rest is outside our reach.
- **Hosting-provider compromise.** A host with filesystem access can
  read `wp-config.php` (and therefore the site's own database
  credentials and authentication salts), read an Ed25519 signing key an
  operator has chosen to store on that same host, modify the plugin's
  code, or replace archive files. An *unencrypted* archive — the
  default, see Section 3 — offers such an attacker nothing further to
  break: its contents are already plaintext. Operators
  concerned about this case should rely on signed archives
  ([archive-format.md §11](./archive-format.md#11-optional-detached-signature))
  and verify signatures against a key not stored on the host — but
  should know exactly how far that goes today, because it is not yet
  everywhere. Signatures are enforced by `wp pontifex import` and
  `wp pontifex verify` against a key given with `--public-key` or
  pinned as `PONTIFEX_PUBLIC_KEY`, and in the browser on the upload
  path, where an archive arriving from another server is refused
  unless it is signed by the pinned key
  ([ADR 0020](./adr/0020-signature-enforcement-on-the-upload-path.md)).
  Two gaps remain, and both need the same attacker — one who can
  already write files on the host. An archive placed **directly** in
  `wp-content/pontifex/backups` never passes through the upload path,
  so nothing checks its signature; and an archive checked at upload is
  checked only *then*, so one modified afterwards restores on the
  strength of unkeyed hashes the same attacker can recompute. Neither
  gap can be closed by verifying later: once stored, an uploaded
  archive is indistinguishable from one this site produced, and the
  only origin claim it carries is written by whoever wrote the
  archive. An operator who wants a signature checked against bytes
  they are about to restore should run `wp pontifex verify
  --public-key` immediately beforehand.
- **Side-channel attacks against the PHP implementation.** Timing,
  cache, and similar side channels that leak information through
  non-functional behaviour of the runtime. PHP is not designed for
  constant-time cryptographic operations and we do not attempt to
  make it so.
- **Loss of the encryption passphrase.** Encryption is opt-in and
  passphrase-based (Section 3); there is no key escrow and no recovery
  path. Lose the passphrase and that archive is unrecoverable by design
  — a deliberate choice, not a gap, because a recovery mechanism is
  itself attack surface.
- **A restore that fails part-way through the file walk.** The database
  half of a restore is atomic: every `db_chunk` replays into staging
  tables, and the live tables are switched over with one atomic RENAME
  only after the whole archive has been walked successfully (ADR 0009),
  so a failure never reaches the live database. The file half is not:
  `RestoreRunner`'s entry walk has no per-entry recovery, so a failure on
  (for example) file entry 12,000 of a 47,000-entry archive leaves those
  12,000 already overwritten and the remaining old files untouched — a
  merged tree that is neither wholly the old site nor wholly the
  archive's. This is why every preflight this document describes
  (symlink confinement, the symlink-capability probe, the free-space
  check) is designed to run and refuse *before* the walk starts rather
  than mid-walk: it is the only way to avoid this outcome, not a
  guarantee against every possible mid-walk failure (a full disk despite
  the preflight, a host restriction the preflight did not anticipate, a
  hard kill of the PHP process). The default pre-import safety archive
  (ADR 0005) is the recovery path for a restore that fails this way, not
  an in-flight undo — and it is itself a merge, not a reversal: replaying
  it puts back what it captured but removes nothing the failed restore
  wrote that the site never had, so a site must be inspected after a
  failed restore rather than assumed restored.
- **Denial of service against the running plugin.** An administrator
  can disable a misbehaving plugin from wp-admin; a non-admin should
  not have reach into the plugin's endpoints at all. DoS-grade bugs
  are bugs to fix, but not security failures of the model.

---

This document evolves as the codebase does. Security-relevant pull
requests should reference the surface(s) they touch and any new
mitigations or risks they introduce.
