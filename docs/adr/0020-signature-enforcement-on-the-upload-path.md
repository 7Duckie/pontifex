# 0020 — signatures in the browser: enforce on the upload path, where an archive crosses the trust boundary

- **Status:** Proposed, 2026-07-29.
- **Deciders:** 7Duckie (signature parity on the admin upload path).

## Context

[ADR 0012](./0012-signature-enforcement-policy.md) settled the signing *policy*:
a trusted public key, supplied per run with `--public-key` or pinned once as
`PONTIFEX_PUBLIC_KEY` in `wp-config.php`, makes the Ed25519 signature
**mandatory**. An unsigned archive — including one whose signature was stripped,
which is byte-for-byte indistinguishable from never-signed — is refused rather
than warned about. The reasoning was that the unkeyed SHA-256 hashes detect
*corruption*, never *tampering*, so verification that can be downgraded by
deleting the signature block protects nothing.

That ADR closed with a line that this one exists to redeem:

> The admin screens are untouched: they restore plain archives only and have
> never consulted signatures — recorded here, and revisited if signing ever
> comes to the browser.
> — `docs/adr/0012-signature-enforcement-policy.md`

Since then the browser has become the primary surface. v0.5.0 shipped the whole
admin UI, and v0.9.x has been spent making it legible and truthful. The
enforcement, however, never moved.

### What shipped before this decision (verified against the code)

The enforcement was CLI-only, and narrowly so.

| Fact | Evidence |
|---|---|
| `PONTIFEX_PUBLIC_KEY` was read in exactly two files | `src/Cli/ImportCommand.php:973-974`, `src/Cli/VerifyCommand.php:436-437` |
| `ArchiveReader::verify_signature()` had exactly two production callers | `src/Cli/ImportCommand.php:1021`, `src/Cli/VerifyCommand.php:484` |
| Admin Restore checks no signature — and says so | `src/Admin/RestoreController.php` class docblock: *"and signatures are not checked — both stay CLI features, matching Verify."* Its gates are encryption refusal, scope refusal, and a checksum-only `verify_gate()`. |
| Admin Verify checks no signature — and says so | `src/Admin/VerifyController.php` class docblock: *"encryption and signatures stay CLI features"* |
| Admin Verify does not even *report* signature presence | Its success payload carries `sound / entries / message / proof{entries,size,scope,created,format}` — no signature field at all |
| Admin upload checked no signature | `src/Admin/UploadController.php` — zero occurrences of `sign*`; its only gate was `assembled_upload_is_an_archive()`, which read the header and provenance and nothing else |
| Admin rollback checks no signature | `src/Admin/RestoreController::rollback()` — only `verify_gate()` |
| CLI rollback checks no signature | `src/Cli/RollbackCommand.php` — zero occurrences of `sign*` or `public_key`; no `--public-key` parameter exists on the command |
| The admin's archive-facts layer exposes no signature information | `src/Admin/ArchiveFactsReader.php` reads only `provenance()->scope()/url()/timestamp()`; its docblock states *"these facts are presentation, never integrity."* |

So a site that had pinned a key got ADR 0012's guarantee at the terminal and
nothing whatsoever in the browser.

### Why that gap is worse than it first looks

`src/Admin/UploadController.php` exists for one reason, stated in its own
docblock: *"receives a foreign backup uploaded in chunks … Restoring a backup
taken on a different server first needs that backup on this one."* It is the
no-shell-access way to bring an archive from somewhere else onto this site. It
is, in other words, precisely the point at which an archive of unknown origin
enters the system — and it was the surface with no check. The one place the
admin UI is *designed* to accept a stranger's bytes was the one place nothing
verified who produced them.

### Two documents described software we did not ship

**The format specification.** `docs/archive-format.md` stated the reader
obligation as an unconditional MUST in **three** places, not one: §11, §12, and
§12.1 step 7. Nothing in any of the three scoped the obligation to a
command-line reader. On a site that pins the constant, the shipped admin UI was
a **non-conforming reader of Pontifex's own published format** — while "the
format is publicly documented, so a backup is never hostage to the plugin" is
one of the project's two headline promises. A spec that the reference
implementation quietly disregards devalues the promise more than the missing
check devalues the security.

