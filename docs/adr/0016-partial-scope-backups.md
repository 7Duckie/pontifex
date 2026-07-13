# 0016 — files-only and database-only backups, and how a partial archive stays honest

- **Status:** Proposed, 2026-07-13.
- **Deciders:** 7Duckie (v0.7.0).

## Context

v0.6.0's content-only default (ADR 0008) always captures both halves of a
site: the `wp-content` tree and the whole database. v0.7.0's selective-content
work adds two partial scopes users ask for — a **files-only** backup that skips
the (often large, slow) database dump, and a **database-only** backup with no
files at all.

Three facts shape the decision. First, the restore engine already tolerates a
missing half: a files-only archive replays no database chunks (the
`DatabaseWriter` has an explicit database-less no-op), and a db-only archive
writes no files — both round-trip today, proven by existing integration tests.
Restore is additive and overwrite-only: it never deletes the absent half, so a
partial archive **merges** into the destination, restoring what it carries and
leaving the rest of the live site intact. Second, the `Scope` value object
records what an archive holds, but the only field that ever drove behaviour was
`includes_database`, always written `true`; there was no `includes_files` field
at all. Third, the archive format's `scope` block is byte-pinned by a golden
conformance fixture, so any change to the bytes an existing archive writes is a
format-compatibility event.

## Decision

- **A files-only backup is an existing shape.** It sets `includes_database`
  false — a field the format already carries — and needs no new field and no
  byte change to any other archive.
- **A database-only backup adds one field, `includes_files`.** It is serialised
  **only when false**, so content-only, whole-site, and files-only archives are
  byte-identical to a pre-v0.7.0 archive and the golden conformance fixture is
  unchanged. A reader defaults the field to `true` when absent, so every archive
  ever shipped keeps parsing. This is an additive minor within format major v1.
- **The model is two booleans, not a mode enum.** `includes_files` and
  `includes_database` fully describe the four shapes (content, whole-site,
  files-only, db-only); a partial backup stays `content_only = true`, so the
  existing content-only restore gate accepts it without a `--whole-site`
  escape hatch.
- **A backup must carry at least one half.** `Scope` refuses a scope with
  neither files nor the database — an archive of nothing is never intended.
- **Restore fails closed on a self-contradicting archive.** If an archive's
  recorded scope declares a half absent while its manifest actually carries it
  (files-only-but-has-db-chunks, or db-only-but-has-file-entries), the restore
  refuses it. Pontifex's own exports never contradict their scope, so this only
  catches a corrupt or hand-forged archive — but restoring contents the scope
  denies would be a silent lie, so it is refused rather than trusted.
- **The admin surface does not choose a partial scope.** Per ADR 0008 the admin
  backup stays content-only (both halves); files-only and db-only are CLI
  operations (`export --files-only` / `--db-only`), mutually exclusive with each
  other and with `--whole-site`. The admin surface only *displays* an archive's
  scope (a later slice).
- **The safety archive stays full.** The pre-restore safety archive is never
  partial regardless of any backup scoping — it must remain a complete net.

## Consequences

- The manifest builder skips the file scan for a db-only backup and the database
  scan for a files-only backup; the assembly-boundary no-database guard applies
  only when the database was meant to be captured, so a files-only backup is not
  mistaken for a lost database.
- A resumable partial export reads which halves to capture from the recorded
  scope on every tick, so a files-only or db-only resume omits the same half
  deterministically and the resume drift check is unaffected.
- A partial restore is a merge: sibling tables and untouched files survive, as
  ADR 0009 already documents for the database side.

## Alternatives considered

- **A `mode` enum field on `Scope`** (content / whole-site / files-only /
  db-only). Clearer to read, but a new required field `from_array` would have to
  tolerate as optional anyway, and two booleans already describe every shape
  with no new required field. Rejected for the smaller, byte-stable change.
- **Regenerating the golden conformance fixture to always carry
  `includes_files`.** Simpler serialisation (always emit), but it changes the
  bytes of every existing archive shape and breaks byte-identity with archives
  already in the wild. Rejected in favour of emit-only-when-false.
- **Trusting a self-contradicting archive** (restore whatever entries are
  present regardless of the recorded scope). Rejected: a fail-closed refusal is
  the project's posture for input that cannot be both true and internally
  consistent.
- **Row-level or content-type database filtering.** Out of scope: only
  whole-table exclusion (ADR/selective-content slice 1) and whole-database
  omission are offered, because partial-row pruning risks a referentially broken
  restore.
