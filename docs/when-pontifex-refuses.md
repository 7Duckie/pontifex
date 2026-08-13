# When Pontifex refuses, and what to do

Pontifex refuses things. Often. That is deliberate, and it is the main reason
to trust it with a live site.

This page exists because a refusal and a bug look identical from the outside.
Both stop you getting what you wanted. The difference is that a bug means
something is broken, while a refusal means Pontifex has spotted something that
would have hurt you and has stopped before doing it.

The distinction matters because the obvious way round several of these
refusals is to switch off the thing that made them — and for a few of them,
that is how you lose a site.

**How to use this page.** Find the message you saw, or the situation you are
in. Each entry tells you what actually happened, whether the refusal is
protecting you or is something you can legitimately fix, what to do, and —
often the most useful part — the wrong thing you would reasonably have tried.

---

## Before anything else: three facts that explain most confusion

### 1. The admin screens do not show you the real message

This is the single biggest source of "Pontifex just says it failed and I don't
know why".

When something goes wrong during a backup or restore, the WP-CLI command
prints the actual reason. The admin screens almost always do not — they show a
short, generic sentence instead:

> The backup could not be completed. Check the Pontifex log for details.

> The restore failed, so your site was automatically rolled back to its state
> before the restore. Check the Pontifex log for details.

