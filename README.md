# Pontifex

A free, open-source WordPress migration plugin with a documented archive format and first-class rollback.

**Status: pre-alpha.** Pontifex is in early development. The current working feature is the `wp pontifex doctor` environment-audit command. Do not use Pontifex on production sites yet.

## What Pontifex will be

Pontifex aims to be the WordPress migration plugin that does it right:

1. **Genuinely free at every capability level.** No file-size caps, no bandwidth caps, no Pro tier behind which the features you actually need are hidden.
2. **Documented, versioned archive format.** The `.wpmig` format is a public specification. Your migration artefacts belong to you, not to a vendor.
3. **Rollback as a first-class feature.** Pontifex takes an automatic pre-import snapshot before any destructive step. One command to restore.

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

Then symlink or copy the directory into `wp-content/plugins/` and activate.

## Usage

```bash
wp pontifex doctor
wp pontifex doctor --format=json
wp pontifex doctor --fields=category,name,status
```

## Roadmap

- **v0.1.0 (Phase 1)** — Job Engine, archive Writer/Reader, FullSiteReader, encryption, WP-CLI export
- **v1.0.0 (Phase 2)** — Destination side: import, search-replace, rollback, admin UI
- **Beyond v1.0** — Push/pull transports, cloud storage, multisite, selective content, scheduled exports, backup mode

See `docs/` for the full design document once published.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Security reports: see [SECURITY.md](SECURITY.md).

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE).
