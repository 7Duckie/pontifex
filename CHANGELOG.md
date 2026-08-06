# Changelog

All notable changes to Pontifex are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Public API stability begins at v1.0.0. Pre-v1.0 releases (v0.x.y) may
introduce breaking changes between minor versions, though the project
holds itself to a stricter "public-API stability across commits within
a release" discipline — see
[ADR 0001](docs/adr/0001-wordpress-context-abstraction.md) and the
v0.0.x decision log for the reasoning.

## [Unreleased]

### Fixed

- **A failed `wp pontifex import`, `export` or `rollback` showed a PHP stack
  trace and told you your website had a critical error.** Every failure — a
  truncated archive, a server with no room, a mistyped flag — was re-thrown out
  of the command and landed in WordPress's own fatal handler, which printed the
  internal call stack, leaked the server's absolute directory paths, and
  finished with "There has been a critical error on this website." That
  sentence says Pontifex is broken, when what had usually happened is that
  Pontifex spotted something wrong and stopped on purpose. All three now report
  what happened in one readable verdict and say which of the three kinds of
  refusal it was — the archive cannot be trusted, this host cannot comply, or
  the request needs correcting — with server paths replaced by placeholders,
  exactly as `wp pontifex verify` already did. The log entry, the failure
  counters, the transfer history and import's automatic rollback from the
  safety archive all continue as before.

- **A failed backup did not tell you whether you had one.** `wp pontifex
  export` now says what it left behind, because the answer is not always the
  same: an ordinary export leaves nothing at the output path, so any file
  sitting there is an older backup it never replaced — exactly the file you
  could otherwise mistake for the one you just tried to take. An archive that
  was finished before something later went wrong is a real, usable backup
  already on the server. And a **resumable** export that fails says plainly
  that `--resume` will not pick it up — `--resume` continues a run that was
  interrupted, not one that failed — then names the part-written `.part` file
  it left behind, if it left one, so a large orphan does not sit on the disk
  unmentioned.

- **A rollback that stopped partway said nothing about the state it left the
  site in.** `wp pontifex rollback` now says how many entries it had restored
  before it stopped, because a replay that halts midway leaves some of the site
  as the safety archive's copy and the rest as the import left it, and nothing
  reconciles the two on its own. It is said only when entries really were
  written; a refusal that touched nothing does not raise a false alarm.

  The exit code for all three is unchanged — `1`, exactly as before — so
  scripts and scheduled jobs behave as they always did. A PHP fatal exits `255`
  on its own, but under WP-CLI these never reached that: WordPress's shutdown
  handler caught the fatal, printed the critical-error sentence, and exited `1`.
  All that changes is what is printed on the way out.

## [1.1.0] — 2026-08-06 — Verify stops promising what a restore will not honour

A minor release rather than a patch: no public API moved — not an interface, a
constructor, a signature or a return type — but `verify` gains an outcome, so a
script that checks its exit code can now see a failure it could not see before.
No breaking changes.

### Fixed

- **Verifying a backup no longer tells you it is fine when a restore will
  refuse it.** Verifying recalculated a fingerprint for every file and reported
  the backup **sound**. Restoring settled four further questions that verifying
  never asked — so a backup engineered to place a symbolic link outside your
  site, which by its nature has perfectly valid fingerprints, was reported sound
  and then refused by the very restore the check was meant to vouch for. The
  person misled by this was the one checking their backups. Verifying now runs
  every check that changes nothing, and answers with one of three verdicts
  rather than two. See [ADR 0023](docs/adr/0023-verify-and-restorability.md).
- **"Refused" is now its own answer, separate from "broken".** A refused backup
  is **not damaged** — every fingerprint matched — but a restore will not accept
  it. The two call for opposite actions: broken sends you to delete the file and
  find another copy, whereas a refused backup should be kept, not restored, and
  traced, because Pontifex never produces one.
- **A server that cannot restore is reported beside the verdict, never as the
  verdict.** A full disk is not a damaged backup, and saying otherwise is the
  one message capable of talking somebody out of a good backup.
- **A failed command explains itself instead of printing a crash.** `import`,
  `export` and `rollback` each ended a failure by re-raising it into WordPress's
  fatal handler, so what you actually saw was a page of PHP internals followed
  by "There has been a critical error on this website." All three now say what
  went wrong, in which of three categories, and what to do about it.
- **Messages no longer name Pontifex's own internal parts.** 523 of them began
  with the name of the code that raised them — "FileWriter: could not create
  directory" — which means nothing to anybody who does not work on Pontifex.
  That information now goes to the log instead, which records the exact file and
  line, so support diagnosis got better while the text you read got clearer.

### Changed

- **`wp pontifex import --dry-run` is now a real rehearsal.** It ran the
  integrity check and nothing else, which meant it ran *fewer* checks than the
  import it claimed to be rehearsing. It now runs every one of them, including
  the one that briefly creates and removes a test symbolic link to find out
  whether this server can — the single check a plain verify cannot make, because
  verifying never writes anything at all.
- **The admin Restore screen refuses a bad backup before making the safety
  copy**, not after. On a large site that copy is minutes of work and a second
  full copy of the site on disk, all of it previously spent to arrive at a
  refusal that was knowable up front.

### Internal

- `actions/upload-artifact` 4.6.2 → 7.0.1.

## [1.0.3] — 2026-08-05 — Exclusion patterns are kept as you typed them

Closes the last of the findings from the full-build audit that produced
v1.0.1 and v1.0.2. One of them was quietly leaving files out of backups. No
breaking changes.

### Fixed

- **The Backup screen silently rewrote your exclusion patterns, and left
  out more than you asked it to.** Anything you typed into "Also leave out"
  was passed through a WordPress text sanitiser that strips every percent
  sign followed by two hex digits — a sensible thing to do to a web address,
  and the wrong thing entirely to do to a file path, where those are just
  characters in a name. So `wp-content/uploads/2024%2F*` became
  `wp-content/uploads/2024*`, which excludes vastly more than the pattern you
  wrote, and the files it swallowed were missing from every backup after
  that. A scheduled backup stores the pattern, so the rewritten version was
  reused by every unattended run from then on, and nothing anywhere said so.
  Patterns are now kept exactly as typed. **If you use exclusion patterns
  containing a percent sign, open the Backup screen and check they still read
  the way you wrote them**, then take a fresh backup.
- **Automatic pruning of an offsite destination now says when a deletion
  failed.** Pruning reported how many old backups it removed but nothing at
  all about the ones it could not — so a destination quietly filling up,
  because the server was refusing deletions, looked exactly like a
  destination being kept tidy. Failures are now recorded in the log.

### Internal

- The WordPress installation root that a restore confines every write
  against — the value every path check downstream is measured from — existed
  as six identical copies. None had drifted, but nothing would have noticed
  if one had. It has one home now.
- The release check additionally verifies the two readme headers that are
  hard errors in the directory's own validator and were checked nowhere, and
  the coding-standards configuration now enables the input-sanitisation
  sniff that the directory's tooling enforces and the previous local
  configuration omitted — a finding of that kind could previously only
  surface at a release gate, on work every local gate had already called
  clean.

## [1.0.2] — 2026-08-05 — The rest of what the audit found

Closes the remaining findings from the same full-build audit that produced
v1.0.1. Two of them could lose or damage something you care about, and one
made the admin screens unusable on an entire platform. No breaking changes.

### Fixed

- **Automatic pruning could delete the newest backup on an offsite
  destination.** Pontifex decides which offsite copies to keep by sorting
  their names, which is oldest-first only for the names Pontifex generates
  itself. A backup you named yourself — `wp pontifex export
  --output=/backups/before-upgrade.wpmig --destination=nas`, say — joined
  that rotation, sorted ahead of every dated name, and was deleted as "the
  oldest": the backup taken minutes earlier, precisely so it would be there
  if the upgrade went wrong. Pruning now only ever touches backups Pontifex
  named, and leaves anything else alone.
- **A restore stopped part-way through when the backup contained a
  read-only directory.** Locking down a directory such as
  `wp-content/uploads/private` is a documented WordPress hardening step.
  Pontifex recreated that directory with its recorded permissions before
  writing the files inside it, so the very next file could not be written
  and the restore stopped — at the one point where there is nothing to undo,
  leaving a site that was neither the old one nor the backup. Directory
  permissions are now applied once everything is in place.
- **The admin backup screen did not work on Windows.** Downloading,
  deleting, verifying or restoring a backup all reported that it could not
  be found — for backups listed on the screen in front of you — because the
  path check assumed a forward slash. Creating a backup still worked, so a
  Windows site produced backups it then refused to touch.
- **Several screens overstated what a backup contains.** The Backup screen
  said a backup packs "every file and the whole database" and then
  contradicted itself three lines later. A backup covers your content in
  `wp-content`, minus the exclusions shown, and the database tables
  belonging to this WordPress site — **tables another application keeps in
  the same database are not included**, which matters if your site shares a
  database with something else.

