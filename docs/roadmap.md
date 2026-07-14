# Pontifex Roadmap

This document is the public commitment of what Pontifex ships and when.
It is forward-looking; for what has already shipped, see
[`../CHANGELOG.md`](../CHANGELOG.md).

The roadmap is the result of explicit scope-locking before each release.
Items deferred from a release are listed with the reasoning, so they do
not drift silently into a later release without a fresh decision. For
ideas still under evaluation — not yet committed to any release — see
[`idea-bank.md`](./idea-bank.md).

## Release principles

A few principles shape every release:

- **Round-trip first.** A feature is not shipped until both halves
  exist: anything that writes must be paired with something that
  reads. This is the strongest correctness check the format can have.
- **Public API stability across commits within a release.** Once a
  release begins, public APIs (constructors, method signatures,
  return types) are designed upfront to their final shape and stay
  stable across the build. Internal implementation details remain
  rewritable. This is stricter than the typical pre-v1.0 project; it
  suits an audience that will care about stability the moment v0.1.0
  ships.
- **Defaults exist, defaults are visible, defaults are overridable.**
  Every default behaviour can be justified to a user who asks, is
  surfaced at runtime before any action, and has a documented
  override path.
- **Cryptographic invariants are boring.** SHA-256, AES-256-GCM,
  Argon2id, Ed25519 — current mainstream recommendations, not novel
  choices. The format's security is in its conservative primitives,
  not in clever ones.

## v0.1.0 — Round-trip baseline (same URL)

The headline of v0.1.0 is a complete round trip: a user can export a
real WordPress site to a `.wpmig` archive, take that archive to a
different machine, and import it onto a fresh WordPress installation
**at the same URL**, ending up with a site that matches the source.
Cross-domain migration (URL rewriting) is explicitly v0.3.0's job, for
the reasons recorded in [ADR 0004](./adr/0004-same-url-import-scope.md)
and [ADR 0006](./adr/0006-cross-url-via-post-restore-search-replace.md).

### What ships

- `wp pontifex export <path>` — produces a `.wpmig` archive from the
  current site.
- `wp pontifex import <path>` — restores a `.wpmig` archive onto a
  WordPress at the **same URL**. No URL rewriting in v0.1.0
  (ADR 0004); this is a backup-and-restore baseline, and the command
  states the scope plainly in its output.
- `ArchiveWriter` and `ArchiveReader` libraries conforming to the v1
  byte layout in [`archive-format.md`](./archive-format.md).
- Codecs `0x0000` (no compression, no encryption) and `0x0001` (gzip,
  no encryption).
- All four entry kinds: `file`, `db_chunk`, `directory`, `symlink`.
- The full integrity contract for unencrypted archives: per-entry
  SHA-256, manifest hash, footer manifest hash, mandatory verification
  order on read.
- Round-trip tests proving write-then-read reconstructs the source
  byte-perfectly.
- WordPress integration tests running in CI across PHP 8.2–8.5 (the
  supported floor through current), against a current WordPress.
- **Minimum diagnostic logger** (idea-bank Idea 003, minimum slice):
  a hand-rolled PSR-3 file logger writing to
  `wp-content/pontifex/logs/`, so import failures — the most dangerous
  failures the plugin produces — are diagnosable from the first
  release that can import. Lifted ahead of Phase 5 deliberately; the
  fuller diagnostics work stays in v0.2.0 (see deferred list).
- **Persistent transfer counters** (idea-bank Idea 002, minimum
  slice): attempted / succeeded / failed / total bytes stored as
  `wp_options` entries, incremented by export and import.
- **Export progress indicator** (idea-bank Idea 005): WP-CLI's
  progress-bar utility driven by a per-entry callback on
  ArchiveWriter, so a long export does not present as a frozen
  terminal. Import inherits the same callback hook.

### What is deliberately deferred

- **URL rewriting / cross-domain migration** → v0.3.0, shipped
  together with its serialised-data defences (ADR 0004). v0.1.0
  restores to the same URL only; naive search-replace over
  PHP-serialised data is the highest-blast-radius operation in the
  threat model and must not ship without its guards.
- **Encryption** (codecs `0x0100`, `0x0101`, `0x0102`; Argon2id KDF;
  AES-256-GCM cipher) → v0.3.0. The format spec's "encrypt by default"
  promise is a property of v1.0 of the *format*; the *plugin* delivers
  it in stages. v0.1.0 archives are unencrypted, and the release notes
  call this out explicitly: archives deposited into untrusted storage
  are not protected by encryption.
