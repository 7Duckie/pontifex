# Pontifex

Pontifex packs a WordPress site — your content under `wp-content` (themes,
plugins, uploads) plus every WordPress-prefixed database table — into one
file, a `.wpmig` archive, and restores it onto another WordPress. Pass
`--whole-site` to capture the entire installation, WordPress core included,
for cloning onto a bare server. An import takes a safety archive of the
current site first by default, so it can be rolled back with one command.

**Status: v1.0.0, released — the first stable release.** From here the
public API is frozen (a breaking change now needs a major version), and
the `.wpmig` format specification is locked at specification version 1.1:
a v1.1 archive stays readable by every future Pontifex, and a change the
specification cannot accommodate needs a new major specification version,
never a silent revision. Pontifex is being submitted to the
WordPress.org plugin directory. See [`CHANGELOG.md`](CHANGELOG.md) for
the full release history.

Pontifex runs no service of its own: it phones home to nothing, needs no
account, and contacts nothing on its own initiative. The only way a byte
ever leaves the machine is an offsite SFTP destination you configure
yourself, pointing at a server you own — configure none, and nothing is
ever sent.

## Why Pontifex

1. **Genuinely free at every capability level.** No file-size caps, no
   bandwidth caps, no Pro tier behind which the features you actually
   need are hidden.
2. **Documented, versioned, locked archive format.** The `.wpmig` format
   is a public specification
   ([`docs/archive-format.md`](docs/archive-format.md)), locked at
   specification version 1.1 as of v1.0.0. Your migration artefacts
   belong to you, not to a vendor.
3. **Rollback as a first-class feature.** Before a destructive import,
   Pontifex takes an automatic safety archive of the current site by
   default, with one command (`wp pontifex rollback`) to restore it.

## What it does

Pontifex is driven two ways: WP-CLI (`wp pontifex …`), or the admin
screens under **Pontifex** in wp-admin (Overview, Backup, Verify,
Restore) for sites without shell access.

- `wp pontifex doctor` — read-only environment audit (memory limit,
  execution time, symbolic-link support, disk space, MySQL/MariaDB
  version, and more); `--format=json`, `--fields=`.
- `wp pontifex export --output=<path>` — pack the site into a `.wpmig`
  archive. The default scope is content-only (`wp-content` plus every
  WordPress-prefixed database table); `--whole-site` adds WordPress
  core. `--files-only` / `--db-only` capture just one half;
  `--exclude` / `--exclude-table` /
  `--exclude-file` leave out named files, tables, or patterns (`.git` is
  left out by default at any depth). `--encrypt` (or
  `--passphrase-stdin` for scripts) encrypts with AES-256-GCM under an
  Argon2id-derived key. `--sign --signing-key=<path>` adds an Ed25519
  signature. `--resumable` / `--resume` survive an interrupted export.
  `--destination=<name>` uploads the finished archive to a configured
  offsite destination.
- `wp pontifex import <archive>` — restore an archive, taking an
  automatic safety archive first (skip with `--no-rollback-archive`).
  Restores content-only to the same URL by default; `--whole-site`
  restores WordPress core too — intended only for a fresh, empty
  destination; `--url=<new-url>` migrates the site with
  serialised-data-safe search-replace; `--dry-run` verifies without
  writing anything; `--public-key=<path>` makes signature verification
  mandatory.
- `wp pontifex verify <archive>` — check an archive's integrity without
  restoring anything; `--list` prints its contents. Pass
  `--public-key=<path>` (or pin `PONTIFEX_PUBLIC_KEY`) to also verify an
  Ed25519 signature and make it mandatory — without a key, a signed
  archive is hash-checked only, with a warning that its signature was
  not verified.
- `wp pontifex rollback` — undo the most recent import from its safety
  archive; `--dry-run`, `--yes`.
- `wp pontifex keygen` — generate an Ed25519 keypair for signing
  archives.
- `wp pontifex stats` / `wp pontifex diagnostics` — a local
  export/import activity readout and a sanitised, never-uploaded
  support bundle.
- `wp pontifex schedule set|show|off` — run backups automatically on a
  daily or weekly UTC hour, with retention pruning and per-schedule
  exclusions; also configurable from the Backup screen.
- `wp pontifex destination add|list|test|archives|pull|prune|remove` —
  configure an SFTP server you own as an offsite destination for
  `export --destination=<name>`, and pull an archive back for recovery.
  See [`docs/using-destinations.md`](docs/using-destinations.md).

The admin screens cover the everyday operations for sites without shell
access: live progress and cancel on Backup, re-attaching to a running
job after a page reload, a pre-restore safety archive on Restore, and
chunked upload of a backup taken on another site. Whole-site backups,
encryption, signing and offsite destinations stay command-line
features — the admin refuses an encrypted or whole-site archive and
points at the matching command.

