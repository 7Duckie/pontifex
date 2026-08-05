# 0019 — restore: anchor db_chunk statements to the engine's own identifier, not a parsed verb

- **Status:** Accepted, 2026-07-28. Implemented and shipped in v0.9.4.
- **Deciders:** 7Duckie (security hardening, db_chunk containment fix).

## Context

A `.wpmig` archive is untrusted input the moment it did not come from this
site's own most recent export. Cross-server migration — "someone hands you a
`.wpmig` file" — is a documented core use case, not an edge case, so a
restoring reader must treat every byte of every chunk's payload as
attacker-controlled, exactly as the threat model already states for archive
contents on import.

`DatabaseWriter` replays a `db_chunk` by rewriting the **one** table
identifier the chunk itself declares — per ADR 0009, to the staging identifier
`pontifexstg_<destination table>` — and then executing the chunk's payload.
Because MySQL's `mysqli` driver refuses a query string containing more than
one statement, the payload is first split on `;\n` and each resulting piece is
handed to its own `query()` call. That splitting step is what defeats the
driver's own protection: mysqli's stacked-statement refusal exists precisely
to stop a payload from smuggling a second, unrelated statement past a caller
that only intended to run one — but Pontifex's replay loop never asks the
driver to run more than one statement per call, so the refusal never engages,
and a smuggled statement runs exactly as if the caller had asked for it.

The identifier rewrite is also narrower than it looks: only the declared
table's identifier is rewritten to the staging name. Any other statement
smuggled into the same chunk, naming a different table (or the same table by
its live name), executes with that identifier untouched — against the **live**
database, not the staging copy, and therefore outside anything the staging
model or `abort_staging()` can undo. This was proven against real MySQL: a
chunk declaring an unremarkable table, whose payload also carried
`UPDATE \`wp_users\` SET user_pass = ... WHERE ID = 1`, changed the live
administrator's password. `UPDATE` on an existing table needs no privilege
beyond the ordinary database access every WordPress install already grants
its own database user, so this is silent, unprivileged administrator takeover
on any real install that restores a hostile archive.

## Why the obvious defences fail

Two more targeted defences were tried first, and both were defeated
empirically before this design was settled on.

**A verb-plus-target allow-list** — permit a statement only if it starts with
an allowed verb (`DROP`, `CREATE`, `INSERT`) and names the expected target
identifier — sounds sufficient but is not. A `CREATE VIEW` statement named
exactly as the expected target passes the verb-plus-target check (`CREATE`,
correct name), and MySQL's updatable-view mechanism means a subsequent
ordinary `INSERT` into that same name — which also passes the check, same
verb-plus-target logic — writes straight through the view into whatever live
table the view's definition actually points at. The allow-list sees two
statements it approves; the database performs a live write neither statement's
surface form disclosed.

**Verb extraction cannot be done safely at all.** Every one of the following,
tested individually, defeated naive extraction of "the verb this statement
starts with" while the statement still executed against real MySQL: a leading
`/* ... */` block comment; a leading `-- ` line comment; a leading `#` line
comment; MySQL's conditional-execution comment syntax `/*!40101 ... */`
(these are not comments to the server — they execute); a form-feed character
(`\x0c`) placed before the verb; a verb written in mixed case; and a verb
split across an executable comment so that no contiguous substring matches
any known verb. Comment-stripping, whitespace-normalisation, and case-folding
are each a transformation an attacker gets to attack — every one of them was
tried, and every one was defeated. A validator with no such transformation
step has no such step to defeat.

## Decision

Anchor each statement's byte-zero shape to an identifier the restore engine
composed itself, and never to anything read out of the archive.

Adversarial re-testing of this design against real MariaDB, before it
shipped, found two further gaps beyond the one described in Context above,
and both are folded into the design below rather than tracked separately.
First, the shape check's `CREATE TABLE` case anchors only the statement's
opening bytes and deliberately never parses the body — a real table can
legitimately carry a `FOREIGN KEY` reference there — which left the body
free to name a storage engine that turns the "staged" table into a writable
alias for a live one; this is closed by asking the server, not the payload,
what the created object actually is. Second, the executable-semicolon scan
tracked quoted literals but had no model of SQL comments, so a single
unbalanced quote-like byte placed inside a comment desynchronised it for
the rest of the statement; this is closed by teaching the scan to track
comment state as well as quote state.