**The threat model.** `docs/threat-model.md`, under *hosting-provider
compromise*, told operators that they *"should rely on signed archives … and
verify signatures against a key not stored on the host."* An operator who took
that advice, pinned their key, and then used the Restore screen received no
verification and — worse — no warning that none had happened. The advice is
sound; the software did not honour it on the surface most operators use.

## Why the obvious fix is the dangerous one

The obvious response is full parity: make admin Restore, admin Verify and
rollback enforce exactly as the CLI does. That fix would break the product for
the operators it is meant to protect, because **every archive Pontifex's own
admin and scheduler can produce is unsigned**:

| Producer | Signing argument | Evidence |
|---|---|---|
| Admin Backup screen | `null` | `src/Admin/BackupController.php:425` — `new ExportOptions( $path, null, null, null, Scope::content_only( … ) )` |
| Scheduled backups | `null` | `src/Schedule/ScheduledExporter.php:145` — same shape |
| Pre-import safety archives | `null` | `src/Rollback/SafetyArchiver.php:188` — `new ExportOptions( $path, null, null, null, $scope )`, unconditional, no signing branch in the file |

`ExportOptions`' third positional parameter is `?SigningContext $signing`. The
only production code that ever builds a non-null one is
`src/Cli/ExportCommand.php:481`, behind `--sign`/`--signing-key`. Signing is
reachable from the CLI and nowhere else.

Applied naively on a site that pins a key, parity would therefore:

- report **every** Backup-screen and scheduled backup as BROKEN in admin Verify;
- **refuse to restore** every backup the site made of itself; and
- if extended to rollback, **refuse the automatic post-failure recovery** —
  converting a failed restore into an unrecoverable one, because the safety
  archive taken moments earlier by `SafetyArchiver` is itself unsigned.

That last outcome is the project's recorded worst failure mode: a tightening
that locks an operator out of their own recovery. It is the reason CLI rollback
was already left exempt, and the reason this ADR treats rollback as untouchable.

Nor can the admin sign its own output to escape the problem. A pinned
`PONTIFEX_PUBLIC_KEY` is the **public** half only; signing needs the secret key
resident on the host, which defeats the exact threat — host compromise — that
the signature exists to defend against, and contradicts the threat model's own
instruction to keep the key off the host.

*(One correction to a common assumption while we are here: the resumable export
path signs correctly. `ResumableExportRunner` threads the signing context through
every tick, persists a `signed` flag on the job payload (`:155`), and refuses a
mid-job mismatch loudly (`:189`); `IncrementalArchiveWriter` sets `FLAG_SIGNED`
at `begin()` (`:182`) and appends the signature at `finish()`. The constraint is
that the admin never *asks* for signing — not that the engine cannot deliver it.)*

## Why restore time cannot be the enforcement point

If the admin cannot enforce on everything, the natural next thought is to
enforce selectively: require a signature only for archives that came from
elsewhere. That immediately raises the question this decision turns on — **at
restore time, can the code know that a given `.wpmig` was uploaded rather than
produced locally?**

It cannot. Three independent findings close the door.

**1. An uploaded archive is stored as an ordinary local backup, by construction.**
`BackupStore::finalise_upload()` renames the completed `.part` file into the
**backups** directory using `next_backup_path()` — the very same helper local
backups use — producing the same `pontifex-backup-<UTC>.wpmig` name, with
collisions resolved by advancing the stamp one second. The transient uploads
staging area does not survive finalisation. After `finalise_upload()` returns, a
foreign archive is byte-for-byte an ordinary backup row.

**2. There is no metadata store to consult.** `BackupStore::backups()` is a
`glob()` filtered by `NAME_PATTERN` and sorted. There is no WP option, no sidecar
file, no database table, no index — a search for
`update_option|get_option|add_option` across all of `src/Admin/` returns a single
hit, and it is a comment. The class holds two path strings and has zero
WordPress coupling. Nor can a distinguishing *filename* be introduced
retroactively: `resolve()` rejects anything not matching `NAME_PATTERN`, so both
kinds are gated by the same regex by design.

