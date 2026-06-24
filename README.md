# Pontifex

A free, open-source WordPress migration and backup plugin built around
a documented archive format (`.wpmig`). Every import takes a safety
archive first, so it can be rolled back.

**Status: pre-alpha.** Pontifex is in early development. Today it can
audit an environment (`wp pontifex doctor`), pack a whole site into a
single `.wpmig` archive (`wp pontifex export`), restore that archive
onto a WordPress at the same URL (`wp pontifex import`, which takes a
safety archive first), migrate a site to a new URL
(`wp pontifex import --url=`) with serialised-data-safe search-replace,
check an archive without restoring it (`wp pontifex verify`), and undo a
mistaken import (`wp pontifex rollback`) — the round trip is proven
end-to-end by an integration test against a real WordPress. The admin UI
and the operational features (scheduling, transports, multisite) are still
to come; see the roadmap. Do not rely on Pontifex for production data yet.

## What Pontifex will be

Pontifex aims to be the WordPress migration plugin that does it right:

1. **Genuinely free at every capability level.** No file-size caps, no
   bandwidth caps, no Pro tier behind which the features you actually
   need are hidden.
2. **Documented, versioned archive format.** The `.wpmig` format is a
   public specification ([`docs/archive-format.md`](docs/archive-format.md)).
   Your migration artefacts belong to you, not to a vendor.
3. **Rollback as a first-class feature.** Before any destructive import,
   Pontifex takes an automatic safety archive of the current site, with
   one command (`wp pontifex rollback`) to restore it.

## What works today

Pontifex is pre-alpha, so what the *format specification* describes and
what the *plugin* actually does are not yet the same thing. This table
is the honest difference, updated at every release.

| Capability | In the spec | In the plugin today |
|---|---|---|
| Environment audit (`wp pontifex doctor`) | — | ✅ |
| Export a site to `.wpmig` (`wp pontifex export`) | ✅ | ✅ |
| Import / restore (`wp pontifex import`, same URL) | ✅ | ✅ |
| Round trip proven end-to-end | — | ✅ — same-URL, integration-tested |
| Rollback (pre-import safety archive + undo) | — | ✅ |
| Archive verification (`wp pontifex verify`) | — | ✅ |
| Cross-URL migration (`wp pontifex import --url=`) | ✅ | ✅ — serialised-safe search-replace + gadget defences |
| zstd compression (codec `0x0002`) | ✅ | ✅ — when `ext-zstd` is present, else gzip |
| Encryption (`--encrypt`) | ✅ | ✅ — AES-256-GCM, Argon2id-derived key; `--encrypt` / `--passphrase-stdin` |
| Ed25519 signatures | ✅ | ✅ — `wp pontifex keygen` + `export --sign`; `--public-key` verification on `verify` / `import` |
| Activity readout (`wp pontifex stats`) | — | ✅ — export/import counters + recent-transfer history; `--format=json` |
| Support bundle (`wp pontifex diagnostics`) | — | ✅ — sanitised tar.gz (redacted; never auto-uploads) |
| Per-transfer log files | — | ✅ — beside the archive on export; in the log dir on import |

**What Pontifex is _not_ yet:** a scheduled-backup system (no cron, no
retention policies) or a point-and-click tool (the admin UI lands in
v0.5.0). If you need those today, pair Pontifex with tools that have them —
and watch the table above as it fills in.

## Requirements

- PHP 8.2 or newer
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

# Restore an archive onto a WordPress at the same URL
wp pontifex import /path/to/site.wpmig --dry-run   # preview: verify only, write nothing
wp pontifex import /path/to/site.wpmig             # restore (prompts before writing)

