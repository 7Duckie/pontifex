# 0010 — restore: stream entry reads, verify before decode, spool large payloads

- **Status:** Proposed, 2026-07-11.
- **Deciders:** 7Duckie (v0.5.0 hardening).

## Context

The restore read path buffers aggressively. `EntryReader::read_record_bytes()`
accumulates an entry's whole stored record into one string; the decode then
round-trips through in-process buffers and hands back the whole decoded payload
as a second string, so reading one entry peaks at several coexisting copies of
it. The per-entry memory budget (a quarter of `memory_limit`) added by the
restore-OOM hardening therefore *refuses* any entry whose decoded size exceeds
roughly 32 MB in a 128 MB web request — fail-closed and correct, but it means a
site whose uploads include one large video, archive, or export file can take a
backup in the browser that the browser then cannot restore. Database chunks are
now budget-bounded at export time (per-table row-width sizing, PR #94); file
entries are the unbounded remainder, and a file entry is exactly the payload
that never needs to be in memory at all — its destination is the filesystem.

Two properties must survive any streaming change:

- **Verify before use.** Every entry carries a trailing SHA-256 over its stored
  bytes, checked against the manifest. Today the whole record is read and the
  hash checked before the payload is decoded; streamed reading must preserve
  that order — no byte may be decoded, and certainly none written to its
  destination, before the stored bytes have authenticated.
- **Authenticated encryption stays authenticated.** An encrypted entry's
  payload is one AES-256-GCM message whose tag verifies only at the end. PHP's
  OpenSSL bindings are one-shot (string in, string out) — there is no streaming
  AEAD — and streaming a large GCM message would in any case mean acting on
  unauthenticated plaintext before the tag check. This is a known cryptographic
  anti-pattern: libsodium's own guidance is that large messages must be split
  into bounded, individually authenticated chunks (its secretstream
  construction), which is also how backup tools that stream encrypted data
  structure their formats.

## Decision

The read path streams what can be streamed and keeps buffering only what
cryptography or consumers genuinely require:

- **Incremental verify.** The stored record is read in chunks; the header is
  parsed from the head, the payload bytes are spooled into a `php://temp`
  buffer (in memory up to a small threshold, transparently on disk beyond it),
  and an incremental SHA-256 runs over the record as it is read. The trailing
  hash and the manifest's recorded hash are both checked **before any decode
  begins** — the same verify-before-decode order as today, at chunk-sized
  memory cost.
- **Plain file entries decode stream-to-stream.** The codecs are already
  stream-to-stream; a plain (unencrypted) file entry decodes from the spool
  into a second spool, and `EntryReadResult` carries it as a **stream** that
  `FileWriter` copies to disk in chunks. A file entry therefore never occupies
  payload-sized memory, and the per-entry memory budget no longer applies to
  it: browser restores stop refusing large media.
- **db_chunks stay strings.** `DatabaseWriter` needs the whole chunk to split
  statements, chunks are budget-bounded at export time (PR #94), and the
  memory-budget refusal remains their fail-closed backstop.
- **Encrypted entries stay buffered.** One-shot AEAD is the only correct thing
  PHP can do with the current envelope, and the admin screens already refuse
  encrypted archives (CLI-only, where memory is unlimited). If browser restores
  of encrypted archives are ever wanted, the right mechanism is a format minor
  adding bounded chunked-AEAD entries — the secretstream pattern — not
  streaming a monolithic GCM message.
- **Budget accounting** in `RestoreRunner` counts decoded bytes from the
  codec's returned byte count rather than payload string length, so the
  archive-total decompression-bomb budget keeps working for streamed entries.

## Consequences

- Peak restore memory for file entries drops from several payload-sized copies
  to chunk-sized reads plus an on-disk spool; the ~32 MB per-entry ceiling in a
  128 MB web request stops applying to plain file entries entirely.
- Disk use during a restore rises by up to one spooled entry at a time
  (bounded, reclaimed as each entry completes).
- `EntryReadResult` becomes a two-shape contract (string payload or payload
  stream); its consumers — `FileWriter`, `DatabaseWriter`, `RestoreRunner` —
  are updated together in the same change.
- The refusal surface narrows but never opens: encrypted and db_chunk entries
  keep the memory-budget refusal; nothing is ever written before its bytes
  have hash-verified.
- The restore-side URL-rewrite batching (`WpdbMigrationDatabase::read_rows`,
  fixed row-count batches) is a sibling finding, out of scope here; it takes
  the same per-table sizing PR #94 gave the scanner, as its own follow-up.

## Alternatives considered

- **Stream encrypted entries too** — rejected: PHP has no streaming AEAD, and
  releasing GCM plaintext before the tag verifies is the canonical misuse the
  chunked constructions exist to prevent.
- **Format change now (chunked file entries / chunked AEAD)** — deferred: it
  buys browser restores of encrypted archives, which nothing currently needs
  (the admin refuses encrypted archives by design), at the cost of a format
  minor and writer/reader work on both sides.
- **Raise the memory budget instead** — rejected: the budget is the defence,
  not the problem; the fix is to stop needing payload-sized memory, not to
  gamble with more of it.