### Changed

- **Pontifex can now say which kind of problem it hit.** Until now every
  failure arrived as the same shape, so the admin screens could only offer
  one generic sentence whether your archive was corrupt or your disk was
  simply full. Refusals now carry that distinction — the archive cannot be
  trusted, this host cannot do it, or the request was wrong — which is what
  lets the screens tell you something useful. See
  [ADR 0022](docs/adr/0022-exception-taxonomy.md).

### Internal

- The release check that enforces the WordPress.org submission limits now
  runs in CI rather than depending on somebody remembering it; the tag guard
  additionally requires the tagged commit to be on `main`; the package CI
  verifies is published rather than discarded; every workflow action is
  pinned to an exact commit; and every job has a timeout.
- Guards that could previously be deleted with the whole suite still passing
  are now pinned by tests, including signature enforcement, both entry-hash
  comparisons, and a completeness check that compares a real directory tree
  against the archive built from it.
- Two facts that several files each kept their own copy of — the backup
  progress contract and the export counter schema — now have one home each.

## [1.0.1] — 2026-08-05 — Three defects a full-build audit found

A security and correctness release. An audit of the released v1.0.0 build,
which had passed every quality gate this project has, found three defects —
each proven by building a hostile archive or driving a real restore rather
than by reading the code. **If you restore archives you did not create
yourself, or you migrate sites, upgrade before you next do either.**

No breaking changes. Every v1.0.0 archive restores unchanged.

### Fixed

- **An archive could disguise a symbolic link as an ordinary file and slip
  past the check that keeps links inside your site.** An entry's kind is
  recorded in two places, and nothing compared them: the safety check that
  decides whether a link is allowed read one copy, and the code that
  actually creates the link read the other. Changing two bytes of the
  index — leaving the entry and its fingerprint untouched, so every
  integrity check still passed and verification still reported the archive
  sound — was enough to have a link created that pointed anywhere the
  server could read, including `wp-config.php`. Restoring now refuses any
  archive whose index contradicts its own contents.
- **A restore altered content that mentioned its own database table.** While
  putting your database back, Pontifex renames the table it is working on,
  and that rename was applied to the whole of a chunk rather than only to
  the table name — so a post, a stored query, or any other value containing
  a table name in backticks came back with `pontifexstg_` written into it.
  Permanently: the change survived the restore finishing. Where that text
  sat inside a stored settings array, the array stopped being readable at
  all and the plugin owning it silently reverted to its defaults. Nothing
  reported any of this — hashes matched and verification said sound — so it
  surfaced days later as settings that had vanished after a migration.
- **Verification did not check the archive's index against its contents
  unless it happened to be given a memory budget.** So verification could
  report an archive sound that restoring would then refuse. That check now
  always runs: a verdict you use to decide whether to trust a backup should
  not depend on how the check was invoked.

### Changed

- **Pontifex now refuses to load on WordPress multisite.** It has no
  multisite handling: it reads a single table prefix, and a network has
  more than one, so it would have backed up part of a network while
  appearing to back up all of it — a backup that looks correct until the
  day you need it. It now stops with a notice explaining why. Multisite
  support remains a planned feature.

## [1.0.0] — 2026-08-05 — Stable surface

The commitment release. From here the public API is frozen: a breaking change
requires a major version, and the `.wpmig` format specification is **locked at
specification version 1.1** — a v1.1 archive will remain readable by every
future version of Pontifex, and a change the specification cannot accommodate
needs a new major specification version rather than a silent revision of v1.
That commitment is the point of publishing the format at all: it is what makes
"a backup is never hostage to the plugin" enforceable rather than aspirational.

This is also the version submitted to the WordPress.org plugin directory.

There are no breaking changes. Every v0.9.x archive restores unchanged.

### Fixed

- **A restore is refused on a host that could not finish it.** A restore that
  reached a symbolic link on a host where PHP's `symlink()` is unavailable —
  common on shared hosting, where it is often listed in `disable_functions` —
  overwrote every file ahead of that point and then stopped where the link
  should have gone, leaving a site that was neither the old one nor the
  archive's. Every link the archive declares is now resolved to the directory
  it would be created in and tested for real before the first byte is written,
  so the restore is refused with nothing changed rather than abandoned
  part-way. A backup containing no symbolic links is never tested and never
  affected.
- **Documentation that was not true.** The readme told you to encrypt a backup
  with `export --passphrase`, a flag that has never existed, so following it
  produced an unencrypted backup and no error; the real flags are `--encrypt`
  and `--passphrase-stdin`. It said Pontifex never contacts any remote service,
  which stopped being true when offsite SFTP destinations shipped in v0.8.0 —
  Pontifex still runs no service and contacts nothing on its own initiative,
  but a destination you configure yourself is a real exception and is now
  described as one. And it described Pontifex as a WP-CLI plugin, when the
  admin interface has been most of the product since v0.5.0; the installation
  steps now work for someone who never opens a terminal.

### Added

- **`wp pontifex doctor` reports symbolic-link support.** Whether this host can
  create symbolic links (needed to restore an archive containing them) and read
  them (needed to back up a site that has them) is now stated in the readout,
  so you learn it before you need it rather than during a restore. It is
  reported as a warning, never a failure: a site with no symbolic links backs up
  and restores perfectly well without either.

### Changed

- The archive format specification graduates from draft to locked, at
  specification version 1.1. See
  [`docs/archive-format.md`](docs/archive-format.md).
- Development and CI dependencies updated: `actions/setup-node` 6 to 7,
  `@wordpress/env` 11.11.0 to 11.12.0, `wp-phpunit/wp-phpunit` 7.0.1 to 7.0.2.
  None ships in the plugin.

## [0.9.5] — 2026-07-29 — An archive can no longer reach outside the site it restores into

A security release, and the one that finishes what v0.9.4 started. v0.9.4 stopped an
archive's SQL escaping its staging table; this one stops an archive's *files* escaping
the site. **If you ever restore or import an archive you did not create yourself,
upgrade before you do it again.**

### Breaking

- **An uploaded backup must now carry a trusted signature, when a trusted key is
  pinned.** A site that defines `PONTIFEX_PUBLIC_KEY` in `wp-config.php` had that key
  honoured on the command line and ignored entirely in the browser — on the one screen
  that exists so an operator can bring a backup in from another server. Uploading an
  archive that is unsigned, or signed by a different key, is now refused before the file
  is stored.

  This is the browser counterpart of the command-line enforcement shipped in v0.5.0, and
  it is breaking in the same way and for the same reason: a stripped signature is
  indistinguishable from one that was never there, so a warning would be no defence.

  **A site with no key configured is unaffected**, byte for byte — the check returns on
  its first line and does not so much as extend the request's time limit.

  Enforcement sits at upload because nowhere later can know where an archive came from:
  uploaded and locally-produced backups land in the same directory, under the same name
  pattern, through the same helper, and the provenance recorded inside an archive is
  written by whoever made it. Origin is a fact when a file arrives and a claim
  afterwards. Recorded in
  [ADR 0020](docs/adr/0020-signature-enforcement-on-the-upload-path.md).

### Security

- **A restored symlink is confined to the site it is restored into.** A content-only
  restore — the default, and the only mode the admin screens offer — would happily
  create `wp-content/uploads/leak.txt` pointing at `wp-config.php`. Uploads are served
  as static files and web servers follow symlinks, so an unauthenticated request then
  returned the database password and the authentication salts.

  Measured against the real writer before the fix: eight of ten hostile shapes were
  written, and five of them handed back the planted secret when read through. All ten
  are now refused, and the six legitimate layouts tested alongside them — including
  Composer-managed sites whose plugins point at a vendor tree beside `wp-content` —
  still work.

  The check resolves every link the archive declares before the first byte is written,
  following each step the way the kernel does, including through links the archive is
  about to create but has not yet. Resolving the target as a *string* does not work: two
  entries, `hop -> ".."` and then `leak.txt -> "hop/../wp-config.php"`, collapse on paper
  to somewhere harmless while the kernel dereferences `hop` first and lands on the real
  file. Absolute targets, the site root itself, `wp-config.php` and the plugin's own
  working directory are all refused. Two limits are stated rather than implied:
  Unicode look-alike spellings are not covered, and a link declared beneath another
  declared link is caught by the existing write-time check rather than by this one.

- **A forged archive can no longer write into Pontifex's own working directory.** That
  namespace holds the safety archives a restore creates as its undo, the `.htaccess`
  keeping whole-database backups out of web reach, and the files the admin backup list
  is built from — so an archive could disarm the recovery meant to survive it, plant a
  rollback the operator would then choose themselves, or expose every backup on the site
  to an unauthenticated request. No legitimate archive can contain such a path: the
  scanner prunes that namespace structurally, before exclusion rules are consulted.

  Entry paths are normalised once, up front, because the first version of this refusal
  was evadable — `wp-content/./pontifex/.htaccess` failed the string comparison while
  the filesystem resolved it into the protected directory anyway. Case is decided by
  probing the destination filesystem rather than assumed, so the guard holds on macOS
  and Windows without refusing a genuine `wp-content/Pontifex/` directory on Linux.

