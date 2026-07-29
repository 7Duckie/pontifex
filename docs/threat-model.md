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
- Round-trip verification: re-serialise and compare; mismatch → keep
  original, log error
- Pre-import scan that surfaces transformed values for operator review
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
- Manifest size capped at 100MB
- Per-entry length checked against remaining file size
- FILE paths validated relative-and-within-wp-content (no traversal)
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
- Regex transforms with `pcre.jit=0` and a hard step limit to
  defeat regex denial-of-service (catastrophic backtracking on
  adversarial input)

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

Encrypted with a per-site key from `wp-config.php`. Anyone who can
read that file can decrypt every snapshot. This is the existing
WordPress threat model, not a regression — but it means snapshots are
not safe to copy off-server without re-encryption.

**Mitigations in design:**

- AES-256-GCM per entry: corruption is *bounded* to a single entry
  rather than cascading through the whole archive. A flipped bit
  produces one decryption failure, not a chain of garbage output
- Argon2id KDF with strong parameters (see
  [archive-format.md §8.1](./archive-format.md#81-key-derivation)
  for the exact cost parameters)
- Disk cap and retention window prevent unbounded accumulation

**CVE priority:** CVEs in `ext-sodium` or libsodium itself are P0.

## 4. Signed download URLs

HMAC-signed with a 1-hour expiry. HMAC (Hash-based Message
Authentication Code) is a cryptographic fingerprint computed using
a secret key — it lets the receiver verify both authenticity (only
someone with the key could have produced this signature) and
integrity (any modification to the signed bytes invalidates the
fingerprint). A CVE in the HMAC implementation would be
catastrophic: predictable signatures mean any archive can be
downloaded by anyone with the URL prefix.

**CVE priority:** CVEs in `ext-openssl`, `ext-hash`, or `hash_hmac`
itself are P0.

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
  read `wp-config.php` (and therefore the per-site encryption key),
  modify the plugin's code, or replace archive files. Operators
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
- **Loss of the per-site encryption key from `wp-config.php`.** If
  the file is destroyed without backup, every snapshot encrypted
  with that key is unrecoverable by design. There is no key escrow.
- **Denial of service against the running plugin.** An administrator
  can disable a misbehaving plugin from wp-admin; a non-admin should
  not have reach into the plugin's endpoints at all. DoS-grade bugs
  are bugs to fix, but not security failures of the model.

---

This document evolves as the codebase does. Security-relevant pull
requests should reference the surface(s) they touch and any new
mitigations or risks they introduce.
