# Technical reference

For developers, sysadmins and agency engineers. For a step-by-step
introduction aimed at site owners, see [Using Pontifex](guide.md). For failure
behaviour, see [When Pontifex refuses](when-pontifex-refuses.md).

Applies to Pontifex 1.1.0. The public API was frozen at v1.0.0: a
breaking change requires a major version bump.

---

## 1. Architecture

Pontifex is a WordPress plugin whose engine deliberately knows nothing about
WordPress. Four seam interfaces separate the two:

| Seam | Purpose |
|---|---|
| `WordPressContext` | Everything the engine needs from WordPress — paths, options, the database handle, filters. Keeps global functions out of the archive layer and makes the engine testable without loading WordPress. |
| `Environment` | Questions about the PHP runtime — version, extensions, `ini` values, free disk space, whether a function exists. |
| `ProgressReporter` | Progress emission, so the same engine drives a WP-CLI progress bar and an admin AJAX poller without knowing which. |
| PSR-3 logger | Hand-rolled, to avoid a dependency running inside other people's sites. |

`src/` layout:

| Namespace | Owns |
|---|---|
| `Archive/` | The `.wpmig` format: readers, writers, integrity hashing, codecs, crypto |
| `Manifest/` | Scanning the filesystem and database into an entry plan |
| `Export/` | Driving a backup, one-shot and resumable |
| `Restore/` | Driving a restore: `FileWriter`, `DatabaseWriter`, `RestoreRunner` |
| `Rollback/` | Safety archives and their retention |
| `Migrate/` | Cross-URL rewriting, including serialised-data handling |
| `Destination/` | Offsite adapters. **The only namespace permitted to open a socket** — enforced by a test |
| `Job/`, `Schedule/` | Background execution and scheduled backups |
| `Lock/` | The single-operation lock |
| `Cli/`, `Admin/` | The two surfaces |
| `Database/`, `Environment/`, `Log/`, `WordPress/` | Supporting seams |