A further round of adversarial testing, once those two fixes were in
place, found two more gaps, and both are also folded into the design
below rather than tracked separately. First, the server-fact check's
read of `CREATE_OPTIONS` sees a table-level `DATA DIRECTORY`/
`INDEX DIRECTORY` clause, but the same clause can be attached to a single
partition instead of to the table as a whole, and a partitioned table's
`CREATE_OPTIONS` reports only the single word `partitioned` — nothing
about where any individual partition's data actually lives. Proven
against real MariaDB, a per-partition `DATA DIRECTORY` clause still
reached the filesystem after the table-level check above had already
shipped, because `CREATE_OPTIONS` alone had nothing to report; this is
closed by also reading the server's own partition catalogue
(`information_schema.PARTITIONS`) for the same staged identifier. Second,
the executable-semicolon scan's exception for MySQL's conditional-
execution comment syntax, `/*! ... */`, did not extend to MariaDB's own
equivalent marker, `/*M! ... */` — a distinct, MariaDB-specific spelling
of the same mechanism, likewise executed by the server rather than
treated as an inert comment. Tested directly against real MariaDB, a
semicolon placed inside an unrecognised `/*M! ... */` block was rejected
by the server itself as a syntax error in every case tried, so — unlike
the nine comment-desync variants the previous round found — this is
recorded as a specification gap in what the scan claimed to recognise as
executable, not as a proven exploitable bypass; it is closed regardless,
by scanning `/*M! ... */` exactly as `/*! ... */` is already scanned.

- **The identifier comes from the engine, not the archive.** The expected
  identifier is the staging table name `DatabaseWriter` already built for this
  chunk under ADR 0009. It is never taken from the chunk header's `table_name`
  field or from anything inside the payload — those are archive-supplied
  metadata, and archive-supplied metadata is exactly what an attacker
  controls.
- **A small, exact allow-list of statement shapes.** Before any statement in
  the chunk executes, every one of the chunk's `;\n`-terminated statements —
  the same split points `statement_count` already counts — must begin, at
  byte offset 0, with one of:
  - `DROP TABLE IF EXISTS` followed by the engine's backtick-quoted staging
    identifier;
  - `CREATE TABLE` followed by the engine's backtick-quoted staging
    identifier;
  - `INSERT INTO` followed by the engine's backtick-quoted staging identifier,
    in either of its two legitimate forms — with an explicit column list, or
    going straight to `VALUES` with none.

  Any statement that does not begin with one of these exact shapes refuses
  the statement, and refusing one statement refuses the whole chunk.
- **A server-fact check on the created object, because the shape check
  cannot see the `CREATE TABLE` body at all.** The permitted `CREATE TABLE`
  shape anchors only the bytes up to and including the opening `" ("`;
  everything after that — the column and constraint list, and any
  trailing table options — is `SHOW CREATE TABLE` output the check
  deliberately does not parse, because a real table can legitimately carry
  a `FOREIGN KEY` reference there. That silence is exactly what a
  storage-engine clause exploits: naming MySQL's built-in `MERGE` engine
  (or `FEDERATED`/`CONNECT`, where the server compiles them in) in the
  body turns the staged identifier into a writable alias for a table the
  chunk never declared, a connection to a remote server, or a local file
  — entirely through bytes the shape check never inspects, and entirely
  compatible with a subsequent `INSERT INTO` of the same staged identifier
  that satisfies the ordinary shape check on its own merits. A
  `DATA DIRECTORY` or `INDEX DIRECTORY` clause in the same body is the same
  route again: not a read, a **write** that points the table's on-disk
  storage at an arbitrary path the database process can reach — and that
  clause can be attached either to the table as a whole or, independently,
  to a single partition; a partitioned table's `CREATE_OPTIONS` reports
  only the word `partitioned`, so a check that reads `CREATE_OPTIONS`
  alone cannot see a per-partition directory clause at all. None of
  this can be closed by parsing the body for "known-bad" clauses — the
  same failure shape as the verb-extraction defences rejected below.
  Instead, once a chunk's `CREATE TABLE` statement has executed, the
  writer queries the server's own catalogue for the exact staged
  identifier just created, and refuses the whole restore, before any
  further statement for that table executes, unless: the reported storage
  engine is on an allow-list of ordinary local row-store engines — InnoDB,
  MyISAM, MariaDB's Aria, and MEMORY, and nothing else — engines that hold
  only their own rows, on local disk, and expose no cross-table,
  cross-connection, or file-path clause; the reported `CREATE_OPTIONS`
  name none of a unioned table list, a remote connection, a data
  directory, or an index directory; the same staged identifier's own
  partitions, read separately from `information_schema.PARTITIONS`
  because `CREATE_OPTIONS` alone reports a partitioned table only as
  `partitioned`, name no data directory or index directory of their own;
  and the catalogue reports the object as an ordinary base table, not a
  view or anything else a `CREATE TABLE` statement can be made to
  produce. This check is load-bearing, not extra
  hardening on top of an already-sufficient shape check: the shape check's
  silence about the body is by design, so without the server check the
  claim that a chunk can write only to the table it declared is false, as
  the Consequences section below proves end-to-end. A future maintainer
  who reads only the byte-offset shape check and judges the server check
  redundant, on a table whose name and opening bytes already look right,
  would silently reopen this hole.
