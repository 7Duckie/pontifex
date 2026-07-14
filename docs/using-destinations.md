# Using offsite SFTP destinations

This guide covers Pontifex's offsite destination feature: uploading a
finished `.wpmig` archive to storage you already own, so a lost disk
or a lost server no longer means a lost backup.

## What this is

A local-only backup is a single point of failure — if the disk, the
filesystem, or the whole server goes, every copy goes with it. A
**destination** is a named, reusable configuration for somewhere else
to keep a copy: in this version, your own SFTP server.

This is not a Pontifex cloud service. There is no Pontifex account, no
Pontifex-run relay, and no phone-home. An upload is an outbound SFTP
connection *you* configure, to *your own* server, using *your own*
credentials, and it only ever happens when you run it — either
directly with `export --destination=<name>`, or by pulling an archive
back later with `destination pull`. Pontifex never initiates a
connection on its own. (See [ADR 0017](./adr/0017-offsite-destination-adapters.md)
for the full design reasoning, including why SFTP is the only
destination type this version ships — an earlier plan to add an
S3-compatible adapter was dropped because the pure-PHP library it
needed pulled in native extensions, a GPLv3 licence, and a cloud-SDK
surface that didn't fit the project's minimal-dependency posture.)

Everything here is **CLI-only**. There is no admin-screen destination
surface yet, and scheduled backups do not push offsite in this
version — a large upload can't finish inside one web-cron tick, so
unattended offsite waits for a future chunked-resumable upload design.

## Configuring a destination

Add a destination once, then refer to it by name. The example below
uses key authentication, the default:

```
wp pontifex destination add offsite \
    --host=backup.example.com \
    --username=wp \
    --remote-path=/backups \
    --key-path=/home/wp/.ssh/id_ed25519 \
    --host-key=SHA256:AbCdEf1234567890… \
    --retention=7
```

Note it's `--remote-path`, not `--path` — WP-CLI reserves `--path` for
the WordPress install path.

To use a password instead of a key, pass `--auth=password` and name
the environment variable that holds the password with `--secret-env`
— never the password itself:

```
wp pontifex destination add offsite \
    --host=backup.example.com \
    --username=wp \
    --remote-path=/backups \
    --auth=password \
    --secret-env=PONTIFEX_SFTP_PASS \
    --host-key=SHA256:AbCdEf1234567890…
```

Then supply the value when you actually run a command that needs it,
as an environment variable on that one command — never as a flag,
never stored in plaintext:

```
PONTIFEX_SFTP_PASS='the-password' wp pontifex export --output=/tmp/site.wpmig --destination=offsite
```

Key authentication can also take an optional passphrase the same way,
via `--secret-env` naming the variable that holds the key's
passphrase.

### Credentials never travel as flags

A destination's secret — a password or a key passphrase — is never
written to the database and never passed on the command line. Only
the *name* of an environment variable is stored; the actual value is
read from the environment at the moment it's needed. This keeps
secrets out of shell history, process listings, and the WordPress
options table.

### Pinning the host key

By default, `destination add` warns if you don't pin a host-key
fingerprint, because trusting whatever key answers on first connection
is exactly how a man-in-the-middle attack goes undetected. Pin the
server's fingerprint with `--host-key=SHA256:…` so a connection to any
other key is refused outright.

To find the fingerprint, ask the server (or your hosting provider) for
its SSH host-key fingerprint in `SHA256:` form — for example, from a
machine that already trusts the server:

```
ssh-keyscan backup.example.com | ssh-keygen -lf - -E sha256
```

The surest way to get the exact fingerprint Pontifex will check — the
key type it actually negotiates — is to add the destination with any
placeholder `--host-key`, run `wp pontifex destination test <name>`,
and read the real fingerprint Pontifex reports in the refusal, then
re-add the destination with that value.

If you genuinely can't pin a fingerprint yet, `--insecure-host-key`
accepts any host key — but this defeats man-in-the-middle protection,
so avoid it outside a one-off test and pin a real fingerprint as soon
as you can.

## Uploading

Upload by naming the destination on export:

```
wp pontifex export --output=/tmp/site.wpmig --destination=offsite --yes
```

The archive is written locally first, exactly as any export is, and
only uploaded once that local file is complete. The upload runs
inside this CLI command, which has no web request timeout, so a large
archive isn't bound by one.

Two things to know:

- **Resumable exports can't use a destination yet.** `--resumable`
  and `--resume` are not available together with `--destination`: a
  resumed run wouldn't know to push the finished archive. Export
  without `--resumable` to upload directly, or upload a resumable
  export's finished archive afterwards with a plain
  `destination` command once it's complete.
- **An unencrypted upload warns.** The archive is leaving your server
  for storage whose safety Pontifex can't vouch for, so uploading
  without `--encrypt` prints a warning (it isn't blocked — it's your
  destination and your choice). Consider pairing `--destination` with
  `--encrypt`.

## Recovering

A destination is recoverable, not write-only — you can list what it
holds and fetch an archive back.

List what's stored there:

```
wp pontifex destination archives offsite
```

Pull one back, for recovery after a local loss:

```
wp pontifex destination pull offsite pontifex-2026-07-13-030000.wpmig --output=/tmp/restore.wpmig
```

`--output` is optional; without it, the archive is written under its
own basename in the current directory. `pull` refuses to overwrite an
existing local file at the target path.

Check a destination is actually reachable, authenticated, and
writable at any time with:

```
wp pontifex destination test offsite
```

## Retention

Set `--retention=N` when adding a destination to keep only the newest
`N` archives there; anything older is deleted after each upload, and
also on demand:

```
wp pontifex destination prune offsite
```

`--retention=0` (the default) keeps every archive — `prune` then does
nothing. When retention is set, archives are ordered **by remote
name**, not by remote modification time (which can be unreliable), so
give `--output` a sortable, timestamped name (for example
`site-2026-07-13-030000.wpmig`) whenever you rely on retention. There
is no automatic naming on the CLI — you choose `--output` — so if you
name archives unsortably, "oldest" is decided lexically and may not
match age. There's also a floor: retention can never prune a
destination down to nothing.

## Checking configuration

List what's configured:

```
wp pontifex destination list
```

`wp pontifex doctor` reports each configured destination's
configuration health — that the required settings are present, that
an `--auth=password` destination names a `--secret-env` and that
variable is actually set in the current environment, that a key
file exists and is readable, and whether a host key is pinned — all
**without connecting to the network**. It's a fast, safe first check;
`destination test` is the live one that actually opens a connection.

## Security notes

- Credentials are referenced by environment-variable name only —
  never a plaintext flag, never a plaintext stored value.
- SFTP host keys are pinned by default; `--insecure-host-key` is an
  explicit, loud opt-in, not the default path.
- Encrypt an archive with `export --encrypt` before sending it
  somewhere Pontifex can't vouch for the safety of.
- Removing a destination (`wp pontifex destination remove offsite`)
  only removes the local configuration — it does not delete anything
  already uploaded.
