# Contributing to Pontifex

Thanks for taking an interest. This document covers the local development
setup. For architectural background, see the design documents in `docs/`.

## One-time setup

```bash
git clone https://github.com/7Duckie/pontifex.git
cd pontifex
composer install
pip install pre-commit
pre-commit install --hook-type pre-commit --hook-type pre-push
```

After this, `git commit` runs the fast pre-commit checks and `git push`
runs the heavier pre-push checks. CI runs everything plus the security
scans.

## Daily workflow

| Command | What it does |
|---|---|
| `composer lint` | PHPCS (WordPress coding standards) |
| `composer lint:fix` | PHPCBF (autofix what PHPCS can) |
| `composer analyse` | PHPStan |
| `composer test` | Full PHPUnit suite |
| `composer test:unit` | Unit tests only — fast, no WP runtime |
| `composer check` | Lint + analyse + test + audit, the full pre-PR sweep |

## Pre-commit / pre-push budget

Pre-commit must complete in ≤3 seconds on a normal change. Pre-push must
complete in ≤60 seconds. If a check exceeds its budget, demote it rather
than tolerate the slowdown — a hook everyone disables protects nobody.

## Commit conventions

- Subject ≤50 characters, imperative, no trailing period.
- Blank line, then body explaining the *why*.
- One logical change per commit.

Example:

```
Add roave/security-advisories to require-dev

Composer will now refuse to install any version of any package
flagged in the FriendsOfPHP advisories database. This is the
cheapest possible CVE control: never have a vulnerable package
on disk to scan.
```

## Branches

- `main` is the integration branch. CI must be green for any merge.
- Feature branches: `feature/<short-description>`
- Fix branches: `fix/<short-description>`
- Security branches: kept private until disclosed; coordinate via the
  email in SECURITY.md.

## Security

Vulnerability reports: see [SECURITY.md](SECURITY.md), do not file
public issues. Architectural questions about security: open a public
discussion or PR.

See also [THREAT_MODEL.md](THREAT_MODEL.md) for the attack-surface
ranking. PRs touching ranks 1-4 should reference the threat model in
their description.

## Quality-gate reference

- Code style — `phpcs.xml.dist` (WordPress-Extra + Pontifex prefix rules)
- Static analysis — `phpstan.neon.dist` (level 6, ratcheting to 8 by v1.0)
- Tests — `phpunit.xml.dist` (unit + integration suites)
- Pre-commit — `.pre-commit-config.yaml`
- CI — `.github/workflows/ci.yml`
