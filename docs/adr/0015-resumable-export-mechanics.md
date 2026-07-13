# 0015 — resumable exports: the progress log is the truth, and drift is refused

- **Status:** Proposed, 2026-07-13.
- **Deciders:** 7Duckie (v0.6.0).

## Context

A forty-minute export that dies at minute thirty-nine — PHP timeout, lost SSH
session, host restart — starts over from zero today, and on hosts with
aggressive limits "starts over" can mean "never completes" (idea-bank 006).
v0.6.0 makes an export a sequence of bounded steps that any later request can
continue (ADR 0014). This ADR records how a half-written archive becomes
safely continuable, and what is deliberately refused.

Three facts shape the mechanics. First, the `.wpmig` format writes its
manifest and footer LAST, so a partial archive is simply a prefix of
self-delimiting entry records — appending is all that resuming requires, and
no format change is needed. Second, the consistent snapshot the database dump
runs in (ADR 0011) lives on a database connection, and connections die with
the request — snapshot consistency cannot span ticks. Third, an encrypted
archive's key is derived from a passphrase into memory and is deliberately
never persisted anywhere.

## Decision

- **The progress log is the truth.** Every appended entry's canonical
  manifest record is appended to the job's JSON-lines sidecar (one line per
  entry, crash-tolerant by shape); the job record carries only advisory
  cursors. Resume rebuilds the manifest-so-far from the log, never from the
  archive bytes alone.
- **Every tick verifies the partial file before appending.** Bytes past the
  last logged entry are truncated (written, never logged); the last logged
  entry's record is re-hashed against its logged hash and dropped if it does
  not match (logged, never fully flushed) — stepping back, entry by entry, to
  the last provably-good prefix. The two crash windows around an append are
  thereby both healed, at the cost of re-writing at most the torn entry.
- **Positional drift is refused, loudly.** The scan is deterministic, so on
  resume the fresh scan must yield the same identity (path, or chunk index)
  at every already-completed position. Content changes are fine — ADR 0013
  already records the truth per entry — but a file added or removed EARLIER
  in the scan order would shift every subsequent index and silently skip or
  duplicate entries. That export refuses with instructions to start over.
- **The database phase runs whole, in one fresh tick, last.** File entries
  spread across as many budgeted ticks as needed; when files are done the
  tick ends, and the next tick dumps every database chunk inside one
  consistent snapshot, writes the manifest and footer, and renames the
  archive into place atomically. ADR 0011's guarantee is preserved exactly
  because the snapshot never has to span a request boundary.
- **Encrypted exports refuse resumable mode.** Resuming one would require
  persisting the derived key to disk, which defeats the passphrase. The
  refusal happens at start, with the alternatives named. Signed exports
  resume fine: the caller re-supplies the signing key (a path on disk, not a
  secret in job state) and the signature is computed at finish over every
  byte, exactly as in a one-shot write.
- **Adoption is byte-identical.** An archive written across N ticks is
  byte-for-byte the archive a one-shot write of the same inputs produces —
  asserted directly in the unit suite, which is what makes every existing
  reader guarantee carry over unchanged.

## Consequences

- A killed export resumes from its last verified entry instead of zero; the
  worst case re-writes one torn entry.
- The job's sidecar grows by one small line per archive entry (a few MB for
  very large sites) and is deleted with the job.
- A site whose wp-content changes shape mid-export must start that export
  over — the honest cost of refusing silent index drift. Content-only
  changes do not restart anything.
- A database too large to dump inside one request's limits cannot complete
  the final tick; the export fails there rather than producing a
  cross-snapshot database. Recorded limitation; the escape hatch is the CLI
  with no time limit, unchanged.

## Alternatives considered

- **Persist the scan list instead of re-scanning per tick** — rejected: the
  list is megabytes of state that can still go stale; re-scanning plus
  positional verification is cheaper and catches exactly the dangerous case.
- **Tolerate drift by re-indexing** — rejected: silently renumbering entries
  invalidates the already-written archive prefix; correctness over
  convenience.
- **Persist the derived encryption key so encrypted exports resume** —
  rejected out of hand: the key on disk defeats the passphrase.
- **A format-level "continued" marker** (idea-bank 006's largest option) —
  unnecessary: manifest-last already makes partial archives appendable with
  no format change.
