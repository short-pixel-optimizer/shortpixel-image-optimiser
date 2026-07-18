# Running the ShortPixel Image Optimiser tests

This document describes how to run the PHPUnit test suite locally and how it
maps to the GitHub Actions CI. Nothing here is shipped to WordPress.org —
this file lives in the repo for the dev team.

## What's in the suite

The suite lives under `tests/` and is split into four PHPUnit testsuites via
`phpunit.xml.dist`:

| Testsuite     | Path                                | Covers                                                  |
|---------------|-------------------------------------|---------------------------------------------------------|
| `Helper`      | `tests/Helper/`                     | Utility classes under `class/Helper/`                   |
| `model`       | `tests/Model/`                      | Data models + business logic under `class/Model/`       |
| `External`    | `tests/External/`                   | Third-party integrations under `class/external/`        |
| `Controllers` | `tests/Controller/`                 | Request handlers under `class/Controller/`              |
| `SPIO Main`   | `tests/` (excluding the four above) | Root plugin classes (bootstrap, `ViewController`, etc.) |

The tests run against a real WordPress test-environment (using the
`WP_UnitTestCase` base class), not `WP_Mock`, so they need a MySQL database
and a checkout of the WordPress test framework — see the setup section below.

Test files follow the convention `test-<ClassName>.php`. Bootstrap lives at
`tests/bootstrap.php`.

## Recommended: run locally via Docker (OS-agnostic)

**Prerequisite:** Docker Desktop (macOS / Windows) OR Docker Engine + the
`docker compose` plugin (Linux). Nothing else — no local PHP, MySQL,
Composer, WP-CLI, or SVN required.

### Quick start

```bash
# Default — every testsuite on PHP 8.3 (matches the CI baseline)
bin/test.sh

# Specific testsuite
bin/test.sh --testsuite Model
bin/test.sh --testsuite External

# Specific test method
bin/test.sh --filter test_isProcessable

# Specific test file
bin/test.sh tests/Model/test-ImageModel.php
```

### PHP version matrix

The setup supports PHP 7.4 (legacy minimum), 8.3 (current mainstream), and
8.5 (upcoming). Each version gets its own Docker image tag, so switching
between versions is cache-warm after the first build per version.

```bash
# One specific PHP version
bin/test.sh --php 7.4
bin/test.sh --php 8.5 --testsuite Model

# All three sequentially — same as CI's matrix strategy
bin/test.sh --matrix

# Combine matrix with a filter
bin/test.sh --matrix --filter test_handleAvif
```

### Integration suite

The integration suite (`tests/Integration/`, `phpunit-integration.xml`) runs
the real optimize/restore pipeline against a WordPress test install, with
only the outbound ShortPixel API mocked at the HTTP layer. It runs in its
own phpunit invocation so the fast unit signal and the slow integration
signal stay separated.

One special case: the Cloudflare purge tests (`test-CloudflarePurge.php`)
boot a local `php -S` capture server on port 8437 inside the container,
because the purge uses raw cURL that the WP HTTP mock can't intercept.
No real Cloudflare traffic is ever sent.

```bash
# Integration suite only
bin/test.sh --integration
bin/test.sh --integration --php 7.4
bin/test.sh --integration --filter test_optimize

# Integration suite on all three PHP versions
bin/test.sh --matrix --integration
```

### Real-API smoke tests

The smoke suite (`tests/Smoke/`) removes the HTTP mock and runs the
pipeline against the **live** ShortPixel API — catching contract drift a
mock can't see. It needs a valid API key and consumes real quota credits
(about one per test), so it is never part of `--integration`, `--all`,
or CI; without the key every test skips.

```bash
SHORTPIXEL_SMOKE_KEY=<your 20-char key> bin/test.sh --smoke
```

Because the live API fetches images by URL and can't reach the local
test install, the suite remaps the request URL list to the committed
fixtures' public `raw.githubusercontent.com` URLs (same bytes) and
disables thumbnail processing — only main files have public counterparts.

### Cross-plugin compatibility tests

