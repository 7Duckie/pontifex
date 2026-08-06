=== Pontifex ===
Contributors: 7duckie
Tags: backup, migration, wp-cli, database, restore
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Back up and migrate WordPress, your content and its database tables, in one openly documented .wpmig archive. CLI and admin UI; never phones home.

== Description ==

Pontifex packs your WordPress content — what is under `wp-content` (themes, plugins, uploads) and every database table belonging to the site — into a single `.wpmig` archive, and restores it onto another WordPress. Pass `--whole-site` to capture the entire installation, WordPress core included, for cloning onto a bare server. Two promises set it apart:

* **The format is documented — and, since 1.0.0, locked.** The `.wpmig` archive format is publicly specified and now locked: a backup taken today stays readable by every future version of Pontifex, so a backup is never hostage to the plugin — it can be read, verified, or recovered without Pontifex at all.
* **No cloud service of ours.** Pontifex runs no service of its own, phones home to nothing, and needs no account. The only way a backup ever leaves your server is an SFTP destination you configure yourself, pointing at a server you own — set none up, and nothing leaves your disk.

Pontifex can be driven two ways: through WP-CLI (`wp pontifex …`), or from the admin screens — Overview, Backup, Verify, and Restore — added in v0.5.0 for sites without shell access. A finished backup can also be sent offsite to a **server you own**, over SFTP — still no cloud service, no account, and no phone-home; it's your server and your credentials, only when you command it.

= What it does =

* `wp pontifex export` — pack your content (`wp-content`) and the database into one `.wpmig` file; `--whole-site` captures WordPress core too, `--files-only` skips the database, `--db-only` captures just the database, and `--exclude`/`--exclude-table` leave out files or tables you name.
* `wp pontifex import` — restore an archive onto WordPress, taking a safety archive automatically first.
* `wp pontifex verify` — check an archive's integrity (and its signature, if signed) without restoring.
* `wp pontifex rollback` — undo the most recent import from its safety archive.
* `wp pontifex keygen` with `export --sign` and `verify/import --public-key` — Ed25519 signing and verification.
* `export --encrypt` — optional AES-256-GCM encryption with an Argon2id-derived key (or `--passphrase-stdin` to supply the passphrase non-interactively, for scripts).
* `import --url=…` — cross-URL migration, with defences against the classic serialised-data corruption bug.
* `wp pontifex stats`, `diagnostics`, and `doctor` — observability and a sanitised, never-uploaded diagnostics bundle.
* `export --resumable` and `export --resume` — an export that survives timeouts, lost connections, and killed processes, continued from where it stopped.
* `wp pontifex schedule` — automatic backups on a daily or weekly schedule (at an hour given in UTC), with old scheduled backups pruned to a retention count. Also configurable from the Backup screen.
* The admin screens — create, verify, restore, and roll back backups from the dashboard, with live progress, a pre-restore safety archive, and chunked upload of a backup taken on another site. A backup runs as a persisted job, so reloading the page re-attaches to its progress instead of losing it.

= Built for other people's live sites =

Pontifex runs inside live websites, on data its author never sees. It refuses hostile input (decompression bombs, path-traversal symlinks, over-budget entries), restores the database atomically — a failed restore itself leaves your live tables untouched — takes a safety archive before every restore (unless skipped with `--no-rollback-archive` on the CLI), and never does naive search-replace over serialised data.

== Installation ==

Pontifex works from your WordPress dashboard — no terminal needed — and, if you have shell access, from WP-CLI as well.

1. Install and activate the plugin (upload the ZIP via Plugins → Add New, or run `wp plugin install`).
2. Go to **Pontifex → Backup** and click "Create backup" to take your first backup (or run `wp pontifex export --output=/path/to/backup.wpmig`).

If you have shell access, `wp pontifex doctor` reports what this host can and cannot do.

Requires PHP 8.2 or newer and WordPress 6.5 or newer.

== Frequently Asked Questions ==

= Does Pontifex upload my data anywhere? =