- **Zstd codec** (`0x0002`) → v0.3.0. The PHP `ext-zstd` extension is
  not universally available; gzip handles compression for v0.1.0.
- **Ed25519 signatures** → v0.3.0, alongside the rest of the
  cryptographic dependency profile.
- **Rolling transfer history and the admin metrics tile** (idea-bank
  Idea 002, beyond the minimum counters above) → v0.3.0.
- **Diagnostics bundle and per-transfer log files** (idea-bank Idea
  003, beyond the minimum logger above) → v0.3.0. v0.1.0 keeps the
  logger lean: a single rotating file, no bundling.
- **`wp pontifex stats` CLI command** → v0.3.0.
- **`wp pontifex diagnostics` CLI command** → v0.3.0.

## v0.2.0 — Safety, verification and rollback

The second release hardens the round trip with the two safety features
that make a destructive import trustworthy: a way to check an archive
before trusting it, and a way to undo an import after running it. The
heavier migration and encryption work moves to v0.3.0, so these trust
features can ship quickly and on their own.

### What ships

- **Archive verification** (idea-bank Idea 010). `wp pontifex verify
  <archive>` opens an archive, walks every entry, checks every hash,
  writes nothing, and exits with a clear verdict — answerable against
  cold storage with no destination site involved. `--list` shows the
  contents, with `--format=json` for tooling.
- **Rollback** (idea-bank Idea 009,
  [ADR 0005](./adr/0005-rollback-safety-archive-policy.md)).
  `wp pontifex import` writes an automatic pre-import safety archive of
  the current site before it restores (`--no-rollback-archive` to skip),
  and `wp pontifex rollback` restores the most recent one — the undo
  button for a destructive import. Safety archives are owner-only, named
  by UTC timestamp, with the most recent retained.

Alongside the two features, v0.2.0 carries the repository-hardening and
open-source-health work done since v0.1.0: a protected `main`
(PR-gated, CI-required), the community-health files (code of conduct,
issue and pull-request templates, support guide), and a refreshed
dependency floor.

## v0.3.0 — Migration, encryption and signatures

Cross-domain migration and the cryptographic properties the format reserves
space for: encryption and signatures. This is the highest-blast-radius work in
the project, which is why it ships on its own, only with its defences. The
observability surface originally grouped here — fuller logging and metrics,
`stats`, `diagnostics` — **moved to v0.4.0** so this release could ship on the
cryptographic work alone; the release boundary was decided as the tag neared,
per the release principles above.

### What ships

- **Cross-URL migration.** Search-replace and URL rewriting that
  preserves PHP-serialised integrity, shipped with allowlist-disabled
  unserialize (`allowed_classes => false`), round-trip verification of
  rewritten values, and a pre-import scan (ADR 0004). Each defence is
  proven by tests before the feature is callable.
