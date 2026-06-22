<!--
Thanks for contributing to Pontifex. Keep a PR to one logical change on a short
type/scope branch off main (feat/, fix/, test/, ci/, docs/, refactor/, chore/).
See .github/CONTRIBUTING.md for the full workflow.
-->

## What this changes

<!-- A short summary of the change and the why. The commit body carries the detail. -->

## Type of change

<!-- Tick one; it should match the branch prefix and the Conventional Commit type. -->

- [ ] `feat` — a new capability
- [ ] `fix` — a bug fix
- [ ] `test` — tests only
- [ ] `ci` — CI or tooling
- [ ] `docs` — documentation only
- [ ] `refactor` — internal change, behaviour identical
- [ ] `chore` — housekeeping

## Checklist

- [ ] One logical change, branched off `main`.
- [ ] Quality gates pass locally: `composer lint`, `composer analyse`, `composer test`.
- [ ] If behaviour changed, the slice's own evidence was run (the export smoke test, or the integration round trip for a restore change).
- [ ] If this touches a restore, database, or archive-reader path ranked 1–4 in [`docs/threat-model.md`](../docs/threat-model.md), the description explains the security relationship.
- [ ] Public claims (README, plugin header, `readme.txt`) still match what the code actually does.
- [ ] `CHANGELOG.md` updated, if this is a user-facing change.

<!--
CI runs the three gates plus the integration suite across PHP 8.2–8.5; every
check must be green before merge. main is protected, and merges use a merge
commit (never squash).
-->
