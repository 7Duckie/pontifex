# Privacy

Pontifex is privacy-first by construction.

## What Pontifex collects

**Nothing.** Pontifex sends no data anywhere. It has no telemetry, no
analytics, no "phone home", and makes no outbound network requests. All
work — export, import, logging, counters — happens locally on the
machine running WP-CLI, on data the Pontifex authors never see.

## What Pontifex stores, and where

- **The archive (`.wpmig`)** you create, at the path you choose. It
  contains your site's content and the whole database — user
  accounts, password hashes, secret keys, and customer data. Treat it
  as highly sensitive: store it outside the web root and delete it
  securely when you are done. See
  [the import trust boundary](../.github/SECURITY.md#the-import-trust-boundary).
- **A local log file** at `wp-content/pontifex/logs/pontifex.log`
  (rotating). It records operations and failures for diagnosis; it
  never contains archive contents, secrets, or moved data.
- **Run counters** in a single autoload-off `wp_option`
  (`pontifex_export_stats`, and the import equivalent): attempt /
  success / failure / byte tallies. Numbers only — no content.

## Telemetry

There is none, and any future telemetry would be **opt-in only** and
decided in the open (idea-bank Idea 001, currently deferred
indefinitely). This file is the standing record of that commitment and
the home for any such decision.

## Your responsibilities as an operator

Because a `.wpmig` holds your content and the whole database in one
file, you control its privacy once it leaves WordPress: choose a
secure transfer channel, keep archives out of web-accessible
directories, and delete them when they are no longer needed.
Encryption of archives at rest arrives in v0.2.0
([`roadmap.md`](roadmap.md)).

---

*Placeholder for v0.1.0. Guidance, not legal advice; confirm with a
qualified adviser before any change that makes Pontifex handle personal
data on users' behalf.*