Not unless you tell it to. Pontifex runs no service of its own and contacts nothing on its own initiative — everything happens on your own server. The one exception is an offsite SFTP destination you configure yourself, described below; set none up, and nothing ever leaves the machine.

= Can I read a backup without the plugin? =

Yes. The `.wpmig` format is publicly documented, so an archive can be inspected and recovered independently of Pontifex.

= Will a backup I take today still open in a future version of Pontifex? =

Yes. Since 1.0.0 the `.wpmig` format specification is locked: an archive written today stays readable by every future version of Pontifex. Any change the format cannot accommodate gets a new major specification version, never a silent revision of this one — so your existing backups are never left behind by an update.

= Is there an admin UI? =

Yes, since v0.5.0: Overview, Backup, Verify, and Restore/Rollback screens, plus uploading a backup taken on another site. WP-CLI remains fully supported and is still the way to script Pontifex.

= Can I migrate to a different site URL? =

Yes, with `wp pontifex import --url=…`, which rewrites the database safely (including serialised data) rather than doing a naive search-replace.

= Are backups encrypted? =

Optionally. Pass `export --encrypt` for AES-256-GCM encryption with an Argon2id-derived key, or `export --passphrase-stdin` to supply the passphrase non-interactively, for scripts. Archives can also be signed with Ed25519 keys.

= Can backups run automatically on a schedule? =

Yes. Set a daily or weekly schedule — from the Backup screen or with `wp pontifex schedule set` — and Pontifex runs a content-only backup unattended at the chosen hour (UTC), pruning old scheduled backups down to the retention count you set.

= What happens if a backup is interrupted? =

A backup started from the admin screen runs as a persisted job: if the page is closed or the request dies, reloading the screen re-attaches to the running backup, and a background tick continues a job whose request was killed. On the CLI, `wp pontifex export --resumable` makes the export continuable with `wp pontifex export --resume` after any interruption, and the finished archive is byte-identical to an uninterrupted one.

= What happens if a restore fails part-way through? =

A failed restore leaves your live tables as they were: a restore replays into staging tables and only cuts them over to the live tables in one atomic step, once the whole archive has been read and verified, so the restore itself never leaves your live tables half-changed. (A `--url` migration runs afterwards, directly against the live database, so a failure during that step can leave it partly rewritten.) Files are written directly as the restore goes, so a failure part-way through can leave some already written. For that reason Pontifex takes a safety archive of your site before every restore (unless you pass `--no-rollback-archive` on the CLI, which skips it — and with it, the automatic recovery) and, if the restore then fails, automatically replays it (unless PHP itself dies outright — out of memory, or a host time limit — in which case nothing is replayed and you roll back yourself), putting back every file and table it captured. Files the failed restore had already written that were not part of your site before are left in place, so check the site afterwards. If that automatic recovery does not run, or runs but cannot finish — a full disk, say — you can replay the same safety archive yourself: the Roll back control on the Pontifex → Restore screen, or `wp pontifex rollback` on the CLI. When the recovery does run, you are told plainly whether it succeeded.

= Can Pontifex store backups offsite? =

Yes. `wp pontifex destination add` configures a named SFTP destination on a server you own, and `wp pontifex export --destination=<name>` uploads the finished archive there after writing it locally. `wp pontifex destination pull` fetches an archive back for recovery after a local loss.

= Does uploading a backup phone home? =

No. An offsite upload is a plain SFTP connection to the server you configured, using credentials you supply — Pontifex runs no service in between and holds none of your data. It connects only when you tell it to — an export with `--destination`, or a `wp pontifex destination test`, `archives`, `pull` or `prune`. It never connects on its own, and `wp pontifex doctor` checks your destinations without connecting at all.

= Does a backup include my .git directory? =

No. A `.git` directory — at the site root, in `wp-content`, or inside any plugin or theme — is left out of every backup by default, because it is version-control metadata rather than site content: it is regenerable from the same remote the working copy was cloned from, and carrying it into an archive means also carrying its full commit history everywhere that archive travels. Pass `--no-defaults` to `wp pontifex export` if you specifically want it included.