**3. The only origin signal the archive carries is chosen by whoever wrote it.**
The provenance block records `url` — a single source-site URL from `site_url()`
— and `ArchiveFacts::is_foreign()` compares it against this site's `site_url()`.
That is the entire origin logic in the product, and the codebase already knows
what it is worth (`src/Admin/ArchiveFacts.php:165`):

> it is attacker-influenceable provenance, shown only as inspectable text

Provenance's own integrity hash is **unkeyed** — anyone editing the JSON
recomputes it. And while the provenance block *is* inside the Ed25519 signed
range, that proves only that some holder of key K chose those bytes; it never
proves the recorded `url` is true. An attacker sets `url` to this site's URL and
the archive reads as locally produced. Using provenance to decide whether the key
applies would fail **open**, silently, against exactly the attacker the key is
for.

**The conclusion, stated plainly:** an archive's origin can be **observed** at
the moment it arrives, or **inferred** afterwards from evidence the archive
itself supplies. The second is worthless here because every scrap of that
evidence is attacker-chosen, and any side-channel record we might keep instead
either fails open under the same attacker (a sidecar or option row they can
write) or fails closed under ordinary use (a desynchronised index refusing a
legitimate backup — the lockout mode again). Upload time is the only moment the
code *observes* the crossing rather than *guessing* at it afterwards. That is
not a convenience; it is the only sound enforcement point available.

## Decision

**When a trusted public key is pinned on this site, an archive arriving through
`UploadController` MUST carry a valid signature. Archives this site produced
itself are exempt, exactly as CLI rollback already is.**

Concretely:

1. **The trigger in the browser is the pin alone.** Enforcement engages when
   `PONTIFEX_PUBLIC_KEY` is defined, via
   `Environment::is_constant_defined()` / `constant_value()`. There is
   deliberately **no** per-upload key field: ADR 0012's per-run `--public-key`
   flag has no browser analogue, and adding one would reintroduce the
   "forgettable second opt-in" that ADR 0012 rejected. Pinning the constant is
   the operator's declaration; the browser reads it and nothing else.

2. **The check runs at admission**, in the method that already opens the
   assembled `.part` file and constructs an `ArchiveReader`, before
   `store_completed_upload()` is ever called. Four outcomes:

   - **Pin defined, archive unsigned** → refused. The `.part` file is discarded
     via the existing `discard_upload()` path; nothing enters the backups
     directory.
   - **Pin defined, signature does not verify against the pinned key** → refused
     identically.
   - **Pin defined, signature verifies** → accepted, stored as today.
   - **No pin defined** → no check, no change of any kind.

3. **A misconfigured pin fails closed.** `SigningKeys::load_public_key()` throws
   on a missing, unreadable or malformed key file. At upload that refusal is
   surfaced as a **distinct** message — a configuration problem, not "this
   archive is unsigned" — so the operator can fix `wp-config.php` rather than
   blame the archive, and it carries HTTP 500 rather than 422 because the fault
   is in the site, not in the file that was sent. Falling open on a broken pin
   would recreate the precise downgrade ADR 0012 closed.

   The *cause* (a missing file, a malformed key) goes to the Pontifex log and
   **not** into the browser message, which names the constant and points at the
   log. This differs from the CLI, which prints the loader's own message through
   `PathRedactor`, and the difference is deliberate: `PathRedactor` replaces a
   fixed list of prefixes (`ABSPATH`, `WP_CONTENT_DIR`, `HOME`, the temp
   directory, `/root`), and the whole point of the pinned key is that operators
   are told to keep it *off* those paths — a key at `/srv/keys/site.pub` would
   pass through unredacted. A terminal is the operator's own; a browser page gets
   screenshotted into support threads. Logging the cause is both safer and the
   pattern the rest of this controller already uses.

4. **Everything else is explicitly, deliberately unchanged**, and this ADR is the
   record of why:
   - Admin Restore, admin Verify and admin rollback do not gain a signature
     check. Their inputs include the site's own unsigned backups and unsigned
     safety archives; enforcing there is the lockout described above.
   - Locally-produced backups (`BackupController`), scheduled backups
     (`ScheduledExporter`) and safety archives (`SafetyArchiver`) stay unsigned.
   - CLI rollback stays exempt, unchanged.
   - CLI import and verify keep ADR 0012 exactly as it stands.

