# 0021 — restore: confine a symlink by the target the kernel will reach, decided before the first byte is written

- **Status:** Accepted, 2026-07-29. Implemented and shipped in v0.9.5.
- **Deciders:** 7Duckie (security hardening, restore symlink confinement).

## Context

A `.wpmig` archive is untrusted input the moment it did not come from this
site's own most recent export. Cross-server migration — "someone hands you a
`.wpmig` file" — is a documented core use case, and the threat model already
states that every byte read from an archive is untrusted
([threat-model.md §2](../threat-model.md)).

`FileWriter` places three kinds of entry under a destination root: files,
directories and symlinks. Two path defences already ship and both are sound as
far as they go. `resolve_safe_path()` refuses an entry **path** that is
absolute, contains a `..` segment, or contains a null byte, and — on a
content-only restore, which is the default and the only mode the admin UI
offers (ADR 0008) — refuses any path that is not `wp-content` or beneath it.
`assert_no_symlinked_ancestor()` walks every ancestor component of the entry
path and refuses the entry if any of them is a symlink, so a hostile archive
cannot plant a link and then write a file *through* it.

A symlink's **target** is governed by neither of those. It is checked by
`symlink_target_escapes_root()`, which refuses an absolute target and otherwise
collapses the target's `.` and `..` segments **textually** against the link's
own directory, requiring the result to be the destination root or beneath it.
The destination root there is `ABSPATH` — the whole site — not the content root
the entry paths are confined to. So on the default content-only restore, entry
paths may not leave `wp-content`, but a symlink may point anywhere inside the
site, including at `wp-config.php`.

The threat model's own summary line — "FILE paths validated
relative-and-within-wp-content (no traversal)" — is therefore true of paths and
false of link targets, and that gap is the vulnerability recorded below.

### The proven attack

Two entries, restored through the real `FileWriter` configured exactly as the
admin screen configures it (`new FileWriter( $root, false, 'wp-content' )` —
content-only, unsafe symlinks disallowed):

```
entry 1: symlink wp-content/uploads/hop      -> ".."
entry 2: symlink wp-content/uploads/leak.txt -> "hop/../wp-config.php"
```

Both are written. Entry 1's target collapses textually to
`<root>/wp-content`, inside the root. Entry 2's target collapses textually to
`<root>/wp-content/uploads/wp-config.php`, also inside the root. Neither
refusal fires.

The kernel does not collapse text. It dereferences `hop` **first**, reaching
`<root>/wp-content`; the following `..` then reaches `<root>`; and
`wp-config.php` resolves to `<root>/wp-config.php`. Reading `leak.txt` returns
the database credentials and the authentication salts. Because the entry sits
under `wp-content/uploads`, which every ordinary webserver serves as static
content, and because the link's own name ends in `.txt` rather than `.php`, the
server hands the file's bytes back instead of executing it: this is an
**unauthenticated remote read of `wp-config.php`**. On a site that already has
any symlink under `wp-content` — a Composer-managed install, a shared-uploads
setup — the first entry is unnecessary and **one entry suffices**.

The same textual collapse also fails to terminate on a symlink loop: two links
pointing at each other are each individually "inside the root", and a third
link resolving through them is permitted.

### Why the naive fix failed

The obvious repair — keep the textual collapse but root it at the **content
root** instead of `ABSPATH`, so a link may not leave `wp-content` — was built
and defeated by execution. It is algorithm **A** in the bench below. It refuses
the direct forms (`../../wp-config.php`) and misses the hop form entirely, for
exactly the reason above: a textual pass has no way to know that `hop` is a
symlink, and the kernel's first act is to dereference it.

It also produced **false refusals**, which matter as much as the miss. A
Composer-managed WordPress keeps its dependencies outside `wp-content` and
reaches them by link:

```
wp-content/mu-plugins/autoload.php -> ../../vendor/acme/lib/autoload.php
wp-content/languages               -> ../languages
```

The scanner records symlinks verbatim and never follows them
(`FileScanner::classify()` tests `isLink()` first, and `build_scanned_entry()`
stats the link rather than its target), so a site's **own** backup contains its
own links exactly as they are. Under algorithm A that site's own archive is
unrestorable — and so is any migration of it to a new server, which is the
feature Pontifex exists for.

Two structural facts make a mid-walk refusal worse than it sounds.
`RestoreRunner::walk()` has **no per-entry catch**: a throw from
`FileWriter::write_entry()` propagates straight out of the loop, so a refusal
on entry 12,000 of 47,000 leaves a half-written site. And
`RestoreController` hard-codes the unsafe-symlink override to `false`, so an
administrator who hits a false refusal in the browser has no way to complete
the restore at all.

## Prior art

This is a solved problem outside WordPress, and the mature answers agree on one
thing above all: **nobody solves it textually.** All three reference
implementations resolve against the real filesystem, order the writes so the
hazard cannot exist, or both.

