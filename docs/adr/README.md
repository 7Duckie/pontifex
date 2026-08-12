# Architecture Decision Records

This directory contains the project's Architecture Decision Records
(ADRs) — short, dated, numbered documents capturing the significant
architectural decisions made during Pontifex's development.

## What an ADR is, and why we keep them

A codebase that lasts longer than a few months accumulates decisions
nobody remembers making. "Why is it built this way?" becomes the
question with no good answer, asked by the maintainer six months from
now who is themselves trying to remember, or by a contributor coming in
fresh, or by the security reviewer trying to understand the boundary
they are evaluating. ADRs answer that question at the moment the
decision is made, while the alternatives and the trade-offs are still
fresh, and preserve the answer for whoever needs it later.

An ADR captures three things:

- **Context.** What was the situation? What forces were in play? What
  constraints, what existing code, what user need motivated the
  decision?
- **Decision.** What did we choose? Not just the chosen option but the
  alternatives that were considered and rejected.
- **Consequences.** What follows from this choice? What does it make
  easier, what does it make harder, what does it commit us to for the
  future?

## Conventions

- **Numbered.** ADRs are numbered sequentially starting at `0001`. The
  number is permanent — even if an ADR is later superseded, its number
  is not reused.
- **Dated.** Each ADR records the date the decision was made. This is
  the decision date, not the implementation date.
- **Immutable.** Once an ADR is merged, its content does not change.
  If a decision needs to be revised, a *new* ADR is written that
  references and supersedes the old one. The old ADR stays in place as
  historical record.
- **Kebab-case filenames** with the leading number:
  `NNNN-short-title.md`.
- **Short.** A typical ADR is 1–3 pages. They are not design documents
  — they record decisions, not explore options exhaustively. Detailed
  exploration belongs in [`../archive-format-design.md`](../archive-format-design.md)
  or in the relevant code comments.

## When to write an ADR

Write an ADR when a decision:

- Affects the public API or the format spec.
- Constrains future work (commits the project to a particular path).
- Trades off concerns where the trade-off would surprise a reader of
  the resulting code.
- Could reasonably be revisited later by someone who would benefit
  from knowing why the current choice was made.

Do *not* write an ADR for routine implementation choices, refactors
that follow established patterns, or anything that would be equally
clear from the code itself.

## Format

Each ADR follows this structure:

```markdown
# ADR NNNN — Short title

- **Status:** Proposed | Accepted | Superseded by ADR-XXXX | Deprecated
- **Date:** YYYY-MM-DD
- **Deciders:** names

## Context

What's the situation? What forces are in play?

## Decision

What did we choose? What alternatives did we consider and reject?

## Consequences

What follows from this choice — positive, negative, and neutral?
```

## Active ADRs

- [ADR 0001](./0001-wordpress-context-abstraction.md) —
  WordPressContext as a separate abstraction from Environment.
- [ADR 0002](./0002-composer-audit-strictness.md) —
  Composer audit strictness: report abandoned, fail on advisories.
- [ADR 0003](./0003-strict-version-stamping.md) —
  Strict version stamping: every tag matches the in-file version.
- [ADR 0004](./0004-same-url-import-scope.md) —
  v0.1.0 import restores to the same URL (URL rewriting deferred).
- [ADR 0005](./0005-rollback-safety-archive-policy.md) —
  Rollback: pre-import safety archive (location, retention, default-on).
- [ADR 0006](./0006-cross-url-via-post-restore-search-replace.md) —
  Cross-URL migration via a post-restore guarded search-replace.
- [ADR 0007](./0007-branch-promotion-model.md) —
  Branch promotion model: feature -> dev -> staging -> main, tiered gates.
- [ADR 0008](./0008-content-only-backup-scope.md) —
  Backups are content-only by default (wp-content + database); whole-site opt-in.
- [ADR 0009](./0009-atomic-staging-table-restore.md) —
  Restore replays into staging tables and cuts over with one atomic RENAME.
- [ADR 0010](./0010-streaming-restore-read-path.md) —
  Restore reads stream what can stream; verify before decode.
- [ADR 0011](./0011-consistent-snapshot-export.md) —
  Exports dump inside a consistent snapshot on a dedicated connection.
- [ADR 0012](./0012-signature-enforcement-policy.md) —
  A supplied or pinned trusted public key makes the signature mandatory.
- [ADR 0013](./0013-truthful-capture-of-files-changing-mid-backup.md) —
  Files changing mid-backup: record what was read, warn, refuse the lie on restore.
- [ADR 0014](./0014-background-execution-model.md) —
  Background work: WP-Cron plus a self-continuing step runner, no job-queue library.
- [ADR 0015](./0015-resumable-export-mechanics.md) —
  Resumable exports: the progress log is the truth; drift refused; DB in one snapshot tick.
- [ADR 0016](./0016-partial-scope-backups.md) —
  Files-only and db-only backups: two booleans, includes_files emit-only-when-false, restore fails closed on a self-contradicting archive.
- [ADR 0017](./0017-offsite-destination-adapters.md) —
  Offsite destinations: a DestinationAdapter seam uploads a finished archive to storage the user owns; CLI-first, host-key pinned, credentials by env-var reference, put paired with pull. Revised 2026-07-14 — SFTP only ships; the S3 adapter is deferred.
- ADR 0018 — Reserved. The number is deliberately held for a decision not
  yet written; it is not a gap in the record and is not available for reuse.
- [ADR 0019](./0019-db-chunk-statement-containment.md) —
  db_chunk statements are anchored to the engine's own staging identifier by exact allow-listed shape, not by parsing a verb; CREATE VIEW is refused.
- [ADR 0020](./0020-signature-enforcement-on-the-upload-path.md) —
  A pinned trusted public key is enforced in the browser on the upload path, the only moment an archive's arrival from outside can be observed; local, scheduled and safety archives stay exempt so no recovery path can lock out.
- [ADR 0021](./0021-symlink-target-confinement.md) —
  A symlink's target, not just its path, is confined to the site root by resolving it the way the kernel would, over the archive's whole declared set of links at once, before the first byte of a restore is written.
- [ADR 0022](./0022-exception-taxonomy.md) —
  Three kinds of refusal — an untrustworthy archive, a host that cannot
  comply, an invalid request — under one marker interface, so a caller can
  tell them apart instead of collapsing every failure into one message.
  Adopted incrementally; the SPL parents are kept so no existing catch changes.
- [ADR 0023](./0023-verify-and-restorability.md) —
  Verify answers for the archive and additionally runs every preflight that
  changes nothing, so it stops calling an archive sound that a restore then
  refuses; a dry run answers for the restore and runs all of them, including
  the one that writes. Refused, cannot-restore-here and could-not-tell are
  three separate outcomes, because each calls for a different response.
- [ADR 0024](./0024-recovery-reverts-by-ledger.md) —
  Recovery after a failed import removes what the restore added, by a ledger
  of what it created rather than by differencing against the safety archive —
  a difference would delete the uploads, caches and logs a live site writes
  during the restore. Makes an existing promise true instead of downgrading it.

## Further reading

The ADR pattern was articulated by Michael Nygard in
["Documenting Architecture Decisions"](https://cognitect.com/blog/2011/11/15/documenting-architecture-decisions)
(2011). The format used here is a lightly trimmed version of his
template.
