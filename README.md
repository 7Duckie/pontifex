# Pontifex

A free, open-source WordPress migration and backup plugin built around
a documented archive format (`.wpmig`). Rollback is designed in and
arrives in v0.2.0.

**Status: pre-alpha.** Pontifex is in early development. Today it can
audit an environment (`wp pontifex doctor`) and pack a whole site into
a single `.wpmig` archive (`wp pontifex export`). The matching
`import` — and with it the first provable round trip — is the v0.1.0
milestone, in progress now. Do not rely on Pontifex for production
data yet.

## What Pontifex will be

Pontifex aims to be the WordPress migration plugin that does it right:

1. **Genuinely free at every capability level.** No file-size caps, no
   bandwidth caps, no Pro tier behind which the features you actually
   need are hidden.
2. **Documented, versioned archive format.** The `.wpmig` format is a
   public specification ([`docs/archive-format.md`](docs/archive-format.md)).
   Your migration artefacts belong to you, not to a vendor.
3. **Rollback as a first-class feature (arriving v0.2.0).** Before any
   destructive step, Pontifex will take an automatic pre-import safety
   archive, with one command to restore it. This point describes a
   committed v0.2.0 goal, not today's behaviour — see the status table
   below for the honest current picture.

## What works today

Pontifex is pre-alpha, so what the *format specification* describes and
what the *plugin* actually does are not yet the same thing. This table
is the honest difference, updated at every release.

| Capability | In the spec | In the plugin today |
|---|---|---|
| Environment audit (`wp pontifex doctor`) | — | ✅ |
| Export a site to `.wpmig` (`wp pontifex export`) | ✅ | ✅ |
| Import / restore (`wp pontifex import`) | ✅ | ❌ — v0.1.0, in progress |
| Round trip proven end-to-end | — | ❌ — the v0.1.0 gate |
| Rollback (pre-import safety archive + undo) | — | ❌ — v0.2.0 |
| Cross-URL migration (URL rewriting) | ✅ | ❌ — v0.2.0, shipped with its security defences |
| Archive verification (`wp pontifex verify`) | — | ❌ — v0.2.0 |
| Encryption (`--passphrase`) | ✅ | ❌ — not yet; **archives today are written unencrypted** |

**What Pontifex is _not_ yet:** a scheduled-backup system (no cron, no
retention policies), a cross-domain migrator (URL rewriting lands in
v0.2.0 together with the defences it needs), or a point-and-click tool
(the admin UI is v0.3.0). If you need those today, pair Pontifex with
tools that have them — and watch the table above as it fills in.

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

For a reproducible, throwaway WordPress to develop against, the repo
ships a [wp-env](https://www.npmjs.com/package/@wordpress/env) config.
With Docker running:

```bash
npx @wordpress/env start
```

This gives you WordPress on `http://localhost:8910` with Pontifex
already active, plus a separate test site on `http://localhost:8911`
for the integration suite.

## Usage

```bash
# Audit the environment
wp pontifex doctor
wp pontifex doctor --format=json
wp pontifex doctor --fields=category,name,status

# Pack the whole site into a single archive
wp pontifex export --output=/path/to/site.wpmig
```

`import` arrives with v0.1.0; until then an exported `.wpmig` cannot
yet be restored by the plugin. See the [roadmap](docs/roadmap.md) for
what ships when.

## Roadmap

- **v0.1.0 — Round-trip baseline (same URL).** WP-CLI `export` and
  `import`. Restores to the **same URL** — a true backup-and-restore
  baseline; cross-domain migration is v0.2.0's job. Unencrypted
  archives, gzip compression, all four entry kinds, full integrity
  contract (per-entry SHA-256, manifest hash, footer hash,
  verification order).
- **v0.2.0 — Migration, safety, encryption and observability.**
  Cross-URL migration (URL rewriting) shipped *together with* its
  serialised-data defences; rollback (automatic pre-import safety
  archive); archive verification (`wp pontifex verify`); Argon2id +
  AES-256-GCM encryption; zstd compression; optional Ed25519
  signatures; structured logging, transfer metrics, `wp pontifex
  stats` and `wp pontifex diagnostics`.
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
[`docs/roadmap.md`](docs/roadmap.md), which is the authoritative source
for what each version means.

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