== Changelog ==

The full, detailed changelog is maintained in `CHANGELOG.md` in the source repository. Recent releases:

= 1.1.0 =
* Verifying a backup now checks whether a restore would accept it, not only whether the file is undamaged, and answers with one of three verdicts. Sound means intact and restorable. Broken means damaged. Refused is new and means the backup is NOT damaged — every fingerprint matched — but a restore will not accept it, because it would place a symbolic link outside your site or its contents contradict what it says it holds; keep such a file, do not restore it, and find out where it came from, because Pontifex never produces one. A server that has no room to restore is reported beside the verdict and never as the verdict, because a full disk is not a damaged backup. Fixed: a failed import, export or rollback re-raised the failure into WordPress's fatal handler, so you saw a page of PHP internals and "There has been a critical error on this website" instead of an explanation; all three now say what went wrong and what to do. Fixed: `import --dry-run` ran fewer checks than the import it claimed to rehearse, and now runs all of them. Fixed: the admin Restore screen refuses a backup it will not accept before making the safety copy rather than after, saving minutes of work and a second copy of your site on disk. Also: messages no longer begin with the name of Pontifex's own internal code, which meant nothing to anyone outside the project; that detail goes to the log instead, which now records the exact file and line. No breaking changes.

= 1.0.3 =
* Closes the last of the findings from the audit behind 1.0.1 and 1.0.2. Fixed: the Backup screen silently rewrote the exclusion patterns you typed, stripping every percent sign followed by two hex digits — sensible for a web address, wrong for a file path, where those are just characters in a name. `wp-content/uploads/2024%2F*` became `wp-content/uploads/2024*`, which leaves out vastly more than you asked, and those files were missing from every backup afterwards; a scheduled backup stores the pattern, so every unattended run reused the rewritten version. If you use exclusion patterns containing a percent sign, check them on the Backup screen and take a fresh backup. Fixed: pruning an offsite destination reported how many old backups it removed but nothing about the ones it could not, so a destination quietly filling up looked like a destination being kept tidy. No breaking changes.

= 1.0.2 =
* Closes the rest of the findings from the audit behind 1.0.1. Fixed: automatic pruning of offsite backups could delete the NEWEST one if you had named it yourself rather than letting Pontifex name it — the backup you took just before an upgrade was exactly the shape at risk, so re-check any offsite destination you prune. Fixed: a restore stopped part-way through if the backup contained a read-only directory, such as a hardened uploads folder, leaving a site that was neither the old one nor the backup. Fixed: on Windows, downloading, deleting, verifying or restoring from the admin screen all reported the backup could not be found, even though it was listed there. Fixed: several screens said a backup contains the whole database — it contains the tables belonging to this WordPress site, so tables another application keeps in the same database are not included. Pontifex can also now tell you which kind of problem it hit rather than one generic message. No breaking changes.

= 1.0.1 =
* A security and correctness release, from an audit of 1.0.0 that found three defects every quality gate had passed. Fixed: an archive could disguise a symbolic link as an ordinary file and slip past the check that keeps links inside your site — changing two bytes of the archive's index was enough, and every integrity check still passed, so restoring an archive from someone else could create a link pointing at wp-config.php. Fixed: a restore altered content that mentioned its own database table in backticks, writing `pontifexstg_` permanently into posts and stored settings, and where that text sat inside a settings array the array stopped being readable and its plugin silently reverted to defaults. Fixed: verification skipped one of its checks unless given a memory budget, so it could call an archive sound that restoring would refuse. Changed: Pontifex now refuses to load on WordPress multisite, which it has never supported — it reads one table prefix and a network has more, so it would have backed up part of a network while appearing to back up all of it. No breaking changes.

