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
Cross-domain migration (URL rewriting) is explicitly v0.2.0's job, for
the reasons recorded in [ADR 0004](./adr/0004-same-url-import-scope.md).

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

- **URL rewriting / cross-domain migration** → v0.2.0, shipped
  together with its serialised-data defences (ADR 0004). v0.1.0
  restores to the same URL only; naive search-replace over
  PHP-serialised data is the highest-blast-radius operation in the
  threat model and must not ship without its guards.
- **Encryption** (codecs `0x0100`, `0x0101`, `0x0102`; Argon2id KDF;
  AES-256-GCM cipher) → v0.2.0. The format spec's "encrypt by default"
  promise is a property of v1.0 of the *format*; the *plugin* delivers
  it in stages. v0.1.0 archives are unencrypted, and the release notes
  call this out explicitly: archives deposited into untrusted storage
  are not protected by encryption.
- **Zstd codec** (`0x0002`) → v0.2.0. The PHP `ext-zstd` extension is
  not universally available; gzip handles compression for v0.1.0.
- **Ed25519 signatures** → v0.2.0, alongside the rest of the
  cryptographic dependency profile.
- **Rolling transfer history and the admin metrics tile** (idea-bank
  Idea 002, beyond the minimum counters above) → v0.2.0.
- **Diagnostics bundle and per-transfer log files** (idea-bank Idea
  003, beyond the minimum logger above) → v0.2.0. v0.1.0 keeps the
  logger lean: a single rotating file, no bundling.
- **`wp pontifex stats` CLI command** → v0.2.0–v0.3.0.
- **`wp pontifex diagnostics` CLI command** → v0.2.0–v0.3.0.

## v0.2.0 — Migration, encryption and observability

The second release adds cross-domain migration and the security and
operability properties that the format reserves space for but v0.1.0
does not yet implement.

### What ships

- **Cross-URL migration.** Search-replace and URL rewriting that
  preserves PHP-serialised integrity, shipped with allowlist-disabled
  unserialize (`allowed_classes => false`), round-trip verification of
  rewritten values, and a pre-import scan (ADR 0004). Each defence is
  proven by tests before the feature is callable.
- **Rollback** (idea-bank Idea 009). An automatic pre-import safety
  archive of the destination site, with `wp pontifex rollback` to
  restore the most recent one — the undo button for a destructive
  import.
- **Archive verification** (idea-bank Idea 010). `wp pontifex verify
  <archive>` opens an archive, walks every entry, checks every hash,
  writes nothing, and exits with a clear verdict — answerable against
  cold storage with no destination site involved.
- **Encryption.** Argon2id-derived keys with the parameters in
  [`archive-format.md §8.1`](./archive-format.md#81-key-derivation);
  AES-256-GCM per-entry encryption; codecs `0x0100`, `0x0101`, `0x0102`
  available in the codec registry; `--passphrase` flag on export and
  import (idea-bank Idea 004).
- **Zstd compression.** Codec `0x0002` when `ext-zstd` is available,
  with graceful fallback to gzip when absent.
- **Optional Ed25519 signatures.** Detached signature appended after
  the footer; verification at import when a trusted public key is
  supplied.
- **Full structured logging.** The minimum v0.1.0 logger grows a
  diagnostics bundle and per-transfer log files alongside each
  archive.
- **Full transfer metrics.** The minimum v0.1.0 counters gain a
  rolling history of recent transfers, all local, all
  user-controllable.
- **`wp pontifex stats`** — local activity readout, `--json` flag for
  bug-report attachment.
- **`wp pontifex diagnostics`** — sanitised diagnostic bundle command
  for support workflows. Generates a tar.gz that bundles recent logs,
  `doctor` output, `stats` output, and an environment summary; never
  auto-uploads; the operator decides what to share.

## v0.3.0 and beyond — Admin UI and operational maturity

Once the CLI is solid, the admin UI work begins. This is also where
the longer-running operational features land.

### What ships, in roughly this order

- **Admin UI** for non-CLI users, following the Swiss-design language
  documented in [`../.github/CONTRIBUTING.md`](../.github/CONTRIBUTING.md#design-language).
  Promotion of design-language guidance to a dedicated
  `design-language.md` once enough vocabulary exists to populate it.
- **Resumable exports** (idea-bank Idea 006), backed by Action
  Scheduler so exports survive PHP timeouts and lost SSH sessions.
- **Scheduled exports** — periodic backups via Action Scheduler.
- **Push/pull transports** — direct host-to-host transfer without
  needing an intermediate file on either side.
- **Selective content** — export-without-database, single-table
  exports, content-type filters.
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
