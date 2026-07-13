# 0012 — signing: a supplied trusted key makes the signature mandatory

- **Status:** Proposed, 2026-07-12.
- **Deciders:** 7Duckie (v0.5.0 hardening).

## Context

A `.wpmig` is protected by two different things that are easy to conflate.
The **unkeyed SHA-256 hashes** (per entry, manifest, footer) detect
*corruption*: a flipped bit, a truncated download. They cannot detect
*tampering*, because anyone who can edit the file can simply recompute every
hash so it still matches — that is what unkeyed hashes are. The **optional
Ed25519 signature** is the only tamper defence: an attacker without the secret
key cannot re-sign a modified archive.

The engine audit found the cryptography sound but the *policy* broken: with a
trusted public key supplied via `--public-key`, an **unsigned** archive
restores with only a warning. So an attacker who tampers with a stored backup
does not need to defeat the signature at all — they strip the signature block,
recompute the unkeyed hashes, and the archive presents as a well-formed
unsigned one that the key-bearing operator's restore merely warns about (and a
`--yes` run scrolls the warning past). The design documentation compounded
this by claiming the integrity hashes detect tampering.

Mature ecosystems settled this long ago: package managers (`gpgcheck=1`),
container content trust, and update frameworks all treat "I have a trusted
key" as "unsigned content is refused". Verification that can be silently
downgraded by removing the signature protects nothing.

## Decision

- **A supplied trusted key makes the signature mandatory.** When
  `--public-key` is given to `wp pontifex import` or `wp pontifex verify`, an
  archive that is unsigned — including one whose signature was stripped —
  is **refused**, not warned about. Signed-but-wrong-key already refuses;
  signed-and-verifying proceeds. The operator's act of supplying a key *is*
  the declaration "I only trust signed archives"; there is no separate
  `--require-signature` flag, because a requirement flag without a key would
  have nothing to verify against, and a second opt-in would be forgettable in
  exactly the way the first one was.
- **A pinned key makes it durable.** A site may define
  `PONTIFEX_PUBLIC_KEY` (the absolute path of the public-key file from
  `wp pontifex keygen`) in `wp-config.php`. When defined and no `--public-key`
  flag is given, import and verify load the pinned key and enforce exactly as
  above — so the trust decision is made once, in configuration, instead of
  resting on a human remembering a flag on every run. An explicit flag
  overrides the pin for that run (explicit beats ambient).
- **Behaviour without any key is unchanged.** No key supplied and no pin
  defined: an unsigned archive restores as before, and a signed one still
  warns that its signature was not verified. Signing remains opt-in for those
  who have not adopted it.
- **The documentation stops overclaiming.** The format specification and
  user-facing docs are reworded: hashes detect corruption; the signature is
  the tamper defence; without a key (supplied or pinned), tampering is not
  detected.
- **The embedded `key_id` stays informational.** It sits outside the signed
  range and no consumer trusts it; matching it against the pinned key would
  add error-message niceness but zero security (the Ed25519 verification
  against the trusted key is the check), so it is deliberately not consulted.

## Consequences

- **Breaking, deliberately:** operators who today pass `--public-key` while
  restoring *unsigned* archives will start being refused. That combination is
  precisely the downgrade the audit flagged; the refusal message names the fix
  (verify without the key, or sign exports).
- The admin screens are untouched: they restore plain archives only and have
  never consulted signatures — recorded here, and revisited if signing ever
  comes to the browser.
- A stripped signature now fails closed for every key-bearing or key-pinned
  operator. For operators with no key anywhere, nothing changes — the honest
  statement (now also the documented one) is that unsigned archives offer
  corruption detection only.
- The audit's related envelope finding — AEAD AAD binds the entry header but
  not its manifest position, so same-shape encrypted entries could in theory
  be swapped by an attacker with write access — is deferred: it needs a format
  minor to change the AAD, the replay consequences are narrow (out-of-order
  chunks fail loudly or reorder INSERTs equivalently), and it is recorded here
  so it is not forgotten.

## Alternatives considered

- **A separate `--require-signature` flag** — rejected: without a key it has
  nothing to verify against, and as a second opt-in it is forgettable in the
  same way the first one was. Key-presence-implies-requirement is one
  mechanism that covers the threat.
- **Keyed hashes (HMAC) instead of signatures for plain archives** — rejected:
  an HMAC key would have to live beside the archive or in the site, where the
  attacker who can edit the archive can usually read it; the asymmetric
  signature already solves this with a secret that never leaves the operator.
- **Warning louder instead of refusing** — rejected: the audit's point is that
  warnings scroll past `--yes` runs; a trust decision must be enforced by the
  machine, not re-made by a human per run.
