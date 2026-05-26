# Pontifex Idea Bank

## Purpose

Ideas for Pontifex arrive at all hours — yours, mine, contributors',
users' — and many of them never reach the build queue not because they
were bad but because nobody captured them when they were fresh and
nobody worked them through to a yes / no / later. This document is the
place where ideas land, get thought through honestly, and either
graduate to a tracked feature, get parked with reasoning, or get
rejected with reasoning.

It is meant to be the **single reference** that other build-process
artefacts (version planning, roadmap discussions, release notes drafts,
design documents) consult when deciding what to build and when. When we
sit down to plan v0.2.0, we read this file. When a contributor asks
"could Pontifex do X?", we read this file. When something feels
half-remembered ("we talked about that once"), we read this file.

## How to use this document

**Adding an idea.** Anyone can add an idea by appending a new entry
under *Active ideas* using the template below. The first time an idea
is added it can be sparse — just the concept and a one-line motivation.
Subsequent passes deepen the analysis. There is no requirement that an
idea be fully analysed on first capture; capture-first, analyse-later
is the right rhythm, because the alternative is that ideas get lost.

**Evaluating an idea.** When time permits, expand a sparse idea into a
full entry by filling in feasibility, benefit, alternatives,
when-to-build, concerns, dependencies, and open questions. Be honest
about trade-offs, especially where an idea conflicts with the project's
privacy-first ethos, threat model, or GPL licensing.

**Connecting to the build process.** When planning a release, the
planner reads through *Active ideas* and decides which ones are ready
to move into the version's scope. Ideas committed to a version are
linked in the version's planning notes. Once shipped, they move from
*Active* to *Implemented* with a reference to the release that
delivered them.

**Status lifecycle.** Every idea sits in one of five states:

- **Active** — captured, possibly partially analysed, not yet committed
  to a release.
- **In-flight** — committed to a specific version that is currently
  being built.
- **Implemented** — shipped. Moved to the *Implemented* section with a
  release reference.
- **Parked** — not now, but worth keeping for later. Reasoning
  recorded.
- **Rejected** — decided against. Reasoning recorded so the same idea
  doesn't come back without new information.

## Idea entry template

```
### Idea NNN — [Short title]

- **Status:** [Active | In-flight | Implemented | Parked | Rejected]
- **Proposed:** [date] by [name]
- **Last reviewed:** [date]

**The concept.** What is the idea, in one or two paragraphs of plain
prose? What does it look like to a user, an operator, or a developer?

**Motivation.** Why does this matter? What's the problem it addresses
or the opportunity it captures? Who benefits?

**Feasibility.** Is it technically buildable in the project's current
shape? What infrastructure, dependencies, or hosting does it require?
What's the rough effort?

**Benefit.** What's the value of building it? For users, for the
project, for the maintainers? Is the value proportionate to the cost?

**Alternative implementations.** What other ways could this idea be
realised? Are any of them simpler, cheaper, or more aligned with
project values?

**Concerns and constraints.** What does this idea conflict with?
Privacy ethos, GPL constraints, the threat model, hosting limitations,
the no-cloud-dependency principle, user trust? The reasoning belongs
here even when uncomfortable.

**When in the build.** Which version makes sense? What needs to exist
first? What does this block or unblock?

**Dependencies.** Other ideas, features, or external things this
depends on.

**Open questions.** Anything we don't know yet that needs answering
before this can move forward.

**Decision log.** Append-only log of decisions, reviews, and changes
of state.
```

## Active ideas

### Idea 001 — Track plugin installs, including direct ZIP installs

- **Status:** Active
- **Proposed:** 2026-05-21 by 7Duckie
- **Last reviewed:** 2026-05-21

**The concept.** A way to know how many Pontifex instances are
installed and active in the wild — not only those installed via the
WordPress plugin directory, but also those installed by downloading a
release ZIP from GitHub (or any other channel) and uploading it
directly through `wp-admin → Plugins → Add New → Upload Plugin`.

**Motivation.** Right now we have no visibility at all into adoption.
We cannot answer "are people using this?", "what PHP versions do they
run?", "is the install base big enough that this CVE needs an emergency
release?", or "is anyone affected by deprecating feature X?". A
direct-install path matters for Pontifex specifically because the
plugin's audience overlaps strongly with the kind of operator who
downloads release ZIPs from GitHub for security-conscious reasons
rather than installing through the WP.org marketplace.

**Feasibility.** Three distinct mechanisms exist, with very different
properties:

1. **WordPress.org plugin directory listing.** Once we submit and are
   approved, WP.org gives us a free "active installs" band (fuzzy —
   e.g. "1,000+", "10,000+") plus version distribution. Zero
   infrastructure on our side. Counts only WP.org-sourced installs, so
   a ZIP-from-GitHub install does not register. Approval takes
   weeks-to-months and constrains some practices (e.g. compiled
   binaries, certain remote calls).

