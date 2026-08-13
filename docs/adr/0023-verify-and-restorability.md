# 0023 — verify answers for the archive, a dry run answers for the restore

- **Status:** Accepted, 2026-08-05. Implemented and shipped in v1.1.0. Amended
  2026-08-12 (the third outcome had no exception route — see Amendment).
- **Deciders:** 7Duckie (raised by the hardening arc; the last of its open
  findings. Amendment: raised by the 2026-08-09 audit and stress campaign).

## Context

`verify()` read every entry and checked every SHA-256 hash, then reported the
archive **sound**. `restore()` did the same walk and, before it, settled four
further questions:

1. Does the archive's recorded scope contradict the entries it actually carries?
2. Can this host create a symbolic link at all?
3. Does every symbolic link the archive declares resolve inside the site?
4. Does the destination have room?

None of the four ran from `verify()`, on the reasoning that verify writes
nothing and therefore has nothing to preflight. That reasoning holds for exactly
one of them.

The consequence was a verification that promised more than it delivered. An
archive engineered to place a symbolic link outside the site has, by
construction, perfectly valid hashes — nothing about it is corrupt — so verify
walked it without complaint and called it sound. The restore then refused the
identical bytes. The person misled was the one checking their backups, which is
precisely the behaviour a backup tool should reward.

The gap had widened rather than narrowed: v0.9.5 added the symbolic-link
confinement preflight and v1.0.0 added the disk-space and host-capability
preflights, all of them to `restore()` alone, so each release made verify's
verdict a little less predictive than the release before.

`import --dry-run` was worse. It called `verify()` and nothing else, so the flag
whose entire purpose is "tell me whether this would work" ran strictly **fewer**
checks than the operation it claimed to rehearse.

### Prior art

Mature backup tools ship two distinct operations and are explicit that they
answer different questions. Borg has `check` (repository and archive
consistency) alongside `extract --dry-run`, documented as performing "all
extraction steps except actually writing the output data" — including
decryption and decompression, which `check` does not do. Restic has `check` for
repository integrity and `restore --dry-run` to report what a restore would do.

Neither treats a passing `check` as a prediction that extraction will succeed.
Both, however, give you a way to ask the restorability question without
performing the restore. Pontifex had the first without the second.

## Decision

Verify remains the integrity operation, and additionally runs every preflight
that changes nothing. A dry run becomes a real rehearsal of the restore.

The split is decided by one property — **does the check write?** — because that
is what verify's contract actually constrains:

| Check | Writes | verify | `--dry-run` | `restore` |
|---|---|---|---|---|
| Scope contradicts manifest | no | reports | throws | throws |
| Symbolic links confined | no | **refuses** | throws | throws |
| Destination has room | no | reports | throws | throws |
| Host can create a link | **yes** | never | throws | throws |

The host capability probe establishes what a host can do by creating a test
symbolic link and removing it again. That is a real write, however brief, so it
stays out of verify and belongs to callers that were going to write anyway.
`wp pontifex doctor` answers the general question; a dry run settles it for a
specific archive.

### Three outcomes, not two

A finding is sorted into one of three buckets, and the bucket decides what the
reader is told to do:

- **The archive is refused.** It is undamaged — every hash matched — and unsafe.
  This is a verdict of its own, deliberately *not* a variant of "broken",
  because the two call for opposite actions. Telling somebody their backup is
  broken sends them to delete it and reach for another copy. A refused archive
  should be kept, not restored, and traced: a Pontifex export never produces
  one.
- **This host cannot restore it right now.** Reported alongside the verdict and
  never able to change it. A full disk is not a damaged backup, and a surface
  implying otherwise would be the one message capable of talking somebody out of
  a good backup.
- **The check reached no decision.** Reported as neither. Treating "I could not
  answer" as "the answer is no" would accuse a good archive of being hostile.

Sorting reads the exception's type, which is what [ADR 0022](./0022-exception-taxonomy.md)
made possible. The seven confinement refusals in `FileWriter` threw a bare
`RuntimeException` and were converted to `ArchiveNotTrustworthy` as part of this
work — one of the staged conversions ADR 0022 anticipated. The SPL parent is
unchanged, so no handler moved.

### What a verification now means

A verification is a statement about **an archive and a destination**, not about
the file alone. The confinement check resolves through symbolic links that
already exist on the site, so in principle the same archive can report
differently on two machines.