### Fixed

- **A backup killed outright could not be resumed.** A hard kill runs no cleanup, so the
  job stayed active and the lock stayed held; the lock then refused the only command able
  to finish it, while the error advised "resume it, then retry". Nothing was ever lost —
  the scheduler could always adopt the job — but the documented command did not work. A
  resume now takes the lock from the job it is adopting, and only when there is genuinely
  a stalled backup to adopt.

- **A restore that would run out of disk is stopped before it starts.** A disk filling
  part-way left a site with some files new and some old. The check needs room for the
  largest single file — each is written alongside the original before being swapped in —
  and for however much the incoming files are *larger* than what they replace. Sizing it
  by growth rather than by total is what keeps a rollback working on a nearly-full disk,
  where files are replaced rather than added.

- **The export refuses a backup this installation could not read back.** Past roughly
  74,700 entries the writer produced a manifest larger than its own reader accepts, so
  the backup completed and was then rejected as unreadable. Both export paths now project
  the manifest size from the real entries and refuse before the temporary file is opened.

- **The entry ceiling rises from 50,000 to 100,000.** The old limit refused legitimate
  sites for no benefit; the manifest's own byte cap already bounds an archive first.

- **Opening a large manifest no longer dies without explanation.** Exhausting memory is
  an uncatchable fatal, so a restore that died this way never restored its safety
  archive, never released the lock and never cleaned up its job. The reader now raises
  the limit where the host permits it and refuses with the megabytes named where it does
  not. Proven on a real 18,675-entry archive at 512M, at 64M, and at 64M with `ini_set`
  disabled.

- **A restore is stopped when no readable safety archive can be written.** The undo was
  previously exempted from the size refusal on the grounds that an archive needing more
  memory to open was better than none. That was wrong: the cap is structural, and such an
  archive opens at no memory limit at all — verified at unlimited and with a 64 GB
  budget. It was not a harder-to-open undo; it was no undo, while the restore proceeded
  believing one existed.

- **The scheduler no longer records failures that did not happen.** A signed resumable
  backup cannot be continued by cron, because signing needs a key that is deliberately
  never stored with the job. The scheduler treated the engine's refusal as a failed
  backup and wrote it into the history. It now leaves such a job for the command that
  started it.

- **`verify --list` carries a memory budget**, so listing a large backup's contents
  cannot die without explanation — on the command an operator reaches for when they are
  already worried about a backup.

- **A clean resume tick no longer rewrites the progress log.** Every tick re-read,
  re-encoded and rewrote the whole log to arrive at the bytes already on disk.

### Documentation

- **`PRIVACY.md` now describes what actually happens.** It claimed no outbound network
  requests — untrue since offsite destinations shipped in v0.8.0 — and said all work
  happens "on the machine running WP-CLI", written before the admin screens existed. An
  architecture test now fails the build on any network call outside `src/Destination/`,
  so the promise is enforced rather than asserted.

- **The format specification no longer states a rule the browser did not follow.** Its
  reader obligation appeared in three places that the admin met in none; it now records
  that a reader may scope a trusted key's reach to archives admitted from outside its
  trust boundary, with the provenance route explicitly closed off.

- **The threat model no longer advises a defence the admin did not provide**, and names
  what remains uncovered.

### Development

- The npm toolchain lockfile is refreshed onto patched releases, closing five advisories.
  None of it ships: `.distignore` keeps it out of the built plugin.

## [0.9.4] — 2026-07-28 — Restore refuses an archive's stray SQL

A security release. **If you ever restore or import an archive you did not
create yourself — a backup from another site, a file someone sent you, the
documented cross-server migration — upgrade before you do it again.**

### Security

- **A database chunk's SQL is confined to its own staged table.** Restoring an
  archive replayed the SQL inside it against the live database almost verbatim:
  only the single table identifier a chunk declared was rewritten to its staging
  name, and every other statement ran as written — before the atomic cut-over,
  and outside anything the abort path could undo, because that drops staging
  tables and the damage was not in one. An archive carrying one extra statement
  could set a user's password and take over the destination site's administrator
  account. It needed no unusual database privilege, so it applied to any
  installation.

  Every statement must now begin, byte for byte, with one of a small set of
  shapes composed from the staging identifier the restore built itself — a DROP
  of that table, a CREATE TABLE of that table, or an INSERT into it. Nothing may
  precede those bytes, so there is no comment to strip and no case to fold, and
  therefore none of that to get wrong: reading a statement's leading keyword
  cannot be made safe, because ordinary comments, conditional comments that
  execute, unusual whitespace and a keyword split across a comment all defeat it.

  Once a CREATE has run, and before anything else in that chunk does, the
  database is asked what it actually built — the object's type, its storage
  engine, its create options, the storage directories of any partitions, and its
  row count. A statement can look entirely ordinary and still ask for a table
  that is an alias onto a live one, or whose files are written to a path of the
  archive's choosing, or that is populated from a table it should never read.
  None of that is visible in a statement's opening bytes, and the server knows.

  A semicolon outside a quoted span is refused, so an acceptable-looking
  statement cannot carry a second one behind it. This no longer depends on the
  database driver rejecting multiple statements — a behaviour the code neither
  stated nor controlled.

  **What this does not do:** two ways for an archive to read data it should not
  remain, both inside an INSERT's values — a scalar subquery, and a file read on
  the database server. Neither can be refused by a statement's shape without a
  list of forbidden functions, which is the kind of list that gets worked around.
  What they reach lands in the restored site rather than travelling back to
  whoever supplied the archive. An archive from a source you do not trust still
  should not be restored; this reduces the consequences, it does not remove them.
  Both are recorded in [ADR 0019](docs/adr/0019-db-chunk-statement-containment.md)
  and the threat model.

### Added

- **Files whose media type could not be determined are counted and reported.**
  Determining a file's type can fail four ways — a missing extension on the host,
  an unreadable source, a broken or absent magic database — and every one of them
  produced exactly what a *successful* check produces for a file that genuinely
  has no identifiable type. The two were indistinguishable, so a host-wide
  failure would have recorded every file in every archive as raw bytes with
  nothing anywhere saying so. An export now reports the count, and it is carried
  on every path that can finish one, including the scheduled and admin backups
  that run unattended. A file that is genuinely unidentifiable is not counted as
  a failure, so an ordinary run reports nothing.

### Fixed

- Backups started from the admin screen no longer erase the changed-file tally
  that command-line exports had accumulated in the stored statistics.

## [0.9.3] — 2026-07-28 — Operational hardening

Four pieces of hardening around the operations the admin UI made easy to reach.
One of them, found while adding a new default exclusion, was a silent data-loss
defect in the manifest scanner: **if you run a site deployed from git, or exclude
any directory that happens to be empty, re-take your backups after upgrading.**

### Fixed

- **The manifest scanner silently dropped the entry after an excluded
  directory.** The walk was advanced twice for every excluded directory, so the
  next entry was consumed without ever being tested against the exclusion rules.
  When the excluded directory was empty — as several inside every git checkout
  are — the dropped entry was a real site file, lost with no error and no
  warning, in an archive that still verified as sound because it was internally
  consistent and merely incomplete. Excluded directories are now pruned inside
  the recursive walk, so one is never opened at all.
- **Backup, restore and rollback now share one lock, across both surfaces.**
  They previously took different locks and so did not exclude one another, which
  allowed a restore to replace the database while a backup was reading it; the
  CLI took no lock at all. A CLI export, import or rollback now refuses and exits
  non-zero, naming the operation already running. Verify keeps its own lock,
  since it only reads an archive and cannot clash with work on the live site.
- **The admin lists told the truth about where a backup came from.** An uploaded
  archive showed its upload time and no origin, even when it had been taken on
  another site entirely. Each backup now leads with its true source and real
  creation date, read from the archive's own recorded provenance. Downloads offer
  a friendly source-and-date filename; the file stored on disk keeps its exact
  name.
- **A backup no longer appears to hang as it finishes.** It ended by sitting at
  the complete byte count with zero seconds remaining while the database snapshot
  and the archive's manifest and footer were still being written. The finishing
  phase is now reported, and a phase can no longer travel backwards, which is
  what stopped the bar collapsing to zero at the database boundary.

### Added

- **`.git` is left out of backups by default, at any depth.** A version-control
  history is not site content: it is regenerable from the same remote the working
  copy came from, it can silently rewind a live git-deployed directory when
  restored over it, and it makes an archive carry every secret ever committed
  wherever that archive travels. `.github`, `.gitignore`, `.gitattributes` and
  `.gitkeep` are ordinary content and stay in. `vendor` and `node_modules` are
  deliberately **not** excluded — both are required by a running site, so
  dropping them would produce sites that restore and then fail. Use
  `--no-defaults` to opt out of the curated defaults entirely.

