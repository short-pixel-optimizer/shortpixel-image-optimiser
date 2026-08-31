#!/usr/bin/env bash
#
# Local PHPUnit test runner for SPIO — wraps docker-compose.tests.yml so
# every team member runs the exact same PHP 8.3 + MySQL 8.0 combo the
# GitHub Actions CI uses, regardless of host OS.
#
# Usage:
#     bin/test.sh                                  # Every testsuite on PHP 8.3 (matches CI default)
#     bin/test.sh --php 7.4                        # Same, but on PHP 7.4
#     bin/test.sh --php 8.5 --testsuite Model      # One suite on PHP 8.5
#     bin/test.sh --matrix                         # Run everything on 7.4, 8.3, AND 8.5 in sequence
#     bin/test.sh --testsuite Model                # One testsuite
#     bin/test.sh --testsuite External             # One testsuite
#     bin/test.sh --filter test_something          # One test method
#     bin/test.sh tests/Model/test-ImageModel.php  # One test file
#     bin/test.sh --integration                    # Integration suite (phpunit-integration.xml)
#     bin/test.sh --integration --php 7.4          # Integration suite on PHP 7.4
#     bin/test.sh --matrix --integration           # Integration suite on 7.4, 8.3 AND 8.5
#     bin/test.sh --wp 6.0                         # Against WordPress 6.0 instead of latest
#     bin/test.sh --wp 6.0 --php 7.4 --integration # Old WP + old PHP combo
#     bin/test.sh --all                            # Unit + integration + compat suites in one go
#     bin/test.sh --matrix --all                   # Same on 7.4, 8.3 AND 8.5 (compat skips 7.4)
#     bin/test.sh --all --wp 6.0 --php 7.4         # Unit + integration on an old-versions combo
#     SHORTPIXEL_SMOKE_KEY=<key> bin/test.sh --smoke  # Real-API smoke tests (uses quota!)
#     bin/test.sh --compat                         # Cross-plugin compatibility suite (PHP 8.3/8.5 + WP latest)
#     bin/test.sh --ms                             # Multisite suite (WP_MULTISITE=1 test install)
#     bin/test.sh --clean                          # Wipe caches + rebuild
#     bin/test.sh --shell                          # Interactive bash inside the container
#
# Supported PHP versions: 7.4, 8.3, 8.5. Each version gets its own tagged
# image (spio-tests:php74 / spio-tests:php83 / spio-tests:php85) so
# switching between versions is cache-warm after the first build per version.
#
# Prerequisite: Docker Desktop (Mac/Windows) OR Docker Engine + the
# `docker compose` plugin (Linux). Nothing else — no PHP, no MySQL, no
# WP-CLI, no SVN installed on the host.
#
# First run per PHP version: ~3-5 min (image pulls + WordPress test
# framework SVN checkout). Subsequent runs: seconds — WP-tests cached
# in the `wp-tests-cache` named volume, PHP image cached by Docker.

set -euo pipefail

# Change to the repo root so relative paths work regardless of where the
# script is invoked from.
cd "$(dirname "$0")/.."

COMPOSE=(docker compose -f docker-compose.tests.yml)

# --clean: nuke the caches + force a fresh no-cache rebuild of every PHP
# image on next run. The --no-cache rebuild is necessary because Docker's
# buildkit can silently re-use stale layers even after the tagged image
# has been removed — this guarantees the current Dockerfile is what
# gets built next.
if [ "${1:-}" = "--clean" ]; then
    echo "==> Wiping containers + wp-tests-cache volume..."
    "${COMPOSE[@]}" down -v
    # Remove per-version tagged images so the next `run` triggers a build.
    docker image rm spio-tests:php74 spio-tests:php83 spio-tests:php85 2>/dev/null || true
    # Force a fresh build of the current PHP version (defaults to 8.3)
    # so the next `bin/test.sh` runs against known-good layers. Other
    # PHP versions rebuild on first use as usual.
    echo "==> Rebuilding the PHP image from scratch (no build cache)..."
    "${COMPOSE[@]}" build --no-cache php
    echo "==> Done. Next run will re-fetch WordPress + re-install test dependencies."
    exit 0
fi

