# Security Policy

## Supported versions

Pontifex is in pre-alpha (v0.0.x). No version is yet production-ready,
and no version is yet receiving security patches. This will change at
v0.1.0 (Phase 1 complete).

## Reporting a vulnerability

Please do **not** open a public issue for security vulnerabilities.
Instead, email `security@<domain-tbd>` with the details. We aim to
acknowledge within 72 hours.

For non-vulnerability security questions (architectural review,
threat-model contributions, hardening suggestions), please open a
public discussion or PR.

## How we triage

Every reported vulnerability or scanner finding receives one of four
dispositions:

- **Patch** — a fix is merged and a release issued.
- **Defer** — accepted risk for now, with a documented rationale and
  a date for re-review. Recorded in `SECURITY-ADVISORIES.md`.
- **Not applicable** — the vulnerable code path is unreachable in
  Pontifex's usage. Rationale documented.
- **Upstream pending** — fix exists in principle but no released
  version yet. We track and pick up automatically.

We do not silently drop alerts. Every advisory we choose not to act on
is documented in `SECURITY-ADVISORIES.md` with a re-review date.

## Threat model

See `THREAT_MODEL.md` for Pontifex's attack-surface ranking. New
security-relevant contributions should explain their relationship to
that document.