### Changed

- **Backups spend far less time scanning.** Media types are now determined when
  an entry is written rather than when it is scanned, so a resumable export
  sniffs each file once across the whole backup instead of once per request, and
  its final pass — which writes nothing — sniffs nothing. On a tree of roughly
  18,600 entries a scan drops from 7.41 seconds to 0.795.
- An exclusion pattern naming a directory without also matching its children now
  leaves out that whole subtree, rather than omitting the directory while keeping
  its contents. The built-in defaults are unaffected.
- An unreadable file or directory inside an excluded subtree no longer aborts an
  export, because nothing inside an excluded subtree is opened.
- A resumable export that was in progress before this upgrade will refuse to
  resume and must be started again, because the repaired scan correctly finds
  entries the old one skipped.

## [0.9.0] — 2026-07-14 — Admin legibility

The release that makes the engine's trustworthiness and each archive's contents
legible from the admin, without removing a single safety confirmation. A verify
now shows its full proof rather than a one-line verdict; every backup list says
what an archive contains and, for one taken elsewhere, that its links can be
rewritten here; the long-running Verify screen survives a page reload; and two
rough edges — a signed-out restore that froze, and a finished-but-stale backup
that looked like it was still running — are put right. No engine or format
change, and no breaking changes.

### Added

- **A "verified — here's the proof" panel.** A sound verify now shows a persistent,
  plain-language panel — the verdict, the entry count and size, what the archive
  contains, when it was created, and the format version — with the assurance that
  every file and database chunk was re-read and its SHA-256 re-checked, and a link
  to the published `.wpmig` format specification. Typographic throughout: the words
  carry the verdict, never a status colour.
- **What each archive contains, in every list.** The Backup, Restore, and Verify
  lists gain a "Contains" column — content and database, database only, files only,
  or whole site — read fail-soft from each archive's provenance, so a content backup
  is told apart from a database-only one without opening it.
- **The Verify screen re-attaches after a reload.** A verification that is already
  running is picked back up when the page is reloaded, showing live progress and an
  elapsed timer from the run's own start, matching the Backup and Restore screens; a
  dead verifier's lock is reclaimed so it never blocks the next check.
- **A migration hint on the Restore screen.** Selecting a backup recorded as taken
  on another site shows a quiet advisory line that the "rewrite its links" checkbox
  applies to it, from a read-only preview of the archive's provenance. It never ticks
  the box, and it stays silent for encrypted archives, which the admin cannot restore.

### Fixed

- **The Backup screen no longer re-attaches to a finished backup.** A backup that
  died on a fatal — the classic web execution-time kill — left the screen showing
  "a backup is running" until the progress transient's TTL expired. The shutdown
  handler now clears that transient, the progress endpoint reports idle for a stale
  transient with no active job, and the single-runner lock is reclaimable, so a dead
  run never blocks the next backup.
- **A restore that signs you out reports honestly instead of freezing.** A restore
  replaces the users table and so signs the operator out mid-run; the progress bar
  used to freeze while WordPress core's own session modal appeared over the top. The
  screen now recognises the reset from the progress poll, shows an honest in-page
  message — the restore reached its final stage and reset the session, with the
  outcome recorded on the Overview and in the log — and reloads to that outcome once
  the operator logs back in through core's native session modal.
- **The backup list agrees with what can be restored.** A stray or malformed file in
  the backups directory no longer appears as a phantom row that cannot be actioned;
  the list is filtered to the exact names the restore path will accept.

## [0.8.0] — 2026-07-14 — Offsite SFTP destinations

The release that gets a backup off the machine it was made on, without Pontifex
ever becoming a service. A finished archive can be uploaded to an SFTP server the
user already owns — their box, their credentials, on their command — so a lost
disk no longer means a lost backup. It is an outbound connection, never a
phone-home: Pontifex runs no server in the path and holds none of the data. SFTP
is the only destination type this release ships; a planned S3 adapter was deferred
because the pure-PHP S3 library it needed pulls in native extensions, a GPL-3.0
licence, and a cloud-SDK surface, against the project's in-house posture
([ADR 0017](docs/adr/0017-offsite-destination-adapters.md)). No breaking changes.

### Added

- **Offsite SFTP destinations.** `wp pontifex export --destination=<name>` uploads
  the finished archive to a configured SFTP server after writing it locally, in the
  CLI process, which has no web-request timeout, so a large archive is not bound by
  one. The host key is pinned before any credential is sent — an unknown or
  mismatched key is refused unless `--insecure-host-key` is explicitly set — and
  credentials are referenced by environment-variable name, never a flag or
  plaintext ([ADR 0017](docs/adr/0017-offsite-destination-adapters.md)).
- **`wp pontifex destination`** — `add`, `remove`, `list`, `test` (a live
  reachability, authentication, and writability check), `archives`, `pull` (fetch an
  archive back for recovery after a local loss, so a destination is never
  write-only), and `prune`.
- **Per-destination retention.** `--retention=<count>` keeps the newest N archives
  at a destination and prunes the rest — after each successful upload and on demand
  via `destination prune` — ordered by the archive's name, with a floor that can
  never prune a destination down to nothing. A prune failure never fails the export.
- **A destination health check in `wp pontifex doctor`.** For each configured
  destination it reports whether the configuration is complete and its credential
  environment variable is present, without ever connecting to the network; live
  reachability stays the on-demand `destination test`.
- **`phpseclib/phpseclib`** as a runtime dependency (pure PHP, no native extension
  required) for the SFTP transport.

### Fixed

- **Plugin Check runs clean, and stays that way.** Four
  `WordPress.Security.ValidatedSanitizedInput` warnings on the admin schedule-save
  handler were fixed at source, and the CI Plugin Check step now fails the build on
  warnings, not only errors — so the zero-errors-and-zero-warnings policy is enforced
  rather than kept by hand.
- **A translator-comment warning** where four progress bars localised the same
  string with different comments is resolved, so the translation template
  regenerates cleanly.

## [0.7.0] — 2026-07-13 — Selective content

The release that lets a backup carry less than everything, on purpose. A site
can now leave out the parts that do not need backing up — caches, log tables,
anything named — and can capture just its files or just its database, each half
a complete archive restorable on its own. What an archive holds is no longer a
guess: `verify` and the admin Verify screen state it in plain words. Two
booleans on the archive's scope express all four shapes — content, whole-site,
files-only, database-only — decided in
[ADR 0016](docs/adr/0016-partial-scope-backups.md), and every archive already
written stays byte-identical, so nothing on disk changes meaning. No breaking
changes this release.

### Added

- **Inline exclusions.** `wp pontifex export --exclude=<glob,…>` drops matching
  files from a backup and `--exclude-table=<name,…>` drops matching database
  tables, on top of the built-in defaults. The admin Backup screen gains an
  editable exclusions field and an effective-scope display, so a shell-less
  operator sees exactly what the next backup will and will not contain.
- **Files-only and database-only backups.** `wp pontifex export --files-only`
  captures the content tree without the database; `--db-only` captures the whole
  database without the files. Each is a complete, restorable archive of its
  half, and restoring one leaves the other half of the live site untouched. The
  two are mutually exclusive with each other and with `--whole-site`, and a
  restore fails closed on an archive whose recorded scope and manifest
  contradict each other rather than restoring contents the scope denies
  ([ADR 0016](docs/adr/0016-partial-scope-backups.md)).
- **A "This backup contains …" label.** `wp pontifex verify` and the admin
  Verify screen now state in plain words what an archive holds — content and
  database, files only, or database only — read from the archive's own recorded
  scope rather than inferred. `docs/archive-format.md` documents the
  `includes_files` field this reads.

### Changed

- **Scheduled backups inherit the configured exclusions.** A schedule set with
  `wp pontifex schedule set --exclude=<glob,…>` applies those exclusions to
  every unattended run, merged with the defaults; the pre-import safety archive
  deliberately stays on full defaults.
- **The archive scope carries two booleans.** `Scope` now records whether an
  archive includes the database and whether it includes the files, which
  together describe every backup shape. The files flag is serialised only when a
  backup omits the files, so content-only, whole-site, and files-only archives
  are byte-identical to a pre-v0.7.0 archive and every archive already written
  keeps parsing (ADR 0016).

## [0.6.0] — 2026-07-13 — Resumable and scheduled exports

The release that stops a backup depending on one uninterrupted request: an
export that survives being killed and continues where it stopped, and an
export that runs unattended on a schedule. Both are built on one foundation —
a persisted job with an append-only progress log — decided in
[ADR 0014](docs/adr/0014-background-execution-model.md) (WP-Cron plus a
self-continuing step runner; no job-queue library) and
[ADR 0015](docs/adr/0015-resumable-export-mechanics.md) (the resume contract:
the progress log is the truth, every tick steps back to the provably-good
prefix, scan drift is refused, and the database is dumped whole in one
snapshot tick). No breaking changes this release.

