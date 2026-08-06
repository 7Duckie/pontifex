# Pontifex

Pontifex packs a WordPress site — your content under `wp-content` (themes,
plugins, uploads) plus every WordPress-prefixed database table — into one
file, a `.wpmig` archive, and restores it onto another WordPress. Pass
`--whole-site` to capture the entire installation, WordPress core included,
for cloning onto a bare server. An import takes a safety archive of the
current site first by default, so it can be rolled back with one command.

Drive it from WP-CLI (`wp pontifex …`) or from the admin screens under
**Pontifex** in wp-admin, for sites without shell access.

**Status: v1.1.0, released.** The public API is
frozen (a breaking change now needs a major version), and the `.wpmig`
specification is locked at specification version 1.1: a v1.1 archive stays
readable by every future Pontifex, and a change the specification cannot
accommodate needs a new major specification version, never a silent
revision. v1.0.0 was the first stable release; v1.0.1 to v1.0.3 are security
and correctness patches on top of it, and v1.1.0 makes verifying a backup
answer for whether a restore would accept it. Pontifex is being submitted to the
WordPress.org plugin directory.

---

## Two promises

**The format is documented, and now locked.** `.wpmig` is a public
specification ([`docs/archive-format.md`](docs/archive-format.md)),
CC BY 4.0, fixed at specification version 1.1. An archive can be read,
verified or recovered without this plugin. Your backups are yours.

**There is no cloud service.** Pontifex runs nothing of its own: it phones
home to nothing, needs no account, and contacts nothing on its own
initiative. The only way a byte leaves the machine is an offsite SFTP
destination you configure yourself, pointing at a server you own —
configure none, and nothing is ever sent. A standing architecture test
fails the build if a network call appears outside `src/Destination/`.

---

## Documentation

**Start here.**

| | |
|---|---|
| [**Using Pontifex**](docs/guide.md) | Step-by-step, in plain English, for site owners rather than developers. Installing, your first backup, checking it is good, moving a site, restoring, undoing a restore, scheduling, offsite copies. No shell required. |
| [**When Pontifex refuses**](docs/when-pontifex-refuses.md) | Pontifex refuses things deliberately, and a refusal looks exactly like a bug from the outside. Every refusal it makes: what it means, whether it is protecting you, the correct remedy — and the plausible-looking wrong one. **Read this before working around anything.** |
| [**Technical reference**](docs/reference.md) | The complete CLI surface, the export and restore pipelines in execution order, integrity and encryption, concurrency, and every limit. |

**Deeper reference.**

- [`docs/archive-format.md`](docs/archive-format.md) — the `.wpmig`
  specification: the bytes-on-disk contract, locked at 1.1.
- [`docs/archive-format-design.md`](docs/archive-format-design.md) — why
  the format is shaped the way it is.
- [`docs/threat-model.md`](docs/threat-model.md) — attack surface, and what
  is deliberately not defended.
- [`docs/PRIVACY.md`](docs/PRIVACY.md) — exactly what does and does not
  leave the machine.
- [`docs/using-destinations.md`](docs/using-destinations.md) — configuring
  an offsite SFTP destination.
- [`docs/roadmap.md`](docs/roadmap.md) — release-by-release scope, and why
  each deferred item waits.
- [`docs/idea-bank.md`](docs/idea-bank.md) — ideas under consideration, with
  deferral and rejection reasoning.
- [`docs/adr/`](docs/adr/) — Architecture Decision Records: the significant
  technical choices, dated, with any revision recorded as an appended,
  dated amendment rather than a rewrite.

---

## Quick start

```bash
# Check this server can do everything Pontifex needs
wp pontifex doctor

# Pack content and database into one archive
wp pontifex export --output=/path/to/site.wpmig

# Check the archive without restoring it
wp pontifex verify /path/to/site.wpmig

# Restore it — preview first, then for real
wp pontifex import /path/to/site.wpmig --dry-run
wp pontifex import /path/to/site.wpmig

# Move a site to a new address (serialised-data-safe)
wp pontifex import /path/to/site.wpmig --url=https://new-site.example

# Undo the most recent import
wp pontifex rollback
```

