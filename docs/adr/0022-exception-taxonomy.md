# 0022 — three kinds of refusal, so a caller can tell them apart

- **Status:** Accepted, 2026-08-05. Implemented and shipping in v1.0.2.
- **Deciders:** 7Duckie (raised by a full-build audit of v1.0.1).

## Context

The safety core throws roughly 279 bare SPL exceptions — 131 in
`Archive/Format`, 79 in `Archive/Reader`, 62 in `Restore`, 7 in
`Archive/Crypto` — almost all of them `RuntimeException` or
`InvalidArgumentException`. The message says what went wrong; the *type* says
nothing.

That has a visible consequence. Every admin surface catches `Throwable` and
answers with one generic sentence, because it has no way to tell a hostile
archive from a full disk. `docs/when-pontifex-refuses.md` had to open with a
section explaining that the browser will not tell you what happened and you
must read the log — a documentation workaround for a typing problem.

It also removes the distinction the user most needs. Three situations demand
three different responses:

- **The archive cannot be trusted.** Malformed, truncated, tampered with, or
  internally contradictory. Do not restore it; the file is the problem. Some of
  these are deliberate attacks — a manifest that disagrees with its own records,
  a symlink pointing at `wp-config.php`, SQL that is not a sanctioned shape.
- **This host cannot do it.** No disk space, `symlink()` unavailable, not enough
  memory to hold the manifest, a directory that will not accept a write. The
  archive may be perfectly good; the environment is the problem, and it is
  usually fixable.
- **The request itself was wrong.** A path that is not absolute, two mutually
  exclusive flags, a retention below the floor. Nothing is wrong with anything;
  the invocation needs correcting.

A caller that can distinguish those can say something useful. One that cannot
says "check the log".

Five typed exceptions already exist — `CodecException`, `CipherException`,
`SignatureException`, `DestinationException`, `ManifestTooLargeException` — and
one of them proves the value: `BackupController` catches
`ManifestTooLargeException` specifically, ahead of its generic handler, and
shows the engine's own message verbatim. It is the only engine message that
reaches a browser intact.

## Decision

**Introduce a three-way taxonomy under one marker interface, and adopt it
incrementally rather than in one sweep.**

```
Pontifex\Exception\PontifexException          (interface — marker)
├── ArchiveNotTrustworthy   extends RuntimeException        implements PontifexException
├── HostCannotComply        extends RuntimeException        implements PontifexException
└── InvalidRequest          extends InvalidArgumentException implements PontifexException
```

An interface rather than a base class, because the three already have different
natural SPL parents and because the existing five typed exceptions can adopt the
marker without changing what they extend — `CodecException` and `CipherException`
are archive-trust failures, `DestinationException` is a host failure, and none of
them needs to move in the hierarchy to say so.

Extending the SPL types keeps every existing `catch ( RuntimeException )` and
`catch ( InvalidArgumentException )` working. This change adds information; it
removes none. That property is what makes incremental adoption safe.

**Adoption is staged, and the stages are ordered by what a user gains:**

1. The types, the marker, and adoption at the sites whose distinction a surface
   can actually act on today — the restore preflights, the archive-structure
   refusals, and the CLI usage gates.
2. The admin controllers branch on the taxonomy instead of collapsing to one
   sentence.
3. The remaining sites, converted as their files are touched for other reasons.

## Consequences

The admin layer can finally say which of the three happened, which is the point
of the exercise. `docs/when-pontifex-refuses.md` becomes a reference rather than
a substitute for the interface telling you anything.

Catching `PontifexException` distinguishes "Pontifex refused" from "something
else broke" — a PHP `TypeError`, a `Random\RandomException` — which is a
distinction no current handler can make, and which matters because those two
warrant different advice.

**We are deliberately not converting all 279 sites in one change.** A sweep of
that size across the safety core, for a benefit that is diagnostic rather than
behavioural, is the shape of change that introduces a defect while fixing
nothing — and this project has already had two audits overturn work that passed
every gate. The types are worthless if adding them breaks a guard.

**The taxonomy will occasionally be ambiguous, and the tie-break is recorded
here:** when a refusal could plausibly be read as either, prefer
`ArchiveNotTrustworthy`. An archive wrongly suspected costs the operator a
re-download; a host problem wrongly reported as trustworthy costs them a restore
they should not have run. The messages stay as they are either way — the type is
additional signal, never a replacement for saying what happened.

**No message text changes as part of this.** Message wording has been corrected
repeatedly this cycle on its own merits; entangling that with a typing change
would make both harder to review and would put user-visible text into a refactor.

## Alternatives considered

**A base `PontifexException extends RuntimeException`.** Rejected: it forces the
usage-error cases to extend `RuntimeException`, when `InvalidArgumentException`
is what they mean and what callers already catch. It would also have made the
five existing typed exceptions change parents to join the family.

**Error codes on one exception type.** Rejected: a caller then branches on an
integer, which PHP cannot check, and `catch` — the language's own dispatch —
stops being usable for the thing it exists for.

**Leave it alone and document the limitation.** This was in effect the status
quo, and it produced a user guide whose first section explains that the software
will not tell you what went wrong. The documentation was doing the type system's
job.