That one is worth reading closely, because there are three versions of it and
they do not mean the same thing: the site was put back exactly, the site was put
back but Pontifex could not account for every file it had added, or recovery
itself failed. See [section 3](#3-your-database-is-protected-on-a-failed-restore-your-files-are-not).

The real message went to the log. **If you are working in the browser and hit
a failure, the log is not optional — it is the only place the answer exists:**

```
wp-content/pontifex/logs/pontifex.log
```

Older entries rotate into `pontifex.log.1` through `.log.4`. The directory is
locked down (owner-only, with an `.htaccess`), so you will need file access —
SFTP, your host's file manager, or a hosting support ticket.

Look for the most recent line containing `Admin backup failed.` or
`Admin restore failed.`

If you have shell access, running the same operation through WP-CLI is often
the fastest way to find out what is wrong, because the CLI tells you.
`wp pontifex export`, `wp pontifex import`, `wp pontifex rollback` and
`wp pontifex verify` name the reason they stopped — and, for the first three,
whether the problem is the archive, this server, or the command you typed —
then exit with a failing status. Server paths are replaced with placeholders
such as `{WP_CONTENT_DIR}`, so that output is safe to paste into a support
thread.

Two of them go further, because the state they leave things in is not obvious.
A failed export says whether there is now a backup at the output path: usually
there is not, though an archive that finished before a later step failed is
still usable. If the run was resumable it also says that `--resume` will *not*
pick it up — that is for an interrupted export, not a failed one — and names
the part-written file it stranded, if it stranded one. A failed rollback says
how many entries it had already restored, because a replay that stops partway
leaves the site half undone and nothing puts that right on its own.

### 2. "Refused" is not "broken", "could not check" is neither, and the difference matters

`verify` has four answers, not two.

**Sound** means the archive's structure is intact, every entry still matches
the fingerprint taken when it was written, and a restore would accept it.

**Broken** means something inside is damaged or unreadable. Find another copy.

**Refused** means the archive is *not* damaged — every fingerprint matched —
but a restore will not accept it: it would place a symbolic link outside your
site, or its contents contradict what its own header says it holds.

That third answer calls for the opposite of what "broken" calls for. Broken
sends you to delete the file and reach for another copy. **A refused archive
should be kept and not restored.** Pontifex never produces one, so its
existence is information: find out where the file came from. If you made it
yourself with Pontifex and see this, please report it.

**Could not check** is the fourth, and it is not a "no" to any of the
questions above — it is Pontifex being unable to ask them. It means this host
stopped the check before it could reach sound, broken or refused, most often
because it ran out of memory partway through. Nothing was learned about the
archive either way: it is not a refusal, because Pontifex has not spotted
anything wrong with it, and it is not broken, because no integrity check
actually failed — the check simply never finished. **Keep the file exactly
as it is.** See ["Could not check: …" below](#could-not-check-).

Two checks verify still cannot make:

- It does **not** check your passphrase. An encrypted archive verifies as
  sound without one, because verify never decrypts anything.
- It does **not** check whether your host can create symbolic links, because
  the only way to establish that is to create one, and verify never writes
  anything. Use `wp pontifex doctor`, or rehearse the whole restore with
  `wp pontifex import --dry-run`.

If your server has no room, verify tells you — as a note beside a sound
verdict, never as the verdict itself. **A full disk is not a damaged backup.**

### 3. Your database is protected on a failed restore. Your files are not.

This is the most important sentence on this page.

**The database half is safe.** Pontifex builds every table under a temporary
name first, and only when all of them are ready does it swap them into place
in a single atomic step. If a restore fails at any point before that swap,
your live tables are exactly as they were. Nothing is half-written.

**The file half is not transactional in the same way.** Restoring files means
writing them over your site as it goes. If a restore fails part-way through the
files, the ones already written stay written.

**What the automatic recovery does now.** Pontifex keeps track of every file a
restore *creates* — as opposed to overwrites — and when the automatic recovery
runs after a failure it puts back what the safety archive captured **and removes
what the failed restore added**. So a plugin file your site never had, written
moments before the failure, is taken away again rather than left behind. It only
ever removes what that run itself created; anything your site wrote in the
meantime, and anything the safety archive contains, is left alone.

When Pontifex can account for every file it added, it tells you the site is back
to how it was. When it cannot — on a very large restore, or if a file would not
delete — it says so instead of claiming a clean revert. **Read which of the two
you were given.**

**There are still cases with no automatic recovery at all:**

- You passed `--no-rollback-archive`, so there is no safety archive to replay.
- The recovery itself failed — Pontifex says so plainly when this happens.
- The restore ended on a fatal error (out of memory, an exceeded time limit),
  which stops PHP outright before any recovery can run. The log says the site
  may be partially restored.

**In any of those three, look at your site before assuming it is back to
normal.** Check your plugins list for anything you do not recognise.

---

## Restoring

### "there is not enough free disk space"

> The restore was stopped before changing anything, because there
> is not enough free disk space at "…". It needs about N MB free, and only N MB
> is available. Free up some space and try again.

**Common. This is an environment problem, not a fault.**

**What happened.** Your server does not have room. Pontifex works out how much
the restore would *add* rather than the archive's total size, so restoring a
site over itself costs almost nothing while restoring a larger site onto a
small disk is caught. Before checking, it also clears away any temp files an
earlier, interrupted restore left behind, so a previous failed attempt's own
debris is never what is holding up the retry. The estimate deliberately leans
low, so if it still fires you are genuinely short.

**Nothing has been changed** beyond clearing away another restore's own
leftover debris, as described above. This still runs before the first byte of
your site is written.

**What to do.** Free up space and try again. Old archives elsewhere on the
server, other sites' logs, and your host's own temp directories are usually
where the space went.

**What not to do.** Do not delete the contents of `wp-content/pontifex/rollback/`
to make room. Those are the safety archives — the undo history for exactly the
operation you are about to run. Freeing space by deleting your only way back is
a bad trade.

**In the browser:** you will see the generic "rolled back" message instead.
Check the log.

### "this host could not create a test symlink"

> This backup contains N symbolic link(s), but this host could not
> create a test symlink in "…", so restoring it would overwrite files and then
> fail partway through, leaving neither the old site nor the archive's…

**Fairly common on shared hosting. An environment problem.**

**What happened.** Your backup contains symbolic links — shortcuts that point
at another file. Many hosts switch off PHP's ability to create them. Without
this check, the restore would have overwritten a good deal of your site and
then stopped dead at the first link, leaving you with a mixture.

Pontifex tests the actual directories the links would land in, not just the
site root, because hosts often allow links in `uploads` but not elsewhere.

**Nothing has been changed** beyond one test link, created and removed again.

**What to do.** Ask your host to allow `symlink` — it is usually listed in a
PHP setting called `disable_functions`. Or restore onto a host that allows it.
You can check in advance with `wp pontifex doctor`, which reports symbolic-link
support as a warning before you need it.

**What not to do — and this is the trap.** `--allow-unsafe-symlinks` looks like
the answer and is not. That flag controls *where links are allowed to point*,
not *whether your host can create them at all*. It will not help here, and you
will have switched off a genuine security protection for nothing.

### "resolving its target … reaches an existing link on disk, but readlink() is not available"

> Cannot check the symbolic link "…": resolving its target "…" reaches an
> existing link on disk, but readlink() is not available on this host, commonly
> because it is listed in disable_functions. Where that link points cannot be
> established, so the restore is refused rather than risk writing outside your
> site.

**Uncommon, and specific to hosts that have disabled `readlink`. An environment
problem, not a fault in your backup.**

**What happened.** Deciding whether a symbolic link in your backup is safe
sometimes means reading the target of a link that is *already* on disk at the
destination — for instance, from an earlier, partial restore. Reading a link's
target needs PHP's `readlink()` function, and some hosts switch it off,
commonly through `disable_functions`. Without an answer, Pontifex cannot tell
whether the link is safe, so it fails closed rather than guess.

**Your backup is not the problem, and should not be deleted.** The archive can
be entirely sound; it is this host that cannot answer the question. Restoring
the very same file on a host where `readlink` is enabled will succeed.

**What to do.** Ask your host to remove `readlink` from `disable_functions`,
the same way you would for `symlink` (see the entry above). You can check for
this in advance with `wp pontifex doctor`.

**What not to do.** Do not assume the backup is corrupt and make a fresh one to
replace it. A new backup taken *on this same host* will run into the identical
problem the moment it tries to record a symbolic link — see
["a site that contains symbolic links cannot be backed up" below](#a-site-that-contains-symbolic-links-cannot-be-backed-up-until-it-is-enabled)
— because the cause is the host's configuration, not anything about this
particular archive.

### "refusing symlink … Re-run with --allow-unsafe-symlinks only if you trust this archive"

**Uncommon in ordinary use. This one is protecting you, and you should take it
seriously.**

**What happened.** A symbolic link in the archive points somewhere it should
not — outside your site, at your `wp-config.php`, or into Pontifex's own
storage. Written to disk, such a link turns an ordinary web request into a way
to read your database password and security keys.

The trick usually needs two links working together, so no single one looks
wrong on its own. Pontifex resolves the whole set the way the operating system
would, before writing anything.

**Nothing has been changed.**

**What to do.** If you did not create this archive yourself, **do not restore
it**. This is what the check is for. Find out where the file came from.

If you did create it, from a site whose layout you control — a deliberately
symlinked uploads directory, for instance — then `--allow-unsafe-symlinks` on
`wp pontifex import` is what it is for. Use it only when you know which link is
being refused and why. Note it also relaxes the automatic recovery, and there
is no way to use it from the admin screens: the browser path always enforces
confinement.

**What not to do.** Do not reach for the flag to make the message go away. Of
ten hostile archive shapes tested against an earlier version, eight wrote their
links successfully and five handed back the contents of `wp-config.php`.

### "does not match a sanctioned shape" and other database refusals

**Only with a corrupt or hostile archive. Protecting you.**

**What happened.** An archive carries the SQL needed to rebuild your database.
Before running any of it, Pontifex checks that each statement begins exactly as
one of three permitted forms, all naming the temporary table it built itself.
Anything else is refused.

This exists because a backup file is just a file. Without the check, an archive
could carry one extra statement that changed an administrator's password, and
restoring it would hand over the site. That was demonstrated against a real
database before the check was added.

**Your live tables are untouched** — this happens while building the temporary
copies. But **your files have already been restored** by this point, because
files are written before the database. Your site is a mixture.

**What to do.** Do not restore this archive. Establish where it came from. If
it is your own and this happens, the file is damaged — use an older backup.

**What not to do.** Do not extract the SQL and run it by hand with
`wp db query`. That is precisely the outcome the check prevents, performed
manually.

### "it is not part of this site, whose tables begin with…"

> Refusing to restore table "wpb_options": it is not part of this site, whose
> tables begin with "wpa_". A backup can only replace the site it belongs to.
> Nothing has been changed.

**Rare, and one of the more serious refusals on this page.**

**What happened.** Some hosting plans put several WordPress sites in one
database, keeping them apart only by giving each site's tables a different
name prefix (`wpa_options`, `wpb_options`). Before staging any table from the
archive, Pontifex works out the archive's own source prefix and checks that
every table it stages begins with the live database's prefix — rewriting the
table names to match first, when the two genuinely differ (see below). This
table did not belong under either.

**Refusing is the last resort, not the first answer.** Pontifex tries two
things before it ever reaches this refusal. First, the prefix recorded in the
archive's own provenance — every archive written by a current Pontifex
carries one. Failing that, a prefix worked out from the archive's own table
names: `wp_options`, `wp_posts` and the rest of a normal WordPress install all
share one leading run, and that shared run is taken as the prefix, provided
it genuinely is shared by every table the archive holds — so a plugin table
such as `wp_myplugin_options` cannot narrow it down to `wp_myplugin_`, because
that narrower run is not shared by the archive's own `wp_posts`. Either route
lets a genuinely different, but legitimate, prefix be rewritten onto this
site automatically, with nothing for you to do. **This message only appears
once both have been tried and neither could account for the table.**

**Pontifex never produces an archive like this** in the ordinary case. A
backup Pontifex writes always carries this site's own table names sharing one
prefix, so once both recovery routes above have failed, this file either
genuinely belongs to a different WordPress installation or has been altered
to claim one it does not own. **Keep it, and do not delete it** — the same
advice as the [symlink refusal above](#refusing-symlink--re-run-with---allow-unsafe-symlinks-only-if-you-trust-this-archive):
a refused archive is undamaged, and its existence is information. Find out
where the file actually came from before doing anything else with it.

**Nothing has been changed.** This is checked before the table is staged and
before any SQL runs.

**The legitimate case this can still catch.** One narrow case remains, and it
needs an old archive: one written before Pontifex recorded a source prefix at
all (a field the archive format added in v1.1; every archive from a current
Pontifex has it), **and** whose own table names cannot establish one shared
prefix either — for instance, a database-only backup that deliberately
excluded every recognisable WordPress core table with `--exclude-table`,
leaving only custom tables Pontifex has no core table name to anchor a prefix
to. Restoring that archive onto a site with a genuinely different prefix hits
this refusal on a backup that is genuinely yours.

**What to do.** In almost every case, nothing — Pontifex now recovers the
right prefix on its own, from the recorded value or the table names, so a
restore that would once have needed a fresh export usually just works. If you
do still hit this message, and you are confident the archive is your own, the
fix is to re-export the source site with a current version of Pontifex — the
new archive will record its own prefix directly, which is not affected by
which tables the backup includes. There is no supported way to force a
rewrite onto an old archive that carries neither the recorded field nor
table names a prefix can be derived from.

**What not to do.** Do not edit the SQL inside the archive to relabel the
tables. That reintroduces exactly the risk this check exists to close, by
hand, on your own live site.

### "the archive records a files-only scope but carries database chunks"

**Corrupt or hand-edited archive. Protecting you. Nothing has been changed.**

The archive's description of itself contradicts its contents. Pontifex will not
guess which is right. Use another backup.

### "archive declares N entries, exceeding the maximum"

**Very large sites. Nothing has been changed.**

The limit is 100,000 files and it is not adjustable — nothing in the plugin lets
you raise it. Take a narrower backup using exclusions, or split it into a
files-only and a database-only pass.

---

## Reading and opening backups

### "failed to decrypt; the passphrase is wrong or the entry has been tampered with"

**The most common refusal in the whole system.**

**What happened.** Almost always: the passphrase is wrong. Encryption cannot
tell the difference between a wrong key and altered data, so the message
covers both.

**What to do.** Try again, watching for a trailing space, a smart quote pasted
from a document, or a different keyboard layout. If you are certain the
passphrase is right and it still fails, the file has been altered or damaged —
get a fresh copy.

**There is no passphrase recovery.** If the passphrase is lost, the archive
cannot be opened. Not by you, not by anyone.

**What not to do.** Do not look for a way to decrypt it anyway. There isn't
one, and a tool that skipped the check would produce plausible-looking rubbish
and write it over your live site.

### "entry hash does not match" / "the entry has been tampered with or is corrupt"

**Protecting you. Never bypass this.**

**What happened.** A piece of the archive does not match the fingerprint taken
when it was written. The file has been damaged in storage or transfer, or
deliberately altered.

**What to do.** Get a clean copy — re-download it, or fetch it from wherever
else you keep it. If every copy fails the same way, that backup is gone. Use an
older one. This is the argument for keeping more than one.

**What not to do.** Do not go looking for a `--force` or `--skip-verify`. There
isn't one, deliberately. The fingerprint is the only thing that can tell you
whether the bytes about to overwrite your live site are the bytes you backed
up. Skipping it would not repair the archive — the damage already happened — it
would just turn a loud, safe failure into a silent one.

### "This backup could not be read, so it was not checked"

**An access problem, not a damaged backup — and the distinction matters.**

**What happened.** The file is there, but this site cannot open it. Almost
always a permissions or ownership problem: a backup written by one user and
read by another, which happens easily when some backups are taken on the
command line and others through the browser.

**Your backup is very probably fine.** Nothing about its contents has been
checked, because nothing could be read. Do not replace it, and do not go back
to an older one on the strength of this message.

**What to do.** Check the ownership and permissions of the backup file and of
`wp-content/pontifex`. The web server user needs to be able to read both. If
you have shell access, `wp pontifex verify` will often succeed where the
browser cannot, because it runs as a different user — and that succeeding is
itself the diagnosis.

### "this archive's manifest needs about N MB to open"

**Common on shared hosting with large sites. An environment problem.**

**What happened.** Opening a backup's index needs memory in proportion to how
many files it holds. There is not enough available.

Pontifex asks WordPress to raise the limit first and only refuses if that was
not enough.

**What to do.** Raise `memory_limit` on the server, or run the restore from
WP-CLI, where far more memory is usually available than in a browser request.
This is a genuine case where the admin screens can fail at something the
command line manages easily.

### "Could not check: …"

> Could not check: Entry declares 41943040 decoded bytes, exceeding the
> 10485760-byte budget for this restore. (…)
>
> This is not a verdict on the backup — no check ran to completion, so
> nothing is known about it either way. Keep the file; the problem named
> above is this host's, and is usually fixable (more memory, more disk
> space), after which the same archive should check cleanly.

**Common on shared hosting with large sites. An environment problem, and —
importantly — not a verdict of any kind.**

**What happened.** Checking a backup's integrity sometimes needs to hold one
piece of it — most often a database chunk — in memory while it is decoded,
and this host ran out before the check could finish. WordPress's own default
`memory_limit`, 40 MB, is commonly too little for anything but a small site.
This is different from ["this archive's manifest needs about N MB to open"
above](#this-archives-manifest-needs-about-n-mb-to-open): that one stops
before the check has even started; this one stops partway through it.

**Your backup is not broken, and is not refused.** Neither of those words has
been earned, because the check that would have earned either one never
finished. The identical file checked with more memory available — raising
`memory_limit`, or running from WP-CLI, where far more memory is usually
available than in a browser request — very often verifies sound.

**What to do.** Raise `memory_limit`, or run `wp pontifex verify` from the
command line rather than the browser.

**What not to do.** Do not delete the backup or treat it as damaged. If you
are scripting against `wp pontifex verify`, note the exit code: this outcome
now exits `2`, not `1` — a script that only checks for a non-zero exit will
lump this in with a genuinely broken or refused archive unless it is updated
to look at the actual code.

### "this archive is format version X.Y, but this reader supports…"

**Update Pontifex. What not to do:** do not edit the version bytes. A higher
major version means the structure changed; forcing it makes the reader
misinterpret the file rather than refuse it.

---

## Making backups

### "would produce a manifest … larger than this installation can read back"

**Large sites. Protecting you.**

**What happened.** Pontifex refuses to write a backup it would not be able to
open again. The limit is on the *index*, not the total size — so it is driven
by how many files you have and how long their paths are, not by gigabytes.

Usually the cause is a directory that does not belong in a backup: a
`node_modules` folder inside a theme, a large `vendor` directory, or thousands
of generated image thumbnails.

**Nothing has been written.** Any previous backup at that path is untouched.

**What to do.** Exclude what you do not need — `--exclude` on the CLI, or the
exclusions box on the Backup screen. Or take the database separately with
`--db-only` and the files in a narrowed pass.

**What not to do.** Raising `memory_limit` will not help; the limit is
structural and is checked before memory is considered. Retrying with
`--resumable` will not help either — the same check runs every time. And
`--no-defaults` makes it strictly worse, because it removes the sensible
exclusions, including the one that stops your backups being inside your backup.

**This is one of the few engine messages the admin screens show you in full.**

### "Refusing to build an empty backup" / "no database in it"

**Protecting you. Nothing has been written.**

Your exclusions matched everything, or the database could not be read. A backup
that verifies as sound and restores nothing is among the worst things a backup
tool can produce, so Pontifex refuses to make one.

Check your exclusion patterns — note that `/**` on its own matches everything.

### "path is not readable; check filesystem permissions"

**Common. An environment problem.**

A file or directory could not be read, so the backup would have been silently
incomplete. Pontifex stops rather than quietly skipping it.

**In the browser the one thing you need — which path — is only in the log.**

**What to do.** Fix the permission, or exclude the path deliberately if it is
something you do not need.

**What not to do.** Do not run the export as `root` to get past it. The restore
then writes files your web server cannot read, and you have turned a backup
problem into a site outage. Do not `chmod -R 777` either.

### "the source changed shape since this export started"

**Resumable backups only.**

A file was added or removed *earlier in the scan* while the backup was running
— usually an automatic plugin update or a deploy. Files *changing* is fine;
appearing and disappearing is not.

**What to do.** Delete the partial backup and start again, ideally with
automatic updates paused.

**What not to do.** Running `--resume` again will not help; the fresh scan
disagrees the same way each time.

### "a site that contains symbolic links cannot be backed up until it is enabled"

> Could not read the symbolic link "…": readlink() is not available on this
> host, commonly because it is listed in disable_functions. A site that
> contains symbolic links cannot be backed up until it is enabled.

**Uncommon. An environment problem.**

**What happened.** Your site contains a symbolic link, and recording it in a
backup means reading where it points with PHP's `readlink()` function. Some
hosts disable it, commonly through `disable_functions`. Without this check,
the backup would either drop the link silently or record it wrongly.

**Nothing has been written.** The backup stops before anything is created.

**What to do.** Ask your host to remove `readlink` from `disable_functions`.
You can check for this in advance with `wp pontifex doctor`.

**What not to do.** Do not just retry — the same check runs every time your
site still contains the link. If you do not need that link included, `--exclude`
it and the rest of the backup will proceed.

---

## "Another Pontifex operation is already running"

**The most-encountered refusal in ordinary use. Protecting you.**

Only one backup, restore or rollback may touch a site at a time. Two at once
could interleave writes into the same tables and files.

**What to do — it depends on what happened:**

| Situation | What to do |
|---|---|
| An operation genuinely is running | Wait for it |
| A CLI resumable backup was killed | `wp pontifex export --resume` |
| A browser backup was orphaned (tab closed) | Wait about two minutes; a background tick adopts it |
| A restore was killed | Wait out the fifteen-minute timeout — **and check your site is intact while you wait** |

A stalled *backup* is reclaimed automatically. A stalled *restore* is not, and
that asymmetry is deliberate: a possibly half-restored site is exactly when a
second writer must not start.

**What not to do.** Three tempting things, all wrong:

- **Deleting the lock by hand.** For a restore, that transient is the only
  thing stopping a second operation starting on a site that may be half
  restored.
- **Restarting PHP.** It will not clear the lock — that part lives in the
  database — and you will have killed the background task that was quietly
  finishing your backup.
- **Deleting the job file.** That throws away the resume position and turns a
  90%-complete backup into rubbish.

---

## Offsite destinations

### "does not match the pinned fingerprint. Refusing to connect."

**Protecting you.** The server answering is not the one you saved. Either the
server was rebuilt, or something is intercepting the connection.

Pontifex checks this *before* sending your credentials, so nothing has leaked.

**What to do.** Get the real fingerprint from the server itself, out of band —
on the server, `ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub`.

**What not to do.** Do not copy the fingerprint out of the error message into
your configuration. That pins whatever just answered, which is exactly what an
attacker would want. And `--insecure-host-key` switches off this protection
permanently, for every future upload of your entire database.

### "The upload to the destination failed … The local archive is still at …"

Read the second half. **The backup succeeded; only the copy failed.** Do not
delete the local file. Fix the connection and upload it again.

### "could not be verified: the destination holds … bytes, but the local archive is … bytes"

**Protecting you.** An upload goes to a temporary name first and is only
renamed into place once its size is checked against the local archive. This
message means the destination reported a different size than the file that
was sent — a connection that dropped partway through, without the transfer
itself reporting failure. Without this check, that partial file would have
been renamed into the archive's real name, listed as a backup, and — being
the newest thing at the destination — could evict a genuinely sound backup
from retention to make room for it.

The partial upload under the temporary name is removed automatically as part
of this refusal; nothing is left behind for retention to trip over.

**What to do.** Check the connection to the destination, then retry the
upload. The local archive this backup produced is unaffected either way —
this is the same situation as "The local archive is still at …" above, just
caught one step later.

### "finished and was verified, but the SFTP destination refused to move it into place"

**Not something a retry fixes — read it fully before doing anything.** The
upload itself succeeded and was checked byte-for-byte against the local
archive; it is sitting safely on the destination under the temporary name the
message gives you. What failed is the final step, moving it from that
temporary name to its real one, and that step fails for a reason that is
still true the next time you try: almost always, the account Pontifex
connects as does not have permission to rename (or delete) files in that
directory, even though it was able to write the temporary file in the first
place.

**What to do.** Check the destination account's permissions on the remote
directory — it needs to be able to rename and delete there, not just create
files. Once that is fixed, either re-run the export (a fresh temporary file
will be uploaded and renamed normally) or, if you would rather not upload the
archive again, rename the temporary file yourself over SFTP.

**What not to do.** Do not keep retrying the export hoping it will go through
— a permissions problem does not resolve itself between attempts, and each
retry uploads the whole archive again for no reason. And do not delete the
temporary file: it is a complete, verified copy of your backup.

---

## Two things that will not tell you they went wrong

Both are known gaps, recorded honestly rather than hidden.

**A cancelled-then-abandoned scheduled backup is silent.** If every attempt to
continue a background backup dies the same way — usually too little memory —
Pontifex eventually gives up. Nothing appears on any screen; there is only a
line in the log, and a failed entry in the transfer history. If scheduled
backups stop appearing, check the log.

**Signature checking only applies to uploads.** If your site pins a trusted key,
an uploaded backup must be signed with it. But a file copied straight into
`wp-content/pontifex/backups/` over SFTP is not checked. If you rely on
signatures, control who can write to that directory.

---

## Flags that switch off a protection

Each of these exists for a real reason. Each is also a way to hurt yourself.

| Flag | What it switches off | Reasonable when | Dangerous when |
|---|---|---|---|
| `--allow-unsafe-symlinks` | Where symbolic links may point | Your own archive, of a layout you control | Any archive you were sent |
| `--whole-site` | The boundary keeping a restore inside `wp-content` | A fresh, empty destination | A live site — it overwrites `wp-config.php` with the *source* site's database details |
| `--no-rollback-archive` | The safety archive, the automatic recovery, **and** any future rollback | A disposable staging site | A live site |
| `--no-defaults` | The sensible exclusions, including `.git` and Pontifex's own folder | You supplied your own complete list | Routine use — your backups end up inside your backup |
| `--insecure-host-key` | Checking you are talking to the right server | A private network you control | Anything over the internet |
| `--yes` | Only the confirmation prompt | Scripted runs | Interactive use on a live site |

`--dry-run` is not in this table because it is not a bypass: it skips the
prompt and every write. Note it does not run the disk or symbolic-link checks
either, so a clean dry run is not a promise a real restore will proceed.

---

## Still stuck

1. Read `wp-content/pontifex/logs/pontifex.log` — for anything that happened in
   the browser, the real reason is only there.
2. Run `wp pontifex doctor` if you can. It checks your environment and reports
   problems before they bite.
3. Run `wp pontifex diagnostics` to produce a support bundle. Check what it
   contains before attaching it anywhere public.
4. Open an issue: https://github.com/7Duckie/pontifex/issues

If you are in the middle of a failed restore and unsure whether your site is
intact: the database is almost certainly fine — that half is protected. Check
the files, particularly your plugins and themes directories, for anything that
does not belong.
