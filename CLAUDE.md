# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Plugin Overview

ShortPixel Image Optimizer is a WordPress plugin for image optimization, WebP/AVIF conversion, and AI-powered image features (upscale, background removal, SEO alt text). Requires PHP 7.4+, WordPress 4.8+. For the current version read `wp-shortpixel.php` (the `Version:` header / `SHORTPIXEL_IMAGE_OPTIMISER_VERSION`) — it is deliberately not duplicated here, because a copied version number goes stale every release.

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