= 1.0.0 =
* The stable release. From here the public API is frozen and the `.wpmig` archive format specification is locked, so a backup taken today stays readable by every future version of Pontifex and can always be opened without the plugin. Fixed: a restore on a host that cannot create symbolic links — common on shared hosting — used to overwrite files and then stop part-way, leaving a site that was neither the old one nor the backup; it is now refused before anything is changed, and a backup containing no symbolic links is never affected. Also fixed: three untrue statements in this readme. It told you to encrypt with `export --passphrase`, a flag that has never existed, so following it produced an UNENCRYPTED backup with no error — the real flags are `--encrypt` and `--passphrase-stdin`, so re-take any backup you believed was encrypted. It said Pontifex never contacts any remote service, which stopped being true when offsite SFTP destinations shipped in 0.8.0. And it described Pontifex as a WP-CLI plugin, when the admin interface has been most of the product since 0.5.0 — the installation steps now work without a terminal, and `wp pontifex doctor` now also reports whether this host supports symbolic links. No breaking changes.

= 0.9.5 =
* Security release. If you ever restore or import an archive you did not create yourself, upgrade before you do it again. A restored symbolic link could point outside your site: a backup could plant a file under uploads that pointed at wp-config.php, and because web servers follow such links, an ordinary web request then returned your database password and authentication salts. Measured before the fix, eight of ten hostile shapes were written and five of them handed the secret back. Links are now resolved the way the operating system resolves them, across the whole backup, before anything is written; layouts that legitimately point outside wp-content, such as Composer-managed sites, keep working. A forged backup can also no longer write into Pontifex's own folder, where your safety archives and the rule keeping database backups off the web live. **Breaking:** if your site pins a trusted signing key in PONTIFEX_PUBLIC_KEY, an uploaded backup must now be signed with it — unsigned or differently-signed uploads are refused. A site with no key configured is unaffected. Also: a backup too large for this installation to read back is refused before it is written rather than after; a restore that would run out of disk is stopped before it changes anything; a force-killed backup can be resumed again; opening a large backup no longer dies without explanation; and the scheduler no longer records failures that never happened.

= 0.9.4 =
* Security release. If you ever restore or import an archive you did not create yourself — a backup from another site, a file someone sent you, a migration between servers — upgrade before you do it again. Restoring an archive replayed the SQL inside it against the live database almost verbatim, so an archive carrying one extra statement could set a user's password and take over the destination site's administrator account, needing no unusual database privilege. Every statement in a database chunk must now match one of a small set of shapes naming that chunk's own staging table, a semicolon outside quoted text is refused so nothing can be smuggled behind an acceptable-looking statement, and once a table has been created the database is asked what it actually built rather than the archive being taken at its word. Two ways an archive can still read data it should not remain, both documented: an archive from a source you do not trust should not be restored, and this reduces the consequences rather than removing them. Also added: an export now reports how many files it could not determine a type for, so a host-wide failure is visible instead of silently recording every file as raw bytes. No breaking changes.

= 0.9.3 =
* Operational hardening, including a silent data-loss fix. The manifest scanner dropped the entry immediately after any excluded directory; when that directory was empty — as several inside every git checkout are — the lost entry was a real site file, missing from an archive that still verified as sound. If you run a site deployed from git, or exclude any directory that happens to be empty, re-take your backups after upgrading. Also: `.git` is now left out of backups by default at any depth (`.github`, `.gitignore` and friends stay in; `vendor` and `node_modules` are deliberately kept, since a restored site needs them); backup, restore and rollback share one lock across both the admin and the CLI, so they can no longer run against the site at the same time; backup lists show each archive's true source and real creation date read from its own provenance, rather than an uploaded file's upload time; and a backup no longer appears to hang at a full progress bar while it finishes, having also stopped re-reading every file on every pass. No breaking changes.

= 0.9.0 =
* Admin legibility. A sound verify now shows a full proof panel — verdict, entry count, size, what the archive contains, created date, format version, and a link to the published format specification — instead of a single line. Every backup list gains a "Contains" column, the Verify screen re-attaches to a running check after a page reload, and selecting a backup taken on another site hints that its links can be rewritten to this one. Fixes: a fatal-killed backup no longer shows as "running"; a restore that signs you out now reports honestly and reloads once you log back in, instead of freezing; and the backup list no longer shows phantom rows for stray files. No breaking changes.