5. **`Environment` is plumbed into `UploadController`.** Its constructor took
   `WordPressContext, BackupStore, LoggerInterface, ?callable` and had no way to
   read a constant. The change follows an established local convention:
   `Environment` is already the **first** parameter of all three sibling
   controllers, and `AdminBootstrap::create()` already constructs a
   `RealEnvironment` and hands it to `BackupController`, `VerifyController` and
   `RestoreController`; `UploadController` was the sole omission. So:
   `Environment $environment` becomes the first constructor parameter, and
   `AdminBootstrap::create()` passes it. Two test factories construct the class
   and were updated with it (`tests/Unit/Admin/UploadControllerTest.php` and
   `tests/Unit/Admin/AdminBootstrapTest.php`); there is no other call site.

6. **`SigningKeys` is reused from `Pontifex\Cli` rather than moved.** Reading the
   key file from `src/Admin/` is a real layering wrinkle — `Pontifex\Cli` should
   not be a dependency of `Pontifex\Admin` — and there are three ways to resolve
   it. **We take the third.**
   - *Move it to a neutral namespace* (`Pontifex\Archive\Crypto\SigningKeys`, next
     to `SigningContext` and `SigningKeypair`). Architecturally correct, but it
     renames a public class and touches five call sites for a cosmetic gain, and
     the class docblock is explicit that the key-file format *"is a CLI/tool
     convenience, not part of the archive format"* — moving it into
     `Archive\Crypto` would state the opposite of what is true.
   - *Duplicate the loader in `src/Admin/`.* Rejected outright: two parsers for
     one security-critical file format is how they drift.
   - **Reuse `Pontifex\Cli\SigningKeys::load_public_key()` as-is, and record the
     wrinkle here.** It is a `final class` of pure statics with no WP-CLI
     dependency in the loading path — importing it costs nothing at runtime and
     creates no cycle. The namespace is a historical accident of where the first
     caller lived, not a layer boundary being violated. **If a second admin-side
     consumer ever appears, that is the trigger to do the move properly** — one
     caller is a wrinkle, two is a pattern. Recorded so the next reader does not
     have to re-derive it. The wrinkle is also noted in the code, at the method
     that imports it. *(Rule-6 note: the move is deliberately out of scope
     for this slice.)*

7. **The refusal message must distinguish the two failures.** The admission gate
   previously returned a bare boolean and the caller sent one message for every
   refusal: *"That file is not a Pontifex backup, so it was not stored."* For a
   signature failure that is actively misleading — the file **is** a valid
   Pontifex backup; it is the trust decision that failed, and telling the operator
   otherwise sends them to debug the wrong thing. The gate therefore returns the
   refusal itself (message and HTTP status) or null for "admit this", and the
   signature case gets its own wording, which names the setting
   (`PONTIFEX_PUBLIC_KEY`) and both remedies: upload a backup signed with the
   trusted key, or remove the pin. The message reaches the operator unmodified —
   the upload script renders `data.message` inline — so it must be
   self-explanatory, in the register ADR 0012 established for the CLI refusal.

8. **The final request lifts its time limit, best-effort, only when the check
   runs.** Verifying a signature streams the whole archive to recompute its
   SHA-256 (1 MiB chunks — memory-bounded, but O(archive size) in I/O).
   `UploadController` was the only long-running admin controller without
   `@set_time_limit( 0 )`; it gains the same guarded call the other three carry,
   placed **inside** the enforcement branch so a site with no pin executes exactly
   the code it executed before.

9. **The specification is amended so the document is true** — see below. The
   amendment permits the scoping this decision takes, forbids the unsound
   version of it, and does not weaken what the CLI enforces.

### The specification amendment

Three occurrences state the MUST; all three are updated. The substantive wording
goes in **§12.1 step 7**, which the other two now reference.

**§12.1 step 7 becomes:**