That is deliberate. "Would this escape *your* site" is the only form of the
question worth answering, and answering the general form would mean either
refusing archives that are safe where they will actually be restored, or
accepting ones that are not. The trade is recorded here so it is a decision
rather than a surprise, and `docs/guide.md` says it in plain terms.

## Consequences

**Good.** An operator who verifies a backup is no longer given a promise the
restore does not keep. A hostile archive is caught by the read-only operation
anyone can safely run, rather than at the moment of recovery. `--dry-run` now
does what its name says. The admin Restore preview refuses before taking the
safety archive, saving minutes of work and a second full copy of the site on
disk for a refusal that was knowable up front.

**Costs.** Verify does more work: one extra seek-and-read pass over the symbolic
link entries (a handful on a real site, each a few dozen bytes) plus a stat per
file entry for the free-space estimate. A verification is no longer purely a
property of the archive, as above. And a `verify` that used to exit 0 for a
hostile archive now exits non-zero, which is a behaviour change for any script
gating on it — the intended one.

**Deliberately not done.** Verify does not decode payloads, so it still does not
test a passphrase; borg's dry run does decrypt, and matching that would mean
verify collecting a passphrase it currently never needs. The preflight report is
advice, never the guard: `restore()` runs every one of these checks again and
still fails closed, which is what makes it safe for the reporting path to stay
quiet when a check cannot be evaluated.

## Amendment — 2026-08-12: the third outcome was written down, not built

This ADR called itself "the last of" the hardening arc's open findings. It was
not, and this amendment says so plainly rather than letting the record imply
otherwise.

**New information:** "Three outcomes, not two" above describes a bucket for
"the check reached no decision", reported as neither sound, refused, nor
broken — and states that "sorting reads the exception's type". Both sentences
were aspirations this decision recorded, not a route this decision's own
implementation actually built. `RestoreController::verify_gate()` had exactly
two ways to leave "OK": a caught `Throwable` returned `GATE_BROKEN`
unconditionally, and `GATE_REFUSED` was reached only by inspecting the
preflight report afterwards — never by any exception, of any type. A
`HostCannotComply` thrown mid-walk is a `Throwable`, so it landed on
`GATE_BROKEN` by the same construction as a genuinely corrupt archive.
`Cli\VerifyCommand`'s verify path and `Admin\VerifyController`'s verify action
had the identical shape. The third bucket this ADR describes in prose was
therefore unreachable by any code path that existed: nothing had been built
for an exception to route to, so "sorting reads the exception's type" was
true only for the two-way REFUSED/BROKEN split this ADR did implement, never
for the three-way split its own prose promised.

This was found, not reasoned to: a real host with a `memory_limit` of 40 MB
— WordPress's own default — reported a perfectly sound archive as "Not
verified — this backup is broken." The identical file, checked from the
command line on the same machine with no such limit, verified sound. That is
exactly the failure this ADR's "This host cannot restore it right now" bucket
was written to prevent, on the operation the whole hardening arc was cut to
make trustworthy.

**Amended decision:** the missing route now exists. `RestoreController::verify_gate()`,
`Admin\VerifyController` and `Cli\VerifyCommand` each catch `HostCannotComply`
ahead of their generic `Throwable` catch and report a fourth outcome, **could
not check** — a
deliberately different name from this ADR's own "the check reached no
decision", because a *caught* `HostCannotComply` is a stronger, narrower claim
than any unclassified failure: this is a check that specifically hit a host
condition, not merely a check that failed to reach one of the other three
verdicts. `Cli\VerifyCommand` halts **2**, a new exit code distinct from the
`0`/`1` this ADR shipped, so a script gating on it can tell "unknown" apart
from "bad" rather than folding a host problem into the same bucket as a
genuinely broken or refused archive. `Admin\VerifyController` reports the
equivalent outcome on the Verify screen, and `Admin\RestoreController` reports
it on the Restore screen for both a forward restore and a rollback — all three
worded so as never to say "broken" and never to imply the backup should be
discarded. `RestoreController::restore()` also never takes a safety archive
against a backup that could not be checked in the first place, matching the
ordinary preflight-refusal behaviour this ADR already established (rollback
never takes one regardless of gate outcome, so this is specific to a forward
restore). Everything else in this ADR stands, including the two-way
REFUSED/BROKEN split it did build and the reasoning behind it.

**The lesson this amendment exists to record:** a decision document that
describes an outcome in prose is not evidence the outcome is reachable. The
next claim that a divergence is "closed" should be checked against the actual
catch sites and their fall-through default, not against what this document
says they do.
