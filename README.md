# Pontifex

A free, open-source WordPress migration plugin with a documented
archive format and first-class rollback.

**Status: pre-alpha.** Pontifex is in early development. The current
working feature is the `wp pontifex doctor` environment-audit command.
Do not use Pontifex on production sites yet.

## What Pontifex will be

Pontifex aims to be the WordPress migration plugin that does it right:

1. **Genuinely free at every capability level.** No file-size caps, no
   bandwidth caps, no Pro tier behind which the features you actually
   need are hidden.
2. **Documented, versioned archive format.** The `.wpmig` format is a
   public specification ([`docs/archive-format.md`](docs/archive-format.md)).
   Your migration artefacts belong to you, not to a vendor.
3. **Rollback as a first-class feature.** Pontifex takes an automatic
   pre-import snapshot before any destructive step. One command to
   restore.

## Requirements

- PHP 8.1 or newer
- WordPress 6.5 or newer
- MySQL 5.7+ or MariaDB 10.4+

## Installation (development)

```bash
git clone https://github.com/7Duckie/pontifex.git
cd pontifex
composer install
```

Then symlink or copy the directory into `wp-content/plugins/` and
activate.

## Usage

```bash
wp pontifex doctor
wp pontifex doctor --format=json
wp pontifex doctor --fields=category,name,status
```

The `doctor` command is the only feature available in current
pre-alpha builds. Export and import arrive with v0.1.0; see the
[roadmap](docs/roadmap.md) for what ships when.

## Roadmap

- **v0.1.0 — Round-trip baseline.** WP-CLI `export` and `import`
  commands. Unencrypted archives, gzip compression, all four entry
  kinds, full integrity contract (per-entry SHA-256, manifest hash,
  footer hash, verification order).
- **v0.2.0 — Encryption and observability.** Argon2id + AES-256-GCM
  encryption, zstd compression, optional Ed25519 signatures,
  structured logging, transfer metrics, `wp pontifex stats` and
  `wp pontifex diagnostics`.
- **v0.3.0 and beyond — Admin UI and operational maturity.** Admin UI
  for non-CLI users, scheduled exports, push/pull transports,
  multisite, selective content.
- **v1.0.0 — Stable surface.** Public API frozen, WordPress.org
  submission, long-term support guarantees.
- **v2.0 — Go reference reader.** Standalone Go CLI implementing the
  format spec, validating that the spec is unambiguous and providing
  a recovery path that does not require a working WordPress install.

Full design rationale lives in [`docs/`](docs/). The version-by-version
roadmap with deferred-item reasoning is in
[`docs/roadmap.md`](docs/roadmap.md).

## Documentation

- [`docs/archive-format.md`](docs/archive-format.md) — `.wpmig` format
  specification (the bytes-on-disk contract).
- [`docs/archive-format-design.md`](docs/archive-format-design.md) —
  rationale behind format decisions (the *why*).
- [`docs/threat-model.md`](docs/threat-model.md) — plugin attack-surface
  ranking with CVE-priority guidance.
- [`docs/roadmap.md`](docs/roadmap.md) — release-by-release scope.
- [`docs/idea-bank.md`](docs/idea-bank.md) — ideas under consideration,
  with deferral and rejection reasoning.
- [`docs/adr/`](docs/adr/) — Architecture Decision Records: the
  significant technical choices, dated and immutable.

## Contributing

See [`.github/CONTRIBUTING.md`](.github/CONTRIBUTING.md) for
development setup, commit conventions, and the quality-gate
expectations. Security reports: see
[`.github/SECURITY.md`](.github/SECURITY.md) and use GitHub's
private vulnerability reporting.

## Changelog

Release history and per-version notes are in
[`CHANGELOG.md`](CHANGELOG.md).

## Licence

GPL-2.0-or-later. See [`LICENSE`](LICENSE).
