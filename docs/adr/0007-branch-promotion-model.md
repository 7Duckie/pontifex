# 0007 — Branch promotion model: feature -> dev -> staging -> main

- **Status:** Accepted, 2026-06-24.
- **Deciders:** 7Duckie (v0.5.0 workflow planning).

## Context

Through v0.4.x the project used a single-tier GitHub Flow: a short-lived
`type/scope` branch off `main`, a pull request, all gates green, merge back to
`main`, tag the release. That was the right weight for the round-trip baseline,
but as the work ahead grows (admin UI, operational features, and eventually a
tester/beta cohort), 7Duckie asked for a tiered promotion model — one branch
per feature or per group of closely-related patches, merging into a `dev`
branch, which merges into a `staging` branch, which merges into `main`.

The model has to fit two facts about Pontifex specifically:

- **There are no environments.** Pontifex is not a service with dev/staging/prod
  servers; it ships as a tagged release a user installs. So `dev`/`staging`/`main`
  cannot mean "the dev server / the staging server / production." They have to
  mean something else, or they are ceremony with no payoff.
- **Correctness is the bar.** This is code that runs inside other people's live
  sites on data we never see, so "the round trip has actually been proven"
  before anything reaches a user is non-negotiable, and `main` must stay
  releasable at all times.

Two further forces shaped the decision. 7Duckie wants every promotion *gated*,
not a matter of convention ("why cut corners?"), and intends — later — to cut
**canary / beta pre-releases** to a small group of testers before a general
release.

## Decision

Adopt a three-tier promotion model in which the long-lived branches denote
**stability/maturity tiers**, not environments:

| Branch | Meaning | What lands |
|---|---|---|
| `feature/*`, `fix/*`, … | one feature, or one group of closely-related patches | branched off `dev`, pull-requested back into `dev` |
| `dev` | integration branch — "the next version in progress" | features merge here continuously |
| `staging` | release candidate | the release is assembled and proven here (version bump, changelog, `.pot` regeneration, built-form Plugin Check) |
| `main` | released only | `staging -> main` is the release pull request; **tags live on `main`** ([ADR 0003](./0003-strict-version-stamping.md) tag-guard unchanged) |

**Every promotion is a gated pull request.** feature -> dev, dev -> staging and
staging -> main are each a pull request that cannot merge until its required CI
checks are green. The tiers are enforced gates, not labels. Merges use a merge
commit, never squash (the existing project rule). `main` remains the only place
tags are cut, and below v1.0.0 every tag is a GitHub pre-release.

**CI runs in tiers, to keep the gates meaningful without making feature work
slow:**

- **feature -> dev** runs the fast gates only: PHPCS, PHPStan, the full unit
  suite, `composer audit` / OSV-Scanner / Gitleaks, and `composer validate
  --strict`.
- **dev -> staging** and **staging -> main** additionally run the full
  round-trip integration suite across PHP 8.2–8.5 and **Plugin Check against the
  built package** (the exact tree `.distignore` produces, with production-only
  dependencies — the only form that catches a broken `.distignore`, the lesson
  of v0.4.6). The full set runs at *both* release-bound gates because `staging`
  gains new commits between them (the release-prep commits), which the second
  gate re-proves before users get anything.

This makes the **required-check sets deliberately asymmetric**, which is also a
correctness requirement of GitHub branch protection: a branch must not require a
status check that never runs on pull requests targeting it, or those pull
requests hang forever waiting. So `dev` requires only the fast checks (which
always run); `staging` and `main` require the full set (which the tiered `if`
conditions guarantee will run on pull requests targeting them).

**Hotfixes** to already-released code follow the model rather than cutting
around it. There are two cases, both fully gated:

- If `dev` is currently shippable, an urgent fix is just an ordinary change:
  `fix/* -> dev -> staging -> main`.
- If `dev` holds unfinished work (the usual mid-cycle case), the fix branches
  off **`main`** (the clean released state, so the fix can ship in isolation),
  is pull-requested into **`staging`** so it gets the same full gate (and, in
  future, the same canary exposure), then `staging -> main` and a patch tag.
  The fix is then **merged back down** (`main -> staging -> dev`) so it is not
  lost on the next promotion. The back-merge is the one obligation this model
  adds and must not be forgotten.

**Canary / beta pre-releases** are recorded as the natural future use of
`staging`: a `vX.Y.Z-beta.N` pre-release tag cut from `staging`, given to a
tester cohort, proven, then promoted `staging -> main` for the general tag. This
is *not* built now (see [idea-bank Idea 014](../idea-bank.md)); the model is
shaped so it can be added without restructuring.

Alternatives considered and rejected: **staying on single-tier GitHub Flow**
(simplest, but gives no gated assembly point for a release and no natural canary
tier — does not meet the stated goal); **running the full CI matrix on every
pull request including feature -> dev** (more uniform, but spends the heavy
wp-env integration run on every small change for no real safety gain, since
features merge one at a time); **an off-`main` hotfix that goes straight to
`main`** (faster, but skips the `staging` proving/canary step the model exists
to provide).

Two robustness additions ride with this change because they are cheap and fit:
**Plugin Check in CI** (above) and **`composer validate --strict`**. Three are
deliberately deferred: **Roave Backward-Compatibility Check** and
**phpstan-strict-rules** (both add a Composer dependency — [ADR 0002](./0002-composer-audit-strictness.md)
parallel-config — and are nice-to-have, not essential), and **Infection
(mutation testing)**, which is valuable but belongs in its own slice and is run
locally and advisorily, never as a gate (see idea-bank).

## Consequences

- **`main` becomes a released-only history** and `staging` a real, gated place
  to assemble and prove a release before it touches `main`. Both reinforce
  "`main` stays releasable" and "the round trip is proven before release."
- **The model is canary-ready:** `staging` is the obvious point to cut beta
  pre-releases when that capability is built, with no further restructuring.
- **Forward-porting is the new standing chore.** Anything that lands high (a
  hotfix on `main`/`staging`) must be merged back down to `dev`, or the branches
  drift. This is the classic cost of long-lived release branches and is the
  thing most likely to be forgotten.
- **A release now crosses two extra pull requests** (dev -> staging -> main)
  instead of one. Acceptable for the gating it buys; it also makes each release
  a deliberate, reviewable step.
- **CI feedback on features stays fast** (no wp-env boot on feature pull
  requests), while every release-bound promotion is fully proven, including the
  built form.
- **The default branch stays `main`** so the repository's shop window is
  released code; feature pull requests retarget to `dev`. The `main` protection
  ruleset keys off the default branch, so changing the default later would
  require switching it to an explicit ref.
- This ADR governs branching and CI tiers; the day-to-day operating rules live
  in the project's working contract, which is updated to match.
