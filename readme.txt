=== Pontifex ===
Contributors: 7duckie
Tags: backup, migration, wp-cli, database, restore
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 0.4.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Back up and migrate WordPress — your content and the whole database — in one openly documented .wpmig archive. CLI-first; never phones home.

== Description ==

Pontifex packs your WordPress content — everything under `wp-content` (themes, plugins, uploads) and the whole database — into a single `.wpmig` archive, and restores it onto another WordPress. Pass `--whole-site` to capture the entire installation, WordPress core included, for cloning onto a bare server. Two promises set it apart:

* **The format is documented.** The `.wpmig` archive format is publicly specified, so a backup is never hostage to the plugin: an archive can be read, verified, or recovered without Pontifex.
* **It never touches the cloud.** Pontifex runs entirely on your own infrastructure. It never uploads your data, never phones home, and needs no account.

Pontifex is CLI-first: it is driven through WP-CLI (`wp pontifex …`). An admin UI is planned for a later release.

= What it does =

* `wp pontifex export` — pack your content (`wp-content`) and the database into one `.wpmig` file; `--whole-site` captures WordPress core too.
* `wp pontifex import` — restore an archive onto WordPress, taking a safety archive automatically first.
* `wp pontifex verify` — check an archive's integrity (and its signature, if signed) without restoring.
* `wp pontifex rollback` — undo the most recent import from its safety archive.
* `wp pontifex keygen` with `export --sign` and `verify/import --public-key` — Ed25519 signing and verification.
* `export --passphrase` — optional AES-256-GCM encryption with an Argon2id-derived key.
* `import --url=…` — cross-URL migration, with defences against the classic serialised-data corruption bug.
* `wp pontifex stats`, `diagnostics`, and `doctor` — observability and a sanitised, never-uploaded diagnostics bundle.

= Built for other people's live sites =

Pontifex runs inside live websites, on data its author never sees. It refuses hostile input (decompression bombs, path-traversal symlinks, over-budget entries), restores the database atomically — a failed restore leaves your live tables untouched — takes a safety archive before every restore, and never does naive search-replace over serialised data.

== Installation ==

Pontifex is a WP-CLI plugin.

1. Install and activate the plugin (upload the ZIP via Plugins → Add New, or run `wp plugin install`).
2. Run `wp pontifex doctor` to check your environment.
3. Run `wp pontifex export --output=/path/to/backup.wpmig` to create a backup.

Requires PHP 8.2 or newer and WordPress 6.5 or newer.

== Frequently Asked Questions ==

= Does Pontifex upload my data anywhere? =

No. Pontifex never contacts any remote service. Everything happens on your own server.

= Can I read a backup without the plugin? =

Yes. The `.wpmig` format is publicly documented, so an archive can be inspected and recovered independently of Pontifex.

= Is there an admin UI? =

Not yet. Pontifex is currently driven through WP-CLI. An admin UI is on the roadmap.

= Can I migrate to a different site URL? =

Yes, with `wp pontifex import --url=…`, which rewrites the database safely (including serialised data) rather than doing a naive search-replace.

= Are backups encrypted? =

Optionally. Pass `export --passphrase` for AES-256-GCM encryption with an Argon2id-derived key. Archives can also be signed with Ed25519 keys.

== Changelog ==

The full, detailed changelog is maintained in `CHANGELOG.md` in the source repository. Recent releases:

= 0.4.6 =
* Distribution readiness: a wp.org `readme.txt`, a `.distignore` and production build, internationalised CLI output, and Plugin Check tidy-ups. No functional changes to backup or restore.

= 0.4.5 =
* Quality cleanup: dead-code removal, type and analysis tightening, docblock re-sync, a passphrase wording fix, and small hardening.

= 0.4.4 =
* Security leftovers: secret key material is wiped from memory on destruction, and absolute filesystem paths are kept out of the diagnostics bundle and user-facing messages.

= 0.4.3 =
* Correctness fixes.

= 0.4.2 =
* Security hardening from a full audit.

== Upgrade Notice ==

= 0.4.6 =
Distribution readiness and internationalisation; no functional changes to backup or restore.
