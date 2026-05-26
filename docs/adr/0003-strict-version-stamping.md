# ADR 0003 — Strict version stamping: every tag matches the in-file version

- **Status:** Accepted
- **Date:** 2026-05-26
- **Deciders:** 7Duckie

## Context

The Pontifex plugin version lives in two locations inside `pontifex.php`:

1. The WordPress plugin header `Version:` line, which WordPress reads
   to populate the wp-admin Plugins screen.
2. The `PONTIFEX_VERSION` constant, which `ExportCommand` reads to
   stamp every archive's Provenance block.

The constant is the **runtime source of truth** — its value ends up
inside every archive Pontifex emits, identifying which release of
the plugin produced that archive. The header is the **distribution
source of truth** — its value is what WordPress and the plugin
directory show users.

Both must agree, by their nature. A mismatch means either the
Plugins screen lies about what version is installed, or every
archive made by that install lies about its origin. For a plugin
whose pitch is "documented archive format" and trust-through-
transparency, neither is acceptable.

The file's docblock already states the policy:

> Bumping the version means updating both this define and the
> header Version line at the top of the file. They must agree.

This was a policy statement without enforcement. Tags `v0.0.3` and
`v0.0.4` both shipped from a commit where the header and constant
both said `0.0.2`. Archives produced by users running the `v0.0.4`
release self-identify in their Provenance block as exporter
`0.0.2` — silently wrong, and exactly the kind of footgun the
format spec was designed to prevent. The 2026-05-26 audit pass
captured this as finding F002 with two clear remediation choices:

- **Strict** — every git tag matching `v*` requires
  `PONTIFEX_VERSION` and the header `Version:` line to equal the
  tag's version (minus the leading `v`), with a CI guard that
  fails the tag-push workflow if they diverge.
- **Loose** — docs-only or internal tags can skip the bump.

Loose was rejected. The cost of strict is one line in one file per
tag — vanishingly small ceremony. The cost of loose is that the
runtime source of truth for archive provenance can silently drift
away from reality, which is exactly the failure mode the strict
policy prevents. For a plugin whose version stamp ends up inside
user-distributed files, strict is the only choice consistent with
the project's trust posture.

## Decision

Adopt the strict policy:

> Every `v*` git tag must match the value of `PONTIFEX_VERSION`
> (minus the leading `v`), and must also match the plugin header
> `Version:` line. A CI step on tag push verifies both values
> agree with the tag and fails the workflow if they do not.

The bump-and-tag flow becomes:

1. Edit `pontifex.php` so the header `Version:` line and the
   `PONTIFEX_VERSION` constant both reflect the version being
   released.
2. Commit with a subject like `chore: bump version to N.N.N`.
3. Tag the commit: `git tag vN.N.N && git push --tags`.

The CI workflow's *Verify tag matches plugin version* step parses
both values from `pontifex.php` and compares them against the tag
ref. Disagreement on either value fails the workflow with a clear
error annotation naming which value disagreed.

## Consequences

**Positive.**

- Every archive Pontifex emits self-identifies accurately.
  Provenance can be trusted to reflect the actual release that
  produced the archive.
- The Plugins screen in wp-admin shows the right version on every
  release.
- The policy was already in the `pontifex.php` docblock; this ADR
  adds enforcement to a rule that was always there but never
  watched.
- Forgetting to bump fails CI at tag-push time, before the release
  artifact reaches users. No silent footgun.

**Negative.**

- One extra step on the bump-and-tag flow. Forgetting it means
  re-tagging (delete the bad tag, edit and re-commit, re-tag).
  The friction is small but real.
- Between tags, `PONTIFEX_VERSION` reflects "the most recent
  release," not "the current commit." Archives exported from an
  untagged `HEAD` will report the latest tagged version, not the
  development state. This is acceptable — provenance is
  fundamentally a release-time concept, and pre-release archives
  are inherently unsupported.
- The CI step parses `pontifex.php` with `grep` and `sed`. If the
  file's structure ever changes — the docblock format, the line
  ordering, the spacing inside the `define()` call — the parser
  breaks. Mitigation: the step prints a clear error annotation on
  parse failure ("Could not extract Version line from
  pontifex.php"), so a broken parser surfaces as a failed step,
  not a passing one.

**Neutral.**

- The existing `.github/workflows/ci.yml` did not have a tag-push
  trigger; this ADR's accompanying commit adds `tags: ['v*']` to
  the workflow's `push:` trigger. Side effect: the full quality-
  gate suite (PHPCS, PHPStan, PHPUnit, audit, OSV scanner,
  gitleaks) now runs on every tag push too. This is desirable —
  the tagged commit gets verified against the same gates that
  ran for its source commits on `main`.

## When to revisit

This ADR should be revisited when any of the following happens:

- **The bump-and-tag flow is automated.** A release tool that
  derives the version from a single source (release-please,
  semantic-release, semver-it) would make the manual bump
  redundant. The CI check would then become a belt-and-braces
  safety net rather than the primary gate, or could be retired
  entirely.
- **The plugin header format changes.** If WordPress core or WPCS
  ever changes its expectations for plugin header lines, the
  grep pattern needs updating in lockstep, and this ADR should
  note the change.
- **`PONTIFEX_VERSION` moves out of `pontifex.php`.** Unlikely —
  the constant has to be defined at plugin-load time, and
  `pontifex.php` is the load-time entry point — but if the
  layout ever changes, the parser needs adjustment.
- **v1.0 release polish.** The release-process discipline is part
  of stabilising the public API; this ADR's enforcement is one
  item on that checklist.

## References

- `pontifex.php` — line 6 (the plugin header `* Version: ...`
  line) and the `define( 'PONTIFEX_VERSION', '...' )` constant
  near the end of the constants block.
- `.github/workflows/ci.yml` — the *Verify tag matches plugin
  version* step in the `quality-gates` job.
- ADR 0002 — composer audit strictness, the structurally similar
  "multiple config points must agree" pattern.
- 2026-05-26 audit pass, finding F002.