### Added

- **Resumable exports.** `wp pontifex export --resumable` writes an export that
  survives a timeout, a lost connection, or a killed process; `wp pontifex
  export --resume` continues it from where it stopped. The archive is built
  incrementally across as many processes as it takes and is byte-identical to
  an uninterrupted one. Encrypted exports deliberately refuse resumable mode —
  the derived key exists for one request and is never persisted.
- **Scheduled exports.** A recurring content-only backup, daily or weekly at an
  hour given in UTC, runs unattended and prunes old scheduled backups down to a
  retention count that can never fall below one. An unattended cron ticker
  continues a job whose driving request died, with a dead-man's switch: it
  lifts the time limit, schedules its successor before working, and fails a
  job loudly rather than looping forever if every attempt dies mid-tick.
- **`wp pontifex schedule`** — `set`, `show`, and `off`. `show` reports the next
  run and cross-checks WordPress's own pending event, so a schedule silently
  killed behind its back (a cron-cleaning plugin, a hand-edit) is surfaced
  rather than assumed live.
- **A Scheduled backups section on the admin Backup screen** — the same
  settings over the same store as the CLI, plus a live-status line: the next
  run time when the event is pending, or a warning when an enabled schedule has
  no pending event, so a shell-less operator gets the liveness check the CLI
  has.
- **Admin backups run as persisted jobs.** Reloading the Backup screen — or
  reopening it after the starting tab was closed — re-attaches to a running
  backup and its progress, with an elapsed timer that survives the reload,
  instead of showing an idle screen while work continues underneath.
- **A WP-Cron reliability check in `wp pontifex doctor`**, since scheduled
  backups and background continuation depend on it.

### Changed

- **Every restored file is written to a temporary sibling and atomically
  renamed into place**, so a read-only destination file no longer aborts a
  restore partway (it also closed the per-file restore-atomicity audit item).

### Fixed

- **The unattended cron ticker could strand a backup forever.** A host's web
  timeout killed the ticker mid-tick — a fatal that runs neither cleanup path —
  leaving a job marked running that nothing would continue. The ticker now
  survives its own death (see Added).
- **A reloaded Backup screen could show frozen, misleading progress.** The
  page preferred a progress value the dead request had left behind for up to
  fifteen minutes, and its fallback mixed compressed bytes into a source-byte
  bar so it could jump backwards. Progress is now distrusted once it stops
  being refreshed while a job is live, and the job's own source-byte cursor
  answers in the bar's units.
