# Security Policy

## Supported versions

Pontifex is in pre-alpha (v0.0.x). No version is yet production-ready,
and no version is yet receiving formal security patches. This will
change at v0.1.0 (Phase 1 complete), at which point security updates
will follow the standard semver-patch cadence.

That said, we triage and respond to every reported vulnerability
regardless of release status. Pre-v0.1.0 reports may be addressed by a
direct commit to `main` rather than a numbered patch release, with the
fix landing in the next pre-release tag.

## Reporting a vulnerability

Please do **not** open a public issue for security vulnerabilities.

Use GitHub's **private vulnerability reporting** instead: navigate to
the [Security tab](https://github.com/7Duckie/pontifex/security) and
click **Report a vulnerability**. The form submits a private advisory
that only the maintainers see. We aim to acknowledge within 72 hours.

If for some reason you cannot use the GitHub channel, fall back to
opening a draft public issue containing only the words "security
disclosure needed, please contact privately" and your preferred
contact method — and nothing else. We will reach out off-channel and
delete the issue.

For non-vulnerability security questions (architectural review,
threat-model contributions, hardening suggestions), please open a
public discussion or PR.

## How we triage

Every reported vulnerability or scanner finding receives one of four
dispositions:

- **Patch** — a fix is merged and a release issued.
- **Defer** — accepted risk for now, with a documented rationale and
  a date for re-review. Recorded as a published GitHub Security
  Advisory on the repository's
  [Security Advisories page](https://github.com/7Duckie/pontifex/security/advisories).
- **Not applicable** — the vulnerable code path is unreachable in
  Pontifex's usage. Rationale documented.
- **Upstream pending** — fix exists in principle but no released
  version yet. We track and pick up automatically.

We do not silently drop alerts. Every advisory we choose not to act
on is documented in the project's GitHub Security Advisories with a
re-review date, where it remains publicly visible alongside any
advisories that did result in a fix.

### Where alerts come from

Scanner findings flow into the same triage workflow regardless of
source. The sources currently in use:

- **Manual reports** via GitHub's private vulnerability reporting (see
  above).
- **Dependabot alerts** for known CVEs affecting Pontifex's Composer
  dependencies. Alerts appear in the repository's Security tab as
  soon as the GitHub Advisory Database publishes them, usually before
  the next CI run.
- **`composer audit`** running on every push and pull request as part
  of the `composer check` aggregate.
- **OSV scanner** running on every push as part of CI.
- **`gitleaks`** running as a pre-commit hook and in CI, catching
  accidentally-committed secrets before they reach `main`.
- **WPCS security sniffs** (`WordPress.Security.*`) enforced by PHPCS
  on every push.

Each source has its own false-positive profile; the triage protocol
above applies uniformly. A Dependabot alert against a dev-only
dependency, for example, is typically marked **Not applicable** with
a note explaining that the package is not shipped to users.

## The import trust boundary

`wp pontifex import` writes an archive's content — the `wp-content`
tree and the whole database, or (with `--whole-site`) the entire
installation including WordPress core and `wp-config.php` — from a
`.wpmig` archive onto the destination WordPress. **Importing an archive
grants its author full write access to this site's content and
database** (and its core, under `--whole-site`). A hostile or tampered
archive can carry a webshell at a legitimate path, hostile rows in
`wp_options`, or — under `--whole-site` — a malicious `wp-config.php`;
none of which is malformed, so the reader cannot tell them apart from a
genuine backup.

Pontifex's job is to refuse *malformed and escaping* input — it verifies
the archive's integrity hashes, enforces defensive size/entry/ratio
limits, and confines every path against traversal and symlink escapes
before touching the destination — not to vouch for the *intent* of a
well-formed archive you chose to restore. Therefore:

- **Only import a `.wpmig` you produced yourself or fully trust.** Treat
  an archive from an untrusted source exactly as you would treat shell
  access to your server.
- **Verify integrity in transit.** Move archives over channels you
  control; the per-entry and whole-file SHA-256 hashes detect corruption
  or tampering on import.
- **Store archives securely.** A `.wpmig` contains your entire database —
  user accounts, password hashes, secret keys — so keep it outside the
  web root and delete it securely when you are done with it.

Cross-URL migration (with the serialised-data defences it requires)
arrives in v0.2.0; archive verification (`wp pontifex verify`) and
optional signing are planned — see [`docs/roadmap.md`](../docs/roadmap.md).

## Threat model

See [`docs/threat-model.md`](../docs/threat-model.md) for Pontifex's
attack-surface ranking. New security-relevant contributions should
explain their relationship to that document.
