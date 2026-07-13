# Pontifex Archive Format Specification

**File extension:** `.wpmig`
**Version:** 1.1 (DRAFT) — adds the optional `scope` and `table_prefix` provenance fields; v1.0 readers accept v1.1 archives unchanged.
**Status:** Draft — finalised when Pontifex 0.1.0 ships. Until that point, this specification may change with each minor version release.
**Licence:** This specification is published under CC BY 4.0. Implementations of the format are not restricted; the spec text itself may be redistributed with attribution.

---

## How to read this document

This document tells implementers what bytes go where. It is short on prose and long on tables, layouts, and field rules. If you are writing code that emits or parses `.wpmig` archives, this is your reference.

The *why* — what alternatives we considered, what trade-offs we accepted, what concerns motivated specific decisions — lives in the companion [design document](./archive-format-design.md). You do not need to read it to implement the format, but it answers most of the "why on earth did you do it that way?" questions.

[Appendix B](#appendix-b-glossary) glosses the cryptographic and format-specific terms that the spec uses without defining inline.

## 1. Introduction

`.wpmig` is a documented, versioned, open archive format designed to package a complete WordPress site — files, database, configuration, and contextual metadata — into a single portable file that can be moved between hosts and reliably reconstructed.

The format is open: this document is the contract, and any party may build an implementation. Pontifex ships one implementation in PHP (as a WordPress plugin); the project intends to ship a second reference implementation in Go as a standalone command-line tool. Two independent implementations from the same specification, producing byte-identical archives from identical inputs, is the project's working definition of "the specification is correct."

This format takes lessons from prior work, both in the WordPress space (existing single-file and multi-archive migration formats, and a proposed core export format) and from outside it (general-purpose and block-compressed archive formats, repository backup tools, and container-image and attestation manifests). Where a decision is non-obvious, [§15 — Design rationale and prior art](#15-design-rationale-and-prior-art) explains which prior format informed it.

## 2. Goals and non-goals

### Goals

- **Durability across decades.** A `.wpmig` file produced by Pontifex 0.1.0 must remain readable by Pontifex versions released ten years later. Conversely, a future Pontifex must be able to refuse — clearly and without crashing — a future-version archive it does not understand.
- **Single-file portability.** One archive equals one site equals one file. No multi-file sets that must be kept together.
- **Streamable in both directions.** A writer must be able to produce output without holding the whole archive in memory. A reader must be able to begin processing entries before the whole file is downloaded.
- **Encryption by default.** Archives are encrypted unless an explicit opt-out is given. Operators wishing to disable encryption must say so deliberately.
- **Tamper-evidence.** Any modification to the archive between production and consumption must be detectable, and the detection must produce information actionable enough for an operator to know which part of the file is affected.
- **Provenance.** The archive carries enough metadata about its source environment for a destination importer to verify it is restoring the site it expected, and to perform a correct search-replace and URL rewrite without guesswork.
- **Resumability under host constraints.** Per-entry boundaries are explicit so that a producer or consumer killed mid-process can resume from the last completed entry.
- **Independent verifiability without Pontifex.** A user holding a `.wpmig` archive and this specification can verify the archive's integrity and extract its contents using any conforming reader, including one they write themselves.

### Non-goals

- **Deduplication across archives.** Unlike deduplicating repository backup tools, `.wpmig` is a single-archive single-site format, not a deduplicating repository. Each archive is self-contained.
- **Differential or incremental archives.** v1 archives are always full. Differential support is a candidate for v2.
- **In-place mutation.** A `.wpmig` archive is immutable once written. Subsequent operations produce new archives; they never patch the original.
- **Replacement for general-purpose archiving.** This is a WordPress-shape-aware format. It is not designed to replace `tar` or `zip` for arbitrary file collections.

## 3. Quick reference

A `.wpmig` archive is laid out as follows:

```
+--------------------+
| Magic + version    |  fixed 16 bytes
+--------------------+
| Provenance block   |  variable, length-prefixed
+--------------------+
| Entry 1            |  variable, length-prefixed
| Entry 2            |
|        ...
| Entry N            |
+--------------------+
| Manifest           |  variable, length-prefixed
+--------------------+
| Footer             |  fixed 64 bytes
+--------------------+
| Optional signature |  variable, only if archive is signed
+--------------------+
```

A reader can:

1. Read the magic to confirm the file is a `.wpmig` archive.
2. Read the version to confirm support.
3. Seek to the end of the file, read the footer, and from it locate the manifest.
4. Read the manifest to learn which entries exist, their offsets, lengths, codecs, and hashes.
5. Use the manifest to perform random-access reads of individual entries — or stream the entire file front-to-back.

## 4. Magic and version header

The archive begins with sixteen bytes:

| Offset | Length | Field          | Value                                              |
|-------:|-------:|----------------|----------------------------------------------------|
|   0    |   8    | Magic          | `0x57 0x50 0x4D 0x49 0x47 0x00 0x00 0x01`          |
|   8    |   2    | Format major   | uint16 big-endian — `0x0001` for v1                |
|  10    |   2    | Format minor   | uint16 big-endian — `0x0001` for v1.1 (`0x0000` = v1.0) |
|  12    |   4    | Flags          | uint32 big-endian bitfield (see below)             |

The magic spells `WPMIG\0\0\x01` in ASCII. The trailing `\x01` is deliberately non-printable: it prevents the file from being mistaken for a UTF-8 text file by tools that probe the first eight bytes.

Flag bits (LSB = bit 0):

| Bit  | Meaning when set                                                       |
|-----:|------------------------------------------------------------------------|
|   0  | Archive is encrypted                                                   |
|   1  | Archive carries a detached signature appended after the footer         |
|   2  | Provenance block is itself encrypted (only valid if bit 0 is also set) |
| 3–31 | Reserved; must be 0 in v1 archives                                     |

A reader encountering a non-zero reserved bit must reject the archive as "version unknown" rather than attempt to interpret it.

## 5. Provenance block

Immediately after the header sits the provenance block: a JSON object describing the source environment.

```
+--------+--------+-----------------------------------------------+
| length |  hash  | provenance JSON (UTF-8, encrypted if bit 2)    |
| 4 B    | 32 B   |                                                |
+--------+--------+-----------------------------------------------+
```

- **length:** uint32 big-endian, byte count of the JSON payload that follows the hash field.
- **hash:** SHA-256 of the JSON payload as stored (i.e., after any encryption applied); 32 bytes.
- **payload:** the JSON object itself, UTF-8 encoded.

The schema of the JSON object:

```json
{
  "wp_version": "6.8.0",
  "php_version": "8.2.18",
  "url": "https://example.com",
  "db_charset": "utf8mb4",
  "db_collation": "utf8mb4_unicode_520_ci",
  "exporter": {
    "name": "pontifex",
    "version": "0.5.0"
  },
  "timestamp": "2026-05-21T14:23:01+00:00",
  "table_prefix": "wp_",
  "scope": {
    "content_only": true,
    "content_root": "wp-content",
    "includes_core": false,
    "includes_wp_config": false,
    "includes_database": true,
    "excluded_paths": ["wp-content/pontifex/**", "wp-content/cache/**"]
  },
  "encryption_disabled_reason": null
}
```

Field rules:

- **Required in every archive:** `wp_version`, `php_version`, `url`, `db_charset`, `db_collation`, `exporter` (with `name` and `version`), and `timestamp`. These are the locked v1 fields (§13.2.3).
- All timestamps are ISO 8601 with an explicit UTC offset.
- The `db_charset` and `db_collation` fields are critical for correct database import. Importers must use these values when the destination database supports them. (Mismatched collation is a documented root cause of UTF-8 corruption and emoji loss in migration — see [§15](#15-design-rationale-and-prior-art).)
- `encryption_disabled_reason` is present and a non-empty string only when the archive is unencrypted (§8.5); it is absent or null otherwise, and when present it is recorded in the audit trail.
- `table_prefix` (optional, added in v1.1) records the source site's database table prefix, so a content-only restore can rewrite the source-prefixed table names to the destination's own prefix.
- `scope` (optional, added in v1.1) records what the archive backed up. **Its absence means a legacy whole-site archive** (one written before this field existed). Its fields:
  - `content_only` (boolean) — true for a content-only archive (the `wp-content` tree plus the whole database), false for a whole-site archive that also carries WordPress core and `wp-config.php`.
  - `content_root` (string) — the directory the file entries are relative to, given relative to the WordPress root: `wp-content` for a content-only archive, the empty string for a whole-site archive.
  - `includes_core` (boolean) — whether WordPress core (`wp-admin`, `wp-includes`, the root core PHP files) is in the archive.
  - `includes_wp_config` (boolean) — whether `wp-config.php` is in the archive.
  - `includes_database` (boolean) — whether the database is in the archive. `false` for a files-only backup; `true` otherwise.
  - `includes_files` (boolean, optional, added in v1.1) — whether file entries are in the archive. Serialised **only when `false`** (a database-only backup), so an archive that carries files is byte-identical to one written before the field existed; a reader treats an absent `includes_files` as `true`. See [ADR 0016](adr/0016-partial-scope-backups.md).
  - `excluded_paths` (string array) — the exclusion patterns that were applied.
  - A backup carries at least one half: a scope with `includes_files` and `includes_database` both `false` is invalid, and a reader refuses an archive whose scope declares a half absent while the manifest actually carries it.
- Unknown fields encountered by a reader must be preserved verbatim if the archive is re-emitted (e.g., by a converter); a reader must not strip future-version fields.

The provenance block is deliberately the first thing after the header so that a reader can inspect it without having to decrypt or process anything else. Operators about to import an archive can be shown its provenance and prompted to confirm it before any destructive operation begins.

## 6. Entries

After the provenance block, the archive contains a sequence of entries, each describing one file or one chunk of the database. Entries are laid out one after another with no gap or filler between them: the next entry's first byte sits immediately after the previous entry's last byte.

Each entry has the structure:

```
+--------+--------+--------+--------+----------+----------+
| header | header | codec  | nonce  | payload  | hash     |
| length |  JSON  |   id   |  12 B  | (length  | (32 B,   |
|  4 B   | (var)  |  2 B   |        | from     | SHA-256) |
|        |        |        |        | header)  |          |
+--------+--------+--------+--------+----------+----------+
```

The entry header is a JSON object (UTF-8) describing the entry. The `kind`
field always comes first, followed by the kind-specific fields in the fixed
order shown — the order is part of the byte-identical-output contract. A
`file` entry's header:

```json
{
  "kind": "file",
  "path": "wp-content/themes/twentytwentyfour/style.css",
  "size": 8842,
  "mode": 420,
  "mtime": 1767225600,
  "media_type": "text/css",
  "size_compressed": 2103
}
```

- **`size`** — the payload's uncompressed byte count, as an integer. Writers
  MUST record the byte count actually read when the entry was written — never
  a stale earlier stat — so a file that changes on disk while the archive is
  being produced is still described truthfully. Readers MUST refuse a `file`
  entry whose decoded payload length differs from `size`: with a conforming
  writer the two always agree, so a mismatch means the archive does not hold
  the content it claims and restoring the entry would silently write wrong
  bytes.
- **`mode`** — the POSIX mode bits as a plain integer (decimal in JSON; `420`
  is `0644` octal). Readers must reject a non-integer.
- **`mtime`** — the Unix modification timestamp as an integer. Readers must
  reject a non-integer.
- **`size_compressed`** — the stored payload's byte count after the codec ran;
  `0` when written by a streaming writer that cannot know it in advance.

Field rules:

- **header length:** uint32 big-endian, byte count of the header JSON.
- **codec id:** uint16 big-endian, identifying the compression and encryption codec applied to the payload (see [§7](#7-compression-codecs)).
- **nonce:** 12 bytes, used as the AES-GCM nonce. For encrypted archives this is constructed as described in [§8.3](#83-nonce-uniqueness). For unencrypted archives the field is present but unused; writers must zero-fill it.
- **payload:** the compressed (and possibly encrypted) bytes.
- **hash:** SHA-256 over the concatenation `header_length || header || codec_id || nonce || payload`. Computed over the as-stored bytes, so a reader can verify the entry without first decrypting it.

The `kind` field is one of:

- `file` — a regular file, with the fields shown above.
- `db_chunk` — a portion of the SQL dump. Its header carries `chunk_index`
  (0-based; chunks concatenated in `chunk_index` order form the table's
  complete dump), `table_name` (the source table the chunk belongs to),
  `statement_count` (the exact number of `;\n`-terminated statements in the
  payload — readers must refuse a chunk whose parsed count disagrees), and
  `byte_count` (the payload's uncompressed size), plus `size_compressed`.
- `directory` — present only to record permissions on an otherwise-empty
  directory: `path`, `mode` (integer), `size_compressed`. Payload is
  zero-length.
- `symlink` — the link target lives in the **header JSON** as a `target`
  field (`path`, `target`, `size_compressed`); the payload is zero-length.

The per-entry hash is the foundation of **corruption detection**: any modification to any byte of any entry — header, codec, nonce, or payload — changes the hash. The manifest records the expected hash for each entry; a reader verifies hashes before any further processing. These hashes are unkeyed, so they detect accidents (bit rot, truncation, a bad transfer), not attackers — anyone who can modify the file can recompute them. Tamper detection is the signature's job (section 12).

## 7. Compression codecs

A codec ID is a 16-bit identifier covering the combination of compression and encryption applied to an entry. The registry of v1 codecs:

| ID       | Compression | Encryption  | Notes                       |
|---------:|-------------|-------------|-----------------------------|
| `0x0000` | none        | none        | Plaintext, no compression   |
| `0x0001` | gzip        | none        | Plaintext, gzip-compressed  |
| `0x0002` | zstd        | none        | Plaintext, zstd-compressed  |
| `0x0100` | none        | AES-256-GCM | Encrypted plaintext         |
| `0x0101` | gzip        | AES-256-GCM | Encrypted gzip              |
| `0x0102` | zstd        | AES-256-GCM | Encrypted zstd              |

The high byte indicates the encryption scheme; the low byte indicates the compression. Future codecs may add new combinations without bumping the format major version, provided the registry remains backward-compatible — existing IDs never change meaning.

Codec policy for v1 writers:

- **gzip is mandatory** in every conforming implementation. PHP includes `ext-zlib` in the project's required extension set; zlib is available everywhere PHP runs.
- **zstd is preferred** when both source and destination support it. The PHP `ext-zstd` extension is detected at runtime; when present, the writer emits codec `0x0002` or `0x0102`. When absent, it falls back to gzip.
- **none** is used for already-compressed files (JPEG, PNG, MP4, etc.) where re-compression would waste CPU for no size gain. Writers may detect this from `media_type` or by a sampling heuristic.

Readers must support **all** v1 codecs to claim v1 compliance. This is non-negotiable. It is what prevents the kind of silent compatibility drift that has affected some existing WordPress archive formats over the years, where archives produced by one version of a tool cannot be read by another because the underlying compression library changed without a corresponding format version bump.

## 8. Encryption

When flag bit 0 is set, the archive is encrypted. The flow has three parts. First, the operator's passphrase is turned into a 256-bit binary key (key derivation, §8.1). Second, each entry's payload is encrypted independently using that key plus a unique per-entry nonce (§8.2 and §8.3). Third, the operator's passphrase must meet minimum strength requirements so the resulting encryption is actually strong (§8.4).

The cipher is AES-256-GCM. Unlike older modes like CBC, GCM both encrypts the data *and* produces an authentication tag — a small cryptographic fingerprint that catches any modification to the encrypted bytes when the reader tries to decrypt them. Modifying ciphertext without the key produces decryption failure, not silently corrupted output.

### 8.1 Key derivation

A key derivation function (KDF) converts a human-typed passphrase into a fixed-size binary key suitable for AES. Done right, a KDF is deliberately slow and memory-hungry — by design — so that brute-force attacks against the resulting archive are expensive even when the passphrase itself is not especially strong. The slow-and-hungry part is the whole point: a fast KDF is a weak one.

The encryption key is derived from the passphrase using Argon2id with the following parameters:

| Parameter   | Value                                                       |
|-------------|-------------------------------------------------------------|
| Variant     | Argon2id                                                    |
| Time cost   | 4 iterations                                                |
| Memory cost | 65 536 KiB (64 MiB)                                         |
| Parallelism | 1                                                           |
| Output      | 32 bytes (256 bits)                                         |
| Salt        | 16 bytes, random per archive, stored in the footer          |

Argon2id is the variant recommended by RFC 9106 for general-purpose key derivation. The chosen cost parameters are conservative for 2026 hardware while remaining tractable on PHP-based shared-hosting environments.

### 8.2 Encryption operation

For each encrypted entry:

1. The entry header, codec id, and nonce are written in plaintext.
2. The payload is compressed (if applicable) and then encrypted with AES-256-GCM using:
  - the derived key,
  - the per-entry nonce, and
  - the entry's plaintext header bytes as additional authenticated data (AAD).
3. The 16-byte GCM authentication tag is appended to the ciphertext. The combined ciphertext+tag is the stored payload.
4. The hash is computed over the as-stored bytes.

Using the plaintext header as AAD binds the metadata to the ciphertext: an attacker cannot move an encrypted payload to a different entry slot without invalidating the GCM tag.

### 8.3 Nonce uniqueness

AES-GCM (like every counter-mode-based cipher) becomes catastrophically insecure if the same nonce is ever used twice with the same key. The mechanism of the break: an attacker who obtains two ciphertexts encrypted with the same nonce-and-key combination can XOR them together to recover the XOR of the underlying plaintexts, which is often enough to reconstruct both. "Catastrophically" is not an exaggeration — this is the same failure mode that broke WEP and is documented as the single most important failure mode of GCM by NIST SP 800-38D.

To prevent any possibility of reuse within a single archive, nonces are constructed as:

```
nonce = entry_index (4 B big-endian) || random (8 B)
```

…where `entry_index` is the 0-based index of the entry within the archive. The 4-byte counter guarantees uniqueness within a single archive (up to 2³² entries). The random 8 bytes guard against accidental reuse across different archives that happen to share a passphrase.

### 8.4 Passphrase requirements

The strength of an encrypted archive depends on the strength of the passphrase. Key derivation can slow down brute-force attacks, but cannot create entropy where none exists. Writers must enforce these minimums:

- **Minimum length:** 10 characters. Writers must refuse to produce an encrypted archive with a passphrase shorter than this.
- **Recommended length:** 16 characters or more, drawn from a mixed character set.
- **No structural mandates:** writers must not require specific character classes (e.g., "must contain one uppercase letter and one symbol"). Such rules reduce passphrase entropy more than they help, by funnelling users into predictable patterns.
- **Entropy guidance:** writers should estimate the passphrase entropy (e.g., via the zxcvbn algorithm) and surface a warning when it falls below ~50 bits of entropy. The warning is informational; the writer still proceeds if the operator confirms.

### 8.5 Disabling encryption

A writer may produce an unencrypted archive only when the operator explicitly opts out, by clearing flag bit 0 and selecting unencrypted codecs for every entry. The `encryption_disabled_reason` field in the provenance block is mandatory in this case and must be a non-empty string. This is recorded in the audit log on import.

Operators choosing to disable encryption should understand they are responsible for protecting the archive's confidentiality through transport (HTTPS, SSH, etc.) and storage (filesystem permissions, encrypted at rest, etc.).

### 8.6 Passphrase recovery

There is none. Losing the passphrase makes the archive permanently unrecoverable. This is by design and is consistent with the security model: any built-in recovery mechanism would become attack surface that defeats the encryption's purpose. Writers must surface this fact prominently in the UI before encryption is enabled — typically as a confirmation dialog requiring explicit acknowledgement that the operator understands the consequence of passphrase loss.

## 9. Manifest

After the last entry, the archive contains a manifest describing every entry that precedes it.

```
+--------+--------+-------------------------------------------------+
| length |  hash  | manifest JSON (UTF-8, encrypted if archive is)  |
| 4 B    | 32 B   |                                                 |
+--------+--------+-------------------------------------------------+
```

The manifest JSON schema:

```json
{
  "entries": [
    {
      "index": 0,
      "offset": 1289,
      "length": 2147,
      "kind": "file",
      "path": "wp-content/themes/twentytwentyfour/style.css",
      "codec_id": 258,
      "hash": "a1b2c3..."
    },
    {
      "index": 1,
      "offset": 3436,
      "length": 278,
      "kind": "db_chunk",
      "chunk_index": 0,
      "codec_id": 258,
      "hash": "d4e5f6..."
    }
  ]
}
```

The top level is a single `entries` array — there is no version, count, or
totals wrapper (the format version lives in the archive header; the count is
the array's length). Per entry, `path` is present for `file`, `directory` and
`symlink` kinds; `chunk_index` is present for `db_chunk`; `hash` is the
per-entry SHA-256, hex-encoded.

The manifest is the random-access index. Readers wishing to extract a single file scan the manifest, locate that entry's offset, seek there, and read.

The manifest hash recorded in the footer is computed over the manifest's stored bytes. The manifest's per-entry hashes are the same SHA-256 values stored alongside each entry; the manifest is, in effect, a signed table of contents.

**Why the manifest sits at the end rather than at the beginning:** at the time a writer begins streaming entries to disk, it does not yet know the offsets, hashes, or final sizes of those entries. Writing the manifest at the end means the writer can stream entries directly to disk without buffering, then emit a single block of metadata once the entry stream is complete. This matches the strategy used by general-purpose archive formats whose central directory sits at the end of the file, and by container-image manifests.

## 10. Footer

The last 64 bytes of the archive (before the optional signature) are the footer:

| Offset (from EOF) | Length | Field                                                |
|------------------:|-------:|------------------------------------------------------|
|               −64 |   8    | Manifest offset (uint64 big-endian)                  |
|               −56 |   8    | Manifest length (uint64 big-endian)                  |
|               −48 |  32    | Manifest hash (SHA-256 of stored manifest)           |
|               −16 |  16    | Argon2id salt (zero-filled if archive unencrypted)   |

A reader locates the footer by seeking 64 bytes from the end of file — or 64 bytes before the signature, if present (per flag bit 1).

The footer is the trust anchor for random access: from it, a reader knows where the manifest is, how long it is, what its hash is supposed to be, and (for encrypted archives) the salt needed to derive the key. Without the footer, the archive is unreadable, which is itself a form of integrity check — truncating an archive corrupts the footer pointer and the reader knows immediately that something is wrong.

## 11. Optional detached signature

When flag bit 1 is set, an Ed25519 signature is appended after the footer. (Ed25519 is a modern public-key signature scheme — small keys, small signatures, single-step verification — used widely across modern secure systems.)

```
+--------+--------+--------+
| key id | sig    | sig    |
| 32 B   | length | bytes  |
|        | 4 B    | (64 B) |
+--------+--------+--------+
```

- **key id:** SHA-256 of the public key used.
- **sig length:** uint32 big-endian; for Ed25519 always `0x00000040`.
- **sig bytes:** Ed25519 signature over the **SHA-256 digest** of the bytes from offset 0 through the end of the footer — that is, `Ed25519( SHA-256( bytes[0 … end of footer] ) )`. This is **plain Ed25519** (libsodium's `crypto_sign_detached`) whose 32-byte *message* is the SHA-256 digest; it is **not** RFC 8032 Ed25519ph, and an implementation using a real Ed25519ph primitive will produce signatures that do not verify. The digest is computed by streaming those bytes, so neither signing nor verifying ever holds the whole archive in memory — the standard pre-hash construction for messages too large to buffer, sound here because SHA-256 is collision-resistant and the signed bytes begin with the magic and version, so a signature cannot be transferred to another context. The digest covers everything but the signature block itself: the header (including the signed flag), the provenance, every entry, the manifest, and the footer.

**Verification:** an operator who trusts a particular public key can verify the signature — by recomputing the SHA-256 of bytes [0 … end of footer] and checking it against that key — to prove the archive was produced by the holder of the corresponding private key. This is independent of encryption: an archive can be signed without being encrypted, and vice versa.

The signature is **detached and optional** for writers: v1 archives are not required to carry one. Reader obligations follow the trust model in section 12: a reader holding a trusted public key (supplied per run or pinned in configuration) MUST refuse an archive that is unsigned or whose signature does not verify — a stripped signature is indistinguishable from never-signed, and the unkeyed hashes cannot detect tampering. A reader with no trusted key should warn the operator when a signature is present and goes unverified.

## 12. Integrity and tamper detection

`.wpmig` integrity rests on four mechanisms, each catching a different class of problem:

| Mechanism                       | Detects                                                                          |
|---------------------------------|-----------------------------------------------------------------------------------|
| Per-entry SHA-256 hash          | Accidental modification of any single entry (unkeyed — an attacker can recompute) |
| Manifest hash in footer         | Accidental modification of the manifest itself (unkeyed, as above)                |
| AES-GCM authentication tag      | Modification of ciphertext (encrypted archives only)                              |
| Optional Ed25519 signature      | Modification by anyone except the holder of the signing key                       |

The distinction in the first two rows matters. The plain SHA-256 hashes are
**corruption** detection: they catch bit rot, truncation, and transfer errors.
They are not a **tamper** defence, because anyone who can edit the file can
recompute every hash so it still matches — and a signature can be *stripped*
(signed flag cleared, trailing block removed), leaving a well-formed unsigned
archive. The Ed25519 signature is therefore the only tamper defence, and it
protects only when the reader enforces it: a reader holding a trusted public
key (supplied per run, or pinned in configuration) MUST refuse an archive that
is unsigned or whose signature does not verify. A reader with no trusted key
provides corruption detection only, and must not claim otherwise.

### 12.1 On-import verification flow

A conforming reader performs verification in this order:

1. **Open and read header.** Confirm magic, confirm major version is supported. Refuse on mismatch.
2. **Read provenance.** Compute and verify provenance hash. Show provenance to the operator; require confirmation before proceeding.
3. **Read footer.** Verify the manifest offset is within the file (not pointing past EOF or into the header region).
4. **Read manifest.** Compute and verify manifest hash against the value in the footer.
5. **Walk entries from manifest.** For each entry, seek to its offset, read the stored bytes, compute SHA-256, and compare to the manifest's expected hash.
6. **If encrypted:** decrypt each entry's payload using the derived key, the per-entry nonce, and the entry header as AAD. AES-GCM will fail the authentication tag check if any byte has been modified.
7. **Signature enforcement:** when the operator has supplied or pinned a trusted public key, the archive MUST be signed and the Ed25519 signature MUST verify — recomputing the SHA-256 of bytes [0 … end of footer] and checking it against that key; an unsigned archive is refused as presumed-tampered (a stripped signature is indistinguishable from never-signed). With no trusted key, a signed archive's signature goes unverified and the reader should say so.

Any failure at any step halts the import. The reader must report which step failed, which entry or block was affected, and what the expected versus actual values were. Specifically: "Entry #1273 (`wp-content/uploads/2024/01/banner.jpg`): expected hash `a1b2…`, got `f7e8…`" — not "Import failed."

### 12.2 Logging detection events

When tamper detection fires, the reader must record the event in the destination WordPress instance's `wp_options` table under the option name `pontifex_integrity_log`. The value is an append-only JSON array; each entry includes:

```json
{
  "timestamp": "2026-05-21T16:04:11Z",
  "archive_hash": "sha256:...",
  "stage": "entry_hash_mismatch",
  "affected_entry": "wp-content/uploads/2024/01/banner.jpg",
  "expected": "a1b2c3...",
  "observed": "f7e8d9...",
  "override_used": false,
  "operator": "wordpress_user:admin"
}
```

This produces an audit trail that survives even if the operator subsequently chooses to override the failure, and that can be inspected later by anyone with database access to the WordPress instance.

### 12.3 Operator override

A reader may offer an explicit `--force` mechanism allowing import to proceed despite a tamper detection failure. When used:

- The fact is recorded in the audit log alongside the original failure event, with `override_used: true`.
- The destination WordPress instance is left with a persistent admin notice ("This site was last restored from an archive that failed integrity checks on YYYY-MM-DD; review `pontifex_integrity_log` for details") until the operator acknowledges and dismisses it.
- The dismissal itself is also logged.

The override exists for one realistic case: an archive that has been partially corrupted but where the operator judges some recoverable data is better than no data. It is not a mechanism for silencing the integrity check; the audit log and the admin notice ensure no override is ever invisible.

## 13. Forward and backward compatibility

### 13.1 Version compatibility rules

- **Major version bump (v1 → v2):** indicates a breaking format change. v1 readers must refuse v2 archives with a clear error rather than attempt to interpret them.
- **Minor version bump (v1.0 → v1.1):** indicates a backward-compatible change — new codec IDs, new provenance fields, new optional flags. v1.0 readers must accept v1.1 archives, ignore the additions they do not understand, and preserve them on re-emit (so that round-tripping through an older reader does not silently strip information).
- **Reserved bits:** any reserved flag bit set in an archive a reader does not understand must cause the reader to reject the archive as "unknown extensions present," not silently ignore.

### 13.2 Invariants — what must never change in v1.x

To preserve durability across decades, the following elements are guaranteed to remain valid for every v1.x version of this specification. They are organised into four categories so future implementers can check candidate changes against the appropriate list. Anything in these lists can be replaced only by a major version bump (v2), and only with good reason — for example, a critical cryptographic break in one of the primitives.

#### 13.2.1 Byte-level invariants

The bits on disk. Any change here would prevent old readers from parsing new archives or vice versa.

- The 8-byte magic value `WPMIG\0\0\x01`. New variants are not "WPMIG"; they are entirely new formats with their own magic.
- The 16-byte header layout: magic (8 B) → major version (2 B) → minor version (2 B) → flags (4 B), in that order, big-endian.
- Big-endian byte order for every multi-byte integer in the format. No little-endian sections, ever.
- The position of the provenance block: immediately after the header, with no padding.
- The structure of every length-prefixed block: 4-byte big-endian length, then 32-byte SHA-256 hash of stored payload, then payload.
- The per-entry layout: `header_length (4 B) || header_JSON || codec_id (2 B) || nonce (12 B) || payload || hash (32 B)`.
- The position of the manifest: immediately after the last entry, before the footer.
- The structure and size of the footer: exactly 64 bytes, with `manifest_offset (8 B) || manifest_length (8 B) || manifest_hash (32 B) || argon2id_salt (16 B)`.
- The footer's distance from the end of the file: 64 bytes when no signature is present, 64 bytes before the signature block when one is present.
- The optional signature block layout when present: `key_id (32 B) || sig_length (4 B) || sig_bytes`.
- UTF-8 as the text encoding for every string in the format, including JSON blocks and filename paths.
- ISO 8601 with explicit UTC offset for every timestamp.

#### 13.2.2 Cryptographic invariants

The primitives chosen for security. These are locked because changing any of them would invalidate the security analysis and require re-auditing every implementation.

- **SHA-256** as the integrity hash algorithm everywhere it is used: per-entry hashes, manifest hash in footer, provenance hash, signature key id, and the prehash digest the detached signature is computed over.
- **AES-256-GCM** as the only authenticated encryption mode used in v1.
- **Argon2id** with the exact v1 parameters as the only key derivation function. The parameters are: 4 iterations, 65 536 KiB memory, 1 thread, 32-byte output, 16-byte salt.
- **Nonce construction**: 4-byte big-endian entry index, followed by 8 random bytes. No other construction is permitted.
- **AAD binding**: the plaintext entry header (including its length prefix) is the AAD for each encrypted entry. No other AAD scheme is permitted.
- **Ed25519** as the only signature algorithm for the optional detached signature, computed over the SHA-256 prehash of the archive (§11), never the raw bytes directly.
- **The verification order on import** (header → provenance → footer → manifest → entries → signature). Reordering creates exploitable race conditions; the order is part of the security contract.

#### 13.2.3 Semantic invariants

What existing fields mean. Future versions may add new fields, but cannot redefine the meaning of fields that already exist.

- Codec ID `0x0000` always means "no compression, no encryption."
- Codec ID `0x0001` always means "gzip compression, no encryption."
- Codec ID `0x0002` always means "zstd compression, no encryption."
- Codec ID `0x0100` always means "no compression, AES-256-GCM encryption."
- Codec ID `0x0101` always means "gzip compression, AES-256-GCM encryption."
- Codec ID `0x0102` always means "zstd compression, AES-256-GCM encryption."
- The codec-ID encoding scheme: high byte for encryption family, low byte for compression family. New codec IDs are added in unused ranges; existing ones never change meaning.
- Flag bit 0 always means "archive is encrypted."
- Flag bit 1 always means "detached signature appended after the footer."
- Flag bit 2 always means "provenance block is itself encrypted."
- The provenance fields listed in §5 keep their names and types. Future versions may add fields, but `db_charset`, `db_collation`, `wp_version`, `php_version`, `url`, and the `exporter` object keep their v1 semantics exactly.
- The `kind` field on entries always uses the values `file`, `db_chunk`, `directory`, `symlink`. New kinds may be added; existing ones never change meaning.
- The manifest's `entries` array is always indexed in the order entries appear in the archive, starting from 0.

#### 13.2.4 Conformance invariants

What conforming implementations must always do. These are behavioural, not structural, but they are equally part of the format contract because readers and writers depend on each other behaving this way.

- Writers must include all required provenance fields. Omission is a malformed archive.
- Writers must zero-fill the nonce field for unencrypted entries (do not leave it uninitialised).
- Writers must populate `encryption_disabled_reason` with a non-empty string when producing an unencrypted archive.
- Readers must reject any archive with a reserved flag bit set, citing "unknown extensions present."
- Readers must reject any archive whose major version is higher than they implement, citing "format version unknown."
- Readers must verify the provenance hash before parsing the provenance JSON content.
- Readers must verify the manifest hash before trusting any offset or length value in the manifest.
- Readers must verify each entry's hash before decrypting or decompressing its payload.
- Readers must log integrity-failure events to the `pontifex_integrity_log` option in the destination WordPress instance, append-only.
- Readers offering an override mechanism (e.g., `--force`) must record the override in the audit log and leave a persistent admin notice in the destination instance.
- Implementations must never silently drop unknown future-version fields when re-emitting an archive (e.g., during conversion).

### 13.3 What v1.x may add

The format is deliberately extensible. Future minor versions may introduce, without breaking compatibility:

- New compression codecs in unused codec-ID ranges (e.g., `0x0003` for lz4, `0x0004` for brotli).
- New encryption codecs in unused high-byte ranges (e.g., `0x0200xx` for ChaCha20-Poly1305 family).
- New provenance fields, provided existing fields retain their v1 semantics.
- New manifest fields, with the same constraint.
- New entry `kind` values for cases v1 did not anticipate.
- New optional flag bits in the reserved range, subject to the rule in §13.2.4 that readers unable to parse them must reject the archive.

**v1.1 — the first minor revision** added the optional `scope` block and `table_prefix` provenance fields (§5), recording what an archive backed up. A v1.0 reader accepts a v1.1 archive and ignores these additions, per the rules above.

The asymmetry is deliberate: adding things is cheap because readers ignoring an unknown extension is safe; redefining things is expensive because readers acting on misinterpreted bytes is dangerous.

## 14. Reference implementations

### 14.1 PHP (Pontifex plugin)

The PHP implementation lives inside the Pontifex WordPress plugin. Source: <https://github.com/7Duckie/pontifex>. Licence: GPL-2.0-or-later.

### 14.2 Go (planned, deferred to a later release)

A standalone Go CLI implementing read, verify, list, and conversion operations on `.wpmig` archives is planned for a future release. Its purposes:

- **Independent verification** ("can I trust this archive?") without requiring a WordPress install on hand.
- **Recovery and emergency extraction** when the PHP plugin is unavailable — for example, when the destination WordPress instance cannot be brought up enough to install Pontifex.
- **Conversion to other formats** (`.tar`, `.zip`) for inspection by general-purpose tools.
- **Validation that the specification is unambiguous** — two implementations producing byte-identical output from identical input is the strongest evidence the spec is correctly written.

The Go implementation is deferred because it is not required to ship the v1.0 plugin. Once the format is locked in and the PHP implementation is stable, the Go implementation will be built strictly from this document, with no reference to the PHP code — the test of a well-written specification is that someone implementing from it produces compatible output.

## 15. Design rationale and prior art

Each major design decision in this specification was informed by prior work. The relevant influences:

**Length-prefixed streamable entries** are taken from an existing single-file WordPress archive format, where this layout has proven workable for very large archives on memory-constrained PHP hosts. Limitations of that format that informed our additions: no format versioning; no compression at the format layer (its tooling tacks on its own, and has changed it incompatibly between versions, breaking older backups); no encryption; no checksums; no manifest; lossy metadata (no directory entries, no permissions, no ownership).

**Manifest at the end with a footer pointer** is taken from general-purpose archive formats whose central directory sits at the end of the file, enabling fast random access and stream writing simultaneously. The same pattern is used by container-image manifests.

**Per-entry independent compression with a codec ID** is taken from block-compressed archive formats, which use block-level independence to enable random-access decompression of large datasets. The pattern lets us decompress individual entries without unpacking the whole archive, and lets us mix codecs based on what is available on the source host.

**Encryption by default with passphrase-derived keys** is taken from modern deduplicating backup tools, which refuse to create an unencrypted repository without explicit opt-in. The observation behind this choice: backups in transit between hosts, sitting in cloud storage, or shared between collaborators are a high-value target. Optional encryption is, in practice, almost-no encryption, because operators forget. Encryption by default is the only setting that actually protects data.

**Argon2id key derivation with conservative parameters** follows the recommendations of [RFC 9106](https://www.rfc-editor.org/rfc/rfc9106.html). Argon2id balances protection against side-channel attacks (Argon2i's strength) with protection against GPU brute-force (Argon2d's strength).

**AES-256-GCM authenticated encryption** follows the recommendations of [NIST SP 800-38D](https://csrc.nist.gov/publications/detail/sp/800-38d/final) and the established use of GCM in TLS 1.3. Using the entry header as additional authenticated data (AAD) binds metadata to ciphertext, preventing payload-swap attacks.

**Nonce construction from counter and randomness** follows the pattern documented in [RFC 5116 §3.2](https://www.rfc-editor.org/rfc/rfc5116) for AEAD nonce management. The counter portion guarantees uniqueness within an archive; the random portion guards against accidental reuse across archives with the same key.

**SHA-256 per-entry hashing for integrity** is borrowed from the general pattern of content-addressed storage used by version-control and container-image systems. SHA-256 was chosen rather than the slightly faster BLAKE2b/BLAKE3 because SHA-256 is available in PHP's built-in `hash()` function without requiring any extra extension — a strict baseline-availability decision for the WordPress hosting environment.

**Optional Ed25519 detached signatures** follow the pattern of simple file-level signing tools, which provide signing without the complexity of a full web-of-trust system. The signature is deliberately detached and optional: most operators will not use it, but those who need cryptographic provenance can.

**Provenance metadata schema** is informed by what real WordPress migration plugins struggle with in practice. Reports of importing archives where source and destination databases have different default collations produce corruption — UTF-8 characters silently converted to Latin-1, emojis turned into "?" symbols, and so on. Explicitly recording the source charset and collation in provenance lets the importer detect the mismatch and either compensate or refuse cleanly. This collation-mismatch corruption is a broad, well-reported pattern across existing migration tools.

**Per-entry hashes as the foundation of tamper detection, with separate manifest and footer hashes**, comes from modern deduplicating backup tools, which distinguish between cheap CRC verification and full cryptographic verification. We adopt the cryptographic version as the default rather than offering it as a power-user option.

**Operator override (`--force`) with persistent audit logging** is informed by repeated reports in plugin support threads where users encounter restore failures and have no way to know what failed or whether partial recovery is possible. Rather than refuse outright, we provide a deliberate override path that records exactly what was overridden, when, and by whom. The persistent admin notice approach is borrowed from how WordPress itself signals security-relevant configuration to administrators.

**"Lose the passphrase, lose the data"** is the standard position of serious passphrase-based backup tools, which are unambiguous on this point. Some tools attempt to provide passphrase recovery; these mechanisms become attack surface in their own right. v1 takes the position that operators are responsible for passphrase management.

### Prior art we considered and did not adopt

**A proposed WordPress core export format** built on ZIP is the closest existing fit, designed as a candidate WordPress core standard. It is not adopted because: (a) it does not provide encryption or integrity guarantees beyond ZIP's CRC32; (b) it is still under active design at the time of writing; (c) it does not capture the migration-specific provenance (source URL, charset, plugin set) essential for correct search-replace operations. We follow its WordPress-aware directory conventions where applicable.

**A composition of stock Unix tools** (an archive container for structure, a compressor, and an encryption tool) was considered. It is a strong technical solution but provides no place for migration-specific metadata, no format-level versioning of the combination, and is ergonomically poor for non-Unix users — most of the WordPress audience.

**Deduplicating repository formats** were considered. They are wrong-shape for our use case: designed for "many backups accumulating in a vault over time," not single-file portable archives suitable for moving between hosts.

**An undocumented proprietary migration format** was considered. Its internals are not publicly documented. Adopting an undocumented format would defeat the purpose of writing this specification.

## 16. References

The following references informed this specification. URLs are stable as of May 2026.

### Standards and RFCs

- RFC 9106 — Argon2 Memory-Hard Function for Password Hashing and Proof-of-Work Applications. <https://www.rfc-editor.org/rfc/rfc9106.html>
- RFC 5116 — An Interface and Algorithms for Authenticated Encryption. <https://www.rfc-editor.org/rfc/rfc5116>
- NIST SP 800-38D — Recommendation for Block Cipher Modes of Operation: Galois/Counter Mode (GCM) and GMAC. <https://csrc.nist.gov/publications/detail/sp/800-38d/final>
- ISO 8601 — Date and time representations.

## 17. Appendices

### Appendix A: Test vectors

A canonical v1.1 archive is committed in the Pontifex repository at
`tests/Fixtures/conformance-v1_1.wpmig`, together with a conformance test
(`tests/Unit/Archive/ConformanceTest.php`) that rebuilds it from the inputs
below and asserts byte identity. A reference implementation must produce these
exact bytes from these exact inputs.

**Inputs.** Provenance: WordPress `6.6.1`, PHP `8.2.0`, URL
`https://conformance.example`, charset `utf8mb4`, collation
`utf8mb4_unicode_520_ci`, exporter `pontifex 1.0.0`, exported at
`2026-01-01T00:00:00+00:00`. Four entries, all raw codec (id `0`) with
zero-filled nonces, in this order:

1. `file` — path `wp-content/hello.txt`, contents `hello wpmig\n` (12 bytes),
   mode `420` (0644), mtime `1767225600`, media type `text/plain`.
2. `directory` — path `wp-content/uploads`, mode `493` (0755).
3. `symlink` — path `wp-content/link`, target `../hello.txt`.
4. `db_chunk` — table `wp_options`, chunk 0, 3 statements, payload
   `DROP TABLE IF EXISTS \`wp_options\`;\nCREATE TABLE \`wp_options\` (id INT);\nINSERT INTO \`wp_options\` VALUES (1);\n` (106 bytes).

**Expected output.** Total size **1802 bytes**; whole-file SHA-256
`bb6cdc326fd715ec83986992639ab1e30e2d6e202a43ea2bb5da95e84d039cb4`.

Header (16 bytes, hex): `57504d49470000010001000100000000` — the 8-byte magic
`WPMIG\x00\x00\x01`, major `0x0001`, minor `0x0001`, flags `0x00000000`, all
big-endian.

Manifest entry table:

| index | kind      | offset | length | entry SHA-256 (hex) |
|-------|-----------|--------|--------|----------------------|
| 0     | file      | 284    | 194    | `16edd8a48c5ab1780e18fb6e21a5a64b2771bdd98e0d813d543e0d6919cffa62` |
| 1     | directory | 478    | 129    | `d6927f23eecc5a6394bd1464cd679f9636b90d7f7c46a8175c114f39bbff0737` |
| 2     | symlink   | 607    | 137    | `1d5b40a5eafac3572570109e5df8497cfd905dcc4c396c338ac5cf8eb4be6b4a` |
| 3     | db_chunk  | 744    | 278    | `77a552dbd7232e9c7b612672f9f760c6f4642ef1f6d8af589a8922f0c54efd81` |

### Appendix B: Glossary

- **AAD (Additional Authenticated Data):** in AEAD ciphers, data that is authenticated but not encrypted. Used here to bind plaintext entry headers to encrypted payloads.
- **AEAD (Authenticated Encryption with Associated Data):** an encryption mode that both encrypts and authenticates, so any modification to ciphertext or AAD is detected on decryption.
- **Argon2id:** a memory-hard password-based key derivation function; the recommended variant of Argon2 per RFC 9106.
- **Codec ID:** the 16-bit identifier in each entry that encodes the combination of compression and encryption applied.
- **Detached signature:** a cryptographic signature stored separately from (in this case, appended after) the data it signs.
- **Entry:** a single unit of data in the archive — a file, a database chunk, a directory, or a symlink.
- **GCM (Galois/Counter Mode):** an AEAD mode for block ciphers, used here with AES-256.
- **Manifest:** the block at the end of a `.wpmig` archive listing all entries with their offsets, lengths, codecs, and hashes.
- **Nonce:** a number-used-once value combined with a key to encrypt data. Reuse with the same key is catastrophic for GCM.
- **Provenance:** the block near the start of a `.wpmig` archive recording the source environment.

---

*End of specification.*
