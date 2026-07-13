# 0009 — restore: replay into staging tables, cut over with one atomic RENAME

- **Status:** Proposed, 2026-07-11.
- **Deciders:** 7Duckie (v0.5.0 hardening).

## Context

A restore replays each table's chunks in order, and every table's first chunk
carries `DROP TABLE IF EXISTS` + `CREATE TABLE` — so the live table is
destroyed the moment its first chunk executes. A failure mid-replay (a corrupt
chunk, a lost connection, an out-of-memory fatal) therefore leaves the live
database in a mixed state: some tables restored, one partial, the rest already
dropped or not yet touched. This is the engine audit's root cause A, and it is
the failure mode that destroyed a live site during the v0.5.0 hardening review.

Two layers of defence already exist, and both are recovery, not prevention: the
pre-import safety archive (ADR 0005, floor of two — amended 2026-07-11) and the
automatic roll-back that replays it when a restore fails. Recovery replays the
whole site backwards through the same non-atomic engine; prevention means a
failed restore never touches the live tables in the first place.

Transactions cannot provide that prevention on MySQL: `DROP TABLE` and
`CREATE TABLE` are DDL, and DDL causes an implicit commit, so a
transaction-wrapped restore silently commits at every table boundary.

## Prior art

This is a solved problem outside WordPress. Percona's pt-online-schema-change,
GitHub's gh-ost, and Vitess all migrate a table by building a complete shadow
copy beside the live one and then cutting over with an atomic `RENAME TABLE` —
the live table is never mutated; it is atomically replaced. MySQL's manual
guarantees the primitive: a `RENAME TABLE` naming any number of tables is a
single atomic operation, no other session can access the involved tables while
it runs, and **if any error occurs the statement fails and no changes are
made**. The same guarantee holds on MariaDB.

## Decision

A restore replays the entire database into **staging tables** and cuts over
with **one atomic `RENAME TABLE`** only after every chunk has replayed and
verified clean:

- **Staging replay.** During the restore walk, every db_chunk's table
  identifier is rewritten to `pontifexstg_<destination table>` — riding the
  same full-identifier, backtick-quoted rewrite mechanism the cross-prefix
  restore already uses (ADR 0008). All `DROP`/`CREATE`/`INSERT` statements
  therefore execute against staging tables; the live tables are not read,
  written, or locked during the replay.
- **Key-column finalisation on staging.** The cross-prefix
  options/usermeta key rewrite runs against the staging tables, before the
  swap, so the cut-over installs fully-finalised tables.
- **Atomic cut-over.** One `RENAME TABLE` statement moves, for every restored
  table `T`: `T → pontifexold_T, pontifexstg_T → T` (a table new to the
  destination simply moves `pontifexstg_T → T`). MySQL performs the whole
  statement atomically: after it, the database is entirely the restored one;
  if it errors, the database is entirely the pre-restore one.
- **Cleanup.** On success, the `pontifexold_*` tables are dropped
  (best-effort; the safety archive remains the undo). On any failure before
  the swap, the staging tables are dropped (best-effort) and the live
  database is untouched — the operator message "your site was not changed"
  becomes literally true for database failures. Leftover `pontifexstg_*` /
  `pontifexold_*` tables from a crashed run are dropped at the start of the
  next restore; the single-runner lock guarantees no concurrent run owns them.
- **Fail-closed length guard.** MySQL caps table names at 64 characters. Any
  table whose staged name would exceed that is refused **before any write**,
  naming the offending tables.
- **Rollback inherits atomicity.** `wp pontifex rollback` and the automatic
  roll-back replay through the same engine, so they gain the same guarantee.

## Consequences

- The database's disk footprint roughly doubles while a restore runs (staging
  beside live, then old beside new until the drop) — the same order of cost
  ADR 0005 accepted for the safety archive, and transient. The existing
  free-disk preflight does not see database server disk; the doubled-DB cost
  is documented rather than preflighted (databases larger than half the DB
  server's free space fail the replay with a clean, staging-only error).
- Live tables not present in the archive are left in place, as today.
- Tables whose live copy carries triggers or foreign keys referencing them
  keep those attached to the renamed-away `pontifexold_*` copy — WordPress
  core uses neither; plugin tables that do are a documented limitation shared
  with pt-online-schema-change.
- The recovery layers stay: safety archive + auto-rollback now guard chiefly
  the file half of a restore, and compose with prevention as defence in depth.
- File-side atomicity (wp-content) is out of scope here and remains
  recovery-based; it is a separate, later arc.

## Alternatives considered

- **Transaction-wrapped replay** — impossible on MySQL: DDL implicit commits
  break the transaction at every table boundary.
- **Recovery only (status quo)** — the auto-rollback replays the whole site
  backwards through the same non-atomic engine, so a second failure during
  recovery still strands the site; prevention removes the window instead of
  narrowing it.
- **Restore into a separate database and repoint** — needs `CREATE DATABASE`
  privileges shared hosts do not grant, and `wp-config.php` rewriting; far
  more invasive than a prefix.