2. **Opt-in phone-home telemetry.** On activation the plugin asks the
   user once whether they wish to share anonymous usage data. If yes,
   the plugin sends a single request to a Pontifex-controlled endpoint
   containing minimum data — an install UUID (random, locally
   generated, no IP retained), the plugin version, the WP version, the
   PHP version. The UUID prevents double-counting on repeated
   requests. Requires a hosted endpoint (a Cloudflare Worker, a small
   VPS, or a CDN-fronted PHP file) and explicit opt-in UI plus a
   documented privacy notice. Counts every opted-in install regardless
   of source.

3. **Passive proxy via GitHub release-download counts.** GitHub
   already shows how many times each release asset has been
   downloaded. This is a free, no-effort, no-network-impact-on-users
   proxy for "people who at least obtained the ZIP." It overcounts
   (one person might download multiple times, or download without
   installing) and undercounts (does not count installs that arrived
   via other distribution), but it costs us nothing.

**Benefit.** Real adoption data informs which PHP versions matter,
which deprecations are safe, when to bump minimum WordPress version,
how seriously to take a CVE disclosure window, and whether features are
actually used. For users, a visible install count on the project page
builds the trust that "this is not just one person's hobby plugin."
For security purposes, knowing the install base helps
responsible-disclosure planning.

**Alternative implementations.**

- **WP.org submission only, no phone-home.** Cleanest. Undercount
  accepted as a known limitation.
- **Voluntary registration page.** Users who care can fill in a form
  on the Pontifex site to register their install. Trivial to build.
  Participation rates would be vanishingly low (5% would be
  exceptional). Effectively useless for absolute numbers but might
  give us geographic distribution.
- **Docs-only analytics.** Use a privacy-respecting analytics platform
  (Plausible, Fathom) on the documentation site so we at least know
  how many people are reading the docs. No plugin-side change. Not a
  proxy for installs, but a useful directional signal.
- **GitHub release-download counts as the headline metric.** Use what
  is already available. Resist the temptation to invent new mechanisms
  before this floor metric is exhausted.

**Concerns and constraints.** This is the deepest tension in the idea.
Pontifex's whole stance is offline-friendly, no-cloud-dependency,
user-controlled. A plugin that phones home on activation — even with
opt-in — sits uncomfortably with that. The project would need to commit
to:

- **Opt-in only**, never opt-out. The default state of a fresh install
  is "no network calls beyond what the user has explicitly enabled."
- **Minimal data.** No URLs, no IP addresses retained beyond the
  request, no site contents, no user counts, no plugin configuration.
  Just the install UUID, plugin version, WP version, PHP version.
- **Transparent.** The exact request payload documented in the privacy
  notice, with the source code that generates it findable in one
  click.
- **Revocable.** The user can revoke at any time. Revocation removes
  the UUID locally and ceases all further requests.
- **Open infrastructure.** The receiving endpoint's code is
  open-source so anyone can audit what happens to the data. Stored
  data is aggregated and published publicly so we are not the sole
  beneficiary of the data we collect.

Even with all of these in place, the act of asking creates a friction
moment on activation that some users will find off-putting. There is a
real cost.

**When in the build.** Tiered:

- **GitHub release-download counts**: in effect already; no work
  needed. Document as the de facto adoption metric until something
  better exists.
- **WP.org submission**: target the v1.0.0 era when the feature set is
  stable enough to commit to long-term support. Submitting earlier
  risks needing to maintain plugin-directory compatibility for a
  moving target.
- **Opt-in phone-home telemetry**: defer to post-v1.0 at the earliest.
  The plugin needs a stable user base, a stable settings UI, and a
  written privacy policy before we add a telemetry channel. Probably
  v1.1 or later, possibly never if release-download counts plus WP.org
  numbers turn out to be enough.
- **Docs-only analytics**: can happen anytime independently of the
  plugin. Low-priority unless we are actively making documentation
  decisions that would benefit from traffic data.

**Dependencies.** Privacy notice document (does not yet exist; would
need writing). Settings UI (none yet; planned for later versions).
WP.org submission flow (separate workstream).

**Open questions.**

- Is there a privacy-respecting third-party we could use that already
  handles "anonymous opt-in plugin install pings" without our building
  infrastructure? Worth a brief survey at decision time.
- What does a worked example of the opt-in dialog look like? Drafting
  one now would clarify whether we are actually comfortable shipping
  it.
- Could a hash of the site URL serve as a stable identifier without
  exposing the URL itself? Probably yes (`hash_hmac` with a project
  secret), but the value over a random UUID is unclear.

**Decision log.**

- 2026-05-21: Captured. Initial analysis. Recommended path: rely on
  GitHub release-download counts until v1.0.0; target WP.org
  submission for the v1.0.0 era; defer or skip phone-home telemetry.

---

### Idea 002 — Track transfer metrics (count, success rate, total bytes)

