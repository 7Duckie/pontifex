# Technical reference

For developers, sysadmins and agency engineers. For a step-by-step
introduction aimed at site owners, see [Using Pontifex](guide.md). For failure
behaviour, see [When Pontifex refuses](when-pontifex-refuses.md).

Applies to Pontifex 1.0.1. The public API was frozen at v1.0.0: a
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

### `wp pontifex import <archive>`

Restores an archive over the current site.

| Flag | Effect |
|---|---|
| `--yes` | Skip the confirmation prompt. |
| `--dry-run` | Validate without writing. Skips the lock and the safety archive. |
| `--url=<new-url>` | Rewrite the site address during restore. |
| `--whole-site` | Permit writing outside `wp-content`, including core and `wp-config.php`. |
| `--allow-unsafe-symlinks` | Disable symlink target confinement. |
| `--no-rollback-archive` | Skip the pre-restore safety archive — **and the automatic recovery, and any future rollback**. |
| `--passphrase-stdin` | Read the decryption passphrase from stdin. |
| `--public-key=<path>` | Require a valid signature from this key. |

`--dry-run` runs the signature and scope gates but **not** the disk-space or
symlink preflights, which live in the restore path. A clean dry run is not a
promise a real restore will proceed.

### `wp pontifex verify <archive>`

| Flag | Effect |
|---|---|
| `--format=<format>` | Output format. |
| `--list` | List entries. |
| `--passphrase-stdin` | Read a passphrase from stdin. |
| `--public-key=<path>` | Require a valid signature from this key. |

Verify checks structure, the entry-count ceiling, and per-entry hashes. It does
not decode payloads, so it does not test a passphrase and does not run the
restore-time preflights. See [section 5](#5-the-restore-pipeline).

### `wp pontifex rollback`

Replays the most recent safety archive. Flags: `--yes`, `--dry-run`.

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

Retention keeps the newest N with a floor that never prunes to nothing.
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
