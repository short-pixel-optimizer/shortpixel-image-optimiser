<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package Shortpixel_Image_Optimiser
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

// Forward custom PHPUnit Polyfills configuration to PHPUnit bootstrap file.
$_phpunit_polyfills_path = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );
if ( false !== $_phpunit_polyfills_path ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $_phpunit_polyfills_path );
}

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php, have you run bin/install-wp-tests.sh ?" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

// Give access to tests_add_filter() function.
require_once "{$_tests_dir}/includes/functions.php";

/**
 * Manually load the plugin being tested.
 */
function _manually_load_plugin() {
	require dirname( dirname( __FILE__ ) ) . '/wp-shortpixel.php';
}

tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

/**
 * Cross-plugin compatibility runs (bin/test.sh --compat) set
 * SPIO_PARTNER_PLUGINS=1 after extracting the partner plugins into the
 * test install's plugin dir. Activating them via the active_plugins
 * option lets WP core load them exactly like a production install —
 * paths, constants (NGG_PLUGIN), and lifecycle hooks (as3cf_init) all
 * behave normally, and SPIO's is_plugin_active()-based detection works.
 *
 * The callback runs when WP core reads the option (WP_PLUGIN_DIR is
 * defined by then); plugins missing from disk are silently skipped so
 * a partial download never fatals the whole suite.
 */
if ( '1' === getenv( 'SPIO_PARTNER_PLUGINS' ) ) {
	tests_add_filter(
		'pre_option_active_plugins',
		function () {
			$partners = array(
				'woocommerce/woocommerce.php',
				'nextgen-gallery/nggallery.php',
				'amazon-s3-and-cloudfront/wordpress-s3.php',
				// Commercial — extracted from tests/partner-plugins/ when
				// present; silently skipped (like the rest) when not.
				'sitepress-multilingual-cms/sitepress.php',
				// WPML add-on: translations may point at their OWN file
				// (vs shared-file duplicates). Must load AFTER sitepress.
				'wpml-media-translation/plugin.php',
			);
			$active = array();
			foreach ( $partners as $partner ) {
				if ( file_exists( WP_PLUGIN_DIR . '/' . $partner ) ) {
					$active[] = $partner;
				}
			}
			return $active;
		}
	);
}

// Start up the WP testing environment.
require "{$_tests_dir}/includes/bootstrap.php";

/**
 * WP 5.9 test-isolation guard: neutralize spurious dbDelta ALTERs.
 *
 * ShortQ re-runs its table install (WPQ::createQueue -> install(true) ->
 * dbDelta) whenever its shortqwp_* status option is missing — which is
 * every test, because per-test rollbacks remove the option. On WP <= 6.0
 * dbDelta mis-compares column types against MySQL 8 DESCRIBE output
 * (display widths/case, e.g. `int(11)` vs `int`) and emits a
 * `CHANGE COLUMN` ALTER for every column, every time. ALTER TABLE
 * implicitly COMMITs the wrapping test transaction, so everything seeded
 * up to that point leaks into later tests in the class (seen as
 * MediaLibraryFilterTest failing on WP 5.9 with prevented attachments
 * from earlier tests).
 *
 * The tables are created with the current schema during the install
 * phase, so these ALTERs are pure churn — rewrite them to a harmless
 * non-committing statement. WP >= 6.1 dbDelta detects no diff and never
 * emits them, making this filter a no-op there.
 */
add_filter(
	'query',
	function ( $query ) {
		if ( preg_match( '/^\s*ALTER TABLE\s+`?\w*shortpixel_\w+`?\s+CHANGE COLUMN\s/i', $query ) ) {
			return 'DO 0';
		}
		return $query;
	}
);

/**
 * Silence ONLY PHP 8.5 deprecations that cannot be fixed while retaining
 * PHP 7.4 back-compat (per the plugin header). Everything else — real
 * warnings, deprecations tied to production bugs, test-fixture races —
 * stays visible so Bas can see the symptoms disappear when the
 * corresponding fix lands.
 *
 * Installed AFTER the WP test bootstrap so we sit on top of any handler
 * WordPress registers, and chain-delegate to it for everything else via
 * `$previous`. Zero effect on PHP 7.4 / 8.3 — none of these deprecations
 * fire there.
 *
 * What we silence and why (both are structurally unfixable in this repo):
 *
 *   - `Reflection[Method|Property]::setAccessible()` (deprecated 8.5,
 *     no-op since 8.1). Removing the calls would break the test suite on
 *     PHP 7.4 where they are still required. 150+ call sites; a global
 *     handler is cheaper than a mass rewrite.
 *
 *   - `SplObjectStorage::attach()` / `SplObjectStorage::contains()`
 *     (deprecated 8.5) — emitted from `vendor/sebastian/recursion-context/`,
 *     a PHPUnit transitive dependency. Vendor code; upstream must fix.
 */
$_spio_previous_error_handler = null;
$_spio_previous_error_handler = set_error_handler(
	function ( $errno, $errstr, $errfile, $errline ) use ( &$_spio_previous_error_handler ) {
		static $silenced_deprecations = array(
			'ReflectionMethod::setAccessible()',
			'ReflectionProperty::setAccessible()',
			'SplObjectStorage::attach()',
			'SplObjectStorage::contains()',
		);
		if ( E_DEPRECATED === $errno ) {
			foreach ( $silenced_deprecations as $needle ) {
				if ( false !== strpos( $errstr, $needle ) ) {
					return true;
				}
			}
		}
		if ( $_spio_previous_error_handler ) {
			return ( $_spio_previous_error_handler )( $errno, $errstr, $errfile, $errline );
		}
		return false; // fall through to PHP's default handler.
	}
);

/**
 * The test install has an empty, unverified API key. Any code path that
 * constructs ApiKeyController — directly or indirectly (QuotaController,
 * AdminNoticesController::get_remote_notices(), RequestManager subclasses) —
 * runs ApiKeyModel::checkKey('') → checkRedirect(), which fires
 * wp_safe_redirect() + exit() on the first non-AJAX request and kills the
 * PHPUnit process mid-suite.
 *
 * Marking the one-time settings redirect as already performed (the normal
 * post-first-activation state) makes controller construction safe everywhere.
 *
 * Both layers are needed:
 *  - the in-memory singleton value covers code that reads the already-built
 *    SettingsModel instance;
 *  - the direct option write covers tests that reset SettingsModel::$instance
 *    (to force a fresh DB read) — a rebuilt instance must find the flag in the
 *    'spio_settings' option or the redirect fires mid-suite. This write runs
 *    before the first test's transaction starts, so it is never rolled back.
 */
\wpSPIO()->settings()->redirectedSettings = 1;

$_spio_settings_option                       = get_option( 'spio_settings', array() );
$_spio_settings_option['redirectedSettings'] = 1;
update_option( 'spio_settings', $_spio_settings_option );

/**
 * Create the plugin's custom tables (shortpixel_folders / _meta / _postmeta /
 * _aipostmeta). The WP test install never runs the plugin's activation hook,
 * so without this any code path that queries these tables (e.g.
 * StatsModel::countMediaItems() via BulkViewController::getApproxData())
 * emits a "table doesn't exist" WordPress database error mid-suite.
 * DDL auto-commits in MySQL, so this runs once here, before the first test
 * transaction, and survives every per-test rollback.
 */
\ShortPixel\Helper\InstallHelper::checkTables();
