# ADR 0001 — WordPressContext as a separate abstraction from Environment

- **Status:** Accepted
- **Date:** 2026-05-26
- **Deciders:** 7Duckie

## Context

Pontifex has, since v0.0.1, routed every PHP-runtime query through an
`Environment` interface (`RealEnvironment` in production, mocks in
tests). This decoupled the doctor command from PHP's globals and made
it testable in isolation. The principle: every concern that the
command depends on goes through a seam.

The problem surfaced during Phase 4 (CLI integration), as the new
`ExportCommand` started to take shape. ExportCommand needs to read
several WordPress-specific values: the active WP version, the site
URL, the wpdb instance and its charset/collation, the database server
version, the uploads basedir, the maximum upload size. None of these
are PHP-runtime concerns. They are WordPress-runtime concerns.

Three problems followed:

1. **DoctorCommand was already a precedent of the wrong shape.** A
   review of `DoctorCommand` found seven direct calls to WordPress
   functions (`get_bloginfo`, `get_option`, `wp_max_upload_size`, and
   so on) and direct access to the `$wpdb` global. These calls were
   the reason its tests had to use `brain/monkey` to stub WordPress
   functions globally — fragile, with global state to manage between
   tests. The seven calls had quietly accumulated as the doctor
   command grew.
2. **ExportCommand would inherit the same fragility** if it followed
   the doctor's pattern. The export command was going to need more
   WordPress integration than the doctor, not less.
3. **Conflating PHP-runtime and WordPress-runtime concerns in a single
   `Environment` interface** would have grown that interface into a
   grab-bag — methods like `wpdb_charset()` next to methods like
   `php_max_execution_time()`. The interface would have become a
   service locator, which defeats the point of having a focused seam.

We considered three paths:

**Path A: extend `Environment` to include WordPress concerns.**
Cheapest in the moment. Adds methods to an existing interface, no new
files. Rejected because it conflates two genuinely different
concerns: `Environment` describes the PHP runtime ("what version of
PHP, what extensions, what memory limit"), and the new methods would
describe the WordPress runtime ("what version of WordPress, what
options are set, what database is wpdb pointed at"). Mixing them
produces an interface that grows unbounded and tells readers nothing.

**Path B: introduce `WordPressContext` as a separate seam and use it
in ExportCommand only.** Leaves DoctorCommand's existing direct calls
in place. Cheaper than Path C but asymmetric — two commands in the
same plugin with two different patterns. Confusing for contributors,
and the DoctorCommand fragility remains. Rejected.

**Path C: introduce `WordPressContext` and refactor DoctorCommand to
use it, then build ExportCommand using it from day one.** Most work
up front. Best long-term shape: one interface per concern, every
command testable without `brain/monkey`, ImportCommand and any future
command inherits the pattern for free.

## Decision

Take **Path C**. Introduce a `WordPressContext` interface and a
`RealWordPressContext` production implementation. Refactor
DoctorCommand to receive a `WordPressContext` through its constructor
and use it instead of the seven direct calls. Build ExportCommand
using the same pattern from the start.

The interface has ten methods covering the WordPress-side queries the
plugin needs: `wp_version`, `site_url`, `wpdb_instance`,
`wpdb_charset`, `wpdb_collation`, `db_server_version`,
`upload_dir_basedir`, `convert_hr_to_bytes`, `max_upload_size`,
`format_size`. The split between Environment (PHP) and
WordPressContext (WordPress) is now the rule: anything
PHP-runtime-related goes through Environment; anything
WordPress-runtime-related goes through WordPressContext.

WP-CLI output calls (`WP_CLI::log`, `WP_CLI::halt`,
`WP_CLI::confirm`) are deliberately *not* routed through the
abstraction. They are output-side, not data-side; their testability
problem is separately tracked as idea-bank Idea 007 and will be
addressed when `brain/monkey` is properly wired into the test
bootstrap.

The refactor was split into three commits to keep each reviewable:

- **19a:** introduce `WordPressContext` and `RealWordPressContext`.
- **19b:** refactor `DoctorCommand` to use it; update its three
  behavioural test files to use Mockery on the new interface instead
  of `brain/monkey` on WordPress functions and `$GLOBALS['wpdb']`
  manipulation.
- **19c:** build `ExportCommand` using the new pattern.

## Consequences

**Positive.**

- Cleaner tests. The 43 behavioural tests across DoctorCommand's
  three test files now use Mockery on a focused interface instead of
  global function stubs and `$GLOBALS` manipulation. Test-to-test
  state leakage is no longer possible by accident.
- Symmetric pattern. Every CLI command now receives `Environment` and
  `WordPressContext` through its constructor. ImportCommand, when it
  arrives in v0.1.0, will follow the same pattern with no fresh
  decisions to make.
- Clearer ownership. PHP-runtime questions go through Environment;
  WordPress-runtime questions go through WordPressContext. Future
  contributors reading the code see the split immediately.
- ImportCommand inherits the work. The most complex command coming in
  v0.1.0 will not need to introduce its own seam.

**Negative.**

- Three new classes to maintain: the interface, the production
  implementation, and a test that covers the production
  implementation's pass-through behaviour. The maintenance cost is
  ongoing but small — once an interface like this stabilises, it
  rarely changes.
- The boundary between "PHP-runtime" and "WordPress-runtime" is mostly
  obvious but has edge cases. The `convert_hr_to_bytes` helper, for
  example, is implemented in PHP but is a WordPress utility; it sits
  in `WordPressContext` because that is where WordPress code finds it.
  Edge cases like this need a judgement call per method.
- One untested gap remains. `ExportCommand::__invoke` orchestrates
  the export pipeline but is not behaviourally tested at the unit
  level — only structurally (the class is wired correctly) and via
  pure-helper tests on its private parsers. The orchestration logic
  is covered by integration tests in Phase 6, but a unit-level
  `__invoke` test would catch orchestration bugs at unit speed. This
  gap is tracked as idea-bank Idea 007 and will be closed in a
  follow-up commit once `brain/monkey` is properly wired into the
  PHPUnit bootstrap.

**Neutral.**

- The composer.json `autoload` block grows by one PSR-4 entry
  (`Pontifex\\WordPress\\` → `src/WordPress/`). Routine and expected.
