# 0004 — v0.1.0 import restores to the same URL (URL rewriting deferred to v0.2.0)

- **Status:** Accepted, 2026-06-11.
- **Deciders:** 7Duckie (adopting the 2026-06-11 audit
  recommendation, finding PATH-1).

## Context

The two planning documents disagreed about v0.1.0's import scope:
the continuity plan treated URL rewriting as in-scope-but-
experimental with an escape hatch, while the build-risk audit placed
it in v0.2.0 behind the search-replace defences (risk C1). The public
roadmap, meanwhile, listed URL rewriting as a v0.1.0 deliverable —
a third, contradictory position. Left unresolved, the question would
have been answered improvisationally mid-sprint, under exactly the
pressure ("round trip is green, a quick find-and-replace and we can
claim full migration") that produces the classic WordPress migration
bug: PHP-serialised strings record their own lengths
(`s:22:"https://old-site.local"`), so naive replacement corrupts them.
Search-replace over serialised data is also the highest-blast-radius
surface in the threat model (hostile serialised payloads → code
execution via unserialize gadgets).

## Decision

v0.1.0's `wp pontifex import` performs **no URL rewriting**. The
milestone is a same-URL restore baseline: export a site, import the
archive on a WordPress at the same URL, get the same site back,
provably. The command's output states this scope plainly. URL
rewriting ships in v0.2.0 **as one package with its defences**:
allowlist-disabled unserialize (`allowed_classes => false`),
round-trip verification of rewritten values, and the pre-import
scan, per the threat model — each proven by tests before the feature
is callable. The roadmap is updated to match this decision in the same
commit, so no document is left contradicting it.

## Consequences

- v0.1.0 is honestly a backup/restore baseline; the README says so
  ("what this is — and isn't yet").
- Cross-domain migration is a documented v0.2.0 commitment, not a
  silent gap.
- Any future proposal to slip rewriting into a v0.1.x must supersede
  this ADR explicitly rather than relitigate it in chat.