Run `wp pontifex <command> --help` for any command's full options.

## Requirements

- PHP 8.2 or newer
- WordPress 6.5 or newer (tested up to 7.0)
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
ships a [wp-env](https://www.npmjs.com/package/@wordpress/env)
configuration, pinned in `package.json`. With Docker running:

```bash
npm ci
npx @wordpress/env start
```

This gives you WordPress on `http://localhost:8910` with Pontifex
already active. The integration suite runs against a second, separate
wp-env configuration (`.wp-env.tests.json`, port 8911) — see
[`.github/CONTRIBUTING.md`](.github/CONTRIBUTING.md) for the exact
commands.

## Usage

```bash
# Audit the environment
wp pontifex doctor
wp pontifex doctor --format=json
wp pontifex doctor --fields=category,name,status

# Pack your content (wp-content) and database into a single archive
wp pontifex export --output=/path/to/site.wpmig
# ...or the entire installation, WordPress core included, for a bare-server clone
wp pontifex export --output=/path/to/site.wpmig --whole-site

# Restore an archive onto a WordPress at the same URL
wp pontifex import /path/to/site.wpmig --dry-run   # preview: verify only, write nothing
wp pontifex import /path/to/site.wpmig             # restore (prompts before writing)

# Restore, then migrate the site to a new URL (serialised-data-safe)
wp pontifex import /path/to/site.wpmig --url=https://new-site.example

# Check an archive without restoring it, or undo an import you just made
wp pontifex verify /path/to/site.wpmig
wp pontifex rollback
```

`import` restores **content-only** (`wp-content` plus every
WordPress-prefixed database table) to the **same URL** by default; pass
`--whole-site` to also restore WordPress core — intended only for a
fresh, empty destination — and `--url=<new-url>` to migrate the site
to a new URL with serialised-data-safe search-replace (the defences
behind it are recorded in
[ADR 0006](docs/adr/0006-cross-url-via-post-restore-search-replace.md)).
See the [roadmap](docs/roadmap.md) for what ships when.

> **Importing writes an archive's content onto your site** (and its
> WordPress core too, with `--whole-site`). Only import a `.wpmig` you
> produced or fully trust — see
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
- **v0.5.0 to v0.9.5 — Admin UI and operational maturity. Shipped.**
  An admin UI for non-CLI users (Overview, Backup, Verify,
  Restore/Rollback); resumable exports, surviving PHP timeouts and lost
  SSH sessions; scheduled exports; selective content (`--exclude`,
  `--exclude-table`, `--files-only`, `--db-only`); offsite SFTP
  destinations on a server you own; and the hardening releases that
  stop an archive's SQL and its files reaching outside the site they
  restore into.
- **v1.0.0 — Stable surface. ✅ Released.** The public API frozen, and the
  `.wpmig` specification locked (see
  [`docs/archive-format.md`](docs/archive-format.md), locked at
  specification version 1.1).
- **Not yet committed to a release.** Push/pull host-to-host transports;
  multisite support.
- **v2.0 — Go reference reader. Planned.** A standalone Go CLI
  implementing read, verify, list and conversion from the format spec —
  independent verification and emergency recovery without a working
  WordPress, and proof that the specification is unambiguous.

Full design rationale lives in [`docs/`](docs/); the authoritative
version-by-version roadmap is [`docs/roadmap.md`](docs/roadmap.md).

## Documentation

**Start here.**

- [**Using Pontifex**](docs/guide.md) — a step-by-step guide in plain
  English, for site owners rather than developers. Installing, your first
  backup, checking it is good, moving a site, restoring, undoing a
  restore, scheduling, and sending backups offsite. No shell required.
- [**When Pontifex refuses, and what to do**](docs/when-pontifex-refuses.md)
  — Pontifex refuses things deliberately, and a refusal looks exactly like
  a bug from the outside. This is every refusal it makes: what it means,
  whether it is protecting you or is something you can fix, the correct
  remedy, and the plausible-looking wrong one. Read this before working
  around anything.
- [**Technical reference**](docs/reference.md) — the complete CLI surface,
  the export and restore pipelines, integrity and encryption, concurrency,
  limits, and what is configurable.

**Reference.**

- [`docs/archive-format.md`](docs/archive-format.md) — `.wpmig` format
  specification (the bytes-on-disk contract), locked at specification
  version 1.1.
- [`docs/archive-format-design.md`](docs/archive-format-design.md) —
  rationale behind format decisions (the *why*).
- [`docs/threat-model.md`](docs/threat-model.md) — plugin attack-surface
  ranking with CVE-priority guidance.
- [`docs/using-destinations.md`](docs/using-destinations.md) —
  configuring an offsite SFTP destination.
- [`docs/PRIVACY.md`](docs/PRIVACY.md) — exactly what Pontifex does, and
  does not, send over the network.
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
