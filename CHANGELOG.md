# Changelog

All notable changes to Pontifex are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Public API stability begins at v1.0.0. Pre-v1.0 releases (v0.x.y) may
introduce breaking changes between minor versions, though the project
holds itself to a stricter "public-API stability across commits within
a release" discipline — see
[ADR 0001](docs/adr/0001-wordpress-context-abstraction.md) and the
v0.0.x decision log for the reasoning.

## [Unreleased]

Nothing yet. Work toward v0.5.0 — the admin UI and the longer-running
operational features (resumable and scheduled exports, transports, selective
content, multisite) — begins after this tag. See
[`docs/roadmap.md`](docs/roadmap.md).

## [0.4.2] — 2026-06-24 — Security hardening

A hardening release from a full security/quality audit. No feature changes; the
defaults are safer and a few attacker-controlled inputs are refused.

### Security

- **Plugin directories are protected from direct web access.** Logs, rollback
  safety archives, and diagnostic bundles under `wp-content/pontifex/` now carry
  an `.htaccess` (deny all) and an `index.php`, and the log directory is created
  owner-only (`0700`). A `.wpmig` backup or log left under the web root is no
  longer fetchable by URL on a typical Apache host. (nginx still needs a server
  rule.)
- **Restored file permissions are clamped.** A restored entry can no longer carry
  setuid/setgid bits or be left world-writable.
- **Archive symlinks that escape the site root are refused** by default; a hostile
  archive can no longer plant a link such as `uploads/x -> /etc`. Use
  `import --allow-unsafe-symlinks` to restore the old behaviour for a trusted
  archive.
- **Log lines are sanitised** of control characters (log-injection defence), and a
  symlinked log path is refused rather than followed.
- **Secret material is always scrubbed**, even on error paths, and an encryption
  context is refused if reused for a second archive (a nonce-reuse guard).
- **The secret signing key is written safely** — created exclusively, restricted
  to `0600` before any bytes are written, and the mode is verified.
- **Hardened diagnostics bundle** — drops `active_plugins`, uses an unpredictable
  temporary filename.
- Per-table export queries assert the WordPress table prefix; the archive reader
  enforces decode and manifest read limits at its own layer.

## [0.4.1] — 2026-06-24 — Rollback-archive flag fix

### Fixed

- **`wp pontifex import --no-rollback-archive` now actually skips the
  pre-import safety archive.** The flag was silently ignored: WP-CLI parses a
  `--no-<name>` argument as `<name> => false`, so `--no-rollback-archive`
  arrived as `rollback-archive => false` rather than the `no-rollback-archive`
  key the command read — and the safety archive was taken regardless. The
  command now reads the form WP-CLI delivers. The documented flag is unchanged;
  this only makes it work, and it fails safe either way.

## [0.4.0] — 2026-06-24 — Stats, diagnostics and logging

The observability release. Pontifex can now show what it has done, package a
sanitised report when something goes wrong, and leave a self-contained log
beside every transfer — all locally, with nothing uploaded. Split out from
v0.3.0 so the cryptographic work could ship on its own.

- **`wp pontifex stats`.** A local readout of the export and import counters —
  how many transfers were attempted, succeeded and failed, and how many bytes
  moved — as a table, or `--format=json` (also csv, yaml) for a bug report.
  Read-only; no network.
- **Rolling transfer history.** Alongside the totals, `stats` now shows a short
  window of the most recent transfers (timestamp, kind, outcome, size — never
  any content), kept in a single capped `wp_options` entry.
- **`wp pontifex diagnostics`.** A sanitised support bundle — recent logs,
  `doctor` and `stats` output, and an environment summary — written as a tar.gz.
  The site URL, absolute paths and sensitive option values (anything ending in
  `_key`, `_secret`, `_token`, `_password`) are redacted, and the bundle is
  never auto-uploaded: it is yours to read and share.
- **Per-transfer log files.** Beyond the central rotating `pontifex.log`, each
  transfer now also writes a self-contained log — export's beside the archive as
  `<output>.wpmig.log`, a real import's as `import-<UTC>.log` in the log
  directory — so a single run's record can be read or shared on its own.

## [0.3.0] — 2026-06-23 — Migration, encryption and signatures

The cryptographic release. Pontifex can now migrate a site to a new URL,
encrypt an archive, and sign it — each with the defences the format reserved
space for.

