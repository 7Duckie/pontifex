# Using Pontifex

A step-by-step guide for people who run WordPress sites, not people who write
WordPress plugins.

Everything here is written twice: once for the admin screens in your browser,
and once for the command line. If you have never used a terminal, ignore every
grey box that starts with `wp` — you will not miss anything essential.

**Contents**

1. [What Pontifex is](#1-what-pontifex-is)
2. [Installing it, and checking your server](#2-installing-it-and-checking-your-server)
3. [Your first backup](#3-your-first-backup)
4. [Checking a backup is good](#4-checking-a-backup-is-good)
5. [Getting the backup somewhere safe](#5-getting-the-backup-somewhere-safe)
6. [Restoring onto the same site](#6-restoring-onto-the-same-site)
7. [Moving a site to a new host or address](#7-moving-a-site-to-a-new-host-or-address)
8. [Undoing a restore](#8-undoing-a-restore)
9. [Automatic backups on a schedule](#9-automatic-backups-on-a-schedule)
10. [Sending backups to another server](#10-sending-backups-to-another-server)
11. [Passphrases and signatures](#11-passphrases-and-signatures)
12. [When something goes wrong](#12-when-something-goes-wrong)

---

## 1. What Pontifex is

Pontifex copies your WordPress site into a single file, and puts it back
again. That file can go onto the same site later, or onto a different site
entirely — which is how you move hosts.

Two things make it unusual.

**The file format is published, and now fixed.** The exact layout of a
`.wpmig` file is written down in [a public specification](archive-format.md),
and as of version 1.0.0 that specification is locked. Anyone could write a
program to read your backup. You are not depending on this plugin continuing
to exist.

**There is no cloud service.** Pontifex has no servers, no account, and
nothing to sign up for. It does not contact anything on its own. If you want
backups copied to another machine, you point it at a server *you* own and it
uses *your* credentials.

---

## 2. Installing it, and checking your server

Install and activate it like any other plugin: **Plugins → Add New → Upload
Plugin**, choose the ZIP, activate.

You need PHP 8.2 or newer and WordPress 6.5 or newer. If your PHP is older,
Pontifex will not start and will tell you so in a notice rather than breaking
your site.

### Checking your server can do everything

There is a command that inspects your server and reports anything that will
cause trouble later — how much memory you have, whether scheduled tasks work,
whether certain file operations are permitted:

```
wp pontifex doctor
```

**Be aware: this is command line only.** There is no equivalent button in the
admin screens. If you have no terminal access you cannot run it, and you will
find out about server limitations when they stop a backup or restore instead
of beforehand. If your host offers any kind of shell or a "WP-CLI" feature in
their control panel, it is worth running this once.

Most rows will say **OK**. A **WARN** is not a failure — it means something is
missing that you may never need. A **FAIL** needs attention before you rely on
backups.

---

## 3. Your first backup

### In the browser

**Pontifex → Backup → Create backup.**

A progress bar appears. You can close the tab — the backup continues, and
reopening the page reconnects to it rather than starting again.

### On the command line

```
wp pontifex export --output=/path/to/backup.wpmig
```

It asks for confirmation first and shows you exactly what it is about to
include. Add `--yes` to skip the question in scripts.

### What is actually in it

By default, a backup contains:

- **everything in `wp-content`** — your themes, your plugins, and your uploaded
  images and files
- **every database table belonging to this WordPress site** — your posts,
  pages, settings, users, and comments

Three things are deliberately left out:

- **`wp-content/pontifex`** — Pontifex's own folder, where your other backups
  live. Including it would put your backups inside your backup.
- **`wp-content/cache`** — temporary files WordPress regenerates anyway.
- **any `.git` folder** — version-control history. It is regenerable, and it
  can be enormous.

**WordPress itself is not included.** The core WordPress files are the same on
every site in the world and can be downloaded again in seconds. If you
specifically want them — cloning onto a completely bare server, say — add
`--whole-site`.

### How big, and how long

Roughly the size of your `wp-content` folder plus your database, with some
compression. A modest site is tens of megabytes and takes under a minute. A
large one with years of uploaded photographs can be several gigabytes and take
considerably longer.

The backup is written to `wp-content/pontifex/backups/` when you use the admin
screens, or wherever you pointed `--output` on the command line.

---

## 4. Checking a backup is good

An untested backup is a hope, not a plan.

### In the browser

**Pontifex → Verify**, choose the backup, click verify.

### On the command line

```
wp pontifex verify /path/to/backup.wpmig
```

### The three things verifying can tell you

**Sound.** Pontifex has recalculated a fingerprint for every single file inside
the backup and compared it against the fingerprint recorded when the backup was
made. If any byte anywhere had changed, it would say so. It has also checked
that a restore would accept the backup. This is the answer you want.

**Broken.** Something inside the backup is damaged or unreadable — the usual
cause is a failing disk or a copy that did not finish. Do not rely on this file;
find another copy.

**Refused.** The rarest and the strangest: the file is *not* damaged — every
fingerprint matched — but a restore will not accept it, because it would place a
symbolic link outside your site, or because its contents contradict what it says
it holds. **Pontifex never produces a backup like this.** Do not restore it, do
not delete it, and find out where it came from. If you made it yourself with
Pontifex and see this, please report it.

### What verifying still does not tell you

- It does **not** check your passphrase. An encrypted backup verifies as sound
  without one, because verifying never unlocks anything.
- It does **not** check whether your host can create symbolic links, because
  finding that out means creating one, and verifying never writes anything at
  all. `wp pontifex doctor` reports it for your server, and
  `wp pontifex import --dry-run` settles it for one particular backup.

If your server has no room to restore the backup, verifying says so — but as a
separate note, never as the verdict. **A full disk is not a damaged backup**,
and Pontifex will not imply that it is.

One consequence worth knowing: because Pontifex checks where the backup's
symbolic links would land *on this server*, a verification is a statement about
a backup **and** a destination, not about the file on its own. In rare cases the
same file can report differently on two different servers. That is deliberate —
"would this escape *your* site" is the only version of the question worth
answering.

### Rehearsing a whole restore

To find out whether a restore would actually succeed here, right now, rehearse
it:

```
wp pontifex import /path/to/backup.wpmig --dry-run
```

This runs every check a real restore runs and writes nothing to your site. It is
the strongest answer available short of restoring. See
[When Pontifex refuses](when-pontifex-refuses.md) if it stops.

---

## 5. Getting the backup somewhere safe

**A backup sitting on the same server as the site is not protection against
losing that server.** If the disk fails, the account is suspended, or the host
goes out of business, the backup goes with the site. This is the most common
way people discover their backups were not backups.

Download a copy from the Backup screen, or fetch it over SFTP, and keep it
somewhere else — your own computer, an external drive, another server. At
least one copy should be somewhere the site's hosting cannot reach.

Pontifex can also copy finished backups to another server automatically — see
[section 10](#10-sending-backups-to-another-server).

**A backup file contains everything.** Your database, which means your user
accounts, their password hashes, and your secret keys. Treat the file as
seriously as you treat the site.

---

## 6. Restoring onto the same site

This replaces your current content with the backup's. It is the destructive
one.

### In the browser

**Pontifex → Restore.** Choose a backup, then type `restore` into the
confirmation box — the button stays disabled until you do. That is deliberate
friction, so nobody does this with a stray click.

### On the command line

```
wp pontifex import /path/to/backup.wpmig
```

### What happens, and what is protected

Before touching anything, Pontifex takes a **safety archive** — a backup of
your site as it is right now — so the restore can be undone. Then it checks
several things that could go wrong and refuses up front if any of them would.

Then it writes. And here is the part worth understanding properly:

**Your database is protected.** Pontifex builds every table under a temporary
name first, and only swaps them into place once all of them are ready, in a
single instant. If anything fails before that moment, your live database is
exactly as it was. It cannot be left half-restored.

**Your files are not protected in the same way.** Files are written over your
site as the restore proceeds, and there is no undo for that. If a restore
fails part-way through the files, the ones already written stay written.

There is a second thing to know. A restore is a **merge**. Pontifex writes
what the backup contains and removes nothing else. If your site currently has
a plugin that the backup does not, that plugin survives the restore. Usually
that is harmless. But it means that after a *failed* restore — even one that
recovered automatically — files may be left behind that were never part of
your site.

**So after any restore that reported a problem, look at your site.** Check
your plugins list for anything you do not recognise.

---

## 7. Moving a site to a new host or address

This is a migration, and Pontifex is built for it.

**On the old site**, take a backup and download it.

**On the new site**, install WordPress, install Pontifex, upload the backup
(the Restore screen accepts a file from another site, uploading it in pieces
so large files work), and restore it.

### If the web address changes

Going from `oldsite.com` to `newsite.com`, or from a staging address to a live
one, the address is written into your database in thousands of places.

```
wp pontifex import backup.wpmig --new-url=https://newsite.com
```

In the browser, Pontifex notices when a backup came from a different address
and offers to rewrite it.

**Why you should not do this with a search-and-replace tool.** WordPress
stores some settings in a packed format that records the *length* of each
piece of text. Change `https://oldsite.com` to `https://a-longer-name.com`
without updating the recorded length and that setting becomes unreadable —
widgets vanish, theme options reset, plugins misbehave. It is the classic way
a WordPress migration goes wrong, and the damage often is not obvious for
days. Pontifex unpacks those settings, changes the address, and repacks them
with corrected lengths.

If it meets a packed setting it cannot safely unpack, it leaves that one
alone rather than risk corrupting it.

---

## 8. Undoing a restore

If a restore was a mistake, the safety archive taken beforehand puts you back.

**In the browser:** **Pontifex → Restore**, then type `rollback`. The screen
shows the date and time of the safety archive — check it is the moment you
mean to return to.

**On the command line:**

```
wp pontifex rollback
```

Two things to know. A rollback discards **everything** since the restore,
including any content added afterwards. And it undoes the *most recent*
restore only — it is not a general time machine.

---

## 9. Automatic backups on a schedule

### In the browser

**Pontifex → Backup → Scheduled backups.** Choose daily or weekly, an hour,
and how many to keep.

### On the command line

```
wp pontifex schedule set --frequency=daily --hour=3 --retention=7
wp pontifex schedule show
wp pontifex schedule off
```

### The hour is UTC — this catches people out

The hour is in **UTC**, not your local time and not your site's configured
timezone.

If you are in London, UTC is the same as your clock in winter and one hour
behind in summer. If you are in New York, `--hour=3` runs at 10pm or 11pm the
*previous evening*. Pick a number, then check `wp pontifex schedule show` or
the Backup screen, which both display when the next run is actually due.

### Keep-count

`--retention=7` keeps the seven most recent *scheduled* backups and deletes
older ones. Backups you took by hand — from the Backup screen or
`wp pontifex export` — are never touched, and they no longer count towards
the seven either: only backups the schedule itself created compete for those
slots, so a hand-made backup can no longer be deleted in place of one.

One thing this cannot do anything about: a backup that already existed
before you updated to this version — hand-made or scheduled — is not in the
list Pontifex now uses to tell them apart, so it will not be pruned
automatically either. Old backups like that will accumulate until you delete
them yourself. That is deliberate: guessing which pre-existing backups were
scheduled risks deleting one you made by hand, which is worse than a few
extra files sitting on disk.

### One caveat

Scheduled backups rely on WordPress's built-in timer, which only runs when
somebody visits your site. On a quiet site, a 3am backup may not happen until
the first visitor arrives. `wp pontifex doctor` reports whether a real system
timer is configured, which is more reliable.

---

## 10. Sending backups to another server

Pontifex can copy a finished backup to another server over SFTP. That server
is yours — Pontifex has nothing in between.

```
wp pontifex destination add myserver \
  --host=backup.example.com \
  --username=backups \
  --secret-env=PONTIFEX_BACKUP_PASSWORD \
  --host-key=SHA256:… \
  --remote-path=/home/backups/pontifex \
  --retention=10
```

Then:

```
wp pontifex export --output=/tmp/backup.wpmig --destination=myserver
```

Other subcommands: `list`, `test`, `archives`, `pull`, `prune`, `remove`.

Two details that are security, not bureaucracy:

**The password never goes on the command line.** You give the *name* of an
environment variable holding it (`--secret-env`). Anything typed on a command
line ends up in your shell history and is visible to other users on the
server.

**You must supply the server's fingerprint** (`--host-key`). This is how
Pontifex knows it is talking to your server and not something pretending to
be. Get it from the server itself:

```
ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub
```

Do not copy the fingerprint out of an error message — that just trusts
whatever answered.

To fetch a backup back after losing the original:

```
wp pontifex destination archives myserver
wp pontifex destination pull myserver pontifex-backup-….wpmig --output=/tmp/recovered.wpmig
```

`--output` is the **full path of the file to write**, not a directory, and
Pontifex refuses to overwrite a path that already exists — so give it a name
that is not yet taken.

Note there is no command to upload an archive Pontifex has already written.
Uploading happens during `export --destination`; to send an existing file,
copy it to the server yourself.

Configuring a destination is command line only; there is no admin screen for
it yet.

---

## 11. Passphrases and signatures

### Locking a backup with a passphrase

```
wp pontifex export --output=/tmp/backup.wpmig --encrypt
```

It asks for a passphrase twice, without showing it. Anyone with the file needs
that passphrase to read anything in it.

**There is no recovery. None.** Lose the passphrase and the backup is
permanently unreadable — there is no master key and no reset. Put it in a
password manager before you create the backup.

For scripts, `--passphrase-stdin` reads it from a pipe instead of asking.

An encrypted backup can only be restored from the command line; the admin
screens will tell you so rather than failing oddly.

### Proving a backup is yours

Signing lets you prove a backup came from you and has not been altered.

```
wp pontifex keygen --secret-key=/secure/key --public-key=/secure/key.pub
wp pontifex export --output=/tmp/backup.wpmig --sign --signing-key=/secure/key
wp pontifex verify /tmp/backup.wpmig --public-key=/secure/key.pub
```

Worth understanding: **the fingerprint check and the signature do different
jobs.** Fingerprints detect accidental damage — a failing disk, a truncated
download. They cannot detect deliberate tampering, because anyone who changes
the contents can recalculate the fingerprints. A signature can, because
forging one needs your secret key.

If you supply a public key and the backup is *not* signed, Pontifex refuses
rather than warns. A removed signature looks exactly like one that was never
there, and that is precisely the case worth refusing.

---

## 12. When something goes wrong

Pontifex refuses things on purpose, and a refusal looks exactly like a
malfunction from the outside. **[When Pontifex refuses, and what to
do](when-pontifex-refuses.md)** lists every one of them: what it means, whether
it is protecting you, the correct fix, and the plausible-looking wrong fix.

Read it before working around anything. Several refusals have an obvious
bypass that is how you lose a site.

Quick triage:

**A backup or restore failed in the browser with a vague message.** The admin
screens show a generic sentence; the real reason is in the log at
`wp-content/pontifex/logs/pontifex.log`. If you have shell access, running the
same command with `wp` will print the actual reason.

**"Another Pontifex operation is already running."** Only one operation may
touch a site at a time. Usually you wait. Do not delete the lock by hand.

**A restore failed.** Your database is almost certainly fine — that half is
protected. Check your files, particularly plugins and themes, for anything
left behind.

**Everything is broken and you need help.** Run `wp pontifex diagnostics` to
produce a support bundle, check what is in it before posting it anywhere
public, and open an issue:
<https://github.com/7Duckie/pontifex/issues>
