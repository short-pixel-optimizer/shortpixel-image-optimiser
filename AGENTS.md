# AGENTS.md

Entry point for AI coding agents (Codex, Cursor, Copilot, Claude Code, and
anything else that reads this file).

**The detailed docs are `CLAUDE.md` (architecture, commands, conventions) and
`TESTING.md` (the full test reference). Read those.** This file only carries
the handful of facts that are expensive to get wrong, so it stays short enough
not to drift.

## Verify your environment before you change anything

```bash
bin/test.sh --verify
```

Runs the smallest suite and requires a real passing result. It exits non-zero
unless tests actually executed, so it cannot report success on a broken setup.
Docker must be running; nothing else is needed on the host — no PHP, MySQL,
Composer, WP-CLI or SVN.

## Running tests

```bash
bin/test.sh                                          # all unit suites (PHP 8.3, matches CI)
bin/test.sh --testsuite model                        # one suite
bin/test.sh --testsuite model --filter ImageModelTest # one file, by CLASS name
bin/test.sh --integration                            # integration suite
bin/test.sh --ms                                     # multisite suite
bin/test.sh --compat                                 # cross-plugin compatibility suite
bin/test.sh --all                                    # unit + integration + compat
```

Three traps, all of which look like success if you are not watching:

1. **Testsuite names are case-sensitive.** The unit suites are `Helper`,
   `model` (lowercase), `External`, `Controllers`, `SPIO Main`. `bin/test.sh`
   rejects an unknown name, but a bare `vendor-tests/bin/phpunit --testsuite
   Model` prints `No tests executed!` and exits **0**.
2. **`--filter` takes a CLASS name, not a file path.** PHPUnit cannot map
   `test-Foo.php` to `FooTest`. A filter that matches nothing is also a silent
   exit 0.
3. **Always check the test count in the output.** `OK (0 tests)` or
   `No tests executed!` means your change was never exercised.

## Do not edit

- `build/shortpixel/` — bundled/generated vendor modules. Edit the sources in
  the sibling `../modules/*` repos, then rebuild (`composer run buildSP`).
- `vendor/`, `vendor-tests/` — installed dependencies, gitignored.

## Linting

Not run by `bin/test.sh` or CI, and the existing code does not pass cleanly.
Advisory — run it on files you touched, not repo-wide:

```bash
COMPOSER=composer.tests.json composer install          # phpcs installs into vendor-tests/
vendor-tests/bin/phpcs --standard=phpcs-security.xml <file>
```

## Conventions

- All classes are namespaced `ShortPixel\`, PSR-4 from `class/`; the autoload
  manifest is `class/plugin.json`.
- Test files are `tests/**/test-<ClassName>.php`; test classes are `<Name>Test`.
- Controllers and models are singletons — use `getInstance()`.
- The plugin version lives in `wp-shortpixel.php`; do not copy it elsewhere.
