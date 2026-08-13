# 0022 — three kinds of refusal, so a caller can tell them apart

- **Status:** Accepted, 2026-08-05. Implemented and shipped in v1.0.2. Amended
  2026-08-12 (adoption at the throw site is not enough on its own — see
  Amendment).
- **Deciders:** 7Duckie (raised by a full-build audit of v1.0.1; amendment:
  raised by the 2026-08-09 audit and stress campaign, cluster A).

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

## Amendment — 2026-08-12: a type thrown is not a type consumed

**New information:** the 2026-08-09 audit and stress campaign found that
staged adoption at the throw site, on its own, guarantees nothing about what
the consumer does with it. `RestoreController::verify_gate()`,
`Admin\VerifyController`'s verify action and `Cli\VerifyCommand`'s verify path
each caught `Throwable` and defaulted to a "broken" outcome for anything that
was not sorted into a refusal by inspecting the preflight report afterwards —
so a caught `HostCannotComply`, thrown exactly as this ADR intended, still
landed on "broken" *by construction*, indistinguishable from a genuinely
corrupt archive. No amount of reclassifying individual throw sites could have
closed that gap, because no exception of any type had a route to the outcome
a host problem actually needed; the consumer had to be taught the branch
existed before the type it read meant anything.

Adoption had also simply not reached every site the staged plan (stage 3 in
this ADR: "the remaining sites, converted as their files are touched for other
reasons") always expected it would. `Rollback\SafetyArchiver` and
`Rollback\RollbackStore` still threw a bare `RuntimeException` for pure host
conditions — no free disk space, a manifest too large to read back, a `chmod`
that failed, a directory that would not accept creation — none of which is
evidence against the safety archive itself. `Archive\Reader\ArchiveReader`'s
refusal of a newer archive format threw the same bare type, even though its
own message already named the real cause and the real remedy: upgrade
Pontifex. And `Archive\Reader\EntryReader`'s two codec-decode failure sites
disagreed with each other — one already threw `ArchiveNotTrustworthy`, the
other a bare `RuntimeException` — and both discarded the one sentence that
explained what had actually gone wrong, keeping it only as `$previous`, which
nothing downstream ever rendered.

**Amended decision:** two changes, at the two ends of the gap. First, the
consumers: `RestoreController::verify_gate()`, `Admin\VerifyController` and
`Cli\VerifyCommand` now each catch `HostCannotComply` ahead of their generic
`Throwable` catch and route it to its own outcome — see
[ADR 0023](./0023-verify-and-restorability.md)'s own amendment for that
outcome's full shape — rather than letting it fall through to "broken".
Second, the remaining throw sites: `SafetyArchiver`,
`RollbackStore` and `ArchiveReader`'s major-version refusal now throw
`HostCannotComply` instead of a bare `RuntimeException`, completing this ADR's
stage-3 adoption for the rollback and format-compatibility paths; and both of
`EntryReader`'s codec-decode catches were first made to agree with each other
and to surface the underlying `CodecException`'s own message rather than
replacing it with a generic one — that pass alone left both agreeing on
`ArchiveNotTrustworthy`, which is where the codec-layer addition below picks
up. Everything else in this ADR stands, including its tie-break rule and its
decision not to convert every site in one sweep.

**Follow-up, same day: the codec layer could not express the distinction
`EntryReader` needed.** Making the two `EntryReader` catches agree surfaced a
sharper question underneath: a codec's decode failure is not always the same
*kind* of failure. `ZstdCodec::assert_available()` refuses when ext-zstd is
not loaded — this host cannot decode the payload, though the bytes are fine
and the identical archive decodes cleanly on a host that has the extension —
but it threw the same plain `CodecException` as every genuinely malformed
payload, and nothing distinguished the two at the only place that could:
`EntryReader`'s catch. Folding a missing extension into `ArchiveNotTrustworthy`
is exactly this ADR's mistake, recurring one layer down — the tie-break rule
above says as much explicitly ("when a refusal could plausibly be read as
either, prefer `ArchiveNotTrustworthy`"), but a missing extension is not
plausibly either; it is unambiguously a host fact, and the codec layer simply
had no way to say so.

The fix stays inside `Archive\Codec`, where the ambiguity actually lives: a
new `CodecUnavailableException extends CodecException` (docblock: the bytes
are fine, this host cannot decode them, the same archive restores on a host
that has the extension), thrown from exactly the one call site that knows
this is true — `ZstdCodec::assert_available()`, called from both its
`encode()` and `decode()`. Every other `CodecException` in `ZstdCodec` —
malformed input, a read/write failure, the decompression-bomb refusal — is
untouched and stays a plain `CodecException`, because those genuinely are
about the bytes. `GzipCodec` was checked and has no equivalent guard at all —
it calls `deflate_init()`/`inflate_init()` directly with no availability check
of its own, a separate, already-recorded gap (a zlib-less host fatals at
operation time rather than refusing cleanly) that this change does not touch.
`EntryReader`'s two decode catches now each catch `CodecUnavailableException`
ahead of the plain `CodecException`, mapping it to `HostCannotComply` while
everything else still maps to `ArchiveNotTrustworthy` — both continuing to
surface the underlying message.

**A note for the next site this reaches:** a throw site conforming to this
taxonomy is necessary, not sufficient. Before adopting the taxonomy at a new
site, check that whatever catches it can actually tell the types apart —
otherwise the type is adopted and the information is still lost one frame
later. And per the follow-up above, the taxonomy is not always fine-grained
enough at the *source* either: when one exception class actually covers two
different kinds of fact — a host limitation and a genuine corruption, in this
case — the distinction may need to be built into the layer that throws it,
not guessed at by the layer that catches it.