- **Cross-URL migration.** `wp pontifex import --url=<new-url>` rewrites the
  site URL across the restored database with a serialised-data-safe
  search-replace: `unserialize()` runs with `allowed_classes => false`, every
  rewritten value is round-trip re-serialised and verified, and a pre-import
  scan reports what will change (ADR 0006).
- **Encryption.** `wp pontifex export --encrypt` (or `--passphrase-stdin`)
  writes an AES-256-GCM archive with an Argon2id-derived key (a per-archive
  salt and a per-entry nonce); `import` and `verify` detect an encrypted
  archive and prompt for the passphrase. There is no passphrase recovery.
- **zstd compression.** Codec `0x0002`, preferred when `ext-zstd` is present and
  falling back to gzip when it is not — the archive is readable either way.
- **Ed25519 signatures.** `wp pontifex keygen` generates a keypair;
  `export --sign --signing-key=<path>` signs the archive (Ed25519 over a
  streamed SHA-256 prehash, so signing stays within memory on large archives);
  `verify` and `import` take `--public-key=<path>` to verify, with import
  refusing a bad signature before it writes anything.

The observability surface originally planned for v0.3.0 — fuller logging and
metrics, `wp pontifex stats`, `wp pontifex diagnostics` — moved to v0.4.0 so
this release could ship on the cryptographic work alone.

## [0.2.0] — 2026-06-22 — Safety, verification and rollback

The trust release. Two safety features make a destructive import
something you can recover from: `wp pontifex verify` checks an archive
before you rely on it, and rollback gives you an undo — every import now
takes a safety archive of the current site first, restorable with
`wp pontifex rollback`. Cross-URL migration and encryption move to
v0.3.0 ([`docs/roadmap.md`](docs/roadmap.md)). This release also lands
the repository hardening done since v0.1.0: a protected `main`, the
open-source community-health files, and a refreshed dependency floor.

### Added

- **`wp pontifex verify <archive>` command.** Opens a `.wpmig` archive,
  walks every entry and checks every SHA-256 hash, writing nothing, and
  exits 0 when the archive is sound or non-zero when it is broken or
  refused — so a backup can be checked against cold storage and gated in
  scripts or cron. `--list` prints the archive's contents as a table or
  `--format=json`. The same read-and-verify engine that already backs
  `import --dry-run`, exposed as a command (idea-bank Idea 010).
- **Rollback — automatic pre-import safety archive and `wp pontifex rollback`**
  (idea-bank Idea 009, [ADR 0005](docs/adr/0005-rollback-safety-archive-policy.md)).
  `import` now writes a safety archive of the current site before it restores
  (`--no-rollback-archive` to skip), and `wp pontifex rollback` restores the most
  recent one — the undo for a destructive import — with `--yes` and `--dry-run`.
  Safety archives live in `wp-content/pontifex/rollback/` (owner-only), named by
  UTC timestamp, with the most recent retained.

## [0.1.0] — 2026-06-22 — the round-trip baseline (same URL)

The headline release: a complete, proven round trip. Export a real
WordPress site to a `.wpmig` archive and restore it onto another
WordPress at the **same URL**, with byte-faithful files and database.
Cross-URL migration, encryption, rollback and verify stay deferred to
v0.2.0 and beyond ([`docs/roadmap.md`](docs/roadmap.md)); v0.1.0
archives are **unencrypted**.

### Added

- **`wp pontifex import <archive>` command.** Restores a `.wpmig`
  onto the current site. It surfaces the same-URL-only scope — no URL
  rewriting ([ADR 0004](docs/adr/0004-same-url-import-scope.md)) —
  before acting, confirms unless `--yes`, and offers `--dry-run`,
  which reads and verifies the whole archive end to end while writing
  nothing to the filesystem or database. Each run is logged.
  Registered in `pontifex.php` beside `export` and `doctor`.
- **First same-URL round-trip integration test.** Packs real file
  bytes and a real `utf8mb4` database table into an archive and
  restores it against a real WordPress, asserting byte-for-byte file
  fidelity and an identical row dump with multibyte content intact.
  The round-trip guarantee is now proven, not merely asserted — the
  first time the writer and reader meet over a real archive.
- **WordPress integration test harness, running in CI.** PHPUnit 11
  with a separate integration suite that boots a real WordPress through
  `wp-env` (`wp-phpunit`) and runs in CI across PHP 8.2–8.5, plus a
  WordPress-booting smoke test. Test counts are reported as two
  numbers — unit and integration.
- **Rotating PSR-3 file logger (`FileLogger`).** Writes to
  `wp-content/pontifex/logs/pontifex.log`, rotates at 2 MB (four
  backups, 10 MB cap), respects `WP_DEBUG` for its level floor, and
  never throws — all log I/O is failure-tolerant. Wired into both
  export and import.