# Restore, then migrate the site to a new URL (serialised-data-safe)
wp pontifex import /path/to/site.wpmig --url=https://new-site.example
```

`import` restores to the **same URL** by default; pass `--url=<new-url>`
to also migrate the site to a new URL with serialised-data-safe
search-replace (the defences behind it are recorded in
[ADR 0006](docs/adr/0006-cross-url-via-post-restore-search-replace.md)).
See the [roadmap](docs/roadmap.md) for what ships when.

> **Importing writes an entire site onto yours.** Only import a `.wpmig`
> you produced or fully trust — see
> [the import trust boundary](.github/SECURITY.md#the-import-trust-boundary).

### A full round trip

On the source site, pack it into one archive:

```bash
wp pontifex export --output=site.wpmig
```

Move `site.wpmig` to the destination (over any channel you control),
then on a WordPress at the **same URL**:

```bash
wp pontifex import site.wpmig --dry-run   # preview: verify the whole archive, write nothing
wp pontifex import site.wpmig             # restore for real (confirms first, unless --yes)
```

You end up with a site that matches the source — files byte-for-byte and
the database intact. That round trip is proven in CI by an integration
test against real WordPress.

## Roadmap

Released and planned, version by version, with status. The source of
truth — including why each deferred item waits — is
[`docs/roadmap.md`](docs/roadmap.md).

- **v0.1.0 — Round-trip baseline (same URL). ✅ Released.** WP-CLI
  `export` and `import` (same-URL restore); the `.wpmig` writer and
  reader; gzip and no-compression codecs; all four entry kinds
  (`file`, `db_chunk`, `directory`, `symlink`); the full integrity
  contract (per-entry SHA-256, manifest hash, footer hash, mandatory
  verification order); a minimum diagnostic logger and persistent
  transfer counters.
- **v0.2.0 — Safety, verification and rollback. ✅ Released.**
  `wp pontifex verify` (walk an archive and check every hash against
  cold storage, writing nothing); rollback (an automatic pre-import
  safety archive, undone with `wp pontifex rollback`); a protected
  `main` and the open-source-health files.
- **v0.3.0 — Migration, encryption and signatures. ✅ Released.**
  - Cross-URL migration — `wp pontifex import --url=`, a serialised-safe
    search-replace with allowlist-disabled unserialize, round-trip
    verification, and a pre-import scan.
  - zstd compression — codec `0x0002`, preferred when `ext-zstd` is
    present, gzip otherwise.
  - Encryption — Argon2id-derived keys, per-entry AES-256-GCM, codecs
    `0x0100`/`0x0101`/`0x0102`, with `--encrypt` / `--passphrase-stdin` on
    `export` and a passphrase prompt on `import` and `verify`.
  - Optional Ed25519 detached signatures (Ed25519 over a streamed SHA-256
    prehash) — `wp pontifex keygen`, `export --sign --signing-key`, and
    `--public-key` verification on `verify` and `import`.
- **v0.4.0 — Stats, diagnostics and logging. ✅ Released.**
  - `wp pontifex stats` — a local readout of the export/import counters,
    with `--format=json` (also csv, yaml) for bug reports.
  - Rolling transfer history — the most recent transfers (timestamp, kind,
    outcome, size; never any content), shown by `stats`.
  - `wp pontifex diagnostics` — a sanitised tar.gz support bundle (recent
    logs, `doctor`/`stats` output, environment summary); redacted, never
    auto-uploaded.
  - Per-transfer log files — a self-contained log per transfer, beside the
    archive on export and in the log directory on import.
- **v0.5.0 and beyond — Admin UI and operational maturity. Planned.**
  An admin UI for non-CLI users; resumable exports (surviving PHP timeouts
  and lost SSH sessions); scheduled exports; push/pull host-to-host
  transports; selective content (export-without-database, single-table,
  content-type filters); multisite support.
- **v1.0.0 — Stable surface. Planned.** The public API frozen;
  submission to the WordPress.org plugin directory; the `.wpmig` spec
  graduating from DRAFT to LOCKED with published test vectors.
- **v2.0 — Go reference reader. Planned.** A standalone Go CLI
  implementing read, verify, list and conversion from the format spec —
  independent verification and emergency recovery without a working
  WordPress, and proof that the specification is unambiguous.

Full design rationale lives in [`docs/`](docs/); the authoritative
version-by-version roadmap is [`docs/roadmap.md`](docs/roadmap.md).

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
