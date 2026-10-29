# 0025 — a restore repairs the placeholder markers older archives carry

- **Status:** Accepted, 2026-10-29. Implemented on `dev`; not yet released.
- **Deciders:** 7Duckie.

## Context

Pontifex writes each database row value into the archive as a SQL literal.
`WpdbAdapter::encode_value()` escaped that value with `$wpdb->_real_escape()`,
and WordPress's implementation of that method does not only escape quotes: it
finishes by calling `add_placeholder_escape()`, which replaces **every `%`** in
the string with a marker — a `{`, 64 hexadecimal characters, and a `}`.

`$wpdb->query()` strips that marker again when a statement runs, so inside one
request the pair cancels out and nothing looks wrong. But the marker is
generated per request, and Pontifex wrote the *escaped* statement into the
archive file, freezing one request's marker into the backup. At restore a
different request strips a different marker, and the frozen one lands in the
database as literal text.

Measured on a real site, as a single export followed by a single import:

```
BEFORE     /%year%/%monthnum%/%day%/%postname%/
AFTER      /{6c2e5f64df34ec…}year{6c2e5f64df34ec…}/…
```

Every permalink broken, the import reporting success, and `verify` calling the
archive sound — correctly, because the fingerprints are consistent with the
corrupted bytes they describe.

Inside serialised data it is worse. PHP serialisation records each string's own
byte length: `s:30:"Hello %name%, you are 50% done"`. Replace two percent signs
with 132 characters of marker and the recorded length no longer describes the
bytes, `unserialize()` fails, and the setting silently reverts to a raw string.
That is the same failure mode as the staging-rewrite defect
[ADR 0022](./0022-exception-taxonomy.md) shipped alongside, arriving by a
different route.

Two facts decide the shape of the answer.

**The marker is in the archive, not added at restore.** The same archive
imported twice, in two separate requests, produced the identical marker. A
marker generated at restore would have differed. So every `.wpmig` written by
an affected version carries this — ordinary backups, safety archives and
rollback archives alike — and fixing the export side cannot repair one of them.

**Nothing could have caught it from inside one request.** The test double
`tests/Unit/Manifest/Fakes/WpdbStub.php` implemented `_real_escape()` as
doubling single quotes and nothing else. It never modelled the marker, so no
unit test could fail; and a same-request probe writes the literal `%` back
correctly, because the pair cancels. The defect is only visible across a
request boundary, which no test in this project crossed.

The body of `encode_value()` was byte-identical from v0.0.5 to v1.1.0, so every
release shipped it.

### Prior art

Git's `fsck.<msg-id>` configuration exists, in its own documentation's framing,
because fsck flags problems in *legacy data that current Git versions would not
generate*, and the setting is there so repositories holding such data can still
be handled. The tolerance is named to a specific defect rather than expressed as
a general relaxation of validation — a reader that knows exactly which historical
mistake it is accommodating, and accommodates nothing else.

restic ships explicit `repair index` and `repair snapshots` commands rather than
declaring a damaged repository lost. The instructive difference is that
`repair snapshots` works by *removing* the damaged directories and file contents
it cannot recover, and its own manual page warns that this causes data loss. That
is the right answer when the original bytes are gone. It is not this case: the
transformation here is known, fixed, and exactly invertible, so the original
bytes can be reconstructed rather than discarded. Nobody should later cite
restic's repair as precedent for a destructive one here.

## Decision

The export stops writing the marker, and a restore repairs the archives that
already carry it.

### The export side

`encode_value()` applies `wpdb::remove_placeholder_escape()` to the escaped
value before wrapping it in quotes. That method is public, it strips exactly
what `_real_escape()` just added, and it runs in the same request, so the pair
is symmetric by construction. A literal `%` carries no meaning inside a MySQL
string literal, and [the format specification](../archive-format.md) constrains
only a statement's shape — its leading bytes, its quoting, its semicolons — so
nothing downstream is affected.

The test double now models the real pair, which is what makes the fix provable.
A double that is simpler than the thing it stands in for is not a shortcut; here
it was the whole reason the defect shipped eleven times.

### The replay side, and the gate that bounds it

A restore replaces every marker it finds with a single `%`. Four constraints
make that safe enough to do by default.

**It is gated on the version that wrote the archive.** Every `.wpmig` records
its exporter's name and version in provenance. The repair runs only when the
exporter is `pontifex` and its version is at or below the last affected release.
An archive from a later version needs no repair, and an archive from a different
writer must never be repaired at all: the marker shape is a Pontifex defect, not
a feature of the archive format, so in another writer's output a value of that
shape is real data.

**It runs per statement, after the split and before the containment check.**
`DatabaseWriter::write_entry()` already splits a chunk's payload into statements
before rewriting each one's leading identifier, with a comment recording why:
rewriting a whole payload first was the v1.0.1 defect, an unanchored replacement
across row data as well as identifiers. The repair follows that precedent
exactly, so the array the containment checks of
[ADR 0019](./0019-db-chunk-statement-containment.md) validate is byte-for-byte
the array that then executes. Replacing a 66-character marker with one `%`
cannot introduce a quote, a semicolon or a statement boundary, so the repair
cannot defeat those checks — but "cannot" is not a reason to validate different
bytes from the ones you execute.

That ordering is worth naming as **unverifiable by test**, because a future
reader will otherwise assume a test is holding it. The repair is lexically
neutral, so moving it after validation changes no observable behaviour: a
mutation that does exactly that survives every test that could be constructed
for it, including one probing for a marker inside an identifier. The ordering
rests on the structure of `write_entry()` and on the comment there. Both must
stay.