- **Export run counters.** A single autoload-off `wp_option`
  (`pontifex_export_stats`) recording attempted / succeeded / failed
  / bytes_exported; import records its own run counters the same way.
- **Export progress bar.** A `ProgressReporter` seam (interface +
  WP-CLI bar + null no-op) that draws per-entry progress during an
  archive write without leaking a CLI type into the archive layer.
- **Restore seam.** `RestoreRunnerInterface` extracted so the CLI
  depends on a contract, not a `final` class; the runner gains
  `verify()` — the read-and-verify-only walk behind `--dry-run` —
  alongside `restore()`, both with an optional per-entry progress
  callback.
- **Release tooling.** `scripts/bump-version.php` and
  `scripts/check-release.php` keep `PONTIFEX_VERSION`, the plugin
  header `Version:` line and the git tag consistent
  ([ADR 0003](docs/adr/0003-strict-version-stamping.md)).
- Idea-bank entries 011 (throttled debug progress logging), 012
  (scan-phase progress reporting) and 013 (a deferred
  `import --max-size` override).

### Changed

- **PHP floor raised to 8.2** (from 8.1); the CI test matrix is now
  PHP 8.2–8.5.
- Adopted **PHPUnit 11** and added the integration stack
  (`wp-phpunit`, PHPUnit polyfills).
- The `RawCodec` and `GzipCodec` decoders now honour an optional
  decoded-byte ceiling, so an over-large or over-compressed payload
  is refused *during* decode rather than after it has been
  materialised.
