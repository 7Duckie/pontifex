# Security Policy

## Supported versions

Pontifex reached **v1.0.0** on 2026-08-05 — the commitment release (see
[`docs/roadmap.md`](../docs/roadmap.md)): the public API is frozen and
the `.wpmig` archive format is locked at specification version 1.1. The
current release is **v1.0.2**, a security and correctness patch; upgrade
before restoring an archive you did not create yourself.

Security fixes ship on the current `1.x` line as a patch release. A
report found against a `0.x` pre-release is still triaged and answered
— see "How we triage" below — but the fix lands on `1.x`; there are no
backports to the `0.x` tags.

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

Cross-URL migration (`import --url=`, with the serialised-data
defences it requires), archive verification (`wp pontifex verify`),
and optional Ed25519 signing (`wp pontifex keygen`, `export --sign`,
`--public-key` on `verify`/`import`) have all shipped.

Signature enforcement is opt-in and it engages the moment you declare
a trusted key. On the CLI, supplying `--public-key` to `import` or
`verify`, or pinning `PONTIFEX_PUBLIC_KEY` in `wp-config.php`, makes
the signature mandatory: an unsigned archive — including one whose
signature was stripped, which is byte-for-byte indistinguishable from
never-signed — is refused rather than warned about
([ADR 0012](../docs/adr/0012-signature-enforcement-policy.md)). In the
browser the pin alone is the trigger, and it is applied at upload, the
one point where an archive of unknown origin crosses into the site;
once a file is on disk an uploaded and a locally-produced backup are
indistinguishable, so admin Restore, Verify and rollback deliberately
do not check signatures
([ADR 0020](../docs/adr/0020-signature-enforcement-on-the-upload-path.md)).
See [`docs/roadmap.md`](../docs/roadmap.md) for what is still ahead.

## Threat model

See [`docs/threat-model.md`](../docs/threat-model.md) for Pontifex's
attack-surface ranking. New security-relevant contributions should
explain their relationship to that document.