The compat suite (`tests/Compat/`) runs the SPIO integrations against the
REAL partner plugins — WooCommerce, NextGen Gallery, and WP Offload Media
Lite — downloaded from wordpress.org (latest stable, zips cached in the
`wp-tests-cache` volume) and activated natively in the test install.
WPML has no public download and is currently not covered.

```bash
bin/test.sh --compat
bin/test.sh --compat --filter CompatWooCommerce
```

How it works:

- `--compat` downloads + extracts the partner plugins into the test
  install's `wp-content/plugins/`, then runs phpunit with
  `SPIO_PARTNER_PLUGINS=1` and the `Compat` testsuite.
- `tests/bootstrap.php` activates the partners via a
  `pre_option_active_plugins` filter (real WP core plugin loading);
  `tests/Integration/bootstrap.php` fires their activation hooks once so
  their installers create the tables they need (DDL auto-commits, so the
  tables survive per-test rollbacks).
- Plain `--integration` / `--all` runs never load the partner plugins —
  the env variable gates everything — so the standard suites are
  unaffected.
- The suite runs on PHP 8.3 or 8.5 with WP latest. PHP 7.4 and pinned
  WP versions exit early with a skip note — partner plugin floors (WP
  Offload Media Lite needs PHP 8.1+, current partner releases require
  modern WP), not ours.
- Each test also self-skips when its partner plugin isn't loaded, so an
  accidental plain-phpunit run of the suite is harmless.

### WordPress version

Tests run against the latest WordPress by default. `--wp <version>` pins a
specific version (pass the tag WordPress publishes — `5.9`, not `5.9.0`).
Each WP version keeps its own cache dirs inside the `wp-tests-cache`
volume, so switching versions is cache-warm after the first install.

```bash
bin/test.sh --wp 5.9                          # unit suites on WP 5.9
bin/test.sh --wp 5.9 --php 7.4 --integration  # old WP + old PHP combo
```

CI mirrors this: pushes run the integration suite on WP latest across
PHP 7.4/8.3/8.5, plus WP 5.9 (the oldest version that runs on this
test setup) on PHP 7.4 and 8.3. Pull requests run PHP 8.3 / WP latest,
plus the same WP 5.9 combos. Every run also includes the `compat` job
(PHP 8.3 and 8.5, WP latest) that downloads the partner plugins and
runs the Compat testsuite — same steps as `bin/test.sh --compat`.

### Everything in one go

```bash
# Unit + integration + compat suites, one command (PHP 8.3 / WP latest)
bin/test.sh --all

# The full local sweep: all three passes on PHP 7.4, 8.3 AND 8.5
# (the compat pass self-skips on 7.4 — partner plugin floors)
bin/test.sh --matrix --all

# Unit + integration on a pinned combo (compat pass skips off-latest)
bin/test.sh --all --wp 5.9 --php 7.4
```

All passes always run — a unit failure doesn't hide the integration or
compat result (or vice versa); failures are aggregated in the final
verdict.

### Debug workflow

```bash
# Drop into an interactive bash shell inside the PHP container.
# Handy for repeated iteration without booting a fresh container per run.
bin/test.sh --shell

# From inside the shell:
vendor/bin/phpunit --testsuite Model
vendor/bin/phpunit --filter test_foo tests/Model/test-Bar.php
```

### Cache / reset

```bash
# Nuke all containers, volumes, and per-version PHP images.
# Next run rebuilds everything from scratch (~3-5 min for one version).
bin/test.sh --clean
```

Caches persisted between runs:

- **`vendor/`** — lives on the host via the bind mount, so `composer install` runs only when `vendor/autoload.php` is missing.
- **`/tmp/wordpress-tests-lib`** and **`/tmp/wordpress`** — persist in the `wp-tests-cache` named Docker volume, so the ~3-minute WordPress test-framework SVN checkout only happens once (per `--clean` cycle).
- **PHP images** — Docker layer cache. Each PHP version keeps its own image tag (`spio-tests:php74` / `spio-tests:php83` / `spio-tests:php85`); switching PHP versions doesn't invalidate the others.

