# 0008 — Backups are content-only by default (wp-content + database); whole-site is an explicit opt-in

- **Status:** Accepted, 2026-06-25. Amended 2026-07-16 (`.git` added to the curated default exclusions — see Amendment).
- **Deciders:** 7Duckie (v0.5.0 admin-UI work surfaced the question; amendment: v0.9.3).

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

## Amendment — 2026-07-16: `.git` added to the curated default exclusions

**This explicitly supersedes** the "Add `.git` to the default exclusions" entry
under "Alternatives considered and rejected" above, on new information.

**New information:** the original rejection reasoned from a hypothetical. A real
one arrived: the plugin's own dev-site bind mount — a live, git-deployed
`wp-content/plugins/pontifex` checkout — carries a `.git` directory into every
backup, and a restore's safety archive backed up 695 MB of it alongside `vendor`
and `node_modules`. A working copy deployed from git is an ordinary, common way
to run a WordPress plugin or theme directory, not an edge case; the observation
generalises to any git-deployed site, not only this dev environment. Two further
considerations sharpen the
original rejection:

- **`.git/time-travel` is a restore hazard, not just a size cost.** A restore
  that writes a stranger's `.git` history over a live, git-deployed directory
  can silently rewind that directory's working tree relative to its own commit
  history the next time git is used against it — a correctness hazard on top of
  the size one.
- **A `.git`-carrying `.wpmig` is a secret-bearing artefact.** Full commit
  history — author identities, commit messages, anything ever committed
  including since-removed secrets — travels inside every archive and every
  place that archive is handed around (an offsite destination, a colleague,
  cross-server import), which is a materially larger exposure than the archive
  not existing at all.

**What makes this NOT the curated drop-list the original ADR decision (and
`test_default_v010_keeps_other_plugin_directories`) guards against:** the
guard is about *site content* — data another plugin wrote, which is the site
owner's and stays in by default. `.git` is not site content; it is
version-control metadata *about* the content, generated and owned by git,
regenerable in full from the same remote the working copy was cloned from. A
backup exists to recover content that cannot be regenerated elsewhere; `.git`
fails that test in a way `wp-content/some-plugin/backup.zip` does not. This is
also why the new default is a single, narrowly-scoped, self-documenting
pattern — `.git` directories specifically, at any depth — rather than a
reopening of the curated-drop-list question generally.

**Amended decision:** `ExclusionRules::default_v010()` gains a third default
exclusion, `.git` (any depth, matched as `/(^|\/)\.git(\/|$)/` — a regex, not a
directory-tree pattern, because a `.git` directory's position is not fixed the
way `wp-content/cache` is; see the class docblock in
[`src/Manifest/ExclusionRules.php`](../../src/Manifest/ExclusionRules.php)).
Like the existing two defaults, it is visible in the printed exclusion summary,
overridable with `--no-defaults`, and does not change the underlying
`ExclusionRules` pattern language — only the curated list gains an entry.

**`vendor/` and `node_modules/` are deliberately NOT added, and this is the
part of the amendment meant to stop that question recurring.** They differ from
`.git` in kind, not just degree:

- **`vendor/` is required at runtime.** WordPress.org performs no build step; a
  restored site's `vendor/autoload.php` is `require`d on every request. Excluding
  it produces a site that restores "successfully" and then fatals — silent data
  loss of the operational kind ADR 0013 exists to prevent, not a saved byte.
- **`node_modules/` is the same failure class, merely rarer.** A theme can
  legitimately `wp_enqueue_script()` a file that lives under `node_modules/`, and
  nothing on a restored production site runs `npm install` to regenerate it.
  Excluding it risks the identical "restores, then breaks" failure `vendor/`
  would produce, for a smaller but real set of sites.
- **No comparable tool excludes either by default**, which was already the
  standing reason the original `.git` proposal was rejected; that reasoning
  still holds for `vendor/` and `node_modules/` specifically, because — unlike
  `.git` — both are runtime dependencies of the running site, not metadata
  about how the site's own source got there.

The size win this amendment buys is real but narrower than the number that
prompted it: the dev-site's 695 MB figure was `.git` **plus** `vendor` **plus**
`node_modules` combined, so excluding only `.git` will not shrink that
particular bind-mounted dev site by anything close to 695 MB. The `.git`
default's real-world payoff is a genuinely git-deployed production site (a
common WordPress deployment pattern outside this dev environment), where the
entire commit history — not just a large-but-static dependency tree — is what
gets carried into, and then back out of, every backup.

**Cost:** a site that (unusually) relies on Pontifex to carry its `.git`
directory loses that copy by default; `--no-defaults` restores the old
behaviour (all three curated defaults are opted out together — there is no
per-default toggle) for anyone who wants it.
