# Pontifex threat model

## What this document is

This is a *plugin-level* threat model. It ranks the attack surfaces
Pontifex exposes while it is running — the places where a successful
exploit would do the most damage — so that triage decisions on CVEs,
code reviews of security-adjacent changes, and design decisions in
future phases can refer to a single shared picture rather than each
contributor reasoning from scratch.

It is distinct from, and complementary to, the *format-level* threat
model in [the archive format design doc](./ARCHIVE-FORMAT-DESIGN.md#5-threat-model).
That document covers what cryptographic properties the `.wpmig`
format provides and what attacks it explicitly does not defend
against. This document is about the plugin as a running system:
which moving parts touch attacker-controlled data, and what would
happen if one of them failed.

### How to read the rankings

"Blast radius" is the realistic worst-case damage if an exploit at
that surface succeeded. A surface where a single exploit grants
remote code execution on the destination site has a far larger blast
radius than one where the worst outcome is denial of service. The
list goes from largest blast radius (1) to smallest (6); urgency of
patching, defensive review, and CVE response should scale with the
ranking.

### CVE priority

Each surface notes the priority assigned to CVEs that touch it. The
scale used here:

- **P0** — drop everything, ship a fix within days. Reserved for
  surfaces where an exploit gives an attacker code execution, key
  recovery, or unrestricted data access.
- **P1** — fix promptly, within a release cycle. Significant
  exposure but not catastrophic.
- **P2/P3** — patch when convenient. Either the surface has small
  blast radius or strong mitigations make exploitation impractical.

---

## 1. Search-replace running on attacker-controlled data

The serialised-data walker is fed everything in the source database.
If an attacker controls a row in `wp_options` or `wp_postmeta` and a
deserialisation gadget — a chain of class instantiations and method
calls triggered by PHP's `unserialize` on attacker-controlled bytes,
used to reach an arbitrary code execution sink — is reachable from
the data we walk, we have a remote code execution path on the
destination site at the moment of import.

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

- Magic/version/footer validated before any other parsing (see
  [ARCHIVE-FORMAT.md §12](./ARCHIVE-FORMAT.md#12-integrity-and-tamper-detection)
  for the exact verification flow)
- Manifest size capped at 100MB
- Per-entry length checked against remaining file size
- FILE paths validated relative-and-within-wp-content (no traversal)
- DB_TABLE_SCHEMA validated as single CREATE TABLE (no stacked
  statements — multiple SQL statements separated by semicolons in
  one query string, the classic injection vector)
- DB_TABLE_DATA as parameterised INSERTs
- Regex transforms with `pcre.jit=0` and a hard step limit to
  defeat regex denial-of-service (catastrophic backtracking on
  adversarial input)

**CVE priority:** CVEs in the archive parser or any decompression
library (zstd, zlib) are P0.

## 3. Snapshot files at rest

Encrypted with a per-site key from `wp-config.php`. Anyone who can
read that file can decrypt every snapshot. This is the existing
WordPress threat model, not a regression — but it means snapshots are
not safe to copy off-server without re-encryption.

**Mitigations in design:**

- AES-256-GCM per entry: corruption is *bounded* to a single entry
  rather than cascading through the whole archive. A flipped bit
  produces one decryption failure, not a chain of garbage output
- Argon2id KDF with strong parameters (see
  [ARCHIVE-FORMAT.md §8.1](./ARCHIVE-FORMAT.md#81-key-derivation)
  for the exact cost parameters)
- Disk cap and retention window prevent unbounded accumulation

**CVE priority:** CVEs in `ext-sodium` or libsodium itself are P0.

## 4. Signed download URLs

HMAC-signed with a 1-hour expiry. HMAC (Hash-based Message
Authentication Code) is a cryptographic fingerprint computed using
a secret key — it lets the receiver verify both authenticity (only
someone with the key could have produced this signature) and
integrity (any modification to the signed bytes invalidates the
fingerprint). A CVE in the HMAC implementation would be
catastrophic: predictable signatures mean any archive can be
downloaded by anyone with the URL prefix.

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

## What this model does not cover

The plugin threat model assumes a baseline trust environment and
treats certain attacks as outside its scope. These are not
oversights — they are intentional limits on what the plugin can
defend against, and stating them explicitly keeps the model honest.

- **Administrators acting in bad faith with valid credentials.** The
  threat model is "admin made a mistake or was phished," not "the
  admin is the attacker." A WordPress administrator with valid
  credentials can already do anything Pontifex can do, without
  Pontifex needing to be exploited. Defending against this case is
  not a plugin-level concern.
- **WordPress core or PHP runtime compromise.** If the underlying
  WordPress installation or PHP itself has been compromised, no
  amount of plugin-side defence helps. Propagating upstream CVE
  fixes is our part of the contract; the rest is outside our reach.
- **Hosting-provider compromise.** A host with filesystem access can
  read `wp-config.php` (and therefore the per-site encryption key),
  modify the plugin's code, or replace archive files. Operators
  concerned about this case should rely on signed archives
  ([ARCHIVE-FORMAT.md §11](./ARCHIVE-FORMAT.md#11-optional-detached-signature))
  and verify signatures against a key not stored on the host.
- **Side-channel attacks against the PHP implementation.** Timing,
  cache, and similar side channels that leak information through
  non-functional behaviour of the runtime. PHP is not designed for
  constant-time cryptographic operations and we do not attempt to
  make it so.
- **Loss of the per-site encryption key from `wp-config.php`.** If
  the file is destroyed without backup, every snapshot encrypted
  with that key is unrecoverable by design. There is no key escrow.
- **Denial of service against the running plugin.** An administrator
  can disable a misbehaving plugin from wp-admin; a non-admin should
  not have reach into the plugin's endpoints at all. DoS-grade bugs
  are bugs to fix, but not security failures of the model.

---

This document evolves as the codebase does. Security-relevant pull
requests should reference the surface(s) they touch and any new
mitigations or risks they introduce.