> 7. **Signature enforcement:** when the operator has supplied or pinned a
>    trusted public key **that applies to this archive**, the archive MUST be
>    signed and the Ed25519 signature MUST verify — recomputing the SHA-256 of
>    bytes [0 … end of footer] and checking it against that key; an unsigned
>    archive is refused as presumed-tampered (a stripped signature is
>    indistinguishable from never-signed). A reader MAY limit the key's reach to
>    archives **admitted from outside its own trust boundary** — those it did not
>    itself produce — provided that (a) the distinction is drawn from the
>    *channel through which the archive arrived*, observed at the moment of
>    arrival, and never from any claim the archive makes about itself, since
>    provenance is chosen by whoever wrote the archive and MUST NOT be used to
>    decide whether the key applies; (b) the reader refuses at the point of
>    admission rather than deferring to restore time; and (c) the scope is
>    documented, so an operator knows which of their archives the key protects.
>    With no trusted key, a signed archive's signature goes unverified and the
>    reader should say so.

**§11 and §12 each gain, after their existing MUST sentence:**

> A reader MAY limit the key's reach to archives admitted from outside its own
> trust boundary, on the terms set out in §12.1 step 7.

**§13.2.4 gains one conformance invariant**, so the permission carries an
obligation rather than becoming a loophole:

> - Readers that limit signature enforcement to archives admitted from outside
>   their own trust boundary must decide that scope from the channel of arrival
>   observed at admission time, never from the archive's own provenance, and must
>   refuse at admission rather than at restore (§12.1, ADR 0020).

Two checks on this amendment:

- **It does not weaken the CLI.** `MAY limit` is permissive. `wp pontifex import`
  and `wp pontifex verify` apply the key to every archive they are pointed at,
  which remains fully conforming — a reader that scopes nothing is a reader that
  scoped to everything.
- **It is not a format-breaking change.** §13.2.4 (conformance invariants) did
  **not** previously list signature enforcement among the frozen reader
  obligations, so the wording is amendable within v1.x without a version bump.

One point deserves explicit handling because a careful reader will raise it.
§13.2.2 locks *"the verification order on import (header → provenance → footer →
manifest → entries → signature)"* as a cryptographic invariant, on the grounds
that reordering creates exploitable race conditions. Checking the signature at
upload does **not** reorder that flow: an upload is not an import, nothing is
restored, and when the archive is later restored the import sequence runs
unchanged and in order. The upload check is an *additional, earlier* admission
gate, and it is safe to run early precisely because it trusts no parsed
structure — `signed_digest()` streams raw bytes from offset 0 and needs only the
stream length and the fixed signature-block size; it never consults a manifest
offset. The invariant's stated hazard is trusting unverified structural values,
and this check reads none.

## Consequences

### What an operator actually experiences

This is the load-bearing table. Case (a) is the one that must be provably inert.

| Case | Before | After | Verdict |
|---|---|---|---|
| **(a) No key pinned — the overwhelming majority** | Upload validates header + provenance, stores | `is_constant_defined( 'PONTIFEX_PUBLIC_KEY' )` returns false, the check returns immediately; header + provenance validation unchanged; stores | **Identical behaviour**, including the `set_time_limit` lift, which sits inside the enforcement branch. Proven by test, not asserted: the environment double refuses to answer `constant_value` at all, so reaching for the key fails the test. |
| **(b) Key pinned, restoring their own admin-made backup** | Works | Works — admin Restore gains no check, and the archive never passed through `UploadController` | **Unchanged.** No lockout. Rollback and safety-archive recovery equally untouched. |
| **(c) Key pinned, uploading a foreign *signed* archive** | Stored with no verification | Signature verified against the pinned key, then stored | **Improved**, and the operator now has the guarantee the threat model promised. |
| **(d) Key pinned, uploading a foreign *unsigned* archive** | Stored silently; restorable with no warning | Refused at admission with a specific message; `.part` discarded; nothing enters the backups directory | **The hole being closed.** |

Case (d) is a **behaviour change on a user-facing surface**, so it needs 7Duckie's
veto decision before any code is written (hard rule 7), and 7Duckie's own browser
gate before it is committed (working agreement 6).

### Residual risks — what this decision does NOT cover

Recorded honestly, because a security ADR that only lists its wins is not
evidence of anything.