# --matrix: run every supported PHP version in sequence. Any per-version
# failure is reported at the end but doesn't stop the other versions —
# mirrors the CI `fail-fast: false` matrix behaviour.
if [ "${1:-}" = "--matrix" ]; then
    shift
    FAILED=()
    for VERSION in 7.4 8.3 8.5; do
        echo ""
        echo "=============================================================="
        echo "==> Running full suite on PHP $VERSION"
        echo "=============================================================="
        if ! "$0" --php "$VERSION" "$@"; then
            FAILED+=("$VERSION")
        fi
    done
    echo ""
    echo "=============================================================="
    if [ ${#FAILED[@]} -eq 0 ]; then
        echo "==> All PHP versions passed."
    else
        echo "!!! Failed on PHP versions: ${FAILED[*]}"
        exit 1
    fi
    exit 0
fi

# --php <version>: pick the PHP version to run against. Defaults to 8.3.
# Validated against the supported set so a typo doesn't silently pull an
# unpublished tag.
# --integration: run the integration suite (phpunit-integration.xml) in a
# single phpunit invocation instead of the per-suite unit loop. Keeps the
# fast unit signal and the slow integration signal cleanly separated.
# --wp <version>: WordPress version to test against (default: latest).
# Pass the tag WordPress publishes — "6.0", not "6.0.0". Each version gets
# its own cache dirs inside the wp-tests-cache volume, so switching
# versions is cache-warm after the first install per version.
# --all: run the unit suites, the integration suite, the multisite suite
# AND the compat suite in one invocation (for the selected PHP/WP
# versions). Combine with --matrix to cover every PHP version; the compat
# pass self-skips on PHP 7.4 and pinned WP versions. --all supersedes
# --integration when both are given.
# --smoke: run the real-API smoke suite (tests/Smoke) against the LIVE
# ShortPixel API. Needs SHORTPIXEL_SMOKE_KEY in the host environment and
# consumes real quota credits. Never part of --all/--matrix/CI.
# --ms: run the multisite suite (tests/Multisite) against a MULTISITE
# WordPress test install. WP_MULTISITE=1 makes the WP test bootstrap
# build the install as a network; the tests self-skip when the install
# is single-site, so a stray suite selection never fails.
# --compat: run the cross-plugin compatibility suite (tests/Compat) with
# WooCommerce, NextGen Gallery and WP Offload Media Lite downloaded from
# wordpress.org and activated in the test install. Runs on PHP 8.3/8.5 +
# WP latest (the partner plugins' own floors: WC needs recent WP,
# Offload Lite needs PHP 8.1+ so 7.4 is out).
export PHP_VERSION="8.3"
WP_VERSION="latest"
INTEGRATION=0
ALL=0
SMOKE=0
COMPAT=0
MS=0
while [ $# -gt 0 ]; do
    case "$1" in
        --php)
            PHP_VERSION="$2"
            shift 2
            ;;
        --php=*)
            PHP_VERSION="${1#--php=}"
            shift
            ;;
        --wp)
            WP_VERSION="$2"
            shift 2
            ;;
        --wp=*)
            WP_VERSION="${1#--wp=}"
            shift
            ;;
        --integration)
            INTEGRATION=1
            shift
            ;;
        --all)
            ALL=1
            shift
            ;;
        --smoke)
            SMOKE=1
            shift
            ;;
        --compat)
            COMPAT=1
            shift
            ;;
        --ms)
            MS=1
            shift
            ;;
        *)
            break
            ;;
    esac
done

case "$PHP_VERSION" in
    7.4|8.3|8.5)
        ;;
    *)
        echo "!!! Unsupported PHP version: $PHP_VERSION"
        echo "    Supported: 7.4, 8.3, 8.5"
        echo "    (Adjust the case block in bin/test.sh if you need a different version.)"
        exit 1
        ;;
esac

case "$WP_VERSION" in
    latest)
        ;;
    [0-9]*.[0-9]*)
        ;;
    *)
        echo "!!! Unsupported WordPress version: $WP_VERSION"
        echo "    Use 'latest' or a published WP tag like '6.0' or '6.5.2'."
        exit 1
        ;;
esac

