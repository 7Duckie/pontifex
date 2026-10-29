# 0026 — a restore's outcome has one durable home, because a restore destroys its own caller

- **Status:** Accepted, 2026-10-29. Implemented on `dev`; not yet released.
- **Deciders:** 7Duckie.

## Context

A restore replays the whole database, including the users and sessions tables.
The browser watching it is therefore signed out **before** the request that is
composing the outcome can deliver it. This is not a defect to be fixed; it is
what the operation does.

Four facts, read from the code rather than assumed:

- `RestoreController::restore()` composes a rich success message — the entry
  count, the relink confirmation and the row count it rewrote, the number of
  percent signs [ADR 0025](./0025-repairing-placeholder-markers-at-replay.md)
  repaired — and sends it as the response to the request that ran the restore.
- `assets/admin/pontifex-restore.js`'s `finishSessionReset()` **overwrites** the
  result area with a truthful, non-committal session-reset notice, because the
  progress poll can no longer read an authenticated answer.
- When the operator signs back in, `checkReauth()` reloads the page,
  `reattachIfRunning()` finds the operation idle, and renders nothing.
- The notice sends the operator to the Overview screen. Until this decision the
  Overview showed only running totals — how many restores have happened and how
  many bytes — with no record of what the last one did.

So the composed message is addressed to a request no logged-in browser ever
receives, and there was nowhere else to look. A browser gate found this by
restoring a genuinely damaged archive and reporting, correctly, that the repair
sentence was nowhere to be seen. The repair had happened; twelve percent signs
were put back; the log proved it. The operator could not.

The codebase already half-knew this. The JavaScript's own docblock says the
proven outcome "is recorded in the transfer history and shown on the Overview
after re-login" — which was the right design, and simply was not true. The
history stored a timestamp, a kind, an outcome and a byte count.

### Prior art

WordPress core solves this exact shape in its settings API. A validation message
cannot be shown by the request that produced it, because that request ends in a
redirect, so core stores the messages and replays them on the next one:
`get_settings_errors()` merges them from a `settings_errors` transient and
deletes it (`wp-admin/includes/template.php`, around line 1930). The message does
not travel in the response; it travels in storage.

The general pattern is older and broader than the web. Any operation that can
destroy its own caller — a package manager replacing the binary that is running,
a migration that drops the connection it is running over — reports through a
journal that outlives the operation, never through a return value. The return
value is the thing least likely to survive.

Pontifex already had the journal. It was simply not recording enough to answer
the question an operator asks after a restore: what did that do to my site?

## Decision

A restore's outcome is recorded durably, as counts and flags, and the screens
read it from there. The inline message stays, because it is correct whenever the
session does survive, but it is no longer the channel any promise depends on.

### The record lives in the existing journal

`TransferHistory` gains an optional details map on each entry. It was chosen over
a new option, and over a core-style flash transient, on four grounds:

- It is already written on **every** path — admin and CLI, success and failure.
- It is already capped at 20 entries and already tolerant of a corrupt option, so
  a malformed or older entry degrades rather than raising.
- `StatsCommand` builds its table from four named columns, so an added key is
  ignored by the reader that exists.
- The code already names it as the home. Making the claim true is better than
  inventing a second place for the same fact to live.

A transient was rejected specifically because it can expire. "What did my last
restore do?" is a question worth answering next week, not only in the next ten
minutes.

### Counts and flags only

`TransferHistory`'s own docblock promises it "never records any content: just a
timestamp, a kind, an outcome, and a byte count, so the history is
privacy-respecting and the option stays small." That promise is kept, and
restated rather than left to rot: the details are integers, booleans and a small
set of fixed labels. **Never a path, a URL, a table name, a link target, an
option name, or anything from the site's own data.** A skipped symbolic link is
recorded as a count, not as a target; a relink as a flag and a row count, not as
two addresses.

This is a real constraint and not a formality: the natural instinct when
reporting a skipped link is to name it, and naming it would put a filesystem
path from somebody else's server into an option that this project promises holds
no content.

### One reader, two screens

A single `RestoreOutcome` class reads the newest record and composes the sentence
that describes it. The Overview and the Restore screen both consume that one
description rather than each building their own from the raw details.

This is deliberate and has been paid for before: v1.0.3 found six byte-identical
copies of the helper every restore path measures confinement from. None had
drifted; the objection was that nothing would have noticed if one had. Two
screens describing the same record in two places is the same shape.

### The recovery labels are duplicated on purpose

`RestoreController`'s `RECOVERY_*` constants are private, and the CLI import
carries its own copy of the same vocabulary. `RestoreOutcome` therefore owns the
canonical labels as public constants, and each writer maps its own local label to
them. Making the controller's constants public so a reader could reach into it
would be the wrong direction: a controller's internals are not an interface, and
the mapping is explicit, in one place per writer, and tested.

### Scope

The restore paths record now. **Rollback does not**, because it records no
history at all today, and giving it one means a third operation kind, which
changes what `wp pontifex stats` prints — observable behaviour, and so a decision
to take deliberately rather than as a side effect of this one.

## Consequences

**Good.** The outcome of a restore survives the restore. An operator who was
signed out mid-run can find out what happened without reading a log file, which
is what ADR 0025's promise rests on. The record is the same whether the restore
was run from the browser or the command line, so the Overview tells the truth
either way.

**Costs.** The history option grows by a small map per entry, bounded by the
existing cap of 20. Two more writes happen on paths that already write there. And
there is now a second description of a restore — the inline message and the
record — which can disagree if somebody edits one and not the other.

**Deliberately not done.** The Restore screen still composes its rich inline
message as though it will be read. This decision gives that information a durable
home, but it does not reconcile the two: the screen should probably stop
composing a message it cannot deliver and render the record instead. That is a
larger change to how the screen reports, and it is recorded here rather than
smuggled into this one.
