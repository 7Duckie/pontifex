# 0011 — export: dump the database inside a consistent snapshot on its own connection

- **Status:** Proposed, 2026-07-12.
- **Deciders:** 7Duckie (v0.5.0 hardening).

## Context

The export walks the database with many queries over a stretch of wall-clock
time: a table list, a row count and a schema read per table, then one
row-window SELECT per chunk. Ordering those windows by primary key (PR #96)
made the pagination a stable total order — but only over a database that is
not changing. On a live site, rows inserted, deleted, or updated *during* the
export can still shift between chunk windows or tear related tables apart
(a post captured without its postmeta, an order without its items). The backup
verifies SOUND — every hash is over what was actually captured — while being a
picture no single moment of the site ever looked like.

This is the exact problem mysqldump's `--single-transaction` solves: set the
isolation level to REPEATABLE READ and open a transaction with a consistent
snapshot before the first read, and every subsequent SELECT sees the database
as it stood at that instant, without blocking any application writes (InnoDB
MVCC provides the old row versions).

One wrinkle is Pontifex-specific. The admin backup writes progress transients
mid-export on the global `$wpdb` connection. If that same connection held the
snapshot transaction, those writes would join the transaction and stay
invisible to the polling request until commit — the progress bar would freeze
for the whole export. mysqldump has no such problem because it dumps on a
dedicated connection; the fix is to do the same.

## Decision

- **The export-side database adapter gets its own connection.** A new
  `WordPressContext::dedicated_wpdb_connection()` opens a second `wpdb` from
  the same credentials (via a small `wpdb` subclass that connects *without
  bailing*, so a host that caps connections cannot `wp_die` the request), and
  `ExportRunner::default_manifest_builder()` — the single construction point
  all three export orchestrators share — builds the `WpdbAdapter` on it.
- **A consistent snapshot opens before the first read.**
  `WpdbAdapter::begin_consistent_snapshot()` issues
  `SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ` and
  `START TRANSACTION WITH CONSISTENT SNAPSHOT` on the dedicated connection.
  Because the chunk SQL providers capture the same adapter instance, the
  snapshot spans everything: the table list, row counts, schemas, and every
  row window realised later during the archive write.
- **Progress stays live.** Transients, counters, and the single-runner lock
  all remain on the global connection, unaffected by the snapshot.
- **Fail open to today's behaviour.** If the dedicated connection cannot be
  opened (shared hosts with `max_user_connections = 1`) or the snapshot
  statements fail, the builder falls back to the global connection with no
  snapshot — exactly the pre-ADR behaviour, ordered but unsnapshotted. A
  backup that is possibly fuzzy beats no backup; the snapshot is a consistency
  upgrade wherever the environment allows it, applied automatically.
- **The snapshot is released with the export's adapter.** An open snapshot
  holds shared **metadata locks** on every table it has read, and those block
  DDL — including Pontifex's own restore cut-over RENAME when a pre-import
  safety archive dumped the same tables moments earlier in the same request
  (found empirically: the first integration test hung its own teardown DROP).
  The release must be deterministic, and it cannot ride the connection object:
  WordPress retains hidden references to every `wpdb` instance, so a
  destructor on the connection never fires mid-request (also found
  empirically). It therefore rides the **adapter**, a plain object with no
  hidden owners: `WpdbAdapter::__destruct()` commits any snapshot it opened,
  and the orchestrators hold the adapter only through locals, so an export's
  return releases the locks at once. `end_consistent_snapshot()` exists for
  callers needing the release at a precise moment, and `DedicatedWpdb` closes
  its connection at script shutdown as a last-resort tidy-up. An integration
  test proves DDL on a dumped table does not block once the adapter is
  released.

## Consequences

- Exports use one extra database connection while they run, where the host
  allows it.
- Only InnoDB tables are snapshot-consistent — MyISAM or MEMORY tables keep
  today's fuzziness (mysqldump's own documented limitation; WordPress has
  defaulted to InnoDB for over a decade).
- Concurrent DDL (`ALTER`/`CREATE`/`DROP`/`RENAME`/`TRUNCATE` by another
  plugin mid-export) is not isolated by a consistent read and can make a
  dumped table's SELECT fail or read wrongly — also mysqldump's documented
  caveat. Pontifex's own restore staging never runs during an export (the
  single-runner locks).
- A long export holds the snapshot open, which makes InnoDB retain undo log
  for the duration on a busy site — the standard, accepted cost of
  `--single-transaction`.
- The restore/verify/rollback adapters are untouched: writes belong on the
  main connection.

## Alternatives considered

- **Snapshot on the global connection** — rejected: the admin progress
  transients would join the transaction and the progress bar would freeze
  until commit; and any other mid-export write on that connection would be
  deferred with it.
- **Per-table transactions** — rejected: consistent within a table but still
  tears related tables apart, which is the failure that matters (posts vs
  postmeta).
- **`LOCK TABLES` / `FLUSH TABLES WITH READ LOCK`** — rejected: blocks the
  live site's writes for the export's duration; MVCC gives consistency
  without blocking anyone.
- **Refuse to export when the dedicated connection fails** — rejected: a
  possibly-fuzzy backup (today's behaviour) beats no backup on the cheapest
  hosts, which are exactly where Pontifex must keep working.