**Python's `tarfile`**, whose extraction filters were added by
[PEP 706](https://peps.python.org/pep-0706/) in response to CVE-2007-4559,
treats a link's target as a first-class hazard. `filter='data'` refuses an
absolute link target outright (`AbsoluteLinkError`), refuses a link whose
target resolves outside the destination (`LinkOutsideDestinationError`), and
refuses a link that would resolve *to the destination directory itself* — the
source comment explains why: such a link "would replace it with a (sym)link,
redirecting the destination for all subsequent members"
([`Lib/tarfile.py`](https://github.com/python/cpython/blob/main/Lib/tarfile.py)).
The resolution is done with
`os.path.realpath(..., strict=os.path.ALLOW_MISSING)`, which resolves symlinks
already on disk — including ones extracted from earlier members — while
tolerating a path whose tail does not exist yet. That distinction is
load-bearing: plain `realpath()` is documented as possibly leaving a result
that "may still contain links or loops"
([`os.path` docs](https://docs.python.org/3/library/os.path.html)), and relying
on it was **CVE-2025-4517**, scored 9.4 CRITICAL by the PSF —
"allows arbitrary filesystem writes outside the extraction directory during
extraction with `filter=\"data\"`"
([NVD](https://nvd.nist.gov/vuln/detail/CVE-2025-4517), one of five filter
bypasses fixed together in June 2025, alongside CVE-2025-4330, CVE-2025-4138,
CVE-2025-4435 and CVE-2024-12718 —
[cpython#135034](https://github.com/python/cpython/issues/135034)).

Python adds a second, purely textual measure: it normalises link targets with
`os.path.normpath`, and the documentation states the cost plainly — "Note that
this removes internal `..` components, which may change the meaning of the link
if the path in `TarInfo.linkname` traverses symbolic links"
([tarfile docs](https://docs.python.org/3/library/tarfile.html)). Verified
directly against Python 3: `normpath('hop/../wp-config.php')` returns
`'wp-config.php'`, while `normpath('../languages')` is returned unchanged.
That is the hop attack defused by **rewriting the link** rather than refusing
it. Python is also explicit about the limits of the whole apparatus: "Even with
`filter='data'`, *tarfile* is not suited for extracting untrusted files without
prior inspection," and its hints recommend extracting to a fresh temporary
directory and disallowing symbolic links entirely if the functionality is not
needed. The PSF's own published mitigation for the 2025 bypasses goes further
still and refuses any link target containing a `..` component at all
([gist](https://gist.github.com/sethmlarson/52398e33eff261329a0180ac1d54f42f)).

**libarchive** solves the adjacent half of the problem — never writing
*through* a link — and does it at the syscall level.
`ARCHIVE_EXTRACT_SECURE_SYMLINKS` is documented as "Refuse to extract any
object whose final location would be altered by a symlink on disk … If
`ARCHIVE_EXTRACT_UNLINK` is specified together with this option, the library
will remove any intermediate symlinks it finds"
([`archive_write_disk(3)`](https://man.freebsd.org/cgi/man.cgi?query=archive_write_disk&sektion=3)).
The implementation, `check_symlinks_fsobj()` in
[`archive_write_disk_posix.c`](https://github.com/libarchive/libarchive/blob/master/libarchive/archive_write_disk_posix.c),
walks the path one `/`-separated segment at a time, testing each with
`fstatat(chdir_fd, head, &st, AT_SYMLINK_NOFOLLOW)` and descending by
`openat()` so that the directory it just checked is the directory it then uses
— components cannot be swapped between the check and the use. Where the `*at`
family is unavailable it falls back to `lstat()` plus `chdir()`, and where even
`lstat()` is missing it returns `ARCHIVE_OK` — that is, "clean" — a fail-open
path worth naming as the anti-pattern. Two further details are directly
relevant here: libarchive does **not** validate a symlink's target for
containment at all (its guarantee is about where objects land, not where links
point), and its own BUGS section records that enabling the check "disables the
support for very long pathnames" — it fails closed on a path it cannot resolve,
which is precisely where CPython failed open.

**GNU tar** takes the third approach: change the order so the hazard cannot
exist. Its manual states that "symbolic links containing `..` or leading `/`
can cause problems when extracting, so `tar` extracts them last; it may create
empty files as placeholders during extraction"
([Absolute File Names](https://www.gnu.org/software/tar/manual/html_node/absolute.html)).
The code matches: `extract_symlink()` in
[`src/extract.c`](https://cgit.git.savannah.gnu.org/cgit/tar.git/plain/src/extract.c)
diverts any link whose target is absolute or contains `..` to
`create_placeholder_file()`, and the real links are created only once every
other member is on disk. GNU tar also documents the limit of that trick —
the placeholders "do not suffice for nonempty directories" — and concedes
that against an attacker mutating the tree during extraction "there is no easy
way for `tar` to distinguish these scenarios from legitimate uses"
([Live untrusted data](https://www.gnu.org/software/tar/manual/html_node/Live-untrusted-data.html)).
Its blunt operational advice is to "extract from untrusted archives only into
an otherwise-empty directory".

**`filepath-securejoin`**, the Go library used for exactly this problem in the
container ecosystem, is the clearest published statement of the algorithm. Its
`SecureJoinVFS` keeps three pieces of state — the resolved-so-far path, the
unparsed remainder, and a link counter — and walks one component at a time:
`Lstat` the candidate; if it is not a symlink (or does not exist) append it and
continue; if it is a symlink, read it and **prepend the target to the unparsed
remainder**, then carry on resolving. An absolute target resets the resolved
prefix to empty rather than escaping, because the root is re-prefixed on every
component — the README describes the whole thing as "a userspace
implementation of how `chroot(2)` operates on file paths". Non-existent
components are handled by an explicit source comment: "Treat non-existent path
components the same as non-symlinks (we can't do any better here)." The link
budget is `MaxSymlinkLimit = 255`, noted in the source as generous against
"Linux has an internal limit of 40"
([`join.go`](https://github.com/cyphar/filepath-securejoin/blob/main/join.go)).

That library is also the sharpest available statement of the limit of the whole
approach, in its own author's words: "the guarantees provided by this function
only apply if the path components in the returned string are not modified …
after this function has returned", and, bluntly, "**There is no way to solve
this problem with `SecureJoinVFS` because the API is fundamentally wrong (you
cannot return a 'safe' path string and guarantee it won't be modified
afterwards).**" The recommended replacement is `OpenInRoot`, which on Linux
5.6+ uses `openat2` with `RESOLVE_IN_ROOT` — the kernel treating the given
directory as the root for that one resolution. Go's standard library reached
the same place with `os.Root` in Go 1.24: "Methods on Root will follow symbolic
links, but symbolic links may not reference a location outside the root.
Symbolic links must not be absolute", with `rootMaxSymlinks = 8`. Notably,
Go's own `archive/tar` does *not* attempt the target problem at all — its
`Reader.Next` documentation says "Only file names are validated, not link
targets", and even that lexical check is off by default; `filepath.IsLocal`,
the check it uses, documents itself as "a purely lexical operation. In
particular, it does not account for the effect of any symbolic links that may
exist in the filesystem." The standard library's model is therefore explicitly
two-layer: a cheap lexical name check, and a syscall-enforced root for the
write. Pontifex can have the first and the resolution; it cannot have the
syscall-enforced root.

**The CVE record is worth reading precisely, because the class is often
misnamed.** "Zip Slip" is not this bug: the original disclosure defines it as
"a form of directory traversal that can be exploited by extracting files from
an archive", exploited "using a specially crafted archive that holds directory
traversal filenames (e.g. `../../evil.sh`)", and the research page does not
mention symbolic links at all. Neither is CVE-2007-4559, the `tarfile` flaw
that prompted PEP 706: its NVD record is plain `..`-in-filenames traversal,
classified CWE-22 only. The symlink variant has no comparably famous name; the
citable class identifier is **CWE-59, "Improper Link Resolution Before File
Access ('Link Following')"**, which real advisories carry alongside CWE-22.
Concrete instances that are exactly this bug: **CVE-2020-11736** (an archive
manager that "lacks a check of whether a file's parent is a symlink to a
directory outside of the intended extraction location", CWE-22 + CWE-59); and
**CVE-2023-37460** in a Java archiver, whose advisory describes the mechanism
better than any secondary write-up — "When extracting an archive with an entry
that already exists in the destination directory as a symbolic link whose
target does not exist … `resolveFile()` will return the symlink's source
instead of its target, which will pass the verification … Later
`Files.newOutputStream()`, that follows symlinks by default, will actually
write the entry's content to the symlink's target." That advisory also records
the cross-archive form — "this attack can be performed also by two different
archives" — which matters here, because a Pontifex restore lands on a live site
that may already carry links from an earlier restore.

**`node-tar` is the cautionary tale, and it is directly on point.** It needed
**three** CVEs to get one check right, and all three were the same root cause:
a directory cache kept as a *performance* optimisation (skipping `stat` calls)
had quietly become the place the symlink check lived.
[CVE-2021-32803](https://github.com/advisories/GHSA-r628-mhmh-qjhw) — an
archive containing "both a directory and a symlink with the same name as the
directory" poisoned that cache, so "By first creating a directory, and then
replacing that directory with a symlink, it was thus possible to bypass
`node-tar` symlink checks on directories".
[CVE-2021-37701](https://github.com/advisories/GHSA-9r2w-394v-53qc) then found
the same hole through **case-insensitive filesystems**: "If a tar archive
contained a directory at `FOO`, followed by a symbolic link named `foo`, then
on case-insensitive file systems, the creation of the symbolic link would
remove the directory from the filesystem, but _not_ from the internal directory
cache". Its fix is the one adopted below: "Directory cache pruning is performed
case-insensitively. This _may_ result in undue cache misses on case-sensitive
file systems, but the performance impact is negligible."
[CVE-2021-37712](https://github.com/advisories/GHSA-qq89-hq3f-393p) found it a
third time through **Unicode normalisation** collisions and Windows short
names, and was fixed by normalising to `NFKD` before comparison. Python's own
hardening hints reach the same conclusion independently: "Check for files that
would be shadowed on case-insensitive filesystems." The generalisable lesson is
that any memoised or byte-exact notion of "this path is safe" is a latent
escape unless every equivalence class the *filesystem* considers equal — case,
Unicode form, separator — is equal to yours too.

Within the WordPress backup category the general posture is cruder: tools
commonly refuse to follow symlinks during backup and simply exclude them,
warning that a link was not followed, on the reasoning that including them
risks an extraction loop. That is a defensible choice for a tool that does not
promise fidelity, and it is precisely the choice Pontifex cannot make without
breaking the restore of a Composer-managed site. Separately, the pattern of
resolving a path *before* deciding on it is established in this ecosystem's own
vulnerability history: at least one WordPress backup plugin's arbitrary-file
flaw (CVE-2023-6972, 2023) was patched by running `realpath()` before the
containment check so that symlinks and traversal were resolved to their real
location first. That specific claim is drawn from a secondary write-up of the
advisory and was **not** verified against the plugin's own patch.

### What the prior art actually settles

Six approaches were on the table at the start of this work. What the primary
sources show:

| Approach | Who does it | Verdict here |
|---|---|---|
| Resolve each component against the real filesystem as you go | `filepath-securejoin`, Python (`realpath` with `ALLOW_MISSING`), libarchive (`fstatat` + `openat` chain) | **Adopted** — the only mechanism that models what the kernel does, and the only one with a published, readable algorithm |
| Refuse any target containing `..` at all | the PSF's published mitigation | Sound, rejected: the worst false-refuser of the set |
| Rewrite the target, stripping internal `..` | Python's `data` filter | Rejected: silently changes a restored site's links |
| Extract symlinks last | GNU tar's delayed links | Insufficient alone; its own manual says placeholders "do not suffice for nonempty directories", and a live WordPress is always nonempty |
| Syscall-enforced root (`openat2` + `RESOLVE_IN_ROOT`, `os.Root`, `OpenInRoot`) | Linux 5.6+, Go 1.24, securejoin's new API | The genuinely correct answer, and **unavailable in PHP** — verified, not assumed |
| Dereference at backup time so archives carry no symlinks | parts of the WordPress backup category | Rejected: does not bind a hand-crafted archive at all, and destroys round-trip fidelity |

Three further conclusions carry across from the sources and shape the decision
below. First, **fail closed when resolution cannot be completed**: libarchive
disables long-path support rather than skip its check, while CPython's
fallback to a weaker resolution was scored 9.4 CRITICAL. Second, every one of
these implementations independently recommends extracting untrusted archives
into a fresh empty directory — advice a WordPress restore cannot take, since
restoring onto a live site is the entire operation, which is why the
containment has to be enforced rather than sidestepped. Third, and most
importantly for how this decision should be read: the resolution walk is
**sound against a hostile archive and unsound against a hostile concurrent
process**, and its own authors say so. That is the right trade here — the
threat this ADR closes is an archive, and an attacker already executing code on
the host has better options than racing a restore — but it must be stated
rather than glossed.

## Decision

Confine a symlink by the target the **kernel** will reach, computed over the
archive's whole declared tree, and decide it **before the first byte is
written**.

Nine rules, all enforced in one preflight pass. The first six are the design;
the seventh and eighth were added after adversarial testing of the design
itself found a bypass in it, and are recorded here rather than tracked
separately; the ninth moves an existing check earlier.

- **The preflight runs before any write, over every symlink entry in the
  archive.** The manifest is already fully decoded before the walk begins
  (`RestoreRunner::walk()` calls `$manifest->entries()` up front), it records
  each entry's kind and byte offset, and symlink entries carry a zero-length
  payload — so reading every symlink entry's header is a seek and a small read
  each, on an already-seekable stream, and costs nothing measurable on a site
  with a handful of links. Entry headers are plaintext even in an encrypted
  archive (they are the AEAD's additional authenticated data — format spec
  §§ 8.2, 13.2), so the preflight needs no passphrase and can run before any
  key material is involved. A refusal at this point has written nothing. This
  replaces refusing mid-walk, which the restore loop cannot survive: it has no
  per-entry catch, so today a refusal on any entry leaves a half-written site.
- **The final-tree model, not the tree as it happens to be at that moment.**
  The preflight builds an index of **every** symlink the archive declares —
  path to raw target — and resolves against that index first, falling back to
  the live filesystem (`is_link()` / `readlink()`) for any component the
  archive does not declare. This is what makes the check order-independent, and
  it is load-bearing rather than tidy: the bench below shows the identical rule
  evaluated only against the tree as it exists at write time is defeated by
  simply reordering the two entries so the consumer link is written while its
  intermediate component is still absent.
- **Component-by-component dereferencing, with a hop ceiling.** Starting from
  the link's own directory, each component of the target is taken in turn. If
  the component is a symlink — in the archive index or on disk — its own target
  is spliced in front of the components still to be processed and resolution
  continues; otherwise the component is appended. This is the same substitution
  the kernel performs, and it is the algorithm libarchive and Python both
  arrive at. A counter caps the number of splices; exceeding it refuses. The
  operating system's own ceiling is the reference point (32 on this macOS host,
  confirmed by `getconf SYMLOOP_MAX`; Linux's `MAXSYMLINKS` of 40 is
  **not verified from a primary source in this session**). This is what
  terminates on the mutual-loop shape that the textual check permits.
- **Absolute targets stay refused, unchanged.** This matches Python's
  `AbsoluteLinkError` and costs nothing known: an absolute target in a
  migratable archive encodes the source server's paths and is almost always
  wrong on the destination anyway.
- **The containment root is the site root, and the target must be a strict
  descendant of it.** A resolved target equal to the site root, or above it, is
  refused — the site root itself for Python's stated reason (a link that *is*
  the root redirects everything under it), and anything above it because that
  is the whole `/etc/passwd`, sibling-vhost, home-directory class. The site
  root is `realpath()`d for the comparison, as the constructor already does,
  because on macOS `/var` is itself a symlink to `/private/var` and an
  unresolved root never matches a resolved path.
- **The site's own `wp-config.php` is refused as a resolved target, and so is
  the plugin's working directory.** These are two named refusals on the
  *fully resolved* path. `wp-config.php` is located exactly as WordPress core
  locates it in `wp-load.php` — `ABSPATH . 'wp-config.php'`, else
  `dirname( ABSPATH ) . '/wp-config.php'` when that exists and
  `dirname( ABSPATH ) . '/wp-settings.php'` does not — so the rule tracks
  core's own definition rather than a guess. `wp-content/pontifex` is added
  because it holds this site's stored backups and safety archives, each of
  which contains the whole database; a link from `uploads` into it would expose
  every one of them to the web, and it carries no false-refusal risk because
  `FileScanner::is_pontifex_working_path()` already excludes that directory
  from every archive Pontifex writes.
- **The archive's symlink index is consulted case-insensitively as well as
  byte-exactly, because the preflight resolves against a tree that is not yet
  on disk.** Adversarial testing of this design, before it was written up,
  found a bypass in it. On a case-insensitive filesystem — macOS by default,
  and Windows — an archive can declare `wp-content/uploads/hop -> ".."` and
  then `wp-content/uploads/leak.txt -> "HOP/../wp-config.php"`. A byte-exact
  index does not match `HOP`; at preflight nothing is on disk yet, so the
  `is_link()` fallback does not match it either; the component is taken
  literally and the target resolves harmlessly to
  `wp-content/uploads/wp-config.php`, which is permitted. At write time the
  kernel resolves `HOP` to the `hop` the first entry just created, and the leak
  lands. Proven on this macOS host: `is_link()` on the upper-case spelling
  returns true once the link exists, and reading the second link returned the
  planted `wp-config.php` contents. The index therefore carries a case-folded
  key alongside the exact one, and a miss on the exact key is retried against
  the folded key. Folding can only ever cause a refusal, never a permission, so
  the failure direction is safe; the only shape it could refuse wrongly is an
  archive holding two symlinks whose paths differ solely by case, which cannot
  exist on a case-insensitive destination at all, and which the refusal message
  should name precisely if it ever fires on a case-sensitive one. This is the
  writer-side counterpart of the existing case-exactness gotcha the project
  already tracks: byte-exact comparisons and case-insensitive filesystems
  disagree, and the attacker picks which one to rely on.

  **This is not a hypothetical, and the fold alone is not the whole fix.** It is
  precisely CVE-2021-37701 in `node-tar` — "on case-insensitive file systems,
  the creation of the symbolic link would remove the directory from the
  filesystem, but _not_ from the internal directory cache" — whose published
  fix is the same one taken here: "Directory cache pruning is performed
  case-insensitively. This _may_ result in undue cache misses on case-sensitive
  file systems, but the performance impact is negligible." The *next* CVE in
  that series, CVE-2021-37712, found the same hole again through **Unicode
  normalisation** collisions and was fixed by normalising to `NFKD` before
  comparison. The index key is therefore case-folded **and** `NFKD`-normalised,
  because a filesystem that treats `café` written two ways as one file will
  resolve the attacker's spelling to the defender's link exactly as it does for
  `HOP` and `hop`. Python's own hardening hints say the same thing from the
  other direction: "Check for files that would be shadowed on case-insensitive
  filesystems."
- **PHP's stat cache is cleared before the preflight and between the preflight
  and the walk.** `is_link()`, `lstat()` and `SplFileInfo` results are cached
  (the manual says so for `is_link()` and `lstat()`; the `realpath()` page is
  silent about the realpath cache, which is itself a hazard). A resolver that
  reads a cached "not a link" for a path something has since replaced is back
  to trusting stale state, which is the shape of every bug in this family.
  `clearstatcache( true )` is cheap and the cost of forgetting it is a bypass.
- **The ancestor-symlink check moves into the same preflight and keeps its
  write-time enforcement.** Today `assert_no_symlinked_ancestor()` fires
  mid-walk. The preflight additionally refuses, up front, any entry whose
  ancestor is declared a symlink by the archive or is one on disk. The
  write-time check stays where it is as the second guard, because the preflight
  reasons about a model and the write-time check observes the real tree.

**A target that does not exist yet is permitted, and that is deliberate.** A
symlink may legitimately dangle at the moment it is written and be satisfied by
a later entry, or by a `composer install` after the restore, or never. The rule
therefore asks *where the target resolves*, not *whether it is there* — a
component the archive does not declare and that is not on disk cannot redirect
anything, so it is taken literally and resolution continues. This is why
`realpath()` cannot be the mechanism: verified on PHP 8.5.6, it returns `false`
for a missing path, for a dangling link, and for any path whose tail does not
exist, so a `realpath()`-based check would refuse every dangling link on the
site. It is the same distinction CPython had to introduce
`os.path.ALLOW_MISSING` for.

`--allow-unsafe-symlinks` narrows in meaning: it waives the containment and
named-target rules for an operator who has inspected the archive, and it never
waives absolute targets, the hop ceiling, or the ancestor check. The admin
screens keep the override hard-coded to `false` — and that becomes defensible
rather than a trap, because under this rule an ordinary site's own archive no
longer trips the check at all.

### The recommendation, and the alternative posture it was chosen over

**Recommended: the site root as the containment root, plus the two named
target refusals.** The bench below records why. Rooting containment at the
**content root** instead is the stricter, tidier rule — it needs no named file
at all — but it refuses a Composer-managed site's own backup, and a backup tool
that cannot restore its own output is not a backup tool. The site-root rule
permits both legitimate shapes and still refuses every attack shape tried.

The cost of that choice must be stated plainly: **the named-target refusals are
a deny-list, and ADR 0019 rejected deny-lists.** Three things distinguish this
one, and if any of them stops holding the decision should be revisited. It is
computed on the fully resolved path, after all dereferencing, not by
pattern-matching attacker-formatted bytes — there is no normalisation step for
an attacker to attack, which was the exact failure mode of the SQL verb
extraction. It names a path the plugin derives from core's own algorithm, not a
spelling supplied by the archive. And it is a **backstop**, not the primary
mechanism: the strict-descendant rule is what confines the target, and the
named refusals close the one location inside that boundary whose exposure is a
total compromise. A future maintainer who reads the strict-descendant rule and
judges the named refusals redundant would reopen the proven attack exactly.

### Evidence

Four algorithms were run against one corpus of fifteen shapes — eight hostile,
seven legitimate — in `symlink_algorithm_bench.php` (scratchpad; standalone
PHP, no WordPress, no Pontifex classes, so it compares *algorithms* rather than
the shipped code, and can be re-run in seconds). Each case is a small sequence
of symlink entries; a case passes only if the algorithm reaches the wanted
verdict for the sequence as a whole. The proven attack itself was reproduced
separately against the real `FileWriter` in `hop_probe.php`.

| Algorithm | Correct | Fails on |
|---|---|---|
| **A** — textual collapse, content root (*the naive fix*) | 10/15 | the hop attack, the reordered hop, the mutual loop, **and both legitimate Composer shapes** |
| **B** — dereferencing walk, content root | 13/15 | **both legitimate Composer shapes** |
| **C** — dereferencing walk, site root + named refusals (*recommended*) | **15/15** | — |
| **D** — rule C, but evaluated only at write time with no whole-archive model | 14/15 | the reordered hop: both links pass individually, and the kernel then reads `wp-config.php` through them |

The hostile shapes are: the proven hop attack; the same two entries reordered
so the consumer is written first; a direct `../../wp-config.php`; a
three-link chain that escapes only at the end; a mutual loop; a climb above the
site root; a hop to the site root with a tail containing no `..` at all; and a
hop placed at the content root rather than inside `uploads`. The legitimate
shapes are the two Composer/`languages` links, an alias inside `uploads`, a
theme linking into `uploads`, a plugin linking to a sibling theme, and a
two-link chain that stays inside.

Row **D** is the reason the preflight builds a whole-archive model rather than
checking each link as it is written; row **C** against the two Composer rows is
the reason the containment root is the site root rather than the content root.

## Consequences

- **The proven attack is closed, and closed before anything is written.** An
  archive carrying the hop pair is refused at preflight with the site
  untouched, and the refusal message can name the link, the raw target, and —
  the useful part — the path it *actually* resolves to, so an operator reading
  "`wp-content/uploads/leak.txt` → `hop/../wp-config.php` → resolves to
  `/srv/site/wp-config.php`" needs no explanation of why it was refused.
- **A Composer-managed site's own backup restores, unchanged, on the same
  server and on a new one.** This is the single most important consequence and
  the one that ruled out the stricter root. `wp-content/languages ->
  ../languages` and `wp-content/mu-plugins/autoload.php ->
  ../../vendor/acme/lib/autoload.php` both resolve to strict descendants of the
  site root and are permitted with no flag, no warning and no operator
  decision. Migration to a new server is permitted for the same reason: the
  rule asks where the target resolves, not whether it already exists, so a
  link whose target has not been created yet is judged on the model, not on the
  destination's current emptiness.
- **A whole-site restore of a shared-configuration layout is refused.** A setup
  that symlinks `wp-config.php` itself out to shared storage produces a symlink
  entry whose resolved target is above the site root. That is a genuine
  behaviour change for a real, if uncommon, layout; the operator's route is
  `--allow-unsafe-symlinks` on the CLI after reading the reported resolution,
  and there is no route in the browser. Content-only restores — the default and
  the only admin mode — are unaffected, because that entry is not under
  `wp-content`.
- **Refusal is per-archive, not per-entry.** One escaping link refuses the
  whole restore before it starts. That is deliberate: a restore that silently
  skipped some entries would produce a site that is neither the old one nor the
  archive's, and Pontifex's promise is that a restore either happened or did
  not.
- **The cost is small and bounded.** The preflight reads only symlink entry
  headers, seeking by manifest offset; symlinks are a handful of entries on a
  real site where files number tens of thousands. Resolution is a few `is_link()`
  calls per component, not a filesystem scan — the archive index answers most
  components without touching disk. Nothing here re-walks the tree, which
  matters given how much of this engine's measured cost is already scan cost.
- **Fail closed on anything the resolver cannot decide.** A component that
  cannot be stat'd, a target longer than `PHP_MAXPATHLEN` (1024 on this host),
  a hop count exceeded — each refuses. This follows libarchive's choice to
  disable long-path support rather than skip the check, and deliberately not
  CPython's pre-2025 behaviour of falling back to a resolution that "may still
  contain links or loops", which was a 9.4 CVE.
- **The threat-model wording must be corrected.** "FILE paths validated
  relative-and-within-wp-content (no traversal)" is true of paths and was never
  true of link targets; the summary should say what is confined and what is
  merely bounded.
- **PHP gives no `openat`, and that ceiling is real.** Confirmed on PHP 8.5.6:
  `O_NOFOLLOW` is not defined, there is no `openat`, and `realpath()` returns
  `false` for a missing path, for a dangling symlink, and for any path whose
  tail does not exist. So the fd-chain that makes libarchive's walk immune to
  swapping between the check and the use cannot be reproduced. What *can* be
  reproduced is the resolution itself, which is where the archive-controlled
  attack lives; the residual is a race against a local attacker, below.

## What this does not cover

- **A local attacker mutating the tree during the restore.** The resolver
  checks and then the writer writes; without a syscall-enforced root there is a
  window. This is not a Pontifex shortcoming so much as the documented ceiling
  of the whole technique: securejoin's author states that a function returning
  a safe path string "is fundamentally wrong (you cannot return a 'safe' path
  string and guarantee it won't be modified afterwards)", and GNU tar concludes
  there is "no easy way" to tell that scenario from a legitimate one. An
  attacker in that position already runs code on the host and has better
  options than racing a restore. Not closed, and not closable in PHP — the
  honest framing is that this design is sound against a hostile *archive* and
  not against a hostile *process*.
- **Exposure of other in-site files.** A link resolving to
  `<site>/wp-includes/version.php` or `<site>/vendor/…` is permitted. Those are
  already inside the document root and already fetchable, so the link adds no
  reach — but a layout that keeps a secret in a non-standard place inside the
  site root, and outside `wp-content`, is not protected by anything here.
- **Exposure of files inside `wp-content` itself.** A link from `uploads` to
  another plugin's credentials file under `wp-content` never leaves the content
  root and is never examined. The named refusal for `wp-content/pontifex`
  closes the one such location this plugin creates, and nothing more.
- **A `wp-config.php` a site bootstraps from a non-core location.** The rule
  uses core's own two-location algorithm; a site loading its configuration from
  somewhere else by custom bootstrap is outside what the rule can see.
- **Anything about the archive's authenticity.** This is containment, not
  trust. Admin Restore and Verify still consult no signature at all, so ADR
  0012's enforcement does not reach the browser; that is a separate finding and
  is not fixed here.
- **Directories and files, which are not re-examined.** The confinement of
  ordinary entry paths is unchanged; this ADR is about targets and about
  moving the ancestor decision earlier.
- **Windows short names (8.3) and other filesystem-specific aliasing.**
  `NFKD` and case folding cover the two variants with published CVEs.
  `node-tar`'s CVE-2021-37712 also names Windows 8.3 short names as a third
  aliasing route, and its fix there was to clear the whole cache on any symlink
  rather than to model the aliasing. Pontifex targets POSIX hosts and does not
  attempt this; on Windows the confinement should be treated as **unproven**,
  not as broken. Verified only that the two POSIX-relevant variants are
  handled; the Windows variant was not tested.
- **Hard links — because the format has none.** The four entry kinds are
  `file`, `db_chunk`, `directory` and `symlink`, so the hard-link half of
  Python's and libarchive's problem does not exist here. It would return if a
  hard-link kind were ever added.

## Testing that would be needed to trust this

Unit tests alone would not be evidence; the shipped textual check has unit
tests and was still defeated by execution.

- **The corpus, as an executable test.** All fifteen bench shapes, as a table
  test over the real `FileWriter`, asserting refusal for the eight hostile ones
  and success for the seven legitimate ones. The reordered-hop case is the one
  that must never be dropped for looking redundant.
- **A real-kernel proof, not just a verdict.** For each hostile shape, if the
  guard is removed the test must show the link is created *and*
  `file_get_contents()` through it returns the planted `wp-config.php`
  contents; with the guard, nothing is created. A test asserting only "an
  exception was thrown" proves nothing about the kernel.
- **A nothing-was-written assertion.** After a refusal, the destination tree
  must be byte-identical to its pre-restore state — the point of moving the
  decision to preflight is worthless if a partial write can still occur.
- **The round trip on a real site with real symlinks.** A wp-env site given a
  Composer-shaped layout (a `vendor/` sibling of `wp-content`, an
  `mu-plugins/autoload.php` link into it, a `languages` link) exported and
  restored, in both directions, with the links intact and the site loading
  afterwards. This is the false-refusal gate and it is not optional.
- **A migration leg.** The same archive restored onto a *different*, empty
  destination, proving the rule does not depend on the targets already
  existing.
- **A loop and a depth drill.** Mutual links, and a chain longer than the hop
  ceiling, must refuse rather than hang. A hang on a hostile archive is a
  denial of service on a live site.
- **The case-spelling and Unicode drills, on a case-insensitive volume.** The
  `HOP`/`hop` pair above, asserted refused, and asserted *leaking* with the fold
  removed; plus the decomposed-versus-precomposed spelling of an accented
  component, the shape that became CVE-2021-37712 elsewhere. Neither can be
  trusted to a CI leg on Linux, where the filesystem is case-sensitive and the
  attacks do not reproduce, so this needs an explicit macOS run or a
  case-insensitive volume created for the test. A green Linux matrix proving
  nothing is exactly how this class of bug survives.
- **A pre-existing-link drill.** A destination that *already* has an escaping
  symlink the archive does not mention, with an archive entry that resolves
  through it — the cross-archive form CVE-2023-37460's advisory records. This
  is the case the archive index alone cannot see and the on-disk fallback must.
- **A scale measurement.** The preflight timed on the 47,000-entry dev-site
  archive, to confirm it adds no meaningful cost to a restore that already
  has a measured budget.
- **7Duckie's own end-to-end pass** (working agreement 6): the CLI import of a
  hostile archive refusing with a readable message and an untouched site, and
  a browser Restore of an ordinary archive that is *not* refused — the second
  matters more than the first, because a false refusal in the browser has no
  override.

## Alternatives considered

- **Textual collapse rooted at the content root** (the naive fix). Rejected —
  defeated by execution: the kernel dereferences an intermediate link before
  applying the following `..`, which no textual pass can model. It also refuses
  both legitimate Composer shapes and never terminates a link loop.
- **Refusing any target containing a `..` component at all** — the PSF's own
  published mitigation for the 2025 `tarfile` bypasses. Rejected here despite
  the pedigree: it is sound, but it refuses `../languages`,
  `../../vendor/acme/lib/autoload.php` and even `../real.txt`, so it is the
  most severe false-refuser of every option considered. It suits a consumer
  extracting a foreign tarball into a scratch directory; it does not suit a
  tool whose job is to put a site back exactly as it was.
- **Normalising internal `..` out of the target and writing the rewritten
  link**, as Python's `data` filter does. Rejected — it defuses the hop class
  without any filesystem access, which is genuinely attractive, but it does so
  by silently changing what the operator's link points at. Python's own
  documentation states the cost. A backup tool that quietly rewrites a
  restored site's links has broken the one promise the format exists to keep.
- **Extracting symlinks last, GNU tar's delayed-link approach.** Rejected as
  insufficient alone, and kept in reserve. It removes the ordering hazard for
  archive-created links, but it does nothing about links already on disk (GNU
  tar says so: the placeholders "do not suffice for nonempty directories"), and
  a restore onto a live WordPress is the nonempty case by definition. It also
  changes entry ordering, which the streaming read path (ADR 0010) and the
  resumable writer (ADR 0015) both depend on. The whole-archive model achieves
  the same order-independence without touching the write order.
- **A syscall-enforced root** — `openat2` with `RESOLVE_IN_ROOT` (Linux 5.6+),
  as used by securejoin's `OpenInRoot` and reached for by Go's `os.Root`; or
  libarchive's `openat`/`fstatat` fd-chain. This is the genuinely correct
  answer and it is **rejected only because PHP cannot express it**. Confirmed
  on PHP 8.5.6 that `O_NOFOLLOW` is undefined and no `openat` exists; `fopen()`
  exposes only `e` and `n` from the POSIX flag set, and there are no file
  stream-context options at all — notable because the manual *does* name
  `O_EXCL|O_CREAT` where a mode maps to them, so the silence is meaningful.
  Reaching it would mean FFI or a PHP extension, neither of which belongs in
  code that runs inside other people's sites.
- **Dereferencing symlinks at backup time so archives contain none.**
  Rejected, and worth recording why, because it looks like it removes the whole
  problem. It does not: a hostile archive is hand-crafted and would carry
  symlink entries regardless, so the reader would still need a rule — the only
  version that actually closes the attack is refusing symlink entries on
  restore, which is the next item. Meanwhile it costs a great deal. A linked
  directory would be copied in full, multiplying archive size and entry count
  on an engine that already has an entry-count ceiling; link cycles would need
  their own detection; a dangling link cannot be dereferenced at all and would
  be silently dropped; and, decisively, it would change the restored site —
  a Composer install's `autoload.php` link would come back as a frozen copy,
  stale the moment anyone runs an install. It is also a format and behaviour
  change, so it would need 7Duckie's veto under rule 7 before anything was
  built.
- **Refusing every symlink entry on restore.** Rejected, and recorded as the
  fallback. It is the most secure option available and it is roughly what the
  wider WordPress backup category does. It is rejected because it fails the
  same test as the strict root, more bluntly: a Composer-managed site restored
  this way comes back broken, with no error, because the link its bootstrap
  needs simply is not there. If the resolution work below is ever judged too
  risky to maintain, this is the honest thing to fall back to — but it should
  be a stated, documented limitation, not a silent skip.
- **Warning instead of refusing.** Rejected for the reason ADR 0012 gives:
  a warning scrolls past a `--yes` run, and the browser has no operator to read
  it at all. A trust decision must be enforced by the machine.
- **Keeping the refusal mid-walk and adding a per-entry catch.** Rejected — a
  catch would turn a refused entry into a silently skipped one, producing a
  site that is neither the old one nor the archive's. Deciding before the first
  byte avoids needing to choose between aborting half-way and skipping.

## References

- [PEP 706 — Filter for `tarfile.extractall`](https://peps.python.org/pep-0706/);
  [`tarfile` extraction filters](https://docs.python.org/3/library/tarfile.html#extraction-filters);
  [`Lib/tarfile.py`](https://github.com/python/cpython/blob/main/Lib/tarfile.py)
- [NVD CVE-2025-4517](https://nvd.nist.gov/vuln/detail/CVE-2025-4517) and
  [cpython#135034](https://github.com/python/cpython/issues/135034) — the five
  2025 extraction-filter bypasses
- [`archive_write_disk(3)`](https://man.freebsd.org/cgi/man.cgi?query=archive_write_disk&sektion=3)
  and [`archive_write_disk_posix.c`](https://github.com/libarchive/libarchive/blob/master/libarchive/archive_write_disk_posix.c)
  — `ARCHIVE_EXTRACT_SECURE_SYMLINKS`, `check_symlinks_fsobj()`
- [GNU tar — Absolute File Names](https://www.gnu.org/software/tar/manual/html_node/absolute.html),
  [Security rules of thumb](https://www.gnu.org/software/tar/manual/html_node/Security-rules-of-thumb.html),
  [Live untrusted data](https://www.gnu.org/software/tar/manual/html_node/Live-untrusted-data.html),
  [`src/extract.c`](https://cgit.git.savannah.gnu.org/cgit/tar.git/plain/src/extract.c)
- [`filepath-securejoin`](https://github.com/cyphar/filepath-securejoin/blob/main/join.go)
  — the component walk, the link budget, and the author's own statement that
  the string-returning API is unfixable against a live attacker;
  [`openat2(2)`](https://www.man7.org/linux/man-pages/man2/openat2.2.html) —
  `RESOLVE_IN_ROOT`
- [Go `os.Root`](https://github.com/golang/go/blob/master/src/os/root.go) and
  the [Go 1.24 release notes](https://go.dev/doc/go1.24);
  [`archive/tar` `Reader.Next`](https://github.com/golang/go/blob/master/src/archive/tar/reader.go)
  — "Only file names are validated, not link targets"
- [CWE-59 — Improper Link Resolution Before File Access](https://cwe.mitre.org/data/definitions/59.html),
  the citable class for this bug; [Zip Slip](https://security.snyk.io/research/zip-slip-vulnerability),
  which is **not** this bug and does not mention symbolic links
- `node-tar`'s three attempts at one check:
  [CVE-2021-32803](https://github.com/advisories/GHSA-r628-mhmh-qjhw),
  [CVE-2021-37701](https://github.com/advisories/GHSA-9r2w-394v-53qc) (case
  insensitivity), [CVE-2021-37712](https://github.com/advisories/GHSA-qq89-hq3f-393p)
  (Unicode normalisation); and
  [CVE-2023-37460](https://github.com/advisories/GHSA-wh3p-fphp-9h2m), the
  clearest description of the dangling-symlink-in-the-destination mechanism
- [WordPress `wp-load.php`](https://github.com/WordPress/WordPress/blob/master/wp-load.php)
  — the two-location `wp-config.php` search this rule mirrors
- ADR 0008 (content-only scope), ADR 0010 (streaming read path), ADR 0012
  (signature enforcement, and the reasoning about warnings), ADR 0015
  (resumable export mechanics), ADR 0019 (why deny-lists were rejected there)

### Not verified from a primary source

- macOS's `SYMLOOP_MAX` of 32 *was* confirmed locally with `getconf`. Linux's
  limit of 40 is stated in securejoin's own source comment but was not read
  from a kernel header.
- The claim that CVE-2023-6972's patch added `realpath()` before its
  containment check — taken from a secondary write-up, not the patch.
- The characterisation of the WordPress backup category's symlink handling —
  drawn from vendor documentation and support threads, not from source.
- Whether GNU tar's delayed-link condition in `extract_symlink()` is precisely
  as it reads on git master: an apparent bitwise `&`/`|` in the guard could not
  be reconciled against a tagged release, and `src/extract.c` has been churned
  recently. The manual's stated behaviour is what is relied on above, not the
  operator precedence.
- The fix for CVE-2021-32803 is not described in its own advisory; it was read
  from the patched `node-tar` source at the fix tag. The fixes quoted for
  CVE-2021-37701 and CVE-2021-37712 *are* in their advisories.
- Several further symlink-escape CVEs surfaced as leads but were not confirmed
  against their primary records and are deliberately **not** cited above
  (`tokio-tar` CVE-2025-59825, a later `node-tar` issue CVE-2026-26960, and
  CVE-2026-55828 in a Go transport library described as failing because "path
  validation is strictly lexical"). One secondary source attributing PHP's
  CVE-2021-21706 to symlink dereferencing was checked and is **wrong** — the
  upstream bug report gives a Windows drive-relative path-parsing root cause —
  so it must not be cited as prior art here.
- PHP's `is_link()` behaviour on a dangling symlink is not documented in the
  manual. It was confirmed empirically on PHP 8.5.6 (it returns `true`), which
  is what the ancestor check already relies on.
- APFS/HFS+ normalisation-insensitivity is asserted from the `node-tar`
  advisory's account of the equivalent bug, not from Apple's filesystem
  documentation.