**Only `INSERT` statements are repaired.** A marker can only ever reach the
archive through `WpdbAdapter::encode_value()`, which is the one caller of
`_real_escape()` in the codebase and is used only for row values.
`dump_table_schema()` copies `SHOW CREATE TABLE` output verbatim and builds its
`DROP` by doubling backticks, so schema text never carries a marker — and
repairing it could therefore only ever produce a false positive, on a column
default that happened to be marker-shaped. Repairing every statement uniformly
was the first instinct and was wrong; narrowing to `INSERT` covers every real
occurrence and removes a whole class of false positive at no cost. The
completeness of that narrowing depends on `encode_value()` remaining the only
escape call site, which is why the code says so where somebody adding a second
one would read it.

**It is reported, never silent.** The count is surfaced by
`wp pontifex import`, by `wp pontifex rollback` and by the admin Restore screen,
including its Rollback button. A repair the operator cannot see is
indistinguishable from data that was always correct, and this project's own rule
is that a message describing state must look rather than predict.

On the admin path that is true only once the outcome is recorded durably — see
the amendment below, which is what makes this bullet honest.

What is counted is **percent signs, not values**, and the message says so. One
permalink structure holding four markers is reported as four, because four is
what the repair measured; counting values would mean tokenising SQL string
literals, which is not worth it, and reporting markers as values would be the
friendlier number rather than the true one.

The automatic recovery replays after a failed restore are the deliberate
exception. They already report whether the site was put back exactly or merged,
and which links were skipped; a repair count there would add noise at the one
moment the operator has least attention to spare. The repair still happens —
it is only the count that goes unreported, and a subsequent deliberate restore
or rollback reports its own.

### The trade-off, stated plainly

A row value that genuinely contains a brace, 64 hexadecimal characters and a
closing brace is indistinguishable from the marker, and the repair would turn it
into a `%`. Nothing can tell the two apart from the bytes alone.

That trade is accepted deliberately. The corruption is certain, silent, and
breaks sites; the false positive is speculative and reaches only one exact
shape, in one statement form. Three things bound it: the version gate, so it
cannot fire on an archive written after the fix or on another tool's archive at
all; the `INSERT`-only narrowing, so no schema text is touched; and the report,
so an operator who does see a value change is told it happened.

The alternative considered and rejected was an opt-in flag. A flag protects
only the operators who already know the defect exists, which is nobody at the
moment they need it — and the people most exposed are those restoring an old
backup during an incident, who will not be reading release notes first.

### Scope

Only the three paths that write data repair: `wp pontifex import`, the admin
Restore screen, and `wp pontifex rollback` — a rollback replays a safety
archive, which carries the same markers. `verify` is unchanged: it alters
nothing, so it has nothing to repair, and its verdicts are about the archive as
stored.

## Consequences

**Good.** New archives are correct. Old archives restore to their original
bytes, which is the only outcome that helps somebody holding a backup taken
months ago. Serialised settings that would have silently reverted survive,
because reconstructing the `%` restores the byte length the prefix already
claimed.

**Costs.** One regular-expression pass per statement on an affected archive, on
a path that already rewrites each statement's leading identifier. A restore now
modifies statement text for a reason other than prefix rewriting, which is a
second place where the bytes executed are not the bytes stored — bounded by the
version gate, the per-statement ordering, and the containment checks that run
after it.

**A note for whoever cuts the release.** The version gate reads the exporter
version an archive records, and an unreleased build still reports the version of
the last release. So every archive written between this decision and the next
version bump is labelled as affected and is eligible for repair. That is
harmless — those archives hold no markers, so nothing matches — but the gate
only begins excluding clean archives once the released version is above
`LAST_AFFECTED_VERSION`. The bump is what completes this decision, not the code.

**Deliberately not done.** A site **already** corrupted by an earlier restore is
not repaired by this decision. The repair happens when an archive is replayed,
so it reaches a database only on the next restore. Detecting markers in a live
database is a separate piece of work, and the honest statement is that anybody
who restored before this ships is carrying the damage until they restore again.

## Amendment — 2026-10-29: on the admin path the message was unreadable

The decision above says the repair is reported, never silent, and names the admin
Restore screen as one of the surfaces. A browser gate showed that on that surface
it was silent in practice, and the cause is structural rather than a slip.

A restore replaces the users and sessions tables, so the browser is signed out
before the request that is composing the success message can deliver it. The
screen's own script then **overwrites** the result area with a truthful,
non-committal session-reset notice, and when the operator signs back in the page
reloads, finds the operation idle, and renders nothing. The composed sentence —
the entry count, the relink confirmation, and the repair count this decision
added — is addressed to a request no logged-in browser ever receives.

The notice sends the operator to the Overview screen, which until now showed only
running totals: how many restores have happened and how many bytes. Nothing
recorded what the last restore actually did.

So the repair count was written correctly, logged correctly, and could not be
read. By this decision's own standard that is a failure, and the standard is the
right one: the whole argument for repairing data in place is that the operator is
told it happened.

**Amended decision:** a restore's outcome is recorded durably, as counts and
flags, and the screens read it from there rather than from a response the
operation may destroy. The repair count travels in that record like every other
fact about the restore. The inline sentence stays, because it is correct whenever
the session does survive, but it is no longer the channel the promise depends on.

The durable record is its own decision, with its own reasoning about where it
lives and what it may hold — see
[ADR 0026](./0026-a-restore-outcome-has-one-durable-home.md). It ships on the
same branch as this one, because this decision's promise is not true without it.

**The wider finding, recorded and deliberately not fixed here.** Every message
the Restore screen composes is lost the same way, not only the repair count: the
entry count, the relink confirmation, "your site now matches that backup". ADR
0026 gives those a durable home too, but the screen still composes them inline
as though they will be read, and reconciling that properly is a separate piece of
work.
