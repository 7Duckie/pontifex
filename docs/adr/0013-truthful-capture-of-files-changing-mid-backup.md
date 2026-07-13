# 0013 — export: record what was actually read when a file changes mid-backup

- **Status:** Proposed, 2026-07-12.
- **Deciders:** 7Duckie (v0.5.0 hardening).

## Context

The export deliberately splits into two passes to keep memory flat: the scan
stats every file up front and records its size in the entry header, and each
file is opened lazily only when its entry is written. On a live site that gap
is a race window. If a file is truncated, rewritten, or appended to between
the scan and the write, the codec reads whatever bytes exist at write time and
the trailing hash covers exactly those bytes — but the header still declared
the scan-time size, and nothing reconciled the two. The archive was internally
self-consistent, **passed verification clean**, and reported success while
silently holding different content than it claimed. That is precisely the
failure mode Pontifex exists to exclude: a backup whose clean verification
means nothing.

The engine audit filed this as the scan/write TOCTOU file-shrink race — the
last audit observation without a fix or an ADR.

Prior art is consistent. GNU tar writes each member's header *before* its
data, so when a file shrinks it has no choice but to pad the member with
zeros to the declared size; it warns ("file changed as we read it") and exits
non-zero, but finishes the archive. restic stores whatever bytes it actually
read, warns, and completes — its documentation is explicit that no file-level
tool can do better without a filesystem snapshot (LVM, VSS). Zip-based tools
are naturally immune to the *lie* because zip records sizes after the data.
Nobody aborts the whole backup over one moving file: that would turn a busy
site — the site that most needs backups — into one that cannot be backed up.

Pontifex's format writes the entry header *after* encoding the payload (that
is how `size_compressed` is already corrected), so unlike tar it can simply
tell the truth.

## Decision

Two guards, defence in depth:

- **The writer records what it captured.** `EntryWriter` counts the raw bytes
  the codec actually reads (a wrapper on the existing byte-progress callback —
  no extra I/O). For a `file` entry whose count differs from the declared
  size — shrunk *or* grown — it corrects the header to the captured byte count
  at the same point `size_compressed` is corrected, and reports the
  discrepancy in `EntryWriteResult`. Because the corrected header bytes are
  also the AES-GCM AAD and the input to the entry hash, encryption and
  integrity stay consistent for free.
- **The discrepancy is surfaced, loudly.** `ArchiveWriter` passes each report
  to an `on_file_changed` callback; `ExportRunner` collects them into
  `ExportResult::changed_files()`; the CLI prints one warning per changed file
  (path, declared bytes, captured bytes) plus a summary, and counts them in
  the stored export stats (`files_changed`). The export succeeds with exit 0:
  the archive is truthful and restorable, and the warnings tell the user those
  files were moving and a re-run may be wanted. All export callers (CLI, admin
  Backup, the pre-import safety archive) inherit the truthful capture because
  the fix is in the engine.
- **The reader refuses the lie.** At the end of `EntryReader::read_entry()`, a
  `file` entry whose decoded byte count does not equal its declared `size` is
  refused — fail closed. With the writer fixed this can only fire on an
  archive produced by a pre-fix writer that actually hit the race (or on a
  future writer bug, which is the point of a second guard). All four restore
  paths inherit the check from the engine seam. A `db_chunk`'s `byte_count`
  is a sizing estimate, not a content claim (its truth is guaranteed by the
  ADR 0011 snapshot), so it is deliberately not checked.
- **The verify path stays hash-only.** `verify_entry()` deliberately never
  decodes (ADR 0010's memory-flat verify), so it does not run the size
  reconciliation. Verify's promise is unchanged — bytes intact, structure
  sound; the decoded-length check runs where decoding happens, on restore.
- **The format spec records both obligations** (§6): writers MUST record the
  byte count actually read; readers MUST refuse a `file` entry whose decoded
  payload length differs from `size`.

## Consequences

- A backup taken while files are changing now completes with truthful
  contents and explicit warnings instead of silent data loss behind a clean
  verification.
- The growth direction is fixed too: previously a grown file's header
  under-declared its payload, quietly weakening the declared-size half of the
  decompression-bomb budget.
- A pre-fix archive that actually contains a raced file now **refuses to
  restore that entry** instead of silently writing a truncated file — the
  fail-closed outcome. Such an archive still verifies clean (verify does not
  decode); the refusal surfaces at restore time.
- A same-size in-place rewrite within the race window is still undetectable
  by this guard — inherent to every non-snapshotting file-level backup tool
  and out of scope here (tar's stat-based heuristic has the same blind spot).
- The exit code stays 0 on success-with-warnings, so scripted backups keep
  working; scripts that care can read `files_changed` in the export stats.

## Alternatives considered

- **Abort the export on the first changed file** — rejected: denial-of-backup
  on busy sites, and no mature tool does it; a truthful archive plus loud
  warnings is strictly more useful than no archive.
- **Pad to the declared size like tar** — rejected: tar pads because its
  format forces it to; padding fabricates bytes that were never on disk.
  Pontifex's header is written after the payload, so it can record the truth.
- **Re-stat (size + mtime) after reading to also catch same-size edits** —
  rejected for this slice: it cannot change what was captured, only add a
  warning for a case with a false-positive window (a change *after* a
  fully-consistent read), and the audit finding is the size lie.
- **Make verify decode every payload to run the same check** — rejected:
  roughly doubles verify's cost to catch only pre-fix racy archives, which
  the restore-time guard already refuses before any table or file is touched
  (restore verifies before writing).
- **A non-zero exit code on success-with-warnings, like tar** — rejected: a
  breaking change to scripted exports for a condition that produced a good
  archive; the stats counter carries the signal instead.
