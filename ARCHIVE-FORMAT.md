# Pontifex Archive Format Specification

**File extension:** `.wpmig`
**Version:** 1.0 (DRAFT)
**Status:** Draft — finalised when Pontifex 0.1.0 ships. Until that point, this specification may change with each minor version release.
**Licence:** This specification is published under CC BY 4.0. Implementations of the format are not restricted; the spec text itself may be redistributed with attribution.

---

## 1. Introduction

`.wpmig` is a documented, versioned, open archive format designed to package a complete WordPress site — files, database, configuration, and contextual metadata — into a single portable file that can be moved between hosts and reliably reconstructed.

The format is open: this document is the contract, and any party may build an implementation. Pontifex ships one implementation in PHP (as a WordPress plugin); the project intends to ship a second reference implementation in Go as a standalone command-line tool. Two independent implementations from the same specification, producing byte-identical archives from identical inputs, is the project's working definition of "the specification is correct."

This format takes lessons from prior work, both in the WordPress space (`.wpress`, DupArchive, UpdraftPlus's multi-zip approach, WordPress Playground's proposed core export) and from outside it (TAR, ZIP, BGZF, BorgBackup, Restic, OCI image manifests, the in-toto attestation framework). Where a decision is non-obvious, [§15 — Design rationale and prior art](#15-design-rationale-and-prior-art) explains which prior format informed it.

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

- **Deduplication across archives.** Unlike BorgBackup or Restic, `.wpmig` is a single-archive single-site format, not a deduplicating repository. Each archive is self-contained.
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
|  10    |   2    | Format minor   | uint16 big-endian — `0x0000` for v1.0              |
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

The schema of the JSON object (v1):

```json
{
  "format_version": 1,
  "exporter": {
    "name": "pontifex",
    "version": "0.1.0",
    "implementation": "php"
  },
  "exported_at": "2026-05-21T14:23:01Z",
  "source": {
    "url": "https://example.com",
    "wp_version": "6.8.0",
    "php_version": "8.2.18",
    "db_engine": "mysql",
    "db_version": "8.0.36",
    "db_charset": "utf8mb4",
    "db_collation": "utf8mb4_unicode_520_ci",
    "table_prefix": "wp_",
    "multisite": false
  },
  "scope": {
    "includes_database": true,
    "includes_uploads": true,
    "includes_themes": true,
    "includes_plugins": true,
    "includes_mu_plugins": true,
    "includes_wp_config": false,
    "excluded_paths": ["wp-content/cache", "wp-content/debug.log"]
  },
  "plugins_active": [
    { "slug": "woocommerce", "version": "8.5.0" }
  ],
  "themes_active": [
    { "slug": "twentytwentyfour", "version": "1.2" }
  ],
  "encryption_disabled_reason": null,
  "note": "Optional human-readable note set by the operator at export time."
}
```

Field rules:

- All timestamps are ISO 8601 with explicit UTC offset.
- Unknown fields encountered by a reader must be preserved verbatim if the archive is re-emitted (e.g., by a converter); a v1 reader must not strip future-version fields.
- The `db_charset` and `db_collation` fields are critical for correct database import. Importers must use these values when the destination database supports them. (Mismatched collation is the documented root cause of UTF-8 corruption and emoji loss in existing migration tools — see [§15](#15-design-rationale-and-prior-art).)
- When the archive is unencrypted, `encryption_disabled_reason` must be a non-empty string explaining why; this is recorded in the audit trail.

The provenance block is deliberately the first thing after the header so that a reader can inspect it without having to decrypt or process anything else. Operators about to import an archive can be shown its provenance and prompted to confirm it before any destructive operation begins.

## 6. Entries

After the provenance block, the archive contains a sequence of entries, each describing one file or one chunk of the database. Entries are laid out one after another, with no padding or alignment requirement.

Each entry has the structure:

```
+--------+--------+--------+--------+----------+----------+
| header | header | codec  | nonce  | payload  | hash     |
| length |  JSON  |   id   |  12 B  | (length  | (32 B,   |
|  4 B   | (var)  |  2 B   |        | from     | SHA-256) |
|        |        |        |        | header)  |          |
+--------+--------+--------+--------+----------+----------+
```

The entry header is a JSON object (UTF-8) describing the entry:

```json
{
  "path": "wp-content/themes/twentytwentyfour/style.css",
  "kind": "file",
  "size_uncompressed": 8842,
  "size_compressed": 2103,
  "modified_at": "2026-05-01T09:12:33Z",
  "mode": "0644",
  "media_type": "text/css"
}
```

Field rules:

- **header length:** uint32 big-endian, byte count of the header JSON.
- **codec id:** uint16 big-endian, identifying the compression and encryption codec applied to the payload (see [§7](#7-compression-codecs)).
- **nonce:** 12 bytes, used as the AES-GCM nonce. For encrypted archives this is constructed as described in [§8.3](#83-nonce-uniqueness). For unencrypted archives the field is present but unused; writers must zero-fill it.
- **payload:** the compressed (and possibly encrypted) bytes.
- **hash:** SHA-256 over the concatenation `header_length || header || codec_id || nonce || payload`. Computed over the as-stored bytes, so a reader can verify the entry without first decrypting it.

The `kind` field is one of:

- `file` — a regular file.
- `db_chunk` — a portion of the SQL dump. An additional `chunk_index` field (0-based) is present, and the chunks together form the complete dump when concatenated in `chunk_index` order.
- `directory` — present only to record permissions on an otherwise-empty directory. Payload is zero-length.
- `symlink` — payload contains the link target as UTF-8.

The per-entry hash is the foundation of tamper detection: any modification to any byte of any entry — header, codec, nonce, or payload — changes the hash. The manifest records the expected hash for each entry; a reader verifies hashes before any further processing.

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

Readers must support **all** v1 codecs to claim v1 compliance. This is non-negotiable. It is what prevents the kind of silent compatibility drift that has affected `.wpress` over the years, where archives produced by one version of the plugin cannot be read by another because the underlying compression library changed without a corresponding format version bump.

## 8. Encryption

When flag bit 0 is set, the archive is encrypted using AES-256-GCM with a key derived from a user-supplied passphrase.

### 8.1 Key derivation

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

Nonce reuse with the same key catastrophically breaks AES-GCM. To prevent any possibility of reuse within a single archive, nonces are constructed as:

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
  "format_version": 1,
  "entry_count": 18341,
  "entries": [
    {
      "index": 0,
      "offset": 1289,
      "length": 2147,
      "path": "wp-content/themes/twentytwentyfour/style.css",
      "kind": "file",
      "codec_id": 258,
      "hash": "a1b2c3..."
    }
  ],
  "totals": {
    "uncompressed_bytes": 184729382,
    "compressed_bytes": 38291928
  }
}
```

The manifest is the random-access index. Readers wishing to extract a single file scan the manifest, locate that entry's offset, seek there, and read.

The manifest hash recorded in the footer is computed over the manifest's stored bytes. The manifest's per-entry hashes are the same SHA-256 values stored alongside each entry; the manifest is, in effect, a signed table of contents.

**Why the manifest sits at the end rather than at the beginning:** at the time a writer begins streaming entries to disk, it does not yet know the offsets, hashes, or final sizes of those entries. Writing the manifest at the end means the writer can stream entries directly to disk without buffering, then emit a single block of metadata once the entry stream is complete. This matches the strategy used by ZIP (central directory at end), Docker/OCI image layers, and PKZIP.

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

When flag bit 1 is set, an Ed25519 signature is appended after the footer:

```
+--------+--------+--------+
| key id | sig    | sig    |
| 32 B   | length | bytes  |
|        | 4 B    | (64 B) |
+--------+--------+--------+
```

- **key id:** SHA-256 of the public key used.
- **sig length:** uint32 big-endian; for Ed25519 always `0x00000040`.
- **sig bytes:** Ed25519 signature over the bytes from offset 0 through the end of the footer.

**Verification:** an operator who trusts a particular public key can verify the signature to prove the archive was produced by the holder of the corresponding private key. This is independent of encryption: an archive can be signed without being encrypted, and vice versa.

The signature is **detached and optional**. v1 archives are not required to carry one; readers are not required to verify one when present, though they should warn the operator if a signature is present and not verified.

## 12. Integrity and tamper detection

`.wpmig` integrity rests on four mechanisms, each catching a different class of problem:

| Mechanism                       | Detects                                                       |
|---------------------------------|---------------------------------------------------------------|
| Per-entry SHA-256 hash          | Modification of any single entry                              |
| Manifest hash in footer         | Modification of the manifest itself                           |
| AES-GCM authentication tag      | Modification of ciphertext (encrypted archives only)          |
| Optional Ed25519 signature      | Modification by anyone except the holder of the signing key   |

### 12.1 On-import verification flow

A conforming reader performs verification in this order:

1. **Open and read header.** Confirm magic, confirm major version is supported. Refuse on mismatch.
2. **Read provenance.** Compute and verify provenance hash. Show provenance to the operator; require confirmation before proceeding.
3. **Read footer.** Verify the manifest offset is within the file (not pointing past EOF or into the header region).
4. **Read manifest.** Compute and verify manifest hash against the value in the footer.
5. **Walk entries from manifest.** For each entry, seek to its offset, read the stored bytes, compute SHA-256, and compare to the manifest's expected hash.
6. **If encrypted:** decrypt each entry's payload using the derived key, the per-entry nonce, and the entry header as AAD. AES-GCM will fail the authentication tag check if any byte has been modified.
7. **If signed:** verify the Ed25519 signature against the trusted public key, if the operator has supplied one.

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

- **SHA-256** as the integrity hash algorithm everywhere it is used: per-entry hashes, manifest hash in footer, provenance hash, signature key id.
- **AES-256-GCM** as the only authenticated encryption mode used in v1.
- **Argon2id** with the exact v1 parameters as the only key derivation function. The parameters are: 4 iterations, 65 536 KiB memory, 1 thread, 32-byte output, 16-byte salt.
- **Nonce construction**: 4-byte big-endian entry index, followed by 8 random bytes. No other construction is permitted.
- **AAD binding**: the plaintext entry header (including its length prefix) is the AAD for each encrypted entry. No other AAD scheme is permitted.
- **Ed25519** as the only signature algorithm for the optional detached signature.
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

**Length-prefixed streamable entries** are taken from the `.wpress` format used by All-in-One WP Migration, where this layout has proven workable for very large archives on memory-constrained PHP hosts. Reference implementation: [yani-/wpress](https://github.com/yani-/wpress) (MIT). Limitations of `.wpress` that informed our additions: no format versioning; no compression at the format layer (the plugin tacks on its own, and has changed it incompatibly between versions, breaking older backups); no encryption; no checksums; no manifest; lossy metadata (no directory entries, no permissions, no ownership).

**Manifest at the end with a footer pointer** is taken from the PKZIP and ZIP family of formats, where the central directory at the end of the file enables fast random access and stream writing simultaneously. The same pattern is used by Docker/OCI image manifests. Reference: [PKWARE APPNOTE.TXT](https://pkware.cachefly.net/webdocs/casestudies/APPNOTE.TXT).

**Per-entry independent compression with a codec ID** is taken from BGZF (Blocked GZIP Format), which uses block-level independence to enable random-access decompression of bioinformatics datasets. Reference: [SAM/BAM specification §4](https://samtools.github.io/hts-specs/SAMv1.pdf). The pattern lets us decompress individual entries without unpacking the whole archive, and lets us mix codecs based on what is available on the source host.

**Encryption by default with passphrase-derived keys** is taken from BorgBackup and Restic, both of which refuse to create an unencrypted repository without explicit opt-in. References: [BorgBackup security model](https://borgbackup.readthedocs.io/en/stable/internals/security.html), [Restic references](https://restic.readthedocs.io/en/stable/100_references.html). The observation behind this choice: backups in transit between hosts, sitting in cloud storage, or shared between collaborators are a high-value target. Optional encryption is, in practice, almost-no encryption, because operators forget. Encryption by default is the only setting that actually protects data.

**Argon2id key derivation with conservative parameters** follows the recommendations of [RFC 9106](https://www.rfc-editor.org/rfc/rfc9106.html). Argon2id balances protection against side-channel attacks (Argon2i's strength) with protection against GPU brute-force (Argon2d's strength).

**AES-256-GCM authenticated encryption** follows the recommendations of [NIST SP 800-38D](https://csrc.nist.gov/publications/detail/sp/800-38d/final) and the established use of GCM in TLS 1.3. Using the entry header as additional authenticated data (AAD) binds metadata to ciphertext, preventing payload-swap attacks.

**Nonce construction from counter and randomness** follows the pattern documented in [RFC 5116 §3.2](https://www.rfc-editor.org/rfc/rfc5116) for AEAD nonce management. The counter portion guarantees uniqueness within an archive; the random portion guards against accidental reuse across archives with the same key.

**SHA-256 per-entry hashing for integrity** is borrowed from the general pattern used by Git (content-addressed blobs), OCI image manifests, and Sigstore. SHA-256 was chosen rather than the slightly faster BLAKE2b/BLAKE3 because SHA-256 is available in PHP's built-in `hash()` function without requiring any extra extension — a strict baseline-availability decision for the WordPress hosting environment.

**Optional Ed25519 detached signatures** follow the pattern used by minisign and signify, which provide simple file-level signing without the complexity of PGP-style web of trust. The signature is deliberately detached and optional: most operators will not use it, but those who need cryptographic provenance can.

**Provenance metadata schema** is informed by what real WordPress migration plugins struggle with in practice. Reports of importing archives where source and destination databases have different default collations produce corruption — UTF-8 characters silently converted to Latin-1, emojis turned into "?" symbols, and so on. Explicitly recording the source charset and collation in provenance lets the importer detect the mismatch and either compensate or refuse cleanly. See user reports such as the [WordPress.org support topic on UTF-8 → Latin-1 conversion in Duplicator](https://wordpress.org/support/plugin/duplicator/) and the [collation failure on six-year-old Duplicator backups](https://wordpress.org/support/plugin/duplicator/) — both representative of a broad pattern.

**Per-entry hashes as the foundation of tamper detection, with separate manifest and footer hashes**, comes from BorgBackup, where the [check command](https://borgbackup.readthedocs.io/en/stable/usage/check.html) distinguishes between cheap CRC verification and full cryptographic verification. We adopt the cryptographic version as the default rather than offering it as a power-user option.

**Operator override (`--force`) with persistent audit logging** is informed by repeated reports in plugin support threads where users encounter restore failures and have no way to know what failed or whether partial recovery is possible — the [WordPress.org support topic where the import gets stuck mid-process](https://wordpress.org/support/topic/import-process-gets-stuck/) is one of many. Rather than refuse outright, we provide a deliberate override path that records exactly what was overridden, when, and by whom. The persistent admin notice approach is borrowed from how WordPress itself signals security-relevant configuration to administrators.

**"Lose the passphrase, lose the data"** is taken from BorgBackup's documentation, which is unambiguous on this point. Some tools attempt to provide passphrase recovery; these mechanisms become attack surface in their own right. v1 takes the position that operators are responsible for passphrase management.

### Prior art we considered and did not adopt

**WordPress Playground's ZIP-based export format** is the closest existing fit and is explicitly designed by Automattic-funded developers as a candidate WordPress core standard. See [the export format design discussion](https://github.com/WordPress/wordpress-playground/issues/1563). It is not adopted because: (a) it does not provide encryption or integrity guarantees beyond ZIP's CRC32; (b) it is still under active design at the time of writing; (c) it does not capture the migration-specific provenance (source URL, charset, plugin set) essential for correct search-replace operations. We follow its WordPress-aware directory conventions where applicable.

**`.tar.zst.age` composition** of three Unix tools (tar for structure, zstd for compression, age for encryption) was considered. It is a strong technical solution but provides no place for migration-specific metadata, no format-level versioning of the combination, and is ergonomically poor for non-Unix users — most of the WordPress audience.

**BorgBackup and Restic repository formats** were considered. They are wrong-shape for our use case: deduplicating repository formats designed for "many backups accumulating in a vault over time," not single-file portable archives suitable for moving between hosts.

**`.daf` (DupArchive)** was considered. It is undocumented; the format internals are not part of the public Duplicator documentation. Adopting an undocumented format would defeat the purpose of writing this specification.

## 16. References

The following references informed this specification. URLs are stable as of May 2026.

### Standards and RFCs

- RFC 9106 — Argon2 Memory-Hard Function for Password Hashing and Proof-of-Work Applications. <https://www.rfc-editor.org/rfc/rfc9106.html>
- RFC 5116 — An Interface and Algorithms for Authenticated Encryption. <https://www.rfc-editor.org/rfc/rfc5116>
- NIST SP 800-38D — Recommendation for Block Cipher Modes of Operation: Galois/Counter Mode (GCM) and GMAC. <https://csrc.nist.gov/publications/detail/sp/800-38d/final>
- ISO 8601 — Date and time representations.

### Format specifications

- ZIP File Format Specification (PKWARE APPNOTE.TXT). <https://pkware.cachefly.net/webdocs/casestudies/APPNOTE.TXT>
- TAR (GNU). <https://www.gnu.org/software/tar/manual/html_node/Standard.html>
- BGZF (Blocked GZIP). SAM/BAM specification §4. <https://samtools.github.io/hts-specs/SAMv1.pdf>
- OCI Image Manifest Specification. <https://github.com/opencontainers/image-spec/blob/main/manifest.md>
- in-toto Attestation Framework. <https://github.com/in-toto/attestation>

### Existing backup and migration formats

- WPRESS reference implementation. <https://github.com/yani-/wpress>
- wpressarc — wpress↔tar converter. <https://github.com/kugland/wpressarc>
- WordPress Playground export format discussion. <https://github.com/WordPress/wordpress-playground/issues/1563>
- BorgBackup internals. <https://borgbackup.readthedocs.io/en/stable/internals.html>
- BorgBackup security model. <https://borgbackup.readthedocs.io/en/stable/internals/security.html>
- Restic design references. <https://restic.readthedocs.io/en/stable/100_references.html>
- ZFS Send Stream format. <https://openzfs.org/wiki/Documentation/ZfsSend>

### User reports informing design

- WordPress.org support: "Import process gets stuck" (All-in-One WP Migration). <https://wordpress.org/support/topic/import-process-gets-stuck/>
- WordPress.org support forum (Duplicator). <https://wordpress.org/support/plugin/duplicator/>
- WebHostingAdvices: "All-in-One WP Migration Import Stuck — solutions". <https://webhostingadvices.com/all-in-one-wp-migration-import-stuck/>

## 17. Appendices

### Appendix A: Test vectors

*(To be added when the v1.0 specification is finalised. Reference implementations must produce byte-identical output from these inputs.)*

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
