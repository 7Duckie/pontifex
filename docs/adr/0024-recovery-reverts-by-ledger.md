# 0024 — recovery undoes what the restore added, by ledger rather than by difference

- **Status:** Accepted, 2026-08-06. Implemented and shipped in v1.1.1.
- **Deciders:** 7Duckie.

## Context

A restore is additive. It overwrites and creates; it never removes a file the
archive does not contain. `tests/Integration/PartialScopeRestoreTest.php` states
that as a deliberate contract, and it is the right contract — the absent half of
a files-only archive must not delete the database, and a partial archive must
not be read as an instruction to empty the site.

Recovery after a failed import inherited it. `recover_from_safety_archive()`
replays the safety archive and, on success, told the operator:

> The import failed, so your site was automatically rolled back to its state
> before the import.

That was false. The replay restores every file that existed before, but any file
the failed import **created** stays, because nothing removes it. The site ends up
as the original files merged with whatever the failed import managed to write.

This was proven in the container against the real engine before any code was
written:

```
BEFORE            original.txt = ORIGINAL CONTENT      intruder absent
FAILED IMPORT     original.txt = OVERWRITTEN…          intruder PRESENT
RECOVERY (ok)     original.txt = ORIGINAL CONTENT ✓    intruder STILL PRESENT ✗
```

The consequence worth naming: restoring a backup that is not your own current
site — an older one, one from another site, one somebody sent you — that fails
part-way, then recovers. A file that backup introduced at a path your site never
had is now live, and the operator has been told the site is back to normal. If it
was an obsolete or compromised plugin file, it is executing.

It needs a failed restore to trigger, which is rare. That is also precisely the
moment the operator is least able to check and most reliant on the message.

### Prior art

RPM can roll a transaction back, but the feature is **off by default** and
requires "repackaging" — saving the old files before erasing them — because a
real revert needs the prior state, not merely a list of what changed. dpkg has no
rollback at all. DNF's history-based undo re-applies an inverse transaction
rather than reverting the filesystem.

The useful observation is that Pontifex already does the expensive half RPM makes
optional: **the safety archive is the repackage**, taken by default before every
restore. What was missing is only the other half — RPM's rollback both restores
saved files and removes what the transaction added. Pontifex did the first and
not the second.

## Decision

The restore records every path where the target did not exist before it wrote.
Recovery removes exactly those.

### Why a ledger and not a set difference

The obvious implementation — delete anything present now but absent from the
safety archive — is a trap, and it is worth writing down why so nobody
reintroduces it as a simplification.

A live WordPress site writes constantly. During a restore it may add uploads,
cache files, session data and logs, none of which the safety archive contains and
none of which the restore created. A difference would delete all of them. The
ledger deletes only what this run added, which is the actual question.

This is the file-ownership model dpkg and rpm use per transaction.

### The ordering that makes it safe

A creation is recorded only **after** the write lands: after the atomic rename for
a file, after `symlink()` returns for a link, after the directory exists for a
directory.

Recorded any earlier, an abort between the record and the write would leave a
ledger entry for a path that was never created — and recovery would then delete a
pre-existing file it had never touched. That is a worse bug than the one being
fixed, and the ordering is the only thing preventing it.

### The guard, and how it can fail quietly

Recovery never deletes a path the safety archive also declares: if the archive
holds it, it belongs to the prior state and restoring it is correct.

That guard matches on **normalised** paths. The ledger stores paths after
`normalise_entry_path()`; an archive declares them as recorded, which may be
`./wp-content/foo.php` or `wp-content//foo.php`. Left unnormalised the lookup
misses and the guard silently fails open — deleting a file the archive declared.
Verified by reverting the normalisation and watching the test delete exactly such
a file. The normalisation therefore lives inside `remove_created_paths()` rather
than at the call sites, so no caller can get it wrong and the two sides cannot
drift apart.

### The cap

The ledger holds at most 20,000 paths. Past that it stops recording and marks
itself incomplete, and recovery reports a merge instead of claiming a revert.

A restore onto a fresh server creates every file, so hitting the cap is the
ordinary case for a large restore rather than an exceptional one. That is
acceptable: the failure mode is a truthful weaker message, not a false strong
one — the same honesty the admin's fatal handler already shows when it says the
site may be partially restored.

The bound exists because the restore path runs inside a 128 MB web request and
already carries a memory budget for exactly that reason. Unbounded growth there
is a defect shape this project has met before.

### Scope

Only the automatic recovery after a failed import changes. `wp pontifex rollback`
keeps its additive behaviour: it makes no claim to revert, it has no ledger to
work from (the failed run may have been another process, or days earlier), and so
it could only use the set-difference rule this decision rejects. Revisiting it is
a separate decision once this one has proven itself.

## Consequences

**Good.** An existing promise becomes true rather than being reworded into a
weaker one. The operator who is least able to verify — mid-incident, after a
failed restore — is the one who benefits.

**Costs.** One `stat` per written path, on a path that already stats every file
entry for the free-space preflight. Recovery now deletes, which is the most
dangerous thing this codebase does; it is bounded by the ledger, the preserved
set, the same path confinement writes use, and a best-effort contract that never
masks the original failure.

**Deliberately not done.** Full file-side restore atomicity, which
[ADR 0009](./0009-atomic-staging-table-restore.md) deferred as "a separate, later
arc", remains deferred. This decision makes recovery honest; it does not make the
file half of a restore transactional. A restore that fails and is *not* recovered
— because the safety archive was skipped with `--no-rollback-archive`, or because
recovery itself failed — still leaves a merged tree, and both surfaces say so.