- **Status:** Active
- **Proposed:** 2026-05-21 by 7Duckie
- **Last reviewed:** 2026-05-21

**The concept.** Each Pontifex instance keeps a running tally of the
transfers it has performed: how many were attempted, how many
succeeded, how many failed, total bytes exported, total bytes imported,
sizes of the largest and smallest, and a rolling history of the last N
transfers with timestamps and sizes (but never contents). Exposed via a
`wp pontifex stats` CLI command and, eventually, an admin dashboard
tile.

**Motivation.** The user gets to see their own activity. "Have I run a
backup this month? How big was it? Did the last one succeed?" These are
questions Pontifex users will ask their plugin, and answering them
locally is both privacy-respecting and immediately useful. The
maintenance team can also ask users to share a stats export for support
purposes ("paste the output of `wp pontifex stats` into the issue"),
which speeds up bug diagnosis without any auto-upload.

**Feasibility.** Trivially feasible for the local case. Two storage
shapes are possible:

- **wp_options entries** for the aggregate counters
  (`pontifex_transfers_attempted`, `pontifex_transfers_succeeded`,
  etc.) plus a JSON-encoded `pontifex_transfer_history` for the rolling
  window. Simplest. Sufficient up to a few hundred history entries
  before the option grows unwieldy.
- **A custom DB table** (`{$wpdb->prefix}pontifex_transfers`) with one
  row per transfer. More structured, queryable, scales to thousands of
  entries, but adds a schema-migration concern to the plugin.

For v0.1.0–v0.2.0 the `wp_options` shape is sufficient and
lower-risk. Migration to a DB table can happen later if growth demands
it; the schema migration is a normal Pontifex concern by then.

A global aggregate across all installs (i.e. "Pontifex users have
transferred X TB in total") would require phone-home and falls into the
same tension as Idea 001. It is not necessary for the user-facing
benefit; out of scope for this idea.

**Benefit.** High for users (immediate, visible value with every
transfer). High for maintainers (richer bug reports). Low cost to
build. Aligns with project values (no network, user controls the data,
user can delete it).

**Alternative implementations.**

- **Stats only, no per-transfer history.** Aggregates without a list
  of past transfers. Smaller, simpler, less useful for self-help
  debugging. Worth considering as the v0.1.0 starting point.
- **Stats plus a separate audit log.** A WP-CLI-only feature for now;
  admin UI deferred. Reduces v0.1.0 surface area.
- **No stats at all, just per-transfer log files.** Each transfer
  produces a `.log` file alongside the archive. Stats are derived on
  demand by parsing logs. Privacy-friendly but slower; better as a
  complement than a replacement.
- **Export the stats as JSON.** A `wp pontifex stats --json` flag for
  support purposes. Trivial addition; valuable for users sharing
  context in issues.

**Concerns and constraints.** Stored stats must never contain content
from transferred sites (no table names, no row contents, no file paths
beyond their existence). A transfer history entry should look like
`{timestamp, direction, size_bytes, duration_seconds, outcome,
error_class?}` — nothing identifying.

There is a question of whether to record sizes for failed transfers (a
transfer that aborted partway through has a known partial size).
Probably yes; failed-transfer sizes are diagnostic information that
helps debugging.

**When in the build.** Layered:

- **Counters and aggregates (attempted, succeeded, failed, total
  bytes)**: build alongside the first export feature in v0.1.0. Cost
  is minimal; the export code is already going to know these numbers,
  so we capture them at the source.
- **Rolling history of the last N transfers**: v0.2.0, when import is
  also implemented and there is something worth recording on both
  sides of the round-trip.
- **`wp pontifex stats` CLI command**: v0.2.0–v0.3.0 once there is
  enough to report.
- **JSON export flag**: same release as the CLI command, basically
  free.
- **Admin page UI**: v0.3.0+ once the project is investing in admin UI
  generally.

**Dependencies.** Export feature (v0.1.0). For the rolling history,
the import feature (v0.2.0).

**Open questions.**

- What is N for the rolling history? 50 entries feels right — large
  enough to cover a year of monthly transfers, small enough that the
  wp_options blob stays manageable. Confirm at build time.
- Should stats persist through plugin deactivation? Probably yes;
  deletion should require explicit user action
  (`wp pontifex stats --reset`).
- Should there be a setting to disable history collection entirely for
  users who prefer no recording? Probably yes — costs nothing, respects
  autonomy.

**Decision log.**

- 2026-05-21: Captured. Initial analysis. Recommended: build aggregate
  counters in v0.1.0 alongside the first export; add history and CLI
  in v0.2.0.

---

### Idea 003 — Error and failure reporting mechanism

- **Status:** Active
- **Proposed:** 2026-05-21 by 7Duckie
- **Last reviewed:** 2026-05-21

**The concept.** A structured way to capture errors and failures during
Pontifex operations (archive write, archive read, manifest validation,
encryption setup, anything else), with a clear severity model, a local
persistent log, a self-service diagnostic-bundle command, and a
documented path for surfacing issues to the project. No automatic
remote reporting; everything user-initiated.

**Motivation.** When a transfer fails today, the user sees… whatever
the failure-throwing code happened to emit. Sometimes that's helpful,
sometimes it's a stack trace, sometimes it's silence followed by a
partial file. We need a consistent, structured way to capture, store,
and present failures so users can self-diagnose and so maintainers can
act on bug reports without playing twenty questions. The existing
`wp pontifex doctor` command already establishes the
diagnostic-information pattern; this extends that pattern from
"checking the environment" to "checking what went wrong."

**Feasibility.** All the pieces are standard PHP and WordPress
practice:

- **A PSR-3 logger** writing to a Pontifex-specific log file under
  `wp-content/pontifex/logs/` (with sensible rotation). Pontifex code
  throughout the plugin gets a logger injected and writes structured
  entries (`level`, `message`, `context`).
- **A severity model** with levels: `debug`, `info`, `notice`,
  `warning`, `error`, `critical`. Per-level handling (debug discarded
  by default; error and above always retained).
- **A WP_DEBUG-aware logger** that respects WP's own debugging
  preferences and integrates with `error_log()` when appropriate.
- **A `wp pontifex diagnostics` CLI command** that bundles the recent
  log, the output of `wp pontifex doctor`, the output of
  `wp pontifex stats`, and a sanitised environment summary into a
  single tar.gz file the user can attach to a GitHub issue. The
  "sanitised" part matters: paths and table prefixes get redacted,
  secrets get scrubbed.
- **Explicit, user-initiated submission.** No auto-upload. The user
  runs the command, inspects the bundle, attaches it to an issue or
  emails it. The plugin never sends anything by itself.

**Benefit.** Big for users (errors become actionable rather than
mysterious). Big for maintainers (bug reports arrive with structured
context). Big for the project's credibility (a plugin with a clear
story for failures looks trustworthy). Modest build cost.

