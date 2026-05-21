# Contributing to Pontifex

Thanks for taking an interest. This document covers the local development
setup. For architectural background, see the design documents in `docs/`.

## One-time setup

```bash
git clone https://github.com/7Duckie/pontifex.git
cd pontifex
composer install
composer dev:setup
```

After this, `git commit` runs the fast pre-commit checks and `git push`
runs the heavier pre-push checks. CI runs everything plus the security


`composer dev:setup` installs `pre-commit` via Python's package manager
and registers it with your local git checkout. Python 3 must be
available on your PATH as `python3` (macOS ships this by default;
Linux distributions vary). Without pre-commit registered, your local
commits will still work — they just skip the fast pre-flight checks
and rely on CI to catch issues.

## Daily workflow

| Command | What it does |
|---|---|
| `composer lint` | PHPCS (WordPress coding standards) |
| `composer lint:fix` | PHPCBF (autofix what PHPCS can) |
| `composer analyse` | PHPStan |
| `composer test` | Full PHPUnit suite |
| `composer test:unit` | Unit tests only — fast, no WP runtime |
| `composer check` | Lint + analyse + test + audit, the full pre-PR sweep |

## Pre-commit / pre-push budget

Pre-commit must complete in ≤3 seconds on a normal change. Pre-push must
complete in ≤60 seconds. If a check exceeds its budget, demote it rather
than tolerate the slowdown — a hook everyone disables protects nobody.

## Commit conventions

- Subject ≤50 characters, imperative, no trailing period.
- Blank line, then body explaining the *why*.
- One logical change per commit.

Example:

```
Add roave/security-advisories to require-dev

Composer will now refuse to install any version of any package
flagged in the FriendsOfPHP advisories database. This is the
cheapest possible CVE control: never have a vulnerable package
on disk to scan.
```

## Branches

- `main` is the integration branch. CI must be green for any merge.
- Feature branches: `feature/<short-description>`
- Fix branches: `fix/<short-description>`
- Security branches: kept private until disclosed; coordinate via the
  email in SECURITY.md.

## Security

Vulnerability reports: see [SECURITY.md](SECURITY.md), do not file
public issues. Architectural questions about security: open a public
discussion or PR.

See also [THREAT_MODEL.md](THREAT_MODEL.md) for the attack-surface
ranking. PRs touching ranks 1-4 should reference the threat model in
their description.

## Quality-gate reference

- Code style — `phpcs.xml.dist` (WordPress-Extra + Pontifex prefix rules)
- Static analysis — `phpstan.neon.dist` (level 6, ratcheting to 8 by v1.0)
- Tests — `phpunit.xml.dist` (unit + integration suites)
- Pre-commit — `.pre-commit-config.yaml`
- CI — `.github/workflows/ci.yml`

## PhpStorm setup

PhpStorm needs two small hints to play nicely with this project. Without
them, you'll see a wash of "undefined function" warnings on every
`add_action`, `esc_html__`, `wp_max_upload_size`, and similar WordPress
or WP-CLI call — even though the code is correct and PHPStan finds the
same functions via the Composer-installed stubs.

After `composer install`, configure PhpStorm:

1. **Add the stubs directories to the PHP include path.** Open Settings
   (or Preferences on macOS) → PHP → Include Path. Click the **+**
   button and add both:

  - `vendor/php-stubs/wordpress-stubs`
  - `vendor/php-stubs/wp-cli-stubs`

   PhpStorm will reindex briefly; the undefined-function warnings
   disappear once it finishes.

2. **Ignore the harmless XSD warning on `phpunit.xml.dist`.** PhpStorm
   may flag the schema declaration because it doesn't have the
   PHPUnit 10.5 XSD cached locally. The XML is valid and PHPUnit
   reads it correctly. The warning can be safely ignored, or you can
   register the schema under Settings → Languages & Frameworks →
   Schemas and DTDs if you'd prefer it gone.

PhpStorm reads `.editorconfig` automatically in recent versions, so tab
indentation and line endings will Just Work without further setup.