- **Encryption.** Argon2id-derived keys with the parameters in
  [`archive-format.md §8.1`](./archive-format.md#81-key-derivation);
  AES-256-GCM per-entry encryption; codecs `0x0100`, `0x0101`, `0x0102`
  available in the codec registry; `--encrypt` / `--passphrase-stdin` on export
  and a passphrase prompt on import and verify (idea-bank Idea 004).
- **Zstd compression.** Codec `0x0002` when `ext-zstd` is available,
  with graceful fallback to gzip when absent.
- **Optional Ed25519 signatures.** A detached signature — Ed25519 over a
  streamed SHA-256 prehash of the archive — appended after the footer, with
  `wp pontifex keygen`, `export --sign --signing-key`, and `--public-key`
  verification on `verify` and `import`.

## v0.4.0 — Observability

The release that makes a Pontifex site legible to its operator: what has been
transferred, whether it worked, and a self-contained record of each run — all
read locally, nothing uploaded. Moved from v0.3.0 so the cryptographic release
could ship on its own.

### What ships

- **`wp pontifex stats`** — a local readout of the export and import counters
  (attempted, succeeded, failed, bytes moved), with `--format=json` (and csv,
  yaml) for pasting into a bug report. Read-only, no network.
- **Rolling transfer history** — a short window of the most recent transfers
  (timestamp, kind, outcome, size; never any content), shown by `stats` beneath
  the totals.
- **`wp pontifex diagnostics`** — a sanitised support bundle (recent logs,
  `doctor` and `stats` output, and an environment summary) as a tar.gz; the site
  URL, absolute paths and sensitive option values are redacted, and it never
  auto-uploads.
- **Per-transfer log files** — beyond the central rotating `pontifex.log`, each
  transfer also writes a self-contained log: export's beside the archive
  (`<output>.wpmig.log`), import's as `import-<UTC>.log` in the log directory.

### What is deliberately deferred

The admin UI and the longer-running operational features move to v0.5.0 and
beyond (below) — v0.4.0 ships on the CLI observability surface alone, mirroring
the v0.3.0 boundary.

## v0.5.0 — Admin UI and the content-only default

Once the CLI was solid, the admin UI work began.

### What ships

- **Content-only by default** — a backup captures `wp-content` plus the
  whole database, the everyday working-WordPress-to-working-WordPress
  default; `--whole-site` opts into the entire installation (WordPress
  core and `wp-config.php`) for cloning onto a bare server (ADR 0008).
- **Admin UI** for non-CLI users — Overview, Backup (with live progress
  and cancel), Verify, and Restore/Rollback screens, plus chunked upload
  of a backup taken on another site — following the Swiss-design
  language documented in
  [`../.github/CONTRIBUTING.md`](../.github/CONTRIBUTING.md#design-language).

## v0.6.0 — Resumable and scheduled exports

The two operational features that stop a backup depending on one
uninterrupted request: an export that survives being killed, and an
export that runs unattended on a schedule. Both are built on the same
foundation — a persisted job with an append-only progress log — decided
in [ADR 0014](./adr/0014-background-execution-model.md) (WP-Cron plus a
self-continuing step runner behind a seam; no job-queue library) and
[ADR 0015](./adr/0015-resumable-export-mechanics.md) (the resume
contract: the progress log is the truth, every tick steps back to the
provably-good prefix, scan drift is refused, the database is dumped
whole in one snapshot tick, and encrypted exports refuse resumable mode
because the derived key is never persisted).

### What ships

- **Resumable exports** (idea-bank Idea 006) — `wp pontifex export
  --resumable`, continued after any interruption with `--resume`; the
  archive is built incrementally across as many processes as it takes
  and is byte-identical to a one-shot export.
- **Admin backups as jobs** — the Backup screen runs its export as a
  persisted job, so a reloaded (or closed and reopened) page re-attaches
  to the running backup instead of losing it, and an unattended cron
  tick continues a job whose driving request died.
- **Scheduled exports** — a recurring content-only backup (daily or
  weekly, at an hour given in UTC), with old scheduled backups pruned
  to a retention count floored at one so pruning can never delete the
  last backup.
- **The schedule surfaces** — `wp pontifex schedule set/show/off` and a
  Scheduled backups section on the Backup screen, both over one stored
  schedule whose save keeps the WP-Cron event in step; plus a WP-Cron
  reliability check in `wp pontifex doctor`.
- **Atomic file restores** — every restored file is written to a
  temporary sibling and atomically renamed into place, so a read-only
  destination file no longer aborts a restore partway.

### What is deliberately deferred

- **Push/pull transports**, **selective content**, and **multisite
  support** → below, unchanged in scope.
- **The PHP-floor raise** — revisit when PHP 8.2 reaches end of life
  (end of 2026).

## v0.7.0 — Selective content

Letting a backup be scoped and trimmed, on top of the content-only
default, with every partial archive honestly self-describing so the
round-trip guarantee is never quietly weakened.

### What ships

- **User exclusions everywhere** — inline `wp pontifex export --exclude`
  and `--exclude-table` beside the existing `--exclude-file`, and an
  editable exclusions field on the admin Backup screen, which now also
  shows the effective scope and the always-applied defaults before a
  backup runs. Scheduled backups inherit the operator's exclusions.
- **Files-only and database-only backups** — `export --files-only`
  (`wp-content`, no database) and `export --db-only` (the database, no
  files), each a partial content archive that restores as a merge and
  leaves the absent half of the destination untouched
  ([ADR 0016](./adr/0016-partial-scope-backups.md)). The `Scope` block
  gains an `includes_files` field, serialised only when false so ordinary
  archives stay byte-identical; a restore refuses an archive whose scope
  contradicts its contents.
- **Honest labelling** — `verify` (CLI and the admin Verify screen) reads
  the recorded scope and states what a sound backup actually contains, so
  a deliberately partial archive is never mistaken for a complete one.

### What is deliberately deferred

- **Push/pull transports**, **multisite support**, and single-table /
  content-type database filtering stay out — see below. Row-level or
  content-type pruning is refused for now because it risks a
  referentially broken restore.

## v0.8.0 — Offsite destinations

An offsite copy of a backup, written to storage the user already owns,
so a lost disk no longer means a lost backup — without Pontifex ever
running a service, holding anyone's data, or phoning home.

### What ships

- **User-owned destinations** — a finished `.wpmig` can be uploaded to
  the user's own **SFTP** server or **S3-compatible** bucket (S3,
  Backblaze B2, Wasabi, MinIO, DigitalOcean Spaces), configured as a
  named destination stored on the site. `wp pontifex export
  --destination=<name>` uploads after writing the local archive
  ([ADR 0017](./adr/0017-offsite-destination-adapters.md)).
- **Recoverable, not write-only** — `wp pontifex destination pull`
  fetches an archive back from a destination for recovery after local
  loss, alongside `destination test` (a live reachability check) and
  `destination list`.
- **Safe by default** — SFTP host keys are pinned (an unknown key is
  refused unless an insecure opt-in is set); S3 requires a TLS endpoint;
  credentials are referenced by environment-variable name, never passed
  as flags or stored in plaintext; and uploading an unencrypted archive
  warns before it leaves the server.
- **Per-destination retention** — each destination prunes its own remote
  copies to a retention count, with a floor that never prunes to nothing.
- **A destination health check** in `wp pontifex doctor` — configuration
  and credential presence, without touching the network.

### What is deliberately deferred

- **Scheduled/cron offsite upload** — a large upload cannot finish inside
  one web-cron tick, so unattended offsite waits for a cross-request
  chunked-resumable upload with its own ADR (a v0.8.x follow-up). v0.8.0
  uploads run in the CLI process, which has unlimited time.
- **Dropbox and Google Drive** — their OAuth token lifecycle and reviewed
  application are heavier; the no-OAuth SFTP and S3 adapters ship first.
- **An admin destination surface** — offsite is CLI-only in v0.8.0, as
  partial and whole-site scopes are (ADR 0008/0016); the admin screens
  may display destination status in a later slice.

## Beyond v0.7.0 — operational maturity

The longer-running operational features, not yet committed to a
numbered release:

- **Push/pull transports** — direct host-to-host transfer without
  needing an intermediate file on either side.
- **Multisite support** — packaging strategy for WordPress multisite
  networks, deferred from v0.1.0 because single-site needs to be
  solid first.

## v1.0.0 — Stable surface

v1.0.0 is the commitment release. From this point:

- The public API is frozen. Breaking changes require a major version
  bump.
- The plugin is submitted to the WordPress.org plugin directory,
  providing the "active installs" adoption signal (idea-bank Idea 001).
- Security updates follow standard semver-patch cadence, with the full
  triage protocol from [`../.github/SECURITY.md`](../.github/SECURITY.md).
- The format spec graduates from DRAFT to LOCKED at v1.0 of the spec
  itself (which is independent of the plugin's version number).

## v2.0 — Go reference reader

A standalone Go CLI implementing read, verify, list, and conversion
operations on `.wpmig` archives.

### Why

- **Independent verification.** "Can I trust this archive?" without
  needing a WordPress install on hand.
- **Recovery.** Emergency extraction when the PHP plugin is
  unavailable — for example, when the destination WordPress instance
  cannot be brought up enough to install Pontifex.
- **Conversion.** Export to `.tar` or `.zip` for inspection by
  general-purpose tools.
- **Specification correctness.** Two independent implementations
  producing byte-identical output from identical input is the
  strongest evidence the spec is unambiguous.

The Go implementation will be built strictly from
[`archive-format.md`](./archive-format.md), with no reference to the
PHP code. The test of a well-written specification is that someone
implementing from it produces compatible output.

## Items with no committed release

These are documented as ideas, not commitments. The full reasoning for
each is in [`idea-bank.md`](./idea-bank.md).

- **Opt-in plugin install telemetry** (idea-bank Idea 001) —
  deferred indefinitely. Reconsider only if WP.org install counts and
  GitHub release-download counts together prove insufficient for
  responsible-disclosure planning.
- **Full behavioural test coverage of CLI commands' `__invoke`**
  (idea-bank Idea 007) — best fit is a dedicated follow-up commit
  after v0.1.0 ships, possibly v0.1.1.
- **Remote opt-in error reporting (Sentry-style)** (idea-bank Idea 003) —
  deferred indefinitely. Reconsider post-v1.0 only if a real need
  emerges that the local diagnostic-bundle workflow cannot meet.