**No build step.** PSR-4 (`Pontifex\` → `src/`), plain admin CSS and JS.

---

## 2. Command-line surface

Every flag below is verified against the command's synopsis *and* its argument
parser.

### `wp pontifex export`

Creates a backup.

| Flag | Effect |
|---|---|
| `--output=<path>` | Destination file. Required, must be absolute. |
| `--yes` | Skip the confirmation prompt. |
| `--whole-site` | Include WordPress core as well as `wp-content`. |
| `--files-only` | Files only, no database. |
| `--db-only` | Database only, no files. |
| `--exclude=<patterns>` | Comma-separated glob patterns to omit. |
| `--exclude-table=<patterns>` | Comma-separated table patterns to omit. |
| `--exclude-file=<path>` | Read exclusion patterns from a file. |
| `--no-defaults` | Drop the curated default exclusions. |
| `--encrypt` | AES-256-GCM, prompting twice for a passphrase. |
| `--passphrase-stdin` | Read the passphrase from stdin. Implies `--encrypt`. |
| `--sign` | Ed25519-sign the archive. Requires `--signing-key`. |
| `--signing-key=<path>` | Secret key file from `keygen`. |
| `--resumable` | Start a resumable export. |
| `--resume` | Continue an interrupted resumable export. |
| `--destination=<name>` | Upload to a configured destination after writing. |

`--whole-site`, `--files-only` and `--db-only` are mutually exclusive.
`--resumable` and `--resume` cannot be combined, and neither works with
encryption — the derived key exists for one run and is never stored.

A refused or failed export prints a verdict rather than propagating the
exception: which of the three kinds of refusal it was (ADR 0022 — the archive
cannot be trusted, this host cannot comply, or the request needs correcting),
the engine's own message with absolute paths redacted, and then exit 1. It also
says whether there is now a backup, because the answer is not always no: a
one-shot export leaves nothing at the output path (the archive is written to a
temp sibling and moved into place only when complete, so any file still there
is an earlier one), and an archive that was completed before a later step
failed is still a usable backup.

A **failed** resumable export cannot be continued with `--resume`. A tick that
raises marks its job `failed`, that status is terminal, and `--resume` accepts
only an active job — so `--resume` is for a run that was *interrupted* (killed,
timed out, disconnected), never for one that failed. The verdict says so, and
names the orphaned `.part` file if the run left one, which it establishes by
looking rather than by assuming.

### `wp pontifex import <archive>`

Restores an archive over the current site.

| Flag | Effect |
|---|---|
| `--yes` | Skip the confirmation prompt. |
| `--dry-run` | Validate without writing. Skips the lock and the safety archive. |
| `--new-url=<new-url>` | Rewrite the site address during restore. |
| `--whole-site` | Permit writing outside `wp-content`, including core and `wp-config.php`. |
| `--allow-unsafe-symlinks` | Disable symlink target confinement. |
| `--no-rollback-archive` | Skip the pre-restore safety archive — **and the automatic recovery, and any future rollback**. |
| `--passphrase-stdin` | Read the decryption passphrase from stdin. |
| `--public-key=<path>` | Require a valid signature from this key. |

`--dry-run` is a full rehearsal: it runs every gate and every preflight a real
restore runs — signature, scope, host symlink capability, symlink target
confinement, free space — and writes nothing. The host capability probe creates
and removes one test symbolic link, which is the only thing a dry run touches
and the only check `verify` cannot perform. See
[ADR 0023](adr/0023-verify-and-restorability.md).

A refused or failed import prints a verdict rather than propagating the
exception: which of the three kinds of refusal it was (ADR 0022 — the archive
cannot be trusted, this host cannot comply, or the request needs correcting),
the engine's own message with absolute paths redacted, and then exit 1. A
failure carrying none of those types is reported as a failure rather than a
refusal, because claiming a refusal would assert an intent that was not there.

### `wp pontifex verify <archive>`

| Flag | Effect |
|---|---|
| `--format=<format>` | Output format. |
| `--list` | List entries. |
| `--passphrase-stdin` | Read a passphrase from stdin. |
| `--public-key=<path>` | Require a valid signature from this key. |

Verify checks structure, the entry-count ceiling, and per-entry hashes, then
runs every restore preflight that writes nothing: scope-versus-manifest,
symlink target confinement, and free space. Four outcomes, distinguished by
exit code and wording:

| Outcome | Exit | Meaning |
|---|---|---|
| Sound | 0 | Undamaged, and a restore would accept it. |
| Refused | 1 | Undamaged, but a restore will not accept it — an escaping symlink, or contents contradicting the recorded scope. |
| Broken | 1 | A hash mismatch, malformed structure, or a defensive-limit breach. |
| Could not check | 2 | This host stopped the check before it reached a verdict — commonly too little memory. Not a statement about the archive either way. |

A finding against the **host** — no free space — is reported as a warning
alongside a sound verdict, never as the verdict: a full disk is not a damaged
backup. That is the shape for a host problem discovered *after* verification
has already reached a sound conclusion. A host problem that instead stops the
check before it reaches any conclusion — too little memory to hold a `db_chunk`
it must decode whole is the case seen in practice, and WordPress's own default
`memory_limit` is 40 MB — is not folded into that warning, and it is not
reported as broken either: nothing was learned about the archive one way or
the other, so it is its own outcome, **could not check**, exit code 2. A
script gating on this command should treat 2 as "unknown", not as "bad"
alongside exit 1. The host symlink-capability probe is not run, because
establishing it requires creating a link; use `doctor`, or `import --dry-run`
for a specific archive. Verify does not decode payloads, so it still does not
test a passphrase.

Because confinement is resolved against this site's own root, a verification is
a statement about an archive **and** a destination. See
[ADR 0023](adr/0023-verify-and-restorability.md) and
[section 5](#5-the-restore-pipeline).

### `wp pontifex rollback`

Replays the most recent safety archive. Flags: `--yes`, `--dry-run`.

A refused or failed rollback prints the same three-way verdict, redacted, and
exits 1. Where import's advice for an untrustworthy archive is to fetch a fresh
copy of the backup, rollback's cannot be: a safety archive is written
automatically, in one copy, at the moment of the import it undoes, so there is
none to fetch — restore a backup you took yourself instead.

A real rollback that stops partway also says so, and how many entries it had
restored. Nothing reconciles a half-replayed site automatically, so this is the
one failure whose verdict describes the site rather than the archive. It is
said only when entries actually landed; a preflight refusal that wrote nothing
does not raise it.

### `wp pontifex keygen`

Generates an Ed25519 keypair. Flags: `--secret-key=<path>`,
`--public-key=<path>`.

### `wp pontifex schedule <set|show|off>`

| Flag | Effect |
|---|---|
| `--frequency=<daily\|weekly>` | Required for `set`. |
| `--hour=<0-23>` | Required for `set`. **UTC**, not site time. |
| `--retention=<count>` | How many scheduled backups to keep. Refused below 1. |
| `--exclude=<patterns>` | Exclusions the scheduled backup inherits. |

### `wp pontifex destination <add\|remove\|list\|test\|archives\|pull\|prune> [name]`

| Flag | Effect |
|---|---|
| `--type=<type>` | `sftp` only. |
| `--host=<host>`, `--port=<port>` | Server address. |
| `--username=<username>` | SFTP user. |
| `--auth=<auth>` | Authentication mode. |
| `--secret-env=<name>` | **Name** of the environment variable holding the credential. |
| `--key-path=<path>` | Private key file. |
| `--host-key=<fingerprint>` | Pinned host-key fingerprint. |
| `--insecure-host-key` | Accept any host key. Disables MITM protection. |
| `--remote-path=<path>` | Remote directory. |
| `--retention=<count>` | Remote keep-count. `0` keeps everything. |
| `--output=<path>` | For `pull`: where to write the fetched archive. |

Credentials are never passed as a flag value — only the environment variable's
name is. `test`, `archives`, `pull` and `prune` open a connection; `list` and
`doctor` do not.

`prune` orders archives oldest-first by their real modification time at the
destination, never by name — a name is self-reported data (a killed upload can
leave a partial file under the canonical name; a hand-set clock can mint a
future-dated one) and neither is trustworthy as a proxy for age. A future
modification time is treated as the oldest thing at the destination and
pruned first; a modification time the destination did not report at all is
treated as current, so it is never mistaken for the oldest. Only names
matching Pontifex's own generated shape (`pontifex-backup-<UTC>.wpmig`) are
ever counted or deleted; anything else sharing the directory is left alone.
A prune that could not delete everything it needed to reports the failure —
via `WP_CLI::error()` for `wp pontifex destination prune`, or a warning after
export's own upload — rather than a false "nothing was pruned".

### `wp pontifex doctor` / `stats` / `diagnostics`

`doctor` reports environment readiness (no flags). `stats` takes `--format`.
`diagnostics` takes `--output` and produces a redacted support bundle.

---

## 3. Archive format

[`archive-format.md`](archive-format.md) is normative. Summary:

```
[header][entry][entry]…[provenance][manifest][footer][signature?]
```

Each entry carries its own header, a SHA-256 of its payload, and its
compressed payload. The manifest indexes every entry by byte offset, so a
reader can seek directly to one entry without walking the file. The footer
records the manifest's offset, length and hash. Provenance records where the
archive came from — source URL, table prefix, scope, WordPress and PHP
versions.

**Locked at specification version 1.1.** A v1.1 archive will remain readable
by every future Pontifex. A change the specification cannot accommodate
requires a new major specification version, never a silent revision of v1. A
reader refuses a major version above its own rather than guessing.

---

## 4. The export pipeline

1. **Scan.** `FileScanner` walks the tree; `ExclusionRules` prunes it during
   traversal. `DatabaseScanner` lists tables and splits them into chunks sized
   by average row length, targeting ~4 MiB per chunk.
2. **Plan.** `ManifestBuilder` produces the entry plan. Files first, then
   database chunks — the order matters at restore time.
3. **Refuse early.** If the projected manifest would exceed 16 MiB, the export
   is refused before the destination is opened. Pontifex will not write a
   backup it could not read back.
4. **Write.** Entries stream through a hashing stream into the codec (zstd if
   available, gzip otherwise) and, if encrypting, through AES-256-GCM. Memory
   stays bounded regardless of file size.
5. **Finalise.** Provenance, manifest, footer, optional signature. Written to
   a temporary sibling and renamed into place, so an interrupted export never
   leaves a half-file at the target path.

**Exclusion precedence:** curated defaults (`wp-content/pontifex`,
`wp-content/cache`, `.git` at any depth) unless `--no-defaults`, then user
patterns, then scope flags.

**Resumable exports** (ADR 0015) persist an entry cursor and the archive's
byte position after each tick, and verify the partial file's length matches
what was claimed before appending. If the scan's *shape* changes between ticks
— a file added or removed earlier in scan order — the export refuses rather
than producing a misaligned archive. Content changes are tolerated: the writer
records the bytes it actually read and corrects the header (ADR 0013).

---

## 5. The restore pipeline

Order is the important part. Everything above the line is a preflight:

```
open archive (header, footer, manifest, provenance, signature)
assert scope consistent with manifest
resolve every declared symlink target
assert host can create symlinks where they will land
assert sufficient free disk space
──────────────────────────── nothing written above this line ────────────────
begin database staging
walk entries:  files → FileWriter (LIVE)   db chunks → staging tables
finalise prefix rewrite
commit staged tables (atomic RENAME)
```

**The database is transactional in effect.** Every table is built as
`pontifexstg_*` and swapped in one atomic rename (ADR 0009). A failure at any
point before the swap leaves live tables untouched, and `abort_staging()`
drops the staging tables.

**The file half has no equivalent.** Files are written live. There is no
file-side rollback — a deliberate, recorded deferral. Two consequences:

- A failure mid-walk leaves files already written in place.
- A restore is a **merge**: it writes what the archive contains and removes
  nothing else. Files present on the destination but absent from the archive
  survive, including after an automatic safety-archive recovery.

**Because files are written before database chunks**, every `DatabaseWriter`
refusal fires after the file half has already been applied.

Each file is written to a temporary sibling and renamed, so an interrupted
single file never leaves a partial at the target path, and read-only targets
are replaceable.

**Database chunk containment** (ADR 0019): every statement must begin, byte
for byte, with one of three shapes composed from the staging identifier the
restore built itself. A semicolon outside a quoted span is refused. After a
`CREATE`, the catalogue is asked what was actually built. Two read channels
inside a matched `INSERT`'s `VALUES` — a scalar subquery and `LOAD_FILE()` —
remain open by design and are documented rather than hidden.

---

## 6. Integrity, encryption and signing

| Mechanism | Covers | Does not cover |
|---|---|---|
| Per-entry SHA-256 | Accidental corruption of any entry | Deliberate tampering |
| Manifest hash (in footer and embedded) | Index tampering or truncation | — |
| AES-256-GCM, Argon2id-derived key | Confidentiality and authenticity of payloads | Anything if the passphrase is lost |
| Ed25519 signature | Provenance and deliberate tampering | Confidentiality |

Hashes detect damage, not attack: anyone who alters content can recompute
them. Only the signature distinguishes the two.

**Argon2id parameters are format invariants**, not tunables. Changing them
derives a different key and makes existing archives permanently undecryptable.

**Signature enforcement is two-tier**, and the tiers differ:

- **CLI** (ADR 0012, breaking in v0.5.0): supplying `--public-key` to `import`
  or `verify`, or pinning `PONTIFEX_PUBLIC_KEY`, makes the signature
  mandatory. An unsigned archive is refused, not warned about — a stripped
  signature is byte-for-byte indistinguishable from never-signed.
- **Browser** (ADR 0020): the pin alone is the trigger, applied at **upload**,
  the one point where an archive of unknown origin crosses into the site. Once
  a file is on disk, an uploaded and a locally-produced backup are
  indistinguishable, so admin Restore, Verify and rollback deliberately do not
  check signatures.

---

## 7. Concurrency

`Pontifex\Lock\OperationLock` serialises backup, restore and rollback across
both surfaces. Verify is read-only and excluded.

Three layers: a MySQL named lock (dies with the connection, so it can never
need manual clearing), a liveness check against the active job and progress
transient, and a holder transient with a 900-second TTL.

**Reclamation is deliberately asymmetric.** A stalled *backup* holder is
reclaimed automatically. A stalled *restore* or *rollback* is not, and waits
out the full TTL — a possibly half-restored site is exactly when a second
writer must not start.

One narrow exemption: `export --resume` may adopt the lock held by the job it
is resuming, conditioned on an active export job existing. A lock that
protects an operation must not lock out that operation's own recovery.

---

## 8. Scheduling and background execution

ADR 0014: WP-Cron, no queue library. A backup started from the browser runs as
a persisted job, so closing the tab does not kill it and reloading reattaches
to its progress.

`JobTicker` schedules its successor **before** doing work and clears it only
when the job is decided, so a fatal cannot orphan the chain. An unclean-attempt
counter (ceiling 8, reset on clean handover) fails a permanently stalled job
rather than crash-looping.

Scheduled hours are UTC. `doctor` reports whether a real system cron is
configured, which is more reliable than WP-Cron on a low-traffic site.

---

## 9. Offsite destinations

ADR 0017. SFTP only; an S3 adapter was considered and dropped.

Host keys are pinned and verified **before** authentication, so credentials
never reach an unverified server. Comparison is constant-time. Credentials
come from a named environment variable, never a flag value.

An upload writes to a temporary remote name first (`<archive>.wpmig.part`)
and renames it into place only once the transfer has completed and the
destination's own reported size matches the local file. A killed or
part-failed upload therefore never leaves a fragment under the archive's real
name — the temporary name does not match `.wpmig` at all, so listing and
retention never see it as a backup in the first place. If a file already sits
at the final name (re-running an export to the same `--output`, or two sites
sharing one destination), it is removed immediately before the rename that
replaces it — never earlier, and only once the new upload is verified — so
the destination is briefly without a file for one rename rather than holding
a part-written one for the whole transfer.

Retention keeps the newest N with a floor that never prunes to nothing,
ordered by each archive's real modification time at the destination rather
than its name (see the `prune` action above for the full ordering rule).
`doctor` grades every destination without connecting.

The architecture test `NoNetworkOutsideDestinationTest` fails the build if any
network primitive appears outside `src/Destination/`.

---

## 10. Limits

| Limit | Value | Configurable? |
|---|---|---|
| Max entries per archive | 100,000 | **No** |
| Max manifest payload | 16 MiB | **No** — a format invariant |
| Max entry payload header | 16 KiB | **No** |
| Max provenance payload | 64 KiB | **No** |
| Max decoded bytes per restore | min(100 × archive size, 1 TiB) | **No** |
| Max single entry | 2 GiB | **No** |
| Symlink resolution hops | 40 | **No** |
| Staging table name length | 64 | **No** (MySQL's limit) |
| Operation lock TTL | 900 s | **No** |
| Job unclean-attempt ceiling | 8 | **No** |
| Safety-archive retention floor | 2 | **No** |
| Argon2id ops / memory | 4 / 64 MiB | **No** — format invariant |

Nothing in the plugin constructs `ArchiveLimits` with custom values — all
production call sites pass `null` and get the defaults. **"Raise the limit" is
never available for an archive limit.** The only user-tunable numbers are the
two retention keep-counts, exclusion patterns, and PHP's own `memory_limit`.

---

## 11. Extension points

Deliberately almost none.

- **One filter:** `pontifex_serialized_classes`, which opts specific classes
  into serialised-data rewriting during a URL migration. Hardened against
  widening — a non-array return yields an empty array.
- **One behaviour constant:** `PONTIFEX_PUBLIC_KEY`, pinning a trusted signing
  key.

There are no other filters or actions. This is a choice: every extension point
is a supported interface, and code that runs inside other people's live sites
should present the smallest surface that does the job.

---

## 12. Testing

```
composer lint        # PHPCS (WordPress Coding Standards)
composer analyse     # PHPStan level 6
composer test        # PHPUnit, unit suite
```

Integration tests need real MySQL and run inside the tests container:

```
npx @wordpress/env start --config .wp-env.tests.json
npx @wordpress/env run cli --env-cwd=wp-content/plugins/pontifex \
  composer test:integration --config .wp-env.tests.json
```

Unit tests mock WordPress, so a green unit run overstates safety for anything
touching the database — real `$wpdb` returns `false` on a failed query rather
than throwing. Counts are always reported as "N unit, M integration".

CI runs tiered gates (ADR 0007): every PR runs the fast gates plus the
real-MySQL integration suite on the PHP floor; `staging` and `main` add the
integration suite across PHP 8.2–8.5 and Plugin Check on the built package,
with a tag guard verifying the tag matches the plugin header and the runtime
constant.
