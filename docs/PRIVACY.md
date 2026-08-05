# Privacy

Pontifex is privacy-first by construction.

## What Pontifex collects

**Nothing.** Pontifex collects no data about you: no telemetry, no
analytics, no phone-home. It has no service, no account and no endpoint
of its own, and it contacts nothing on its own initiative. All work —
export, import, logging, counters — happens locally, on the machine
running WP-CLI or serving the admin screens, on data the Pontifex
authors never see.

There is exactly one way a byte can leave the machine, and you have to
ask for it: an **offsite destination** you configure yourself (v0.8.0
onwards), which uploads a finished archive over SFTP to a server whose
address, credentials and host key you supply. It goes to your server,
not ours. Configure no destination and nothing is ever sent. Every
network call in the plugin's PHP lives in that one component,
`src/Destination/`; a standing architecture test in the suite
(`tests/Unit/Architecture/NoNetworkOutsideDestinationTest.php`) scans
every PHP file under `src/` and fails the build if any of them, outside
`src/Destination/`, calls a known network function — the `curl_*`,
`wp_remote_*`, `ssh2_*`, `ftp_*` and socket families among them. It is a
source check over the plugin's own call sites, not a runtime sandbox.

## What Pontifex stores, and where

- **The archive (`.wpmig`)** you create, at the path you choose. It
  contains your site's content and the whole database — user
  accounts, password hashes, secret keys, and customer data. Treat it
  as highly sensitive: store it outside the web root and delete it
  securely when you are done. See
  [the import trust boundary](../.github/SECURITY.md#the-import-trust-boundary).
- **A local log file** at `wp-content/pontifex/logs/pontifex.log`
  (rotating). It records operations and failures for diagnosis; it
  never contains archive contents, secrets, or moved data.
- **Run counters** in autoload-off `wp_option`s
  (`pontifex_export_stats`, `pontifex_import_stats`,
  `pontifex_rollback_stats`): attempt / success / failure / byte
  tallies. Numbers only — no content.

## Telemetry

There is none, and any future telemetry would be **opt-in only** and
decided in the open (idea-bank Idea 001, currently deferred
indefinitely). This file is the standing record of that commitment and
the home for any such decision.

## Your responsibilities as an operator

Because a `.wpmig` holds your content and the whole database in one
file, you control its privacy once it leaves WordPress: choose a
secure transfer channel, keep archives out of web-accessible
directories, and delete them when they are no longer needed.

Encryption of archives at rest is opt-in per export, not automatic:
`wp pontifex export --encrypt` (an interactive, double-entry passphrase
prompt) or `--passphrase-stdin` (for scripts). An archive taken without
either flag — the default — is unencrypted and, if anyone can read the
file, readable in full, including password hashes, secret keys and
customer data. See [the threat model, §3](./threat-model.md#3-snapshot-files-at-rest)
for exactly what encryption does and does not protect against.

---

*Guidance, not legal advice; confirm with a qualified adviser before any
change that makes Pontifex handle personal data on users' behalf.*