- **Byte-exact and case-sensitive, deliberately.** No comment-stripping, no
  whitespace normalisation, no case-folding. Every defence tried above failed
  because it first transformed the input and then matched against the
  transformed form; this check matches the untransformed bytes at a fixed
  offset, so there is no transformation step left for an attacker to hide
  inside.
- **Allow-list, never deny-list.** The permitted set is exactly the three
  shapes above; nothing is refused by pattern-matching "known-bad" input,
  because scanning for bad patterns is the same shape of problem as verb
  extraction and fails the same way.
- **Whole-payload validation before any execution.** Every statement in the
  chunk is checked before the first one runs. A chunk that fails partway
  through is never partially executed and then refused; it is refused before
  execution begins.
- **Every statement is also refused if it carries a semicolon the database
  would act on.** The shapes above anchor only a statement's *opening*
  bytes; they say nothing about what follows a sanctioned opening. The
  `;\n` split leaves an embedded `; ` (a semicolon not immediately followed
  by a newline) untouched, so a payload can carry a statement whose opening
  satisfies one of the three shapes and then continue, past that semicolon,
  into a second and unrelated statement — for example `INSERT INTO
  `pontifexstg_wp_options` VALUES (1); UPDATE `wp_users` SET user_pass =
  ... WHERE ID = 1`. Every statement is scanned for a semicolon outside a
  quoted literal (single-quoted, double-quoted, or backtick-quoted, with a
  backslash recognised as an escape inside the first two) that is followed
  by further content, and refused if one is found; this scan is what
  actually stops the second statement from executing. Statements were
  already executed one at a time via the adapter, never as a
  multi-statement query, so mysqli's own refusal of a multi-statement query
  never gets the chance to engage once the payload has been split into
  single-statement calls — that is the vulnerability this ADR opened with.

  The scan also tracks SQL's comment forms, not only its quoting, because
  a scan that tracks quoting alone can be desynchronised from inside a
  comment: a single unbalanced quote-like byte placed inside a `-- `
  line comment, a `#` line comment, or a `/* ... */` block comment leaves
  the scanner believing it remains inside a literal for the rest of the
  statement, so a semicolon that follows is read as opaque content instead
  of a statement boundary. Adversarial testing against real MariaDB found
  nine such variants and all nine were accepted by the scan's first
  version — for example `CREATE TABLE `pontifexstg_t` (id INT) /* don't
  */; UPDATE `wp_users` SET a=1` executed both statements, the unmatched
  `'` inside the comment having desynchronised the scanner's quote state.
  The scan now recognises all three comment forms and treats their
  contents as opaque, with two deliberate exceptions, both
  conditional-execution comment syntax the server itself treats as live
  SQL rather than as a comment: MySQL's `/*! ... */` (optionally carrying
  a version number, as in `/*!50700 ... */`) and MariaDB's own equivalent
  marker, `/*M! ... */` (optionally carrying a version number the same
  way, as in `/*M!100108 ... */`). Neither is treated as a comment,
  because neither server treats its own marker as one — the bytes either
  encloses are ordinary SQL the server executes whenever the version
  condition is met, so a semicolon inside either is scanned exactly as if
  the surrounding markers were not there. The `/*M!` recognition was added
  after a further round of adversarial testing found the scan's first
  version treated it as an ordinary opaque block comment; tested directly
  against real MariaDB, a semicolon placed inside one was rejected by the
  server as a syntax error in every case tried, so this is recorded as a
  specification gap the scan's own claim about what MariaDB executes had
  left open, not as a proven bypass of the same kind as the nine variants
  above.

  Before this scan existed, containment of a stacked statement rested on
  driver behaviour this code neither stated nor controlled. The scan is a
  lexical pass over the same statement bytes the shape check reads — it
  tracks quoting and comment state byte by byte — not a SQL parser: it
  builds no syntax tree and does not understand every construct MySQL's
  own parser does. Within that scope, it removes the driver dependency for
  every desynchronisation route adversarial testing found, including the
  nine comment-based variants above and the `/*M!` recognition gap. That
  is a narrower claim than "cannot
  in principle be desynchronised," and is stated here as such rather than
  as a proof: Pontifex no longer relies on mysqli's stacked-statement
  refusal to catch what this scan is meant to catch, for every route found
  so far.
