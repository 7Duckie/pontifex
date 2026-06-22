# Getting help with Pontifex

Pontifex is **pre-alpha**, free, open-source software maintained by one person
in their spare time. Help is offered on a **best-effort basis with no
service-level guarantee** — please bear that in mind, be patient, and be kind.

## Where to go

| You want to… | Go here |
|---|---|
| Ask how to use Pontifex, share an idea, or discuss the archive format | [GitHub Discussions](https://github.com/7Duckie/pontifex/discussions) |
| Report a bug (export, import, the round trip, the CLI) | [Open a bug report](https://github.com/7Duckie/pontifex/issues/new?template=bug_report.yml) |
| Request a feature | [Open a feature request](https://github.com/7Duckie/pontifex/issues/new?template=feature_request.yml) — but skim the [roadmap](../docs/roadmap.md) and [idea bank](../docs/idea-bank.md) first |
| Report a security vulnerability | **Privately** — see [`SECURITY.md`](SECURITY.md). Never in a public issue. |

## Before you ask

A well-formed question is answered faster. Please include your Pontifex version,
your WordPress and PHP versions, the exact `wp pontifex …` command you ran, and
what you expected versus what happened.

**Never paste secrets, credentials, or the contents of a `.wpmig` archive** — it
contains your entire database, including password hashes and secret keys.

## What this project does not offer

- No paid support, no service-level agreement, and no guaranteed response time.
- No help with general WordPress administration unrelated to Pontifex.
- No production-readiness promise yet. See the status table in the
  [README](../README.md); do not rely on Pontifex for production data at this
  stage.
