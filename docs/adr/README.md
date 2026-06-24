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

## Further reading

The ADR pattern was articulated by Michael Nygard in
["Documenting Architecture Decisions"](https://cognitect.com/blog/2011/11/15/documenting-architecture-decisions)
(2011). The format used here is a lightly trimmed version of his
template.