- **`CREATE VIEW` is refused by the ordinary allow-list on its own merits;
  a second check only supplies the reason.** A view living under the
  site's own table prefix is something Pontifex's own exporter could in
  principle capture — a plugin can create one. But an archive containing a
  view already fails to restore correctly today: the replay and staging
  cut-over model is built around tables, not views, so a view-bearing
  archive either fatals outright or leaves staging debris that breaks a
  later restore. No real MySQL server ever emits a statement beginning
  `CREATE VIEW`: it writes the view's full definition, for example
  `CREATE ALGORITHM=UNDEFINED DEFINER=\`root\`@\`%\` SQL SECURITY DEFINER
  VIEW \`n\` AS select ...`, so a view statement already fails every
  permitted shape unaided — it is not `DROP TABLE IF EXISTS`, not `CREATE
  TABLE` immediately followed by `" ("`, not `INSERT INTO`. No
  view-specific rule is needed to reject it. A second check, reached only
  once a statement has already failed every sanctioned shape, recognises
  that real emitted form — a statement beginning `CREATE ` that goes on to
  contain the keyword `VIEW` immediately followed by a backtick-quoted
  name — purely so the refusal message can name the reason as a view
  rather than report only "an unrecognised shape"; it decides the wording
  of the refusal, never the verdict. Refusing `CREATE VIEW` at the
  shape-anchor stage costs nothing that currently works, and closes off the
  view-then-insert bypass described above as a side effect. This is
  recorded here as a deliberate, documented behaviour change: an archive
  carrying a view is now refused with that reason named, rather than
  failing however it happened to fail before.

## Consequences

