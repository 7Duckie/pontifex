# ADR 0002 — Composer audit strictness: report abandoned, fail on advisories

- **Status:** Accepted
- **Date:** 2026-05-26
- **Deciders:** 7Duckie

## Context

`composer audit` runs in two places in Pontifex's quality gate:

1. As the last step of the `composer check` aggregate script defined
   in `composer.json`.
2. As a pre-push hook defined in `.pre-commit-config.yaml`.

Both invocations look at the same lockfile and produce the same
findings, but they are configured independently — the
`composer.json` script wraps the command in one place, the
pre-commit hook wraps it in another. Strictness has to be aligned
explicitly across the two; they do not share configuration.

During Sprint 1 (test infrastructure work, commit b26fb47),
`composer update` ran and refreshed the lockfile. The next
`composer check` failed with exit code 2 — not because of a CVE,
but because `composer audit` had detected two abandoned packages:

- `sebastian/code-unit`
- `sebastian/code-unit-reverse-lookup`

Both are tiny internal utilities of PHPUnit 10, maintained by
Sebastian Bergmann (PHPUnit's creator). They are marked
"abandoned" upstream because their functionality is being absorbed
into the main `phpunit/phpunit` package in PHPUnit 11+. "Abandoned"
in this context means **the standalone API is frozen and will not
receive feature work; the underlying code continues to ship and
receive security fixes inside the broader PHPUnit umbrella**. The
packages remain functional and there is no replacement to migrate
to short of upgrading PHPUnit itself.

Resolving the warnings genuinely requires migrating to PHPUnit 11
or 12. That migration is non-trivial:

- PHPUnit 11 requires PHP 8.2+.
- PHPUnit 12 requires PHP 8.3+ in some minor releases.
- Pontifex's current support floor is PHP 8.1.
- Raising the floor is a product decision affecting which
  WordPress hosts can run the plugin.

The migration is tracked as idea-bank Idea 008 with a recommended
defer to v0.3.0 or v1.0 release polish, when the PHP-floor question
gets a deliberate review anyway.

In the meantime, Composer's default behaviour changed at some point
in the 2.7+ series: abandoned packages now cause `composer audit`
to exit non-zero, where previously they were warnings. The change
is reasonable for projects that can react quickly; for projects in
the middle of a release cycle with a deferred-but-tracked migration
plan, it converts a known-and-documented condition into a noisy
gate failure on every push.

Three options were considered:

**A. Migrate to PHPUnit 11/12 immediately.** Real fix. Forces the
PHP-floor decision inside Sprint 1, derails the sprint, and makes
the floor change for tooling reasons rather than the deliberate
product-driven review that Idea 008 envisages. Rejected as wrong
timing.

**B. Set `--abandoned=ignore` on both audit invocations.** Cheapest
restore-to-green. Loses the visibility of the abandoned warning
entirely — if a *different* package gets abandoned later, no
in-workflow signal until someone runs `composer outdated` or
external monitoring catches it. Rejected as too quiet.

**C. Set `--abandoned=report` on both audit invocations.** Composer
still reports the abandoned packages in its output (the warning
table prints exactly as before), but exit code is 0 so the gate
does not fail. Preserves the visibility of B's downside while not
blocking the workflow.

## Decision

Take **Option C**. Both `composer.json`'s `check` script and
`.pre-commit-config.yaml`'s `composer-audit` hook pass
`--abandoned=report` to `composer audit`. CVE advisories continue
to use Composer's default behaviour, which is to fail the gate.
The decision is summarised by this invariant:

> Security advisories fail the gate. Abandoned packages are
> reported but do not fail the gate, until the underlying
> migration that resolves them is landed.

The two configuration points that enforce this:

1. `composer.json` — the `check` script ends with
   `"@composer audit --abandoned=report"`.
2. `.pre-commit-config.yaml` — the `composer-audit` hook's `entry:`
   is `composer audit --abandoned=report`.

Both must change together. A drift between them re-introduces the
exact gate-failure-on-one-side situation that motivated this ADR.

## Consequences

**Positive.**

- `composer check` and `git push` are both green on a normal-day
  abandoned-package state.
- The abandoned-package warning still prints in every run, so the
  signal is not hidden — a contributor seeing two abandoned
  packages in the output and asking "what about these?" gets the
  same prompt that motivated this ADR.
- The CVE-detection capability of `composer audit` is untouched.
  Any package with a published security advisory continues to
  fail the gate at both the local and pre-push levels.
- Idea 008's PHPUnit migration is the real fix; this ADR holds
  the line until that migration is timed correctly.

**Negative.**

- Drift between the two configuration points is now a class of
  bug. If a future change updates one without the other, the
  gate becomes asymmetric again — passing locally and failing
  on push, or vice versa. Mitigation: this ADR documents the
  invariant; the `composer.json` and `.pre-commit-config.yaml`
  changes ship together.
- The `--abandoned=report` flag is a slightly non-standard
  configuration. A new contributor reading the project's
  `composer.json` and not finding this ADR could mistake the
  flag for sloppiness rather than a deliberate trade-off.
  Mitigation: the suggested-replacement column of the abandoned
  warning is "none" for both packages, which is a strong
  contextual signal that the situation is upstream-driven, not
  Pontifex-driven.

**Neutral.**

- The two abandoned packages may eventually drop off the warning
  list of their own accord (if Composer's behaviour changes
  again, or if upstream un-abandons them — unlikely but
  possible). The flag remains harmless either way.

## When to revisit

This ADR should be revisited when any of the following happens:

- **Idea 008 lands.** PHPUnit 11 or 12 in use → the two listed
  packages disappear from the lock → the flag may be removed and
  audit returned to default strictness.
- **New abandoned packages appear** that are not internal-to-PHPUnit
  in the same way. The current decision is tightly scoped to "we
  know exactly why these two are abandoned and have a plan."
  Anything new requires fresh analysis.
- **v1.0 release polish.** The strictness posture of the quality
  gate is reviewed as part of stabilising the v1.0 public API; the
  audit flag is one of the items on that checklist.
- **A security advisory is filed against either listed package.**
  This would change the calculus entirely: advisories still fail
  the gate, and an advisory against an abandoned package with no
  upstream maintainer to ship a fix is exactly the situation a
  forced migration responds to.

## References

- Idea 008 — PHPUnit 11/12 migration (`docs/idea-bank.md`).
- Commit b26fb47 — first introduced the `--abandoned=report` flag
  in `composer.json`.
- Commit 8b25e63 — aligned the pre-push hook with the same flag.
- Composer's audit documentation:
  <https://getcomposer.org/doc/03-cli.md#audit>.