**R1 — Direct filesystem placement bypasses the gate entirely. Rated MEDIUM.**
An attacker who can write a `.wpmig` into `wp-content/pontifex/backups` by any
other means — an arbitrary-file-write flaw in another plugin, stolen SFTP or
control-panel credentials, a compromised storage sync — never touches
`UploadController`. The archive appears in the Restore list and restores with no
signature check, on a site that pinned a key. `UploadController`'s own docblock
already concedes the path exists: *"A backup copied straight into that directory
by other means already appears there; this is the no-shell-access way to do the
same thing."*

  Why MEDIUM rather than HIGH: it requires filesystem write access, a materially
  higher bar than "the operator was sent a malicious backup", and an attacker
  with *code*-write access defeats any in-plugin check whatever, which is already
  excluded by the threat model's *WordPress core or PHP runtime compromise* and
  *hosting-provider compromise* clauses. Why not LOW: the backups directory must
  be writable by the web user (the plugin writes there), so file-write without
  code-write is a real and reachable intermediate position — and it lands inside
  the very gap `docs/threat-model.md` previously promised signatures would cover.
  **The threat-model wording is tightened in the same slice** to say what is
  true: signatures are enforced on the CLI, and in the browser on the upload
  path; an archive planted directly in the backups directory is not covered.

**R2 — The check is admission-time, not restore-time (TOCTOU). Rated MEDIUM,
same attacker as R1.** An archive verified at upload may sit in the backups
directory for weeks. An attacker with file-write can modify it after the check;
the later restore re-validates unkeyed hashes only, which they can recompute. So
even for uploaded archives this is *admission control*, not a restore-time
guarantee, and this ADR should not be read as claiming otherwise. It is the
unavoidable price of the finding above: the restore-time enforcement that would
close R2 is exactly what cannot be built, because at restore time the origin
needed to scope it is unknowable. The operator's mitigation is to run
`wp pontifex verify --public-key` immediately before restoring; the threat model
now says so.

**R3 — A pre-existing inconsistency this ADR does not resolve.** With a pin
defined, `wp pontifex verify` **already** returns BROKEN for the site's own
admin-made backups, because they are unsigned and the CLI applies the key to
everything. That is ADR 0012 behaviour, unchanged here, but a pinned-key
operator can hit it today and be alarmed by a correct-but-unhelpful verdict on a
perfectly good backup. Resolving it means either signing admin output
(impossible — R4) or scoping the CLI's key the same way (impossible — the CLI has
no admission event to observe). Recorded as known and deliberately unfixed.

**R4 — Admin-produced archives remain unsigned and therefore unverifiable by
anyone, including their owner.** Nothing here changes that, and it cannot be
changed without a secret key on the host. If offline signing of existing archives
is ever wanted, that is a separate decision and a separate ADR.

**R5 — Upload finalisation becomes O(archive size).** Only when a pin is defined,
and bounded in memory (1 MiB streaming chunks), but a multi-gigabyte archive adds
a full SHA-256 pass to the final chunk request. Mitigated by the `set_time_limit`
lift in decision 8, and proportionate — the upload itself already transferred
every one of those bytes over HTTP, which costs far more than reading them back
from local disk. It must nonetheless be measured on a large archive under a real
web `max_execution_time`, not under CLI's unlimited budget (working agreement 6;
this is the exact shape of the v0.5.0 memory fatal).

### Positive consequences

- The shipped admin becomes a **conforming reader** of the published format on
  the path where conformance is meaningful, and the spec stops describing
  software that does not exist.
- The threat model's advice becomes actionable in the browser for the delivery
  path a non-shell operator actually uses.
- **No new lockout risk.** Every recovery path — admin Restore of local backups,
  admin rollback, CLI rollback, safety-archive recovery — is untouched by
  construction, not by careful ordering that a later change could disturb.
- The enforcement point is the same one the code already uses to decide whether
  a file is an archive at all, so there is one admission gate, not two.

### Costs

- Signature enforcement is now expressed in **two** places with different scopes
  (CLI: every archive; admin: admitted archives only). The spec amendment and
  this ADR are what stop that becoming folklore.
- `Pontifex\Admin` gains an import from `Pontifex\Cli` (decision 6), with a
  recorded trigger for repaying it.