**Alternative implementations.**

- **Use WordPress's built-in `error_log()` only**, no Pontifex-specific
  logging. Cheapest. Less structured, harder to query, mingled with
  everything else in the site's error log.
- **Structured logging to a DB table** rather than a file. Queryable,
  but a new schema to maintain and slower to read out for bundling.
- **Sentry / Bugsnag-style remote error reporting**, opt-in. Powerful
  but reintroduces the phone-home tension from Idea 001. Out of scope
  for early versions; could be reconsidered post-v1.0 if there is real
  demand.
- **Per-transfer log files** alongside the archive, listing exactly
  what happened during that transfer. Self-contained, archivable,
  shareable. Excellent complement to the central log. Worth doing.
- **A GitHub issue template** that requests the output of
  `wp pontifex diagnostics`. Low-effort; pairs with the CLI command
  and shapes the contribution flow.

**Concerns and constraints.** The redaction layer is the hard part.
Log entries may legitimately need to mention table prefixes, file
paths, plugin slugs, WordPress core paths. They must not contain
database contents, options values, user data, secrets, or anything from
`wp-config.php`. The diagnostic-bundle command must redact site URLs to
a generic placeholder, replace absolute paths with relative ones, mask
`wp_options` values where the option name suggests sensitivity
(anything ending in `_key`, `_secret`, `_token`, `_password`), and
strip request headers entirely from any captured network output.

Some redactions will inevitably be wrong (over-redacted, hiding useful
information; under-redacted, leaking something we did not anticipate).
The redaction pass should be conservative: when in doubt, redact. Users
can be invited to send unredacted information separately if a
maintainer needs more detail.

**When in the build.** Tied closely to v0.1.0:

- **PSR-3 logger and severity model**: v0.1.0, alongside the
  ArchiveWriter. Errors during the first real feature are the first
  place this matters.
- **Per-transfer log files alongside the archive**: v0.1.0 for export,
  extended in v0.2.0 for import.
- **`wp pontifex diagnostics` CLI command with sanitised bundle
  output**: v0.2.0 or v0.3.0, once there is enough working code that
  failures actually happen in interesting ways.
- **GitHub issue template requesting the diagnostics bundle**: same
  release as the diagnostics command, paired together.
- **Remote opt-in error reporting (Sentry-style)**: deferred
  indefinitely. Reconsider post-v1.0 only if a real need emerges that
  the local-bundle workflow cannot meet.

**Dependencies.** Archive writer (v0.1.0). Idea 002 (the diagnostics
bundle includes stats output, which presumes stats exist).

**Open questions.**

- Where should the log file live? `wp-content/pontifex/logs/` is the
  obvious choice, but writability assumptions for `wp-content/` vary
  by hosting. A fallback to `WP_CONTENT_DIR/uploads/pontifex-logs/`
  might be more universally writable.