# --all: re-invoke ourselves twice — once for the unit suites, once for
# the integration suite — so each pass behaves exactly like a standalone
# run. Both passes always run; failures are aggregated at the end,
# mirroring the CI behaviour of never letting one signal hide the other.
if [ "$ALL" = "1" ]; then
    FAILED_MODES=()
    echo ""
    echo "=============================================================="
    echo "==> [--all 1/4] Unit suites (PHP $PHP_VERSION / WP $WP_VERSION)"
    echo "=============================================================="
    if ! "$0" --php "$PHP_VERSION" --wp "$WP_VERSION" "$@"; then
        FAILED_MODES+=("unit")
    fi
    echo ""
    echo "=============================================================="
    echo "==> [--all 2/4] Integration suite (PHP $PHP_VERSION / WP $WP_VERSION)"
    echo "=============================================================="
    if ! "$0" --php "$PHP_VERSION" --wp "$WP_VERSION" --integration "$@"; then
        FAILED_MODES+=("integration")
    fi
    echo ""
    echo "=============================================================="
    echo "==> [--all 3/4] Multisite suite (PHP $PHP_VERSION / WP $WP_VERSION)"
    echo "=============================================================="
    if ! "$0" --php "$PHP_VERSION" --wp "$WP_VERSION" --ms "$@"; then
        FAILED_MODES+=("multisite")
    fi
    echo ""
    echo "=============================================================="
    echo "==> [--all 4/4] Compat suite (PHP $PHP_VERSION / WP $WP_VERSION)"
    echo "=============================================================="
    # The compat pass self-skips (exit 0) on PHP 7.4 and on pinned WP
    # versions, so --matrix --all stays green on those combos.
    if ! "$0" --php "$PHP_VERSION" --wp "$WP_VERSION" --compat "$@"; then
        FAILED_MODES+=("compat")
    fi
    echo ""
    echo "=============================================================="
    if [ ${#FAILED_MODES[@]} -eq 0 ]; then
        echo "==> --all: unit + integration + multisite + compat all passed (PHP $PHP_VERSION / WP $WP_VERSION)."
        exit 0
    fi
    echo "!!! --all: FAILED: ${FAILED_MODES[*]} (PHP $PHP_VERSION / WP $WP_VERSION)"
    exit 1
fi

echo "==> Using PHP $PHP_VERSION / WordPress $WP_VERSION"

# Per-WP-version cache dirs inside the wp-tests-cache volume, so 'latest'
# and pinned versions don't clobber each other's install. 'latest' keeps
# the historical un-suffixed paths so existing warm caches stay valid.
if [ "$WP_VERSION" = "latest" ]; then
    WP_DIR_SUFFIX=""
else
    WP_DIR_SUFFIX="-$WP_VERSION"
fi
RUN_ENV=(
    -e WP_VERSION="$WP_VERSION"
    -e WP_CORE_DIR="/tmp/wordpress$WP_DIR_SUFFIX"
    -e WP_TESTS_DIR="/tmp/wordpress-tests-lib$WP_DIR_SUFFIX"
)

# --shell: drop into an interactive bash inside the PHP container.
# Useful for debugging test failures without repeatedly booting a fresh container.
if [ "${1:-}" = "--shell" ]; then
    "${COMPOSE[@]}" up -d mysql
    "${COMPOSE[@]}" run --rm "${RUN_ENV[@]}" php bash
    exit 0
fi

# Boot MySQL in the background; the healthcheck makes `depends_on` block
# the php container until MySQL is accepting connections.
"${COMPOSE[@]}" up -d mysql

# --smoke: real-API smoke suite. Same config file as --integration but
# opts into the Smoke testsuite (excluded from plain runs by
# defaultTestSuite) and forwards the API key into the container. Warn
# loudly when the key is missing — the tests would all skip.
if [ "$SMOKE" = "1" ]; then
    if [ -z "${SHORTPIXEL_SMOKE_KEY:-}" ]; then
        echo "!!! SHORTPIXEL_SMOKE_KEY is not set — every smoke test will SKIP."
        echo "    Run as: SHORTPIXEL_SMOKE_KEY=<your 20-char key> bin/test.sh --smoke"
    fi
    "${COMPOSE[@]}" run --rm "${RUN_ENV[@]}" \
        -e SHORTPIXEL_SMOKE_KEY="${SHORTPIXEL_SMOKE_KEY:-}" \
        php bash -c '
        set -eu
        if [ ! -f vendor-tests/autoload.php ]; then
            echo "==> composer install (first run)"
            COMPOSER=composer.tests.json composer install --no-interaction --prefer-dist
        fi
        if [ ! -d "$WP_TESTS_DIR/includes" ]; then
            echo "==> Installing WordPress $WP_VERSION test framework (first run — ~3 min)"
            bin/install-wp-tests.sh wordpress_test root password mysql "$WP_VERSION"
        fi
        echo "==> vendor-tests/bin/phpunit -c phpunit-integration.xml --testsuite Smoke $*"
        vendor-tests/bin/phpunit -c phpunit-integration.xml --testsuite Smoke "$@"
    ' bash "$@"
    exit $?
fi

# --compat: cross-plugin compatibility suite. Downloads the partner
# plugins from wordpress.org (zips cached in the wp-tests-cache volume,
# extraction refreshed from the cached zip when missing), activates them
# via SPIO_PARTNER_PLUGINS=1 (see tests/bootstrap.php), and runs the
# Compat testsuite. Gated to PHP 8.3/8.5 + WP latest — the partner
# plugins' own requirement floors, not ours.
if [ "$COMPAT" = "1" ]; then
    # PHP 7.4 is excluded by the partner plugins' own floors (WP Offload
    # Media Lite needs PHP 8.1+); pinned WP versions are excluded because
    # current partner releases need recent WP cores.
    if [ "$PHP_VERSION" = "7.4" ] || [ "$WP_VERSION" != "latest" ]; then
        echo "!!! --compat only runs on PHP 8.3/8.5 + WP latest (partner plugin requirement floors)."
        echo "    Requested: PHP $PHP_VERSION / WP $WP_VERSION — skipping (not a failure)."
        exit 0
    fi
    "${COMPOSE[@]}" run --rm "${RUN_ENV[@]}" \
        -e SPIO_PARTNER_PLUGINS=1 \
        php bash -c '
        set -eu
        if [ ! -f vendor-tests/autoload.php ]; then
            echo "==> composer install (first run)"
            COMPOSER=composer.tests.json composer install --no-interaction --prefer-dist
        fi
        if [ ! -d "$WP_TESTS_DIR/includes" ]; then
            echo "==> Installing WordPress $WP_VERSION test framework (first run — ~3 min)"
            bin/install-wp-tests.sh wordpress_test root password mysql "$WP_VERSION"
        fi

        # Fetch + extract the partner plugins into the test install.
        ZIP_CACHE=/tmp/partner-plugin-zips
        PLUGIN_DIR="$WP_CORE_DIR/wp-content/plugins"
        mkdir -p "$ZIP_CACHE" "$PLUGIN_DIR"
        for SLUG in woocommerce nextgen-gallery amazon-s3-and-cloudfront; do
            if [ ! -d "$PLUGIN_DIR/$SLUG" ]; then
                if [ ! -f "$ZIP_CACHE/$SLUG.zip" ]; then
                    echo "==> Downloading $SLUG (latest stable) from wordpress.org"
                    curl -sSL "https://downloads.wordpress.org/plugin/$SLUG.latest-stable.zip" \
                        -o "$ZIP_CACHE/$SLUG.zip"
                fi
                echo "==> Extracting $SLUG into the test install"
                unzip -qo "$ZIP_CACHE/$SLUG.zip" -d "$PLUGIN_DIR"
            fi
        done

        # Commercial partner plugins (WPML, …) have no public download —
        # any zip dropped into tests/partner-plugins/ (gitignored) is
        # extracted too. Re-extracted whenever the zip is newer than the
        # extracted copy, so updating = replacing the zip. Tests
        # self-skip when a commercial partner is absent.
        for LOCAL_ZIP in tests/partner-plugins/*.zip; do
            [ -f "$LOCAL_ZIP" ] || continue
            TOP_DIR=$(unzip -Z1 "$LOCAL_ZIP" | head -1 | cut -d/ -f1)
            if [ -z "$TOP_DIR" ]; then
                echo "!!! Skipping $LOCAL_ZIP — could not read its top-level directory."
                continue
            fi
            if [ ! -d "$PLUGIN_DIR/$TOP_DIR" ] || [ "$LOCAL_ZIP" -nt "$PLUGIN_DIR/$TOP_DIR" ]; then
                echo "==> Extracting local partner zip $LOCAL_ZIP ($TOP_DIR) into the test install"
                rm -rf "${PLUGIN_DIR:?}/$TOP_DIR"
                unzip -qo "$LOCAL_ZIP" -d "$PLUGIN_DIR"
                touch "$PLUGIN_DIR/$TOP_DIR"
            fi
        done

        echo "==> vendor-tests/bin/phpunit -c phpunit-integration.xml --testsuite Compat $*"
        vendor-tests/bin/phpunit -c phpunit-integration.xml --testsuite Compat "$@"
    ' bash "$@"
    exit $?
fi

# --ms: multisite suite. WP_MULTISITE=1 makes the WP test-lib bootstrap
# (re)install the test database as a network install, so no separate
# cache dir or config file is needed — the install is rebuilt on every
# run anyway. Uses the integration config/bootstrap (mock API + base
# class) with the Multisite testsuite.
if [ "$MS" = "1" ]; then
    "${COMPOSE[@]}" run --rm "${RUN_ENV[@]}" \
        -e WP_MULTISITE=1 \
        php bash -c '
        set -eu
        if [ ! -f vendor-tests/autoload.php ]; then
            echo "==> composer install (first run)"
            COMPOSER=composer.tests.json composer install --no-interaction --prefer-dist
        fi
        if [ ! -d "$WP_TESTS_DIR/includes" ]; then
            echo "==> Installing WordPress $WP_VERSION test framework (first run — ~3 min)"
            bin/install-wp-tests.sh wordpress_test root password mysql "$WP_VERSION"
        fi
        echo "==> WP_MULTISITE=1 vendor-tests/bin/phpunit -c phpunit-integration.xml --testsuite Multisite $*"
        vendor-tests/bin/phpunit -c phpunit-integration.xml --testsuite Multisite "$@"
    ' bash "$@"
    exit $?
fi

# --integration: phpunit against phpunit-integration.xml. Extra args
# (--filter, a test file, …) are forwarded as usual. With no extra args,
# a SECOND phpunit invocation runs the IntegrationIsolated suite
# (constant-defining tests that would poison the shared process — see
# tests/Integration/test-ConstantsAndFilters.php); with extra args only
# the requested selection runs.
if [ "$INTEGRATION" = "1" ]; then
    "${COMPOSE[@]}" run --rm "${RUN_ENV[@]}" php bash -c '
        set -eu
        if [ ! -f vendor-tests/autoload.php ]; then
            echo "==> composer install (first run)"
            COMPOSER=composer.tests.json composer install --no-interaction --prefer-dist
        fi
        if [ ! -d "$WP_TESTS_DIR/includes" ]; then
            echo "==> Installing WordPress $WP_VERSION test framework (first run — ~3 min)"
            bin/install-wp-tests.sh wordpress_test root password mysql "$WP_VERSION"
        fi
        if [ $# -eq 0 ]; then
            RC=0
            echo "==> vendor-tests/bin/phpunit -c phpunit-integration.xml"
            vendor-tests/bin/phpunit -c phpunit-integration.xml || RC=1
            echo "==> vendor-tests/bin/phpunit -c phpunit-integration.xml --testsuite IntegrationIsolated"
            vendor-tests/bin/phpunit -c phpunit-integration.xml --testsuite IntegrationIsolated || RC=1
            exit $RC
        fi
        echo "==> vendor-tests/bin/phpunit -c phpunit-integration.xml $*"
        vendor-tests/bin/phpunit -c phpunit-integration.xml "$@"
    ' bash "$@"
    exit $?
fi

# When called with no args, we run each testsuite in its own phpunit
# invocation (matching the CI workflow). This isolates suite crashes —
# if one suite hits a hard exit() bomb (e.g. wp_safe_redirect+exit() in
# a controller), the others still run. Aggregated exit code at the end.
#
# When called WITH args (--testsuite / --filter / a file), we forward
# everything to a single phpunit invocation so callers keep the same
# ergonomics they'd have running vendor-tests/bin/phpunit directly.
if [ $# -eq 0 ]; then
    "${COMPOSE[@]}" run --rm "${RUN_ENV[@]}" php bash -c '
        set -eu
        if [ ! -f vendor-tests/autoload.php ]; then
            echo "==> composer install (first run)"
            COMPOSER=composer.tests.json composer install --no-interaction --prefer-dist
        fi
        if [ ! -d "$WP_TESTS_DIR/includes" ]; then
            echo "==> Installing WordPress $WP_VERSION test framework (first run — ~3 min)"
            bin/install-wp-tests.sh wordpress_test root password mysql "$WP_VERSION"
        fi

        # ANSI colours — enabled only when stdout is a real terminal, so
        # pipes/redirects/CI logs stay clean. Same TTY gate is used to
        # decide whether to force PHPUnits own --colors=always (which we
        # need because tee-ing to a logfile disables PHPUnits auto-detect).
        if [ -t 1 ]; then
            RED=$(printf "\033[31m"); GREEN=$(printf "\033[32m"); CYAN=$(printf "\033[36m"); BOLD=$(printf "\033[1m"); RESET=$(printf "\033[0m")
            PHPUNIT_COLOR="--colors=always"
        else
            RED=""; GREEN=""; CYAN=""; BOLD=""; RESET=""
            PHPUNIT_COLOR="--colors=never"
        fi

        # Per-suite output is tee-d to /tmp/spio-suite-*.log so the end-of-run
        # summary can extract failing test names + suite totals without
        # re-invoking phpunit.
        LOGDIR=$(mktemp -d)
        trap "rm -rf $LOGDIR" EXIT

        FAILED=0
        FAILED_SUITES=""
        for SUITE in "Helper" "model" "External" "Controllers" "SPIO Main"; do
            echo ""
            echo "${CYAN}==============================================================${RESET}"
            echo "${BOLD}${CYAN}==> Running testsuite: $SUITE${RESET}"
            echo "${CYAN}==============================================================${RESET}"
            SAFE_NAME=$(echo "$SUITE" | tr " " "_")
            LOG="$LOGDIR/$SAFE_NAME.log"
            vendor-tests/bin/phpunit --testsuite "$SUITE" $PHPUNIT_COLOR 2>&1 | tee "$LOG"
            # PIPESTATUS[0] holds phpunits real exit code; tee always returns 0.
            RC=${PIPESTATUS[0]}
            if [ "$RC" -ne 0 ]; then
                FAILED=1
                FAILED_SUITES="$FAILED_SUITES $SUITE"
                echo "${RED}${BOLD}==> Testsuite $SUITE FAILED${RESET}"
            else
                echo "${GREEN}${BOLD}==> Testsuite $SUITE passed${RESET}"
            fi
        done

        echo ""
        echo "${CYAN}==============================================================${RESET}"
        echo "${BOLD}${CYAN}==> Summary${RESET}"
        echo "${CYAN}==============================================================${RESET}"
        # Per-suite totals + failing test names. Extracted straight from the
        # captured PHPUnit output so the numbers match what you scrolled past.
        # With --colors=always PHPUnit wraps its summary lines in SGR
        # escape sequences (e.g. `\x1b[30;42mOK (…)\x1b[0m`), which
        # breaks any anchored ^Tests:/^OK regex. Strip ANSI + CR before
        # scanning so the extraction works regardless of colour mode.
        strip_ansi() {
            sed -E "s/$(printf "\033")\[[0-9;]*m//g; s/$(printf "\r")//g" "$1"
        }

        # Grand totals across all suites, accumulated from each suites
        # PHPUnit summary line.
        GRAND_TESTS=0
        GRAND_ASSERTIONS=0
        GRAND_FAILED=0

        for SUITE in "Helper" "model" "External" "Controllers" "SPIO Main"; do
            SAFE_NAME=$(echo "$SUITE" | tr " " "_")
            LOG="$LOGDIR/$SAFE_NAME.log"
            # `Tests: 42, Assertions: 99, Failures: 3, ...` — pick the last
            # one in case a suite emits multiple summary lines.
            TOTALS=$(strip_ansi "$LOG" | grep -E "^(Tests: |OK \(|OK, but incomplete)" | tail -n 1)
            echo ""
            echo "${BOLD}-- $SUITE --${RESET}"
            if [ -n "$TOTALS" ]; then
                # `OK (…)` = suite passed → green. `Tests: … Failures: …` /
                # `Errors: …` = suite failed → red.
                case "$TOTALS" in
                    "OK ("*|"OK, but"*) echo "   ${GREEN}$TOTALS${RESET}" ;;
                    *)                  echo "   ${RED}$TOTALS${RESET}" ;;
                esac

                # Accumulate into grand totals. Two shapes to parse:
                #   `OK (N tests, M assertions)`               — all passed
                #   `Tests: N, Assertions: M, [Errors: E,] [Failures: F,] …`
                # Errors + Failures both count as "failed" for the summary.
                if [[ "$TOTALS" =~ ^OK\ \(([0-9]+)\ tests,\ ([0-9]+)\ assertions\) ]]; then
                    GRAND_TESTS=$((GRAND_TESTS + ${BASH_REMATCH[1]}))
                    GRAND_ASSERTIONS=$((GRAND_ASSERTIONS + ${BASH_REMATCH[2]}))
                elif [[ "$TOTALS" =~ Tests:\ ([0-9]+),\ Assertions:\ ([0-9]+) ]]; then
                    GRAND_TESTS=$((GRAND_TESTS + ${BASH_REMATCH[1]}))
                    GRAND_ASSERTIONS=$((GRAND_ASSERTIONS + ${BASH_REMATCH[2]}))
                    [[ "$TOTALS" =~ Errors:\ ([0-9]+) ]]   && GRAND_FAILED=$((GRAND_FAILED + ${BASH_REMATCH[1]}))
                    [[ "$TOTALS" =~ Failures:\ ([0-9]+) ]] && GRAND_FAILED=$((GRAND_FAILED + ${BASH_REMATCH[1]}))
                fi
            else
                echo "   ${RED}(no summary emitted — PHPUnit likely died mid-run)${RESET}"
            fi
            # Test names prefixed with `N) ClassName::method` are the lines
            # PHPUnit emits for each failure/error above the trace block.
            FAILING=$(strip_ansi "$LOG" | grep -E "^[0-9]+\) [A-Za-z_]+Test::test_" || true)
            if [ -n "$FAILING" ]; then
                echo "   ${RED}Failing tests:${RESET}"
                echo "$FAILING" | sed "s|^|     ${RED}|; s|$|${RESET}|"
            fi
        done

        GRAND_PASSED=$((GRAND_TESTS - GRAND_FAILED))
        echo ""
        echo "${BOLD}-- Grand total --${RESET}"
        echo "   Tests:      ${BOLD}$GRAND_TESTS${RESET}"
        echo "   Assertions: ${BOLD}$GRAND_ASSERTIONS${RESET}"
        echo "   Passed:     ${GREEN}${BOLD}$GRAND_PASSED${RESET}"
        if [ "$GRAND_FAILED" -gt 0 ]; then
            echo "   Failed:     ${RED}${BOLD}$GRAND_FAILED${RESET}"
        else
            echo "   Failed:     ${BOLD}0${RESET}"
        fi

        echo ""
        echo "${CYAN}==============================================================${RESET}"
        if [ $FAILED -ne 0 ]; then
            echo "${RED}${BOLD}!!! FAILED testsuites:$FAILED_SUITES${RESET}"
            exit 1
        fi
        echo "${GREEN}${BOLD}==> All testsuites passed${RESET}"
    '
else
    "${COMPOSE[@]}" run --rm "${RUN_ENV[@]}" php bash -c '
        set -eu
        if [ ! -f vendor-tests/autoload.php ]; then
            echo "==> composer install (first run)"
            COMPOSER=composer.tests.json composer install --no-interaction --prefer-dist
        fi
        if [ ! -d "$WP_TESTS_DIR/includes" ]; then
            echo "==> Installing WordPress $WP_VERSION test framework (first run — ~3 min)"
            bin/install-wp-tests.sh wordpress_test root password mysql "$WP_VERSION"
        fi
        echo "==> vendor-tests/bin/phpunit $*"
        vendor-tests/bin/phpunit "$@"
    ' bash "$@"
fi