A full walkthrough, including the admin screens, is in
[Using Pontifex](docs/guide.md).

> **Importing writes an archive's content onto your site** (and its
> WordPress core too, with `--whole-site`). Only import a `.wpmig` you
> produced or fully trust — see
> [the import trust boundary](.github/SECURITY.md#the-import-trust-boundary).

---

## Commands

| Command | What it does |
|---|---|
| `export` | Pack the site into a `.wpmig`. Content-only by default; `--whole-site`, `--files-only`, `--db-only` change the scope; `--exclude` / `--exclude-table` / `--exclude-file` narrow it. `--encrypt` (AES-256-GCM, Argon2id), `--sign`, `--resumable`, `--destination`. |
| `import` | Restore an archive, taking a safety archive first. `--url` migrates to a new address; `--dry-run` writes nothing; `--public-key` makes the signature mandatory. |
| `verify` | Check an archive's integrity without restoring. `--list` prints its contents. |
| `rollback` | Undo the most recent import from its safety archive. |
| `doctor` | Read-only environment audit — memory, execution time, symbolic-link support, disk space, database version. |
| `schedule` | Automatic daily or weekly backups at a UTC hour, with retention pruning. |
| `destination` | Configure an SFTP server you own for offsite copies, and pull an archive back. |
| `keygen` | Generate an Ed25519 keypair for signing. |
| `stats` / `diagnostics` | Local activity readout, and a sanitised support bundle. |

Every flag is documented in the [technical reference](docs/reference.md), or
run `wp pontifex <command> --help`.

The admin screens cover the everyday operations: Backup with live progress
and cancel, re-attaching to a running job after a reload, Verify, and
Restore with its pre-restore safety archive and chunked upload of a backup
taken elsewhere. Whole-site backups, encryption, signing and offsite
destinations stay command-line features; the admin refuses an encrypted or
whole-site archive and points at the matching command.

---

## Requirements

- PHP 8.2 or newer
- WordPress 6.5 or newer (tested up to 7.0)
- MySQL 5.7+ or MariaDB 10.4+

## Development

```bash
git clone https://github.com/7Duckie/pontifex.git
cd pontifex
composer install
```

Symlink or copy the directory into `wp-content/plugins/` and activate.

For a throwaway WordPress to develop against, the repo ships a
[wp-env](https://www.npmjs.com/package/@wordpress/env) configuration, pinned
in `package.json`. With Docker running:

```bash
npm ci
npx @wordpress/env start
```

That gives you WordPress on `http://localhost:8910` with Pontifex active.
The integration suite runs against a second wp-env configuration
(`.wp-env.tests.json`, port 8911) —
[`.github/CONTRIBUTING.md`](.github/CONTRIBUTING.md) has the exact commands,
the commit conventions, and the quality gates.

Security reports: [`.github/SECURITY.md`](.github/SECURITY.md), via GitHub's
private vulnerability reporting.

## Roadmap

v0.1.0 through v1.1.0 have shipped — round-trip baseline, verification and
rollback, cross-URL migration, encryption and signing, observability, the
admin interface, resumable and scheduled exports, selective content, offsite
destinations, the v1.0.0 stable surface, the v1.0.1 to v1.0.3 security and
correctness patches, and the v1.1.0 agreement between verifying and restoring.

Not yet committed to a release: push/pull host-to-host transports, multisite
support, and a standalone Go reader for `.wpmig`.

[`docs/roadmap.md`](docs/roadmap.md) is the source of truth, version by
version, including why each deferred item waits.

## Changelog

[`CHANGELOG.md`](CHANGELOG.md).

## Licence

GPL-2.0-or-later. See [`LICENSE`](LICENSE).