- What is the log retention policy? "Keep the last 5 MB of logs,
  rotate older into a `.1` file" is a reasonable starting point.
- Should errors trigger a WordPress admin notice? Probably yes for
  `error` and `critical`, never for lower levels.
- How does the logger interact with `WP_DEBUG`? When `WP_DEBUG` is on,
  `debug` level is captured; otherwise it is discarded.

**Decision log.**

- 2026-05-21: Captured. Initial analysis. Recommended: PSR-3 logger
  and severity model in v0.1.0; diagnostics bundle in v0.2.0–v0.3.0;
  never auto-upload.

---

### Idea 004 — `--passphrase` encryption flag for `wp pontifex export`

- **Status:** Active
- **Proposed:** 2026-05-26 by 7Duckie (surfaced during commit 19c planning)
- **Last reviewed:** 2026-05-26

**The concept.** A flag on `wp pontifex export` (and the matching
import command) that accepts a passphrase from the user, derives an
encryption key from it, and writes the archive's entry payloads as
AES-256-GCM ciphertext using the sodium extension. The archive
format already reserves space for a 12-byte per-entry nonce and a
16-byte argon2id salt in the footer, so the layout is ready; what is
missing is the codec implementation, the key-derivation step, the
CLI plumbing, and the matching read path.

**Motivation.** A Pontifex archive is a complete copy of a
WordPress site, database included. Anyone who can read the file can
read every password hash, every option value, every uploaded file.
For archives stored on shared hosts, transferred via email, or
parked on USB drives between two physical locations, encryption is
the difference between "safe to lose" and "credential disaster".
Some operators will refuse to use Pontifex without it.

**Feasibility.** The sodium extension is part of PHP since 7.2 and
is already on Pontifex's required-extensions list (DoctorCommand
flags its absence as FAIL). Argon2id is available via
`sodium_crypto_pwhash`. AES-256-GCM is available via
`sodium_crypto_aead_aes256gcm_*`. The codec registry already
contemplates encrypted codec variants — the `CodecRegistry` docblock
explicitly notes v0.2.0 will register them. So the building blocks
exist; the work is implementation, testing, and CLI ergonomics
(passphrase entry without exposing it in shell history,
configurable cost parameters, etc.).

**Benefit.** Substantial for security-conscious operators. Probably
the single most-requested missing feature once Pontifex has users.
Differentiates from the "just zip wp-content and dump the DB"
school of WordPress backup.

**Alternative implementations.**

- **Whole-archive encryption** by passing the .wpmig file through
  `gpg --symmetric` or `openssl enc`. Works today with no Pontifex
  changes but trades streaming for buffering and makes recovery
  fiddlier.
- **Per-entry only.** v0.1.0's payload-only encryption (the
  EntryHeader stays plaintext). Smaller scope, faster to ship,
  but the file list leaks.
- **File-level encryption only, database-level not.** Inconsistent;
  database dumps often hold the most sensitive material.
- **Use OS keyrings (macOS Keychain, libsecret, gnome-keyring)**
  for storage rather than passphrase entry. Better UX but
  platform-specific code we do not want to maintain in v0.x.

**Concerns and constraints.** Cryptographic UX is the hard part:
passphrases in shell history, terminal echo, key-derivation cost
tuning, what to do when the user forgets the passphrase. The
format already accommodates encryption, but the user experience of
"this archive is encrypted and the passphrase is wrong" needs
careful design. Cryptographic bugs are catastrophic; the
implementation needs review by someone with cryptographic
experience, not just code review by a generalist.

**When in the build.** Out of v0.1.0 scope. Best fit is v0.2.0
when the encryption-codec slot is the headline feature. Worth
deferring further if v0.1.0 ships and we discover encryption is
not actually a blocking concern for early users.

**Dependencies.** Functional v0.1.0 export and import (so the
encryption layer can wrap an already-working pipeline rather than
being built into the foundation). sodium extension (already
required). Documented threat model around at-rest archive
confidentiality (the existing threat model may already cover
this; needs cross-checking when this idea is picked up).

**Open questions.**

- Argon2id parameters: ops/mem cost defaults? Configurable per
  archive?
- What's the recovery story if the passphrase is forgotten? (Not
  recoverable by design — but the documentation needs to be loud
  about this.)
- How does this interact with the future `wp pontifex sign`
  detached-signature feature? Encrypt-then-sign or sign-then-
  encrypt? Both? Neither?

**Decision log.**

- 2026-05-26: Captured during commit 19c planning. Recommended:
  defer to v0.2.0 alongside the broader encryption-codec work
  already contemplated by CodecRegistry.

---

### Idea 005 — Per-file progress indicators during `wp pontifex export`

- **Status:** Active
- **Proposed:** 2026-05-26 by 7Duckie (surfaced during commit 19c planning)
- **Last reviewed:** 2026-05-26

