# 0014 — background work: WP-Cron plus a self-continuing step runner, no job-queue library

- **Status:** Proposed, 2026-07-13.
- **Deciders:** 7Duckie (v0.6.0 planning).

## Context

v0.6.0 brings the operational features: resumable exports (a backup that
survives PHP timeouts and lost SSH sessions) and scheduled exports (periodic
backups that fire without an operator). Both need work to happen across more
than one PHP request, which raises the question every WordPress plugin with
background work faces: what runs the steps?

The idea bank had pencilled in bundling a well-known job-queue library for
this. That lean predates two things v0.5.0 shipped which change the
arithmetic: the atomic single-runner lock (Pontifex runs exactly one
operation at a time, by design — there is no queue to manage because there is
never more than one job), and persisted per-operation progress state with
admin polling (the visibility a queue's status tables would otherwise
provide).

Prior art, surveyed 2026-07-13. The market-leading WordPress backup plugins
run their scheduling on WP-Cron, not on a queue library — and the most widely
deployed of them uses exactly the architecture proposed here: a manual backup
starts immediately in the current request, and the scheduler's only job is to
fire *resumptions* when the work needs more time than the web server allows
in one go. The job-queue library's own documentation is equally clarifying:
its queue runner is itself triggered by WP-Cron (or a loopback request), so
it does not escape WP-Cron's known weakness — on a site with no visitors,
nothing fires — it inherits it, while adding its own database tables, a
store/logger/runner subsystem, and multi-action concurrency semantics sized
for e-commerce order volumes, not for one backup at a time.

WP-Cron's weakness is real and must be stated honestly: it is a
pseudo-scheduler that fires on page loads, so a dead-quiet site can miss a
schedule, and some hosts block the loopback request that triggers it. Every
tool in this space carries that constraint; the honest mitigations are a
diagnostics check and documentation recommending a real cron entry — not a
library that sits on the same trigger.

## Decision

- **The unit of background work is a step, not a request.** The v0.6.0
  engine work makes an export a resumable state machine: run for a bounded
  budget, persist progress, report done-or-more. That design is owned by
  Pontifex and is scheduler-agnostic.
- **Who ticks the steps sits behind a seam** (`BackgroundRunner`), with
  implementations per surface: the CLI ticks in a loop within its own
  process; the admin's polling requests double as ticks while the operator
  watches; a WP-Cron event ticks a job nobody is watching (a browser-started
  backup after the tab closed, and every scheduled run).
- **Scheduled exports are WP-Cron events** that enqueue a job for the same
  step runner, skipping with a log line when the single-runner lock is held.
- **No job-queue library is bundled.** One operation at a time (the lock),
  one owned job store (the v0.6.0 slice that follows this ADR), and WP-Cron
  as the only external trigger. Every dependency ships into other people's
  sites; this one would bring its own tables and a queue abstraction whose
  problems Pontifex has already solved or does not have.
- **`wp pontifex doctor` gains a WP-Cron reliability check** that warns when
  `DISABLE_WP_CRON` is set or the loopback request fails, and recommends a
  real cron entry — the honest posture toward the shared constraint.

## Consequences

- No new runtime dependency; no third-party database tables; the schema
  Pontifex owns stays exactly the job store it designs for itself.
- Scheduled backups on a dead-quiet site with no real cron entry can fire
  late or not at all — the documented, industry-shared WP-Cron constraint,
  surfaced by the doctor check rather than hidden.
- If real-world evidence ever demands queue semantics (parallel jobs,
  cross-job retry policies), the runner seam is the insertion point: a
  queue-library-backed `BackgroundRunner` slots in without touching the
  engine. That is the recorded path for reopening this decision.

## Alternatives considered

- **Bundle the job-queue library** — rejected: its runner is triggered by
  WP-Cron too, so reliability does not improve; its queue/concurrency
  features solve problems the single-runner lock deliberately excludes; and
  it ships ~a megabyte of third-party code plus its own tables into every
  user's site for the benefit of neither.
- **Require a real system cron** — rejected: Pontifex must work on the
  cheapest shared hosting, where the operator cannot always add cron
  entries. Recommend, detect, and warn — never require.
- **A persistent worker/daemon** — rejected out of hand: distributed
  software running inside arbitrary hosts cannot assume process supervision.
- **Loopback-chained requests as the only ticker** (each request fires the
  next) — rejected as the sole mechanism because some hosts block loopback;
  it remains available as a tick source behind the seam where it works.
