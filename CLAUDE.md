# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Plugin Overview

ShortPixel Image Optimizer is a WordPress plugin (v6.4.3) for image optimization, WebP/AVIF conversion, and AI-powered image features (upscale, background removal, SEO alt text). Requires PHP 7.4+, WordPress 4.8+.

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

PHPUnit with WP_Mock (no live WordPress required for unit tests):

```bash
# Run all tests
vendor/bin/phpunit

# Run a specific test suite
vendor/bin/phpunit --testsuite fileSystem
vendor/bin/phpunit --testsuite imageModel
vendor/bin/phpunit --testsuite Controllers
vendor/bin/phpunit --testsuite queue
vendor/bin/phpunit --testsuite model

# Run a single test file
vendor/bin/phpunit tests/Model/image/test-ImageModel.php
```

Test bootstrap: `tests/bootstrap.php`. Test files follow the naming convention `test-*.php`.

For integration/acceptance tests (requires a running WordPress instance):
```bash
./test.sh
./test.sh -t <suite_name>
```

## Linting

```bash
vendor/bin/phpcs --standard=phpcs-ruleset.xml class/
vendor/bin/phpcs --standard=phpcs-security.xml class/
```

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