- A pinned-key site can now be refused an upload it would previously have
  accepted. That is the point, but it is a support burden, so the message
  carries the remedy.

## Alternatives considered

- **Full parity — enforce in admin Restore, Verify and rollback.** Rejected: on
  a pinned-key site it reports every locally-made backup as BROKEN, refuses to
  restore them, and (via rollback) refuses the automatic post-failure recovery,
  turning a failed restore into an unrecoverable one. Evidence:
  `BackupController.php:425`, `ScheduledExporter.php:145`,
  `SafetyArchiver.php:188` all pass a null signing context.

- **Sign admin-produced backups so parity becomes safe.** Rejected: the pin is
  the public half only; signing requires a secret key resident on the host,
  defeating the threat the signature defends against and contradicting the threat
  model, which tells operators to keep the key off the host.

- **Decide "is this foreign?" at restore time from the archive's provenance
  `url`.** Rejected as unsound, and this is the important rejection. Provenance
  is chosen by whoever wrote the archive; its own hash is unkeyed; an attacker
  sets `url` to this site's URL and the archive is classified as locally
  produced. The control would fail **open** against precisely the attacker it
  exists for. The codebase already labels the field *"attacker-influenceable"*.
  The spec amendment above forbids this approach explicitly so no future
  implementation reaches for it.

- **Write a sidecar file marking each archive's origin.** Rejected. Marking
  *foreign* fails open — an attacker deletes the sidecar and the archive is
  treated as local. Marking *local* fails open too, one step later — the same
  attacker writes the marker. Authenticating the marker needs a site secret,
  which the host-compromise attacker reads. The construction is circular.

- **Keep an index of locally-produced archives in a WP option, keyed by content
  hash.** Rejected, though it is the strongest of the rejected options: it does
  resist a file-write-only attacker. But it adds durable state that `BackupStore`
  deliberately does not have (a pure directory scan with zero WordPress
  coupling), and it desynchronises under entirely ordinary use — archives copied
  in by hand, a site migrated, the option lost with the database. Every desync
  fails **closed** on a legitimate backup, which is the lockout mode this ADR
  exists to avoid. Trading a certain usability failure for a partial security
  gain against an attacker who mostly also has database access is the wrong
  trade.

- **Warn in the admin instead of refusing.** Rejected on ADR 0012's own
  reasoning: a trust decision must be enforced by the machine, not re-made by a
  human on every run. A dismissible browser notice is weaker than the `--yes`
  scroll-past that argument was originally aimed at.

- **Add a per-upload "trusted key" field to the Restore screen.** Rejected: it
  reintroduces the forgettable second opt-in ADR 0012 removed, and asks the
  operator to hand a trust anchor to the very host the anchor is meant to
  distrust. The pin is made once, in configuration, and that is the right shape.

- **Do nothing and amend only the specification to say "CLI only".** Rejected.
  It would make the document true at the cost of publishing that the plugin's
  main surface ignores its own trust anchor, on the one path built for foreign
  archives. Weakening the spec to match the weaker implementation is the wrong
  direction when closing the gap is this cheap.

- **Render the key-loader's own error message in the browser, redacted through
  `PathRedactor`, as the CLI does.** Rejected during implementation, see
  decision 3: `PathRedactor` knows a fixed set of prefixes, and a pinned key is
  meant to live outside all of them, so an absolute server path could reach a web
  page unredacted. The cause is logged instead.

## Testing this must pass before it is trusted

Three gates green across PHP 8.2–8.5, plus the following. Items 1–5 are unit
tests in `tests/Unit/Admin/UploadControllerTest.php`; item 7 is 7Duckie's, per
working agreement 6.

1. **The inert case (a), proven not asserted.** With
   `is_constant_defined( 'PONTIFEX_PUBLIC_KEY' )` false, every pre-existing
   `UploadControllerTest` case passes unmodified, and the environment double
   asserts `constant_value` is never called at all.
2. **The four decision outcomes.** Pin + unsigned → refused; pin + signed with
   the pinned key → stored; pin + signed with a *different* key → refused; pin +
   valid signature → stored. Fixtures built with the real
   `ArchiveWriter`/`SigningContext` and real generated Ed25519 keypairs, not
   hand-rolled bytes (the round-trip-must-drive-the-real-engine rule), and the
   trusted key is read back out of a real key file written by
   `SigningKeys::write_keypair()`.
