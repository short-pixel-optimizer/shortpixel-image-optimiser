# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Plugin Overview

ShortPixel Image Optimizer is a WordPress plugin for image optimization, WebP/AVIF conversion, and AI-powered image features (upscale, background removal, SEO alt text). Requires PHP 7.4+, WordPress 5.9+ (declared in the `wp-shortpixel.php` header and in `readme.txt`). For the current version read `wp-shortpixel.php` (the `Version:` header / `SHORTPIXEL_IMAGE_OPTIMISER_VERSION`) — it is deliberately not duplicated here, because a copied version number goes stale every release.

## Build & Dependencies

Dependencies live in `../modules/*` (sibling directory path repositories, loaded via Composer path repositories with symlinks). Run after cloning:

```bash
composer install
```

To rebuild the autoloader/bundled assets:

```bash
composer run buildSP
composer run buildLoader
```

The `build/shortpixel/` directory contains bundled dependencies (notices, log, shortq, replacer2) and should not be edited directly.

## Testing

Test dependencies (PHPUnit + Yoast polyfills) are kept SEPARATE from the main
composer.json (which is the plugin/module build tool). They live in
`composer.tests.json` / `composer.tests.lock` and install into `vendor-tests/`:

```bash
COMPOSER=composer.tests.json composer install
```

All suites run against a real WordPress test install inside Docker via
`bin/test.sh` (see TESTING.md for the full reference):

```bash
bin/test.sh --verify               # FIRST: prove the environment works (fails unless tests really ran)
bin/test.sh                        # all unit suites (PHP 8.3, matches CI)
bin/test.sh --testsuite Controllers
bin/test.sh --integration          # integration suite (phpunit-integration.xml)
bin/test.sh --ms                   # multisite suite
bin/test.sh --compat               # cross-plugin compatibility suite
bin/test.sh --all                  # unit + integration + compat
bin/test.sh --php 8.5 --integration
bin/test.sh --testsuite model --filter ImageModelTest   # single file: filter on CLASS name (file paths don't work — PHPUnit can't map test-Foo.php to FooTest)
```

Testsuite names are **case-sensitive**: the unit suites are `Helper`, `model`
(lowercase), `External`, `Controllers`, `SPIO Main`, declared in
`phpunit.xml.dist`. `bin/test.sh` rejects an unknown name, but calling PHPUnit
directly (`vendor-tests/bin/phpunit --testsuite Model`) prints
`No tests executed!` and exits **0** — a green run that tested nothing. The
same applies to a `--filter` that matches nothing, so always check the test
count in the output.

Unit bootstrap: `tests/bootstrap.php`; integration bootstrap:
`tests/Integration/bootstrap.php`. Test files follow the naming convention
`test-*.php`.

## Linting

phpcs ships with the test dependencies (`composer.tests.json`), NOT with
`composer.json` — same split as PHPUnit, so the binary lives in
`vendor-tests/bin/`. Install it the same way:

```bash
COMPOSER=composer.tests.json composer install    # installs phpunit + phpcs into vendor-tests/
```

```bash
vendor-tests/bin/phpcs --standard=phpcs-ruleset.xml class/
vendor-tests/bin/phpcs --standard=phpcs-security.xml class/
```

Both rulesets reference the `WordPress` standard, which comes from
`wp-coding-standards/wpcs`; `dealerdirect/phpcodesniffer-composer-installer`
registers it automatically on install, so no `installed_paths` setup is
needed. Verify with `vendor-tests/bin/phpcs -i` (the list must include
`WordPress`).

Linting is NOT part of `bin/test.sh` or CI — the existing code does not pass
these rulesets cleanly, so run it on the files you touched, not repo-wide,
and treat the output as advisory rather than a gate.

## Architecture

### Entry Points

- `wp-shortpixel.php` — Plugin bootstrap: defines constants, sets up autoloader, calls `wpSPIO()`
- `shortpixel-plugin.php` — `ShortPixelPlugin` singleton class, attaches to `plugins_loaded` (priority 5), `init`, `admin_init`

### Initialization Flow

1. `plugins_loaded` (priority 5): `ShortPixelPlugin::lowInit()` — early setup
2. `init`: Starts `CronController`
3. `admin_init`: Version checks, quota retrieval, text domain
4. Controllers instantiated: `FrontController`, `AdminController`, `AdminNoticesController`, `WPCliController`

### Namespace & Autoloading

All classes use the `ShortPixel\` namespace, PSR-4 autoloaded from `/class`. The autoloader manifest is `class/plugin.json`. Tests use `ShortPixel\Tests\` from `/tests`.

### Code Structure

```
class/
  Controller/         - Request handlers
    Optimizer/        - Image optimization pipeline
    Queue/            - MediaLibrary and Custom queues
    Front/            - CDN, PageConverter, PictureController (WebP/AVIF delivery)
    View/             - Template rendering controllers
    AjaxController.php
    QueueController.php
    QuotaController.php
  Model/              - Data models & business logic
    Image/            - ImageModel, MediaLibraryModel, CustomImageModel
    File/             - File system operations
    Queue/            - Queue data models
    Converter/        - Format conversion models
    SettingsModel.php, ApiKeyModel.php, EnvironmentModel.php, etc.
  Helper/             - Utilities (Install, Util, Download, Ui)
  view/               - PHP template files (settings, bulk, custom pages)
  external/           - Third-party integrations (WooCommerce, NextGen, WP-CLI, S3 offload, Cloudflare, etc.)
