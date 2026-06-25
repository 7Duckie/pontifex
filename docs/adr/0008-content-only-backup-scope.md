# 0008 — Backups are content-only by default (wp-content + database); whole-site is an explicit opt-in

- **Status:** Accepted, 2026-06-25.
- **Deciders:** 7Duckie (v0.5.0 admin-UI work surfaced the question).

## Context

Pontifex has packed the **whole WordPress installation** since v0.1.0. The
file scanner is rooted at `ABSPATH` (`ExportCommand`/`BackupController` both
resolve `rtrim( ABSPATH, '/' )`), and `ExclusionRules::default_v010()` carves
out only Pontifex's own working directory, `wp-content/cache`, and other backup
plugins' directories. WordPress core (`wp-admin`, `wp-includes`, the root core
PHP files) and `wp-config.php` are therefore captured in every archive, and a
restore writes them back over the destination's own core and configuration.

This is at odds with three things at once:

- **The project's own threat model.** `docs/threat-model.md` states that FILE
  paths are validated "relative-and-within-`wp-content`". The documented intent
  was content-scoped; the implementation drifted to whole-`ABSPATH` and the
  drift was never caught, because the round-trip tests restore to the *same*
  path and so pass regardless of whether core belongs in the archive.
- **The stated use case.** Pontifex transfers a site from one *working*
  WordPress to another. A working destination already has core — re-downloadable
  and identical for a given version — so shipping and overwriting it is
  redundant and, on a live site, dangerous: it overwrites the running core and
  `wp-config.php` (the destination's own DB credentials, salts, and table
  prefix) mid-request, and can drag a patched destination back to an older,
  vulnerable core.
- **What comparable tools do.** The mainstream migration plugins that run inside
  a working WordPress back up content (`wp-content`) and the database, not core.
  Only the tools built to deploy onto a bare/empty server — those that ship a
  self-contained installer — bundle core, and that is their distinct purpose,
  not an everyday default.

The whole-site behaviour was made visible for the first time by the v0.5.0 admin
Restore screen, where overwriting core/`wp-config.php`/the live tree is a single
visible click rather than a controlled CLI invocation.

## Decision

**Backups are content-only by default, and the whole-site behaviour is retained
as an explicit, off-by-default opt-in.**

1. **Default scope is content-only.** Export scans `WP_CONTENT_DIR` (plugins,
   themes, uploads, mu-plugins, drop-ins) and packs the **whole database**. Core
   and `wp-config.php` are neither backed up nor restored. "Content-only" scopes
   the *files*; the database is still captured in full.

2. **Entry paths stay `ABSPATH`-relative (i.e. `wp-content/…`-prefixed).** The
   scan root changes; the recorded path convention does not. This keeps the
   archive in the same family as every full-site-capable backup tool (all of
   which store `ABSPATH`-rooted paths), and makes content-only a strict
   *subset* of the whole-site entry set under one scheme — so restore, the
   exclusion patterns, and the documented `.wpmig` path convention are unchanged.

3. **Whole-site (incl. core) is an explicit opt-in, off by default.** A
   `--whole-site` flag on `wp pontifex export` selects the original `ABSPATH`
   scan, for the "clone onto a bare/empty server" case. It is steered to fresh
   destinations, not restore-over-a-live-site, and is the foundation for a future
   explicit clone mode — it is preserved, not removed.

4. **The archive records its scope.** Provenance gains a `scope` block
   (`content_only`, `content_root`, `includes_core`, `includes_wp_config`,
   `includes_database`, `excluded_paths`) and the source **`table_prefix`**.
   These are additive provenance fields, so per the format's own compatibility
   rule (`archive-format.md §13`) they are a **v1.1 minor** change, not a
   breaking one: old readers ignore them and old archives still read.

5. **Restore is scope-aware and fails closed.** A content-only restore writes
   only under `WP_CONTENT_DIR` and refuses any entry that resolves outside it. An
   archive made before this change carries no `scope` block; it is treated as a
   legacy whole-site archive and **refused** by a content-only restore, with a
   pointer to the whole-site/CLI path — never silently overwriting the
   destination's core or `wp-config.php`.

6. **The database restore rewrites the table prefix to the destination's.**
   Because content-only keeps the destination's own `wp-config.php` (and thus its
   `$table_prefix`), the database — which embeds the source's prefixed table
   names — is rewritten to the destination prefix on restore: the tables are
   renamed and the known prefix-bearing rows updated (`{prefix}user_roles` in
   `wp_options`, and the anchored `{prefix}…` `meta_key` set in `wp_usermeta`).
   This matches the prevailing approach among migration tools. The prefix lives
   only in table identifiers and plain key columns, never inside a serialised
   value, so —
   unlike URL rewriting (ADR 0006) — it is a bounded operation, not a
   serialised-length hazard.

**Alternatives considered and rejected:**

- **Exclude core via a blocklist** (keep the `ABSPATH` scan, add `wp-admin/**`,
  `wp-includes/**`, etc. to the exclusion list). Rejected: a blocklist that
  forgets a future core file silently leaks core — exactly the drift class that
  produced this problem — and it cannot follow a relocated `WP_CONTENT_DIR`.
  An include-model (scan `WP_CONTENT_DIR`) is correct by construction.
- **Content-relative paths** (record `themes/x`, no `wp-content/` prefix).
  Rejected: it cannot represent core at all, so the whole-site mode would need a
  second path convention. Root-relative keeps one scheme for both modes.
- **Add `.git` to the default exclusions** to "fix" the read-only-restore
  failure that first exposed this. Rejected: no comparable tool excludes
  version-control directories by default, it silently loses data for
  git-deployed sites, and it would not address the general read-only-target
  problem.
- **Refuse on a table-prefix mismatch** instead of rewriting. Rejected: every
  serious migration tool rewrites the prefix to the destination's; refusing
  would put Pontifex below the field's baseline for a bounded, well-understood
  operation.

## Consequences

- The default archive is smaller and no longer carries WordPress core or secrets.
  `wp-config.php` — the threat model's own example of a hostile import payload —
  is no longer written on restore, narrowing the import trust boundary.
- `docs/threat-model.md`'s "within-`wp-content`" guarantee becomes true; the
  code-versus-doc drift closes, and the restore writer enforces the boundary.
- Restoring between two working sites with **different** table prefixes now works
  correctly, which it did not before (full-site mode only "worked" by also
  overwriting `wp-config.php`, so the two happened to agree).
- Archives made before this change still read, but a content-only restore refuses
  them and points to the whole-site/CLI path; the whole-site mode replays them as
  before.
- Whole-site mode retains the overwrite, stale-core, and read-only-target hazards,
  so it is documented as a fresh/empty-destination clone path, guarded against
  live-site restore — not an equal everyday choice. The full clone experience
  (a bare-server install flow and its own guards) is future work that builds on
  the retained whole-site code.
- This supersedes the implicit whole-site scope. A future proposal to change the
  default scope must supersede this ADR explicitly rather than relitigate it
  (per the precedent of ADR 0004).
