# 0006 — cross-URL migration via a post-restore guarded search-replace

- **Status:** Accepted, 2026-06-23. Amended 2026-08-11 (the CLI flag renamed from `--url` to `--new-url`) and 2026-08-12 (the replacer's recursive walk gained a depth and node bound — see Amendments).
- **Deciders:** 7Duckie (v0.3.0 slice 3 planning; amendments: 2026-08-11 the `--new-url` rename, 2026-08-12 the walk bounds).

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

## Amendment — 2026-08-11: the CLI flag renamed `--url` → `--new-url`

**New information:** WP-CLI reserves `--url` as one of its own global
parameters (confirmed against `wp cli param-dump`: the reserved list is
`path url ssh http blog user skip-plugins skip-themes skip-packages require
exec context disabled_commands color debug prompt quiet apache_modules
allow-root`) and consumes it before dispatching to any command. That means
`wp pontifex import --url=<new-url>`, exactly as slice 3c above wired it up
and as it shipped in v0.3.0, never reached this command at all:
`$associative_args` never carried the key, the code that reads it found
nothing, and the restore silently took the same-URL branch — exit 0,
"Restoring to the same site URL; no URL rewriting," and every URL left on
the old domain. This was confirmed end to end on a real site; the only way
to make the documented invocation actually migrate was the undocumented
`WP_CLI_STRICT_ARGS_MODE=1` environment variable.

**Amended decision:** the flag is renamed from `--url=<new-url>` to
`--new-url=<new-url>`, which is not in WP-CLI's reserved list and so reaches
the command normally. This is a breaking change to the CLI surface. The
command additionally now detects a bare `--url` on its own command line
(read from `$_SERVER['argv']` directly, since WP-CLI's own config merges
`wp-cli.yml` and would over-refuse an operator whose config merely sets an
unrelated `url:` line) and refuses via `WP_CLI::error()` rather than
silently taking the same-URL branch, so an operator following stale
documentation or an old script is corrected loudly instead of migrating
nothing. Everything else this ADR decided — the guarded, serialised-safe
search-replace pass, the threat-model defences, the pre-import safety
archive as the undo — stands unchanged; only the flag's name and the
up-front refusal of the old one are new.

## Amendment — 2026-08-12: bounded, abandon-on-breach recursion in the replacer's walk

**New information:** `SerialisedReplacer`'s two recursive walks —
`replace_value()`, which rewrites, and `contains_blocked_object()`, which
scans for the gadget-object defence — had no bound of any kind. A
fourteen-byte serialised value, `a:1:{i:0;R:1;}` (an array holding a
reference to its own enclosing array), made the walk run indefinitely.
Measured on a real `wp pontifex import --new-url=<new-url>`: because
WP-CLI runs with no memory limit, the process ground for 434 seconds
before the operating system killed it, and the operator's entire output
was the word `Killed`. `siteurl` and `home` had already been migrated to
the new domain by the time the hang was reached, so every post was left
pointing at the old one — a half-migration that read as a successful one,
because the process never survived to report anything at all.
`contains_blocked_object()` runs FIRST on every decoded value, so it is
what actually hung; a fix that bounded only `replace_value()` would have
left the hang exactly where it was.

Two bounding schemes were measured and rejected before this one. A depth
cap that truncates the branch that hit the limit and continues exploring
its siblings still hangs: a cycle that branches (several keys all
referencing the same enclosing array) explores up to 3^depth distinct
paths under that scheme, and a 300-second probe of it had to be killed by
hand. A node budget with no depth cap segfaults instead: PHP's own call
stack overflows — a segfault, not a catchable error — at a few tens of
thousands of frames, well before a 100,000-node budget would ever trip on
its own.

**Amended decision:** both walks are now bounded by depth (64 levels) and
by a total-nodes-visited budget (100,000) for one top-level value, and
breaching either **abandons the whole value** rather than truncating the
one branch that hit the limit and continuing with its siblings — that
distinction, not the choice of limit, is what actually stops the
exponential blow-up. The bounds are threaded as extra parameters through
`replace_value()`'s own recursion — including through any nested
serialised-string layer, so the budget cannot be reset by nesting more of
them — while `contains_blocked_object()` carries its own, independent copy
of both budgets for its own walk, so neither method's cost can starve the
other's. A breach throws, which unwinds every pending recursive call
without exploring any further siblings; only `replace_in_serialized()`
ever catches it, and it keeps the original value unchanged when it does —
the same "mismatch → keep original" rule this ADR already applies to a
value that will not round-trip. The abandoned value is counted in the
same `skipped_values` tally as every other value kept unchanged for
safety: the whole complaint about this defect was a *silent*
half-migration, so a silent skip here would have repeated it in miniature.
Everything else this ADR decided — the guarded search-replace pass, the
`allowed_classes => false` default, round-trip verification, the
pre-import safety archive as the undo — stands unchanged; only the walk
gained a bound it did not have before.
