# Pontifex threat model

This document ranks Pontifex's attack surface by blast radius — the
realistic damage an exploit at each surface could cause. It exists so
that triage decisions on CVEs, code reviews of security-adjacent
changes, and design decisions in future phases can refer to a single
shared picture rather than each contributor reasoning from scratch.

Ranking is from highest blast radius (1) to lowest (6).

## 1. Search-replace running on attacker-controlled data

The serialised-data walker is fed everything in the source database.
If an attacker controls a row in `wp_options` or `wp_postmeta` and we
have a deserialisation gadget, we have a remote code execution path
on the destination site at the moment of import.

**Mitigations in design:**

- Class allowlist (`allowed_classes => false`) to defeat gadget chains
- Round-trip verification: re-serialise and compare; mismatch → keep
  original, log error
- Pre-import scan that surfaces transformed values for operator review
- Filter-extensibility (`pontifex_serialized_classes`) so legitimate
  custom classes can opt in explicitly

**CVE priority:** any CVE touching PHP's `unserialize`, the
serialisation format itself, or library code we use to walk
serialised data is P0.

## 2. Archive contents on import

A malicious `.wpmig` file uploaded by an admin (the threat model is
"admin made a mistake or was phished," not "anyone on the internet")
could contain crafted FILE entries, oversized manifests, malicious
SQL, or path-traversal filenames. Every byte we read from an archive
is untrusted.

**Mitigations in design:**

- Magic/version/footer validated before any other parsing
- Manifest size capped at 100MB
- Per-entry length checked against remaining file size
- FILE paths validated relative-and-within-wp-content (no traversal)
- DB_TABLE_SCHEMA validated as single CREATE TABLE (no stacked
  statements)
- DB_TABLE_DATA as parameterised INSERTs
- Regex transforms with `pcre.jit=0` and hard step limit

**CVE priority:** CVEs in the archive parser or any decompression
library (zstd, zlib) are P0.

## 3. Snapshot files at rest

Encrypted with a per-site key from `wp-config.php`. Anyone who can
read that file can decrypt every snapshot. This is the existing
WordPress threat model, not a regression — but it means snapshots are
not safe to copy off-server without re-encryption.

**Mitigations in design:**

- AES-256-GCM per entry (corruption-bounded)
- Argon2id KDF with strong parameters
- Disk cap and retention window prevent unbounded accumulation

**CVE priority:** CVEs in `ext-sodium` or libsodium itself are P0.

## 4. Signed download URLs

HMAC-signed with 1-hour expiry. A CVE in the HMAC implementation
would be catastrophic: predictable signatures mean any archive can
be downloaded by anyone with the URL prefix.

**CVE priority:** CVEs in `ext-openssl`, `ext-hash`, or `hash_hmac`
itself are P0.

## 5. Admin UI form handling

Every wp-admin form requires nonce + capability checks. A CVE in
WordPress core's nonce system affects us; a CVE in admin-ajax
handling does too.

**CVE priority:** WordPress core CVEs are propagated by WordPress
itself; we just need to be prompt about supporting the version that
includes the fix.

## 6. Development dependencies

PHPUnit, PHPStan, PHPCS, and their transitive deps. A CVE here is
annoying but does not ship to users.

**CVE priority:** P2/P3. Patch when convenient, don't lose sleep.

---

This document evolves as the codebase does. Security-relevant pull
requests should reference the surface(s) they touch and any new
mitigations or risks they introduce.