= 0.8.0 =
* Offsite SFTP destinations. Upload a finished backup to an SFTP server you own with `wp pontifex export --destination`; a new `wp pontifex destination` command adds, tests, lists, prunes, and pulls archives back for recovery. Host keys are pinned and credentials come from an environment variable, never a flag. Per-destination retention keeps the newest N with a floor that never prunes to nothing, and `wp pontifex doctor` checks each destination without connecting. SFTP only this release (an S3 adapter was deferred). No breaking changes.

= 0.7.0 =
* Selective content. `export --exclude`/`--exclude-table` drop named files and database tables from a backup; `--files-only` and `--db-only` capture just the files or just the database, each restorable on its own and leaving the other half of the live site untouched. Scheduled backups inherit the configured exclusions, and `verify` and the admin Verify screen now state what an archive contains. No breaking changes.

= 0.6.0 =
* Resumable and scheduled exports. `export --resumable`/`--resume` survives timeouts, lost connections, and killed processes and continues where it stopped, byte-identical to an uninterrupted export. Scheduled backups run daily or weekly at a UTC hour, unattended, pruning to a retention count, with a self-healing cron ticker. New `wp pontifex schedule` command and a Scheduled backups section on the Backup screen, both with a next-run/liveness readout. Admin backups now run as persisted jobs, so reloading the page re-attaches to a running backup. No breaking changes.

= 0.5.0 =
* The admin interface: Overview, Backup (progress and cancel), Verify, Restore/Rollback with a pre-restore safety archive, and cross-server backup upload. Engine hardening throughout: atomic staged-table restores, snapshot-consistent exports, streaming restores within web memory limits, and changed-file detection on export. Breaking: supplying or pinning a trusted public key now makes the archive signature mandatory. Backups now default to content-only (wp-content plus the whole database); use --whole-site for full clones.

= 0.4.6 =
* Distribution readiness: a wp.org `readme.txt`, a `.distignore` and production build, internationalised CLI output, and Plugin Check tidy-ups. No functional changes to backup or restore.

= 0.4.5 =
* Quality cleanup: dead-code removal, type and analysis tightening, docblock re-sync, a passphrase wording fix, and small hardening.

= 0.4.4 =
* Security leftovers: secret key material is wiped from memory on destruction, and absolute filesystem paths are kept out of the diagnostics bundle and user-facing messages.

= 0.4.3 =
* Correctness fixes.

= 0.4.2 =
* Security hardening from a full audit.

== Upgrade Notice ==

= 1.1.0 =
Verifying a backup now also checks whether a restore would accept it, and answers sound, broken, or refused — refused meaning intact but unsafe. A failed import, export or rollback explains itself instead of printing a crash. Messages no longer name Pontifex internals.

= 1.0.3 =
If you use exclusion patterns containing a percent sign, check them on the Backup screen: they were being silently rewritten to exclude far more than you asked, so files were missing from every backup. Pruning an offsite destination now reports deletions it could not make.

= 1.0.2 =
Pruning of offsite backups could delete the newest one if you named it yourself; re-check any destination you prune. A read-only directory in a backup could stop a restore part-way. The admin backup screen did not work on Windows at all. No breaking changes.

= 1.0.1 =
Security and correctness. A crafted archive could plant a symbolic link pointing outside your site, and a restore could write `pontifexstg_` into posts and settings that name a table. Upgrade before restoring an archive you did not create. Multisite is now refused.

= 1.0.0 =
The stable release: the public API and archive format are now locked. If you ever ran `export --passphrase` believing it encrypted a backup, it did not — that flag never existed. Use `--encrypt` and re-take that backup. A restore is now also refused up front on a host that cannot finish it.

= 0.4.6 =
Distribution readiness and internationalisation; no functional changes to backup or restore.