- **The guard — shape check plus server-fact check together — confines a
  chunk to writing only the table it declared, before cut-over; it does
  not make restoring a hostile archive safe.** That confinement claim is
  not true of the shape check alone, and adversarial testing against real
  MariaDB proved it before the server-fact check existed: a `CREATE TABLE`
  statement's body — the part after the sanctioned opening bytes, which
  the shape check deliberately never parses because a real table can
  legitimately carry a `FOREIGN KEY` reference there — can name a storage
  engine that turns the staged identifier into something other than an
  ordinary local table. `ENGINE=MRG_MyISAM UNION=(`wp_users`)
  INSERT_METHOD=LAST` made the staged identifier a writable, readable
  alias for the live `wp_users` table: a shape-perfect
  `INSERT INTO `<staging identifier>` (`ID`, `user_pass`) VALUES (99,
  'ATTACKER-PLANTED')` then wrote its row straight into the live table,
  the restore completed with no refusal, live row counts changed, and
  `abort_staging()` did not undo it — the write had never touched a
  staging table at all. `ENGINE=FEDERATED CONNECTION='mysql://...'` and
  `ENGINE=CONNECT TABLE_TYPE=XML FILE_NAME='/etc/hostname'` belong to the
  same family: an outbound network connection from the database server,
  and an arbitrary local file, respectively, both reachable purely through
  a body clause the shape check cannot see. A `DATA DIRECTORY` or
  `INDEX DIRECTORY` clause in the same body is the same route again —
  not a read, a **write**, pointing the table's on-disk storage at an
  arbitrary path the database process can reach. That clause can also be
  attached per partition rather than at the table level —
  `PARTITION BY RANGE (id) (PARTITION p0 VALUES LESS THAN (1000) DATA
  DIRECTORY = '/attacker/path')` — and a partitioned table's
  `CREATE_OPTIONS` reports only the single word `partitioned`, saying
  nothing about where any individual partition's data actually lives;
  proven against real MariaDB, this let a per-partition `DATA DIRECTORY`
  clause reach the filesystem even after the table-level check above had
  already shipped, because `CREATE_OPTIONS` alone had nothing to report.
  This is why the server-fact check also reads `information_schema.PARTITIONS`
  for the same staged identifier, described just below — the table-level
  `CREATE_OPTIONS` check on its own closes only the table-level form of
  this route, not the per-partition form.

  This is why the Decision above also asks the server what the staged
  identifier actually became, once `CREATE TABLE` ran: refusing the whole
  restore unless the reported engine is on an allow-list of ordinary local
  row-store engines — InnoDB, MyISAM, MariaDB's Aria, and MEMORY, and
  nothing else — the reported `CREATE_OPTIONS` name none of a unioned
  table list, a remote connection, a data directory, or an index
  directory, the same identifier's own partitions (read separately from
  `information_schema.PARTITIONS`, for the reason above) name no data or
  index directory of their own, and the catalogue reports an ordinary base
  table. That check is load-bearing, not optional hardening on an
  already-sufficient shape check — say it again here because it is the
  point this Consequences section exists to record: a future maintainer
  who reads only the byte-offset shape check, judges the server check
  redundant on a table whose name and opening bytes already look right,
  and removes it would silently reopen the
  MERGE/FEDERATED/CONNECT/DATA-DIRECTORY hole just described — both its
  table-level and per-partition forms — with no test short of a real
  MariaDB adversarial re-run likely to catch the regression.

  **The engine allow-list is a deliberate trade, and it has a cost an
  operator can hit during a real recovery.** InnoDB, MyISAM, Aria, and
  MEMORY are permitted because each holds only its own rows, on local
  disk, and exposes no cross-table, cross-connection, or file-path clause;
  every other engine is refused outright, including MySQL's `MERGE`
  engine (`MRG_MyISAM`: a writable alias over other tables, not local
  storage of its own), `FEDERATED` and `CONNECT` (each opens a connection
  to a remote server), and file-backed or storage-less engines such as
  `CSV`, `ARCHIVE`, and `BLACKHOLE` — every refused family exists
  specifically to reach something other than the engine's own local rows,
  which is exactly the property this guard exists to rule out. An
  operator whose site legitimately depends on a table using a refused
  engine — a plugin's audit log stored in `ARCHIVE`, say — has the whole
  restore refused outright, not silently degraded or worked around; that
  refusal is the guard doing its job, not a bug, but it is also a real
  operational cost for that operator. It is recorded here so it can be
  found in this document rather than discovered for the first time
  mid-recovery.

  What neither check constrains, and what no mechanism at this layer can,
  is what a permitted statement's own values *read* from elsewhere on the
  destination server — a read here is not idle: what it reads is written
  into the staged table, which the cut-over then installs as part of the
  restored site.

  A third route sat here until the row-count check was added: `CREATE
  TABLE `<staging identifier>` AS SELECT * FROM wp_users`, built with an
  ordinary local storage engine and no disallowed `CREATE_OPTIONS`, passed
  the server-fact check on its shape — engine, options, and base-table
  status were all exactly as expected — while its `SELECT` clause copied
  another table's data wholesale; the server check confirmed what the
  object *is* and had nothing to say about the rows a `SELECT` populated
  it with. Reading the just-created object's own exact row count,
  immediately after its `CREATE TABLE` executes and before any later
  statement in the chunk runs, closes the whole family: the `(cols)` and
  `(cols) AS` forms, `IGNORE`/`REPLACE SELECT`, a subquery standing in for
  the `SELECT`, and a `UNION` all populate at least one row the moment the
  `CREATE` runs, so every one of them is now refused — confirmed against
  real MariaDB, with zero false refusals against an ordinary empty
  `CREATE TABLE`. A `SELECT` that happens to return zero rows still
  passes — `CREATE TABLE `<staging identifier>` AS SELECT * FROM
  `wp_users` WHERE 1 = 0` builds a table exactly as an ordinary empty
  `CREATE TABLE` would — but a `SELECT` that returns nothing exfiltrates
  nothing either, so a row count, rather than a parse of the `SELECT`
  clause, is exactly the right check: it distinguishes a `CREATE` that
  moved data from one that did not, without the guard ever attempting to
  understand what the `SELECT` means. This is the same server-fact shift
  the engine/`CREATE_OPTIONS`/table-type check above already makes,
  applied to the one remaining question none of those checks answer: not
  what kind of table the `CREATE` built, but whether it already held data
  the moment it existed.

  Two routes remain open, proven against real MariaDB, and the row-count
  check does not and cannot reach either — both live inside an `INSERT`'s
  own `VALUES`, which is sanctioned statement content, not a `CREATE
  TABLE`'s catalogue facts, so there is no server-reported fact for a
  row-count-style check to read:
  - `INSERT INTO `<staging identifier>` VALUES (LOAD_FILE('/etc/hostname'))`
    reads an arbitrary file **from the filesystem of the machine running
    the database server** into the restored table. On the common
    single-host WordPress deployment — web server and database on one
    machine, the database user holding the `FILE` privilege,
    `secure_file_priv` unset, a combination a great many ordinary
    shared-hosting and small-VPS installs meet — this can read
    `wp-config.php` itself: the site's database credentials and
    authentication salts, copied into a table the restored site then
    serves. This is the more serious of the two, and was previously
    undocumented anywhere in this project.
  - `INSERT INTO `<staging identifier>` VALUES ((SELECT user_pass FROM
    `wp_users` ORDER BY ID LIMIT 1))` uses a scalar subquery inside
    `VALUES` to copy live data — here, an administrator's password hash —
    out of another table and into the chunk's own staged, and then
    restored, table.

  Neither of these two can be closed by adding more rejected patterns to
  the check: `LOAD_FILE` and a subquery are both ordinary, legal SQL that
  can appear anywhere inside a value expression the guard deliberately
  does not parse the meaning of, so a deny-list against today's examples
  leaves tomorrow's equivalent construction open — the same failure shape
  as the verb-extraction defences rejected above. They are documented
  here, as the guard's genuinely residual risk, instead of chased with
  more pattern-matching.

  Stated plainly and without alarmism: the guard — shape check,
  server-fact check, and row-count check together — prevents a chunk from
  writing to, or aliasing, any table other than the one it declared, and
  prevents a `CREATE`'s own `SELECT` clause from populating that table
  with another table's rows, before cut-over. It does not, and cannot by
  this mechanism, prevent a sanctioned `INSERT`'s own `VALUES` from
  reading other data on the destination server via `LOAD_FILE()` or a
  scalar subquery. What is read lands **in the restored site itself**,
  not sent back to whoever supplied the archive — the chunk doing the
  reading is the same chunk about to become live table data. The operator
  conclusion is unchanged from the rest of this document: an archive from
  an untrusted source should not be restored. This guard reduces, but
  does not eliminate, the consequences of doing so anyway.
