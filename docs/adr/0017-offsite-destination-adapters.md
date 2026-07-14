# 0017 — offsite destinations: uploading a finished backup to storage the user owns

- **Status:** Proposed, 2026-07-13.
- **Deciders:** 7Duckie (v0.8.0).
- **Revised:** 2026-07-14 — v0.8.0 ships **SFTP only**; the S3 adapter is deferred. See "Revision" at the end.

## Context

Through v0.7.x a `.wpmig` archive is only ever written to local disk. A
local-only backup is a single point of failure — if the disk, the filesystem,
or the whole server is lost, every copy is lost with it — and it is the most
commonly cited shortcoming of local backup tools. Users want an offsite copy.

Pontifex's ethos is no-cloud-dependency and no phone-home, so the tension has to
be resolved before any code: **Pontifex runs no service and holds no one's
data.** An offsite destination writes a finished archive to storage the *user*
already owns and configures — their SFTP box, their S3-compatible bucket. It is
the user's cloud, never ours. The tagline sharpens from "a backup never leaves
the disk" to "we run no cloud": no Pontifex relay, no account, no phone-home.

Three facts shape the design. First, both export runners finalise the archive
with a single atomic `rename()` and deliberately leave every post-write side
effect (counters, history, chmod) to the caller — a clean, single hook point for
an upload step, in the caller, after the file is provably complete. Second, the
seam pattern is established (a small interface named for the work it does, a
`final` implementation, injected via a factory) — a destination is a natural
behaviour seam in the style of `SafetyArchiverInterface`. Third, a multi-gigabyte
upload cannot complete inside a 30-second web-cron tick, which is exactly the
timeout the resumable engine (ADR 0014/0015) was built to survive — so the
unattended path needs more than a naive upload call.

## Decision

- **User's-own-storage only.** Destinations write to storage the user owns and
  configures. Pontifex adds no service, no account, and no phone-home. A
  Pontifex-run relay or vault is rejected outright.
- **A `DestinationAdapter` seam** (`put`, `list`, `get`, `delete`) lives in a new
  `src/Destination/` namespace. Concrete adapters are `final`; a
  `DestinationFactory` resolves a stored destination to an adapter, and a
  `DestinationStore` persists named destinations in `wp_options` (the
  `ScheduleStore` pattern). The archive layer never sees a cloud SDK type.
- **`put` is paired with `pull`.** A finished backup can be fetched back from a
  destination for recovery after local loss — offsite storage is recoverable, not
  write-only. This is the round-trip discipline applied to the transport.
- **Two adapters first, both without OAuth:** SFTP (via `phpseclib`) and
  S3-compatible (via `akeeba/s3`). Both are pure-PHP, GPL-compatible, need no
  native PHP extension, and make no call home. One S3 adapter covers S3,
  Backblaze B2, Wasabi, MinIO, and DigitalOcean Spaces.
- **Upload runs in the caller, after the atomic rename, in the CLI process.** The
  CLI has unlimited execution time, so a large upload is safe there. v0.8.0 is
  **CLI-first**: no admin destination surface (per the ADR 0008/0016 precedent
  that partial and offsite operations are CLI-only), and **no scheduled/cron
  offsite** — that waits for a cross-request chunked-resumable upload with its own
  ADR, so it cannot re-introduce the web-cron timeout.
- **Credentials never travel as flags.** A named destination is stored in
  `wp_options`; its secret is referenced by **environment-variable name** and read
  at point of use. A `--destination=<name>` flag carries only the name — never a
  password, key, or token — so nothing sensitive lands in shell history (the
  Idea 004 passphrase concern).
- **SFTP host keys are pinned.** A configured host-key fingerprint is required; a
  connection to a host whose key does not match is refused. Trust-on-first-use is
  not the default — it must be opted into explicitly with `--insecure-host-key`
  (or the stored equivalent), which emits a loud warning. Silent MITM exposure is
  never the path of least resistance.
- **S3 requires TLS.** A plain-`http` endpoint is refused; the endpoint must be
  `https`.
- **Uploading an unencrypted archive to a destination warns.** The archive is
  leaving the user's own server into storage whose safety Pontifex cannot vouch
  for, so an unencrypted upload emits a warning (it is not blocked — the user owns
  the destination and the choice).