- README, CONTRIBUTING and the package description corrected to match
  what actually ships — no claim exceeds the code (the "first-class
  rollback" pitch reworded to its true v0.2.0 status).

### Fixed

- **Symlink path-traversal escape in the restore writer.** A hostile
  archive could write files *outside* the destination root via a
  symlink entry followed by a file that descends through it (the
  Zip-Slip-via-symlink class). `FileWriter` now refuses any entry with
  a symlinked ancestor and clears a conflicting symlink at the write
  target; five path-traversal vectors are tested.

### Security

- **Reader defensive limits (`ArchiveLimits`), each proven against a
  hostile archive.** The restore walk refuses a hostile or malformed
  archive *before* it touches the destination, enforcing four
  conservative ceilings borrowed from mature backup tooling: at most
  50,000 entries, 2 GiB per decoded entry, a 100× decompression ratio,
  and 1 TiB total decoded size — a decompression bomb is refused
  mid-stream. This is the one MVP-blocking safety item (audit finding
  F015): the reader treats every byte as attacker-suppliable.
- **A failed database statement fails closed.** Real `$wpdb` returns
  `false` (it does not throw) on a failed query; an integration test
  proves a failed statement halts the restore before any later
  statement runs, so a corrupt chunk can never silently drop a table.
- **Import trust boundary documented** in
  [`.github/SECURITY.md`](.github/SECURITY.md): importing a `.wpmig`
  grants its author full write access to the target, so only trusted
  archives should be imported. A peer-CVE security review (Wordfence /
  WPScan / Patchstack) informed the hardening above.

## [0.0.6] — pre-alpha (tests strengthened; v0.1.0 scope settled)

### Added
- 23 behavioural tests for `Restore\FileWriter` (closes audit
  finding F001) — every path-traversal and write defence now has a
  named test.
- ADR 0004: v0.1.0 imports restore to the same URL; URL rewriting
  ships in v0.2.0 together with its serialised-data defences.
- wp-env development environment config (`.wp-env.json`) for a
  reproducible local WordPress on Docker.
- PHP 8.5 added to the CI test matrix.
- Idea bank entries 009 (rollback) and 010 (archive verification);
  Idea 007 recorded as partially implemented (Sprint 1 close-out).

### Changed
- Roadmap aligned with ADR 0004: v0.1.0 is a same-URL restore
  baseline; the minimum logger, transfer counters and export progress
  bar are pulled into v0.1.0, with cross-URL migration, encryption and
  the fuller observability surfaces in v0.2.0.
- README rewritten for accuracy: a "What works today" status table,
  rollback described as a v0.2.0 commitment rather than an existing
  feature, and export documented as already shipped (v0.0.5).

### Notes
- No plugin-behaviour changes; checkpoint release before Phase 5
  (ImportCommand). Exercises the ADR 0003 tag/version CI guard.

## [0.0.5] — pre-alpha: archive format + export half

The archive format is implemented end-to-end as a library: writer
produces sealed archives conforming to the v1 byte layout, reader
enforces the mandatory verification-order contract. The export CLI
is functional. The import CLI is not yet registered; restore
primitives exist at the PHP API level but the command is pending.
This is **the export half of the v0.1.0 round-trip baseline**, with
the import half and the round-trip tests still to come.

### Added

- **Format primitives.** `Codec` interface, `RawCodec` (`0x0000`),
  `GzipCodec` (`0x0001`), `CodecRegistry`, `HashingStream`,
  `ByteOrder` big-endian helpers, and the `Header`, `Provenance`,
  `EntryHeader`, `ArchiveManifest`, `Footer` value objects.
- **Archive writer.** `EntryWriter`, `FooterWriter`,
  `ArchiveWriter`, `FileScanner`, `DatabaseScanner`,
  `ManifestBuilder` (with extracted `ManifestBuilderInterface`
  for test mockability), `ExclusionRules`.
- **Archive reader.** `EntryReader`, `ArchiveReader`,
  `EntryReadResult`, with the mandatory verification-order
  contract from the format spec.
- **Restore primitives (library only).** `DatabaseWriter`,
  `FileWriter`, `RestoreRunner`. Not user-facing yet; pending the
  `wp pontifex import` CLI command in a later v0.0.x release.
- **CLI integration.** `WordPressContext` abstraction
  ([ADR 0001](docs/adr/0001-wordpress-context-abstraction.md)),
  refactored `DoctorCommand` to use it, and new `ExportCommand`
  registering `wp pontifex export`.
- **Strict version-stamping policy.** Every `v*` git tag must
  match `PONTIFEX_VERSION` and the plugin header `Version:` line;
  the CI workflow's *Verify tag matches plugin version* step
  enforces this on tag push
  ([ADR 0003](docs/adr/0003-strict-version-stamping.md)).
- **Test infrastructure.** brain/monkey for WordPress-function
  mocking, Mockery lifecycle handling in a shared
  `Pontifex\Tests\TestCase` base class, `INVOKE_TESTING.md`
  documenting the surgical `__invoke` test pattern.
- **Pre-commit hooks.** 72-char commit-subject limit enforced via
  a `commit-msg` stage hook
  (`scripts/check-commit-subject-length.sh`).

### Changed

- **Composer audit strictness.** `--abandoned=report` flag aligned
  across all three call sites — `composer.json`'s `check` script,
  `.pre-commit-config.yaml`'s pre-push hook, and
  `.github/workflows/ci.yml`'s audit step. ADR 0002 documents the
  invariant and was amended during the 2026-05-26 audit pass to
  cover the CI site that the original enumeration missed.
- **PSR-4 autoload.** Three redundant entries in `composer.json`
  collapsed to the single umbrella entry the others were already
  subsumed by.
- **Test directory naming.** `tests/Unit/Wordpress/` renamed to
  `tests/Unit/WordPress/` to align with the namespace declared
  inside and avoid PSR-4 case-sensitivity issues on Linux CI.

### Removed

- `src/Version.php` — dead-code class that claimed canonical
  status but had no consumers. The strict version-stamping policy
  above makes the abstraction unnecessary at this scale; git
  remembers it if a future need brings it back.

### Known issues and pending work toward v0.1.0

- **`wp pontifex import` is not registered.** The restore
  primitives (`DatabaseWriter`, `FileWriter`, `RestoreRunner`) are
  implemented and unit-tested as far as the audit allowed, but the
  CLI command that exposes them to users is not yet wired. This is
  Phase 5 of the roadmap and the gating piece for v0.1.0's round-
  trip headline.
- **No WordPress integration tests yet.** Phase 6 of the roadmap
  requires running PHPUnit against a real WordPress installation
  across the PHP 8.1–8.4 matrix; `wp-phpunit` is not yet wired
  into the test harness.
- **No round-trip tests yet.** Cannot exist until import lands. The
  format's correctness guarantee is "export then import
  reconstructs the source byte-perfectly"; that test cannot run
  while only the export half exists.
- **`src/Restore/FileWriter.php` lacks dedicated unit tests**
  (audit finding F001). Roughly 350 lines of path-traversal
  defence code, exercised indirectly via `RestoreRunnerTest` but
  with most individual defences unverified at unit level.

### Notes

- **Archives are unencrypted** in the v0.1.x series. Encryption
  (codecs `0x0100`, `0x0101`, `0x0102`) is deferred to v0.2.0.
  See [`docs/roadmap.md`](docs/roadmap.md) for the full deferred
  list.
- **Format compatibility.** Archives written by v0.0.5 will be
  readable by v0.1.0 and onward without conversion. The byte
  layout from `archive-format.md` is the public contract; later
  versions add features without breaking earlier archives.
- **Practical implication.** Users running v0.0.5 can produce
  archives via `wp pontifex export` and the archives are valid
  per the spec, but they cannot import them back through a CLI
  command — the restore path is library-only until import wires
  up.

## [0.0.4] — pre-alpha (docs-only)

### Added
- pip and SSL troubleshooting section in `CONTRIBUTING.md` covering
  certifi setup behind corporate TLS interception, for contributors
  whose Python install cannot reach PyPI during `pre-commit install`.

### Notes
- Byte-identical to v0.0.3 for end users; documentation-only release.

## [0.0.3] — pre-alpha

### Added
- Archive format specification (`docs/archive-format.md`) and design
  rationale (`docs/archive-format-design.md`) — the public contract
  every future version of Pontifex commits to honour.
- `Environment` interface and `RealEnvironment` implementation
  abstracting PHP-runtime queries so `DoctorCommand`'s checks can be
  exercised under controlled test conditions.
- brain/monkey added as a test dependency for WordPress-function
  mocking; behavioural tests now cover `DoctorCommand`'s runtime
  checks, PHP-configuration checks, required and recommended
  extension checks, filesystem-permission checks, WP-config status
  checks, and the status-summary computation.
- `compute_status_counts()` extracted from `print_summary()` for
  direct test coverage.
- Xdebug-with-Local-and-PhpStorm setup guide in `CONTRIBUTING.md`.
- `composer dev:setup` script bundling first-time contributor setup
  (pre-commit hook installation) into a single command.

### Notes
- No user-facing feature changes; foundation work for the archive
  implementation that lands in v0.1.0.

## [0.0.2] — pre-alpha

### Added
- `PONTIFEX_VERSION` constant defined in `pontifex.php`.

### Changed
- `pontifex.php` registers the new `wp pontifex export` command stub
  alongside the existing `wp pontifex doctor`.

## [0.0.1] — pre-alpha

### Added
- Initial plugin scaffolding: `pontifex.php` bootstrap, Composer
  autoload, PSR-4 namespace, GPL-2.0-or-later licence.
- `wp pontifex doctor` WP-CLI command — environment-audit checklist
  covering PHP runtime, PHP configuration, required and recommended
  extensions, filesystem permissions, WordPress configuration, and
  database compatibility.
- `Environment` interface and `RealEnvironment` production
  implementation, decoupling all PHP-runtime queries from the doctor
  command for test isolation.
- WPCS-clean codebase: PHPCS WordPress-Extra, PHPStan level 6, PHPUnit
  unit tests with comprehensive coverage of every check method.
- Project documentation: README, archive format specification
  (`docs/archive-format.md`), design rationale
  (`docs/archive-format-design.md`), threat model
  (`docs/threat-model.md`), idea bank (`docs/idea-bank.md`).
- CI workflow running PHPCS, PHPStan, PHPUnit, and `composer audit`
  on every push.
- Pre-commit hooks: gitleaks, PHPCS fast subset, PHPStan changed-files.
- Security tooling: `roave/security-advisories` in `require-dev`
  refusing installation of any CVE-flagged dependency.

[Unreleased]: https://github.com/7Duckie/pontifex/compare/v0.4.2...HEAD
[0.4.2]: https://github.com/7Duckie/pontifex/compare/v0.4.1...v0.4.2
[0.4.1]: https://github.com/7Duckie/pontifex/compare/v0.4.0...v0.4.1
[0.4.0]: https://github.com/7Duckie/pontifex/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/7Duckie/pontifex/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/7Duckie/pontifex/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/7Duckie/pontifex/compare/v0.0.6...v0.1.0
[0.0.6]: https://github.com/7Duckie/pontifex/releases/tag/v0.0.6
[0.0.5]: https://github.com/7Duckie/pontifex/releases/tag/v0.0.5
[0.0.4]: https://github.com/7Duckie/pontifex/releases/tag/v0.0.4
[0.0.3]: https://github.com/7Duckie/pontifex/releases/tag/v0.0.3
[0.0.2]: https://github.com/7Duckie/pontifex/releases/tag/v0.0.2
[0.0.1]: https://github.com/7Duckie/pontifex/releases/tag/v0.0.1
