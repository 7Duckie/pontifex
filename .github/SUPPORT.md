# Getting help with Pontifex

Pontifex is free, open-source software maintained by one person in their
spare time. Help is offered on a **best-effort basis with no
service-level guarantee** — please bear that in mind, be patient, and be kind.

## Try this first

Before opening an issue, two CLI commands cover most environment and
diagnosis questions without waiting on anyone:

- `wp pontifex doctor` — a read-only audit of the host environment (PHP
  and WordPress versions, disk space, symbolic-link support, and more).
  Most "why doesn't this work" questions are answered by a WARN or FAIL
  row here.
- `wp pontifex diagnostics` — writes a single `.tar.gz` support bundle
  (the `doctor` output, the activity readout, and recent Pontifex
  logs). The site URL is replaced with a placeholder, paths under the
  WordPress root, wp-content, your home directory, the system temp
  directory and `/root` are replaced with placeholders such as
  `{WP_CONTENT_DIR}`, and option values whose names end in `_key`,
  `_secret`, `_token` or `_password` are masked. **Paths outside those
  prefixes are not rewritten, so skim the bundle before you attach it**
  — nothing is ever uploaded automatically; you decide what to attach
  to a bug report.

## Where to go

| You want to… | Go here |
|---|---|
| Ask how to use Pontifex, share an idea, or discuss the archive format | [GitHub Discussions](https://github.com/7Duckie/pontifex/discussions) |
| Report a bug (export, import, the round trip, the CLI) | [Open a bug report](https://github.com/7Duckie/pontifex/issues/new?template=bug_report.yml) — attach your `diagnostics` bundle if you have one |
| Request a feature | [Open a feature request](https://github.com/7Duckie/pontifex/issues/new?template=feature_request.yml) — but skim the [roadmap](../docs/roadmap.md) and [idea bank](../docs/idea-bank.md) first |
| Report a security vulnerability | **Privately** — see [`SECURITY.md`](SECURITY.md). Never in a public issue. |

Pontifex 1.0.0 has been submitted to the WordPress.org plugin
directory; until that listing is live, install from GitHub.

## Before you ask

A well-formed question is answered faster. Please include your Pontifex version,
your WordPress and PHP versions, the exact `wp pontifex …` command you ran, and
what you expected versus what happened — the `doctor` and `diagnostics` output
above covers most of this in one step.

**Never paste secrets, credentials, or the contents of a `.wpmig` archive** — it
contains your entire database, including password hashes and secret keys.
`diagnostics` redacts the paths and secret-shaped option values listed
above from its bundle, but a raw `.wpmig` archive is not sanitised at all.

## What this project does not offer

- No paid support, no service-level agreement, and no guaranteed response time.
- No help with general WordPress administration unrelated to Pontifex.
- No promise beyond what [`SECURITY.md`](SECURITY.md) states: v1.0.0 is the
  commitment release — the public API and the `.wpmig` format are stable —
  but this is still one person's spare-time project, not a supported product.
