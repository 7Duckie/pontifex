# Contributing to Pontifex

## What this document is

Thanks for taking an interest. This document covers what you need to
develop and contribute to the plugin: local environment setup, commit
and branch conventions, test commands, and the quality gates every PR
must pass.

For architectural background — what the format is and why it's shaped
this way — see [`docs/archive-format.md`](../docs/archive-format.md),
[`docs/archive-format-design.md`](../docs/archive-format-design.md), and
[`docs/threat-model.md`](../docs/threat-model.md). Ideas not yet
committed to a release live in [`docs/idea-bank.md`](../docs/idea-bank.md).

## One-time setup

```bash
git clone https://github.com/7Duckie/pontifex.git
cd pontifex
composer install
composer dev:setup
```

After this, `git commit` runs the fast pre-commit checks and `git push`
runs the heavier pre-push checks. CI runs everything plus the security
audit.

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
| `composer test` | Unit suite (PHPUnit; no WordPress runtime) |
| `composer test:unit` | Unit tests only — same as `composer test` |
| `composer test:integration` | Integration suite — real WordPress via wp-env (see below) |
| `composer check` | Lint + analyse + unit tests + audit — the pre-PR sweep |

## Pre-commit / pre-push budget

Pre-commit must complete in ≤3 seconds on a normal change. Pre-push must
complete in ≤60 seconds. If a check exceeds its budget, demote it rather
than tolerate the slowdown — a hook everyone disables protects nobody.

## Commit conventions

- Subject ≤72 characters, imperative, no trailing period.
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
- Work on short-lived `type/scope` branches off `main`, using
  Conventional Commits types — `feat/`, `fix/`, `test/`, `ci/`,
  `docs/`, `refactor/`, `chore/` (e.g. `feat/import`,
  `ci/integration-suite`). No version numbers in branch names.
- Security branches: kept private until disclosed; coordinate via the
  email in SECURITY.md.

## Security

Vulnerability reports: see [SECURITY.md](SECURITY.md), do not file
public issues. Architectural questions about security: open a public
discussion or PR.

See also [`docs/threat-model.md`](../docs/threat-model.md) for the
attack-surface ranking. PRs touching ranks 1-4 should reference the
threat model in their description.

## Design language

From v0.1.0 onward, Pontifex's UI follows Swiss design principles — the
mid-20th-century typographic style associated with Helvetica, the
Bauhaus school, and modern wayfinding systems. The plugin's admin
screens, when they arrive in v0.3.0+, will reflect these principles
consistently:

- **Typography over decoration.** Information hierarchy through type
  weight and size, not boxes, borders, or alert colours.
- **Generous whitespace.** Density through good rhythm, not by packing
  pixels.
- **Sans-serif faces.** Helvetica or a system sans-serif. No serifs,
  no display faces.
- **Restrained colour.** Black, white, and grey as the working
  palette, with one accent colour used sparingly. No traffic-light
  status colours unless the status genuinely demands them.
- **Grid-based layouts.** Asymmetric balance, sharp lines, alignment
  to a consistent grid.
- **Restraint over alarm.** Destructive actions (e.g. the reset
  feature in the idea bank) are communicated through clear
  language and explicit confirmation steps, not warning-coloured
  boxes that operators learn to dismiss.

This direction will be promoted to a dedicated `docs/design-language.md`
once admin UI work begins in earnest (target: v0.3.0+) and there is
enough component vocabulary, colour palette, and type scale to
populate it. For now, contributors making UI proposals or sketches
should note the principles above and bring intentional restraint to
anything they build.

## Quality-gate reference

- Code style — `phpcs.xml.dist` (WordPress-Extra + Pontifex prefix rules)
- Static analysis — `phpstan.neon.dist` (level 6, ratcheting to 8 by v1.0)
- Tests — `phpunit.xml.dist` (unit) and `phpunit-integration.xml.dist`
  (integration, via wp-env)
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

Pontifex is developed against a real WordPress, not against mocked
functions. The repository ships a
[wp-env](https://www.npmjs.com/package/@wordpress/env) configuration
(`.wp-env.json`) that runs WordPress, the web server, and
MySQL/MariaDB in Docker for you. With Docker running:

```bash
npx @wordpress/env start
```

This boots a development site on `http://localhost:8910` with Pontifex
already mounted and active (`.wp-env.json` maps the project in as a
plugin), plus a separate test site on `http://localhost:8911` that the
integration suite uses. The bare `wp-env` command is not on the PATH —
always invoke it as `npx @wordpress/env …`.

### Run WP-CLI against the site

Pontifex registers a `wp pontifex` command tree. Run it inside the
development container:

```bash
npx @wordpress/env run cli wp pontifex doctor
npx @wordpress/env run cli wp pontifex export --output=/tmp/site.wpmig --yes
```

If you see "Command 'pontifex' is not registered," confirm the plugin
is active with `npx @wordpress/env run cli wp plugin list`.

### Run the tests

```bash
composer test          # unit suite, on your host PHP — fast, no Docker
# integration suite — must run inside the wp-env tests container:
npx @wordpress/env run tests-cli --env-cwd=wp-content/plugins/pontifex composer test:integration
```

The unit suite mocks WordPress and runs directly on your machine. The
integration suite boots a real WordPress, so it must run inside the
wp-env *tests* container, where `WP_TESTS_DIR` and the test database
exist — running `composer test:integration` on the host fails with a
missing-constants error. To exercise a specific PHP version, drop a
`.wp-env.override.json` containing `{ "phpVersion": "8.5" }` next to
`.wp-env.json` and restart wp-env; CI does exactly this across the
8.2–8.5 matrix.

### Your daily cycle

1. Edit code in your editor of choice (pointed at the project root).
2. Run `composer check` to lint, analyse and unit-test before
   committing.
3. Use `npx @wordpress/env run cli wp pontifex …` to drive the commands
   against real WordPress.

Edits take effect immediately — wp-env mounts your working tree, so
there is no "deploy" step between saving and running.

## Step debugging with Xdebug

Stepping through failing code beats scattering `var_dump()` calls.
wp-env ships Xdebug; start the environment with it enabled:

```bash
npx @wordpress/env start --xdebug
```

Xdebug then listens on the standard port `9003`. Point your editor's
PHP debugger at the wp-env container (in PhpStorm: a CLI interpreter on
the Docker image, port `9003`, "Can accept external connections"
ticked), set a breakpoint, and run a command — for example
`npx @wordpress/env run cli wp pontifex doctor` — to hit it. The
[wp-env Xdebug guide](https://www.npmjs.com/package/@wordpress/env#using-xdebug)
covers editor-specific setup. Start without `--xdebug` for everyday
work, since Xdebug slows execution noticeably.


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
python3 -m pip install --upgrade certifi
```