### Timing expectations

| Operation                                              | First run                                    | Subsequent runs |
|--------------------------------------------------------|----------------------------------------------|-----------------|
| Full suite on one PHP version                          | 3-5 min (image pull + WP-tests SVN checkout) | ~20-60 s        |
| Full matrix (all 3 PHP versions)                       | 10-15 min                                    | ~1-3 min        |
| Single testsuite                                       | (setup + ~10 s)                              | ~10 s           |
| Integration suite on one PHP version                   | (setup + ~1 min)                             | ~1 min          |
| `--matrix --all` (unit + integration × 3 PHP versions) | 15-20 min                                    | ~6-8 min        |

## Alternative: run locally without Docker

If Docker isn't an option, you can install the dependencies directly on your
host. This is the setup the GitHub Actions runner uses under the hood.

### System requirements

- PHP 8.3 (or one of 7.4 / 8.5 for cross-version testing) with the `mbstring`, `mysqli`, `xml`, and `zip` extensions.
- Composer 2.
- MySQL 8.0 (any 5.7+ works but 8.0 matches CI).
- WP-CLI (the `wp-cli.phar` binary somewhere on `$PATH` as `wp`).
- SVN (`subversion` package) — required by `bin/install-wp-tests.sh` to check out the WordPress test framework.
- `mysql` client — required by the same script to create the test database.

### macOS install (Homebrew)

```bash
brew install php@8.3 composer mysql svn wp-cli
brew services start mysql
```

### Linux install (Debian / Ubuntu)

```bash
sudo apt-get install php8.3-cli php8.3-mbstring php8.3-mysql php8.3-xml \
                     php8.3-zip composer mysql-server subversion
# WP-CLI:
curl -sSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o /usr/local/bin/wp
chmod +x /usr/local/bin/wp
sudo service mysql start
```

### Windows

Native Windows install is discouraged because `bin/install-wp-tests.sh` is a
Bash script and depends on GNU-style tools (`grep -oP`, `sed -i`, `svn`).
Use **WSL2** with a Debian/Ubuntu instance and follow the Linux instructions
above, OR use the Docker path.

### One-time bootstrap

```bash
# Install PHP dependencies
composer install

# Install the WordPress test framework (~3 min — SVN checkout).
# Adjust the DB creds to match your local MySQL setup.
bin/install-wp-tests.sh wordpress_test root '' 127.0.0.1 latest
```

The install script:
1. Downloads the latest WordPress into `/tmp/wordpress` via WP-CLI.
2. Creates a `wordpress_test` MySQL database.
3. Runs `wp core install` to seed WordPress.
4. SVN-checks-out the WordPress unit-test framework into `/tmp/wordpress-tests-lib`.
5. Copies + patches `wp-tests-config.php` with the DB credentials.

### Running tests

```bash
# All testsuites
vendor/bin/phpunit

# Specific testsuite
vendor/bin/phpunit --testsuite Model
vendor/bin/phpunit --testsuite External

# Specific test method
vendor/bin/phpunit --filter test_isProcessable

# Specific file
vendor/bin/phpunit tests/Model/test-ImageModel.php
```

## Running against the CI reference

The CI configuration lives at `.github/workflows/phpunit.yml`. It:

- Runs on `ubuntu-latest` GitHub Actions runners.
- Uses a matrix strategy across PHP 7.4 / 8.3 / 8.5 (three jobs per push).
- Installs PHP via `shivammathur/setup-php@v2`.
- Uses MySQL 8.0 as a service container on port 3306.
- Runs each testsuite in its own `phpunit` invocation so per-suite exit codes
  are visible even when an earlier suite fails.

The Docker-based local setup (`.docker/Dockerfile.tests` +
`docker-compose.tests.yml` + `bin/test.sh`) is designed to match this
environment byte-for-byte — same PHP versions, same MySQL image, same
`bin/install-wp-tests.sh` script. If a test passes locally via `bin/test.sh`,
it should pass on CI.

## Writing new tests