3. **Refusal is clean and complete.** For every refusal the backups directory is
   left **empty**, proving the check precedes `finalise_upload()`.
4. **The misconfigured pin.** A pin naming a missing file refuses with the
   *configuration* message and HTTP 500 rather than the *unsigned* message, and
   the rendered string contains no absolute path.
5. **The messages stay distinct.** A file that is not an archive at all keeps the
   original "not a Pontifex backup" wording even with a key pinned.
6. **Integration + conformance.** The real-MySQL suite green; and
   `ConformanceTest` against `tests/Fixtures/conformance-v1_1.wpmig` still
   byte-identical, proving the spec amendment is prose-only and moved no bytes.
7. **7Duckie's browser gate — the real end-to-end pass.** In `wp-env` (dev,
   `localhost:8910`):

   ```
   # generate a keypair and pin the public half
   npx @wordpress/env run cli wp pontifex keygen --secret=/tmp/k.key --public=/tmp/k.pub
   # add to wp-config.php:  define( 'PONTIFEX_PUBLIC_KEY', '/tmp/k.pub' );

   # produce one SIGNED and one UNSIGNED archive to upload
   npx @wordpress/env run cli wp pontifex export --output=/tmp/signed.wpmig --sign --signing-key=/tmp/k.key --yes
   npx @wordpress/env run cli wp pontifex export --output=/tmp/unsigned.wpmig --yes
   ```

   Then, in the browser, with the pin **defined**:
   - Restore screen → upload `unsigned.wpmig`. **Pass:** refused inline with a
     message that says the signature is the problem and names the remedy; the
     backups list does **not** gain a row; no stray `.part` file remains.
   - Upload `signed.wpmig`. **Pass:** accepted and listed as normal.
   - Point the pin at a path that does not exist and upload again. **Pass:**
     refused with the *configuration* message, not the unsigned one.
   - Run a Backup from the Backup screen, then Restore it. **Pass:** both work
     untouched — this is the lockout check, and it is the one that matters most.
   - Trigger a restore failure and let rollback run. **Pass:** recovery completes.

   Then comment the pin **out** and repeat the first upload. **Pass:**
   `unsigned.wpmig` uploads exactly as it does today.

8. **Scale check under a real constraint (R5).** Upload a large archive with the
   pin defined, under a forced web `max_execution_time` (30s), never under CLI's
   unlimited budget. **Pass:** finalisation completes, or fails loudly and
   cleanly with the `.part` discarded — never a half-stored archive.

9. **Plugin Check on the built package: zero errors and zero warnings**, per
   standing policy.

---

## Notes for 7Duckie — verification record

Everything above was read from the checkout at the time of writing. Four
corrections and one addition to the brief as given:

- **The spec's unconditional MUST appears in three places, not one.** §12.1 step 7
  is the one named in the brief, but §11 and §12 state it too. All three needed
  amending or the document stayed self-contradictory.
- **In the browser the trigger is the pin only.** ADR 0012's "supplied *or*
  pinned" has no browser half — there is no `--public-key` analogue in the admin,
  and this ADR argues against adding one. The decision is worded accordingly.
- **The resumable export path signs correctly** — it threads the signing context
  through every tick and refuses a mid-job mismatch. Worth knowing, since a
  reasonable person might assume the constraint extends there. It does not.
- **§13.2.2 locks the import verification order** as a cryptographic invariant.
  It does not block this decision — an upload is not an import, and
  `signed_digest()` trusts no parsed structure — but the ADR addresses it head-on
  because a reviewer will ask.
- **§13.2.4 does *not* list signature enforcement**, so the spec amendment is
  within-v1.x prose and needs no format version bump. That was worth confirming
  before proposing the wording.

Could **not** be verified from the code, and is stated as judgement rather than
fact: the brief's *"it has shipped three times before"* for the lockout failure
mode. The failure mode itself is real and well-evidenced in the design (CLI
rollback's exemption, ADR 0005's retention floor), but no record fixes the count
at three, so the ADR argues the risk without citing a tally.
