# 0006 — cross-URL migration via a post-restore guarded search-replace

- **Status:** Accepted, 2026-06-23.
- **Deciders:** 7Duckie (v0.3.0 slice 3 planning).

## Context

v0.1.0 restores at the same URL only; cross-domain migration (URL
rewriting) was deferred — first to v0.2.0, then re-cut into v0.3.0
([ADR 0004](./0004-same-url-import-scope.md)) — to ship together with the
defences that make it safe. The danger is well known: naive search-replace
over PHP-serialised data corrupts it, because serialised strings record
their own byte lengths (`s:22:"https://old-site.local"`); and `unserialize`
on attacker-controlled bytes is an object-injection (gadget-chain)
remote-code-execution surface (threat-model §1).

Pontifex stores the database as raw SQL chunks that the importer replays
statement by statement (`DatabaseWriter`). Rewriting URLs in that SQL text
would be the naive, corrupting approach. The values that must be rewritten —
serialised arrays in `wp_options`, `wp_postmeta` and the like — are only
safely rewritable as structured values, not as text.

## Decision

Cross-URL migration is **not** a rewrite of the archive's SQL. It is a
**guarded, serialised-safe search-replace pass run over the live database
after a same-URL restore** — the proven `wp search-replace` model:

1. Restore the archive at the same URL (the v0.1.0 path, unchanged).
2. Walk the destination database's rows and rewrite each value with a
   **`SerialisedReplacer`** that Pontifex owns and fully controls.

The replacer carries the threat-model §1 defences, each proven by tests
before the feature is callable (ADR 0004):

- `unserialize(..., ['allowed_classes' => false])` by default — gadget
  chains cannot instantiate. Pontifex does **not** use WordPress's
  `maybe_unserialize()`, which does not guarantee this guard across
  WordPress versions.
- Round-trip verification — re-serialise and confirm the result
  round-trips; on any mismatch, the original value is kept unchanged.
- A `pontifex_serialized_classes` filter so legitimate custom classes can
  opt back into the allowlist explicitly.
- A pre-import/pre-migrate scan that previews transforms for operator
  review.

The pre-import safety archive (ADR 0005, shipped in v0.2.0) is the undo for
the whole operation: if a migration goes wrong, `wp pontifex rollback`
restores the pre-migration state.

The feature is built in slices: **3a** the `SerialisedReplacer` and its
adversarial tests (nothing callable); **3b** the database rewrite pass and
the pre-migrate scan; **3c** the `wp pontifex import --url=<new>`
integration.

## Consequences

- The migration runs on structured `$wpdb` values, so serialised lengths
  are always recomputed correctly and the gadget surface is guarded at the
  one `unserialize` call Pontifex controls.
- Values inside serialised **objects** are left unchanged by default (they
  cannot be rewritten safely under `allowed_classes => false`), matching the
  "mismatch → keep original" safety rule. The filter widens the allowlist
  when an operator needs it.
- Any CVE touching `unserialize`, the serialisation format, or the replacer
  is P0 (threat-model §1).
- Superseding this approach requires a new ADR, not a mid-sprint change.
