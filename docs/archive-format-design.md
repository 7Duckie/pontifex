# Pontifex Archive Format — Design Document

*Companion to* [`archive-format.md`](./archive-format.md). *Where the spec defines* what *implementations must do, this document explains* why.

**Status:** Living document. Expected to evolve as we learn from real-world use of the format. Updates here do not change the format itself — they only deepen the rationale.

**Audience:** Future maintainers of Pontifex, third-party implementers of the format, security reviewers, and anyone trying to understand a decision before changing it. Not required reading for users of the plugin or the eventual CLI.

---

## Table of contents

1. [What this document is, and what it isn't](#1-what-this-document-is-and-what-it-isnt)
2. [The decade-in-a-box framing](#2-the-decade-in-a-box-framing)
3. [Why a new format at all](#3-why-a-new-format-at-all)
4. [Major design tensions and their resolutions](#4-major-design-tensions-and-their-resolutions)
5. [Threat model](#5-threat-model)
6. [Implementation guidance for writers](#6-implementation-guidance-for-writers)
7. [Implementation guidance for readers](#7-implementation-guidance-for-readers)
8. [The audit log and the persistent notice — pedagogy through irritation](#8-the-audit-log-and-the-persistent-notice--pedagogy-through-irritation)
9. [Known limitations and open questions](#9-known-limitations-and-open-questions)
10. [A note on the open-spec posture](#10-a-note-on-the-open-spec-posture)
11. [Use cases beyond WordPress](#11-use-cases-beyond-wordpress)

---

## 1. What this document is, and what it isn't

The specification document defines an interoperability contract. Two implementations of that contract should produce byte-identical archives from identical inputs, and should accept or reject any given archive identically. The spec is short, terse, and reference-oriented. If you are reading it to decide what bytes to write or how to parse a particular field, the spec is the right document.

This design document is different. It explains the *reasoning* behind the spec's choices — what alternatives were considered, what trade-offs were accepted, what concerns motivated specific decisions, and what we would change if certain assumptions turned out to be wrong. It is what a senior engineer would tell a new contributor over coffee to bring them up to speed on the project's history of decisions.

A change to the spec is a change to the format. A change to this document is a change to our understanding of the format. The two are deliberately separate so that the spec can stay stable while our explanation of it deepens.

## 2. The decade-in-a-box framing

The hardest problem a file format has to solve is not what it does today; it is what it must continue to do ten years from now, when its authors have moved on, when the code that originally produced it no longer compiles, and when the tools used to read it are completely different software. Every file format that has lasted — ZIP, TAR, PNG, FLAC, JPEG, the Unix `ar` archive — became durable not because it was clever but because it was *boring* in exactly the right places. Stable bytes, conservative cryptography, explicit versioning, refusing to change the meaning of existing fields.

The formats that have not lasted — proprietary `.doc` files, vendor-specific image formats, the long graveyard of "we'll just iterate on this" specifications — failed for the inverse reasons. They quietly mutated. They relied on tooling that disappeared. They had no versioning scheme, or had one that nobody respected. The archive a user produced in 2015 stopped opening in 2023 because someone changed the compression algorithm without bumping the format version.

`.wpmig` is designed against this failure mode. The framing we kept returning to during design was: *what does it take for an archive produced by Pontifex 0.1.0 to still extract cleanly in 2036?* Not in a museum-piece sense — actually extract, in a working WordPress environment, producing the original site.

The constraint forces several decisions that look pedantic in isolation:

- Magic bytes that are stable forever, including a non-ASCII trailing byte that makes the file obviously binary to any tool that might try to "fix" it as text.
- A version number that goes at the front, before anything else, so a future reader knows immediately whether it can or cannot proceed.
- A footer at a fixed distance from the end of the file, so locating the manifest does not depend on parsing anything else first.
- Cryptographic primitives that are widely implemented, well-reviewed, and unlikely to be deprecated within the decade.
- A registry of codec IDs whose existing entries are locked, so adding new compression formats can never break old archives.
- Conformance requirements that force readers to reject what they do not understand rather than silently ignore it — silent acceptance is how subtle compatibility breaks accumulate.

These choices feel over-cautious for a v0.1.0 product. They are deliberately over-cautious. The cost of being too careful here is mild; the cost of being too lax is years of accumulated user pain.

## 3. Why a new format at all

The question "why not just adopt an existing format" was the first real design decision, and it deserves a longer answer than the spec gives it.

We seriously considered four candidates: the `.wpress` format used by All-in-One WP Migration, WordPress Playground's proposed ZIP-based export, the composition `.tar.zst.age` (combining well-loved Unix tools), and the BorgBackup/Restic repository formats. Each had real merits.

`.wpress` is the closest existing match conceptually — a single-file WordPress archive with a reference Go implementation under an MIT licence. Adopting it would have been the fastest path to a working product. The reasons it doesn't work as the basis for `.wpmig` are accumulated, not individually decisive:

- No format version field. A `.wpress` file does not announce what version of the format it was produced by. The plugin has changed its compression algorithm multiple times over the years without any change to the file's identifier, and the result has been that backups produced by older versions of the plugin cannot be reliably extracted by newer versions. This has been reported by users repeatedly. We want the opposite property — a Pontifex 5.0 must read a 0.1.0 archive without question, because the archive announces what it is and Pontifex 5.0 implements the original reader.
- No encryption. `.wpress` files are stored in plain compressed form. For a format that operators will move between hosts, share with collaborators, and store in cloud buckets, this is an unacceptable default. Adding encryption on top of `.wpress` (e.g., by encrypting the whole file with a separate tool) loses the per-entry properties we want and produces an opaque blob that cannot be inspected without full decryption.
- No checksums or signatures. The format has no built-in way to detect corruption or tampering. A truncated `.wpress` file is detectable only by trying to extract it; a modified file is not detectable at all.
- No manifest. To know what is in a `.wpress` archive, you must read the whole archive. Random access to a single file requires scanning from the start. This is workable for migration but precludes a class of recovery operations (extract one corrupted entry to retry it; inspect the file list without unpacking).
- Lossy on metadata. `.wpress` does not preserve directory entries, file permissions, or ownership. For WordPress migration this is mostly acceptable, but for the recovery and verification use cases we want to support, it leaves us without information we need.

WordPress Playground's emerging ZIP-based format is the most architecturally aligned candidate. It uses standard ZIP containers, has WordPress-aware directory conventions, is designed for streaming, and is being shaped by Automattic-funded developers who care about portability. The reasons we did not adopt it:

- It is still under active design. The discussion thread on the [Playground GitHub repository](https://github.com/WordPress/wordpress-playground/issues/1563) was open and being iterated at the time we made our format decision. Adopting a moving target is risky.
- It provides no encryption beyond what ZIP itself offers, which is the legacy ZipCrypto (broken) or AES-256 ZIP extensions (uncommonly implemented in PHP, inconsistently in other languages). We want encryption as a first-class concern, not a layered-on extension that some readers will silently skip.
- It carries no migration-specific metadata. The provenance fields we consider essential (source URL, source charset and collation, source plugin set, source WordPress version) are not part of the Playground format. We could fit them in by convention — for example, as a JSON file at a known path — but at that point we are layering our own format on top of theirs, which is the worst of both worlds.
- ZIP's central-directory-at-end design has well-understood failure modes around large files, ZIP64 extensions, and tools that don't implement them correctly. We'd inherit those problems.

We will, however, keep our directory conventions aligned with the Playground format where they don't conflict with our other goals — anything inside a `.wpmig` archive that is meant to look like a WordPress installation should follow the same conventions Playground uses, so that a future tool that can read both formats can do so without bizarre special cases.

The `.tar.zst.age` composition is technically excellent. Tar is universally streamable, zstd is the best modern general-purpose compressor, and age is a clean modern encryption tool. The reason we didn't adopt it is the absence of a place for our metadata. We could include a `manifest.json` as the first entry in the tar, but then we have to define what that JSON looks like — which is exactly what we are doing, and at that point we have a format whose name happens to involve three other formats. Calling it `.tar.zst.age` would mislead users into thinking they could use stock `tar`, `zstd`, and `age` binaries to inspect their archives, when in fact they would also need to understand our metadata layout.

BorgBackup and Restic were the most carefully considered candidates because their cryptography and integrity work is exemplary. Both refuse to create unencrypted repositories without explicit opt-in. Both have rigorous integrity checking. Both have years of production use under their belts. They were not adopted because the *shape* is wrong: they are repository formats, designed for "many backups accumulating in a vault over time, deduplicating against each other." A WordPress migration is a different problem — one site, one moment, one file you can email someone or upload via FTP. Forcing repository semantics onto our use case would make routine operations (move this archive to a different host) into multi-step processes (export, then sync the repository, then import on the other side).

So we are building our own — but doing so while taking the best ideas from each. The spec's design rationale section traces each major decision to its prior-art source.

## 4. Major design tensions and their resolutions

Several design questions had no obvious answer. They forced explicit trade-offs. The following are the most consequential.

### 4.1 Streamable writes vs random-access reads

A writer should not need to hold the whole archive in memory before flushing it to disk — for a site with 50 GB of uploads, this would simply fail on any realistic host. A reader should be able to extract any single file without reading the entire archive first — for a 50 GB archive where only one file is needed, requiring a full scan is unacceptable.

These goals are in tension. Streaming writes implies emitting entries in order, with no information about what comes next; random-access reads imply some kind of index that says where each entry lives.

The resolution is the same one PKZIP arrived at decades ago: an index *at the end* of the file (the manifest, in our terms), with a footer pointing to it. The writer streams entries to disk without buffering; only at the end, once all entries are known, does it emit a single manifest block summarising what was written. A reader that wants random access seeks to the footer first, reads the manifest, and from it knows every entry's offset.

This costs one thing: a reader that wants to know what is in an archive cannot begin extracting until it has at least retrieved the footer. For local files this is free; for HTTP-streamed archives, the reader either reads the whole file first (defeating the purpose) or uses HTTP range requests to fetch the footer separately (which most clients support).

We accepted this trade-off because the alternative — a manifest at the start — would force the writer to either buffer the entire archive in memory or do two passes over the source data, both of which are unworkable in PHP environments with strict memory limits.

### 4.2 Per-entry encryption vs whole-archive encryption

We could encrypt the entire archive as one big blob (using something like age or a streaming AES mode), or encrypt each entry independently. The first is simpler to implement and harder to get wrong cryptographically; the second is more flexible and supports partial recovery.

The choice came down to recovery semantics. If a single byte is corrupted in a whole-archive-encrypted file, decryption fails for everything after that byte — depending on the cipher mode, the entire archive may become unrecoverable. With per-entry encryption, a corrupted byte affects only one entry; everything else remains decryptable and recoverable. For a backup format whose whole job is to survive things going wrong, per-entry encryption is the right design even though it is more complex.

The complexity is real: we need nonce uniqueness across entries (handled by the counter portion of our nonce construction), and we need each entry's metadata to be bound to its ciphertext (handled by using the entry header as additional authenticated data). The cost is justified by the recovery property.

**Could whole-archive encryption be added as a future option?** Yes, and the format is deliberately structured so that this addition would not break v1.0 readers. The mechanism is the reserved flag bits in §4 of the spec: a future minor or major version could allocate a reserved bit for "whole-archive encryption mode," with v1.0 readers rejecting such archives cleanly under the §13.2.4 rule that reserved bits must cause refusal. We are not pre-committing to this addition — there is no current evidence operators want to choose between modes — but the door is open if real-world use surfaces a clear reason. The throughput difference between modes (roughly 5–15% on typical WordPress sites) is on its own not a sufficient reason; the realistic motivating case would be a hosting environment where per-entry Argon2id is impractically expensive.

### 4.3 JSON metadata vs binary metadata

Provenance, entry headers, and the manifest could all be encoded as compact binary structures. This would save space and make parsing faster. We chose JSON instead.

The reasoning is durability through tooling. A binary format ties readers to a specific parser. If our parser has a bug, every reader has the bug. JSON has thirty years of implementations in every language, and any decent text editor can show a corrupted JSON block to a human who is trying to recover an archive manually. The space cost is real but small relative to the actual content of the archive: provenance is typically under 2 KB, the manifest scales with file count and is typically a few hundred KB even for large sites.

A future v2 might revisit this if archives grow into the hundreds of millions of entries, where the manifest cost becomes meaningful. For v1, JSON is the right choice.

### 4.4 SHA-256 vs faster hash functions

BLAKE2b and BLAKE3 are both faster than SHA-256, often by significant margins on large inputs. For an archive integrity hash, faster is better — verification is the slowest step of importing a large archive.

We chose SHA-256 anyway. PHP's `hash()` function includes SHA-256 in its built-in algorithm list, requiring no extension. BLAKE2b is also built in. BLAKE3 is not, and requires a PHP extension that is not widely installed and is not present on most managed hosts. Choosing BLAKE3 would lock us out of the same hosting environments we are trying to support.

The decision is "baseline availability over performance." SHA-256 is hardware-accelerated on every modern CPU through the SHA-NI extension on x86 and ARMv8 cryptography extensions; in practice the speed difference for our use case is much smaller than benchmarks on raw CPU suggest.

### 4.5 Mandatory encryption vs operator choice

Every existing WordPress migration plugin treats encryption as optional, defaulting to off. We invert this: encryption is on by default, and operators must explicitly opt out by setting a non-empty `encryption_disabled_reason` field in provenance.

This is opinionated. Some operators will find it irritating. The justification is that WordPress migration archives routinely contain database dumps with hashed passwords, session tokens, plugin configuration data including third-party API keys, and uploaded files including documents users have considered private. Treating this material as plaintext by default is a security posture we are not willing to ship.

The opt-out path is real — a single configuration flag — but it requires the operator to articulate a reason that gets logged into the archive itself. Future-them will see why past-them disabled encryption. This is "encryption by default with friction-for-disabling," not "encryption only."

### 4.6 PHP-only vs language-agnostic format

We could have designed the format around PHP's strengths and weaknesses, optimising for what PHP does well. We chose instead to design a format any language could implement with reasonable effort.

This shows up most clearly in the choice of big-endian byte order (a deliberate convention rather than mirroring PHP's native machine order), the use of plain JSON for metadata (rather than PHP's serialisation format), and the cryptographic primitives chosen (SHA-256, AES-256-GCM, Argon2id, Ed25519 — all widely implemented).

The reasoning: the WordPress plugin is PHP because it has to be, but the format is its own thing. The eventual Go reference implementation is the test that we got this right. If anyone else wants to build a `.wpmig` reader in Python or Rust or Java, the spec should support them without their having to reverse-engineer PHP-specific quirks.

### 4.7 Passphrase-based authentication vs other key-management schemes

The encryption design uses passphrase-based authentication: an operator-supplied passphrase is run through Argon2id to derive a 256-bit key, which feeds AES-256-GCM for the actual encryption. We considered three alternatives.

**Full public-key cryptography (PGP-style or age-style).** Each operator generates a keypair; archives are encrypted to the recipient's public key. Advantages: encrypt to multiple recipients without sharing a secret, separate keys for different archives, key rotation without re-encryption. Disadvantages: every operator now has a key-management problem — generating keypairs, distributing public keys, protecting private keys, recovering from key loss. For the WordPress migration use case, where the operator is typically the same person on both source and destination, this is added friction with no proportional benefit.

**Keyfile-based encryption (BorgBackup's `keyfile` mode).** A high-entropy key is stored in a separate file that the operator manages. Advantages: cryptographically stronger than any human-typed passphrase, easy to share between automated systems. Disadvantages: the file becomes another thing to lose or expose, the user experience for one-off manual migrations is awkward, the threat model now includes filesystem-level attacks on the keyfile.

**Hybrid passphrase-or-keyfile.** Support both, let the operator choose. Disadvantages: doubles the maintenance surface, doubles the documentation, and exposes a choice that most operators are not equipped to make.

We chose passphrase-only because it matches the dominant use case (one operator, one archive, manual operation), aligns with how Restic and similar tools behave (Restic is passphrase-only, period), and avoids the complexity of key management for users who do not need it. The trade-off is that passphrase strength becomes the operator's responsibility — a weak passphrase produces weak encryption no matter how strong the KDF and cipher.

The mitigation is making weak passphrases harder to choose accidentally: spec §8.4 requires writers to enforce a minimum length, encourage 16+ characters, and surface entropy warnings when the passphrase is below approximately 50 bits of entropy. Writers do not impose character-class rules (no "must contain one uppercase and one symbol") because those reduce entropy more than they help by funnelling users toward predictable patterns.

**Why these primitives specifically.** Every choice here is the current mainstream recommendation rather than an exotic preference:

- **AES-256-GCM** is the authenticated encryption mode used by TLS 1.3, IPsec ESP, and SSH transport. NIST SP 800-38D is the formal recommendation. Hardware acceleration is available on every modern CPU. No practical attacks are known.
- **Argon2id** won the 2015 Password Hashing Competition and is the RFC 9106 recommendation for general-purpose passphrase-based key derivation. It is memory-hard, which defeats the GPU/ASIC time-memory trade-offs that weaken older KDFs like PBKDF2.
- **Ed25519** is the modern default signature algorithm: small keys, small signatures, single-step verification, used by OpenSSH, Signal, signify, and minisign.

These are boring choices. That is the point. We do not want this format's cryptography to be where we innovate.

**What if the passphrase is lost.** The data is unrecoverable. This is the explicit position taken by BorgBackup, Restic, age, signify, and every passphrase-based encryption tool that takes its threat model seriously. A built-in recovery mechanism (key escrow, recovery codes) becomes attack surface that defeats the encryption's purpose. The plugin UI must surface this consequence explicitly before encryption is enabled, requiring acknowledged understanding rather than a checkbox that can be ticked without reading.

## 5. Threat model

Every security-relevant design decision needs a threat model to justify it. Ours is below.

### What we protect against

**Passive observation of archives in transit or at rest.** An attacker who obtains a `.wpmig` archive (intercepted in transit, exfiltrated from cloud storage, recovered from a stolen laptop) should not be able to read its contents without the passphrase. AES-256-GCM with Argon2id-derived keys handles this.

**Tampering with archive contents.** An attacker who can modify bytes of an archive (a malicious host, a compromised storage provider, an MITM that can intercept and modify but not the passphrase) should not be able to alter the archive without the destination reader noticing. The combination of per-entry SHA-256 hashes, the manifest hash in the footer, and AES-GCM authentication tags handles this — *for encrypted archives*. For unencrypted archives, only the per-entry and manifest hashes apply, and an attacker who can rewrite the entire archive (including the hashes) is undetected. This is why we require the explicit opt-out reason for unencrypted archives.

**Truncation and corruption.** An archive truncated mid-write (process killed, network interrupted, disk full) should be detected as malformed by any reader. The footer's fixed distance from EOF means truncation corrupts the footer pointer; the manifest hash check fails if the manifest is partial; per-entry hashes fail if individual entries are partial.

**Replay and re-binding of entries.** An attacker should not be able to take an encrypted payload from one entry and present it as a different entry. The use of the entry header as additional authenticated data (AAD) for AES-GCM binds metadata to ciphertext: the GCM tag fails verification if the header is changed.

**Importing the wrong archive by accident.** An operator should not silently restore an archive from the wrong source. The provenance block, shown to the operator before any destructive operation, gives them the chance to confirm "yes, this is the site I expected."

### What we explicitly do not protect against

**Loss of the passphrase.** This is unrecoverable by design. Some tools attempt to provide passphrase recovery via key escrow or backup recovery codes; these mechanisms become attack surface in their own right and are inconsistent with the threat model above. Operators are responsible for passphrase management; the spec is clear on this.

**Compromised export-time environment.** If the source WordPress installation has been compromised at export time, the resulting archive may include the compromise. The format cannot detect this, and is not designed to. Operators concerned about this should sign archives with their own Ed25519 keys *before* the compromise occurs, and verify the signature at import.

**Side-channel leakage from PHP implementation.** The PHP implementation does its best, but PHP is not a language designed for constant-time cryptographic operations. Timing-side-channel attacks against the PHP implementation are not part of our threat model. The Go reference implementation will be more resistant on this front.

**Active attackers who possess the passphrase.** An attacker who has both the archive and the passphrase can do anything an operator can. The format does not protect against this case; no format can.

**Denial of service.** An attacker constructing a malicious archive (extreme nesting in JSON, zip-bomb-style compression ratios, gigantic manifest claiming billions of entries) may be able to exhaust resources during import. Readers must implement defensive limits (maximum entry count, maximum entry size, maximum decompressed-to-compressed ratio); these are implementation concerns rather than format concerns. We provide guidance in §7 but do not specify limits in the format itself, because reasonable limits depend on the destination environment.

**Privacy of provenance.** When flag bit 2 is clear, the provenance block is stored in plaintext even when the rest of the archive is encrypted. This is intentional — it lets a reader inspect the archive's origin without first deriving the encryption key. Operators who consider their site's source URL or plugin list confidential should set flag bit 2 to encrypt the provenance as well, at the cost of needing the passphrase to inspect what archive they are holding.

## 6. Implementation guidance for writers

What follows is non-normative guidance — not part of the format contract — but reflects what we expect conforming writers to do.

### 6.1 Chunking strategy

The archive is streamable, but in practice writers should buffer in chunks rather than emitting bytes one at a time. A reasonable default is to flush after each entry, with internal buffering up to perhaps 1 MB per entry. For database chunks, we recommend 500 KB per chunk based on the WordPress Playground guidance, which gives a sensible balance between per-chunk overhead and streaming behaviour.

### 6.2 Memory budget

The expected operating environment includes shared PHP hosts with `memory_limit = 256M` or even `128M`. The writer should not require more than 64 MB of resident memory at any point, regardless of archive size. This rules out approaches that buffer the entire manifest or hold large numbers of entries in memory simultaneously. The manifest can be built incrementally as entries are emitted, with only the running list of entry descriptors in memory.

### 6.3 Resumability

A writer that is killed mid-archive should leave the partial file in a state where the next invocation can resume from the last completed entry. The natural strategy: maintain a sidecar progress file (e.g., `archive.wpmig.progress`) recording the byte offset of the last completed entry. On resume, truncate the archive to that offset and continue writing from there. Once the manifest and footer are emitted, the progress file is deleted.

The progress file is not part of the format; it is a writer implementation detail. Readers never see it.

### 6.4 Excluding files

WordPress installations contain many files that should not be in a migration archive: caches, log files, temporary uploads, sometimes very large debug logs. The writer should support an exclusion list; the spec's provenance `scope.excluded_paths` field records what was excluded for the destination reader to see. Common defaults: `wp-content/cache/`, `wp-content/debug.log`, `wp-content/uploads/wp-personal-data-exports/`.

### 6.5 Database export strategy

Databases should be exported via `mysqldump`-compatible SQL streams, split into chunks of around 500 KB each, with chunks emitted as separate entries of `kind: db_chunk`. The chunks must be reassembled in `chunk_index` order on import. This strategy was learned from the WordPress Playground discussion and is consistent with what makes large database imports tractable on PHP-time-limited hosts.

### 6.6 Compressing already-compressed media

JPEG, PNG, MP4, and similar media are already compressed. Re-running them through gzip or zstd wastes CPU for negligible size gain (often producing slightly *larger* output). Writers should detect these by media type or by sampling-based heuristic, and emit them with codec `0x0000` (or `0x0100` for encryption-only) rather than `0x0001/0x0002/0x0101/0x0102`.

## 7. Implementation guidance for readers

### 7.1 Defensive limits

A reader processing an untrusted archive must enforce limits:

- **Maximum entry count.** A manifest claiming a billion entries is malicious or broken. Reject anything above a sensible threshold — we suggest 10 million as an upper bound for v1 readers.
- **Maximum compressed entry size.** A 10 GB compressed entry is suspicious in a WordPress context. We suggest 4 GB as the upper bound for v1 readers.
- **Maximum decompression ratio.** Zip-bomb-style compression can decompress to far more than the source data. We suggest a 1000:1 ratio cap, with the reader aborting decompression once this is exceeded.
- **Maximum manifest size.** The manifest is JSON; even a large archive should produce a manifest of at most a few MB. Reject manifests over 100 MB.

These are recommendations, not requirements. Implementers may set tighter or looser limits based on what their environment can safely handle.

### 7.2 Verification order is mandatory

A reader must perform verification in the order specified in §12.1 of the spec. The order is not arbitrary — it is the only ordering in which each step has the information it needs to be meaningful. Reading entries before verifying the manifest, for example, means trusting the manifest's claim about where each entry lives without first having verified the manifest hash. An attacker who has tampered with the manifest can redirect reads to arbitrary file offsets.

### 7.3 Error messages

When verification fails, the reader must produce error messages a human can act on. "Import failed" is not acceptable. The minimum useful information:

- Which verification step failed (header, provenance, footer, manifest, entry, signature).
- For per-entry failures, which entry (by path and by index in the manifest).
- For hash failures, both expected and observed values.
- For version failures, the version found in the archive.

This level of detail is what users complaining about existing migration plugins consistently say they wish they had. An archive failing at 87% with no further information is the experience we are trying to make impossible.

### 7.4 Random-access vs streaming reads

Readers should support both modes. Streaming is the natural mode for full restores: process entries in archive order, verifying as you go. Random-access is the natural mode for inspection and recovery: read the manifest, present a list, let the user pick what they want.

For random-access reads of encrypted archives, the reader still needs the passphrase to decrypt each entry. The salt is in the footer; the key derivation happens once at the start of any session that touches multiple entries.

## 8. The audit log and the persistent notice — pedagogy through irritation

The spec requires that integrity failures be logged to the `wp_options` table, that override invocations be logged with their reasoning, and that overrides leave a persistent admin notice in the destination WordPress instance until explicitly dismissed. This is deliberate friction, and the friction is the point.

In the existing WordPress migration plugin landscape, the most common complaint among advanced users is that they have no information when something goes wrong. The most common complaint among less-experienced users is more troubling: they have a vague feeling that the restore was strange, but the plugin says it succeeded, and they have no way to investigate. We are trying to make both of these complaints impossible.

The audit log is the answer for advanced users — when something fails, they can read exactly what failed and decide whether the override was reasonable. The persistent notice is the answer for less-experienced users — they cannot ignore the fact that the override happened, because WordPress itself will keep reminding them until they acknowledge it. If a less-experienced user dismisses the notice without understanding it, the audit log preserves the event for whoever later helps them debug the resulting confusion.

This is a posture choice. We could have made the override silent. We could have left no log. Both would produce a smoother user experience in the happy path. We chose pedagogy through irritation — making operators slightly uncomfortable in the unhappy path, in exchange for never letting them be confused about what state their site is in.

## 9. Known limitations and open questions

These are issues we are aware of and have either deliberately deferred or do not yet have a complete answer for. They are grouped by the design choice that produced them, so that anyone reconsidering a choice can see what they would be inheriting.

### 9.1 Limitations arising from the compression design

**Codec selection leaks file-type information.** A writer that emits codec `0x0000` (no compression) for an entry signals to anyone with read access to the archive that the entry is probably already-compressed media — typically a JPEG, PNG, MP4, or similar. An attacker examining encrypted archive bytes cannot read the content but can build a profile of "this archive contains roughly N images and N text files." For most use cases this is acceptable; for use cases where file-type metadata is itself sensitive, operators should consider encrypting at the filesystem level in addition to using the format's encryption.

**Reader binary footprint includes every supported codec.** A conforming v1 reader must support gzip *and* zstd to handle any v1 archive, even if a given archive uses only one. This adds the zstd library to readers that might otherwise not have needed it (gzip is universally available; zstd is not). For PHP readers this is a runtime extension check; for Go readers it adds compiled-in dependencies.

**The "is this file already compressed" detection is heuristic.** Writers decide between codec `0x0000` (skip compression) and codec `0x0001/0x0002` (compress) based on media type or sampling, both of which can be wrong. A mislabelled `.jpg` that is actually plain text gets stored uncompressed and wastes space; a `.bin` file that compresses well gets stored compressed even if it contains JPEG data. The cost of misclassification is small (a few percent of archive size in the worst case) but real.

### 9.2 Limitations arising from per-entry encryption

**Per-entry crypto overhead is non-trivial at scale.** Each encrypted entry carries 28 bytes of overhead: a 12-byte nonce plus a 16-byte AES-GCM authentication tag. For an archive of 50,000 entries — easily reached on a WordPress site with many small theme and plugin files — this is 1.4 MB of pure crypto envelope. Acceptable for the recovery properties it provides, but not free.

**Parallel encryption is awkward.** The monotonic-counter portion of the nonce construction means a writer cannot easily encrypt multiple entries in parallel without coordinating counter assignment. Single-threaded throughput therefore sets the upper bound on encryption speed. For a single host this is fine — encryption is rarely the bottleneck — but it precludes naive parallelisation strategies.

**Re-encryption requires re-writing the whole archive.** If an operator wants to change the passphrase of an existing archive, every entry must be decrypted with the old key and re-encrypted with the new key. There is no key-wrapping shortcut (e.g., a master key encrypted with the passphrase-derived key, where changing the passphrase only re-wraps the master) because adding one would have meant a more complex format. The trade-off was simplicity now versus convenience later; we chose simplicity.

**Inspecting archives always requires the passphrase.** Listing the contents of an encrypted archive — even just the filenames — requires deriving the key from the passphrase to decrypt the manifest. There is no "open this archive in read-only metadata mode" path. Operators who frequently need to identify archives from outside should either name files descriptively or set flag bit 2 to *not* encrypt the provenance (accepting the metadata leak in exchange for inspectability).

### 9.3 Limitations arising from the manifest-at-end design

**HTTP streaming is awkward.** A reader fetching an archive over HTTP has to either download the whole file first or make a separate range request for the footer (the last 64 bytes, or last 64 plus signature length). Most HTTP clients support range requests, but the additional round-trip is a measurable cost for very large archives stored in distant object stores.

**Network interruption mid-write produces a file with no manifest.** A partial `.wpmig` file consisting of header + provenance + some entries but no manifest is structurally invalid — readers will report missing footer/manifest. Recovery from such files is possible by scanning entries from the start (each entry's length prefix lets the scanner advance correctly), but this is a recovery path, not the normal read path. The PHP plugin and the future Go CLI should both implement this fallback.

**JSON parsing of large manifests is slow.** A manifest for an archive with 100,000 entries is roughly 30 MB of JSON. PHP's `json_decode` handles this without issue but uses meaningful memory and time. A binary manifest format would parse faster but would lose the tooling benefit of "any JSON parser can show this to a human." We accept the trade-off.

### 9.4 Limitations arising from the encrypt-by-default stance

**The plaintext provenance block is a metadata leak.** When flag bit 2 is clear (the default for ergonomic reasons), an attacker who obtains an encrypted archive learns the source site URL, the WordPress version, the plugin set, and other provenance fields without needing to decrypt. For most operators this is acceptable — none of this is highly sensitive. For operators where this metadata *is* sensitive, flag bit 2 must be set deliberately, accepting the inspectability cost noted in §9.2.

**The "encrypted everything" mode makes archives opaque to their own owner.** With flag bit 2 set, the operator cannot tell from inspection which site an archive is for, when it was made, or what it contains, without first decrypting. For operators who hold many archives, this can make basic organisation surprisingly hard. The mitigation is naming files descriptively (`example-com-2026-05-21.wpmig`), but this puts the metadata back into the filename and partially defeats the encryption.

### 9.5 Limitations arising from the audit log design

**The audit log lives in the database that is being restored.** Writing to `wp_options` means the log entries are themselves part of the WordPress installation. A malicious archive could in principle include entries that overwrite or clear `pontifex_integrity_log` as part of its database content. The mitigation is strict: integrity events must be written *after* all archive content is applied, never before, so that any malicious overwrites are themselves overwritten by the legitimate log entries. This is an implementation requirement, not a format-level guarantee.

**The log grows unboundedly across imports.** Each import adds entries; nothing prunes them. A long-lived WordPress installation that has been restored from `.wpmig` archives dozens of times will accumulate a substantial log. The plugin should offer a "prune entries older than N days" option, but the format does not mandate one.

**Broken WordPress means lost audit trail.** If the destination installation is broken badly enough that `wp_options` is unreadable — corrupted database, missing tables, fatal error before WordPress fully loads — the audit log is inaccessible. This is the same vulnerability faced by any in-database logging system; the only complete defence would be writing logs to a separate file outside WordPress, which adds complexity disproportionate to the value.

### 9.6 Limitations arising from the cryptographic baseline

**SHA-256 hashing takes meaningful time at scale.** A 50 GB archive requires around 50 seconds of single-core CPU time just to compute per-entry hashes. Hashing parallelises naturally (each entry is independent), so multi-core implementations can amortise this, but single-threaded readers — the typical PHP case — pay the full cost. A faster hash (BLAKE3) would help but is not available in baseline PHP, as discussed in §4.4.

**Argon2id memory cost may exhaust budget on the cheapest hosts.** The 64 MiB memory cost during key derivation is conservative for 2026 hardware but uncomfortable on shared hosts with `memory_limit = 128M` where PHP itself plus WordPress plus the plugin already consume a significant fraction. We may need to lower the parameters in v1.1 once beta data shows real failures, with the caveat below.

**No upgrade path for archives produced with weaker parameters.** Argon2id parameters are part of the v1 spec; archives are written with those exact parameters baked into the security model. If v1.1 lowers the parameters to accommodate weaker hosts, archives produced under v1.1 are weaker forever — there is no mechanism to "upgrade" an archive's KDF strength without re-encrypting the whole thing.

### 9.7 Limitations arising from the open-spec posture

**The format cannot make breaking changes once shipped.** Even bug fixes that would change the byte-level behaviour have to be back-compatible. This constrains our ability to fix subtle design errors after v1.0 lock-in. The mitigation is the extensive design-review phase before that lock-in, including documents like this one.

**Other implementations may produce non-conformant archives.** A bug in a third-party reader or writer (e.g., wrong byte order on a length prefix, missing required provenance field) produces an archive that some readers accept and others reject. We have to choose between lenient acceptance (compatibility at the cost of correctness) and strict rejection (correctness at the cost of usability). The current draft leans toward strict rejection because lenient acceptance compounds: each lenient reader normalises slightly different bug variants, and downstream readers cannot tell which variant they are looking at.

**A cryptographic break forces v2.** If a serious weakness is discovered in SHA-256, AES-256-GCM, Argon2id, or Ed25519, we have to ship v2 with new primitives. Operators holding v1 archives then face a migration problem — they need to decrypt under v1, re-encrypt under v2, and verify the round-trip. The format itself cannot help with this; it is a known operational risk of committing to specific cryptographic primitives.

### 9.8 Scope-level limitations

**Multisite is captured but not structured.** The provenance block records `multisite: true|false`, but v1 does not define how a multisite network should be packaged — one archive containing all subsites, or a parent archive containing per-subsite archives. This decision is deferred until single-site migration is solid and we have feedback from operators with multisite needs.

**Cross-database-engine migration is informed but not enforced.** The format records the source database engine, version, charset, and collation, but does not specify how an importer should react to mismatches. A v1.1 may add a `compatibility_mode` field to the manifest indicating which destination engines an archive is intended for.

**Differential and incremental archives are out of scope for v1.** Current archives encode full snapshots. Differential support is a reasonable v2 direction but adds substantial complexity and is not needed for first-migration use cases.

**No per-entry format versioning.** If v1.x adds new fields to the entry header, those fields will be present in some entries and absent in others within the same archive. Readers handle this naturally (unknown fields are ignored), but a more disciplined approach might add a per-entry format version. We left this out of v1 because it adds complexity without clear current benefit.

## 10. A note on the open-spec posture

The decision to publish this specification openly — CC BY 4.0 for the spec itself, free implementation rights, no patents, no royalties — is the project's most consequential governance choice. It commits us to a position that other migration plugins do not take: the format belongs to the WordPress community, not to Pontifex.

We do this for a reason. The track record of "the only tool that can read this file is the tool that wrote it" is the track record of vendor lock-in. Every closed migration format has produced users stuck with the format because no alternative reader exists. The .wpress format is partially open by accident — the reference Go implementation exists because the company's founders published it years ago — but `.daf` (Duplicator's format), the proprietary formats of WPvivid Pro and the rest, are deliberately closed. Users with archives in these formats are bound to their original tooling.

By publishing the `.wpmig` specification, we accept that other implementations will exist. We expect, eventually, that third parties will write their own readers and writers. We do not consider this a threat. It is the test that we built something worth building. A format that nobody else implements is a format that has not earned its place.

The corollary is that we cannot change the format unilaterally. Once a v1.0 specification ships with multiple implementations, changes must respect the implementations that exist. This is what the invariants in spec §13.2 are protecting: the things that, no matter how good a future change might be, cannot break the existing ecosystem of readers and archives.

If this constraint feels uncomfortable, that is appropriate. Open specifications are a discipline. The discipline is the point.

## 11. Use cases beyond WordPress

The format is intentionally general. WordPress is the primary application because that is where the gap exists — but nothing in the spec hardcodes WordPress assumptions in a way that would prevent other uses. Documenting the broader applicability serves two purposes: it stress-tests the design (if the format only works for WordPress, we have accidentally embedded assumptions we should make explicit), and it invites adoption by other projects facing similar shape problems.

The format's shape is: *single-file, durable, encrypted-by-default, tamper-evident container for a tree of files plus structured metadata plus optional database content*. Any application matching that shape is a potential consumer.

Five concrete cases:

### 11.1 Migration of other content-managed platforms

Drupal, Joomla, Ghost, MediaWiki, Discourse, and similar CMSes share the WordPress migration problem: filesystem tree plus database plus configuration plus URL rewriting on restore. The format works for all of them with provenance-schema adaptation — the `source` object would carry the CMS name and version instead of (or alongside) `wp_version`, the `scope` object would describe the CMS-specific directory layout, and the `plugins_active`/`themes_active` fields would generalise to "extensions" or "modules" depending on the platform.

The PHP plugin remains WordPress-specific, but the Go CLI we plan to ship later could read and verify archives from any platform, given the same provenance schema. A future contributor could write a Drupal-side writer producing `.wpmig` archives, and they would interoperate with our Go reader without modification.

### 11.2 SaaS data portability for GDPR and CCPA compliance

When a SaaS application is required to provide a user's data on request, the typical output is a zip file with no integrity guarantees, no encryption, no provenance about what version of the application produced it, and no way to verify the export came from where it claims to have come from. `.wpmig` provides all of those properties.

The provenance schema generalises naturally: `source.url` becomes the SaaS instance URL, `wp_version` becomes the application version, `plugins_active` becomes the user's enabled features, and additional fields can capture user identifier, export scope (full account vs partial), and the legal basis for the export request. The audit log requirement (write events to a known location on the destination side) does not apply when the destination is not a database-backed application, but the per-entry integrity and optional signature still apply.

The compliance value is meaningful: an export signed with the SaaS provider's Ed25519 key proves to a regulator or to the user that the data came from where it claims to have come from, and that it has not been tampered with since export.

### 11.3 Research dataset distribution

Reproducible research depends on being able to verify that the dataset you are analysing is the same dataset that produced the original results. Current practice is a tarball plus a SHA-256 checksum, distributed separately. `.wpmig` packages both into a single artifact, with structured provenance describing the dataset's collection methodology, the software version that produced it, and the principal investigators responsible.

The format's per-entry hashes let downstream consumers verify individual files without re-hashing the whole archive. The optional Ed25519 signature lets institutions sign datasets they vouch for, producing a chain-of-custody that survives mirror-site redistribution. The encryption is useful for datasets under embargo or with sensitive content that should be released only to verified researchers.

### 11.4 Legal and medical document archives

Chain-of-custody requirements in legal and medical record-keeping map directly onto the format's design: tamper-evident container, per-document hashes, signed provenance, audit log of any access or override. A case file archived in `.wpmig` carries cryptographic evidence of its integrity from the moment of archival. The persistent admin notice on `--force` overrides is exactly the kind of friction these workflows want — a record that an override happened and a reason it happened.

Adaptation needed: the provenance schema would shift entirely toward the case identifier, custodian information, archive jurisdiction, and retention requirements. The audit log on import would need to be persisted outside the destination database, since legal/medical workflows typically integrate with external case-management systems rather than running on WordPress. The format itself accommodates this — readers can implement audit logging to any destination, with the spec requirement being "log somewhere durable" rather than "log to WordPress specifically."

### 11.5 Static site and general content backup

Hugo, Jekyll, Eleventy, and similar static site generators have a simpler shape than WordPress: filesystem tree plus configuration plus generated output, no database. `.wpmig` handles this trivially by setting `scope.includes_database: false` and emitting only file entries.

The same approach works for any general-purpose content backup where a user wants a single encrypted, integrity-verified file containing a directory tree they care about — personal document archives, photo collections, code repositories, configuration backups. The format is overkill for casual use (a plain encrypted tar would do), but the verifiability, the provenance metadata, and the audit log on restore become valuable when the backups matter — when they are insurance against ransomware, hardware failure, or accidental deletion.

### Adapting the provenance schema

The pattern across all these use cases is the same: the provenance JSON's `source` object describes "where this came from" in terms specific to the application, the `scope` object describes "what is and isn't included," and any additional application-specific fields go alongside. v1 readers preserve unknown fields verbatim (spec §13.2.4), so application-specific extensions do not break interoperability with general-purpose tooling.

The recommended convention for extension fields is to namespace them by application: `drupal:taxonomies`, `mediawiki:namespaces`, `legal:jurisdiction`, and so on. This keeps the v1 WordPress-specific fields from colliding with extensions added by other projects.

---

*End of design document.*