res/
  js/, css/, scss/    - Frontend assets
build/shortpixel/     - Bundled vendor modules (do not edit directly)
```

### Key Patterns

- **Singleton:** Controllers and Models use `getInstance()` — avoid direct instantiation
- **MVC:** Controllers handle WordPress hooks/AJAX, Models own data and business logic, Views are PHP templates in `class/view/`
- **Queue system:** Image optimization runs through `shortq` queue library (in `build/shortpixel/shortq/`), orchestrated by `QueueController` and `MediaLibraryQueue`/`CustomQueue`
- **Two image pipelines:** Media Library images (`MediaLibraryModel`) and Custom/other images (`CustomImageModel`) have separate models but share `ImageModel` base logic
- **Frontend delivery:** `FrontController` → `PictureController`/`PageConverter` handles real-time WebP/AVIF `<picture>` tag injection and CDN URL replacement

## Git workflow (no commits, no branches, no worktrees unless asked)

The maintainer reviews every change locally before anything is committed.
These rules override any default agent behaviour (background-job or
worktree-isolation defaults included):

- **Never commit** in this repository unless specifically instructed to.
  Leave the work uncommitted in the working tree and end the hand-off with
  a ready-to-paste `git commit -m` suggestion instead.
- **Never create a branch or a git worktree** unless clearly asked for one.
- **Code, docs and test changes go into the current working tree** (the
  checkout and branch that are checked out when the task starts), unless
  instructed differently.
- Never push, open a PR, merge or rebase on your own initiative.

## Changing existing tests

Existing tests are read-only by default. A red test is evidence, not an
obstacle: it is first assumed to have found a defect. Never change a test just
to make a run green.

1. **Find the cause first.** A failing test has one of three causes: the code
   is wrong, the intended behaviour changed, or the test is wrong. The
   hand-off names the cause for every failing test before any assertion
   changes.
2. **Valid reasons to change an existing test** (the list is closed):
   - the maintainer changed the intended behaviour, or approved the change;
   - a pinned defect was fixed, so its pin flips to a regular test;
   - the code under test was renamed or moved and the asserted behaviour stays
     the same;
   - the test was wrong, or depended on something outside its subject (the
     date, the run order, the environment), and the hand-off shows the proof.
3. **Never acceptable as a fix:** loosening an assertion (`assertSame` to
   `assertEquals`, an exact value to "contains", a count to "at least"),
   deleting an assertion or a test, widening an allow-list of known errors,
   raising a timeout or a tolerance, adding a skip, refreshing a snapshot
   without an intended change.
4. **Ask before, not after.** A flipped pin and a pure rename need no
   question. For every other reason, stop, report the failure and its cause,
   and wait for the maintainer's decision. A newly found defect is pinned,
   never fixed and never hidden.
5. **Removal needs replacement.** A test is deleted only when the thing it
   tested no longer exists. The hand-off names the test that covers the
   behaviour now, or says that none does.
6. **Report test changes on their own.** Every hand-off lists each changed,
   removed or loosened test with its reason. Changes to existing tests go into
   their own commit, apart from new tests and from code, and the suggested
   commit message carries a `Tests-Changed: <reason>` line.
7. **Prove a changed test still bites.** After changing a test, show that it
   fails against the old or the broken behaviour, and say how in the hand-off.
8. **Snapshots:** `--update-snapshots` only for an intended UI change, one
   spec at a time, with the before and after images named in the hand-off.

`bin/check-test-changes.sh` reports the uncommitted changes that fall under
these rules; run it before every hand-off that touches `tests/`. CI runs the
same script (`.github/workflows/test-guard.yml`): a pushed commit that changes
existing tests fails without a `Tests-Changed: <reason>` line in its message,
and a pull request fails until a maintainer applies the
`test-changes-approved` label.

## Known bugs and @todo (no bug numbers in the repo)

Bug tracking is internal (Asana), and its numbering must never appear in the
code, tests or docs — no `BUG #42`, `pin42`, `regression42`, commit hashes as
history, review dates or people's names. This holds until bug tracking moves
to GitHub issues; only then may a public issue reference be used.

- A known, not-yet-fixed defect is documented with a `@todo` in the nearest
  docblock (`// @todo` inline, `# @todo` in shell/YAML): what is wrong, where,
  and the suggested fix if known. Present tense, no number, no date.
- A test that pins such a defect is named `test_<description>_pinned_for_deferred_fix`
  and its docblock describes the defect and how to recognise the fix.
- A fixed bug needs no mention at all. Keep only the reason the code is the way
  it is, in the present tense (e.g. "the guard runs on the sanitised value so
  that…"), never the history ("fixed in abc1234", "used to…").