**The concept.** During `wp pontifex export`, print a progress
indicator that updates as each entry is written. At minimum a
running entry count ("Wrote 4523 of ~12000 entries"); ideally a
progress bar with a current-entry name ("[==>      ] writing
wp-content/uploads/2024/photo.jpg") that updates in place via
carriage return.

**Motivation.** A bare `wp pontifex export` on a real-sized site
runs for minutes with no output. Users won't know whether it's
working, stuck, or hung. The doctor command is fast and chatty;
the export command will be slow and silent unless we add feedback.
A progress indicator is the difference between "I trust this
tool" and "I tab away and forget about it".

**Feasibility.** Modest. WP-CLI ships a Notify progress bar
(`\WP_CLI\Utils\make_progress_bar`) that handles tty detection,
update throttling, and non-tty fallback. Plugging into it requires
ArchiveWriter to expose a per-entry callback hook — currently it
writes entries in a tight loop with no extension point. So this
idea is partly a CLI feature and partly an ArchiveWriter API
extension.

**Benefit.** High user-trust value. Modest engineering cost. No
correctness impact.

**Alternative implementations.**

- **Per-entry log lines** without an in-place progress bar. Simpler
  to implement (every entry produces a line of output). Verbose for
  large sites; obscures the summary line at the end.
- **Periodic time-based heartbeat** ("still working... 5s elapsed,
  2300 entries written"). Lighter-touch; no per-entry visibility.
  Easy to implement without ArchiveWriter changes (a timer in
  ExportCommand that polls a shared counter). Acceptable middle
  ground.
- **No progress indicator at all**, document the expected duration
  in the user-facing docs. Cheap; underwhelming.

**Concerns and constraints.** ArchiveWriter must remain testable
in isolation, so any callback hook needs a no-op default. Progress
bars should NOT print when --quiet is given or when stdout is not
a tty. Progress bars should NOT print when --format=json is in use
(no such flag on export yet, but anticipate it). None of these
are hard problems; they need to be remembered when the feature
lands.

**When in the build.** Defer to v0.2.0 at the earliest, possibly
later. v0.1.0's job is "make a working archive end to end"; the
ergonomic polish layer comes once the foundation is stable.

**Dependencies.** A callback or progress-event hook in
ArchiveWriter (does not exist yet; would need designing). The
existing WP_CLI progress bar utility (already available).

**Open questions.**

- Should the progress bar show entry count, byte count, or both?
- For database chunks, how do we name "what's being written"
  meaningfully? "wp_posts chunk 3 of 12" is good but requires the
  manifest builder to know the total chunk count ahead of time.

**Decision log.**

- 2026-05-26: Captured during commit 19c planning. Recommended:
  defer to v0.2.0+; design the ArchiveWriter callback hook at
  that point.

---

### Idea 006 — Resumable exports from partial state

- **Status:** Active
- **Proposed:** 2026-05-26 by 7Duckie (surfaced during commit 19c planning)
- **Last reviewed:** 2026-05-26

**The concept.** If `wp pontifex export` is interrupted partway
through (PHP timeout, kill signal, host reboot, lost SSH session),
the operator can re-invoke with the same flags and the export
picks up from where it left off rather than starting over.

**Motivation.** A 40-minute export that has to restart because a
broken SSH connection killed the process is a brutal user
experience. For large sites on shared hosts with PHP timeouts,
"restart from scratch" might mean the export never completes at
all. Resume support turns a hard-to-use tool into a forgiving one.

**Feasibility.** Genuinely hard. Three sub-problems:

1. **Capturing progress state.** Where is the export now? Which
   entries have been written, which are pending? Likely a
   sidecar `.wpmig.progress` file alongside the destination,
   listing committed entries.
2. **Resuming the writer.** ArchiveWriter currently writes the
   manifest at the END once all entries are known. To resume,
   the manifest needs to be reconstructible from progress state
   or the format needs a "partial archive, finish later" mode.
3. **Verifying nothing has changed.** If the source database or
   filesystem has changed since the partial export started, the
   archive would be internally inconsistent. Resume needs to
   detect this and refuse rather than silently corrupting.

**Benefit.** Big for users with large sites or aggressive PHP
timeouts. Significant engineering effort.

**Alternative implementations.**

- **Just lower PHP's max_execution_time message in
  DoctorCommand**, encourage operators to use `php -d
  max_execution_time=0` from WP-CLI. Cheap, partially effective.
  Already happens.
- **Action Scheduler-driven background exports.** Run the export
  via the WP-Cron / Action Scheduler queue so it survives request
  death naturally. Fits Pontifex's existing plan to bundle
  Action Scheduler. Probably the right long-term direction; v0.2.0
  or later when the Action Scheduler integration lands.
- **Streaming-resume via the archive format itself.** Reserve
  format space for a "continued" flag; allow appending to an
  in-progress archive. Largest scope; biggest payoff; closest to a
  true resume.

**Concerns and constraints.** This is a "v1.x or beyond" feature.
v0.1.0 sets up the working baseline; v0.2.0 adds polish; resume is
probably v0.3.0+ work, behind Action Scheduler integration.

**When in the build.** Out of scope until at least v0.3.0.

**Dependencies.** Action Scheduler integration (currently Pontifex
detects but does not yet bundle it). Format-level work to support
either sidecar progress files or in-place resume markers.

**Open questions.**

- Cleanly separable into "Action-Scheduler-runs-the-export-in-
  background" vs "operator-resumes-a-killed-export"? They sound
  similar but solve different problems.
- How does this interact with encryption? A partial-then-resumed
  encrypted archive has subtle key-derivation implications.

**Decision log.**

- 2026-05-26: Captured during commit 19c planning. Recommended:
  defer until Action Scheduler integration lands; reassess then.

---

### Idea 007 — Full behavioural test coverage of CLI commands' `__invoke`

- **Status:** Active
- **Proposed:** 2026-05-26 by 7Duckie (surfaced during commit 19c planning)
- **Last reviewed:** 2026-05-26

**The concept.** Add behavioural tests that exercise each CLI
command's `__invoke` method end-to-end with mocked dependencies,
catching orchestration bugs (wrong call order, missing argument
plumbing, malformed output) that the current
structural-plus-helpers test pattern does not catch. Requires
brain/monkey to be properly wired into the PHPUnit bootstrap so
WP_CLI's static methods can be stubbed.

**Motivation.** Today, DoctorCommand and ExportCommand both ship
with structural tests (the class is wired correctly) and per-method
behavioural tests of pure helpers. What's NOT tested is the
orchestration logic inside `__invoke`. Phase 5 integration tests
catch some of those bugs, but slowly and only after spinning up a
real WordPress. A unit-level `__invoke` test catches the same
class of bug in milliseconds and surfaces clearer failure
messages.

**Feasibility.** Two pieces of work:

1. **Bootstrap brain/monkey** in the PHPUnit setUp/tearDown lifecycle.
   brain/monkey is already in composer.json (`brain/monkey: ^2.7`)
   but no tests currently rely on its function-stubbing for `WP_CLI`
   static calls. The bootstrap needs to call `Brain\Monkey\setUp()`
   and `tearDown()` for tests that want WordPress function or class
   stubs.
2. **Write the actual `__invoke` tests.** For ExportCommand: stub
   WP_CLI::confirm, WP_CLI::log, WP_CLI::error, WP_CLI::halt, mock
   ManifestBuilder, run the command with various flag combinations,
   assert on the expected sequence of calls. Probably 8-12 tests
   per command.

**Benefit.** Catches orchestration bugs at unit speed; reduces
reliance on integration tests for early bug detection; documents
the expected call sequence for future readers. Modest cost once
the bootstrap is done.

**Alternative implementations.**

- **Rely entirely on Phase 5 integration tests.** Current plan.
  Slower feedback; less granular failure messages.
- **Test ONLY the new ExportCommand**, leave DoctorCommand alone.
  Asymmetric; readers wonder why one command is tested
  comprehensively and another isn't.
- **Promote the orchestration to a separate testable class** rather
  than testing the WP-CLI dispatch surface directly. Cleaner
  architecturally but a larger refactor.

**Concerns and constraints.** brain/monkey for static methods (like
`WP_CLI::log`) is less straightforward than for free functions —
it requires either an alias or a slight architectural twist. The
existing brain/monkey usage in Pontifex (pre-19b, before the
WordPressContext refactor) only stubbed free functions, so we'd be
breaking new ground.

**When in the build.** Best fit is a dedicated commit AFTER commit
19c lands. Doing both refactors in one commit would have made
review and rollback harder. Realistically: a commit 19d or a
v0.1.1 commit, depending on whether v0.1.0 ships first.

**Dependencies.** brain/monkey is already installed; just needs
wiring. Existing tests must continue to pass after the bootstrap
addition (verify that the bootstrap doesn't break the structural
tests by leaking state).

**Open questions.**

- Per-test brain/monkey lifecycle, or per-class? PHPUnit hooks
  make either possible.
- How to stub `WP_CLI::confirm` and `WP_CLI::halt` cleanly? Both
  have side effects (prompt or exit) that need clean test-side
  substitutes.

**Decision log.**

- 2026-05-26: Captured during commit 19c planning. Recommended:
  dedicated follow-up commit after 19c lands. The gap was
  deliberately accepted at the time of building 19c on the
  understanding it would be closed in this follow-up.


### Idea 008 — Migrate to PHPUnit 11 or 12 (drop abandoned transitive deps)

- **Status:** Active
- **Proposed:** 2026-05-26 by 7Duckie (surfaced during Sprint 1 lockfile work)
- **Last reviewed:** 2026-05-26

**The concept.** Upgrade the project's test framework from PHPUnit
10.5 to PHPUnit 11 or 12. The motivating signal is that `composer
update` reports two of PHPUnit 10's transitive dependencies as
"abandoned": `sebastian/code-unit` and
`sebastian/code-unit-reverse-lookup`. Both are tiny internal
utilities written by Sebastian Bergmann (PHPUnit's creator); his
plan is to merge their functionality into the main
`phpunit/phpunit` package, which happens in PHPUnit 11+. Upgrading
removes the abandoned warnings cleanly without any package-by-
package replacement.

**Motivation.** Less about urgency, more about hygiene. The
abandoned warnings are not security concerns — Bergmann actively
maintains the broader PHPUnit umbrella, and "abandoned" here means
"the standalone API is frozen; the code lives on inside PHPUnit
itself." But the warnings are noise in every `composer update`,
they may eventually attract drive-by Dependabot alerts, and the
underlying issue does resolve on its own with the framework
upgrade. Doing it deliberately, on our schedule, is cleaner than
being forced into it by a future security disclosure or a
PHPUnit 10 EOL announcement.

**Feasibility.** Real work, not a one-line change. Three concerns:

1. **PHPUnit 11 requires PHP 8.2+.** Pontifex currently targets
   PHP 8.1 as its support floor (composer.json: `"php": ">=8.1"`;
   PHPStan pinned to `phpVersion: 80100`). Raising the floor to
   8.2 is a real product decision — it affects which WordPress
   hosts can run Pontifex. WordPress.org reports show 8.1 still
   has meaningful share in shared hosting. Worth checking the
   adoption numbers at decision time rather than guessing.
2. **PHPUnit 11 dropped some PHPUnit 10 patterns** (annotation
   syntax in particular) and changed others. Our 578 tests would
   need a review pass for deprecated patterns. The maintainers
   publish a migration guide; the work is mechanical but
   non-trivial.
3. **PHPUnit 12 has the same PHP-floor concern (8.3+ in some
   minor releases).** Going directly to 12 rather than 11 doubles
   the floor-raise question.

**Benefit.** Removes two abandoned-package warnings on every
`composer update`. Brings the project onto an actively-developed
test framework with current features (better data providers,
improved attribute syntax, stricter risky-test handling).
Modernises the test suite ahead of v1.0. Modest immediate
benefit; future-proofing value.

**Alternative implementations.**

- **Stay on PHPUnit 10, accept the warnings.** Composer's
  "abandoned" notification is informational, not blocking. The
  packages remain functional. Cost-free. Worth doing if v0.1.0
  ships and gets traction without anyone complaining about
  abandoned-package warnings.
- **Pin to PHPUnit 11 specifically** rather than 12, accepting
  the smaller PHP-floor raise (8.1 → 8.2) for the smaller
  upgrade surface.
- **Skip 11, go directly to 12** when 12 is mature, accepting
  the larger PHP-floor raise but avoiding a two-step migration.

**Concerns and constraints.** PHP-floor raise is the real cost.
Every WordPress site that runs Pontifex on PHP 8.1 would be cut
off the day this lands. Mitigation: pair the upgrade with a
clear release-note announcement, give it a major version bump
(v0.x.0 → v0.(x+1).0 minimum, possibly defer to v1.0), and time
it to align with WordPress core's own PHP-floor moves.

**When in the build.** Defer to post-v0.1.0 at the earliest.
Best candidate is v0.3.0 or v1.0 release polish, when the
PHP-floor question gets a deliberate review anyway. Not v0.1.0
or v0.2.0 — both have enough scope already.

**Dependencies.** None hard. The decision is calendar-driven
(when does PHPUnit 10 EOL? what's PHP 8.1's WordPress install
share at the time?) rather than blocked by other Pontifex work.

**Open questions.**

- What's the actual WordPress install share on PHP 8.1 at the
  point this decision is made? `wordpress.org/about/stats` is
  the data source; the answer drives the floor-raise call.
- PHPUnit 11 or PHPUnit 12? Probably 11 first (smaller jump),
  but check support-window dates at decision time.
- Are there any custom assertion helpers or attributes Pontifex
  uses that have changed between PHPUnit 10 and 11? Quick audit
  before the migration starts.

**Decision log.**

- 2026-05-26: Captured during Sprint 1 / Commit 1 lockfile work.
  Recommended: defer to v0.3.0 or v1.0 release polish; revisit
  PHP-floor data at decision time.
---

## Implemented ideas

*None yet. Entries arrive here once shipped, with a reference to the
release that delivered them.*

## Parked ideas

*None yet. Entries arrive here when an active idea is set aside for
later, with reasoning recorded.*

## Rejected ideas

*None yet. Entries arrive here when an active idea is decided against,
with reasoning recorded so the same idea doesn't return without new
information.*