- Test files: `tests/<Group>/test-<ClassName>.php`.
- Extend `WP_UnitTestCase` (not raw `PHPUnit\Framework\TestCase`) — SPIO relies on real WordPress state (real `wpdb`, real filters, real cache).
- Use the existing test-file headers as templates. Each declares a focus-areas section, a skipped-at-unit-level section (integration territory), and reflection helpers where private members need inspection.
- For SPIO's own database tables (`shortpixel_meta`, `shortpixel_folders`, `shortpixel_postmeta`), call `InstallHelper::checkTables()` in `set_up()` — plugin activation hooks don't fire in the WP test harness. See `tests/Model/test-DirectoryOtherMediaModel.php` for the canonical pattern.
- Settings mutation: snapshot in `set_up()`, restore in `tear_down()`. See `tests/Model/test-PNGConverter.php` for the pattern.
- Pinned regression tests: suffix the method name with `_pinned_for_deferred_fix` and include a docblock referencing the bug's file:line so it's grep-able.

## Troubleshooting

**"Could not find bin/install-wp-tests.sh" or SVN errors on first run**
The Docker approach installs SVN in the container. If running natively, install `subversion` (Homebrew / apt) and retry.

**"Class 'WP_UnitTestCase' not found"**
The WordPress test framework isn't installed. Run `bin/install-wp-tests.sh` (native) or `bin/test.sh` (Docker — installs automatically on first run).

**Tests pass locally but fail on CI (or vice versa)**
Run `bin/test.sh --matrix` locally to check across PHP versions. Most cross-env failures are PHP-version-specific deprecations (PHP 8.5 surfaces things 8.3 doesn't) or a stale test cache. Try `bin/test.sh --clean` and rerun.

**Docker on macOS: "no matching manifest for linux/arm64"**
All the images we use (`php:*-cli`, `mysql:8.0`, `composer:2`) publish arm64 tags natively. If you see this, Docker Desktop needs updating, or a specific PHP version tag doesn't ship arm64 yet — fall back to `--php 8.3` which definitely does.

**Docker: "port 3306 already in use"**
The MySQL service in `docker-compose.tests.yml` does NOT publish its port to the host, so this shouldn't happen. If it does, another Compose file in the repo is claiming that port — check your local overrides.

**Docker: `ERROR 2026 (HY000): TLS/SSL error: self-signed certificate in certificate chain`**
MySQL 8 auto-generates a self-signed TLS cert at startup and the MariaDB client (installed via Debian's `default-mysql-client`) rejects it. The Dockerfile ships a `/usr/local/bin/mysql` wrapper that always passes `--skip-ssl` to the underlying binary, avoiding TLS negotiation entirely. Safe because the mysql traffic never leaves Docker's internal network. If you still see this error, your image is stale — `bin/test.sh --clean` now runs `docker compose build --no-cache` to guarantee a fresh rebuild:

```bash
bin/test.sh --clean
bin/test.sh
```

**Some tests are labeled `pinned_for_deferred_fix` — should I care?**
Those tests are supposed to fail. They pin real bugs that are being tracked for the maintainer to review. See the sidecar memo `project_deferred_root_bugs.md` (not in the repo — internal doc) for the full list. Don't "fix" a pinned test by changing its assertion; that defeats the sentinel.

**PHPUnit prints a few dots + F then abruptly ends with no summary (exit code 0)**
Something in the code-under-test called `exit()` mid-test, which kills PHPUnit before it can emit its summary. The tell: no `Tests: X, Assertions: Y` line, no `OK`/`FAILURES!` block. Common culprits inside SPIO: `ApiKeyModel::checkRedirect()` (`wp_safe_redirect() + exit()` when no verified key), `wp_die()` in AJAX paths, `wp_send_json*()`. The fix is per-test: seed whatever state short-circuits the exit — e.g. `\wpSPIO()->settings()->redirectedSettings = 1` for the redirect guard (pattern: `tests/Helper/test-UiHelper.php::set_up`). Running with `--debug` shows exactly which test the process died in.
