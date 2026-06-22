# 0005 — rollback: pre-import safety archive (location, retention, default-on)

- **Status:** Accepted, 2026-06-22.
- **Deciders:** 7Duckie (v0.2.0 slice 2 planning).

## Context

Import is the most dangerous thing Pontifex does — it overwrites the live
site's files and database. v0.1.0 shipped `--dry-run` and same-URL-only as
its rails, but there is no undo: a mistyped path or the wrong archive turns a
five-minute mistake into a destroyed site (Idea 009 / audit IDEA-1). Rollback
is the committed v0.2.0 answer, and it composes engines that already exist —
the export pipeline writes a safety archive of the current site, the import
pipeline restores it.

Three questions had to be settled before building (§9.3 Q1): where the safety
archive lives, how many are kept, and whether taking one is the default.

## Decision

- **A safety archive is taken by default** before `wp pontifex import` restores.
  It is a full export of the current site, written *before* the destructive
  restore begins, so a failed safety archive aborts the import before it touches
  the site. `--no-rollback-archive` opts out; `--dry-run` skips it (it changes
  nothing); `--yes` still takes one (it is safety, not confirmation).
- **Location:** `wp-content/pontifex/rollback/`, beside the existing
  `…/pontifex/logs/`, created **not world-readable** (directory mode 0700,
  archive files 0600). A safety archive contains the entire database, so it is
  treated as the sensitive artefact it is (C-ARCHIVE-SENSITIVE, §8.4) — more
  restrictively than the v0.1.0 log directory.
- **Naming:** `pre-import-rollback-<UTC>.wpmig` (e.g.
  `pre-import-rollback-20260622T143000Z.wpmig`), so the most recent safety
  archive is the lexicographically last.
- **Retention:** keep the **most recent only** (N = 1); older safety archives
  are pruned after a new one is written. Each archive is roughly the size of the
  whole site, so unbounded retention would fill the disk; keeping one bounds the
  cost to a single doubling and gives a clear "undo your last import" promise.
  Auto-delete-on-success was rejected (a successful import can still be the wrong
  one); never-delete was rejected (unbounded disk). A configurable count can
  follow if operators ask for deeper history.
- **`wp pontifex rollback`** restores the most recent safety archive (mirroring
  import's `--yes` / `--dry-run`); it does **not** take a nested safety archive
  of its own.
- A free-disk **preflight** estimates the archive size (from the manifest's file
  sizes) and warns before starting; it is best-effort, because the real
  guarantee is the write-before-restore ordering above.

## Consequences

- Import does more by default (extra time + disk for the safety archive); the
  doubled-disk cost is documented and the preflight warns before starting.
- The undo is bounded to the last import. Operators needing deeper history use
  the full export/backup feature; a configurable retention count can follow.
- The export pipeline is reused through a new `SafetyArchiver` seam; migrating
  `ExportCommand` onto it is a later cleanup, not part of this slice.
- A future `wp pontifex reset` reuses the same safety-archive pattern.