- **It cannot, and does not try to, make an untrusted archive's data
  trustworthy.** A legitimate restore of a users-table chunk is *supposed* to
  replace the site's own `wp_users` table with the archive's version, once
  cut-over happens — that is what restoring a database backup means. An
  attacker who controls the content of an otherwise correctly-shaped
  users-table chunk can still plant an administrator account or password hash
  in data that shape anchoring correctly permits, because writing new row
  data into the declared table is exactly what an `INSERT` chunk is for. This
  fix defends the mechanism — execute only what was declared, only against
  what was declared — not the payload's meaning. An operator who restores an
  archive from an untrusted source is still trusting its contents, exactly as
  the staging model in ADR 0009 already assumed; this ADR does not change
  that, and should not be read as though it does.
- **Verify does not apply these checks.** `wp pontifex verify` is a hash-only,
  no-decode structural check (ADR 0010); it never executes SQL, so it never
  runs a chunk's `CREATE TABLE` statement either, and a hostile archive
  with a shape-violating `db_chunk`, or one whose `CREATE TABLE` body names
  a disallowed engine, `CREATE_OPTIONS`, or per-partition directory clause,
  still reports as verify-sound.
  Containment is enforced only at restore time, at the point the payload is
  actually about to run — the same division of labour ADR 0013 already draws
  for the size-lie check, worth stating plainly here rather than assumed.