- **Job-backed admin backups were slower than the one-shot export they
  replaced.** A duplicate pre-scan and short per-tick budgets made a large
  backup re-walk the filesystem many times; the pre-scan is removed and the
  budgets raised, cutting the scan overhead without risking data (a killed
  request's work is healed and continued).
- **`wp pontifex doctor` printed the WP-Cron status twice.** Collapsed to one
  row.

## [0.5.0] — 2026-07-13 — The admin interface

The release that takes Pontifex beyond the command line: a complete admin
interface over the existing engine, and the closure of the engine-audit
hardening backlog — every audit observation now carries a shipped fix or a
recorded, deliberate deferral (ADRs 0009–0013). One breaking change for
signed archives; one changed default for backup scope. Both below.

### Changed

- **BREAKING — a trusted public key makes the signature mandatory** (ADR 0012).
  Supplying `--public-key`, or pinning `PONTIFEX_PUBLIC_KEY` in `wp-config.php`,
  now refuses an unsigned archive on import and reports it BROKEN on verify — a
  stripped signature is indistinguishable from never-signed, and the unkeyed
  hashes cannot detect tampering. Behaviour with no key anywhere is unchanged;
  there is deliberately no separate `--require-signature` flag.
- **Backups default to content-only** (ADR 0008): `wp-content` plus the whole
  database — the everyday working-WordPress-to-working-WordPress scope.
  `--whole-site` opts into the entire installation for cloning onto a bare
  server. The scope is recorded in the archive's provenance (format v1.1).
- **The format specification now matches the shipped bytes exactly.** Six
  documentation drifts corrected, a committed golden conformance archive with
  worked Appendix A vectors, byte-identity proven across PHP 8.2–8.5, and the
  reader now refuses an archive with a higher format major version — the gate
  the spec always promised.
- **`readme.txt` claims the specific safety properties Pontifex actually has**
  instead of a blanket "fails closed on errors".

### Added

- **The admin interface** — for people who never touch a terminal, following
  the project's Swiss-typography design language:
  - **Overview**: operation counters and transfer history at a glance.
  - **Backup**: create with a live byte-based progress bar and time estimate,
    cancel mid-run, download, and delete — with warnings naming any file that
    changed while it was being read.
  - **Verify**: pick a backup, hash-check every entry, get the sound-or-broken
    verdict with progress.
  - **Restore / Rollback**: typed-action confirmation, phase-labelled progress,
    a pre-restore safety archive, and honest failure reporting — a restore
    whose verdict never arrives says the result is unknown and points at
    Rollback instead of inviting a blind retry.
  - **Cross-server upload**: chunked upload of a `.wpmig` taken on another
    site, sized to the host's upload ceiling, validated before it joins the
    backup list.
- **`PONTIFEX_PUBLIC_KEY`** — pin a trusted public key in `wp-config.php` so
  every import and verify on the site enforces signatures without per-command
  flags.
- **Changed-file detection on export** (ADR 0013): a file that shrinks or grows
  between the scan and the write is recorded at the byte count actually
  captured, warned about per file, and counted in a new `files_changed` export
  statistic — the archive never claims content it does not hold.

### Fixed

- **A failed database restore can no longer harm the live tables** (ADR 0009):
  every chunk replays into staging tables and one atomic `RENAME TABLE` cuts
  over; failure at any point aborts staging with the live site untouched, and
  an automatic safety-archive replay backstops the file half.
- **Restores stream within web memory limits** (ADR 0010): entries are
  hash-verified before decode and large plain files decode stream-to-stream,
  so a browser-initiated restore no longer risks memory exhaustion on large
  files; verify and restore enforce the same decoded-size budgets.
- **Exports are consistent under concurrent writes** (ADR 0011): the dump runs
  inside a REPEATABLE READ consistent snapshot on a dedicated connection
  (falling back gracefully where hosts refuse one), with row windows ordered by
  primary key and per-table chunk sizing from real row statistics — the
  original unstable-pagination incident is closed at the root.
- **A failed export can no longer clobber a prior good archive**: archives are
  written to a sibling temp file and atomically renamed into place only when
  complete.
- **Concurrent operations are refused atomically**: the single-runner guard is
  a server-side named lock (site-scoped), so two simultaneous restores can no
  longer interleave.
- **A second restore can no longer delete the first restore's undo**: safety
  archive retention is floored at two.
- **Replays run under the source archive's charset**, so legacy
  latin1/utf8mb3 content migrates without mojibake; **`guid` columns are
  preserved verbatim** during URL rewrites, matching WordPress convention.
- **A backup with no database is refused at two independent layers**, so an
  empty table listing can never produce a hash-correct but useless archive.
- **A file entry whose decoded size contradicts its header is refused on
  restore** (ADR 0013) — an archive that lost data to a pre-fix writer race
  fails closed instead of silently restoring a wrong-sized file.
- **The admin screens keep their accessibility promises**: the backup chooser
  is a real keyboard radio group (roving tabindex, arrow keys), progress bars
  carry accessible names tied to their status lines, and indeterminate phases
  no longer announce a stale percentage.

## [0.4.6] — 2026-06-24 — WordPress.org readiness and i18n

Distribution readiness for a WordPress.org submission, plus internationalisation
of the CLI. Packaging, documentation, and presentation only — no change to the
archive format, backup, or restore behaviour.

### Added

- **A WordPress.org `readme.txt`** — listing copy, header metadata, FAQ, and a
  changelog summary.
- **A `.distignore`** so the distributed package is the runtime only (the plugin
  file, `src/`, `vendor/`, `languages/`, `readme.txt`, `LICENSE`, `CHANGELOG.md`
  and `composer.json`); the shipped build is `composer install --no-dev`.
- **Internationalised CLI output** — every user-facing command string is wrapped
  for translation under the `pontifex` text domain, with `languages/pontifex.pot`.

### Changed

- The two by-design direct-database queries (the archive SQL replay and the
  prepared `SHOW TABLES` listing) carry Plugin Check annotations so a
  WordPress.org review passes cleanly.

## [0.4.5] — 2026-06-24 — Quality cleanup

Quality "leftovers" from the 2026-06-24 audit (findings 053–057): dead-code
removal, type and analysis tightening, documentation re-sync, and small
hardening. No archive-format, CLI-flag, or default changes.

### Fixed

- **A malformed export timestamp is now rejected.** Provenance parsing checked
  only for a hard `false` from the date parser; a coercible-but-malformed
  timestamp (e.g. trailing data) is now caught via `getLastErrors()`.
- **The passphrase minimum is measured in characters.** It counted bytes while
  the message and `ARCHIVE-FORMAT.md` §8.4 say "characters"; it now uses
  `mb_strlen()`, falling back to `strlen()` only without ext-mbstring.

### Security

- **The confirmation copy of the passphrase is scrubbed.** `collect_for_export`
  now `sodium_memzero`s the second (confirm) passphrase copy on every path —
  defence-in-depth alongside the existing scrubbing.

### Changed

- Removed dead test-only API (the `ScannedEntry` kind predicates and the
  `HashingStream` byte counter) and inlined a trivial private wrapper.
- PHPStan now analyses at the PHP 8.2 floor; `RealEnvironment` gained native
  return types; `ArchiveSignature`'s block size is composed from its parts, and
  `FileLogger`'s log-directory mode and the JSON-decode depth are named.
- Re-synced several drifted docblocks and two `doctor` reason strings with the
  current code and roadmap, and tidied three coding-standard micro-nits.

## [0.4.4] — 2026-06-24 — Key-material wipe and path redaction

The final hardening patch from the 2026-06-24 audit, completing two items
deferred from v0.4.2 and v0.4.3. Both are defence-in-depth (low severity); there
are no feature, archive-format, or CLI-flag changes.

### Security

- **Secret key material is wiped from memory when it is no longer needed.** The
  encryption and signing context objects, and the signing keypair, now zero their
  derived key or secret key with `sodium_memzero()` when destroyed, so a secret
  no longer lingers in process memory until garbage collection. (The passphrase
  and the CLI's working copies were already scrubbed in v0.4.2.)
- **Absolute filesystem paths are kept out of the shareable diagnostics bundle.**
  The bundle now replaces home directories, the system temp directory and `/root`
  with placeholders — it already redacted the WordPress root and wp-content — so a
  shared bundle no longer leaks the operator's username or directory layout.
- **Absolute paths are kept out of user-facing CLI messages.** Operator-facing
  error and log lines that name a path now show a placeholder (`{ABSPATH}`,
  `{WP_CONTENT_DIR}`, `{HOME}`, `{TMP}`, `{ROOT}`) instead of the full path, so a
  pasted terminal line or screenshot no longer exposes the layout. The on-disk
  log keeps real paths (it is owner-only and not web-readable), so diagnosis is
  unaffected.

## [0.4.3] — 2026-06-24 — Correctness fixes

A correctness/quality patch from the same audit as v0.4.2.

### Fixed

- **`wp pontifex export --no-defaults` now actually disables the curated default
  exclusions.** It was silently ignored — WP-CLI parses `--no-defaults` as
  `defaults => false`, but the command read a `no-defaults` key that is never set
  (the same trap as the v0.4.1 `--no-rollback-archive` fix), so the defaults were
  always applied. Verified against real WP-CLI.
- **A failed restore statement is detected reliably.** The database restore now
  treats a `false` return from the query as failure, not only a non-empty error
  string, so a failed statement can no longer pass as success and silently drop
  or skip a table (a real `$wpdb` returns `false` and, with errors suppressed,
  leaves the error string empty).
- **The safety-archive disk preflight now counts the database.** It summed file
  sizes only, counting the database — usually the bulk of a backup — as zero; it
  could pass and then run out of disk mid-write. It also fails closed (POSIX) if
  the database-bearing archive cannot be locked to owner-only.
- **An unreadable directory during export now fails with a clear, path-named
  error** instead of an opaque internal exception, and is never silently skipped.
- Smaller robustness fixes: the composite logger isolates a failing log sink; the
  sodium AES-GCM cipher wraps `SodiumException` like its siblings.

## [0.4.2] — 2026-06-24 — Security hardening

A hardening release from a full security/quality audit. No feature changes; the
defaults are safer and a few attacker-controlled inputs are refused.

### Security

- **Plugin directories are protected from direct web access.** Logs, rollback
  safety archives, and diagnostic bundles under `wp-content/pontifex/` now carry
  an `.htaccess` (deny all) and an `index.php`, and the log directory is created
  owner-only (`0700`). A `.wpmig` backup or log left under the web root is no
  longer fetchable by URL on a typical Apache host. (nginx still needs a server
  rule.)
- **Restored file permissions are clamped.** A restored entry can no longer carry
  setuid/setgid bits or be left world-writable.
- **Archive symlinks that escape the site root are refused** by default; a hostile
  archive can no longer plant a link such as `uploads/x -> /etc`. Use
  `import --allow-unsafe-symlinks` to restore the old behaviour for a trusted
  archive.
- **Log lines are sanitised** of control characters (log-injection defence), and a
  symlinked log path is refused rather than followed.
- **Secret material is always scrubbed**, even on error paths, and an encryption
  context is refused if reused for a second archive (a nonce-reuse guard).
- **The secret signing key is written safely** — created exclusively, restricted
  to `0600` before any bytes are written, and the mode is verified.
- **Hardened diagnostics bundle** — drops `active_plugins`, uses an unpredictable
  temporary filename.
- Per-table export queries assert the WordPress table prefix; the archive reader
  enforces decode and manifest read limits at its own layer.

## [0.4.1] — 2026-06-24 — Rollback-archive flag fix

### Fixed

- **`wp pontifex import --no-rollback-archive` now actually skips the
  pre-import safety archive.** The flag was silently ignored: WP-CLI parses a
  `--no-<name>` argument as `<name> => false`, so `--no-rollback-archive`
  arrived as `rollback-archive => false` rather than the `no-rollback-archive`
  key the command read — and the safety archive was taken regardless. The
  command now reads the form WP-CLI delivers. The documented flag is unchanged;
  this only makes it work, and it fails safe either way.

## [0.4.0] — 2026-06-24 — Stats, diagnostics and logging

The observability release. Pontifex can now show what it has done, package a
sanitised report when something goes wrong, and leave a self-contained log
beside every transfer — all locally, with nothing uploaded. Split out from
v0.3.0 so the cryptographic work could ship on its own.

- **`wp pontifex stats`.** A local readout of the export and import counters —
  how many transfers were attempted, succeeded and failed, and how many bytes
  moved — as a table, or `--format=json` (also csv, yaml) for a bug report.
  Read-only; no network.
- **Rolling transfer history.** Alongside the totals, `stats` now shows a short
  window of the most recent transfers (timestamp, kind, outcome, size — never
  any content), kept in a single capped `wp_options` entry.
- **`wp pontifex diagnostics`.** A sanitised support bundle — recent logs,
  `doctor` and `stats` output, and an environment summary — written as a tar.gz.
  The site URL, absolute paths and sensitive option values (anything ending in
  `_key`, `_secret`, `_token`, `_password`) are redacted, and the bundle is
  never auto-uploaded: it is yours to read and share.
- **Per-transfer log files.** Beyond the central rotating `pontifex.log`, each
  transfer now also writes a self-contained log — export's beside the archive as
  `<output>.wpmig.log`, a real import's as `import-<UTC>.log` in the log
  directory — so a single run's record can be read or shared on its own.

## [0.3.0] — 2026-06-23 — Migration, encryption and signatures

The cryptographic release. Pontifex can now migrate a site to a new URL,
encrypt an archive, and sign it — each with the defences the format reserved
space for.

- **Cross-URL migration.** `wp pontifex import --url=<new-url>` rewrites the
  site URL across the restored database with a serialised-data-safe
  search-replace: `unserialize()` runs with `allowed_classes => false`, every
  rewritten value is round-trip re-serialised and verified, and a pre-import
  scan reports what will change (ADR 0006).
- **Encryption.** `wp pontifex export --encrypt` (or `--passphrase-stdin`)
  writes an AES-256-GCM archive with an Argon2id-derived key (a per-archive
  salt and a per-entry nonce); `import` and `verify` detect an encrypted
  archive and prompt for the passphrase. There is no passphrase recovery.
- **zstd compression.** Codec `0x0002`, preferred when `ext-zstd` is present and
  falling back to gzip when it is not — the archive is readable either way.
- **Ed25519 signatures.** `wp pontifex keygen` generates a keypair;
  `export --sign --signing-key=<path>` signs the archive (Ed25519 over a
  streamed SHA-256 prehash, so signing stays within memory on large archives);
  `verify` and `import` take `--public-key=<path>` to verify, with import
  refusing a bad signature before it writes anything.

The observability surface originally planned for v0.3.0 — fuller logging and
metrics, `wp pontifex stats`, `wp pontifex diagnostics` — moved to v0.4.0 so
this release could ship on the cryptographic work alone.

## [0.2.0] — 2026-06-22 — Safety, verification and rollback

The trust release. Two safety features make a destructive import
something you can recover from: `wp pontifex verify` checks an archive
before you rely on it, and rollback gives you an undo — every import now
takes a safety archive of the current site first, restorable with
`wp pontifex rollback`. Cross-URL migration and encryption move to
v0.3.0 ([`docs/roadmap.md`](docs/roadmap.md)). This release also lands
the repository hardening done since v0.1.0: a protected `main`, the
open-source community-health files, and a refreshed dependency floor.

### Added

- **`wp pontifex verify <archive>` command.** Opens a `.wpmig` archive,
  walks every entry and checks every SHA-256 hash, writing nothing, and
  exits 0 when the archive is sound or non-zero when it is broken or
  refused — so a backup can be checked against cold storage and gated in
  scripts or cron. `--list` prints the archive's contents as a table or
  `--format=json`. The same read-and-verify engine that already backs
  `import --dry-run`, exposed as a command (idea-bank Idea 010).
- **Rollback — automatic pre-import safety archive and `wp pontifex rollback`**
  (idea-bank Idea 009, [ADR 0005](docs/adr/0005-rollback-safety-archive-policy.md)).
  `import` now writes a safety archive of the current site before it restores
  (`--no-rollback-archive` to skip), and `wp pontifex rollback` restores the most
  recent one — the undo for a destructive import — with `--yes` and `--dry-run`.
  Safety archives live in `wp-content/pontifex/rollback/` (owner-only), named by
  UTC timestamp, with the most recent retained.

## [0.1.0] — 2026-06-22 — the round-trip baseline (same URL)

The headline release: a complete, proven round trip. Export a real
WordPress site to a `.wpmig` archive and restore it onto another
WordPress at the **same URL**, with byte-faithful files and database.
Cross-URL migration, encryption, rollback and verify stay deferred to
v0.2.0 and beyond ([`docs/roadmap.md`](docs/roadmap.md)); v0.1.0
archives are **unencrypted**.

### Added

- **`wp pontifex import <archive>` command.** Restores a `.wpmig`
  onto the current site. It surfaces the same-URL-only scope — no URL
  rewriting ([ADR 0004](docs/adr/0004-same-url-import-scope.md)) —
  before acting, confirms unless `--yes`, and offers `--dry-run`,
  which reads and verifies the whole archive end to end while writing
  nothing to the filesystem or database. Each run is logged.
  Registered in `pontifex.php` beside `export` and `doctor`.
- **First same-URL round-trip integration test.** Packs real file
  bytes and a real `utf8mb4` database table into an archive and
  restores it against a real WordPress, asserting byte-for-byte file
  fidelity and an identical row dump with multibyte content intact.
  The round-trip guarantee is now proven, not merely asserted — the
  first time the writer and reader meet over a real archive.
- **WordPress integration test harness, running in CI.** PHPUnit 11
  with a separate integration suite that boots a real WordPress through
  `wp-env` (`wp-phpunit`) and runs in CI across PHP 8.2–8.5, plus a
  WordPress-booting smoke test. Test counts are reported as two
  numbers — unit and integration.
- **Rotating PSR-3 file logger (`FileLogger`).** Writes to
  `wp-content/pontifex/logs/pontifex.log`, rotates at 2 MB (four
  backups, 10 MB cap), respects `WP_DEBUG` for its level floor, and
  never throws — all log I/O is failure-tolerant. Wired into both
  export and import.
- **Export run counters.** A single autoload-off `wp_option`
  (`pontifex_export_stats`) recording attempted / succeeded / failed
  / bytes_exported; import records its own run counters the same way.
- **Export progress bar.** A `ProgressReporter` seam (interface +
  WP-CLI bar + null no-op) that draws per-entry progress during an
  archive write without leaking a CLI type into the archive layer.
- **Restore seam.** `RestoreRunnerInterface` extracted so the CLI
  depends on a contract, not a `final` class; the runner gains
  `verify()` — the read-and-verify-only walk behind `--dry-run` —
  alongside `restore()`, both with an optional per-entry progress
  callback.
- **Release tooling.** `scripts/bump-version.php` and
  `scripts/check-release.php` keep `PONTIFEX_VERSION`, the plugin
  header `Version:` line and the git tag consistent
  ([ADR 0003](docs/adr/0003-strict-version-stamping.md)).
- Idea-bank entries 011 (throttled debug progress logging), 012
  (scan-phase progress reporting) and 013 (a deferred
  `import --max-size` override).

### Changed

- **PHP floor raised to 8.2** (from 8.1); the CI test matrix is now
  PHP 8.2–8.5.
- Adopted **PHPUnit 11** and added the integration stack
  (`wp-phpunit`, PHPUnit polyfills).
- The `RawCodec` and `GzipCodec` decoders now honour an optional
  decoded-byte ceiling, so an over-large or over-compressed payload
  is refused *during* decode rather than after it has been
  materialised.
- README, CONTRIBUTING and the package description corrected to match
  what actually ships — no claim exceeds the code (the "first-class
  rollback" pitch reworded to its true v0.2.0 status).

### Fixed

- **Symlink path-traversal escape in the restore writer.** A hostile
  archive could write files *outside* the destination root via a
  symlink entry followed by a file that descends through it (the
  Zip-Slip-via-symlink class). `FileWriter` now refuses any entry with
  a symlinked ancestor and clears a conflicting symlink at the write
  target; five path-traversal vectors are tested.

### Security

- **Reader defensive limits (`ArchiveLimits`), each proven against a
  hostile archive.** The restore walk refuses a hostile or malformed
  archive *before* it touches the destination, enforcing four
  conservative ceilings borrowed from mature backup tooling: at most
  50,000 entries, 2 GiB per decoded entry, a 100× decompression ratio,
  and 1 TiB total decoded size — a decompression bomb is refused
  mid-stream. This is the one MVP-blocking safety item (audit finding
  F015): the reader treats every byte as attacker-suppliable.
- **A failed database statement fails closed.** Real `$wpdb` returns
  `false` (it does not throw) on a failed query; an integration test
  proves a failed statement halts the restore before any later
  statement runs, so a corrupt chunk can never silently drop a table.
- **Import trust boundary documented** in
  [`.github/SECURITY.md`](.github/SECURITY.md): importing a `.wpmig`
  grants its author full write access to the target, so only trusted
  archives should be imported. A review against published WordPress
  vulnerability data informed the hardening above.

## [0.0.6] — pre-alpha (tests strengthened; v0.1.0 scope settled)

### Added
- 23 behavioural tests for `Restore\FileWriter` (closes audit
  finding F001) — every path-traversal and write defence now has a
  named test.
- ADR 0004: v0.1.0 imports restore to the same URL; URL rewriting
  ships in v0.2.0 together with its serialised-data defences.
- wp-env development environment config (`.wp-env.json`) for a
  reproducible local WordPress on Docker.
- PHP 8.5 added to the CI test matrix.
- Idea bank entries 009 (rollback) and 010 (archive verification);
  Idea 007 recorded as partially implemented (Sprint 1 close-out).

### Changed
- Roadmap aligned with ADR 0004: v0.1.0 is a same-URL restore
  baseline; the minimum logger, transfer counters and export progress
  bar are pulled into v0.1.0, with cross-URL migration, encryption and
  the fuller observability surfaces in v0.2.0.
- README rewritten for accuracy: a "What works today" status table,
  rollback described as a v0.2.0 commitment rather than an existing
  feature, and export documented as already shipped (v0.0.5).

### Notes
- No plugin-behaviour changes; checkpoint release before Phase 5
  (ImportCommand). Exercises the ADR 0003 tag/version CI guard.

## [0.0.5] — pre-alpha: archive format + export half

The archive format is implemented end-to-end as a library: writer
produces sealed archives conforming to the v1 byte layout, reader
enforces the mandatory verification-order contract. The export CLI
is functional. The import CLI is not yet registered; restore
primitives exist at the PHP API level but the command is pending.
This is **the export half of the v0.1.0 round-trip baseline**, with
the import half and the round-trip tests still to come.

### Added

- **Format primitives.** `Codec` interface, `RawCodec` (`0x0000`),
  `GzipCodec` (`0x0001`), `CodecRegistry`, `HashingStream`,
  `ByteOrder` big-endian helpers, and the `Header`, `Provenance`,
  `EntryHeader`, `ArchiveManifest`, `Footer` value objects.
- **Archive writer.** `EntryWriter`, `FooterWriter`,
  `ArchiveWriter`, `FileScanner`, `DatabaseScanner`,
  `ManifestBuilder` (with extracted `ManifestBuilderInterface`
  for test mockability), `ExclusionRules`.
- **Archive reader.** `EntryReader`, `ArchiveReader`,
  `EntryReadResult`, with the mandatory verification-order
  contract from the format spec.
- **Restore primitives (library only).** `DatabaseWriter`,
  `FileWriter`, `RestoreRunner`. Not user-facing yet; pending the
  `wp pontifex import` CLI command in a later v0.0.x release.
- **CLI integration.** `WordPressContext` abstraction
  ([ADR 0001](docs/adr/0001-wordpress-context-abstraction.md)),
  refactored `DoctorCommand` to use it, and new `ExportCommand`
  registering `wp pontifex export`.
- **Strict version-stamping policy.** Every `v*` git tag must
  match `PONTIFEX_VERSION` and the plugin header `Version:` line;
  the CI workflow's *Verify tag matches plugin version* step
  enforces this on tag push
  ([ADR 0003](docs/adr/0003-strict-version-stamping.md)).
- **Test infrastructure.** brain/monkey for WordPress-function
  mocking, Mockery lifecycle handling in a shared
  `Pontifex\Tests\TestCase` base class, `INVOKE_TESTING.md`
  documenting the surgical `__invoke` test pattern.
- **Pre-commit hooks.** 72-char commit-subject limit enforced via
  a `commit-msg` stage hook
  (`scripts/check-commit-subject-length.sh`).

### Changed

- **Composer audit strictness.** `--abandoned=report` flag aligned
  across all three call sites — `composer.json`'s `check` script,
  `.pre-commit-config.yaml`'s pre-push hook, and
  `.github/workflows/ci.yml`'s audit step. ADR 0002 documents the
  invariant and was amended during the 2026-05-26 audit pass to
  cover the CI site that the original enumeration missed.
- **PSR-4 autoload.** Three redundant entries in `composer.json`
  collapsed to the single umbrella entry the others were already
  subsumed by.
- **Test directory naming.** `tests/Unit/Wordpress/` renamed to
  `tests/Unit/WordPress/` to align with the namespace declared
  inside and avoid PSR-4 case-sensitivity issues on Linux CI.

### Removed

- `src/Version.php` — dead-code class that claimed canonical
  status but had no consumers. The strict version-stamping policy
  above makes the abstraction unnecessary at this scale; git
  remembers it if a future need brings it back.

### Known issues and pending work toward v0.1.0

- **`wp pontifex import` is not registered.** The restore
  primitives (`DatabaseWriter`, `FileWriter`, `RestoreRunner`) are
  implemented and unit-tested as far as the audit allowed, but the
  CLI command that exposes them to users is not yet wired. This is
  Phase 5 of the roadmap and the gating piece for v0.1.0's round-
  trip headline.
- **No WordPress integration tests yet.** Phase 6 of the roadmap
  requires running PHPUnit against a real WordPress installation
  across the PHP 8.1–8.4 matrix; `wp-phpunit` is not yet wired
  into the test harness.
- **No round-trip tests yet.** Cannot exist until import lands. The
  format's correctness guarantee is "export then import
  reconstructs the source byte-perfectly"; that test cannot run
  while only the export half exists.
- **`src/Restore/FileWriter.php` lacks dedicated unit tests**
  (audit finding F001). Roughly 350 lines of path-traversal
  defence code, exercised indirectly via `RestoreRunnerTest` but
  with most individual defences unverified at unit level.

### Notes

- **Archives are unencrypted** in the v0.1.x series. Encryption
  (codecs `0x0100`, `0x0101`, `0x0102`) is deferred to v0.2.0.
  See [`docs/roadmap.md`](docs/roadmap.md) for the full deferred
  list.
- **Format compatibility.** Archives written by v0.0.5 will be
  readable by v0.1.0 and onward without conversion. The byte
  layout from `archive-format.md` is the public contract; later
  versions add features without breaking earlier archives.
- **Practical implication.** Users running v0.0.5 can produce
  archives via `wp pontifex export` and the archives are valid
  per the spec, but they cannot import them back through a CLI
  command — the restore path is library-only until import wires
  up.

## [0.0.4] — pre-alpha (docs-only)

### Added
- pip and SSL troubleshooting section in `CONTRIBUTING.md` covering
  certifi setup behind corporate TLS interception, for contributors
  whose Python install cannot reach PyPI during `pre-commit install`.

### Notes
- Byte-identical to v0.0.3 for end users; documentation-only release.

## [0.0.3] — pre-alpha

### Added
- Archive format specification (`docs/archive-format.md`) and design
  rationale (`docs/archive-format-design.md`) — the public contract
  every future version of Pontifex commits to honour.
- `Environment` interface and `RealEnvironment` implementation
  abstracting PHP-runtime queries so `DoctorCommand`'s checks can be
  exercised under controlled test conditions.
- brain/monkey added as a test dependency for WordPress-function
  mocking; behavioural tests now cover `DoctorCommand`'s runtime
  checks, PHP-configuration checks, required and recommended
  extension checks, filesystem-permission checks, WP-config status
  checks, and the status-summary computation.
- `compute_status_counts()` extracted from `print_summary()` for
  direct test coverage.
- Xdebug-with-Local-and-PhpStorm setup guide in `CONTRIBUTING.md`.
- `composer dev:setup` script bundling first-time contributor setup
  (pre-commit hook installation) into a single command.

### Notes
- No user-facing feature changes; foundation work for the archive
  implementation that lands in v0.1.0.

## [0.0.2] — pre-alpha

### Added
- `PONTIFEX_VERSION` constant defined in `pontifex.php`.

### Changed
- `pontifex.php` registers the new `wp pontifex export` command stub
  alongside the existing `wp pontifex doctor`.

## [0.0.1] — pre-alpha

### Added
- Initial plugin scaffolding: `pontifex.php` bootstrap, Composer
  autoload, PSR-4 namespace, GPL-2.0-or-later licence.
- `wp pontifex doctor` WP-CLI command — environment-audit checklist
  covering PHP runtime, PHP configuration, required and recommended
  extensions, filesystem permissions, WordPress configuration, and
  database compatibility.
- `Environment` interface and `RealEnvironment` production
  implementation, decoupling all PHP-runtime queries from the doctor
  command for test isolation.
- WPCS-clean codebase: PHPCS WordPress-Extra, PHPStan level 6, PHPUnit
  unit tests with comprehensive coverage of every check method.
- Project documentation: README, archive format specification
  (`docs/archive-format.md`), design rationale
  (`docs/archive-format-design.md`), threat model
  (`docs/threat-model.md`), idea bank (`docs/idea-bank.md`).
- CI workflow running PHPCS, PHPStan, PHPUnit, and `composer audit`
  on every push.
- Pre-commit hooks: gitleaks, PHPCS fast subset, PHPStan changed-files.
- Security tooling: `roave/security-advisories` in `require-dev`
  refusing installation of any CVE-flagged dependency.

[Unreleased]: https://github.com/7Duckie/pontifex/compare/v0.9.5...HEAD
[1.1.0]: https://github.com/7Duckie/pontifex/compare/v1.0.3...v1.1.0
[1.0.3]: https://github.com/7Duckie/pontifex/compare/v1.0.2...v1.0.3
[1.0.2]: https://github.com/7Duckie/pontifex/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/7Duckie/pontifex/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/7Duckie/pontifex/compare/v0.9.5...v1.0.0
[0.9.5]: https://github.com/7Duckie/pontifex/compare/v0.9.4...v0.9.5
[0.9.4]: https://github.com/7Duckie/pontifex/compare/v0.9.3...v0.9.4
[0.9.3]: https://github.com/7Duckie/pontifex/compare/v0.9.0...v0.9.3
[0.9.0]: https://github.com/7Duckie/pontifex/compare/v0.8.0...v0.9.0
[0.8.0]: https://github.com/7Duckie/pontifex/compare/v0.7.0...v0.8.0
[0.7.0]: https://github.com/7Duckie/pontifex/compare/v0.6.0...v0.7.0
[0.6.0]: https://github.com/7Duckie/pontifex/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/7Duckie/pontifex/compare/v0.4.6...v0.5.0
[0.4.6]: https://github.com/7Duckie/pontifex/compare/v0.4.5...v0.4.6
[0.4.5]: https://github.com/7Duckie/pontifex/compare/v0.4.4...v0.4.5
[0.4.4]: https://github.com/7Duckie/pontifex/compare/v0.4.3...v0.4.4
[0.4.3]: https://github.com/7Duckie/pontifex/compare/v0.4.2...v0.4.3
[0.4.2]: https://github.com/7Duckie/pontifex/compare/v0.4.1...v0.4.2
[0.4.1]: https://github.com/7Duckie/pontifex/compare/v0.4.0...v0.4.1
[0.4.0]: https://github.com/7Duckie/pontifex/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/7Duckie/pontifex/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/7Duckie/pontifex/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/7Duckie/pontifex/compare/v0.0.6...v0.1.0
[0.0.6]: https://github.com/7Duckie/pontifex/releases/tag/v0.0.6
[0.0.5]: https://github.com/7Duckie/pontifex/releases/tag/v0.0.5
[0.0.4]: https://github.com/7Duckie/pontifex/releases/tag/v0.0.4
[0.0.3]: https://github.com/7Duckie/pontifex/releases/tag/v0.0.3
[0.0.2]: https://github.com/7Duckie/pontifex/releases/tag/v0.0.2
[0.0.1]: https://github.com/7Duckie/pontifex/releases/tag/v0.0.1