Other editors (VS Code with Intelephense, Neovim with phpactor, etc.)
typically pick up the stubs through Composer's autoload metadata and
need no equivalent step.

## Local development environment

Pontifex is developed against a real WordPress installation, not against
mocked WordPress functions. The recommended setup uses
[Local](https://localwp.com) by Flywheel, which is free, has a friendly
GUI, and handles PHP, the web server, and MySQL/MariaDB for you. Other
WordPress dev environments (wp-env, Valet, Lando) work too, but the
instructions below assume Local.

### 1. Create a Local site for Pontifex

In Local, create a new site:

- **Name**: anything, but `pontifex-dev` is the convention used in the
  rest of these docs.
- **Domain**: `pontifex-dev.local` (Local sets this automatically from
  the name).
- **PHP version**: any version from 8.1 upward. The CI matrix tests
  against 8.1, 8.2, 8.3, and 8.4 — matching one of these means
  "works locally" implies "works in CI." This document was written
  against a site running PHP 8.1.29 to match the CI floor; pick
  whichever version makes sense for what you are debugging.
- **Web server and database**: the defaults (nginx + MySQL) are fine.

Once created, click "Open site" once to confirm WordPress loads. You
should see the default WordPress welcome screen.

### 2. Symlink the project into the site

This is what lets WordPress see your project as an installed plugin
without needing to copy files. Edits to your project's files take effect
immediately, because the plugin directory inside the Local site is a
symbolic link pointing back at your working tree.

From your project's parent directory, run:

```bash
ln -s "$(pwd)/pontifex" \
      "$HOME/Local Sites/pontifex-dev/app/public/wp-content/plugins/pontifex"
```

Adjust the path on the right if your Local site has a different name or
your `Local Sites` directory lives elsewhere.

Verify it worked:

```bash
ls -la "$HOME/Local Sites/pontifex-dev/app/public/wp-content/plugins/"
```

You should see `pontifex` listed with an `->` arrow pointing back at
your project directory.

### 3. Activate the plugin

In Local, click "WP Admin" to open `pontifex-dev.local/wp-admin`. Log in
(Local pre-creates an admin user; the credentials are visible in Local's
site dashboard). Navigate to **Plugins**. Pontifex should appear in the
list. Click **Activate**.

### 4. Run WP-CLI against the site

Pontifex registers a `wp pontifex` command tree via WP-CLI. The
easiest way to invoke it is through Local's built-in site shell:

1. In Local, right-click the site in the sidebar.
2. Choose **Open site shell**.
3. A terminal opens, pre-configured with the right PHP version and the
   `wp` command pointed at this site.
4. Run:

```bash
   wp pontifex doctor
```

You should see the doctor command's environment checklist. If you see
"Command 'pontifex' is not registered," confirm the plugin is activated
in WP Admin and re-run.

### 5. Working with the project

From this point on, your daily cycle is:

1. Edit code in your usual location (e.g., PhpStorm pointed at the
   project root).
2. Run `composer check` from the project directory to lint, analyse,
   and test before committing.
3. Use the Local site shell to invoke `wp pontifex` commands against a
   real WordPress install.
4. Use WP Admin (browser) to confirm anything that affects the
   admin UI.

The symlink means there's no "deploy" step between editing and running.
What you save is what WordPress sees.

### Other Local sites

If you want to test Pontifex against another existing Local site (a
WooCommerce installation, a multisite setup, a site you've been
building separately), symlink the plugin into that site's
`wp-content/plugins/` directory using the same command pattern as
above, substituting the other site's name. One project, many sites.

## Step debugging with Xdebug

When a test fails or a check method returns an unexpected row, stepping
through the failing code is dramatically faster than scattering
`var_dump()` calls and re-running. Xdebug is the PHP step debugger; Local
bundles it, PhpStorm has first-class support, and the integration is
mostly configuration once you do it the first time.

This section assumes you have the Local site from "Local development
environment" already working and the PhpStorm hints from "PhpStorm
setup" already applied.

### 1. Enable Xdebug in Local

In Local, click your `pontifex-dev` site in the sidebar, then click the
**Tools** tab at the top. Find the **Xdebug** entry and toggle it on.
Local restarts the site's PHP-FPM and CLI processes with Xdebug loaded.

Xdebug runs in `debug` mode by default in Local — i.e. it listens for
PhpStorm to connect when an HTTP request or CLI command runs. This is
the mode you want for stepping through code. Other modes (`profile`,
`trace`) exist but aren't needed for the workflow described here.

### 2. Configure PhpStorm

Open **Settings** → **PHP** in PhpStorm. Two configurations matter.

**CLI Interpreter.** Point PhpStorm at the PHP binary Local ships, not
your system PHP. To find the path, open Local's site shell and run:

```bash
which php
```

Copy the path it returns. In PhpStorm, click the **...** beside the CLI
Interpreter dropdown, add a new interpreter from the local path, and
paste it in. Once configured, PhpStorm displays the interpreter's PHP
version and detected extensions — you should see `xdebug` in the list.

**Debug port.** Open **Settings** → **PHP** → **Debug**. Confirm the
Xdebug port is set to `9003` (the modern default; the older `9000` is
no longer used). Make sure **Can accept external connections** is
ticked.

### 3. Set a breakpoint and listen for connections

Open any PHP file in PhpStorm and click in the left gutter next to a
line. A red dot appears — that's a breakpoint. Pick something the doctor
command actually hits, e.g. inside `check_php_version()`.

Click the small **phone-with-a-bug** icon in the top-right toolbar to
start listening for debug connections. The icon turns green to indicate
PhpStorm is waiting.

### 4. Run the code under debug

From Local's site shell:

```bash
wp pontifex doctor
```

When PHP reaches the breakpoint, PhpStorm pulls itself to the
foreground and pauses execution there. You can:

- Hover any variable in the source pane to see its current value.
- Use the Variables panel (bottom of the Debug tool window) to inspect
  the full scope, including `$this->environment` and any nested mock
  state.
- Step **Over** (F8), **Into** (F7), or **Out** (Shift-F8) one line at a
  time.
- Click **Resume** (F9) to continue running until the next breakpoint
  or the program ends.

### 5. Debugging tests

The same setup debugs the test suite. From the project directory, with
PhpStorm listening for connections:

```bash
composer test
```

Or `composer test:unit` for just the unit suite. PHPUnit runs each test
under Xdebug; breakpoints in both test code and production code are hit
in execution order. Useful when a behavioural test fails and you want
to inspect the mock Environment state at the moment the check method
returns.

### 6. Disable Xdebug when you're not actively debugging

Xdebug adds significant overhead even when no breakpoints are set —
roughly 5-10× slower test execution. The 80ms test run becomes 500-800ms
with Xdebug active. Toggle Xdebug off in Local's Tools tab when you're
done debugging, so the daily `composer check` stays fast.

Local remembers the last-used setting per site, so re-enabling is a
single click whenever the next bug appears.


## Troubleshooting

### `pip: command not found` during `composer dev:setup`

macOS doesn't alias `pip` by default. The `dev:setup` script uses
`python3 -m pip` for portability, which works as long as `python3` is on
your PATH. If you see this error, confirm Python 3 is installed and
accessible by running `python3 --version`. macOS ships Python 3 by
default; if it's missing, install it from
[python.org](https://www.python.org/downloads/macos/) or via Homebrew
(`brew install python`).

### SSL certificate verification failed during `git commit`

If git commit fails with a Python `URLError: [SSL: CERTIFICATE_VERIFY_FAILED]`
while pre-commit is downloading hook dependencies (gitleaks, etc.), Python
can't verify TLS certificates against your system trust store. This is
a common state for Python installations from python.org's installer on
macOS, which doesn't bootstrap the certifi bundle automatically.

The fix is shipped with the Python installer. Open Finder, navigate to
`/Applications/Python 3.x/` (substituting your installed version), and
double-click `Install Certificates.command`. A Terminal window opens
briefly, installs certifi, and configures Python to use it. Close that
Terminal and re-run your commit.

For non-python.org Python installations (Homebrew, asdf, pyenv, system
Python), the fix is usually:

```bash