- **The chunk payload splitter keeps its job; the driver dependency it
  created is substantially narrowed, not proven eliminated.** Splitting
  each chunk into individual `query()` calls remains necessary because
  mysqli accepts only one statement per call; that splitting is also what
  supplies the exact statement boundaries the shape and semicolon checks
  validate. Its previously undocumented side effect — quietly defeating
  mysqli's own stacked-statement refusal — was the reason these checks
  exist at the engine layer rather than being left to the driver. The
  executable-semicolon scan in the Decision above, now with a model of
  SQL's comment forms, closes every desynchronisation route adversarial
  testing found — including nine independent comment-based variants and
  the separate `/*M!`-recognition gap that defeated the scan's first
  version — without reintroducing a
  transformation step of its own for an attacker to subvert. But the scan
  is a lexical pass over statement bytes, not a SQL parser, so the honest
  claim is "closes every desynchronisation route found so far," not "closes
  every route that could exist"; it should not be read as licence to treat
  driver-level stacked-statement refusal as irrelevant defence in depth.
- **The format specification gains a normative reader obligation** (see
  [archive-format.md §6](../archive-format.md#6-entries)), so a third-party
  `.wpmig` reader is required to build the same defence rather than merely
  benefiting from this implementation of it.

## Alternatives considered

- **A verb-plus-target allow-list.** Rejected — defeated by a `CREATE VIEW`
  named as the expected target followed by an ordinary `INSERT` through it,
  which writes to whatever live table the view actually points at while both
  statements individually pass the check.
- **Verb extraction with comment-stripping, whitespace-normalisation, or
  case-folding.** Rejected — every normalisation step tried was itself
  defeated (leading block/line/hash comments, MySQL conditional-execution
  comments that execute, a leading form feed, mixed case, a verb split across
  an executable comment). A check with a transformation step has a
  transformation step to attack.
- **A full SQL parser or AST-based validator.** Considered as the more
  "principled" general solution, but rejected for this slice: it would have
  to correctly and completely recognise every MySQL comment form, every
  conditional-comment version gate, and vendor-specific syntax quirks to
  avoid being fooled the same way naive verb extraction was — reintroducing
  the same class of risk one layer up, while pulling in a large dependency
  surface this codebase otherwise avoids by choice. Shape anchoring needs no
  understanding of SQL semantics at all, because it checks fixed bytes at a
  fixed offset rather than parsing meaning. A parser-based layer remains open
  as future defence in depth if a bypass class against shape anchoring itself
  is ever found.
- **Permitting `CREATE VIEW` under the same shape rule.** Rejected — see the
  Decision above; refusing it costs nothing that currently works and removes
  a statement kind the replay and cut-over model has no story for cleaning up
  after.
- **Parsing the `CREATE TABLE` body for a deny-list of engine names or
  clauses (`ENGINE=MERGE`, `ENGINE=FEDERATED`, `DATA DIRECTORY`, and so
  on).** Rejected for the same reason verb extraction was: it is a
  transformation-and-pattern-match step over untrusted bytes, and every
  such step tried elsewhere in this design was defeated by a form the
  deny-list did not anticipate. `SHOW CREATE TABLE` output is not a fixed
  grammar Pontifex controls; a future MySQL or MariaDB version, or a
  storage engine not yet considered, could add another clause with the
  same effect and a different spelling. Asking the server what the object
  actually is, after creating it, does not have this gap: the server's own
  catalogue is authoritative about the engine and options a `CREATE TABLE`
  statement actually produced, whatever spelling was used to produce them.