- **Retention is per-destination.** Each destination prunes its own remote copies
  to the newest N, ordered by the archive's timestamped name (not remote mtime,
  which can lie), with a floor that can never prune to nothing — mirroring the
  schedule-retention and safety-archive discipline (ADR 0005). Pruning runs in the
  CLI process after a successful upload, or on demand.
- **Doctor reports destination config without touching the network.** `doctor`
  validates that each stored destination is well-formed and that its referenced
  credential environment variables are present — no I/O — preserving its
  read-only, no-network contract. Live reachability is an explicit, on-demand
  `wp pontifex destination test`.

## Consequences

- Two new runtime dependencies — `phpseclib/phpseclib` and `akeeba/s3` — both
  pure-PHP and added require-only, which does not trip the ADR 0002 parallel-config
  invariant but does enlarge the shipped `--no-dev` vendor; Plugin Check on the
  built package must stay error- and warning-free under them.
- `ExportOptions` gains an optional destination; the runners are unchanged, since
  the upload is a caller concern after finalisation.
- Scheduled backups do **not** push offsite in v0.8.0. Unattended offsite waits
  for a chunked-resumable upload design (a future ADR), because a large upload
  cannot finish in one web-cron tick.
- Encryption still refuses resumable mode (unchanged); a destination is orthogonal
  to the archive bytes — it moves the finished file, encrypted or not.
- Dropbox and Google Drive are deferred: their OAuth token lifecycle (silent
  expiry is a known failure mode of other tools) and a reviewed app are heavier,
  and the no-OAuth SFTP/S3 pair already covers most "user's own storage" cases.

## Alternatives considered

- **`aws-sdk-php` for S3.** Rejected: a heavy transitive dependency graph running
  inside other people's sites, against the every-dependency-ships ethos.
  `akeeba/s3` is a pure-PHP, no-dependency SigV4 connector that does the same job.
- **The `ssh2` PHP extension for SFTP.** Rejected: absent on most shared hosts,
  which would make the feature unavailable exactly where local-only backups are
  most dangerous. `phpseclib` is pure PHP and works wherever PHP does.
- **Trust-on-first-use host keys** (accept and remember whatever key answers).
  Rejected: it silently accepts a man-in-the-middle on the first connection. The
  fingerprint is pinned and the insecure path is an explicit, loud opt-in.
- **Credentials as CLI flags or stored in plaintext.** Rejected: flags leak into
  shell history and process listings; secrets are referenced by environment-variable
  name and never written to the database.
- **A Pontifex-run relay or vault.** Rejected outright: it would make Pontifex a
  service that holds user data and phones home — the opposite of the project's
  stance.
- **Pushing scheduled backups offsite in v0.8.0.** Rejected for this version: a
  large unattended upload cannot complete in a single web-cron tick and would
  re-introduce the timeout the resumable engine exists to avoid. It needs a
  cross-request chunked-resumable upload and its own ADR.
- **Dropbox / Google Drive as the first destinations.** Deferred: OAuth adds a
  token-refresh lifecycle and a reviewed application; the no-OAuth SFTP adapter
  ships first.

## Revision (2026-07-14) — SFTP only for v0.8.0; the S3 adapter is deferred

Slice 2's pre-build research established that `akeeba/s3` — the pure-PHP S3
connector this ADR chose — in fact requires the `ext-curl` and `ext-simplexml`
extensions, is GPL-3.0-or-later (making the distributed whole GPLv3+), and brings
a cloud-SDK surface. Together those cut against the project's in-house,
minimal-dependency, airgap-leaning posture. 7Duckie's decision: **v0.8.0 ships
SFTP only; the S3 adapter (and any external-SDK backend) is deferred**, revisited
only if a future need outweighs the dependency and ethos cost.

This narrows the "two adapters first" decision above to one. Nothing else in this
ADR changes: the seam, credential-by-environment-variable-name, host-key pinning,
`put` paired with `pull`, per-destination retention, and the network-free doctor
check all stand for the SFTP adapter. On the airgap question this surfaced: an
SFTP upload is an outbound connection but **not** a phone-home — Pontifex runs no
server in the path; the user's backup goes to the user's own server, under the
user's own credentials, only on the user's explicit `export --destination`
command. SFTP is the minimal way to offer that (one pure-PHP library, no service,
no SDK); the only stricter posture is local-only, where the plugin opens no socket
and the user moves the archive themselves.
